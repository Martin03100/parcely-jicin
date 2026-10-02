<?php

declare(strict_types=1);

namespace Parcely\Controller;

use Parcely\Http\Response;
use Parcely\Repository\ParcelRepository;

/** JSON API for parcel detail, search and metadata. */
final class ParcelController
{
    public function __construct(private readonly ParcelRepository $parcels)
    {
    }

    /** @param array<string, string> $params */
    public function show(array $params): Response
    {
        $row = $this->parcels->find((int) $params['id']);
        if ($row === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        return Response::json([
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'cadastralAreaCode' => $row['ku_code'] === null ? null : (int) $row['ku_code'],
            'cadastralAreaName' => $row['ku_name'],
            'areaM2' => $row['area_m2'] === null ? null : (int) $row['area_m2'],
            'landType' => $row['land_type'],
            'source' => $row['source'],
            'knUrl' => $this->knUrl($row),
            'bbox' => [
                (float) $row['min_lon'], (float) $row['min_lat'],
                (float) $row['max_lon'], (float) $row['max_lat'],
            ],
        ], cache: 'public, max-age=300');
    }

    /** ?q=123, 123/4, st. 123 or 123/4 Jičín; optional &ku=659541 */
    public function search(array $params): Response
    {
        $query = self::parseQuery((string) ($_GET['q'] ?? ''));
        if ($query === null) {
            return Response::json(['error' => 'Invalid query'], 400);
        }
        $ku = (string) ($_GET['ku'] ?? '');
        $ku = preg_match('#^\d{1,9}$#', $ku) === 1 ? (int) $ku : null;
        $rows = $this->parcels->searchByNumber($query['main'], $query['sub'], $query['building'], $ku, $query['area']);

        return Response::json(['results' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'label' => $r['label'],
            'cadastralAreaCode' => $r['ku_code'] === null ? null : (int) $r['ku_code'],
            'cadastralAreaName' => $r['ku_name'],
        ], $rows)]);
    }

    /**
     * Parses "123", "123/4", "st. 123/4" with an optional cadastral area name prefix ("123/4 Jičín").
     * Without the "st." prefix both building and land plots match.
     *
     * @return array{main: int, sub: ?int, building: ?bool, area: ?string}|null
     */
    public static function parseQuery(string $q): ?array
    {
        if (preg_match('#^(st\.?\s*)?(\d{1,9})(?:\s*/\s*(\d{1,9}))?(?:\s+(\p{L}[\p{L}\d .-]{0,59}))?$#iu', trim($q), $m) !== 1) {
            return null;
        }

        return [
            'main' => (int) $m[2],
            'sub' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
            'building' => $m[1] !== '' ? true : null,
            'area' => isset($m[4]) ? trim($m[4]) : null,
        ];
    }

    /** @param array<string, string> $params */
    public function meta(array $params): Response
    {
        $m = $this->parcels->meta();
        $has = isset($m['min_lon']);

        return Response::json([
            'parcels' => (int) ($m['parcels'] ?? 0),
            'cadastralAreas' => (int) ($m['cadastral_areas'] ?? 0),
            'landTypes' => json_decode((string) ($m['land_types'] ?? '[]'), true) ?: [],
            'bounds' => $has ? [(float) $m['min_lon'], (float) $m['min_lat'], (float) $m['max_lon'], (float) $m['max_lat']] : null,
            'boundsWebMercator' => $has ? [(float) $m['min_x'], (float) $m['min_y'], (float) $m['max_x'], (float) $m['max_y']] : null,
        ], cache: 'public, max-age=60');
    }

    /** @param array<string, mixed> $row */
    private function knUrl(array $row): ?string
    {
        if ($row['ruian_id'] === null) {
            return null;
        }

        return 'https://nahlizenidokn.cuzk.cz/ZobrazObjekt.aspx?typ=parcela&id=' . (int) $row['ruian_id'];
    }
}
