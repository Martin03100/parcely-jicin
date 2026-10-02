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
            return Response::json(['error' => 'Parcela nenalezena'], 404);
        }

        return Response::json([
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'cadastralAreaCode' => $row['ku_code'] === null ? null : (int) $row['ku_code'],
            'cadastralAreaName' => $row['ku_name'],
            'areaM2' => $row['area_m2'] === null ? null : (int) $row['area_m2'],
            'landType' => $row['land_type'],
            'landUse' => $row['land_use'],
            'source' => $row['source'],
            'knUrl' => $this->knUrl($row),
            'bbox' => [
                (float) $row['min_lon'], (float) $row['min_lat'],
                (float) $row['max_lon'], (float) $row['max_lat'],
            ],
        ], cache: 'public, max-age=300');
    }

    /** ?q=123 or ?q=123/4, optional &ku=600123 */
    public function search(array $params): Response
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (preg_match('#^(\d{1,9})(?:\s*/\s*(\d{1,9}))?$#', $q, $m) !== 1) {
            return Response::json(['results' => [], 'hint' => 'Zadej číslo parcely, např. 123 nebo 123/4']);
        }
        $ku = isset($_GET['ku']) && ctype_digit((string) $_GET['ku']) ? (int) $_GET['ku'] : null;
        $rows = $this->parcels->searchByNumber((int) $m[1], isset($m[2]) ? (int) $m[2] : null, $ku);

        return Response::json(['results' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'label' => $r['label'],
            'cadastralAreaCode' => $r['ku_code'] === null ? null : (int) $r['ku_code'],
            'cadastralAreaName' => $r['ku_name'],
            'lon' => (float) $r['lon'],
            'lat' => (float) $r['lat'],
        ], $rows)]);
    }

    /** @param array<string, string> $params */
    public function meta(array $params): Response
    {
        $m = $this->parcels->meta();
        $has = isset($m['min_lon']);

        return Response::json([
            'parcels' => (int) ($m['parcels'] ?? 0),
            'cadastralAreas' => (int) ($m['cadastral_areas'] ?? 0),
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
