<?php
/**
 * listComponents - List all available reusable components
 * Method: GET
 * URL: /management/listComponents
 * 
 * Returns all components from secure/templates/model/json/components/
 * with their structure and metadata.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$componentsDir = SECURE_FOLDER_PATH . '/templates/model/json/components';

// Check directory exists
if (!is_dir($componentsDir)) {
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Components directory not found, no components available')
        ->withData([
            'components' => [],
            'count' => 0
        ])
        ->send();
}

// Get all JSON files
$files = glob($componentsDir . '/*.json');
$components = [];

foreach ($files as $file) {
    $name = basename($file, '.json');
    
    // Read component structure
    $content = @file_get_contents($file);
    if ($content === false) {
        continue; // Skip unreadable files
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Include but mark as invalid
        $components[] = [
            'name' => $name,
            'file' => $name . '.json',
            'valid' => false,
            'error' => 'Invalid JSON: ' . json_last_error_msg(),
            'structure' => null,
            'slots' => [],
            'size' => filesize($file),
            'modified' => date('Y-m-d H:i:s', filemtime($file))
        ];
        continue;
    }
    
    // Extract slots (placeholders like {{name}})
    $slots = extractSlots($structure);
    
    // Detect if component uses other components
    $usesComponents = findUsedComponents($structure);
    
    $components[] = [
        'name' => $name,
        'file' => $name . '.json',
        'valid' => true,
        'structure' => $structure,
        'slots' => array_unique($slots),
        'uses_components' => $usesComponents,
        'size' => filesize($file),
        'modified' => date('Y-m-d H:i:s', filemtime($file))
    ];
}

// Sort by name
usort($components, fn($a, $b) => strcasecmp($a['name'], $b['name']));

ApiResponse::create(200, 'operation.success')
    ->withMessage('Components listed successfully')
    ->withData([
        'components' => $components,
        'count' => count($components),
        'directory' => 'secure/templates/model/json/components/'
    ])
    ->send();

/**
 * Extract placeholder slots from component structure
 * Finds patterns like {{name}}, {{href}}, etc.
 */
function extractSlots($node, &$slots = []) {
    if (!is_array($node)) {
        return $slots;
    }
    
    // Check params for placeholders
    if (isset($node['params']) && is_array($node['params'])) {
        foreach ($node['params'] as $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $slot) {
                    $slots[] = $slot;
                }
            }
        }
    }
    
    // Check textKey for placeholders
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (preg_match_all('/\{\{([^}]+)\}\}/', $node['textKey'], $matches)) {
            foreach ($matches[1] as $slot) {
                $slots[] = $slot;
            }
        }
    }
    
    // Check component data for placeholders
    if (isset($node['data']) && is_array($node['data'])) {
        foreach ($node['data'] as $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $slot) {
                    $slots[] = $slot;
                }
            }
        }
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractSlots($child, $slots);
        }
    }
    
    return $slots;
}

/**
 * Find other components used by this component
 */
function findUsedComponents($node, &$used = []) {
    if (!is_array($node)) {
        return $used;
    }
    
    // Check if this node references a component
    if (isset($node['component'])) {
        $used[] = $node['component'];
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            findUsedComponents($child, $used);
        }
    }
    
    return array_unique($used);
}
