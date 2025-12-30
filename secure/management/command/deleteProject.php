<?php
/**
 * deleteProject Command
 * 
 * Deletes a project and all its files.
 * Can be called via API or internally from admin panel.
 * 
 * WARNING: This operation is destructive and cannot be undone.
 * 
 * @method POST
 * @route /management/deleteProject
 * @auth required (admin permission)
 * 
 * @param string $name Project name (required)
 * @param bool $confirm Safety confirmation (required, must be true)
 * @param bool $force Force delete even if project is active (optional, default: false)
 * 
 * @return ApiResponse Deletion result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_deleteProject(array $params = [], array $urlParams = []): ApiResponse {
    // Validate project name
    $projectName = trim($params['name'] ?? '');
    
    if (empty($projectName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Safety confirmation
    $confirm = filter_var($params['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (!$confirm) {
        return ApiResponse::create(400, 'validation.confirmation_required')
            ->withMessage('Deletion must be confirmed')
            ->withErrors(['confirm' => 'Set confirm=true to proceed with deletion'])
            ->withData(['warning' => 'This will permanently delete all project files including templates, translations, and assets']);
    }
    
    // Force flag for active project
    $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Check project exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Project '$projectName' not found")
            ->withData(['searched_path' => 'secure/projects/' . $projectName]);
    }
    
    // Check if this is the active project
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    $activeProject = null;
    
    if (file_exists($targetFile)) {
        $target = require $targetFile;
        $activeProject = $target['project'] ?? null;
    }
    
    if ($activeProject === $projectName && !$force) {
        return ApiResponse::create(400, 'validation.active_project')
            ->withMessage("Cannot delete active project '$projectName'")
            ->withData([
                'active_project' => $activeProject,
                'hint' => 'Switch to another project first, or set force=true to delete anyway'
            ]);
    }
    
    // Count what we're about to delete
    $stats = countProjectFiles($projectPath);
    
    // Delete the project directory recursively
    $deleted = deleteDirectory($projectPath);
    
    if (!$deleted) {
        return ApiResponse::create(500, 'server.delete_failed')
            ->withMessage('Failed to delete project directory')
            ->withData(['path' => $projectPath]);
    }
    
    // If this was the active project, we need to update target.php
    $switchedTo = null;
    if ($activeProject === $projectName) {
        // Find another project to switch to
        $projectsDir = SECURE_FOLDER_PATH . '/projects';
        $projects = array_diff(scandir($projectsDir), ['.', '..']);
        $projects = array_filter($projects, fn($p) => is_dir($projectsDir . '/' . $p));
        
        if (!empty($projects)) {
            $newActive = reset($projects);
            $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'project' => '" . addslashes($newActive) . "'\n];\n";
            file_put_contents($targetFile, $targetContent, LOCK_EX);
            $switchedTo = $newActive;
        } else {
            // No projects left - write empty/placeholder
            $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n * WARNING: No projects available\n */\n\nreturn [\n    'project' => ''\n];\n";
            file_put_contents($targetFile, $targetContent, LOCK_EX);
        }
    }
    
    $result = [
        'project' => $projectName,
        'deleted' => true,
        'files_deleted' => $stats['files'],
        'directories_deleted' => $stats['directories'],
        'size_freed' => formatBytes($stats['size']),
        'size_bytes' => $stats['size']
    ];
    
    if ($switchedTo !== null) {
        $result['switched_to'] = $switchedTo;
        $result['note'] = "Active project was deleted, switched to '$switchedTo'";
    } elseif ($activeProject === $projectName) {
        $result['warning'] = 'No remaining projects. Please create a new project.';
    }
    
    return ApiResponse::create(200, 'resource.deleted')
        ->withMessage("Project '$projectName' deleted successfully")
        ->withData($result);
}

/**
 * Count files, directories and total size in a directory
 * 
 * @param string $dir Directory path
 * @return array Stats array with files, directories, size
 */
function countProjectFiles(string $dir): array {
    $stats = ['files' => 0, 'directories' => 0, 'size' => 0];
    
    if (!is_dir($dir)) {
        return $stats;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $stats['directories']++;
        } else {
            $stats['files']++;
            $stats['size'] += $item->getSize();
        }
    }
    
    // Count root directory
    $stats['directories']++;
    
    return $stats;
}

/**
 * Recursively delete a directory
 * 
 * @param string $dir Directory path
 * @return bool Success
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!unlink($path)) {
                return false;
            }
        }
    }
    
    return rmdir($dir);
}

/**
 * Format bytes to human readable
 * 
 * @param int $bytes Byte count
 * @return string Formatted string
 */
function formatBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $exp = floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);
    
    return round($bytes / pow(1024, $exp), 2) . ' ' . $units[$exp];
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteProject($trimParams->params(), $trimParams->additionalParams())->send();
}