<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

$params = $trimParametersManagement->params();

if (!isset($params['language']) || !isset($params['translations'])) {
    $missing = [];
    if (!isset($params['language'])) $missing[] = 'language';
    if (!isset($params['translations'])) $missing[] = 'translations';
    
    ApiResponse::create(400, 'validation.required')
        ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
        ->send();
}

$language = $params['language'];
$translations = $params['translations'];

// Type validation - language must be string
if (!is_string($language)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The language parameter must be a string.')
        ->withErrors([
            ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// SECURITY: Check for path traversal attempts FIRST (before length/format)
if (strpos($language, '..') !== false || 
    strpos($language, '/') !== false || 
    strpos($language, '\\') !== false ||
    strpos($language, "\0") !== false) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Language code contains invalid characters')
        ->withErrors([
            ['field' => 'language', 'reason' => 'path_traversal_attempt']
        ])
        ->send();
}

// Length validation for language code (max 10 chars for locale codes like "zh-Hans-CN")
if (strlen($language) > 10) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Language code must not exceed 10 characters')
        ->withErrors([
            ['field' => 'language', 'value' => $language, 'max_length' => 10]
        ])
        ->send();
}

// SECURITY: Validate language code format
// Supports: en, fr, eng (ISO 639), en-US, zh-Hans (BCP 47 locale codes)
// Pattern: 2-3 lowercase letters, optionally followed by dash and 2-4 alphanumeric chars
if (!preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $language)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid language code format')
        ->withErrors([
            ['field' => 'language', 'value' => $language, 'expected' => 'ISO 639 or BCP 47 format (e.g., en, fr, en-US, zh-Hans)']
        ])
        ->send();
}

// Type validation - translations must be array/object
if (!is_array($translations)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The translations parameter must be an object/array.')
        ->withErrors([
            ['field' => 'translations', 'reason' => 'invalid_type', 'expected' => 'object/array']
        ])
        ->send();
}

// SECURITY: Check translation structure depth (prevent deeply nested structures)
if (!validateNestedDepth($translations, 0, 20)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Translation structure too deeply nested (max 20 levels)')
        ->withErrors([
            ['field' => 'translations', 'reason' => 'exceeds max depth of 20']
        ])
        ->send();
}

// SECURITY: Check translation size (prevent huge payloads)
$translationSize = strlen(json_encode($translations));
$maxSize = 5 * 1024 * 1024; // 5MB for translations

if ($translationSize > $maxSize) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Translation data too large (max 5MB)')
        ->withData([
            'size' => $translationSize,
            'max_size' => $maxSize,
            'size_mb' => round($translationSize / 1024 / 1024, 2)
        ])
        ->send();
}

$translations_file = SECURE_FOLDER_PATH . '/translate/' . $language . '.json';

// Encode to JSON
$json_content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json_content === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to encode translations to JSON")
        ->send();
}

// Write to file
if (file_put_contents($translations_file, $json_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write translation file")
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Translations updated successfully')
    ->withData([
        'language' => $language,
        'file' => $translations_file
    ])
    ->send();