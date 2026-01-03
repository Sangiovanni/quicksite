<?php
/**
 * exportProject Command
 * 
 * Exports a project as a downloadable ZIP file with secure JSON-only format.
 * PHP files are NOT exported - they will be rebuilt on import from JSON structures.
 * 
 * Export format v2.0:
 * - config.json (sanitized from config.php)
 * - routes.json (from routes.php)
 * - templates/model/json/ (JSON structures only)
 * - translate/*.json
 * - data/*.json
 * - public/assets/, public/style/, public/build/
 * 
 * @method GET
 * @route /management/exportProject
 * @auth required (admin permission)
 * 
 * @param string $name Project name (optional, defaults to active project)
 * @param bool $include_public Include public files (optional, default: true)
 * @param bool $save Save to exports folder instead of streaming (optional, default: false)
 * 
 * @return ApiResponse|void Streams ZIP file or returns download URL if save=true
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Allowed keys in config.json export (security: no arbitrary PHP execution)
const EXPORT_ALLOWED_CONFIG_KEYS = [
    'SITE_NAME',
    'LANGUAGES_SUPPORTED',
    'DEFAULT_LANGUAGE',
    'MULTILINGUAL_SUPPORT',
    'TITLE',
    'FAVICON'
];

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body/query parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_exportProject(array $params = [], array $urlParams = []): ApiResponse {
    // Merge query parameters for GET requests
    $params = array_merge($_GET, $params);
    
    // Get project name - default to active project if not specified
    $projectName = trim($params['name'] ?? $params['project'] ?? '');
    
    // If no project specified, use active project
    if (empty($projectName)) {
        $projectName = PROJECT_NAME ?? 'quicksite';
    }
    
    // Options
    $includePublic = filter_var($params['include_public'] ?? true, FILTER_VALIDATE_BOOLEAN);
    // Default: stream directly. Use save=true to store in exports folder
    $save = filter_var($params['save'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Check project exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Project '$projectName' not found")
            ->withData(['searched_path' => 'secure/projects/' . $projectName]);
    }
    
    // Check ZipArchive is available
    if (!class_exists('ZipArchive')) {
        return ApiResponse::create(500, 'server.missing_extension')
            ->withMessage('ZIP extension not available')
            ->withData(['hint' => 'Install php-zip extension']);
    }
    
    // Create temp directory for export
    $tempDir = sys_get_temp_dir();
    $zipFileName = $projectName . '_export_' . date('Ymd_His') . '.zip';
    $zipPath = $tempDir . '/' . $zipFileName;
    
    // Create ZIP archive
    $zip = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    
    if ($result !== true) {
        return ApiResponse::create(500, 'server.zip_create_failed')
            ->withMessage('Failed to create ZIP archive')
            ->withData(['error_code' => $result]);
    }
    
    // Add project files to ZIP (secure JSON-only format)
    $stats = ['files' => 0, 'directories' => 0, 'total_size' => 0];
    
    try {
        // 1. Export config.php as config.json (sanitized)
        exportConfigAsJson($zip, $projectPath, $projectName, $stats);
        
        // 2. Export routes.php as routes.json
        exportRoutesAsJson($zip, $projectPath, $projectName, $stats);
        
        // 3. Export templates/model/json/ (JSON structures only - no PHP!)
        $jsonModelPath = $projectPath . '/templates/model/json';
        if (is_dir($jsonModelPath)) {
            addDirectoryToZip($zip, $jsonModelPath, $projectName . '/templates/model/json', $stats);
        }
        
        // 4. Export translate/*.json
        $translatePath = $projectPath . '/translate';
        if (is_dir($translatePath)) {
            addJsonFilesOnly($zip, $translatePath, $projectName . '/translate', $stats);
        }
        
        // 5. Export data/*.json
        $dataPath = $projectPath . '/data';
        if (is_dir($dataPath)) {
            addJsonFilesOnly($zip, $dataPath, $projectName . '/data', $stats);
        }
        
        // 6. Export public/ (assets only, no PHP)
        if ($includePublic) {
            $publicPath = $projectPath . '/public';
            if (is_dir($publicPath)) {
                addPublicAssetsToZip($zip, $publicPath, $projectName . '/public', $stats);
            }
        }
        
        // 7. Add metadata file
        $metadata = createExportMetadata($projectName, $projectPath, $stats);
        $zip->addFromString($projectName . '/export_info.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $stats['files']++;
        
        $zip->close();
    } catch (Exception $e) {
        $zip->close();
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        return ApiResponse::create(500, 'server.zip_error')
            ->withMessage('Error creating ZIP archive')
            ->withData(['error' => $e->getMessage()]);
    }
    
    // Get final ZIP size
    $zipSize = filesize($zipPath);
    
    // If save=true, store in exports folder for later download
    if ($save) {
        $exportDir = SECURE_FOLDER_PATH . '/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        
        $finalPath = $exportDir . '/' . $zipFileName;
        
        // Move ZIP to exports folder
        if (!rename($zipPath, $finalPath)) {
            // Try copy+delete if rename fails
            if (!copy($zipPath, $finalPath)) {
                unlink($zipPath);
                return ApiResponse::create(500, 'server.move_failed')
                    ->withMessage('Failed to save export file');
            }
            unlink($zipPath);
        }
        
        // Clean up old exports (keep last 5 per project)
        cleanupOldExports($exportDir, $projectName);
        
        return ApiResponse::create(200, 'resource.exported')
            ->withMessage("Project '$projectName' exported and saved")
            ->withData([
                'project' => $projectName,
                'filename' => $zipFileName,
                'path' => 'secure/exports/' . $zipFileName,
                'size' => formatExportBytes($zipSize),
                'size_bytes' => $zipSize,
                'files_count' => $stats['files'],
                'directories_count' => $stats['directories'],
                'original_size' => formatExportBytes($stats['total_size']),
                'download_url' => '/management/downloadExport?file=' . urlencode($zipFileName),
                'expires' => date('Y-m-d H:i:s', time() + 86400), // 24 hours
                'format' => 'v2.0-secure',
                'note' => 'Secure format: PHP files excluded, will be rebuilt on import'
            ]);
    }
    
    // Default: Stream ZIP directly to browser (no file saved)
    streamZipDownload($zipPath, $zipFileName);
    // streamZipDownload exits, so we never reach here
}

/**
 * Export config.php as sanitized config.json
 */
function exportConfigAsJson(ZipArchive $zip, string $projectPath, string $projectName, array &$stats): void {
    $configFile = $projectPath . '/config.php';
    
    if (!file_exists($configFile)) {
        // Create minimal default config
        $configJson = [
            'SITE_NAME' => $projectName,
            'LANGUAGES_SUPPORTED' => ['en'],
            'DEFAULT_LANGUAGE' => 'en',
            'MULTILINGUAL_SUPPORT' => false
        ];
    } else {
        $config = require $configFile;
        
        // Filter to allowed keys only (security)
        $configJson = [];
        foreach (EXPORT_ALLOWED_CONFIG_KEYS as $key) {
            if (isset($config[$key])) {
                $configJson[$key] = $config[$key];
            }
        }
    }
    
    $content = json_encode($configJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $zip->addFromString($projectName . '/config.json', $content);
    $stats['files']++;
    $stats['total_size'] += strlen($content);
}

/**
 * Export routes.php as routes.json
 */
function exportRoutesAsJson(ZipArchive $zip, string $projectPath, string $projectName, array &$stats): void {
    $routesFile = $projectPath . '/routes.php';
    
    if (!file_exists($routesFile)) {
        $routes = ['home' => []];
    } else {
        $routes = require $routesFile;
    }
    
    $content = json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $zip->addFromString($projectName . '/routes.json', $content);
    $stats['files']++;
    $stats['total_size'] += strlen($content);
}

/**
 * Add only JSON files from a directory (no PHP)
 */
function addJsonFilesOnly(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;
        
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addJsonFilesOnly($zip, $path, $zipPath, $stats);
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            // Only JSON files
            $zip->addFile($path, $zipPath);
            $stats['files']++;
            $stats['total_size'] += filesize($path);
        }
        // Skip non-JSON files (especially .php)
    }
}

/**
 * Add public assets to ZIP (images, CSS, JS - no PHP)
 */
function addPublicAssetsToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    // Allowed folders in public/
    $allowedFolders = ['assets', 'style', 'build'];
    
    foreach ($allowedFolders as $folder) {
        $folderPath = $dir . '/' . $folder;
        if (is_dir($folderPath)) {
            addSafeFilesToZip($zip, $folderPath, $zipBase . '/' . $folder, $stats);
        }
    }
}

/**
 * Add files to ZIP excluding dangerous extensions
 */
function addSafeFilesToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    // Dangerous extensions that could execute code
    $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'htaccess'];
    
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;
        
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addSafeFilesToZip($zip, $path, $zipPath, $stats);
        } else {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            
            // Skip dangerous file types
            if (in_array($ext, $dangerousExtensions)) {
                continue;
            }
            
            $zip->addFile($path, $zipPath);
            $stats['files']++;
            $stats['total_size'] += filesize($path);
        }
    }
}

/**
 * Recursively add directory contents to ZIP (used for templates/model/json)
 * 
 * @param ZipArchive $zip ZIP archive instance
 * @param string $dir Directory to add
 * @param string $zipBase Base path in ZIP
 * @param array &$stats Stats counter
 */
function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;
        
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addDirectoryToZip($zip, $path, $zipPath, $stats);
        } else {
            $zip->addFile($path, $zipPath);
            $stats['files']++;
            $stats['total_size'] += filesize($path);
        }
    }
}

/**
 * Create export metadata
 */
function createExportMetadata(string $projectName, string $projectPath, array $stats): array {
    // Load config if exists
    $config = [];
    $configFile = $projectPath . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
    // Count routes recursively
    $routesCount = 0;
    $routesFile = $projectPath . '/routes.php';
    if (file_exists($routesFile)) {
        $routes = require $routesFile;
        $routesCount = countRoutesRecursive($routes);
    }
    
    // Count languages
    $languages = [];
    $translateDir = $projectPath . '/translate';
    if (is_dir($translateDir)) {
        foreach (scandir($translateDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json' && $file !== 'default.json') {
                $languages[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
    }
    
    return [
        'export_info' => [
            'version' => '2.0',
            'format' => 'secure-json-only',
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => 'QuickSite Engine',
            'php_version' => PHP_VERSION,
            'note' => 'PHP files excluded for security. They will be rebuilt on import from JSON structures.'
        ],
        'project' => [
            'name' => $projectName,
            'site_name' => $config['SITE_NAME'] ?? $projectName,
            'routes_count' => $routesCount,
            'languages' => $languages,
            'multilingual' => $config['MULTILINGUAL_SUPPORT'] ?? false
        ],
        'statistics' => [
            'files' => $stats['files'],
            'directories' => $stats['directories'],
            'total_size_bytes' => $stats['total_size']
        ]
    ];
}

/**
 * Count routes recursively in nested structure
 */
function countRoutesRecursive(array $routes): int {
    $count = 0;
    foreach ($routes as $name => $children) {
        $count++; // Count this route
        if (is_array($children) && !empty($children)) {
            $count += countRoutesRecursive($children); // Count children
        }
    }
    return $count;
}

/**
 * Stream ZIP file as download
 */
function streamZipDownload(string $zipPath, string $filename): void {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');
    
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

/**
 * Clean up old exports
 */
function cleanupOldExports(string $exportDir, string $projectName): void {
    $pattern = $exportDir . '/' . $projectName . '_export_*.zip';
    $files = glob($pattern);
    
    if ($files === false || count($files) <= 5) {
        return;
    }
    
    // Sort by modification time
    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
    
    // Delete oldest files, keep last 5
    $toDelete = count($files) - 5;
    for ($i = 0; $i < $toDelete; $i++) {
        unlink($files[$i]);
    }
}

/**
 * Format bytes to human readable
 */
function formatExportBytes(int $bytes): string {
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
    __command_exportProject($trimParams->params(), $trimParams->additionalParams())->send();
}
