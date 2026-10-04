<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_json_write
/**
 * cloneProject Command
 * 
 * Duplicates an existing project to a new name.
 * Copies all project files (excluding backups), updates config,
 * and optionally switches to the new project.
 * 
 * @method POST
 * @route /management/cloneProject
 * @auth required (admin permission)
 * 
 * @param string $source Source project name (optional, default: active project)
 * @param string $name New project name (required)
 * @param bool $switch_to Switch to the cloned project after creation (optional, default: false)
 * 
 * @return ApiResponse Clone result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_copy_tree, qs_delete_tree_rollback, countDirectoryFiles
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_cloneProject(array $params = [], array $urlParams = []): ApiResponse {
    // C8 8.4 CONTAINMENT (confused-deputy / F6): the clone SOURCE is BOUND to the
    // URL marker (PROJECT_NAME, authorized by the dispatcher — project.data, admin+
    // on the source — before this runs). A body `source` that disagrees is refused;
    // it is optional. You cannot clone FROM a project you did not target/authorize.
    $bound = qs_bind_marker_project($params, 'cloneProject', ['source']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $sourceProject = $bound['project'];

    // Reject a traversal payload in the source name before the recursive copy
    // reads from it (beta.10 C3 F1-c).
    if (!is_valid_project_name($sourceProject)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid source project name')
            ->withErrors(['source' => 'Only letters, numbers, dash, underscore; must start with a letter']);
    }

    // Validate new project name
    // qs_param_string: `?name[]=x` reached trim() as a TypeError (F-C13-11).
    $newName = trim(qs_param_string($params, 'name', ''));
    
    if (empty($newName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('New project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Validate project name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/D', $newName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['name' => 'Must start with letter, contain only alphanumeric/dash/underscore, max 50 chars']);
    }
    
    // Reserved names
    $reserved = ['admin', 'management', 'src', 'logs', 'config', 'projects'];
    if (in_array(strtolower($newName), $reserved)) {
        return ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage("Project name '$newName' is reserved")
            ->withErrors(['name' => 'This name is reserved for system use']);
    }
    
    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Check source project exists
    $sourcePath = SECURE_FOLDER_PATH . '/projects/' . $sourceProject;
    
    if (!is_dir($sourcePath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Source project '$sourceProject' not found")
            ->withData(['searched_path' => SECURE_FOLDER_NAME . '/projects/' . $sourceProject]);
    }
    
    // Check target doesn't already exist
    $targetPath = SECURE_FOLDER_PATH . '/projects/' . $newName;
    
    if (is_dir($targetPath)) {
        return ApiResponse::create(409, 'resource.already_exists')
            ->withMessage("Project '$newName' already exists")
            ->withData(['existing_path' => SECURE_FOLDER_NAME . '/projects/' . $newName]);
    }
    
    // Every structure the clone would carry — pages, components, menu, footer,
    // consent layer, snippets — is checked before the target exists: a refused
    // clone creates nothing.
    $unsafeStructureParam = qs_first_unsafe_param_in_tree($sourcePath);
    if ($unsafeStructureParam !== null) {
        return qs_unsafe_structure_param_response($unsafeStructureParam);
    }

    // The storage quota, before the clone exists: a clone is a new project, so it
    // is charged to whoever makes it, who becomes its owner — as an import is.
    if (qs_quota_storage_limited()) {
        $incoming = max(0, getDirectorySize($sourcePath) - getDirectorySize($sourcePath . '/backups'));
        $breach = qs_quota_check_storage((string)(getCurrentUser()['id'] ?? ''), $incoming, null, ['kind' => 'clone']);
        if ($breach !== null) {
            return ApiResponse::create(507, 'quota.storage_exceeded')
                ->withMessage($breach['message'])
                ->withData($breach['data']);
        }
    }

    // Recursive copy, excluding backups/
    if (!qs_copy_tree($sourcePath, $targetPath, ['backups'])['ok']) {
        qs_delete_tree_rollback($targetPath, 'cloneProject');
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to clone project files');
    }
    
    // Update config.php — change SITE_NAME to new project name
    $configPath = $targetPath . '/config.php';
    if (file_exists($configPath)) {
        $config = include $configPath;
        if (is_array($config)) {
            $config['SITE_NAME'] = ucfirst(str_replace(['-', '_'], ' ', $newName));
            $refusal = qs_project_settings_guard($config, ['SITE_NAME']);
            if ($refusal !== null) {
                qs_delete_tree_rollback($targetPath, 'cloneProject');
                return $refusal;
            }
            $configContent = "<?php\n/**\n * Site Configuration\n * Cloned on " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";
            file_put_contents($configPath, $configContent, LOCK_EX);
        }
    }
    
    // Update site.name in translation files
    $translateDir = $targetPath . '/translate';
    $newSiteName = ucfirst(str_replace(['-', '_'], ' ', $newName));
    if (is_dir($translateDir)) {
        foreach (glob($translateDir . '/*.json') as $langFile) {
            $translations = json_decode(file_get_contents($langFile), true);
            if (is_array($translations) && isset($translations['site']['name'])) {
                $translations['site']['name'] = $newSiteName;
                qs_json_write($langFile, $translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, LOCK_EX);
            }
        }
    }
    
    // C8 8.4 BIRTH-WRITE: never inherit the source's members.json (the C10
    // clone-hijack — the copy carried the source's old owner, every member at
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
    error_log("cloneProject: '{$newName}' birth-written to owner '{$clonerId}'; source '{$sourceProject}' roster NOT carried over (C8 8.4 containment)");

    // A measurement left by an earlier project of the same name is not this one's.
    qs_invalidate_space_cache($newName);

    // Count cloned files for the response
    $fileCount = countDirectoryFiles($targetPath);

    $result = [
        'project' => $newName,
        'source' => $sourceProject,
        'path' => SECURE_FOLDER_NAME . '/projects/' . $newName,
        'site_name' => $newSiteName,
        'files_copied' => $fileCount,
        'cloned' => true,
        'owner_user_id' => $clonerId,
        'switched_to' => false
    ];
    
    // Register the clone in the cloner's own project index (users.php cache) —
    // like createProject. With switch_to, ONLY the cloner's per-user editing
    // target (selected_project) moves to the new project; a command NEVER repoints
    // any installation-wide pointer (the old switch_to tail here repointed one and
    // synced the live public dir: the same pre-C9 leftover
    // that createProject dropped in 8.0). The new project is edited at /p/<id>/.
    if ($clonerId !== null) {
        $written = qs_users_mutate(function (array &$cfg) use ($clonerId, $newName, $newSiteName, $switchTo) {
            if (!isset($cfg['users'][$clonerId])) {
                return false;
            }
            $cfg['users'][$clonerId]['projects'][$newName] = [
                'name'    => $newSiteName,
                'created' => date('Y-m-d'),
            ];
            if ($switchTo) {
                $cfg['users'][$clonerId]['selected_project'] = $newName;
            }
            return true;
        });
        $result['switched_to'] = ($written === true && $switchTo);
    }

    return ApiResponse::create(201, 'resource.created')
        ->withMessage("Project '$sourceProject' cloned to '$newName' successfully")
        ->withData($result);
}

// Direct execution block
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_cloneProject($trimParams->params(), $trimParams->additionalParams())->send();
}
