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
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

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

    // C8 CONTAINMENT (confused-deputy / F6): a project-scoped command is
    // AUTHORIZED against the URL marker project (PROJECT_NAME, bound by the
    // dispatcher after the owner-only members.json check). The destructive
    // target MUST be that same project — never a different one named only in
    // the request body. Bind the target to the authorized marker; a body
    // `name` that disagrees is refused outright (you cannot delete a project
    // you did not target/authorize).
    $markerProject = defined('PROJECT_NAME') ? (string)PROJECT_NAME : '';
    if ($markerProject !== '') {
        if ($projectName !== '' && $projectName !== $markerProject) {
            return ApiResponse::create(400, 'project.mismatch')
                ->withMessage('The targeted project does not match the project in the request body')
                ->withErrors(['name' => 'Must match the project in the URL']);
        }
        $projectName = $markerProject;
    }

    if (empty($projectName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Project name is required')
            ->withErrors(['name' => 'Required field']);
    }

    // Reject a traversal payload before it reaches the delete sink (beta.10 C3 F1-a).
    if (!is_valid_project_name($projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name')
            ->withErrors(['name' => 'Only letters, numbers, dash, underscore; must start with a letter']);
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

    // C8 8.3a membership cascade — capture BEFORE the directory (and the
    // members.json inside it) is destroyed: who must be notified, and the
    // project's display name for the notices.
    $cascadeMembers = null;
    $membersPath = $projectPath . '/config/members.json';
    if (is_file($membersPath)) {
        $cascadeMembers = json_decode((string)@file_get_contents($membersPath), true);
        if (!is_array($cascadeMembers)) {
            $cascadeMembers = null;
            error_log("deleteProject: members.json for '{$projectName}' is unreadable — no membership cascade possible");
        }
    }
    $cascadeSiteName = qs_project_site_name($projectName);
    $callerId = getCurrentUser()['id'] ?? null;

    // Delete the project directory recursively
    $deleted = deleteDirectory($projectPath);
    
    if (!$deleted) {
        return ApiResponse::create(500, 'server.delete_failed')
            ->withMessage('Failed to delete project directory')
            ->withData(['path' => $projectPath]);
    }
    
    // If this was the SERVED main, clear the pointer — never auto-promote.
    //
    // C8 8.1: this used to promote `reset($projects)` — the first project in scandir
    // order — to the world-facing root, regardless of the deleter's membership on it
    // and regardless of its `visibility`. That silently published a stranger's PRIVATE
    // project, the same exposure class as the switchProject escalation fixed in this
    // slice (and it bypassed setProjectVisibility just as thoroughly). Promotion is a
    // deliberate, owner-authorized act: it belongs to switchProject
    // (project.serve, owner-only) and nowhere else. Leaving the root unserved is the
    // safe, honest state — the deployer picks the next main explicitly.
    if ($activeProject === $projectName) {
        $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n * WARNING: the served project was deleted — no main is served.\n * Set one with POST /management/p/<projectId>/switchProject (owner only).\n */\n\nreturn [\n    'project' => ''\n];\n";
        file_put_contents($targetFile, $targetContent, LOCK_EX);
        clearstatcache(true, $targetFile);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($targetFile, true);
        }
    }
    
    // C8 8.3a membership cascade — the project is gone; update every affected
    // user's status-mirror cache in ONE users.php write. The DELETING owner's
    // own entry is plainly removed (self-initiated exits leave no tombstone);
    // every other member AND every ENGAGED pending party gets a dismissable
    // 'deleted' notice (Sangio R3: they must know the project died — they
    // were not refused). Engaged = an invitee (direction 'invite') or a
    // self-requester (direction 'request', by == themselves). A SPONSORED,
    // not-yet-validated proposal target (8.3b) was never engaged — never told
    // anything — so the cascade must not conjure a notice for a project they
    // never knew existed. Cache failure is silent by ruling (error_log only):
    // access is already correct — the authority died with the folder.
    $cascade = ['members_notified' => 0, 'invitees_notified' => 0, 'self_removed' => false];
    if ($cascadeMembers !== null) {
        $engaged = [];
        foreach (($cascadeMembers['invitations'] ?? []) as $iuid => $inv) {
            $direction = is_array($inv) ? ($inv['direction'] ?? 'invite') : 'invite';
            if ($direction === 'invite' || (is_array($inv) && ($inv['by'] ?? null) === (string)$iuid)) {
                $engaged[] = (string)$iuid;
            }
        }
        $affected = array_merge(
            array_keys($cascadeMembers['members'] ?? []),
            $engaged
        );
        $memberIds = $cascadeMembers['members'] ?? [];
        $today = date('Y-m-d');
        $written = qs_users_mutate(function (array &$cfg) use ($affected, $memberIds, $projectName, $cascadeSiteName, $callerId, $today, &$cascade) {
            foreach (array_unique($affected) as $uid) {
                $uid = (string)$uid;
                if (!isset($cfg['users'][$uid])) {
                    continue; // account gone — nothing to notify
                }
                if ($uid === $callerId) {
                    unset($cfg['users'][$uid]['projects'][$projectName]);
                    $cascade['self_removed'] = true;
                    continue;
                }
                $existingName = $cfg['users'][$uid]['projects'][$projectName]['name'] ?? null;
                $cfg['users'][$uid]['projects'][$projectName] = [
                    'name'   => is_string($existingName) && $existingName !== '' ? $existingName : $cascadeSiteName,
                    'status' => 'deleted',
                    'at'     => $today,
                ];
                if (isset($memberIds[$uid])) {
                    $cascade['members_notified']++;
                } else {
                    $cascade['invitees_notified']++;
                }
            }
            return true;
        });
        if ($written !== true) {
            error_log("deleteProject: membership-cascade cache write failed for '{$projectName}'");
        }
    }

    $result = [
        'project' => $projectName,
        'deleted' => true,
        'files_deleted' => $stats['files'],
        'directories_deleted' => $stats['directories'],
        'size_freed' => formatBytes($stats['size']),
        'size_bytes' => $stats['size'],
        'membership_cascade' => $cascade
    ];
    
    if ($activeProject === $projectName) {
        $result['served_main_cleared'] = true;
        $result['warning'] = 'The deleted project was the served main. No project is served at the site root until an owner sets one with switchProject.';
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