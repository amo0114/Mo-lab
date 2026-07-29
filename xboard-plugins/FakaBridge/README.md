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
| POST | `/api/v1/plugin/faka-bridge/order-paid` |
| GET  | `/api/v1/plugin/faka-bridge/order-status` |

契约全文：[`docs/monexus-xboard-contract.md`](../../docs/monexus-xboard-contract.md)  
部署步骤：[`docs/faka-bridge-deploy.md`](../../docs/faka-bridge-deploy.md)

## Period 约定

与 cedar2025/Xboard `Plan::PERIOD_*` 一致（canonical）：

`monthly` | `quarterly` | `half_yearly` | `yearly` | `two_yearly` | `three_yearly`

仍接受 legacy：`month_price` → `monthly` 等。

回调默认 `period=monthly`。命名 SKU（如 `aster-basic-monthly`）以 `SKU_MAPPING` 为准，忽略请求体中的 period。

## 逻辑加固（v1.1.0）

```text
claim faka_orders (unique)
  → lock row
  → createFromRequest
  → UPDATE xboard_trade_no   ← before paid
  → OrderService::paid()     ← sync open + order.open.after → completed
  → reconcileStatus
  → on error: failed + error_log (claim kept)
```

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
