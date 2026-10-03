<?php
/**
 * Restore Backup Command
 * 
 * Restores a project from a specific backup. A snapshot of the current state is
 * taken first only when asked (create_backup).
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
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_entry, qs_copy_tree

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
    $bound = qs_bind_marker_project($params, 'restoreBackup', ['name']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

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

    // Every structure the restore would bring back is checked before anything
    // is touched, the optional pre-restore backup included: a refused restore
    // leaves the project exactly as it was.
    $unsafeStructureParam = qs_first_unsafe_param_in_tree($backupPath);
    if ($unsafeStructureParam !== null) {
        return qs_unsafe_structure_param_response($unsafeStructureParam);
    }

    // Check if user wants a pre-restore backup (default: false)
    $createBackup = $params['create_backup'] ?? false;
    if (is_string($createBackup)) {
        $createBackup = filter_var($createBackup, FILTER_VALIDATE_BOOLEAN);
    }

    // What a backup holds, and so what a restore brings back.
    $itemsToCopy = ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'];

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

        // Copy current state to pre-restore backup. The project's own public/ is the
        // one it serves from, so the current state includes it.
        foreach ($itemsToCopy as $item) {
            $srcPath = $projectPath . '/' . $item;
            $dstPath = $preRestorePath . '/' . $item;

            if (is_link($srcPath) || !file_exists($srcPath)) {
                continue; // missing, or a link, which a copy never follows (qs_copy_tree)
            }

            $copied = is_dir($srcPath) ? qs_copy_tree($srcPath, $dstPath)['ok'] : copy($srcPath, $dstPath);
            if ($copied) {
                $preRestoreItems[] = $item;
            }
        }
    }

    // Now restore from the selected backup. It keeps going past a failure, so that as
    // much as possible comes back, and names every item it could not restore whole.
    // The backup is only read: whatever happens here, it can be restored again.
    $restoredItems = [];
    $failedItems = [];
    $errors = [];
    $filesCopied = 0;

    foreach ($itemsToCopy as $item) {
        $srcPath = $backupPath . '/' . $item;
        $dstPath = $projectPath . '/' . $item;

        if (is_link($srcPath) || !file_exists($srcPath)) {
            continue; // missing, or a link, which a copy never follows (qs_copy_tree)
        }

        // The current item goes first, as the one delete removes an entry: a link is
        // removed and never followed, a read-only file is removed.
        $whole = true;
        $removal = qs_delete_entry($projectPath, $item);
        if (!$removal['ok']) {
            $whole = false;
            $errors[] = "Could not remove all of the current $item before restoring it: "
                . implode(', ', $removal['survived']);
        }

        if (is_dir($srcPath)) {
            $copy = qs_copy_tree($srcPath, $dstPath);
            $filesCopied += $copy['files'];
            if (!$copy['ok']) {
                $whole = false;
                $errors[] = "Could not restore all of $item: " . implode(', ', array_map(
                    static fn(string $rel): string => $rel === '.' ? $item : $item . '/' . $rel,
                    $copy['failed']
                ));
            }
        } elseif (@copy($srcPath, $dstPath)) {
            $filesCopied++;
        } else {
            $whole = false;
            $errors[] = "Could not restore $item";
        }

        if ($whole) {
            $restoredItems[] = $item;
        } else {
            $failedItems[] = $item;
        }
    }

    // A restore reads its backup and never changes it, so a failed one can be run
    // again once its cause is fixed. Both failure answers say so.
    $retry = 'The backup itself is untouched; fix the cause (most often a file held open by another process, '
        . 'or a permission the web server does not have) and restore it again.';

    if ($restoredItems === [] && $filesCopied === 0) {
        return ApiResponse::create(500, 'restore.no_files_restored')
            ->withMessage('Failed to restore backup - no files restored. ' . $retry)
            ->withData([
                'errors' => $errors,
                'failed_items' => $failedItems,
                'backup_intact' => true,
                'pre_restore_backup' => $preRestoreName
            ]);
    }

    if ($failedItems !== []) {
        return ApiResponse::create(500, 'restore.incomplete')
            ->withMessage("Backup $backupName was only partly restored: " . implode(', ', $failedItems)
                . ' could not be restored completely. ' . $retry)
            ->withData([
                'project' => $projectName,
                'restored_backup' => $backupName,
                'pre_restore_backup' => $preRestoreName,
                'restored_items' => $restoredItems,
                'failed_items' => $failedItems,
                'pre_restore_items' => $preRestoreItems,
                'backup_intact' => true,
                'errors' => $errors
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
