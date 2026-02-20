<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * getTranslationKeys - Extracts all translation keys from site structures
 * 
 * @method GET
 * @url /management/getTranslationKeys
 * @url /management/getTranslationKeys/{language}
 * @auth required
 * @permission read
 * 
 * If language is provided, also returns translation status for each key:
 * - translated: true if key exists with non-empty value
 * - translated: false if key missing or has empty value
 */

/**
 * Check if a key exists in translations AND has a non-empty value (dot notation)
 */
function keyHasValue_getKeys(string $key, array $translations): bool {
    $segments = explode('.', $key);
    $current = $translations;
    foreach ($segments as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    // Empty string = untranslated
    return !(is_string($current) && $current === '');
}

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
        
        if (!RegexPatterns::match('language_code_extended', $targetLang)) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage('Invalid language code format')
                ->withErrors([RegexPatterns::validationError('language_code_extended', 'language', $targetLang)]);
        }
    }
    
    // Load translations if language specified
    $translations = null;
    if ($targetLang) {
        $translationFile = PROJECT_PATH . '/translate/' . $targetLang . '.json';
        if (file_exists($translationFile)) {
            $translations = json_decode(@file_get_contents($translationFile), true);
            if (!is_array($translations)) {
                $translations = null;
            }
        }
    }
    
    $allKeys = [];
    $scannedFiles = [];

    // 1. Scan all page structures (supports folder structure)
    $pageFiles = scanAllPageJsonFiles();
    foreach ($pageFiles as $pageInfo) {
        $structure = loadJsonStructure($pageInfo['path']);
        
        if ($structure !== null) {
            $pageKeys = [];
            if (is_array($structure)) {
                foreach ($structure as $node) {
                    extractTextKeys($node, $pageKeys, 0, 20); // Max 20 levels depth
                }
            }
            
            $allKeys[$pageInfo['route']] = array_unique($pageKeys);
            $scannedFiles['pages'][] = $pageInfo['route'];
        }
    }

    // 2. Scan menu structure
    $menuFile = PROJECT_PATH . '/templates/model/json/menu.json';
    $menuStructure = loadJsonStructure($menuFile);

    if ($menuStructure !== null) {
        $menuKeys = [];
        if (is_array($menuStructure)) {
            foreach ($menuStructure as $node) {
                extractTextKeys($node, $menuKeys, 0, 20); // Max 20 levels depth
            }
        }
        $allKeys['menu'] = array_unique($menuKeys);
        $scannedFiles['menu'] = true;
    }

    // 3. Scan footer structure
    $footerFile = PROJECT_PATH . '/templates/model/json/footer.json';
    $footerStructure = loadJsonStructure($footerFile);

    if ($footerStructure !== null) {
        $footerKeys = [];
        if (is_array($footerStructure)) {
            foreach ($footerStructure as $node) {
                extractTextKeys($node, $footerKeys, 0, 20); // Max 20 levels depth
            }
        }
        $allKeys['footer'] = array_unique($footerKeys);
        $scannedFiles['footer'] = true;
    }

    // 4. Flatten all keys for easy comparison
    $flattenedKeys = [];
    foreach ($allKeys as $source => $keys) {
        foreach ($keys as $key) {
            if (!in_array($key, $flattenedKeys)) {
                $flattenedKeys[] = $key;
            }
        }
    }

    sort($flattenedKeys);
    
    // 5. Build response data
    $responseData = [
        'keys_by_source' => $allKeys,
        'all_keys' => $flattenedKeys,
        'total_keys' => count($flattenedKeys),
        'scanned_files' => $scannedFiles
    ];
    
    // 6. If language provided, add translation status for each key
    if ($translations !== null) {
        $keysWithStatus = [];
        $translatedCount = 0;
        $untranslatedCount = 0;
        
        foreach ($flattenedKeys as $key) {
            $isTranslated = keyHasValue_getKeys($key, $translations);
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