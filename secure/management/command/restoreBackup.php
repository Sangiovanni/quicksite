<?php
/**
 * Restore Backup Command
 * 
 * Restores a project from a specific backup.
 * Creates a pre-restore backup automatically for safety.
 * 
 * @method POST
 * @route /management/restoreBackup
 * @auth required
 * @param string $backup Required - backup name (timestamp folder)
 * @param string $name Optional - project name (defaults to active project)
 * @return ApiResponse Restore result info
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

/**
 * Recursively copy a directory
 */
if (!function_exists('restore_copyDirectory')) {
    function restore_copyDirectory($src, $dst, $exclude = []) {
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
                if (!restore_copyDirectory($srcPath, $dstPath, $exclude)) {
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
 * Recursively delete a directory
 */
if (!function_exists('restore_deleteDirectory')) {
    function restore_deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
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
}

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_restoreBackup(array $params = [], array $urlParams = []): ApiResponse {
    // Get parameters
    $backupName = $params['backup'] ?? null;

    if (!$backupName) {
        return ApiResponse::create(400, 'backup.name_required')
            ->withMessage('Backup name is required');
    }

    // C8 8.4 CONTAINMENT (confused-deputy / F6): the restore target is BOUND to
    // the URL marker (PROJECT_NAME, authorized by the dispatcher — project.data,
    // admin+). A body `name` that disagrees is refused; body is optional. You
    // cannot overwrite a project you did not target/authorize.
    $projectName = trim((string)($params['name'] ?? ''));
    $markerProject = defined('PROJECT_NAME') ? (string)PROJECT_NAME : '';
    if ($markerProject !== '') {
        if ($projectName !== '' && $projectName !== $markerProject) {
            return ApiResponse::create(400, 'project.mismatch')
                ->withMessage('The targeted project does not match the project in the request body')
                ->withErrors(['name' => 'Must match the project in the URL']);
        }
        $projectName = $markerProject;
    }

    if ($projectName === '') {
        return ApiResponse::create(400, 'project.not_specified')
            ->withMessage('No project specified');
    }

    // Reject traversal payloads before the backup source + project dest paths are
    // built (beta.10 C3 F1-d). The active-project fallback is trusted.
    if (!is_valid_backup_name((string)$backupName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid backup name')
            ->withErrors([['field' => 'backup', 'reason' => 'invalid_format']]);
    }
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

    // Validate backup exists
    $backupsDir = $projectPath . '/backups';
    $backupPath = $backupsDir . '/' . $backupName;

    if (!is_dir($backupPath)) {
        return ApiResponse::create(404, 'backup.not_found')
            ->withMessage('Backup not found: ' . $backupName);
    }

    // Check if user wants a pre-restore backup (default: false)
    $createBackup = $params['create_backup'] ?? false;
    if (is_string($createBackup)) {
        $createBackup = filter_var($createBackup, FILTER_VALIDATE_BOOLEAN);
    }

    $preRestoreName = null;
    $preRestoreItems = [];

    if ($createBackup) {
        // Create pre-restore backup for safety
        $preRestoreName = 'pre-restore_' . date('Y-m-d_H-i-s');
        $preRestorePath = $backupsDir . '/' . $preRestoreName;

        if (!mkdir($preRestorePath, 0755, true)) {
            return ApiResponse::create(500, 'backup.prerestore_failed')
                ->withMessage('Failed to create pre-restore backup');
        }

        // C15 15.3 — no "sync live public into the project" step: the project's own public/
        // IS the live one, so the current state below already includes it (same reasoning as
        // backupProject).

        // Copy current state to pre-restore backup
        $itemsToCopy = ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'];
        
        foreach ($itemsToCopy as $item) {
            $srcPath = $projectPath . '/' . $item;
            $dstPath = $preRestorePath . '/' . $item;
            
            if (!file_exists($srcPath)) {
                continue;
            }
            
            if (is_dir($srcPath)) {
                if (restore_copyDirectory($srcPath, $dstPath)) {
                    $preRestoreItems[] = $item;
                }
            } else {
                if (copy($srcPath, $dstPath)) {
                    $preRestoreItems[] = $item;
                }
            }
        }
    }

    // Now restore from the selected backup
    $itemsToCopy = ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'];
    $restoredItems = [];
    $errors = [];

    foreach ($itemsToCopy as $item) {
        $srcPath = $backupPath . '/' . $item;
        $dstPath = $projectPath . '/' . $item;
        
        if (!file_exists($srcPath)) {
            continue;
        }
        
        // Delete existing item if it exists
        if (file_exists($dstPath)) {
            if (is_dir($dstPath)) {
                restore_deleteDirectory($dstPath);
            } else {
                unlink($dstPath);
            }
        }
        
        // Copy from backup
        if (is_dir($srcPath)) {
            if (restore_copyDirectory($srcPath, $dstPath)) {
                $restoredItems[] = $item;
            } else {
                $errors[] = "Failed to restore directory: $item";
            }
        } else {
            if (copy($srcPath, $dstPath)) {
                $restoredItems[] = $item;
            } else {
                $errors[] = "Failed to restore file: $item";
            }
        }
    }

    // C15 15.3 — nothing to push out afterwards: restoring the project's public/ restores
    // the very directory it serves from. The old post-restore copy pushed it to the web
    // root, which was only ever the served project's live location.

    if (empty($restoredItems)) {
        return ApiResponse::create(500, 'restore.no_files_restored')
            ->withMessage('Failed to restore backup - no files restored')
            ->withData([
                'errors' => $errors,
                'pre_restore_backup' => $preRestoreName
            ]);
    }

    return ApiResponse::create(200, 'restore.success')
        ->withMessage("Backup restored successfully: $backupName")
        ->withData([
            'project' => $projectName,
            'restored_backup' => $backupName,
            'pre_restore_backup' => $preRestoreName,
            'restored_items' => $restoredItems,
            'pre_restore_items' => $preRestoreItems,
            'errors' => $errors
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_restoreBackup($trimParams->params(), $trimParams->additionalParams())->send();
}
