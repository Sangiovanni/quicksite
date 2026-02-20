<?php


/**
 * Generate page template content for a new route
 * 
 * @param string $route The route name
 * @return string The complete PHP page template content
 */
function generate_page_template(string $route): string {
    return <<<PHP
<?php

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
\$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
\$translator = new Translator(\$trimParameters->lang());
\$lang = \$trimParameters->lang();

require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
\$renderer = new JsonToHtmlRenderer(\$translator, ['lang' => \$lang, 'page' => '{$route}']);

\$content = \$renderer->renderPage('{$route}');

require_once SECURE_FOLDER_PATH . '/src/classes/PageManagement.php';

// Get page title from translation
\$pageTitle = \$translator->translate('page.titles.{$route}');

\$page = new PageManagement(\$pageTitle, \$content, \$lang);
\$page->render();

PHP;
}

/**
 * Generate default JSON page structure
 * 
 * @param string $route_name The route name
 * @return string JSON content for the page
 */
function generate_page_json(string $route_name): string {
    $json_structure = [];
    
    return json_encode($json_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}


function validateStructureDepth($node, $depth = 0, $maxDepth = 50): bool {
    if ($depth > $maxDepth) {
        return false;
    }
    
    if (!is_array($node)) {
        return true;
    }
    
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (!validateStructureDepth($child, $depth + 1, $maxDepth)) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Validate nested object/array depth (for translations, configs, etc.)
 * Unlike validateStructureDepth which checks 'children' arrays,
 * this checks ALL nested arrays/objects recursively
 * 
 * @param mixed $data The data to validate
 * @param int $depth Current recursion depth
 * @param int $maxDepth Maximum allowed depth (default 20)
 * @return bool True if depth is valid, false if exceeds limit
 */
function validateNestedDepth($data, $depth = 0, $maxDepth = 20): bool {
    if ($depth > $maxDepth) {
        return false;
    }
    
    if (!is_array($data)) {
        return true;
    }
    
    // Check depth of ALL nested values
    foreach ($data as $value) {
        if (is_array($value)) {
            if (!validateNestedDepth($value, $depth + 1, $maxDepth)) {
                return false;
            }
        }
    }
    
    return true;
}


function countNodes($structure): int {
    if (!is_array($structure)) {
        return 0;
    }
    
    $count = 1;
    
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $child) {
            $count += countNodes($child);
        }
    }
    
    return $count;
}

/**
 * Recursively extract textKey values from a JSON structure with depth protection
 * 
 * @param mixed $node The node to extract keys from
 * @param array $keys Accumulator for found keys (passed by reference)
 * @param int $currentDepth Current recursion depth
 * @param int $maxDepth Maximum allowed recursion depth (default 20)
 * @return array The accumulated keys
 */
function extractTextKeys($node, &$keys = [], $currentDepth = 0, $maxDepth = 20): array {
    // Depth limit protection - prevent stack overflow
    if ($currentDepth > $maxDepth) {
        return $keys;
    }
    
    // Only process arrays
    if (!is_array($node)) {
        return $keys;
    }
    
    // Extract textKey if present (skip __RAW__ prefixed keys)
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (strpos($node['textKey'], '__RAW__') !== 0) {
            $keys[] = $node['textKey'];
        }
    }
    
    // Recursively process children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractTextKeys($child, $keys, $currentDepth + 1, $maxDepth);
        }
    }
    
    // Process component data - check all string values that look like translation keys
    if (isset($node['component']) && isset($node['data']) && is_array($node['data'])) {
        foreach ($node['data'] as $key => $value) {
            // String value that looks like a translation key (contains dot, no spaces, not a path/url)
            if (is_string($value) && 
                strpos($value, '.') !== false && 
                strpos($value, ' ') === false &&
                strpos($value, '/') !== 0 &&
                strpos($value, 'http') !== 0 &&
                strpos($value, '__RAW__') !== 0 &&
                strpos($value, '{{') !== 0) {
                $keys[] = $value;
            }
            // Array of labels (for carousel, etc.)
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (isset($item['textKey']) && is_string($item['textKey'])) {
                        if (strpos($item['textKey'], '__RAW__') !== 0) {
                            $keys[] = $item['textKey'];
                        }
                    }
                    // Also check if item itself is a translation key string
                    if (is_string($item) && 
                        strpos($item, '.') !== false && 
                        strpos($item, ' ') === false &&
                        strpos($item, '/') !== 0 &&
                        strpos($item, 'http') !== 0 &&
                        strpos($item, '__RAW__') !== 0) {
                        $keys[] = $item;
                    }
                }
            }
        }
    }
    
    return $keys;
}

/**
 * Load and parse a JSON structure file with error handling
 * 
 * @param string $filePath Path to the JSON file
 * @return array|null Parsed array on success, null on failure
 */
function loadJsonStructure(string $filePath): ?array {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $json = file_get_contents($filePath);
    if ($json === false) {
        return null;
    }
    
    $structure = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    
    return $structure;
}

/**
 * Resolve page JSON file path using folder structure convention
 * Convention: ALL pages use folder structure - route/route.json
 * Falls back to flat route.json for backward compatibility
 * 
 * @param string $routePath Route path (e.g., 'home', 'guides/getting-started')
 * @param string|null $projectPath Optional project path, defaults to PROJECT_PATH constant
 * @return string|null Full path to JSON file, or null if not found
 */
function resolvePageJsonPath(string $routePath, ?string $projectPath = null): ?string {
    $projectPath = $projectPath ?? PROJECT_PATH;
    $basePath = $projectPath . '/templates/model/json/pages';
    
    $routePath = trim($routePath, '/');
    $segments = explode('/', $routePath);
    $leafName = end($segments);
    
    // Try folder structure first: path/name/name.json
    $folderPath = $basePath . '/' . $routePath . '/' . $leafName . '.json';
    if (file_exists($folderPath)) {
        return $folderPath;
    }
    
    // Fallback to flat structure: path/name.json
    $flatPath = $basePath . '/' . $routePath . '.json';
    if (file_exists($flatPath)) {
        return $flatPath;
    }
    
    return null;
}

/**
 * Resolve page PHP file path using folder structure convention
 * Convention: ALL pages use folder structure - route/route.php
 * Falls back to flat route.php for backward compatibility
 * 
 * @param string $routePath Route path (e.g., 'home', 'guides/getting-started')
 * @param string|null $projectPath Optional project path, defaults to PROJECT_PATH constant
 * @return string|null Full path to PHP file, or null if not found
 */
function resolvePagePhpPath(string $routePath, ?string $projectPath = null): ?string {
    $projectPath = $projectPath ?? PROJECT_PATH;
    $basePath = $projectPath . '/templates/pages';
    
    $routePath = trim($routePath, '/');
    $segments = explode('/', $routePath);
    $leafName = end($segments);
    
    // Try folder structure first: path/name/name.php
    $folderPath = $basePath . '/' . $routePath . '/' . $leafName . '.php';
    if (file_exists($folderPath)) {
        return $folderPath;
    }
    
    // Fallback to flat structure: path/name.php
    $flatPath = $basePath . '/' . $routePath . '.php';
    if (file_exists($flatPath)) {
        return $flatPath;
    }
    
    return null;
}

/**
 * Get the target path for a NEW page (always uses folder structure)
 * 
 * @param string $routePath Route path (e.g., 'home', 'guides/getting-started')
 * @param string $extension File extension ('json' or 'php')
 * @param string|null $projectPath Optional project path
 * @return string Full path where file should be created
 */
function getNewPagePath(string $routePath, string $extension, ?string $projectPath = null): string {
    $projectPath = $projectPath ?? PROJECT_PATH;
    $basePath = ($extension === 'json') 
        ? $projectPath . '/templates/model/json/pages'
        : $projectPath . '/templates/pages';
    
    $routePath = trim($routePath, '/');
    $segments = explode('/', $routePath);
    $leafName = end($segments);
    
    // Always use folder structure: path/name/name.ext
    return $basePath . '/' . $routePath . '/' . $leafName . '.' . $extension;
}

/**
 * Recursively scan all page JSON files in the pages directory
 * Handles both folder structure (route/route.json) and flat structure (route.json)
 * 
 * @param string|null $projectPath Optional project path, defaults to PROJECT_PATH constant
 * @return array Array of ['path' => absolute path, 'route' => route path]
 */
function scanAllPageJsonFiles(?string $projectPath = null): array {
    $projectPath = $projectPath ?? PROJECT_PATH;
    $pagesDir = $projectPath . '/templates/model/json/pages';
    $results = [];
    
    if (!is_dir($pagesDir)) {
        return $results;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pagesDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $fullPath = $file->getPathname();
            $relativePath = str_replace($pagesDir . DIRECTORY_SEPARATOR, '', $fullPath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            
            // Extract route from path
            // For folder structure: guides/getting-started/getting-started.json → guides/getting-started
            // For flat structure: home.json → home
            $route = preg_replace('/\.json$/', '', $relativePath);
            
            // If folder structure, the last segment is duplicated: guides/getting-started/getting-started
            // Remove the duplicate leaf
            $segments = explode('/', $route);
            if (count($segments) >= 2) {
                $lastTwo = array_slice($segments, -2);
                if ($lastTwo[0] === $lastTwo[1]) {
                    array_pop($segments);
                    $route = implode('/', $segments);
                }
            }
            
            $results[] = [
                'path' => $fullPath,
                'route' => $route,
                'filename' => $file->getFilename()
            ];
        }
    }
    
    return $results;
}

/**
 * Flatten nested routes array to flat list of route paths
 * e.g., ['home' => [], 'guides' => ['getting-started' => []]] 
 *       → ['home', 'guides', 'guides/getting-started']
 * 
 * @param array $routes Nested routes array
 * @param string $prefix Current path prefix
 * @return array Flat list of route paths
 */
function flattenRoutes(array $routes, string $prefix = ''): array {
    $result = [];
    
    foreach ($routes as $name => $children) {
        $path = $prefix === '' ? $name : $prefix . '/' . $name;
        $result[] = $path;
        
        if (is_array($children) && !empty($children)) {
            $result = array_merge($result, flattenRoutes($children, $path));
        }
    }
    
    return $result;
}

/**
 * Check if a route path exists in nested routes structure
 * 
 * @param string $routePath Route path to check (e.g., 'guides/getting-started')
 * @param array $routes Nested routes array
 * @return bool True if route exists
 */
function routeExists(string $routePath, array $routes): bool {
    $segments = array_filter(explode('/', trim($routePath, '/')));
    $current = $routes;
    
    foreach ($segments as $segment) {
        if (!isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    
    return true;
}
/**
 * Validate that params array does not contain reserved data-qs-* attributes
 * 
 * These attributes are auto-generated by QuickSite and must not be set manually:
 * - data-qs-node: Node identifier for Visual Editor
 * - data-qs-struct: Structure type indicator
 * - data-qs-*: Any other QuickSite internal attribute
 * 
 * @param array $params Associative array of HTML attributes
 * @return string|null The first reserved attribute found, or null if all valid
 */
function findReservedQsParam(array $params): ?string {
    foreach (array_keys($params) as $key) {
        if (is_string($key) && str_starts_with(strtolower($key), 'data-qs-')) {
            return $key;
        }
    }
    return null;
}

/**
 * Recursively check a structure tree for reserved data-qs-* attributes in params
 * 
 * @param mixed $node Node to validate (can be array structure or scalar)
 * @param int $depth Current recursion depth
 * @param int $maxDepth Maximum recursion depth to prevent infinite loops
 * @return array|null ['key' => string, 'path' => string] if found, null if valid
 */
function findReservedQsParamInStructure($node, int $depth = 0, int $maxDepth = 50, string $path = 'root'): ?array {
    if ($depth > $maxDepth || !is_array($node)) {
        return null;
    }
    
    // Check params in current node
    if (isset($node['params']) && is_array($node['params'])) {
        $reserved = findReservedQsParam($node['params']);
        if ($reserved !== null) {
            return ['key' => $reserved, 'path' => $path];
        }
    }
    
    // Recursively check children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $index => $child) {
            $childPath = $path . '.children[' . $index . ']';
            $result = findReservedQsParamInStructure($child, $depth + 1, $maxDepth, $childPath);
            if ($result !== null) {
                return $result;
            }
        }
    }
    
    return null;
}