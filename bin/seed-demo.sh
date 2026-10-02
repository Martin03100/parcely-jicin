#!/usr/bin/env bash
# Generates synthetic parcels (default 300 x 300). Size: COLS=500 ROWS=500 bin/seed-demo.sh
source "$(dirname "$0")/_common.sh"
docker compose up -d --wait db app
echo "==> Generating ${COLS:-300} x ${ROWS:-300} demo parcels"
run_sql_file db/seed_demo.sql -v "cols=${COLS:-300}" -v "rows=${ROWS:-300}"
finalize
