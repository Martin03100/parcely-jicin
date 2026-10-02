#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Tile benchmark: random tiles within the data extent, first (cold) and second (warm) request.
 * Clear the tile cache first.
 *
 *   php bin/bench.php [baseUrl=http://localhost:8080] [tilesPerZoom=40]
 */

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$perZoom = (int) ($argv[2] ?? 40);

function fetchTile(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept-Encoding: gzip'],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    $info = [
        'ms' => curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000,
        'bytes' => (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
    ];
    curl_close($ch);

    return $info;
}

function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);

    return $values[(int) min(count($values) - 1, floor($p * count($values)))];
}

$meta = json_decode((string) file_get_contents("$base/api/meta"), true, flags: JSON_THROW_ON_ERROR);
if (empty($meta['boundsWebMercator'])) {
    fwrite(STDERR, "No data (run bin/import-cuzk.sh or bin/seed-demo.sh).\n");
    exit(1);
}
[$minX, $minY, $maxX, $maxY] = $meta['boundsWebMercator'];
$world = 20037508.342789244;

printf("Parcels: %s, cadastral areas: %s\n\n", number_format($meta['parcels']), $meta['cadastralAreas']);
printf("%-5s %-10s %10s %10s %10s %10s %12s\n", 'zoom', 'phase', 'p50 [ms]', 'p95 [ms]', 'max [ms]', 'median kB', 'empty');

foreach ([10, 12, 13, 14, 15, 16] as $z) {
    $n = 1 << $z;
    $tx = [(int) floor(($minX + $world) / (2 * $world) * $n), (int) floor(($maxX + $world) / (2 * $world) * $n)];
    $ty = [(int) floor(($world - $maxY) / (2 * $world) * $n), (int) floor(($world - $minY) / (2 * $world) * $n)];

    $urls = [];
    for ($i = 0; $i < $perZoom; $i++) {
        $key = "$z/" . random_int($tx[0], $tx[1]) . '/' . random_int($ty[0], $ty[1]);
        $urls[$key] = "$base/tiles/$key.pbf";
    }

    foreach (['cold', 'warm'] as $phase) {
        $times = [];
        $sizes = [];
        $empty = 0;
        foreach ($urls as $url) {
            $r = fetchTile($url);
            if ($r['status'] === 204) {
                $empty++;
                continue;
            }
            $times[] = $r['ms'];
            $sizes[] = $r['bytes'] / 1024;
        }
        printf(
            "%-5d %-10s %10.1f %10.1f %10.1f %10.1f %9d/%d\n",
            $z,
            $phase,
            percentile($times, 0.5),
            percentile($times, 0.95),
            $times === [] ? 0 : max($times),
            percentile($sizes, 0.5),
            $empty,
            count($urls),
        );
    }
}
