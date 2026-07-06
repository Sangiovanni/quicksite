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
    
    $user = $result['user'];

    // Get full permission details (role + commands for the user's selected project)
    $permissions = getTokenPermissions($user);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Permissions retrieved successfully')
        ->withData([
            'token_name' => $user['name'] ?? 'Unknown',
            'role' => $permissions['role'],
            'commands' => $permissions['commands'],
            'command_count' => count($permissions['commands']),
            // superadmin tier removed (C5); the commands list is authoritative
            'is_superadmin' => false
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getMyPermissions($trimParams->params(), $trimParams->additionalParams())->send();
}
