<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
/**
 * cloneProject Command
 * 
 * Duplicates an existing project as a new one: a new id, always drawn
 * (qs_project_id_claim()), and the name the caller gives.
 * Copies the project's files as they are, except what belongs to the source:
 * its backups, its build and its saved exports, and its OAuth keys (the
 * clone's addresses differ, so its sign-in needs keys registered for them);
 * the answer names the providers whose keys must be created again. Optionally
 * switches to the new project.
 * 
 * @method POST
 * @route /management/p/<projectId>/cloneProject
 * @auth required (admin permission)
 * 
 * @param string $source Source project id (optional echo of the URL marker)
 * @param string $name The clone's name (optional, default: the source's name)
 * @param bool $switch_to Switch to the cloned project after creation (optional, default: false)
 * 
 * @return ApiResponse Clone result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_copy_tree, qs_delete_tree_rollback, countDirectoryFiles
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache
require_once SECURE_FOLDER_PATH . '/src/functions/projectIdentity.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_cloneProject(array $params = [], array $urlParams = []): ApiResponse {
    // CONTAINMENT (confused deputy): the clone SOURCE is BOUND to the
    // URL marker (PROJECT_NAME, authorized by the dispatcher — project.data, admin+
    // on the source — before this runs). A body `source` that disagrees is refused;
    // it is optional. You cannot clone FROM a project you did not target/authorize.
    $bound = qs_bind_marker_project($params, 'cloneProject', ['source']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $sourceProject = $bound['project'];

    // Reject a traversal payload in the source name before the recursive copy
    // reads from it.
    if (!is_valid_project_name($sourceProject)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid source project name')
            ->withErrors(['source' => 'Only letters, numbers, dash, underscore; must start with a letter']);
    }

    // The clone's name: the one sent, else the source's (its name, else its id).
    // The clone's id is drawn below, never taken from the request.
    $problem = qs_project_name_problem($params['name'] ?? qs_project_display_name($sourceProject), $cloneName);
    if ($problem !== null) {
        return qs_project_name_refusal($problem);
    }

    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Check source project exists
    $sourcePath = SECURE_FOLDER_PATH . '/projects/' . $sourceProject;
    
    if (!is_dir($sourcePath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Source project '$sourceProject' not found")
            ->withData(['searched_path' => SECURE_FOLDER_NAME . '/projects/' . $sourceProject]);
    }
    
    // Every structure the clone would carry — pages, components, menu, footer,
    // consent layer, snippets — is checked before the target exists: a refused
    // clone creates nothing.
    $unsafeStructureParam = qs_first_unsafe_param_in_tree($sourcePath);
    if ($unsafeStructureParam !== null) {
        return qs_unsafe_structure_param_response($unsafeStructureParam);
    }

    // What a clone never carries: the source's backups, build and saved exports (they belong to
    // the source; first run's copy leaves the same three behind), and its OAuth keys. A build and a
    // saved export hold the keys too, so leaving them is also what keeps the keys from travelling.
    $notCarried = ['backups', 'exports', 'qs_build'];
    $keyFile = 'oauth-secrets.json';

    // The storage quota, before the clone exists: a clone is a new project, so it
    // is charged to whoever makes it, who becomes its owner — as an import is.
    if (qs_quota_storage_limited()) {
        $incoming = getDirectorySize($sourcePath) - (int) @filesize($sourcePath . '/data/' . $keyFile);
        foreach ($notCarried as $item) {
            $incoming -= getDirectorySize($sourcePath . '/' . $item);
        }
        $incoming = max(0, $incoming);
        $breach = qs_quota_check_storage((string)(getCurrentUser()['id'] ?? ''), $incoming, null, ['kind' => 'clone']);
        if ($breach !== null) {
            return ApiResponse::create(507, 'quota.storage_exceeded')
                ->withMessage($breach['message'])
                ->withData($breach['data']);
        }
    }

    // The providers whose keys stay behind, read before anything is copied: the clone's answer
    // names them, so the clone's owner knows its sign-in needs keys of its own.
    require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';
    $keysNotCopied = array_map('strval', array_keys(qs_oauth_keys_read()));

    // A new id, drawn, its folder created exclusively; then the recursive copy
    // into it: everything but what a clone never carries, and data/ without the
    // key file, so the keys are never written into the clone at all. A data/
    // that is a link is left out, as the copy leaves out every link.
    $newName = qs_project_id_claim();
    if ($newName === null) {
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to clone project files');
    }
    $targetPath = SECURE_FOLDER_PATH . '/projects/' . $newName;
    $copied = qs_copy_tree($sourcePath, $targetPath, array_merge($notCarried, ['data']))['ok'];
    if ($copied && is_dir($sourcePath . '/data') && !qs_delete_tree_leads_elsewhere($sourcePath, 'data', $sourcePath . '/data')) {
        $copied = qs_copy_tree($sourcePath . '/data', $targetPath . '/data', [$keyFile])['ok'];
    }
    if (!$copied) {
        qs_delete_tree_rollback($targetPath, 'cloneProject');
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to clone project files');
    }
    
    // The clone's name, in its own file. The site itself is copied as it is: its
    // config.php and its translations (site.name, the text visitors read) are
    // the source's, for the clone's owner to change.
    if (!qs_project_name_write($targetPath, $cloneName)) {
        qs_delete_tree_rollback($targetPath, 'cloneProject');
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write the clone\'s name');
    }

    // BIRTH-WRITE: never inherit the source's members.json (a clone hijack —
    // the copy would carry the source's old owner, every member at
    // their role, and every pending invitation). Overwrite it with a fresh trust
    // file: the CLONER is the sole owner, no members, no invitations, private,
    // closed. The source roster is intentionally discarded (a clone is a new,
    // independent project — re-invite collaborators explicitly).
    require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    $clonerId = getCurrentUser()['id'] ?? null;
    if (!qs_project_birth_write_members($targetPath, $clonerId)) {
        // Files exist but the trust file could not be minted — an ownerless
        // project is inaccessible; roll back rather than orphan it.
        qs_delete_tree_rollback($targetPath, 'cloneProject');
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to initialise cloned project membership');
    }
    @unlink($targetPath . '/config/members.json.lock'); // stale sidecar if it was copied
    error_log("cloneProject: '{$newName}' birth-written to owner '{$clonerId}'; source '{$sourceProject}' roster NOT carried over (containment)");

    // A measurement left under the same id is not this project's.
    qs_invalidate_space_cache($newName);

    // Count cloned files for the response
    $fileCount = countDirectoryFiles($targetPath);

    $result = [
        'project' => $newName,
        'name' => $cloneName,
        'source' => $sourceProject,
        'path' => SECURE_FOLDER_NAME . '/projects/' . $newName,
        'files_copied' => $fileCount,
        'cloned' => true,
        'owner_user_id' => $clonerId,
        'switched_to' => false,
        // The providers the source had OAuth keys for, none of them copied: [] when it had none.
        'oauth_keys_not_copied' => $keysNotCopied,
    ];
    
    // Register the clone in the cloner's own project index (users.php cache, no
    // name: read from the project when shown) — like createProject. With switch_to, ONLY the cloner's per-user editing
    // target (selected_project) moves to the new project; a command NEVER repoints
    // any installation-wide pointer, nor syncs the live public dir — as in
    // createProject. The new project is edited at /p/<id>/.
    if ($clonerId !== null) {
        $written = qs_users_mutate(function (array &$cfg) use ($clonerId, $newName, $switchTo) {
            if (!isset($cfg['users'][$clonerId])) {
                return false;
            }
            $cfg['users'][$clonerId]['projects'][$newName] = qs_membership_mirror_entry('member', date('Y-m-d'));
            if ($switchTo) {
                $cfg['users'][$clonerId]['selected_project'] = $newName;
            }
            return true;
        });
        $result['switched_to'] = ($written === true && $switchTo);
    }

    return ApiResponse::create(201, 'resource.created')
        ->withMessage("Project '" . qs_project_label($sourceProject) . "' cloned to '$cloneName #$newName' successfully"
            . ($keysNotCopied !== []
                ? '. Its OAuth keys were not copied: create keys for ' . implode(', ', $keysNotCopied)
                    . ' on the clone\'s OAuth providers page, for the clone\'s own addresses.'
                : ''))
        ->withData($result);
}

// Direct execution block
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_cloneProject($trimParams->params(), $trimParams->additionalParams())->send();
}
