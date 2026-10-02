<?php

declare(strict_types=1);

namespace Parcely\Http;

/** Regex router; named groups are passed to the handler. */
final class Router
{
    /** @var list<array{string, callable(array<string, string>): Response}> */
    private array $routes = [];

    /** @param callable(array<string, string>): Response $handler */
    public function get(string $regex, callable $handler): void
    {
        $this->routes[] = [$regex, $handler];
    }

    public function dispatch(string $method, string $path): Response
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return Response::json(['error' => 'Method not allowed'], 405);
        }
        foreach ($this->routes as [$regex, $handler]) {
            if (preg_match($regex, $path, $m) === 1) {
                $params = array_filter($m, is_string(...), ARRAY_FILTER_USE_KEY);

                return $handler($params);
            }
        }

        return Response::json(['error' => 'Not found'], 404);
    }
}
