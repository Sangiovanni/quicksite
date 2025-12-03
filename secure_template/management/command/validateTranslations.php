<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Get URL segment for language (optional)
$urlSegments = $trimParametersManagement->additionalParams();
$targetLang = !empty($urlSegments) ? $urlSegments[0] : null;

// Validate language if provided
if ($targetLang !== null && !preg_match('/^[a-z]{2,3}$/', $targetLang)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->send();
}

/**
 * Extract all textKeys from structures (same as getTranslationKeys)
 */
function extractAllKeys() {
    $allKeys = [];
    
    // Helper function
    $extractTextKeys = function($node, &$keys = []) use (&$extractTextKeys) {
        if (!is_array($node)) return $keys;
        
        if (isset($node['textKey']) && strpos($node['textKey'], '__RAW__') !== 0) {
            $keys[] = $node['textKey'];
        }
        
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $extractTextKeys($child, $keys);
            }
        }
        
        if (isset($node['data']['label']) && is_string($node['data']['label'])) {
            $keys[] = $node['data']['label'];
        }
        
        return $keys;
    };
    
    // Scan pages
    $pagesDir = SECURE_FOLDER_PATH . '/templates/model/json/pages';
    if (is_dir($pagesDir)) {
        foreach (glob($pagesDir . '/*.json') as $file) {
            $structure = json_decode(@file_get_contents($file), true);
            if (is_array($structure)) {
                foreach ($structure as $node) {
                    $extractTextKeys($node, $allKeys);
                }
            }
        }
    }
    
    // Scan menu
    $menuFile = SECURE_FOLDER_PATH . '/templates/model/json/menu.json';
    $menuStructure = json_decode(@file_get_contents($menuFile), true);
    if (is_array($menuStructure)) {
        foreach ($menuStructure as $node) {
            $extractTextKeys($node, $allKeys);
        }
    }
    
    // Scan footer
    $footerFile = SECURE_FOLDER_PATH . '/templates/model/json/footer.json';
    $footerStructure = json_decode(@file_get_contents($footerFile), true);
    if (is_array($footerStructure)) {
        foreach ($footerStructure as $node) {
            $extractTextKeys($node, $allKeys);
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