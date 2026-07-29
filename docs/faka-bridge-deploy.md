# FakaBridge 生产部署说明

> 基准：生产 Docker Compose（`ghcr.io/cedar2025/xboard:new` + Octane web + Horizon + 插件 volume）

## 1. Compose 约束（不要改也能用）

生产典型片段：

```yaml
services:
  web:
    image: ghcr.io/cedar2025/xboard:new
    volumes:
      - ./plugins:/www/plugins
      # ... .env, logs, theme, redis data ...
    command: php artisan octane:start --host=0.0.0.0 --port=7001
    ports:
      - 127.0.0.1:7001:7001
  horizon:
    image: ghcr.io/cedar2025/xboard:new
    volumes:
      - ./plugins:/www/plugins
      # ...
    command: php artisan horizon
  redis:
    image: redis:7-alpine
    # unix socket ...
```

| 点 | 要求 |
|----|------|
| 插件路径 | 宿主机 `./plugins/FakaBridge` → 容器 `/www/plugins/FakaBridge` |
| 目录名 | 必须是 **`FakaBridge`**（不是 `faka-bridge`） |
| 双进程 | `web` 与 `horizon` 都挂同一 `plugins` |
| 对外 | 只暴露本机 7001，公网走反代到域名 |
| 镜像 API | 只调用镜像内 `OrderService` / `UserService` / `Plan`，不改镜像 |

**无需为 FakaBridge 修改 compose**，拷贝插件目录即可。

## 2. 从 Mo-lab 同步

```bash
# 变量：XBOARD_ROOT = 生产机 Xboard 目录（含 compose.yaml / plugins /）
export XBOARD_ROOT=/root/Xboard
export MOLAB=/path/to/Mo-lab

rsync -av --delete \
  "$MOLAB/xboard-plugins/FakaBridge/" \
  "$XBOARD_ROOT/plugins/FakaBridge/"

cd "$XBOARD_ROOT"
docker compose restart web horizon
```

校验：

```bash
# 目录
ls "$XBOARD_ROOT/plugins/FakaBridge/Plugin.php"

# 路由（期望含 api/v1/plugin/faka-bridge）
docker compose exec web php artisan route:list | grep -i faka
# 或: docker exec xboard-web-1 php artisan route:list | grep -i faka

# 日志
docker compose exec web tail -n 50 storage/logs/laravel.log
```

## 3. 安装与启用

1. 打开 Xboard 管理后台（`https://v.uuwu.de` 管理路径）→ 插件管理  
2. 找到 **FakaBridge**（code: `faka_bridge`）→ 安装（会跑 `database/migrations`）  
3. 启用插件  
4. 配置：

| 配置项 | 说明 |
|--------|------|
| `hmac_secret` | 与 MoNexus `FAKA_BRIDGE_SECRET` 一致，**必填** |
| `time_window` | 默认 300 秒 |
| `auto_create_user` | 默认 true；关闭后未知邮箱会 400 |
| `default_group_id` | 可选，自动建用户时的权限组 |

5. 再次 `docker compose restart web horizon`（Octane 需加载新路由/代码）

确认表存在：

```sql
SHOW TABLES LIKE 'faka_orders';
```

## 4. 反代

公网 HTTPS 域名 **`https://v.uuwu.de`** 需把下列路径转到 `127.0.0.1:7001`，**不要**被站点登录墙拦住：

```text
POST /api/v1/plugin/faka-bridge/order-paid
GET  /api/v1/plugin/faka-bridge/order-status
```

冒烟（在能访问域名的机器上，替换 secret / 签名）：

```bash
# 先用 order-status 测通鉴权与路由
curl -sS "https://v.uuwu.de/api/v1/plugin/faka-bridge/order-status?order_no=probe&sign=..."
```

## 5. 与 MoNexus 对接配置（MoNexus 侧尚未实现时先备好）

```env
FAKA_BRIDGE_URL=https://v.uuwu.de/api/v1/plugin/faka-bridge/order-paid
FAKA_BRIDGE_SECRET=<与插件 hmac_secret 相同>
FAKA_BRIDGE_TIMEOUT=5000
FAKA_BRIDGE_RETRY=3
```

商品 SKU 必须落在 `FakaOrderService::SKU_MAPPING` 或 `plan-{id}-{period}` 别名。

Period 传 **`monthly`** 等 canonical 值（见契约）。

## 6. 回滚

```bash
# 后台禁用 FakaBridge
# 或
# UPDATE v2_plugins SET is_enabled=0 WHERE code='faka_bridge';

docker compose restart web horizon
```

禁用后回调返回插件未启用 / 404 类错误，MoNexus 应失败重试或人工介入。

## 7. 版本与源码

| 项 | 值 |
|----|-----|
| 插件版本 | 见 `plugins/FakaBridge/config.json` → `version` |
| 上游 Xboard | [cedar2025/Xboard](https://github.com/cedar2025/Xboard)（生产镜像 tag `:new`） |
| 契约 | `docs/monexus-xboard-contract.md` |

## 8. 逻辑加固说明（v1.1.0）

| 能力 | 行为 |
|------|------|
| 幂等占坑 | 先 `INSERT faka_orders`（unique `external_order_no`），再履约；并发第二次拿已有行 |
| 可恢复 | `pending` / `failed`(无或有 trade_no) 允许重试；`processing`/`completed` 直接幂等成功 |
| open 时序 | **先写 `xboard_trade_no` 再 `paid()`**，保证 `order.open.after` 能匹配到行 |
| 失败落库 | `status=failed` + `error_log`；占坑不删，MoNexus 重试安全 |
| 配置 | `enabled` / `hmac_secret` / `time_window` / `auto_create_user` / `default_group_id` 经 `FakaBridgeConfig` 读取 |
| 邮箱 | 格式校验；未开自动建用户时邮箱不存在返回 400 |

升级已安装插件后：

```bash
rsync .../FakaBridge/ ./plugins/FakaBridge/
docker compose restart web horizon
# 后台插件版本应显示 1.1.0（若有缓存以文件为准）
```
