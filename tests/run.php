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

/** The server's own error word on an answer, or null. */
function slug(array $answer): ?string
{
    return $answer[2]['X-Nasta-Error'] ?? null;
}

/**
 * A database where another request acts between this one's steps: it makes the day's row between
 * this one's update and its insert ([other]), or takes the code this one has just read ([codeTaken]).
 */
final class RacingPdo extends PDO
{
    /** [service, requests] of the other request's row, made once. */
    public ?array $other = null;

    /** The other request deletes the codes before this one does, once. */
    public bool $codeTaken = false;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->codeTaken && str_starts_with($query, 'DELETE FROM codes')) {
            $this->codeTaken = false;
            parent::exec('DELETE FROM codes');
        }
        if ($this->other !== null && str_starts_with($query, 'INSERT INTO usage_counts')) {
            [$service, $requests] = $this->other;
            $this->other = null;
            parent::prepare('INSERT INTO usage_counts (device_id, day, service, requests) VALUES (1, ?, ?, ?)')->execute([gmdate('Y-m-d'), $service, $requests]);
        }
        return parent::prepare($query, $options);
    }
}

/** A refusal of the server's own: [status], its word in X-Nasta-Error and in the body, and nothing else. */
function refused(array $answer, int $status, string $slug): bool
{
    return $answer[0] === $status && slug($answer) === $slug && json_decode($answer[1], true) === ['error' => $slug];
}

// The app's field masks (RoutesApi.FIELDS and MATRIX_FIELDS in HussamEl/S20Ultra), copied byte for byte.
const APP_FIELDS = 'routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline,routes.legs.duration,routes.legs.distanceMeters,routes.legs.polyline.encodedPolyline';
const APP_MATRIX_FIELDS = 'originIndex,destinationIndex,duration,condition';

// The app's requests as it writes them (RoutesApi.body, matrixBody; MapmapApi.serverRouteBody,
// matrixBody), with invented places from S20Ultra's testdata/README.md.
const ROUTE_BODY = '{"origin":{"location":{"latLng":{"latitude":59.381234,"longitude":13.501234}}},'
    . '"destination":{"address":"Storgatan 14, 652 24 Karlstad"},'
    . '"intermediates":[{"location":{"latLng":{"latitude":59.597,"longitude":13.7228}}}],'
    . '"travelMode":"DRIVE","languageCode":"sv-SE","units":"METRIC"}';
const MATRIX_BODY = '{"origins":[{"waypoint":{"location":{"latLng":{"latitude":59.381234,"longitude":13.501234}}}},'
    . '{"waypoint":{"location":{"latLng":{"latitude":59.597,"longitude":13.7228}}}}],'
    . '"destinations":[{"waypoint":{"location":{"latLng":{"latitude":59.597,"longitude":13.7228}}}}],"travelMode":"DRIVE"}';
const MAPMAP_POINTS = '13.501234,59.381234;13.722800,59.597000';
const MAPMAP_ROUTE_BODY = '{"points":"' . MAPMAP_POINTS . '","overview":"full","geometries":"polyline","steps":"true"}';
const MAPMAP_MATRIX_BODY = '{"sources":[{"lat":59.381234,"lon":13.501234},{"lat":59.597,"lon":13.7228}],"targets":[{"lat":59.597,"lon":13.7228}],"costing":"auto"}';

$keys = ['google_routes_key' => 'G-KEY', 'google_maps_js_key' => 'JS-KEY', 'mapmap_key' => 'snk_test'];
$roomy = ['google_routes' => 100, 'google_matrix' => 100, 'mapmap_route' => 100, 'mapmap_matrix' => 100, 'config' => 100];
$store = new Store(new PDO('sqlite::memory:'));
$asked = [];
$reply = [200, '{"routes":[]}'];
$forward = function (string $method, string $url, array $headers, ?string $body) use (&$asked, &$reply): array {
    $asked[] = compact('method', 'url', 'headers', 'body');
    return $reply;
};
$apiWith = fn (array $values) => new Api(new Config($values), $store, $forward);
$api = $apiWith($keys + ['daily_limits' => $roomy]);
$call = fn (string $m, string $p, array $h = [], string $b = '', ?Api $with = null) => ($with ?? $api)->handle($m, $p, $h, $b);

/** Requests counted today for the device labelled [label], per service. */
$today = function (string $label) use ($store): array {
    foreach ($store->devices() as $d) {
        if ($d['label'] === $label) {
            return array_map('intval', array_column($d['usage'], 'today', 'service'));
        }
    }
    return [];
};
$enroll = function (string $label, string $role) use ($store, $call): string {
    $code = $store->newCode($label);
    return json_decode($call('POST', '/v1/enroll', [], json_encode(['code' => $code, 'role' => $role]))[1], true)['token'] ?? '';
};
$route = fn (array $auth, string $body = ROUTE_BODY, string $fields = APP_FIELDS, ?Api $with = null) =>
    $call('POST', '/v1/google/routes', $auth + ['x-goog-fieldmask' => $fields], $body, $with);
$matrix = fn (array $auth, string $body = MATRIX_BODY, string $fields = APP_MATRIX_FIELDS, ?Api $with = null) =>
    $call('POST', '/v1/google/matrix', $auth + ['x-goog-fieldmask' => $fields], $body, $with);

echo "Enrolling\n";
check(refused($call('GET', '/v1/config'), 401, 'not-enrolled'), 'nothing without a token');
check(refused($call('GET', '/src/Api.php'), 404, 'unknown') && refused($call('GET', '/.git/config'), 404, 'unknown'), 'a path the API does not have is unknown');
check(refused($call('GET', '/v1/enroll'), 404, 'unknown') && refused($call('POST', '/v1/config'), 404, 'unknown'), 'a path asked with another method is unknown');
check(refused($call('POST', '/v1/enroll', [], '{"code":"NOPE2345","role":"tablet"}'), 403, 'unknown'), 'an unknown code is refused');
$code = $store->newCode('tablet-40274');
check((bool) preg_match('/^[A-HJKMNP-Z2-9]{8}$/', $code), 'a code is 8 readable letters and digits (no 0/O, 1/I/L)');
check(refused($call('POST', '/v1/enroll', [], json_encode(['code' => $code, 'role' => 'robot'])), 400, 'bad-request'), 'an unknown role is refused');
check(refused($call('POST', '/v1/enroll', [], 'code=' . $code), 400, 'bad-request'), 'an enrollment that is not JSON is refused');
[$status, $body] = $call('POST', '/v1/enroll', [], json_encode(['code' => strtolower($code), 'role' => 'tablet']));
$enrolled = json_decode($body, true);
$token = $enrolled['token'] ?? '';
check($status === 200 && (bool) preg_match('/^[0-9a-f]{64}$/', $token), 'a code enrolls a device (in any case) with a token of 64 hex digits');
check(is_array($enrolled) && array_keys($enrolled) === ['token', 'label'] && $enrolled['label'] === 'tablet-40274', 'the enrollment answers the token and the label');
check(refused($call('POST', '/v1/enroll', [], json_encode(['code' => $code, 'role' => 'tablet'])), 403, 'unknown'), 'a code is used once');
$auth = ['authorization' => 'Bearer ' . $token];

echo "The token\n";
check(refused($call('GET', '/v1/config', ['authorization' => 'Bearer ' . str_repeat('ab', 32)]), 401, 'not-enrolled'), 'a token no device holds is not enrolled');
foreach (['Bearer xyz', 'Basic ' . base64_encode('a:b'), $token, 'Bearer ' . $token . 'ff', 'Token ' . $token] as $wrong) {
    check(refused($call('GET', '/v1/config', ['authorization' => $wrong]), 401, 'not-enrolled'), 'a malformed Authorization is not enrolled: ' . substr($wrong, 0, 6) . '…');
}
check($call('GET', '/v1/config', ['authorization' => 'bearer ' . $token])[0] === 200, 'a lower-case "bearer" works');
check($call('GET', '/v1/config', ['authorization' => 'BEARER ' . strtoupper($token)])[0] === 200, 'so does an upper-case one, and the token in capitals');
check(refused($call('GET', '/src/Api.php', $auth), 404, 'unknown'), 'with a token, an unknown path is still unknown');

echo "The map key\n";
[$status, $body, $headers] = $call('GET', '/v1/config', $auth);
check($status === 200 && $headers === [], 'the map key reaches an enrolled tablet, with no error header');
check(json_decode($body, true) === ['mapsJsKey' => 'JS-KEY', 'label' => 'tablet-40274', 'ways' => ['google' => true, 'mapmap' => true]], 'the config is the map key, the label and the ways the server can ask');
check(!str_contains($body, 'G-KEY') && !str_contains($body, 'snk_test'), 'the server keys never leave the server');
$noJs = $apiWith(['google_routes_key' => 'G-KEY', 'mapmap_key' => 'snk_test', 'daily_limits' => $roomy]);
$before = $today('tablet-40274')['config'] ?? 0;
check(refused($call('GET', '/v1/config', $auth, '', $noJs), 503, 'no-key'), 'no map key on the server: 503 no-key');
check(($today('tablet-40274')['config'] ?? 0) === $before, 'and that is not counted');
$noMapmap = $apiWith(['google_routes_key' => 'G-KEY', 'google_maps_js_key' => 'JS-KEY', 'daily_limits' => $roomy]);
check(json_decode($call('GET', '/v1/config', $auth, '', $noMapmap)[1], true)['ways'] === ['google' => true, 'mapmap' => false], 'no mapmap key: the config says so');

echo "Google\n";
check(Api::ROUTE_FIELDS === APP_FIELDS && Api::MATRIX_FIELDS === APP_MATRIX_FIELDS, "the server's field masks are the app's");
$asked = [];
[$status, $body, $headers] = $route($auth);
check($status === 200 && count($asked) === 1 && $asked[0]['method'] === 'POST' && $asked[0]['url'] === 'https://routes.googleapis.com/directions/v2:computeRoutes', 'a way is asked from Google');
check(in_array('X-Goog-Api-Key: G-KEY', $asked[0]['headers'], true) && in_array('X-Goog-FieldMask: ' . APP_FIELDS, $asked[0]['headers'], true), "with the server key and the app's field mask");
check($asked[0]['body'] === ROUTE_BODY, 'and the body as the app sent it');
check($headers === [] && $body === '{"routes":[]}', "Google's answer comes back as it came");
$asked = [];
[$status] = $matrix($auth);
check($status === 200 && count($asked) === 1 && $asked[0]['url'] === 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', 'travel times are asked from Google');
check(in_array('X-Goog-FieldMask: ' . APP_MATRIX_FIELDS, $asked[0]['headers'], true) && $asked[0]['body'] === MATRIX_BODY, "with the app's matrix field mask and body");

$asked = [];
$before = $today('tablet-40274');
foreach (['*', 'routes.*', APP_MATRIX_FIELDS, APP_FIELDS . ',routes.travelAdvisory', strtoupper(APP_FIELDS), APP_FIELDS . ' ', "routes\nX-Evil: 1", ''] as $mask) {
    check(refused($route($auth, ROUTE_BODY, $mask), 400, 'field-mask'), 'a way with another field mask is refused: ' . json_encode(substr($mask, 0, 24)));
}
foreach (['*', APP_FIELDS, APP_MATRIX_FIELDS . ',originIndex'] as $mask) {
    check(refused($matrix($auth, MATRIX_BODY, $mask), 400, 'field-mask'), 'a matrix with another field mask is refused: ' . json_encode(substr($mask, 0, 24)));
}
check(refused($call('POST', '/v1/google/routes', $auth, ROUTE_BODY), 400, 'field-mask'), 'a way without a field mask is refused');

$routeWith = function (callable $change): string {
    $request = json_decode(ROUTE_BODY, true);
    $change($request);
    return json_encode($request);
};
$point = ['location' => ['latLng' => ['latitude' => 59.4, 'longitude' => 13.5]]];
$badRoutes = [
    'routingPreference' => $routeWith(function (&$r) { $r['routingPreference'] = 'TRAFFIC_AWARE'; }),
    'extraComputations' => $routeWith(function (&$r) { $r['extraComputations'] = ['TOLLS']; }),
    'computeAlternativeRoutes' => $routeWith(function (&$r) { $r['computeAlternativeRoutes'] = true; }),
    'requestedReferenceRoutes' => $routeWith(function (&$r) { $r['requestedReferenceRoutes'] = ['FUEL_EFFICIENT']; }),
    'optimizeWaypointOrder' => $routeWith(function (&$r) { $r['optimizeWaypointOrder'] = true; }),
    'a walk' => $routeWith(function (&$r) { $r['travelMode'] = 'WALK'; }),
    '11 stops between' => $routeWith(function (&$r) use ($point) { $r['intermediates'] = array_fill(0, 11, $point); }),
    'a stop passed through' => $routeWith(function (&$r) use ($point) { $r['intermediates'] = [$point + ['via' => true]]; }),
    'a stopover' => $routeWith(function (&$r) use ($point) { $r['intermediates'] = [$point + ['vehicleStopover' => true]]; }),
    'a heading' => $routeWith(function (&$r) { $r['origin']['location']['heading'] = 90; }),
    'a stop without a place' => $routeWith(function (&$r) { $r['destination'] = ['name' => 'Anna Testsson']; }),
    'no origin' => $routeWith(function (&$r) { unset($r['origin']); }),
    'a point off the earth' => $routeWith(function (&$r) { $r['origin']['location']['latLng']['latitude'] = 123.0; }),
    'a language ending in a new line' => $routeWith(function (&$r) { $r['languageCode'] = "sv\n"; }),
    'a list' => '[1,2]',
    'not JSON' => 'origin=59.38,13.5',
];
foreach ($badRoutes as $what => $bad) {
    check(refused($route($auth, $bad), 400, 'bad-request'), 'a Google way is refused with ' . $what);
}
$tenBetween = $routeWith(function (&$r) use ($point) { $r['intermediates'] = array_fill(0, 10, $point); });
check($route($auth, $tenBetween)[0] === 200, 'ten stops between are taken');
$places = fn (int $n) => array_fill(0, $n, ['waypoint' => ['location' => ['latLng' => ['latitude' => 59.4, 'longitude' => 13.5]]]]);
check(refused($matrix($auth, json_encode(['origins' => $places(11), 'destinations' => $places(10)])), 400, 'bad-request'), 'a matrix of more than 100 answers is refused');
check($matrix($auth, json_encode(['origins' => $places(10), 'destinations' => $places(10)]))[0] === 200, 'a matrix of 100 is taken');
check(refused($matrix($auth, json_encode(['origins' => $places(2), 'destinations' => $places(2), 'routingPreference' => 'TRAFFIC_AWARE_OPTIMAL'])), 400, 'bad-request'), 'a matrix with traffic is refused');
check(refused($matrix($auth, json_encode(['origins' => $places(2), 'destinations' => []])), 400, 'bad-request'), 'a matrix without destinations is refused');
check(count($asked) === 2, 'nothing refused reaches Google');
$after = $today('tablet-40274');
check(($after['google_routes'] ?? 0) === ($before['google_routes'] ?? 0) + 1 && ($after['google_matrix'] ?? 0) === ($before['google_matrix'] ?? 0) + 1, 'a request refused by a check is not counted');

echo "mapmap\n";
$asked = [];
[$status, , $headers] = $call('POST', '/v1/mapmap/route', $auth, MAPMAP_ROUTE_BODY);
check($status === 200 && count($asked) === 1 && $asked[0]['method'] === 'GET' && $asked[0]['body'] === null, 'a mapmap way is asked');
check($asked[0]['url'] === 'https://api.mapmap.ai/route/v1/driving/' . MAPMAP_POINTS . '?overview=full&geometries=polyline&steps=true', 'by its points and its own options, as the app names them');
check(in_array('Authorization: Bearer snk_test', $asked[0]['headers'], true) && $headers === [], 'with the server mapmap key');
$asked = [];
check($call('POST', '/v1/mapmap/route', $auth, '{"points":"-13.5,-59.3;13.722800,59.597000"}')[0] === 200
    && $asked[0]['url'] === 'https://api.mapmap.ai/route/v1/driving/-13.5,-59.3;13.722800,59.597000', 'negative points and no options are taken');
$badPoints = [
    'one point' => '13.5,59.3',
    'thirteen points' => implode(';', array_fill(0, 13, '13.5,59.3')),
    'three numbers in a pair' => '13.5,59.3,1;13.6,59.4',
    'a longitude off the earth' => '181.0,59.3;13.6,59.4',
    'a latitude off the earth' => '13.5,90.5;13.6,59.4',
    'seven decimals' => '13.1234567,59.3;13.6,59.4',
    'an empty pair' => '13.5,59.3;;13.6,59.4',
    'a path' => '../../keys',
    'a number with two dots' => '13.5.1,59.3;13.6,59.4',
    'a sign in the middle' => '13-5,59.3;13.6,59.4',
    'nothing' => '',
    'a new line at the end' => MAPMAP_POINTS . "\n",
    'a new line after a pair' => "13.5,59.3\n;13.6,59.4",
];
$before = $today('tablet-40274');
$asked = [];
foreach ($badPoints as $what => $points) {
    check(refused($call('POST', '/v1/mapmap/route', $auth, json_encode(['points' => $points])), 400, 'bad-request'), 'a mapmap way is refused with ' . $what);
}
check($asked === [] && ($today('tablet-40274')['mapmap_route'] ?? 0) === ($before['mapmap_route'] ?? 0), 'points refused are neither asked nor counted');
$badRouteBodies = [
    'points as a list' => '{"points":[13.5,59.3]}',
    'a name' => '{"points":"' . MAPMAP_POINTS . '","name":"Anna Testsson"}',
    'a key of its own' => '{"points":"' . MAPMAP_POINTS . '","api_key":"x"}',
    'an option that is not a word' => '{"points":"' . MAPMAP_POINTS . '","geometries":"a&b"}',
    'an option that is not text' => '{"points":"' . MAPMAP_POINTS . '","steps":true}',
    'an option ending in a new line' => '{"points":"' . MAPMAP_POINTS . '","overview":"full\\n"}',
    'not JSON' => 'points=' . MAPMAP_POINTS,
];
foreach ($badRouteBodies as $what => $bad) {
    check(refused($call('POST', '/v1/mapmap/route', $auth, $bad), 400, 'bad-request'), 'a mapmap way is refused with ' . $what);
}
check(refused($call('GET', '/v1/mapmap/route/' . MAPMAP_POINTS . '?overview=full', $auth), 404, 'unknown'), 'no point is taken in a URL: the old GET path is unknown');
$asked = [];
check($call('POST', '/v1/mapmap/matrix', $auth, MAPMAP_MATRIX_BODY)[0] === 200 && $asked[0]['method'] === 'POST' && $asked[0]['url'] === 'https://api.mapmap.ai/matrix', 'mapmap travel times are asked');
check($asked[0]['body'] === MAPMAP_MATRIX_BODY && in_array('Authorization: Bearer snk_test', $asked[0]['headers'], true), 'with the body as the app sent it and the server mapmap key');
$mapmapPlaces = fn (int $n) => array_fill(0, $n, ['lat' => 59.4, 'lon' => 13.5]);
$badMatrices = [
    'a name' => '{"sources":[{"lat":59.4,"lon":13.5,"name":"Anna Testsson"}],"targets":[{"lat":59.4,"lon":13.5}]}',
    'an address' => '{"sources":[{"address":"Storgatan 14"}],"targets":[{"lat":59.4,"lon":13.5}]}',
    'another costing' => '{"sources":[{"lat":59.4,"lon":13.5}],"targets":[{"lat":59.4,"lon":13.5}],"costing":"pedestrian"}',
    'more than 100 answers' => json_encode(['sources' => $mapmapPlaces(11), 'targets' => $mapmapPlaces(10)]),
    'a point as text' => '{"sources":[{"lat":"59.4","lon":13.5}],"targets":[{"lat":59.4,"lon":13.5}]}',
    'another field' => '{"sources":[{"lat":59.4,"lon":13.5}],"targets":[{"lat":59.4,"lon":13.5}],"units":"miles"}',
];
foreach ($badMatrices as $what => $bad) {
    check(refused($call('POST', '/v1/mapmap/matrix', $auth, $bad), 400, 'bad-request'), 'mapmap travel times are refused with ' . $what);
}

echo "Answers passed on\n";
foreach ([401, 403, 429, 500] as $upstream) {
    $reply = [$upstream, '{"error":{"code":' . $upstream . '}}'];
    $google = $route($auth);
    $mapmap = $call('POST', '/v1/mapmap/route', $auth, MAPMAP_ROUTE_BODY);
    check($google[0] === $upstream && $google[2] === [] && $mapmap[0] === $upstream && $mapmap[2] === [], "an answer $upstream from Google or mapmap comes back without the server's error header");
}
$reply = Api::json(502, ['error' => 'no-answer'], 'no-answer');
check(refused($route($auth), 502, 'no-answer'), "no answer from Google is the server's own: 502 no-answer");
$reply = [200, '{"routes":[]}'];

echo "Only tablets\n";
$phone = ['authorization' => 'Bearer ' . $enroll('phone-40274', 'phone')];
$asked = [];
check(refused($call('GET', '/v1/config', $phone), 403, 'role'), 'a phone gets no map key');
check(refused($route($phone), 403, 'role') && refused($matrix($phone), 403, 'role'), 'nor a Google way or travel times');
check(refused($call('POST', '/v1/mapmap/route', $phone, MAPMAP_ROUTE_BODY), 403, 'role') && refused($call('POST', '/v1/mapmap/matrix', $phone, MAPMAP_MATRIX_BODY), 403, 'role'), 'nor a mapmap way or travel times');
check($asked === [] && $today('phone-40274') === [], 'nothing of it is asked or counted');

echo "No key on the server\n";
$noGoogle = $apiWith(['google_maps_js_key' => 'JS-KEY', 'mapmap_key' => 'snk_test', 'daily_limits' => $roomy]);
$noKeys = $apiWith(['google_maps_js_key' => 'JS-KEY', 'daily_limits' => $roomy]);
$before = $today('tablet-40274');
$asked = [];
check(refused($route($auth, ROUTE_BODY, APP_FIELDS, $noGoogle), 503, 'no-key') && refused($matrix($auth, MATRIX_BODY, APP_MATRIX_FIELDS, $noGoogle), 503, 'no-key'), 'no Google key: 503 no-key');
check(refused($call('POST', '/v1/mapmap/route', $auth, MAPMAP_ROUTE_BODY, $noKeys), 503, 'no-key') && refused($call('POST', '/v1/mapmap/matrix', $auth, MAPMAP_MATRIX_BODY, $noKeys), 503, 'no-key'), 'no mapmap key: 503 no-key');
check($asked === [] && $today('tablet-40274') === $before, 'nothing of it is asked or counted');

echo "Limits\n";
$tight = $apiWith($keys + ['daily_limits' => ['google_routes' => 2, 'google_matrix' => 1, 'mapmap_route' => 1, 'mapmap_matrix' => 1, 'config' => 1]]);
$limited = ['authorization' => 'Bearer ' . $enroll('tablet-50000', 'tablet')];
check($route($limited, ROUTE_BODY, APP_FIELDS, $tight)[0] === 200 && $route($limited, ROUTE_BODY, APP_FIELDS, $tight)[0] === 200, 'two ways of the day');
$asked = [];
$third = $route($limited, ROUTE_BODY, APP_FIELDS, $tight);
check(refused($third, 429, 'daily-limit'), 'the daily limit stops the third: 429 daily-limit');
$retry = $third[2]['Retry-After'] ?? '';
check((bool) preg_match('/^[0-9]+$/', $retry) && (int) $retry > 0 && (int) $retry <= 86400, 'with Retry-After: the seconds to the next UTC midnight');
$route($limited, ROUTE_BODY, APP_FIELDS, $tight);
check($asked === [] && $today('tablet-50000')['google_routes'] === 2, 'nothing is asked, and the count never passes the limit');
check($matrix($limited, MATRIX_BODY, APP_MATRIX_FIELDS, $tight)[0] === 200, "each service has its own limit: the ways' limit does not stop the travel times");
$fresh = ['authorization' => 'Bearer ' . $enroll('tablet-60000', 'tablet')];
check(refused($matrix($limited, MATRIX_BODY, APP_MATRIX_FIELDS, $tight), 429, 'daily-limit') && $matrix($fresh, MATRIX_BODY, APP_MATRIX_FIELDS, $tight)[0] === 200, 'and each device its own');
check(Api::secondsToUtcMidnight(5 * 86400 - 30) === 30 && Api::secondsToUtcMidnight(5 * 86400) === 86400, 'the next UTC midnight: 23:59:30 waits 30 s, 00:00 a whole day');
check(refused($call('POST', '/v1/mapmap/matrix', $auth, str_repeat('x', 70000)), 413, 'too-large'), 'a body too large is refused');
check(refused($call('POST', '/v1/enroll', [], str_repeat('x', 70000)), 413, 'too-large'), 'also when enrolling');

echo "Two requests at once\n";
$racing = new RacingPdo('sqlite::memory:');
$raced = new Store($racing);
$rows = fn (string $service) => (int) $racing->query("SELECT requests FROM usage_counts WHERE service = '$service'")->fetchColumn();
$racing->other = ['google_routes', 1];
check($raced->count(1, 'google_routes', 5) && $rows('google_routes') === 2, 'the first request of the day still counts when another made its row: no error');
$devices = fn () => (int) $racing->query('SELECT COUNT(*) FROM devices')->fetchColumn();
$shared = $raced->newCode('tablet-70000');
$racing->codeTaken = true;
check($raced->enroll($shared, 'tablet') === null && $devices() === 0, 'a code another request took after this one read it enrolls nothing');
check($raced->enroll($raced->newCode('tablet-70000'), 'tablet') !== null && $devices() === 1, 'and the store goes on: a new code enrolls');
$racing->other = ['google_matrix', 1];
check(!$raced->count(1, 'google_matrix', 1) && $rows('google_matrix') === 1, 'and is refused when that other one reached the limit');
check(!$raced->count(1, 'google_matrix', 1) && $rows('google_matrix') === 1, 'a row already at the limit stays there');

echo "Stopping a device\n";
$id = (int) array_values(array_filter($store->devices(), fn ($d) => $d['label'] === 'tablet-40274'))[0]['id'];
$before = $today('tablet-40274');
$store->setActive($id, false);
check(refused($call('GET', '/v1/config', $auth), 403, 'stopped') && refused($route($auth), 403, 'stopped'), 'a stopped device is refused: 403 stopped');
check($today('tablet-40274') === $before, 'and that is not counted');
$store->setActive($id, true);
check($call('GET', '/v1/config', $auth)[0] === 200, 'and works again when started');
check(count($today('tablet-40274')) >= 4, 'its requests are counted per service');
$before = $today('tablet-40274');
[$status, $body, $headers] = $call('POST', '/v1/leave', $auth);
check($status === 200 && $body === '{}' && $headers === [], 'a device leaves');
check(refused($call('GET', '/v1/config', $auth), 403, 'stopped'), 'and is stopped from then on');
check($call('POST', '/v1/leave', $auth)[0] === 200, 'leaving again changes nothing');
check($call('POST', '/v1/leave', $phone)[0] === 200, 'a device of any role may leave');
check(refused($call('POST', '/v1/leave'), 401, 'not-enrolled'), 'leaving needs a token');
check($today('tablet-40274') === $before, 'leaving is not counted');
$store->setActive($id, true);

echo "The error header\n";
check(Api::json(200, ['a' => 1])[2] === [] && Api::json(400, ['error' => 'x'], "evil\r\nSet-Cookie: a=b")[2] === ['X-Nasta-Error' => 'server'], 'only a fixed word goes in the header, never input');
check(count(Api::ERRORS) === count(array_filter(Api::ERRORS, fn ($s) => (bool) preg_match('/^[a-z-]{1,16}$/', $s))), 'every error word is plain ASCII');

echo "Nothing kept\n";
$db = (new ReflectionProperty(Store::class, 'db'))->getValue($store);
$dump = '';
foreach (['devices', 'codes', 'usage_counts'] as $table) {
    $dump .= json_encode($db->query("SELECT * FROM $table")->fetchAll(), JSON_UNESCAPED_UNICODE);
}
foreach (['59.38', '13.50', '59.59', 'Storgatan', 'Karlstad', 'Anna', $token, $code] as $carried) {
    check(!str_contains($dump, $carried), 'nothing a request carried is stored: ' . (strlen($carried) > 12 ? 'the token' : $carried));
}

echo $failed === 0 ? "All passed\n" : "$failed failed\n";
exit($failed === 0 ? 0 : 1);
