<?php
/**
 * Build Command - Creates production-ready deployments
 * 
 * Parameters:
 * - name (optional): Custom build folder name. If omitted, auto-generates build_YYYYMMDD_HHMMSS.
 * - public (optional): Custom public folder name/path (max 5 levels, e.g., 'www/v1/public')
 * - secure (optional): Custom secure folder name (max 1 level, e.g., 'backend')
 * - space (optional): URL path prefix - creates subdirectory inside public folder (max 5 levels, e.g., '' or 'space')
 *                     When set, all public files are placed in: {public}/{space}/
 *                     This allows multiple sub-websites on the same domain (e.g., http://site.com/space/en/)
 * 
 * Output: secure/projects/<id>/qs_build/<name>/ — outside the project's public/,
 * so no URL reaches it. Retention is N = 1: a project holds one build, a second
 * build is refused, and a FAILED build removes its own partial directory.
 * No zip is stored; downloadBuild archives the folder on demand and streams it.
 *
 * The site it produces is self-contained: a front controller copied from
 * src/runtime/site/index.php, parameterised by one generated data file beside
 * it, plus the pre-compiled pages and the small runtime that renders them. A
 * build that cannot answer a request is discarded rather than reported as a
 * success (see the servability gate near the end).
 *
 * Security:
 * - File locking prevents concurrent builds
 * - Public and secure folders must have different root directories
 * - Build size must not exceed MAX_BUILD_SIZE_MB
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToPhpCompiler.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php';
require_once SECURE_FOLDER_PATH . '/src/functions/filePolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/buildSiteRuntime.php';

// Get optional parameters for renaming folders in build
// Defaults are standard names (public/secure/''), NOT the QuickSite installation's own folder names
$params = $trimParametersManagement->params();
$buildPublicName = $params['public'] ?? 'public';
$buildSecureName = $params['secure'] ?? 'secure';
$buildPublicSpace = $params['space'] ?? '';
$buildCustomName = $params['name'] ?? '';

// Validate optional custom build name
if (!empty($buildCustomName)) {
    if (!is_string($buildCustomName)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('name parameter must be a string')
            ->withData(['field' => 'name', 'expected' => 'string'])
            ->send();
    }
    if (strlen($buildCustomName) > 100) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('name must be 100 characters or less')
            ->withErrors([['field' => 'name', 'max_length' => 100, 'actual_length' => strlen($buildCustomName)]])
            ->send();
    }
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $buildCustomName)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('name must contain only letters, numbers, hyphens, underscores and dots (must start with alphanumeric)')
            ->withErrors([['field' => 'name', 'value' => $buildCustomName, 'allowed' => 'a-z, A-Z, 0-9, -, _, . (start with alphanumeric)']])
            ->send();
    }
}

// Validate public folder name parameter
if (!empty($params['public'])) {
    // Type validation
    if (!is_string($params['public'])) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('public parameter must be a string')
            ->withData([
                'field' => 'public',
                'expected_type' => 'string',
                'received_type' => gettype($params['public'])
            ])
            ->send();
    }
    
    // Path validation (max 5 levels like movePublicRoot)
    if (!is_valid_relative_path($buildPublicName, 255, 5, false)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid public folder name (max 5 levels deep, e.g., 'app/v1/public')")
            ->withErrors([
                ['field' => 'public', 'value' => $buildPublicName],
                ['constraints' => [
                    'max_length' => 255,
                    'max_depth' => 5,
                    'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore, forward slash',
                    'empty_allowed' => false
                ]]
            ])
            ->send();
    }
}

// Validate secure folder name parameter
if (!empty($params['secure'])) {
    // Type validation
    if (!is_string($params['secure'])) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('secure parameter must be a string')
            ->withData([
                'field' => 'secure',
                'expected_type' => 'string',
                'received_type' => gettype($params['secure'])
            ])
            ->send();
    }
    
    // Path validation (max 5 levels deep, like public folder)
    if (!is_valid_relative_path($buildSecureName, 255, 5, false)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid secure folder name (max 5 levels deep, e.g., 'app' or 'backend/core')")
            ->withErrors([
                ['field' => 'secure', 'value' => $buildSecureName],
                ['constraints' => [
                    'max_length' => 255,
                    'max_depth' => 5,
                    'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore, forward slash',
                    'empty_allowed' => false
                ]]
            ])
            ->send();
    }
}

// Validate space parameter (PUBLIC_FOLDER_SPACE - URL path prefix)
if (!empty($params['space'])) {
    // Type validation
    if (!is_string($params['space'])) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('space parameter must be a string')
            ->withData([
                'field' => 'space',
                'expected_type' => 'string',
                'received_type' => gettype($params['space'])
            ])
            ->send();
    }
    
    // Path validation (max 5 levels, can be empty)
    if (!is_valid_relative_path($buildPublicSpace, 255, 5, true)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid space parameter (subdirectory for public files, max 5 levels, e.g., '' or 'space/v1')")
            ->withErrors([
                ['field' => 'space', 'value' => $buildPublicSpace],
                ['constraints' => [
                    'max_length' => 255,
                    'max_depth' => 5,
                    'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore, forward slash',
                    'empty_allowed' => true,
                    'note' => 'Creates subdirectory inside public folder where all public files are placed (e.g., "space" creates {public}/space/index.php)'
                ]]
            ])
            ->send();
    }
}

// Security validation: Public and secure folders must NOT share parent directory
if (!empty($params['public']) || !empty($params['secure'])) {
    $publicRoot = explode('/', $buildPublicName)[0];
    $secureRoot = explode('/', $buildSecureName)[0];
    
    if ($publicRoot === $secureRoot) {
        ApiResponse::create(400, 'validation.shared_parent_folder')
            ->withMessage('Public and secure folders cannot share the same root directory for security reasons')
            ->withData([
                'public_root' => $publicRoot,
                'secure_root' => $secureRoot,
                'explanation' => 'If both folders share a parent, the secure folder could be accessible from the public web space through path traversal',
                'example_valid' => [
                    'public' => 'www/assets',
                    'secure' => 'backend/core'
                ],
                'example_invalid' => [
                    'public' => 'app/public',
                    'secure' => 'app/secure'
                ]
            ])
            ->send();
    }
}

// Validate source folders exist before starting build
if (!is_dir(PUBLIC_FOLDER_ROOT)) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage('Source public folder does not exist')
        ->withData(['path' => PUBLIC_FOLDER_ROOT])
        ->send();
}

if (!is_dir(SECURE_FOLDER_PATH)) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage('Source secure folder does not exist')
        ->withData(['path' => SECURE_FOLDER_PATH])
        ->send();
}

// Define build path before locking (so we can scope the lock to this build).
// qs_build_root() — secure/projects/<id>/qs_build/, OUTSIDE the served public/.
$buildPath = qs_build_root();
$timestamp = date('Ymd_His');
// Retention is N = 1: a project holds ONE build, and a second build is REFUSED
// rather than overwriting it. Refused HERE — before the lock, before any
// directory is created — so a build that was never going to be allowed to
// replace the existing one never touches it.
$existingBuild = qs_build_current();
if ($existingBuild !== null) {
    ApiResponse::create(409, 'conflict.already_exists')
        ->withMessage('This project already has a build. Delete it before building again.')
        ->withData([
            'existing_build' => $existingBuild,
            'complete'       => qs_build_is_complete($existingBuild),
            'hint'           => 'Download it first with downloadBuild if you want to keep a copy, then remove it with deleteBuild and run build again.',
            'next_steps'     => ['download' => 'downloadBuild', 'delete' => 'deleteBuild']
        ])
        ->send();
}
// beta.10 C13 13.6b: the auto name is second-resolution, and it used to be
// mkdir'd with no existence check — so two builds inside the same second made
// the second one answer 500 server.directory_create_failed on an operation that
// is perfectly legitimate. Disambiguate with a suffix instead. At N = 1 the
// refusal above already guarantees an empty qs_build/, so this loop is belt and
// braces against a stray same-second directory rather than the load-bearing
// guard it was when builds accumulated.
if ($buildCustomName !== '') {
    $buildFolderName = $buildCustomName;
} else {
    $buildFolderName = 'build_' . $timestamp;
    for ($suffix = 2; $suffix <= 100 && is_dir($buildPath . '/' . $buildFolderName); $suffix++) {
        $buildFolderName = 'build_' . $timestamp . '_' . $suffix;
    }
}
$buildFullPath = $buildPath . '/' . $buildFolderName;

// Sanitize folder name for lock file (replace / with _ to make it filename-safe)
$lockId = 'build_' . str_replace('/', '_', $buildFolderName);

// === CRITICAL SECTION: Use file lock to prevent concurrent builds ===
// Lock is scoped per build name — different builds can run in parallel
$lock = acquireLock($lockId);

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage('Another build with this name is already in progress. Please wait and try again.')
        ->withData(['buildName' => $buildFolderName])
        ->send();
}

// Release the lock. SUCCESS PATH ONLY — a failed build must call
// abort_build() instead, which also removes the partial directory.
//
// This function used to be named for a cleanup it did not do: its comment said
// "release lock and cleanup on error" and its body released the lock and
// nothing else. Of ~30 failure exits exactly one (the size-limit breach) also
// deleted the directory, so every other failure left a partial build on disk
// forever — quota-counted, and indistinguishable from a good build. Under N = 1
// that partial would BLOCK the next build, which is what makes the cleanup
// below load-bearing rather than tidy-up.
function release_build_lock() {
    global $lock;
    if ($lock) {
        releaseLock($lock);
    }
}

/**
 * Abandon a build in progress: release the lock, then remove everything this
 * run created, and answer.
 *
 * The partial must not survive. If the removal itself fails we say so in the
 * response AND leave the build without a manifest — build_manifest.json is
 * written last, so "no manifest" is the durable incomplete marker that getBuild
 * reports and that the next build's refusal message carries.
 *
 * @param ApiResponse $response The refusal to send once the disk is clean.
 */
function abort_build(ApiResponse $response) {
    global $buildFullPath;

    release_build_lock();

    $removed = null;
    if (isset($buildFullPath) && $buildFullPath !== '' && is_dir($buildFullPath)) {
        $removed = deleteDirectory($buildFullPath) && !is_dir($buildFullPath);
    }

    // withData() REPLACES; merge so the caller's own diagnosis survives.
    if ($removed === false) {
        $response->withData(array_merge($response->getData() ?? [], [
            'partial_build_removed' => false,
            'warning'               => 'The incomplete build directory could not be removed. It carries no build_manifest.json, so getBuild reports it as incomplete; remove it with deleteBuild before building again.'
        ]));
    } elseif ($removed === true) {
        $response->withData(array_merge($response->getData() ?? [], [
            'partial_build_removed' => true
        ]));
    }

    $response->send();
}

// Step 1: Create/clear build directory
if (!file_exists($buildPath)) {
    if (!mkdir($buildPath, 0755, true)) {
        abort_build(
            ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage("Failed to create build directory")
        );
    }
}

// The per-name "already exists" 409 that used to sit here is GONE, and its
// removal is deliberate rather than an oversight. At N = 1 the retention
// refusal above has already established that qs_build/ holds no build at all,
// so this branch was unreachable — and it was the single call site where the
// cleanup below would have deleted a GOOD build rather than a partial. One
// refusal, raised before anything is created, replaces it.

// Create build folder
if (!mkdir($buildFullPath, 0755, true)) {
    abort_build(
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage("Failed to create timestamped build folder")
    );
}

// Create build directory structure using configured names
// If space parameter is provided, public files go inside space subdirectory (like movePublicRoot)
$publicBasePath = $buildFullPath . '/' . $buildPublicName;
$publicContentPath = $buildPublicSpace !== '' 
    ? $publicBasePath . '/' . $buildPublicSpace 
    : $publicBasePath;

$directories = [
    $buildFullPath . '/' . $buildPublicName,
    $publicContentPath,
    $publicContentPath . '/style',
    $publicContentPath . '/assets',
    $buildFullPath . '/' . $buildSecureName,
    $buildFullPath . '/' . $buildSecureName . '/src',
    $buildFullPath . '/' . $buildSecureName . '/src/classes',
    $buildFullPath . '/' . $buildSecureName . '/src/functions',
    $buildFullPath . '/' . $buildSecureName . '/templates',
    $buildFullPath . '/' . $buildSecureName . '/templates/pages',
    $buildFullPath . '/' . $buildSecureName . '/translate'
];

foreach ($directories as $dir) {
    if (!file_exists($dir) && !mkdir($dir, 0755, true)) {
        abort_build(
            ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage("Failed to create directory: {$dir}")
        );
    }
}

// Step 2: Emit the entry point
//
// A build's entry point is COPIED from src/runtime/site/index.php and
// PARAMETERISED WITH DATA — one small generated file beside it holding the
// project id, the folder names and the URL space.
//
// It used to be copied from the project's own public/ and then rewritten with
// four preg_replace calls. No project has ever held an index.php or an init.php
// there, so the file_exists() guard skipped both silently and the build emitted
// no entry point at all while answering 201; the block those patches targeted
// had also been moved into qs_load_project_context(), so all four matched
// nothing. There was no source to copy and no block to patch, because a
// QuickSite INSTALL has no root entry point either — its web root is
// deliberately free so the engine never squats the domain. A built site is the
// opposite case: it is the whole site at its root, and every request must
// funnel into it. That entry point is now a real file in the repository, which
// is what lets it be linted, grepped and opened rather than existing only
// inside a build nobody served.
$entryPointSource = qs_site_runtime_source();
if (!is_file($entryPointSource)) {
    abort_build(
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage('The built-site entry point is missing from this QuickSite installation')
            ->withData(['expected' => 'src/runtime/site/index.php'])
    );
}

if (!copy($entryPointSource, $publicContentPath . '/index.php')) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write the site entry point (index.php)')
    );
}

// The parameters the entry point reads. PROJECT_NAME is the REAL project id:
// it names this site's browser-storage namespace (`qsp_<PROJECT_NAME>_<key>`)
// and its theme key, so a built site claiming a different identity from the
// same project at /p/<id>/ would read back none of the visitor's stored state.
$siteConfigPhp = qs_site_config_php(
    (string) PROJECT_NAME,
    $buildPublicName,
    $buildSecureName,
    $buildPublicSpace
);
if (file_put_contents($publicContentPath . '/qs-site.php', $siteConfigPhp) === false) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write the site parameters (qs-site.php)')
    );
}

// The funnel, beside the content it serves.
if (file_put_contents($publicContentPath . '/.htaccess', qs_site_htaccess($buildPublicSpace)) === false) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write the request funnel (.htaccess)')
    );
}

// With a URL space the content sits one or more levels below the document
// root, so the funnel above covers /<space>/ and the root gets nothing at all.
// Give the root its own guard, so a bare "/" does not answer with a listing of
// the deployment's folders. It deliberately does not funnel: the root is not
// this site's.
if ($buildPublicSpace !== ''
    && file_put_contents($publicBasePath . '/.htaccess', qs_site_root_htaccess()) === false) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write the document-root .htaccess')
    );
}

// SECURITY (C11 11.0) — the PUBLISH boundary. These two copies are the point
// where a file stops being project data and becomes something a web server
// hands to the public, so this is where the publish allowlist applies. They
// used to go through FileSystem.php's generic copyDirectory(), which recurses
// and copies with no filter at all — so an executable or server-config file
// planted in a project by any means rode an ordinary build into a deploy root.
// Refusals are reported, not fatal: a stray file must not fail a build.
$skippedUnpublishable = [];

if (!qs_copy_publishable_directory(PUBLIC_CONTENT_PATH . '/style', $publicContentPath . '/style', $skippedUnpublishable)) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy /style/ directory")
    );
}

if (!qs_copy_publishable_directory(PUBLIC_CONTENT_PATH . '/assets', $publicContentPath . '/assets', $skippedUnpublishable)) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy /assets/ directory")
    );
}

// Copy LICENSE file to build root (MIT License requirement)
if (file_exists(SERVER_ROOT . '/LICENSE')) {
    if (!copy(SERVER_ROOT . '/LICENSE', $buildFullPath . '/LICENSE')) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy LICENSE file")
        );
    }
}

// Copy sitemap.txt from project public folder (if generated)
$projectSitemapPath = PROJECT_PATH . '/public/sitemap.txt';
if (file_exists($projectSitemapPath)) {
    copy($projectSitemapPath, $publicContentPath . '/sitemap.txt');
}

// Step 3: Copy secure folder files (selective)

// Copy routes.php
if (!copy(PROJECT_PATH . '/routes.php', $buildFullPath . '/' . $buildSecureName . '/routes.php')) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy routes.php")
    );
}

// Copy config.php
if (!copy(PROJECT_PATH . '/config.php', $buildFullPath . '/' . $buildSecureName . '/config.php')) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write sanitized config.php")
    );
}

// Copy specific class files
$classFiles = [
    'Page.php',
    'Translator.php',
    'TrimParameters.php',
    'RegexPatterns.php'
];

foreach ($classFiles as $file) {
    $source = SECURE_FOLDER_PATH . '/src/classes/' . $file;
    $dest = $buildFullPath . '/' . $buildSecureName . '/src/classes/' . $file;
    
    if (!copy($source, $dest)) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy class file: {$file}")
        );
    }
}

// Copy the function files the compiled pages' runtime requires.
// String.php: removePrefix() + friends, used by TrimParameters.
// projectLanguage.php: the single project-language detection point, required
// by BOTH TrimParameters and Translator — omit it and a built site fatals on
// its first page at a require_once, not at a translation lookup.
// routeHelpers.php: the canonical ':name' ↔ '__name' mapping. The compiler
// writes this build's page folders through it, so the entry point must READ
// them through the same function — a second spelling is how a param route
// stops resolving on one surface and keeps resolving on the other.
// aliasRouting.php: URL aliases, applied by the /p/<id>/ renderer and by a
// built site through one implementation.
$functionFiles = [
    'String.php',
    'projectLanguage.php',
    'routeHelpers.php',
    'aliasRouting.php',
];

foreach ($functionFiles as $file) {
    $source = SECURE_FOLDER_PATH . '/src/functions/' . $file;
    $dest   = $buildFullPath . '/' . $buildSecureName . '/src/functions/' . $file;

    if (!copy($source, $dest)) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy function file: {$file}")
        );
    }
}

// Copy /translate/ directory (only default.json in mono-language mode)
$translateDestPath = $buildFullPath . '/' . $buildSecureName . '/translate';
if (!is_dir($translateDestPath)) {
    mkdir($translateDestPath, 0755, true);
}

if (MULTILINGUAL_SUPPORT) {
    // Multilingual: copy all translation files. Deliberately the generic
    // copyDirectory() and NOT the publish-filtered copier: translations land in
    // the SECURE sibling folder, which a deployment never serves, so this is
    // not a publish boundary and filtering here would drop files for no gain.
    if (!copyDirectory(PROJECT_PATH . '/translate', $translateDestPath)) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy /translate/ directory")
        );
    }
} else {
    // Mono-language: copy only default.json
    $defaultJsonPath = PROJECT_PATH . '/translate/default.json';
    if (file_exists($defaultJsonPath)) {
        if (!copy($defaultJsonPath, $translateDestPath . '/default.json')) {
            abort_build(
                ApiResponse::create(500, 'server.file_write_failed')
                    ->withMessage("Failed to copy default.json")
            );
        }
    } else {
        abort_build(
            ApiResponse::create(404, 'file.not_found')
                ->withMessage("default.json not found - required for mono-language mode")
        );
    }
}

// Copy aliases.json if it exists (for URL alias/redirect support)
$aliasesSource = PROJECT_PATH . '/data/aliases.json';
if (file_exists($aliasesSource)) {
    $dataDir = $buildFullPath . '/' . $buildSecureName . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    if (!copy($aliasesSource, $dataDir . '/aliases.json')) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy aliases.json")
        );
    }
}

// Step 4: Compile menu.php and footer.php using JsonToPhpCompiler
$compiler = new JsonToPhpCompiler();

// Compile menu
$menuJsonPath = PROJECT_PATH . '/templates/model/json/menu.json';
if (file_exists($menuJsonPath)) {
    $menuJson = json_decode(file_get_contents($menuJsonPath), true);
    if ($menuJson === null) {
        abort_build(
            ApiResponse::create(500, 'server.internal_error')
                ->withMessage("Failed to parse menu.json")
        );
    }
    
    $menuPhp = $compiler->compileMenuOrFooter($menuJson);
    if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/templates/menu.php', $menuPhp) === false) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to write compiled menu.php")
        );
    }
}

// Compile footer
$footerJsonPath = PROJECT_PATH . '/templates/model/json/footer.json';
if (file_exists($footerJsonPath)) {
    $footerJson = json_decode(file_get_contents($footerJsonPath), true);
    if ($footerJson === null) {
        abort_build(
            ApiResponse::create(500, 'server.internal_error')
                ->withMessage("Failed to parse footer.json")
        );
    }
    
    $footerPhp = $compiler->compileMenuOrFooter($footerJson);
    if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/templates/footer.php', $footerPhp) === false) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to write compiled footer.php")
        );
    }
}

// Step 4.5: Compile API endpoints config to JavaScript
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';
$apiManager = new ApiEndpointManager(PROJECT_PATH);
$apiConfigPath = $buildFullPath . '/' . $buildPublicName . '/' . ($buildPublicSpace !== '' ? $buildPublicSpace . '/' : '') . 'scripts/qs-api-config.js';

// Ensure scripts directory exists in build
$scriptsDir = dirname($apiConfigPath);
if (!is_dir($scriptsDir)) {
    mkdir($scriptsDir, 0755, true);
}

// Write compiled API config
if (!$apiManager->writeCompiledJs($apiConfigPath)) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write qs-api-config.js")
    );
}

// Step 4.6: Compile routes schema to JavaScript (beta.8 A1 Build Slice 1).
// qs.js's client-side path matcher (Build Slice 2) consumes this on every
// page load so deployed sites know which segments are :params. routeHelpers
// is loaded via utilsManagement which build.php already depends on.
$routesMetaPath = $scriptsDir . '/qs-route-schema.js';
if (!writeRoutesMetaFile(ROUTES, $routesMetaPath)) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write qs-route-schema.js")
    );
}

// Write qs-enums.js for the build. The runtime QS.enum() (and the
// componentList render mode) reads from window.QS_ENUMS, populated by
// this file. Built sites still need it — without it, any binding with
// enum-resolved fields would fall back to raw values + console-warn.
require_once SECURE_FOLDER_PATH . '/src/classes/EnumSyncHelper.php';
$enumsSyncResult = EnumSyncHelper::sync(PROJECT_PATH, $scriptsDir);
if (!($enumsSyncResult['ok'] ?? false)) {
    abort_build(
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write qs-enums.js: " . ($enumsSyncResult['error'] ?? 'unknown'))
    );
}

// Copy qs.js (required for all interaction/event functionality).
// C15 15.2: read the shared ENGINE runtime from its engine-owned home, NOT from the
// project's own public/ (PUBLIC_CONTENT_PATH). A non-served project's PUBLIC_CONTENT_PATH
// is its own public/, which has no qs.js — so building a non-served project silently
// shipped a build with no qs.js. Sourcing the engine copy fixes that.
$qsJsSource = SECURE_FOLDER_PATH . '/src/runtime/qs.js';
if (file_exists($qsJsSource)) {
    if (!copy($qsJsSource, $scriptsDir . '/qs.js')) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy qs.js")
        );
    }
}

// Load page-level events for compilation into pages
$pageEventsFile = PROJECT_PATH . '/data/page-events.json';
$allPageEvents = [];
if (file_exists($pageEventsFile)) {
    $pageEventsContent = @file_get_contents($pageEventsFile);
    if ($pageEventsContent !== false) {
        $allPageEvents = json_decode($pageEventsContent, true) ?? [];
    }
}

// Load per-page state stores for compilation into pages (window.QS_STATE_STORES)
$stateStoresFile = PROJECT_PATH . '/data/state-stores.json';
$allStateStores = [];
if (file_exists($stateStoresFile)) {
    $stateStoresContent = @file_get_contents($stateStoresFile);
    if ($stateStoresContent !== false) {
        $allStateStores = json_decode($stateStoresContent, true) ?? [];
    }
}

// Step 5: Compile all pages based on ROUTES
$compiledPages = [];

// Load RouteLayoutManager for menu/footer visibility settings
require_once SECURE_FOLDER_PATH . '/src/classes/RouteLayoutManager.php';
$layoutManager = new RouteLayoutManager();

// First compile 404 page (special case) - supports folder structure
// 404 pages inherit layout from root (default: menu=true, footer=true)
$page404JsonPath = resolvePageJsonPath('404');
if ($page404JsonPath !== null && file_exists($page404JsonPath)) {
    $page404Json = json_decode(file_get_contents($page404JsonPath), true);
    if ($page404Json === null) {
        abort_build(
            ApiResponse::create(500, 'server.internal_error')
                ->withMessage("Failed to parse 404.json")
        );
    }
    
    // Get layout for 404 page (inherits from root)
    $layout404 = $layoutManager->getEffectiveLayout('404');
    $page404Events = $allPageEvents['404'] ?? [];
    $page404Stores = $allStateStores['404'] ?? [];
    $page404Php = $compiler->compilePage($page404Json, '404', $layout404['menu'], $layout404['footer'], $page404Events, $page404Stores);
    // Create folder structure in build
    @mkdir($buildFullPath . '/' . $buildSecureName . '/templates/pages/404', 0755, true);
    $page404FilePath = $buildFullPath . '/' . $buildSecureName . '/templates/pages/404/404.php';
    
    if (file_put_contents($page404FilePath, $page404Php) === false) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to write compiled 404.php")
        );
    }
    
    $compiledPages[] = '404';
}

// Then compile regular route pages (supports nested routes)
$allRoutes = flattenRoutes(ROUTES);
$skippedPages = [];
foreach ($allRoutes as $route) {
    $pageJsonPath = resolvePageJsonPath($route);
    
    if ($pageJsonPath === null || !file_exists($pageJsonPath)) {
        // Track skipped pages (route exists but JSON missing)
        $skippedPages[] = $route;
        continue;
    }
    
    $pageJson = json_decode(file_get_contents($pageJsonPath), true);
    if ($pageJson === null) {
        abort_build(
            ApiResponse::create(500, 'server.internal_error')
                ->withMessage("Failed to parse {$route}.json")
        );
    }
    
    // Use route name as title (capitalize first letter of last segment).
    // Beta.8 A1 — `:slug` segment as the leaf would give a useless title
    // like ':slug'; titles for param routes are handled at request time
    // anyway (the per-route .php file looks up page.titles.<routePath>).
    $routeName = basename($route);
    $pageTitle = ucfirst(str_replace('-', ' ', ltrim($routeName, ':')));

    // Get layout settings (with inheritance)
    $pageLayout = $layoutManager->getEffectiveLayout($route);
    $routeEvents = $allPageEvents[$route] ?? [];
    $routeStores = $allStateStores[$route] ?? [];
    $pagePhp = $compiler->compilePage($pageJson, $route, $pageLayout['menu'], $pageLayout['footer'], $routeEvents, $routeStores);

    // Create folder structure in build: route/route.php
    // Beta.8 A1 — sanitise `:slug` → `__slug` for the build output path
    // (NTFS reserves ':'). Matches the source-side convention used by
    // resolvePageJsonPath. Helper in routeHelpers.php (already required
    // via utilsManagement.php which build.php depends on).
    $fsRoute = paramRoutePathToFs($route);
    $fsRouteName = paramRouteSegmentToFs($routeName);
    $buildPageDir = $buildFullPath . '/' . $buildSecureName . '/templates/pages/' . $fsRoute;
    @mkdir($buildPageDir, 0755, true);
    $pageFilePath = $buildPageDir . '/' . $fsRouteName . '.php';
    
    if (file_put_contents($pageFilePath, $pagePhp) === false) {
        abort_build(
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to write compiled page: {$route}.php")
        );
    }
    
    $compiledPages[] = $route;
}

// Step 6: Create README.txt with deployment instructions.
//
// The URL space used to be absent from these instructions entirely: they said
// "point your document root to <public>/" while the site actually lived one or
// more levels below it, so a deployer following them exactly reached a root
// that serves nothing.
$spaceNote = $buildPublicSpace !== ''
    ? "\n  This site is mounted under a URL SPACE: it answers at\n"
    . "    http://your-domain/{$buildPublicSpace}/\n"
    . "  The document root is still {$buildPublicName}/, and the site's own files\n"
    . "  live in {$buildPublicName}/{$buildPublicSpace}/. A bare \"/\" is NOT this site.\n"
    : '';

$readme = <<<README
=======================================================
PRODUCTION BUILD - DEPLOYMENT INSTRUCTIONS
=======================================================

Generated on: %DATE%

FOLDER STRUCTURE:

  your-server/
  ├── {$buildPublicName}/    <-- This IS your web root (document root)
  └── {$buildSecureName}/    <-- Sibling folder, next to the public one

DEPLOYMENT STEPS:

1. Upload both folders to your server so they sit side by side.
   Point your web server's document root to the {$buildPublicName}/ folder.
   Example with a typical hosting layout:
     /home/user/htdocs/{$buildPublicName}/    <- document root
     /home/user/htdocs/{$buildSecureName}/    <- private, not web-accessible
{$spaceNote}

2. Permissions (should already be correct from the build):
   - Directories: 755
   - Files: 644
   - PHP must be able to read {$buildSecureName}/

3. Apache: mod_rewrite must be enabled and the document root must allow
   .htaccess to take effect (AllowOverride All, or at least FileInfo +
   Options + Indexes). Without it the request funnel is ignored and every
   page except the home page answers 404.

   nginx: .htaccess is not read at all. A ready-to-use snippet describing
   THIS site is included:
     {$buildSecureName}/nginx_routes.conf
   Add this inside your server { } block:
     include /path/to/{$buildSecureName}/nginx_routes.conf;
   Then test and reload:
     nginx -t && nginx -s reload

4. Test:
   - Visit your domain
   - Check all pages load correctly
   - Test language switching (if multilingual)

REQUIREMENTS:
- PHP 8.0 or newer, with mod_php / php-fpm serving the document root
- No PHP extensions beyond the defaults, no Composer, no database

NOTES:
- This is a production build (no management API included)
- No database required — QuickSite is entirely file-based
- All pages are pre-compiled for performance
- Language mode: %LANG_MODE%

COMPILED PAGES: %PAGES%

=======================================================
README;

$readme = str_replace('%DATE%', date('Y-m-d H:i:s'), $readme);
$readme = str_replace('%PAGES%', implode(', ', $compiledPages), $readme);
$langMode = MULTILINGUAL_SUPPORT ? 'Multilingual (all language files included)' : 'Mono-language (default.json only)';
$readme = str_replace('%LANG_MODE%', $langMode, $readme);

file_put_contents($buildFullPath . '/README.txt', $readme);

// Step 6a: Generate nginx config snippet for nginx users.
//
// The BUILT SITE's config, not the install's. It used to call
// generate_nginx_config() — the installer's own generator — so every build
// shipped locations for /admin/, /management/, /admin/api/ and /p/, none of
// which exist in a build, plus instructions to include a differently-named file
// from a path that is not there and to define a named location "or every
// project URL answers 500". A deployer must not be handed configuration for
// namespaces that do not exist.
$nginxConfig = qs_site_nginx_config($buildPublicName, $buildSecureName, $buildPublicSpace);
file_put_contents($buildFullPath . '/' . $buildSecureName . '/nginx_routes.conf', $nginxConfig);

// Step 6b: Create build manifest. Written LAST on purpose: its presence is
// what marks the build COMPLETE (qs_build_is_complete), which is how a build
// that survived a failed cleanup stays distinguishable from a good one.
$manifest = [
    'name' => $buildFolderName,
    'created' => date('c'), // ISO 8601 format
    'created_timestamp' => time(),
    'public' => $buildPublicName,
    'secure' => $buildSecureName,
    'space' => $buildPublicSpace,
    'multilingual' => CONFIG['MULTILINGUAL_SUPPORT'],
    'languages' => CONFIG['MULTILINGUAL_SUPPORT'] ? CONFIG['LANGUAGES_SUPPORTED'] : ['default'],
    'default_language' => CONFIG['LANGUAGE_DEFAULT'],
    'compiled_pages' => $compiledPages,
    'pages_count' => count($compiledPages),
    'source' => [
        'public_folder' => PUBLIC_FOLDER_NAME,
        'secure_folder' => SECURE_FOLDER_NAME,
        'public_space' => PUBLIC_FOLDER_SPACE
    ],
    'quicksite_version' => '1.4.0'
];
qs_json_write($buildFullPath . '/build_manifest.json', $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Check build size (prevent resource exhaustion)
$buildSizeBytes = getDirectorySize($buildFullPath);
$buildSizeMB = round($buildSizeBytes / 1024 / 1024, 2);
$maxBuildSizeMB = CONFIG['MAX_BUILD_SIZE_MB'] ?? 500;

if ($buildSizeMB > $maxBuildSizeMB) {
    abort_build(
        ApiResponse::create(413, 'validation.size_limit_exceeded')
            ->withMessage("Build size exceeds maximum allowed size")
            ->withData([
                'build_size_mb' => $buildSizeMB,
                'max_size_mb' => $maxBuildSizeMB,
                'note' => 'Increase MAX_BUILD_SIZE_MB in config.php if needed'
            ])
    );
}

// === CAN THIS BUILD SERVE A REQUEST? ===
//
// Asked before success is reported, because "the build completed" and "the
// build can serve" were never the same claim and only the first was ever
// checked. A build could write its pages, its styles and its assets, answer
// 201 operation.success, and contain no entry point of any kind — with an
// .htaccess funnelling every request to a file that was not there.
//
// The gate walks the request path: the funnel target, the parameters it reads,
// the project data, the runtime the compiled pages require, the menu and
// footer they pull in, every compiled route's page at the exact path routing
// will compute for it, and the 404. Structural, not a render — rendering is
// proven by serving the build — but it fails on exactly what used to pass.
//
// Placed AFTER the manifest so a build that fails here is already marked
// complete-then-removed rather than half-marked, and it goes out through
// abort_build() like every other failure: lock released, partial removed,
// removal verified.
$servabilityProblems = qs_site_verify_servable(
    $buildFullPath,
    $buildPublicName,
    $buildSecureName,
    $buildPublicSpace,
    $compiledPages
);

if (!empty($servabilityProblems)) {
    abort_build(
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage('The build completed but cannot serve requests, so it was discarded')
            ->withData([
                'problems'       => $servabilityProblems,
                'problems_count' => count($servabilityProblems),
                'explanation'    => 'A build is pre-compiled pages plus the runtime that serves them. This one is missing part of the request path, so deploying it would produce a site that answers nothing.'
            ])
    );
}

// NO ZIP IS WRITTEN HERE, and that is the point.
//
// The build used to emit BOTH an expanded folder and a zip of that same folder,
// paying disk for the deliverable twice and leaving the archive to go stale
// against the folder beside it. The folder is the build; downloadBuild zips it
// on demand and streams the bytes without storing them, so there is exactly one
// copy on disk and the download can never be out of date. deployBuild copies
// the expanded folder, so it never wanted the archive either.

// Release lock before sending response
release_build_lock();

// Count page events compiled
$pageEventsCount = 0;
foreach ($allPageEvents as $routeKey => $routeEvents) {
    if (in_array($routeKey, $compiledPages, true) && !empty($routeEvents)) {
        $pageEventsCount++;
    }
}

// Step 8: Success response
ApiResponse::create(201, 'operation.success')
    ->withMessage('Production build completed successfully')
    ->withData([
        'build_name' => $buildFolderName,
        'build_path' => $buildFullPath,
        'build_size_mb' => $buildSizeMB,
        'compiled_pages' => $compiledPages,
        'total_pages' => count($compiledPages),
        'skipped_pages' => $skippedPages,
        'skipped_count' => count($skippedPages),
        // Files present in the project's public/ but refused by the publish
        // allowlist, so they never reach a web-served directory (C11 11.0).
        'skipped_unpublishable' => $skippedUnpublishable,
        'skipped_unpublishable_count' => count($skippedUnpublishable),
        'page_events_compiled' => $pageEventsCount,
        'public_folder_name' => $buildPublicName,
        'secure_folder_name' => $buildSecureName,
        'public_folder_space' => $buildPublicSpace,
        'config_sanitized' => true,
        // The site's own front controller, its parameters and its request
        // funnel — all three verified present and consistent before this
        // response was allowed to be a success.
        'entry_point_written' => true,
        'entry_point_verified' => true,
        'project_name' => (string) PROJECT_NAME,
        'menu_compiled' => file_exists($buildFullPath . '/' . $buildSecureName . '/templates/menu.php'),
        'footer_compiled' => file_exists($buildFullPath . '/' . $buildSecureName . '/templates/footer.php'),
        'scripts_copied' => file_exists($scriptsDir . '/qs.js'),
        'build_date' => date('Y-m-d H:i:s'),
        'readme_created' => true,
        // No download_url: the build is not reachable by URL at all. It lives
        // outside public/, and downloadBuild streams it on request.
        'download_with' => 'downloadBuild'
    ])
    ->send();