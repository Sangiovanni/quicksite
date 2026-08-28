<?php
/**
 * Deploy Build Command - Copies a build to a target root directory
 *
 * Method: POST
 * Endpoint: /management/deployBuild
 *
 * Parameters:
 * - name: (optional) Build folder name. Retention is N = 1, so the project has
 *               one build or none and this command finds it on its own. Supply
 *               the name only to assert WHICH build you meant; a name that is
 *               not the current build is a 404 rather than a silent substitution.
 * - targetPath: Absolute path to the root directory where the build will be deployed
 *               The build's public and secure folders will be placed inside this path.
 *               Example: /var/www/mysite -> creates /var/www/mysite/{publicFolder}/ and /var/www/mysite/{secureFolder}/
 * - overwrite: (optional) If true, overwrite existing files (default: false)
 *              When false, the command scans for file conflicts first and returns them.
 * - acceptRouteCollisions: (optional) If true, deploy even though one of the
 *              site's routes is shadowed by a directory that already exists at
 *              the target. Default false, which refuses with the collisions named.
 *
 * THREE INDEPENDENT GATES, all of which must pass:
 *   1. deploy.php    — may this installation deploy at all? ABSENT MEANS NO.
 *                      (src/functions/deployPolicy.php)
 *   2. roles.php     — may this caller? `deployBuild` is alone in the `deploy`
 *                      category, granted to admin and owner.
 *   3. deploy-roots.php — where may it write? Absent ⇒ SERVER_ROOT only.
 *
 * SECURITY NOTE:
 * - This command allows copying to arbitrary paths on the filesystem
 * - Protect your API token - anyone with access can deploy anywhere the PHP process can write
 * - Path traversal attempts (..) are blocked
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/deployPolicy.php';   // qs_deploy_allowed
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_build_* (the one build-location derivation)

// === GATE 1: IS DEPLOY ENABLED ON THIS INSTALLATION AT ALL? ===
// Asked FIRST, before any parameter is read, so a disabled install answers the
// same way whatever it is asked — no probing the target allowlist or the build
// inventory through the shape of a refusal. Absent config ⇒ denied.
if (!qs_deploy_allowed()) {
    ApiResponse::create(403, 'deploy.disabled')
        ->withMessage('Deploying is disabled on this installation')
        ->withData([
            'hint' => 'The operator enables it on the server: copy secure/management/config/deploy.php.example to deploy.php and set allow_deploy => true (setup.sh / setup.bat offers this). Building, downloading and deleting a build are unaffected.',
        ])
        ->send();
}

$params = $trimParametersManagement->params();
$buildName = $params['name'] ?? null;
$targetPath = $params['targetPath'] ?? null;
// Cast overwrite to boolean (form checkboxes send "true"/"false" strings)
$rawOverwrite = $params['overwrite'] ?? false;
if (is_string($rawOverwrite)) {
    $overwrite = in_array(strtolower($rawOverwrite), ['true', '1', 'yes'], true);
} else {
    $overwrite = (bool) $rawOverwrite;
}

// Same cast for the route-collision opt-in — one spelling of "the form said yes".
$rawAcceptCollisions = $params['acceptRouteCollisions'] ?? false;
if (is_string($rawAcceptCollisions)) {
    $acceptRouteCollisions = in_array(strtolower($rawAcceptCollisions), ['true', '1', 'yes'], true);
} else {
    $acceptRouteCollisions = (bool) $rawAcceptCollisions;
}

// Default targetPath to SERVER_ROOT (the project root where public/ and secure/ live)
if (empty($targetPath)) {
    $targetPath = SERVER_ROOT;
}

// === VALIDATION ===

// Retention is N = 1, so the build is discoverable: `name` became optional when
// the seven-command family shrank. Supplying it still means something — it
// asserts WHICH build the caller meant, and a stale name is a 404 below rather
// than a silent deploy of a different build.
if ($buildName === null || $buildName === '') {
    $buildName = qs_build_current();
    if ($buildName === null) {
        ApiResponse::create(404, 'build.not_found')
            ->withMessage('This project has no build to deploy')
            ->withData([
                'exists' => false,
                'hint'   => 'Run build to create one.'
            ])
            ->send();
    }
}

if (!is_string($buildName) || !RegexPatterns::match('build_name', $buildName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid build name format')
        ->withErrors([RegexPatterns::validationError('build_name', 'name', $buildName ?? '')])
        ->send();
}

// Validate targetPath
if (!is_string($targetPath)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('targetPath must be a string')
        ->withErrors([['field' => 'targetPath', 'expected' => 'string']])
        ->send();
}



// Security: Block path traversal attempts
if (strpos($targetPath, '..') !== false) {
    ApiResponse::create(400, 'validation.security_violation')
        ->withMessage('Path traversal is not allowed')
        ->withErrors([
            ['reason' => 'Paths containing ".." are blocked for security']
        ])
        ->send();
}

// Normalize path (handle both Windows and Unix)
$targetPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetPath), DIRECTORY_SEPARATOR);

// Security: Ensure path is absolute
$isAbsolute = (PHP_OS_FAMILY === 'Windows')
    ? preg_match('/^[A-Za-z]:/', $targetPath)
    : (strpos($targetPath, '/') === 0);

if (!$isAbsolute) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('targetPath must be an absolute path')
        ->withErrors([
            ['field' => 'targetPath', 'value' => $targetPath],
            ['example' => PHP_OS_FAMILY === 'Windows' ? 'C:\\wamp64\\www\\mysite' : '/var/www/mysite']
        ])
        ->send();
}

// === CONFINE DEPLOY TARGET TO AN ALLOWLISTED ROOT (beta.10 C4 / F8) ===
// The build contains generated PHP; under the CONTAIN model a leaked/low-
// trust deploy token must not write it to an arbitrary absolute path
// (another vhost, a startup folder, a system dir) or overwrite unrelated
// files. Allowed roots = SERVER_ROOT (always) + any listed in
// secure/management/config/deploy-roots.php. Absent/empty config ⇒
// SERVER_ROOT only, so the default deploy-to-self flow is unaffected.
$allowedRoots = [SERVER_ROOT];
$deployRootsFile = SECURE_FOLDER_PATH . '/management/config/deploy-roots.php';
if (is_file($deployRootsFile)) {
    $configuredRoots = require $deployRootsFile;
    if (is_array($configuredRoots)) {
        foreach ($configuredRoots as $configuredRoot) {
            if (is_string($configuredRoot) && $configuredRoot !== '') {
                $allowedRoots[] = $configuredRoot;
            }
        }
    }
}

// Canonicalise for a boundary-safe containment check: resolve symlinks/case
// on the deepest EXISTING ancestor (the target itself may not exist yet),
// then re-append the not-yet-created tail. '..' was already rejected above.
$deployCanonicalise = static function (string $p): string {
    $p = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p), DIRECTORY_SEPARATOR);
    $suffix = '';
    $probe = $p;
    while ($probe !== '' && @realpath($probe) === false) {
        $slash = strrpos($probe, DIRECTORY_SEPARATOR);
        if ($slash === false) break;
        $suffix = substr($probe, $slash) . $suffix;
        $probe = substr($probe, 0, $slash);
    }
    $real = ($probe !== '') ? @realpath($probe) : false;
    $full = $real !== false ? $real . $suffix : $p;
    return (PHP_OS_FAMILY === 'Windows') ? strtolower($full) : $full;
};

$targetCanonical = $deployCanonicalise($targetPath);
$targetAllowed = false;
foreach ($allowedRoots as $allowedRoot) {
    $rootCanonical = $deployCanonicalise($allowedRoot);
    if ($rootCanonical !== '' && ($targetCanonical === $rootCanonical
        || str_starts_with($targetCanonical . DIRECTORY_SEPARATOR, $rootCanonical . DIRECTORY_SEPARATOR))) {
        $targetAllowed = true;
        break;
    }
}
if (!$targetAllowed) {
    ApiResponse::create(403, 'validation.security_violation')
        ->withMessage('Deploy target is outside the allowed deploy root(s). Add it to secure/management/config/deploy-roots.php to permit this location.')
        ->withErrors([
            ['field' => 'targetPath', 'value' => $targetPath],
            ['reason' => 'Only SERVER_ROOT and configured deploy-roots.php entries are permitted']
        ])
        ->send();
}

// === BUILD VALIDATION ===

// Check that no build with this name is currently in progress (would mean files are incomplete)
$buildLockId = 'build_' . str_replace('/', '_', $buildName);
if (isLocked($buildLockId)) {
    ApiResponse::create(409, 'conflict.build_in_progress')
        ->withMessage('A build operation for "' . $buildName . '" is currently in progress. Wait for it to complete before deploying.')
        ->withData(['buildName' => $buildName])
        ->send();
}

// The build lives at secure/projects/<id>/qs_build/<name>/ — outside public/,
// where no URL reaches it. This command read PUBLIC_CONTENT_PATH . '/build'
// until beta.11 S3.8, a directory that stopped existing when the output moved,
// so every deploy answered "Build not found" no matter what was on disk.
// qs_build_path() is the single derivation every caller shares.
$buildFolder = qs_build_path($buildName);

if (!is_dir($buildFolder)) {
    ApiResponse::create(404, 'build.not_found')
        ->withMessage('Build not found')
        ->withData([
            'requested_build' => $buildName,
            'hint'            => 'Retention is one build per project. Call getBuild to see which build exists, if any.'
        ])
        ->send();
}

// An incomplete build is refused rather than deployed. A partial carries no
// build_manifest.json, so it did not finish — deploying one puts a half-written
// site on a server, which is the one place a broken deliverable does damage.
// Same rule downloadBuild applies to the archive.
if (!qs_build_is_complete($buildName)) {
    ApiResponse::create(409, 'build.incomplete')
        ->withMessage('This build is incomplete and cannot be deployed')
        ->withData([
            'build' => $buildName,
            'hint'  => 'It carries no build_manifest.json, so it did not finish. Remove it with deleteBuild and run build again.'
        ])
        ->send();
}

// Read manifest to get folder names
$manifestPath = $buildFolder . '/build_manifest.json';
$buildPublicName = null;
$buildSecureName = null;
$buildSpace = null;

if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if ($manifest) {
        $buildPublicName = $manifest['public'] ?? null;
        $buildSecureName = $manifest['secure'] ?? null;
        $buildSpace = $manifest['space'] ?? '';
    }
}

// If no manifest, scan for folders (handles nested secure paths like "secure/business")
if (!$buildPublicName || !$buildSecureName) {
    $foundDirs = [];
    foreach (scandir($buildFolder) as $item) {
        if ($item === '.' || $item === '..' || !is_dir($buildFolder . '/' . $item)) continue;
        $foundDirs[] = $item;
    }
    
    // Try to identify secure folder by recursively searching for config.php
    foreach ($foundDirs as $dir) {
        $dirPath = $buildFolder . '/' . $dir;
        // Direct config.php (flat secure folder like "backend/config.php")
        if (file_exists($dirPath . '/config.php')) {
            $buildSecureName = $dir;
        } else {
            // Check one level deeper for nested secure paths (e.g., "secure/business/config.php")
            foreach (scandir($dirPath) as $sub) {
                if ($sub === '.' || $sub === '..') continue;
                if (is_dir($dirPath . '/' . $sub) && file_exists($dirPath . '/' . $sub . '/config.php')) {
                    $buildSecureName = $dir . '/' . $sub;
                    break;
                }
            }
        }
    }
    
    // Public folder is whichever top-level dir is NOT the secure root
    $secureRoot = $buildSecureName ? explode('/', $buildSecureName)[0] : null;
    foreach ($foundDirs as $dir) {
        if ($dir !== $secureRoot) {
            $buildPublicName = $dir;
            break;
        }
    }
}

if (!$buildPublicName || !$buildSecureName) {
    ApiResponse::create(500, 'build.invalid_structure')
        ->withMessage('Could not identify public and secure folders in build')
        ->withData(['build' => $buildName])
        ->send();
}

$sourcePublic = $buildFolder . '/' . $buildPublicName;
$sourceSecure = $buildFolder . '/' . $buildSecureName;

if (!is_dir($sourcePublic)) {
    ApiResponse::create(500, 'build.missing_folder')
        ->withMessage('Public folder not found in build')
        ->withData(['expected' => $buildPublicName])
        ->send();
}

if (!is_dir($sourceSecure)) {
    ApiResponse::create(500, 'build.missing_folder')
        ->withMessage('Secure folder not found in build')
        ->withData(['expected' => $buildSecureName])
        ->send();
}

// Determine destination paths
$destPublic = $targetPath . DIRECTORY_SEPARATOR . $buildPublicName;
$destSecure = $targetPath . DIRECTORY_SEPARATOR . $buildSecureName;

// === ROUTE COLLISIONS AT THE TARGET (Sangio's ruling, 2026-08-16) ===
//
// The default deploy target is the installation's own web root, and `addRoute`
// reserves nothing — so a project with a route named `admin`, `management` or
// `p` lands its pages beside QuickSite's own namespaces and loses. The site's
// entry point funnels requests through a FallbackResource, which only applies
// when the URL is NOT a real file or directory: a real directory beside the
// front controller therefore SHADOWS a same-named route, permanently and
// silently.
//
// Blacklisting those names in `addRoute` was considered and rejected. They are
// reserved in exactly ONE of three deployment shapes (marker form `/p/<id>/x`
// and a build on its own domain both have no conflict), so blocking them
// everywhere punishes every author for a layout most never use, and the rule
// would have to be threaded through route creation, editing and import to hold.
//
// The check belongs HERE: the target layout is known, the collision is real
// rather than hypothetical, and the person answering for it is the deployer
// rather than a content author.
//
// DERIVED FROM DISK, NOT FROM A LIST OF NAMES. What shadows a route is whatever
// directory happens to sit beside the site's front controller at this target —
// QuickSite's own three on an install root, plus anything else the deployer put
// there. Reading the target says which, and says it correctly for a target this
// code has never seen.
$deployedSiteDir = $destPublic . ($buildSpace !== '' ? DIRECTORY_SEPARATOR . $buildSpace : '');

$routeCollisions = [];
$buildRoutesFile = $buildFolder . '/' . $buildSecureName . '/routes.php';
if (is_dir($deployedSiteDir) && is_file($buildRoutesFile)) {
    // Same containment the config readers use: a broken routes.php in a build
    // must not end the request, and a file that is not PHP must not echo itself
    // into the envelope.
    $builtRoutes = null;
    ob_start();
    try {
        $builtRoutes = require $buildRoutesFile;
    } catch (Throwable $e) {
        error_log('QuickSite: unreadable routes.php in build ' . $buildName . ' (' . $e->getMessage() . ') — route-collision check skipped.');
        $builtRoutes = null;
    } finally {
        ob_end_clean();
    }

    if (is_array($builtRoutes)) {
        // URLs are case-sensitive on Linux and not on Windows; fold only where
        // the filesystem does, so the check matches how the target will serve.
        $fold = static fn(string $s): string => (PHP_OS_FAMILY === 'Windows') ? strtolower($s) : $s;

        $existingDirs = [];
        foreach ((array) @scandir($deployedSiteDir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($deployedSiteDir . DIRECTORY_SEPARATOR . $entry)) {
                $existingDirs[$fold($entry)] = $entry;
            }
        }

        foreach (array_keys($builtRoutes) as $routeKey) {
            $routeKey = (string) $routeKey;
            // Only the FIRST segment can be shadowed — a directory named `admin`
            // takes `/admin` and everything under it, and a nested route only
            // exists below its own first segment anyway.
            $firstSegment = explode('/', ltrim($routeKey, '/'))[0];
            if ($firstSegment === '') {
                continue;
            }
            if (isset($existingDirs[$fold($firstSegment)])) {
                $routeCollisions[] = [
                    'route'       => $routeKey,
                    'segment'     => $firstSegment,
                    'shadowed_by' => $deployedSiteDir . DIRECTORY_SEPARATOR . $existingDirs[$fold($firstSegment)],
                ];
            }
        }
    }
}

if ($routeCollisions !== [] && !$acceptRouteCollisions) {
    ApiResponse::create(409, 'conflict.route_collision')
        ->withMessage(count($routeCollisions) === 1
            ? 'One of this site\'s routes is already a directory at the deploy target and would never be reachable'
            : count($routeCollisions) . ' of this site\'s routes are already directories at the deploy target and would never be reachable')
        ->withData([
            'build'       => $buildName,
            'target'      => $targetPath,
            'served_from' => $deployedSiteDir,
            'collisions'  => $routeCollisions,
            'hint'        => 'A directory beside the site\'s entry point wins over its routing, so these pages would answer with the directory instead. Rename the route, deploy to a target that does not carry these directories, or set acceptRouteCollisions=true to deploy anyway and leave them unreachable.',
        ])
        ->send();
}

// === SAFETY CHECK: Warn when deploying without space to existing multi-project directory ===
$spaceWarning = null;
if (empty($buildSpace) && is_dir($destPublic)) {
    // Check if the destination public dir already has subdirectories (indicating multi-project / space usage)
    $existingSubdirs = [];
    foreach (scandir($destPublic) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (is_dir($destPublic . DIRECTORY_SEPARATOR . $item) && $item !== 'build') {
            $existingSubdirs[] = $item;
        }
    }
    if (count($existingSubdirs) > 0) {
        $spaceWarning = 'This build has no "space" parameter — public files will be placed directly in ' 
            . $buildPublicName . '/ root alongside existing subdirectories: ' . implode(', ', $existingSubdirs) 
            . '. If this is a multi-project setup, rebuild with space=<projectname> to place files in their own subdirectory.';
    }
}

// === FILE CONFLICT DETECTION ===

/**
 * Scan source directory and find files that already exist at destination.
 * Returns array of relative paths that would be overwritten.
 */
function findConflicts(string $source, string $dest): array {
    $conflicts = [];
    if (!is_dir($dest)) return $conflicts;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $item) {
        $relativePath = $iterator->getSubPathname();
        $destFile = $dest . DIRECTORY_SEPARATOR . $relativePath;
        if (file_exists($destFile)) {
            $conflicts[] = $relativePath;
        }
    }
    
    return $conflicts;
}

// Check for file conflicts in public/secure directories
$publicConflicts = findConflicts($sourcePublic, $destPublic);
$secureConflicts = findConflicts($sourceSecure, $destSecure);
$totalConflicts = count($publicConflicts) + count($secureConflicts);

if ($totalConflicts > 0 && !$overwrite) {
    $conflictData = [
        'total_conflicts' => $totalConflicts,
        'public_conflicts' => [
            'folder' => $buildPublicName,
            'count' => count($publicConflicts),
            'files' => array_slice($publicConflicts, 0, 50)
        ],
        'secure_conflicts' => [
            'folder' => $buildSecureName,
            'count' => count($secureConflicts),
            'files' => array_slice($secureConflicts, 0, 50)
        ],
        'space' => $buildSpace ?: '(none — files at public root)',
        'hint' => 'Set overwrite=true to replace existing files'
    ];
    if ($spaceWarning) {
        $conflictData['warning'] = $spaceWarning;
    }
    ApiResponse::create(409, 'conflict.files_exist')
        ->withMessage("Found {$totalConflicts} file(s) that would be overwritten")
        ->withData($conflictData)
        ->send();
}

// === CHECK/CREATE TARGET DIRECTORY ===

if (!is_dir($targetPath)) {
    if (!mkdir($targetPath, 0755, true)) {
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage('Failed to create target directory')
            ->withData(['path' => $targetPath])
            ->send();
    }
}

if (!is_writable($targetPath)) {
    ApiResponse::create(500, 'server.permission_denied')
        ->withMessage('Target directory is not writable')
        ->withData(['path' => $targetPath])
        ->send();
}

// === ACQUIRE LOCK (scoped to target path so parallel deploys to different targets are allowed) ===
$deployLockId = 'deploy_' . md5($targetPath);
$lock = acquireLock($deployLockId);

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage('Another deployment to this target is already in progress')
        ->withData(['targetPath' => $targetPath])
        ->send();
}

// Helper to release lock on error
function release_deploy_lock() {
    global $lock;
    if ($lock) releaseLock($lock);
}

// === COPY FILES ===

// Track all created files and directories for rollback on failure
$createdFiles = [];
$createdDirs = [];

/**
 * Recursively copy a directory, tracking all created items for rollback
 */
function copyDirectory(string $source, string $dest, bool $overwrite, array &$createdFiles, array &$createdDirs): array {
    if (!is_dir($dest)) {
        if (!mkdir($dest, 0755, true)) {
            return ['files' => 0, 'directories' => 0, 'error' => "Failed to create directory: {$dest}"];
        }
        $createdDirs[] = $dest;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $copiedFiles = 0;
    $copiedDirs = 0;
    
    foreach ($iterator as $item) {
        $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
        
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                if (!mkdir($destPath, 0755, true)) {
                    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'error' => "Failed to create directory: {$destPath}"];
                }
                $createdDirs[] = $destPath;
                $copiedDirs++;
            }
        } else {
            $fileExisted = file_exists($destPath);
            if ($overwrite || !$fileExisted) {
                if (!copy($item->getPathname(), $destPath)) {
                    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'error' => "Failed to copy file: {$destPath}"];
                }
                // Only track for rollback if we created a new file (not overwrote)
                if (!$fileExisted) {
                    $createdFiles[] = $destPath;
                }
                $copiedFiles++;
            }
        }
    }
    
    return ['files' => $copiedFiles, 'directories' => $copiedDirs];
}

/**
 * Attempt rollback: remove all NEW files and directories created during deployment.
 * Does not touch files that were overwritten (they are already changed).
 */
function rollbackDeployment(array $createdFiles, array $createdDirs): array {
    $rollbackErrors = [];
    
    // Delete files first (in reverse order)
    foreach (array_reverse($createdFiles) as $file) {
        if (file_exists($file) && !@unlink($file)) {
            $rollbackErrors[] = "Could not remove file: {$file}";
        }
    }
    
    // Delete directories in reverse order (deepest first)
    foreach (array_reverse($createdDirs) as $dir) {
        if (is_dir($dir)) {
            if (count(scandir($dir)) <= 2) {
                if (!@rmdir($dir)) {
                    $rollbackErrors[] = "Could not remove directory: {$dir}";
                }
            } else {
                $rollbackErrors[] = "Directory not empty after file cleanup: {$dir}";
            }
        }
    }
    
    return $rollbackErrors;
}

// Copy public folder
$publicResult = copyDirectory($sourcePublic, $destPublic, $overwrite, $createdFiles, $createdDirs);

if (isset($publicResult['error'])) {
    $rollbackErrors = rollbackDeployment($createdFiles, $createdDirs);
    release_deploy_lock();
    ApiResponse::create(500, 'deploy.copy_failed')
        ->withMessage('Deployment failed while copying public folder')
        ->withData([
            'error' => $publicResult['error'],
            'files_copied_before_failure' => $publicResult['files'],
            'rollback_attempted' => true,
            'rollback_complete' => empty($rollbackErrors),
            'rollback_errors' => $rollbackErrors ?: null
        ])
        ->send();
}

// Copy secure folder
$secureResult = copyDirectory($sourceSecure, $destSecure, $overwrite, $createdFiles, $createdDirs);

if (isset($secureResult['error'])) {
    $rollbackErrors = rollbackDeployment($createdFiles, $createdDirs);
    release_deploy_lock();
    ApiResponse::create(500, 'deploy.copy_failed')
        ->withMessage('Deployment failed while copying secure folder')
        ->withData([
            'error' => $secureResult['error'],
            'files_copied_before_failure' => $publicResult['files'] + $secureResult['files'],
            'rollback_attempted' => true,
            'rollback_complete' => empty($rollbackErrors),
            'rollback_errors' => $rollbackErrors ?: null
        ])
        ->send();
}

// Copy LICENSE to target (always overwrite silently — same license file)
$licenseCopied = false;
$licenseSource = $buildFolder . '/LICENSE';
$licenseDest = $targetPath . DIRECTORY_SEPARATOR . 'LICENSE';
if (file_exists($licenseSource)) {
    $fileExisted = file_exists($licenseDest);
    if (copy($licenseSource, $licenseDest)) {
        if (!$fileExisted) {
            $createdFiles[] = $licenseDest;
        }
        $licenseCopied = true;
    }
}

// Release lock
release_deploy_lock();

// === SUCCESS RESPONSE ===
$responseData = [
    'build' => $buildName,
    'target' => $targetPath,
    'folders' => [
        'public' => $buildPublicName,
        'secure' => $buildSecureName,
        'space' => $buildSpace ?: '(none — files at public root)'
    ],
    'deployed_paths' => [
        'public' => $destPublic,
        'secure' => $destSecure
    ],
    'public_deployment' => [
        'files_copied' => $publicResult['files'],
        'directories_created' => $publicResult['directories']
    ],
    'secure_deployment' => [
        'files_copied' => $secureResult['files'],
        'directories_created' => $secureResult['directories']
    ],
    'license_copied' => $licenseCopied,
    'overwrite_mode' => $overwrite,
    'files_overwritten' => $overwrite ? $totalConflicts : 0
];

if ($spaceWarning) {
    $responseData['warning'] = $spaceWarning;
}

// An accepted collision is REPORTED, not forgotten. The deployer opted in, and
// the pages really are unreachable — a success envelope that said nothing would
// be the same silence the check exists to break.
if ($routeCollisions !== []) {
    $responseData['route_collisions'] = $routeCollisions;
    $responseData['route_collisions_accepted'] = true;
    $responseData['route_collision_warning'] =
        'Deployed with ' . count($routeCollisions) . ' shadowed route(s): a directory of the same name sits beside the site\'s entry point, so those pages are not reachable at this target.';
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Build deployed successfully')
    ->withData($responseData)
    ->send();
