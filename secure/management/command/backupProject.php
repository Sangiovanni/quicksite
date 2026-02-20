<?php
/**
 * Backup Project Command
 * 
 * Creates a timestamped backup of the current (or specified) project.
 * Backups are stored in the project's backups/ folder as complete folder copies.
 * 
 * This is for INTERNAL backups (same server, instant restore).
 * For external sharing, use exportProject instead.
 * 
 * @method GET
 * @route /management/backupProject
 * @auth required
 * @param string $name Optional - project name (defaults to active project)
 * @param int $max_backups Optional - maximum backups to keep (default: 5, 0 = unlimited)
 * @return ApiResponse Backup info with path, size, and backup count
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Recursively copy a directory
 */
if (!function_exists('backup_copyDirectory')) {
    function backup_copyDirectory($src, $dst, $exclude = []) {
        if (!is_dir($src)) {
            return false;
        }
        
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        
        $dir = opendir($src);
        $success = true;
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            // Check if this file/folder should be excluded
            if (in_array($file, $exclude)) {
                continue;
            }
            
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            if (is_dir($srcPath)) {
                if (!backup_copyDirectory($srcPath, $dstPath, $exclude)) {
                    $success = false;
                }
            } else {
                if (!copy($srcPath, $dstPath)) {
                    $success = false;
                }
            }
        }
        
        closedir($dir);
        return $success;
    }
}

/**
 * Calculate directory size
 */
if (!function_exists('backup_getDirectorySize')) {
    function backup_getDirectorySize($path) {
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
 * Count files in directory
 */
if (!function_exists('backup_countFiles')) {
    function backup_countFiles($path) {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        
        return $count;
    }
}

/**
 * Format size for display
 */
if (!function_exists('backup_formatSize')) {
    function backup_formatSize($bytes) {
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
function __command_backupProject(array $params = [], array $urlParams = []): ApiResponse {
    // Get parameters
    $projectName = $params['name'] ?? null;
    $maxBackups = isset($params['max_backups']) ? (int)$params['max_backups'] : 5;

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

    // Create backups folder if doesn't exist
    $backupsDir = $projectPath . '/backups';
    if (!is_dir($backupsDir)) {
        if (!mkdir($backupsDir, 0755, true)) {
            return ApiResponse::create(500, 'backup.folder_create_failed')
                ->withMessage('Failed to create backups directory');
        }
    }

    // Generate backup name with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $backupName = $timestamp;
    $backupPath = $backupsDir . '/' . $backupName;

    // Check if backup already exists (unlikely but possible if called multiple times per second)
    if (is_dir($backupPath)) {
        $backupName .= '_' . uniqid();
        $backupPath = $backupsDir . '/' . $backupName;
    }

    // Create backup directory
    if (!mkdir($backupPath, 0755, true)) {
        return ApiResponse::create(500, 'backup.create_failed')
            ->withMessage('Failed to create backup directory');
    }

    // If this is the active project, sync live public files back to project first
    // This ensures style changes, asset uploads etc. are included in the backup
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    $activeProject = null;
    $publicSynced = false;
    if (file_exists($targetFile)) {
        $target = include $targetFile;
        $activeProject = is_array($target) ? ($target['project'] ?? null) : $target;
    }
    
    if ($activeProject === $projectName) {
        $livePublicPath = PUBLIC_FOLDER_ROOT;
        $projectPublicPath = $projectPath . '/public';
        
        // Sync key folders from live to project before backup
        $foldersToSync = ['assets', 'style'];
        foreach ($foldersToSync as $folder) {
            $src = $livePublicPath . '/' . $folder;
            $dst = $projectPublicPath . '/' . $folder;
            
            if (is_dir($src)) {
                // Ensure destination folder exists
                if (!is_dir($projectPublicPath)) {
                    mkdir($projectPublicPath, 0755, true);
                }
                // Delete existing and copy fresh from live
                if (is_dir($dst)) {
                    // Recursively delete destination first
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dst, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iterator as $file) {
                        if ($file->isDir()) {
                            rmdir($file->getRealPath());
                        } else {
                            unlink($file->getRealPath());
                        }
                    }
                    rmdir($dst);
                }
                backup_copyDirectory($src, $dst);
            }
        }
        $publicSynced = true;
    }

    // Copy project contents to backup (excluding backups folder itself)
    $itemsToCopy = ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'];
    $copiedItems = [];
    $errors = [];

    foreach ($itemsToCopy as $item) {
        $srcPath = $projectPath . '/' . $item;
        $dstPath = $backupPath . '/' . $item;
        
        if (!file_exists($srcPath)) {
            continue; // Skip if doesn't exist
        }
        
        if (is_dir($srcPath)) {
            if (backup_copyDirectory($srcPath, $dstPath)) {
                $copiedItems[] = $item;
            } else {
                $errors[] = "Failed to copy directory: $item";
            }
        } else {
            if (copy($srcPath, $dstPath)) {
                $copiedItems[] = $item;
            } else {
                $errors[] = "Failed to copy file: $item";
            }
        }
    }

    // Check if backup was successful
    if (empty($copiedItems)) {
        // Clean up empty backup
        rmdir($backupPath);
        return ApiResponse::create(500, 'backup.no_files_copied')
            ->withMessage('Failed to create backup - no files copied')
            ->withData(['errors' => $errors]);
    }

    // Calculate backup size
    $backupSize = backup_getDirectorySize($backupPath);
    $fileCount = backup_countFiles($backupPath);

    // Get list of all backups and apply max_backups limit
    $backups = [];
    $backupDirs = glob($backupsDir . '/*', GLOB_ONLYDIR);

    foreach ($backupDirs as $dir) {
        $name = basename($dir);
        $backups[$name] = [
            'name' => $name,
            'path' => $dir,
            'created' => filemtime($dir)
        ];
    }

    // Sort by creation time (oldest first)
    uasort($backups, function($a, $b) {
        return $a['created'] - $b['created'];
    });

    // Delete oldest backups if over limit
    $deletedBackups = [];
    if ($maxBackups > 0 && count($backups) > $maxBackups) {
        $toDelete = array_slice(array_keys($backups), 0, count($backups) - $maxBackups);
        
        foreach ($toDelete as $oldBackup) {
            $oldPath = $backups[$oldBackup]['path'];
            
            // Recursively delete old backup
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($oldPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            
            if (rmdir($oldPath)) {
                $deletedBackups[] = $oldBackup;
                unset($backups[$oldBackup]);
            }
        }
    }

    return ApiResponse::create(200, 'backup.created')
        ->withMessage("Backup created successfully: $backupName")
        ->withData([
            'project' => $projectName,
            'backup' => [
                'name' => $backupName,
                'path' => $backupPath,
                'size' => $backupSize,
                'size_formatted' => backup_formatSize($backupSize),
                'files' => $fileCount,
                'items' => $copiedItems,
                'created' => $timestamp,
                'public_synced' => $publicSynced
            ],
            'total_backups' => count($backups),
            'max_backups' => $maxBackups,
            'deleted_old_backups' => $deletedBackups,
            'errors' => $errors
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_backupProject($trimParams->params(), $trimParams->additionalParams())->send();
}
