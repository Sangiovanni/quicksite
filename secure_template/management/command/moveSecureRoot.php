<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';

if (!array_key_exists('destination', $trimParametersManagement->params())) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('destination parameter is required')
        ->withData([
            'required_fields' => ['destination']
        ])
        ->send();
}
    
$relative_path_input = $trimParametersManagement->params()['destination'];

// Validate type: destination must be a string
if (!is_string($relative_path_input)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('destination must be a string')
        ->withData([
            'field' => 'destination',
            'expected_type' => 'string',
            'received_type' => gettype($relative_path_input)
        ])
        ->send();
}

// Validate: must be single folder name (depth = 1), not empty for secure folder
if ($relative_path_input === '') {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Secure folder name cannot be empty")
        ->withErrors([
            ['field' => 'destination', 'reason' => 'empty_not_allowed']
        ])
        ->send();
}

// Validate path: max_depth = 1 (single folder name only)
if (!is_valid_relative_path($relative_path_input, 255, 1, false)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Secure folder must be a single folder name (no subdirectories)")
        ->withErrors([
            ['field' => 'destination', 'value' => $relative_path_input],
            ['constraints' => [
                'max_length' => 255,
                'max_depth' => 1,
                'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore',
                'no_subdirectories' => true
            ]]
        ])
        ->send();
}

// Define Source and Destination paths
$template_source_path = SERVER_ROOT . DIRECTORY_SEPARATOR . SECURE_FOLDER_NAME;
$target_destination_path = SERVER_ROOT . DIRECTORY_SEPARATOR . $relative_path_input;

// === CRITICAL SECTION: Use file lock to prevent race conditions ===
// Acquire exclusive lock (blocks other processes)
$lock = acquireLock('moveSecureRoot');

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage("Another move operation is in progress. Please wait and try again.")
        ->send();
}

// Now we have exclusive access - recheck conditions inside lock
// Check if destination already exists (sibling folder conflict)
if (file_exists($target_destination_path)) {
    releaseLock($lock);
    
    ApiResponse::create(409, 'conflict.duplicate')
        ->withMessage("A folder with this name already exists")
        ->withData([
            'conflicting_path' => $target_destination_path,
            'destination' => $relative_path_input
        ])
        ->send();
}

// Check source exists
if (!is_dir($template_source_path)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Secure template source folder does not exist")
        ->withData([
            'source_path' => $template_source_path
        ])
        ->send();
}

// Use the recursive move function (still inside lock)
if (!recursive_move_template($template_source_path, $target_destination_path, $relative_path_input)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to move secure template contents")
        ->withData([
            'source' => $template_source_path,
            'destination' => $target_destination_path
        ])
        ->send();
}

// --- MODIFY PUBLIC INIT.PHP ---
// Build init.php path
$init_path = PUBLIC_FOLDER_SPACE !== '' 
    ? PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . PUBLIC_FOLDER_SPACE . DIRECTORY_SEPARATOR . 'init.php'
    : PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . 'init.php';

// Update SECURE_FOLDER_NAME constant (still inside lock)
$search = "define('SECURE_FOLDER_NAME', '" . SECURE_FOLDER_NAME . "');";
$replace = "define('SECURE_FOLDER_NAME', '" . $relative_path_input . "');";

if (!replace_in_file($init_path, $search, $replace)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to update SECURE_FOLDER_NAME in init.php")
        ->withData([
            'file' => $init_path,
            'old_value' => SECURE_FOLDER_NAME,
            'new_value' => $relative_path_input
        ])
        ->send();
}

// Cleanup empty source directories (still inside lock)
if (!str_contains($target_destination_path, $template_source_path)) {
    cleanup_empty_source_chain($template_source_path, $target_destination_path);
}

// Release lock - operation complete
releaseLock($lock);

// === CRITICAL: Send response and exit immediately ===
// After moving the folder, all paths using SECURE_FOLDER_NAME become invalid
// We must send the response and terminate to prevent any code from trying
// to load files from the old (now non-existent) secure folder path
// 
// Use explicit JSON output + exit to bypass any potential autoloading/shutdown functions
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

$response_data = [
    'status' => 200,
    'code' => 'operation.success',
    'message' => 'Secure root successfully moved',
    'data' => [
        'old_path' => $template_source_path,
        'new_path' => $target_destination_path,
        'old_name' => SECURE_FOLDER_NAME,
        'new_name' => $relative_path_input,
        'init_file_updated' => $init_path
    ]
];

echo json_encode($response_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// CRITICAL: Exit immediately to prevent any further execution
// that might try to access the old secure folder path
exit(0);