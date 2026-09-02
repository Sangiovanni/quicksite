<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_param_string
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
 *
 * @return ApiResponse Deletion result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_tree

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_deleteProject(array $params = [], array $urlParams = []): ApiResponse {
    // Validate project name
    // qs_param_string: `?name[]=x` reached trim() as a TypeError (F-C13-11).
    $projectName = trim(qs_param_string($params, 'name', ''));

    // C8 CONTAINMENT (confused-deputy / F6): a project-scoped command is
    // AUTHORIZED against the URL marker project (PROJECT_NAME, bound by the
    // dispatcher after the owner-only members.json check). The destructive
    // target MUST be that same project — never a different one named only in
    // the request body. Bind the target to the authorized marker; a body
    // `name` that disagrees is refused outright (you cannot delete a project
    // you did not target/authorize).
    $bound = qs_bind_marker_project($params, 'deleteProject', ['name']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

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
    
    // Check project exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;

    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Project '$projectName' not found")
            ->withData(['searched_path' => SECURE_FOLDER_NAME . '/projects/' . $projectName]);
    }

    // C15 15.3 — no project is "the active one" installation-wide any more, so there is no
    // active-project guard and no `force` escape hatch for it. Deleting a project is gated
    // by ownership of THAT project (project.delete) and by confirm=true, nothing else.

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

    // Delete the project directory recursively.
    //
    // qs_delete_tree keeps going past a failure and reports what is left, which
    // is the whole point here: a delete that stopped at the first locked file
    // used to answer a bare "failed" while most of the project was already
    // gone. The caller could not tell a project that is intact from one that is
    // half-removed, and neither could the next command to touch it.
    //
    // ⚠ THREE ENTRIES GO LAST, and that is load-bearing rather than tidy.
    // Between them they are what makes this project addressable and authorized,
    // which is to say: what a RETRY needs.
    //
    //   config.php, routes.php   the project context boots from these
    //                            (projectContext.php dies without either), so a
    //                            project missing them cannot be the target of
    //                            any project-scoped command at all
    //   config/                  holds members.json, the authoritative
    //                            permission gate (users.php is only a cache)
    //
    // In scandir order all three came first, so any failure further down the
    // tree destroyed them on the way past and left a project that could no
    // longer be named or authorized: the retry answered "Insufficient
    // permissions", then "Missing file: config.php", and the leftovers could
    // only be cleared from the filesystem by hand. Deferred, they are removed
    // only once everything else is already gone — and kept untouched when
    // anything is not, which is the part ordering alone does not buy
    // (qs_delete_tree continues past failures by design).
    $removal = qs_delete_tree($projectPath, ['config', 'config.php', 'routes.php']);

    if (!$removal['ok']) {
        // beta.10 C13 F-C13-18. This returned `path` => the absolute project
        // directory, ungated. The central scrub in ApiResponse would render it
        // "secure/projects/<id>", but the id is something the caller just named
        // and the folder convention adds nothing — so say what actually failed
        // and send the path where the person who can act on it is looking. Same
        // treatment C12 gave qs_project_context_die().
        //
        // `survived` is PROJECT-RELATIVE for the same reason (qs_delete_tree
        // builds it that way): the person reading the response asked to delete
        // this project and needs to know which of ITS files are still there,
        // not where the install lives on disk.
        $partial = $removal['files'] > 0 || $removal['dirs'] > 0;
        error_log("deleteProject: '{$projectName}' not fully removed — "
            . count($removal['survived']) . " path(s) survived under {$projectPath}");
        return ApiResponse::create(500, 'server.delete_failed')
            ->withMessage($partial
                ? 'Project only partially deleted'
                : 'Failed to delete project directory')
            ->withData([
                'project'             => $projectName,
                'partial'             => $partial,
                'files_deleted'       => $removal['files'],
                'directories_deleted' => $removal['dirs'],
                'survived'            => $removal['survived'],
                // Kept ON PURPOSE, not blocked — the distinction matters when
                // someone reads this list looking for what to unlock.
                'retained'            => $removal['retained'],
                'hint'                => 'These paths could not be removed — most often a file held open by '
                    . 'another process, or a permission the web server does not have. Release them and run '
                    . 'deleteProject again; it is safe to repeat, and "retained" was kept deliberately so the '
                    . 'project still has an owner to authorize that retry.',
            ]);
    }

    // C10 10.1b — destroy this project's command log with it. The log lives in
    // secure/logs/p/<id>/ (deliberately OUTSIDE the project folder, so exports,
    // clones and backups never carry it). A project id is a folder NAME and can
    // therefore be re-used: without this purge, a newly created project of the
    // same name would inherit the previous project's audit trail.
    require_once SECURE_FOLDER_PATH . '/src/functions/LoggingManagement.php';
    $logDir = qs_log_dir($projectName);
    $logsPurged = 0;
    if ($logDir !== null && is_dir($logDir)) {
        foreach (glob($logDir . '/*') ?: [] as $logFile) {
            if (is_file($logFile) && @unlink($logFile)) { $logsPurged++; }
        }
        @rmdir($logDir);
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
        'size_freed' => qs_format_size($stats['size']),
        'size_bytes' => $stats['size'],
        'log_files_purged' => $logsPurged,
        'membership_cascade' => $cascade
    ];

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

// (Removed, beta.11) A local deleteDirectory(). It shadowed the shared one in
// FileSystem.php — two global functions of one name with DIFFERENT semantics
// (the shared one ignored failures and returned rmdir's result; this one bailed
// on the first), so which behaviour ran depended on which file a process had
// loaded, and loading both was a redeclare fatal. Deleting now goes through
// qs_delete_tree(), required at the top of this file. Exactly the collision the
// note below records for formatBytes — same class, same file, twelve lines
// apart.

// (Removed, S2.9) A local formatBytes(). Byte formatting lives in
// qs_format_size() in utilsManagement.php, already required at the top of this
// file. Three copies existed; they disagreed with each other and with the
// shared one above 100 units, and none of them had a TB unit — so a large
// deletion reported "1024 GB". Being global functions in command files, two of
// them were also a latent redeclare collision for any process that loaded both.

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteProject($trimParams->params(), $trimParams->additionalParams())->send();
}