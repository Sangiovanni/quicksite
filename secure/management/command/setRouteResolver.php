<?php
/**
 * setRouteResolver Command — Set / clear / patch server-side data
 * resolver(s) for a route (beta.8 A2 + Slice 7.5).
 *
 * @method POST
 * @route /management/setRouteResolver
 * @auth required (write permission)
 *
 * Body shapes (locked in BETA8_MULTI_RESOLVER.md decision #7):
 *
 *   1. {route, resolver}                     → REPLACE whole entry with
 *                                              ONE resolver (scalar shape
 *                                              on disk). Backward compat
 *                                              for single-resolver callers.
 *   2. {route, resolver: [c0, c1, ...]}      → REPLACE whole entry with
 *                                              an array of resolvers
 *                                              (array shape on disk).
 *   3. {route, resolver, index: N}           → PATCH the resolver at
 *                                              index N. 0 ≤ N < length.
 *                                              Other indices preserved.
 *   4. {route, resolver, index: <length>}    → APPEND at end of array.
 *                                              N must equal current length.
 *   5. {route, index: N}    (no resolver)    → REMOVE the resolver at
 *                                              index N (array shrinks).
 *   6. {route}              (no resolver,    → CLEAR all resolvers for
 *                            no index)         the route.
 *
 * Invalid combinations rejected loudly:
 *   - resolver as array + index           → ambiguous (replace-all vs slot)
 *   - index out of range for patch/remove → 400 with currentLength shown
 *   - index > length for append           → 400 (append must equal length)
 *
 * Validation: validateResolverConfigs runs on the final target array
 * (handles both single- and multi-element cases identically, plus the
 * flat-namespace expose-key collision check that's only meaningful for
 * multi-resolver routes).
 *
 * @param string             $route     Route path (e.g., 'book/:id'). Must
 *                                       exist in routes.php.
 * @param array|object|null  $resolver  Single resolver object OR array of
 *                                       resolver objects, OR null/omitted
 *                                       (combine with index for remove;
 *                                       alone for clear-all).
 * @param int|null           $index     Optional. Targets a specific slot
 *                                       in the array shape for patch /
 *                                       append / remove operations.
 *
 * Resolver config shape (per BETA8_DATA_RESOLVER.md):
 *   {
 *       "endpoint": "@apiId/endpointId",   required
 *       "inputs":   {"<name>": "<spec>"},  optional (param:/query:/session:/literal)
 *       "expose":   {"<var>": "<path>"},   optional (response dot-paths)
 *       "cacheTTL": 300,                   optional, seconds (Slice 4)
 *       "onMiss":   "render-empty"         optional (Slice 6)
 *   }
 *
 * Why one idempotent command instead of separate add/edit/delete:
 * the sidecar storage is idempotent — "set X" means "this is now the
 * state of X" regardless of prior state. The scalar/array dual shape
 * + the `index` param fold the common multi-resolver operations
 * (patch, append, remove single) into the same surface without
 * forcing callers to read-then-write the whole array.
 *
 * Route file integration: setRouteResolver does NOT touch the route's
 * .php / .json template files — only the sidecar. The route itself
 * must already exist (via addRoute) before resolvers can be attached.
 *
 * @return ApiResponse Created / updated / cleared result + the current
 *                     state. `data.resolvers` is ALWAYS the resulting
 *                     array (1-element when scalar shape, N-element
 *                     when array shape). Empty array when cleared.
 *
 * @example
 *   {"route":"book/:id","resolver":[
 *       {"endpoint":"@books-api/get-book", "inputs":{"id":"param:id"},
 *        "expose":{"book":"data"}, "cacheTTL":3600},
 *       {"endpoint":"@books-api/get-content", "inputs":{"id":"param:id"},
 *        "expose":{"chapters":"data.chapters"}, "cacheTTL":60}
 *   ]}
 *   →  saves both resolvers (parallel execution per Slice 7.5.C)
 *
 *   {"route":"book/:id","resolver":{"endpoint":"@books-api/get-book",...},
 *    "index":0}
 *   →  patches just the first resolver, leaves index 1 alone
 *
 *   {"route":"book/:id","index":1}
 *   →  removes index 1, array shrinks (now single-element → written scalar)
 *
 *   {"route":"book/:id"}
 *   →  clears all resolvers for the route
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';

// ============================================================================
// PARAMETER PARSING
// ============================================================================

$params = $trimParametersManagement->params();
$routePath      = $params['route']    ?? null;
$resolverInput  = $params['resolver'] ?? null;
$index          = $params['index']    ?? null;

// Empty array / object payload normalised to null (clear). PHP's
// json_decode($_, true) collapses both `{}` and `[]` into PHP `[]`, so
// authoring an empty body shape via either JSON form lands here. The
// semantics match the locked idempotent contract — "no resolvers" is
// the same intent whether expressed as null, omitted, or empty.
if (is_array($resolverInput) && empty($resolverInput)) {
    $resolverInput = null;
}

// Coerce numeric route to string (consistent with addRoute / deleteRoute).
if (is_int($routePath) || is_float($routePath)) {
    $routePath = (string) $routePath;
}

if (!is_string($routePath) || $routePath === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Route path is required')
        ->withErrors([['field' => 'route', 'reason' => 'missing']])
        ->send();
}

// Normalize path — strip leading/trailing slashes, convert backslashes.
$routePath = trim(str_replace('\\', '/', $routePath), '/');

// Index validation. When present, must be a non-negative integer. Range
// validation happens later once we know whether it targets an existing
// slot, an append slot, or a remove slot.
if ($index !== null) {
    if (!is_int($index) || $index < 0) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('Index must be a non-negative integer')
            ->withErrors([[
                'field'    => 'index',
                'reason'   => 'invalid_type',
                'expected' => 'non-negative integer',
            ]])
            ->send();
    }
}

// ============================================================================
// ROUTE-EXISTS CHECK
// ============================================================================

if (!routeExists($routePath, ROUTES)) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Route '{$routePath}' does not exist — add it first via /management/addRoute.")
        ->withData(['route' => $routePath])
        ->send();
}

// ============================================================================
// CLEAR-ALL PATH (no resolver, no index)
// ============================================================================

if ($resolverInput === null && $index === null) {
    $existed = !empty(getResolversForRoute($routePath));
    if (!setResolversForRoute($routePath, null)) {
        ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to write resolver sidecar after clear')
            ->send();
    }
    ApiResponse::create(200, $existed ? 'resolver.cleared' : 'resolver.unchanged')
        ->withMessage($existed
            ? "All resolvers cleared for route '{$routePath}'"
            : "No resolver was attached to route '{$routePath}' (idempotent no-op)")
        ->withData([
            'route'     => $routePath,
            'resolvers' => [],
            'cleared'   => $existed,
        ])
        ->send();
}

// ============================================================================
// REMOVE-AT-INDEX PATH (index present, no resolver)
// ============================================================================

if ($resolverInput === null && $index !== null) {
    $existing = getResolversForRoute($routePath);
    if ($index >= count($existing)) {
        ApiResponse::create(400, 'resolver.index_out_of_range')
            ->withMessage("No resolver at index {$index} for route '{$routePath}'")
            ->withData([
                'route'         => $routePath,
                'index'         => $index,
                'currentLength' => count($existing),
            ])
            ->send();
    }
    array_splice($existing, $index, 1);
    if (!setResolversForRoute($routePath, $existing)) {
        ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to write resolver sidecar after index remove')
            ->send();
    }
    ApiResponse::create(200, 'resolver.removed')
        ->withMessage("Resolver at index {$index} removed for route '{$routePath}'")
        ->withData([
            'route'         => $routePath,
            'resolvers'     => $existing,
            'removedIndex'  => $index,
        ])
        ->send();
}

// ============================================================================
// SET PATH (resolver provided)
// ============================================================================

if (!is_array($resolverInput)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('Resolver must be an object, an array of objects, or omitted/null to clear')
        ->withErrors([[
            'field'    => 'resolver',
            'reason'   => 'invalid_type',
            'expected' => 'object|array|null',
        ]])
        ->send();
}

// Distinguish single-config object from array-of-configs. Empty arrays
// would be ambiguous (both shapes accept empty); the shape-detector
// returns false for empty so they fall into the scalar branch and
// validateResolverConfigs rejects them as missing endpoint.
$isArrayShape = _isResolverArrayShape($resolverInput);

if ($isArrayShape && $index !== null) {
    // Array shape replaces the WHOLE entry; an index targets one slot.
    // Combining the two is ambiguous (caller's intent unclear) — refuse.
    ApiResponse::create(400, 'validation.conflict')
        ->withMessage('Cannot combine an array resolver with index — index targets a single slot, array replaces the whole entry')
        ->withErrors([[
            'field'   => 'index',
            'reason'  => 'invalid_combination',
            'hint'    => 'Either pass a single resolver object + index (patch one slot), OR an array of resolver objects with no index (replace whole entry).',
        ]])
        ->send();
}

// ============================================================================
// BUILD TARGET ARRAY
// ============================================================================
// Three modes lead here:
//   - array + no index → replace whole entry with the array
//   - scalar + no index → replace whole entry with a 1-element array
//   - scalar + index → patch slot N (or append when N == length)

$existing = getResolversForRoute($routePath);
$mode = null;
$targetConfigs = null;

if ($isArrayShape) {
    $mode = 'replace_all_array';
    $targetConfigs = array_values($resolverInput);
} elseif ($index === null) {
    $mode = 'replace_all_scalar';
    $targetConfigs = [$resolverInput];
} else {
    // Patch or append. Append requires index === count($existing).
    if ($index > count($existing)) {
        ApiResponse::create(400, 'resolver.index_out_of_range')
            ->withMessage("Index {$index} is out of range — current length is " . count($existing) . " (use 0.." . (count($existing) - 1) . " to patch an existing slot, " . count($existing) . " to append).")
            ->withData([
                'route'         => $routePath,
                'index'         => $index,
                'currentLength' => count($existing),
            ])
            ->send();
    }
    $mode = ($index === count($existing)) ? 'append' : 'patch';
    $existing[$index] = $resolverInput;
    $targetConfigs = array_values($existing);
}

// ============================================================================
// VALIDATE FINAL TARGET
// ============================================================================
// validateResolverConfigs handles both single- and multi-resolver arrays:
// runs per-config validation (with field paths re-pathed to
// resolver[N].x.y) AND the cross-resolver flat-namespace expose-key
// collision check.

$errors = validateResolverConfigs($targetConfigs);
if (!empty($errors)) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage('Resolver config is invalid — see errors')
        ->withErrors($errors)
        ->send();
}

// ============================================================================
// WRITE
// ============================================================================
// setResolversForRoute picks the on-disk shape: scalar when length 1
// (back-compat), array when length > 1.

if (!setResolversForRoute($routePath, $targetConfigs)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write resolver sidecar')
        ->send();
}

ApiResponse::create(200, 'resolver.saved')
    ->withMessage("Resolver saved for route '{$routePath}' ({$mode}, " . count($targetConfigs) . " resolver" . (count($targetConfigs) === 1 ? '' : 's') . ")")
    ->withData([
        'route'     => $routePath,
        'resolvers' => $targetConfigs,
        'mode'      => $mode,
        'index'     => $index,
    ])
    ->send();
