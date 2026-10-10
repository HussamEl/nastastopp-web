<?php
declare(strict_types=1);

namespace Nasta;

/**
 * api.nastastopp.se: an enrolled tablet asks for its map key, its way and its travel times here,
 * and the server asks Google or mapmap.ai with the company's own keys, so no key but the Maps
 * JavaScript key ever reaches a device. Only tablets get the map key and the ways.
 *
 * What is passed on is checked first and is only what the app sends:
 * - to Google, a field mask that is exactly one of the app's two, and a body of only the fields
 *   the app sends, which stays in the basic tier (driving, no traffic, at most ten stops between,
 *   a small matrix); its places are points, or the address of a stop that has no point (passed
 *   on, never kept), never a name;
 * - to mapmap, points only, and the route's own options. A device sends them in a body, never in
 *   the URL it asks, so no position reaches the host's access log; the server puts them in
 *   mapmap's own route URL.
 * Nothing a request carries is kept or logged; each device has a daily limit per service, and a
 * request counts only once every check has passed.
 *
 * The server's own refusals carry the header X-Nasta-Error (one of [ERRORS]), so the app tells
 * them from Google's or mapmap's answers, which are passed on as they came, without it.
 */
final class Api
{
    /** The largest body taken (a way of a few stops, a matrix of a few points). */
    private const MAX_BODY = 65536;

    public const ERROR_HEADER = 'X-Nasta-Error';

    /** The server's own refusals, as the header names them: fixed words, never a request's input. */
    public const ERRORS = [
        'not-enrolled', // 401: no token, or one no device holds
        'stopped',      // 403: the owner stopped this device, or it left
        'role',         // 403: only a tablet may ask for the map key and the ways
        'too-large',    // 413
        'field-mask',   // 400: not exactly the app's field mask
        'bad-request',  // 400: a body or a code the server does not take
        'daily-limit',  // 429: this device's limit for this service today, with Retry-After
        'no-key',       // 503: the server has no key for this service
        'no-answer',    // 502: Google or mapmap did not answer
        'unknown',      // 404: a path the API does not have, or an unknown or expired code (403)
        'server',       // 500
    ];

    /**
     * The field masks the app asks Google with (RoutesApi.FIELDS and RoutesApi.MATRIX_FIELDS in
     * HussamEl/S20Ultra), byte for byte: any other is refused, so a request cannot ask for what
     * Google bills higher. A new field in the app needs this list changed and deployed first.
     */
    public const ROUTE_FIELDS = 'routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline,'
        . 'routes.legs.duration,routes.legs.distanceMeters,routes.legs.polyline.encodedPolyline';
    public const MATRIX_FIELDS = 'originIndex,destinationIndex,duration,condition';

    /** Stops between the start and the end of a Google way (its basic tier allows ten). */
    private const MAX_INTERMEDIATES = 10;

    /** Answers in one matrix (the app asks at most 8 × 7). */
    private const MAX_ELEMENTS = 100;

    /** Points on a mapmap way: the vehicle and up to eleven stops. */
    private const MIN_POINTS = 2;
    private const MAX_POINTS = 12;

    /** A mapmap route's own options, in the order they are passed on. */
    private const MAPMAP_OPTIONS = ['overview', 'geometries', 'steps'];

    /**
     * The fields of the app's Google requests (RoutesApi.body and matrixBody). Any other is refused,
     * among them all that leave the basic tier or ask for more than a way: routingPreference,
     * extraComputations, computeAlternativeRoutes, requestedReferenceRoutes, optimizeWaypointOrder.
     */
    private const ROUTE_KEYS = ['origin', 'destination', 'intermediates', 'travelMode', 'languageCode', 'units'];
    private const MATRIX_KEYS = ['origins', 'destinations', 'travelMode'];

    /** The longest address passed on for a stop without a point. */
    private const MAX_ADDRESS = 300;

    /**
     * @param callable(string $method, string $url, array $headers, ?string $body): array $forward
     *   asks Google or mapmap: [status, body] of the answer, or the server's own [status, body,
     *   headers] when none came
     */
    public function __construct(private Config $config, private Store $store, private $forward) {}

    /**
     * Answers one request: [status, JSON body, headers to send]. [headers] are the request's,
     * lower-cased.
     *
     * @param array<string, string> $headers
     * @return array{int, string, array<string, string>}
     */
    public function handle(string $method, string $path, array $headers, string $body): array
    {
        $path = '/' . trim($path, '/');
        // A path the API does not have is "unknown" for everyone, before any token is looked at.
        if (!self::isRoute($method, $path)) {
            return self::fail(404, 'unknown');
        }
        if (strlen($body) > self::MAX_BODY) {
            return self::fail(413, 'too-large');
        }
        if ($path === '/v1/enroll') {
            return $this->enroll($body);
        }

        $token = preg_match('/^Bearer\s+([0-9a-f]{64})$/iD', trim($headers['authorization'] ?? ''), $m) ? strtolower($m[1]) : null;
        $device = $token === null ? null : $this->store->device($token);
        if ($device === null) {
            return self::fail(401, 'not-enrolled');
        }
        // A device that leaves is stopped; leaving again changes nothing.
        if ($path === '/v1/leave') {
            $this->store->setActive((int) $device['id'], false);
            return self::json(200, []);
        }
        if ((int) $device['active'] !== 1) {
            return self::fail(403, 'stopped');
        }
        if ($device['role'] !== 'tablet') {
            return self::fail(403, 'role');
        }

        return match ($path) {
            '/v1/config' => $this->mapConfig($device),
            '/v1/google/routes' => $this->google($device, 'google_routes', 'https://routes.googleapis.com/directions/v2:computeRoutes', self::ROUTE_FIELDS, $headers, $body),
            '/v1/google/matrix' => $this->google($device, 'google_matrix', 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', self::MATRIX_FIELDS, $headers, $body),
            '/v1/mapmap/route' => $this->mapmapRoute($device, $body),
            '/v1/mapmap/matrix' => $this->mapmapMatrix($device, $body),
            default => self::fail(404, 'unknown'),
        };
    }

    /** The requests the API answers: enrolling and leaving, the map key, and the ways. */
    private static function isRoute(string $method, string $path): bool
    {
        return match (true) {
            $method === 'GET' && $path === '/v1/config',
            $method === 'POST' && in_array($path, ['/v1/enroll', '/v1/leave', '/v1/google/routes', '/v1/google/matrix', '/v1/mapmap/route', '/v1/mapmap/matrix'], true) => true,
            default => false,
        };
    }

    private function enroll(string $body): array
    {
        $request = json_decode($body, true);
        $code = is_array($request) && is_string($request['code'] ?? null) ? $request['code'] : '';
        $role = is_array($request) && in_array($request['role'] ?? null, ['phone', 'tablet'], true) ? $request['role'] : null;
        if ($code === '' || $role === null) {
            return self::fail(400, 'bad-request');
        }
        $enrolled = $this->store->enroll($code, $role);
        return $enrolled === null ? self::fail(403, 'unknown') : self::json(200, $enrolled);
    }

    /** The tablet's map key and which ways the server can ask, never the server's own keys. */
    private function mapConfig(array $device): array
    {
        $key = $this->config->string('google_maps_js_key');
        if ($key === '') {
            return self::fail(503, 'no-key');
        }
        return $this->counted($device, 'config', fn () => self::json(200, [
            'mapsJsKey' => $key,
            'label' => (string) $device['label'],
            'ways' => [
                'google' => $this->config->string('google_routes_key') !== '',
                'mapmap' => $this->config->string('mapmap_key') !== '',
            ],
        ]));
    }

    /** @param array<string, string> $headers */
    private function google(array $device, string $service, string $url, string $fields, array $headers, string $body): array
    {
        if (($headers['x-goog-fieldmask'] ?? '') !== $fields) {
            return self::fail(400, 'field-mask');
        }
        $checked = self::googleBody($body, $service === 'google_matrix');
        if ($checked === null) {
            return self::fail(400, 'bad-request');
        }
        $key = $this->config->string('google_routes_key');
        if ($key === '') {
            return self::fail(503, 'no-key');
        }
        return $this->counted($device, $service, fn () => $this->forwarded('POST', $url, ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $fields], $checked));
    }

    /**
     * The Google body as it is passed on (the same JSON written again, so what is sent is exactly
     * what was checked), or null when it is not a request the app makes: only [ROUTE_KEYS] or
     * [MATRIX_KEYS], driving, each place a point or an address, at most [MAX_INTERMEDIATES] stops
     * between, at most [MAX_ELEMENTS] answers in a matrix.
     */
    private static function googleBody(string $body, bool $matrix): ?string
    {
        $request = json_decode($body, true, 16);
        if (!is_array($request) || self::isListArray($request) || array_diff(array_keys($request), $matrix ? self::MATRIX_KEYS : self::ROUTE_KEYS) !== []) {
            return null;
        }
        if (($request['travelMode'] ?? 'DRIVE') !== 'DRIVE') {
            return null;
        }
        foreach (['languageCode', 'units'] as $word) {
            if (array_key_exists($word, $request) && !(is_string($request[$word]) && preg_match('/^[A-Za-z_-]{1,12}$/D', $request[$word]))) {
                return null;
            }
        }
        if ($matrix) {
            $origins = $request['origins'] ?? null;
            $destinations = $request['destinations'] ?? null;
            if (!self::isList($origins) || !self::isList($destinations) || count($origins) * count($destinations) > self::MAX_ELEMENTS) {
                return null;
            }
            foreach ([...$origins, ...$destinations] as $entry) {
                if (!is_array($entry) || !self::hasKeys($entry, ['waypoint']) || !self::isPlace($entry['waypoint'])) {
                    return null;
                }
            }
        } else {
            $intermediates = $request['intermediates'] ?? [];
            if (!is_array($intermediates) || !self::isListArray($intermediates) || count($intermediates) > self::MAX_INTERMEDIATES) {
                return null;
            }
            foreach ([$request['origin'] ?? null, $request['destination'] ?? null, ...$intermediates] as $place) {
                if (!self::isPlace($place)) {
                    return null;
                }
            }
        }
        $again = json_encode(json_decode($body, false, 16), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return is_string($again) ? $again : null;
    }

    /** A non-empty JSON list. */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && $value !== [] && self::isListArray($value);
    }

    /** Whether [value]'s keys are 0, 1, 2… in order (PHP 8.1's array_is_list, written for any PHP 8). */
    private static function isListArray(array $value): bool
    {
        return array_values($value) === $value;
    }

    /** Whether [value]'s fields are exactly [keys], in any order. */
    private static function hasKeys(array $value, array $keys): bool
    {
        $have = array_map('strval', array_keys($value));
        sort($have);
        sort($keys);
        return $have === $keys;
    }

    /**
     * A Google waypoint as the app writes it: {location: {latLng: {latitude, longitude}}}, or
     * {address} for a stop without a point. Nothing else (no heading, stopover, side or via).
     */
    private static function isPlace(mixed $place): bool
    {
        if (!is_array($place)) {
            return false;
        }
        if (self::hasKeys($place, ['address'])) {
            return is_string($place['address']) && $place['address'] !== '' && mb_strlen($place['address']) <= self::MAX_ADDRESS;
        }
        if (!self::hasKeys($place, ['location']) || !is_array($place['location']) || !self::hasKeys($place['location'], ['latLng'])) {
            return false;
        }
        $point = $place['location']['latLng'];
        return is_array($point) && self::hasKeys($point, ['latitude', 'longitude'])
            && self::isCoordinate($point['latitude'], 90) && self::isCoordinate($point['longitude'], 180);
    }

    /**
     * A mapmap way: {points, overview, geometries, steps}. The points ("lng,lat;lng,lat…") are
     * checked pair by pair and go into mapmap's URL from here; the device's request never carries
     * them in its own.
     */
    private function mapmapRoute(array $device, string $body): array
    {
        $request = json_decode($body, true, 4);
        if (!is_array($request) || self::isListArray($request) || array_diff(array_keys($request), ['points', ...self::MAPMAP_OPTIONS]) !== []) {
            return self::fail(400, 'bad-request');
        }
        $points = $request['points'] ?? null;
        if (!is_string($points) || !self::isPoints($points)) {
            return self::fail(400, 'bad-request');
        }
        $kept = [];
        foreach (self::MAPMAP_OPTIONS as $option) {
            if (!array_key_exists($option, $request)) {
                continue;
            }
            $value = $request[$option];
            if (!is_string($value) || !preg_match('/^[a-z0-9]{1,12}$/D', $value)) {
                return self::fail(400, 'bad-request');
            }
            $kept[$option] = $value;
        }
        if ($this->config->string('mapmap_key') === '') {
            return self::fail(503, 'no-key');
        }
        $url = 'https://api.mapmap.ai/route/v1/driving/' . $points . ($kept ? '?' . http_build_query($kept) : '');
        return $this->counted($device, 'mapmap_route', fn () => $this->mapmap('GET', $url, null));
    }

    /**
     * "lng,lat;lng,lat…": [MIN_POINTS] to [MAX_POINTS] pairs, each a longitude and a latitude with
     * at most six decimals. Each pattern here ends with D, so its $ is the very end: a final
     * newline never passes into mapmap's URL.
     */
    private static function isPoints(string $points): bool
    {
        if (!preg_match('/^[-0-9.,;]{1,400}$/D', $points)) {
            return false;
        }
        $pairs = explode(';', $points);
        if (count($pairs) < self::MIN_POINTS || count($pairs) > self::MAX_POINTS) {
            return false;
        }
        foreach ($pairs as $pair) {
            $parts = explode(',', $pair);
            if (count($parts) !== 2 || !self::isDegrees($parts[0], 180) || !self::isDegrees($parts[1], 90)) {
                return false;
            }
        }
        return true;
    }

    private static function isDegrees(string $value, int $most): bool
    {
        return (bool) preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,6})?$/D', $value) && abs((float) $value) <= $most;
    }

    /**
     * mapmap's matrix: {sources, targets, costing}, each place only {lat, lon}, driving only, at
     * most [MAX_ELEMENTS] answers. Passed on written again, so only what was checked is sent.
     */
    private function mapmapMatrix(array $device, string $body): array
    {
        $request = json_decode($body, true, 8);
        if (!is_array($request) || self::isListArray($request) || array_diff(array_keys($request), ['sources', 'targets', 'costing']) !== []) {
            return self::fail(400, 'bad-request');
        }
        $sources = $request['sources'] ?? null;
        $targets = $request['targets'] ?? null;
        if (!self::isList($sources) || !self::isList($targets) || count($sources) * count($targets) > self::MAX_ELEMENTS
            || ($request['costing'] ?? 'auto') !== 'auto') {
            return self::fail(400, 'bad-request');
        }
        foreach ([...$sources, ...$targets] as $place) {
            if (!is_array($place) || !self::hasKeys($place, ['lat', 'lon'])
                || !self::isCoordinate($place['lat'], 90) || !self::isCoordinate($place['lon'], 180)) {
                return self::fail(400, 'bad-request');
            }
        }
        if ($this->config->string('mapmap_key') === '') {
            return self::fail(503, 'no-key');
        }
        $again = json_encode(['sources' => $sources, 'targets' => $targets, 'costing' => 'auto'], JSON_PRESERVE_ZERO_FRACTION);
        return $this->counted($device, 'mapmap_matrix', fn () => $this->mapmap('POST', 'https://api.mapmap.ai/matrix', $again));
    }

    private static function isCoordinate(mixed $value, int $most): bool
    {
        return (is_float($value) || is_int($value)) && abs($value) <= $most;
    }

    /** Asks the service once this device's request is counted, or answers its daily limit. */
    private function counted(array $device, string $service, callable $answer): array
    {
        if (!$this->store->count((int) $device['id'], $service, $this->config->dailyLimit($service))) {
            return self::fail(429, 'daily-limit', ['Retry-After' => (string) self::secondsToUtcMidnight()]);
        }
        return $answer();
    }

    /** Seconds until the next day begins (UTC), when the daily counts start again: 1 to 86400. */
    public static function secondsToUtcMidnight(?int $now = null): int
    {
        return 86400 - (($now ?? time()) % 86400);
    }

    private function mapmap(string $method, string $url, ?string $body): array
    {
        return $this->forwarded($method, $url, ['Content-Type: application/json', 'Authorization: Bearer ' . $this->config->string('mapmap_key')], $body);
    }

    /**
     * Google's or mapmap's answer as it came, without the server's error header, so the app never
     * takes their 401 or 429 for the server's own; only the server's own "no answer" carries it.
     */
    private function forwarded(string $method, string $url, array $headers, ?string $body): array
    {
        $answer = ($this->forward)($method, $url, $headers, $body);
        return [(int) $answer[0], (string) $answer[1], $answer[2] ?? []];
    }

    /**
     * An answer: [status, JSON body, headers]. A status of 400 or more is the server's own refusal
     * and carries [ERROR_HEADER] with [slug] (one of [ERRORS], else "server"); [extra] are more
     * headers, set by the server only.
     *
     * @param array<string, string> $extra
     * @return array{int, string, array<string, string>}
     */
    public static function json(int $status, array $value, ?string $slug = null, array $extra = []): array
    {
        $headers = $extra;
        if ($status >= 400) {
            $headers[self::ERROR_HEADER] = in_array($slug, self::ERRORS, true) ? $slug : 'server';
        }
        $text = $value === [] ? '{}' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [$status, $text, $headers];
    }

    /** The server's own refusal: its [slug] in the body and in [ERROR_HEADER]. */
    private static function fail(int $status, string $slug, array $extra = []): array
    {
        return self::json($status, ['error' => $slug], $slug, $extra);
    }

    /**
     * Asks [url] with curl: TLS verified, HTTPS only, no redirect followed, 15 s at most; the
     * answer's status and body, or the server's own 502.
     *
     * @return array{int, string}|array{int, string, array<string, string>}
     */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($answer) || $status === 0) {
            return self::fail(502, 'no-answer');
        }
        return [$status, $answer];
    }
}
