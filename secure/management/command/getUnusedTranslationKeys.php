<?php
/**
 * getUnusedTranslationKeys - Find translation keys not used in any structure
 * 
 * Identifies orphaned/unused translation keys that exist in translation files
 * but are not referenced by any page, menu, footer, or component structure.
 * 
 * @method GET
 * @url /management/getUnusedTranslationKeys
 * @url /management/getUnusedTranslationKeys/{lang}
 * @auth required
 * @permission read
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';

/**
 * Extract all textKeys from all structures
 */
function extractAllUsedKeys(): array {
    $allKeys = [];
    
    // Scan all pages recursively (supports folder structure)
    $pageFiles = scanAllPageJsonFiles();
    foreach ($pageFiles as $pageInfo) {
        $structure = loadJsonStructure($pageInfo['path']);
        if (is_array($structure)) {
            foreach ($structure as $node) {
                extractTextKeys($node, $allKeys, 0, 20);
            }
        }
    }
    
    // Scan menu
    $menuFile = PROJECT_PATH . '/templates/model/json/menu.json';
    $menuStructure = loadJsonStructure($menuFile);
    if (is_array($menuStructure)) {
        foreach ($menuStructure as $node) {
            extractTextKeys($node, $allKeys, 0, 20);
        }
    }
    
    // Scan footer
    $footerFile = PROJECT_PATH . '/templates/model/json/footer.json';
    $footerStructure = loadJsonStructure($footerFile);
    if (is_array($footerStructure)) {
        foreach ($footerStructure as $node) {
            extractTextKeys($node, $allKeys, 0, 20);
        }
    }
    
    // Scan components
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';
    if (is_dir($componentsDir)) {
        foreach (glob($componentsDir . '/*.json') as $file) {
            $structure = loadJsonStructure($file);
            if (is_array($structure)) {
                foreach ($structure as $node) {
                    extractTextKeys($node, $allKeys, 0, 20);
                }
            }
        }
    }
    
    return array_unique($allKeys);
}

/**
 * Flatten nested translation array to dot notation keys
 * e.g., {"menu": {"home": "Home"}} → ["menu.home"]
 */
function flattenTranslationKeys(array $translations, string $prefix = ''): array {
    $keys = [];
    
    foreach ($translations as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        
        if (is_array($value)) {
            // Recurse into nested structure
            $keys = array_merge($keys, flattenTranslationKeys($value, $fullKey));
        } else {
            // Leaf node - this is an actual translation key
            $keys[] = $fullKey;
        }
    }
    
    return $keys;
}

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

    // Get all keys used in structures
    $usedKeys = extractAllUsedKeys();

    // The page keys, read dynamically (qs_page_translation_keys()): every route's title, a nested
    // route's included, and below, per language, each description that language holds.
    $routesFile = PROJECT_PATH . '/routes.php';
    $routes = file_exists($routesFile) ? include $routesFile : [];
    $routes = is_array($routes) ? $routes : [];
    $usedKeys = array_merge($usedKeys, qs_page_translation_keys($routes, [])['titles']);

    $usedKeys = array_unique($usedKeys);

    // Determine which languages to check
    $languagesToCheck = $targetLang ? [$targetLang] : qs_project_language_codes();

    $results = [];
    $totalUnused = 0;

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
        
        $translations = json_decode(@file_get_contents($translationFile), true);
        
        if (!is_array($translations)) {
            $results[$lang] = [
                'file_exists' => true,
                'file_valid' => false,
                'unused_keys' => [],
                'total_unused' => 0
            ];
            continue;
        }
        
        // Flatten translation keys to dot notation
        $translationKeys = flattenTranslationKeys($translations);
        
        // Find keys that exist in translations but not in structures
        // A page's description is used where this language holds it.
        $langUsedKeys = array_values(array_unique(array_merge($usedKeys, qs_page_translation_keys($routes, $translations)['descriptions'])));
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
