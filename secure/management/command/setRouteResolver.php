<?php
/**
 * setRouteResolver Command — Set / clear the server-side data resolver
 * for a route (beta.8 A2).
 *
 * @method POST
 * @route /management/setRouteResolver
 * @auth required (write permission)
 *
 * @param string      $route    Route path (e.g., 'products/:slug' or
 *                              'about'). Must exist in routes.php.
 * @param array|null  $resolver Resolver config block. When provided:
 *                              validated + persisted to the project's
 *                              data/route-resolvers.json sidecar. When
 *                              missing or null: clears any existing
 *                              entry for the route (idempotent — safe
 *                              to call on a route with no resolver).
 *
 * Resolver config shape:
 *   {
 *       "endpoint": "@apiId/endpointId",   required
 *       "inputs":   {"<name>": "<spec>"},  optional (param:/query:/session:/literal)
 *       "expose":   {"<var>": "<path>"},   optional (response dot-paths)
 *       "cacheTTL": 300,                   optional, seconds (Slice 4)
 *       "onMiss":   "render-empty"         optional (Slice 6)
 *   }
 *
 * Why one command for add + edit + clear instead of separate
 * addRouteResolver / editRouteResolver / deleteRouteResolver: the
 * sidecar storage is idempotent — set X means "this is now the state
 * of X" regardless of whether X had a value before. Splitting the
 * surface into three commands would force callers to track current
 * state. One idempotent command is simpler for the admin form (which
 * just submits the desired state) and for scripted callers.
 *
 * Route file integration: setRouteResolver does NOT touch the route's
 * .php / .json template files — only the sidecar. The route itself
 * must already exist (via addRoute) before a resolver can be attached.
 *
 * @return ApiResponse Created / updated / cleared result + the current
 *                     state.
 *
 * @example
 *   {"route":"products/:slug","resolver":{
 *       "endpoint":"@products-api/get-product",
 *       "inputs":{"id":"param:slug"},
 *       "expose":{"product":"data.product"}
 *   }}
 *   →  saves the resolver
 *
 *   {"route":"products/:slug"}
 *   →  clears the resolver (idempotent — no error if none was set)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';

// ============================================================================
// PARAMETER PARSING
// ============================================================================

$params = $trimParametersManagement->params();
$routePath = $params['route'] ?? null;
$resolverConfig = $params['resolver'] ?? null;

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

// ============================================================================
// ROUTE-EXISTS CHECK
// ============================================================================
// The resolver attaches to an existing route — addRoute creates the route
// + page files; this command only manages the sidecar. Refuse to attach
// resolvers to phantom routes so a stale config doesn't silently survive
// across renames / deletes.

if (!routeExists($routePath, ROUTES)) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Route '{$routePath}' does not exist — add it first via /management/addRoute.")
        ->withData(['route' => $routePath])
        ->send();
}

// ============================================================================
// CLEAR PATH (resolver === null / absent)
// ============================================================================

if ($resolverConfig === null) {
    $existed = getResolverForRoute($routePath) !== null;
    if (!deleteResolverForRoute($routePath)) {
        ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to write resolver sidecar after clear')
            ->send();
    }
    ApiResponse::create(200, $existed ? 'resolver.cleared' : 'resolver.unchanged')
        ->withMessage($existed
            ? "Resolver cleared for route '{$routePath}'"
            : "No resolver was attached to route '{$routePath}' (idempotent no-op)")
        ->withData([
            'route'    => $routePath,
            'resolver' => null,
            'cleared'  => $existed,
        ])
        ->send();
}

// ============================================================================
// SET PATH (resolver provided)
// ============================================================================

if (!is_array($resolverConfig)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('Resolver must be an object (or omitted/null to clear)')
        ->withErrors([['field' => 'resolver', 'reason' => 'invalid_type', 'expected' => 'object|null']])
        ->send();
}

$errors = validateResolverConfig($resolverConfig);
if (!empty($errors)) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage('Resolver config is invalid — see errors')
        ->withErrors($errors)
        ->send();
}

if (!setResolverForRoute($routePath, $resolverConfig)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write resolver sidecar')
        ->send();
}

$previouslyHad = getResolverForRoute($routePath); // re-read after save — same value back
ApiResponse::create(200, 'resolver.saved')
    ->withMessage("Resolver saved for route '{$routePath}'")
    ->withData([
        'route'    => $routePath,
        'resolver' => $resolverConfig,
    ])
    ->send();
