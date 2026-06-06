<?php
/**
 * serverFetch — Server-side equivalent of QS.fetch (beta.8 A2).
 *
 * Resolves an endpoint reference against the project's API registry
 * (the same `api-endpoints.json` that drives the client-side QS.fetch),
 * substitutes path placeholders + body / query inputs, attaches auth
 * for server-callable endpoints (apiKey / bearer-with-session / cookie),
 * fires curl, and returns a normalised response shape.
 *
 * Used by:
 *   - secure/src/classes/DataResolver.php — per-route resolvers that
 *     populate template variables before render (the headline A2 use).
 *   - Future: OAuth callback handlers (beta.9), webhook responders,
 *     any server-side code that needs to call a registered API.
 *
 *
 * Refusing client-only endpoints:
 *
 *   Endpoints carry a `callableFrom` marker (beta.8 Track A4 — see
 *   ApiEndpointManager::effectiveCallableFrom). Endpoints whose effective
 *   value is `client` cannot be invoked here — serverFetch returns
 *   ok=false with a clear error. This is the server-side mirror of the
 *   client-side guard in QS.fetch + the qs-api-config.js filtering that
 *   keeps server-only endpoints out of the public bundle. Defence in
 *   depth: even if a malformed route config tries to call a client-only
 *   endpoint from the server, it fails loudly instead of silently
 *   leaking auth context.
 *
 *
 * Auth handling:
 *
 *   - `none`   → no auth header.
 *   - `apiKey` → read the key from secure/admin/config/api-secrets.php
 *                (gitignored — copy from .example to set up). The key
 *                is looked up by the endpoint's apiId. The endpoint's
 *                tokenSource configures the header name
 *                (default 'header:X-API-Key').
 *   - `bearer` → uses the user's session token from $context['session']
 *                ['token']. Without that, the call goes auth-less and
 *                will likely 401 — Tier 3 server-side session wiring
 *                (beta.8 A3 → A2 integration slice) populates this.
 *   - `cookie` → passes through the user's Cookie header (from
 *                $context['cookieHeader'] or $_SERVER['HTTP_COOKIE']).
 *                The auth API and the user's site must share an origin
 *                or have explicit cross-origin cookie config.
 *
 *
 * Inputs handling:
 *
 *   $inputs is a flat map of name → value. Inputs whose name matches a
 *   `:placeholder` segment in the endpoint's path get substituted into
 *   the URL (URL-encoded). Remaining inputs go to the request body for
 *   POST/PUT/PATCH (as JSON) or to the query string for GET/HEAD.
 *
 *   The DataResolver is responsible for source resolution
 *   ('param:slug' → routeParams['slug']); serverFetch just consumes the
 *   already-resolved flat values.
 *
 *
 * Return shape:
 *
 *   [
 *       'ok'       => bool,    // 2xx status
 *       'status'   => int,     // HTTP status (0 on transport failure)
 *       'data'     => mixed,   // decoded JSON, or raw string when not JSON
 *       'error'    => string,  // present only on ok=false
 *   ]
 *
 *
 * @param string $endpointRef Endpoint reference, '@apiId/endpointId' or
 *                            'apiId/endpointId' or just 'endpointId'
 *                            (searches all APIs in that case).
 * @param array  $inputs      Map of input name → already-resolved value.
 * @param array  $context     Optional execution context:
 *                              'session'      => ['token' => string, ...]
 *                                                bearer token for the user
 *                              'cookieHeader' => string  raw Cookie header
 *                              'apiSecrets'   => array  inject for testing
 *                                                (default: require the
 *                                                gitignored file).
 * @return array              Normalised response (see above).
 */

require_once __DIR__ . '/../classes/ApiEndpointManager.php';

function serverFetch(string $endpointRef, array $inputs = [], array $context = []): array {
    // Strip leading '@' if present (registry-mode convention from QS.fetch).
    $endpointRef = ltrim($endpointRef, '@');

    // Parse 'apiId/endpointId' vs bare 'endpointId'.
    $apiId = null;
    $endpointId = $endpointRef;
    if (strpos($endpointRef, '/') !== false) {
        [$apiId, $endpointId] = explode('/', $endpointRef, 2);
    }

    // Resolve the endpoint via the project's registry.
    $manager = new ApiEndpointManager();
    $endpoint = $manager->getEndpoint($endpointId, $apiId);
    if ($endpoint === null) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "Endpoint not found in registry: @{$endpointRef}",
        ];
    }

    // callableFrom enforcement. Need the merged API config for
    // effectiveCallableFrom; getEndpoint gives us apiAuth + apiId but not
    // the full $api array, so re-fetch via getApi.
    $api = $manager->getApi($endpoint['apiId']);
    if ($api === null) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "API not found for resolved endpoint: {$endpoint['apiId']}",
        ];
    }
    $callableFrom = ApiEndpointManager::effectiveCallableFrom($api, $endpoint);
    if ($callableFrom === 'client') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "Endpoint @{$endpointRef} is marked callableFrom='client' and cannot be invoked server-side. Either change its callableFrom to 'server' / 'both' in /admin/apis, or call it from the browser via QS.fetch.",
        ];
    }

    // Identify which inputs map to path :placeholders so they don't
    // also end up in the body / query string.
    $rawPath = $endpoint['path'] ?? '';
    $pathPlaceholders = [];
    if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $rawPath, $matches)) {
        $pathPlaceholders = $matches[1];
    }

    // Substitute path placeholders. Required-but-missing placeholders
    // get left as the literal ':name' so a 404 from the upstream surfaces
    // the misconfig visibly (mirrors QS.fetch's "leave literal if optional"
    // policy; we don't distinguish required vs optional here — that's a
    // resolver-level config concern handled upstream).
    $path = $rawPath;
    foreach ($pathPlaceholders as $name) {
        if (array_key_exists($name, $inputs)) {
            $path = str_replace(':' . $name, rawurlencode((string) $inputs[$name]), $path);
        }
    }
    $url = rtrim($endpoint['baseUrl'] ?? '', '/') . $path;

    // Method.
    $method = strtoupper($endpoint['method'] ?? 'GET');
    $isBodyless = in_array($method, ['GET', 'HEAD'], true);

    // Split the non-path inputs into either body (POST/PUT/PATCH) or
    // query string (GET/HEAD).
    $nonPathInputs = [];
    foreach ($inputs as $name => $value) {
        if (!in_array($name, $pathPlaceholders, true)) {
            $nonPathInputs[$name] = $value;
        }
    }

    $body = null;
    if ($isBodyless) {
        if (!empty($nonPathInputs)) {
            $queryParts = [];
            foreach ($nonPathInputs as $name => $value) {
                if ($value === null || $value === '') continue;
                $queryParts[] = rawurlencode($name) . '=' . rawurlencode((string) $value);
            }
            if (!empty($queryParts)) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . implode('&', $queryParts);
            }
        }
    } else {
        if (!empty($nonPathInputs)) {
            $body = json_encode($nonPathInputs);
        }
    }

    // Build headers.
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    // Auth resolution. Endpoint-level auth overrides API-level when set
    // to anything other than 'inherit' (matching the convention in
    // ApiEndpointManager + /admin/apis).
    $auth = $endpoint['apiAuth'] ?? ['type' => 'none'];
    if (isset($endpoint['auth']) && $endpoint['auth'] !== 'inherit') {
        $auth = is_array($endpoint['auth'])
            ? $endpoint['auth']
            : ['type' => (string) $endpoint['auth']];
    }
    $authType = $auth['type'] ?? 'none';

    if ($authType === 'apiKey') {
        // Pull the secret from secure/admin/config/api-secrets.php. The
        // file is gitignored — see api-secrets.php.example for the format
        // + the security disclosure. $context['apiSecrets'] allows tests
        // to inject without touching disk.
        $apiSecrets = $context['apiSecrets'] ?? null;
        if ($apiSecrets === null) {
            $secretsPath = defined('SECURE_FOLDER_PATH')
                ? SECURE_FOLDER_PATH . '/admin/config/api-secrets.php'
                : __DIR__ . '/../../admin/config/api-secrets.php';
            $apiSecrets = file_exists($secretsPath) ? require $secretsPath : [];
        }
        $apiIdForSecret = $endpoint['apiId'];
        if (!isset($apiSecrets[$apiIdForSecret]) || $apiSecrets[$apiIdForSecret] === '') {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'error' => "apiKey not configured for API '{$apiIdForSecret}' in secure/admin/config/api-secrets.php (see .example template).",
            ];
        }
        $keyValue = $apiSecrets[$apiIdForSecret];
        $tokenSource = $auth['tokenSource'] ?? 'header:X-API-Key';
        if (strpos($tokenSource, 'header:') === 0) {
            $headerName = substr($tokenSource, 7);
            $headers[] = $headerName . ': ' . $keyValue;
        }
        // Future tokenSource variants (e.g. 'query:apikey') would slot in
        // here. Keeping the v1 surface narrow until a real need surfaces.
    } elseif ($authType === 'bearer') {
        // The user's session bearer token. Provided by the caller via
        // $context['session']['token'] — beta.8 Tier 3 server-side session
        // wiring populates this from the cookie/header on the incoming
        // request. Without it the call goes auth-less and will likely 401
        // — that's a clearer signal than silently calling unauthed.
        $token = $context['session']['token'] ?? null;
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
    } elseif ($authType === 'cookie') {
        // Pattern X (beta.7) — pass the user's Cookie header straight
        // through. Same as the client's `credentials: 'include'`. The
        // auth API and the QuickSite site need a shared origin (or
        // explicit cross-origin cookie config on both ends).
        $cookieHeader = $context['cookieHeader']
            ?? ($_SERVER['HTTP_COOKIE'] ?? null);
        if ($cookieHeader !== null && $cookieHeader !== '') {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }
    } elseif ($authType === 'basic') {
        // Basic auth from $context — same shape as bearer (token = the
        // already-base64-encoded user:pass blob). Rare server-side use
        // case; ship for completeness.
        $token = $context['session']['token'] ?? null;
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Basic ' . $token;
        }
    }
    // authType === 'none' or anything unrecognised → no auth header.

    // Endpoint-level extra headers (rare but supported).
    if (!empty($endpoint['headers']) && is_array($endpoint['headers'])) {
        foreach ($endpoint['headers'] as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
    }

    // TODO: telemetry hook — see POST_1_0_RESOLVER_OBSERVABILITY.md.
    // The right place for a per-call observe(start, endpoint, callableFrom)
    // before curl_exec + observe(end, status, durationMs, cacheHit) after.

    // Fire curl.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $curlError = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "curl failed for @{$endpointRef}: {$curlError}",
        ];
    }
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Decode response body. JSON when it parses; raw string otherwise
    // (the resolver's expose mapping reads dot-paths into the decoded
    // value, so non-JSON responses simply yield null exposes — the
    // template's `onMiss` fallback handles it).
    $decoded = json_decode($responseBody, true);
    $data = ($decoded !== null || json_last_error() === JSON_ERROR_NONE)
        ? $decoded
        : $responseBody;

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data' => $data,
    ];
}
