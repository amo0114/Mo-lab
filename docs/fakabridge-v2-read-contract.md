# FakaBridge v2 只读账号契约（1.8.0）

日期：2026-10-25。状态：本地实现、隔离定向验证通过，未部署。只读能力默认关闭；B2 免费重置已实现，见 [重置契约](./fakabridge-v2-reset-contract.md)。B3b1 已实现稳定 ID 付费商业协议，见 [付费契约](./fakabridge-v2-paid-reset-contract.md)；MoNexus 结账/worker 和机器人购买入口尚未接入，实际 QQ 会话尚未验收。

## 请求与认证

三个固定 POST 路径，无 query，`Content-Type: application/json`（可附 UTF-8 charset），body 最大 8192 字节：

| 路径 | 严格 JSON 字段 |
| --- | --- |
| `/plugin/faka-bridge/v2/capabilities` | `request_id` |
| `/plugin/faka-bridge/v2/user-resolve` | `request_id, external_order_no` |
| `/plugin/faka-bridge/v2/user-subscription` | `request_id, user_id` |

`request_id` 为小写 UUID v4。订单号为 1–64 位 ASCII 字母、数字、下划线或连字符，用户 ID 为不带前导零的正十进制字符串，最多 PHP 64 位有符号整数上限。拒绝额外字段（包括 email、URL、站内 userId）。本阶段 request_id 用于关联响应，不承诺查询结果冻结；同号重试可以得到最新快照。后续写操作另有数据库业务幂等。

使用现有 `hmac_secret`，不兼容降级为 v1：

```text
bodyHash = SHA256(raw_body_bytes).hex_lowercase()
payload = compact_JSON(["monexus-hmac-v2", "xboard", "POST", fullPath,
                        timestampMs_as_number, nonce, bodyHash])
signature = HMAC_SHA256(secret, UTF8(payload)).hex_lowercase()
```

头：`x-monexus-signature-version: 2`、`x-monexus-timestamp`（非负安全整数毫秒）、`x-monexus-nonce`（32 位小写十六进制）、`x-monexus-signature`（64 位小写十六进制）。拒绝重复签名头、压缩编码和路径别名；仅接受无 query 的完整外部路径。时间窗 ±300000ms，含边界。签名只覆盖实际 wire bytes，不重新序列化请求内容。

签名通过后，`faka_protocol_nonces` 使用密钥摘要作用域和 nonce 的组合 hash 作为主键原子占用；保留 601000ms，覆盖未来时间戳从首次可接受到最后可接受的窗口。每次请求最多清理 100 条已到期记录。重放返回 409，存储/迁移不可用返回 503，均不继续查询。重试保留 request_id，生成新 nonce。

v2 每次请求从启用插件的数据库行读取一次配置快照，绕过 Octane singleton 和 PluginManager 内存配置缓存；该快照同时用于验签和读开关。v1 保持原行为。改密钥或关闭 v2 查询后，新请求可立即观察到数据库变化；变更期间已进入处理的请求使用原快照。

## 响应

全部响应 `Cache-Control: private, no-store`。成功公共 envelope：

```json
{"success":true,"protocol_version":2,"request_id":"<same UUID>","queried_at":"<UTC ISO timestamp>"}
```

`capabilities` 另返回 `plugin_version, build_id, core_adapter, capabilities`。build_id 是插件 PHP/JSON 文件的稳定摘要，core_adapter 为 `xboard-eloquent-read-v1`。目前 `resolve_by_order` 和 `subscription_by_user_id` 只有读开关与所需 schema 都满足才为 true；B2 的 reset_idempotency 取决于执行开关、白名单、存储和精确核心适配；reset_status/cancel_tombstone 取决于事务存储，独立于执行开关；paid_reset_by_user_id 取决于独立付费开关、读开关、商业迁移/事务存储及精确核心适配，默认关闭；见付费契约。schema 检查不是完整核心兼容证明，也不是生产验证。

`user-resolve` 另返回 `external_order_no, trade_no, source_status, account:{user_id,email}`。链路固定为：

```text
faka_orders.external_order_no → xboard_trade_no
  → Xboard Order.trade_no → Order.user_id → User.id
```

必须有 completed_at 和远端单号，bridge 状态 completed/revoked，核心订单 completed/discounted，原用户存在。缺任何证据统一返回 ACCOUNT_UNRESOLVED；绝不按历史邮箱回退、创建用户或修改订单。成功后撤销的历史仍可恢复身份事实，**不表示赠送资格合格**。MoNexus B1 还须校验站内归属证明、冲突和来源资格，不可把此接口直接暴露给 QQ 用户。

`user-subscription` 另返回 `account:{user_id,email}` 和 `subscription`：

```text
status: active | expired | no_plan | blocked
plan_id: decimal_string | null
plan_name: string | null
total_bytes, upload_bytes, download_bytes, used_bytes, remaining_bytes: decimal_string
expires_at, next_reset_at, reset_count: decimal_string | null
```

流量来自 User.transfer_enable/u/d（字节），不是 Plan 的 GiB 配置。采用字符串整数运算，u+d 可以超过 PHP_INT_MAX；remaining 最小为零。非法/缺失流量值返回 CORE_DATA_INVALID，不伪造零。expires_at 保留 `0` 与 null：此隔离核心里 0 为已过期、null 为无时限。缺套餐、过期、封禁分开表示；reset_count/next_reset_at 在字段缺失或 null 时返回 null，不推算全来源历史。它不是 MoNexus 赠送次数，MoNexus 本地权益及免费成功次数另由 B2 提供。

失败：`{success:false,protocol_version:2,request_id:<UUID或null>,error_code:<固定码>}`。解析请求 ID 前失败返回 null。客户端对非空 ID 也须核对请求归属；不透传原始错误、异常文本、邮箱或密钥。

| HTTP | 固定码 |
| --- | --- |
| 400 | REQUEST_INVALID |
| 401 | SIGNATURE_INVALID |
| 404 | ACCOUNT_NOT_FOUND |
| 409 | REQUEST_REPLAYED / ACCOUNT_UNRESOLVED |
| 413 / 415 | BODY_TOO_LARGE / CONTENT_TYPE_INVALID |
| 503 | REPLAY_STORE_UNAVAILABLE / CAPABILITY_UNAVAILABLE / CORE_DATA_INVALID / BRIDGE_UNAVAILABLE |

## 升级与验证边界

- 新增 `2026_10_25_000000_create_faka_protocol_nonces_table.php`，保留原订单表与 v1 路由。配置/类常量现为 1.8.0，付费扩展新增商业身份字段迁移；旧订单默认 protocol_version=1。新增 `v2_read_enabled=false`，旧配置缺该键也按 false。
- 安装/升级由 PluginManager 的 migration loader 执行；不能仅复制文件就跳过升级。本批未执行任何生产升级。将来按既有部署流程升级，并确保 web/horizon 加载同一代码；先确认 capability 和 B1 授权，再启用查询。关闭读开关不删除 nonce 表，回退应用也不清除既有审计数据。
- 本地固定镜像：`ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8`，镜像 revision `935522d28bbeb666824facd8bdd674db8ab37df1`，PHP 8.2.35，Laravel 12.54.1。该镜像由当前 `:new` 拉取；没有读取线上 digest，不能当作部署版本证明。
- `bash tests/run-fakabridge-v2-read.sh`：禁用网络、只读挂载，仅 /tmp 可写；使用镜像真实 Laravel、Eloquent、路由器、核心模型及 PluginManager，SQLite 内存夹具运行迁移/安装/升级、签名、身份和 DTO 断言。没有启动全站 Kernel/Octane、中间件或 Horizon，没有使用完整生产 schema，也没有验证 MySQL 并发；不替代后续部署一致环境联调。
- `tests/fixtures/signed-protocol-v2.json` 镜像 MoNexus 固定向量，两端应逐字一致。后续修改协议时须共同更新并重新互验。

上游依据：[固定 revision 的 Order](https://github.com/cedar2025/Xboard/blob/935522d28bbeb666824facd8bdd674db8ab37df1/app/Models/Order.php)、[User](https://github.com/cedar2025/Xboard/blob/935522d28bbeb666824facd8bdd674db8ab37df1/app/Models/User.php)、[PluginManager](https://github.com/cedar2025/Xboard/blob/935522d28bbeb666824facd8bdd674db8ab37df1/app/Services/Plugin/PluginManager.php)。2026-10-25 先抓取官方源码，再核对上述本地镜像中的实际文件和 labels。
