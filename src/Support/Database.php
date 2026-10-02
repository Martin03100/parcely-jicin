<?php

declare(strict_types=1);

namespace Parcely\Support;

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
        [$dsn, $user, $password] = $this->credentials();

        return $this->pdo ??= new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /**
     * DATABASE_URL (postgres://user:pass@host:port/db) takes precedence over DB_DSN / DB_USER / DB_PASSWORD.
     *
     * @return array{string, string, string}
     */
    private function credentials(): array
    {
        $url = $this->config->get('DATABASE_URL', '');
        if ($url === '') {
            return [
                $this->config->get('DB_DSN', 'pgsql:host=localhost;port=5432;dbname=parcely'),
                $this->config->get('DB_USER', 'parcely'),
                $this->config->get('DB_PASSWORD', 'parcely'),
            ];
        }
        $p = parse_url($url);
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $p['host'] ?? 'localhost',
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '/parcely', '/'),
        );

        return [$dsn, rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? '')];
    }
}
