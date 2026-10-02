<?php

declare(strict_types=1);

namespace Parcely\Http;

/** Immutable HTTP response. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200, string $cache = 'no-store'): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => $cache],
        );
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if (!$headOnly && $this->status !== 204 && $this->status !== 304) {
            header('Content-Length: ' . strlen($this->body));
            echo $this->body;
        }
    }
}
