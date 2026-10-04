<?php
/**
 * Restore Backup Command
 * 
 * Restores a project from a specific backup. A snapshot of the current state is
 * taken first only when asked (create_backup), and the backup is deleted once it
 * is restored whole only when asked (delete_backup).
 * 
 * @method POST
 * @route /management/restoreBackup
 * @auth required
 * @param string $backup Required - backup name (timestamp folder)
 * @param bool $create_backup Optional - snapshot the current state first (default false)
 * @param bool $delete_backup Optional - delete the backup once it is restored whole (default false)
 * @param string $name Optional - project name (defaults to active project)
 * @return ApiResponse Restore result info
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_tree, getDirectorySize
require_once SECURE_FOLDER_PATH . '/src/functions/projectBackup.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache

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

    // Two optional booleans: a snapshot of the current state first, and deleting
    // the backup once it is restored whole.
    $flag = static function ($value): bool {
        return is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (bool) $value;
    };
    $createBackup = $flag($params['create_backup'] ?? false);
    $deleteBackup = $flag($params['delete_backup'] ?? false);

    // What the backup brings back: only the items it holds replace the project's.
    $items = qs_backup_items_in($backupPath);

    // The storage quota, before anything is touched: what comes back and the
    // snapshot, less what they replace and, when asked, the backup itself. A
    // restore that does not fit is refused, and the refusal names both ways out.
    if (qs_quota_storage_limited()) {
        $callerId = (string)(getCurrentUser()['id'] ?? '');
        $incoming = qs_backup_measure($backupPath, $items) + ($createBackup ? qs_backup_measure($projectPath) : 0);
        $freed = qs_backup_measure($projectPath, $items) + ($deleteBackup ? getDirectorySize($backupPath) : 0);
        $breach = qs_quota_check_storage(qs_quota_storage_owner($projectName, $callerId), $incoming, $callerId,
            ['project' => $projectName, 'kind' => 'restore', 'freed' => $freed]);
        if ($breach !== null) {
            return ApiResponse::create(507, 'quota.storage_exceeded')
                ->withMessage($breach['message'])
                ->withData($breach['data']);
        }
    }

    // A restore never changes its backup, so a failed one can be run again once its
    // cause is fixed. Every failure answer says so.
    $retry = 'The backup itself is untouched; fix the cause (most often a file held open by another process, '
        . 'or a permission the web server does not have) and restore it again.';

    $preRestoreName = null;
    $preRestoreItems = [];

    if ($createBackup) {
        // The snapshot is the user's way back: a restore that cannot make it whole
        // does not go on, and leaves nothing of it behind.
        $preRestoreName = 'pre-restore_' . date('Y-m-d_H-i-s');
        $preRestorePath = $backupsDir . '/' . $preRestoreName;

        if (!mkdir($preRestorePath, 0755, true)) {
            return ApiResponse::create(500, 'backup.prerestore_failed')
                ->withMessage('Failed to create pre-restore backup');
        }

        $snapshot = qs_backup_copy($projectPath, $preRestorePath);
        if ($snapshot['failed'] !== []) {
            qs_delete_tree($preRestorePath);
            qs_invalidate_space_cache($projectName);
            return ApiResponse::create(500, 'backup.prerestore_failed')
                ->withMessage('The copy of the current state could not be made completely: '
                    . implode(', ', $snapshot['failed']) . ' could not be copied, so nothing was restored. '
                    . 'The project is untouched. ' . $retry)
                ->withData([
                    'failed_items' => $snapshot['failed'],
                    'errors' => $snapshot['errors'],
                    'project_intact' => true,
                    'backup_intact' => true
                ]);
        }
        $preRestoreItems = $snapshot['copied'];
    }

    // Now restore from the selected backup. It keeps going past a failure, so that as
    // much as possible comes back, and names every item it could not restore whole.
    $restoredItems = [];
    $failedItems = [];
    $errors = [];
    $filesCopied = 0;

    foreach ($items as $item) {
        // The current item goes first, as the one delete removes an entry: a link is
        // removed and never followed, a read-only file is removed. config/ keeps what
        // a backup never holds.
        $whole = true;
        $removal = qs_backup_remove_item($projectPath, $item);
        if (!$removal['ok']) {
            $whole = false;
            $errors[] = "Could not remove all of the current $item before restoring it: "
                . implode(', ', $removal['survived']);
        }

        $copy = qs_backup_copy_item($backupPath, $projectPath, $item);
        $filesCopied += $copy['files'];
        if (!$copy['ok']) {
            $whole = false;
            $errors[] = $copy['failed'] === [$item]
                ? "Could not restore $item"
                : "Could not restore all of $item: " . implode(', ', $copy['failed']);
        }

        if ($whole) {
            $restoredItems[] = $item;
        } else {
            $failedItems[] = $item;
        }
    }
    qs_invalidate_space_cache($projectName);

    if ($restoredItems === [] && $filesCopied === 0) {
        return ApiResponse::create(500, 'restore.no_files_restored')
            ->withMessage('Failed to restore backup - no files restored. ' . $retry)
            ->withData([
                'errors' => $errors,
                'failed_items' => $failedItems,
                'backup_intact' => true,
                'backup_deleted' => false,
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
                'backup_deleted' => false,
                'errors' => $errors
            ]);
    }

    // Restored whole: the backup goes only now, when asked.
    $backupDeleted = false;
    $deleteNote = '';
    if ($deleteBackup) {
        $backupDeleted = qs_delete_tree($backupPath)['ok'];
        qs_invalidate_space_cache($projectName);
        $deleteNote = $backupDeleted
            ? '. The backup was deleted'
            : '. The backup could not be fully deleted; delete it from the backup list';
    }

    return ApiResponse::create(200, 'restore.success')
        ->withMessage("Backup restored successfully: $backupName" . $deleteNote)
        ->withData([
            'project' => $projectName,
            'restored_backup' => $backupName,
            'pre_restore_backup' => $preRestoreName,
            'restored_items' => $restoredItems,
            'pre_restore_items' => $preRestoreItems,
            'backup_deleted' => $backupDeleted,
            'errors' => $errors
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_restoreBackup($trimParams->params(), $trimParams->additionalParams())->send();
}
