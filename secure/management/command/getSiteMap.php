<?php
/**
 * getSiteMap - Returns a complete sitemap of all routes × all languages
 * 
 * @method GET
 * @url /management/getSiteMap
 * @url /management/getSiteMap/{format}
 * @auth required
 * @permission read
 * 
 * Supports two output formats:
 * - text: Plain text URLs (for sitemap.txt generation)
 * - json: Structured data (for Dashboard and API consumers)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Recursively count non-empty translation values
 */
function countNonEmptyTranslations(array $arr): int {
    $count = 0;
    foreach ($arr as $value) {
        if (is_array($value)) {
            $count += countNonEmptyTranslations($value);
        } elseif (is_string($value) && trim($value) !== '') {
            $count++;
        }
    }
    return $count;
}

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments - [0] = format ('text' or 'json')
 * @return ApiResponse
 */
function __command_getSiteMap(array $params = [], array $urlParams = []): ApiResponse {
    // Get format from URL parameter (default: json)
    $format = $urlParams[0] ?? 'json';

    // Validate format
    if (!in_array($format, ['text', 'json'])) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid format. Use 'text' or 'json'")
            ->withData(['requested_format' => $format, 'valid_formats' => ['text', 'json']]);
    }

    // Get configuration
    $baseUrl = rtrim(BASE_URL, '/');
    $multilingual = CONFIG['MULTILINGUAL_SUPPORT'] ?? false;
    $languages = CONFIG['LANGUAGES_SUPPORTED'] ?? ['en'];
    $defaultLang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
    $languageNames = CONFIG['LANGUAGES_NAME'] ?? [];

    // Get all routes (flatten nested structure)
    $routes = flattenRoutes(ROUTES);

    // Build sitemap data
    $sitemapData = [
        'baseUrl' => $baseUrl,
        'multilingual' => $multilingual,
        'languages' => $languages,
        'defaultLang' => $defaultLang,
        'languageNames' => $languageNames,
        'routes' => [],
        'urls' => [],
        'totalUrls' => 0
    ];

    // Generate URLs for each route
    foreach ($routes as $route) {
        $routeData = [
            'name' => $route,
            'path' => $route === 'home' ? '/' : '/' . $route,
            'urls' => []
        ];
        
        if ($multilingual) {
            // Generate URL for each language
            foreach ($languages as $lang) {
                $url = $baseUrl . '/' . $lang;
                if ($route !== 'home') {
                    $url .= '/' . $route;
                }
                $routeData['urls'][$lang] = $url;
                $sitemapData['urls'][] = $url;
            }
        } else {
            // Single language
            $url = $baseUrl;
            if ($route !== 'home') {
                $url .= '/' . $route;
            } else {
                $url .= '/';
            }
            $routeData['urls']['default'] = $url;
            $sitemapData['urls'][] = $url;
        }
        
        $sitemapData['routes'][] = $routeData;
    }

    $sitemapData['totalUrls'] = count($sitemapData['urls']);

    // Calculate language coverage (for Dashboard use)
    if ($multilingual) {
        $sitemapData['coverage'] = [];
        foreach ($languages as $lang) {
            // Check how many routes have translations
            $translationsFile = PROJECT_PATH . '/translate/' . $lang . '.json';
            $translationCount = 0;
            $hasTranslations = false;
            
            if (file_exists($translationsFile)) {
                $translations = json_decode(file_get_contents($translationsFile), true);
                if ($translations) {
                    $hasTranslations = true;
                    // Count non-empty translations
                    $translationCount = countNonEmptyTranslations($translations);
                }
            }
            
            $sitemapData['coverage'][$lang] = [
                'code' => $lang,
                'name' => $languageNames[$lang] ?? $lang,
                'isDefault' => $lang === $defaultLang,
                'hasTranslations' => $hasTranslations,
                'translationKeyCount' => $translationCount
            ];
        }
    }

    // For internal calls and JSON format, return ApiResponse
    // Note: text format requires direct output, handled only via HTTP
    if ($format === 'text' && !defined('COMMAND_INTERNAL_CALL')) {
        // Return plain text sitemap (HTTP only)
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="sitemap.txt"');
        echo implode("\n", $sitemapData['urls']);
        exit;
    }
    
    // For text format via internal call, include URLs in data
    if ($format === 'text') {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Sitemap generated successfully')
            ->withData([
                'format' => 'text',
                'content' => implode("\n", $sitemapData['urls'])
            ]);
    }

    // Return JSON response
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Sitemap generated successfully')
        ->withData($sitemapData);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    // Get format from URL parameter for HTTP requests
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getSiteMap([], $urlSegments)->send();
}
