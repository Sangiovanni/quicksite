<?php
/**
 * exportProject Command
 * 
 * Exports a project as a downloadable ZIP file.
 * Can be called via API or internally from admin panel.
 * 
 * @method GET
 * @route /management/exportProject
 * @auth required (admin permission)
 * 
 * @param string $name Project name (required)
 * @param bool $include_public Include public files (optional, default: true)
 * @param bool $download Stream as download (optional, default: false)
 * 
 * @return ApiResponse|void Returns data or streams ZIP file
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

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
    
    // Validate project name
    $projectName = trim($params['name'] ?? '');
    
    if (empty($projectName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Options
    $includePublic = filter_var($params['include_public'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $download = filter_var($params['download'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
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
    
    // Add project files to ZIP
    $stats = ['files' => 0, 'directories' => 0, 'total_size' => 0];
    
    try {
        // Add project files
        addDirectoryToZip($zip, $projectPath, $projectName, $stats, $includePublic ? null : ['public']);
        
        // Add metadata file
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
    
    // If download requested, stream the file
    if ($download) {
        streamZipDownload($zipPath, $zipFileName);
        // This function exits, so we never reach here
    }
    
    // Store ZIP for later download (for API response)
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
    
    // Clean up old exports (keep last 5)
    cleanupOldExports($exportDir, $projectName);
    
    return ApiResponse::create(200, 'resource.exported')
        ->withMessage("Project '$projectName' exported successfully")
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
            'expires' => date('Y-m-d H:i:s', time() + 86400) // 24 hours
        ]);
}

/**
 * Recursively add directory contents to ZIP
 * 
 * @param ZipArchive $zip ZIP archive instance
 * @param string $dir Directory to add
 * @param string $zipBase Base path in ZIP
 * @param array &$stats Stats counter
 * @param array|null $exclude Folders to exclude
 */
function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats, ?array $exclude = null): void {
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        // Check exclusions
        if ($exclude !== null && in_array($item, $exclude)) {
            continue;
        }
        
        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;
        
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addDirectoryToZip($zip, $path, $zipPath, $stats, null);
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
    
    // Count routes
    $routesCount = 0;
    $routesFile = $projectPath . '/routes.php';
    if (file_exists($routesFile)) {
        $routes = require $routesFile;
        $routesCount = count($routes);
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
            'version' => '1.0',
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => 'QuickSite Engine',
            'php_version' => PHP_VERSION
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