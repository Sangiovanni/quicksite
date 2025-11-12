<?php

require_once SECURE_FOLDER_PATH . '/src/functions/String.php';


if (!array_key_exists('route', $trimParametersManagement->params())) {
  die("ERROR: 'route' parameter is required.\n");
}

$route_name = $trimParametersManagement->params()['route'];


if(!is_valid_route_name($route_name)){
  die("ERROR: The route name '" . htmlspecialchars($route_name) . "' is invalid. Only alphanumeric characters and hyphens (-) are allowed.");
}

if(in_array($route_name, ROUTES)){
  die("ERROR: The route '" . htmlspecialchars($route_name) . "' already exists");
}

// Source: The boilerplate file
$source_file = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'material' . DIRECTORY_SEPARATOR . 'Page.php';

// Destination directory for new templates
$target_dir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages';

// Destination file: Use the route name (e.g., 'about') and ensure it has the .php extension
$target_file = $target_dir . DIRECTORY_SEPARATOR . $route_name . '.php';

if (!file_exists($source_file)) {
  die("FATAL ERROR: Source boilerplate file not found at: " . htmlspecialchars($source_file));
}

if (!is_dir($target_dir)) {
  if (!mkdir($target_dir, 0755, true)) {
    die("FATAL ERROR: Failed to create target directory: " . htmlspecialchars($target_dir));
  }
}

if (!copy($source_file, $target_file)) {
  die("ERROR: Failed to copy template file from source to: " . htmlspecialchars($target_file));
}


$routes_file_path = ROUTES_PATH;
$new_route_name = $route_name;

if (!file_exists($routes_file_path)) {
    die("FATAL ERROR: Routes file not found for modification: " . htmlspecialchars($routes_file_path));
}

$current_routes = ROUTES;

// Append the new route name
$current_routes[] = $new_route_name;

$quoted_routes = array_map(function ($route) {
    return "'" . str_replace("'", "\'", $route) . "'";
}, $current_routes); // Escape any single quotes just in case

$routes_string = implode(', ', $quoted_routes);

$new_file_content = "<?php return [" . $routes_string . "]; ?>";

if (!file_put_contents($routes_file_path, $new_file_content, LOCK_EX) !== false) {
    die("FATAL ERROR: Failed to write updated routes file to disk: " . htmlspecialchars($routes_file_path));
} 

// The complete success message for the whole command
echo "Route '" . htmlspecialchars($new_route_name) . "' successfully created and registered.";