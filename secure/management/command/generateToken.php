<?php
/**
 * Generate Token Command
 * 
 * Creates a new API token with specified role.
 * Requires * permission (superadmin).
 * 
 * Parameters:
 * - name (required): Description/name for the token
 * - role (required): Role name (viewer, editor, designer, developer, admin) or '*'
 * - note (optional): Additional note for the token
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$params = $trimParametersManagement->params();

// Validate name parameter
if (empty($params['name'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('name parameter is required')
        ->withData([
            'required' => ['name', 'role'],
            'optional' => ['note'],
            'available_roles' => array_merge(array_keys(loadRolesConfig()), ['*'])
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

// Validate role parameter
if (empty($params['role'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('role parameter is required')
        ->withData([
            'required' => ['name', 'role'],
            'available_roles' => array_merge(array_keys(loadRolesConfig()), ['*'])
        ])
        ->send();
}

$role = trim($params['role']);

// Validate role exists
if (!isValidRole($role)) {
    ApiResponse::create(400, 'validation.invalid_role')
        ->withMessage("Invalid role: {$role}")
        ->withData([
            'available_roles' => array_merge(array_keys(loadRolesConfig()), ['*'])
        ])
        ->send();
}

// Optional note
$note = isset($params['note']) ? trim($params['note']) : null;

// Generate new token
$newToken = generateApiToken();

// Load current config
$configPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
$config = require $configPath;

// Add new token
$tokenData = [
    'name' => $tokenName,
    'role' => $role,
    'created' => date('Y-m-d H:i:s')
];

if ($note) {
    $tokenData['note'] = $note;
}

$config['authentication']['tokens'][$newToken] = $tokenData;

// Write updated config
$configContent = "<?php\n/**\n * Authentication & CORS Configuration\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";

if (file_put_contents($configPath, $configContent) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to save new token')
        ->send();
}

// Get role commands for response
$commands = $role === '*' ? ['*'] : (getRoleCommands($role) ?? []);

ApiResponse::create(200, 'operation.success')
    ->withMessage('Token generated successfully')
    ->withData([
        'token' => $newToken,
        'name' => $tokenName,
        'role' => $role,
        'command_count' => $role === '*' ? 'unlimited' : count($commands),
        'created' => date('Y-m-d H:i:s'),
        'warning' => 'Save this token securely - it cannot be retrieved later!'
    ])
    ->send();
