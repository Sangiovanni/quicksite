<?php
/**
 * Clean Builds Command - Deletes all builds older than a specified timestamp
 * 
 * Method: POST
 * Endpoint: /management/cleanBuilds
 * 
 * Parameters:
 * - before: Unix timestamp or ISO 8601 date string - delete builds created before this time
 * - dry_run: (optional) If true, only list what would be deleted without actually deleting
 * 
 * Example: Delete builds older than 30 days
 * {"before": 1702483200} or {"before": "2024-12-13T00:00:00"}
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php';

$params = $trimParametersManagement->params();
$before = $params['before'] ?? null;
$dryRun = $params['dry_run'] ?? false;

// Validate 'before' parameter is provided
if (empty($before)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('before parameter is required (Unix timestamp or ISO 8601 date)')
        ->withErrors([
            ['field' => 'before', 'reason' => 'missing'],
            ['examples' => [
                'unix_timestamp' => time() - (30 * 24 * 60 * 60), // 30 days ago
                'iso_8601' => date('c', time() - (30 * 24 * 60 * 60))
            ]]
        ])
        ->send();
}

// Parse timestamp
$timestamp = null;
if (is_numeric($before)) {
    $timestamp = (int) $before;
} elseif (is_string($before)) {
    $parsed = strtotime($before);
    if ($parsed !== false) {
        $timestamp = $parsed;
    }
}

if ($timestamp === null || $timestamp <= 0) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid timestamp format')
        ->withErrors([
            ['field' => 'before', 'value' => $before],
            ['accepted_formats' => [
                'unix_timestamp' => '1702483200',
                'iso_8601' => '2024-12-13T00:00:00',
                'date_string' => '2024-12-13'
            ]]
        ])
        ->send();
}

// Validate dry_run is boolean if provided
if (isset($params['dry_run']) && !is_bool($dryRun)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('dry_run must be a boolean')
        ->withErrors([
            ['field' => 'dry_run', 'expected' => 'boolean', 'received' => gettype($params['dry_run'])]
        ])
        ->send();
}

$buildPath = PUBLIC_FOLDER_ROOT . '/build';

// Check if build directory exists
if (!is_dir($buildPath)) {
    ApiResponse::create(200, 'operation.success')
        ->withMessage('No builds to clean')
        ->withData([
            'before_timestamp' => $timestamp,
            'before_date' => date('c', $timestamp),
            'builds_found' => 0,
            'builds_deleted' => 0
        ])
        ->send();
}

// Find builds older than timestamp
$items = scandir($buildPath);
$buildsToDelete = [];
$deletedBuilds = [];
$errors = [];

foreach ($items as $item) {
    // Skip non-directories and non-build folders
    if ($item === '.' || $item === '..') continue;
    if (!is_dir($buildPath . '/' . $item)) continue;
    if (!preg_match('/^build_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/', $item, $matches)) continue;
    
    // Parse timestamp from folder name
    $buildTimestamp = mktime(
        (int)$matches[4], // hour
        (int)$matches[5], // minute
        (int)$matches[6], // second
        (int)$matches[2], // month
        (int)$matches[3], // day
        (int)$matches[1]  // year
    );
    
    // Check if manifest exists for more accurate timestamp
    $manifestPath = $buildPath . '/' . $item . '/build_manifest.json';
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if ($manifest && isset($manifest['created_timestamp'])) {
            $buildTimestamp = $manifest['created_timestamp'];
        }
    }
    
    // Check if this build is older than the threshold
    if ($buildTimestamp < $timestamp) {
        $buildFolder = $buildPath . '/' . $item;
        $zipPath = $buildPath . '/' . $item . '.zip';
        
        // Calculate sizes
        $folderSize = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($buildFolder, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $folderSize += $file->getSize();
        }
        
        $zipSize = file_exists($zipPath) ? filesize($zipPath) : 0;
        
        $buildsToDelete[] = [
            'name' => $item,
            'created' => date('c', $buildTimestamp),
            'created_timestamp' => $buildTimestamp,
            'folder_size_mb' => round($folderSize / 1024 / 1024, 2),
            'zip_size_mb' => round($zipSize / 1024 / 1024, 2),
            'has_zip' => file_exists($zipPath)
        ];
    }
}

// If dry run, just return what would be deleted
if ($dryRun) {
    $totalFolderSize = array_sum(array_column($buildsToDelete, 'folder_size_mb'));
    $totalZipSize = array_sum(array_column($buildsToDelete, 'zip_size_mb'));
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Dry run completed - no builds were deleted')
        ->withData([
            'dry_run' => true,
            'before_timestamp' => $timestamp,
            'before_date' => date('c', $timestamp),
            'builds_to_delete' => $buildsToDelete,
            'total_builds' => count($buildsToDelete),
            'total_folder_size_mb' => round($totalFolderSize, 2),
            'total_zip_size_mb' => round($totalZipSize, 2),
            'total_space_to_free_mb' => round($totalFolderSize + $totalZipSize, 2)
        ])
        ->send();
}

// Actually delete the builds
foreach ($buildsToDelete as $build) {
    $buildFolder = $buildPath . '/' . $build['name'];
    $zipPath = $buildPath . '/' . $build['name'] . '.zip';
    
    $deleted = [
        'name' => $build['name'],
        'folder_deleted' => false,
        'zip_deleted' => false,
        'folder_size_mb' => $build['folder_size_mb'],
        'zip_size_mb' => $build['zip_size_mb']
    ];
    
    // Delete folder
    if (is_dir($buildFolder)) {
        if (deleteDirectory($buildFolder)) {
            $deleted['folder_deleted'] = true;
        } else {
            $errors[] = ['build' => $build['name'], 'type' => 'folder', 'reason' => 'Failed to delete'];
        }
    }
    
    // Delete ZIP
    if (file_exists($zipPath)) {
        if (unlink($zipPath)) {
            $deleted['zip_deleted'] = true;
        } else {
            $errors[] = ['build' => $build['name'], 'type' => 'zip', 'reason' => 'Failed to delete'];
        }
    }
    
    $deletedBuilds[] = $deleted;
}

// Calculate freed space
$freedFolderSpace = array_sum(array_map(function($b) {
    return $b['folder_deleted'] ? $b['folder_size_mb'] : 0;
}, $deletedBuilds));

$freedZipSpace = array_sum(array_map(function($b) {
    return $b['zip_deleted'] ? $b['zip_size_mb'] : 0;
}, $deletedBuilds));

$response = [
    'before_timestamp' => $timestamp,
    'before_date' => date('c', $timestamp),
    'builds_processed' => count($deletedBuilds),
    'deleted_builds' => $deletedBuilds,
    'space_freed_mb' => round($freedFolderSpace + $freedZipSpace, 2)
];

if (!empty($errors)) {
    $response['errors'] = $errors;
    ApiResponse::create(207, 'operation.partial_success')
        ->withMessage('Some builds could not be deleted')
        ->withData($response)
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Old builds cleaned successfully')
    ->withData($response)
    ->send();
