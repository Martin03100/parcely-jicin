#!/usr/bin/env bash
# Exports imported parcels to var/parcels-cuzk.tsv.gz (geometry as TWKB, 1 cm precision).
source "$(dirname "$0")/_common.sh"
OUT="var/parcels-cuzk.tsv.gz"
psql_db -c "COPY (
  SELECT ku_code, ku_name, number_main, number_sub, label, area_m2, land_type, land_use, ruian_id,
         encode(ST_AsTWKB(geom, 2), 'hex')
  FROM parcels WHERE source = 'cuzk' ORDER BY id
) TO STDOUT" | gzip -9 > "$OUT"
echo "$OUT: $(gunzip -c "$OUT" | wc -l) parcels, $(du -h "$OUT" | cut -f1)"
