<?php
/**
 * Rename Public Folder Command
 * 
 * Renames the public folder at server root level (e.g., public_template → www).
 * Updates all internal references. Requires Apache/web server config update after rename.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';

if (!array_key_exists('destination', $trimParametersManagement->params())) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('destination parameter is required')
        ->withData([
            'required_fields' => ['destination'],
            'note' => 'New name for the public folder (e.g., "www", "public", "web")'
        ])
        ->send();
}
    
$new_folder_name = $trimParametersManagement->params()['destination'];

// Validate type: destination must be a string
if (!is_string($new_folder_name)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('destination must be a string')
        ->withData([
            'field' => 'destination',
            'expected_type' => 'string',
            'received_type' => gettype($new_folder_name)
        ])
        ->send();
}

// Validate: must not be empty
if ($new_folder_name === '') {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Public folder name cannot be empty")
        ->withErrors([
            ['field' => 'destination', 'reason' => 'empty_not_allowed']
        ])
        ->send();
}

// Validate path: max_depth = 1 (single folder name only)
if (!is_valid_relative_path($new_folder_name, 255, 1, false)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Public folder must be a single folder name (no subdirectories)")
        ->withErrors([
            ['field' => 'destination', 'value' => $new_folder_name],
            ['constraints' => [
                'max_length' => 255,
                'max_depth' => 1,
                'allowed_chars' => 'a-z, A-Z, 0-9, dot, hyphen, underscore',
                'no_subdirectories' => true
            ]]
        ])
        ->send();
}

// Get current public folder name from PUBLIC_FOLDER_ROOT
$current_public_folder_name = basename(PUBLIC_FOLDER_ROOT);

// Check if same name
if ($new_folder_name === $current_public_folder_name) {
    ApiResponse::create(409, 'conflict.same_path')
        ->withMessage("Source and destination are the same")
        ->withData([
            'current_name' => $current_public_folder_name,
            'requested_name' => $new_folder_name
        ])
        ->send();
}

// Define Source and Destination paths
$source_path = PUBLIC_FOLDER_ROOT;
$target_path = SERVER_ROOT . DIRECTORY_SEPARATOR . $new_folder_name;

// === CRITICAL SECTION: Use file lock to prevent race conditions ===
$lock = acquireLock('renamePublicFolder');

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage("Another rename operation is in progress. Please wait and try again.")
        ->send();
}

// Check if destination already exists (sibling folder conflict)
if (file_exists($target_path)) {
    releaseLock($lock);
    
    ApiResponse::create(409, 'conflict.duplicate')
        ->withMessage("A folder with this name already exists")
        ->withData([
            'conflicting_path' => $target_path,
            'destination' => $new_folder_name
        ])
        ->send();
}

// Check source exists
if (!is_dir($source_path)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Public folder source does not exist")
        ->withData([
            'source_path' => $source_path
        ])
        ->send();
}

// Rename the folder
if (!rename($source_path, $target_path)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to rename public folder")
        ->withData([
            'source' => $source_path,
            'destination' => $target_path,
            'hint' => 'Check file permissions and ensure no files are in use'
        ])
        ->send();
}

// Update init.php - change PUBLIC_FOLDER_NAME constant
$init_path = PUBLIC_FOLDER_SPACE !== '' 
    ? $target_path . DIRECTORY_SEPARATOR . PUBLIC_FOLDER_SPACE . DIRECTORY_SEPARATOR . 'init.php'
    : $target_path . DIRECTORY_SEPARATOR . 'init.php';

// Update PUBLIC_FOLDER_NAME in init.php
if (file_exists($init_path)) {
    $init_content = file_get_contents($init_path);
    if ($init_content !== false) {
        // Replace the PUBLIC_FOLDER_NAME definition
        $init_content = preg_replace(
            "/define\\('PUBLIC_FOLDER_NAME',\\s*'[^']*'\\)/",
            "define('PUBLIC_FOLDER_NAME', '" . $new_folder_name . "')",
            $init_content
        );
        file_put_contents($init_path, $init_content);
    }
}

// Release lock - operation complete
releaseLock($lock);

// === CRITICAL: Send response and exit immediately ===
// After renaming the folder, all paths become invalid
// We must send the response and terminate
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

$response_data = [
    'status' => 200,
    'code' => 'operation.success',
    'message' => 'Public folder successfully renamed',
    'data' => [
        'old_path' => $source_path,
        'new_path' => $target_path,
        'old_name' => $current_public_folder_name,
        'new_name' => $new_folder_name,
        'warning' => 'You must update your Apache/web server DocumentRoot to point to: ' . $target_path,
        'next_steps' => [
            '1. Update Apache VirtualHost DocumentRoot to: ' . $target_path,
            '2. Restart Apache/web server',
            '3. Access site at new URL'
        ]
    ]
];

echo json_encode($response_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// CRITICAL: Exit immediately
exit(0);
