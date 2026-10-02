DELETE FROM parcels;
-- Spatial (Hilbert) order, so nearby parcels share disk pages.
INSERT INTO parcels (source, ku_code, ku_name, number_main, number_sub, label, area_m2, land_type, land_use, ruian_id, geom)
SELECT * FROM (
    SELECT 'cuzk', ku_code, ku_name, number_main, number_sub, label, area_m2, land_type, land_use, ruian_id,
           ST_Multi(ST_SetSRID(ST_GeomFromTWKB(decode(twkb, 'hex')), 3857)) AS geom
    FROM imp
) t
ORDER BY geom;
COMMIT;
