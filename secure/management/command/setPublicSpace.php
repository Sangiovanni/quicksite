<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';

if (!array_key_exists('destination', $trimParametersManagement->params())) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([
            ['field' => 'destination', 'reason' => 'missing']
        ])
        ->send();
}
    
$relative_path_input = $trimParametersManagement->params()['destination'];

// Trim any leading/trailing slashes from input
$relative_path_input = trim($relative_path_input, '/\\');

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

// Empty string is ALLOWED for movePublicRoot (means root directory)
// But we validate max_depth=5 for subfolder structure (e.g., 'app/v1/api/public/assets')
if (!is_valid_relative_path($relative_path_input, 255, 5, true)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Public folder path must be valid (max 5 levels deep, e.g., 'app/v1/api/public/assets')")
        ->withErrors([
            ['field' => 'destination', 'value' => $relative_path_input],
            ['constraints' => [
                'max_length' => 255,
                'max_depth' => 5,
                'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore, forward slash',
                'empty_allowed' => true
            ]]
        ])
        ->send();
}
    
// Define Source and Destination paths
// Source: current public space (or root if no space defined)
$template_source_path = PUBLIC_FOLDER_SPACE !== '' 
    ? PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . PUBLIC_FOLDER_SPACE 
    : PUBLIC_FOLDER_ROOT;
    
// Destination: new public space INSIDE PUBLIC_FOLDER_ROOT
// Normalize path separators and remove duplicates
$public_root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, PUBLIC_FOLDER_ROOT), DIRECTORY_SEPARATOR);
$target_destination_path = $relative_path_input !== '' 
    ? $public_root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_path_input)
    : $public_root;

// === CRITICAL SECTION: Use file lock to prevent race conditions ===
// Acquire exclusive lock (blocks other processes)
$lock = acquireLock('movePublicRoot');

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage("Another move operation is in progress. Please wait and try again.")
        ->send();
}

// Now we have exclusive access - check conditions inside lock
if (!is_dir($template_source_path)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Template source folder does not exist")
        ->withData([
            'source_path' => $template_source_path
        ])
        ->send();
}

// Use the recursive copy function to create the new folder AND populate it (still inside lock)
if (!recursive_move_template($template_source_path, $target_destination_path, $relative_path_input)) {
    releaseLock($lock);
    
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
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write .htaccess file")
        ->withData(['file' => $htaccess_path])
        ->send();
}

// 2. Write management .htaccess (still inside lock)
$management_htaccess_path = $new_base_path . DIRECTORY_SEPARATOR . 'management' . DIRECTORY_SEPARATOR . '.htaccess';
$management_fallback = $relative_path_input !== '' ? '/' . $relative_path_input . '/management/index.php' : '/management/index.php';

if (!write_htaccess_fallback($management_htaccess_path, $management_fallback)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write management/.htaccess file")
        ->withData(['file' => $management_htaccess_path])
        ->send();
}

// 3. Write admin .htaccess (still inside lock)
$admin_htaccess_path = $new_base_path . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . '.htaccess';
$admin_fallback = $relative_path_input !== '' ? '/' . $relative_path_input . '/admin/index.php' : '/admin/index.php';

if (is_dir(dirname($admin_htaccess_path))) {
    if (!write_htaccess_fallback($admin_htaccess_path, $admin_fallback)) {
        releaseLock($lock);
        
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write admin/.htaccess file")
            ->withData(['file' => $admin_htaccess_path])
            ->send();
    }
}

// 4. Modify $target_destination_path/init.php (PUBLIC_FOLDER_SPACE constant) - still inside lock
$init_path = $new_base_path . DIRECTORY_SEPARATOR . 'init.php';

// The search pattern MUST match exactly what is in your file, including quotes and spaces.
$search3 = "define('PUBLIC_FOLDER_SPACE', '".PUBLIC_FOLDER_SPACE."');";
$replace3 = "define('PUBLIC_FOLDER_SPACE', '" . $relative_path_input . "');";

if (!replace_in_file($init_path, $search3, $replace3)) {
    releaseLock($lock);
    
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to update PUBLIC_FOLDER_SPACE in init.php")
        ->withData([
            'file' => $init_path
        ])
        ->send();
}

// Cleanup empty source directories (still inside lock)
if(!str_contains($target_destination_path, $template_source_path)){
    cleanup_empty_source_chain($template_source_path, $target_destination_path);
}

// Release lock - operation complete
releaseLock($lock);

// === CRITICAL: Send response and exit immediately ===
// After moving public folder, paths may become invalid
// Use explicit JSON output + exit to prevent any further execution
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

// Normalize paths to forward slashes for clean JSON output (works on both Windows and Linux)
$normalizePath = fn($path) => str_replace('\\', '/', $path);

$response_data = [
    'status' => 200,
    'code' => 'operation.success',
    'message' => 'Public root successfully moved',
    'data' => [
        'old_path' => $normalizePath($template_source_path),
        'new_path' => $normalizePath($target_destination_path),
        'destination' => $relative_path_input,
        'init_file_updated' => $normalizePath($init_path),
        'htaccess_updated' => [
            'main' => $normalizePath($htaccess_path),
            'management' => $normalizePath($management_htaccess_path),
            'admin' => $normalizePath($admin_htaccess_path)
        ],
        'warning' => 'Admin panel URL has changed. You may need to navigate to the new location.'
    ]
];

echo json_encode($response_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// CRITICAL: Exit immediately
exit(0);