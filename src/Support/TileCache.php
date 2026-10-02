<?php

declare(strict_types=1);

namespace Parcely\Support;

/**
 * File cache of gzipped tiles: {dir}/{z}/{x}/{y}.pbf. Empty tiles are stored as zero-length files.
 * No TTL: the cache is cleared on every data import.
 */
final class TileCache
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @return string|null null = miss, '' = empty tile, otherwise gzipped MVT */
    public function get(int $z, int $x, int $y): ?string
    {
        $file = $this->path($z, $x, $y);
        if (!is_file($file)) {
            return null;
        }
        $data = file_get_contents($file);

        return $data === false ? null : $data;
    }

    public function put(int $z, int $x, int $y, string $gzipped): void
    {
        $file = $this->path($z, $x, $y);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // caching is best-effort
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $gzipped) !== false) {
            rename($tmp, $file); // atomic for concurrent readers
        }
    }

    private function path(int $z, int $x, int $y): string
    {
        return "{$this->dir}/$z/$x/$y.pbf";
    }
}
