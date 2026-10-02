<?php

declare(strict_types=1);

/**
 * Front controller (Apache, or: php -S localhost:8080 -t public public/index.php).
 */

use Parcely\Controller\ParcelController;
use Parcely\Controller\TileController;
use Parcely\Http\Response;
use Parcely\Http\Router;
use Parcely\Repository\ParcelRepository;
use Parcely\Repository\TileRepository;
use Parcely\Support\Config;
use Parcely\Support\Database;
use Parcely\Support\TileCache;

$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Built-in server: let it serve static files.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');

    return true;
}

// PSR-4 autoloader: Parcely\ -> src/
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Parcely\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$config = new Config();
$db = new Database($config);
$tileCache = new TileCache($config->get('TILE_CACHE_DIR', $root . '/var/tiles'));
$tiles = new TileController(
    new TileRepository($db, $config->int('PARCEL_MIN_ZOOM', 14)),
    $tileCache,
);
$parcels = new ParcelController(new ParcelRepository($db));

$router = new Router();
$router->get('#^/tiles/(?P<z>\d{1,2})/(?P<x>\d{1,8})/(?P<y>\d{1,8})\.pbf$#', $tiles);
$router->get('#^/api/parcels/(?P<id>\d+)$#', $parcels->show(...));
$router->get('#^/api/search$#', $parcels->search(...));
$router->get('#^/api/meta$#', $parcels->meta(...));

try {
    $response = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $e) {
    error_log((string) $e);
    $response = Response::json(['error' => 'Internal server error'], 500);
}

$response->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
return true;
