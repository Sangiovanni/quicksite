<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';

// Get URL segments: /management/getStructure/{type}/{name?}/{nodeId|showIds}
$urlSegments = $trimParametersManagement->additionalParams();

// Validate type parameter (first segment)
if (empty($urlSegments) || !isset($urlSegments[0])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage("Type parameter missing from URL")
        ->withErrors([['field' => 'type', 'reason' => 'missing', 'usage' => 'GET /management/getStructure/{type}/{name?}']])
        ->send();
}

$type = $urlSegments[0];

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

// For pages and components, name is the second URL segment
if ($type === 'page' || $type === 'component') {
    if (!isset($urlSegments[1]) || empty($urlSegments[1])) {
        ApiResponse::create(400, 'validation.required')
            ->withMessage("Name required in URL for type={$type}")
            ->withErrors([['field' => 'name', 'reason' => 'missing', 'usage' => "GET /management/getStructure/{$type}/{name}"]])
            ->send();
    }
    
    $name = $urlSegments[1];
    
    // Type validation - name must be string (allow numeric for routes like "404")
    if (is_int($name) || is_float($name)) {
        $name = (string) $name;
    }
    
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
    
    // Check for path traversal attempts in name (BEFORE other validations)
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
    
    // Validate name format (alphanumeric, hyphens, underscores) for both pages and components
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid name format. Use only alphanumeric, hyphens, and underscores")
            ->withErrors([['field' => 'name', 'value' => $name, 'type' => $type]])
            ->send();
    }
    
    // Special pages that exist but are not in ROUTES (error pages, etc.)
    $specialPages = ['404', '500', '403', '401'];
    
    // Validate page exists (only for pages, not components)
    // Allow special pages (404, 500, etc.) even if not in ROUTES
    if ($type === 'page' && !in_array($name, ROUTES, true) && !in_array($name, $specialPages, true)) {
        ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$name}' does not exist")
            ->withData(['available_routes' => ROUTES, 'special_pages' => $specialPages])
            ->send();
    }
    
    // Build file path based on type
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

// Check file exists
if (!file_exists($json_file)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Structure file not found")
        ->withData(['file' => $json_file])
        ->send();
}

// Read and decode JSON
$json_content = @file_get_contents($json_file);
if ($json_content === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read structure file")
        ->send();
}

$structure = json_decode($json_content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Invalid JSON in structure file: " . json_last_error_msg())
        ->send();
}

// Check for additional parameters: nodeId or showIds
// For page/component: urlSegments[2] would be the option
// For menu/footer: urlSegments[1] would be the option
$optionSegment = ($type === 'page' || $type === 'component') 
    ? ($urlSegments[2] ?? null) 
    : ($urlSegments[1] ?? null);

$showIds = false;
$targetNodeId = null;
$summaryMode = false;

if ($optionSegment !== null) {
    if ($optionSegment === 'showIds') {
        $showIds = true;
    } elseif ($optionSegment === 'summary') {
        $summaryMode = true;
    } elseif (preg_match('/^[0-9]+(\.[0-9]+)*$/', $optionSegment)) {
        // It's a nodeId - retrieve specific node
        $targetNodeId = $optionSegment;
    } else {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid option. Use 'showIds', 'summary', or a node identifier (e.g., '0.2.1')")
            ->withErrors([['field' => 'option', 'value' => $optionSegment]])
            ->send();
    }
}

// Handle summary mode - returns simplified tree view
if ($summaryMode) {
    $summary = NodeNavigator::getSummary($structure);
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Structure summary retrieved successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'summary' => $summary,
            'note' => 'Use _nodeId values with editStructure for targeted edits'
        ])
        ->send();
}

// Handle specific node retrieval
if ($targetNodeId !== null) {
    $node = NodeNavigator::getNode($structure, $targetNodeId);
    
    if ($node === null) {
        ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found at identifier: {$targetNodeId}")
            ->withErrors([['field' => 'nodeId', 'value' => $targetNodeId]])
            ->send();
    }
    
    // Add nodeId to the returned node
    $nodeWithId = NodeNavigator::addNodeIds($node, $targetNodeId);
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Node retrieved successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'nodeId' => $targetNodeId,
            'node' => $nodeWithId
        ])
        ->send();
}

// Handle showIds - add _nodeId to all nodes
if ($showIds) {
    $structure = NodeNavigator::addNodeIds($structure);
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Structure retrieved successfully')
    ->withData([
        'type' => $type,
        'name' => $name,
        'structure' => $structure,
        'file' => $json_file,
        'nodeIds' => $showIds ? 'included' : 'not included (add /showIds to URL)'
    ])
    ->send();