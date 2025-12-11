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
 * @param array $seen Array of seen node references to prevent circular loops
 * @return array The accumulated keys
 */
function extractTextKeys($node, &$keys = [], $currentDepth = 0, $maxDepth = 20, &$seen = []): array {
    // Depth limit protection - prevent stack overflow
    if ($currentDepth > $maxDepth) {
        return $keys;
    }
    
    // Only process arrays
    if (!is_array($node)) {
        return $keys;
    }
    
    // Circular reference protection
    $nodeId = spl_object_hash((object)$node);
    if (isset($seen[$nodeId])) {
        return $keys;
    }
    $seen[$nodeId] = true;
    
    // Extract textKey if present (skip __RAW__ prefixed keys)
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (strpos($node['textKey'], '__RAW__') !== 0) {
            $keys[] = $node['textKey'];
        }
    }
    
    // Recursively process children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractTextKeys($child, $keys, $currentDepth + 1, $maxDepth, $seen);
        }
    }
    
    // Process component data labels
    if (isset($node['data']['label']) && is_array($node['data']['label'])) {
        foreach ($node['data']['label'] as $label) {
            if (isset($label['textKey']) && is_string($label['textKey'])) {
                if (strpos($label['textKey'], '__RAW__') !== 0) {
                    $keys[] = $label['textKey'];
                }
            }
        }
    }
    
    // Clean up seen tracker for this branch
    unset($seen[$nodeId]);
    
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