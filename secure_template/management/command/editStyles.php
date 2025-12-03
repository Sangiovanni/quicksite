<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$params = $trimParametersManagement->params();

// Validate required parameter
if (!isset($params['content'])) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([['field' => 'content', 'reason' => 'missing']])
        ->send();
}

$newContent = $params['content'];

// Validate content is a string
if (!is_string($newContent)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Content must be a string")
        ->send();
}

// Validate file size (max 2MB)
$contentSize = strlen($newContent);
$maxSize = 2 * 1024 * 1024; // 2MB

if ($contentSize > $maxSize) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Content too large (max 2MB)")
        ->withData([
            'size' => $contentSize,
            'max_size' => $maxSize,
            'size_mb' => round($contentSize / 1024 / 1024, 2)
        ])
        ->send();
}

$styleFile = PUBLIC_FOLDER_PATH . '/style/style.scss';

// Check if file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Style file not found")
        ->withData(['file' => $styleFile])
        ->send();
}

// Read current content (for backup in response)
$oldContent = @file_get_contents($styleFile);
if ($oldContent === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read current style file")
        ->send();
}

// Write new content
if (file_put_contents($styleFile, $newContent, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write style file")
        ->send();
}

// Success - include old content for manual rollback if needed
ApiResponse::create(200, 'operation.success')
    ->withMessage('Style file updated successfully')
    ->withData([
        'file' => $styleFile,
        'new_size' => strlen($newContent),
        'old_size' => strlen($oldContent),
        'backup_content' => $oldContent, // For rollback
        'modified' => date('Y-m-d H:i:s')
    ])
    ->send();