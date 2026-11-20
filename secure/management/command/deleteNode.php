<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

/**
 * Remove translation keys from every language file (default.json included), each word and the groups it leaves
 * empty. deleteNode hands it only the keys the site no longer names (qs_site_translation_keys()).
 *
 * @param string[] $keys Keys to remove
 * @return array{removed: string[], errors: string[]} the keys removed from at least one file
 */
function removeTranslationKeysFromFiles(array $keys): array {
    if (empty($keys)) {
        return ['removed' => [], 'errors' => []];
    }
    
    $translateDir = PROJECT_PATH . '/translate';
    if (!is_dir($translateDir)) {
        return ['removed' => [], 'errors' => ['Translate directory not found']];
    }
    
    $removed = [];
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
                $removed[$key] = true;
            }
        }
        
        if ($modified) {
            // Clean up empty parent objects
            cleanupEmptyParents($translations);
            
            if (!qs_json_write($file, $translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, LOCK_EX)) {
                $errors[] = "Failed to write: " . basename($file);
            }
        }
    }
    
    return ['removed' => array_map('strval', array_keys($removed)), 'errors' => $errors];
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
 * Removes a node and all its children from the structure, then each translation key the node named that the site
 * no longer names anywhere (qs_site_translation_keys()), in every language; a key still named elsewhere is kept,
 * and keepTranslationKeys keeps every key.
 */

/**
 * Command function for internal execution via CommandRunner
 */
function __command_deleteNode(array $params = [], array $urlParams = []): ApiResponse {
    // This command only removes content: neither the size cap nor the over-quota rule
    // refuses its writes, even when a re-save makes a file larger (qs_small_write_removal_only()).
    qs_small_write_removal_only();

    // Required parameters
    if (!isset($params['type'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameter: type')
            ->withErrors([['field' => 'type', 'reason' => 'missing']]);
    }
    
    if (!isset($params['nodeId'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameter: nodeId')
            ->withErrors([['field' => 'nodeId', 'reason' => 'missing']]);
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $nodeId = $params['nodeId'];
    $keepTranslationKeys = !empty($params['keepTranslationKeys']);
    
    // Validate type
    $allowed_types = ['menu', 'footer', 'page', 'component'];
    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]]);
    }
    
    // Name required for page/component
    if (($type === 'page' || $type === 'component') && ($name === null || $name === '')) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("Missing required parameter: name (required when type is {$type})")
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
        // The one page-name rule every command shares (qs_page_name_refusal()).
        $refusal = qs_page_name_refusal($name);
        if ($refusal !== null) {
            return $refusal;
        }
        $json_file = resolvePageJsonPath($name);
        if ($json_file === null) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$name}' does not exist");
        }
    } elseif ($type === 'component') {
        // The one component-name rule every command shares (qs_component_name_refusal()).
        $refusal = qs_component_name_refusal($name);
        if ($refusal !== null) {
            return $refusal;
        }
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
    
    // The translation keys the node names, by the rule every reader of a site's keys takes (in a component's
    // file, a value still holding a slot names no key yet)
    $translationKeys = qs_node_translation_keys($node, PROJECT_PATH, $type === 'component');
    
    // Remove the node from structure (NodeNavigator::removeNode, the removal every command shares)
    $removeResult = NodeNavigator::removeNode($structure, $nodeId);
    if (!$removeResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to delete node: " . $removeResult['error']);
    }
    $structure = $removeResult['structure'];
    
    // The whole structure about to be written. Checked on the RESULT, so
    // deleting the one node that fails is always accepted — that is how an
    // author clears a refusal.
    $unsafeStructureParam = qs_first_unsafe_structure_param($structure);
    if ($unsafeStructureParam !== null) {
        return qs_unsafe_structure_param_response($unsafeStructureParam);
    }

    // Write back to file
    $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to encode structure to JSON");
    }
    
    // The size check (qs_small_write_refusal): a removal, so it never refuses (marked
    // above), even when the structure was stored compact and grows as it is re-saved.
    $refusal = qs_small_write_refusal([$json_file => strlen($json_content)]);
    if ($refusal !== null) {
        return $refusal;
    }

    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write structure file");
    }

    // The node's keys the site still names elsewhere, read once the structure is written: kept in every case.
    // The others are removed from every language, unless keepTranslationKeys keeps them all.
    $stillNamed = [];
    if (!empty($translationKeys)) {
        $named = qs_site_translation_keys_named(qs_site_translation_keys(PROJECT_PATH));
        $stillNamed = array_values(array_filter($translationKeys, static fn(string $key): bool => isset($named[$key])));
    }
    $translationCleanup = ['removed' => [], 'errors' => []];
    if (!$keepTranslationKeys) {
        $translationCleanup = removeTranslationKeysFromFiles(array_values(array_diff($translationKeys, $stillNamed)));
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Node deleted successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'nodeId' => $nodeId,
            'deletedNode' => $deletedNodeInfo,
            'file' => $json_file,
            'translationKeysRemoved' => count($translationCleanup['removed']),
            'translationKeys' => $translationKeys,
            'translationKeysKept' => $stillNamed
        ]);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_deleteNode($params, [])->send();
}
