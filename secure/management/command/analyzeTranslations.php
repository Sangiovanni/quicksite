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
 * Performs comprehensive analysis of translations, on the keys the site uses (qs_site_translation_keys()):
 * 1. Missing translations (keys the site needs in every language, with no word in a translation file)
 * 2. Unused translations (keys in translation files the site does not use)
 * 3. Coverage statistics per language
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
        
        // An EXISTING language: one of the project's.
        if (!qs_project_has_language($targetLang)) {
            return qs_language_not_in_project_response($targetLang, 'language');
        }
    }

    // The keys the site uses, and the keys it needs in every language (sorted)
    $sites = qs_site_translation_keys(PROJECT_PATH);
    $requiredKeys = qs_translation_keys_in_use($sites, [])['required'];

    // Determine which languages to analyze
    $languagesToAnalyze = $targetLang ? [$targetLang] : qs_project_language_codes();

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
        
        $translations = qs_translation_file_tree($translationFile);
        
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
            if (!qs_translation_tree_has_word($translations, $key)) {
                $missingKeys[] = $key;
            }
        }
        
        // Find unused keys (in translations, not used by the site in this language). An optional key this
        // language holds is used, never missing where it does not.
        $translationKeys = qs_translation_tree_keys($translations);
        $unusedKeys = array_values(array_diff($translationKeys, qs_translation_keys_in_use($sites, [$translations])['used']));
        
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
