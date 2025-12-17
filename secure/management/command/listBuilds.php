<?php
/**
 * List Builds Command - Returns all production builds with metadata
 * 
 * Method: GET
 * Endpoint: /management/listBuilds
 * 
 * Returns list of all build folders with:
 * - Build name and creation date
 * - Public/secure/space settings used
 * - Multilingual mode and languages
 * - ZIP availability and sizes
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

$buildPath = PUBLIC_FOLDER_ROOT . '/build';

// Check if build directory exists
if (!is_dir($buildPath)) {
    ApiResponse::create(200, 'operation.success')
        ->withMessage('No builds found')
        ->withData([
            'builds' => [],
            'total' => 0,
            'build_directory' => $buildPath
        ])
        ->send();
}

// Scan for build folders (build_YYYYMMDD_HHMMSS pattern)
$items = scandir($buildPath);
$builds = [];

foreach ($items as $item) {
    // Skip non-directories and non-build folders
    if ($item === '.' || $item === '..') continue;
    if (!is_dir($buildPath . '/' . $item)) continue;
    if (!RegexPatterns::match('build_name', $item)) continue;
    
    $buildFolder = $buildPath . '/' . $item;
    $manifestPath = $buildFolder . '/build_manifest.json';
    $zipPath = $buildPath . '/' . $item . '.zip';
    
    // Build info object
    $buildInfo = [
        'name' => $item,
        'created' => null,
        'created_timestamp' => null,
        'public' => null,
        'secure' => null,
        'space' => null,
        'multilingual' => null,
        'languages' => null,
        'pages_count' => null,
        'has_zip' => file_exists($zipPath),
        'has_manifest' => file_exists($manifestPath),
        'folder_size_mb' => null,
        'zip_size_mb' => null
    ];
    
    // If manifest exists, read it for accurate data
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if ($manifest) {
            $buildInfo['created'] = $manifest['created'] ?? null;
            $buildInfo['created_timestamp'] = $manifest['created_timestamp'] ?? null;
            $buildInfo['public'] = $manifest['public'] ?? null;
            $buildInfo['secure'] = $manifest['secure'] ?? null;
            $buildInfo['space'] = $manifest['space'] ?? null;
            $buildInfo['multilingual'] = $manifest['multilingual'] ?? null;
            $buildInfo['languages'] = $manifest['languages'] ?? null;
            $buildInfo['pages_count'] = $manifest['pages_count'] ?? null;
        }
    } else {
        // Fallback: parse timestamp from folder name
        if (RegexPatterns::matchWithCapture('build_name_parse', $item, $matches)) {
            $dateStr = "{$matches[1]}-{$matches[2]}-{$matches[3]}T{$matches[4]}:{$matches[5]}:{$matches[6]}";
            $buildInfo['created'] = $dateStr;
            $buildInfo['created_timestamp'] = strtotime($dateStr);
        }
        
        // Try to parse config.php from build to get settings (legacy builds)
        $configPath = null;
        // Find secure folder in build (could be any name)
        foreach (scandir($buildFolder) as $subItem) {
            if ($subItem === '.' || $subItem === '..') continue;
            if (is_dir($buildFolder . '/' . $subItem)) {
                $possibleConfig = $buildFolder . '/' . $subItem . '/config.php';
                if (file_exists($possibleConfig)) {
                    $configPath = $possibleConfig;
                    $buildInfo['secure'] = $subItem;
                    break;
                }
            }
        }
    }
    
    // Calculate folder size
    $folderSize = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildFolder, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $folderSize += $file->getSize();
    }
    $buildInfo['folder_size_mb'] = round($folderSize / 1024 / 1024, 2);
    
    // Get ZIP size if exists
    if ($buildInfo['has_zip']) {
        $buildInfo['zip_size_mb'] = round(filesize($zipPath) / 1024 / 1024, 2);
    }
    
    $builds[] = $buildInfo;
}

// Sort by creation timestamp (newest first)
usort($builds, function($a, $b) {
    $tsA = $a['created_timestamp'] ?? 0;
    $tsB = $b['created_timestamp'] ?? 0;
    return $tsB - $tsA;
});

// Calculate total size
$totalFolderSize = array_sum(array_column($builds, 'folder_size_mb'));
$totalZipSize = array_sum(array_filter(array_column($builds, 'zip_size_mb')));

ApiResponse::create(200, 'operation.success')
    ->withMessage('Build list retrieved successfully')
    ->withData([
        'builds' => $builds,
        'total' => count($builds),
        'total_folder_size_mb' => round($totalFolderSize, 2),
        'total_zip_size_mb' => round($totalZipSize, 2),
        'build_directory' => $buildPath
    ])
    ->send();
