<?php
declare(strict_types=1);

// The API's tests, on SQLite in memory and a fake Google/mapmap: php tests/run.php
require __DIR__ . '/../src/bootstrap.php';

use Nasta\Api;
use Nasta\Config;
use Nasta\Store;

$failed = 0;
function check(bool $ok, string $what): void
{
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}

$config = new Config([
    'google_routes_key' => 'G-KEY',
    'google_maps_js_key' => 'JS-KEY',
    'mapmap_key' => 'snk_test',
    'daily_limits' => ['google_routes' => 2, 'google_matrix' => 1, 'mapmap_route' => 5, 'mapmap_matrix' => 1, 'config' => 5],
]);
$store = new Store(new PDO('sqlite::memory:'));
$asked = [];
$forward = function (string $method, string $url, array $headers, ?string $body) use (&$asked): array {
    $asked[] = compact('method', 'url', 'headers', 'body');
    return [200, '{"routes":[]}'];
};
$api = new Api($config, $store, $forward);
$call = fn (string $m, string $p, array $h = [], string $b = '', array $q = []) => $api->handle($m, $p, $q, $h, $b);

echo "Enrolling\n";
check($call('GET', '/v1/config')[0] === 401, 'nothing without a token');
check($call('POST', '/v1/enroll', [], '{"code":"NOPE2345","role":"tablet"}')[0] === 403, 'an unknown code is refused');
$code = $store->newCode('Tablet 40274');
check((bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code), 'a code is 8 readable letters and digits');
check($call('POST', '/v1/enroll', [], json_encode(['code' => $code, 'role' => 'robot']))[0] === 400, 'an unknown role is refused');
[$status, $body] = $call('POST', '/v1/enroll', [], json_encode(['code' => strtolower($code), 'role' => 'tablet']));
$token = json_decode($body, true)['token'] ?? '';
check($status === 200 && strlen($token) === 64, 'a code enrolls a device (in any case)');
check($call('POST', '/v1/enroll', [], json_encode(['code' => $code, 'role' => 'tablet']))[0] === 403, 'a code is used once');
$auth = ['authorization' => 'Bearer ' . $token];

echo "Asking\n";
[$status, $body] = $call('GET', '/v1/config', $auth);
check($status === 200 && json_decode($body, true)['mapsJsKey'] === 'JS-KEY', 'the map key reaches an enrolled tablet');
check(!str_contains($body, 'G-KEY') && !str_contains($body, 'snk_test'), 'the server keys never leave the server');

$asked = [];
$routeBody = '{"origin":{"location":{"latLng":{"latitude":59.38,"longitude":13.5}}}}';
[$status] = $call('POST', '/v1/google/routes', $auth + ['x-goog-fieldmask' => 'routes.legs.duration,routes.polyline'], $routeBody);
check($status === 200 && $asked[0]['url'] === 'https://routes.googleapis.com/directions/v2:computeRoutes', 'a way is asked from Google');
check(in_array('X-Goog-Api-Key: G-KEY', $asked[0]['headers'], true) && $asked[0]['body'] === $routeBody, 'with the server key and the body as sent');
check($call('POST', '/v1/google/routes', $auth + ['x-goog-fieldmask' => "routes\nX-Evil: 1"], $routeBody)[0] === 400, 'a field mask cannot add a header');

$asked = [];
[$status] = $call('GET', '/v1/mapmap/route/13.5036,59.3793;13.7228,59.597', $auth, '', ['overview' => 'full', 'steps' => 'true', 'api_key' => 'x', 'geometries' => 'a&b']);
check($status === 200 && $asked[0]['url'] === 'https://api.mapmap.ai/route/v1/driving/13.5036,59.3793;13.7228,59.597?overview=full&steps=true', 'a mapmap way, only its own options');
check(in_array('Authorization: Bearer snk_test', $asked[0]['headers'], true), 'with the server mapmap key');
check($call('GET', '/v1/mapmap/route/../../keys', $auth)[0] === 404, 'only points in a mapmap path');

echo "Limits\n";
check($call('POST', '/v1/google/routes', $auth + ['x-goog-fieldmask' => 'routes.duration'], '{}')[0] === 200, 'the second way of the day');
check($call('POST', '/v1/google/routes', $auth + ['x-goog-fieldmask' => 'routes.duration'], '{}')[0] === 429, 'the daily limit stops the third');
check($call('POST', '/v1/mapmap/matrix', $auth, str_repeat('x', 70000))[0] === 413, 'a body too large is refused');

echo "Stopping a device\n";
$id = (int) $store->devices()[0]['id'];
$store->setActive($id, false);
check($call('GET', '/v1/config', $auth)[0] === 401, 'a stopped device is refused');
$store->setActive($id, true);
check($call('GET', '/v1/config', $auth)[0] === 200, 'and works again when started');
$usage = $store->devices()[0]['usage'];
check(count($usage) >= 3, 'its requests are counted per service');

echo "Nothing kept\n";
$dump = '';
$db = new ReflectionProperty(Store::class, 'db');
foreach (['devices', 'codes', 'usage_counts'] as $table) {
    $dump .= json_encode($db->getValue($store)->query("SELECT * FROM $table")->fetchAll());
}
check(!str_contains($dump, '59.38') && !str_contains($dump, $token) && !str_contains($dump, $code), 'no point, token or code is stored');

echo $failed === 0 ? "All passed\n" : "$failed failed\n";
exit($failed === 0 ? 0 : 1);
