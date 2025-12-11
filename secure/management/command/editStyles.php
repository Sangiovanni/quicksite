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

// Type validation - content must be string
if (!is_string($newContent)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The content parameter must be a string.')
        ->withErrors([
            ['field' => 'content', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// Length validation - cannot be empty
$contentSize = strlen($newContent);

if ($contentSize === 0) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Content cannot be empty')
        ->withErrors([
            ['field' => 'content', 'reason' => 'empty']
        ])
        ->send();
}

// Validate file size (max 2MB)
$maxSize = 2 * 1024 * 1024; // 2MB

if ($contentSize > $maxSize) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage("Content too large (max 2MB)")
        ->withData([
            'size' => $contentSize,
            'max_size' => $maxSize,
            'size_mb' => round($contentSize / 1024 / 1024, 2)
        ])
        ->send();
}

// SECURITY: Check for potentially dangerous CSS patterns
// Note: This is basic protection. SCSS is compiled server-side, so we focus on obvious attacks
$dangerousPatterns = [
    'javascript:' => 'JavaScript protocol',
    'expression(' => 'CSS expression (IE-specific JS)',
    'behavior:' => 'CSS behavior (IE-specific)',
    'vbscript:' => 'VBScript protocol',
    '-moz-binding:' => 'XBL binding (Firefox-specific)',
    '@import url("/' => 'Absolute path import (potential file access)',
    '@import url(\'/' => 'Absolute path import (potential file access)',
    'data:text/html' => 'Data URI with HTML',
];

foreach ($dangerousPatterns as $pattern => $description) {
    if (stripos($newContent, $pattern) !== false) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Content contains potentially dangerous CSS pattern')
            ->withErrors([
                ['field' => 'content', 'reason' => 'dangerous_pattern', 'pattern' => $description]
            ])
            ->send();
    }
}

$styleFile = PUBLIC_FOLDER_ROOT . '/style/style.scss';

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