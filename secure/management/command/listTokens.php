<?php
/**
 * listTokens - List API tokens (without revealing full token values)
 * 
 * @method GET
 * @url /management/listTokens
 * @auth required
 * @permission admin
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_listTokens(array $params = [], array $urlParams = []): ApiResponse {
    $config = loadAuthConfig();

    // Build safe token list (mask token values)
    $tokens = [];
    foreach ($config['authentication']['tokens'] as $token => $info) {
        // Show only first 8 and last 4 characters
        $masked = substr($token, 0, 8) . '...' . substr($token, -4);
        
        $tokens[] = [
            'token_preview' => $masked,
            'name' => $info['name'],
            'permissions' => $info['permissions'],
            'created' => $info['created'] ?? 'unknown'
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Tokens retrieved successfully')
        ->withData([
            'total_tokens' => count($tokens),
            'tokens' => $tokens,
            'auth_enabled' => $config['authentication']['enabled'] ?? true,
            'development_mode' => $config['cors']['development_mode'] ?? false
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listTokens()->send();
}
