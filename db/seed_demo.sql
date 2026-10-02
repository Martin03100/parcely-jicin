-- Synthetic parcels around Jičín (source = 'demo') on a jittered grid with shared vertices.
-- Default 300 x 300; size: psql -v cols=500 -v rows=500 -f db/seed_demo.sql
if :{?cols} \else \set cols 300 \endif
\if :{?rows} \else \set rows 300 \endif

BEGIN;
DELETE FROM parcels WHERE source = 'demo';

WITH origin AS (
    SELECT ST_X(p) AS ox, ST_Y(p) AS oy
    FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(15.3513, 50.4368), 4326), 3857) AS p) t
),
-- jitter depends only on (i, j), so neighbouring cells share vertices
vertices AS (
    SELECT i, j,
           ox + (i - :cols / 2) * 30.0 + 11 * sin(i * 12.9898 + j * 78.233) AS vx,
           oy + (j - :rows / 2) * 60.0 + 22 * cos(i * 39.346  + j * 11.135) AS vy
    FROM origin, generate_series(0, :cols) i, generate_series(0, :rows) j
),
cells AS (
    SELECT a.i, a.j,
           ST_Multi(ST_SetSRID(ST_MakePolygon(ST_MakeLine(ARRAY[
               ST_MakePoint(a.vx, a.vy), ST_MakePoint(b.vx, b.vy),
               ST_MakePoint(c.vx, c.vy), ST_MakePoint(d.vx, d.vy),
               ST_MakePoint(a.vx, a.vy)])), 3857)) AS geom,
           random() AS rt, random() AS rs
    FROM vertices a
    JOIN vertices b ON b.i = a.i + 1 AND b.j = a.j
    JOIN vertices c ON c.i = a.i + 1 AND c.j = a.j + 1
    JOIN vertices d ON d.i = a.i     AND d.j = a.j + 1
),
types AS (
    SELECT ARRAY['orná půda', 'orná půda', 'orná půda', 'trvalý travní porost', 'lesní pozemek',
                 'zahrada', 'zastavěná plocha a nádvoří', 'ostatní plocha', 'vodní plocha', 'ovocný sad'] AS t
)
INSERT INTO parcels (source, ku_code, number_main, number_sub, label, area_m2, land_type, geom)
SELECT 'demo',
       600000 + (i / 50) * 10 + (j / 50),            -- 50 x 50 blocks as cadastral areas
       1 + (i * :rows + j) % 4000,
       CASE WHEN rs < 0.3 THEN 1 + (rs * 30)::int END,
       '', 0,
       t[1 + floor(rt * 10)::int],
       geom
FROM cells, types;

UPDATE parcels
   SET area_m2 = round(ST_Area(geom)),
       label   = number_main || COALESCE('/' || number_sub, '')
 WHERE source = 'demo';
COMMIT;
