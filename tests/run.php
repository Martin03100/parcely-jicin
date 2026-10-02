<?php

declare(strict_types=1);

/**
 * Unit tests without dependencies: php tests/run.php
 */

use Parcely\Controller\ParcelController;
use Parcely\Http\Response;
use Parcely\Http\Router;
use Parcely\Support\Config;
use Parcely\Support\Database;
use Parcely\Support\TileCache;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Parcely\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});

$failures = 0;
$count = 0;

function test(string $name, callable $fn): void
{
    global $failures, $count;
    $count++;
    try {
        $fn();
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL  $name: {$e->getMessage()}\n";
    }
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true)));
    }
}

// --- search query ---

test('parses a plain number', fn () => same(['main' => 123, 'sub' => null, 'building' => null, 'area' => null], ParcelController::parseQuery('123')));
test('parses a number with subdivision', fn () => same(['main' => 123, 'sub' => 4, 'building' => null, 'area' => null], ParcelController::parseQuery(' 123 / 4 ')));
test('parses a building plot', fn () => same(['main' => 56, 'sub' => null, 'building' => true, 'area' => null], ParcelController::parseQuery('st. 56')));
test('parses a building plot without dot', fn () => same(['main' => 56, 'sub' => 2, 'building' => true, 'area' => null], ParcelController::parseQuery('ST56/2')));
test('parses a cadastral area name', fn () => same(['main' => 594, 'sub' => null, 'building' => true, 'area' => 'Lázně Bělohrad'], ParcelController::parseQuery('st. 594 Lázně Bělohrad')));
test('rejects invalid queries', function (): void {
    foreach (['', 'abc', '12a', '1/2/3', '1234567890', "1 x'; DROP TABLE parcels", '1 %'] as $q) {
        same(null, ParcelController::parseQuery($q));
    }
});

// --- router ---

test('router passes named groups', function (): void {
    $router = new Router();
    $router->get('#^/a/(?P<id>\d+)$#', fn (array $p) => Response::json($p));
    same('{"id":"42"}', $router->dispatch('GET', '/a/42')->body);
});
test('router returns 404 and 405', function (): void {
    $router = new Router();
    same(404, $router->dispatch('GET', '/missing')->status);
    same(405, $router->dispatch('POST', '/missing')->status);
});

// --- response ---

test('json response', function (): void {
    $r = Response::json(['a' => 'č'], 201, 'public, max-age=60');
    same(201, $r->status);
    same('{"a":"č"}', $r->body);
    same('public, max-age=60', $r->headers['Cache-Control']);
});

// --- tile cache ---

test('tile cache stores and reads tiles', function (): void {
    $dir = sys_get_temp_dir() . '/parcely-test-' . bin2hex(random_bytes(4));
    $cache = new TileCache($dir);
    same(null, $cache->get(14, 1, 2));
    $cache->put(14, 1, 2, 'tile');
    same('tile', $cache->get(14, 1, 2));
    unlink("$dir/14/1/2.pbf");
    rmdir("$dir/14/1");
    rmdir("$dir/14");
    rmdir($dir);
});

// --- configuration ---

test('config falls back to defaults', function (): void {
    $config = new Config(['A' => 'x']);
    same('x', $config->get('A', 'y'));
    same('d', $config->get('PARCELY_UNSET_VARIABLE', 'd'));
    same(14, (new Config(['Z' => '14']))->int('Z', 0));
});
test('parses DATABASE_URL', fn () => same(
    ['pgsql:host=db.example;port=5432;dbname=parcely', 'user', 'p@ss'],
    Database::parseUrl('postgresql://user:p%40ss@db.example/parcely'),
));
test('rejects invalid DATABASE_URL', function (): void {
    try {
        Database::parseUrl('mysql://user@host/db');
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('no exception');
});

echo $failures === 0 ? "\n$count tests passed\n" : "\n$failures of $count tests failed\n";
exit($failures === 0 ? 0 : 1);
