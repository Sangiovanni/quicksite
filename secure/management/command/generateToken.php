<?php
/**
 * Generate Token Command
 * 
 * Creates a new API token with specified permissions.
 * Requires admin permission.
 * 
 * Parameters:
 * - name (required): Description/name for the token
 * - permissions (optional): Array of permissions (default: ['read'])
 *   Options: '*', 'read', 'write', 'admin', 'command:<name>'
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

$params = $trimParametersManagement->params();

// Validate name parameter
if (empty($params['name'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('name parameter is required')
        ->withData([
            'required' => ['name'],
            'optional' => ['permissions']
        ])
        ->send();
}

if (!is_string($params['name'])) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('name must be a string')
        ->send();
}

$tokenName = trim($params['name']);

if (strlen($tokenName) < 1 || strlen($tokenName) > 100) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('name must be between 1 and 100 characters')
        ->send();
}

// Validate permissions
$validPermissions = ['*', 'read', 'write', 'admin'];
$permissions = $params['permissions'] ?? ['read'];

if (!is_array($permissions)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('permissions must be an array')
        ->withData([
            'valid_permissions' => $validPermissions,
            'command_format' => 'command:<command_name>'
        ])
        ->send();
}

// Validate each permission
foreach ($permissions as $perm) {
    if (!is_string($perm)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('Each permission must be a string')
            ->send();
    }
    
    // Check if it's a valid base permission or command:xxx format
    if (!in_array($perm, $validPermissions) && !RegexPatterns::match('permission_command', $perm)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid permission: {$perm}")
            ->withData([
                'valid_permissions' => $validPermissions,
                'command_format' => 'command:<command_name>'
            ])
            ->send();
    }
}

// Generate new token
$newToken = generateApiToken();

// Load current config
$configPath = SECURE_FOLDER_PATH . '/config/auth.php';
$config = require $configPath;

// Add new token
$config['authentication']['tokens'][$newToken] = [
    'name' => $tokenName,
    'permissions' => $permissions,
    'created' => date('Y-m-d H:i:s')
];

// Write updated config
$configContent = "<?php\n/**\n * Authentication & CORS Configuration\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";

if (file_put_contents($configPath, $configContent) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to save new token')
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Token generated successfully')
    ->withData([
        'token' => $newToken,
        'name' => $tokenName,
        'permissions' => $permissions,
        'created' => date('Y-m-d H:i:s'),
        'warning' => 'Save this token securely - it cannot be retrieved later!'
    ])
    ->send();
