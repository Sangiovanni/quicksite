<?php
/**
 * Route resolvers — the READ half, and the only half a served page needs.
 *
 * `resolverHelpers.php` is 840 lines, and nearly all of it is AUTHORING:
 * validating a resolver config against the API registry, generating sample
 * values from a JSON schema, checking the flat-namespace collision rule. A
 * request that renders a page uses two things — "what resolvers does this route
 * have?" and the per-request stash of what they resolved to — and neither needs
 * an `ApiEndpointManager`.
 *
 * WHY THE SPLIT EXISTS. Same reason as apiRegistry.php: a production build
 * carries the runtime that serves its pages and nothing else. Both surfaces run
 * the SAME lookup code; only the install carries the half that validates and
 * writes.
 *
 * `resolverHelpers.php` requires this file, so `setResolversForRoute` and the
 * validators keep working unchanged and there is one definition of the sidecar
 * shape in the tree.
 *
 * Sidecar shape — `data/route-resolvers.json`, keyed by routes.php-style path:
 *
 *   {"<routePath>": {"endpoint": "@apiId/endpointId", "inputs": {...},
 *                    "expose": {...}, "cacheTTL": 300, "onMiss": "render-empty"}}
 *
 * A route's entry is a single config (scalar shape) or a list of them (array
 * shape); `qs_resolvers_for_route()` flattens both to a list so callers never
 * branch on it.
 *
 * ⚠ NOTHING HERE WRITES.
 */

if (!function_exists('qs_resolver_sidecar_path')) {
    /** Where this project's resolver sidecar lives. */
    function qs_resolver_sidecar_path(?string $projectPath = null): string
    {
        return ($projectPath ?? PROJECT_PATH) . '/data/route-resolvers.json';
    }
}

if (!function_exists('qs_resolvers_load_sidecar')) {
    /**
     * Every resolver in the project, or an empty array. Resolvers are fully
     * optional — most routes have none — so a missing file is normal, not an
     * error.
     *
     * @param string|null $projectPath Another project's sidecar; defaults to the active one.
     */
    function qs_resolvers_load_sidecar(?string $projectPath = null): array
    {
        $path = qs_resolver_sidecar_path($projectPath);
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
}

if (!function_exists('qs_resolver_is_array_shape')) {
    /**
     * Is this sidecar entry the LIST shape (several resolvers) rather than a
     * single config?
     *
     * ⚠ NOT array_is_list(). That answers TRUE for an empty array; this answers
     * FALSE, because an empty entry means "no resolver", not "an empty list of
     * resolvers". Swapping one for the other sends an empty entry down the
     * list branch.
     */
    function qs_resolver_is_array_shape(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

if (!function_exists('qs_resolver_normalize_entry')) {
    /**
     * Normalise either sidecar shape to a list of configs. Malformed input
     * gives an empty list, which downstream code reads as "no resolver".
     *
     * @param mixed $entry
     */
    function qs_resolver_normalize_entry($entry): array
    {
        if (!is_array($entry) || empty($entry)) {
            return [];
        }
        if (qs_resolver_is_array_shape($entry)) {
            // Drop malformed members so callers can iterate without checking.
            return array_values(array_filter($entry, 'is_array'));
        }
        return [$entry];
    }
}

if (!function_exists('qs_resolvers_for_route')) {
    /**
     * Every resolver configured for one route, as a list. Empty when none.
     *
     * The canonical accessor: single- and multi-resolver routes look identical
     * from here, which is what lets DataResolver::resolveMany handle both
     * without branching.
     */
    function qs_resolvers_for_route(string $routePath, ?string $projectPath = null): array
    {
        $all = qs_resolvers_load_sidecar($projectPath);
        return qs_resolver_normalize_entry($all[$routePath] ?? null);
    }
}

/**
 * ---------------------------------------------------------------------------
 * The per-request stash of resolved template variables.
 * ---------------------------------------------------------------------------
 *
 * Written once, after the resolvers fire for the matched route; read by the
 * renderer's and the compiled page's `{{resolved:NAME}}` substitution, by the
 * hydration handoff, and by any page template that wants the values in PHP
 * scope.
 *
 * A global is deliberate: the request lifecycle is process-local, the stash
 * never crosses requests, and threading it through three layers of constructor
 * options buys nothing.
 */

if (!function_exists('qs_set_resolved_vars')) {
    function qs_set_resolved_vars(array $vars): void
    {
        $GLOBALS['__qs_resolved_vars'] = $vars;
    }
}

if (!function_exists('qs_get_resolved_vars')) {
    function qs_get_resolved_vars(): array
    {
        return $GLOBALS['__qs_resolved_vars'] ?? [];
    }
}
