<?php
/**
 * List Backups Command
 * 
 * Lists all available backups for a project.
 * Returns backup names, sizes, and creation dates.
 * 
 * @method GET
 * @route /management/listBackups
 * @auth required
 * @param string $name Optional - project name (defaults to active project)
 * @return ApiResponse List of backups with metadata
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // getDirectorySize, countDirectoryFiles
require_once SECURE_FOLDER_PATH . '/src/functions/projectBackup.php'; // QS_BACKUP_ITEMS

/**
 * Format size for display
 */
if (!function_exists('listbackups_formatSize')) {
    function listbackups_formatSize($bytes) {
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
function __command_listBackups(array $params = [], array $urlParams = []): ApiResponse {
    // CONTAINMENT (confused deputy): the listed project is BOUND to
    // the URL marker (PROJECT_NAME, authorized by the dispatcher — project.data,
    // admin+). A body `name` that disagrees is refused; body is optional. You
    // cannot enumerate the backups of a project you did not target/authorize.
    $bound = qs_bind_marker_project($params, 'listBackups', ['name']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    // Reject a traversal payload before the backups path is enumerated
    // The active-project fallback is trusted.
    if (!is_valid_project_name((string)$projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name')
            ->withErrors([['field' => 'name', 'reason' => 'invalid_format']]);
    }

    // Validate project exists
    $projectsDir = SECURE_FOLDER_PATH . '/projects';
    $projectPath = $projectsDir . '/' . $projectName;

    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'project.not_found')
            ->withMessage('Project not found: ' . $projectName);
    }

    // Check for backups folder
    $backupsDir = $projectPath . '/backups';

    if (!is_dir($backupsDir)) {
        return ApiResponse::create(200, 'backup.list_empty')
            ->withMessage('No backups folder exists yet')
            ->withData([
                'project' => $projectName,
                'backups' => [],
                'count' => 0,
                'total_size' => 0,
                'total_size_formatted' => '0 bytes'
            ]);
    }

    // Get list of all backups
    $backups = [];
    $totalSize = 0;
    $backupDirs = glob($backupsDir . '/*', GLOB_ONLYDIR);

    foreach ($backupDirs as $dir) {
        $name = basename($dir);
        $size = getDirectorySize($dir);
        $fileCount = countDirectoryFiles($dir);
        $created = filemtime($dir);
        
        // Detect backup type
        $type = strpos($name, 'pre-restore_') === 0 ? 'pre-restore' : 'manual';
        
        // List contents
        $contents = [];
        foreach (QS_BACKUP_ITEMS as $item) {
            if (file_exists($dir . '/' . $item)) {
                $contents[] = $item;
            }
        }
        
        // Get relative time
        $diff = time() - $created;
        if ($diff < 60) {
            $relativeTime = 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            $relativeTime = $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            $relativeTime = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            $relativeTime = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            $relativeTime = date('M j, Y', $created);
        }
        
        $backups[] = [
            'name' => $name,
            'type' => $type,
            'size' => $size,
            'size_formatted' => listbackups_formatSize($size),
            'files' => $fileCount,
            'contents' => $contents,
            'created' => $created,
            'created_formatted' => date('Y-m-d H:i:s', $created),
            'created_relative' => $relativeTime
        ];
        
        $totalSize += $size;
    }

    // Sort by creation time (newest first)
    usort($backups, function($a, $b) {
        return $b['created'] - $a['created'];
    });

    return ApiResponse::create(200, 'backup.list_success')
        ->withMessage('Found ' . count($backups) . ' backup(s)')
        ->withData([
            'project' => $projectName,
            'backups' => $backups,
            'count' => count($backups),
            'total_size' => $totalSize,
            'total_size_formatted' => listbackups_formatSize($totalSize)
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listBackups($trimParams->params(), $trimParams->additionalParams())->send();
}
