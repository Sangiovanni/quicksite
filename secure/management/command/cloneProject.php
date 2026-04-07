<?php
/**
 * cloneProject Command
 * 
 * Duplicates an existing project to a new name.
 * Copies all project files (excluding backups), updates config,
 * and optionally switches to the new project.
 * 
 * @method POST
 * @route /management/cloneProject
 * @auth required (admin permission)
 * 
 * @param string $source Source project name (optional, default: active project)
 * @param string $name New project name (required)
 * @param bool $switch_to Switch to the cloned project after creation (optional, default: false)
 * 
 * @return ApiResponse Clone result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_cloneProject(array $params = [], array $urlParams = []): ApiResponse {
    // Determine source project
    $sourceProject = trim($params['source'] ?? '');
    
    if (empty($sourceProject)) {
        // Use active project as source
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $sourceProject = is_array($target) ? ($target['project'] ?? '') : (string)$target;
        }
        if (empty($sourceProject)) {
            return ApiResponse::create(400, 'validation.missing_field')
                ->withMessage('No source project specified and no active project found')
                ->withErrors(['source' => 'Specify a source project or ensure an active project is set']);
        }
    }
    
    // Validate new project name
    $newName = trim($params['name'] ?? '');
    
    if (empty($newName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('New project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Validate project name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', $newName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['name' => 'Must start with letter, contain only alphanumeric/dash/underscore, max 50 chars']);
    }
    
    // Reserved names
    $reserved = ['admin', 'management', 'src', 'logs', 'config', 'projects'];
    if (in_array(strtolower($newName), $reserved)) {
        return ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage("Project name '$newName' is reserved")
            ->withErrors(['name' => 'This name is reserved for system use']);
    }
    
    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Check source project exists
    $sourcePath = SECURE_FOLDER_PATH . '/projects/' . $sourceProject;
    
    if (!is_dir($sourcePath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Source project '$sourceProject' not found")
            ->withData(['searched_path' => 'secure/projects/' . $sourceProject]);
    }
    
    // Check target doesn't already exist
    $targetPath = SECURE_FOLDER_PATH . '/projects/' . $newName;
    
    if (is_dir($targetPath)) {
        return ApiResponse::create(409, 'resource.already_exists')
            ->withMessage("Project '$newName' already exists")
            ->withData(['existing_path' => 'secure/projects/' . $newName]);
    }
    
    // Recursive copy, excluding backups/
    $excludeDirs = ['backups'];
    
    if (!cloneProjectDirectory($sourcePath, $targetPath, $excludeDirs)) {
        // Cleanup on failure
        if (is_dir($targetPath)) {
            deleteDirectoryRecursive($targetPath);
        }
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to clone project files');
    }
    
    // Update config.php — change SITE_NAME to new project name
    $configPath = $targetPath . '/config.php';
    if (file_exists($configPath)) {
        $config = include $configPath;
        if (is_array($config)) {
            $config['SITE_NAME'] = ucfirst(str_replace(['-', '_'], ' ', $newName));
            $configContent = "<?php\n/**\n * Site Configuration\n * Cloned from '$sourceProject' on " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";
            file_put_contents($configPath, $configContent, LOCK_EX);
        }
    }
    
    // Update site.name in translation files
    $translateDir = $targetPath . '/translate';
    $newSiteName = ucfirst(str_replace(['-', '_'], ' ', $newName));
    if (is_dir($translateDir)) {
        foreach (glob($translateDir . '/*.json') as $langFile) {
            $translations = json_decode(file_get_contents($langFile), true);
            if (is_array($translations) && isset($translations['site']['name'])) {
                $translations['site']['name'] = $newSiteName;
                file_put_contents($langFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
    }
    
    // Count cloned files for the response
    $fileCount = countFilesRecursive($targetPath);
    
    $result = [
        'project' => $newName,
        'source' => $sourceProject,
        'path' => 'secure/projects/' . $newName,
        'site_name' => $newSiteName,
        'files_copied' => $fileCount,
        'cloned' => true,
        'switched_to' => false
    ];
    
    // Switch to new project if requested
    if ($switchTo) {
        // Sync live public files back to the previous project before switching
        $targetConfFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetConfFile)) {
            $prevTarget = include $targetConfFile;
            $prevName = is_array($prevTarget) ? ($prevTarget['project'] ?? null) : $prevTarget;
            if ($prevName && $prevName !== $newName) {
                $prevProjectPath = SECURE_FOLDER_PATH . '/projects/' . $prevName;
                if (is_dir($prevProjectPath)) {
                    $prevPublicPath = $prevProjectPath . '/public';
                    if (!is_dir($prevPublicPath)) {
                        mkdir($prevPublicPath, 0755, true);
                    }
                    foreach (['style', 'assets'] as $folder) {
                        $liveSrc = PUBLIC_CONTENT_PATH . '/' . $folder;
                        $projDst = $prevPublicPath . '/' . $folder;
                        if (is_dir($liveSrc)) {
                            if (is_dir($projDst)) {
                                deleteDirectoryRecursive($projDst);
                            }
                            cloneProjectDirectory($liveSrc, $projDst, []);
                        }
                    }
                }
            }
        }

        $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'project' => '" . addslashes($newName) . "'\n];\n";
        
        $handle = fopen($targetConfFile, 'w');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX)) {
                $bytesWritten = fwrite($handle, $targetContent);
                fflush($handle);
                flock($handle, LOCK_UN);
                if ($bytesWritten === strlen($targetContent)) {
                    $result['switched_to'] = true;
                }
            }
            fclose($handle);
            
            clearstatcache(true, $targetConfFile);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($targetConfFile, true);
            }
        }
    }
    
    return ApiResponse::create(201, 'resource.created')
        ->withMessage("Project '$sourceProject' cloned to '$newName' successfully")
        ->withData($result);
}

/**
 * Recursively copy a directory, excluding specified subdirectory names
 */
function cloneProjectDirectory(string $source, string $dest, array $excludeDirs): bool {
    if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
        return false;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $subPath = $iterator->getSubPathname();
        
        // Check if any part of the path starts with an excluded dir
        $skip = false;
        foreach ($excludeDirs as $excludeDir) {
            if (str_starts_with($subPath, $excludeDir . DIRECTORY_SEPARATOR) || $subPath === $excludeDir) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;
        
        $destPath = $dest . DIRECTORY_SEPARATOR . $subPath;
        
        if ($item->isDir()) {
            if (!is_dir($destPath) && !mkdir($destPath, 0755, true)) {
                return false;
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
 * Recursively delete a directory
 */
function deleteDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Count files in a directory recursively
 */
function countFilesRecursive(string $dir): int {
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) $count++;
    }
    return $count;
}

// Direct execution block
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_cloneProject($trimParams->params(), $trimParams->additionalParams())->send();
}
