<?php
/**
 * getApiEndpoint - Get a single endpoint by ID
 * 
 * @method GET
 * @url /management/getApiEndpoint/{endpointId}
 * @url /management/getApiEndpoint/{apiId}/{endpointId}
 * @auth required
 * @permission viewer
 * 
 * URL Parameters:
 *     endpointId - The endpoint ID to retrieve
 *     apiId - Optional API filter for faster lookup
 * 
 * Returns full endpoint definition including the parent API's auth config.
 * If the same endpoint ID exists in multiple APIs, returns the first match
 * unless apiId is specified.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments - [0] = endpointId or apiId, [1] = endpointId (if apiId provided)
 * @return ApiResponse
 */
function __command_getApiEndpoint(array $params = [], array $urlParams = []): ApiResponse {
    // Parse URL params
    $apiId = null;
    $endpointId = null;
    
    if (count($urlParams) >= 2) {
        // /getApiEndpoint/{apiId}/{endpointId}
        $apiId = $urlParams[0];
        $endpointId = $urlParams[1];
    } elseif (count($urlParams) >= 1) {
        // /getApiEndpoint/{endpointId}
        $endpointId = $urlParams[0];
    } else {
        // Try body params
        $endpointId = $params['endpointId'] ?? null;
        $apiId = $params['apiId'] ?? null;
    }
    
    if (!$endpointId || !is_string($endpointId)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing endpoint ID. Use: GET /management/getApiEndpoint/{endpointId}');
    }
    
    $manager = new ApiEndpointManager();
    $endpoint = $manager->getEndpoint($endpointId, $apiId);
    
    if (!$endpoint) {
        $msg = $apiId 
            ? "Endpoint '$endpointId' not found in API '$apiId'"
            : "Endpoint '$endpointId' not found";
        return ApiResponse::create(404, 'api.error.not_found')
            ->withMessage($msg);
    }
    
    // Check if there are duplicates (same endpointId in multiple APIs)
    $duplicates = $manager->findEndpoints($endpointId);
    $hasDuplicates = count($duplicates) > 1;
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Endpoint '$endpointId' retrieved successfully")
        ->withData([
            'endpoint' => $endpoint,
            'hasDuplicates' => $hasDuplicates,
            'duplicateCount' => count($duplicates)
        ]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getApiEndpoint($trimParams->params(), $trimParams->additionalParams())->send();
}
