"""Focused adapter tests; no network, production account or AstrBot process."""

import asyncio
import hashlib
import json
import sys
import tempfile
import unittest
import uuid
from pathlib import Path

import httpx

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "astrbot-plugins"))
from astrbot_plugin_monexus_xboard.client import (  # noqa: E402 -- repo plugin path
    BotClient,
    BotError,
    signed_headers,
    validate_origin,
)
from astrbot_plugin_monexus_xboard.service import Commands  # noqa: E402
from astrbot_plugin_monexus_xboard.state import StateStore  # noqa: E402

ACCOUNT = str(uuid.uuid4())
OTHER = str(uuid.uuid4())
INTENT = str(uuid.uuid4())
OPERATION = str(uuid.uuid4())
SENDER = "12345678"
PROFILE = {
    "bound": True,
    "account": {"id": ACCOUNT, "maskedEmail": "ab***@panel.test"},
    "subscription": {
        "status": "active",
        "planName": "测试套餐",
        "totalBytes": "107374182400",
        "remainingBytes": "53687091200",
        "expiresAtUnix": None,
    },
    "freeReset": {
        "granted": 1,
        "available": 1,
        "reserved": 0,
        "consumed": 0,
        "status": "active",
    },
    "canFreeReset": True,
    "blockReason": None,
    "resetHistory": {
        "freeSuccessfulForAccount": 0,
        "platformSuccessfulForAccount": None,
        "xboardAllSources": "9",
        "coverageScope": "free_reset_v2_only",
    },
    "freshness": {
        "stale": False,
        "unavailable": False,
        "queriedAt": "2026-10-25T00:00:00Z",
    },
}


def clone(value):
    return json.loads(json.dumps(value))


class Wire:
    def __init__(self):
        self.requests = []
        self.outcomes = []
        self.status = "queued"
        self.effects = 0
        self.confirmed = False
        self.target = ACCOUNT

    async def __call__(self, request):
        body = json.loads(request.content)
        expected = signed_headers(
            request.content,
            request.url.path,
            "test-only-bot-secret",
            int(request.headers["x-monexus-timestamp"]),
            request.headers["x-monexus-nonce"],
        )
        assert all(request.headers[k] == v for k, v in expected.items())
        assert body["platformId"] == SENDER and body["platform"] == "qq"
        self.requests.append(
            (request.url.path.rsplit("/", 1)[1], body, dict(request.headers))
        )
        action = self.requests[-1][0]
        if self.outcomes:
            result = self.outcomes.pop(0)
            if isinstance(result, BaseException):
                raise result
            return result
        if action == "accounts":
            result = {
                "bound": True,
                "accounts": [
                    {
                        "id": ACCOUNT,
                        "maskedEmail": "ab***@panel.test",
                        "status": "active",
                        "isDefault": self.target == ACCOUNT,
                    },
                    {
                        "id": OTHER,
                        "maskedEmail": "cd***@panel.test",
                        "status": "active",
                        "isDefault": self.target == OTHER,
                    },
                ],
                "truncated": False,
            }
        elif action == "profile":
            result = clone(PROFILE)
            result["account"]["id"] = self.target
        elif action == "purchase-reset":
            result = {
                "bound": True,
                "accountLinkId": body["accountLinkId"],
                "blockReason": None,
                "purchase": {
                    "productId": 7,
                    "offerId": 8,
                    "accountLinkId": body["accountLinkId"],
                    "price": 123,
                },
            }
        elif action == "select-default":
            self.target = body["accountLinkId"]
            result = {"bound": True, "selectedAccountLinkId": self.target}
        elif action == "reset-intents":
            result = {
                "bound": True,
                "intentId": INTENT,
                "accountLinkId": body["accountLinkId"],
                "confirmationCode": "ABC2D7",
                "expiresAt": "2026-10-25T00:02:00Z",
                "expired": False,
                "consumes": 1,
            }
        elif action == "resets":
            if body["confirmationCode"] != "ABC2D7" and not self.confirmed:
                return httpx.Response(
                    400,
                    json={
                        "error": {
                            "code": "TRAFFIC_RESET_CONFIRMATION_INVALID",
                            "message": "secret remote text",
                        }
                    },
                )
            if not self.confirmed:
                self.effects += 1
                self.confirmed = True
            result = {
                "bound": True,
                "operationId": OPERATION,
                "status": self.status,
                "replayed": self.confirmed,
            }
        elif action == "reset-intent-status":
            result = {
                "bound": True,
                "intentId": INTENT,
                "operation": {"operationId": OPERATION, "status": self.status}
                if self.confirmed
                else None,
            }
        elif action == "reset-status":
            result = {
                "bound": True,
                "operationId": OPERATION,
                "status": self.status,
                "before": {"secret": "never render"},
            }
        return httpx.Response(202 if action == "resets" else 200, json=result)


class Signatures(unittest.TestCase):
    def test_shared_independent_vectors(self):
        vectors = json.loads(
            (ROOT / "tests/fixtures/signed-protocol-v2.json").read_text()
        )["vectors"]
        for vector in vectors:
            raw = vector["rawBody"].encode()
            self.assertEqual(hashlib.sha256(raw).hexdigest(), vector["bodyHash"])
            headers = signed_headers(
                raw,
                vector["path"],
                vector["secret"],
                vector["timestampMs"],
                vector["nonce"],
                vector["audience"],
            )
            self.assertEqual(headers["x-monexus-signature"], vector["signature"])

    def test_origin_and_signature_reject_unsafe_configuration(self):
        for origin in [
            "http://shop.test",
            "https://user:pass@shop.test",
            "https://shop.test/api",
            "https://shop.test?x=1",
            "https://shop.test?",
            "https://shop.test#",
            "https://@shop.test",
            "https://shop.test#foo",
            "https://shop.test:0",
            "https://shop.test\\@evil.test",
            "https://shop.test\n",
        ]:
            with self.subTest(origin=origin), self.assertRaises(ValueError):
                validate_origin(origin)
        for path in ["/api//bot", "/api/bot/", "/api/bot?x=1", "/api/%62ot"]:
            with self.subTest(path=path), self.assertRaises(ValueError):
                signed_headers(b"{}", path, "secret", 1, "0" * 32)


class Adapter(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.path = Path(self.directory.name) / "persistent" / "context.sqlite3"
        self.store = StateStore(self.path)
        self.wire = Wire()
        self.client = BotClient(
            "https://shop.test",
            "test-only-bot-secret",
            transport=httpx.MockTransport(self.wire),
            sleep=self.no_sleep,
        )
        self.now = 1792886400.0  # 2026-10-25 00:00 UTC
        self.commands = Commands(
            self.client,
            self.store,
            queries_enabled=True,
            reset_enabled=True,
            clock=lambda: self.now,
        )

    async def no_sleep(self, _):
        pass

    async def asyncTearDown(self):
        await self.client.close()
        await self.store.close()
        self.directory.cleanup()

    async def call(self, action, argument="", private=True, sender=SENDER):
        return await self.commands.handle(sender, private, action, argument)

    async def test_private_boundary_blocks_all_group_actions_before_http(self):
        for action in [
            "profile",
            "accounts",
            "select",
            "counts",
            "reset",
            "confirm",
            "status",
            "purchase",
        ]:
            self.assertIn("私聊", await self.call(action, "ABC2D7", private=False))
        self.assertEqual(self.wire.requests, [])

    async def test_identity_is_never_taken_from_command_argument(self):
        self.assertIn("50.00 GiB", await self.call("profile", "99999999"))
        self.assertEqual(
            self.wire.requests[-1][1], {"platform": "qq", "platformId": SENDER}
        )
        self.assertIn("身份", await self.call("profile", sender="999"))
        with self.assertRaises(BotError):
            await self.client.call("profile", SENDER, platformId="99999999")

    async def test_queries_and_execution_have_separate_default_off_switches(self):
        self.commands.queries_enabled = False
        self.assertIn("未开放", await self.call("profile"))
        self.assertEqual(self.wire.requests, [])
        self.commands.queries_enabled = True
        self.commands.reset_enabled = False
        self.assertIn("未开放", await self.call("reset"))
        self.assertIn("50.00 GiB", await self.call("profile"))

    async def test_unbound_is_not_misreported_as_missing_xboard_binding(self):
        self.wire.outcomes = [httpx.Response(200, json={"bound": False})]
        result = await self.call("profile")
        self.assertIn("站内完成 QQ 绑定", result)
        self.assertIn("无需重新绑定", result)

    async def test_counts_show_available_opportunity_without_internal_history(self):
        result = await self.call("counts")
        self.assertIn("可用 1 次", result)
        self.assertIn("发送“重置流量”", result)
        for term in ["Xboard", "v2", "成功记录", "全来源", "9 次", "退款"]:
            self.assertNotIn(term, result)
        profile = await self.call("profile")
        self.assertIn("50.00 GiB", profile)
        self.assertNotIn("全来源", profile)
        self.assertNotIn("v2", profile)

    async def test_counts_respect_local_and_backend_execution_gates(self):
        self.commands.reset_enabled = False
        result = await self.call("counts")
        self.assertIn("可用 1 次", result)
        self.assertIn("未开放，次数会保留", result)
        self.assertNotIn("发送“重置流量”", result)
        self.commands.reset_enabled = True
        body = clone(PROFILE)
        body.update(canFreeReset=False, blockReason="RESET_FEATURE_NOT_ENABLED")
        self.wire.outcomes = [httpx.Response(200, json=body)]
        self.assertIn("未开放，次数会保留", await self.call("counts"))

    async def test_counts_distinguish_missing_pending_processing_and_used(self):
        for free, expected, forbidden in [
            (None, "尚未发放", "剩余 0 次"),
            ({"status": "pending_verification", "available": 0}, "资格待确认", "剩余 0 次"),
            ({"status": "revoked", "available": 0}, "当前不可用", "赠送机会已使用"),
            ({"available": 0, "reserved": 1}, "1 次处理中", "购买重置"),
            ({"available": 0, "consumed": 1}, "剩余 0 次", "发送“重置流量”"),
        ]:
            with self.subTest(free=free):
                body = clone(PROFILE)
                if free is None:
                    body["freeReset"] = None
                else:
                    body["freeReset"].update(free)
                self.wire.outcomes = [httpx.Response(200, json=body)]
                result = await self.call("counts")
                self.assertIn(expected, result)
                self.assertNotIn(forbidden, result)
                self.assertNotIn("Xboard", result)
                self.assertNotIn("v2", result)
        body = clone(PROFILE)
        body["freeReset"].update(available=0, consumed=1)
        self.wire.outcomes = [httpx.Response(200, json=body)]
        self.assertIn("购买入口暂未开放", await self.call("counts"))
        self.commands.purchase_enabled = True
        self.wire.outcomes = [httpx.Response(200, json=body)]
        self.assertIn("发送“购买重置”查看价格", await self.call("counts"))

    async def test_empty_accounts_gives_order_guidance_without_claiming_sync(self):
        self.wire.outcomes = [httpx.Response(200, json={"bound": True, "accounts": []})]
        result = await self.call("accounts")
        self.assertIn("暂未找到已购买", result)
        self.assertIn("下单时的 MoNexus 账号", result)
        self.assertNotIn("正在同步", result)
        self.assertNotIn("Xboard", result)

    async def test_unavailable_traffic_is_unknown_and_stale_is_labelled(self):
        body = clone(PROFILE)
        body["subscription"] = None
        body["freshness"].update(stale=True, unavailable=True)
        self.wire.outcomes = [httpx.Response(200, json=body)]
        result = await self.call("profile")
        self.assertIn("暂时查询不到", result)
        self.assertIn("上次查询结果", result)
        self.assertNotIn("0.00 GiB", result)

    async def test_account_selection_uses_the_shown_list_and_preserves_intent_target(
        self,
    ):
        await self.call("reset")
        self.assertIn("请先", await self.call("select", "1"))
        self.assertIn("1.", await self.call("accounts"))
        self.assertIn("已更新", await self.call("select", "2"))
        self.assertIn("ABC2D7", await self.call("reset"))
        intents = [
            body for action, body, _ in self.wire.requests if action == "reset-intents"
        ]
        self.assertEqual(intents[0], intents[1])
        self.assertEqual(intents[-1]["accountLinkId"], ACCOUNT)
        self.now += 601
        self.assertIn("请先", await self.call("select", "1"))

    async def test_list_selection_cannot_use_a_uuid_or_other_sender_number(self):
        await self.call("accounts")
        for value in [OTHER, "99999999", "0", "-1", "3"]:
            self.assertIn("序号", await self.call("select", value))
        self.assertFalse(
            any(action == "select-default" for action, _, _ in self.wire.requests)
        )

    async def test_confirmation_requires_explicit_valid_code(self):
        self.assertIn("先发送", await self.call("confirm", "ABC2D7"))
        self.assertIn("消耗 1 次", await self.call("reset"))
        self.assertFalse(any(action == "resets" for action, _, _ in self.wire.requests))
        self.assertIn("六位", await self.call("confirm", "abc2d7"))
        self.assertIn("有误", await self.call("confirm", "AAAAAA"))
        self.assertIn("等待处理", await self.call("confirm", "ABC2D7"))
        self.assertEqual(self.wire.effects, 1)

    async def test_accepted_is_not_rendered_as_success_and_status_is_not_raw_snapshot(
        self,
    ):
        await self.call("reset")
        result = await self.call("confirm", "ABC2D7")
        self.assertNotIn("成功", result)
        self.wire.status = "succeeded"
        self.assertIn("重置成功", await self.call("status"))
        self.assertNotIn("secret", await self.call("status"))

    async def test_parallel_duplicate_confirmations_create_no_new_intent(self):
        await self.call("reset")
        await asyncio.gather(*(self.call("confirm", "ABC2D7") for _ in range(10)))
        self.assertEqual(self.wire.effects, 1)
        self.assertEqual(
            sum(action == "resets" for action, _, _ in self.wire.requests), 1
        )
        self.assertIn("等待处理", await self.call("reset"))
        self.assertEqual(
            sum(action == "reset-intents" for action, _, _ in self.wire.requests), 1
        )

    async def test_client_retries_preserve_body_and_sign_each_attempt_with_fresh_nonce(
        self,
    ):
        self.wire.outcomes = [
            httpx.ReadTimeout("do not expose me"),
            httpx.Response(503, text="private backend stack"),
        ]
        request_id = str(uuid.uuid4())
        await self.client.call(
            "select-default", SENDER, accountLinkId=ACCOUNT, requestId=request_id
        )
        bodies = [body for _, body, _ in self.wire.requests]
        self.assertEqual(bodies, [bodies[0]] * 3)
        self.assertEqual(
            len({headers["x-monexus-nonce"] for _, _, headers in self.wire.requests}), 3
        )

    async def test_unknown_confirmation_survives_restart_and_replays_same_request_after_expiry(
        self,
    ):
        await self.call("reset")
        self.wire.outcomes = [httpx.ReadTimeout("private")] * 3
        self.assertIn("结果暂未核实", await self.call("confirm", "ABC2D7"))
        confirmations = [
            body for action, body, _ in self.wire.requests if action == "resets"
        ]
        original = confirmations[0]
        self.assertIn("上次确认", await self.call("reset"))
        await self.store.close()
        self.store = StateStore(self.path)
        self.commands = Commands(
            self.client,
            self.store,
            queries_enabled=True,
            reset_enabled=True,
            clock=lambda: self.now,
        )
        self.now += 300
        self.assertIn("等待处理", await self.call("confirm", "ABC2D7"))
        latest = [body for action, body, _ in self.wire.requests if action == "resets"][
            -1
        ]
        self.assertEqual(original, latest)
        self.assertEqual(self.wire.effects, 1)
        saved = self.path.read_bytes()
        self.assertNotIn(b"ABC2D7", saved)

    async def test_lost_accepted_response_recovers_read_only_with_reset_disabled(self):
        await self.call("reset")
        original = self.wire.__call__

        async def lose_response(request):
            response = await original(request)
            if request.url.path.endswith("/resets"):
                raise httpx.ReadTimeout("response lost after server accepted")
            return response

        await self.client.close()
        self.client = BotClient(
            "https://shop.test",
            "test-only-bot-secret",
            transport=httpx.MockTransport(lose_response),
            sleep=self.no_sleep,
        )
        self.commands.client = self.client
        self.assertIn("结果暂未核实", await self.call("confirm", "ABC2D7"))
        self.commands.reset_enabled = False
        self.wire.status = "succeeded"
        self.assertIn("重置成功", await self.call("status"))
        self.assertEqual(self.wire.requests[-1][0], "reset-intent-status")
        self.assertEqual(self.wire.effects, 1)

    async def test_missing_confirmation_operation_stays_pending_without_writes(self):
        await self.call("reset")
        self.wire.outcomes = [httpx.ReadTimeout("not reached")] * 3
        await self.call("confirm", "ABC2D7")
        self.assertIn("结果仍待核实", await self.call("status"))
        self.assertIn("上次确认", await self.call("reset"))
        self.assertEqual(self.wire.effects, 0)

    async def test_unknown_selection_must_resolve_before_switching_to_a_different_target(
        self,
    ):
        await self.call("accounts")
        self.wire.outcomes = [httpx.ReadTimeout("unknown")] * 3
        await self.call("select", "1")
        self.assertIn("重复原序号", await self.call("select", "2"))
        self.assertIn("已更新", await self.call("select", "1"))
        self.assertIn("已更新", await self.call("select", "2"))

    async def test_pending_selection_recovers_after_original_target_disappears(self):
        await self.call("accounts")
        self.wire.outcomes = [httpx.ReadTimeout("unknown")] * 3
        await self.call("select", "1")
        previous = [
            body for action, body, _ in self.wire.requests if action == "select-default"
        ][-1]
        self.wire.outcomes = [
            httpx.Response(
                200,
                json={
                    "bound": True,
                    "accounts": [
                        {
                            "id": OTHER,
                            "maskedEmail": "cd***@panel.test",
                            "status": "active",
                            "isDefault": False,
                        }
                    ],
                    "truncated": False,
                },
            )
        ]
        await self.call("accounts")
        self.assertIn("不带序号", await self.call("select", "1"))
        self.wire.outcomes = [
            httpx.Response(409, json={"error": {"code": "XBOARD_ACCOUNT_UNRESOLVED"}})
        ]
        self.assertIn("原目标已不可选择", await self.call("select"))
        self.assertEqual(self.wire.requests[-1][1], previous)
        self.assertIn("已更新", await self.call("select", "1"))
        self.assertEqual(self.wire.target, OTHER)

    async def test_binding_change_clears_old_context_and_never_reuses_an_old_intent(
        self,
    ):
        await self.call("reset")
        self.wire.outcomes = [
            httpx.Response(409, json={"error": {"code": "XBOARD_BINDING_CHANGED"}})
        ]
        await self.call("confirm", "ABC2D7")
        self.assertIn("先发送", await self.call("confirm", "ABC2D7"))

    async def test_progress_survives_restart_and_can_be_read_with_execution_disabled(
        self,
    ):
        await self.call("reset")
        await self.call("confirm", "ABC2D7")
        await self.store.close()
        self.store = StateStore(self.path)
        self.commands = Commands(
            self.client, self.store, queries_enabled=True, reset_enabled=False
        )
        self.wire.status = "succeeded"
        self.assertIn("重置成功", await self.call("status"))

    async def test_expired_intent_cannot_start_a_new_confirmation(self):
        await self.call("reset")
        self.now += 121
        self.assertIn("过期", await self.call("confirm", "ABC2D7"))
        self.assertFalse(any(action == "resets" for action, _, _ in self.wire.requests))

    async def test_failure_and_remote_errors_never_leak_the_raw_message(self):
        self.wire.outcomes = [
            httpx.Response(
                409,
                json={
                    "error": {
                        "code": "XBOARD_ACCOUNT_UNRESOLVED",
                        "message": "private@example.com password=secret",
                    }
                },
            )
        ]
        result = await self.call("profile")
        self.assertIn("核对订单", result)
        self.assertNotIn("private", result)
        self.wire.outcomes = [
            httpx.Response(200, json={"bound": True, "status": "succeeded"})
        ]
        self.assertNotIn("成功", await self.call("profile"))

    async def test_no_redirect_or_unknown_malformed_success_is_accepted(self):
        for response in [
            httpx.Response(302, headers={"location": "https://evil.test"}),
            httpx.Response(200, json=[]),
            httpx.Response(200, content=b"invalid json"),
            httpx.Response(200, json={"bound": 1}),
            httpx.Response(200, content=b"x" * 131073),
        ]:
            self.wire.outcomes = [response]
            with self.assertRaises(BotError):
                await self.client.call("profile", SENDER)
        self.assertEqual(len(self.wire.requests), 5)

    async def test_overall_deadline_bounds_a_stalled_request(self):
        async def stall(_request):
            await asyncio.sleep(1)
            return httpx.Response(200, json={"bound": True})

        client = BotClient(
            "https://shop.test",
            "test-only-bot-secret",
            transport=httpx.MockTransport(stall),
            deadline=0.01,
        )
        try:
            with self.assertRaises(BotError) as caught:
                await client.call("profile", SENDER)
            self.assertTrue(caught.exception.uncertain)
        finally:
            await client.close()

    async def test_paid_command_does_not_construct_email_or_unverified_sku_link(self):
        result = await self.call("purchase")
        self.assertIn("暂未开放", result)
        self.assertNotIn("http", result)
        self.assertEqual(self.wire.requests, [])

    async def test_paid_pointer_uses_selected_account_and_fixed_origin_without_charging(
        self,
    ):
        self.commands.purchase_enabled = True
        await self.call("accounts")
        await self.call("select", "2")
        self.commands.reset_enabled = False
        result = await self.call("purchase")
        self.assertIn(
            f"https://shop.test/product/7?offerId=8&xboardAccountLinkId={OTHER}", result
        )
        self.assertIn("123 积分", result)
        self.assertIn("不会使用赠送机会", result)
        self.assertEqual(self.wire.requests[-1][0], "purchase-reset")
        self.assertEqual(self.wire.requests[-1][1]["accountLinkId"], OTHER)
        self.assertEqual(self.wire.effects, 0)
        self.assertFalse(
            any(
                action in {"resets", "reset-intents"}
                for action, *_ in self.wire.requests
            )
        )

    async def test_paid_pointer_rejects_untrusted_components_and_mismatched_target(
        self,
    ):
        self.commands.purchase_enabled = True
        pointer = {"productId": 7, "offerId": 8, "accountLinkId": ACCOUNT, "price": 123}
        for change in [
            {"productId": "https://evil.test"},
            {"offerId": True},
            {"accountLinkId": OTHER},
            {"price": -1},
            {"productId": 2147483648},
            {"url": "https://evil.test/product/7"},
        ]:
            body = {
                "bound": True,
                "accountLinkId": ACCOUNT,
                "blockReason": None,
                "purchase": {**pointer, **change},
            }
            self.wire.outcomes = [
                httpx.Response(200, json=PROFILE),
                httpx.Response(200, json=body),
            ]
            result = await self.call("purchase")
            self.assertNotIn("http", result)
            self.assertNotIn("evil", result)
        body = {
            "bound": True,
            "accountLinkId": OTHER,
            "blockReason": None,
            "purchase": pointer,
        }
        self.wire.outcomes = [
            httpx.Response(200, json=PROFILE),
            httpx.Response(200, json=body),
        ]
        self.assertNotIn("http", await self.call("purchase"))

    async def test_paid_no_offer_or_ineligible_never_falls_back_to_another_product(
        self,
    ):
        self.commands.purchase_enabled = True
        for reason, expected in [
            ("NO_OFFER", "未配置"),
            ("SUBSCRIPTION_INELIGIBLE", "不符合"),
        ]:
            self.wire.outcomes = [
                httpx.Response(200, json=PROFILE),
                httpx.Response(
                    200,
                    json={
                        "bound": True,
                        "accountLinkId": ACCOUNT,
                        "purchase": None,
                        "blockReason": reason,
                    },
                ),
            ]
            result = await self.call("purchase")
            self.assertIn(expected, result)
            self.assertNotIn("http", result)

    async def test_counts_reject_inconsistent_once_only_grant_balances(self):
        for change in [
            {"available": 1, "reserved": 1, "consumed": 1},
            {"granted": 2},
            {"granted": True},
            {"status": "revoked", "available": 1},
        ]:
            body = clone(PROFILE)
            body["freeReset"].update(change)
            self.wire.outcomes = [httpx.Response(200, json=body)]
            self.assertNotIn("免费重置：可用", await self.call("counts"))

    async def test_history_is_still_validated_but_not_shown_as_a_balance(self):
        body = clone(PROFILE)
        body["resetHistory"].update(
            paidSuccessfulForAccount=2,
            platformSuccessfulForAccount=2,
            coverageScope="verified_v2_only",
            truncated=False,
        )
        self.wire.outcomes = [httpx.Response(200, json=body)]
        result = await self.call("counts")
        self.assertIn("可用 1 次", result)
        for term in ["2 次", "已核实", "付费成功", "v2", "Xboard", "退款"]:
            self.assertNotIn(term, result)
        body["resetHistory"]["truncated"] = True
        self.wire.outcomes = [httpx.Response(200, json=body)]
        self.assertEqual(result, await self.call("counts"))
        body["resetHistory"]["platformSuccessfulForAccount"] = 3
        self.wire.outcomes = [httpx.Response(200, json=body)]
        self.assertIn("服务暂时不可用", await self.call("counts"))

    async def test_local_storage_failure_blocks_before_confirmation_dispatch(self):
        await self.call("reset")
        original = self.store.write

        async def fail(*_):
            raise OSError("secret storage failure")

        self.store.write = fail
        self.assertIn("暂时无法处理", await self.call("confirm", "ABC2D7"))
        self.assertEqual(self.wire.effects, 0)
        self.store.write = original

    async def test_state_and_remote_reply_are_scoped_to_the_site_and_sender(self):
        await self.call("reset")
        other = Commands(
            type("Client", (), {"origin": "https://different.test"})(),
            self.store,
            queries_enabled=True,
            reset_enabled=True,
        )
        self.assertIn("先发送", await other.handle(SENDER, True, "confirm", "ABC2D7"))
        self.assertIn(
            "先发送", await self.commands.handle("87654321", True, "confirm", "ABC2D7")
        )


if __name__ == "__main__":
    unittest.main()
