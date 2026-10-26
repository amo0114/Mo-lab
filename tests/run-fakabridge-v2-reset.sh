#!/usr/bin/env bash
set -euo pipefail
contract_kind=${1:-reset}
case "$contract_kind" in reset|paid-reset) ;; *) echo "expected reset or paid-reset" >&2; exit 2 ;; esac
sqlite_mode=${2:-memory}
case "$sqlite_mode" in memory|sqlite-wal) ;; *) echo 'expected memory or sqlite-wal' >&2; exit 2 ;; esac
sqlite_args=()
if [[ "$sqlite_mode" == sqlite-wal ]]; then sqlite_args=(-e FAKABRIDGE_SQLITE_FILE=/tmp/faka-reset.sqlite); fi
lab_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
test_image='ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8'
reset_fixture_dir=$(mktemp -d)
trap 'rm -rf "$reset_fixture_dir"' EXIT
docker run --rm --network none --entrypoint cat "$test_image" /www/app/Services/TrafficResetService.php > "$reset_fixture_dir/original.php"
python3 "$lab_root/patches/prepare-atomic-reset.py" --source "$reset_fixture_dir/original.php" --output "$reset_fixture_dir/atomic-reset.php"
docker run --rm --network none --read-only --tmpfs /tmp \
  "${sqlite_args[@]}" \
  --mount "type=bind,src=${lab_root}/xboard-plugins/FakaBridge,dst=/www/plugins/FakaBridge,readonly" \
  --mount "type=bind,src=${lab_root}/tests,dst=/contract-tests,readonly" \
  --mount "type=bind,src=${reset_fixture_dir}/atomic-reset.php,dst=/tmp/atomic-reset.php,readonly" \
  --entrypoint php "$test_image" "/contract-tests/fakabridge-v2-${contract_kind}.php"
