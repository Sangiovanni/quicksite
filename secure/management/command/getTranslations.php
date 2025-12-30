<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * getTranslations - Retrieves all translation files
 * 
 * This command returns translations for all available languages in the system.
 * 
 * @method GET
 * @url /management/getTranslations
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getTranslations(array $params = [], array $urlParams = []): ApiResponse {
    $translateFolder = PROJECT_PATH . '/translate/';

    // Check if translate folder exists
    if (!is_dir($translateFolder)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('Translation folder not found')
            ->withData(['path' => $translateFolder]);
    }

    // Get all JSON files in translate folder
    $files = glob($translateFolder . '*.json');

    if (empty($files)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('No translation files found');
    }

    $translations = [];
    $languages = [];
    $errors = [];

    // Check multilingual status using the constant
    $multilingualEnabled = defined('MULTILINGUAL_SUPPORT') ? MULTILINGUAL_SUPPORT : false;

    foreach ($files as $file) {
        $lang = pathinfo($file, PATHINFO_FILENAME);
        
        // In multilingual mode, skip default.json (it's a template)
        // In mono-language mode, ONLY include default.json
        if ($multilingualEnabled && $lang === 'default') {
            continue;
        }
        if (!$multilingualEnabled && $lang !== 'default') {
            continue;
        }
        
        $content = @file_get_contents($file);
        
        if ($content === false) {
            $errors[] = [
                'language' => $lang,
                'reason' => 'Could not read file'
            ];
            continue;
        }
        
        $decoded = json_decode($content, true);
        
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = [
                'language' => $lang,
                'reason' => 'Invalid JSON: ' . json_last_error_msg()
            ];
            continue;
        }
        
        $translations[$lang] = $decoded;
        $languages[] = $lang;
    }

    // Check if we have at least one valid translation
    if (empty($translations)) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Failed to load any translation files')
            ->withErrors($errors);
    }

    $responseData = [
        'translations' => $translations,
        'languages' => $languages,
        'multilingual_enabled' => $multilingualEnabled,
        'mode' => $multilingualEnabled ? 'multilingual' : 'mono-language',
        'count' => count($translations),
        'default_language' => $languages[0] ?? 'en'
    ];

    // Add errors if any files failed to load
    if (!empty($errors)) {
        $responseData['warnings'] = $errors;
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translations retrieved successfully')
        ->withData($responseData);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getTranslations()->send();
}
