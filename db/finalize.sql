-- Run after every import.
BEGIN;
TRUNCATE ku_outline;
INSERT INTO ku_outline (ku_code, parcel_count, geom)
SELECT ku_code,
       count(*),
       ST_Multi(ST_CollectionExtract(ST_Buffer(ST_Buffer(ST_Union(ST_MakeValid(ST_SnapToGrid(geom, 0.5)), 0.5), 2), -2), 3))
FROM parcels
WHERE ku_code IS NOT NULL
GROUP BY ku_code;
COMMIT;

-- Physically order rows by location so tile queries read fewer pages.
CLUSTER parcels USING parcels_geom_gix;
ANALYZE parcels;
ANALYZE ku_outline;
