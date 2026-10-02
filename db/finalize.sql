-- Run after every import. Outlines are computed first and swapped in a short transaction,
-- so the API is not blocked while they are being built.
CREATE TEMP TABLE outline_new AS
SELECT ku_code,
       count(*) AS parcel_count,
       ST_Multi(ST_CollectionExtract(ST_Buffer(ST_Buffer(ST_Union(ST_MakeValid(ST_SnapToGrid(geom, 0.5)), 0.5), 2), -2), 3)) AS geom
FROM parcels
WHERE ku_code IS NOT NULL
GROUP BY ku_code;

BEGIN;
DELETE FROM ku_outline;
INSERT INTO ku_outline (ku_code, parcel_count, geom) SELECT ku_code, parcel_count, geom FROM outline_new;

INSERT INTO app_meta (key, value)
SELECT 'land_types', COALESCE(json_agg(t ORDER BY t), '[]')::text
FROM (SELECT DISTINCT land_type AS t FROM parcels WHERE land_type IS NOT NULL) d
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value;
COMMIT;

ANALYZE parcels;
ANALYZE ku_outline;
