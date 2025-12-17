<?php
/**
 * createAlias - Create a URL redirect alias
 * Method: POST
 * URL: /management/createAlias
 * 
 * Body (JSON): {
 *     "alias": "/old-url",        // The alias URL path (with leading /)
 *     "target": "/actual-route",  // The target route to redirect to
 *     "type": "redirect"          // Optional: "redirect" (301) or "internal" (transparent). Default: "redirect"
 * }
 * 
 * Creates a URL alias that redirects to another route.
 * Validates that alias doesn't conflict with existing routes.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// Get request body
$rawBody = defined('REQUEST_BODY_RAW') ? REQUEST_BODY_RAW : file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!$body) {
    ApiResponse::create(400, 'api.error.invalid_request')
        ->withMessage('Invalid JSON body')
        ->send();
}

// Validate required fields
$alias = $body['alias'] ?? null;
$target = $body['target'] ?? null;
$type = $body['type'] ?? 'redirect';

if (!$alias || !is_string($alias)) {
    ApiResponse::create(400, 'api.error.missing_parameter')
        ->withMessage('Missing or invalid "alias" parameter')
        ->send();
}

if (!$target || !is_string($target)) {
    ApiResponse::create(400, 'api.error.missing_parameter')
        ->withMessage('Missing or invalid "target" parameter')
        ->send();
}

// Validate type
if (!in_array($type, ['redirect', 'internal'])) {
    ApiResponse::create(400, 'api.error.invalid_parameter')
        ->withMessage('Invalid "type" parameter. Must be "redirect" or "internal"')
        ->send();
}

// Normalize paths (ensure leading slash, remove trailing slash)
$alias = '/' . ltrim($alias, '/');
$alias = rtrim($alias, '/');
if ($alias === '') $alias = '/';

$target = '/' . ltrim($target, '/');
$target = rtrim($target, '/');
if ($target === '') $target = '/';

// Validate alias format
if (!RegexPatterns::match('url_alias', $alias)) {
    ApiResponse::create(400, 'api.error.invalid_parameter')
        ->withMessage('Invalid alias format. Use alphanumeric characters, dashes, underscores, and slashes only')
        ->withErrors([RegexPatterns::validationError('url_alias', 'alias', $alias)])
        ->send();
}

// Cannot alias to itself
if ($alias === $target) {
    ApiResponse::create(400, 'api.error.invalid_parameter')
        ->withMessage('Alias cannot point to itself')
        ->send();
}

// Check alias doesn't conflict with existing routes
$routesFile = SECURE_FOLDER_PATH . '/routes.php';
$routes = file_exists($routesFile) ? require($routesFile) : [];

// Extract alias path segment (first part after /)
$aliasSegment = trim($alias, '/');
if (strpos($aliasSegment, '/') !== false) {
    $aliasSegment = explode('/', $aliasSegment)[0];
}

// Check against routes
if (in_array($aliasSegment, $routes)) {
    ApiResponse::create(409, 'api.error.conflict')
        ->withMessage("Alias '$alias' conflicts with existing route '$aliasSegment'")
        ->send();
}

// Reserved management paths
$reservedPaths = ['management', 'assets', 'build', 'init', 'api'];
if (in_array($aliasSegment, $reservedPaths)) {
    ApiResponse::create(409, 'api.error.conflict')
        ->withMessage("Alias '$alias' uses a reserved path")
        ->send();
}

// Load existing aliases
$aliasesFile = SECURE_FOLDER_PATH . '/config/aliases.json';
$configDir = dirname($aliasesFile);

// Ensure config directory exists
if (!is_dir($configDir)) {
    mkdir($configDir, 0755, true);
}

$aliases = [];
if (file_exists($aliasesFile)) {
    $content = file_get_contents($aliasesFile);
    $aliases = json_decode($content, true) ?: [];
}

// Check if alias already exists
if (isset($aliases[$alias])) {
    ApiResponse::create(409, 'api.error.conflict')
        ->withMessage("Alias '$alias' already exists. Use deleteAlias first to modify it.")
        ->send();
}

// Verify target route exists (either as a route or page)
$targetSegment = trim($target, '/');
if (strpos($targetSegment, '/') !== false) {
    $targetSegment = explode('/', $targetSegment)[0];
}

$targetIsRoute = in_array($targetSegment, $routes);
$targetIsPage = file_exists(SECURE_FOLDER_PATH . '/templates/model/json/pages/' . $targetSegment . '.json');
$targetIsAlias = isset($aliases[$target]);

if (!$targetIsRoute && !$targetIsPage && !$targetIsAlias && $target !== '/') {
    ApiResponse::create(400, 'api.error.invalid_parameter')
        ->withMessage("Target '$target' does not exist as a route or page")
        ->send();
}

// Add alias
$aliases[$alias] = [
    'target' => $target,
    'type' => $type,
    'created' => date('Y-m-d H:i:s')
];

// Save aliases
$json = json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($aliasesFile, $json) === false) {
    ApiResponse::create(500, 'api.error.write_failed')
        ->withMessage('Failed to save aliases')
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage("Alias '$alias' created successfully")
    ->withData([
        'alias' => $alias,
        'target' => $target,
        'type' => $type,
        'redirect_code' => $type === 'redirect' ? 301 : null
    ])
    ->send();
