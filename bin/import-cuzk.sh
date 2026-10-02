#!/usr/bin/env bash
# Imports cadastral parcels from the ČÚZK INSPIRE WFS.
#
#   bin/import-cuzk.sh                                  whole Jičín district
#   BBOX="15.30 50.41 15.40 50.47" bin/import-cuzk.sh   bounding box (WGS84)
#
# The area is downloaded in STEP-degree cells with plain GetFeature requests (GDAL's WFS paging
# does not work with this service) and the GML files are then loaded with ogr2ogr.
# Downloaded cells are kept in var/cuzk and skipped on the next run.
source "$(dirname "$0")/_common.sh"

CP_WFS="https://services.cuzk.cz/wfs/inspire-cp-wfs.asp"
AU_WFS="https://services.cuzk.cz/wfs/inspire-au-wfs.asp"
DISTRICT_ID="${DISTRICT_ID:-AU.3604}"   # Jičín
STEP="${STEP:-0.02}"
JOBS="${JOBS:-4}"
DIR="var/cuzk"
mkdir -p "$DIR/tiles"

docker compose up -d --wait db app
run_sql_file db/schema.sql
importer() { MSYS_NO_PATHCONV=1 docker compose run --rm -v "./$DIR:/data" importer "$@"; }

# --- area ---
psql_db -c "DROP TABLE IF EXISTS stg_area"
if [[ -n "${BBOX:-}" ]]; then
  read -r MINLON MINLAT MAXLON MAXLAT <<< "$BBOX"
  psql_db -c "CREATE TABLE stg_area AS SELECT ST_MakeEnvelope($MINLON, $MINLAT, $MAXLON, $MAXLAT, 4326) AS geom"
else
  echo "==> District boundary ($DISTRICT_ID)"
  curl -sSf --retry 5 -o "$DIR/area.gml" \
    "$AU_WFS?service=WFS&version=2.0.0&request=GetFeature&storedQuery_id=urn:ogc:def:query:OGC-WFS::GetFeatureById&Id=$DISTRICT_ID&srsName=urn:ogc:def:crs:EPSG::4326"
  # GDAL misreads this document, so PostGIS parses the geometry.
  {
    echo 'CREATE TABLE stg_area AS SELECT ST_Multi(ST_GeomFromGML(CAST(g AS text))) AS geom FROM ('
    echo '  SELECT unnest(xpath($q$//au:geometry/*$q$, x, ARRAY[ARRAY[$q$au$q$, $q$http://inspire.ec.europa.eu/schemas/au/4.0$q$]])) AS g'
    echo '  FROM (SELECT CAST($gml$'
    sed 1d "$DIR/area.gml"
    echo '$gml$ AS xml) AS x) t) u;'
  } | psql_db
  read -r MINLON MINLAT MAXLON MAXLAT <<< "$(psql_db -tAF ' ' -c \
    'SELECT ST_XMin(e), ST_YMin(e), ST_XMax(e), ST_YMax(e) FROM (SELECT ST_Extent(geom) AS e FROM stg_area) t')"
fi
[[ -n "$MAXLAT" ]] || { echo "Could not determine the area" >&2; exit 1; }
echo "    bbox: $MINLON $MINLAT $MAXLON $MAXLAT"

# --- download ---
download_cell() { # minlat minlon maxlat maxlon
  local f="$DIR/tiles/$1_$2.gml"
  [[ -s "$f" ]] && grep -q '</FeatureCollection>' "$f" && return 0
  curl -sSf --retry 5 --retry-delay 5 --max-time 600 -o "$f.part" \
    "$CP_WFS?service=WFS&version=2.0.0&request=GetFeature&typeNames=CP:CadastralParcel&srsName=urn:ogc:def:crs:EPSG::4326&bbox=$1,$2,$3,$4,urn:ogc:def:crs:EPSG::4326" \
    || { echo "error: $f" >&2; return 1; }
  local head matched returned
  head=$(head -c 4000 "$f.part")
  matched=$(grep -o 'numberMatched="[0-9]*"' <<< "$head" | grep -o '[0-9]*')
  returned=$(grep -o 'numberReturned="[0-9]*"' <<< "$head" | grep -o '[0-9]*')
  if [[ -z "$returned" || "$matched" != "$returned" ]]; then
    echo "error: $f returned $returned of $matched features, decrease STEP" >&2
    return 1
  fi
  mv "$f.part" "$f"
}
export -f download_cell
export DIR CP_WFS

CELLS=$(awk -v a="$MINLON" -v b="$MINLAT" -v c="$MAXLON" -v d="$MAXLAT" -v s="$STEP" 'BEGIN {
  for (lon = a; lon < c; lon += s) for (lat = b; lat < d; lat += s)
    printf "%.4f %.4f %.4f %.4f\n", lat, lon, lat + s, lon + s }')
echo "==> Downloading $(wc -l <<< "$CELLS") cells"
xargs -P "$JOBS" -L 1 bash -c 'download_cell "$@"' _ <<< "$CELLS"

# --- load ---
echo "==> Loading GML"
importer sh -c '
  set -e; first=1
  for f in /data/tiles/*.gml; do
    if [ $first = 1 ]; then mode="-overwrite"; first=0; else mode="-append -addfields"; fi
    ogr2ogr -f PostgreSQL "PG:host=db dbname=parcely user=parcely password=parcely" "$f" CadastralParcel \
      -oo DOWNLOAD_SCHEMA=NO --config GML_ATTRIBUTES_TO_OGR_FIELDS YES \
      -nln stg_cp -lco GEOMETRY_NAME=geom -lco PRECISION=NO -nlt CONVERT_TO_LINEAR -nlt PROMOTE_TO_MULTI \
      --config PG_USE_COPY YES $mode
  done'

psql_db -c "DELETE FROM stg_cp s WHERE NOT EXISTS (
  SELECT 1 FROM stg_area a WHERE ST_Intersects(a.geom, ST_PointOnSurface(ST_MakeValid(s.geom))))"
psql_db -c "DROP TABLE stg_area"

echo "==> Transform"
run_sql_file db/transform_cuzk.sql
finalize
psql_db -tAc "SELECT count(*) || ' parcels, ' || count(DISTINCT ku_code) || ' cadastral areas' FROM parcels"
