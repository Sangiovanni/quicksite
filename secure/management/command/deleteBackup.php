<?php
/**
 * Delete Backup Command
 * 
 * Deletes a specific backup from a project.
 * 
 * @method DELETE
 * @route /management/deleteBackup
 * @auth required
 * @param string $backup Required - backup name (timestamp folder)
 * @param string $name Optional - project name (defaults to active project)
 * @return ApiResponse Delete result info
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Calculate directory size
 */
if (!function_exists('delbackup_getDirectorySize')) {
    function delbackup_getDirectorySize($path) {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
}

/**
 * Format size for display
 */
if (!function_exists('delbackup_formatSize')) {
    function delbackup_formatSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_deleteBackup(array $params = [], array $urlParams = []): ApiResponse {
    // Get parameters
    $backupName = $params['backup'] ?? null;
    $projectName = $params['name'] ?? null;

    if (!$backupName) {
        return ApiResponse::create(400, 'backup.name_required')
            ->withMessage('Backup name is required');
    }

    // If no project name, use active project
    if (!$projectName) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
        }
    }

    if (!$projectName) {
        return ApiResponse::create(400, 'project.not_specified')
            ->withMessage('No project specified and no active project found');
    }

    // Validate project exists
    $projectsDir = SECURE_FOLDER_PATH . '/projects';
    $projectPath = $projectsDir . '/' . $projectName;

    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'project.not_found')
            ->withMessage('Project not found: ' . $projectName);
    }

    // Validate backup exists
    $backupsDir = $projectPath . '/backups';
    $backupPath = $backupsDir . '/' . $backupName;

    if (!is_dir($backupPath)) {
        return ApiResponse::create(404, 'backup.not_found')
            ->withMessage('Backup not found: ' . $backupName);
    }

    // Get backup size before deletion
    $backupSize = delbackup_getDirectorySize($backupPath);

    // Recursively delete backup directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    if (!rmdir($backupPath)) {
        return ApiResponse::create(500, 'backup.delete_failed')
            ->withMessage('Failed to delete backup directory');
    }

    // Count remaining backups
    $remainingBackups = glob($backupsDir . '/*', GLOB_ONLYDIR);

    return ApiResponse::create(200, 'backup.deleted')
        ->withMessage("Backup deleted successfully: $backupName")
        ->withData([
            'project' => $projectName,
            'deleted_backup' => $backupName,
            'freed_space' => $backupSize,
            'freed_space_formatted' => delbackup_formatSize($backupSize),
            'remaining_backups' => count($remainingBackups)
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteBackup($trimParams->params(), $trimParams->additionalParams())->send();
}
