<?php
declare(strict_types=1);

namespace Nasta;

/**
 * api.nastastopp.se: an enrolled device asks for its way and travel times here, and the server
 * asks Google or mapmap.ai with the company's own keys, so no key is ever on a phone or a tablet.
 * Only what the request carries is passed on (points, never a name), nothing of it is kept or
 * logged, and each device has a daily limit per service.
 */
final class Api
{
    /** The largest body passed on (a way of a few stops, a matrix of a few points). */
    private const MAX_BODY = 65536;

    /** @param callable(string $method, string $url, array $headers, ?string $body): array{int, string} $forward */
    public function __construct(private Config $config, private Store $store, private $forward) {}

    /**
     * Answers one request: [status, JSON body]. [headers] are the request's, lower-cased.
     *
     * @param array<string, string> $headers
     * @return array{int, string}
     */
    public function handle(string $method, string $path, array $query, array $headers, string $body): array
    {
        $path = '/' . trim($path, '/');
        if ($method === 'POST' && $path === '/v1/enroll') {
            return $this->enroll($body);
        }
        if ($method === 'GET' && $path === '/v1/health') {
            return self::json(200, ['ok' => true]);
        }

        $token = preg_match('/^Bearer\s+([0-9a-f]{64})$/', $headers['authorization'] ?? '', $m) ? $m[1] : null;
        $device = $token === null ? null : $this->store->device($token);
        if ($device === null) {
            return self::json(401, ['error' => 'not enrolled']);
        }
        if (strlen($body) > self::MAX_BODY) {
            return self::json(413, ['error' => 'too large']);
        }

        if ($method === 'GET' && $path === '/v1/config') {
            return $this->counted($device, 'config', fn () => self::json(200, ['mapsJsKey' => $this->config->string('google_maps_js_key')]));
        }
        // The fields asked from Google, as the app names them (letters, dots, commas, stars): checked
        // before the request counts.
        $fields = $headers['x-goog-fieldmask'] ?? '';
        if (str_starts_with($path, '/v1/google/') && !preg_match('/^[A-Za-z0-9_.,*]{1,600}$/', $fields)) {
            return self::json(400, ['error' => 'field mask']);
        }
        if ($method === 'POST' && $path === '/v1/google/routes') {
            return $this->counted($device, 'google_routes', fn () => $this->google('https://routes.googleapis.com/directions/v2:computeRoutes', $fields, $body));
        }
        if ($method === 'POST' && $path === '/v1/google/matrix') {
            return $this->counted($device, 'google_matrix', fn () => $this->google('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', $fields, $body));
        }
        if ($method === 'GET' && preg_match('#^/v1/mapmap/route/([-0-9.,;]+)$#', $path, $m)) {
            return $this->counted($device, 'mapmap_route', fn () => $this->mapmapRoute($m[1], $query));
        }
        if ($method === 'POST' && $path === '/v1/mapmap/matrix') {
            return $this->counted($device, 'mapmap_matrix', fn () => $this->mapmap('POST', 'https://api.mapmap.ai/matrix', $body));
        }
        return self::json(404, ['error' => 'unknown']);
    }

    private function enroll(string $body): array
    {
        $request = json_decode($body, true);
        $code = is_array($request) && is_string($request['code'] ?? null) ? $request['code'] : '';
        $role = is_array($request) && in_array($request['role'] ?? null, ['phone', 'tablet'], true) ? $request['role'] : null;
        if ($code === '' || $role === null) {
            return self::json(400, ['error' => 'code and role']);
        }
        $token = $this->store->enroll($code, $role);
        return $token === null ? self::json(403, ['error' => 'unknown or expired code']) : self::json(200, ['token' => $token]);
    }

    private function counted(array $device, string $service, callable $answer): array
    {
        if (!$this->store->count((int) $device['id'], $service, $this->config->dailyLimit($service))) {
            return self::json(429, ['error' => 'daily limit']);
        }
        return $answer();
    }

    private function google(string $url, string $fields, string $body): array
    {
        $key = $this->config->string('google_routes_key');
        if ($key === '') {
            return self::json(503, ['error' => 'no Google key']);
        }
        return ($this->forward)('POST', $url, ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $fields], $body);
    }

    private function mapmapRoute(string $points, array $query): array
    {
        // Only the route's own options, by name.
        $kept = array_intersect_key($query, array_flip(['overview', 'geometries', 'steps']));
        $kept = array_filter($kept, fn ($v) => is_string($v) && preg_match('/^[a-z0-9]{1,12}$/', $v));
        $url = 'https://api.mapmap.ai/route/v1/driving/' . $points . ($kept ? '?' . http_build_query($kept) : '');
        return $this->mapmap('GET', $url, null);
    }

    private function mapmap(string $method, string $url, ?string $body): array
    {
        $key = $this->config->string('mapmap_key');
        if ($key === '') {
            return self::json(503, ['error' => 'no mapmap key']);
        }
        return ($this->forward)($method, $url, ['Content-Type: application/json', 'Authorization: Bearer ' . $key], $body);
    }

    /** @return array{int, string} */
    public static function json(int $status, array $value): array
    {
        return [$status, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    /**
     * Asks [url] with curl: TLS verified, 15 s at most; the answer's status and body, or 502.
     *
     * @return array{int, string}
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
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($answer) || $status === 0) {
            return self::json(502, ['error' => 'no answer']);
        }
        return [$status, $answer];
    }
}
