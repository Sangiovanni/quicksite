<?php
/**
 * Delete Build Command - Removes a build folder and its ZIP archive
 * 
 * Method: POST
 * Endpoint: /management/deleteBuild
 * 
 * Parameters:
 * - name: Build folder name (e.g., build_20251213_185955)
 * 
 * Deletes both the build folder and associated ZIP file
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php';

$params = $trimParametersManagement->params();
$buildName = $params['name'] ?? null;

// Validate build name is provided
if (empty($buildName)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Build name is required')
        ->withErrors([
            ['field' => 'name', 'reason' => 'missing']
        ])
        ->send();
}

// Type validation
if (!is_string($buildName)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('Build name must be a string')
        ->withErrors([
            ['field' => 'name', 'expected' => 'string', 'received' => gettype($buildName)]
        ])
        ->send();
}

// Validate build name format (strict pattern to prevent path traversal)
if (!preg_match('/^build_\d{8}_\d{6}$/', $buildName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid build name format')
        ->withErrors([
            ['field' => 'name', 'value' => $buildName, 'expected_format' => 'build_YYYYMMDD_HHMMSS']
        ])
        ->send();
}

$buildPath = PUBLIC_FOLDER_ROOT . '/build';
$buildFolder = $buildPath . '/' . $buildName;
$zipPath = $buildPath . '/' . $buildName . '.zip';

// Check if build exists (either folder or ZIP)
$folderExists = is_dir($buildFolder);
$zipExists = file_exists($zipPath);

if (!$folderExists && !$zipExists) {
    ApiResponse::create(404, 'build.not_found')
        ->withMessage('Build not found')
        ->withData([
            'requested_build' => $buildName,
            'build_directory' => $buildPath
        ])
        ->send();
}

$deletedItems = [];
$errors = [];

// Delete folder if exists
if ($folderExists) {
    // Get folder size before deletion
    $folderSize = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildFolder, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $folderSize += $file->getSize();
    }
    
    if (deleteDirectory($buildFolder)) {
        $deletedItems[] = [
            'type' => 'folder',
            'name' => $buildName,
            'size_mb' => round($folderSize / 1024 / 1024, 2)
        ];
    } else {
        $errors[] = [
            'type' => 'folder',
            'name' => $buildName,
            'reason' => 'Failed to delete folder'
        ];
    }
}

// Delete ZIP if exists
if ($zipExists) {
    $zipSize = filesize($zipPath);
    
    if (unlink($zipPath)) {
        $deletedItems[] = [
            'type' => 'zip',
            'name' => $buildName . '.zip',
            'size_mb' => round($zipSize / 1024 / 1024, 2)
        ];
    } else {
        $errors[] = [
            'type' => 'zip',
            'name' => $buildName . '.zip',
            'reason' => 'Failed to delete ZIP file'
        ];
    }
}

// Check if there were any errors
if (!empty($errors)) {
    if (empty($deletedItems)) {
        // Complete failure
        ApiResponse::create(500, 'server.file_delete_failed')
            ->withMessage('Failed to delete build')
            ->withErrors($errors)
            ->send();
    } else {
        // Partial success
        ApiResponse::create(207, 'operation.partial_success')
            ->withMessage('Build partially deleted')
            ->withData([
                'deleted' => $deletedItems,
                'errors' => $errors
            ])
            ->send();
    }
}

// Calculate total freed space
$totalFreedMB = array_sum(array_column($deletedItems, 'size_mb'));

ApiResponse::create(200, 'operation.success')
    ->withMessage('Build deleted successfully')
    ->withData([
        'deleted_build' => $buildName,
        'deleted_items' => $deletedItems,
        'space_freed_mb' => round($totalFreedMB, 2)
    ])
    ->send();
