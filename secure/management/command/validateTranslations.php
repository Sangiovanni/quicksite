<?php
/**
 * validateTranslations - Validates translation coverage
 * 
 * @method GET
 * @url /management/validateTranslations
 * @url /management/validateTranslations/{language}
 * @auth required
 * @permission read
 * 
 * Checks if all required translation keys exist in translation files.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Extract all textKeys from structures (uses refactored utility function)
 */
function extractAllKeys_validate() {
    $allKeys = [];
    
    // Scan pages
    $pagesDir = SECURE_FOLDER_PATH . '/templates/model/json/pages';
    if (is_dir($pagesDir)) {
        foreach (glob($pagesDir . '/*.json') as $file) {
            $structure = loadJsonStructure($file);
            if (is_array($structure)) {
                foreach ($structure as $node) {
                    extractTextKeys($node, $allKeys, 0, 20);
                }
            }
        }
    }
    
    // Scan menu
    $menuFile = SECURE_FOLDER_PATH . '/templates/model/json/menu.json';
    $menuStructure = loadJsonStructure($menuFile);
    if (is_array($menuStructure)) {
        foreach ($menuStructure as $node) {
            extractTextKeys($node, $allKeys, 0, 20);
        }
    }
    
    // Scan footer
    $footerFile = SECURE_FOLDER_PATH . '/templates/model/json/footer.json';
    $footerStructure = loadJsonStructure($footerFile);
    if (is_array($footerStructure)) {
        foreach ($footerStructure as $node) {
            extractTextKeys($node, $allKeys, 0, 20);
        }
    }
    
    // Scan components
    $componentsDir = SECURE_FOLDER_PATH . '/templates/model/json/components';
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
    
    // Also include page.titles.{route} keys which are used dynamically
    $routesFile = SECURE_FOLDER_PATH . '/routes.php';
    if (file_exists($routesFile)) {
        $routes = include $routesFile;
        if (is_array($routes)) {
            foreach ($routes as $route) {
                $allKeys[] = 'page.titles.' . $route;
            }
        }
    }
    
    return array_unique($allKeys);
}

/**
 * Check if a key exists in translations (supports dot notation)
 */
function keyExistsInTranslations_validate($key, $translations) {
    $keys = explode('.', $key);
    $current = $translations;
    
    foreach ($keys as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    
    return true;
}

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
        
        // Length validation (max 10 chars for locale codes)
        if (strlen($targetLang) > 10) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage('Language code must not exceed 10 characters')
                ->withErrors([
                    ['field' => 'language', 'value' => $targetLang, 'max_length' => 10]
                ]);
        }
        
        // Format validation - supports ISO 639 and BCP 47 locale codes
        if (!RegexPatterns::match('language_code_extended', $targetLang)) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage('Invalid language code format')
                ->withErrors([RegexPatterns::validationError('language_code_extended', 'language', $targetLang)]);
        }
    }

    // Get all required keys
    $requiredKeys = extractAllKeys_validate();

    // Determine which languages to validate
    $languagesToValidate = $targetLang ? [$targetLang] : CONFIG['LANGUAGES_SUPPORTED'];

    $validationResults = [];

    foreach ($languagesToValidate as $lang) {
        $translationFile = SECURE_FOLDER_PATH . '/translate/' . $lang . '.json';
        
        if (!file_exists($translationFile)) {
            $validationResults[$lang] = [
                'file_exists' => false,
                'missing_keys' => $requiredKeys,
                'total_missing' => count($requiredKeys)
            ];
            continue;
        }
        
        $translations = json_decode(@file_get_contents($translationFile), true);
        
        if (!is_array($translations)) {
            $validationResults[$lang] = [
                'file_exists' => true,
                'file_valid' => false,
                'missing_keys' => $requiredKeys,
                'total_missing' => count($requiredKeys)
            ];
            continue;
        }
        
        $missingKeys = [];
        
        foreach ($requiredKeys as $key) {
            if (!keyExistsInTranslations_validate($key, $translations)) {
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