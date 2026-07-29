# FakaBridge

MoNexus（发卡网）↔ Xboard 桥接插件。

用户在 MoNexus 用积分兑换订阅类商品后，MoNexus 回调本插件，在 Xboard 自动创建/续费订阅。

## 生产环境基准

对齐生产 Docker Compose：

| 项 | 值 |
|----|-----|
| 镜像 | `ghcr.io/cedar2025/xboard:new` |
| 公网域名 | `https://v.uuwu.de` |
| 生产路径 | `/root/Xboard`（`~/Xboard`） |
| 进程 | `xboard-web-1`（Octane `127.0.0.1:7001`）+ `xboard-horizon-1` |
| 插件挂载 | `./plugins:/www/plugins` |
| 插件目录名 | **`FakaBridge`**（`Str::studly('faka_bridge')`） |
| 插件 code | `faka_bridge` |

## 目录约定

```text
# 开发 (Mo-lab)
xboard-plugins/FakaBridge/

# 生产 Xboard 根目录
plugins/FakaBridge/   ← 必须与此一致，不要用 faka-bridge
```

## 公开 API

PluginManager 只挂 `api` 中间件，**不会**自动加 `/api/v1`，路由已自带前缀：

| 方法 | 路径 |
|------|------|
| POST | `/plugin/faka-bridge/order-paid` |
| GET  | `/plugin/faka-bridge/order-status` |
| GET  | `/plugin/faka-bridge/plan-capacity` |
| POST | `/plugin/faka-bridge/plan-capacity` |
| GET  | `/plugin/faka-bridge/plan-catalog` |

契约全文：[`docs/monexus-xboard-contract.md`](../../docs/monexus-xboard-contract.md)  
部署步骤：[`docs/faka-bridge-deploy.md`](../../docs/faka-bridge-deploy.md)

## Period 约定

与 cedar2025/Xboard `Plan::PERIOD_*` 一致（canonical）：

`monthly` | `quarterly` | `half_yearly` | `yearly` | `two_yearly` | `three_yearly`

仍接受 legacy：`month_price` → `monthly` 等。

回调默认 `period=monthly`。命名 SKU（如 `aster-basic-monthly`）以 `SKU_MAPPING` 为准，忽略请求体中的 period。

## 逻辑加固（v1.1.x）

```text
claim faka_orders (unique)
  → lock row
  → createFromRequest
  → UPDATE xboard_trade_no   ← before paid
  → ensurePaid (refresh + paid/open)  ← 必须落到 Xboard STATUS_COMPLETED
  → reconcileStatus
  → on error: failed + error_log (claim kept)
```

### v1.3.0 plan-capacity 写 + plan-catalog

- `POST /plugin/faka-bridge/plan-capacity`：设置 `capacity_limit`（null=不限）
- `GET /plugin/faka-bridge/plan-catalog`：套餐目录（导入商品用）
- 全部 HMAC 密钥鉴权，**仅 MoNexus 平台可调**；买家/商家无此密钥

### v1.2.0 plan-capacity

供 MoNexus 结算预检读取 Xboard `capacity_limit`（订阅人数限制）：

- `GET /plugin/faka-bridge/plan-capacity?sku=...&paid_at=...&sign=...`
- 返回 `capacity_limit / active_users / remaining / sellable`
- **不**限制升/降级；仅回答「该 plan 是否还能给新用户开通」

### v1.1.1 关键修复（有用户 / 订单待支付 / 无套餐）

根因：`OrderService::createFromRequest` 返回的内存模型常为 `status=null`，而
`OrderService::paid()` 使用严格比较 `status !== STATUS_PENDING(0)`，会把 `null`
误判为「已支付」并直接 `return true`，**从未真正 paid/open**。

表现：

- 后台有新建用户（findOrCreateUser 已执行）
- 订单卡在 **待支付**
- 用户无套餐 / `expired_at` 未写
- Faka 侧 `faka_orders.status=processing` 且带 `trade_no`，回调却回报 success

修复：

1. `ensurePaid()` 先 `$order->refresh()`，再按真实 DB 状态 paid / 补 open
2. `isTerminalSuccess()` 仅 `completed`（不再把 `processing` 当成功）
3. `canResume()` 对 `processing+trade_no` 允许重试，卡住单可再次回调修单

配置项（后台）：`hmac_secret`、`time_window`、`enabled`、`auto_create_user`、`default_group_id`。

## 本地 → 生产同步示例

```bash
# 在生产 Xboard 根目录执行
rsync -av --delete \
  /path/to/Mo-lab/xboard-plugins/FakaBridge/ \
  ./plugins/FakaBridge/

docker compose restart web horizon
docker compose exec web php artisan route:list | grep faka
```

首次部署需在后台「插件管理」安装并启用，填写 `hmac_secret`。
