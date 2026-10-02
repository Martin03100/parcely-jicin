<?php

declare(strict_types=1);

namespace Parcely\Controller;

use Parcely\Http\Response;
use Parcely\Repository\TileRepository;
use Parcely\Support\TileCache;

/** GET /tiles/{z}/{x}/{y}.pbf */
final class TileController
{
    private const MAX_ZOOM = 16; // the client overzooms above

    public function __construct(
        private readonly TileRepository $tiles,
        private readonly TileCache $cache,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(array $params): Response
    {
        $z = (int) $params['z'];
        $x = (int) $params['x'];
        $y = (int) $params['y'];

        if ($z > self::MAX_ZOOM || $x >= (1 << $z) || $y >= (1 << $z)) {
            return Response::json(['error' => 'Invalid tile coordinates'], 400);
        }

        $gz = $this->cache->get($z, $x, $y);
        $cacheState = 'HIT';
        if ($gz === null) {
            $cacheState = 'MISS';
            $raw = $this->tiles->build($z, $x, $y);
            $gz = $raw === '' ? '' : (string) gzencode($raw, 6);
            if ($gz !== '') {
                $this->cache->put($z, $x, $y, $gz); // empty tiles are not stored: unbounded key space
            }
        }

        // MapLibre treats 204 as an empty tile.
        if ($gz === '') {
            return new Response(204, '', ['Cache-Control' => 'public, max-age=3600', 'X-Tile-Cache' => $cacheState]);
        }

        $etag = '"' . md5($gz) . '"';
        $headers = [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Content-Encoding' => 'gzip',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding',
            'X-Tile-Cache' => $cacheState,
        ];

        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return new Response(304, '', $headers);
        }

        return new Response(200, $gz, $headers);
    }
}
