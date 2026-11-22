<?php

declare(strict_types=1);

// The front controller: every request starts here (`composer serve` runs it with PHP's built-in web server).
// It builds the objects, picks the controller action for "METHOD /path", and sends what it returns.

use App\ApiError;
use App\Controllers\AuthController;
use App\Controllers\PageController;
use App\Database;
use App\Models\Session;
use App\Models\User;

require __DIR__ . '/../vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;  // a static file (assets/app.js, ...): let the built-in server send it
}

$db = Database::fromEnv();
$auth = new AuthController(new User($db), new Session($db));
$routes = [
    'GET /' => [new PageController(), 'home'],
    'POST /api/register' => [$auth, 'register'],
    'POST /api/login' => [$auth, 'login'],
    'POST /api/logout' => [$auth, 'logout'],
    'GET /api/me' => [$auth, 'me'],
];

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $routes["$method $path"] ?? throw new ApiError(404, 'not found');
    if ($method === 'POST') {
        // CSRF defence, with SameSite=Lax on the cookie: only JSON (a plain HTML form cannot send it
        // cross-site) and only from this site's own pages.
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            throw new ApiError(415, 'send application/json');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && parse_url($origin, PHP_URL_HOST) . (($port = parse_url($origin, PHP_URL_PORT)) ? ":$port" : '') !== $_SERVER['HTTP_HOST']) {
            throw new ApiError(403, 'cross-site request refused');
        }
    }
    [$status, $body] = $action();
} catch (ApiError $e) {
    [$status, $body] = [$e->status, ['error' => $e->getMessage()]];
}

http_response_code($status);
header('Cache-Control: no-store');
if (is_array($body)) {
    header('Content-Type: application/json');
    echo json_encode($body);
} elseif ($body !== null) {
    header('Content-Type: text/html; charset=utf-8');
    echo $body;
}
