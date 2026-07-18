<?php
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
    $sourceProject = trim((string)($params['source'] ?? ''));
    $markerProject = defined('PROJECT_NAME') ? (string)PROJECT_NAME : '';
    if ($markerProject !== '') {
        if ($sourceProject !== '' && $sourceProject !== $markerProject) {
            return ApiResponse::create(400, 'project.mismatch')
                ->withMessage('The clone source does not match the project in the request body')
                ->withErrors(['source' => 'Must match the project in the URL']);
        }
        $sourceProject = $markerProject;
    }
    if ($sourceProject === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('No source project specified')
            ->withErrors(['source' => 'Specify a source project']);
    }

    // Reject a traversal payload in the source name before the recursive copy
    // reads from it (beta.10 C3 F1-c).
    if (!is_valid_project_name($sourceProject)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid source project name')
            ->withErrors(['source' => 'Only letters, numbers, dash, underscore; must start with a letter']);
    }

    // Validate new project name
    $newName = trim($params['name'] ?? '');
    
    if (empty($newName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('New project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Validate project name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', $newName)) {
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
            ->withData(['searched_path' => 'secure/projects/' . $sourceProject]);
    }
    
    // Check target doesn't already exist
    $targetPath = SECURE_FOLDER_PATH . '/projects/' . $newName;
    
    if (is_dir($targetPath)) {
        return ApiResponse::create(409, 'resource.already_exists')
            ->withMessage("Project '$newName' already exists")
            ->withData(['existing_path' => 'secure/projects/' . $newName]);
    }
    
    // Recursive copy, excluding backups/
    $excludeDirs = ['backups'];
    
    if (!cloneProjectDirectory($sourcePath, $targetPath, $excludeDirs)) {
        // Cleanup on failure
        if (is_dir($targetPath)) {
            deleteDirectoryRecursive($targetPath);
        }
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage('Failed to clone project files');
    }
    
    // Update config.php — change SITE_NAME to new project name
    $configPath = $targetPath . '/config.php';
    if (file_exists($configPath)) {
        $config = include $configPath;
        if (is_array($config)) {
            $config['SITE_NAME'] = ucfirst(str_replace(['-', '_'], ' ', $newName));
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
                file_put_contents($langFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
        deleteDirectoryRecursive($targetPath);
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to initialise cloned project membership');
    }
    @unlink($targetPath . '/config/members.json.lock'); // stale sidecar if it was copied
    error_log("cloneProject: '{$newName}' birth-written to owner '{$clonerId}'; source '{$sourceProject}' roster NOT carried over (C8 8.4 containment)");

    // Count cloned files for the response
    $fileCount = countFilesRecursive($targetPath);

    $result = [
        'project' => $newName,
        'source' => $sourceProject,
        'path' => 'secure/projects/' . $newName,
        'site_name' => $newSiteName,
        'files_copied' => $fileCount,
        'cloned' => true,
        'owner_user_id' => $clonerId,
        'switched_to' => false
    ];
    
    // Register the clone in the cloner's own project index (users.php cache) —
    // like createProject. With switch_to, ONLY the cloner's per-user editing
    // target (selected_project) moves to the new project; a command NEVER repoints
    // the served main (target.php) — the C9 fixed-main model (the old switch_to
    // tail here wrote target.php + synced live public: the same pre-C9 leftover
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

/**
 * Recursively copy a directory, excluding specified subdirectory names
 */
function cloneProjectDirectory(string $source, string $dest, array $excludeDirs): bool {
    if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
        return false;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $subPath = $iterator->getSubPathname();
        
        // Check if any part of the path starts with an excluded dir
        $skip = false;
        foreach ($excludeDirs as $excludeDir) {
            if (str_starts_with($subPath, $excludeDir . DIRECTORY_SEPARATOR) || $subPath === $excludeDir) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;
        
        $destPath = $dest . DIRECTORY_SEPARATOR . $subPath;
        
        if ($item->isDir()) {
            if (!is_dir($destPath) && !mkdir($destPath, 0755, true)) {
                return false;
            }
        } else {
            if (!copy($item->getPathname(), $destPath)) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Recursively delete a directory
 */
function deleteDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Count files in a directory recursively
 */
function countFilesRecursive(string $dir): int {
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) $count++;
    }
    return $count;
}

// Direct execution block
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_cloneProject($trimParams->params(), $trimParams->additionalParams())->send();
}
