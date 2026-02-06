<?php
/**
 * listApiEndpoints - List all API endpoints
 * 
 * @method GET
 * @url /management/listApiEndpoints
 * @url /management/listApiEndpoints/{apiId}
 * @auth required
 * @permission viewer
 * 
 * URL Parameters (optional):
 *     apiId - Filter endpoints by API group
 * 
 * Returns all registered API endpoints, grouped by API.
 * If apiId is provided, only returns endpoints for that API.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments - [0] = apiId (optional)
 * @return ApiResponse
 */
function __command_listApiEndpoints(array $params = [], array $urlParams = []): ApiResponse {
    $apiId = $urlParams[0] ?? $params['apiId'] ?? null;
    
    $manager = new ApiEndpointManager();
    
    // Get APIs
    $apis = $manager->listApis();
    
    // Filter by apiId if provided
    if ($apiId !== null) {
        if (!isset($apis[$apiId])) {
            return ApiResponse::create(404, 'api.error.not_found')
                ->withMessage("API '$apiId' not found");
        }
        $apis = [$apiId => $apis[$apiId]];
    }
    
    // Build response
    $apiList = [];
    $totalEndpoints = 0;
    $endpointsByMethod = [
        'GET' => 0,
        'POST' => 0,
        'PUT' => 0,
        'PATCH' => 0,
        'DELETE' => 0
    ];
    
    foreach ($apis as $aid => $api) {
        $endpointList = [];
        foreach ($api['endpoints'] as $endpoint) {
            $method = strtoupper($endpoint['method']);
            $endpointList[] = [
                'id' => $endpoint['id'],
                'name' => $endpoint['name'],
                'path' => $endpoint['path'],
                'method' => $method,
                'fullUrl' => $api['baseUrl'] . $endpoint['path'],
                'description' => $endpoint['description'] ?? null,
                'auth' => $endpoint['auth'] ?? null,
                'requestSchema' => $endpoint['requestSchema'] ?? null,
                'responseSchema' => $endpoint['responseSchema'] ?? null,
                'hasResponseBindings' => !empty($endpoint['responseBindings'])
            ];
            $totalEndpoints++;
            if (isset($endpointsByMethod[$method])) {
                $endpointsByMethod[$method]++;
            }
        }
        
        $apiList[] = [
            'apiId' => $aid,
            'name' => $api['name'],
            'description' => $api['description'] ?? null,
            'baseUrl' => $api['baseUrl'],
            'auth' => $api['auth'] ?? ['type' => 'none'],
            'endpointCount' => count($api['endpoints']),
            'endpoints' => $endpointList,
            'created' => $api['created'] ?? null,
            'updated' => $api['updated'] ?? null
        ];
    }
    
    // Sort APIs by name
    usort($apiList, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage($apiId 
            ? "Endpoints for API '$apiId' listed successfully" 
            : 'All API endpoints listed successfully')
        ->withData([
            'apis' => $apiList,
            'apiCount' => count($apiList),
            'totalEndpoints' => $totalEndpoints,
            'byMethod' => $endpointsByMethod
        ]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listApiEndpoints($trimParams->params(), $trimParams->additionalParams())->send();
}
