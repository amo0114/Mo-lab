"""Adapter boundary with AstrBot API doubles; not a live QQ acceptance test."""

import importlib
import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "astrbot-plugins"))


class Filters:
    PlatformAdapterType = types.SimpleNamespace(AIOCQHTTP="aiocqhttp")

    @staticmethod
    def command(name, **kwargs):
        def decorate(fn):
            fn.command = (name, kwargs)
            return fn

        return decorate

    @staticmethod
    def platform_adapter_type(platform):
        def decorate(fn):
            fn.platform = platform
            return fn

        return decorate


class Star:
    def __init__(self, context):
        self.context = context


class Event:
    def __init__(self, *, sender="12345678", group=""):
        self.sender, self.group = sender, group
        self.stopped = False

    def stop_event(self):
        self.stopped = True

    def get_group_id(self):
        return self.group

    def get_sender_id(self):
        return self.sender

    def plain_result(self, message):
        return message


def load_entry():
    api = types.ModuleType("astrbot.api")
    api.AstrBotConfig = dict
    event = types.ModuleType("astrbot.api.event")
    event.AstrMessageEvent, event.filter = Event, Filters
    star = types.ModuleType("astrbot.api.star")
    star.Context, star.Star = object, Star
    star.register = lambda *args, **kwargs: lambda cls: cls
    with patch.dict(
        sys.modules,
        {
            "astrbot": types.ModuleType("astrbot"),
            "astrbot.api": api,
            "astrbot.api.event": event,
            "astrbot.api.star": star,
        },
    ):
        sys.modules.pop("astrbot_plugin_monexus_xboard.main", None)
        return importlib.import_module("astrbot_plugin_monexus_xboard.main")


class Entry(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.module = load_entry()
        self.plugin = self.module.MonexusXboard(object(), {})

    async def test_all_eight_commands_are_qq_only(self):
        for method in [
            "profile",
            "accounts",
            "select",
            "counts",
            "reset",
            "confirm",
            "status",
            "purchase",
        ]:
            self.assertEqual(getattr(self.plugin, method).platform, "aiocqhttp")
            self.assertTrue(getattr(self.plugin, method).command[0])
        schema = json.loads(
            (
                ROOT / "astrbot-plugins/astrbot_plugin_monexus_xboard/_conf_schema.json"
            ).read_text()
        )
        self.assertFalse(schema["query_enabled"]["default"])
        self.assertFalse(schema["free_reset_enabled"]["default"])
        self.assertFalse(schema["paid_reset_purchase_enabled"]["default"])
        self.assertTrue(schema["bot_shared_secret"]["secret"])

    async def test_disabled_plugin_initializes_without_credentials_or_data(self):
        with (
            patch.object(
                self.module,
                "BotClient",
                side_effect=AssertionError("must not open client"),
            ),
            patch.object(
                self.module,
                "StateStore",
                side_effect=AssertionError("must not open database"),
            ),
        ):
            await self.plugin.initialize()
        self.assertIsNone(self.plugin.commands)
        await self.plugin.terminate()

    async def test_paid_switch_is_passed_explicitly_and_does_not_require_free_switch(
        self,
    ):
        self.plugin.config = {
            "query_enabled": True,
            "site_url": "https://shop.test",
            "bot_shared_secret": "test-only",
            "paid_reset_purchase_enabled": True,
        }
        with (
            patch.object(self.module, "BotClient"),
            patch.object(self.module, "StateStore"),
            patch.object(self.module, "Commands") as commands,
        ):
            await self.plugin.initialize()
            self.assertTrue(commands.call_args.kwargs["purchase_enabled"])
            self.assertFalse(commands.call_args.kwargs["reset_enabled"])

    async def test_group_invocation_is_stopped_before_sender_and_client_use(self):
        self.plugin.commands = types.SimpleNamespace(handle=AsyncMock())
        event = Event(sender="99999999", group="77777777")
        response = [result async for result in self.plugin.confirm(event, "ABC2D7")]
        self.assertIn("私聊", response[0])
        self.assertTrue(event.stopped)
        self.plugin.commands.handle.assert_not_awaited()

    async def test_private_invocation_passes_actual_sender_and_argument_separately(
        self,
    ):
        self.plugin.commands = types.SimpleNamespace(
            handle=AsyncMock(return_value="正在处理")
        )
        event = Event(sender="12345678")
        response = [result async for result in self.plugin.select(event, "2")]
        self.assertEqual(response, ["正在处理"])
        self.plugin.commands.handle.assert_awaited_once_with(
            "12345678", True, "select", "2"
        )
        self.assertTrue(event.stopped)


if __name__ == "__main__":
    unittest.main()
