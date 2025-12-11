<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

if (!array_key_exists('route', $trimParametersManagement->params())) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([
            ['field' => 'route', 'reason' => 'missing']
        ])
        ->send();
}

// Type validation
if (!is_string($trimParametersManagement->params()['route'])) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The route parameter must be a string.')
        ->withErrors([
            ['field' => 'route', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

$route_name = $trimParametersManagement->params()['route'];

// Length validation
if (strlen($route_name) < 1 || strlen($route_name) > 100) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('The route name must be between 1 and 100 characters.')
        ->withErrors([
            ['field' => 'route', 'value' => $route_name, 'min_length' => 1, 'max_length' => 100]
        ])
        ->send();
}

// Validate route name
if (!is_valid_route_name($route_name)) {
    ApiResponse::create(400, 'route.invalid_name')
        ->withMessage("The route name '{$route_name}' is invalid. Only lowercase letters, numbers, and hyphens are allowed.")
        ->withErrors([
            ['field' => 'route', 'value' => $route_name, 'pattern' => 'a-z, 0-9, hyphen']
        ])
        ->send();
}

// Check if route already exists
if (in_array($route_name, ROUTES)) {
    ApiResponse::create(400, 'route.already_exists')
        ->withMessage("The route '{$route_name}' already exists")
        ->withData([
            'route' => $route_name,
            'existing_routes' => ROUTES
        ])
        ->send();
}

// --- CREATE PHP PAGE FILE ---
$target_dir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages';
$target_file = $target_dir . DIRECTORY_SEPARATOR . $route_name . '.php';

// Ensure directory exists
if (!is_dir($target_dir)) {
    if (!mkdir($target_dir, 0755, true)) {
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage("Failed to create pages directory")
            ->withData(['directory' => $target_dir])
            ->send();
    }
}

// Generate page template content
$page_content = generate_page_template($route_name);

// Write PHP page file
if (file_put_contents($target_file, $page_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to create page template file")
        ->withData(['file' => $target_file])
        ->send();
}

// --- CREATE JSON STRUCTURE FILE ---
$json_dir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'json' . DIRECTORY_SEPARATOR . 'pages';
$json_file = $json_dir . DIRECTORY_SEPARATOR . $route_name . '.json';

// Ensure JSON directory exists
if (!is_dir($json_dir)) {
    if (!mkdir($json_dir, 0755, true)) {
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage("Failed to create JSON pages directory")
            ->withData(['directory' => $json_dir])
            ->send();
    }
}

// Generate and write JSON structure
$json_content = generate_page_json($route_name);

if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
    // Cleanup: remove the PHP file we just created
    @unlink($target_file);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to create page JSON file")
        ->withData(['file' => $json_file])
        ->send();
}

// --- UPDATE ROUTES FILE ---
$routes_file_path = ROUTES_PATH;

if (!file_exists($routes_file_path)) {
    // Cleanup
    @unlink($target_file);
    @unlink($json_file);
    
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Routes file not found")
        ->withData(['file' => $routes_file_path])
        ->send();
}

$current_routes = ROUTES;
$current_routes[] = $route_name;

// Use var_export for safer array generation (prevents injection)
$new_file_content = "<?php return " . var_export($current_routes, true) . "; ?>";

if (file_put_contents($routes_file_path, $new_file_content, LOCK_EX) === false) {
    // Cleanup
    @unlink($target_file);
    @unlink($json_file);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to update routes file")
        ->withData(['file' => $routes_file_path])
        ->send();
}

// Invalidate opcode cache for routes file
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($routes_file_path, true);
}

// Success!
ApiResponse::create(201, 'route.created')
    ->withMessage("Route '{$route_name}' successfully created and registered")
    ->withData([
        'route' => $route_name,
        'php_file' => $target_file,
        'json_file' => $json_file,
        'routes_updated' => $routes_file_path
        ])
    ->send();