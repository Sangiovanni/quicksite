<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

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

$allowed_types = ['menu', 'footer', 'page'];

if (!in_array($type, $allowed_types)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
        ->withErrors([
            ['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]
        ])
        ->send();
}

// Validate structure is an array
if (!is_array($structure)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Structure must be an array or object")
        ->withErrors([['field' => 'structure', 'reason' => 'must be array/object']])
        ->send();
}

// For pages, name is required
if ($type === 'page') {
    if (!isset($params['name'])) {
        ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'name', 'reason' => 'required for type=page']])
            ->send();
    }
    
    $name = $params['name'];
    
    // Validate page exists
    if (!in_array($name, ROUTES)) {
        ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$name}' does not exist")
            ->withData(['available_routes' => ROUTES])
            ->send();
    }
    
    $json_file = SECURE_FOLDER_PATH . '/templates/model/json/pages/' . $name . '.json';
} else {
    // For menu/footer, use the type directly
    $json_file = SECURE_FOLDER_PATH . '/templates/model/json/' . $type . '.json';
}

// Check file exists
if (!file_exists($json_file)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Structure file not found")
        ->withData(['file' => $json_file])
        ->send();
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
        'name' => $type === 'page' ? $name : null,
        'file' => $json_file,
        'structure_size' => count($structure)
    ])
    ->send();