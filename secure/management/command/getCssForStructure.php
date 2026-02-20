<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * getCssForStructure - Extracts CSS rules relevant to a specific structure
 * 
 * Returns only the CSS selectors/rules that apply to classes, IDs, and tags
 * found in the structure (page, menu, footer, component).
 * 
 * @method GET
 * @url /management/getCssForStructure/{type}/{name?}
 * @auth required
 * @permission read
 */

/**
 * Extract all CSS classes, IDs, and tags from a JSON structure recursively
 * 
 * @param array $structure The JSON structure
 * @param array $components Loaded component data (to avoid re-loading)
 * @return array ['classes' => [...], 'ids' => [...], 'tags' => [...]]
 */
function extractCssSelectorsFromStructure(array $structure, array &$components = []): array {
    $classes = [];
    $ids = [];
    $tags = [];
    
    foreach ($structure as $node) {
        // Handle component references
        if (isset($node['component'])) {
            $componentName = $node['component'];
            
            // Load component if not already loaded
            if (!isset($components[$componentName])) {
                $componentPath = TEMPLATES_JSON_PATH . '/components/' . $componentName . '.json';
                if (file_exists($componentPath)) {
                    $componentContent = @file_get_contents($componentPath);
                    if ($componentContent !== false) {
                        $componentData = json_decode($componentContent, true);
                        if (is_array($componentData)) {
                            $components[$componentName] = $componentData;
                        }
                    }
                }
            }
            
            // Recursively extract from component
            if (isset($components[$componentName])) {
                $componentSelectors = extractCssSelectorsFromStructure(
                    is_array($components[$componentName][0] ?? null) ? $components[$componentName] : [$components[$componentName]],
                    $components
                );
                $classes = array_merge($classes, $componentSelectors['classes']);
                $ids = array_merge($ids, $componentSelectors['ids']);
                $tags = array_merge($tags, $componentSelectors['tags']);
            }
            
            // Also extract from data params (component might have class in data)
            if (isset($node['data']) && is_array($node['data'])) {
                if (isset($node['data']['class'])) {
                    $nodeClasses = preg_split('/\s+/', trim($node['data']['class']));
                    $classes = array_merge($classes, $nodeClasses);
                }
                if (isset($node['data']['id'])) {
                    $ids[] = $node['data']['id'];
                }
            }
            
            continue;
        }
        
        // Handle regular tags
        if (isset($node['tag'])) {
            $tags[] = $node['tag'];
            
            // Extract params
            if (isset($node['params']) && is_array($node['params'])) {
                if (isset($node['params']['class'])) {
                    $nodeClasses = preg_split('/\s+/', trim($node['params']['class']));
                    $classes = array_merge($classes, $nodeClasses);
                }
                if (isset($node['params']['id'])) {
                    $ids[] = $node['params']['id'];
                }
            }
        }
        
        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            $childSelectors = extractCssSelectorsFromStructure($node['children'], $components);
            $classes = array_merge($classes, $childSelectors['classes']);
            $ids = array_merge($ids, $childSelectors['ids']);
            $tags = array_merge($tags, $childSelectors['tags']);
        }
    }
    
    return [
        'classes' => array_unique(array_filter($classes)),
        'ids' => array_unique(array_filter($ids)),
        'tags' => array_unique(array_filter($tags))
    ];
}

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [type, name?]
 * @return ApiResponse
 */
function __command_getCssForStructure(array $params = [], array $urlParams = []): ApiResponse {
    // Validate type parameter
    if (empty($urlParams) || !isset($urlParams[0])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("Type parameter missing from URL")
            ->withErrors([['field' => 'type', 'reason' => 'missing', 'usage' => 'GET /management/getCssForStructure/{type}/{name?}']]);
    }

    $type = $urlParams[0];

    if (!is_string($type)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The type parameter must be a string.')
            ->withErrors([['field' => 'type', 'reason' => 'invalid_type', 'expected' => 'string']]);
    }

    $allowed_types = ['menu', 'footer', 'page', 'component'];

    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]]);
    }

    // For pages and components, name is required
    $name = null;
    if ($type === 'page' || $type === 'component') {
        if (!isset($urlParams[1]) || empty($urlParams[1])) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage("Name required in URL for type={$type}")
                ->withErrors([['field' => 'name', 'reason' => 'missing', 'usage' => "GET /management/getCssForStructure/{$type}/{name}"]]);
        }
        
        // For pages, support nested routes
        if ($type === 'page') {
            $routeSegments = array_slice($urlParams, 1);
            $routeSegments = array_filter($routeSegments, fn($s) => $s !== '');
            $name = implode('/', $routeSegments);
        } else {
            $name = $urlParams[1];
        }
        
        // Security: block path traversal
        if (strpos($name, '..') !== false || strpos($name, '\\') !== false || strpos($name, "\0") !== false) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage('Name contains invalid path characters')
                ->withErrors([['field' => 'name', 'reason' => 'path_traversal_attempt']]);
        }
        
        // Validate format
        $segments = array_filter(explode('/', $name), fn($s) => $s !== '');
        foreach ($segments as $segment) {
            if (!RegexPatterns::match('identifier_alphanum', $segment)) {
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage("Invalid segment '$segment'. Use only alphanumeric, hyphens, and underscores")
                    ->withErrors([RegexPatterns::validationError('identifier_alphanum', 'name', $segment)]);
            }
        }
    }

    // Load the structure
    $structure = null;
    $structureType = $type;
    
    switch ($type) {
        case 'menu':
            $jsonPath = TEMPLATES_JSON_PATH . '/menu.json';
            break;
        case 'footer':
            $jsonPath = TEMPLATES_JSON_PATH . '/footer.json';
            break;
        case 'page':
            $jsonPath = resolvePageJsonPath($name);
            if ($jsonPath === null) {
                return ApiResponse::create(404, 'file.not_found')
                    ->withMessage("Structure file not found for page '{$name}'");
            }
            break;
        case 'component':
            $jsonPath = TEMPLATES_JSON_PATH . '/components/' . $name . '.json';
            break;
    }

    if (!file_exists($jsonPath)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found: " . basename($jsonPath));
    }

    $content = @file_get_contents($jsonPath);
    if ($content === false) {
        return ApiResponse::create(500, 'file.read_error')
            ->withMessage("Failed to read structure file");
    }

    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'json.parse_error')
            ->withMessage("Invalid JSON in structure file: " . json_last_error_msg());
    }

    // Ensure structure is an array
    if (!is_array($structure)) {
        $structure = [$structure];
    }

    // Extract CSS selectors from structure
    $components = [];
    $selectors = extractCssSelectorsFromStructure($structure, $components);

    // Load and parse CSS
    if (!defined('TEMPLATES_CSS_PATH') || !file_exists(TEMPLATES_CSS_PATH)) {
        return ApiResponse::create(500, 'config.missing')
            ->withMessage("CSS path not configured or file not found");
    }

    $cssContent = @file_get_contents(TEMPLATES_CSS_PATH);
    if ($cssContent === false) {
        return ApiResponse::create(500, 'file.read_error')
            ->withMessage("Failed to read CSS file");
    }

    $parser = new CssParser($cssContent);
    
    // Get relevant CSS
    $cssData = $parser->getCssForSelectors(
        $selectors['classes'],
        $selectors['ids'],
        $selectors['tags']
    );

    // Count totals
    $globalCount = count($cssData['global']);
    $mediaCount = 0;
    foreach ($cssData['mediaQueries'] as $rules) {
        $mediaCount += count($rules);
    }

    return ApiResponse::create(200, 'success')
        ->withMessage("CSS extracted for {$type}" . ($name ? "/{$name}" : ''))
        ->withData([
            'type' => $type,
            'name' => $name,
            'selectors' => [
                'classes' => array_values($selectors['classes']),
                'ids' => array_values($selectors['ids']),
                'tags' => array_values($selectors['tags'])
            ],
            'css' => $cssData,
            'cssFormatted' => $parser->formatExtractedCss($cssData),
            'stats' => [
                'classesFound' => count($selectors['classes']),
                'idsFound' => count($selectors['ids']),
                'tagsFound' => count($selectors['tags']),
                'globalRules' => $globalCount,
                'mediaQueryRules' => $mediaCount,
                'keyframes' => count($cssData['keyframes']),
                'rootVariables' => count($cssData['rootVariables'])
            ],
            'componentsResolved' => array_keys($components)
        ]);
}
