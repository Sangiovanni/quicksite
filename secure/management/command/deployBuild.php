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
 * - targetPath: (optional) Absolute path to the root directory where the build
 *               will be deployed. The build's public and secure folders are
 *               placed inside it as <target>/{publicFolder}/ and
 *               <target>/{secureFolder}/. Defaults to SERVER_ROOT, and must
 *               be inside SERVER_ROOT or a configured deploy root — see gate 3.
 * - overwrite: (optional) If true, overwrite existing files (default: false)
 *              When false, the command scans for file conflicts first and returns them.
 * - acceptRouteCollisions: (optional) If true, deploy even though one of the
 *              site's routes is shadowed by a directory that already exists at
 *              the target. Default false, which refuses with the collisions named.
 * - confirmUpdate: (optional) The target already holds a deployment OF THIS
 *              PROJECT. Confirms the update. This is the routine path.
 * - replaceDeployment: (optional) The target's secure folder belongs to a
 *              DIFFERENT project. Overwrites what that deployment wrote.
 * - adoptSecureFolder: (optional) The target's secure folder has contents and
 *              no QuickSite marker, so its owner is unknown. Writes into it anyway.
 *
 * CO-TENANCY: deploying site B never damages site A. A build owns its own
 * subtree — <public>/<space>/** and <secure>/** — and nothing else. Outside it a
 * deploy may CREATE but never OVERWRITE, and `overwrite` does not reach those
 * paths. Each cross-tenant conflict above has its own refusal code and its own
 * opt-in; none is reachable through `overwrite`. Nothing here ever DELETES.
 *
 * THREE INDEPENDENT GATES, all of which must pass:
 *   1. deploy.php    — may this installation deploy at all? ABSENT MEANS NO.
 *                      (src/functions/deployPolicy.php)
 *   2. roles.php     — may this caller? `deployBuild` is alone in the `deploy`
 *                      category, granted to admin and owner.
 *   3. deploy-roots.php — where may it write? Absent ⇒ SERVER_ROOT only.
 *
 * SECURITY NOTE:
 * - The target is NOT an arbitrary absolute path. It must be SERVER_ROOT or a
 *   root the operator listed in deploy-roots.php; anything else is refused 403.
 *   A default installation, which has no such file, deploys to itself and
 *   nowhere else. (beta.10 C4/F8 — before that it did write anywhere.)
 * - Path traversal attempts (..) are blocked before the allowlist is consulted.
 * - The gates above are what a leaked deploy token runs into: it can redeploy
 *   this install's own site, and it cannot write generated PHP to another vhost.
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php'; // qs_opcache_invalidate
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/deployPolicy.php';   // qs_deploy_allowed
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_build_* (the one build-location derivation)
require_once SECURE_FOLDER_PATH . '/src/functions/deploymentMarker.php'; // QS_DEPLOY_MARKER, marker shape
require_once SECURE_FOLDER_PATH . '/src/functions/NginxConfig.php';     // write_nginx_dynamic_routes

// === GATE 1: IS DEPLOY ENABLED ON THIS INSTALLATION AT ALL? ===
// Asked FIRST, before any parameter is read, so a disabled install answers the
// same way whatever it is asked — no probing the target allowlist or the build
// inventory through the shape of a refusal. Absent config ⇒ denied.
if (!qs_deploy_allowed()) {
    ApiResponse::create(403, 'deploy.disabled')
        ->withMessage('Deploying is disabled on this installation')
        ->withData([
            'hint' => 'The operator enables it on the server: copy ' . SECURE_FOLDER_NAME . '/management/config/deploy.php.example to deploy.php and set allow_deploy => true (setup.sh / setup.bat offers this). Building, downloading and deleting a build are unaffected.',
        ])
        ->send();
}

$params = $trimParametersManagement->params();
$buildName = $params['name'] ?? null;
$targetPath = $params['targetPath'] ?? null;
// One spelling of "the form said yes", for every opt-in this command takes.
// Form checkboxes send "true"/"false" as strings; JSON callers send booleans.
$optIn = static function (string $key) use ($params): bool {
    $raw = $params[$key] ?? false;
    return is_string($raw)
        ? in_array(strtolower($raw), ['true', '1', 'yes'], true)
        : (bool) $raw;
};

$overwrite             = $optIn('overwrite');
$acceptRouteCollisions = $optIn('acceptRouteCollisions');
// The three co-tenancy opt-ins. Each answers ONE named refusal and nothing
// else: none of them is reachable through `overwrite`, and none of them
// implies another. That separation is the point — a single blunt
// replace-everything checkbox is exactly how a deployer destroys a site they
// did not know was there.
$confirmUpdate       = $optIn('confirmUpdate');
$replaceDeployment   = $optIn('replaceDeployment');
$adoptSecureFolder   = $optIn('adoptSecureFolder');

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
        ->withMessage('Deploy target is outside the allowed deploy root(s). Add it to ' . SECURE_FOLDER_NAME . '/management/config/deploy-roots.php to permit this location.')
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

// === WHO OWNS THE SECURE FOLDER AT THIS TARGET? ===
//
// THE WORST CO-TENANCY CASE, and until now the least guarded. Deploying site B
// with site A's secure folder name replaces A's compiled pages and its config:
// A silently becomes B. It surfaced only as `conflict.files_exist` — a file
// list that anyone redeploying clicks straight past, because redeploying your
// own site produces exactly the same list.
//
// So the folder that holds the data answers for itself. Nothing in a build
// carried an identity that survived to the target: `qs-site.php` names the
// project but lives in the PUBLIC folder, and `build_manifest.json` is read out
// of the build and never deployed. The marker is written HERE, at deploy, into
// <secure>/ — which is not web-reachable, so it can carry the project id
// safely, and which is the right place because it is the folder whose contents
// are at stake. Checking the secure folder rather than the public one is also
// what makes this work when the two sites use different public folder names,
// which is the multi-tenant case.
//
// ⚠ NOTHING HERE DELETES. QuickSite may create a secure folder and may write
// into one it owns; clearing a stale secure folder is the deployer's own manual
// act, deliberately (Sangio's rule). A command that could delete a secure
// folder is a command that can destroy a site by typo.
//
// ⚠ THE REFUSALS ARE DISCRETE. They say the name is not available. They do not
// name the other project, its build, when it was deployed, or anything else on
// that box — same reasoning as keeping the install root out of the panel.
//
// QS_DEPLOY_MARKER and the record's shape now live in src/functions/
// deploymentMarker.php: the nginx generator reads the same file to find out
// what is deployed at the document root, and a record with two readers cannot
// have its definition inside one of them.

$markerPath = $destSecure . DIRECTORY_SEPARATOR . QS_DEPLOY_MARKER;
$existingMarker = null;
if (is_file($markerPath)) {
    $decoded = json_decode((string) @file_get_contents($markerPath), true);
    if (is_array($decoded)) {
        $existingMarker = $decoded;
    }
}

/** Is there anything in the destination secure folder at all? */
$secureFolderOccupied = false;
if (is_dir($destSecure)) {
    foreach ((array) @scandir($destSecure) as $entry) {
        if ($entry !== '.' && $entry !== '..') { $secureFolderOccupied = true; break; }
    }
}

if ($existingMarker !== null) {
    $markerProject = (string) ($existingMarker['project'] ?? '');
    if ($markerProject === (string) PROJECT_NAME) {
        // THE UPDATE PATH — the common one, and the only one that is routine.
        // It still asks, because "update the live site" deserves a deliberate
        // yes even when it is the thing you meant.
        if (!$confirmUpdate) {
            ApiResponse::create(409, 'deploy.update_confirmation_required')
                ->withMessage('This target already holds a deployment of this project. Confirm to update it.')
                ->withData([
                    'secure_folder'   => $buildSecureName,
                    'deployed_at'     => $existingMarker['deployed_at'] ?? null,
                    'deployed_build'  => $existingMarker['build'] ?? null,
                    'hint'            => 'Set confirmUpdate=true to update the existing deployment in place.',
                ])
                ->send();
        }
    } elseif (!$replaceDeployment) {
        // A DIFFERENT project's data. Its own code, its own opt-in, and
        // nothing about the occupant in the answer.
        ApiResponse::create(409, 'deploy.secure_folder_in_use')
            ->withMessage('The secure folder name "' . $buildSecureName . '" is not available at this target')
            ->withData([
                'secure_folder' => $buildSecureName,
                'hint'          => 'Another deployment already owns this folder. Build with a different secure folder name, deploy to a different target, or set replaceDeployment=true to overwrite what is there. Nothing is deleted either way: files the new deployment does not write are left untouched.',
            ])
            ->send();
    }
} elseif ($secureFolderOccupied && !$adoptSecureFolder) {
    // Occupied, and QuickSite did not put the marker there — so it cannot say
    // whose it is. Adopting is a decision, not a default.
    ApiResponse::create(409, 'deploy.secure_folder_unmarked')
        ->withMessage('The secure folder name "' . $buildSecureName . '" is not available at this target')
        ->withData([
            'secure_folder' => $buildSecureName,
            'hint'          => 'This folder already has contents and carries no QuickSite deployment marker, so its owner is unknown — a deployment made before markers existed, or something else entirely. Build with a different secure folder name, deploy to a different target, or set adoptSecureFolder=true to write into it anyway. Nothing is deleted either way.',
        ])
        ->send();
}

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

// === WHAT THIS BUILD OWNS ===
//
// THE PRINCIPLE: deploying site B never damages site A.
//
// A build owns its own subtree and nothing else. With a URL space the site
// lives at <public>/<space>/, so <public>/ itself belongs to whatever else the
// deployer serves from that document root — including another QuickSite site
// deployed at the root. Everything under <secure>/ is the build's (subject to
// the ownership check further down, which decides whether it may write there
// at all).
//
// Outside its own subtree a deploy may CREATE but never OVERWRITE, and
// `overwrite: true` does not reach those paths. That is what makes a shared
// document root safe: the first tenant's files stay the first tenant's.
//
// THE DEFECT THIS CLOSES, measured: a spaced build emits two .htaccess files —
// its own funnel at <public>/<space>/, and a guard at <public>/ carrying
// `Options -Indexes` and headers but NO FallbackResource. Deployed over a root
// build, that guard replaced the root site's funnel: the root site kept "/"
// (index.php is a real file) and lost every route. Order-dependent —
// root-then-spaced broke, spaced-then-root did not — so it passed a test and
// broke the next deploy.
//
// Expressed as the general rule rather than a spaced-build special case, so a
// nested space and a third tenant need no second patch.
$ownedPublicPrefix = $buildSpace !== ''
    ? trim(str_replace('/', DIRECTORY_SEPARATOR, $buildSpace), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    : '';

/**
 * Does this build own the destination of `$relativePath` within the public tree?
 *
 * With no space it owns all of it. With a space it owns only what is under that
 * space; anything else the build happens to emit into the document root — today
 * exactly one file, the root guard — is a shared path.
 */
$ownsPublicPath = static function (string $relativePath) use ($ownedPublicPrefix): bool {
    if ($ownedPublicPrefix === '') {
        return true;
    }
    $normalised = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    return strncmp($normalised, $ownedPublicPrefix, strlen($ownedPublicPrefix)) === 0;
};

// === FILE CONFLICT DETECTION ===

/**
 * Scan source directory and find files that already exist at destination.
 *
 * Returns two lists. `conflicts` are paths this build OWNS and would therefore
 * overwrite — those are what the overwrite decision is about. `shared` are
 * paths outside its subtree that already exist: they are not conflicts, because
 * nothing is going to touch them, and counting them as such would ask the user
 * to authorise an overwrite that will not happen.
 */
function findConflicts(string $source, string $dest, ?callable $ownsPath = null): array {
    $conflicts = [];
    $shared = [];
    if (!is_dir($dest)) return ['conflicts' => $conflicts, 'shared' => $shared];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        $relativePath = $iterator->getSubPathname();
        $destFile = $dest . DIRECTORY_SEPARATOR . $relativePath;
        if (file_exists($destFile)) {
            if ($ownsPath !== null && !$ownsPath($relativePath)) {
                $shared[] = $relativePath;
            } else {
                $conflicts[] = $relativePath;
            }
        }
    }

    return ['conflicts' => $conflicts, 'shared' => $shared];
}

// Check for file conflicts in public/secure directories
$publicScan     = findConflicts($sourcePublic, $destPublic, $ownsPublicPath);
$secureScan     = findConflicts($sourceSecure, $destSecure);
$publicConflicts = $publicScan['conflicts'];
$secureConflicts = $secureScan['conflicts'];
$sharedPaths     = $publicScan['shared'];
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
function copyDirectory(string $source, string $dest, bool $overwrite, array &$createdFiles, array &$createdDirs, ?callable $ownsPath = null): array {
    if (!is_dir($dest)) {
        if (!mkdir($dest, 0755, true)) {
            return ['files' => 0, 'directories' => 0, 'php_invalidated' => 0, 'error' => "Failed to create directory: {$dest}"];
        }
        $createdDirs[] = $dest;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    // The jail root, canonicalised ONCE: every entry below must resolve inside
    // it. Without this the walk follows a reparse point out of the build and
    // copies whatever it finds — RecursiveDirectoryIterator reports a junction
    // as an ordinary directory (beta.11 S3.10c, audit F6). Defence in depth:
    // the source is a build QuickSite generated with mkdir/copy and no command
    // plants a link, so no remote vector was found. It is here so this boundary
    // and the publish copier make the SAME decision instead of differing
    // invisibly; both now ask qs_path_is_within().
    $jailRoot = realpath($source);
    if ($jailRoot === false) {
        return ['files' => 0, 'directories' => 0, 'php_invalidated' => 0, 'error' => "Source directory is unreadable: {$source}"];
    }

    $copiedFiles = 0;
    $copiedDirs = 0;
    $skippedShared = [];
    $skippedOutside = [];
    $phpInvalidated = 0;

    foreach ($iterator as $item) {
        $relativePath = $iterator->getSubPathname();
        $destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;

        if (!qs_path_is_within($item->getPathname(), $jailRoot)) {
            $skippedOutside[] = $relativePath;
            continue;
        }

        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                if (!mkdir($destPath, 0755, true)) {
                    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'php_invalidated' => $phpInvalidated, 'error' => "Failed to create directory: {$destPath}"];
                }
                $createdDirs[] = $destPath;
                $copiedDirs++;
            }
        } else {
            $fileExisted = file_exists($destPath);
            // OUTSIDE THIS BUILD'S SUBTREE: create, never overwrite — and
            // `overwrite` deliberately does not reach here. This is the whole of
            // "deploying site B never damages site A": the file belongs to
            // whoever put it there first, and a blanket replace-everything
            // checkbox must not be able to take it.
            if ($fileExisted && $ownsPath !== null && !$ownsPath($relativePath)) {
                $skippedShared[] = $relativePath;
                continue;
            }
            if ($overwrite || !$fileExisted) {
                if (!copy($item->getPathname(), $destPath)) {
                    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'php_invalidated' => $phpInvalidated, 'error' => "Failed to copy file: {$destPath}"];
                }
                // Only track for rollback if we created a new file (not overwrote)
                if (!$fileExisted) {
                    $createdFiles[] = $destPath;
                }
                $copiedFiles++;

                // ⚠ THE FILE WAS REPLACED; ITS COMPILED FORM WAS NOT. `require`
                // on a cached file returns what was compiled, not what is on
                // disk — a two-second window with PHP's defaults, and FOREVER on
                // a production install running `opcache.validate_timestamps=0`,
                // where a redeploy is a silent no-op until php-fpm restarts.
                //
                // force=true, so it applies whether or not timestamp validation
                // is on. Same pattern, same reason, as qs_deploy_allowed() and
                // qs_environment(); the difference is that those invalidate a
                // file THIS process is about to read, and this one invalidates a
                // file ANOTHER process will read — which is why it is
                // best-effort: the deployed site usually runs in a different
                // php-fpm pool, and OPcache memory is per-pool. Free when the
                // pool is shared, harmless when it is not. The response says so.
                if (substr($destPath, -4) === '.php' && qs_opcache_invalidate($destPath)) {
                    $phpInvalidated++;
                }
            }
        }
    }

    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'skipped_shared' => $skippedShared,
            'skipped_outside' => $skippedOutside, 'php_invalidated' => $phpInvalidated];
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
$publicResult = copyDirectory($sourcePublic, $destPublic, $overwrite, $createdFiles, $createdDirs, $ownsPublicPath);

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

// === STAMP THE SECURE FOLDER WITH WHO OWNS IT ===
// Written LAST, and only on the success path, so a deploy that failed and
// rolled back does not leave a claim on a folder it did not populate. A failure
// to write the marker is not fatal — the site is already there and serving —
// but it IS reported, because the next deploy will then see an unmarked folder
// and refuse until adopted, and the deployer should learn that here rather than
// next time.
//
// It also records WHERE the deployment landed — the public folder name and the
// URL space — because the nginx generator below has to tell a build sitting at
// this installation's document root from one sitting beside it, and only this
// record knows. `<public>/qs-site.php` holds the same three fields and is not
// usable for it: it answers 404 and calls exit unless QS_SITE_BOOT is defined.
$markerWritten = @file_put_contents($markerPath, json_encode(
    qs_deployment_marker_fields(
        (string) PROJECT_NAME,
        $buildName,
        $buildPublicName,
        $buildSecureName,
        (string) $buildSpace
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n") !== false;

// === THE WEB SERVER'S ROUTING NOW DISAGREES WITH THE DISK ===
//
// nginx only. This installation generates `<secure>/nginx/dynamic_routes.conf`
// once, at first page load, and its last block describes the document root as
// FREE — `try_files $uri $uri/ =404;` — which is correct for an installation,
// whose public root deliberately holds no front controller.
//
// The deploy that just finished put one there. Left alone, the site serves its
// home page (`index index.php` resolves the DIRECTORY) and answers every other
// URL with nginx's own grey 404 — measured on nginx 1.24.0. A vhost carrying a
// server-level `try_files $uri $uri/ /index.php?$args;` does not rescue it:
// server-level try_files runs only for requests matching NO location, and
// `location /` matches everything.
//
// So the file is regenerated from what is on disk. Apache installs generate it
// too and it stays inert there — Apache never reads it, .htaccess does the
// routing — and try_nginx_reload() refuses to shell out on a non-nginx server,
// so an Apache deploy attempts nothing.
//
// ⚠ NOT FATAL, AND NEVER OPTIMISTIC. The files are copied and the site is on
// disk; a configuration that could not be rewritten is something the deployer
// must be TOLD about, not a reason to roll back a successful copy. The response
// carries which of the three outcomes happened and never claims a reload that
// did not occur.
//
// $destSecure is handed in explicitly: a build's secure folder name may be
// nested (`build` validates it to five levels and offers `backend/core` as an
// example), and the marker scan stops at two. The deploy that just happened must
// never depend on that cap.
$nginxResult = write_nginx_dynamic_routes(
    PUBLIC_FOLDER_SPACE,
    SECURE_FOLDER_PATH,
    null,
    null,
    [$destSecure]
);

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
    'files_overwritten' => $overwrite ? $totalConflicts : 0,
    // The folder now says who owns it, so the next deploy can tell an update
    // from a stranger without asking the deployer to remember.
    'ownership_marker' => [
        'written' => $markerWritten,
        'path'    => $destSecure . DIRECTORY_SEPARATOR . QS_DEPLOY_MARKER,
        'updated_existing' => $existingMarker !== null,
    ],
];

if (!$markerWritten) {
    $responseData['ownership_marker']['warning'] =
        'The deployment marker could not be written. The site is deployed and serving, but the next deploy to this target will see an unmarked secure folder and refuse until it is adopted.';
}

$nginxRootIsFunnelled = false;
foreach ($nginxResult['deployed_sites'] as $deployedSite) {
    if ($deployedSite['space'] === '') { $nginxRootIsFunnelled = true; break; }
}

// The routing file and what happened to the running nginx. `reload` is the
// field that matters and it is never optimistic: 'reloaded' only when nginx
// actually took the new configuration, 'pending' when the flag was left for the
// cron fallback and a manual reload is still needed, 'not_applicable' when this
// is not an nginx server and nothing was attempted.
$responseData['nginx'] = [
    'config_regenerated' => (bool) $nginxResult['success'],
    'config_path'        => $nginxResult['config_path'],
    'reload'             => $nginxResult['reload_outcome'],
    'reload_note'        => $nginxResult['reload_note'],
    // What the regenerated root block says: a funnel to a deployed front
    // controller, or the free `=404` form that leaves the domain root alone.
    'root_serves_a_build' => $nginxRootIsFunnelled,
];
if (!$nginxResult['success']) {
    $responseData['nginx']['warning'] =
        'The nginx routing file could not be rewritten (' . ($nginxResult['error'] ?? 'unknown error')
        . '). The site is deployed. On nginx, its pages will not route until that file describes the '
        . 'document root correctly — the symptom is that only "/" serves and every other URL answers '
        . 'nginx\'s own 404 page. Apache installs are unaffected.';
}

// ⚠ OPCACHE ON THE DEPLOYED SITE, AND WHAT THIS CAN AND CANNOT DO.
// `opcache.validate_timestamps=0` is a normal production tuning under which PHP
// never re-stats a compiled file — so replacing a deployed .php file changes
// nothing until php-fpm is reloaded, and a redeploy is a SILENT NO-OP.
//
// The invalidation above (inside copyDirectory) is best-effort by nature and
// cannot be more: a deployed site normally runs in a different php-fpm pool from
// the panel that deployed it, and OPcache shared memory is per-pool — so the
// invalidate lands in the wrong memory. It is free when the pool IS shared
// (the common self-deploy-to-this-install case, where it is exactly right) and
// harmless when it is not. What closes the gap in every case is saying so.
$responseData['php_opcache'] = [
    'files_invalidated' => $publicResult['php_invalidated'] + $secureResult['php_invalidated'],
    'note' => 'Deployed PHP files were invalidated in THIS process\'s OPcache. If the deployed site '
        . 'runs in a different php-fpm pool and that pool has opcache.validate_timestamps=0, it is '
        . 'still serving the previous code — reload php-fpm to pick this deploy up.',
];

// Paths this build does NOT own that already existed and were therefore left
// alone. Reported rather than passed over in silence: on a shared document root
// this is the difference between "your site is fine" and "something quietly did
// not happen".
if ($sharedPaths !== []) {
    $responseData['shared_paths_skipped'] = [
        'count' => count($sharedPaths),
        'paths' => array_slice($sharedPaths, 0, 50),
        'reason' => 'Outside this build\'s own subtree (it is mounted under the "' . $buildSpace . '" URL space), so it belongs to whatever else is served from that document root. Existing files there are never replaced, and overwrite does not reach them.',
    ];
}

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
