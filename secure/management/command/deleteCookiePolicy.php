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
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';

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
    $newRoutes = _dcp_removeRoute($segments, defined('ROUTES') && is_array(ROUTES) ? ROUTES : []);
    if (defined('ROUTES_PATH')) {
        file_put_contents(ROUTES_PATH, '<?php return ' . varExportNested($newRoutes) . '; ?>', LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(ROUTES_PATH, true);
        }
    }
    if (function_exists('writeRoutesMetaFile')) {
        writeRoutesMetaFile($newRoutes, PUBLIC_CONTENT_PATH . '/scripts/qs-route-schema.js');
    }
    // Delete the leaf page files, then prune now-empty folders.
    $name = end($segments);
    $jsonDir = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments);
    $phpDir  = PROJECT_PATH . '/templates/pages/' . implode('/', $segments);
    @unlink($jsonDir . '/' . $name . '.json');
    @unlink($phpDir . '/' . $name . '.php');
    @rmdir($jsonDir);
    @rmdir($phpDir);
    $routeDeleted = true;
}

// Forget the route + drop the banner's policy link (regenerate without it).
saveConsentConfig(array_merge($cfg, ['policyRoute' => null]));
if (file_exists(consentBannerPath())) {
    $banner = buildConsentBannerStructure(null);
    file_put_contents(
        consentBannerPath(),
        json_encode($banner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        LOCK_EX
    );
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
