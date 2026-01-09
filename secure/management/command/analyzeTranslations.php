<?php
/**
 * analyzeTranslations - Complete translation health check
 * 
 * @method GET
 * @url /management/analyzeTranslations
 * @url /management/analyzeTranslations/{language}
 * @auth required
 * @permission read
 * 
 * Performs comprehensive analysis of translations:
 * 1. Missing translations (keys in structures but not in translation files)
 * 2. Unused translations (keys in translation files but not in structures)
 * 3. Coverage statistics per language
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Extract all textKeys from all structures
 */
function extractRequiredKeys_analyze(): array {
    $allKeys = [];
    
    // Scan pages
    $pagesDir = PROJECT_PATH . '/templates/model/json/pages';
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
    
    // Add page.titles.{route} keys (used dynamically)
    $routesFile = PROJECT_PATH . '/routes.php';
    if (file_exists($routesFile)) {
        $routes = include $routesFile;
        if (is_array($routes)) {
            // Routes are keyed by route name, values are config arrays
            foreach (array_keys($routes) as $route) {
                $allKeys[] = 'page.titles.' . $route;
            }
        }
    }
    
    return array_unique($allKeys);
}

/**
 * Flatten nested translation array to dot notation keys
 */
function flattenKeys_analyze(array $arr, string $prefix = ''): array {
    $keys = [];
    foreach ($arr as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys_analyze($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }
    return $keys;
}

/**
 * Check if a key exists in translations AND has a non-empty value (dot notation)
 * Empty string values are considered "untranslated"
 */
function keyExists_analyze(string $key, array $translations): bool {
    $segments = explode('.', $key);
    $current = $translations;
    foreach ($segments as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    // Empty string is considered as "missing/untranslated"
    if (is_string($current) && $current === '') {
        return false;
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
function __command_analyzeTranslations(array $params = [], array $urlParams = []): ApiResponse {
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

    // Get all required keys from structures
    $requiredKeys = extractRequiredKeys_analyze();
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
        $translationFile = PROJECT_PATH . '/translate/' . $lang . '.json';
        
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
            if (!keyExists_analyze($key, $translations)) {
                $missingKeys[] = $key;
            }
        }
        
        // Find unused keys (in translations but not required)
        $translationKeys = flattenKeys_analyze($translations);
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

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translation analysis complete')
        ->withData([
            'summary' => $summary,
            'analysis' => $analysis,
            'required_keys' => $requiredKeys,
            'recommendations' => $recommendations
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_analyzeTranslations([], $urlSegments)->send();
}
