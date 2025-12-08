<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Get URL segment for language (optional)
$urlSegments = $trimParametersManagement->additionalParams();
$targetLang = !empty($urlSegments) ? $urlSegments[0] : null;

// Validate language if provided
if ($targetLang !== null) {
    // Type validation
    if (!is_string($targetLang)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The language parameter must be a string.')
            ->withErrors([
                ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
            ])
            ->send();
    }
    
    // Length validation (max 10 chars for locale codes)
    if (strlen($targetLang) > 10) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Language code must not exceed 10 characters')
            ->withErrors([
                ['field' => 'language', 'value' => $targetLang, 'max_length' => 10]
            ])
            ->send();
    }
    
    // Format validation - supports ISO 639 and BCP 47 locale codes
    if (!preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $targetLang)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid language code format')
            ->withErrors([
                ['field' => 'language', 'value' => $targetLang, 'expected' => 'ISO 639 or BCP 47 format (e.g., en, fr, en-US, zh-Hans)']
            ])
            ->send();
    }
}

/**
 * Extract all textKeys from structures (uses refactored utility function)
 */
function extractAllKeys() {
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
    
    return array_unique($allKeys);
}

/**
 * Check if a key exists in translations (supports dot notation)
 */
function keyExistsInTranslations($key, $translations) {
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

// Get all required keys
$requiredKeys = extractAllKeys();

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
        if (!keyExistsInTranslations($key, $translations)) {
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
ApiResponse::create(200, 'operation.success')
    ->withMessage('Translation validation complete')
    ->withData([
        'validation_results' => $validationResults,
        'total_required_keys' => count($requiredKeys),
        'languages_validated' => $languagesToValidate
    ])
    ->send();