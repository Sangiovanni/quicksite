<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

// Check if file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Style file not found")
        ->withData(['file' => $styleFile])
        ->send();
}

// Read file content
$content = @file_get_contents($styleFile);
if ($content === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read style file")
        ->send();
}

// Get file stats
$fileStats = stat($styleFile);

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Style file retrieved successfully')
    ->withData([
        'content' => $content,
        'file' => $styleFile,
        'size' => $fileStats['size'],
        'modified' => date('Y-m-d H:i:s', $fileStats['mtime'])
    ])
    ->send();