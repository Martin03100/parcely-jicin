<?php

declare(strict_types=1);

namespace Parcely\Repository;

use Parcely\Support\Database;

/**
 * Builds Mapbox Vector Tiles in PostGIS.
 * Below parcelMinZoom only cadastral area outlines are returned, parcels from parcelMinZoom up.
 */
final class TileRepository
{
    private const EXTENT = 4096;
    private const BUFFER = 64;

    public function __construct(
        private readonly Database $db,
        private readonly int $parcelMinZoom,
    ) {
    }

    /** @return string MVT bytes, '' for an empty tile */
    public function build(int $z, int $x, int $y): string
    {
        $sql = $z < $this->parcelMinZoom ? $this->outlineSql() : $this->parcelSql();
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['z' => $z, 'x' => $x, 'y' => $y]);
        $tile = $stmt->fetchColumn();

        // pdo_pgsql returns bytea as a stream
        if (is_resource($tile)) {
            $tile = stream_get_contents($tile);
        }

        return is_string($tile) ? $tile : '';
    }

    private function parcelSql(): string
    {
        $extent = self::EXTENT;
        $buffer = self::BUFFER;

        return <<<SQL
            WITH bounds AS (
                SELECT ST_TileEnvelope(CAST(:z AS integer), CAST(:x AS integer), CAST(:y AS integer)) AS env
            ),
            mvt AS (
                SELECT p.id, p.label, p.area_m2, p.land_type,
                       ST_AsMVTGeom(p.geom, b.env, $extent, $buffer, true) AS geom
                FROM parcels p, bounds b
                WHERE p.geom && ST_Expand(b.env, ($buffer::float / $extent) * (ST_XMax(b.env) - ST_XMin(b.env)))
            )
            SELECT ST_AsMVT(mvt.*, 'parcels', $extent, 'geom', 'id') FROM mvt WHERE geom IS NOT NULL
            SQL;
    }

    private function outlineSql(): string
    {
        $extent = self::EXTENT;
        $buffer = self::BUFFER;

        return <<<SQL
            WITH bounds AS (
                SELECT ST_TileEnvelope(CAST(:z AS integer), CAST(:x AS integer), CAST(:y AS integer)) AS env
            ),
            mvt AS (
                SELECT o.ku_code, o.parcel_count,
                       ST_AsMVTGeom(o.geom, b.env, $extent, $buffer, true) AS geom
                FROM ku_outline o, bounds b
                WHERE o.geom && b.env
            )
            SELECT ST_AsMVT(mvt.*, 'ku', $extent, 'geom') FROM mvt WHERE geom IS NOT NULL
            SQL;
    }
}
