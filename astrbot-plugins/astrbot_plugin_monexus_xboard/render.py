"""Only render approved DTO fields, never raw error text or reset snapshots."""

import re
from datetime import datetime, timezone
from decimal import Decimal

from .client import BotError

STATUSES = {
    "queued": "等待处理",
    "sending": "正在处理",
    "needs_reconcile": "正在核对结果，请稍后查进度",
    "succeeded": "重置成功，已使用 1 次赠送机会",
    "failed": "本次未执行，赠送机会未扣除",
    "cancelled": "本次已取消，赠送机会未扣除",
}


def text(value, maximum=100):
    return "".join(c for c in str(value) if c.isprintable()).replace("[CQ:", "［CQ:")[
        :maximum
    ]


def gib(value):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9]{1,40}", value):
        return "未知"
    return f"{Decimal(value) / Decimal(1073741824):.2f} GiB"


def expiry(value):
    if value is None:
        return "无时限"
    try:
        if not isinstance(value, str) or not re.fullmatch(r"[0-9]{1,19}", value):
            return "未知"
        if value == "0":
            return "已过期"
        return datetime.fromtimestamp(int(value), timezone.utc).strftime(
            "%Y-%m-%d %H:%M UTC"
        )
    except (ValueError, OverflowError, OSError):
        return "未知"


def _validate_reset_history(profile):
    # Keep contract validation; purchase/automatic-reset counters are not a
    # balance and must not appear as usable opportunities in user replies.
    history = profile.get("resetHistory", {})
    successful = history.get("freeSuccessfulForAccount")
    if history.get("coverageScope") == "verified_v2_only":
        paid, total = (
            history.get("paidSuccessfulForAccount"),
            history.get("platformSuccessfulForAccount"),
        )
        if (
            type(paid) is not int
            or paid < 0
            or type(total) is not int
            or total < 0
            or type(successful) is not int
            or successful < 0
            or total != successful + paid
            or type(history.get("truncated")) is not bool
        ):
            raise BotError("INVALID_RESPONSE")
    elif not (
        history.get("coverageScope") == "free_reset_v2_only"
        and history.get("platformSuccessfulForAccount") is None
    ):
        raise BotError("INVALID_RESPONSE")


def counts(profile, *, reset_enabled=False, purchase_enabled=False):
    _validate_reset_history(profile)
    free = profile.get("freeReset")
    if free is None:
        result = ["免费重置：尚未发放。", "每位符合条件的套餐购买用户可获赠 1 次。"]
        if not reset_enabled or profile.get("blockReason") == "RESET_FEATURE_NOT_ENABLED":
            result.append("免费重置暂未开放。")
        return "\n".join(result)
    if not (
        isinstance(free, dict)
        and type(free.get("granted")) is int
        and free["granted"] == 1
        and all(
            type(free.get(k)) is int and 0 <= free[k] <= 1
            for k in ["available", "reserved", "consumed"]
        )
        and free["reserved"] + free["consumed"] <= free["granted"]
        and free.get("status") in {"active", "pending_verification", "revoked"}
        and free["available"]
        == (
            free["granted"] - free["reserved"] - free["consumed"]
            if free["status"] == "active"
            else 0
        )
    ):
        raise BotError("INVALID_RESPONSE")
    if free["reserved"]:
        return "免费重置：1 次处理中。\n发送“重置进度”查看结果，请勿重复操作。"
    if free["consumed"]:
        next_step = (
            "再次重置需购买，发送“购买重置”查看价格。"
            if purchase_enabled
            else "再次重置需购买，购买入口暂未开放。"
        )
        return "免费重置：剩余 0 次（赠送机会已使用）。\n" + next_step
    if free["status"] == "pending_verification":
        return "免费重置：资格待确认，暂不可用。\n如已购买套餐，请稍后查询或联系站点客服核对。"
    if free["status"] == "revoked":
        return "免费重置：当前不可用。\n请联系站点客服核对套餐资格。"
    result = [f"免费重置：可用 {free['available']} 次（每人限赠一次，多账号共享）。"]
    if not reset_enabled or profile.get("blockReason") == "RESET_FEATURE_NOT_ENABLED":
        result.append("免费重置暂未开放，次数会保留。")
    elif profile.get("canFreeReset") is True:
        result.append("发送“重置流量”使用，确认后才会执行。")
    else:
        result.append("当前暂不能使用，请稍后重试或联系站点客服核对套餐状态。")
    return "\n".join(result)


def profile_text(profile, *, reset_enabled=False, purchase_enabled=False):
    account = profile.get("account")
    freshness = profile.get("freshness")
    if not isinstance(account, dict) or not isinstance(freshness, dict):
        raise BotError("INVALID_RESPONSE")
    result = [f"当前账号：{text(account.get('maskedEmail', '***'))}"]
    sub = profile.get("subscription")
    if not isinstance(sub, dict):
        result.append("流量暂时查询不到，请稍后重试。")
    else:
        status = {
            "active": "有效",
            "expired": "已过期",
            "no_plan": "无套餐",
            "blocked": "不可用",
        }.get(sub.get("status"), "未知")
        result.extend(
            [
                f"套餐：{text(sub.get('planName') or '无套餐')}（{status}）",
                f"剩余流量：{gib(sub.get('remainingBytes'))} / {gib(sub.get('totalBytes'))}",
                f"到期：{expiry(sub.get('expiresAtUnix'))}",
            ]
        )
    result.append(
        counts(profile, reset_enabled=reset_enabled, purchase_enabled=purchase_enabled)
    )
    if freshness.get("stale") or freshness.get("unavailable"):
        result.append("当前显示的是上次查询结果，请稍后再查最新流量。")
    result.append("查询时间：" + text(freshness.get("queriedAt") or "未知"))
    result.append("流量更新可能稍有延迟。")
    return "\n".join(result)


def operation_text(result):
    status = result.get("status")
    if status not in STATUSES:
        raise BotError("INVALID_RESPONSE", uncertain=True)
    message = STATUSES[status]
    if result.get("errorCode") == "BENEFIT_ALREADY_CONSUMED":
        message = "本次未执行；该账号或用户已经使用过此赠送权益，无法再次领取。"
    return message


def error_text(error):
    return {
        "XBOARD_PAID_RESET_UNAVAILABLE": "付费重置购买暂未开放，请稍后再试。",
        "CHECKOUT_CHANGED": "套餐账号或商品已变化，请重新查询后购买。",
        "XBOARD_ACCOUNT_SELECTION_REQUIRED": "有多个套餐账号，请先发送“我的账号”，再发送“切换套餐账号 序号”。",
        "XBOARD_ACCOUNT_UNRESOLVED": "暂未找到可用的套餐账号。如已购买套餐，请联系站点客服核对订单，无需重新绑定套餐邮箱。",
        "XBOARD_BINDING_CHANGED": "QQ 绑定或账号状态已变化，请先在站内确认绑定后重新查询。",
        "TRAFFIC_RESET_INELIGIBLE": "当前没有可用赠送机会，或套餐暂不符合重置条件。请先查流量和重置次数。",
        "TRAFFIC_RESET_CONFIRMATION_INVALID": "确认码有误或尝试次数过多，请检查原确认码；必要时重新发起。",
        "TRAFFIC_RESET_INTENT_EXPIRED": "确认已过期，请重新发送“重置流量”。",
        "TRAFFIC_RESET_UNAVAILABLE": "免费重置暂未开放或服务不可用，请稍后重试。",
        "RATE_LIMITED": "查询较频繁，请稍后再试。",
        "TOO_MANY_REQUESTS": "查询较频繁，请稍后再试。",
        "NOT_FOUND": "该记录已不可用或不属于当前绑定，请重新查询。",
        "IDEMPOTENCY_KEY_REUSED": "请求状态发生冲突，请联系站点客服核验，暂勿重复发起。",
    }.get(error.code, "服务暂时不可用，请稍后再试。")
