<?php
/**
 * setStateStores - replace a page's state-store definitions
 *
 * @method POST
 * @url /management/setStateStores
 * @auth required
 * @permission editStructure
 *
 * Body (JSON): {
 *   "route": "home",
 *   "stores": {
 *     "commandsList": {
 *       "endpoint": "@help-api/list",
 *       "fetchOnLoad": true,
 *       "fields": { "page": { "dir": "request", "init": "query:page", "default": 1 } }
 *     }
 *   }
 * }
 *
 * Read-modify-write: the admin panel sends the route's full store-set.
 * An empty "stores" object clears the route's entry.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/StateStoreManager.php';

function __command_setStateStores(array $params = [], array $urlParams = []): ApiResponse {
    $route = $params['route'] ?? ($params['pageName'] ?? null);
    if (!$route || !is_string($route)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing or invalid "route" parameter');
    }

    // Validate the page exists (same special-page allowance as page events).
    $specialPages = ['404', '500', '403', '401'];
    if (!routeExists($route, ROUTES) && !in_array($route, $specialPages, true)) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$route}' does not exist");
    }

    $stores = $params['stores'] ?? null;
    if (!is_array($stores)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing or invalid "stores" object');
    }

    $manager = new StateStoreManager();
    $result = $manager->setForRoute($route, $stores);
    if (!$result['success']) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage($result['error']);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('State stores saved')
        ->withData(['route' => $route, 'stores' => $result['stores']]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setStateStores($trimParams->params(), $trimParams->additionalParams())->send();
}
