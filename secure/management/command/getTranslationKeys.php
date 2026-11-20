<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

/**
 * getTranslationKeys - The translation keys the site uses, by source
 *
 * @method GET
 * @url /management/getTranslationKeys
 * @url /management/getTranslationKeys/{language}
 * @auth required
 * @permission read
 *
 * The keys come from qs_site_translation_keys(), the one answer every reader of a site's keys takes: each
 * required key, and each optional one (a page's description, the site's name, …) where a word file holds it —
 * the language asked's, or any language's when none is asked.
 *
 * If language is provided, also returns translation status for each key:
 * - translated: true if key exists with non-empty value
 * - translated: false if key missing or has empty value
 */

/**
 * Command function for internal execution via CommandRunner
 *
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments - [0] = language code (optional)
 * @return ApiResponse
 */
function __command_getTranslationKeys(array $params = [], array $urlParams = []): ApiResponse {
    $targetLang = !empty($urlParams) ? $urlParams[0] : null;

    // Validate language if provided
    if ($targetLang !== null) {
        if (!is_string($targetLang)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The language parameter must be a string.')
                ->withErrors([['field' => 'language', 'reason' => 'invalid_type']]);
        }

        if (strlen($targetLang) > 10) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage('Language code must not exceed 10 characters');
        }

        // An EXISTING language: one of the project's, or "default" (the
        // mono-language translation file), as validateTranslations and
        // getUnusedTranslationKeys take it.
        if (!qs_project_has_language($targetLang, true)) {
            return qs_language_not_in_project_response($targetLang, 'language', true);
        }
    }

    // The language asked's words, when its file exists and reads (null otherwise: no status below).
    $translations = null;
    if ($targetLang) {
        $translationFile = PROJECT_PATH . '/translate/' . $targetLang . '.json';
        $translations = is_file($translationFile) ? qs_translation_file_tree($translationFile) : null;
    }

    // The words that make an optional key used: the language asked's, else the words every language's pages read.
    $trees = $targetLang ? [$translations ?? []] : array_values(qs_translation_trees_read(PROJECT_PATH));

    $sites = qs_site_translation_keys(PROJECT_PATH);
    $inUse = qs_translation_keys_in_use($sites, $trees);

    // keys_by_source: a page by its route (the function's page:<route>), menu, footer, component:<name> (prefixed:
    // pages and components share a flat namespace), and the site's own sources, site:<name> (a route never holds a
    // colon inside a segment). A page routed menu or footer shares the layout's entry, the two lists joined. A page,
    // the menu, the footer and a component are answered whenever their file exists; a site: source when it names a
    // used key.
    $allKeys = [];
    foreach ($inUse['bySource'] as $source => $keys) {
        $source = (string) $source;
        if (strpos($source, 'site:') === 0 && $keys === [] && $source !== 'site:consent') {
            continue;
        }
        if (strpos($source, 'page:') === 0) {
            $source = substr($source, strlen('page:'));
        }
        $allKeys[$source] = array_values(array_unique(array_merge($allKeys[$source] ?? [], $keys)));
    }
    $flattenedKeys = $inUse['used'];

    // What was read, as before: the page structures, the menu, the footer, the components.
    $scannedFiles = [];
    foreach (scanAllPageJsonFiles() as $pageInfo) {
        if (loadJsonStructure($pageInfo['path']) !== null) {
            $scannedFiles['pages'][] = $pageInfo['route'];
        }
    }
    foreach (['menu', 'footer'] as $layout) {
        if (loadJsonStructure(PROJECT_PATH . '/templates/model/json/' . $layout . '.json') !== null) {
            $scannedFiles[$layout] = true;
        }
    }
    foreach (array_keys($allKeys) as $source) {
        if (strpos($source, 'component:') === 0) {
            $scannedFiles['components'][] = substr($source, strlen('component:'));
        }
    }

    $responseData = [
        'keys_by_source' => $allKeys,
        'all_keys' => $flattenedKeys,
        'total_keys' => count($flattenedKeys),
        'scanned_files' => $scannedFiles
    ];

    // A language asked: each key's status in it
    if ($translations !== null) {
        $keysWithStatus = [];
        $translatedCount = 0;
        $untranslatedCount = 0;

        foreach ($flattenedKeys as $key) {
            $isTranslated = qs_translation_tree_has_word($translations, $key);
            $keysWithStatus[] = [
                'key' => $key,
                'translated' => $isTranslated
            ];
            if ($isTranslated) {
                $translatedCount++;
            } else {
                $untranslatedCount++;
            }
        }

        $responseData['keys_status'] = $keysWithStatus;
        $responseData['language'] = $targetLang;
        $responseData['translated_count'] = $translatedCount;
        $responseData['untranslated_count'] = $untranslatedCount;
        $responseData['coverage_percent'] = count($flattenedKeys) > 0
            ? round(($translatedCount / count($flattenedKeys)) * 100, 2)
            : 100;
    }

    // Success
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translation keys extracted successfully')
        ->withData($responseData);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getTranslationKeys([], $urlSegments)->send();
}
