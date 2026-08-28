<?php
/**
 * API registry — the READ half, and the only half a served page needs.
 *
 * `ApiEndpointManager` is 1,100 lines, and almost all of it is AUTHORING:
 * add / edit / delete an API, validate a method, compile the client bundle,
 * transform count bindings. A request that renders a page uses exactly three
 * things from it — look up an API, look up an endpoint, decide whether the
 * endpoint may be called from the server — and those three are what
 * `serverFetch` needs to turn `@api/endpoint` into an HTTP call.
 *
 * WHY THE SPLIT EXISTS. A production build carries the runtime that serves its
 * pages and nothing else; that is what precompilation buys. Shipping the whole
 * manager to make three lookups work would drag the authoring surface — and its
 * `utilsManagement.php` dependency — into every deployed site. Splitting the
 * read half out means both surfaces run the SAME lookup code, and only the
 * install carries the half that writes.
 *
 * `ApiEndpointManager` delegates to these functions rather than duplicating
 * them, so there is one definition of "what is this endpoint" in the tree.
 *
 * ⚠ NOTHING HERE WRITES, and nothing here creates a directory. The manager's
 * constructor does both (it mkdir's data/ and seeds an empty config), which is
 * fine for an authoring surface and wrong for a served page — a deployed build
 * may sit on a read-only filesystem, and a page render has no business creating
 * project files.
 */

if (!function_exists('qs_api_config_path')) {
    /**
     * Where this project's API registry lives.
     *
     * @param string|null $projectPath Defaults to PROJECT_PATH, then SECURE_FOLDER_PATH.
     */
    function qs_api_config_path(?string $projectPath = null): string
    {
        if ($projectPath === null) {
            $projectPath = defined('PROJECT_PATH') ? PROJECT_PATH : SECURE_FOLDER_PATH;
        }
        return $projectPath . '/data/api-endpoints.json';
    }
}

if (!function_exists('qs_api_load_config')) {
    /**
     * The registry, or an empty one. Never fails, never writes.
     *
     * @return array{version: string, updated: ?string, apis: array}
     */
    function qs_api_load_config(?string $projectPath = null): array
    {
        $empty = ['version' => '1.0', 'updated' => null, 'apis' => []];

        $path = qs_api_config_path($projectPath);
        if (!file_exists($path)) {
            return $empty;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return $empty;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $empty;
        }
        if (!isset($data['apis']) || !is_array($data['apis'])) {
            $data['apis'] = [];
        }
        return $data;
    }
}

if (!function_exists('qs_api_get')) {
    /** One API's definition (name, baseUrl, auth, endpoints), or null. */
    function qs_api_get(string $apiId, ?string $projectPath = null): ?array
    {
        $config = qs_api_load_config($projectPath);
        return $config['apis'][$apiId] ?? null;
    }
}

if (!function_exists('qs_api_get_endpoint')) {
    /**
     * One endpoint, flattened with the API context a caller needs to issue the
     * request: `apiId`, `apiName`, `baseUrl`, `fullUrl`, `apiAuth`.
     *
     * @param string      $endpointId Endpoint id.
     * @param string|null $apiId      Restrict the search to one API.
     */
    function qs_api_get_endpoint(string $endpointId, ?string $apiId = null, ?string $projectPath = null): ?array
    {
        $config = qs_api_load_config($projectPath);

        foreach ($config['apis'] as $aid => $api) {
            if ($apiId !== null && $aid !== $apiId) {
                continue;
            }
            foreach (($api['endpoints'] ?? []) as $endpoint) {
                if (($endpoint['id'] ?? null) === $endpointId) {
                    return array_merge($endpoint, [
                        'apiId'   => $aid,
                        'apiName' => $api['name'] ?? $aid,
                        'baseUrl' => $api['baseUrl'] ?? '',
                        'fullUrl' => ($api['baseUrl'] ?? '') . ($endpoint['path'] ?? ''),
                        'apiAuth' => $api['auth'] ?? [],
                    ]);
                }
            }
        }
        return null;
    }
}

if (!function_exists('qs_api_derive_callable_from')) {
    /**
     * Where an endpoint may be called from, inferred from its auth type.
     *
     * `apiKey` is the one shape whose secret is meant to live server-side, so
     * it defaults to server-only; every other shape keeps its credential on the
     * client and defaults to both.
     */
    function qs_api_derive_callable_from(string $authType): string
    {
        return $authType === 'apiKey' ? 'server' : 'both';
    }
}

if (!function_exists('qs_api_effective_callable_from')) {
    /**
     * The endpoint's effective `callableFrom`: an explicit value wins,
     * otherwise it is derived from the endpoint's auth type, otherwise the
     * API's.
     */
    function qs_api_effective_callable_from(array $api, array $endpoint): string
    {
        $valid = ['client', 'server', 'both'];
        if (isset($endpoint['callableFrom']) && in_array($endpoint['callableFrom'], $valid, true)) {
            return $endpoint['callableFrom'];
        }
        $authType = $endpoint['auth']['type'] ?? $api['auth']['type'] ?? 'none';
        return qs_api_derive_callable_from((string) $authType);
    }
}

if (!function_exists('qs_api_count_strings')) {
    /**
     * The count-sentence strings this project's bindings need, in THIS
     * request's language.
     *
     * A count binding in sentence format stores three translation KEYS
     * (`zeroKey` / `oneKey` / `manyKey`). The keys are language-independent and
     * belong in the compiled client config; the SENTENCES are not, and cannot
     * live there — `scripts/qs-api-config.js` is one file per project, so
     * whichever language last wrote it would be served to every visitor. A
     * bilingual site froze in one language, on the live surface and in a build
     * alike.
     *
     * So the page carries them. PHP already knows the language at render time,
     * resolves each key for it, and hands the result to `qs.js` as
     * `window.QS_COUNT_STRINGS` — the same shape as the seven other
     * `window.QS_*` blocks the runtime handoff emits. Nothing about the
     * mechanism is new; the strings simply moved from the static file to the
     * page, which is where per-language values already live.
     *
     * PROJECT-WIDE, not per-page. Which endpoints a page may call is not
     * statically known (an interaction chain, a state store and a resolver can
     * each reach one), and the table is three short strings per sentence
     * binding. Emitting the project's whole set costs bytes measured in
     * hundreds and removes the question.
     *
     * A key that does not resolve is still returned, carrying Translator's
     * `{translation missing: …}` marker — the author needs to see which key is
     * missing, and the alternative (omitting it) is indistinguishable at the
     * runtime from a stale artifact.
     *
     * @return array<string,string> key => sentence; empty when the project has
     *                              no count-sentence binding.
     */
    function qs_api_count_strings(?string $projectPath = null): array
    {
        $keys = [];
        foreach (qs_api_load_config($projectPath)['apis'] as $api) {
            foreach (($api['endpoints'] ?? []) as $endpoint) {
                foreach (($endpoint['responseBindings'] ?? []) as $binding) {
                    if (!is_array($binding)
                        || ($binding['renderMode'] ?? null) !== 'count'
                        || ($binding['format'] ?? null) !== 'sentence') {
                        continue;
                    }
                    foreach (['zeroKey', 'oneKey', 'manyKey'] as $field) {
                        $key = $binding[$field] ?? null;
                        if (is_string($key) && $key !== '') {
                            $keys[$key] = true;
                        }
                    }
                }
            }
        }
        if (empty($keys)) {
            return [];
        }

        // Lazily: a project with no sentence binding never loads the translator.
        require_once __DIR__ . '/../classes/Translator.php';

        $out = [];
        foreach (array_keys($keys) as $key) {
            $out[$key] = Translator::translate($key);
        }
        return $out;
    }
}

if (!function_exists('qs_api_substitute_path')) {
    /**
     * Put values into an endpoint path's `:placeholders`, and decide what an
     * OMITTED one leaves behind.
     *
     * ⚠ AN OMITTED OPTIONAL PARAMETER MUST PRODUCE A VALID REQUEST. All three
     * substitution sites used to leave `:name` in the URL when no value was
     * supplied — deliberately, so a missing value would "surface the omission".
     * That is defensible for a REQUIRED parameter and wrong for an optional
     * one: declaring a parameter optional and then omitting it sent the API the
     * literal string `:nameContains` as the filter value. Silently, and with a
     * 200 back, because most APIs treat an unknown filter value as a filter.
     *
     * The rule for an omitted optional placeholder:
     *
     *   - it occupies a whole path segment        → the segment is removed;
     *   - the segment BEFORE it is a literal that
     *     equals the parameter's name             → that segment goes too;
     *   - it is only part of a segment
     *     (`/file-:id.json`)                      → left literal, because
     *                                               removing part of a segment
     *                                               has no defensible meaning.
     *
     * The second rule is the key/value path-pair convention QuickSite's own
     * `TrimParameters` reads — `/endpoint.php/key1/value1/key2/value2`. Dropping
     * only the value there leaves a dangling `/nameContains/`, which most APIs
     * read as "this filter, empty" rather than "no filter". Dropping the pair is
     * what the author meant. It fires only when the label and the parameter name
     * MATCH, so `/users/:id/posts` loses `:id` and keeps `users` — narrow and
     * predictable beats clever.
     *
     * ⚠ REQUIRED-and-missing is NOT decided here. The three callers legitimately
     * differ — the test panel shows the literal so the omission is visible in the
     * response, `QS.fetch` rejects before issuing the request, and the server-side
     * resolver lets the upstream 404 speak. So the literal is left in place and
     * the name is REPORTED; the caller chooses.
     *
     * ⚠ `secure/src/runtime/qs.js` implements this same rule for the browser and
     * the two must not drift. It cannot call this — it is the other language.
     *
     * @param string $path       The endpoint's path, e.g. `/listFile.php/nameContains/:nameContains`.
     * @param array  $values     name => value. Empty string and null count as "not supplied".
     * @param array  $parameters The endpoint's declared `parameters` list. A
     *                           placeholder no entry declares is treated as
     *                           OPTIONAL, matching QS.fetch.
     * @return array{path: string, consumed: string[], missingRequired: string[]}
     */
    function qs_api_substitute_path(string $path, array $values, array $parameters = []): array
    {
        $required = [];
        foreach ($parameters as $def) {
            if (is_array($def) && isset($def['name']) && !empty($def['required'])) {
                $required[(string) $def['name']] = true;
            }
        }

        $supplied = static function ($name) use ($values) {
            return array_key_exists($name, $values)
                && $values[$name] !== null
                && $values[$name] !== '';
        };

        $consumed = [];
        $missingRequired = [];
        $segments = explode('/', $path);
        $out = [];

        foreach ($segments as $segment) {
            // A whole segment that is exactly one placeholder — the only shape
            // that can be dropped.
            if (preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)$/', $segment, $m)) {
                $name = $m[1];
                if ($supplied($name)) {
                    $out[] = rawurlencode((string) $values[$name]);
                    $consumed[] = $name;
                } elseif (isset($required[$name])) {
                    $missingRequired[] = $name;
                    $out[] = $segment;               // left literal; caller decides
                } else {
                    // Optional and omitted: drop it, and the label before it
                    // when the two share a name (the key/value pair convention).
                    if (!empty($out) && end($out) === $name) {
                        array_pop($out);
                    }
                }
                continue;
            }

            // A placeholder embedded in a larger segment: substitute what we
            // have and leave the rest alone.
            $out[] = preg_replace_callback(
                '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
                function ($m) use ($supplied, $values, $required, &$consumed, &$missingRequired) {
                    $name = $m[1];
                    if ($supplied($name)) {
                        $consumed[] = $name;
                        return rawurlencode((string) $values[$name]);
                    }
                    if (isset($required[$name])) {
                        $missingRequired[] = $name;
                    }
                    return $m[0];
                },
                $segment
            );
        }

        $result = implode('/', $out);
        // Dropping a trailing segment can leave `/listFile.php/` — tidy it, but
        // never reduce the path to nothing: a path of `/:only` with the value
        // omitted is still a request to the root, not a request with no path.
        if (strlen($result) > 1) {
            $result = rtrim($result, '/');
        }
        if ($result === '') {
            $result = '/';
        }

        return [
            'path'            => $result,
            'consumed'        => array_values(array_unique($consumed)),
            'missingRequired' => array_values(array_unique($missingRequired)),
        ];
    }
}

if (!function_exists('qs_api_client_origins')) {
    /**
     * The origins a page may legitimately call, for the surface-B CSP.
     *
     * `connect-src 'self'` blocked EVERY browser-side call to a registered
     * external API on `/p/<projectId>/` — which is the whole client-side half
     * of the API registry, unusable in exactly the place an author develops.
     * A built site sends no CSP at all, so the same page worked once deployed:
     * blocked in development, open in production, which is the worst way round.
     *
     * The registry already names every origin an author registered, so the
     * policy is derived rather than configured. Registering an API IS the act
     * of saying the site talks to it.
     *
     * Filtered the same way the compiled client config is: an API whose every
     * endpoint is server-only never reaches the browser, so its origin has no
     * business in `connect-src`. Both go through
     * qs_api_effective_callable_from(), so the allowlist and the config cannot
     * disagree about what the browser can reach.
     *
     * Only the ORIGIN is emitted (scheme://host[:port]) — a CSP source is not a
     * path matcher, and a baseUrl that does not parse to an http(s) origin is
     * skipped rather than guessed at, so a malformed value can never inject a
     * token into the header.
     *
     * @return string[] Distinct origins, no duplicates, possibly empty.
     */
    function qs_api_client_origins(?string $projectPath = null): array
    {
        $origins = [];
        foreach (qs_api_load_config($projectPath)['apis'] as $api) {
            if (!is_array($api)) {
                continue;
            }
            $reachable = false;
            foreach (($api['endpoints'] ?? []) as $endpoint) {
                if (is_array($endpoint)
                    && qs_api_effective_callable_from($api, $endpoint) !== 'server') {
                    $reachable = true;
                    break;
                }
            }
            if (!$reachable) {
                continue;
            }

            $parts = parse_url((string) ($api['baseUrl'] ?? ''));
            if (!is_array($parts)
                || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                continue;
            }
            // Host and port only, and only from parse_url's own output — never
            // a substring of the author's string.
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $parts['host'])) {
                continue;
            }
            $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
            if (isset($parts['port']) && is_int($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            $origins[$origin] = true;
        }
        return array_keys($origins);
    }
}
