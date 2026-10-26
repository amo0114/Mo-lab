# 稳定账号付费重置协议（FakaBridge 1.8.0 / B3b1）

2026-10-25。本阶段交付插件商业协议和 MoNexus TypeScript transport，后续 B3b2 已完成 MoNexus 商业 outbox/worker 与退款对账的本地定向验证，未部署。B3b3 网站稳定账号结账已在独立开关下完成本地定向验证；B3b4 机器人购买引用与有覆盖范围的付费次数也已本地实现；实际部署验收尚未完成，**不能宣称真实 QQ 闭环已验收或直接上线**。免费赠送仍按 [B2 契约](./fakabridge-v2-reset-contract.md) 独立执行。

## 请求

沿用 [v2 原始字节签名及共享 nonce](./fakabridge-v2-read-contract.md)，全部 POST，无 query、禁止压缩、JSON ≤8192 bytes；不接收邮箱、远端 URL 或由用户指定的站内身份。固定路径：

| `/plugin/faka-bridge/v2/` 后缀 | 严格字段（均为字符串） |
| --- | --- |
| `paid-reset-preview` | `request_id, user_id, sku` |
| `paid-reset` | `request_id, order_no, user_id, sku, expected_plan_id, period` |
| `paid-reset-status` | 与执行相同 |
| `paid-reset-revoke` | 与执行相同 |

`request_id` 为小写 UUID v4，仅绑定本次 HTTP 响应；新 HTTP 可使用新的 ID/nonce。业务重试固定 order_no、稳定 user_id、SKU、expected_plan_id、period 和服务端配置的 client ID。order_no 为 1–64 位 ASCII 字母/数字/下划线/连字符；user_id 为正十进制字符串，最大 9223372036854775807；expected_plan_id 最大 2147483647（既有 faka_orders.plan_id 为 SQL INT）。SKU 小写 ASCII 字母/数字/下划线/连字符，最多 128 字符；period 固定 `reset_traffic`。普通购买 SKU 和未知 SKU 不可用于执行。

幂等语义 hash：

```text
SHA256(compact_JSON([
  "paid_reset_v1", configured_client_id, order_no, user_id,
  sku, expected_plan_id, "reset_traffic"
]))
```

客户端 ID 来自插件 `v2_client_id`，不得通过改 ID 复用旧单号；外部单号在 v1/v2 间全局唯一。同号异语义或跨协议返回 409 OPERATION_CONFLICT，不进入邮箱路径。两仓库 `fixtures/paid-reset-v2.json` 保存独立 Python 计算的固定向量。

## 资格、执行与事务

`v2_paid_reset_enabled=false` 默认关闭，独立于免费执行开关；还需 `v2_read_enabled`、稳定 client ID、新 migration、事务存储和精确核心适配。没有配置允许付费重置，不通过免费开关间接开放。capabilities.paid_reset_by_user_id 只在所有条件满足时为 true；B2 的 reset_status/cancel_tombstone 仍专指免费操作，不应据此推断商业协议能力。

preview 新鲜读取稳定账号与 SKU 对应套餐：未封禁、有效订阅（null 无限期，0 已过期）、正流量额度、当前 plan 与 SKU 一致，按 Xboard canResetTraffic 要求开启重置策略且 reset 价格 >0。execute 在锁内重验。满额不阻止对现有账号重置；这沿用核心 reset 分支跳过新增订阅容量的语义，普通新购规则未放宽。

execute 在同一数据库外层事务中占用唯一 bridge 行、锁定 User 和 Plan，调用固定核心 `OrderService::createFromRequest → paid → dispatchSync → open`，保留正常商业价格、折扣、余额与佣金机制。**此协议不会消费或补发免费权益**。网站积分报价与付款授权由 MoNexus 结账负责，本接口不是供用户直接调用的付款凭据。

本地核心 OrderService 忽略 performReset(false)，因此 bridge 在提交前必须验证：原商业单 user/plan/period/type/trade 不变且完成，u/d 为零、reset_count 恰好 +1、新增一条原生日志、plan/额度/到期不变。原生失败、Eloquent veto、提交前崩溃均回滚 bridge 单、商业单、原生计数及日志；未知响应不能当作失败退款。核心免费补丁将原生缓存/hook 延至外层提交后。没有改写全局 OrderService，也没有声称修复其所有调用方。

核心摘要门禁：

- 固定镜像 `ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8`。
- OrderService SHA256 `50d712e59c90b60d71269dc08cb95aecd0b0808dde912145ee585fc2ec91b6bf`。
- 当前原生补丁 SHA256 `75feffb645e22def3f2afb8789b141cb848e5b970e9bc468d5f704993c5798c9`（第十三批SQLite写锁版本），构建方式同 B2。
- User/Plan/Order/TrafficResetLog/FakaOrder 需使用同一默认连接对象。MySQL相关表需InnoDB；SQLite需满足免费契约的同一文件型main/WAL/同步配置/无attached或TEMP遮蔽条件，商业表同样检查。普通内存库仍拒绝，显式测试绑定仅用于旧隔离夹具。

## 状态与退款

成功 envelope 同只读协议，附 `operation`：order_no/user_id/sku/plan_id/period/payload_hash/status/trade_no/error_code/before/after/completed_at。before/after 字段为 plan_id、transfer_enable、expired_at、last_reset_at、reset_count、next_reset_at、u、d，十进制字符串或对应允许的 null；不携带邮箱。快照在执行成功时冻结，重放/status 不按邮箱或最新用量重建。

| 状态 | 含义 |
| --- | --- |
| `completed` | 已提交商业重置；必须有 trade、完整 before/after 和成功时间，error_code=null |
| `failed` | 明确拒绝且不会再执行；error_code 为 ACCOUNT_NOT_FOUND / SUBSCRIPTION_INELIGIBLE / RESET_NOT_FOR_SALE，无执行证据 |
| `revoked` | 已撤销并禁止迟到执行；可能带已完成执行证据（执行先于退款），也可能是无执行证据的 tombstone |
| `not_found` | 仅 status 可返回；无执行证据，**不是已取消或安全退款的证明** |
| `pending` | status 观察到非终态行；不能算成功或失败，需继续核对 |

正常新执行只返回 completed/failed/已有 revoked；revoke 必须返回 revoked，TS 校验 action 与状态相符。非预期状态、hash/身份/request ID 不匹配或不完整成功证据均视为 INVALID_RESPONSE，不可信作履约依据。

revoke 与 execute 锁同一外部单号行。revoke 先到，创建无执行 tombstone，迟到 execute 不再运行；execute 先到，revoke 保留原执行证据，只标记该商业操作撤销。已清零的流量不可恢复，**不使用户原套餐过期、不延长订阅、不改余额或流量额度**。MoNexus 金钱/积分退款仍由本站流程负责。即使套餐/SKU/用户已删除、写开关关闭，也保留 status/revoke；认证、bridge 总开关、nonce 和事务存储仍必须可用。

v1 支付/status/revoke 拒绝处理 v2 记录；原 v1 订单仍默认 protocol_version=1。另修复 v1 reset_traffic 退款误使原套餐过期的问题，普通基础套餐撤销维持原逻辑。

## 迁移、验证及未完成接入

新增 migration `2026_10_25_160000_add_paid_reset_identity` 给 faka_orders 增加 protocol_version（旧记录默认 1）、client_id、xboard_user_id、semantic_hash、result_payload；通过 PluginManager 安装/升级，不能只复制文件。保留审计行及 tombstone，回退应用不得清表。常驻 web/horizon 必须加载同一插件代码和相同核心补丁，线上 digest 仍待实证。

定向 runner：

```bash
bash tests/run-fakabridge-v2-reset.sh paid-reset
bash tests/run-fakabridge-v2-reset.sh paid-reset sqlite-wal
bash tests/run-fakabridge-v2-reset-mysql.sh paid-reset
```

使用真实镜像 Laravel/Eloquent/OrderService/OrderHandleJob 和最小核心 schema，读写只在可丢弃 SQLite/MySQL 夹具，测试配置与生产无关。MySQL runner 使用无网络容器、tmpfs 数据、共享本地 socket，收尾清理；多独立进程和屏障验证执行/撤销两种先后与重复执行。并非完整 Kernel/Octane/Horizon、第三方插件 hook 或真实 QQ 验收；afterCommit hook 不是持久化外发队列。

第十三批文件型SQLite/WAL验证187处断言：无测试特权绑定，执行/撤销和重复执行；先证实竞争连接被写锁阻挡，再在真实DML前握手；免费/付费两种先后、计划重置与免费意图两种先后、两个陈旧原生模型、真实SIGKILL提交前中断及同号恢复（免费/付费各一）、busy503后同号重试。attached/TEMP遮蔽/synchronous OFF/非WAL/query_only均拒绝能力。MySQL受影响回归106处断言。断言数不是独立用例数；进程中断恢复不等于掉电/磁盘故障验收。

MoNexus B3b2 已冻结商业 task 的 protocol/link/instance/origin/user/plan/client/SKU，增量迁移保留旧任务默认 v1、禁止已创建任务切换协议或稳定目标。worker/status/revoke 始终使用同一 v2 协议，身份、租约与 resultVersion 纳入 CAS；unknown/not_found 保留冻结积分，耗尽重试后通过 revoke tombstone 收敛，不沿用 v1 的 404 自动退款。成功证据、交付及积分 capture 同事务写入，已执行退款保留 before/after；后续空 tombstone 或不同执行证据不能覆盖本地成功记录。配置变更导致目标不符时 fail closed，需恢复旧受信目标/client 后由原任务继续处理，不能改写任务身份。详情见 MoNexus `server/src/modules/fakaBridge/README.md`。

MoNexus B3b3 已接通网站本人稳定 link 结账，默认 `XBOARD_PAID_RESET_TARGET_V2_ENABLED=false`，仅受支持的 plan-<正 SQL INT>-reset_traffic/reset_price 规格进入新分支。脱敏选择、UUID 与语义目标版本贯穿 preview、订单、幂等指纹和登录/充值回跳；多账号明确选择，新鲜远端资格在事务外读取、事务内锁定 link 重验。仅此分支绕过 Xboard 邮箱 OTP/邮箱表单及新增订阅容量，本站邮箱验证、非邮箱表单、权限、价格、库存仍保留。创建 v2 商业 task，注册邮箱不充当远端身份；失败重试保持原幂等意图，不降级邮箱。

网站已有真实 PostgreSQL 定向授权/履约验证与单条受控 API 浏览器冒烟，未进行真实站点/Xboard/QQ 联调。关闭新结账开关后原 v2 task 仍需对账/撤销，须保留旧受信来源/client/存储。B3b4 已新增签名 purchase-reset：按本人稳定账号新鲜当前 plan 匹配可售 reset 规格、重验远端付费资格，返回 productId/offerId/accountLinkId/参考价；AstrBot 0.2.0 只在固定站点构建 /product 链接，购买开关独立默认关闭，不直接扣款。profile 已按冻结身份、完整执行证据及语义 hash 复验付费 v2 历史，按实例/稳定远端账号/买家归并并去重，执行后退款仍计；最近最多1000条任务，超出标 truncated，仅显示已核实下限。旧 v1/站外未覆盖。部署版本一致和真实 AstrBot/NapCat 验收仍待完成；新入口默认关闭、未部署。

发布准备 B4：固定核心/插件一起构建及容器文件核对见 [构建说明](../release/xboard-reset/README.md)。build 仅产生本地 imageId 与清单，没有 registry digest 或生产部署；check 的 productionReady 始终 false，不能把文件一致当作迁移/能力或真实 QQ 验收。
