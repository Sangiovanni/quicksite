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

// Validate language code format (basic check)
if (!preg_match('/^[a-z]{2,3}$/', $language)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->withErrors([['field' => 'language', 'value' => $language, 'expected' => '2-3 lowercase letters']])
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