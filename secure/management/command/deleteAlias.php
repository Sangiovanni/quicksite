<?php
/**
 * deleteAlias - Delete a URL redirect alias
 * Method: POST
 * URL: /management/deleteAlias
 * 
 * Body (JSON): {
 *     "alias": "/old-url"  // The alias to delete (with leading /)
 * }
 * 
 * Deletes an existing URL alias.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Get request body
$body = json_decode(file_get_contents('php://input'), true);

if (!$body) {
    ApiResponse::create(400, 'api.error.invalid_request')
        ->withMessage('Invalid JSON body')
        ->send();
}

// Validate required field
$alias = $body['alias'] ?? null;

if (!$alias || !is_string($alias)) {
    ApiResponse::create(400, 'api.error.missing_parameter')
        ->withMessage('Missing or invalid "alias" parameter')
        ->send();
}

// Normalize path
$alias = '/' . ltrim($alias, '/');
$alias = rtrim($alias, '/');
if ($alias === '') $alias = '/';

// Load existing aliases
$aliasesFile = SECURE_FOLDER_PATH . '/config/aliases.json';

if (!file_exists($aliasesFile)) {
    ApiResponse::create(404, 'api.error.not_found')
        ->withMessage("Alias '$alias' not found (no aliases exist)")
        ->send();
}

$content = file_get_contents($aliasesFile);
$aliases = json_decode($content, true) ?: [];

// Check if alias exists
if (!isset($aliases[$alias])) {
    ApiResponse::create(404, 'api.error.not_found')
        ->withMessage("Alias '$alias' not found")
        ->withData([
            'available_aliases' => array_keys($aliases)
        ])
        ->send();
}

// Get alias info before deletion
$deletedAlias = $aliases[$alias];

// Remove alias
unset($aliases[$alias]);

// Save aliases
$json = json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($aliasesFile, $json) === false) {
    ApiResponse::create(500, 'api.error.write_failed')
        ->withMessage('Failed to save aliases')
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage("Alias '$alias' deleted successfully")
    ->withData([
        'deleted' => [
            'alias' => $alias,
            'target' => $deletedAlias['target'],
            'type' => $deletedAlias['type']
        ],
        'remaining_count' => count($aliases)
    ])
    ->send();
