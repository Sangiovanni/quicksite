<?php
/**
 * Download Build Command - Returns ZIP download URL and file info
 * 
 * Method: GET
 * Endpoint: /management/downloadBuild/{name}
 * 
 * Parameters:
 * - name: Build folder name (e.g., build_20251213_185955)
 * 
 * Returns the direct download URL for the build ZIP file
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Get build name from URL path: /management/downloadBuild/{name}
$urlSegments = $trimParametersManagement->additionalParams();
$buildName = $urlSegments[0] ?? null;

// Validate build name is provided
if (empty($buildName)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Build name is required')
        ->withErrors([
            ['field' => 'name', 'reason' => 'missing', 'usage' => 'GET /management/downloadBuild/{name}']
        ])
        ->send();
}

// Validate build name format
if (!preg_match('/^build_\d{8}_\d{6}$/', $buildName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid build name format')
        ->withErrors([
            ['field' => 'name', 'value' => $buildName, 'expected_format' => 'build_YYYYMMDD_HHMMSS']
        ])
        ->send();
}

$buildPath = PUBLIC_FOLDER_ROOT . '/build';
$zipPath = $buildPath . '/' . $buildName . '.zip';

// Check if ZIP file exists
if (!file_exists($zipPath)) {
    // Check if folder exists (ZIP might not have been created)
    if (is_dir($buildPath . '/' . $buildName)) {
        ApiResponse::create(404, 'build.zip_not_found')
            ->withMessage('Build exists but ZIP file was not found')
            ->withData([
                'build' => $buildName,
                'build_folder_exists' => true,
                'zip_exists' => false,
                'hint' => 'The ZIP file may have been deleted. Re-run the build command to create a new one.'
            ])
            ->send();
    }
    
    ApiResponse::create(404, 'build.not_found')
        ->withMessage('Build not found')
        ->withData([
            'requested_build' => $buildName,
            'build_directory' => $buildPath
        ])
        ->send();
}

// Get file info
$fileSize = filesize($zipPath);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);
$fileMTime = filemtime($zipPath);

// Get manifest info if available
$manifestPath = $buildPath . '/' . $buildName . '/build_manifest.json';
$manifestInfo = null;
if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if ($manifest) {
        $manifestInfo = [
            'public' => $manifest['public'] ?? null,
            'secure' => $manifest['secure'] ?? null,
            'space' => $manifest['space'] ?? null,
            'multilingual' => $manifest['multilingual'] ?? null,
            'languages' => $manifest['languages'] ?? null,
            'pages_count' => $manifest['pages_count'] ?? null
        ];
    }
}

// Build download URL
$downloadUrl = rtrim(BASE_URL, '/') . '/build/' . $buildName . '.zip';

ApiResponse::create(200, 'operation.success')
    ->withMessage('Download URL retrieved successfully')
    ->withData([
        'build' => $buildName,
        'download_url' => $downloadUrl,
        'filename' => $buildName . '.zip',
        'file_size_bytes' => $fileSize,
        'file_size_mb' => $fileSizeMB,
        'file_modified' => date('c', $fileMTime),
        'content_type' => 'application/zip',
        'manifest' => $manifestInfo
    ])
    ->send();
