<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Get URL segment: /management/getTranslation/{lang}
$urlSegments = $trimParametersManagement->additionalParams();

// Validate language parameter
if (empty($urlSegments) || !isset($urlSegments[0])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage("Language code missing from URL")
        ->withErrors([['field' => 'language', 'reason' => 'missing', 'usage' => 'GET /management/getTranslation/{lang}']])
        ->send();
}

$language = $urlSegments[0];

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

// Length validation for language code (max 10 chars for locale codes)
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

$translations_file = SECURE_FOLDER_PATH . '/translate/' . $language . '.json';

// Check if file exists
if (!file_exists($translations_file)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Translation file not found for language: {$language}")
        ->withData([
            'requested_language' => $language,
            'file' => $translations_file
        ])
        ->send();
}

// Read translation file
$content = @file_get_contents($translations_file);
if ($content === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read translation file")
        ->send();
}

// Decode JSON
$translations = json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Invalid JSON in translation file: " . json_last_error_msg())
        ->send();
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Translation retrieved successfully')
    ->withData([
        'language' => $language,
        'translations' => $translations,
        'file' => $translations_file
    ])
    ->send();