<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

if (!array_key_exists('destination', $trimParametersManagement->params())) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([
            ['field' => 'destination', 'reason' => 'missing']
        ])
        ->send();
}
    
$relative_path_input = $trimParametersManagement->params()['destination'];

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

// Check if destination already exists (sibling folder conflict)
if (file_exists($target_destination_path)) {
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
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Secure template source folder does not exist")
        ->withData([
            'source_path' => $template_source_path
        ])
        ->send();
}

// Use the recursive move function
if (!recursive_move_template($template_source_path, $target_destination_path, $relative_path_input)) {
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

// Update SECURE_FOLDER_NAME constant
$search = "define('SECURE_FOLDER_NAME', '" . SECURE_FOLDER_NAME . "');";
$replace = "define('SECURE_FOLDER_NAME', '" . $relative_path_input . "');";

if (!replace_in_file($init_path, $search, $replace)) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to update SECURE_FOLDER_NAME in init.php")
        ->withData([
            'file' => $init_path,
            'old_value' => SECURE_FOLDER_NAME,
            'new_value' => $relative_path_input
        ])
        ->send();
}

// Cleanup empty source directories
if (!str_contains($target_destination_path, $template_source_path)) {
    cleanup_empty_source_chain($template_source_path, $target_destination_path);
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage("Secure root successfully moved")
    ->withData([
        'old_path' => $template_source_path,
        'new_path' => $target_destination_path,
        'old_name' => SECURE_FOLDER_NAME,
        'new_name' => $relative_path_input,
        'init_file_updated' => $init_path
    ])
    ->send();