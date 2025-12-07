<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToPhpCompiler.php';

// Get optional parameters for renaming folders in build
$params = $trimParametersManagement->params();
$buildPublicName = $params['public'] ?? PUBLIC_FOLDER_NAME;
$buildSecureName = $params['secure'] ?? SECURE_FOLDER_NAME;

// Validate folder names (same rules as movePublicRoot/moveSecureRoot)
if (!empty($params['public'])) {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $buildPublicName) || strlen($buildPublicName) > 255) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid public folder name (alphanumeric, hyphens, underscores only, max 255 chars)")
            ->send();
    }
}

if (!empty($params['secure'])) {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $buildSecureName) || strlen($buildSecureName) > 255) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid secure folder name (alphanumeric, hyphens, underscores only, max 255 chars)")
            ->send();
    }
}

// Define build path
$buildPath = PUBLIC_FOLDER_ROOT . '/build';
$timestamp = date('Ymd_His');
$buildFolderName = 'build_' . $timestamp;
$buildFullPath = $buildPath . '/' . $buildFolderName;

// Step 1: Create/clear build directory
if (!file_exists($buildPath)) {
    if (!mkdir($buildPath, 0755, true)) {
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage("Failed to create build directory")
            ->send();
    }
}

// Create timestamped build folder
if (!mkdir($buildFullPath, 0755, true)) {
    ApiResponse::create(500, 'server.directory_create_failed')
        ->withMessage("Failed to create timestamped build folder")
        ->send();
}

// Create build directory structure using configured names
$directories = [
    $buildFullPath . '/' . $buildPublicName,
    $buildFullPath . '/' . $buildPublicName . '/style',
    $buildFullPath . '/' . $buildPublicName . '/assets',
    $buildFullPath . '/' . $buildSecureName,
    $buildFullPath . '/' . $buildSecureName . '/src',
    $buildFullPath . '/' . $buildSecureName . '/src/classes',
    $buildFullPath . '/' . $buildSecureName . '/src/functions',
    $buildFullPath . '/' . $buildSecureName . '/templates',
    $buildFullPath . '/' . $buildSecureName . '/templates/pages',
    $buildFullPath . '/' . $buildSecureName . '/translate'
];

foreach ($directories as $dir) {
    if (!mkdir($dir, 0755, true)) {
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
    $dest = $buildFullPath . '/' . $buildPublicName . '/' . $file;
    
    if (file_exists($source)) {
        $content = file_get_contents($source);
        
        // Replace folder name references in copied files if names changed
        if ($buildPublicName !== PUBLIC_FOLDER_NAME || $buildSecureName !== SECURE_FOLDER_NAME) {
            if ($file === 'init.php') {
                // Update SECURE_FOLDER_PATH in init.php
                $content = preg_replace(
                    "/define\('SECURE_FOLDER_PATH',\s*[^)]+\);/",
                    "define('SECURE_FOLDER_PATH', dirname(__DIR__) . '/" . $buildSecureName . "');",
                    $content
                );
                
                // Update PUBLIC_FOLDER_NAME constant
                $content = preg_replace(
                    "/define\('PUBLIC_FOLDER_NAME',\s*'[^']+'\);/",
                    "define('PUBLIC_FOLDER_NAME', '" . $buildPublicName . "');",
                    $content
                );
            }
        }
        
        if (file_put_contents($dest, $content) === false) {
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to copy file: {$file}")
                ->send();
        }
    }
}

// Copy /style/ directory recursively
function copyDirectory($source, $dest) {
    if (!is_dir($source)) return false;
    if (!is_dir($dest) && !mkdir($dest, 0755, true)) return false;
    
    foreach (scandir($source) as $item) {
        if ($item == '.' || $item == '..') continue;
        
        $sourcePath = $source . '/' . $item;
        $destPath = $dest . '/' . $item;
        
        if (is_dir($sourcePath)) {
            if (!copyDirectory($sourcePath, $destPath)) return false;
        } else {
            if (!copy($sourcePath, $destPath)) return false;
        }
    }
    return true;
}

if (!copyDirectory(PUBLIC_FOLDER_ROOT . '/style', $buildFullPath . '/' . $buildPublicName . '/style')) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy /style/ directory")
        ->send();
}

// Copy /assets/ directory recursively
if (!copyDirectory(PUBLIC_FOLDER_ROOT . '/assets', $buildFullPath . '/' . $buildPublicName . '/assets')) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy /assets/ directory")
        ->send();
}

// Step 3: Copy secure folder files (selective)

// Copy routes.php
if (!copy(SECURE_FOLDER_PATH . '/routes.php', $buildFullPath . '/' . $buildSecureName . '/routes.php')) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy routes.php")
        ->send();
}

// Copy and sanitize config.php (remove DB credentials)
$configContent = file_get_contents(SECURE_FOLDER_PATH . '/config.php');
if ($configContent === false) {
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
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to copy class file: {$file}")
            ->send();
    }
}

// Copy String.php function
if (!copy(SECURE_FOLDER_PATH . '/src/functions/String.php', $buildFullPath . '/' . $buildSecureName . '/src/functions/String.php')) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy String.php")
        ->send();
}

// Copy /translate/ directory
if (!copyDirectory(SECURE_FOLDER_PATH . '/translate', $buildFullPath . '/' . $buildSecureName . '/translate')) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy /translate/ directory")
        ->send();
}

// Step 4: Compile menu.php and footer.php using JsonToPhpCompiler
$compiler = new JsonToPhpCompiler();

// Compile menu
$menuJsonPath = SECURE_FOLDER_PATH . '/templates/model/json/menu.json';
if (file_exists($menuJsonPath)) {
    $menuJson = json_decode(file_get_contents($menuJsonPath), true);
    if ($menuJson === null) {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse menu.json")
            ->send();
    }
    
    $menuPhp = $compiler->compileMenuOrFooter($menuJson);
    if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/templates/menu.php', $menuPhp) === false) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled menu.php")
            ->send();
    }
}

// Compile footer
$footerJsonPath = SECURE_FOLDER_PATH . '/templates/model/json/footer.json';
if (file_exists($footerJsonPath)) {
    $footerJson = json_decode(file_get_contents($footerJsonPath), true);
    if ($footerJson === null) {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse footer.json")
            ->send();
    }
    
    $footerPhp = $compiler->compileMenuOrFooter($footerJson);
    if (file_put_contents($buildFullPath . '/' . $buildSecureName . '/templates/footer.php', $footerPhp) === false) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled footer.php")
            ->send();
    }
}

// Step 5: Compile all pages based on ROUTES
$compiledPages = [];

// First compile 404 page (special case)
$page404JsonPath = SECURE_FOLDER_PATH . '/templates/model/json/pages/404.json';
if (file_exists($page404JsonPath)) {
    $page404Json = json_decode(file_get_contents($page404JsonPath), true);
    if ($page404Json === null) {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse 404.json")
            ->send();
    }
    
    $page404Php = $compiler->compilePage($page404Json, '404');
    $page404FilePath = $buildFullPath . '/' . $buildSecureName . '/templates/pages/404.php';
    
    if (file_put_contents($page404FilePath, $page404Php) === false) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write compiled 404.php")
            ->send();
    }
    
    $compiledPages[] = '404';
}

// Then compile regular route pages
foreach (ROUTES as $route) {
    $pageJsonPath = SECURE_FOLDER_PATH . '/templates/model/json/pages/' . $route . '.json';
    
    if (!file_exists($pageJsonPath)) {
        // Skip if page JSON doesn't exist
        continue;
    }
    
    $pageJson = json_decode(file_get_contents($pageJsonPath), true);
    if ($pageJson === null) {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to parse {$route}.json")
            ->send();
    }
    
    // Use route name as title (capitalize first letter)
    $pageTitle = ucfirst(str_replace('-', ' ', $route));
    
    $pagePhp = $compiler->compilePage($pageJson, $route);
    $pageFilePath = $buildFullPath . '/' . $buildSecureName . '/templates/pages/' . $route . '.php';
    
    if (file_put_contents($pageFilePath, $pagePhp) === false) {
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
- Translation files are included for runtime language switching

COMPILED PAGES: %PAGES%

=======================================================
README;

$readme = str_replace('%DATE%', date('Y-m-d H:i:s'), $readme);
$readme = str_replace('%PAGES%', implode(', ', $compiledPages), $readme);

file_put_contents($buildFullPath . '/README.txt', $readme);

// Step 7: Create ZIP archive
$zipFilename = $buildFolderName . '.zip';
$zipPath = $buildPath . '/' . $zipFilename;

// Use ZipArchive to create compressed archive
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to create ZIP archive")
        ->send();
}

// Recursively add files to ZIP
function addDirectoryToZip($zip, $dir, $baseDir = '') {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $dir . '/' . $file;
        $zipPath = $baseDir . '/' . $file;
        
        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipPath);
            addDirectoryToZip($zip, $filePath, $zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
        }
    }
}

// Add all build files to ZIP
addDirectoryToZip($zip, $buildFullPath, basename($buildFullPath));
$zip->close();

// Get ZIP file size
$zipSize = filesize($zipPath);
$zipSizeMB = round($zipSize / 1024 / 1024, 2);

// Calculate original folder size for comparison
function getDirectorySize($dir) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

$originalSize = getDirectorySize($buildFullPath);
$originalSizeMB = round($originalSize / 1024 / 1024, 2);
$compressionRatio = round((1 - ($zipSize / $originalSize)) * 100, 1);

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
        'public_folder_name' => $buildPublicName,
        'secure_folder_name' => $buildSecureName,
        'config_sanitized' => true,
        'menu_compiled' => file_exists($buildFullPath . '/' . $buildSecureName . '/templates/menu.php'),
        'footer_compiled' => file_exists($buildFullPath . '/' . $buildSecureName . '/templates/footer.php'),
        'build_date' => date('Y-m-d H:i:s'),
        'readme_created' => true,
        'download_url' => BASE_URL . '/build/' . $zipFilename
    ])
    ->send();