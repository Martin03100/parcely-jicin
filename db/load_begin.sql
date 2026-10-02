-- Loads an export from bin/export-data.sh; the TSV data and db/load_end.sql follow on stdin.
BEGIN;
CREATE TEMP TABLE imp (
    ku_code integer, ku_name text, number_main integer, number_sub integer, label text,
    area_m2 integer, land_type text, land_use text, ruian_id bigint, twkb text
) ON COMMIT DROP;
COPY imp FROM STDIN;
