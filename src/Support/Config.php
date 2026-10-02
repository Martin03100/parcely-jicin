<?php

declare(strict_types=1);

namespace Parcely\Support;

/** Configuration from environment variables. */
final class Config
{
    /** @param array<string, string> $overrides */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public function get(string $key, string $default): string
    {
        if (isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    public function int(string $key, int $default): int
    {
        return (int) $this->get($key, (string) $default);
    }
}
