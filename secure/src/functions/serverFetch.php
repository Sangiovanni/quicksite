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

/**
 * Internal — build a curl handle + bookkeeping for serverFetch /
 * serverFetchMulti. Holds all the deterministic work (registry
 * lookup, callableFrom gate, URL/body/headers construction, auth
 * resolution, cache eligibility + read) so the caller does ONLY the
 * actual transport (curl_exec single vs curl_multi_exec parallel).
 *
 * Return shape:
 *   ['state' => 'error',    'result' => <serverFetch envelope, ok:false>]
 *   ['state' => 'hit',      'result' => <cached envelope, ok:true>,
 *                            'cacheStatus' => 'hit']
 *   ['state' => 'prepared', 'handle' => <curl handle>,
 *                            'endpointRef' => '<apiId/endpointId without leading @>',
 *                            'inputs' => <canonicalised inputs for cache key>,
 *                            'cacheable' => <bool>,
 *                            'cacheTTL' => <int>,
 *                            'cacheStatus' => 'miss'|'skip'|'disabled']
 *
 * Beta.8 A2 Slice 7.5.C — extracted from serverFetch's single-curl
 * implementation so serverFetchMulti can prepare N requests, hit the
 * cache for the ones with usable entries, and only fire curl_multi_*
 * for genuine misses.
 */
function _serverFetchPrepare(string $endpointRef, array $inputs, array $context): array {
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
        return ['state' => 'error', 'result' => [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "Endpoint not found in registry: @{$endpointRef}",
        ]];
    }

    // callableFrom enforcement. Need the merged API config for
    // effectiveCallableFrom; getEndpoint gives us apiAuth + apiId but not
    // the full $api array, so re-fetch via getApi.
    $api = $manager->getApi($endpoint['apiId']);
    if ($api === null) {
        return ['state' => 'error', 'result' => [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "API not found for resolved endpoint: {$endpoint['apiId']}",
        ]];
    }
    $callableFrom = ApiEndpointManager::effectiveCallableFrom($api, $endpoint);
    if ($callableFrom === 'client') {
        return ['state' => 'error', 'result' => [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => "Endpoint @{$endpointRef} is marked callableFrom='client' and cannot be invoked server-side. Either change its callableFrom to 'server' / 'both' in /admin/apis, or call it from the browser via QS.fetch.",
        ]];
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
            return ['state' => 'error', 'result' => [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'error' => "apiKey not configured for API '{$apiIdForSecret}' in secure/admin/config/api-secrets.php (see .example template).",
            ]];
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

    // Beta.8 A2 Slice 4 — cache read attempt (cacheStatus reporting
    // moved up to the caller in Slice 7.5.C so multi-resolver callers
    // get a per-resolver status array instead of a single $GLOBALS
    // value clobbered by whichever call ran last).
    //
    // Eligibility: cacheTTL>0 + auth type doesn't carry per-user identity.
    // The auth-cacheable rule (LOCKED): only `none` and `apiKey` —
    // `bearer`/`cookie`/`basic` would cross-leak user data across sessions.
    require_once __DIR__ . '/resolverCache.php';
    $cacheTTL = (int) ($context['cacheTTL'] ?? 0);
    $cacheable = false;
    $cacheStatus = 'skip';
    if ($cacheTTL <= 0) {
        $cacheStatus = 'skip';
    } elseif (!isResolverAuthCacheable($authType)) {
        $cacheStatus = 'disabled';
    } else {
        $cacheable = true;
        $cached = readResolverCache('@' . $endpointRef, $inputs);
        if ($cached !== null) {
            return [
                'state'       => 'hit',
                'result'      => $cached,
                'cacheStatus' => 'hit',
            ];
        }
        $cacheStatus = 'miss';
    }

    // Prepare the curl handle. CURLOPT_RETURNTRANSFER is on so we can
    // recover the body via curl_multi_getcontent (curl_multi path) or
    // the return of curl_exec (single path).
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    return [
        'state'       => 'prepared',
        'handle'      => $ch,
        'endpointRef' => $endpointRef,   // stripped of leading '@'
        'inputs'      => $inputs,        // for cache key on write
        'cacheable'   => $cacheable,
        'cacheTTL'    => $cacheTTL,
        'cacheStatus' => $cacheStatus,
    ];
}

/**
 * Decode a response body. Shared post-curl logic used by both single
 * (serverFetch) and multi (serverFetchMulti) paths.
 */
function _serverFetchParseResponse(string $responseBody, int $httpCode): array {
    // JSON when it parses; raw string otherwise (the resolver's expose
    // mapping reads dot-paths into the decoded value, so non-JSON
    // responses simply yield null exposes — the template's `onMiss`
    // fallback handles it).
    $decoded = json_decode($responseBody, true);
    $data = ($decoded !== null || json_last_error() === JSON_ERROR_NONE)
        ? $decoded
        : $responseBody;
    return [
        'ok'     => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data'   => $data,
    ];
}

/**
 * Single-endpoint synchronous fetch. Thin wrapper around the prepare /
 * curl_exec / parse trio; preserves the pre-7.5.C contract for callers
 * (signature unchanged, `$GLOBALS['__qs_resolver_cache_status']` still
 * set for back-compat with public/index.php's single-resolver path).
 */
function serverFetch(string $endpointRef, array $inputs = [], array $context = []): array {
    $prep = _serverFetchPrepare($endpointRef, $inputs, $context);

    if ($prep['state'] === 'error') {
        // Skip/disabled wasn't set; surface a neutral status for the header.
        $GLOBALS['__qs_resolver_cache_status'] = 'skip';
        return $prep['result'];
    }
    if ($prep['state'] === 'hit') {
        $GLOBALS['__qs_resolver_cache_status'] = 'hit';
        return $prep['result'];
    }

    // state === 'prepared'
    $GLOBALS['__qs_resolver_cache_status'] = $prep['cacheStatus'];
    $ch = $prep['handle'];

    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $curlError = curl_error($ch);
        curl_close($ch);
        return [
            'ok'     => false,
            'status' => 0,
            'data'   => null,
            'error'  => "curl failed for @{$prep['endpointRef']}: {$curlError}",
        ];
    }
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = _serverFetchParseResponse($responseBody, $httpCode);

    // Cache write on success only — failures aren't cached so re-trying
    // soon is the right behaviour (Slice 4 reasoning, unchanged).
    if ($prep['cacheable'] && $result['ok']) {
        writeResolverCache('@' . $prep['endpointRef'], $prep['inputs'], $result, $prep['cacheTTL']);
    }

    return $result;
}

/**
 * Parallel multi-endpoint fetch (beta.8 A2 Slice 7.5.C).
 *
 * Accepts an array of fetch specs and fires them concurrently via
 * curl_multi_*. Each spec gets its OWN preparation pass (cache lookup,
 * URL/header build, auth resolution); cache hits short-circuit without
 * touching curl_multi. Only the genuine cache-miss specs are added to
 * the multi-handle.
 *
 * Returns results in the SAME order as the input specs (a results array
 * indexed 0..N-1). Each result has the serverFetch envelope shape.
 *
 * Per-resolver cache statuses are collected into
 * $GLOBALS['__qs_resolver_cache_statuses'] (array, in spec order). The
 * caller (public/index.php) emits the X-QS-Resolver-Cache header as a
 * comma-separated list of these per-call statuses.
 *
 * @param array $specs Array of `['endpointRef' => ..., 'inputs' => [...],
 *                      'context' => [...]]`. The per-spec `context` is
 *                      merged on top of the outer `$context` so callers
 *                      can per-spec-override cacheTTL etc.
 * @param array $context Default execution context applied to every spec
 *                       (session, cookieHeader, apiSecrets test-injection).
 * @return array Indexed array of result envelopes (same order as $specs).
 */
function serverFetchMulti(array $specs, array $context = []): array {
    $n = count($specs);
    $results  = array_fill(0, $n, null);
    $statuses = array_fill(0, $n, 'skip');
    $prepared = [];   // idx => prep array (for the genuine misses)

    // Preparation phase — registry lookup + auth + cache check per spec.
    foreach ($specs as $idx => $spec) {
        $endpointRef    = (string) ($spec['endpointRef'] ?? '');
        $specInputs     = $spec['inputs'] ?? [];
        $mergedContext  = array_merge($context, $spec['context'] ?? []);

        $prep = _serverFetchPrepare($endpointRef, $specInputs, $mergedContext);

        if ($prep['state'] === 'error') {
            $results[$idx]  = $prep['result'];
            $statuses[$idx] = 'skip';   // no cache interaction happened
            continue;
        }
        if ($prep['state'] === 'hit') {
            $results[$idx]  = $prep['result'];
            $statuses[$idx] = 'hit';
            continue;
        }
        // prepared — needs curl
        $statuses[$idx]  = $prep['cacheStatus'];
        $prepared[$idx]  = $prep;
    }

    // Multi-curl phase — fire all the prepared handles in parallel.
    if (!empty($prepared)) {
        $multi = curl_multi_init();
        foreach ($prepared as $idx => $prep) {
            curl_multi_add_handle($multi, $prep['handle']);
        }

        $stillRunning = null;
        do {
            $multiStatus = curl_multi_exec($multi, $stillRunning);
            if ($stillRunning > 0) {
                // Block until at least one handle has activity — keeps
                // CPU off the busy-loop while the network does its work.
                curl_multi_select($multi, 1.0);
            }
        } while ($stillRunning > 0 && $multiStatus === CURLM_OK);

        // Collection phase — read each response body + parse + cache write.
        foreach ($prepared as $idx => $prep) {
            $ch           = $prep['handle'];
            $responseBody = curl_multi_getcontent($ch);
            $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno    = curl_errno($ch);
            $curlError    = $curlErrno !== 0 ? curl_error($ch) : '';

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($curlErrno !== 0 || $responseBody === false || $responseBody === null) {
                $results[$idx] = [
                    'ok'     => false,
                    'status' => 0,
                    'data'   => null,
                    'error'  => "curl failed for @{$prep['endpointRef']}: {$curlError}",
                ];
                continue;
            }

            $result = _serverFetchParseResponse((string) $responseBody, $httpCode);
            if ($prep['cacheable'] && $result['ok']) {
                writeResolverCache('@' . $prep['endpointRef'], $prep['inputs'], $result, $prep['cacheTTL']);
            }
            $results[$idx] = $result;
        }

        curl_multi_close($multi);
    }

    // Per-resolver cache status array for observability. public/index.php
    // emits this as a comma-separated X-QS-Resolver-Cache header so
    // DevTools shows "hit,miss,disabled" for a 3-resolver route.
    $GLOBALS['__qs_resolver_cache_statuses'] = $statuses;

    return $results;
}
