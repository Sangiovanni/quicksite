<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';

$params = $trimParametersManagement->params();

// Check for nodeId-based edit mode
$nodeId = $params['nodeId'] ?? null;
$action = $params['action'] ?? 'update'; // 'update', 'delete', 'insertBefore', 'insertAfter'

// Validate required parameters
// For nodeId mode: type, name (for page/component), structure (except delete)
// For full mode: type, structure
if (!isset($params['type'])) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([['field' => 'type', 'reason' => 'missing']])
        ->send();
}

// Structure is required unless action is 'delete'
if (!isset($params['structure']) && $action !== 'delete') {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([['field' => 'structure', 'reason' => 'missing (required unless action is delete)']])
        ->send();
}

$type = $params['type'];
$structure = $params['structure'] ?? null;

// Type validation - type must be string
if (!is_string($type)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The type parameter must be a string.')
        ->withErrors([
            ['field' => 'type', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

$allowed_types = ['menu', 'footer', 'page', 'component'];

// Dynamic length validation for type
$maxTypeLength = max(array_map('strlen', $allowed_types));
if (strlen($type) > $maxTypeLength) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage("The type parameter must not exceed {$maxTypeLength} characters.")
        ->withErrors([
            ['field' => 'type', 'value' => $type, 'max_length' => $maxTypeLength]
        ])
        ->send();
}

if (!in_array($type, $allowed_types, true)) {
    ApiResponse::create(400, 'validation.invalid_value')
        ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
        ->withErrors([
            ['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]
        ])
        ->send();
}

// Validate structure is an array (skip for delete action with nodeId)
if ($structure !== null && !is_array($structure)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Structure must be an array or object")
        ->withErrors([['field' => 'structure', 'reason' => 'must be array/object']])
        ->send();
}

// SECURITY: Check structure size (prevent memory exhaustion) - skip for delete
$nodeCount = 0;
if ($structure !== null && is_array($structure)) {
    // For pages/menu/footer (arrays of nodes)
    if (isset($structure[0]) || empty($structure)) {
        foreach ($structure as $node) {
            $nodeCount += countNodes($node);
        }
    } else {
        // For components (single object node)
        $nodeCount = countNodes($structure);
    }
}

if ($structure !== null && $nodeCount > 10000) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Structure too large (max 10,000 nodes)")
        ->withData(['node_count' => $nodeCount, 'max_allowed' => 10000])
        ->send();
}

// SECURITY: Check structure depth (prevent stack overflow) - skip for delete
if ($structure !== null && is_array($structure)) {
    // For pages/menu/footer
    if (isset($structure[0]) || empty($structure)) {
        foreach ($structure as $node) {
            if (!validateStructureDepth($node, 0, 50)) {
                ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage("Structure too deeply nested (max 50 levels)")
                    ->withErrors([['field' => 'structure', 'reason' => 'exceeds max depth of 50']])
                    ->send();
            }
        }
    } else {
        // For components (single object)
        if (!validateStructureDepth($structure, 0, 50)) {
            ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage("Structure too deeply nested (max 50 levels)")
                ->withErrors([['field' => 'structure', 'reason' => 'exceeds max depth of 50']])
                ->send();
        }
    }
}

// For pages and components, name is required
if ($type === 'page' || $type === 'component') {
    if (!isset($params['name'])) {
        ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'name', 'reason' => "required for type={$type}"]])
            ->send();
    }
    
    $name = $params['name'];
    
    // Type validation - name must be string
    if (!is_string($name)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The name parameter must be a string.')
            ->withErrors([
                ['field' => 'name', 'reason' => 'invalid_type', 'expected' => 'string']
            ])
            ->send();
    }
    
    // Length validation - max 100 characters for name
    if (strlen($name) > 100) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage("The name parameter must not exceed 100 characters.")
            ->withErrors([
                ['field' => 'name', 'value' => $name, 'max_length' => 100]
            ])
            ->send();
    }
    
    // Validate page exists (only for pages, not components - components can be created)
    if ($type === 'page' && !in_array($name, ROUTES, true)) {
        ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$name}' does not exist")
            ->withData(['available_routes' => ROUTES])
            ->send();
    }
    
    // Check for path traversal attempts in name
    if (strpos($name, '..') !== false || 
        strpos($name, '/') !== false || 
        strpos($name, '\\') !== false ||
        strpos($name, "\0") !== false) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Name contains invalid path characters')
            ->withErrors([
                ['field' => 'name', 'reason' => 'path_traversal_attempt']
            ])
            ->send();
    }
    
    // Validate component name format (alphanumeric, hyphens, underscores)
    if ($type === 'component' && !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid component name. Use only alphanumeric, hyphens, and underscores")
            ->withErrors([['field' => 'name', 'value' => $name]])
            ->send();
    }
    
    // Build file path
    if ($type === 'page') {
        $json_file = SECURE_FOLDER_PATH . '/templates/model/json/pages/' . $name . '.json';
    } else { // component
        $json_file = SECURE_FOLDER_PATH . '/templates/model/json/components/' . $name . '.json';
    }
} else {
    // For menu/footer, use the type directly
    $json_file = SECURE_FOLDER_PATH . '/templates/model/json/' . $type . '.json';
    $name = null;
}

// Check file exists (except for new components)
if (!file_exists($json_file) && $type !== 'component') {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Structure file not found")
        ->withData(['file' => $json_file])
        ->send();
}

// For components with empty structure, delete the component file
if ($type === 'component' && is_array($structure) && empty($structure)) {
    if (file_exists($json_file)) {
        if (!unlink($json_file)) {
            ApiResponse::create(500, 'server.file_delete_failed')
                ->withMessage("Failed to delete component file")
                ->withData(['file' => $json_file])
                ->send();
        }
        
        // Success - component deleted
        ApiResponse::create(200, 'operation.success')
            ->withMessage('Component deleted successfully')
            ->withData([
                'type' => $type,
                'name' => $name,
                'deleted' => true
            ])
            ->send();
    } else {
        // Component doesn't exist, nothing to delete
        ApiResponse::create(404, 'file.not_found')
            ->withMessage("Component '{$name}' does not exist")
            ->send();
    }
}

// For components, ensure directory exists
if ($type === 'component') {
    $componentDir = dirname($json_file);
    if (!is_dir($componentDir)) {
        if (!mkdir($componentDir, 0755, true)) {
            ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage("Failed to create components directory")
                ->send();
        }
    }
}

// ===== NodeId-based targeted edit mode =====
if ($nodeId !== null) {
    // Validate nodeId format
    if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $nodeId)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid nodeId format. Use dot notation like '0.2.1'")
            ->withErrors([['field' => 'nodeId', 'value' => $nodeId]])
            ->send();
    }
    
    // Validate action
    $allowedActions = ['update', 'delete', 'insertBefore', 'insertAfter'];
    if (!in_array($action, $allowedActions, true)) {
        ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid action. Must be one of: " . implode(', ', $allowedActions))
            ->withErrors([['field' => 'action', 'value' => $action, 'allowed' => $allowedActions]])
            ->send();
    }
    
    // Read existing structure
    if (!file_exists($json_file)) {
        ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found")
            ->withData(['file' => $json_file])
            ->send();
    }
    
    $existingContent = @file_get_contents($json_file);
    if ($existingContent === false) {
        ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage("Failed to read structure file")
            ->send();
    }
    
    $existingStructure = json_decode($existingContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON in structure file: " . json_last_error_msg())
            ->send();
    }
    
    // Perform the targeted operation
    $result = null;
    
    switch ($action) {
        case 'delete':
            $result = NodeNavigator::updateNode($existingStructure, $nodeId, null);
            break;
            
        case 'update':
            $result = NodeNavigator::updateNode($existingStructure, $nodeId, $structure);
            break;
            
        case 'insertBefore':
            $result = NodeNavigator::insertNode($existingStructure, $nodeId, $structure, 'before');
            $result['action'] = 'inserted';
            break;
            
        case 'insertAfter':
            $result = NodeNavigator::insertNode($existingStructure, $nodeId, $structure, 'after');
            $result['action'] = 'inserted';
            break;
    }
    
    if (!$result['success']) {
        ApiResponse::create(400, 'operation.failed')
            ->withMessage($result['error'] ?? 'Failed to perform node operation')
            ->withErrors([['field' => 'nodeId', 'value' => $nodeId, 'action' => $action]])
            ->send();
    }
    
    // Use the modified structure
    $structure = $result['structure'];
    
    // Encode and write
    $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to encode structure to JSON")
            ->send();
    }
    
    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write structure file")
            ->send();
    }
    
    // Success for nodeId operation
    ApiResponse::create(200, 'operation.success')
        ->withMessage("Node {$result['action']} successfully")
        ->withData([
            'type' => $type,
            'name' => $name,
            'nodeId' => $nodeId,
            'action' => $result['action'],
            'insertedAt' => $result['insertedAt'] ?? null,
            'file' => $json_file
        ])
        ->send();
}

// ===== Full structure replacement mode =====

// Encode structure to JSON
$json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json_content === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to encode structure to JSON")
        ->send();
}

// Write to file
if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write structure file")
        ->withData(['file' => $json_file])
        ->send();
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Structure updated successfully')
    ->withData([
        'type' => $type,
        'name' => $type === 'page' || $type === 'component' ? $name : null,
        'file' => $json_file,
        'structure_size' => is_array($structure) && (isset($structure[0]) || empty($structure)) ? count($structure) : 1,
        'node_count' => $nodeCount,
        'created' => !file_exists($json_file) // Indicates if component was newly created
    ])
    ->send();