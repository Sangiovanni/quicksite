<?php
/**
 * listRoles Command
 * 
 * Lists all available roles with their metadata.
 * Non-* users see only role names and descriptions.
 * * users see the full command lists for each role.
 * 
 * @method POST
 * @route /management/listRoles
 * @auth required (all roles)
 * 
 * @return ApiResponse List of roles with metadata
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
function __command_listRoles(array $params = [], array $urlParams = []): ApiResponse {
    $roles = loadRolesConfig();
    
    if (empty($roles)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No roles configured')
            ->withData([
                'roles' => [],
                'count' => 0
            ]);
    }
    
    // Get current user's role to determine what to show
    $tokenInfo = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($authHeader) {
        $result = validateBearerToken($authHeader);
        if ($result['valid']) {
            $tokenInfo = $result['token_info'];
        }
    }
    
    $isSuperAdmin = ($tokenInfo['role'] ?? '') === '*';
    
    // Build response
    $rolesList = [];
    foreach ($roles as $name => $config) {
        $roleData = [
            'name' => $name,
            'description' => $config['description'] ?? '',
            'builtin' => $config['builtin'] ?? false,
            'command_count' => count($config['commands'] ?? [])
        ];
        
        // Only * users can see the full command list
        if ($isSuperAdmin) {
            $roleData['commands'] = $config['commands'] ?? [];
        }
        
        $rolesList[] = $roleData;
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Roles retrieved successfully')
        ->withData([
            'roles' => $rolesList,
            'count' => count($rolesList),
            'includes_commands' => $isSuperAdmin
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listRoles($trimParams->params(), $trimParams->additionalParams())->send();
}
