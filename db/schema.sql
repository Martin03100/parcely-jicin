CREATE EXTENSION IF NOT EXISTS postgis;

-- Geometry is stored in EPSG:3857 so tiles need no reprojection.
CREATE TABLE IF NOT EXISTS parcels (
    id          bigserial PRIMARY KEY,
    source      text    NOT NULL DEFAULT 'cuzk',   -- cuzk | demo
    ku_code     integer,                            -- cadastral area code
    ku_name     text,
    number_main integer,
    number_sub  integer,
    label       text    NOT NULL,                   -- e.g. 123/4, st. 56
    area_m2     integer,
    land_type   text,
    land_use    text,
    ruian_id    bigint,
    geom        geometry(MultiPolygon, 3857) NOT NULL
);

-- migration
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS ku_name text;

CREATE INDEX IF NOT EXISTS parcels_geom_gix   ON parcels USING gist (geom);
CREATE INDEX IF NOT EXISTS parcels_number_idx ON parcels (number_main, number_sub, ku_code);

-- Cadastral area outlines for low zoom levels (db/finalize.sql).
CREATE TABLE IF NOT EXISTS ku_outline (
    ku_code      integer PRIMARY KEY,
    parcel_count integer NOT NULL,
    geom         geometry(MultiPolygon, 3857) NOT NULL
);
CREATE INDEX IF NOT EXISTS ku_outline_geom_gix ON ku_outline USING gist (geom);

CREATE TABLE IF NOT EXISTS app_meta (
    key   text PRIMARY KEY,
    value text NOT NULL
);
