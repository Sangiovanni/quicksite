<?php
/**
 * getMyPermissions Command
 * 
 * Returns the current token's role and list of allowed commands.
 * Useful for admin panel to filter UI based on permissions.
 * 
 * @method POST
 * @route /management/getMyPermissions
 * @auth required (all roles)
 * 
 * @return ApiResponse Current user's role and commands
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getMyPermissions(array $params = [], array $urlParams = []): ApiResponse {
    // Get current token info
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    
    if (empty($authHeader)) {
        return ApiResponse::create(401, 'auth.missing_header')
            ->withMessage('Authorization header required');
    }
    
    $result = validateBearerToken($authHeader);
    
    if (!$result['valid']) {
        return ApiResponse::create(401, 'auth.invalid_token')
            ->withMessage($result['error'] ?? 'Invalid token');
    }
    
    $tokenInfo = $result['token_info'];
    
    // Get full permission details
    $permissions = getTokenPermissions($tokenInfo);
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Permissions retrieved successfully')
        ->withData([
            'token_name' => $tokenInfo['name'] ?? 'Unknown',
            'role' => $permissions['role'],
            'commands' => $permissions['commands'],
            'command_count' => count($permissions['commands']),
            'is_superadmin' => $permissions['role'] === '*'
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getMyPermissions($trimParams->params(), $trimParams->additionalParams())->send();
}
