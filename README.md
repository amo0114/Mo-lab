# Mo-lab

Amo's personal lab — monorepo for homelab projects, plugins, and tools.

## Structure

```
mo-lab/
├── xboard-plugins/          # Xboard plugins
│   └── faka-bridge/         # MoNexus ↔ Xboard bridge plugin
├── docs/                    # Documentation & contracts
│   └── monexus-xboard-contract.md
├── docker-compose/          # Docker Compose stacks (future)
└── README.md
```

## Projects

### FakaBridge (Xboard Plugin)

Bridge plugin that connects MoNexus (发卡网) to Xboard — when a user purchases a plan via MoNexus points, the plugin automatically creates/renews their Xboard subscription.

- **Status**: ✅ Deployed & Verified
- **Docs**: [API Contract](docs/monexus-xboard-contract.md)

## License

Private — personal use.
