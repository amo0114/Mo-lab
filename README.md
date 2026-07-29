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
| Callback | `POST /api/v1/plugin/faka-bridge/order-paid` |
| Status | v1.1.0 logic hardened (idempotent claim / config / open.after); MoNexus client not started |
| Docs | [API Contract](docs/monexus-xboard-contract.md) · [Deploy](docs/faka-bridge-deploy.md) · [Plugin README](xboard-plugins/FakaBridge/README.md) |

## License

Private — personal use.
