<?php
/**
 * listPages - List all JSON page structures
 * Method: GET
 * URL: /management/listPages
 * 
 * Returns all pages from secure/templates/model/json/pages/
 * with their metadata and component usage.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$pagesDir = SECURE_FOLDER_PATH . '/templates/model/json/pages';

// Check directory exists
if (!is_dir($pagesDir)) {
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Pages directory not found, no pages available')
        ->withData([
            'pages' => [],
            'count' => 0
        ])
        ->send();
}

// Get all JSON files
$files = glob($pagesDir . '/*.json');
$pages = [];

// Also get routes for cross-reference
$routesFile = SECURE_FOLDER_PATH . '/routes.php';
$routes = file_exists($routesFile) ? require($routesFile) : [];

foreach ($files as $file) {
    $name = basename($file, '.json');
    
    // Read page structure
    $content = @file_get_contents($file);
    if ($content === false) {
        continue; // Skip unreadable files
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Include but mark as invalid
        $pages[] = [
            'name' => $name,
            'file' => $name . '.json',
            'valid' => false,
            'error' => 'Invalid JSON: ' . json_last_error_msg(),
            'has_route' => in_array($name, $routes),
            'components_used' => [],
            'translation_keys' => [],
            'node_count' => 0,
            'size' => filesize($file),
            'modified' => date('Y-m-d H:i:s', filemtime($file))
        ];
        continue;
    }
    
    // Count nodes
    $nodeCount = countPageNodes($structure);
    
    // Find components used
    $componentsUsed = [];
    $translationKeys = [];
    analyzePageStructure($structure, $componentsUsed, $translationKeys);
    
    $pages[] = [
        'name' => $name,
        'file' => $name . '.json',
        'valid' => true,
        'has_route' => in_array($name, $routes),
        'route_url' => in_array($name, $routes) ? '/' . $name : null,
        'components_used' => array_unique($componentsUsed),
        'translation_keys' => array_unique($translationKeys),
        'node_count' => $nodeCount,
        'size' => filesize($file),
        'modified' => date('Y-m-d H:i:s', filemtime($file))
    ];
}

// Sort by name
usort($pages, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Summary
$withRoutes = count(array_filter($pages, fn($p) => $p['has_route']));
$orphaned = count(array_filter($pages, fn($p) => !$p['has_route']));

ApiResponse::create(200, 'operation.success')
    ->withMessage('Pages listed successfully')
    ->withData([
        'pages' => $pages,
        'count' => count($pages),
        'with_routes' => $withRoutes,
        'orphaned' => $orphaned,
        'directory' => 'secure/templates/model/json/pages/'
    ])
    ->send();

/**
 * Count nodes in page structure
 */
function countPageNodes($structure) {
    $count = 0;
    
    if (!is_array($structure)) {
        return 0;
    }
    
    // If it's an array of nodes
    if (isset($structure[0]) || empty($structure)) {
        foreach ($structure as $node) {
            $count += countPageNodes($node);
        }
        return $count;
    }
    
    // Single node
    $count = 1;
    
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $child) {
            $count += countPageNodes($child);
        }
    }
    
    return $count;
}

/**
 * Analyze page structure for components and translation keys
 */
function analyzePageStructure($node, &$components, &$translations) {
    if (!is_array($node)) {
        return;
    }
    
    // If it's an array of nodes
    if (isset($node[0]) || (is_array($node) && !isset($node['tag']) && !isset($node['textKey']) && !isset($node['component']))) {
        foreach ($node as $child) {
            analyzePageStructure($child, $components, $translations);
        }
        return;
    }
    
    // Check for component reference
    if (isset($node['component'])) {
        $components[] = $node['component'];
        
        // Check data for translation keys
        if (isset($node['data']) && is_array($node['data'])) {
            foreach ($node['data'] as $key => $value) {
                // Keys ending with 'label', 'title', 'text' or starting with translation-like patterns
                if (is_string($value) && !str_starts_with($value, '/') && !str_starts_with($value, 'http') && !str_starts_with($value, '#')) {
                    // Check if it looks like a translation key (contains dots)
                    if (strpos($value, '.') !== false && !str_contains($value, ' ')) {
                        $translations[] = $value;
                    }
                }
            }
        }
    }
    
    // Check for textKey
    if (isset($node['textKey'])) {
        $key = $node['textKey'];
        // Skip raw text markers and placeholders
        if (!str_starts_with($key, '__RAW__') && !str_starts_with($key, '{{')) {
            $translations[] = $key;
        }
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            analyzePageStructure($child, $components, $translations);
        }
    }
}
