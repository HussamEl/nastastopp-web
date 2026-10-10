<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Nasta\Api;
use Nasta\Config;
use Nasta\Store;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// The health check says what is still missing during setup (the secrets file, the database),
// never what is in them.
if (rtrim($path, '/') === '/v1/health') {
    $check = ['ok' => false, 'config' => false, 'database' => false];
    try {
        $config = Config::load();
        $check['config'] = true;
        Store::open($config);
        $check['database'] = true;
        $check['ok'] = true;
    } catch (Throwable) {
    }
    http_response_code($check['ok'] ? 200 : 503);
    echo json_encode($check);
    exit;
}
try {
    $config = Config::load();
    $api = new Api($config, Store::open($config), [Api::class, 'curl']);
    $headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
    // Where the server does not give every header to getallheaders(), the two the API reads.
    if (!isset($headers['authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!isset($headers['x-goog-fieldmask']) && isset($_SERVER['HTTP_X_GOOG_FIELDMASK'])) {
        $headers['x-goog-fieldmask'] = $_SERVER['HTTP_X_GOOG_FIELDMASK'];
    }
    [$status, $body, $extra] = $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $headers, file_get_contents('php://input') ?: '');
} catch (Throwable) {
    [$status, $body, $extra] = Api::json(500, ['error' => 'server'], 'server');
}
// The server's own headers only (X-Nasta-Error, Retry-After): fixed words and numbers, never input.
foreach ($extra as $name => $value) {
    header($name . ': ' . $value);
}
http_response_code($status);
echo $body;
