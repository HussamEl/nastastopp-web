<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Nasta\Api;
use Nasta\Config;
use Nasta\Store;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $config = Config::load();
    $api = new Api($config, Store::open($config), [Api::class, 'curl']);
    $headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
    if (!isset($headers['authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    [$status, $body] = $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $headers, file_get_contents('php://input') ?: '');
} catch (Throwable) {
    [$status, $body] = Api::json(500, ['error' => 'server']);
}
http_response_code($status);
echo $body;
