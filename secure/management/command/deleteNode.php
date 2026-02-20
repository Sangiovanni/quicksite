<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Translatable attributes that may contain translation keys
 * These are created by addNode and should be cleaned up on delete
 */
const TRANSLATABLE_ATTRS = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];

/**
 * Collect all translation keys from a node and its children recursively
 * 
 * @param array $node The node to extract keys from
 * @return array Array of translation keys found
 */
function collectTranslationKeysFromNode(array $node): array {
    $keys = [];
    
    // Check for textKey (direct text content)
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        $keys[] = $node['textKey'];
    }
    
    // Check for translatable attributes in params
    if (isset($node['params']) && is_array($node['params'])) {
        foreach (TRANSLATABLE_ATTRS as $attr) {
            if (isset($node['params'][$attr]) && is_string($node['params'][$attr])) {
                // Only add if it looks like a translation key (contains dots)
                $value = $node['params'][$attr];
                if (strpos($value, '.') !== false && !preg_match('/^https?:/', $value)) {
                    $keys[] = $value;
                }
            }
        }
    }
    
    // Recursively collect from children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (is_array($child)) {
                $keys = array_merge($keys, collectTranslationKeysFromNode($child));
            }
        }
    }
    
    return $keys;
}

/**
 * Remove translation keys from all language files
 * 
 * NOTE: This does NOT check if keys are used elsewhere in other structures.
 * Auto-generated keys (e.g., "home.item5") are unique by design so this is safe.
 * If users manually share translation keys across nodes, they should be aware
 * that deleting one node may remove the shared key. This is acceptable as:
 * 1. Scanning all structures would be expensive for large projects
 * 2. Shared manual keys are a rare edge case
 * 3. Users manually sharing keys should manage them consciously
 * 
 * @param array $keys Keys to remove
 * @return array Result with removed count and any errors
 */
function removeTranslationKeysFromFiles(array $keys): array {
    if (empty($keys)) {
        return ['removed' => 0, 'errors' => []];
    }
    
    $translateDir = PROJECT_PATH . '/translate';
    if (!is_dir($translateDir)) {
        return ['removed' => 0, 'errors' => ['Translate directory not found']];
    }
    
    $removed = 0;
    $errors = [];
    
    // Process all JSON files in translate directory
    $files = glob($translateDir . '/*.json');
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        $translations = json_decode($content, true);
        if (!is_array($translations)) continue;
        
        $modified = false;
        foreach ($keys as $key) {
            if (removeNestedKey($translations, $key)) {
                $modified = true;
                $removed++;
            }
        }
        
        if ($modified) {
            // Clean up empty parent objects
            cleanupEmptyParents($translations);
            
            $json = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($file, $json, LOCK_EX) === false) {
                $errors[] = "Failed to write: " . basename($file);
            }
        }
    }
    
    return ['removed' => $removed, 'errors' => $errors];
}

/**
 * Remove a nested key using dot notation
 */
function removeNestedKey(array &$arr, string $key): bool {
    $parts = explode('.', $key);
    $ref = &$arr;
    
    for ($i = 0; $i < count($parts) - 1; $i++) {
        if (!isset($ref[$parts[$i]]) || !is_array($ref[$parts[$i]])) {
            return false;
        }
        $ref = &$ref[$parts[$i]];
    }
    
    $lastKey = end($parts);
    if (array_key_exists($lastKey, $ref)) {
        unset($ref[$lastKey]);
        return true;
    }
    
    return false;
}

/**
 * Recursively clean up empty parent objects after key deletion
 */
function cleanupEmptyParents(array &$arr): void {
    foreach ($arr as $key => &$value) {
        if (is_array($value)) {
            cleanupEmptyParents($value);
            if (empty($value)) {
                unset($arr[$key]);
            }
        }
    }
}

/**
 * deleteNode - Deletes a node from a structure
 * 
 * @method DELETE
 * @url /management/deleteNode
 * @auth required
 * @permission editStructure
 * 
 * Removes a node and all its children from the structure.
 */

/**
 * Command function for internal execution via CommandRunner
 */
function __command_deleteNode(array $params = [], array $urlParams = []): ApiResponse {
    // Required parameters
    if (!isset($params['type'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'type', 'reason' => 'missing']]);
    }
    
    if (!isset($params['nodeId'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'nodeId', 'reason' => 'missing']]);
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $nodeId = $params['nodeId'];
    
    // Validate type
    $allowed_types = ['menu', 'footer', 'page', 'component'];
    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]]);
    }
    
    // Name required for page/component
    if (($type === 'page' || $type === 'component') && empty($name)) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'name', 'reason' => "required for type={$type}"]]);
    }
    
    // Validate node ID format
    if (!RegexPatterns::match('node_id', $nodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid nodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'nodeId', $nodeId)]);
    }
    
    // Build file path
    if ($type === 'page') {
        $json_file = resolvePageJsonPath($name);
        if ($json_file === null) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$name}' does not exist");
        }
    } elseif ($type === 'component') {
        $json_file = PROJECT_PATH . '/templates/model/json/components/' . $name . '.json';
    } else {
        $json_file = PROJECT_PATH . '/templates/model/json/' . $type . '.json';
    }
    
    // Read existing structure
    if (!file_exists($json_file)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found")
            ->withData(['file' => $json_file]);
    }
    
    $content = @file_get_contents($json_file);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage("Failed to read structure file");
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON in structure file: " . json_last_error_msg());
    }
    
    // Verify node exists
    $node = NodeNavigator::getNode($structure, $nodeId);
    if ($node === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found at: {$nodeId}")
            ->withErrors([['field' => 'nodeId', 'value' => $nodeId]]);
    }
    
    // Get node info for response (tag, component name, etc.)
    $deletedNodeInfo = [
        'tag' => $node['tag'] ?? null,
        'component' => $node['component'] ?? null,
        'hasChildren' => isset($node['children']) && !empty($node['children'])
    ];
    
    // Collect translation keys from the node before deletion
    $translationKeys = collectTranslationKeysFromNode($node);
    
    // Parse node ID into indices
    $indices = array_map('intval', explode('.', $nodeId));
    
    // Remove the node from structure
    $removeResult = removeNodeFromStructure($structure, $indices);
    if (!$removeResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to delete node: " . $removeResult['error']);
    }
    $structure = $removeResult['structure'];
    
    // Write back to file
    $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to encode structure to JSON");
    }
    
    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write structure file");
    }
    
    // Clean up translation keys from the deleted node
    $translationCleanup = ['removed' => 0, 'errors' => []];
    if (!empty($translationKeys)) {
        $translationCleanup = removeTranslationKeysFromFiles($translationKeys);
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Node deleted successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'nodeId' => $nodeId,
            'deletedNode' => $deletedNodeInfo,
            'file' => $json_file,
            'translationKeysRemoved' => count($translationKeys),
            'translationKeys' => $translationKeys
        ]);
}

/**
 * Remove a node from the structure at the given indices path
 */
function removeNodeFromStructure(array $structure, array $indices): array {
    if (empty($indices)) {
        return ['success' => false, 'error' => 'Empty indices'];
    }
    
    // Detect if root is a single object (component) or array of nodes (page)
    $isRootObject = isset($structure['tag']);
    
    // Navigate to the parent and remove the node
    $ref = &$structure;
    
    for ($i = 0; $i < count($indices) - 1; $i++) {
        $idx = $indices[$i];
        
        if ($i === 0) {
            // First level access
            if ($isRootObject) {
                // Component: first index accesses children directly
                if (!isset($ref['children'][$idx])) {
                    return ['success' => false, 'error' => "Node not found at index {$idx}"];
                }
                $ref = &$ref['children'][$idx];
            } else {
                // Page: direct array access
                if (!isset($ref[$idx])) {
                    return ['success' => false, 'error' => "Node not found at index {$idx}"];
                }
                $ref = &$ref[$idx];
            }
        } else {
            // Deeper levels - access children
            if (!isset($ref['children']) || !isset($ref['children'][$idx])) {
                return ['success' => false, 'error' => "Node not found in children at index {$idx}"];
            }
            $ref = &$ref['children'][$idx];
        }
    }
    
    // Now $ref is the parent, remove the node at final index
    $finalIndex = end($indices);
    
    if (count($indices) === 1) {
        // Root level deletion
        if ($isRootObject) {
            // Component: delete from children array
            if (!isset($structure['children']) || !isset($structure['children'][$finalIndex])) {
                return ['success' => false, 'error' => "Root child node not found at index {$finalIndex}"];
            }
            array_splice($structure['children'], $finalIndex, 1);
        } else {
            // Page: delete from root array
            if (!isset($structure[$finalIndex])) {
                return ['success' => false, 'error' => "Root node not found at index {$finalIndex}"];
            }
            array_splice($structure, $finalIndex, 1);
        }
    } else {
        // Nested level
        if (!isset($ref['children']) || !isset($ref['children'][$finalIndex])) {
            return ['success' => false, 'error' => "Child node not found at index {$finalIndex}"];
        }
        array_splice($ref['children'], $finalIndex, 1);
    }
    
    return ['success' => true, 'structure' => $structure];
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_deleteNode($params, [])->send();
}
