# MoNexus ↔ Xboard 桥接契约

> **版本**: v1.2.0  
> **更新**: 2026-07-29  
> **状态**: 生产验收通过 ✅（路径无 `/api/v1`；域名 `v.uuwu.de`）+ 1.4.0 退款撤销

---

## 概述

MoNexus 内部积分购买完成后,自动在 Xboard 创建/续费用户订阅。

### 架构

```
┌──────────────┐  POST order-paid / GET status / POST revoke / capacity  ┌──────────────┐
│   MoNexus   │ ────────────────────────────────────────────────────────► │    Xboard    │
│ (发卡网)    │  ◄──────────────────────────────────────────────────────── │  FakaBridge  │
│              │        200 { success, trade_no | status | remaining }     │   (Plugin)   │
└──────────────┘                                                           └──────────────┘
      │                                                                          │
      │ 积分购买 → outbox → 开通；退款 → revoke                                   │ 按邮箱查用户 → 开通/过期订阅
      ▼                                                                          ▼
  MoNexus 订单 + FakaBridgeTask                                            faka_orders + User
```

### 基本规则

| 规则 | 说明 |
|------|------|
| 邮箱定位 | 所有订单通过 `email` 字段匹配 Xboard 用户,不存在则自动创建。**该邮箱由买家在 MoNexus 下单时填写（推荐 `purchaseForm.xboardEmail`），不是强制等于 MoNexus 登录邮箱** |
| 幂等 | MoNexus `order_no` 全局唯一,重复回调只发货一次 |
| 防伪造 | 所有请求携带 HMAC-SHA256 签名,密钥仅双方知晓 |
| 防重放 | 请求携带 `paid_at` 时间戳,偏差超过 300s 拒绝 |
| 套餐映射 | SKU → plan_id/period 映射在服务端硬编码,不信任回调 |

---

## 1. 回调端点

### POST /plugin/faka-bridge/order-paid

**触发时机**: MoNexus 内部积分购买订单完成后(积分已扣、商品已锁定)

**Base URL**: `https://monexus.oai-o.com` → `https://v.uuwu.de`

**Content-Type**: `application/json`

### 1.1 请求体

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `order_no` | string | ✅ | MoNexus 订单号,全局唯一,最长 64 字符 |
| `email` | string | ✅ | 用户邮箱,最长 255 字符 |
| `sku` | string | ✅ | 商品 SKU,决定套餐和周期 |
| `period` | string | ❌ | 周期,默认 `monthly`（Xboard 规范 key；legacy `month_price` 等会被服务端规范化） |
| `paid_at` | int | ❌ | Unix 时间戳(秒),默认当前时间 |
| `sign` | string | ✅ | HMAC-SHA256 签名,hex 小写 |

### 1.2 签名算法

```
1. 排除 sign 字段
2. 剩余字段按 key 字母序升序排序
3. 拼接成 key=value&key=value&... 格式
4. HMAC-SHA256(payload, secret) → hex 小写
```

**示例**:

```
params = {
  email: "user@example.com",
  order_no: "MN-20260728-001",
  paid_at: 1785250533,
  period: "monthly",
  sku: "aster-basic-monthly"
}

payload = "email=user@example.com&order_no=MN-20260728-001&paid_at=1785250533&period=monthly&sku=aster-basic-monthly"
sign = HMAC-SHA256(payload, "test_secret")
     = "e7b175f00477b9bad99c7b60667ff2883562312d6c8f39c1b9a94f492f44ace1"
```

> 以算法为准；若文档与实现的 hex 不一致，以排序拼接 + HMAC-SHA256 计算结果为准。

### 1.3 TypeScript 参考实现

```typescript
import crypto from 'node:crypto';

function signParams(params: Record<string, string | number>, secret: string): string {
  const keys = Object.keys(params)
    .filter(k => k !== 'sign')
    .sort();

  const payload = keys
    .map(k => `${k}=${params[k]}`)
    .join('&');

  return crypto
    .createHmac('sha256', secret)
    .update(payload, 'utf-8')
    .digest('hex');
}

// 使用
const secret = process.env.FAKA_BRIDGE_SECRET!;
const body = {
  order_no: 'MN-20260728-001',
  email: 'user@example.com',
  sku: 'aster-basic-monthly',
  period: 'monthly',
  paid_at: Math.floor(Date.now() / 1000),
};
const sign = signParams(body, secret);

await fetch('https://v.uuwu.de/plugin/faka-bridge/order-paid', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ ...body, sign }),
});
```

### 1.4 响应

**成功 - 新订单**(HTTP 200):

```json
{
  "success": true,
  "trade_no": "202607281500001",
  "order_no": "MN-20260728-001",
  "status": "processing",
  "message": "订单已创建并开始处理",
  "expired_at": 1791244800
}
```

**成功 - 重复订单/幂等**(HTTP 200):

```json
{
  "success": true,
  "trade_no": "202607281500001",
  "order_no": "MN-20260728-001",
  "status": "completed",
  "message": "订单已处理,请勿重复回调",
  "expired_at": 1791244800
}
```

> **`expired_at`（≥1.4.1）**：Xboard 用户 `expired_at`（Unix 秒）。开通/续费成功后，这是订阅真实到期时刻。  
> MoNexus 必须用它写入 `DeliveryRecord.expiresAt`，**不要**仅用 `validityDays` 从交付时刻推算——否则 Xboard 侧已按账号剩余时长顺延（如 9.6→10.6），MoNexus 仍显示「交付+30天」（如 9.5），两边不一致。
>
> **`action` / `before` / `after` / `subscription`（≥1.5.0）**：开通前后快照，供买家端展示  
> 「续期前/后到期」「购买前/后剩余流量」。`transfer_enable` / `used` / `remaining` 单位为**字节**。

**签名错误**(HTTP 400):

```json
{
  "success": false,
  "error": "签名验证失败"
}
```

**SKU 未配置**(HTTP 400):

```json
{
  "success": false,
  "error": "未配置的 SKU: plan-999-monthly"
}
```

**邮箱/参数错误**(HTTP 400):

```json
{
  "success": false,
  "error": "缺少必填参数: email"
}
```

### 1.5 重试策略

MoNexus 侧建议:

| 重试次数 | 间隔 | 超时 |
|----------|------|------|
| 第 1 次 | 立即 | 5s |
| 第 2 次 | 2s 后 | 5s |
| 第 3 次 | 8s 后 | 5s |

> 幂等保证: 无论重试多少次,最终状态一致,不会重复发货。

---

## 2. 对账查询(可选)

### GET /plugin/faka-bridge/order-status

用于 MoNexus 主动查询订单状态,例如重试后确认是否已成功。

**请求参数**(Query String):

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `order_no` | string | ✅ | MoNexus 订单号 |
| `sign` | string | ✅ | HMAC-SHA256 签名(同回调算法) |

**响应**:

```json
{
  "success": true,
  "order_no": "MN-20260728-001",
  "status": "completed",
  "trade_no": "202607281500001",
  "created_at": "2026-07-28T15:00:00Z",
  "completed_at": "2026-07-28T15:00:05Z",
  "expired_at": 1791244800
}
```

`status` 取值: `pending` | `processing` | `completed` | `failed`  
`expired_at`（≥1.4.1，可选）：开通邮箱对应 Xboard 用户当前 `expired_at`（Unix 秒）。

---

## 3. SKU 映射表(服务端硬编码)

| SKU | 套餐名 | plan_id | 金额(分) | 周期 |
|-----|--------|---------|----------|------|
| `aster-basic-monthly` | 🐤 小雏鸡套餐 | 3 | 99900 | monthly |
| `aster-basic-quarterly` | 🐤 小雏鸡套餐 | 3 | 284715 | quarterly |
| `aster-basic-half-yearly` | 🐤 小雏鸡套餐 | 3 | 539460 | half_yearly |
| `aster-basic-yearly` | 🐤 小雏鸡套餐 | 3 | 1018980 | yearly |
| `aster-basic-two-yearly` | 🐤 小雏鸡套餐 | 3 | 1918080 | two_yearly |
| `aster-basic-three-yearly` | 🐤 小雏鸡套餐 | 3 | 2697300 | three_yearly |
| `aster-pro-monthly` | 🐔 战斗老母鸡套餐 | 4 | 199900 | monthly |
| `aster-pro-quarterly` | 🐔 战斗老母鸡套餐 | 4 | 569715 | quarterly |
| `aster-pro-half-yearly` | 🐔 战斗老母鸡套餐 | 4 | 1079460 | half_yearly |
| `aster-pro-yearly` | 🐔 战斗老母鸡套餐 | 4 | 2038980 | yearly |
| `aster-pro-two-yearly` | 🐔 战斗老母鸡套餐 | 4 | 3838080 | two_yearly |
| `aster-pro-three-yearly` | 🐔 战斗老母鸡套餐 | 4 | 5397300 | three_yearly |
| `aster-legend-monthly` | 🔥 传奇凤凰套餐 | 5 | 999900 | monthly |
| `aster-legend-quarterly` | 🔥 传奇凤凰套餐 | 5 | 2849715 | quarterly |
| `aster-legend-half-yearly` | 🔥 传奇凤凰套餐 | 5 | 5399460 | half_yearly |
| `aster-legend-yearly` | 🔥 传奇凤凰套餐 | 5 | 10198980 | yearly |
| `aster-speed-monthly` | 测速专用 | 6 | 88800 | monthly |
| `aster-speed-yearly` | 测速专用 | 6 | 905760 | yearly |
| `aster-direct-monthly` | 专线测速 | 7 | 66600 | monthly |
| `aster-direct-yearly` | 专线测速 | 7 | 679320 | yearly |
| `aster-chain-monthly` | 链式代理 | 9 | 999900 | monthly |
| `aster-chain-yearly` | 链式代理 | 9 | 10198980 | yearly |

> 通用别名: `plan-{id}-{period}` 也支持,如 `plan-3-monthly`

---

## 4. MoNexus 侧配置项

需要在 MoNexus 后台或 `.env` 中配置:

```env
# 回调地址
FAKA_BRIDGE_URL=https://v.uuwu.de/plugin/faka-bridge/order-paid

# 签名密钥(与 Xboard 插件配置一致)
FAKA_BRIDGE_SECRET=your_shared_secret_here

# 超时(毫秒)
FAKA_BRIDGE_TIMEOUT=5000

# 重试次数
FAKA_BRIDGE_RETRY=3
```

### 商品 SKU 配置

在 MoNexus 后台的每个商品上设置 `sku` 字段,值必须匹配上表。

---

## 5. 数据流(完整流程)

```
用户购买 MoNexus 商品
  │
  ▼
MoNexus 创建订单(积分锁定)
  │
  ▼
MoNexus 扣除积分 → 订单状态改为 completed
  │
  ▼
MoNexus 调用 FakaBridge 回调
  ├── 成功 → 记录回调状态
  └── 失败 → 重试 3 次 → 最终失败则人工介入
  │
  ▼
Xboard FakaBridge Plugin:
  1. 验签 + 插件启用/桥接开关/HMAC 密钥检查(失败则 400)
  2. 时间戳检查(有 paid_at 且偏差过大则 400)
  3. 校验 email / SKU → plan_id + canonical period
  4. 占坑 faka_orders(external_order_no UNIQUE)
     ├── 已存在且 processing/completed → 幂等返回
     ├── 已存在且 pending/failed 可恢复 → 继续履约
     └── 并发插入冲突 → 转读已有行
  5. 行锁后履约:
     a. 按 email 查/建 User(受 auto_create_user / default_group_id 控制)
     b. 无 trade_no → createFromRequest → **先写入 xboard_trade_no** → paid()
     c. 已有 trade_no → 仅 ensurePaid(幂等)
     d. paid() 内 dispatchSync → open → order.open.after 标 completed
     e. reconcileStatus 兜底(钩子竞态)
  6. 失败 → status=failed + error_log(占坑保留,可重试)
  7. 返回 { success, trade_no, order_no, status, message, expired_at? }
```

---

## 6. 错误码速查

| HTTP | 响应 error | 含义 | 处理建议 |
|------|-----------|------|---------|
| 400 | 签名验证失败 | 签名不匹配或密钥不一致 | 检查密钥是否一致 |
| 400 | 请求已过期 | 时间戳偏差 > 300s | 检查服务器时间 |
| 400 | 缺少必填参数: xxx | 请求体缺少字段 | 补全参数 |
| 400 | 未配置的 SKU: xxx | SKU 不在映射表中 | 在后台配置该 SKU |
| 400 | 邮箱格式无效 | email 格式不正确 | 校验邮箱格式 |
| 500 | 内部错误 | 服务端异常 | 联系管理员查看日志 |

---

## 7. 回滚/紧急处理

如果桥接出现问题:

1. **Xboard 后台** → 插件管理 → 禁用 FakaBridge
2. 或直接在数据库: `UPDATE v2_plugins SET is_enabled=0 WHERE code='faka_bridge';`
3. 禁用后,MoNexus 回调会收到 404,MoNexus 侧做回滚

---

## 8. 部署与运行基准

- 生产镜像: `ghcr.io/cedar2025/xboard:new`
- 插件目录: 宿主机 `./plugins/FakaBridge`（code=`faka_bridge`，目录名必须 StudlyCase）
- 进程: Octane `web` + `horizon`，插件 volume 两边都要挂
- 详细步骤: [faka-bridge-deploy.md](./faka-bridge-deploy.md)

## 9. 参考

- Xboard 部署: `https://v.uuwu.de`（生产目录 `~/Xboard`，镜像 `ghcr.io/cedar2025/xboard:new`，`127.0.0.1:7001`）
- MoNexus 部署: `https://monexus.oai-o.com`
- 上游: [cedar2025/Xboard](https://github.com/cedar2025/Xboard)
- HMAC 算法: [RFC 2104](https://datatracker.ietf.org/doc/html/rfc2104)