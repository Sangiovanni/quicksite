<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';

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

// Length validation (consistent with addRoute, prevents DoS)
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

// Check if route exists
if (!in_array($route_name, ROUTES)) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("The route '{$route_name}' does not exist")
        ->withData([
            'route' => $route_name,
            'existing_routes' => ROUTES
        ])
        ->send();
}

// --- DELETE PHP PAGE FILE ---
$target_dir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages';
$target_file = $target_dir . DIRECTORY_SEPARATOR . $route_name . '.php';

if (!file_exists($target_file)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage("Page template file not found")
        ->withData(['file' => $target_file])
        ->send();
}

if (!unlink($target_file)) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to delete page template file")
        ->withData(['file' => $target_file])
        ->send();
}

// --- DELETE JSON STRUCTURE FILE ---
$json_dir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'json' . DIRECTORY_SEPARATOR . 'pages';
$json_file = $json_dir . DIRECTORY_SEPARATOR . $route_name . '.json';

// Try to delete JSON file (might not exist if created before JSON system)
if (file_exists($json_file)) {
    if (!unlink($json_file)) {
        // Non-fatal: PHP file already deleted, so we continue
        error_log("Warning: Failed to delete JSON file: {$json_file}");
    }
}

// --- UPDATE ROUTES FILE ---
$routes_file_path = ROUTES_PATH;

if (!file_exists($routes_file_path)) {
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Routes file not found")
        ->withData(['file' => $routes_file_path])
        ->send();
}

$current_routes = ROUTES;

// Remove the route
$key = array_search($route_name, $current_routes);
if ($key !== false) {
    unset($current_routes[$key]);
}

// Re-index array
$current_routes = array_values($current_routes);

// Use var_export for safer array generation (prevents injection)
$new_file_content = "<?php return " . var_export($current_routes, true) . "; ?>";

if (file_put_contents($routes_file_path, $new_file_content, LOCK_EX) === false) {
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
ApiResponse::create(200, 'route.deleted')
    ->withMessage("Route '{$route_name}' successfully deleted")
    ->withData([
        'route' => $route_name,
        'deleted_files' => [
            'php' => $target_file,
            'json' => file_exists($json_file) ? null : $json_file // null if already deleted
        ],
        'routes_updated' => $routes_file_path
    ])
    ->send();