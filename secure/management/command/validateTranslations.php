<?php
/**
 * validateTranslations - Validates translation coverage
 *
 * @method GET
 * @url /management/validateTranslations
 * @url /management/validateTranslations/{language}
 * @auth required
 * @permission content.read
 *
 * Checks that every key the site needs in every language (qs_site_translation_keys()'s required keys) has a
 * word in each translation file. An optional key (a page's description, the site's name, …) is never missing.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

/**
 * Command function for internal execution via CommandRunner
 *
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments - [0] = language code (optional)
 * @return ApiResponse
 */
function __command_validateTranslations(array $params = [], array $urlParams = []): ApiResponse {
    $targetLang = !empty($urlParams) ? $urlParams[0] : null;

    // Validate language if provided
    if ($targetLang !== null) {
        // Type validation
        if (!is_string($targetLang)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The language parameter must be a string.')
                ->withErrors([
                    ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
                ]);
        }

        // Length validation (no language code, and not "default", is longer)
        if (strlen($targetLang) > 10) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage('Language code must not exceed 10 characters')
                ->withErrors([
                    ['field' => 'language', 'value' => $targetLang, 'max_length' => 10]
                ]);
        }

        // An EXISTING language: one of the project's, or "default" — the
        // mono-language translation file, which the Translation Manager asks
        // for on a monolingual site (refusing it would leave missing_keys
        // empty there and show every key as used).
        if (!qs_project_has_language($targetLang, true)) {
            return qs_language_not_in_project_response($targetLang, 'language', true);
        }
    }

    // Get all required keys: the keys the site needs in every language
    $requiredKeys = qs_translation_keys_in_use(qs_site_translation_keys(PROJECT_PATH), [])['required'];

    // Determine which languages to validate
    $languagesToValidate = $targetLang ? [$targetLang] : qs_project_language_codes();

    $validationResults = [];

    foreach ($languagesToValidate as $lang) {
        $translationFile = PROJECT_PATH . '/translate/' . $lang . '.json';

        if (!file_exists($translationFile)) {
            $validationResults[$lang] = [
                'file_exists' => false,
                'missing_keys' => $requiredKeys,
                'total_missing' => count($requiredKeys)
            ];
            continue;
        }

        $translations = qs_translation_file_tree($translationFile);

        if (!is_array($translations)) {
            $validationResults[$lang] = [
                'file_exists' => true,
                'file_valid' => false,
                'missing_keys' => $requiredKeys,
                'total_missing' => count($requiredKeys)
            ];
            continue;
        }

        // A key is missing where the file gives it no word a page shows (absent, empty, or a group)
        $missingKeys = [];

        foreach ($requiredKeys as $key) {
            if (!qs_translation_tree_has_word($translations, $key)) {
                $missingKeys[] = $key;
            }
        }

        $validationResults[$lang] = [
            'file_exists' => true,
            'file_valid' => true,
            'required_keys' => count($requiredKeys),
            'missing_keys' => $missingKeys,
            'total_missing' => count($missingKeys),
            'coverage_percent' => count($requiredKeys) > 0
                ? round((1 - count($missingKeys) / count($requiredKeys)) * 100, 2)
                : 100
        ];
    }

    // Success
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translation validation complete')
        ->withData([
            'validation_results' => $validationResults,
            'total_required_keys' => count($requiredKeys),
            'languages_validated' => $languagesToValidate
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_validateTranslations([], $urlSegments)->send();
}
