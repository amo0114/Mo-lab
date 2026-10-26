# MoNexus 套餐与流量（AstrBot + NapCat）

版本 0.2.1，B3a/B3b4 已实现，查询/免费重置/付费购买链接默认关闭。使用已有 MoNexus QQ 绑定和成功购买关联；注册邮箱可以与 Xboard 邮箱不同。本包只需要 MoNexus 的 BOT_SHARED_SECRET，不持有 Xboard 管理/bridge 密钥。

已提供查询套餐、账号列表/选择、赠送机会、确认、进度及当前套餐付费重置购买链接。购买只返回指向本人稳定账号的站内入口，不直接扣积分；价格以网站结账为准。普通用户回复仅展示免费机会的可用状态及下一步操作，不展示内部系统名、协议版本或多套累计次数。后台历史统计和消费记录继续保留；自动周期次数不当作可用机会。

## 安装准备

- AstrBot 4.x（元数据约束 >=4.16,<5），NapCat 通过 OneBot v11 / aiocqhttp 连接。2026-10-25 只读核对现用 AstrBot 4.27.2、NapCat 4.7.78、httpx 0.28.1，静态 API 与依赖条件符合；0.2.0 已安装到该实例并开启查询，真实重置/付款尚未验收；当前生产状态以部署记录为准。具体证据和步骤见 [实际部署核对](https://github.com/amo0114/MoNexus/blob/feat/performance-qq-xboard-20261025/docs/specs/2026-10-25-performance-and-qq-xboard/production-readiness.md)。
- 从 Mo-lab 的 `astrbot-plugins/astrbot_plugin_monexus_xboard/` 复制整个目录到 AstrBot 的 `data/plugins/astrbot_plugin_monexus_xboard/`，或在 WebUI 上传本包 ZIP。Mo-lab 是多项目仓库，不能把其根 URL 当作单插件 Git 安装地址。
- 保持入口 main.py、metadata.yaml、_conf_schema.json 等在插件目录同一层，依赖为 httpx>=0.28.1,<0.29。重载插件后在配置页填写站点和机器人密钥。
- 将 AstrBot 的 `data/plugin_data/astrbot_plugin_monexus_xboard/` 放在持久卷；程序按 AstrBot 工作目录下的 data 路径存储最小 SQLite 上下文。不要把此目录放进插件 ZIP 或代码仓库，不在多个 AstrBot 进程中同时共享该数据目录。

## 配置和启用顺序

| 配置 | 默认 / 含义 |
| --- | --- |
| query_enabled | false；打开查询，必须已配置站点和 bot 密钥 |
| free_reset_enabled | false；允许新免费重置与确认；关闭后仍可只读查找已接受的操作 |
| paid_reset_purchase_enabled | false；允许获取当前套餐对应的站内付费重置入口，独立于免费确认开关 |
| site_url | 空；固定 MoNexus HTTPS origin，例如 https://shop.example.com，不含 /api、账号密码、路径、query 或 fragment |
| bot_shared_secret | 空；对应后端 BOT_SHARED_SECRET，WebUI 遮罩展示，不填入 Xboard 密钥 |

先部署并验证后端 B1/B2 和本包新增的 reset-intent-status 接口，确认 Redis、防重放、稳定购买关联和正确 Xboard 核心适配，再启 query_enabled；内部测试 QQ 通过后才开 free_reset_enabled。插件开关不能替代后端开关。付费入口还需后端 XBOARD_PAID_RESET_TARGET_V2_ENABLED 与插件 paid_reset_by_user_id 能力就绪，核对 B3b3 网站稳定账号结账和实际部署版本后再开 paid_reset_purchase_enabled；不需要同时开启免费执行。

重载插件以应用配置。不要在日常输出、截图或日志中打印配置；WebUI secret 遮罩不等于配置文件已加密。仅关闭 free_reset_enabled 时，已有已知操作和响应丢失的确认仍可只读查询；完全关闭 query_enabled 后机器人停止访问后端，MoNexus worker 仍负责其自身对账。

## 命令

按 AstrBot 现有唤醒前缀输入以下命令（例如 /我的套餐）。只接收 aiocqhttp 平台的真实消息 sender，不接收代查 QQ、邮箱或用户 ID。

| 命令 | 行为 |
| --- | --- |
| 我的套餐 / 查流量 | 当前账号套餐、剩余/总流量、到期、快照时间及异常标记 |
| 我的账号 | 本人的脱敏账号列表，序号有效 10 分钟 |
| 切换套餐账号 2 | 选择刚展示的本人账号；改变默认不影响已发出的重置意图；不带序号可核对上次未知切换 |
| 重置次数 | 免费可用次数及下一步；区分未发放、待确认、处理中、已用完，按实际功能开关给出操作提示 |
| 重置流量 | 获取 120 秒、6 位 Base32 确认码和明确目标，不立即执行 |
| 确认重置 ABC2D7 | 明确确认消耗 1 次；202 显示等待/处理中，成功需再查询 |
| 重置进度 | 查看最近一次操作；确认响应丢失时从原意图只读找回操作号 |
| 购买重置 | 当前默认账号对应重置规格和本人账号的站内链接；无匹配商品或资格不符时不跳转其他套餐 |

群聊中的上述命令统一提示用户主动私聊，**群聊不查询后端、不展示账号、流量或确认码、不自动加好友/发私信**。NapCat 不能私聊陌生人时按现有 QQ 规则先建立可用私聊，不在群里索要代码。适配器本身不发送定时提醒或主动私信。

## 超时、重启与取消

HTTP 异步调用，连接 3 秒/单次阶段 8 秒、含重试总计最多 30 秒，网络异常及 5xx 最多 3 次有限重试；同 body/requestId 原样重发，每次新时间戳/nonce。拒绝跨站重定向、环境代理及超大/非法响应，不回显远端原文或配置。

保存每个 QQ/站点的最近账号列表 UUID、待选目标、意图 ID、请求 ID 和最近 operation ID；不保存明文确认码或完整套餐快照。数据库写入失败会阻止确认发出。确认不确定时保留上下文，阻止创建新的意图；重发原确认码仍对应原请求号，重启后同样可恢复。

“重置进度”优先凭 intentId 调 reset-intent-status 找回丢失 operation ID。operation=null 仅代表本次未观察到提交，继续待核对，不释放、不自动创建另一操作。新重置关闭时该查询仍可用。用户可主动稍后再查，适配器没有后台轮询，避免消耗共享 10 次/分钟配额。

QQ 解绑/换绑或资源不属于当前绑定时清除本机旧上下文；不删除后端 grant/outbox/幂等历史，也不擅自退还机会。没有用户可用的“强制退次”命令，异常取消由后端受审计运维入口处理。

## 定向验证和发布限制

从 Mo-lab 根运行：

```bash
python3 -m unittest discover -s tests/astrbot -p 'test_*.py' -v
python3 scripts/package-astrbot-xboard.py --output /tmp/astrbot_plugin_monexus_xboard-0.2.1.zip
```

测试包括跨 TypeScript/PHP/Python 固定签名向量、原始字节和 fresh nonce、HTTP 错误/重定向/限额、真实 sender、群聊不访问后端、默认开关、目标冻结、并发确认、重启恢复、结果未知、失去绑定及安装包白名单。HTTP 为受控 transport，AstrBot 入口测试使用 API doubles，**不等于完整 AstrBot 运行或真实 QQ 会话验收**。

B3b1–B3b4 已在独立 worktree 本地实现：稳定 ID 商业协议、任务/退款对账、网站结账、QQ 商品匹配及有覆盖范围的付费次数；固定核心忽略 native false 已通过事务后置验证适配。第十二批已核对实际 Xboard digest 与 AstrBot/NapCat 版本；核心/插件升级与查询部署已完成，免费/付费执行仍关闭；完整上线仍需真实重置/付款及受控内部验收。0.2.1 为回复文案更新，不改赠送发放、消费或订单逻辑。

接口依据（2026-10-25 通过 GrokSearch 核验）：[插件开发/安装与依赖](https://docs.astrbot.app/dev/star/plugin-new.html)、[命令、参数和平台过滤](https://docs.astrbot.app/dev/star/guides/listen-message-event.html)、[配置与 secret](https://docs.astrbot.app/dev/star/guides/plugin-config.html)。


## 0.2.1 用户回复调整

“重置次数”以实际免费余额为准：未发放不会显示成已用完；处理中提示查进度；余额已用完才提示后续购买，购买入口关闭时明确说明。普通用户不再看到底层系统名、协议版本、各来源累计重置和退款统计说明；后端统计不删除。机器人或服务端免费入口关闭时，不提示立即执行。无购买关联的账号改为提示核对下单账号/订单，不再保证正在同步。
