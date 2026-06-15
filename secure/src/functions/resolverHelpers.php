<?php
/**
 * resolverHelpers.php — Sidecar storage + validation for per-route
 * data resolvers (beta.8 A2).
 *
 * Storage shape:
 *   secure/projects/<project>/data/route-resolvers.json
 *
 *   {
 *       "<routePath>": {                      // routes.php-style path
 *           "endpoint": "@apiId/endpointId",  // required
 *           "inputs":   {"<name>": "<source spec>"},  // optional
 *           "expose":   {"<varName>": "<dot.path>"},  // optional
 *           "cacheTTL": 300,                  // optional, seconds, Slice 4
 *           "onMiss":   "render-empty"        // optional, Slice 6
 *       }
 *   }
 *
 * Why sidecar (vs co-locating in routes.php):
 *   routes.php today is a nested array of route-segment KEYS — leaves
 *   are empty arrays. Adding per-route metadata would either require
 *   reserving special keys (e.g. '__meta') which breaks the tree-walk
 *   in routeExists and the matcher, OR migrating the whole shape to a
 *   richer record form. The sidecar keeps routes.php untouched and
 *   keeps the resolver concern isolated. Migration to inline records
 *   stays available as a beta.9+ refactor.
 *
 * Source-spec convention for inputs:
 *   "param:<name>"   → URL path param from QS.routeParams (beta.8 A1)
 *   "query:<name>"   → URL query string param
 *   "session:<name>" → server-side session field (depends on Tier 3
 *                      server session wiring)
 *   "<literal>"      → bare literal value (no recognised prefix)
 *
 * Dot-path convention for expose:
 *   "data.product.name" walks $response['data']['product']['name'].
 *   Empty string ('') exposes the whole response as the variable.
 *   Missing path resolves to null at render time (the renderer's
 *   variable substitution treats null/missing identically).
 */

require_once __DIR__ . '/../classes/ApiEndpointManager.php';

function getResolverSidecarPath(): string {
    return PROJECT_PATH . '/data/route-resolvers.json';
}

/**
 * Load all resolvers for the active project. Empty array when the
 * sidecar file doesn't exist or is unreadable (route-resolvers are
 * fully optional — most routes don't have one).
 */
function loadResolversSidecar(): array {
    $path = getResolverSidecarPath();
    if (!file_exists($path)) {
        return [];
    }
    $content = @file_get_contents($path);
    if ($content === false || $content === '') {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function saveResolversSidecar(array $resolvers): bool {
    $path = getResolverSidecarPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            return false;
        }
    }
    // PHP can't distinguish empty-list `[]` from empty-object `{}` at
    // the encoding layer (json_encode of either emits `[]`). The
    // sidecar is semantically a route-keyed object — write `{}` when
    // empty so the on-disk file looks right to humans reading it.
    // (JSON_FORCE_OBJECT is NOT a fix — it'd also clobber the inner
    // array-shape resolver lists, turning [config0, config1] into
    // {"0": config0, "1": config1}.)
    if (empty($resolvers)) {
        $json = "{}\n";
    } else {
        $json = json_encode($resolvers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Beta.8 A2 Slice 7.5 — sidecar storage now supports BOTH shapes per
 * route (backward-compat with single-resolver entries):
 *
 *   - SCALAR (associative array, has 'endpoint' key directly):
 *       {"products/:slug": {"endpoint": "...", "inputs": {...}, ...}}
 *     Single-resolver route. Written when a route has exactly ONE
 *     resolver. Backward-compat — existing sidecars from Slices 1-7
 *     keep their on-disk shape.
 *
 *   - ARRAY (sequential numeric-indexed array of associative configs):
 *       {"book/:id": [{"endpoint": "...", ...}, {"endpoint": "...", ...}]}
 *     Multi-resolver route. Written when a route has >1 resolver.
 *     Fires in parallel via curl_multi_*; exposed vars go into a flat
 *     namespace AND namespaced-by-index ($r0, $r1, ...) — collision
 *     in the flat namespace is rejected at save time per
 *     BETA8_MULTI_RESOLVER.md locked decisions.
 *
 * Detection: a SCALAR entry has 'endpoint' as a top-level key (the
 * required resolver field). An ARRAY entry is a sequential 0-indexed
 * array of objects. `_normalizeResolverEntry` consolidates both into
 * an internal array-of-configs representation that downstream code
 * (DataResolver, validators) works on uniformly.
 */

/**
 * True when $arr is a sequential 0-indexed list (PHP 8.1's
 * array_is_list polyfill — runtime is 8.0.30).
 */
function _isResolverArrayShape(array $arr): bool {
    if (empty($arr)) return false;
    return array_keys($arr) === range(0, count($arr) - 1);
}

/**
 * Normalise a sidecar entry (either scalar or array shape) to the
 * internal array-of-configs representation. Empty array for malformed
 * input; downstream code treats empty as "no resolver."
 */
function _normalizeResolverEntry($entry): array {
    if (!is_array($entry) || empty($entry)) return [];
    if (_isResolverArrayShape($entry)) {
        // Array shape — filter out any non-array (malformed) entries
        // so downstream code can iterate safely.
        return array_values(array_filter($entry, 'is_array'));
    }
    // Scalar (single config) — wrap into a 1-element array.
    return [$entry];
}

/**
 * Get all resolvers for a route as an array. Empty array when none.
 *
 * This is the canonical accessor for downstream code (DataResolver,
 * renderer, hydration handoff) — both single- and multi-resolver
 * routes look identical from the caller's perspective. Slice 7.5
 * onward; existing callers from Slices 1-7 still use
 * getResolverForRoute (backward-compat wrapper below).
 */
function getResolversForRoute(string $routePath): array {
    $all = loadResolversSidecar();
    return _normalizeResolverEntry($all[$routePath] ?? null);
}

/**
 * Set or clear ALL resolvers for a route. Pass $configs = null OR
 * empty array to clear. Otherwise pass an array of resolver configs:
 *
 *   - 1 element → written as SCALAR shape on disk (backward-compat
 *     readers from Slices 1-7 keep working)
 *   - 2+ elements → written as ARRAY shape on disk
 *
 * Idempotent: clearing a route that has no resolver is a no-op
 * success. Caller must run validateResolverConfigs first — this
 * function does not re-validate, it just writes.
 */
function setResolversForRoute(string $routePath, ?array $configs): bool {
    $all = loadResolversSidecar();
    if ($configs === null || empty($configs)) {
        if (!array_key_exists($routePath, $all)) {
            return true; // already absent — nothing to do
        }
        unset($all[$routePath]);
    } else {
        // Storage shape locked in BETA8_MULTI_RESOLVER.md decision #1:
        //   scalar when single resolver (back-compat with Slices 1-7)
        //   array  when multiple resolvers
        $list = array_values($configs);
        $all[$routePath] = count($list) === 1 ? $list[0] : $list;
    }
    return saveResolversSidecar($all);
}

/**
 * Backward-compat single-resolver accessor — returns the FIRST
 * resolver of the route (or null when none). Used by existing
 * callsites in public/index.php, PageManagement.php, deleteRoute.php
 * until they migrate to getResolversForRoute (incremental in
 * subsequent Slice 7.5 sub-slices).
 *
 * Multi-resolver routes silently return only the first entry through
 * this function — that's the price of keeping the existing contract.
 * Callers that need ALL resolvers MUST switch to getResolversForRoute.
 *
 * @deprecated since Slice 7.5 — prefer getResolversForRoute. Kept for
 *             backward compatibility with pre-7.5 callers; removed in
 *             a future cleanup once all callsites migrate.
 */
function getResolverForRoute(string $routePath): ?array {
    $configs = getResolversForRoute($routePath);
    return empty($configs) ? null : $configs[0];
}

/**
 * Backward-compat single-resolver setter — REPLACES the entire entry
 * with the single config (or clears with null). Multi-resolver entries
 * are clobbered to a single resolver if called.
 *
 * @deprecated since Slice 7.5 — prefer setResolversForRoute. Kept for
 *             backward compatibility (setRouteResolver command uses it
 *             until the command grows the multi-resolver `index` param
 *             in Slice 7.5.B).
 */
function setResolverForRoute(string $routePath, ?array $config): bool {
    if ($config === null) {
        return setResolversForRoute($routePath, null);
    }
    return setResolversForRoute($routePath, [$config]);
}

function deleteResolverForRoute(string $routePath): bool {
    return setResolversForRoute($routePath, null);
}

/**
 * Per-request stash of resolved template variables (beta.8 A2 Slice 3).
 *
 * Populated by public/index.php after firing DataResolver for the matched
 * route. Read by JsonToHtmlRenderer's {{resolved:NAME}} substitution and
 * by any per-page bootstrap that wants $resolved in PHP scope.
 *
 * Globals scope is intentional — the request lifecycle is process-local,
 * the stash never crosses requests, and a global avoids forcing every
 * caller to pass the array through 3+ layers of constructor options.
 */
function setResolvedVars(array $vars): void {
    $GLOBALS['__qs_resolved_vars'] = $vars;
}
function getResolvedVars(): array {
    return $GLOBALS['__qs_resolved_vars'] ?? [];
}

/**
 * Beta.8 A2 Track 2d — generate sample default values for a resolver's
 * `expose` mapping, derived from the endpoint's responseSchema.
 *
 * The editor's emulation panel uses these as initial input values when
 * no per-page emulation has been saved yet — so authors opening a
 * resolver-bound page for the first time see meaningful placeholders
 * shaped like the real data instead of empty inputs.
 *
 * Without a responseSchema on the endpoint, returns an empty array
 * (panel falls back to empty inputs + placeholder text in the field
 * UI). Schemas are JSON-Schema-shaped per the api-endpoints.json
 * convention (`type`, `properties`, optional `format`).
 *
 * @param array $resolverConfig A single resolver block: requires
 *                              'endpoint' + 'expose'.
 * @param ApiEndpointManager|null $apiManager Optional injection point.
 * @return array<string, mixed> Map of expose var name → sample value.
 *                              Type-aware: strings/numbers/booleans
 *                              come back as primitives; arrays/objects
 *                              come back as empty containers.
 */
function getResolverDefaultsForRoute(array $resolverConfig, ?ApiEndpointManager $apiManager = null): array {
    $defaults = [];
    $endpointRef = $resolverConfig['endpoint'] ?? '';
    $expose = $resolverConfig['expose'] ?? [];
    if (!is_string($endpointRef) || $endpointRef === '' || empty($expose) || !is_array($expose)) {
        return $defaults;
    }

    $apiManager = $apiManager ?? new ApiEndpointManager();
    $ref = ltrim($endpointRef, '@');
    $apiId = null;
    $endpointId = $ref;
    if (strpos($ref, '/') !== false) {
        [$apiId, $endpointId] = explode('/', $ref, 2);
    }
    $endpoint = $apiManager->getEndpoint($endpointId, $apiId);
    if ($endpoint === null || empty($endpoint['responseSchema']) || !is_array($endpoint['responseSchema'])) {
        return $defaults;
    }

    $schema = $endpoint['responseSchema'];
    foreach ($expose as $varName => $dotPath) {
        if (!is_string($varName) || !is_string($dotPath)) continue;
        $defaults[$varName] = _generateSampleFromSchemaPath($schema, $dotPath, $varName);
    }
    return $defaults;
}

/**
 * Walk a JSON schema down a dot-path then generate a sample value for
 * whatever node is reached. Helper for getResolverDefaultsForRoute.
 *
 * Empty path '' targets the root (the whole response).
 *
 * @param array  $rootSchema JSON-Schema-shaped node
 * @param string $dotPath    e.g. 'data.product.name', '' for root
 * @param string $varName    Used to make string samples a bit more
 *                           recognisable ('sample-userEmail' rather
 *                           than just 'sample').
 * @return mixed Sample value (string/int/bool/array/null)
 */
function _generateSampleFromSchemaPath(array $rootSchema, string $dotPath, string $varName) {
    $cursor = $rootSchema;
    if ($dotPath !== '') {
        foreach (explode('.', $dotPath) as $segment) {
            if (!is_array($cursor)) return null;
            // JSON-Schema convention: properties.<name> for object members.
            if (isset($cursor['properties']) && is_array($cursor['properties'])
                && isset($cursor['properties'][$segment])) {
                $cursor = $cursor['properties'][$segment];
                continue;
            }
            // Array items step: 'items.0' or just `items` for the
            // element shape (we don't model index-stepping fully).
            if ($segment === 'items' && isset($cursor['items']) && is_array($cursor['items'])) {
                $cursor = $cursor['items'];
                continue;
            }
            // Path doesn't exist in schema → no default for this var.
            return null;
        }
    }
    return _sampleValueFromSchemaNode($cursor, $varName);
}

/**
 * Generate a representative sample for one JSON-Schema node.
 */
function _sampleValueFromSchemaNode($schema, string $varName) {
    if (!is_array($schema)) return null;
    $type = $schema['type'] ?? 'string';
    if (is_array($type)) {
        // type can be a list like ['string', 'null'] — take the first
        // non-null type for the sample.
        foreach ($type as $t) {
            if ($t !== 'null') { $type = $t; break; }
        }
        if (is_array($type)) $type = 'string';
    }
    switch ($type) {
        case 'string':
            $format = $schema['format'] ?? '';
            if ($format === 'email') return 'sample@example.com';
            if ($format === 'date') return '2026-01-01';
            if ($format === 'date-time') return '2026-01-01T00:00:00Z';
            if ($format === 'uri' || $format === 'url') return 'https://example.com';
            // Use enum's first value when one is declared.
            if (isset($schema['enum']) && is_array($schema['enum']) && !empty($schema['enum'])) {
                return (string) $schema['enum'][0];
            }
            return 'sample-' . $varName;
        case 'integer':
        case 'number':
            return 1;
        case 'boolean':
            return true;
        case 'array':
            return [];
        case 'object':
            return new stdClass();
        case 'null':
            return null;
        default:
            return 'sample-' . $varName;
    }
}

/**
 * Validate a resolver config block. Returns array of error entries
 * (empty when valid) shaped like {field, reason, hint?}.
 *
 * Required: endpoint (string, must exist in registry, callableFrom !== 'client')
 * Optional: inputs (object<string,string>), expose (object<string,string>),
 *           cacheTTL (non-negative integer), onMiss (string).
 *
 * The validator is conservative — it won't catch every misuse (e.g. a
 * dot-path that walks into a primitive at runtime), but it rejects
 * shape mistakes loudly at write time so the resolver itself can keep
 * its runtime path tight.
 */
/**
 * Allowed resolver kinds. Beta.8 shipped only data-fetch resolvers (kind
 * was implicit); beta.9 A1 Slice 2b introduces side-effect kinds
 * (oauth-start, oauth-callback) that short-circuit the render with a
 * redirect + optional session cookie. Future side-effect kinds (e.g.
 * 'redirect', 'oauth-logout') extend this list + the dispatcher in
 * public/index.php.
 */
const RESOLVER_ALLOWED_KINDS = ['data', 'oauth-start', 'oauth-callback'];

function validateResolverConfig(array $config, ?ApiEndpointManager $apiManager = null): array {
    $errors = [];

    // Kind dispatch (beta.9 A1 Slice 2b). Default 'data' preserves
    // backward-compat with beta.8 configs (no kind field = data resolver).
    $kind = $config['kind'] ?? 'data';
    if (!is_string($kind) || $kind === '') {
        return [[
            'field'    => 'resolver.kind',
            'reason'   => 'invalid_type',
            'expected' => 'string',
        ]];
    }
    if (!in_array($kind, RESOLVER_ALLOWED_KINDS, true)) {
        return [[
            'field'    => 'resolver.kind',
            'reason'   => 'invalid_value',
            'value'    => $kind,
            'expected' => 'one of: ' . implode(', ', RESOLVER_ALLOWED_KINDS),
        ]];
    }

    // OAuth kinds use a completely different schema (provider, no
    // endpoint/inputs/expose/cacheTTL/onMiss). Validate + early-return.
    if ($kind === 'oauth-start' || $kind === 'oauth-callback') {
        // provider — required, string, either a known preset id or a
        // {:routeParam} placeholder so one resolver entry on
        // /auth/oauth/:provider/callback can serve every provider.
        if (!isset($config['provider']) || !is_string($config['provider']) || $config['provider'] === '') {
            $errors[] = [
                'field'  => 'resolver.provider',
                'reason' => 'required',
                'hint'   => 'OAuth resolver kinds require a provider id matching a key in oauth-presets.json (e.g. "google", "github"), or a {:routeParam} placeholder.',
            ];
        } else {
            $provider = $config['provider'];
            $isPlaceholder = (bool) preg_match('/^\{:\w+\}$/', $provider);
            if (!$isPlaceholder) {
                // Literal provider — must exist in oauth-presets.json.
                $presetsPath = SECURE_FOLDER_PATH . '/admin/config/oauth-presets.json';
                if (file_exists($presetsPath)) {
                    $presets = json_decode(@file_get_contents($presetsPath) ?: '{}', true);
                    if (is_array($presets) && !isset($presets[$provider])) {
                        $errors[] = [
                            'field'  => 'resolver.provider',
                            'reason' => 'unknown_provider',
                            'value'  => $provider,
                            'hint'   => 'Provider id must match a key in secure/admin/config/oauth-presets.json. Add the preset there (URL/scope/userinfo paths) or use a {:routeParam} placeholder to read from the URL.',
                        ];
                    }
                }
            }
        }

        // callback_url — optional, string. Applies ONLY to oauth-start
        // (it sets the redirect_uri sent to the provider). The
        // oauth-callback route IS the callback URL, so the field is
        // inapplicable on that kind. {:routeParam} placeholders are
        // allowed and resolved by the dispatcher before handleStart()
        // is invoked. Omitted ⇒ handler defaults to
        // /auth/oauth/<provider>/callback.
        if (array_key_exists('callback_url', $config)) {
            if ($kind === 'oauth-callback') {
                $errors[] = [
                    'field'  => 'resolver.callback_url',
                    'reason' => 'inapplicable_for_kind',
                    'kind'   => $kind,
                    'hint'   => 'callback_url applies only to oauth-start (it sets the redirect_uri sent to the provider). The oauth-callback route IS the callback URL.',
                ];
            } elseif (!is_string($config['callback_url']) || $config['callback_url'] === '') {
                $errors[] = [
                    'field'  => 'resolver.callback_url',
                    'reason' => 'invalid_type',
                    'hint'   => 'callback_url must be a non-empty string (e.g., "/auth/oauth/{:provider}/callback" or "/auth/google/callback"). Omit to use the default /auth/oauth/<provider>/callback.',
                ];
            }
        }

        // Reject fields that belong to the data-resolver schema — author
        // likely mixed shapes by mistake; explicit error beats silent
        // ignore so they fix the config rather than expect both to work.
        foreach (['endpoint', 'inputs', 'expose', 'cacheTTL', 'onMiss'] as $dataField) {
            if (isset($config[$dataField])) {
                $errors[] = [
                    'field'  => 'resolver.' . $dataField,
                    'reason' => 'inapplicable_for_kind',
                    'kind'   => $kind,
                    'hint'   => 'Field "' . $dataField . '" applies only to data resolvers (kind=data). OAuth kinds use "kind" + "provider" (+ optional "callback_url" for oauth-start).',
                ];
            }
        }

        return $errors;
    }

    // ── kind == 'data' → existing data-resolver validation below ──
    $apiManager = $apiManager ?? new ApiEndpointManager();

    // endpoint — required, string, must exist in registry, server-callable.
    if (!isset($config['endpoint']) || !is_string($config['endpoint']) || $config['endpoint'] === '') {
        $errors[] = [
            'field'  => 'resolver.endpoint',
            'reason' => 'required',
            'hint'   => 'Provide an @apiId/endpointId reference from the API registry.',
        ];
    } else {
        $ref = ltrim($config['endpoint'], '@');
        $apiId = null;
        $endpointId = $ref;
        if (strpos($ref, '/') !== false) {
            [$apiId, $endpointId] = explode('/', $ref, 2);
        }
        $endpoint = $apiManager->getEndpoint($endpointId, $apiId);
        if ($endpoint === null) {
            $errors[] = [
                'field'  => 'resolver.endpoint',
                'reason' => 'not_in_registry',
                'value'  => $config['endpoint'],
                'hint'   => 'Register the endpoint at /admin/apis first.',
            ];
        } else {
            $api = $apiManager->getApi($endpoint['apiId']);
            if ($api !== null) {
                $callableFrom = ApiEndpointManager::effectiveCallableFrom($api, $endpoint);
                if ($callableFrom === 'client') {
                    $errors[] = [
                        'field'        => 'resolver.endpoint',
                        'reason'       => 'client_only',
                        'value'        => $config['endpoint'],
                        'callableFrom' => 'client',
                        'hint'         => "Endpoint is marked callableFrom='client' and cannot be called server-side. Change it to 'server' or 'both' in /admin/apis.",
                    ];
                }
            }
        }
    }

    // inputs — optional object<string,string>.
    if (array_key_exists('inputs', $config)) {
        if (!is_array($config['inputs'])) {
            $errors[] = [
                'field'    => 'resolver.inputs',
                'reason'   => 'invalid_type',
                'expected' => 'object',
            ];
        } else {
            foreach ($config['inputs'] as $name => $sourceSpec) {
                if (!is_string($name) || $name === '') {
                    $errors[] = [
                        'field'  => 'resolver.inputs',
                        'reason' => 'invalid_key',
                        'hint'   => 'Input names must be non-empty strings.',
                    ];
                    continue;
                }
                // Beta.8 A2 Slice 7 — character validation. Input names
                // map to endpoint param keys (URL/query/body); hyphens
                // ARE allowed (kebab-case APIs like `api-key`,
                // `content-type` are common). Special chars (quotes,
                // spaces, dots, etc.) break URL encoding semantics or
                // create JSON-escape oddness — block them.
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-]*$/', $name)) {
                    $errors[] = [
                        'field'  => 'resolver.inputs',
                        'reason' => 'invalid_name_chars',
                        'value'  => $name,
                        'hint'   => 'Input names must start with a letter or underscore and use only letters, digits, underscores, or hyphens.',
                    ];
                    continue;
                }
                if (!is_string($sourceSpec)) {
                    $errors[] = [
                        'field'    => "resolver.inputs.{$name}",
                        'reason'   => 'invalid_type',
                        'expected' => 'string',
                        'hint'     => "Use a source spec like 'param:slug' / 'query:lang' / 'session:userId', or a bare literal.",
                    ];
                    continue;
                }
                // Beta.8 A2 Slice 7 server backstop — empty + malformed
                // source specs. The admin UI blocks these at form-fill
                // time, but direct POST callers (curl, scripts) need
                // the same protection so a malformed config can't slip
                // into the sidecar.
                //
                // Source-spec semantics (inputs ONLY — expose's empty
                // dot-path means "whole response" and is legitimate):
                //   - empty                : meaningless ('' as a literal
                //     value is ~never intentional; "default to empty"
                //     is better expressed as not declaring the input)
                //   - 'param:' / 'query:' /
                //     'session:' (no payload): missing the value after
                //     the colon — author started typing then stopped
                if ($sourceSpec === '') {
                    $errors[] = [
                        'field'  => "resolver.inputs.{$name}",
                        'reason' => 'empty_value',
                        'hint'   => 'Source spec cannot be empty. Use param:<segment>, query:<key>, session:<field>, or a literal value.',
                    ];
                    continue;
                }
                if (preg_match('/^(param|query|session):$/', $sourceSpec)) {
                    $errors[] = [
                        'field'  => "resolver.inputs.{$name}",
                        'reason' => 'malformed_spec',
                        'value'  => $sourceSpec,
                        'hint'   => "Source spec '{$sourceSpec}' is missing the value after the colon. Use e.g. 'param:slug' / 'query:lang' / 'session:userId'.",
                    ];
                }
            }
        }
    }

    // expose — optional object<string,string>.
    if (array_key_exists('expose', $config)) {
        if (!is_array($config['expose'])) {
            $errors[] = [
                'field'    => 'resolver.expose',
                'reason'   => 'invalid_type',
                'expected' => 'object',
            ];
        } else {
            foreach ($config['expose'] as $name => $path) {
                if (!is_string($name) || $name === '') {
                    $errors[] = [
                        'field'  => 'resolver.expose',
                        'reason' => 'invalid_key',
                        'hint'   => 'Template variable names must be non-empty strings.',
                    ];
                    continue;
                }
                // Beta.8 A2 Slice 7 — character validation. Expose names
                // become $<name> PHP template variables — anything that
                // isn't a valid PHP identifier silently breaks the
                // template at render time. STRICT rule: letters, digits,
                // underscores only, must start with letter or underscore.
                // Hyphens are NOT allowed here (would break PHP var
                // syntax) even though they're allowed in input names.
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                    $errors[] = [
                        'field'  => 'resolver.expose',
                        'reason' => 'invalid_name_chars',
                        'value'  => $name,
                        'hint'   => 'Template variable names must start with a letter or underscore and use only letters, digits, and underscores (becomes a $variable in the template — must be a valid PHP identifier).',
                    ];
                    continue;
                }
                if (!is_string($path)) {
                    $errors[] = [
                        'field'    => "resolver.expose.{$name}",
                        'reason'   => 'invalid_type',
                        'expected' => 'string',
                        'hint'     => "Use a dot-path like 'data.product.name', or '' for the whole response.",
                    ];
                }
            }
        }
    }

    // cacheTTL — optional non-negative integer (Slice 4 implements; just validate shape).
    if (array_key_exists('cacheTTL', $config)) {
        if (!is_int($config['cacheTTL']) || $config['cacheTTL'] < 0) {
            $errors[] = [
                'field'    => 'resolver.cacheTTL',
                'reason'   => 'invalid_value',
                'expected' => 'non-negative integer (seconds)',
            ];
        }
    }

    // onMiss — optional enum. Beta.8 A2 Slice 7 Step 5 tightens to a
    // strict allowed list so typos like 'render-empy' are caught at
    // save time instead of silently falling through to default at
    // request time. Future onMiss values (e.g. 'redirect:<url>') are
    // reserved per BETA8_DATA_RESOLVER.md — when added, extend
    // ALLOWED_ONMISS here AND the dropdown in sitemap.js's
    // _renderResolverOnMissSection.
    $ALLOWED_ONMISS = ['render-empty'];
    if (array_key_exists('onMiss', $config)) {
        if (!is_string($config['onMiss']) || $config['onMiss'] === '') {
            $errors[] = [
                'field'    => 'resolver.onMiss',
                'reason'   => 'invalid_type',
                'expected' => "string (e.g. 'render-empty')",
            ];
        } elseif (!in_array($config['onMiss'], $ALLOWED_ONMISS, true)) {
            $errors[] = [
                'field'    => 'resolver.onMiss',
                'reason'   => 'invalid_value',
                'value'    => $config['onMiss'],
                'expected' => 'one of: ' . implode(', ', $ALLOWED_ONMISS),
                'hint'     => "Currently only 'render-empty' is supported. Future values (e.g. 'redirect:<url>') reserved per the design doc.",
            ];
        }
    }

    return $errors;
}

/**
 * Beta.8 A2 Slice 7.5 — multi-resolver validator. Walks an array of
 * resolver configs, runs validateResolverConfig on each (collecting
 * per-config errors with the array index baked into the field path),
 * then runs the collision check: expose key names must be unique
 * across all resolvers in the array (locked decision in
 * BETA8_MULTI_RESOLVER.md — flat-namespace collisions are rejected at
 * save time, authors disambiguate by renaming OR by using the
 * always-available $r0/$r1 namespaced form in the template).
 *
 * Single-resolver routes use the same function with a 1-element array
 * — the collision check is a no-op on length 1.
 *
 * @param array $configs Array of resolver config blocks.
 * @param ApiEndpointManager|null $apiManager Optional injection point.
 * @return array<array> Error entries — same shape as validateResolverConfig
 *                       returns (field/reason/hint/value), plus a
 *                       `resolverIndex` field on per-config errors so the
 *                       caller can highlight the right entry in the UI.
 */
function validateResolverConfigs(array $configs, ?ApiEndpointManager $apiManager = null): array {
    $errors = [];
    if (empty($configs)) return $errors;

    // Phase 1 — per-config validation. Each config gets the full
    // validateResolverConfig pass; errors get re-pathed with the
    // array index so the caller can map them back to a specific
    // resolver slot in the form.
    foreach ($configs as $idx => $config) {
        if (!is_array($config)) {
            $errors[] = [
                'field'         => "resolver[{$idx}]",
                'reason'        => 'invalid_type',
                'expected'      => 'object',
                'resolverIndex' => $idx,
            ];
            continue;
        }
        $perConfigErrors = validateResolverConfig($config, $apiManager);
        foreach ($perConfigErrors as $err) {
            if (isset($err['field']) && is_string($err['field'])) {
                // Rewrite 'resolver.x.y' → 'resolver[N].x.y' so the
                // index is explicit in error reporting.
                if (strpos($err['field'], 'resolver.') === 0) {
                    $err['field'] = 'resolver[' . $idx . '].' . substr($err['field'], 9);
                } elseif ($err['field'] === 'resolver') {
                    $err['field'] = 'resolver[' . $idx . ']';
                }
            }
            $err['resolverIndex'] = $idx;
            $errors[] = $err;
        }
    }

    // Phase 2 — all-same-kind check (beta.9 A1 Slice 2b). Mixing data +
    // side-effect resolvers on one route is incoherent: side-effect kinds
    // (oauth-start, oauth-callback) short-circuit the render with a 302;
    // data resolvers expect the render to proceed with their exposed
    // vars. Reject mixed routes — author splits into separate routes.
    $kinds = [];
    foreach ($configs as $config) {
        if (is_array($config)) {
            $kinds[] = $config['kind'] ?? 'data';
        }
    }
    $uniqueKinds = array_values(array_unique($kinds));
    if (count($uniqueKinds) > 1) {
        $errors[] = [
            'field'  => 'resolver',
            'reason' => 'mixed_kinds',
            'kinds'  => $uniqueKinds,
            'hint'   => 'A single route cannot mix resolver kinds — side-effect kinds (oauth-start, oauth-callback) short-circuit the render, data resolvers feed it. Split into separate routes (one route per kind).',
        ];
    }

    // Phase 3 — flat-namespace collision detection (data resolvers only).
    // Locked decision (BETA8_MULTI_RESOLVER.md #4): when two data
    // resolvers in the same route expose a key with the same name, the
    // save is REJECTED. Authors disambiguate by renaming, OR by accessing
    // the colliding values through the always-available namespaced form
    // ($r0.title / $r1.title) and removing the offending flat expose
    // from one resolver. Skip for non-data kinds — OAuth resolvers have
    // no expose field.
    $isAllData = (count($uniqueKinds) === 1 && $uniqueKinds[0] === 'data');
    if ($isAllData) {
        $exposeKeyToResolverIndex = [];
        foreach ($configs as $idx => $config) {
            if (!is_array($config)) continue;
            $expose = $config['expose'] ?? [];
            if (!is_array($expose)) continue;
            foreach ($expose as $name => $path) {
                if (!is_string($name) || $name === '') continue;
                if (isset($exposeKeyToResolverIndex[$name])) {
                    $otherIdx = $exposeKeyToResolverIndex[$name];
                    $errors[] = [
                        'field'         => "resolver[{$idx}].expose.{$name}",
                        'reason'        => 'collision',
                        'value'         => $name,
                        'collidesWith'  => "resolver[{$otherIdx}].expose.{$name}",
                        'resolverIndex' => $idx,
                        'hint'          => "Two resolvers expose '{$name}' to the flat template namespace. Rename one (e.g. {$name}Alt), OR use the namespaced form in your template (\$r{$idx}['{$name}'] / \$r{$otherIdx}['{$name}']) and remove the colliding flat exposure from one resolver.",
                    ];
                } else {
                    $exposeKeyToResolverIndex[$name] = $idx;
                }
            }
        }
    }

    return $errors;
}
