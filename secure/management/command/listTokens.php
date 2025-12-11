<?php
/**
 * List Tokens Command
 * 
 * Lists all API tokens (without revealing the full token values).
 * Requires admin permission.
 * 
 * Parameters: none
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

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

ApiResponse::create(200, 'operation.success')
    ->withMessage('Tokens retrieved successfully')
    ->withData([
        'total_tokens' => count($tokens),
        'tokens' => $tokens,
        'auth_enabled' => $config['authentication']['enabled'] ?? true,
        'development_mode' => $config['cors']['development_mode'] ?? false
    ])
    ->send();
