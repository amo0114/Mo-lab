# FakaBridge v2 免费重置契约（1.8.0 / B2及SQLite适配）

日期：2026-10-25。已实现两端代码及隔离定向验证，未部署。沿用 [只读签名契约](./fakabridge-v2-read-contract.md)，真实 QQ 适配器和稳定 ID 付费重置另属 B3。

## 接口和幂等

四个 POST，原始字节 HMAC v2、无 query、8 KiB、严格字段、共享数据库 nonce、防重放失败拒绝、所有响应 private,no-store：

| 后缀 `/plugin/faka-bridge/v2/` | JSON 字段 |
| --- | --- |
| traffic-reset-preview | request_id, user_id |
| traffic-reset | request_id + 下列固定操作字段 |
| traffic-reset-status | request_id + 同一组固定操作字段 |
| traffic-reset-cancel | request_id + 同一组固定操作字段 |

操作字段为 operation_id、actor_ref（MoNexus 用户 ID）、user_id（Xboard 稳定用户 ID）、benefit_code 固定 welcome_traffic_reset_v1、expected_plan_id、subscription_fingerprint。UUID 为小写 v4，ID 为 PHP_INT_MAX 以内的正十进制字符串。client ID 从插件服务端配置取得，调用方不能传。每次重试只换 nonce/request_id，固定操作字段不变。

payload_hash = SHA256(compact_JSON([operation_id,actor_ref,user_id,benefit_code,expected_plan_id,subscription_fingerprint]))。同 client/operation 异语义为 409 OPERATION_CONFLICT。预览 fingerprint 按顺序 compact_JSON 对象 plan_id/transfer_enable/expired_at/last_reset_at/reset_count/next_reset_at 求 SHA256，均为原始十进制字符串或 null；不含持续增长的 u/d。两仓库 fixtures/traffic-reset-v2.json 提供独立 Python 计算的固定向量。

预览返回 preview:{user_id,plan_id,fingerprint,eligible,block_reason,snapshot}。操作返回 operation:{operation_id,payload_hash,status,error_code,before,after,completed_at}，共有 success/protocol_version/request_id/queried_at envelope。snapshot 顺序在上述六字段后追加 u/d。成功结果保存执行时快照，重复请求返回原结果，不能用新流量改写历史。

操作 status 为 not_found/succeeded/failed/cancelled；未提交中的操作不返回假终态。not_found 无快照/完成时间/错误，绝不能据此释放本地预占。failed/cancelled 有完成时间和有限原因、无前后快照；succeeded 有快照、无错误，u/d 清零且计数加一，plan/总额/到期不变。常见原因为 SUBSCRIPTION_INELIGIBLE、SUBSCRIPTION_CHANGED、NOTHING_TO_RESET、ACCOUNT_NOT_FOUND、BENEFIT_ALREADY_CONSUMED、CANCELLED；基础签名/存储错误沿用只读协议，不透传异常。

## 事务与核心适配

新增事务操作表和消费表（MySQL 使用 InnoDB，SQLite 使用同一文件型 WAL 主库）。operation 按 client/operation 唯一；消费按 client/benefit/actor 及 client/benefit/user 双重唯一，分别限制站内用户跨账号和远端账号跨站内用户。记录不可清除或换 client ID 绕过。

execute 与 cancel 共享操作行锁，execute 再锁原生 User，校验当前套餐、白名单、封禁、到期、周期及指纹。原生重置、核心日志/计数、消费和 succeeded 在同连接外层事务提交；原生 false/异常/更新事件 veto 或成功 SQL 后崩溃全部回滚。无商业订单、账号创建、余额扣除或佣金。cancel 先提交会留下 tombstone 拦截迟到执行；execute 已提交则 cancel 返回原 succeeded。

**仅支持经过摘要校验的核心适配产物**：

- 固定测试镜像 ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8，revision 935522d28bbeb666824facd8bdd674db8ab37df1。
- 原 /www/app/Services/TrafficResetService.php SHA256：147f535d7cb3a5cefb2569d6e03102be0ab71b89f21ea4d6ca79c683fe18a26d。
- patches/prepare-atomic-reset.py 当前构建产物 SHA256：75feffb645e22def3f2afb8789b141cb848e5b970e9bc468d5f704993c5798c9。第十三批加入 SQLite 原生写锁，取代旧 e803de1c 补丁；未知来源拒绝构建，从原镜像文件生成，不对已打补丁文件重复执行。
- NativeResetAdapter 要求精确核心摘要，User/Plan/TrafficResetLog 与默认连接对象相同。MySQL 要求 v2_user/v2_plan/v2_traffic_reset_logs 及两张操作表均 InnoDB；SQLite 要求同一真实 main 文件、WAL、synchronous 至少 NORMAL、query_only 关闭、无额外 attached 数据库、关键表存在于 main 且没有 TEMP 名称遮蔽。内存库仍只允许显式隔离测试绑定，不作为生产支持。

补丁让所有 performReset 取同一用户行锁并刷新流量/计数，保留未保存的购买套餐/到期等字段；checkAndReset 在锁内重判周期，防止旧 cron 对象重复清零。原生缓存清理及 after hook 延到外层 afterCommit，异常被有限日志捕获，不把已提交重置误报为可重试失败。afterCommit 回调不是持久化消息队列；提交后进程退出仍可能漏发外部 hook，不宣称外发恰好一次。

SQLite 没有 FOR UPDATE 行锁：桥接 execute/cancel 先执行 INSERT OR IGNORE 取得数据库写锁，再读操作/用户；原生 performReset/checkAndReset 在事务内先对 v2_user 执行零行 UPDATE，取得写锁后才读快照。这不改变任何用户行，但把所有写者串行化。busy/快照升级失败必须回滚并以同一业务 ID、fresh nonce 重试，不能改号或当作未执行终态。能力检查只读 PRAGMA，不在生产请求中切换 journal/synchronous/timeout。

## 构建与发布准备

以下只描述构建产物，不代表生产已发布。先取得真实部署 digest 并复核；“最新版”不构成匹配证明。不要在运行容器内临时覆盖核心文件，web 与 horizon 必须使用同一构建。

```bash
# 在插件仓库根运行；源始终为已固定原镜像。
reset_build_dir=$(mktemp -d)
docker run --rm --network none --entrypoint cat \
  ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8 \
  /www/app/Services/TrafficResetService.php > "$reset_build_dir/original.php"
python3 patches/prepare-atomic-reset.py --source "$reset_build_dir/original.php" --output "$reset_build_dir/TrafficResetService.php"
# 自定义镜像的构建上下文应包含上述产物与本仓库 FakaBridge。
# Dockerfile 基于相同 digest，并 COPY：
# TrafficResetService.php -> /www/app/Services/TrafficResetService.php
# xboard-plugins/FakaBridge -> /www/plugins/FakaBridge
```

使用现有 PluginManager 安装/升级入口应用新增 migration，不能只复制文件；安装/更新 loader 已在契约测试验证。迁移后核对 nonce/operations/consumptions 表与索引及当前插件版本（1.8.0 另含商业身份迁移），重启常驻 web/horizon 以加载同版本。

默认 v2_read_enabled=false、v2_reset_enabled=false、v2_reset_plan_ids 为空、v2_client_id=monexus。先读再写；套餐白名单为无空白逗号分隔正 ID。reset_idempotency 仅在 reset 开关、白名单、存储及核心适配全满足时为 true；reset_status/cancel_tombstone 在事务存储就绪时独立可用；paid_reset_by_user_id 自 1.8.0 起由独立付费开关和核心/存储门禁决定，默认 false；见 [付费协议](./fakabridge-v2-paid-reset-contract.md)，MoNexus 网站及 AstrBot 0.2.0 购买引用已本地接入，仍未部署。关闭 reset 开关继续保留 status/cancel、认证和表，以便核对未知操作，不删除成功/取消/消费记录。

## 定向证据和限制

- bash tests/run-fakabridge-v2-read.sh：81 处断言（只读回归）。
- bash tests/run-fakabridge-v2-reset.sh：73 处断言，真实 Laravel/Eloquent/native 服务，SQLite 内存隔离夹具。
- bash tests/run-fakabridge-v2-reset-mysql.sh：第十三批97处断言，MySQL 8.4.11/InnoDB，独立连接/进程及屏障覆盖execute/cancel两种先后、跨用户/账号终身唯一及同操作重复执行；脚本只创建可丢弃tmpfs库和socket volume并自动清理。就绪条件要求认证查询成功，mysqladmin ping不能证明账号已初始化。
- 第十三批 `bash tests/run-fakabridge-v2-reset.sh reset sqlite-wal`：104处断言，真实文件型SQLite/WAL、无隔离测试绑定；独立连接先验证写锁确实冲突，再在真实写语句前握手，覆盖取消两种先后、跨账号/actor终身唯一及同操作重复执行。付费扩展另覆盖跨协议/原生竞争及SIGKILL，见付费契约。

覆盖重复执行后的新流量不再次清零、响应语义/指纹、明确失败、native false、Eloquent veto、核心写入后外层提交前故障回滚、延迟 cron、开关关闭后查状态/取消、无商业单/用户新增、跨语言向量。断言数不是独立测试数。

框架 harness 仅最小核心表夹具，没有完整 Kernel/Octane/Horizon、节点上报、完整生产 schema 或真实 QQ 联调。第十二/十三批已核对生产基础镜像及SQLite/WAL，但没有部署新补丁/插件。尚无所有第三方写入和复杂购买并发的全站验收证据；上线灰度仍待完成。文件夹具在容器tmpfs，SIGKILL证明进程中断回滚，不代表掉电或文件系统故障测试。

B4 已补齐固定输入镜像构建和受信清单核对，参阅 [构建/核对说明](../release/xboard-reset/README.md)。它取代上文手动拼装构建上下文的准备步骤，不改变核心适配或数据库业务；检查成功只证明容器落盘文件匹配，常驻加载、迁移、能力与真实 QQ 仍须验证。
