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
require_once SECURE_FOLDER_PATH . '/src/functions/renderBootstrap.php'; // qs_resolve_public_base (C15 15.4)

/**
 * Load sitemap config (custom URLs + excluded routes)
 */
function loadSitemapConfig(): array {
    $configPath = PROJECT_PATH . '/config/sitemap-config.json';
    $default = ['excludedRoutes' => [], 'customUrls' => []];
    if (!file_exists($configPath)) return $default;
    $data = json_decode(file_get_contents($configPath), true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

/**
 * Save sitemap config
 */
function saveSitemapConfig(array $config): bool {
    $configDir = PROJECT_PATH . '/config';
    if (!is_dir($configDir)) mkdir($configDir, 0755, true);
    $data = [
        'excludedRoutes' => array_values(array_unique($config['excludedRoutes'] ?? [])),
        'customUrls' => array_values(array_filter(array_unique($config['customUrls'] ?? []), function($u) {
            return is_string($u) && trim($u) !== '';
        }))
    ];
    return file_put_contents($configDir . '/sitemap-config.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

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

    // The sitemap base — ABSOLUTE by spec (R1 keeps the absolute form exactly here).
    // C15 15.4 chain, first non-empty wins: the per-call `baseUrl` param (the author's
    // word at generation time, unchanged since beta.8) → QS_PUBLIC_BASE_URL server env →
    // derived (install base + p/<id>/ — BASE_URL alone stopped being a project URL when
    // the webroot mirror died in 15.3, which is why it is no longer the default).
    $baseUrl = rtrim(qs_resolve_public_base()['abs'], '/');
    if (!empty($params['baseUrl'])) {
        $customBase = filter_var($params['baseUrl'], FILTER_VALIDATE_URL);
        if ($customBase) {
            $baseUrl = rtrim($customBase, '/');
        }
    }
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

    // Include per-route layout settings (menu/footer visibility)
    require_once SECURE_FOLDER_PATH . '/src/classes/RouteLayoutManager.php';
    $layoutManager = new RouteLayoutManager();
    $routeLayouts = [];
    foreach ($routes as $route) {
        $routeLayouts[$route] = $layoutManager->getEffectiveLayout($route);
    }
    $sitemapData['routeLayouts'] = $routeLayouts;

    // Beta.8 A2 Slice 7 — per-route resolver configs (sparse map: only
    // routes that have a sidecar entry are present). Powers two things in
    // the sitemap UI:
    //   1. The 'resolver' badge in _renderRoutePath (presence check).
    //   2. The Configure-resolver modal — opens pre-populated from the
    //      current config without a second round-trip on first edit.
    // Empty array when no resolvers are configured (most projects).
    require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';
    $sitemapData['routeResolvers'] = loadResolversSidecar();

    // Load sitemap config (exclusions + custom URLs)
    $sitemapConfig = loadSitemapConfig();
    $sitemapData['sitemapConfig'] = $sitemapConfig;

    // Handle saveConfig action (persist custom URLs / exclusions)
    if (isset($params['saveConfig']) && is_array($params['saveConfig'])) {
        $sitemapConfig = $params['saveConfig'];
        saveSitemapConfig($sitemapConfig);
        $sitemapData['sitemapConfig'] = loadSitemapConfig(); // re-read to return clean data
        $sitemapConfig = $sitemapData['sitemapConfig'];
    }

    // Apply exclusions and custom URLs only for text format (save/download)
    // JSON format returns all routes so the UI can show toggles
    if ($format === 'text') {
        $excludedRoutes = $sitemapConfig['excludedRoutes'] ?? [];
        if (!empty($excludedRoutes)) {
            // Remove URLs belonging to excluded routes
            $filteredRoutes = [];
            $filteredUrls = [];
            foreach ($sitemapData['routes'] as $routeData) {
                if (in_array($routeData['name'], $excludedRoutes)) continue;
                $filteredRoutes[] = $routeData;
                foreach ($routeData['urls'] as $url) {
                    $filteredUrls[] = $url;
                }
            }
            $sitemapData['routes'] = $filteredRoutes;
            $sitemapData['urls'] = $filteredUrls;
        }

        // Append custom URLs
        $customUrls = $sitemapConfig['customUrls'] ?? [];
        foreach ($customUrls as $customUrl) {
            if (filter_var($customUrl, FILTER_VALIDATE_URL)) {
                $sitemapData['urls'][] = $customUrl;
            }
        }
        $sitemapData['totalUrls'] = count($sitemapData['urls']);
    }

    // For internal calls and JSON format, return ApiResponse
    // Note: text format requires direct output, handled only via HTTP
    if ($format === 'text' && !defined('COMMAND_INTERNAL_CALL')) {
        $content = implode("\n", $sitemapData['urls']) . "\n";

        // If save=true, write sitemap.txt into the project's own public/ — the one copy.
        // C15 15.3: this used to write twice, to the project folder AND to the live public
        // root, because the served project's live copy lived at the web root. Those two
        // paths are now the same directory for every project, so the second write is gone.
        if (!empty($params['save'])) {
            $projectPublicDir = PROJECT_PATH . '/public';
            if (!is_dir($projectPublicDir)) mkdir($projectPublicDir, 0755, true);
            $projectSitemapPath = $projectPublicDir . '/sitemap.txt';
            if (file_put_contents($projectSitemapPath, $content) === false) {
                return ApiResponse::create(500, 'operation.write_failed')
                    ->withMessage('Failed to write sitemap.txt to project');
            }

            return ApiResponse::create(200, 'operation.success')
                ->withMessage('sitemap.txt saved successfully')
                ->withData([
                    'path' => $projectSitemapPath,
                    'urlCount' => count($sitemapData['urls']),
                    'content' => $content
                ]);
        }

        // Return plain text sitemap (HTTP only)
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="sitemap.txt"');
        echo $content;
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
    $bodyParams = json_decode(defined('REQUEST_BODY_RAW') ? REQUEST_BODY_RAW : file_get_contents('php://input'), true) ?: [];
    __command_getSiteMap($bodyParams, $urlSegments)->send();
}
