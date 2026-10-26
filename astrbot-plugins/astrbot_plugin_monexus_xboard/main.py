"""AstrBot + NapCat entry point. Commands never take a QQ number argument."""

from pathlib import Path

from astrbot.api import AstrBotConfig
from astrbot.api.event import AstrMessageEvent, filter
from astrbot.api.star import Context, Star, register

from .client import BotClient
from .service import Commands, PRIVATE_HINT
from .state import StateStore


@register(
    "astrbot_plugin_monexus_xboard", "MoNexus", "查询套餐及一次赠送流量重置", "0.2.1"
)
class MonexusXboard(Star):
    def __init__(self, context: Context, config: AstrBotConfig):
        super().__init__(context)
        self.config = config
        self.client = None
        self.store = None
        self.commands = None

    async def initialize(self):
        # No client/storage or mandatory credentials when disabled.
        if self.config.get("query_enabled") is not True:
            return
        self.client = BotClient(
            self.config.get("site_url", ""), self.config.get("bot_shared_secret", "")
        )
        try:
            # AstrBot's documented persistent data directory; survives updates.
            self.store = StateStore(
                Path("data/plugin_data/astrbot_plugin_monexus_xboard")
                / "context.sqlite3"
            )
            self.commands = Commands(
                self.client,
                self.store,
                queries_enabled=True,
                reset_enabled=self.config.get("free_reset_enabled") is True,
                purchase_enabled=self.config.get("paid_reset_purchase_enabled") is True,
            )
        except Exception:
            await self.client.close()
            raise RuntimeError(
                "MoNexus adapter initialization failed; check its data directory"
            ) from None

    async def terminate(self):
        if self.client:
            await self.client.close()
        if self.store:
            await self.store.close()

    async def _run(self, event, action, argument=""):
        event.stop_event()
        # Fail closed before touching the client or state in group contexts.
        if event.get_group_id():
            return event.plain_result(PRIVATE_HINT)
        if not self.commands:
            return event.plain_result("套餐查询暂未开放。")
        response = await self.commands.handle(
            str(event.get_sender_id()), True, action, argument
        )
        return event.plain_result(response)

    @filter.command("我的套餐", alias={"查流量"})
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def profile(self, event: AstrMessageEvent):
        yield await self._run(event, "profile")

    @filter.command("我的账号")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def accounts(self, event: AstrMessageEvent):
        yield await self._run(event, "accounts")

    @filter.command("切换套餐账号")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def select(self, event: AstrMessageEvent, index: str = ""):
        yield await self._run(event, "select", index)

    @filter.command("重置次数")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def counts(self, event: AstrMessageEvent):
        yield await self._run(event, "counts")

    @filter.command("重置流量")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def reset(self, event: AstrMessageEvent):
        yield await self._run(event, "reset")

    @filter.command("确认重置")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def confirm(self, event: AstrMessageEvent, code: str = ""):
        yield await self._run(event, "confirm", code)

    @filter.command("重置进度")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def status(self, event: AstrMessageEvent):
        yield await self._run(event, "status")

    @filter.command("购买重置")
    @filter.platform_adapter_type(filter.PlatformAdapterType.AIOCQHTTP)
    async def purchase(self, event: AstrMessageEvent):
        yield await self._run(event, "purchase")
