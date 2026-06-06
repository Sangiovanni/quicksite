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
    $json = json_encode($resolvers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function getResolverForRoute(string $routePath): ?array {
    $all = loadResolversSidecar();
    return $all[$routePath] ?? null;
}

/**
 * Idempotent set/clear. Passing $config = null removes the entry.
 */
function setResolverForRoute(string $routePath, ?array $config): bool {
    $all = loadResolversSidecar();
    if ($config === null) {
        if (!array_key_exists($routePath, $all)) {
            return true; // already absent — nothing to do
        }
        unset($all[$routePath]);
    } else {
        $all[$routePath] = $config;
    }
    return saveResolversSidecar($all);
}

function deleteResolverForRoute(string $routePath): bool {
    return setResolverForRoute($routePath, null);
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
function validateResolverConfig(array $config, ?ApiEndpointManager $apiManager = null): array {
    $errors = [];
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
                if (!is_string($sourceSpec)) {
                    $errors[] = [
                        'field'    => "resolver.inputs.{$name}",
                        'reason'   => 'invalid_type',
                        'expected' => 'string',
                        'hint'     => "Use a source spec like 'param:slug' / 'query:lang' / 'session:userId', or a bare literal.",
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

    // onMiss — optional string (Slice 6 enumerates valid values; just validate shape for now).
    if (array_key_exists('onMiss', $config)) {
        if (!is_string($config['onMiss']) || $config['onMiss'] === '') {
            $errors[] = [
                'field'    => 'resolver.onMiss',
                'reason'   => 'invalid_type',
                'expected' => "string (e.g. 'render-empty')",
            ];
        }
    }

    return $errors;
}
