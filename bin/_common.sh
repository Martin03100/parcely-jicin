#!/usr/bin/env bash
# Shared helpers for the bin/ scripts.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

psql_db() { docker compose exec -T db psql -U parcely -d parcely -v ON_ERROR_STOP=1 -q "$@"; }

run_sql_file() { psql_db "${@:2}" < "$1"; }

finalize() {
  echo "==> Outlines"
  run_sql_file db/finalize.sql
  docker compose exec -T app sh -c 'rm -rf /var/cache/tiles/*'
}
