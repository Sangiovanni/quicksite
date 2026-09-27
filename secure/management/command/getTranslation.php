<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';

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

    // Length validation (no language code, and not "default", is longer)
    if (strlen($language) > 10) {
        return ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Language code must not exceed 10 characters')
            ->withErrors([
                ['field' => 'language', 'value' => $language, 'max_length' => 10]
            ]);
    }

    // An EXISTING language: one of the project's, or "default" (the
    // mono-language translation file).
    if (!qs_project_has_language($language, true)) {
        return qs_language_not_in_project_response($language, 'language', true);
    }
    $isDefault = ($language === 'default');

    $translations_file = PROJECT_PATH . '/translate/' . $language . '.json';

    // Declared-but-missing tolerance: a project language whose <lang>.json does
    // not exist yet (addLang succeeded, the file write was deferred or skipped)
    // answers 200 with empty translations, so the Translation Manager panel can
    // render "0% translated, all keys unset" instead of an opaque 404.
    //
    // The 'default' pseudo-language with no file stays 404 (a mono-language
    // project without translation seeds).
    //
    // The Translator class itself still uses `default.json` as the
    // mono-language fallback; this tolerance only affects the API surface
    // for the admin panel.
    if (!file_exists($translations_file)) {
        if (!$isDefault) {
            return ApiResponse::create(200, 'operation.success')
                ->withMessage('Translation file not yet created for declared language; returning empty.')
                ->withData([
                    'language' => $language,
                    'translations' => [],
                    'file' => $translations_file,
                    'file_exists' => false,
                ]);
        }

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