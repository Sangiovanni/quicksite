<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsStyleManagement.php';

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

// Validate file size. F-C13-6: the ceiling is CSS_MAX_BYTES (what the CssParser can
// read back within the memory_limit), shared with every other CSS writer via
// cssWriteAllTargets — NOT the old 2 MB, which was ~2.5x beyond what the parser
// survives, so one oversized editStyles write bricked the 14 CssParser-using commands.
$maxSize = CSS_MAX_BYTES;

if ($contentSize > $maxSize) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Content too large (max ' . (int) round($maxSize / 1024) . ' KB)')
        ->withData([
            'size' => $contentSize,
            'max_size' => $maxSize,
            'size_kb' => round($contentSize / 1024, 1)
        ])
        ->send();
}

// SECURITY: Check for potentially dangerous CSS patterns.
// The patterns run against a normalised copy (comments stripped, `\XX` escapes
// decoded) so the byte-level bypasses `behavior/**​/:` and `b\65 havior` cannot slip
// past (F5). The @import rule now blocks ANY REMOTE import (scheme or `//`) — a
// remote stylesheet is an exfiltration channel AND violates the dependency-free
// policy; relative and same-origin (`/x.css`) imports are still allowed.
$scanContent = qs_css_normalize_for_scan($newContent);
$dangerousPatterns = [
    '/javascript\s*:/i' => 'JavaScript protocol',
    '/expression\s*\(/i' => 'CSS expression (IE-specific JS)',
    '/(?<!scroll-)behavior\s*:/i' => 'CSS behavior (IE-specific)',
    '/vbscript\s*:/i' => 'VBScript protocol',
    '/-moz-binding\s*:/i' => 'XBL binding (Firefox-specific)',
    '/@import\s+(?:url\(\s*)?["\']?\s*(?:[a-z][a-z0-9+.\-]*:|\/\/)/i' => 'Remote @import (external stylesheet — blocked by the dependency-free policy)',
    '/data\s*:\s*text\/html/i' => 'Data URI with HTML',
];

foreach ($dangerousPatterns as $pattern => $description) {
    if (preg_match($pattern, $scanContent)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Content contains potentially dangerous CSS pattern')
            ->withErrors([
                ['field' => 'content', 'reason' => 'dangerous_pattern', 'pattern' => $description]
            ])
            ->send();
    }
}

$styleFile = PUBLIC_CONTENT_PATH . '/style/style.css';
$projectStyleFile = PROJECT_PATH . '/public/style/style.css';

// Check if live file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Style file not found")
        ->withData(['file' => $styleFile])
        ->send();
}

// Ensure project style directory exists (kept in sync with live stylesheet)
$projectStyleDir = dirname($projectStyleFile);
if (!is_dir($projectStyleDir) && !mkdir($projectStyleDir, 0755, true)) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to create project style directory")
        ->send();
}

// Read current content (for backup in response)
$oldContent = @file_get_contents($styleFile);
if ($oldContent === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read current style file")
        ->send();
}

// Write new content to live stylesheet and project stylesheet copy
if (file_put_contents($styleFile, $newContent, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write live style file")
        ->send();
}

if ($projectStyleFile !== $styleFile && file_put_contents($projectStyleFile, $newContent, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write project style file")
        ->send();
}

// Success - include old content for manual rollback if needed
ApiResponse::create(200, 'operation.success')
    ->withMessage('Style file updated successfully')
    ->withData([
        'file' => $styleFile,
        'project_file' => $projectStyleFile,
        'new_size' => strlen($newContent),
        'old_size' => strlen($oldContent),
        'backup_content' => $oldContent, // For rollback
        'modified' => date('Y-m-d H:i:s')
    ])
    ->send();