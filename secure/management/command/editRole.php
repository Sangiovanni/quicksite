<?php
/**
 * editRole Command
 * 
 * Edits an existing role's description and/or commands.
 * Requires * permission (superadmin).
 * 
 * For builtin roles: can only edit description, not commands.
 * For custom roles: can edit description and commands.
 * 
 * Parameters:
 * - name (required): Role name to edit
 * - description (optional): New description
 * - commands (optional): New command list (custom roles only)
 * 
 * @method POST
 * @route /management/editRole
 * @auth required (* only)
 * 
 * @return ApiResponse Updated role details
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
function __command_editRole(array $params = [], array $urlParams = []): ApiResponse {
    // Validate name parameter
    if (empty($params['name'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('name parameter is required')
            ->withData([
                'required' => ['name'],
                'optional' => ['description', 'commands']
            ]);
    }
    
    $name = trim($params['name']);
    
    // Cannot edit '*' (it's not a role)
    if ($name === '*') {
        return ApiResponse::create(400, 'validation.invalid_role')
            ->withMessage('Cannot edit "*" - it is not a role');
    }
    
    // Load roles
    $roles = loadRolesConfig();
    
    // Check role exists
    if (!isset($roles[$name])) {
        return ApiResponse::create(404, 'role.not_found')
            ->withMessage("Role '{$name}' not found")
            ->withData(['existing_roles' => array_keys($roles)]);
    }
    
    $role = $roles[$name];
    $isBuiltin = $role['builtin'] ?? false;
    $changes = [];
    
    // Update description if provided
    if (isset($params['description'])) {
        $description = trim($params['description']);
        if (strlen($description) < 3 || strlen($description) > 255) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage('Description must be between 3 and 255 characters');
        }
        $roles[$name]['description'] = $description;
        $changes[] = 'description';
    }
    
    // Update commands if provided (custom roles only)
    if (isset($params['commands'])) {
        if ($isBuiltin) {
            return ApiResponse::create(403, 'role.builtin_commands_protected')
                ->withMessage("Cannot modify commands for builtin role '{$name}'")
                ->withData([
                    'hint' => 'Builtin roles can only have their description edited',
                    'builtin_roles' => array_keys(array_filter($roles, fn($r) => $r['builtin'] ?? false))
                ]);
        }
        
        if (!is_array($params['commands'])) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('commands must be an array');
        }
        
        $commands = $params['commands'];
        $allCommands = getAllCommands();
        
        // Add the role commands
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
        
        $roles[$name]['commands'] = $commands;
        $changes[] = 'commands';
    }
    
    // Check if anything changed
    if (empty($changes)) {
        return ApiResponse::create(400, 'validation.no_changes')
            ->withMessage('No changes provided')
            ->withData([
                'optional' => ['description', 'commands'],
                'note' => $isBuiltin ? 'Builtin roles can only have description edited' : null
            ]);
    }
    
    // Save roles config
    $configPath = SECURE_FOLDER_PATH . '/management/config/roles.php';
    $content = "<?php\n/**\n * Role Definitions for Permission System\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($roles, true) . ";\n";
    
    if (file_put_contents($configPath, $content) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save role configuration');
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Role '{$name}' updated successfully")
        ->withData([
            'changes' => $changes,
            'role' => [
                'name' => $name,
                'description' => $roles[$name]['description'],
                'builtin' => $isBuiltin,
                'commands' => $roles[$name]['commands'],
                'command_count' => count($roles[$name]['commands'])
            ]
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editRole($trimParams->params(), $trimParams->additionalParams())->send();
}
