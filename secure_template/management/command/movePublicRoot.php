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


if (!is_valid_relative_path($relative_path_input, 255, 10)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("The provided destination path is invalid or unsafe")
        ->withErrors([
            ['field' => 'destination', 'value' => $relative_path_input],
            ['constraints' => [
                'max_length' => 255,
                'max_depth' => 10,
                'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore'
            ]]
        ])
        ->send();
}
    
// Define Source and Destination paths
$template_source_path = PUBLIC_FOLDER_SPACE !== '' 
    ? PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . PUBLIC_FOLDER_SPACE 
    : PUBLIC_FOLDER_ROOT;
    
$target_destination_path = $relative_path_input !== '' 
    ? PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . $relative_path_input 
    : PUBLIC_FOLDER_ROOT;
    
if (!is_dir($template_source_path)) {
  ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Template source folder does not exist")
        ->withData([
            'source_path' => $template_source_path
        ])
        ->send();
}

// Use the recursive copy function to create the new folder AND populate it
if (!recursive_move_template($template_source_path, $target_destination_path, $relative_path_input)) {
  ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to copy template contents")
        ->withData([
            'source' => $template_source_path,
            'destination' => $target_destination_path
        ])
        ->send();
} 
    
// Define the full path to the new directory for convenience
$new_base_path = $target_destination_path; 

// --- START FILE MODIFICATION ---
// 1. Write main .htaccess
$htaccess_path = $new_base_path . DIRECTORY_SEPARATOR . '.htaccess';
$fallback = $relative_path_input !== '' ? '/' . $relative_path_input . '/index.php' : '/index.php';

if (!write_htaccess_fallback($htaccess_path, $fallback)) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write .htaccess file")
        ->withData(['file' => $htaccess_path])
        ->send();
}

// 2. Write management .htaccess
$management_htaccess_path = $new_base_path . DIRECTORY_SEPARATOR . 'management' . DIRECTORY_SEPARATOR . '.htaccess';
$management_fallback = $relative_path_input !== '' ? '/' . $relative_path_input . '/management/index.php' : '/management/index.php';

if (!write_htaccess_fallback($management_htaccess_path, $management_fallback)) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write management/.htaccess file")
        ->withData(['file' => $management_htaccess_path])
        ->send();
}

// 3. Modify $target_destination_path/init.php (PUBLIC_FOLDER_SPACE constant)
$init_path = $new_base_path . DIRECTORY_SEPARATOR . 'init.php';

// The search pattern MUST match exactly what is in your file, including quotes and spaces.
// We are escaping quotes within the search string for literal matching.
$search3 = "define('PUBLIC_FOLDER_SPACE', '".PUBLIC_FOLDER_SPACE."');";
$replace3 = "define('PUBLIC_FOLDER_SPACE', '" . $relative_path_input . "');";


if (!replace_in_file($init_path, $search3, $replace3)) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to update PUBLIC_FOLDER_SPACE in init.php")
        ->withData([
            'file' => $init_path
        ])
        ->send();
}
if(!str_contains($target_destination_path, $template_source_path)){
  cleanup_empty_source_chain($template_source_path, $target_destination_path);
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage("Public root successfully moved")
    ->withData([
        'old_path' => $template_source_path,
        'new_path' => $target_destination_path,
        'destination' => $relative_path_input
    ])
    ->send();