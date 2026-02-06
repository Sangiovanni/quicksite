<?php
/**
 * editApi - Modify an existing API group or its endpoints
 * 
 * @method POST
 * @url /management/editApi
 * @auth required
 * @permission editor
 * 
 * Body (JSON): {
 *     "apiId": "main-backend",           // Required: API to edit
 *     
 *     // API-level updates (all optional):
 *     "name": "New API Name",
 *     "baseUrl": "https://new-api.example.com",
 *     "description": "Updated description",
 *     "auth": { "type": "bearer", "tokenSource": "localStorage:newToken" },
 *     
 *     // Endpoint operations (use ONE of these):
 *     "endpoints": [...],                // Full replacement of all endpoints
 *     "addEndpoint": { ... },            // Add a single endpoint
 *     "editEndpoint": { "id": "...", "updates": {...} },  // Edit endpoint
 *     "deleteEndpoint": "endpoint-id"    // Delete endpoint by ID
 * }
 * 
 * Endpoint object structure:
 * {
 *     "id": "contact-submit",            // Required: Unique within API
 *     "name": "Submit Contact Form",     // Required: Display name
 *     "path": "/contact",                // Required: Path (appended to baseUrl)
 *     "method": "POST",                  // Required: GET, POST, PUT, PATCH, DELETE
 *     "description": "...",              // Optional
 *     "headers": { "X-Custom": "value" }, // Optional: Extra headers
 *     "requestSchema": { ... },          // Optional: Expected request body schema
 *     "responseSchema": { ... },         // Optional: Expected response schema
 *     "queryParams": [ ... ],            // Optional: For GET requests
 *     "responseBindings": { ... }        // Optional: DOM update mappings
 * }
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
function __command_editApi(array $params = [], array $urlParams = []): ApiResponse {
    $apiId = $params['apiId'] ?? null;
    
    if (!$apiId || !is_string($apiId)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing or invalid "apiId" parameter');
    }
    
    // Build updates array from params
    $updates = [];
    
    // API-level fields
    $apiFields = ['name', 'baseUrl', 'description', 'auth'];
    foreach ($apiFields as $field) {
        if (isset($params[$field])) {
            $updates[$field] = $params[$field];
        }
    }
    
    // Endpoint operations
    if (isset($params['endpoints'])) {
        $updates['endpoints'] = $params['endpoints'];
    }
    if (isset($params['addEndpoint'])) {
        $updates['addEndpoint'] = $params['addEndpoint'];
    }
    if (isset($params['editEndpoint'])) {
        $updates['editEndpoint'] = $params['editEndpoint'];
    }
    if (isset($params['deleteEndpoint'])) {
        $updates['deleteEndpoint'] = $params['deleteEndpoint'];
    }
    
    if (empty($updates)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('No updates provided. Specify at least one field to update.');
    }
    
    $manager = new ApiEndpointManager();
    $result = $manager->editApi($apiId, $updates);
    
    if (!$result['success']) {
        $code = strpos($result['error'], 'not found') !== false ? 404 : 400;
        return ApiResponse::create($code, 'api.error.invalid_parameter')
            ->withMessage($result['error']);
    }
    
    // Regenerate qs-api-config.js for live development
    $apiConfigPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-api-config.js';
    $manager->writeCompiledJs($apiConfigPath);
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("API '$apiId' updated successfully")
        ->withData([
            'apiId' => $result['apiId'],
            'api' => $result['api'],
            'endpointCount' => count($result['api']['endpoints'] ?? [])
        ]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editApi($trimParams->params(), $trimParams->additionalParams())->send();
}
