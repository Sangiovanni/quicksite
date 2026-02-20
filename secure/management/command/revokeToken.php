<?php
/**
 * Revoke Token Command
 * 
 * Revokes (deletes) an API token.
 * Requires admin permission.
 * 
 * Parameters:
 * - token_preview (required): The token preview (first 8 + last 4 chars) from listTokens
 *   OR the full token value
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$params = $trimParametersManagement->params();

// Validate token_preview parameter
if (empty($params['token_preview'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('token_preview parameter is required')
        ->withData([
            'hint' => 'Use the token_preview value from listTokens command, or the full token'
        ])
        ->send();
}

$tokenPreview = trim($params['token_preview']);

// Load current config
$configPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
$config = require $configPath;

// Find matching token
$tokenToRevoke = null;
$tokenInfo = null;

foreach ($config['authentication']['tokens'] as $fullToken => $info) {
    // Check if it matches the preview format or full token
    $preview = substr($fullToken, 0, 8) . '...' . substr($fullToken, -4);
    
    if ($tokenPreview === $preview || $tokenPreview === $fullToken) {
        $tokenToRevoke = $fullToken;
        $tokenInfo = $info;
        break;
    }
}

if ($tokenToRevoke === null) {
    ApiResponse::create(404, 'resource.not_found')
        ->withMessage('Token not found')
        ->withData([
            'provided' => $tokenPreview,
            'hint' => 'Use listTokens to see available tokens'
        ])
        ->send();
}

// Check if trying to revoke the last token
if (count($config['authentication']['tokens']) === 1) {
    ApiResponse::create(400, 'operation.denied')
        ->withMessage('Cannot revoke the last remaining token')
        ->withData([
            'hint' => 'Generate a new token first, then revoke this one'
        ])
        ->send();
}

// Check if trying to revoke current token
$currentToken = getTokenFromRequest();
if ($currentToken === $tokenToRevoke) {
    ApiResponse::create(400, 'operation.denied')
        ->withMessage('Cannot revoke the token currently in use')
        ->withData([
            'hint' => 'Use a different token to revoke this one'
        ])
        ->send();
}

// Remove token
$revokedName = $tokenInfo['name'];
unset($config['authentication']['tokens'][$tokenToRevoke]);

// Write updated config
$configContent = "<?php\n/**\n * Authentication & CORS Configuration\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";

if (file_put_contents($configPath, $configContent) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to save config after revoking token')
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Token revoked successfully')
    ->withData([
        'revoked_token' => substr($tokenToRevoke, 0, 8) . '...' . substr($tokenToRevoke, -4),
        'name' => $revokedName,
        'remaining_tokens' => count($config['authentication']['tokens'])
    ])
    ->send();
