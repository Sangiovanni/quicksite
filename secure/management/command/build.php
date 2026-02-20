<?php
/**
 * Build Command - Creates production-ready deployments
 * 
 * Parameters:
 * - public (optional): Custom public folder name/path (max 5 levels, e.g., 'www/v1/public')
 * - secure (optional): Custom secure folder name (max 1 level, e.g., 'backend')
 * - space (optional): URL path prefix - creates subdirectory inside public folder (max 5 levels, e.g., '' or 'space')
 *                     When set, all public files are placed in: {public}/{space}/
 *                     This allows multiple sub-websites on the same domain (e.g., http://site.com/space/en/)
 * 
 * Security:
 * - File locking prevents concurrent builds
 * - Public and secure folders must have different root directories
 * - Secure folder restricted to single name (no nesting) for init.php compatibility
 * - Build size must not exceed MAX_BUILD_SIZE_MB
 * - Config file sanitized (DB credentials removed)
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToPhpCompiler.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/ZipUtilities.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Get active project name from target.php
 */
if (!function_exists('getActiveProjectName')) {
    function getActiveProjectName(): ?string {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            return is_array($target) ? ($target['project'] ?? null) : $target;
        }
        return null;
    }
}

// Get optional parameters for renaming folders in build
$params = $trimParametersManagement->params();
$buildPublicName = $params['public'] ?? PUBLIC_FOLDER_NAME;
$buildSecureName = $params['secure'] ?? SECURE_FOLDER_NAME;
$buildPublicSpace = $params['space'] ?? PUBLIC_FOLDER_SPACE;

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
    
    // Path validation (max 1 level - single folder name only)
    if (!is_valid_relative_path($buildSecureName, 255, 1, false)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid secure folder name (single folder name only, e.g., 'app' or 'backend')")
            ->withErrors([
                ['field' => 'secure', 'value' => $buildSecureName],
                ['constraints' => [
                    'max_length' => 255,
                    'max_depth' => 1,
                    'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore',
                    'empty_allowed' => false,
                    'note' => 'Nested paths not allowed for secure folder due to init.php path resolution limitations'
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

// === CRITICAL SECTION: Use file lock to prevent concurrent builds ===
// Acquire exclusive lock (blocks other processes)
$lock = acquireLock('build');

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage('Another build operation is in progress. Please wait and try again.')
        ->send();
}

// Helper function to release lock and cleanup on error (uses global $lock)
function release_build_lock() {
    global $lock;
    if ($lock) {
        releaseLock($lock);
    }
}

// Define build path (inside locked section)
$buildPath = PUBLIC_FOLDER_ROOT . '/build';
$timestamp = date('Ymd_His');
$buildFolderName = 'build_' . $timestamp;
$buildFullPath = $buildPath . '/' . $buildFolderName;

// Step 1: Create/clear build directory
if (!file_exists($buildPath)) {
    if (!mkdir($buildPath, 0755, true)) {
        release_build_lock();
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage("Failed to create build directory")
            ->send();
    }
}

// Create timestamped build folder
if (!mkdir($buildFullPath, 0755, true)) {
    release_build_lock();
    ApiResponse::create(500, 'server.directory_create_failed')
        ->withMessage("Failed to create timestamped build folder")
        ->send();
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
        release_build_lock();
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage("Failed to create directory: {$dir}")
            ->send();
    }
}

// Step 2: Copy public folder files
$publicFiles = [
    'index.php',
    'init.php',
    '.htaccess'
];

foreach ($publicFiles as $file) {
    $source = PUBLIC_FOLDER_ROOT . '/' . $file;
    $dest = $publicContentPath . '/' . $file;  // Use publicContentPath (includes space if set)
    
    if (file_exists($source)) {
        $content = file_get_contents($source);
        
        if ($file === 'init.php') {
            // === ALWAYS patch init.php for production builds ===
            
            // Replace PROJECT_PATH block: production builds don't use target.php
            // All project files are at SECURE_FOLDER_PATH root (config.php, routes.php, templates/, translate/)
            $content = preg_replace(
                "/if\s*\(\s*!defined\('PROJECT_PATH'\)\s*\)\s*\{.*?\n\}/s",
                "if (!defined('PROJECT_PATH')) {\n    define('PROJECT_PATH', SECURE_FOLDER_PATH);\n    define('PROJECT_NAME', 'production');\n}",
                $content
            );
            
            // Replace folder name references if names changed
            if ($buildPublicName !== PUBLIC_FOLDER_NAME || $buildSecureName !== SECURE_FOLDER_NAME || $buildPublicSpace !== PUBLIC_FOLDER_SPACE) {
                // Update SECURE_FOLDER_PATH in init.php (use SERVER_ROOT, not dirname)
                $content = preg_replace(
                    "/define\('SECURE_FOLDER_PATH',\s*[^)]+\);/",
                    "define('SECURE_FOLDER_PATH', SERVER_ROOT . DIRECTORY_SEPARATOR . '" . $buildSecureName . "');",
                    $content
                );
                
                // Update PUBLIC_FOLDER_NAME constant
                $content = preg_replace(
                    "/define\('PUBLIC_FOLDER_NAME',\s*'[^']+'\);/",
                    "define('PUBLIC_FOLDER_NAME', '" . $buildPublicName . "');",
                    $content
                );
                
                // Update SECURE_FOLDER_NAME constant
                $content = preg_replace(
                    "/define\('SECURE_FOLDER_NAME',\s*'[^']+'\);/",
                    "define('SECURE_FOLDER_NAME', '" . $buildSecureName . "');",
                    $content
                );
                
                // Update PUBLIC_FOLDER_SPACE constant
                $content = preg_replace(
                    "/define\('PUBLIC_FOLDER_SPACE',\s*'[^']*'\);/",
                    "define('PUBLIC_FOLDER_SPACE', '" . $buildPublicSpace . "');",
                    $content
                );
            }
        }
        
        // Update .htaccess fallback path if space is used
        if ($file === '.htaccess' && $buildPublicSpace !== '') {
            $fallback = '/' . $buildPublicSpace . '/index.php';
            $content = preg_replace(
                "/FallbackResource\s+.*/",
                "FallbackResource " . $fallback,
                $content
            );
        }
        
        // Strip component preview section from index.php (dev-only feature, requires JsonToHtmlRenderer)
        if ($file === 'index.php') {
            $content = preg_replace(
                '/\/\/ --- Component Preview Mode.*?exit;\s*\}\s*\n/s',
                '',
                $content
            );
        }
        
        if (file_put_contents($dest, $content) === false) {
            release_build_lock();
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy file: {$file}")
                ->send();
        }
    }
}

// Copy /style/ directory recursively (using FileSystem utility)
if (!copyDirectory(PUBLIC_FOLDER_ROOT . '/style', $publicContentPath . '/style')) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy /style/ directory")
        ->send();
}

// Copy /assets/ directory recursively
if (!copyDirectory(PUBLIC_FOLDER_ROOT . '/assets', $publicContentPath . '/assets')) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy /assets/ directory")
        ->send();
}

// Copy LICENSE file to build root (MIT License requirement)
if (file_exists(SERVER_ROOT . '/LICENSE')) {
    if (!copy(SERVER_ROOT . '/LICENSE', $buildFullPath . '/LICENSE')) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy LICENSE file")
            ->send();
    }
}

// Step 3: Copy secure folder files (selective)

// Copy routes.php
if (!copy(PROJECT_PATH . '/routes.php', $buildFullPath . '/' . $buildSecureName . '/routes.php')) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy routes.php")
        ->send();
}

// Copy and sanitize config.php (remove DB credentials)
$configContent = file_get_contents(PROJECT_PATH . '/config.php');
if ($configContent === false) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read config.php")
        ->send();
}

// Remove database credentials (replace with empty strings)
$configContent = preg_replace(
    [
        "/'DB_HOST'\s*=>\s*'[^']*'/",
        "/'DB_NAME'\s*=>\s*'[^']*'/",
        "/'DB_USER'\s*=>\s*'[^']*'/",
        "/'DB_PASS'\s*=>\s*'[^']*'/"
    ],
    [
        "'DB_HOST' => ''",
        "'DB_NAME' => ''",
        "'DB_USER' => ''",
        "'DB_PASS' => ''"
    ],
    $configContent
);

if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/config.php', $configContent) === false) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write sanitized config.php")
        ->send();
}

// Copy specific class files
$classFiles = [
    'Page.php',
    'Translator.php',
    'TrimParameters.php'
];

foreach ($classFiles as $file) {
    $source = SECURE_FOLDER_PATH . '/src/classes/' . $file;
    $dest = $buildFullPath . '/' . $buildSecureName . '/src/classes/' . $file;
    
    if (!copy($source, $dest)) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy class file: {$file}")
            ->send();
    }
}

// Copy String.php function
if (!copy(SECURE_FOLDER_PATH . '/src/functions/String.php', $buildFullPath . '/' . $buildSecureName . '/src/functions/String.php')) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy String.php")
        ->send();
}

// Copy /translate/ directory (only default.json in mono-language mode)
$translateDestPath = $buildFullPath . '/' . $buildSecureName . '/translate';
if (!is_dir($translateDestPath)) {
    mkdir($translateDestPath, 0755, true);
}

if (MULTILINGUAL_SUPPORT) {
    // Multilingual: copy all translation files
    if (!copyDirectory(PROJECT_PATH . '/translate', $translateDestPath)) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy /translate/ directory")
            ->send();
    }
} else {
    // Mono-language: copy only default.json
    $defaultJsonPath = PROJECT_PATH . '/translate/default.json';
    if (file_exists($defaultJsonPath)) {
        if (!copy($defaultJsonPath, $translateDestPath . '/default.json')) {
            release_build_lock();
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy default.json")
                ->send();
        }
    } else {
        release_build_lock();
        ApiResponse::create(404, 'file.not_found')
            ->withMessage("default.json not found - required for mono-language mode")
            ->send();
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
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy aliases.json")
            ->send();
    }
}

// Step 4: Compile menu.php and footer.php using JsonToPhpCompiler
$compiler = new JsonToPhpCompiler();

// Compile menu
$menuJsonPath = PROJECT_PATH . '/templates/model/json/menu.json';
if (file_exists($menuJsonPath)) {
    $menuJson = json_decode(file_get_contents($menuJsonPath), true);
    if ($menuJson === null) {
        release_build_lock();
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse menu.json")
            ->send();
    }
    
    $menuPhp = $compiler->compileMenuOrFooter($menuJson);
    if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/templates/menu.php', $menuPhp) === false) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled menu.php")
            ->send();
    }
}

// Compile footer
$footerJsonPath = PROJECT_PATH . '/templates/model/json/footer.json';
if (file_exists($footerJsonPath)) {
    $footerJson = json_decode(file_get_contents($footerJsonPath), true);
    if ($footerJson === null) {
        release_build_lock();
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse footer.json")
            ->send();
    }
    
    $footerPhp = $compiler->compileMenuOrFooter($footerJson);
    if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/templates/footer.php', $footerPhp) === false) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled footer.php")
            ->send();
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
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write qs-api-config.js")
        ->send();
}

// Copy qs.js (required for all interaction/event functionality)
$qsJsSource = PUBLIC_FOLDER_ROOT . '/scripts/qs.js';
if (file_exists($qsJsSource)) {
    if (!copy($qsJsSource, $scriptsDir . '/qs.js')) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy qs.js")
            ->send();
    }
}

// Copy qs-custom.js (user-defined custom functions)
$qsCustomJsSource = PUBLIC_FOLDER_ROOT . '/scripts/qs-custom.js';
if (file_exists($qsCustomJsSource) && filesize($qsCustomJsSource) > 500) {
    if (!copy($qsCustomJsSource, $scriptsDir . '/qs-custom.js')) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy qs-custom.js")
            ->send();
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
        release_build_lock();
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse 404.json")
            ->send();
    }
    
    // Get layout for 404 page (inherits from root)
    $layout404 = $layoutManager->getEffectiveLayout('404');
    $page404Events = $allPageEvents['404'] ?? [];
    $page404Php = $compiler->compilePage($page404Json, '404', $layout404['menu'], $layout404['footer'], $page404Events);
    // Create folder structure in build
    @mkdir($buildFullPath . '/' . $buildSecureName . '/templates/pages/404', 0755, true);
    $page404FilePath = $buildFullPath . '/' . $buildSecureName . '/templates/pages/404/404.php';
    
    if (file_put_contents($page404FilePath, $page404Php) === false) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled 404.php")
            ->send();
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
        release_build_lock();
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse {$route}.json")
            ->send();
    }
    
    // Use route name as title (capitalize first letter of last segment)
    $routeName = basename($route);
    $pageTitle = ucfirst(str_replace('-', ' ', $routeName));
    
    // Get layout settings (with inheritance)
    $pageLayout = $layoutManager->getEffectiveLayout($route);
    $routeEvents = $allPageEvents[$route] ?? [];
    $pagePhp = $compiler->compilePage($pageJson, $route, $pageLayout['menu'], $pageLayout['footer'], $routeEvents);
    
    // Create folder structure in build: route/route.php
    $buildPageDir = $buildFullPath . '/' . $buildSecureName . '/templates/pages/' . $route;
    @mkdir($buildPageDir, 0755, true);
    $pageFilePath = $buildPageDir . '/' . $routeName . '.php';
    
    if (file_put_contents($pageFilePath, $pagePhp) === false) {
        release_build_lock();
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled page: {$route}.php")
            ->send();
    }
    
    $compiledPages[] = $route;
}

// Step 6: Create README.txt with deployment instructions
$readme = <<<README
=======================================================
PRODUCTION BUILD - DEPLOYMENT INSTRUCTIONS
=======================================================

This build was generated on: %DATE%

FOLDER STRUCTURE:
- {$buildPublicName}/  -> Deploy to your web root (public_html, www, etc.)
- {$buildSecureName}/  -> Deploy OUTSIDE web root (one level up from public)

DEPLOYMENT STEPS:

1. Configure Database (REQUIRED):
   Edit {$buildSecureName}/config.php and set:
   - DB_HOST (e.g., 'localhost')
   - DB_NAME (your database name)
   - DB_USER (database username)
   - DB_PASS (database password)

2. Upload Files:
   - Upload {$buildPublicName}/ contents to your web root
   - Upload {$buildSecureName}/ to parent directory of web root

3. Update init.php (if needed):
   - Edit {$buildPublicName}/init.php
   - Verify SECURE_FOLDER_PATH points to correct location

4. Set Permissions:
   - Directories: 755
   - Files: 644
   - Ensure PHP can read {$buildSecureName}/ folder

5. Test:
   - Visit your domain
   - Check all pages work
   - Test language switching (if multilingual)

NOTES:
- This is a production build (no management API)
- Database credentials are intentionally blank for security
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

// Step 6b: Create build manifest for listBuilds/getBuild commands
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
file_put_contents($buildFullPath . '/build_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Check build size before creating ZIP (prevent resource exhaustion)
$buildSizeBytes = getDirectorySize($buildFullPath);
$buildSizeMB = round($buildSizeBytes / 1024 / 1024, 2);
$maxBuildSizeMB = CONFIG['MAX_BUILD_SIZE_MB'] ?? 500;

if ($buildSizeMB > $maxBuildSizeMB) {
    release_build_lock();
    // Clean up failed build folder
    deleteDirectory($buildFullPath);
    
    ApiResponse::create(413, 'validation.size_limit_exceeded')
        ->withMessage("Build size exceeds maximum allowed size")
        ->withData([
            'build_size_mb' => $buildSizeMB,
            'max_size_mb' => $maxBuildSizeMB,
            'note' => 'Increase MAX_BUILD_SIZE_MB in config.php if needed'
        ])
        ->send();
}

// Step 7: Create ZIP archive
$zipFilename = $buildFolderName . '.zip';
$zipPath = $buildPath . '/' . $zipFilename;

// Use ZipArchive to create compressed archive (using ZipUtilities)
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    release_build_lock();
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to create ZIP archive")
        ->send();
}

// Add all build files to ZIP (using ZipUtilities function)
addDirectoryToZip($zip, $buildFullPath, basename($buildFullPath));
$zip->close();

// Get ZIP file size
$zipSize = filesize($zipPath);
$zipSizeMB = round($zipSize / 1024 / 1024, 2);

// Calculate original folder size for comparison (using FileSystem utility)
$originalSize = getDirectorySize($buildFullPath);
$originalSizeMB = round($originalSize / 1024 / 1024, 2);
$compressionRatio = round((1 - ($zipSize / $originalSize)) * 100, 1);

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
        'build_path' => $buildFullPath,
        'zip_path' => $zipPath,
        'zip_filename' => $zipFilename,
        'zip_size_mb' => $zipSizeMB,
        'original_size_mb' => $originalSizeMB,
        'compression_ratio' => $compressionRatio . '%',
        'compiled_pages' => $compiledPages,
        'total_pages' => count($compiledPages),
        'skipped_pages' => $skippedPages,
        'skipped_count' => count($skippedPages),
        'page_events_compiled' => $pageEventsCount,
        'public_folder_name' => $buildPublicName,
        'secure_folder_name' => $buildSecureName,
        'public_folder_space' => $buildPublicSpace,
        'config_sanitized' => true,
        'menu_compiled' => file_exists($buildFullPath . '/' . $buildSecureName . '/templates/menu.php'),
        'footer_compiled' => file_exists($buildFullPath . '/' . $buildSecureName . '/templates/footer.php'),
        'scripts_copied' => file_exists($scriptsDir . '/qs.js'),
        'build_date' => date('Y-m-d H:i:s'),
        'readme_created' => true,
        'download_url' => BASE_URL . '/build/' . $zipFilename
    ])
    ->send();