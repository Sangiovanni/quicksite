<?php
/**
 * deleteApi - Remove an API group and all its endpoints
 * 
 * @method DELETE
 * @url /management/deleteApi/{apiId}
 * @auth required
 * @permission editor
 * 
 * URL Parameters:
 *     apiId - The API group to delete
 * 
 * Deletes an API group and all endpoints within it.
 * This action cannot be undone.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments - [0] = apiId
 * @return ApiResponse
 */
function __command_deleteApi(array $params = [], array $urlParams = []): ApiResponse {
    $apiId = $urlParams[0] ?? $params['apiId'] ?? null;
    
    if (!$apiId || !is_string($apiId)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing API ID. Use: DELETE /management/deleteApi/{apiId}');
    }
    
    $manager = new ApiEndpointManager();
    $result = $manager->deleteApi($apiId);
    
    if (!$result['success']) {
        $code = strpos($result['error'], 'not found') !== false ? 404 : 400;
        return ApiResponse::create($code, 'api.error.invalid_parameter')
            ->withMessage($result['error']);
    }
    
    // Regenerate qs-api-config.js for live development
    $apiConfigPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-api-config.js';
    $manager->writeCompiledJs($apiConfigPath);
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("API '$apiId' deleted successfully")
        ->withData([
            'apiId' => $result['apiId'],
            'deletedEndpoints' => $result['deletedEndpoints']
        ]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteApi($trimParams->params(), $trimParams->additionalParams())->send();
}
