<?php
/**
 * createRole Command
 * 
 * Creates a new custom role with specified commands.
 * Requires * permission (superadmin).
 * 
 * Parameters:
 * - name (required): Role name (alphanumeric + underscore, 3-32 chars)
 * - description (required): Role description
 * - commands (required): Array of command names
 * 
 * @method POST
 * @route /management/createRole
 * @auth required (* only)
 * 
 * @return ApiResponse Created role details
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
function __command_createRole(array $params = [], array $urlParams = []): ApiResponse {
    // Validate name parameter
    if (empty($params['name'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('name parameter is required')
            ->withData([
                'required' => ['name', 'description', 'commands'],
                'name_rules' => 'Alphanumeric + underscore, 3-32 characters'
            ]);
    }
    
    $name = trim($params['name']);
    
    // Validate name format
    if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/i', $name)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Role name must be alphanumeric + underscore, 3-32 chars, start with letter')
            ->withData(['provided' => $name]);
    }
    
    // Cannot use reserved names
    if ($name === '*') {
        return ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage('Cannot use "*" as role name - it is reserved for superadmin');
    }
    
    // Check if role already exists
    $roles = loadRolesConfig();
    if (isset($roles[$name])) {
        return ApiResponse::create(409, 'role.already_exists')
            ->withMessage("Role '{$name}' already exists")
            ->withData(['existing_roles' => array_keys($roles)]);
    }
    
    // Validate description
    if (empty($params['description'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('description parameter is required');
    }
    
    $description = trim($params['description']);
    if (strlen($description) < 3 || strlen($description) > 255) {
        return ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Description must be between 3 and 255 characters');
    }
    
    // Validate commands
    if (empty($params['commands']) || !is_array($params['commands'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('commands parameter is required and must be an array');
    }
    
    $commands = $params['commands'];
    $allCommands = getAllCommands();
    
    // Add the new role commands that will exist
    $allCommands = array_merge($allCommands, ['listRoles', 'getMyPermissions', 'createRole', 'editRole', 'deleteRole']);
    $allCommands = array_unique($allCommands);
    
    // Validate each command exists
    $invalidCommands = [];
    foreach ($commands as $cmd) {
        if (!is_string($cmd)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('Each command must be a string');
        }
        if (!in_array($cmd, $allCommands, true)) {
            $invalidCommands[] = $cmd;
        }
    }
    
    if (!empty($invalidCommands)) {
        return ApiResponse::create(400, 'validation.invalid_commands')
            ->withMessage('Some commands do not exist')
            ->withData([
                'invalid_commands' => $invalidCommands,
                'valid_commands' => $allCommands
            ]);
    }
    
    // Remove duplicates and sort
    $commands = array_values(array_unique($commands));
    sort($commands);
    
    // Add new role to config
    $roles[$name] = [
        'description' => $description,
        'builtin' => false,
        'commands' => $commands
    ];
    
    // Save roles config
    $configPath = SECURE_FOLDER_PATH . '/management/config/roles.php';
    $content = "<?php\n/**\n * Role Definitions for Permission System\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($roles, true) . ";\n";
    
    if (file_put_contents($configPath, $content) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save role configuration');
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Role '{$name}' created successfully")
        ->withData([
            'role' => [
                'name' => $name,
                'description' => $description,
                'builtin' => false,
                'commands' => $commands,
                'command_count' => count($commands)
            ]
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_createRole($trimParams->params(), $trimParams->additionalParams())->send();
}
