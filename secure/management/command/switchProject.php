<?php
/**
 * switchProject Command
 *
 * Sets the globally SERVED main project — the one deployment served at the site
 * ROOT (BASE_URL/). It writes `secure/management/config/target.php`, the served-main
 * pointer, and materializes that project's static surface into the base live
 * public/ so the root deployment serves the right assets.
 *
 * NOT the per-user editing target: that is `setSelectedProject` (which is what the
 * admin header picker and the dashboard call). Under the C9 fixed-main model every
 * NON-served project is edited + previewed live at `/p/<id>/` from its own folder,
 * with no copy anywhere; only the ROOT deployment needs materialized files, because
 * the webserver serves `/assets/…`, `/style/…`, `/build/…` and `sitemap.txt`
 * straight from the webroot while pages live-render from PROJECT_PATH.
 *
 * C8 8.1 — AUTHORIZATION (fixes a proven privilege escalation). This command used to
 * sit in the GLOBAL `system.admin` category with access 'owner', which hasPermission
 * resolves as "owns AT LEAST ONE project ANYWHERE" — never "owns the project you are
 * promoting". Since createProject is access 'any', ANY authenticated account could
 * mint that ownership in one call and then repoint the world-facing root at a project
 * it had no membership on — publishing a private project and bypassing the owner-only
 * setProjectVisibility. It is now PROJECT-SCOPED (`project.serve`, owner-only): the
 * target is the URL marker the dispatcher already authorized, so only an OWNER of
 * project X may make X the served main.
 *
 * @method POST
 * @route /management/p/<projectId>/switchProject
 * @auth required (project.serve — owner)
 *
 * @param string $project Optional advisory echo of the target; if present it MUST
 *                        match the URL marker (else 400 project.mismatch).
 *
 * @return ApiResponse Switch result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectPublicArtifacts.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_switchProject(array $params = [], array $urlParams = []): ApiResponse {
    // C8 8.1 CONTAINMENT (confused-deputy / F6), the deleteProject idiom: the served
    // main is BOUND to the URL marker (PROJECT_NAME, authorized by the dispatcher —
    // project.serve, OWNER-only — before this runs). A body `project` that disagrees
    // is refused; body is optional (advisory). You cannot promote a project you did
    // not target/authorize.
    $markerProject = defined('PROJECT_NAME') ? (string)PROJECT_NAME : '';
    if ($markerProject === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/switchProject');
    }
    $bodyProject = trim((string)($params['project'] ?? ''));
    if ($bodyProject !== '' && $bodyProject !== $markerProject) {
        return ApiResponse::create(400, 'project.mismatch')
            ->withMessage('The body project does not match the targeted project')
            ->withErrors([
                'project' => "Targeted '{$markerProject}' but body named '{$bodyProject}'",
            ]);
    }
    $projectName = $markerProject;

    // F1 — the shared validator (was a looser local regex that allowed a leading
    // digit/dash and unbounded length). Runs BEFORE any path is built from the name.
    if (!is_valid_project_name($projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['project' => 'Must start with a letter, contain only alphanumeric/dash/underscore, max 50 chars']);
    }

    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;

    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Project '$projectName' not found")
            ->withData([
                'requested_project' => $projectName,
                'expected_path' => 'secure/projects/' . $projectName
            ]);
    }

    // A project missing its engine files would break the ROOT site for everyone.
    $requiredFiles = [
        'config.php' => $projectPath . '/config.php',
        'routes.php' => $projectPath . '/routes.php'
    ];

    $missingFiles = [];
    foreach ($requiredFiles as $name => $path) {
        if (!file_exists($path)) {
            $missingFiles[] = $name;
        }
    }

    if (!empty($missingFiles)) {
        return ApiResponse::create(400, 'validation.incomplete_project')
            ->withMessage("Project '$projectName' is missing required files")
            ->withErrors(['missing_files' => $missingFiles]);
    }

    // Current served main (target.php)
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    $previousProject = qs_served_project();

    if ($previousProject === $projectName) {
        return ApiResponse::create(200, 'operation.no_change')
            ->withMessage("Project '$projectName' is already the served main")
            ->withData([
                'project' => $projectName,
                'was_already_active' => true
            ]);
    }

    // Write the served-main pointer with explicit sync so the file is fully written
    // before the response (a half-written target.php would break the root site).
    $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * \n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'project' => '" . addslashes($projectName) . "'\n];\n";

    $handle = fopen($targetFile, 'w');
    if ($handle === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to open target configuration for writing');
    }

    if (flock($handle, LOCK_EX)) {
        $bytesWritten = fwrite($handle, $targetContent);
        fflush($handle);  // Flush PHP buffers to OS
        flock($handle, LOCK_UN);
    } else {
        fclose($handle);
        return ApiResponse::create(500, 'server.file_lock_failed')
            ->withMessage('Failed to acquire lock on target configuration');
    }
    fclose($handle);

    if ($bytesWritten !== strlen($targetContent)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Target configuration write incomplete');
    }

    // Clear caches to ensure fresh read on next request
    clearstatcache(true, $targetFile);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($targetFile, true);
    }

    $result = [
        'project' => $projectName,
        'previous_project' => $previousProject,
        'target_updated' => true,
    ];

    // ---- materialize the new main's static surface into the BASE live public/ ----
    // The base is derived from PUBLIC_FOLDER_ROOT, NOT from PUBLIC_CONTENT_PATH: the
    // management dispatcher pre-points PUBLIC_CONTENT_PATH at the TARGETED project's
    // own public/ (it was not the served main when the request arrived), so using it
    // here would copy the project onto itself and leave the root untouched.
    $baseLivePublic = PUBLIC_FOLDER_SPACE !== ''
        ? PUBLIC_FOLDER_ROOT . '/' . PUBLIC_FOLDER_SPACE
        : PUBLIC_FOLDER_ROOT;

    $projectPublicPath = $projectPath . '/public';
    if (is_dir($projectPublicPath)) {
        $copyResult = copyProjectPublicFiles($projectPublicPath, $baseLivePublic);
        $result['public_files_copied'] = $copyResult['success'];
        $result['public_copy_details'] = $copyResult;
    } else {
        $result['public_files_copied'] = false;
        $result['public_copy_details'] = [
            'success' => false,
            'reason' => 'Project has no public/ folder'
        ];
    }

    // Regenerate the three generated client artifacts for the NEW main. The previous
    // project's registry/config/route-schema would be stale (they describe components,
    // endpoints and routes that may not exist here). Built via the C9 helper into the
    // project's OWN public/scripts (idempotent, the single generation path), then
    // copied into the base — the base scripts/ dir also holds the shipped engine
    // (qs.js), so it is never folder-synced, only these three files are placed.
    $regen = qs_regenerate_project_scripts($projectPath, $projectName);
    $result['scripts_regenerated'] = [
        'api_config'   => $regen['api'],
        'enums'        => $regen['enums'],
        'route_schema' => $regen['routes'],
    ];

    $baseScriptsDir = $baseLivePublic . '/scripts';
    if (!is_dir($baseScriptsDir)) {
        @mkdir($baseScriptsDir, 0755, true);
    }
    $placed = [];
    foreach (['qs-api-config.js', 'qs-enums.js', 'qs-route-schema.js'] as $generated) {
        $src = $regen['scriptsDir'] . '/' . $generated;
        if (is_file($src) && @copy($src, $baseScriptsDir . '/' . $generated)) {
            $placed[] = $generated;
        }
    }
    $result['scripts_placed_in_base'] = $placed;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Served main switched to project '$projectName'")
        ->withData($result);
}

/**
 * Copy a project's public files to the BASE live public directory (the root
 * deployment's served surface).
 *
 * Note: this only ever writes INTO the base live public/. C8 8.1 removed the former
 * reverse "sync live → previous project" step, which recursively DELETED and rebuilt
 * folders inside another project's directory on every switch. Each project's own
 * public/ is authoritative (C9), and the write-time mirrors in uploadAsset /
 * deleteAsset / editStyles keep it that way, so nothing has to be rescued at switch
 * time any more.
 *
 * @param string $source Project's public folder
 * @param string $destination Base live public folder
 * @return array Result with success status and details
 */
function copyProjectPublicFiles(string $source, string $destination): array {
    $result = [
        'success' => true,
        'copied' => [],
        'errors' => []
    ];

    // Folders to replace (clean destination first, then copy fresh from project)
    $foldersToSync = ['assets', 'style', 'build'];

    foreach ($foldersToSync as $folder) {
        $srcFolder = $source . '/' . $folder;
        $destFolder = $destination . '/' . $folder;

        if (!is_dir($srcFolder)) {
            continue;
        }

        // Clean destination first to avoid stale files from previous project
        if (is_dir($destFolder)) {
            deleteDirectoryRecursive($destFolder);
        }

        // Copy folder contents recursively
        $copySuccess = copyDirectoryContents($srcFolder, $destFolder);

        if ($copySuccess) {
            $result['copied'][] = $folder;
        } else {
            $result['errors'][] = "Failed to copy $folder";
            $result['success'] = false;
        }
    }

    // Copy individual files (sitemap.txt)
    $filesToCopy = ['sitemap.txt'];
    foreach ($filesToCopy as $file) {
        $srcFile = $source . '/' . $file;
        if (file_exists($srcFile)) {
            if (copy($srcFile, $destination . '/' . $file)) {
                $result['copied'][] = $file;
            }
        } else {
            // Remove stale file from previous project
            $destFile = $destination . '/' . $file;
            if (file_exists($destFile)) {
                unlink($destFile);
            }
        }
    }

    // Copy .htaccess if exists.
    // NOTE (C8 8.1, deliberately KEPT): this installs a project-authored .htaccess at
    // the webroot, while importProject refuses .htaccess entries as dangerous — an
    // inconsistency. Removing it was proposed and DEFERRED by Sangio: QuickSite must
    // stay deployable on Apache AND nginx (nginx never reads .htaccess), so the root
    // rewrite story needs a deliberate server-agnostic design pass rather than a
    // drive-by cut here. Tracked as an open question for the deploy concern.
    $htaccessSrc = $source . '/.htaccess';
    if (file_exists($htaccessSrc)) {
        $destHtaccess = $destination . '/.htaccess';
        if (copy($htaccessSrc, $destHtaccess)) {
            $result['copied'][] = '.htaccess';
            // Ensure FallbackResource paths include the URL space prefix
            if (PUBLIC_FOLDER_SPACE !== '') {
                $content = file_get_contents($destHtaccess);
                $space = trim(PUBLIC_FOLDER_SPACE, '/');
                // Normalize: strip any existing space prefix, then re-add it
                $content = preg_replace(
                    '#^(FallbackResource\s+)/' . preg_quote($space, '#') . '/#m',
                    '$1/',
                    $content
                );
                $content = preg_replace(
                    '#^(FallbackResource\s+)/#m',
                    '$1/' . $space . '/',
                    $content
                );
                file_put_contents($destHtaccess, $content);
            }
        }
    }

    return $result;
}

/**
 * Recursively copy directory contents
 */
function copyDirectoryContents(string $source, string $destination): bool {
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $destPath = $destination . '/' . $iterator->getSubPathname();

        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
        } else {
            // Skip backup files (e.g. favicon.png~)
            if (str_ends_with($item->getFilename(), '~')) {
                continue;
            }
            if (!copy($item->getPathname(), $destPath)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Recursively delete a directory and its contents
 */
function deleteDirectoryRecursive(string $dir): bool {
    if (!is_dir($dir)) {
        return true;
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

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_switchProject($trimParams->params(), $trimParams->additionalParams())->send();
}
