<?php
/**
 * listComponents - List all available reusable components
 * 
 * @method GET
 * @url /management/listComponents
 * @auth required
 * @permission read
 * 
 * Returns all components from secure/templates/model/json/components/
 * with their structure, metadata, and variable type detection.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Extract placeholder variables from component structure with type detection.
 * Returns array of ['name' => string, 'type' => 'textKey'|'param']
 * 
 * - Variables found in textKey fields → type: "textKey" (translation values)
 * - Variables found in params fields → type: "param" (attributes like href, src)
 * 
 * Filters out:
 * - System placeholders starting with __ (e.g., __current_page)
 * - Placeholders with parameters (containing ;) as they are fixed values
 */
function extractVariables($node, &$variables = []) {
    if (!is_array($node)) {
        return $variables;
    }
    
    // Check params for placeholders → type depends on attribute name
    // Translatable attributes (alt, placeholder, title, aria-*) → type: "textKey"
    // Other attributes (href, src, etc.) → type: "param"
    $translatableParams = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];
    
    if (isset($node['params']) && is_array($node['params'])) {
        foreach ($node['params'] as $paramName => $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $varName) {
                    // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                    if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                        continue;
                    }
                    
                    // Only add if not already present (first occurrence wins)
                    $exists = false;
                    foreach ($variables as $v) {
                        if ($v['name'] === $varName) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        // Determine type based on attribute name
                        $type = in_array($paramName, $translatableParams, true) ? 'textKey' : 'param';
                        $variables[] = ['name' => $varName, 'type' => $type];
                    }
                }
            }
        }
    }
    
    // Check textKey for placeholders → type: "textKey"
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (preg_match_all('/\{\{([^}]+)\}\}/', $node['textKey'], $matches)) {
            foreach ($matches[1] as $varName) {
                // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                    continue;
                }
                
                // Only add if not already present (first occurrence wins)
                $exists = false;
                foreach ($variables as $v) {
                    if ($v['name'] === $varName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $variables[] = ['name' => $varName, 'type' => 'textKey'];
                }
            }
        }
    }
    
    // Check component data for placeholders (nested component usage)
    if (isset($node['data']) && is_array($node['data'])) {
        foreach ($node['data'] as $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $varName) {
                    // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                    if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                        continue;
                    }
                    
                    $exists = false;
                    foreach ($variables as $v) {
                        if ($v['name'] === $varName) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        // Data bindings are typically text content
                        $variables[] = ['name' => $varName, 'type' => 'textKey'];
                    }
                }
            }
        }
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractVariables($child, $variables);
        }
    }
    
    return $variables;
}

/**
 * Legacy function - extract slot names only (for backwards compatibility)
 */
function extractSlots($node, &$slots = []) {
    $variables = extractVariables($node);
    foreach ($variables as $var) {
        $slots[] = $var['name'];
    }
    return array_unique($slots);
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

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_listComponents(array $params = [], array $urlParams = []): ApiResponse {
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';

    // Check directory exists
    if (!is_dir($componentsDir)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Components directory not found, no components available')
            ->withData([
                'components' => [],
                'count' => 0
            ]);
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
        
        // Extract slots (placeholders like {{name}}) - legacy format
        $slots = [];
        // Structure can be a single node object OR an array of nodes
        if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
            // Single node object
            extractSlots($structure, $slots);
        } else if (is_array($structure)) {
            // Array of nodes
            foreach ($structure as $node) {
                extractSlots($node, $slots);
            }
        }
        
        // Extract variables with type detection - new format
        $variables = [];
        if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
            // Single node object
            extractVariables($structure, $variables);
        } else if (is_array($structure)) {
            // Array of nodes
            foreach ($structure as $node) {
                extractVariables($node, $variables);
            }
        }
        
        // Detect if component uses other components
        $usesComponents = findUsedComponents($structure);
        
        $components[] = [
            'name' => $name,
            'file' => $name . '.json',
            'valid' => true,
            'structure' => $structure,
            'slots' => array_unique($slots),           // Legacy: just names
            'variables' => $variables,                  // New: [{name, type}, ...]
            'uses_components' => $usesComponents,
            'size' => filesize($file),
            'modified' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }

    // Sort by name
    usort($components, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Components listed successfully')
        ->withData([
            'components' => $components,
            'count' => count($components),
            'directory' => 'secure/templates/model/json/components/'
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listComponents()->send();
}
