<?php
/**
 * deletePrivacyPolicy Command — remove the generated privacy-policy page (the
 * route recorded in data/privacy.json) and clear the recorded route.
 *
 * @method POST
 * @route  /management/deletePrivacyPolicy
 * @auth   required (write permission)
 *
 * No body — the route comes from privacy.json (single source of truth, so an
 * unrelated route can't be deleted by accident). Deletes the leaf route + its
 * page files; parent routes stay.
 *
 * @return ApiResponse 200 { route, routeDeleted } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/policyPageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php'; // consentRouteExists

$reg = loadPrivacyRegistry();
$route = $reg['privacyRoute'] ?? null;
if (!is_string($route) || trim($route, '/') === '') {
    ApiResponse::create(400, 'privacy.no_policy_route')
        ->withMessage('No privacy-policy route is configured for this project')
        ->send();
}

$routeTrim = trim($route, '/');
$segments = array_values(array_filter(explode('/', $routeTrim), fn($s) => $s !== ''));
$routeDeleted = false;

if (consentRouteExists($routeTrim)) {
    policyDeleteRoute($segments);
    $routeDeleted = true;
}

$reg['privacyRoute'] = null;
savePrivacyRegistry($reg);

ApiResponse::create(200, 'success')
    ->withMessage($routeDeleted ? "Privacy-policy page deleted ($route)" : "Cleared stale privacy-policy route ($route)")
    ->withData(['route' => $route, 'routeDeleted' => $routeDeleted])
    ->send();
