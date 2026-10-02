#!/bin/sh
# With DB_AUTO_INIT=1 the database is prepared before Apache starts:
#   DATA_URL set   - load the parcel export (bin/export-data.sh) unless already loaded; runs in the background
#   otherwise      - seed demo parcels into an empty database
set -eu

APP=/var/www/app

if [ "${DB_AUTO_INIT:-0}" = "1" ] && [ -n "${DATABASE_URL:-}" ]; then
  psql_db() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }

  i=0
  until psql_db -c 'SELECT 1' >/dev/null 2>&1; do
    i=$((i + 1))
    [ "$i" -ge 60 ] && { echo "Database unavailable" >&2; exit 1; }
    sleep 2
  done

  psql_db -f "$APP/db/schema.sql"

  load_data() {
    f=/tmp/parcels.tsv.gz
    echo "==> Downloading $1"
    curl -sSfL --retry 5 -o "$f" "$1" && gzip -t "$f" || { echo "Download failed" >&2; return 1; }
    echo "==> Loading parcels"
    { cat "$APP/db/load_begin.sql"; gunzip -c "$f"; printf '%s\n' '\.'; cat "$APP/db/load_end.sql"; } \
      | psql_db -v "data_url=$1" || { echo "Load failed" >&2; return 1; }
    rm -f "$f"
    psql_db -f "$APP/db/finalize.sql" || return 1
    rm -rf "${TILE_CACHE_DIR:?}"/*
    echo "==> Loaded $(psql_db -tAc 'SELECT count(*) FROM parcels') parcels"
  }

  if [ -n "${DATA_URL:-}" ]; then
    loaded=$(psql_db -tAc "SELECT value FROM app_meta WHERE key = 'data_url'")
    if [ "$loaded" != "$DATA_URL" ]; then
      load_data "$DATA_URL" &
    fi
  elif [ "$(psql_db -tAc 'SELECT count(*) FROM parcels')" = "0" ]; then
    psql_db -v "cols=${SEED_COLS:-300}" -v "rows=${SEED_ROWS:-300}" -f "$APP/db/seed_demo.sql"
    psql_db -f "$APP/db/finalize.sql"
    rm -rf "${TILE_CACHE_DIR:?}"/*
  fi
fi

exec docker-php-entrypoint "$@"
