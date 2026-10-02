<?php

declare(strict_types=1);

namespace Parcely\Repository;

use Parcely\Support\Database;

/** Parcel detail, search and dataset metadata. */
final class ParcelRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            <<<'SQL'
            SELECT id, source, ku_code, ku_name, label, number_main, number_sub, area_m2, land_type, land_use, ruian_id,
                   ST_XMin(g) AS min_lon, ST_YMin(g) AS min_lat, ST_XMax(g) AS max_lon, ST_YMax(g) AS max_lat
            FROM (SELECT p.*, ST_Transform(ST_Envelope(p.geom), 4326) AS g FROM parcels p WHERE p.id = :id) t
            SQL
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchByNumber(int $main, ?int $sub, ?int $kuCode, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            <<<'SQL'
            SELECT id, label, ku_code, ku_name,
                   ST_X(c) AS lon, ST_Y(c) AS lat
            FROM (
                SELECT p.id, p.label, p.ku_code, p.ku_name,
                       ST_Transform(ST_PointOnSurface(p.geom), 4326) AS c
                FROM parcels p
                WHERE p.number_main = :main
                  AND (CAST(:sub AS integer) IS NULL OR p.number_sub = CAST(:sub AS integer))
                  AND (CAST(:ku AS integer) IS NULL OR p.ku_code = CAST(:ku AS integer))
                ORDER BY p.ku_code, p.number_sub NULLS FIRST
                LIMIT :limit
            ) t
            SQL
        );
        $stmt->bindValue('main', $main, \PDO::PARAM_INT);
        $stmt->bindValue('sub', $sub, $sub === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue('ku', $kuCode, $kuCode === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Parcel count and extent (WGS84 and EPSG:3857), read from the small outline table.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $row = $this->db->pdo()->query(
            <<<'SQL'
            SELECT n AS cadastral_areas, parcels,
                   ST_XMin(w) AS min_lon, ST_YMin(w) AS min_lat, ST_XMax(w) AS max_lon, ST_YMax(w) AS max_lat,
                   ST_XMin(m) AS min_x,   ST_YMin(m) AS min_y,   ST_XMax(m) AS max_x,   ST_YMax(m) AS max_y
            FROM (
                SELECT n, parcels, m, ST_Transform(m, 4326) AS w
                FROM (
                    SELECT count(*) AS n,
                           COALESCE(sum(parcel_count), 0) AS parcels,
                           ST_SetSRID(ST_Extent(geom)::geometry, 3857) AS m
                    FROM ku_outline
                ) s
            ) t
            SQL
        )->fetch();

        return $row === false ? [] : $row;
    }
}
