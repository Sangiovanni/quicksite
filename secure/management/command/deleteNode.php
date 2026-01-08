<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

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
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Node deleted successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'nodeId' => $nodeId,
            'deletedNode' => $deletedNodeInfo,
            'file' => $json_file
        ]);
}

/**
 * Remove a node from the structure at the given indices path
 */
function removeNodeFromStructure(array $structure, array $indices): array {
    if (empty($indices)) {
        return ['success' => false, 'error' => 'Empty indices'];
    }
    
    // Navigate to the parent and remove the node
    $ref = &$structure;
    
    for ($i = 0; $i < count($indices) - 1; $i++) {
        $idx = $indices[$i];
        
        if ($i === 0) {
            // First level - direct array access
            if (!isset($ref[$idx])) {
                return ['success' => false, 'error' => "Node not found at index {$idx}"];
            }
            $ref = &$ref[$idx];
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
        // Root level
        if (!isset($structure[$finalIndex])) {
            return ['success' => false, 'error' => "Root node not found at index {$finalIndex}"];
        }
        array_splice($structure, $finalIndex, 1);
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
