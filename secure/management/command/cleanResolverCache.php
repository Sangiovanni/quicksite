<?php
/**
 * cleanResolverCache Command — Manual cleanup for the server-side
 * data-resolver response cache (beta.8 A2 Slice 4).
 *
 * @method POST
 * @route /management/cleanResolverCache
 * @auth required (write permission)
 *
 * @param bool        $all              Optional. true = delete every
 *                                      cached entry regardless of expiry.
 *                                      Defaults to false (housekeeping
 *                                      mode — expired entries only).
 * @param int|null    $before           Optional unix timestamp. When set,
 *                                      deletes every entry with
 *                                      stored_at < this timestamp,
 *                                      regardless of expiry. Useful for
 *                                      "drop everything older than X
 *                                      hours" sweeps.
 * @param string|null $apiId            Optional. When set, deletes every
 *                                      entry belonging to that API
 *                                      (@apiId/* endpoints). Combine
 *                                      with `all=false` (default) to
 *                                      target one API's cache only.
 * @param string|null $endpoint         Optional. Exact endpoint ref
 *                                      (@apiId/endpointId) to clear.
 *                                      Most surgical option.
 *
 * Mirrors cleanBuilds's surface (no params = housekeeping; richer params
 * for targeted sweeps). Mutually-exclusive priority order:
 *   1. endpoint (most specific)
 *   2. apiId
 *   3. before / all
 *   4. (no params) → expired-only housekeeping
 *
 * @return ApiResponse {deleted: int, mode: string}
 *
 * @example
 *   {}                                 → housekeeping (expired-only)
 *   {"all": true}                      → nuclear (drop everything)
 *   {"before": 1717900000}             → drop entries stored before timestamp
 *   {"apiId": "auth-api"}              → drop all entries for an API
 *   {"endpoint": "@auth-api/me"}       → drop all entries for one endpoint
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/resolverCache.php';

$params = $trimParametersManagement->params();

$endpointRef = $params['endpoint'] ?? null;
$apiId       = $params['apiId'] ?? null;
$before      = $params['before'] ?? null;
$all         = filter_var($params['all'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($endpointRef !== null && is_string($endpointRef) && $endpointRef !== '') {
    $deleted = clearResolverCacheForEndpoint($endpointRef);
    ApiResponse::create(200, 'cache.cleared')
        ->withMessage("Resolver cache cleared for endpoint {$endpointRef}")
        ->withData(['deleted' => $deleted, 'mode' => 'endpoint', 'endpoint' => $endpointRef])
        ->send();
}

if ($apiId !== null && is_string($apiId) && $apiId !== '') {
    $deleted = clearResolverCacheForApi($apiId);
    ApiResponse::create(200, 'cache.cleared')
        ->withMessage("Resolver cache cleared for API {$apiId}")
        ->withData(['deleted' => $deleted, 'mode' => 'api', 'apiId' => $apiId])
        ->send();
}

if ($all) {
    $deleted = clearAllResolverCache();
    ApiResponse::create(200, 'cache.cleared')
        ->withMessage("Resolver cache cleared (all {$deleted} entries)")
        ->withData(['deleted' => $deleted, 'mode' => 'all'])
        ->send();
}

if ($before !== null) {
    $beforeTs = (int) $before;
    if ($beforeTs <= 0) {
        ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Parameter 'before' must be a positive unix timestamp")
            ->withErrors([['field' => 'before', 'value' => $before, 'expected' => 'positive integer (unix timestamp)']])
            ->send();
    }
    $deleted = sweepResolverCache($beforeTs);
    ApiResponse::create(200, 'cache.cleared')
        ->withMessage("Resolver cache: {$deleted} entries stored before " . date('c', $beforeTs) . ' deleted')
        ->withData(['deleted' => $deleted, 'mode' => 'before', 'before' => $beforeTs])
        ->send();
}

// Default: housekeeping pass — sweep expired entries only.
$deleted = sweepResolverCache(null);
ApiResponse::create(200, 'cache.cleared')
    ->withMessage("Resolver cache housekeeping: {$deleted} expired entries deleted")
    ->withData(['deleted' => $deleted, 'mode' => 'expired'])
    ->send();
