#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
KEY="${2:-}"
HASH="${3:-test_install_hash_001}"

if [[ -z "$KEY" ]]; then
  echo "Usage: scripts/smoke_test.sh <base_url> <license_key> [installation_hash]"
  exit 1
fi

echo "[1/4] health"
curl -sS "$BASE_URL/health" | sed -e 's/{/\n{/g'

echo "\n[2/4] activate"
curl -sS -X POST "$BASE_URL/api/v1/license/activate" \
  -H 'Content-Type: application/json' \
  -d "{\"license_key\":\"$KEY\",\"domain\":\"example.com\",\"installation_hash\":\"$HASH\",\"php_version\":\"8.2.0\",\"cms_version\":\"1.0.0\",\"database_type\":\"mysql\"}" | sed -e 's/{/\n{/g'

echo "\n[3/4] verify"
curl -sS -X POST "$BASE_URL/api/v1/license/verify" \
  -H 'Content-Type: application/json' \
  -d "{\"license_key\":\"$KEY\",\"installation_hash\":\"$HASH\"}" | sed -e 's/{/\n{/g'

echo "\n[4/4] deactivate"
curl -sS -X POST "$BASE_URL/api/v1/license/deactivate" \
  -H 'Content-Type: application/json' \
  -d "{\"license_key\":\"$KEY\",\"installation_hash\":\"$HASH\",\"reason\":\"manual_test\"}" | sed -e 's/{/\n{/g'

echo "\nDone"
