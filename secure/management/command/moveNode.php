<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * moveNode - Moves a node from one position to another within a structure
 * 
 * @method PATCH
 * @url /management/moveNode
 * @auth required
 * @permission editStructure
 * 
 * Atomically moves a node to a new position. Handles:
 * - Same-level moves (reordering siblings)
 * - Cross-level moves (moving into/out of nested children)
 * - Automatic index adjustment after removal
 */

/**
 * Command function for internal execution via CommandRunner
 */
function __command_moveNode(array $params = [], array $urlParams = []): ApiResponse {
    // Required parameters
    if (!isset($params['type'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'type', 'reason' => 'missing']]);
    }
    
    if (!isset($params['sourceNodeId'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'sourceNodeId', 'reason' => 'missing']]);
    }
    
    if (!isset($params['targetNodeId'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'targetNodeId', 'reason' => 'missing']]);
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $sourceNodeId = $params['sourceNodeId'];
    $targetNodeId = $params['targetNodeId'];
    $position = $params['position'] ?? 'after'; // 'before' or 'after'
    
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
    
    // Validate node IDs format
    if (!RegexPatterns::match('node_id', $sourceNodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid sourceNodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'sourceNodeId', $sourceNodeId)]);
    }
    
    if (!RegexPatterns::match('node_id', $targetNodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid targetNodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'targetNodeId', $targetNodeId)]);
    }
    
    // Validate position
    if (!in_array($position, ['before', 'after', 'inside'], true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid position. Must be 'before', 'after', or 'inside'")
            ->withErrors([['field' => 'position', 'value' => $position, 'allowed' => ['before', 'after', 'inside']]]);
    }
    
    // Can't move a node relative to itself
    if ($sourceNodeId === $targetNodeId) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Cannot move a node relative to itself")
            ->withErrors([['field' => 'targetNodeId', 'reason' => 'same as source']]);
    }
    
    // Can't move a node into its own children
    if (strpos($targetNodeId, $sourceNodeId . '.') === 0) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Cannot move a node into its own descendants")
            ->withErrors([['field' => 'targetNodeId', 'reason' => 'is descendant of source']]);
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
    
    // Get the source node
    $sourceNode = NodeNavigator::getNode($structure, $sourceNodeId);
    if ($sourceNode === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Source node not found at: {$sourceNodeId}")
            ->withErrors([['field' => 'sourceNodeId', 'value' => $sourceNodeId]]);
    }
    
    // Verify target node exists
    $targetNode = NodeNavigator::getNode($structure, $targetNodeId);
    if ($targetNode === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Target node not found at: {$targetNodeId}")
            ->withErrors([['field' => 'targetNodeId', 'value' => $targetNodeId]]);
    }
    
    // Parse node IDs into indices
    $sourceIndices = array_map('intval', explode('.', $sourceNodeId));
    $targetIndices = array_map('intval', explode('.', $targetNodeId));
    
    // Determine source and target parents
    $sourceParentPath = array_slice($sourceIndices, 0, -1);
    $targetParentPath = array_slice($targetIndices, 0, -1);
    $sourceIndex = end($sourceIndices);
    $targetIndex = end($targetIndices);
    
    // Check if same parent (simpler case)
    $sameParent = ($sourceParentPath === $targetParentPath);
    
    // Step 1: Remove source node from its current position
    $removeResult = removeNodeFromStructure($structure, $sourceIndices);
    if (!$removeResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to remove source node: " . $removeResult['error']);
    }
    $structure = $removeResult['structure'];
    $removedNode = $removeResult['node'];
    
    // Step 2: Adjust target indices after removal
    // When we remove a node, all subsequent siblings (and their descendants) shift down
    // We need to check if source removal affects the target path at any level
    
    // Function to check if removing source affects target at a given depth
    // Source removal affects target if at the same parent path up to that depth,
    // and source index < target index at that level
    $adjustedTargetIndices = $targetIndices;
    
    // Only need to check the first index (root level) or shared parent path
    // Compare at each level where source and target share the same parent prefix
    $minLen = min(count($sourceIndices), count($targetIndices));
    
    // Find the level where source could affect target
    // Source path: [2] (root level, index 2)
    // Target path: [3, 0] (root level index 3, then child 0)
    // If source was removed at root level index 2, target's root index 3 becomes 2
    
    for ($level = 0; $level < $minLen; $level++) {
        // Get the parent path up to this level
        $sourceParentAtLevel = array_slice($sourceIndices, 0, $level);
        $targetParentAtLevel = array_slice($targetIndices, 0, $level);
        
        // If parents match at this level, check if source index affects target
        if ($sourceParentAtLevel === $targetParentAtLevel) {
            $sourceIdxAtLevel = $sourceIndices[$level];
            $targetIdxAtLevel = $targetIndices[$level];
            
            // If this is the last index of source (where removal happens)
            // and source is before target at this level, adjust target
            if ($level === count($sourceIndices) - 1 && $sourceIdxAtLevel < $targetIdxAtLevel) {
                $adjustedTargetIndices[$level]--;
            }
        }
    }
    
    // Use adjusted indices
    $targetIndices = $adjustedTargetIndices;
    $targetIndex = end($targetIndices);
    $targetParentPath = array_slice($targetIndices, 0, -1);
    
    // Step 3: Calculate insert position
    if ($position === 'inside') {
        // 'inside' = append as last child of the target node
        // Navigate to the target node in the (post-removal) structure to count its children
        $adjustedTargetId = implode('.', $targetIndices);
        $targetNodeAfterRemoval = NodeNavigator::getNode($structure, $adjustedTargetId);
        if ($targetNodeAfterRemoval === null) {
            return ApiResponse::create(400, 'operation.failed')
                ->withMessage("Target node not found after source removal at: {$adjustedTargetId}");
        }
        $childCount = isset($targetNodeAfterRemoval['children']) ? count($targetNodeAfterRemoval['children']) : 0;
        $insertIndices = $targetIndices;
        $insertIndices[] = $childCount; // append at end of children
    } else {
        $insertIndex = ($position === 'after') ? $targetIndex + 1 : $targetIndex;
        $insertIndices = $targetParentPath;
        $insertIndices[] = $insertIndex;
    }
    
    // Step 4: Insert node at new position
    $insertResult = insertNodeIntoStructure($structure, $insertIndices, $removedNode);
    if (!$insertResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to insert node: " . $insertResult['error']);
    }
    $structure = $insertResult['structure'];
    $newNodeId = implode('.', $insertIndices);
    
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
        ->withMessage('Node moved successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'sourceNodeId' => $sourceNodeId,
            'targetNodeId' => $targetNodeId,
            'position' => $position,
            'newNodeId' => $newNodeId,
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
        // Root level removal
        if ($isRootObject) {
            // Component: remove from children array
            if (!isset($structure['children']) || !isset($structure['children'][$finalIndex])) {
                return ['success' => false, 'error' => "Root child node not found at index {$finalIndex}"];
            }
            $removedNode = $structure['children'][$finalIndex];
            array_splice($structure['children'], $finalIndex, 1);
        } else {
            // Page: remove from root array
            if (!isset($structure[$finalIndex])) {
                return ['success' => false, 'error' => "Root node not found at index {$finalIndex}"];
            }
            $removedNode = $structure[$finalIndex];
            array_splice($structure, $finalIndex, 1);
        }
    } else {
        // Nested level
        if (!isset($ref['children']) || !isset($ref['children'][$finalIndex])) {
            return ['success' => false, 'error' => "Child node not found at index {$finalIndex}"];
        }
        $removedNode = $ref['children'][$finalIndex];
        array_splice($ref['children'], $finalIndex, 1);
        
        // If children is now empty, we could optionally remove it
        // but let's keep it for consistency
    }
    
    return ['success' => true, 'structure' => $structure, 'node' => $removedNode];
}

/**
 * Insert a node into the structure at the given indices path
 */
function insertNodeIntoStructure(array $structure, array $indices, array $node): array {
    if (empty($indices)) {
        return ['success' => false, 'error' => 'Empty indices'];
    }
    
    // Detect if root is a single object (component) or array of nodes (page)
    $isRootObject = isset($structure['tag']);
    
    $ref = &$structure;
    
    for ($i = 0; $i < count($indices) - 1; $i++) {
        $idx = $indices[$i];
        
        if ($i === 0) {
            // First level
            if ($isRootObject) {
                // Component: first index accesses children directly
                if (!isset($ref['children'][$idx])) {
                    return ['success' => false, 'error' => "Parent node not found at index {$idx}"];
                }
                $ref = &$ref['children'][$idx];
            } else {
                // Page: direct array access
                if (!isset($ref[$idx])) {
                    return ['success' => false, 'error' => "Parent node not found at index {$idx}"];
                }
                $ref = &$ref[$idx];
            }
        } else {
            // Deeper levels
            if (!isset($ref['children'])) {
                $ref['children'] = [];
            }
            if (!isset($ref['children'][$idx])) {
                return ['success' => false, 'error' => "Parent node not found in children at index {$idx}"];
            }
            $ref = &$ref['children'][$idx];
        }
    }
    
    // Insert at final index
    $finalIndex = end($indices);
    
    if (count($indices) === 1) {
        // Root level insert
        if ($isRootObject) {
            // Component: insert into children array
            if (!isset($structure['children'])) {
                $structure['children'] = [];
            }
            array_splice($structure['children'], $finalIndex, 0, [$node]);
        } else {
            // Page: insert into root array
            array_splice($structure, $finalIndex, 0, [$node]);
        }
    } else {
        // Nested level insert
        if (!isset($ref['children'])) {
            $ref['children'] = [];
        }
        array_splice($ref['children'], $finalIndex, 0, [$node]);
    }
    
    return ['success' => true, 'structure' => $structure];
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_moveNode($params, [])->send();
}
