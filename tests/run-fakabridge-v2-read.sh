#!/usr/bin/env bash
set -euo pipefail
lab_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
test_image='ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8'
docker run --rm --network none --read-only --tmpfs /tmp \
  --mount "type=bind,src=${lab_root}/xboard-plugins/FakaBridge,dst=/www/plugins/FakaBridge,readonly" \
  --mount "type=bind,src=${lab_root}/tests,dst=/contract-tests,readonly" \
  --entrypoint php "$test_image" /contract-tests/fakabridge-v2-read.php
