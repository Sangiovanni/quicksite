<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * getTranslation - Retrieves translations for a specific language
 * 
 * @method GET
 * @url /management/getTranslation/{lang}
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [lang]
 * @return ApiResponse
 */
function __command_getTranslation(array $params = [], array $urlParams = []): ApiResponse {
    // Validate language parameter
    if (empty($urlParams) || !isset($urlParams[0])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("Language code missing from URL")
            ->withErrors([['field' => 'language', 'reason' => 'missing', 'usage' => 'GET /management/getTranslation/{lang}']]);
    }

    $language = $urlParams[0];

    // Type validation - language must be string
    if (!is_string($language)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The language parameter must be a string.')
            ->withErrors([
                ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
            ]);
    }

    // SECURITY: Check for path traversal attempts FIRST (before length/format)
    if (strpos($language, '..') !== false || 
        strpos($language, '/') !== false || 
        strpos($language, '\\') !== false ||
        strpos($language, "\0") !== false) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Language code contains invalid characters')
            ->withErrors([
                ['field' => 'language', 'reason' => 'path_traversal_attempt']
            ]);
    }

    // Length validation for language code (max 10 chars for locale codes)
    if (strlen($language) > 10) {
        return ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Language code must not exceed 10 characters')
            ->withErrors([
                ['field' => 'language', 'value' => $language, 'max_length' => 10]
            ]);
    }

    // SECURITY: Validate language code format
    // Supports: en, fr, eng (ISO 639), en-US, zh-Hans (BCP 47 locale codes)
    // Also supports "default" for mono-language mode
    // Pattern: 2-3 lowercase letters, optionally followed by dash and 2-4 alphanumeric chars
    $isDefault = ($language === 'default');
    if (!$isDefault && !RegexPatterns::match('language_code_extended', $language)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid language code format')
            ->withErrors([RegexPatterns::validationError('language_code_extended', 'language', $language)]);
    }

    $translations_file = PROJECT_PATH . '/translate/' . $language . '.json';

    // Check if file exists
    if (!file_exists($translations_file)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Translation file not found for language: {$language}")
            ->withData([
                'requested_language' => $language,
                'file' => $translations_file
            ]);
    }

    // Read translation file
    $content = @file_get_contents($translations_file);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to read translation file");
    }

    // Decode JSON
    $translations = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON in translation file: " . json_last_error_msg());
    }

    // Success
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translation retrieved successfully')
        ->withData([
            'language' => $language,
            'translations' => $translations,
            'file' => $translations_file
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getTranslation([], $urlSegments)->send();
}