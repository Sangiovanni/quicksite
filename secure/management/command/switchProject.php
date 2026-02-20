<?php
/**
 * switchProject Command
 * 
 * Changes the active project by updating target.php.
 * Also copies the project's public files to the live public/ directory.
 * Can be called via API or internally from admin panel.
 * 
 * @method POST
 * @route /management/switchProject
 * @auth required (admin permission)
 * 
 * @param string $project Project name to switch to (required)
 * @param bool $copy_public Whether to copy project's public files to live public/ (default: true)
 * 
 * @return ApiResponse Switch result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_switchProject(array $params = [], array $urlParams = []): ApiResponse {
    // Validate project parameter
    $projectName = trim($params['project'] ?? '');
    
    if (empty($projectName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Project name is required')
            ->withErrors(['project' => 'Required field']);
    }
    
    // Validate project name format (alphanumeric, dashes, underscores)
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['project' => 'Only alphanumeric characters, dashes, and underscores allowed']);
    }
    
    $copyPublic = $params['copy_public'] ?? true;
    if (is_string($copyPublic)) {
        $copyPublic = filter_var($copyPublic, FILTER_VALIDATE_BOOLEAN);
    }
    
    // Check project exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Project '$projectName' not found")
            ->withData([
                'requested_project' => $projectName,
                'expected_path' => 'secure/projects/' . $projectName
            ]);
    }
    
    // Check project has required files
    $requiredFiles = [
        'config.php' => $projectPath . '/config.php',
        'routes.php' => $projectPath . '/routes.php'
    ];
    
    $missingFiles = [];
    foreach ($requiredFiles as $name => $path) {
        if (!file_exists($path)) {
            $missingFiles[] = $name;
        }
    }
    
    if (!empty($missingFiles)) {
        return ApiResponse::create(400, 'validation.incomplete_project')
            ->withMessage("Project '$projectName' is missing required files")
            ->withErrors(['missing_files' => $missingFiles]);
    }
    
    // Get current project before switch
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    $previousProject = null;
    if (file_exists($targetFile)) {
        $currentConfig = include $targetFile;
        $previousProject = $currentConfig['project'] ?? null;
    }
    
    // Check if already active
    if ($previousProject === $projectName) {
        return ApiResponse::create(200, 'operation.no_change')
            ->withMessage("Project '$projectName' is already active")
            ->withData([
                'project' => $projectName,
                'was_already_active' => true
            ]);
    }
    
    // CRITICAL: Sync live public files BACK to previous project before switching
    // This preserves any CSS/asset edits made while that project was active
    $previousProjectSynced = false;
    if ($previousProject !== null) {
        $previousProjectPath = SECURE_FOLDER_PATH . '/projects/' . $previousProject;
        if (is_dir($previousProjectPath)) {
            $previousProjectSynced = syncLiveToProject($previousProjectPath);
        }
    }
    
    // Write new target.php with explicit sync to ensure file is fully written before response
    $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * \n * Updated: " . date('Y-m-d H:i:s') . "\n * Previous: " . ($previousProject ?? 'none') . "\n */\n\nreturn [\n    'project' => '" . addslashes($projectName) . "'\n];\n";
    
    // Use fopen/fwrite/fflush for explicit sync instead of file_put_contents
    $handle = fopen($targetFile, 'w');
    if ($handle === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to open target configuration for writing');
    }
    
    if (flock($handle, LOCK_EX)) {
        $bytesWritten = fwrite($handle, $targetContent);
        fflush($handle);  // Flush PHP buffers to OS
        flock($handle, LOCK_UN);
    } else {
        fclose($handle);
        return ApiResponse::create(500, 'server.file_lock_failed')
            ->withMessage('Failed to acquire lock on target configuration');
    }
    fclose($handle);
    
    // Verify write succeeded by checking bytes written
    if ($bytesWritten !== strlen($targetContent)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Target configuration write incomplete');
    }
    
    // Clear caches to ensure fresh read on next request
    clearstatcache(true, $targetFile);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($targetFile, true);
    }
    
    $result = [
        'project' => $projectName,
        'previous_project' => $previousProject,
        'previous_project_synced' => $previousProjectSynced,
        'target_updated' => true,
        'public_files_copied' => false,
        'custom_js_regenerated' => false
    ];
    
    // Copy public files if requested
    if ($copyPublic) {
        $projectPublicPath = $projectPath . '/public';
        
        if (is_dir($projectPublicPath)) {
            $copyResult = copyProjectPublicFiles($projectPublicPath, PUBLIC_FOLDER_ROOT);
            $result['public_files_copied'] = $copyResult['success'];
            $result['public_copy_details'] = $copyResult;
        } else {
            $result['public_files_copied'] = false;
            $result['public_copy_details'] = [
                'success' => false,
                'reason' => 'Project has no public/ folder'
            ];
        }
    }
    
    // Regenerate qs-custom.js with new project's custom functions
    require_once SECURE_FOLDER_PATH . '/src/classes/JsFunctionManager.php';
    $jsManager = new JsFunctionManager($projectPath);
    $regenerateResult = $jsManager->regenerateJsFile();
    $result['custom_js_regenerated'] = $regenerateResult['success'];
    $result['custom_functions_count'] = count($jsManager->getCustomFunctions());
    
    // Regenerate qs-api-config.js with project's API configurations
    require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';
    $apiManager = new ApiEndpointManager($projectPath);
    $apiConfigPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-api-config.js';
    $apiConfigWritten = $apiManager->writeCompiledJs($apiConfigPath);
    $result['api_config_regenerated'] = $apiConfigWritten;
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Switched to project '$projectName'")
        ->withData($result);
}

/**
 * Copy project's public files to live public directory
 * 
 * @param string $source Project's public folder
 * @param string $destination Live public folder
 * @return array Result with success status and details
 */
function copyProjectPublicFiles(string $source, string $destination): array {
    $result = [
        'success' => true,
        'copied' => [],
        'errors' => []
    ];
    
    // Folders to copy (not overwrite entirely - merge)
    $foldersToSync = ['assets', 'style', 'build'];
    
    foreach ($foldersToSync as $folder) {
        $srcFolder = $source . '/' . $folder;
        $destFolder = $destination . '/' . $folder;
        
        if (!is_dir($srcFolder)) {
            continue;
        }
        
        // Create destination if needed
        if (!is_dir($destFolder)) {
            mkdir($destFolder, 0755, true);
        }
        
        // Copy folder contents recursively
        $copySuccess = copyDirectoryContents($srcFolder, $destFolder);
        
        if ($copySuccess) {
            $result['copied'][] = $folder;
        } else {
            $result['errors'][] = "Failed to copy $folder";
            $result['success'] = false;
        }
    }
    
    // Copy .htaccess if exists
    $htaccessSrc = $source . '/.htaccess';
    if (file_exists($htaccessSrc)) {
        if (copy($htaccessSrc, $destination . '/.htaccess')) {
            $result['copied'][] = '.htaccess';
        }
    }
    
    return $result;
}

/**
 * Recursively copy directory contents
 */
function copyDirectoryContents(string $source, string $destination): bool {
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $destPath = $destination . '/' . $iterator->getSubPathname();
        
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
        } else {
            if (!copy($item->getPathname(), $destPath)) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Sync live public files back to project folder
 * Preserves CSS/asset edits made while project was active
 * 
 * @param string $projectPath Project folder path
 * @return bool Success status
 */
function syncLiveToProject(string $projectPath): bool {
    $livePublicPath = PUBLIC_FOLDER_ROOT;
    $projectPublicPath = $projectPath . '/public';
    
    // Ensure project public folder exists
    if (!is_dir($projectPublicPath)) {
        mkdir($projectPublicPath, 0755, true);
    }
    
    // Folders to sync from live → project
    $foldersToSync = ['assets', 'style'];
    $success = true;
    
    foreach ($foldersToSync as $folder) {
        $src = $livePublicPath . '/' . $folder;
        $dst = $projectPublicPath . '/' . $folder;
        
        if (!is_dir($src)) {
            continue;
        }
        
        // Delete existing destination and copy fresh from live
        if (is_dir($dst)) {
            deleteDirectoryRecursive($dst);
        }
        
        if (!copyDirectoryContents($src, $dst)) {
            $success = false;
        }
    }
    
    return $success;
}

/**
 * Recursively delete a directory and its contents
 */
function deleteDirectoryRecursive(string $dir): bool {
    if (!is_dir($dir)) {
        return true;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    return rmdir($dir);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_switchProject($trimParams->params(), $trimParams->additionalParams())->send();
}