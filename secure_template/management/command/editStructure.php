<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['type']) || !isset($params['structure'])) {
    $missing = [];
    if (!isset($params['type'])) $missing[] = 'type';
    if (!isset($params['structure'])) $missing[] = 'structure';
    
    ApiResponse::create(400, 'validation.required')
        ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
        ->send();
}

$type = $params['type'];
$structure = $params['structure'];

$allowed_types = ['menu', 'footer', 'page', 'component'];

if (!in_array($type, $allowed_types)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
        ->withErrors([
            ['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]
        ])
        ->send();
}

// Validate structure is an array (or object for components)
if (!is_array($structure)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Structure must be an array or object")
        ->withErrors([['field' => 'structure', 'reason' => 'must be array/object']])
        ->send();
}

// SECURITY: Check structure size (prevent memory exhaustion)
$nodeCount = 0;
if (is_array($structure)) {
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

if ($nodeCount > 10000) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Structure too large (max 10,000 nodes)")
        ->withData(['node_count' => $nodeCount, 'max_allowed' => 10000])
        ->send();
}

// SECURITY: Check structure depth (prevent stack overflow)
if (is_array($structure)) {
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
    
    // Validate page exists (only for pages, not components - components can be created)
    if ($type === 'page' && !in_array($name, ROUTES)) {
        ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$name}' does not exist")
            ->withData(['available_routes' => ROUTES])
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