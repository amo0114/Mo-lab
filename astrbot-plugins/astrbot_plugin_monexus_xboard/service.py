"""Command orchestration. Pending intent/operation IDs survive plugin restart."""

import asyncio
import hashlib
import re
import time
import uuid
import weakref
from datetime import datetime

from .client import BotError, validate_origin
from .render import counts, error_text, operation_text, profile_text, text

TERMINAL = {"succeeded", "failed", "cancelled"}
PRIVATE_HINT = "请主动私聊机器人后使用此命令，套餐详情和重置确认只在私聊中处理。"
UNBOUND = "请先登录 MoNexus，在站内完成 QQ 绑定；无需重新绑定已购买套餐的 Xboard 邮箱。"


def checked_uuid(value):
    try:
        if not isinstance(value, str) or str(uuid.UUID(value)) != value:
            raise ValueError()
        return value
    except (ValueError, AttributeError):
        raise BotError("INVALID_RESPONSE", uncertain=True) from None


def instant(value):
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()
    except (ValueError, TypeError, AttributeError):
        raise BotError("INVALID_RESPONSE", uncertain=True) from None


class Commands:
    def __init__(
        self,
        client,
        store,
        *,
        queries_enabled=False,
        reset_enabled=False,
        purchase_enabled=False,
        clock=time.time,
    ):
        self.client, self.store = client, store
        self.queries_enabled, self.reset_enabled = queries_enabled, reset_enabled
        self.purchase_enabled = purchase_enabled
        self.clock = clock
        self._locks = weakref.WeakValueDictionary()
        self._scope = hashlib.sha256(client.origin.encode()).hexdigest()[:32]

    async def handle(self, sender, private, action, argument=""):
        # Do not query or persist personal information for group invocations.
        if not private:
            return PRIVATE_HINT
        if not re.fullmatch(r"[1-9][0-9]{4,19}", sender):
            return "无法识别当前 QQ 身份。"
        if not self.queries_enabled:
            return "套餐查询暂未开放。"
        key = self._scope + ":" + sender
        lock = self._locks.setdefault(key, asyncio.Lock())
        async with lock:
            try:
                state = await self.store.read(key)
                return await self._handle(sender, key, state, action, argument.strip())
            except BotError as error:
                if error.code in {"NOT_FOUND", "XBOARD_BINDING_CHANGED"}:
                    try:
                        await self.store.write(key, {})
                    except Exception:
                        return "暂时无法处理，请稍后重试；不要重复发起重置。"
                return UNBOUND if error.code == "UNBOUND" else error_text(error)
            except Exception:
                # No repr/traceback: request bodies, sender and secrets stay out
                # of replies and plugin logs. Storage failures block execution.
                return "暂时无法处理，请稍后重试；不要重复发起重置。"

    async def _call(self, sender, key, action, **fields):
        result = await self.client.call(action, sender, **fields)
        if result.get("bound") is False:
            await self.store.write(key, {})
            raise BotError("UNBOUND")
        if result.get("bound") is not True:
            raise BotError("INVALID_RESPONSE", uncertain=True)
        return result

    async def _profile(self, sender, key, **fields):
        result = await self._call(sender, key, "profile", **fields)
        if not isinstance(result.get("account"), dict):
            raise BotError("INVALID_RESPONSE")
        checked_uuid(result["account"].get("id"))
        return result

    async def _status(self, sender, key, state):
        operation = state.get("operation")
        if not operation:
            return None
        result = await self._call(
            sender, key, "reset-status", operationId=operation["id"]
        )
        if checked_uuid(result.get("operationId")) != operation["id"]:
            raise BotError("INVALID_RESPONSE", uncertain=True)
        operation_text(result)  # Reject unknown status before changing state.
        state["operation"]["status"] = result["status"]
        await self.store.write(key, state)
        return result

    async def _handle(self, sender, key, state, action, argument):
        if action == "accounts":
            result = await self._call(sender, key, "accounts")
            rows = result.get("accounts")
            if not isinstance(rows, list) or len(rows) > 100:
                raise BotError("INVALID_RESPONSE")
            snapshot = []
            lines = []
            for index, row in enumerate(rows, 1):
                if not isinstance(row, dict) or row.get("status") not in {
                    "active",
                    "blocked",
                    "unresolved",
                }:
                    raise BotError("INVALID_RESPONSE")
                snapshot.append(
                    {"id": checked_uuid(row.get("id")), "status": row["status"]}
                )
                status = "" if row["status"] == "active" else "（需核验）"
                default = "（默认）" if row.get("isDefault") else ""
                lines.append(
                    f"{index}. {text(row.get('maskedEmail', '***'))}{default}{status}"
                )
            state["accounts"] = {"rows": snapshot, "expires": self.clock() + 600}
            await self.store.write(key, state)
            if not rows:
                return (
                    "购买账号正在同步，请稍后重试；已有订单无需重新绑定 Xboard 邮箱。"
                )
            if result.get("truncated"):
                lines.append("账号较多，仅显示部分；其余请联系站点客服。")
            lines.append("发送“切换套餐账号 序号”选择；列表 10 分钟内有效。")
            return "\n".join(lines)
        if action == "select":
            pending = state.get("selection")
            # A formerly selectable target may disappear from a refreshed
            # list. Recover by its frozen UUID/request, independent of index.
            if pending and not argument:
                return await self._apply_selection(sender, key, state, pending)
            listing = state.get("accounts", {})
            if listing.get("expires", 0) <= self.clock():
                return "请先发送“我的账号”获取最新列表。"
            if not re.fullmatch(r"[1-9][0-9]{0,2}", argument) or int(argument) > len(
                listing["rows"]
            ):
                return "请输入“我的账号”列表里的序号，例如：切换套餐账号 2。"
            row = listing["rows"][int(argument) - 1]
            if row["status"] != "active":
                return "该账号需要先核验购买关联，暂不能选择。"
            if pending and pending["target"] != row["id"]:
                return "上次账号切换结果尚未确认，请先重复原序号，或发送“切换套餐账号”（不带序号）核对。"
            if not pending or pending["target"] != row["id"]:
                pending = {"target": row["id"], "request": str(uuid.uuid4())}
                state["selection"] = pending
                await self.store.write(key, state)
            return await self._apply_selection(sender, key, state, pending)
        if action in {"profile", "counts"}:
            result = await self._profile(sender, key)
            return profile_text(result) if action == "profile" else counts(result)
        if action == "purchase":
            if not self.purchase_enabled:
                return "当前套餐的重置购买入口暂未开放，请联系站点客服。"
            if state.get("selection"):
                return (
                    "上次账号切换结果尚未确认，请先发送“切换套餐账号”（不带序号）核对。"
                )
            profile = await self._profile(sender, key)
            target = profile["account"]["id"]
            result = await self._call(
                sender, key, "purchase-reset", accountLinkId=target
            )
            if result.get("accountLinkId") != target or "purchase" not in result:
                raise BotError("INVALID_RESPONSE")
            purchase = result["purchase"]
            if purchase is None:
                reason = result.get("blockReason")
                if reason == "NO_OFFER":
                    return "当前套餐暂未配置可用的重置购买入口，请联系站点客服。"
                if reason == "SUBSCRIPTION_INELIGIBLE":
                    return "当前账号套餐暂不符合付费重置条件，请先查询套餐状态。"
                raise BotError("INVALID_RESPONSE")
            if (
                not isinstance(purchase, dict)
                or set(purchase) != {"productId", "offerId", "accountLinkId", "price"}
                or result.get("blockReason") is not None
            ):
                raise BotError("INVALID_RESPONSE")
            if purchase.get("accountLinkId") != target:
                raise BotError("INVALID_RESPONSE")
            for field in ("productId", "offerId"):
                if (
                    type(purchase.get(field)) is not int
                    or not 0 < purchase[field] <= 2147483647
                ):
                    raise BotError("INVALID_RESPONSE")
            price = purchase.get("price")
            if type(price) is not int or not 0 <= price <= 2147483647:
                raise BotError("INVALID_RESPONSE")
            # Build only the known website route from typed IDs. Never consume a
            # server-provided URL, email or SKU; origin is locally configured.
            origin = validate_origin(self.client.origin)
            url = f"{origin}/product/{purchase['productId']}?offerId={purchase['offerId']}&xboardAccountLinkId={target}"
            return f"当前账号：{text(profile['account'].get('maskedEmail', '***'))}\n付费重置参考价：{price} 积分（以网站结账为准）。\n{url}\n请登录绑定的 MoNexus 账号确认购买；本次不会使用赠送机会，也不延长套餐期限。"
        if action == "status":
            pending = state.get("intent", {})
            if pending.get("confirming"):
                result = await self._call(
                    sender, key, "reset-intent-status", intentId=pending["id"]
                )
                if result.get("intentId") != pending["id"] or "operation" not in result:
                    raise BotError("INVALID_RESPONSE", uncertain=True)
                operation = result["operation"]
                if operation is None:
                    return "暂未查到已接受的重置，结果仍待核实；请稍后再查进度，或在重置开放时重发原确认码。"
                if not isinstance(operation, dict):
                    raise BotError("INVALID_RESPONSE", uncertain=True)
                operation_id = checked_uuid(operation.get("operationId"))
                rendered = operation_text(operation)
                state["operation"] = {"id": operation_id, "status": operation["status"]}
                state.pop("intent", None)
                await self.store.write(key, state)
                return rendered
            result = await self._status(sender, key, state)
            return operation_text(result) if result else "这里没有最近的重置记录。"
        if action not in {"reset", "confirm"}:
            return "可用命令：我的套餐、我的账号、切换套餐账号 序号、重置次数、重置流量、确认重置 六位确认码、重置进度、购买重置。"
        if not self.reset_enabled:
            return "免费重置暂未开放；仍可查询已有重置进度。"
        if action == "reset":
            pending = state.get("intent")
            if pending and pending.get("confirming"):
                return "上次确认的结果尚未核实，请重新发送原确认码，暂勿发起新的重置。"
            previous = state.get("operation")
            if previous and previous.get("status") not in TERMINAL:
                result = await self._status(sender, key, state)
                if result["status"] not in TERMINAL:
                    return operation_text(result)
            if not pending or pending.get("expires", 0) <= self.clock():
                profile = await self._profile(sender, key)
                pending = {
                    "request": str(uuid.uuid4()),
                    "target": profile["account"]["id"],
                    "email": text(profile["account"].get("maskedEmail", "***")),
                    "expires": self.clock() + 120,
                }
                state["intent"] = pending
                await self.store.write(key, state)
            result = await self._call(
                sender,
                key,
                "reset-intents",
                accountLinkId=pending["target"],
                requestId=pending["request"],
            )
            if (
                result.get("accountLinkId") != pending["target"]
                or result.get("consumes") != 1
            ):
                raise BotError("INVALID_RESPONSE", uncertain=True)
            intent_id = checked_uuid(result.get("intentId"))
            code = result.get("confirmationCode")
            if not isinstance(code, str) or not re.fullmatch(r"[A-Z2-7]{6}", code):
                raise BotError("INVALID_RESPONSE", uncertain=True)
            pending.update(id=intent_id, expires=instant(result.get("expiresAt")))
            await self.store.write(key, state)
            if result.get("expired") or pending["expires"] <= self.clock():
                state.pop("intent", None)
                await self.store.write(key, state)
                return "确认已过期，请重新发送“重置流量”。"
            return f"将重置 {pending['email']} 的当前套餐已用流量。\n本次消耗 1 次赠送机会，总流量和到期时间保持不变。\n请在 2 分钟内发送：确认重置 {code}"
        if not re.fullmatch(r"[A-Z2-7]{6}", argument):
            return "请发送“确认重置 六位确认码”，确认码仅含大写字母和数字 2–7。"
        pending = state.get("intent")
        if not pending or not pending.get("id"):
            return "请先发送“重置流量”获取确认码。"
        if not pending.get("confirming") and pending["expires"] <= self.clock():
            return "确认已过期，请重新发送“重置流量”。"
        # Persist BEFORE HTTP. On timeout/restart the same confirmation request
        # can recover an already-created operation, even after intent expiry.
        pending.setdefault("confirmationRequest", str(uuid.uuid4()))
        pending["confirming"] = True
        await self.store.write(key, state)
        try:
            result = await self._call(
                sender,
                key,
                "resets",
                intentId=pending["id"],
                confirmationCode=argument,
                requestId=pending["confirmationRequest"],
            )
        except BotError as error:
            if not error.uncertain:
                pending["confirming"] = False
                if error.code in {
                    "TRAFFIC_RESET_INTENT_EXPIRED",
                    "XBOARD_BINDING_CHANGED",
                    "NOT_FOUND",
                }:
                    state.pop("intent", None)
                # _call already removed all old state on an unbind.
                if error.code != "UNBOUND":
                    await self.store.write(key, state)
            if error.uncertain:
                return (
                    "确认已发送，结果暂未核实。请重发同一确认码核对，不要重新发起重置。"
                )
            raise
        operation_id = checked_uuid(result.get("operationId"))
        rendered = operation_text(result)
        state["operation"] = {"id": operation_id, "status": result["status"]}
        state.pop("intent", None)
        await self.store.write(key, state)
        return rendered + (
            "。发送“重置进度”查询结果。" if result["status"] not in TERMINAL else "。"
        )

    async def _apply_selection(self, sender, key, state, pending):
        try:
            result = await self._call(
                sender,
                key,
                "select-default",
                accountLinkId=pending["target"],
                requestId=pending["request"],
            )
        except BotError as error:
            if not error.uncertain and error.code == "XBOARD_ACCOUNT_UNRESOLVED":
                state.pop("selection", None)
                await self.store.write(key, state)
                return "原目标已不可选择。请重新发送“我的账号”，再选择当前可用账号。"
            raise
        if result.get("selectedAccountLinkId") != pending["target"]:
            raise BotError("INVALID_RESPONSE", uncertain=True)
        state.pop("selection", None)
        await self.store.write(key, state)
        return "默认账号已更新。此前已发出的重置确认仍对应原账号。"
