<?php
/**
 * getUnusedTranslationKeys - Find translation keys nothing on the site names
 *
 * Identifies orphaned/unused translation keys: keys a translation file holds that the site does not use
 * (qs_site_translation_keys(), the one answer every reader of a site's keys takes: its pages, menu, footer,
 * components, consent layer, page titles and descriptions, translated attributes, call arguments, Media's
 * pictures, an API's sentences, the site's name and the registries' words).
 *
 * @method GET
 * @url /management/getUnusedTranslationKeys
 * @url /management/getUnusedTranslationKeys/{lang}
 * @auth required
 * @permission content.read
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

/**
 * Command function for internal execution via CommandRunner
 *
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [lang?]
 * @return ApiResponse
 */
function __command_getUnusedTranslationKeys(array $params = [], array $urlParams = []): ApiResponse {
    $targetLang = !empty($urlParams) ? $urlParams[0] : null;

    // Validate language if provided
    if ($targetLang !== null) {
        if (!is_string($targetLang)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The language parameter must be a string.')
                ->withErrors([
                    ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
                ]);
        }

        if (strlen($targetLang) > 10) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage('Language code must not exceed 10 characters')
                ->withErrors([
                    ['field' => 'language', 'value' => $targetLang, 'max_length' => 10]
                ]);
        }

        // An EXISTING language: one of the project's, or "default" (see
        // validateTranslations.php for why the Translation Manager sends it).
        if (!qs_project_has_language($targetLang, true)) {
            return qs_language_not_in_project_response($targetLang, 'language', true);
        }
    }

    // The keys the site uses: each required one, and each optional one where a language checked holds it.
    $sites = qs_site_translation_keys(PROJECT_PATH);

    // Determine which languages to check
    $languagesToCheck = $targetLang ? [$targetLang] : qs_project_language_codes();

    $results = [];
    $totalUnused = 0;
    $trees = [];

    foreach ($languagesToCheck as $lang) {
        $translationFile = PROJECT_PATH . '/translate/' . $lang . '.json';

        if (!file_exists($translationFile)) {
            $results[$lang] = [
                'file_exists' => false,
                'unused_keys' => [],
                'total_unused' => 0
            ];
            continue;
        }

        $translations = qs_translation_file_tree($translationFile);

        if (!is_array($translations)) {
            $results[$lang] = [
                'file_exists' => true,
                'file_valid' => false,
                'unused_keys' => [],
                'total_unused' => 0
            ];
            continue;
        }
        $trees[] = $translations;

        // The keys this file holds that the site does not use in this language
        $translationKeys = qs_translation_tree_keys($translations);
        $langUsedKeys = qs_translation_keys_in_use($sites, [$translations])['used'];
        $unusedKeys = array_values(array_diff($translationKeys, $langUsedKeys));
        sort($unusedKeys);

        $results[$lang] = [
            'file_exists' => true,
            'file_valid' => true,
            'total_translation_keys' => count($translationKeys),
            'total_used_keys' => count($langUsedKeys),
            'unused_keys' => $unusedKeys,
            'total_unused' => count($unusedKeys),
            'usage_percent' => count($translationKeys) > 0
                ? round((1 - count($unusedKeys) / count($translationKeys)) * 100, 2)
                : 100
        ];

        $totalUnused += count($unusedKeys);
    }

    // Every key the site uses in the languages checked
    $usedKeys = qs_translation_keys_in_use($sites, $trees)['used'];

    // Determine overall status
    $hasUnused = $totalUnused > 0;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($hasUnused
            ? "Found {$totalUnused} unused translation key(s)"
            : 'All translation keys are in use')
        ->withData([
            'results' => $results,
            'total_unused_across_languages' => $totalUnused,
            'used_keys_in_structures' => $usedKeys,
            'total_used_keys' => count($usedKeys),
            'recommendation' => $hasUnused
                ? 'Consider removing unused keys with deleteTranslationKeys command'
                : 'Translation files are clean'
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getUnusedTranslationKeys([], $urlSegments)->send();
}
