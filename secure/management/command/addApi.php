<?php
/**
 * addApi - Create a new API group for external endpoints
 * 
 * @method POST
 * @url /management/addApi
 * @auth required
 * @permission editor
 * 
 * Body (JSON): {
 *     "apiId": "main-backend",           // Required: Unique identifier (alphanumeric, dashes, underscores)
 *     "name": "Main Backend API",        // Required: Display name
 *     "baseUrl": "https://api.example.com", // Required: Base URL for all endpoints
 *     "description": "Our main API",     // Optional: Description
 *     "auth": {                          // Optional: Authentication config
 *         "type": "bearer",              // none, bearer, apiKey, basic
 *         "tokenSource": "localStorage:apiToken"  // Where to get token at runtime
 *     }
 * }
 * 
 * Creates a new API group that can contain multiple endpoints.
 * Endpoints are added via editApi command with addEndpoint parameter.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_addApi(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required fields
    $apiId = $params['apiId'] ?? null;
    $name = $params['name'] ?? null;
    $baseUrl = $params['baseUrl'] ?? null;
    $description = $params['description'] ?? '';
    $auth = $params['auth'] ?? [];
    
    if (!$apiId || !is_string($apiId)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing or invalid "apiId" parameter');
    }
    
    if (!$name || !is_string($name)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing or invalid "name" parameter');
    }
    
    if (!$baseUrl || !is_string($baseUrl)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing or invalid "baseUrl" parameter');
    }
    
    // Use manager to add API
    $manager = new ApiEndpointManager();
    $result = $manager->addApi($apiId, $name, $baseUrl, $auth, $description);
    
    if (!$result['success']) {
        return ApiResponse::create(400, 'api.error.invalid_parameter')
            ->withMessage($result['error']);
    }
    
    // Regenerate qs-api-config.js for live development
    $apiConfigPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-api-config.js';
    $manager->writeCompiledJs($apiConfigPath);
    
    return ApiResponse::create(201, 'operation.success')
        ->withMessage("API '$apiId' created successfully")
        ->withData([
            'apiId' => $result['apiId'],
            'api' => $result['api']
        ]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addApi($trimParams->params(), $trimParams->additionalParams())->send();
}
