<?php
/**
 * deleteCookiePolicy Command — remove the generated cookie-policy page (the
 * route recorded in data/consent.json), clear the recorded route, and refresh
 * the banner so it no longer links to a dead page.
 *
 * @method POST
 * @route  /management/deleteCookiePolicy
 * @auth   required (write permission)
 *
 * No body — the route comes from consent.json (single source of truth, so the
 * admin can't delete an unrelated route by accident). Deletes the leaf route +
 * its page files; leaves any parent routes intact.
 *
 * @return ApiResponse 200 { route, routeDeleted }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

// This command only removes content: neither the size cap nor the over-quota rule refuses
// its writes, even when a re-save makes a file larger (qs_small_write_removal_only()).
qs_small_write_removal_only();

$cfg = loadConsentConfig();
$route = $cfg['policyRoute'] ?? null;
if (!is_string($route) || trim($route, '/') === '') {
    ApiResponse::create(400, 'consent.no_policy_route')
        ->withMessage('No cookie-policy route is configured for this project')
        ->send();
}

$routeTrim = trim($route, '/');
$segments = array_values(array_filter(explode('/', $routeTrim), fn($s) => $s !== ''));
$routeDeleted = false;

if (consentRouteExists($routeTrim)) {
    $oldRoutes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
    $newRoutes = _dcp_removeRoute($segments, $oldRoutes);
    $name = end($segments);
    $jsonDir = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments);
    $phpDir  = PROJECT_PATH . '/templates/pages/' . implode('/', $segments);
    $routesContent = '<?php return ' . varExportNested($newRoutes) . '; ?>';
    // Every write of this command passes the size check as it happens
    // (qs_small_write_guard). A removal, so none is refused (marked above).
    if ((defined('ROUTES_PATH') && !qs_small_write_guard(ROUTES_PATH, strlen($routesContent)))
        || !qs_small_write_guard($jsonDir . '/' . $name . '.json', null)
        || !qs_small_write_guard($phpDir . '/' . $name . '.php', null)) {
        qs_small_write_send_refusal();
    }
    if (defined('ROUTES_PATH')) {
        file_put_contents(ROUTES_PATH, $routesContent, LOCK_EX);
        qs_opcache_invalidate(ROUTES_PATH);
    }
    require_once SECURE_FOLDER_PATH . '/src/functions/projectPublicArtifacts.php';
    foreach (qs_public_artifact_targets('scripts/qs-route-schema.js') as $schema) {
        qs_small_write_keep($schema);
    }
    qs_emit_route_schema($newRoutes);
    // Delete the leaf page files, then prune now-empty folders.
    @unlink($jsonDir . '/' . $name . '.json');
    @unlink($phpDir . '/' . $name . '.php');
    @rmdir($jsonDir);
    @rmdir($phpDir);
    $routeDeleted = true;
}

// Forget the route + drop the banner's policy link (regenerate without it).
saveConsentConfig(array_merge($cfg, ['policyRoute' => null]));
qs_small_write_send_refusal();
if (file_exists(consentBannerPath())) {
    $banner = buildConsentBannerStructure(null);
    $bannerFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (!qs_small_write_guard(consentBannerPath(), qs_json_size($banner, $bannerFlags, "\n"))) {
        qs_small_write_send_refusal();
    }
    qs_json_write(consentBannerPath(), $banner, $bannerFlags, LOCK_EX, "\n");
}

ApiResponse::create(200, 'success')
    ->withMessage($routeDeleted ? "Cookie-policy page deleted ($route)" : "Cleared stale cookie-policy route ($route)")
    ->withData(['route' => $route, 'routeDeleted' => $routeDeleted])
    ->send();

/** Remove a route (by segment path) from the nested ROUTES structure. */
function _dcp_removeRoute(array $segments, array $routes): array {
    if (count($segments) === 1) {
        unset($routes[$segments[0]]);
        return $routes;
    }
    $head = array_shift($segments);
    if (isset($routes[$head]) && is_array($routes[$head])) {
        $routes[$head] = _dcp_removeRoute($segments, $routes[$head]);
    }
    return $routes;
}
