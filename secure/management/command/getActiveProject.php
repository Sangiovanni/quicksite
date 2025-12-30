<?php
/**
 * getActiveProject Command
 * 
 * Returns the currently active project name and path.
 * Can be called via API or internally from admin panel.
 * 
 * @method GET
 * @route /management/getActiveProject
 * @auth required
 * 
 * @return ApiResponse Active project info
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getActiveProject(array $params = [], array $urlParams = []): ApiResponse {
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    
    // Check target.php exists
    if (!file_exists($targetFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('Target configuration file not found')
            ->withData([
                'expected_file' => 'secure/management/config/target.php'
            ]);
    }
    
    // Load target config
    $targetConfig = include $targetFile;
    $projectName = $targetConfig['project'] ?? null;
    
    if (empty($projectName)) {
        return ApiResponse::create(500, 'config.invalid')
            ->withMessage('No project defined in target configuration');
    }
    
    // Build project path
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    // Check project exists
    $projectExists = is_dir($projectPath);
    
    $data = [
        'project' => $projectName,
        'path' => 'secure/projects/' . $projectName,
        'full_path' => $projectPath,
        'exists' => $projectExists,
        'target_file' => 'secure/management/config/target.php'
    ];
    
    // Add project details if it exists
    if ($projectExists) {
        $data['has_config'] = file_exists($projectPath . '/config.php');
        $data['has_routes'] = file_exists($projectPath . '/routes.php');
        $data['has_templates'] = is_dir($projectPath . '/templates');
        $data['has_translations'] = is_dir($projectPath . '/translate');
        
        // Get site name from config if available
        if ($data['has_config']) {
            $config = @include($projectPath . '/config.php');
            if (is_array($config)) {
                $data['site_name'] = $config['SITE_NAME'] ?? null;
            }
        }
    }
    
    if (!$projectExists) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Active project configured but folder not found')
            ->withData($data);
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Active project retrieved')
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getActiveProject()->send();
}