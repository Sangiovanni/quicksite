<?php
/**
 * getBuild - Returns detailed information for a specific build
 * 
 * @method GET
 * @url /management/getBuild/{name}
 * @auth required
 * @permission read
 * 
 * URL Parameters:
 * - name: Build folder name (e.g., build_20251213_185955)
 * 
 * Returns full manifest data plus file listings and download URL
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments - [0] = build name
 * @return ApiResponse
 */
function __command_getBuild(array $params = [], array $urlParams = []): ApiResponse {
    $buildName = $urlParams[0] ?? null;

    // Validate build name is provided
    if (empty($buildName)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Build name is required')
            ->withErrors([
                ['field' => 'name', 'reason' => 'missing', 'usage' => 'GET /management/getBuild/{name}']
            ]);
    }

    // Validate build name format
    if (!RegexPatterns::match('build_name', $buildName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid build name format')
            ->withErrors([RegexPatterns::validationError('build_name', 'name', $buildName)]);
    }

    $buildPath = PUBLIC_FOLDER_ROOT . '/build';
    $buildFolder = $buildPath . '/' . $buildName;

    // Check if build folder exists
    if (!is_dir($buildFolder)) {
        return ApiResponse::create(404, 'build.not_found')
            ->withMessage('Build not found')
            ->withData([
                'requested_build' => $buildName,
                'build_directory' => $buildPath
            ]);
    }

    $manifestPath = $buildFolder . '/build_manifest.json';
    $zipPath = $buildPath . '/' . $buildName . '.zip';

    // Build response data
    $buildData = [
        'name' => $buildName,
        'path' => $buildFolder,
        'has_manifest' => file_exists($manifestPath),
        'has_zip' => file_exists($zipPath)
    ];

    // Load manifest if available
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if ($manifest) {
            $buildData = array_merge($buildData, $manifest);
        }
    } else {
        // Fallback for legacy builds without manifest
        if (RegexPatterns::matchWithCapture('build_name_parse', $buildName, $matches)) {
            $dateStr = "{$matches[1]}-{$matches[2]}-{$matches[3]}T{$matches[4]}:{$matches[5]}:{$matches[6]}";
            $buildData['created'] = $dateStr;
            $buildData['created_timestamp'] = strtotime($dateStr);
        }
    }

    // Calculate folder size
    $folderSize = 0;
    $fileCount = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildFolder, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $folderSize += $file->getSize();
        $fileCount++;
    }
    $buildData['folder_size_mb'] = round($folderSize / 1024 / 1024, 2);
    $buildData['file_count'] = $fileCount;

    // Get ZIP info if exists
    if (file_exists($zipPath)) {
        $buildData['zip_path'] = $zipPath;
        $buildData['zip_size_mb'] = round(filesize($zipPath) / 1024 / 1024, 2);
        $buildData['download_url'] = BASE_URL . '/build/' . $buildName . '.zip';
        
        // Calculate compression ratio
        if ($folderSize > 0) {
            $buildData['compression_ratio'] = round((1 - (filesize($zipPath) / $folderSize)) * 100, 1) . '%';
        }
    }

    // List top-level contents (public and secure folders)
    $contents = [];
    foreach (scandir($buildFolder) as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $itemPath = $buildFolder . '/' . $item;
        $itemInfo = [
            'name' => $item,
            'type' => is_dir($itemPath) ? 'directory' : 'file'
        ];
        
        if (is_file($itemPath)) {
            $itemInfo['size_bytes'] = filesize($itemPath);
        }
        
        $contents[] = $itemInfo;
    }
    $buildData['contents'] = $contents;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Build details retrieved successfully')
        ->withData($buildData);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getBuild([], $urlSegments)->send();
}
