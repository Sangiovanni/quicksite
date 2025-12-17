<?php
/**
 * analyzeTranslations - Complete translation health check
 * 
 * Performs comprehensive analysis of translations:
 * 1. Missing translations (keys in structures but not in translation files)
 * 2. Unused translations (keys in translation files but not in structures)
 * 3. Coverage statistics per language
 * 
 * @param string language (optional via URL) - Analyze specific language, or all if omitted
 * @return array Complete analysis report
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// Get URL segment for language (optional)
$urlSegments = $trimParametersManagement->additionalParams();
$targetLang = !empty($urlSegments) ? $urlSegments[0] : null;

// Validate language if provided
if ($targetLang !== null) {
    if (!is_string($targetLang)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The language parameter must be a string.')
            ->withErrors([['field' => 'language', 'reason' => 'invalid_type']])
            ->send();
    }
    
    if (strlen($targetLang) > 10) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Language code must not exceed 10 characters')
            ->send();
    }
    
    if (!RegexPatterns::match('language_code_extended', $targetLang)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid language code format')
            ->withErrors([RegexPatterns::validationError('language_code_extended', 'language', $targetLang)])
            ->send();
    }
}

/**
 * Extract all textKeys from all structures
 */
function extractRequiredKeys(): array {
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
    
    // Add page.titles.{route} keys (used dynamically)
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
 * Flatten nested translation array to dot notation keys
 */
function flattenKeys(array $arr, string $prefix = ''): array {
    $keys = [];
    foreach ($arr as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }
    return $keys;
}

/**
 * Check if a key exists in translations (dot notation)
 */
function keyExists(string $key, array $translations): bool {
    $segments = explode('.', $key);
    $current = $translations;
    foreach ($segments as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    return true;
}

// Get all required keys from structures
$requiredKeys = extractRequiredKeys();
sort($requiredKeys);

// Determine which languages to analyze
$languagesToAnalyze = $targetLang ? [$targetLang] : CONFIG['LANGUAGES_SUPPORTED'];

$analysis = [];
$summary = [
    'total_required_keys' => count($requiredKeys),
    'total_missing_across_languages' => 0,
    'total_unused_across_languages' => 0,
    'languages_analyzed' => count($languagesToAnalyze),
    'health_status' => 'healthy' // Will be updated
];

foreach ($languagesToAnalyze as $lang) {
    $translationFile = SECURE_FOLDER_PATH . '/translate/' . $lang . '.json';
    
    if (!file_exists($translationFile)) {
        $analysis[$lang] = [
            'status' => 'error',
            'error' => 'file_not_found',
            'missing_keys' => $requiredKeys,
            'unused_keys' => [],
            'total_missing' => count($requiredKeys),
            'total_unused' => 0
        ];
        $summary['total_missing_across_languages'] += count($requiredKeys);
        $summary['health_status'] = 'critical';
        continue;
    }
    
    $translations = json_decode(@file_get_contents($translationFile), true);
    
    if (!is_array($translations)) {
        $analysis[$lang] = [
            'status' => 'error',
            'error' => 'invalid_json',
            'missing_keys' => $requiredKeys,
            'unused_keys' => [],
            'total_missing' => count($requiredKeys),
            'total_unused' => 0
        ];
        $summary['total_missing_across_languages'] += count($requiredKeys);
        $summary['health_status'] = 'critical';
        continue;
    }
    
    // Find missing keys (required but not in translations)
    $missingKeys = [];
    foreach ($requiredKeys as $key) {
        if (!keyExists($key, $translations)) {
            $missingKeys[] = $key;
        }
    }
    
    // Find unused keys (in translations but not required)
    $translationKeys = flattenKeys($translations);
    $unusedKeys = array_values(array_diff($translationKeys, $requiredKeys));
    
    sort($missingKeys);
    sort($unusedKeys);
    
    // Calculate stats
    $totalTranslationKeys = count($translationKeys);
    $totalMissing = count($missingKeys);
    $totalUnused = count($unusedKeys);
    
    $coverage = count($requiredKeys) > 0 
        ? round((1 - $totalMissing / count($requiredKeys)) * 100, 2) 
        : 100;
    
    $efficiency = $totalTranslationKeys > 0
        ? round((1 - $totalUnused / $totalTranslationKeys) * 100, 2)
        : 100;
    
    // Determine status
    $status = 'healthy';
    if ($totalMissing > 0 && $totalUnused > 0) {
        $status = 'needs_attention';
    } elseif ($totalMissing > 0) {
        $status = 'incomplete';
    } elseif ($totalUnused > 0) {
        $status = 'has_unused';
    }
    
    $analysis[$lang] = [
        'status' => $status,
        'total_translation_keys' => $totalTranslationKeys,
        'total_required_keys' => count($requiredKeys),
        'missing_keys' => $missingKeys,
        'total_missing' => $totalMissing,
        'unused_keys' => $unusedKeys,
        'total_unused' => $totalUnused,
        'coverage_percent' => $coverage,
        'efficiency_percent' => $efficiency
    ];
    
    $summary['total_missing_across_languages'] += $totalMissing;
    $summary['total_unused_across_languages'] += $totalUnused;
    
    // Update overall health
    if ($status === 'needs_attention' || $status === 'incomplete') {
        $summary['health_status'] = ($summary['health_status'] === 'critical') 
            ? 'critical' 
            : 'needs_attention';
    } elseif ($status === 'has_unused' && $summary['health_status'] === 'healthy') {
        $summary['health_status'] = 'has_unused';
    }
}

// Build recommendations
$recommendations = [];
if ($summary['total_missing_across_languages'] > 0) {
    $recommendations[] = 'Add missing translations using setTranslationKeys command';
}
if ($summary['total_unused_across_languages'] > 0) {
    $recommendations[] = 'Clean up unused keys using deleteTranslationKeys command';
}
if (empty($recommendations)) {
    $recommendations[] = 'All translations are complete and optimized!';
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Translation analysis complete')
    ->withData([
        'summary' => $summary,
        'analysis' => $analysis,
        'required_keys' => $requiredKeys,
        'recommendations' => $recommendations
    ])
    ->send();
