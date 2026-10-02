<?php

declare(strict_types=1);

namespace Parcely\Support;

use InvalidArgumentException;
use PDO;

/** Lazy PDO connection: cached tiles never touch the database. */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            [$dsn, $user, $password] = $this->credentials();
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }

    /**
     * Converts postgres://user:pass@host:port/db to [dsn, user, password].
     *
     * @return array{string, string, string}
     */
    public static function parseUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['host'], $p['path']) || !in_array($p['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new InvalidArgumentException('Invalid DATABASE_URL');
        }
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/'));

        return [$dsn, rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? '')];
    }

    /** @return array{string, string, string} */
    private function credentials(): array
    {
        $url = $this->config->get('DATABASE_URL', '');
        if ($url !== '') {
            return self::parseUrl($url);
        }

        return [
            $this->config->get('DB_DSN', 'pgsql:host=localhost;port=5432;dbname=parcely'),
            $this->config->get('DB_USER', 'parcely'),
            $this->config->get('DB_PASSWORD', 'parcely'),
        ];
    }
}
