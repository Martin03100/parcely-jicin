DELETE FROM parcels;
INSERT INTO parcels (source, ku_code, ku_name, number_main, number_sub, label, area_m2, land_type, land_use, ruian_id, geom)
SELECT 'cuzk', ku_code, ku_name, number_main, number_sub, label, area_m2, land_type, land_use, ruian_id,
       ST_Multi(ST_SetSRID(ST_GeomFromTWKB(decode(twkb, 'hex')), 3857))
FROM imp;
INSERT INTO app_meta VALUES ('data_url', :'data_url')
    ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value;
COMMIT;
