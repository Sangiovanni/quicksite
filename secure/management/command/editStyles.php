<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$params = $trimParametersManagement->params();

// Support both 'content' (full form) and 'css' (shorthand alias)
$newContent = $params['content'] ?? $params['css'] ?? null;

// Validate required parameter
if ($newContent === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('CSS content is required')
        ->withErrors([['field' => 'content', 'reason' => 'missing', 'hint' => 'Use "content" or "css" parameter']])
        ->send();
}

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
// Using regex to avoid false positives (e.g., scroll-behavior vs behavior:)
$dangerousPatterns = [
    '/javascript\s*:/i' => 'JavaScript protocol',
    '/expression\s*\(/i' => 'CSS expression (IE-specific JS)',
    '/(?<!scroll-)behavior\s*:/i' => 'CSS behavior (IE-specific)',
    '/vbscript\s*:/i' => 'VBScript protocol',
    '/-moz-binding\s*:/i' => 'XBL binding (Firefox-specific)',
    '/@import\s+url\s*\(\s*["\']?\//i' => 'Absolute path import (potential file access)',
    '/data\s*:\s*text\/html/i' => 'Data URI with HTML',
];

foreach ($dangerousPatterns as $pattern => $description) {
    if (preg_match($pattern, $newContent)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Content contains potentially dangerous CSS pattern')
            ->withErrors([
                ['field' => 'content', 'reason' => 'dangerous_pattern', 'pattern' => $description]
            ])
            ->send();
    }
}

$styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

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