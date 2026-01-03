<?php
/**
 * importProject Command (Secure Version)
 * 
 * Imports a project from an uploaded ZIP file with secure rebuild approach.
 * PHP files in the ZIP are IGNORED - all PHP is rebuilt from JSON structures.
 * 
 * Security measures:
 * - PHP files in ZIP are skipped (logged as warnings)
 * - config.php rebuilt from validated config.json
 * - routes.php rebuilt from validated routes.json
 * - All page/component PHP rebuilt from JSON using JsonToPhpCompiler
 * 
 * @method POST
 * @route /management/importProject
 * @auth required (admin permission)
 * 
 * @param file $file Uploaded ZIP file (required via multipart/form-data)
 * @param string $name New project name (optional, uses ZIP folder name if not provided)
 * @param bool $overwrite Overwrite if project exists (optional, default: false)
 * @param bool $switch_to Switch to imported project (optional, default: false)
 * 
 * @return ApiResponse Import result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToPhpCompiler.php';

// Allowed keys in config.json import (security: whitelist only)
const IMPORT_ALLOWED_CONFIG_KEYS = [
    'SITE_NAME',
    'LANGUAGES_SUPPORTED',
    'DEFAULT_LANGUAGE',
    'MULTILINGUAL_SUPPORT',
    'TITLE',
    'FAVICON'
];

// Dangerous file extensions to skip
const DANGEROUS_EXTENSIONS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar'];

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_importProject(array $params = [], array $urlParams = []): ApiResponse {
    // Merge query parameters for POST with multipart (query params in URL)
    $params = array_merge($_GET, $_POST, $params);
    
    // Check ZipArchive is available
    if (!class_exists('ZipArchive')) {
        return ApiResponse::create(500, 'server.missing_extension')
            ->withMessage('ZIP extension not available')
            ->withData(['hint' => 'Install php-zip extension']);
    }
    
    // Check for uploaded file
    $uploadedFile = null;
    $zipPath = null;
    
    // Method 1: Check $_FILES for uploaded file
    if (!empty($_FILES['file'])) {
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ApiResponse::create(400, 'upload.failed')
                ->withMessage('File upload failed')
                ->withData(['error_code' => $file['error'], 'error' => getUploadErrorMessage($file['error'])]);
        }
        
        // Validate file type
        $mimeType = mime_content_type($file['tmp_name']);
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
        
        if (!in_array($mimeType, $allowedMimes) && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'zip') {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('File must be a ZIP archive')
                ->withData(['received_type' => $mimeType]);
        }
        
        $zipPath = $file['tmp_name'];
        $uploadedFile = $file['name'];
    }
    // Method 2: Check for file path in params (for internal calls)
    elseif (!empty($params['file_path']) && file_exists($params['file_path'])) {
        $zipPath = $params['file_path'];
        $uploadedFile = basename($params['file_path']);
    }
    else {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('No file uploaded')
            ->withErrors(['file' => 'Required. Upload a ZIP file or provide file_path for internal calls']);
    }
    
    // Options
    $newName = trim($params['name'] ?? '');
    $overwrite = filter_var($params['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Open and validate ZIP
    $zip = new ZipArchive();
    $result = $zip->open($zipPath);
    
    if ($result !== true) {
        return ApiResponse::create(400, 'validation.invalid_zip')
            ->withMessage('Invalid or corrupted ZIP file')
            ->withData(['error_code' => $result]);
    }
    
    // Find project folder in ZIP
    $projectFolder = findProjectFolderInZip($zip);
    
    if ($projectFolder === null) {
        $zip->close();
        return ApiResponse::create(400, 'validation.invalid_structure')
            ->withMessage('Invalid project structure in ZIP')
            ->withErrors(['structure' => 'ZIP must contain a project folder with config.json, routes.json, or templates/model/json/']);
    }
    
    // Determine project name
    $projectName = !empty($newName) ? $newName : $projectFolder['name'];
    
    // Validate project name
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', $projectName)) {
        $zip->close();
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['name' => 'Must start with letter, contain only alphanumeric/dash/underscore, max 50 chars']);
    }
    
    // Reserved names
    $reserved = ['admin', 'management', 'src', 'logs', 'config', 'projects'];
    if (in_array(strtolower($projectName), $reserved)) {
        $zip->close();
        return ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage("Project name '$projectName' is reserved");
    }
    
    // Check if project already exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (is_dir($projectPath)) {
        if (!$overwrite) {
            $zip->close();
            return ApiResponse::create(409, 'resource.already_exists')
                ->withMessage("Project '$projectName' already exists")
                ->withData([
                    'existing_path' => 'secure/projects/' . $projectName,
                    'hint' => 'Set overwrite=true to replace existing project'
                ]);
        }
        
        // Delete existing project
        deleteImportDirectory($projectPath);
    }
    
    // Create project directory structure
    if (!mkdir($projectPath, 0755, true)) {
        $zip->close();
        return ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage('Failed to create project directory');
    }
    
    // Create required subdirectories
    $requiredDirs = [
        '/templates',
        '/templates/pages',
        '/templates/components',
        '/templates/model',
        '/templates/model/json',
        '/templates/model/json/pages',
        '/templates/model/json/components',
        '/translate',
        '/data',
        '/public',
        '/public/assets',
        '/public/style',
        '/public/build'
    ];
    foreach ($requiredDirs as $dir) {
        @mkdir($projectPath . $dir, 0755, true);
    }
    
    // Extract files (secure: skip PHP, track warnings)
    $stats = ['files' => 0, 'directories' => 0, 'total_size' => 0, 'skipped_php' => []];
    $extractResult = extractProjectFromZipSecure($zip, $projectFolder['prefix'], $projectPath, $stats);
    
    $zip->close();
    
    if (!$extractResult['success']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(500, 'server.extract_failed')
            ->withMessage('Failed to extract project files')
            ->withData(['error' => $extractResult['error']]);
    }
    
    // Rebuild PHP files from JSON (secure approach)
    $rebuildResult = rebuildPhpFromJson($projectPath);
    
    if (!$rebuildResult['success']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(500, 'server.rebuild_failed')
            ->withMessage('Failed to rebuild PHP files from JSON')
            ->withData(['error' => $rebuildResult['error']]);
    }
    
    // Validate imported project has required files
    $validation = validateImportedProject($projectPath);
    
    if (!$validation['valid']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(400, 'validation.incomplete_project')
            ->withMessage('Imported project is incomplete')
            ->withErrors($validation['errors']);
    }
    
    // Load project info
    $projectInfo = getImportedProjectInfo($projectPath);
    
    $result = [
        'project' => $projectName,
        'path' => 'secure/projects/' . $projectName,
        'imported' => true,
        'source_file' => $uploadedFile,
        'files_count' => $stats['files'],
        'directories_count' => $stats['directories'],
        'total_size' => formatImportBytes($stats['total_size']),
        'site_name' => $projectInfo['site_name'],
        'routes_count' => $projectInfo['routes_count'],
        'languages' => $projectInfo['languages'],
        'switched_to' => false,
        'security' => [
            'format' => 'v2.0-secure',
            'php_rebuilt_from_json' => true,
            'skipped_php_files' => count($stats['skipped_php']),
            'skipped_files' => $stats['skipped_php']
        ],
        'rebuild_stats' => $rebuildResult['stats'] ?? []
    ];
    
    // Switch to project if requested
    if ($switchTo) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'project' => '" . addslashes($projectName) . "'\n];\n";
        
        if (file_put_contents($targetFile, $targetContent, LOCK_EX) !== false) {
            $result['switched_to'] = true;
        }
    }
    
    return ApiResponse::create(201, 'resource.imported')
        ->withMessage("Project '$projectName' imported successfully (secure rebuild)")
        ->withData($result);
}

/**
 * Find project folder in ZIP archive (v2.0 format)
 */
function findProjectFolderInZip(ZipArchive $zip): ?array {
    // Look for v2.0 format indicators: config.json, routes.json, or templates/model/json/
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts = explode('/', $name);
        
        $filename = basename($name);
        
        // Check for v2.0 JSON format
        if ($filename === 'config.json' || $filename === 'routes.json') {
            if (count($parts) === 1) {
                return ['name' => 'imported_project', 'prefix' => '', 'format' => 'v2.0'];
            } elseif (count($parts) === 2) {
                return ['name' => $parts[0], 'prefix' => $parts[0] . '/', 'format' => 'v2.0'];
            }
        }
        
        // Check for templates/model/json/ structure
        if (strpos($name, 'templates/model/json/') !== false) {
            // Extract project folder name from path
            $jsonPos = strpos($name, '/templates/model/json/');
            if ($jsonPos > 0) {
                $projectFolder = substr($name, 0, $jsonPos);
                $firstSlash = strpos($projectFolder, '/');
                if ($firstSlash === false) {
                    return ['name' => $projectFolder, 'prefix' => $projectFolder . '/', 'format' => 'v2.0'];
                }
            }
        }
        
        // Legacy: check for config.php or routes.php (v1.0 format - still supported)
        if ($filename === 'config.php' || $filename === 'routes.php') {
            if (count($parts) === 1) {
                return ['name' => 'imported_project', 'prefix' => '', 'format' => 'v1.0'];
            } elseif (count($parts) === 2) {
                return ['name' => $parts[0], 'prefix' => $parts[0] . '/', 'format' => 'v1.0'];
            }
        }
    }
    
    return null;
}

/**
 * Extract project from ZIP (secure: skip PHP files)
 */
function extractProjectFromZipSecure(ZipArchive $zip, string $prefix, string $destPath, array &$stats): array {
    $prefixLen = strlen($prefix);
    
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        
        // Skip if not in our prefix
        if ($prefix !== '' && strpos($name, $prefix) !== 0) {
            continue;
        }
        
        // Get relative path within project
        $relativePath = substr($name, $prefixLen);
        
        // Skip empty or root
        if (empty($relativePath) || $relativePath === '/') {
            continue;
        }
        
        // Skip export_info.json (metadata file)
        if ($relativePath === 'export_info.json') {
            continue;
        }
        
        // Get file extension
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        
        // SECURITY: Skip PHP and other dangerous files
        if (in_array($ext, DANGEROUS_EXTENSIONS)) {
            $stats['skipped_php'][] = $relativePath;
            continue;
        }
        
        // Skip .htaccess files
        if (basename($relativePath) === '.htaccess') {
            $stats['skipped_php'][] = $relativePath . ' (htaccess)';
            continue;
        }
        
        $destFilePath = $destPath . '/' . $relativePath;
        
        // If directory (ends with /)
        if (substr($name, -1) === '/') {
            if (!is_dir($destFilePath) && !mkdir($destFilePath, 0755, true)) {
                return ['success' => false, 'error' => "Failed to create directory: $relativePath"];
            }
            $stats['directories']++;
        } else {
            // Ensure parent directory exists
            $parentDir = dirname($destFilePath);
            if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true)) {
                return ['success' => false, 'error' => "Failed to create parent directory for: $relativePath"];
            }
            
            // Extract file
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                return ['success' => false, 'error' => "Failed to read file from ZIP: $relativePath"];
            }
            
            if (file_put_contents($destFilePath, $content) === false) {
                return ['success' => false, 'error' => "Failed to write file: $relativePath"];
            }
            
            $stats['files']++;
            $stats['total_size'] += strlen($content);
        }
    }
    
    return ['success' => true];
}

/**
 * Rebuild PHP files from JSON structures
 */
function rebuildPhpFromJson(string $projectPath): array {
    $stats = [
        'config_rebuilt' => false,
        'routes_rebuilt' => false,
        'pages_rebuilt' => 0,
        'components_rebuilt' => 0,
        'menu_rebuilt' => false,
        'footer_rebuilt' => false
    ];
    
    // 1. Rebuild config.php from config.json
    $configJsonPath = $projectPath . '/config.json';
    if (file_exists($configJsonPath)) {
        $configJson = json_decode(file_get_contents($configJsonPath), true);
        if ($configJson === null) {
            return ['success' => false, 'error' => 'Invalid config.json format'];
        }
        
        // Validate and filter config keys
        $validConfig = [];
        foreach (IMPORT_ALLOWED_CONFIG_KEYS as $key) {
            if (isset($configJson[$key])) {
                $validConfig[$key] = $configJson[$key];
            }
        }
        
        // Set defaults for required keys
        if (!isset($validConfig['SITE_NAME'])) {
            $validConfig['SITE_NAME'] = basename($projectPath);
        }
        if (!isset($validConfig['LANGUAGES_SUPPORTED'])) {
            $validConfig['LANGUAGES_SUPPORTED'] = ['en'];
        }
        if (!isset($validConfig['DEFAULT_LANGUAGE'])) {
            $validConfig['DEFAULT_LANGUAGE'] = 'en';
        }
        if (!isset($validConfig['MULTILINGUAL_SUPPORT'])) {
            $validConfig['MULTILINGUAL_SUPPORT'] = false;
        }
        
        $configPhp = "<?php\n/**\n * Site Configuration\n * Rebuilt from JSON on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($validConfig, true) . ";\n";
        
        if (file_put_contents($projectPath . '/config.php', $configPhp) === false) {
            return ['success' => false, 'error' => 'Failed to write config.php'];
        }
        
        $stats['config_rebuilt'] = true;
        
        // Remove config.json after successful rebuild
        unlink($configJsonPath);
    } else {
        // Create default config if no config.json
        $defaultConfig = [
            'SITE_NAME' => basename($projectPath),
            'LANGUAGES_SUPPORTED' => ['en'],
            'DEFAULT_LANGUAGE' => 'en',
            'MULTILINGUAL_SUPPORT' => false
        ];
        $configPhp = "<?php\n/**\n * Site Configuration (default)\n * Created on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($defaultConfig, true) . ";\n";
        file_put_contents($projectPath . '/config.php', $configPhp);
        $stats['config_rebuilt'] = true;
    }
    
    // 2. Rebuild routes.php from routes.json
    $routesJsonPath = $projectPath . '/routes.json';
    if (file_exists($routesJsonPath)) {
        $routesJson = json_decode(file_get_contents($routesJsonPath), true);
        if ($routesJson === null) {
            return ['success' => false, 'error' => 'Invalid routes.json format'];
        }
        
        // Validate routes structure (should be nested array)
        if (!is_array($routesJson)) {
            return ['success' => false, 'error' => 'routes.json must be an array'];
        }
        
        $routesPhp = "<?php\n/**\n * Route Definitions\n * Rebuilt from JSON on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($routesJson, true) . ";\n";
        
        if (file_put_contents($projectPath . '/routes.php', $routesPhp) === false) {
            return ['success' => false, 'error' => 'Failed to write routes.php'];
        }
        
        $stats['routes_rebuilt'] = true;
        
        // Remove routes.json after successful rebuild
        unlink($routesJsonPath);
    } else {
        // Create default routes if no routes.json
        $defaultRoutes = ['home' => []];
        $routesPhp = "<?php\n/**\n * Route Definitions (default)\n * Created on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($defaultRoutes, true) . ";\n";
        file_put_contents($projectPath . '/routes.php', $routesPhp);
        $stats['routes_rebuilt'] = true;
    }
    
    // 3. Rebuild page PHP files from JSON structures
    $compiler = new JsonToPhpCompiler();
    $pagesJsonDir = $projectPath . '/templates/model/json/pages';
    
    if (is_dir($pagesJsonDir)) {
        $result = rebuildPagesFromJson($pagesJsonDir, $projectPath . '/templates/pages', $compiler, $stats);
        if (!$result['success']) {
            return $result;
        }
    }
    
    // 4. Rebuild component PHP files from JSON structures
    $componentsJsonDir = $projectPath . '/templates/model/json/components';
    
    if (is_dir($componentsJsonDir)) {
        $result = rebuildComponentsFromJson($componentsJsonDir, $projectPath . '/templates/components', $compiler, $stats);
        if (!$result['success']) {
            return $result;
        }
    }
    
    // 5. Rebuild menu.php from menu.json
    $menuJsonPath = $projectPath . '/templates/model/json/menu.json';
    if (file_exists($menuJsonPath)) {
        $menuJson = json_decode(file_get_contents($menuJsonPath), true);
        if ($menuJson !== null) {
            $menuPhp = $compiler->compileMenuOrFooter($menuJson);
            file_put_contents($projectPath . '/templates/menu.php', $menuPhp);
            $stats['menu_rebuilt'] = true;
        }
    }
    
    // 6. Rebuild footer.php from footer.json
    $footerJsonPath = $projectPath . '/templates/model/json/footer.json';
    if (file_exists($footerJsonPath)) {
        $footerJson = json_decode(file_get_contents($footerJsonPath), true);
        if ($footerJson !== null) {
            $footerPhp = $compiler->compileMenuOrFooter($footerJson);
            file_put_contents($projectPath . '/templates/footer.php', $footerPhp);
            $stats['footer_rebuilt'] = true;
        }
    }
    
    return ['success' => true, 'stats' => $stats];
}

/**
 * Rebuild page PHP files from JSON directory (recursive for nested routes)
 * 
 * JSON structure: pages/home/home.json OR pages/404.json
 * PHP structure:  pages/home/home.php, pages/404/404.php
 * 
 * Rule: If JSON is in folder matching its name (home/home.json), create same structure
 *       If JSON is at root (404.json), create folder (404/404.php)
 */
function rebuildPagesFromJson(string $jsonDir, string $phpDir, JsonToPhpCompiler $compiler, array &$stats, string $prefix = ''): array {
    $items = scandir($jsonDir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $jsonPath = $jsonDir . '/' . $item;
        
        if (is_dir($jsonPath)) {
            // It's a directory - check if it contains a JSON file with same name
            $expectedJsonFile = $item . '.json';
            $hasMatchingJson = file_exists($jsonPath . '/' . $expectedJsonFile);
            
            // Calculate current route
            $currentRoute = $prefix ? $prefix . '/' . $item : $item;
            
            // Create matching PHP directory
            $subPhpDir = $phpDir . '/' . $item;
            @mkdir($subPhpDir, 0755, true);
            
            // If this folder has a matching JSON file, compile it
            if ($hasMatchingJson) {
                $pageJsonFile = $jsonPath . '/' . $expectedJsonFile;
                $pageJson = json_decode(file_get_contents($pageJsonFile), true);
                if ($pageJson !== null) {
                    $pagePhp = $compiler->compilePage($pageJson, $currentRoute);
                    $phpPath = $subPhpDir . '/' . $item . '.php';
                    
                    if (file_put_contents($phpPath, $pagePhp) === false) {
                        return ['success' => false, 'error' => "Failed to write: $item.php at $phpPath"];
                    }
                    $stats['pages_rebuilt']++;
                }
            }
            
            // Recurse for nested routes (but skip the already-processed matching JSON)
            $result = rebuildPagesFromJsonSubdir($jsonPath, $subPhpDir, $compiler, $stats, $currentRoute, $expectedJsonFile);
            if (!$result['success']) {
                return $result;
            }
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            // It's a JSON file at this level (not in a matching folder)
            // Example: pages/404.json → pages/404/404.php
            
            $routeName = pathinfo($item, PATHINFO_FILENAME);
            $currentRoute = $prefix ? $prefix . '/' . $routeName : $routeName;
            
            $pageJson = json_decode(file_get_contents($jsonPath), true);
            if ($pageJson === null) {
                return ['success' => false, 'error' => "Invalid JSON in: $currentRoute.json"];
            }
            
            $pagePhp = $compiler->compilePage($pageJson, $currentRoute);
            
            // Create folder for this page
            $pageDir = $phpDir . '/' . $routeName;
            @mkdir($pageDir, 0755, true);
            
            $phpPath = $pageDir . '/' . $routeName . '.php';
            if (file_put_contents($phpPath, $pagePhp) === false) {
                return ['success' => false, 'error' => "Failed to write: $routeName.php"];
            }
            
            $stats['pages_rebuilt']++;
        }
    }
    
    return ['success' => true];
}

/**
 * Helper to recurse into subdirectories, skipping already-processed files
 */
function rebuildPagesFromJsonSubdir(string $jsonDir, string $phpDir, JsonToPhpCompiler $compiler, array &$stats, string $prefix, string $skipFile): array {
    $items = scandir($jsonDir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === $skipFile) {
            continue;
        }
        
        $jsonPath = $jsonDir . '/' . $item;
        
        if (is_dir($jsonPath)) {
            // Nested subdirectory
            $subName = $item;
            $currentRoute = $prefix . '/' . $subName;
            $expectedJsonFile = $subName . '.json';
            $hasMatchingJson = file_exists($jsonPath . '/' . $expectedJsonFile);
            
            $subPhpDir = $phpDir . '/' . $subName;
            @mkdir($subPhpDir, 0755, true);
            
            if ($hasMatchingJson) {
                $pageJsonFile = $jsonPath . '/' . $expectedJsonFile;
                $pageJson = json_decode(file_get_contents($pageJsonFile), true);
                if ($pageJson !== null) {
                    $pagePhp = $compiler->compilePage($pageJson, $currentRoute);
                    $phpPath = $subPhpDir . '/' . $subName . '.php';
                    file_put_contents($phpPath, $pagePhp);
                    $stats['pages_rebuilt']++;
                }
            }
            
            $result = rebuildPagesFromJsonSubdir($jsonPath, $subPhpDir, $compiler, $stats, $currentRoute, $expectedJsonFile);
            if (!$result['success']) {
                return $result;
            }
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            // Loose JSON file in subdirectory
            $routeName = pathinfo($item, PATHINFO_FILENAME);
            $currentRoute = $prefix . '/' . $routeName;
            
            $pageJson = json_decode(file_get_contents($jsonPath), true);
            if ($pageJson !== null) {
                $pagePhp = $compiler->compilePage($pageJson, $currentRoute);
                $phpPath = $phpDir . '/' . $routeName . '.php';
                file_put_contents($phpPath, $pagePhp);
                $stats['pages_rebuilt']++;
            }
        }
    }
    
    return ['success' => true];
}

/**
 * Rebuild component PHP files from JSON directory
 */
function rebuildComponentsFromJson(string $jsonDir, string $phpDir, JsonToPhpCompiler $compiler, array &$stats): array {
    $items = scandir($jsonDir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $jsonPath = $jsonDir . '/' . $item;
        
        if (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            // Compile JSON to PHP (components use compileMenuOrFooter style)
            $componentJson = json_decode(file_get_contents($jsonPath), true);
            if ($componentJson === null) {
                continue; // Skip invalid JSON
            }
            
            $componentName = pathinfo($item, PATHINFO_FILENAME);
            $componentPhp = $compiler->compileMenuOrFooter($componentJson);
            
            $phpPath = $phpDir . '/' . $componentName . '.php';
            if (file_put_contents($phpPath, $componentPhp) !== false) {
                $stats['components_rebuilt']++;
            }
        }
    }
    
    return ['success' => true];
}

/**
 * Validate imported project structure
 */
function validateImportedProject(string $projectPath): array {
    $errors = [];
    
    // Check for config.php (should have been rebuilt)
    if (!file_exists($projectPath . '/config.php')) {
        $errors['config'] = 'config.php missing (rebuild failed)';
    }
    
    // Check for routes.php (should have been rebuilt)
    if (!file_exists($projectPath . '/routes.php')) {
        $errors['routes'] = 'routes.php missing (rebuild failed)';
    }
    
    // Check templates directory exists
    if (!is_dir($projectPath . '/templates')) {
        $errors['templates'] = 'templates directory missing';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Get info from imported project
 */
function getImportedProjectInfo(string $projectPath): array {
    $info = [
        'site_name' => 'Unknown',
        'routes_count' => 0,
        'languages' => []
    ];
    
    // Load config
    if (file_exists($projectPath . '/config.php')) {
        $config = require $projectPath . '/config.php';
        $info['site_name'] = $config['SITE_NAME'] ?? 'Unknown';
    }
    
    // Count routes (recursive for nested)
    if (file_exists($projectPath . '/routes.php')) {
        $routes = require $projectPath . '/routes.php';
        $info['routes_count'] = countRoutesRecursive($routes);
    }
    
    // List languages
    $translateDir = $projectPath . '/translate';
    if (is_dir($translateDir)) {
        foreach (scandir($translateDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json' && $file !== 'default.json') {
                $info['languages'][] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
    }
    
    return $info;
}

/**
 * Count routes recursively
 */
function countRoutesRecursive(array $routes): int {
    $count = 0;
    foreach ($routes as $name => $children) {
        $count++;
        if (is_array($children) && !empty($children)) {
            $count += countRoutesRecursive($children);
        }
    }
    return $count;
}

/**
 * Recursively delete a directory
 */
function deleteImportDirectory(string $dir): bool {
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
            deleteImportDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * Get upload error message
 */
function getUploadErrorMessage(int $errorCode): string {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
    ];
    
    return $messages[$errorCode] ?? 'Unknown error';
}

/**
 * Format bytes to human readable
 */
function formatImportBytes(int $bytes): string {
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
    __command_importProject($trimParams->params(), $trimParams->additionalParams())->send();
}
