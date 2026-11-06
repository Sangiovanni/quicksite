<?php
/**
 * DataResolver — Per-route server-side data resolver.
 *
 * Bridges a route's `resolver` config (declared in routes.php) with the
 * server-side fetch (serverFetch.php), then maps the response into
 * template-scope variables for the renderer to consume.
 *
 *
 * Lifecycle position (by design, DESIGN_DECISIONS.md "Resolver lifecycle position"):
 *
 *   AFTER the auth gate (yes/no decision) but BEFORE template render.
 *   The gate is hard-wired framework middleware; the resolver is the
 *   user-configurable data-fetch layer that runs once per request.
 *   See "Auth gate vs auth data" in the design doc — the distinction is
 *   architectural, not just an ordering convention.
 *
 *
 * Resolver config shape (from routes.php):
 *
 *   'resolver' => [
 *       'endpoint' => '@products-api/get-product',  // registry ref
 *       'inputs'   => [
 *           // endpoint placeholder/body input → source spec
 *           'id'        => 'param:slug',     // from QS.routeParams
 *           'lang'      => 'query:lang',     // from URL query string
 *           'userId'    => 'session:userId', // accepted; reads as nothing (below)
 *           'tag'       => 'featured',       // literal value
 *       ],
 *       'expose'   => [
 *           // template variable name → response dot-path
 *           'product' => 'data.product',
 *           'related' => 'data.related',
 *       ],
 *       'cacheTTL' => 300,        // optional, seconds
 *       'onMiss'   => 'render-empty',  // optional fallback
 *   ]
 *
 *
 * Source-spec resolution:
 *
 *   Inputs accept the same prefixed-source convention used by client-side
 *   state stores' `init` — `param:NAME`,
 *   `query:NAME`, `session:NAME`, plus bare literals. A `session:` source is
 *   accepted and always reads as nothing, as the runtime gives a resolver no
 *   session (resolverRuntime.php). A missing source resolves to `null`; the resolver lets that propagate to serverFetch
 *   (which leaves missing path placeholders literal so the upstream 404
 *   surfaces the misconfig visibly) rather than silently substituting
 *   defaults.
 *
 *
 * Not done here:
 *
 *   - Caching (serverFetch caches, from the cacheTTL forwarded below)
 *   - Hydration handoff to the client
 *   - Session population: the runtime passes an empty session, so nothing
 *     reads one.
 */

require_once __DIR__ . '/../functions/serverFetch.php';

class DataResolver {

    /**
     * Resolve a single input source spec. Mirrors the client-side
     * state-store init conventions:
     *
     *   'param:slug'    → $context['routeParams']['slug']
     *   'query:lang'    → $context['query']['lang']
     *   'session:userId'→ $context['session']['userId'], empty from the runtime
     *   'red-vase'      → literal 'red-vase' (no recognised prefix)
     *
     * Returns null for missing values so the caller can drop the input
     * from the request entirely instead of sending an empty placeholder
     * that the upstream might interpret as "match all" / "use default".
     *
     * @param string $spec
     * @param array  $context
     * @return mixed|null
     */
    private function resolveSource(string $spec, array $context) {
        $colon = strpos($spec, ':');
        if ($colon === false) {
            return $spec; // bare literal
        }
        $prefix = substr($spec, 0, $colon);
        $key    = substr($spec, $colon + 1);

        switch ($prefix) {
            case 'param':
                $v = $context['routeParams'][$key] ?? null;
                return ($v === null || $v === '') ? null : $v;
            case 'query':
                $v = $context['query'][$key] ?? null;
                return ($v === null || $v === '') ? null : $v;
            case 'session':
                $v = $context['session'][$key] ?? null;
                return ($v === null || $v === '') ? null : $v;
            default:
                // Unrecognised prefix → treat the whole thing as a literal.
                // Lets `'http://example.com/static'` work as a literal URL
                // without escaping the colon, while still allowing future
                // prefixes (header:X, env:Y) to slot in without breaking
                // existing configs.
                return $spec;
        }
    }

    /**
     * Read a dot-notation path from a nested array. 'data.product.name'
     * walks $arr['data']['product']['name'].
     *
     * Returns null when any segment is missing or hits a non-array — that
     * matches what the template renderer reads when a variable is
     * unbound, so a missing path quietly produces a missing variable
     * (the onMiss fallback path, when configured, takes it from there).
     *
     * @param mixed  $data
     * @param string $path
     * @return mixed|null
     */
    private function readDotPath($data, string $path) {
        if ($path === '') return $data;
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    /**
     * Resolve an array of resolver configs concurrently via
     * serverFetchMulti, with per-resolver `onMiss` semantics. A route's
     * resolvers go through here however many it has: a single resolver
     * is a 1-element array.
     *
     * Return shape:
     *   [
     *       'ok'              => bool,    // false ONLY if any resolver failed
     *                                     // AND wasn't covered by onMiss
     *       'exposed'         => array,   // FLAT namespace — merged
     *                                     // across all resolvers (collisions
     *                                     // are rejected at save time, so
     *                                     // this merge is collision-free at
     *                                     // runtime).
     *       'exposedByIndex'  => array,   // [resolverIdx => [varName => value]]
     *                                     // — for the namespaced address
     *                                     // ($r0, $r1, ...) fallback the
     *                                     // template can use to disambiguate
     *                                     // if author skipped flat exposure.
     *       'firstError'      => array|null, // when ok=false, the FIRST
     *                                     // resolver failure that wasn't
     *                                     // covered by onMiss. qs_resolve_route_data()
     *                                     // uses this to drive the 404/500 path.
     *                                     // {error, status, resolverIndex}
     *   ]
     *
     * Failure semantics (by design, DESIGN_DECISIONS.md "Failure handling —
     * per-resolver `onMiss`, page-level short-circuit"):
     *   - Per-resolver onMiss applies independently to its exposed vars.
     *   - Any resolver failure WITHOUT onMiss='render-empty' short-circuits
     *     the whole page (ok=false; caller emits 404/500 with firstError).
     *   - onMiss='render-empty' on a failed resolver: its exposed vars
     *     become null; the page renders.
     *
     * @param array $configs Array of resolver config blocks (from
     *                       getResolversForRoute, normalised — always array).
     *                       Required keys: 'endpoint', 'expose'.
     *                       Optional keys: 'inputs', 'cacheTTL', 'onMiss'.
     * @param array $context Request-time context:
     *                         'routeParams' => array  URL path params from
     *                                          TrimParameters (e.g. ['slug' => 'red-vase'])
     *                         'query'       => array  $_GET equivalent
     *                         'session'     => array  ['token' => ..., 'userId' => ..., ...]
     *                         'cookieHeader'=> string the request's Cookie header
     *                                          (serverFetch decides what of it leaves)
     *                         'apiSecrets'  => array  test injection (default: read from disk)
     * @return array         See "Return shape" above.
     */
    public function resolveMany(array $configs, array $context = []): array {
        if (empty($configs)) {
            return [
                'ok'             => true,
                'exposed'        => [],
                'exposedByIndex' => [],
                'firstError'     => null,
            ];
        }

        // Build serverFetchMulti specs per resolver. Specs index in the
        // SAME ORDER as $configs so we can map results back. Malformed
        // configs (missing endpoint — should be caught by the validator
        // at save time; defensive here) get a synthetic error result
        // and their slot stays null in the specs array.
        $specs = [];
        $synthErrors = [];   // idx => synthetic error result
        foreach ($configs as $idx => $config) {
            if (!is_array($config) || empty($config['endpoint'])) {
                $synthErrors[$idx] = [
                    'ok'     => false,
                    'status' => 0,
                    'data'   => null,
                    'error'  => 'Resolver config missing required field: endpoint',
                ];
                continue;
            }

            // Resolve each input's source spec. null values are dropped so
            // they don't override the upstream's defaults.
            $resolvedInputs = [];
            foreach (($config['inputs'] ?? []) as $inputName => $sourceSpec) {
                $value = $this->resolveSource((string) $sourceSpec, $context);
                if ($value !== null) {
                    $resolvedInputs[$inputName] = $value;
                }
            }

            // Per-resolver context overrides (only cacheTTL today).
            $perResolverContext = [];
            if (isset($config['cacheTTL']) && is_int($config['cacheTTL']) && $config['cacheTTL'] > 0) {
                $perResolverContext['cacheTTL'] = $config['cacheTTL'];
            }

            $specs[$idx] = [
                'endpointRef' => (string) $config['endpoint'],
                'inputs'      => $resolvedInputs,
                'context'     => $perResolverContext,
            ];
        }

        // Run multi-fetch on the valid specs. We index by config slot,
        // serverFetchMulti returns a dense array — re-map by collecting
        // its results into a sparse one keyed by the original index.
        $validIndices = array_keys($specs);
        $multiResults = !empty($validIndices)
            ? serverFetchMulti(array_values($specs), $context)
            : [];

        $fetchByIdx = [];
        foreach ($validIndices as $j => $origIdx) {
            $fetchByIdx[$origIdx] = $multiResults[$j];
        }
        foreach ($synthErrors as $origIdx => $err) {
            $fetchByIdx[$origIdx] = $err;
        }

        // Build the flat + indexed exposed namespaces, plus track first
        // unrecovered failure for the caller's error path.
        $exposed        = [];
        $exposedByIndex = [];
        $allHandled     = true;
        $firstError     = null;

        foreach ($configs as $idx => $config) {
            $fetchResult     = $fetchByIdx[$idx];
            $resolverExposed = [];

            if (!empty($fetchResult['ok'])) {
                foreach (($config['expose'] ?? []) as $varName => $responsePath) {
                    $resolverExposed[(string) $varName] = $this->readDotPath(
                        $fetchResult['data'],
                        (string) $responsePath
                    );
                }
            } else {
                $onMiss = $config['onMiss'] ?? null;
                if ($onMiss === 'render-empty') {
                    // Render-empty — expose null vars; page still renders.
                    foreach (($config['expose'] ?? []) as $varName => $_path) {
                        $resolverExposed[(string) $varName] = null;
                    }
                } else {
                    // Unrecovered failure — record + flag for caller.
                    if ($firstError === null) {
                        $firstError = [
                            'error'         => $fetchResult['error'] ?? 'fetch failed',
                            'status'        => $fetchResult['status'] ?? 0,
                            'resolverIndex' => $idx,
                        ];
                    }
                    $allHandled = false;
                }
            }

            // Merge into flat namespace (collision-free by save-time validation).
            foreach ($resolverExposed as $k => $v) {
                $exposed[$k] = $v;
            }
            $exposedByIndex[$idx] = $resolverExposed;
        }

        return [
            'ok'             => $allHandled,
            'exposed'        => $exposed,
            'exposedByIndex' => $exposedByIndex,
            'firstError'     => $firstError,
        ];
    }
}
