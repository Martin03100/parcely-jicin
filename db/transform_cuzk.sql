-- stg_cp (raw INSPIRE GML loaded by bin/import-cuzk.sh) -> parcels.
--   gml_id                      CP.1760152604   (RÚIAN parcel id)
--   label                       774/2, st. 3198
--   nationalcadastralreference  659541-774/2    (cadastral area code - number)
--   zoning_title                Jičín           (cadastral area name)
-- Download cells overlap, hence DISTINCT ON.
BEGIN;
DELETE FROM parcels WHERE source = 'cuzk';

-- Rows are inserted in spatial (Hilbert) order, so nearby parcels share disk pages.
INSERT INTO parcels (source, ku_code, ku_name, number_main, number_sub, label, area_m2, land_type, ruian_id, geom)
SELECT * FROM (
SELECT DISTINCT ON (gml_id)
       'cuzk',
       NULLIF(split_part(nationalcadastralreference, '-', 1), '')::int,
       zoning_title,
       m[1]::int,
       m[2]::int,
       label,
       areavalue,
       -- INSPIRE has no land type, but building plots ("st.") are always built-up areas.
       CASE WHEN label LIKE 'st.%' THEN 'zastavěná plocha a nádvoří' END,
       NULLIF(substring(gml_id FROM '(\d+)$'), '')::bigint,
       ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(geom, 3857)), 3)) AS geom
FROM (
    SELECT s.*, regexp_match(s.label, '(\d+)(?:/(\d+))?') AS m
    FROM stg_cp s
    WHERE s.label IS NOT NULL AND s.geom IS NOT NULL
) t
ORDER BY gml_id
) d
ORDER BY geom;

DELETE FROM parcels WHERE source = 'cuzk' AND ST_IsEmpty(geom);
DELETE FROM parcels WHERE source = 'demo';

DROP TABLE stg_cp;
COMMIT;
