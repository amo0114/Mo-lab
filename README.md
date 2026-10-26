# Mo-lab

Amo's personal lab — monorepo for homelab projects, plugins, and tools.

## Structure

```
mo-lab/
├── xboard-plugins/
│   └── FakaBridge/          # MoNexus ↔ Xboard bridge (deploy dir name must stay FakaBridge)
├── docs/
│   ├── monexus-xboard-contract.md
│   └── faka-bridge-deploy.md
└── README.md
```

## Projects

### FakaBridge (Xboard Plugin)

Bridge plugin that connects MoNexus (发卡网) to Xboard — when a user purchases a plan via MoNexus points, the plugin creates/renews their Xboard subscription.

| Item | Value |
|------|--------|
| Plugin code | `faka_bridge` |
| Deploy path | `plugins/FakaBridge/` on Xboard host |
| Image baseline | `ghcr.io/cedar2025/xboard:new` |
| Callback | `POST /plugin/faka-bridge/order-paid` |
| Status | v1.1.1 production verified on v.uuwu.de (path **without** /api/v1); MoNexus client not started |
| Docs | [API Contract](docs/monexus-xboard-contract.md) · [Deploy](docs/faka-bridge-deploy.md) · [Plugin README](xboard-plugins/FakaBridge/README.md) |

Local next version: FakaBridge 1.8.0 includes opt-in v2 account reads, free resets, and the stable-account paid reset protocol. See the
[v2 read contract](docs/fakabridge-v2-read-contract.md) and targeted offline harness
`bash tests/run-fakabridge-v2-read.sh`. This version has not been deployed.

## License

Private — personal use.

B2 免费重置、固定核心适配构建、MySQL 隔离验证和回退边界见 [FakaBridge v2 免费重置契约](docs/fakabridge-v2-reset-contract.md)。开关默认关闭，尚未部署或完成真实 QQ 验收。

B3a 提供独立分发的 [AstrBot + NapCat 套餐适配器](astrbot-plugins/astrbot_plugin_monexus_xboard/README.md)：查询、选择、一次赠送确认/进度及持久化恢复。可构建安装 ZIP，默认关闭、未部署；AstrBot 0.2.0 已接稳定 ID 付费购买引用与有范围的次数，购买开关独立默认关闭。

B3b1 已实现 [稳定账号付费重置协议](docs/fakabridge-v2-paid-reset-contract.md) 和两端严格 transport；B3b2–B3b4 已本地接通商业 worker/对账、网站稳定账号结账、QQ 购买引用及已核实 v2 次数；未部署、真实 QQ 尚未验收。

第十三批按已确认的生产SQLite/WAL补齐事务支持及真实文件多进程验证，核心补丁/发布清单已更新；保持现用数据库，无生产迁移或重启。部署条件与定向证据见上述免费/付费契约。

B4 补充：[Xboard 重置构建与容器文件核对](release/xboard-reset/README.md) 提供固定基镜像/核心补丁构建、白名单源码 ZIP 及明确指定 web/worker 的文件摘要核对。只在本地离线临时容器验证，不自动部署或发送 QQ 消息，不能替代实际迁移和运行验收。
