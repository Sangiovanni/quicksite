<?php
/**
 * deleteRole Command
 * 
 * Deletes a custom role.
 * Requires * permission (superadmin).
 * 
 * Cannot delete builtin roles.
 * Cannot delete role if tokens are using it (unless force=true).
 * 
 * Parameters:
 * - name (required): Role name to delete
 * - force (optional): If true, reassign affected tokens to 'viewer' role
 * 
 * @method POST
 * @route /management/deleteRole
 * @auth required (* only)
 * 
 * @return ApiResponse Deletion result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_deleteRole(array $params = [], array $urlParams = []): ApiResponse {
    // Validate name parameter
    if (empty($params['name'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('name parameter is required');
    }
    
    $name = trim($params['name']);
    $force = isset($params['force']) && $params['force'] === true;
    
    // Cannot delete '*'
    if ($name === '*') {
        return ApiResponse::create(400, 'validation.invalid_role')
            ->withMessage('Cannot delete "*" - it is not a role');
    }
    
    // Load roles
    $roles = loadRolesConfig();
    
    // Check role exists
    if (!isset($roles[$name])) {
        return ApiResponse::create(404, 'role.not_found')
            ->withMessage("Role '{$name}' not found")
            ->withData(['existing_roles' => array_keys($roles)]);
    }
    
    // Cannot delete builtin roles
    if ($roles[$name]['builtin'] ?? false) {
        return ApiResponse::create(403, 'role.builtin_protected')
            ->withMessage("Cannot delete builtin role '{$name}'")
            ->withData([
                'builtin_roles' => array_keys(array_filter($roles, fn($r) => $r['builtin'] ?? false)),
                'custom_roles' => array_keys(array_filter($roles, fn($r) => !($r['builtin'] ?? false)))
            ]);
    }
    
    // Check if any tokens are using this role
    $authConfig = loadAuthConfig();
    $tokens = $authConfig['authentication']['tokens'] ?? [];
    $affectedTokens = [];
    
    foreach ($tokens as $tokenKey => $tokenInfo) {
        $tokenRole = $tokenInfo['role'] ?? migrateTokenPermissions($tokenInfo);
        if ($tokenRole === $name) {
            $affectedTokens[] = [
                'name' => $tokenInfo['name'] ?? 'Unknown',
                'created' => $tokenInfo['created'] ?? 'Unknown'
            ];
        }
    }
    
    if (!empty($affectedTokens) && !$force) {
        return ApiResponse::create(409, 'role.in_use')
            ->withMessage("Role '{$name}' is in use by " . count($affectedTokens) . " token(s)")
            ->withData([
                'affected_tokens' => $affectedTokens,
                'hint' => 'Use force=true to reassign affected tokens to "viewer" role'
            ]);
    }
    
    // If force=true and tokens affected, reassign them to 'viewer'
    $reassignedTokens = [];
    if (!empty($affectedTokens) && $force) {
        foreach ($tokens as $tokenKey => &$tokenInfo) {
            $tokenRole = $tokenInfo['role'] ?? migrateTokenPermissions($tokenInfo);
            if ($tokenRole === $name) {
                $tokenInfo['role'] = 'viewer';
                // Remove old permissions array if it exists
                unset($tokenInfo['permissions']);
                $reassignedTokens[] = $tokenInfo['name'] ?? 'Unknown';
            }
        }
        unset($tokenInfo);
        
        // Save updated auth config
        $authConfig['authentication']['tokens'] = $tokens;
        $authPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
        $authContent = "<?php\n/**\n * Authentication & CORS Configuration\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($authConfig, true) . ";\n";
        
        if (file_put_contents($authPath, $authContent) === false) {
            return ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage('Failed to update token configuration');
        }
    }
    
    // Remove role from config
    unset($roles[$name]);
    
    // Save roles config
    $configPath = SECURE_FOLDER_PATH . '/management/config/roles.php';
    $content = "<?php\n/**\n * Role Definitions for Permission System\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($roles, true) . ";\n";
    
    if (file_put_contents($configPath, $content) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save role configuration');
    }
    
    $responseData = [
        'deleted_role' => $name,
        'remaining_roles' => array_keys($roles)
    ];
    
    if (!empty($reassignedTokens)) {
        $responseData['reassigned_tokens'] = $reassignedTokens;
        $responseData['reassigned_to'] = 'viewer';
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Role '{$name}' deleted successfully")
        ->withData($responseData);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteRole($trimParams->params(), $trimParams->additionalParams())->send();
}
