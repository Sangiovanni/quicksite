<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Get URL segments: /management/getStructure/{type}/{name?}
$urlSegments = $trimParametersManagement->additionalParams();

// Validate type parameter (first segment)
if (empty($urlSegments) || !isset($urlSegments[0])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage("Type parameter missing from URL")
        ->withErrors([['field' => 'type', 'reason' => 'missing', 'usage' => 'GET /management/getStructure/{type}/{name?}']])
        ->send();
}

$type = $urlSegments[0];
$allowed_types = ['menu', 'footer', 'page', 'component'];

if (!in_array($type, $allowed_types)) {
    ApiResponse::create(400, 'validation.invalid_format')
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
    
    // Validate page exists (only for pages, not components)
    if ($type === 'page' && !in_array($name, ROUTES)) {
        ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$name}' does not exist")
            ->withData(['available_routes' => ROUTES])
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

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Structure retrieved successfully')
    ->withData([
        'type' => $type,
        'name' => $name,
        'structure' => $structure,
        'file' => $json_file
    ])
    ->send();