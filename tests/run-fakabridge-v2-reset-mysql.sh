#!/usr/bin/env bash
set -euo pipefail
contract_kind=${1:-reset}
case "$contract_kind" in reset|paid-reset) ;; *) echo "expected reset or paid-reset" >&2; exit 2 ;; esac
lab_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
test_image='ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8'
mysql_test_name="faka-reset-mysql-$$"
mysql_socket_volume="faka-reset-mysql-socket-$$"
reset_fixture_dir=$(mktemp -d)
cleanup() {
  docker stop "$mysql_test_name" >/dev/null 2>&1 || true
  docker volume rm "$mysql_socket_volume" >/dev/null 2>&1 || true
  rm -rf "$reset_fixture_dir"
}
trap cleanup EXIT
docker volume create "$mysql_socket_volume" >/dev/null
docker run -d --rm --network none --name "$mysql_test_name" --tmpfs /var/lib/mysql \
  --mount "type=volume,src=${mysql_socket_volume},dst=/var/run/mysqld" \
  -e MYSQL_ROOT_PASSWORD=b2_root_test -e MYSQL_DATABASE=faka_reset_test -e MYSQL_USER=b2_test -e MYSQL_PASSWORD=b2_test mysql:8.4.11 >/dev/null
ready=false
for attempt in $(seq 1 45); do
  # mysqladmin ping also succeeds on Access denied and can see the temporary
  # initialization server. Require an authenticated query on the fixture DB.
  if docker exec "$mysql_test_name" mysql --user=b2_test --password=b2_test faka_reset_test --execute='SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
if [[ "$ready" != true ]]; then echo 'isolated MySQL did not become ready' >&2; exit 1; fi
docker run --rm --network none --entrypoint cat "$test_image" /www/app/Services/TrafficResetService.php > "$reset_fixture_dir/original.php"
python3 "$lab_root/patches/prepare-atomic-reset.py" --source "$reset_fixture_dir/original.php" --output "$reset_fixture_dir/atomic-reset.php"
docker run --rm --network none --read-only --tmpfs /tmp \
  --mount "type=volume,src=${mysql_socket_volume},dst=/mysqlsocket" \
  --mount "type=bind,src=${lab_root}/xboard-plugins/FakaBridge,dst=/www/plugins/FakaBridge,readonly" \
  --mount "type=bind,src=${lab_root}/tests,dst=/contract-tests,readonly" \
  --mount "type=bind,src=${reset_fixture_dir}/atomic-reset.php,dst=/tmp/atomic-reset.php,readonly" \
  -e FAKABRIDGE_MYSQL_SOCKET=/mysqlsocket/mysqld.sock --entrypoint php "$test_image" "/contract-tests/fakabridge-v2-${contract_kind}.php"
