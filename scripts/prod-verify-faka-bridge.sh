#!/usr/bin/env bash
# FakaBridge 生产验收（在你本机执行；本机需能 SSH 生产机）
#
# 生产事实（已对齐）:
#   SSH:     -p 2345 root@<host>
#   路径:    /root/Xboard
#   域名:    https://v.uuwu.de
#   容器:    xboard-web-1 / xboard-horizon-1
#   镜像:    ghcr.io/cedar2025/xboard:new
#
# 用法:
#   export SSH_HOST=134.185.87.245
#   export SSH_PORT=2345
#   export FAKA_HMAC_SECRET='后台密钥'   # 可选
#   bash scripts/prod-verify-faka-bridge.sh
#
# 同步插件:
#   export SYNC_PLUGIN=1
#   export MOLAB_FAKA=/path/to/Mo-lab/xboard-plugins/FakaBridge
#   bash scripts/prod-verify-faka-bridge.sh

set -euo pipefail

SSH_HOST="${SSH_HOST:-134.185.87.245}"
SSH_PORT="${SSH_PORT:-2345}"
SSH_USER="${SSH_USER:-root}"
XBOARD_ROOT="${XBOARD_ROOT:-/root/Xboard}"
PUBLIC_BASE="${PUBLIC_BASE:-https://v.uuwu.de}"
SYNC_PLUGIN="${SYNC_PLUGIN:-0}"
DRY_ORDER_NO="${DRY_ORDER_NO:-FB-VERIFY-$(date +%Y%m%d%H%M%S)}"
TEST_EMAIL="${TEST_EMAIL:-faka-verify-$(date +%s)@example.com}"
TEST_SKU="${TEST_SKU:-aster-basic-monthly}"

ssh_prod() {
  ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 \
    -p "$SSH_PORT" "${SSH_USER}@${SSH_HOST}" "$@"
}

echo "=== 生产基线 ==="
echo "SSH ${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
echo "XBOARD_ROOT=$XBOARD_ROOT"
echo "PUBLIC_BASE=$PUBLIC_BASE"

echo
echo "=== 1) SSH 连通性 ==="
ssh_prod 'echo OK; hostname; date; whoami'

echo
echo "=== 2) Xboard 目录 / 容器 ==="
ssh_prod "bash -s" <<REMOTE
set -e
cd '$XBOARD_ROOT'
pwd
ls -la
echo '-- compose (image/ports) --'
grep -E 'image:|ports:|7001|plugins' compose.yaml 2>/dev/null | head -30 || true
echo '-- docker ps xboard --'
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' | grep -i xboard || docker ps
echo '-- plugins --'
ls -la plugins | head -40
echo '-- FakaBridge? --'
if [[ -d plugins/FakaBridge ]]; then
  ls -la plugins/FakaBridge
  head -20 plugins/FakaBridge/config.json 2>/dev/null || true
else
  echo 'NOT INSTALLED (plugins/FakaBridge missing)'
  ls plugins | grep -i faka || true
fi
REMOTE

if [[ "$SYNC_PLUGIN" == "1" ]]; then
  if [[ -z "${MOLAB_FAKA:-}" || ! -d "$MOLAB_FAKA" ]]; then
    echo "ERROR: SYNC_PLUGIN=1 需要 MOLAB_FAKA 指向本地 FakaBridge 目录"
    exit 1
  fi
  echo
  echo "=== 3) rsync 插件 → 生产 ==="
  rsync -avz --delete \
    -e "ssh -o StrictHostKeyChecking=accept-new -p ${SSH_PORT}" \
    "$MOLAB_FAKA/" \
    "${SSH_USER}@${SSH_HOST}:${XBOARD_ROOT}/plugins/FakaBridge/"
  ssh_prod "cd '$XBOARD_ROOT' && docker compose restart web horizon"
  echo "等待 Octane 起来..."
  sleep 5
else
  echo
  echo "=== 3) 跳过同步 (export SYNC_PLUGIN=1 MOLAB_FAKA=... 可推送) ==="
fi

echo
echo "=== 4) 容器内路由 / 版本 ==="
ssh_prod "bash -s" <<REMOTE
set -e
cd '$XBOARD_ROOT'
cid=\$(docker compose ps -q web 2>/dev/null || true)
if [[ -z "\$cid" ]]; then
  cid=\$(docker ps -qf name=xboard-web)
fi
echo "web_cid=\$cid"
if [[ -n "\$cid" ]]; then
  docker exec "\$cid" sh -c 'test -f /www/plugins/FakaBridge/config.json && cat /www/plugins/FakaBridge/config.json || echo NO_PLUGIN_IN_CONTAINER'
  echo '-- routes --'
  docker exec "\$cid" php artisan route:list 2>/dev/null | grep -i faka || echo '无 faka 路由（插件未启用或未加载）'
  echo '-- plugin table (if tinker works) --'
  docker exec "\$cid" php artisan tinker --execute="echo json_encode(\\App\\Models\\Plugin::where('code','faka_bridge')->first(['code','is_enabled','version']));" 2>/dev/null || true
fi
REMOTE

echo
echo "=== 5) 公网域名: $PUBLIC_BASE ==="
curl -sS -m 15 -o /dev/null -w 'home HTTP %{http_code}\n' "$PUBLIC_BASE/" || echo 'home fail'
code=$(curl -sS -m 15 -o /tmp/faka_probe_body.txt -w '%{http_code}' \
  "$PUBLIC_BASE/plugin/faka-bridge/order-status?order_no=probe" || echo '000')
echo "order-status(no sign) HTTP $code"
echo "body: $(head -c 300 /tmp/faka_probe_body.txt 2>/dev/null || true)"
echo "期望: 插件启用后多为 400 缺签名/签名失败；404=路由未挂；000=网络不通"

if [[ -z "${FAKA_HMAC_SECRET:-}" ]]; then
  echo
  echo "=== 6) 跳过签名冒烟 (export FAKA_HMAC_SECRET=...) ==="
  echo "DONE (partial)"
  exit 0
fi

echo
echo "=== 6) 签名冒烟 ==="
export PUBLIC_BASE DRY_ORDER_NO TEST_EMAIL TEST_SKU
export FAKA_HMAC_SECRET
python3 - <<'PY'
import hmac, hashlib, json, time, urllib.parse, urllib.request, os, ssl, urllib.error

secret = os.environ["FAKA_HMAC_SECRET"].encode()
base = os.environ.get("PUBLIC_BASE", "https://v.uuwu.de").rstrip("/")
order_no = os.environ.get("DRY_ORDER_NO", "FB-VERIFY")
email = os.environ.get("TEST_EMAIL", "verify@example.com")
sku = os.environ.get("TEST_SKU", "aster-basic-monthly")
paid_at = int(time.time())

def sign(params: dict) -> str:
    items = sorted((k, v) for k, v in params.items() if k != "sign")
    payload = "&".join(f"{k}={v}" for k, v in items)
    return hmac.new(secret, payload.encode(), hashlib.sha256).hexdigest()

def req(method, url, body=None):
    data = None if body is None else json.dumps(body).encode()
    r = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json"})
    ctx = ssl.create_default_context()
    try:
        with urllib.request.urlopen(r, context=ctx, timeout=20) as resp:
            return resp.status, resp.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

st = {"order_no": order_no}
st["sign"] = sign(st)
q = urllib.parse.urlencode(st)
code, body = req("GET", f"{base}/plugin/faka-bridge/order-status?{q}")
print(f"order-status signed -> {code} {body[:400]}")

if os.environ.get("DO_PAID_SMOKE", "0") != "1":
    print("skip order-paid (export DO_PAID_SMOKE=1 才会真实开通)")
else:
    body = {
        "order_no": order_no,
        "email": email,
        "sku": sku,
        "period": "monthly",
        "paid_at": paid_at,
    }
    body["sign"] = sign(body)
    code, resp = req("POST", f"{base}/plugin/faka-bridge/order-paid", body)
    print(f"order-paid #1 -> {code} {resp[:500]}")
    code2, resp2 = req("POST", f"{base}/plugin/faka-bridge/order-paid", body)
    print(f"order-paid #2 idempotent -> {code2} {resp2[:500]}")
PY

echo
echo "DONE"
