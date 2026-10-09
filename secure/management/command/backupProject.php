<?php
/**
 * Backup Project Command
 * 
 * Creates a timestamped backup of the current (or specified) project.
 * Backups are stored in the project's backups/ folder as folder copies of what
 * projectBackup.php says a backup holds.
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
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_tree, getDirectorySize, qs_tree_measure
require_once SECURE_FOLDER_PATH . '/src/functions/projectBackup.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache

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
    $maxBackups = isset($params['max_backups']) ? (int)$params['max_backups'] : 5;

    // CONTAINMENT (confused deputy): the target is BOUND to the URL
    // marker (PROJECT_NAME, authorized by the dispatcher — project.data, admin+ —
    // before this runs). A body `name` that disagrees is refused; body is optional
    // (advisory). You cannot back up a project you did not target/authorize.
    $bound = qs_bind_marker_project($params, 'backupProject', ['name']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    // Reject a traversal payload before the backup source/dest path is built
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

    $backupsDir = $projectPath . '/backups';

    // The older backups this one replaces, so that max_backups holds once it is
    // made. Chosen now, so the quota is checked against what the backup removes.
    $toPrune = qs_backup_prune_plan($backupsDir, $maxBackups);

    // The storage quota, before anything is written: what the backup copies, less
    // the older backups it removes, charged to the project's owner.
    if (qs_quota_storage_limited()) {
        $callerId = (string)(getCurrentUser()['id'] ?? '');
        $freed = 0;
        foreach ($toPrune as $old) {
            $freed += getDirectorySize($backupsDir . '/' . $old);
        }
        $breach = qs_quota_check_storage(qs_quota_storage_owner($projectName, $callerId),
            qs_backup_measure($projectPath), $callerId,
            ['project' => $projectName, 'kind' => 'backup', 'freed' => $freed]);
        if ($breach !== null) {
            return ApiResponse::create(507, 'quota.storage_exceeded')
                ->withMessage($breach['message'])
                ->withData($breach['data']);
        }
    }

    // Create backups folder if doesn't exist
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

    // The project's own public/ is the live one it serves from, so style edits and
    // asset uploads land there directly and are part of what is copied.
    $copy = qs_backup_copy($projectPath, $backupPath);

    // A backup that is not whole is not kept: restoring it would replace each item
    // of the project with a partial copy. No older backup is removed for it.
    if ($copy['copied'] === [] || $copy['failed'] !== []) {
        $removal = qs_delete_tree($backupPath);
        qs_invalidate_space_cache($projectName);
        $closing = 'Nothing was kept and no older backup was removed. The project itself is untouched; '
            . 'fix the cause (most often a file held open by another process, or a permission the web '
            . 'server does not have) and back it up again.'
            . ($removal['ok'] ? '' : " The incomplete copy could not be fully removed: delete backup $backupName before restoring anything.");
        $data = [
            'project' => $projectName,
            'failed_items' => $copy['failed'],
            'errors' => $copy['errors'],
            'project_intact' => true,
            'backup_kept' => false,
            'leftover' => $removal['ok'] ? null : $backupName,
        ];
        if ($copy['copied'] === []) {
            return ApiResponse::create(500, 'backup.no_files_copied')
                ->withMessage('Failed to create backup - no files copied. ' . $closing)
                ->withData($data);
        }
        return ApiResponse::create(500, 'backup.incomplete')
            ->withMessage("Backup $backupName could not be made completely: " . implode(', ', $copy['failed'])
                . ' could not be copied. ' . $closing)
            ->withData($data);
    }

    // Calculate backup size
    $measure = qs_tree_measure($backupPath);

    // Remove the older backups chosen above. One that cannot be fully removed does
    // not make this backup fail: it is whole, and the answer names the other.
    $deletedBackups = [];
    $pruneFailed = [];
    foreach ($toPrune as $oldBackup) {
        if (qs_delete_tree($backupsDir . '/' . $oldBackup)['ok']) {
            $deletedBackups[] = $oldBackup;
        } else {
            $pruneFailed[] = $oldBackup;
        }
    }
    qs_invalidate_space_cache($projectName);

    return ApiResponse::create(200, 'backup.created')
        ->withMessage("Backup created successfully: $backupName"
            . ($pruneFailed === [] ? '' : '. An older backup could not be fully removed: ' . implode(', ', $pruneFailed)
                . '; delete it from the backup list'))
        ->withData([
            'project' => $projectName,
            'backup' => [
                'name' => $backupName,
                'path' => $backupPath,
                'size' => $measure['bytes'],
                'size_formatted' => backup_formatSize($measure['bytes']),
                'files' => $measure['files'],
                'items' => $copy['copied'],
                'created' => $timestamp
            ],
            'total_backups' => count(glob($backupsDir . '/*', GLOB_ONLYDIR) ?: []),
            'max_backups' => $maxBackups,
            'deleted_old_backups' => $deletedBackups,
            'prune_failed' => $pruneFailed,
            'errors' => []
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_backupProject($trimParams->params(), $trimParams->additionalParams())->send();
}
