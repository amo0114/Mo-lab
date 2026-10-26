"""Raw-byte HMAC v2 client. Business retries keep the body, never the nonce."""

import asyncio
import hashlib
import hmac
import json
import re
import secrets
import time
from urllib.parse import urlsplit

import httpx

ACTIONS = frozenset(
    {
        "accounts",
        "profile",
        "purchase-reset",
        "select-default",
        "reset-intents",
        "resets",
        "reset-status",
        "reset-intent-status",
    }
)


class BotError(Exception):
    def __init__(self, code: str, *, uncertain: bool = False):
        super().__init__(code)
        self.code = code
        self.uncertain = uncertain


def signed_headers(
    raw: bytes,
    path: str,
    secret: str,
    timestamp: int,
    nonce: str,
    audience: str = "bot",
) -> dict:
    if not re.fullmatch(r"/(?:[a-z0-9-]+/)*[a-z0-9-]+", path) or len(path) > 200:
        raise ValueError("invalid protocol path")
    if audience not in {"bot", "xboard"} or not re.fullmatch(r"[a-f0-9]{32}", nonce):
        raise ValueError("invalid protocol envelope")
    if (
        type(timestamp) is not int
        or not 0 <= timestamp <= 9007199254740991
        or not secret
    ):
        raise ValueError("invalid protocol envelope")
    payload = json.dumps(
        [
            "monexus-hmac-v2",
            audience,
            "POST",
            path,
            timestamp,
            nonce,
            hashlib.sha256(raw).hexdigest(),
        ],
        separators=(",", ":"),
    ).encode()
    return {
        "content-type": "application/json",
        "x-monexus-signature-version": "2",
        "x-monexus-timestamp": str(timestamp),
        "x-monexus-nonce": nonce,
        "x-monexus-signature": hmac.new(
            secret.encode(), payload, hashlib.sha256
        ).hexdigest(),
    }


def validate_origin(value: str) -> str:
    try:
        url = urlsplit(value)
        if (
            url.scheme != "https"
            or not url.hostname
            or url.username is not None
            or url.password is not None
            or url.path not in {"", "/"}
            or url.query
            or url.fragment
            or url.port == 0
            or any(c.isspace() for c in value)
            or any(c in value for c in "\\?#")
        ):
            raise ValueError()
    except (ValueError, TypeError):
        raise ValueError(
            "site_url must be an HTTPS origin without credentials, path or query"
        ) from None
    return value.rstrip("/")


class BotClient:
    def __init__(
        self,
        origin: str,
        secret: str,
        *,
        transport=None,
        sleep=asyncio.sleep,
        deadline=30,
    ):
        self.origin = validate_origin(origin)
        if not isinstance(secret, str) or not secret:
            raise ValueError("bot shared secret is required")
        self._secret = secret
        self._sleep = sleep
        if not 0 < deadline <= 30:
            raise ValueError("invalid request deadline")
        self._deadline = deadline
        self._http = httpx.AsyncClient(
            transport=transport,
            timeout=httpx.Timeout(8, connect=3),
            follow_redirects=False,
            trust_env=False,
            limits=httpx.Limits(max_connections=10),
        )

    async def close(self):
        await self._http.aclose()

    async def call(self, action: str, sender: str, **fields) -> dict:
        try:
            return await asyncio.wait_for(
                self._call(action, sender, **fields), self._deadline
            )
        except asyncio.TimeoutError:
            raise BotError("NETWORK_UNAVAILABLE", uncertain=True) from None

    async def _call(self, action: str, sender: str, **fields) -> dict:
        if action not in ACTIONS or not re.fullmatch(r"[1-9][0-9]{4,19}", sender):
            raise BotError("INVALID_REQUEST")
        if {"platform", "platformId"} & fields.keys():
            raise BotError("INVALID_REQUEST")
        path = "/api/bot/v2/xboard/" + action
        raw = json.dumps(
            {"platform": "qq", "platformId": sender, **fields},
            ensure_ascii=False,
            separators=(",", ":"),
        ).encode()
        # Serialize once, transmit those exact UTF-8 bytes on every attempt.
        for attempt in range(3):
            headers = signed_headers(
                raw, path, self._secret, int(time.time() * 1000), secrets.token_hex(16)
            )
            try:
                async with self._http.stream(
                    "POST", self.origin + path, content=raw, headers=headers
                ) as response:
                    if response.status_code >= 500:
                        raise BotError("SERVICE_UNAVAILABLE", uncertain=True)
                    if response.is_redirect:
                        raise BotError("INVALID_RESPONSE", uncertain=True)
                    content = bytearray()
                    async for chunk in response.aiter_bytes():
                        content.extend(chunk)
                        if len(content) > 131072:
                            raise BotError("INVALID_RESPONSE", uncertain=True)
                    try:
                        body = json.loads(content)
                    except (ValueError, UnicodeError):
                        raise BotError("INVALID_RESPONSE", uncertain=True) from None
                    if not isinstance(body, dict):
                        raise BotError("INVALID_RESPONSE", uncertain=True)
                    if response.status_code not in {200, 202}:
                        error = body.get("error")
                        code = error.get("code") if isinstance(error, dict) else None
                        if not isinstance(code, str) or not re.fullmatch(
                            r"[A-Z_]{1,64}", code
                        ):
                            code = "SERVICE_UNAVAILABLE"
                        raise BotError(
                            code,
                            uncertain=response.status_code
                            not in {400, 401, 403, 404, 409, 413, 415, 429},
                        )
                    if type(body.get("bound")) is not bool:
                        raise BotError("INVALID_RESPONSE", uncertain=True)
                    return body
            except httpx.HTTPError:
                error = BotError("NETWORK_UNAVAILABLE", uncertain=True)
            except BotError as caught:
                error = caught
            if (
                not error.uncertain
                or error.code in {"INVALID_RESPONSE"}
                or attempt == 2
            ):
                raise error from None
            await self._sleep((0.25, 0.75)[attempt])
        raise BotError("NETWORK_UNAVAILABLE", uncertain=True)
