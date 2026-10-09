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
 * - config/*.json (project settings: route-layout, etc.)
 * - templates/model/json/ (JSON structures only)
 * - snippets/ (the project's own snippets)
 * - translate/*.json
 * - data/*.json
 * - public/assets/, public/style/
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
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php'; // qs_safe_error_message
// The settings an export carries into config.json (security: no arbitrary PHP
// execution) are QS_PROJECT_SETTING_KEYS — the one list importProject takes back.
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php'; // qs_project_language_codes
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache

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

    // CONTAINMENT (confused deputy): the exported project is BOUND to
    // the URL marker (PROJECT_NAME, authorized by the dispatcher — project.data,
    // admin+ — before this runs). A query `name`/`project` that disagrees is
    // refused; it is optional. You cannot export a project you did not
    // target/authorize.
    $bound = qs_bind_marker_project($params, 'exportProject', ['name', 'project']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    // Reject a traversal payload before the export source path is built.
    if (!is_valid_project_name($projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name')
            ->withErrors([['field' => 'name', 'reason' => 'invalid_format']]);
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
            ->withData(['searched_path' => SECURE_FOLDER_NAME . '/projects/' . $projectName]);
    }
    
    // Check ZipArchive is available
    if (!class_exists('ZipArchive')) {
        return ApiResponse::create(500, 'server.missing_extension')
            ->withMessage('ZIP extension not available')
            ->withData(['hint' => 'Install php-zip extension']);
    }
    
    // No pre-export "pull the live public/ into the project folder" step. The
    // project's own public/ IS its live dir, so the export below already carries the current
    // styles and assets. A pull from a shared live copy would also let exporting any
    // project pick up another project's assets.

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
        
        // 3. Export config/*.json (project settings) — but NEVER members.json
        // (privacy): it holds the membership graph + owner id + private
        // invitation notes. Import discards any archived members.json and
        // birth-writes the importer as sole owner, so shipping it would be a pure
        // leak. (The members.json.lock sidecar is not .json — already skipped.)
        $configDir = $projectPath . '/config';
        if (is_dir($configDir)) {
            addJsonFilesOnly($zip, $configDir, $projectName . '/config', $stats, ['members.json']);
        }
        
        // 4. Export templates/model/json/ (JSON structures only - no PHP!)
        $jsonModelPath = $projectPath . '/templates/model/json';
        if (is_dir($jsonModelPath)) {
            addDirectoryToZip($zip, $jsonModelPath, $projectName . '/templates/model/json', $stats);
        }
        
        // 5. Export snippets/ — the project's own snippets, the tier the editor's
        // Save as Snippet writes to by default. Without them an export → import
        // round trip loses them. (A personal snippet belongs to its author, not
        // to the project, and never travels.)
        $snippetsPath = $projectPath . '/snippets';
        if (is_dir($snippetsPath)) {
            addJsonFilesOnly($zip, $snippetsPath, $projectName . '/snippets', $stats);
        }
        
        // 6. Export translate/*.json
        $translatePath = $projectPath . '/translate';
        if (is_dir($translatePath)) {
            addJsonFilesOnly($zip, $translatePath, $projectName . '/translate', $stats);
        }
        
        // 7. Export data/*.json
        $dataPath = $projectPath . '/data';
        if (is_dir($dataPath)) {
            addJsonFilesOnly($zip, $dataPath, $projectName . '/data', $stats);
        }
        
        // 8. Export public/ (assets only, no PHP)
        if ($includePublic) {
            $publicPath = $projectPath . '/public';
            if (is_dir($publicPath)) {
                addPublicAssetsToZip($zip, $publicPath, $projectName . '/public', $stats);
            }
        }
        
        // 9. Add metadata file
        $metadata = createExportMetadata($projectName, $projectPath, $stats);
        $zip->addFromString($projectName . '/export_info.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $stats['files']++;
        
        $zip->close();
    } catch (Throwable $e) {
        // `Throwable`, not `Exception`: an `Error` (TypeError from a malformed
        // routes.php, the max_execution_time timeout, any other engine-level
        // failure) is NOT an Exception. It would escape, so neither close() nor
        // unlink() would run — and the ZipArchive destructor still MATERIALISES
        // the half-built archive at request shutdown: a full copy of the project's
        // data tree left in the service temp dir, owned by the Apache service
        // account, with no product route to remove it. `Throwable` is the only
        // catch that covers both hierarchies.
        $zip->close();
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
        return ApiResponse::create(500, 'server.zip_error')
            ->withMessage('Error creating ZIP archive')
            // PHP's own messages embed absolute paths ("... called in
            // C:\wamp64\...\exportProject.php on line 419"), so the raw message
            // would publish the install layout. Development still sees it; production
            // gets a fixed string and the detail goes to the error log.
            ->withData(['error' => qs_safe_error_message($e, 'exportProject')]);
    }
    
    // Get final ZIP size
    $zipSize = filesize($zipPath);
    
    // If save=true, store in the PROJECT'S OWN exports folder for later download.
    // One installation-wide exports directory would make every archive
    // addressable from ANY authorized marker — downloadExport could stream another
    // project's archive and clearExports could delete it. Per-project storage
    // removes the shared namespace instead of filtering it, so the containment is
    // structural.
    if ($save) {
        $exportDir = qs_ensure_project_exports_dir($projectName);
        if ($exportDir === null) {
            unlink($zipPath);
            return ApiResponse::create(500, 'server.move_failed')
                ->withMessage('Failed to create the export directory');
        }

        // The storage quota: a saved archive is kept in the project, so it is charged
        // to the project's owner, less the oldest archive the five-export limit drops
        // for it. A refused archive is not kept. (A streamed export lives in the
        // system's temporary folder for the download only, and is not charged.)
        if (qs_quota_storage_limited()) {
            $quotaCallerId = (string)(getCurrentUser()['id'] ?? '');
            $freed = 0;
            foreach (exportsBeyondLimit($exportDir, $projectName, 1) as $oldExport) {
                $freed += (int) @filesize($oldExport);
            }
            $quotaBreach = qs_quota_check_storage(qs_quota_storage_owner($projectName, $quotaCallerId), (int) $zipSize,
                $quotaCallerId, ['project' => $projectName, 'kind' => 'export', 'freed' => $freed]);
            if ($quotaBreach !== null) {
                unlink($zipPath);
                return ApiResponse::create(507, 'quota.storage_exceeded')
                    ->withMessage($quotaBreach['message'])
                    ->withData($quotaBreach['data']);
            }
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
        qs_invalidate_space_cache($projectName);

        return ApiResponse::create(200, 'resource.exported')
            ->withMessage("Project '$projectName' exported and saved")
            ->withData([
                'project' => $projectName,
                'filename' => $zipFileName,
                'path' => SECURE_FOLDER_NAME . '/projects/' . $projectName . '/exports/' . $zipFileName,
                'size' => formatExportBytes($zipSize),
                'size_bytes' => $zipSize,
                'files_count' => $stats['files'],
                'directories_count' => $stats['directories'],
                'original_size' => formatExportBytes($stats['total_size']),
                // downloadExport is project-scoped, so the URL carries the marker.
                'download_url' => '/management/p/' . rawurlencode($projectName) . '/downloadExport?file=' . urlencode($zipFileName),
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
 * Export config.php as sanitized config.json.
 *
 * The project's config.php exists: the dispatcher loads a project-scoped command's
 * project strictly, and refuses one without it before this command runs.
 *
 * The archive names the languages the project is served in — its list, or, when
 * its config.php has none, its default language (qs_project_language_codes()) —
 * because an import refuses an archive that names none.
 */
function exportConfigAsJson(ZipArchive $zip, string $projectPath, string $projectName, array &$stats): void {
    $config = require $projectPath . '/config.php';
    $config = is_array($config) ? $config : [];

    // Filter to allowed keys only (security)
    $configJson = [];
    foreach (QS_PROJECT_SETTING_KEYS as $key) {
        if (isset($config[$key])) {
            $configJson[$key] = $config[$key];
        }
    }
    $configJson['LANGUAGES_SUPPORTED'] = qs_project_language_codes($config);

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
    
    // A project's routes.php is data on disk, not a trusted constant: a truncated
    // or hand-edited file can `return` a scalar (or nothing at all). Four of the
    // six sites that require it already gate on is_array(); these two did not.
    if (!file_exists($routesFile)) {
        $routes = ['home' => []];
    } else {
        $routes = require $routesFile;
        if (!is_array($routes)) {
            $routes = [];
        }
    }

    $content = json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $zip->addFromString($projectName . '/routes.json', $content);
    $stats['files']++;
    $stats['total_size'] += strlen($content);
}

/**
 * Add only JSON files from a directory (no PHP). $excludeNames = basenames to
 * skip at THIS level (members.json is excluded from the config export).
 */
function addJsonFilesOnly(ZipArchive $zip, string $dir, string $zipBase, array &$stats, array $excludeNames = []): void {
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (in_array($item, $excludeNames, true)) {
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
    // Allowed folders in public/.
    //
    // 'build' is GONE from this list, and its removal is the point rather than an
    // omission: builds no longer live under public/ at all, and while they did,
    // every export carried every build. Exports are dramatically smaller now and
    // carry only what an export is for — the project's own content.
    $allowedFolders = ['assets', 'style'];
    
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
            
            // Skip editor/system backup files (e.g. favicon.png~)
            if (str_ends_with($item, '~')) {
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
        if (!is_array($config)) {
            $config = [];
        }
    }

    // Count routes recursively. countRoutesRecursive() is typed `array`, so a
    // scalar here would raise a TypeError. The guard removes that carrier; the
    // Throwable catch above covers every other one.
    $routesCount = 0;
    $routesFile = $projectPath . '/routes.php';
    if (file_exists($routesFile)) {
        $routes = require $routesFile;
        if (is_array($routes)) {
            $routesCount = countRoutesRecursive($routes);
        }
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
    foreach (exportsBeyondLimit($exportDir, $projectName, 0) as $file) {
        unlink($file);
    }
}

/**
 * The saved archives past the last 5 once $adding more are saved: the oldest, by
 * modification time. The quota check asks with 1 before an archive is kept.
 *
 * @return string[] paths, oldest first
 */
function exportsBeyondLimit(string $exportDir, string $projectName, int $adding): array {
    $files = glob($exportDir . '/' . $projectName . '_export_*.zip') ?: [];
    $excess = count($files) + $adding - 5;
    if ($excess <= 0) {
        return [];
    }
    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
    return array_slice($files, 0, $excess);
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
