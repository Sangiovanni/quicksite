<?php
/**
 * getStateStores - read per-page state-store definitions
 *
 * @method POST
 * @url /management/getStateStores
 * @auth required
 * @permission read
 *
 * Body (JSON): { "route": "home" }   // optional; omit for all routes
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/StateStoreManager.php';

function __command_getStateStores(array $params = [], array $urlParams = []): ApiResponse {
    $manager = new StateStoreManager();
    $route = $params['route'] ?? ($params['pageName'] ?? null);

    if ($route !== null && $route !== '') {
        $stores = $manager->getForRoute((string) $route);
    } else {
        $stores = $manager->loadAll();
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('State stores retrieved')
        ->withData(['route' => $route, 'stores' => $stores]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getStateStores($trimParams->params(), $trimParams->additionalParams())->send();
}
