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

// Validate: secure folder path cannot be empty
if ($relative_path_input === '') {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Secure folder path cannot be empty")
        ->withErrors([
            ['field' => 'destination', 'reason' => 'empty_not_allowed']
        ])
        ->send();
}

// Validate path: max_depth = 5 (allows nested paths like 'backends/project1')
if (!is_valid_relative_path($relative_path_input, 255, 5, false)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Secure folder path must be valid (max 5 levels deep, e.g., 'backends/project1')")
        ->withErrors([
            ['field' => 'destination', 'value' => $relative_path_input],
            ['constraints' => [
                'max_length' => 255,
                'max_depth' => 5,
                'allowed_chars' => 'a-z, A-Z, 0-9, hyphen, underscore, forward slash',
                'empty_not_allowed' => true
            ]]
        ])
        ->send();
}

// Validate: check for reserved/conflicting folder names
// Get all path segments to check each one
$path_segments = explode('/', str_replace('\\', '/', $relative_path_input));

// Reserved names that could cause conflicts or issues
$reserved_names = [
    // Project structure conflicts
    'public',           // Would conflict with public folder
    // Development folders
    '.git', '.svn', '.hg',
    '.venv', 'venv', 'env',
    'node_modules', 'vendor',
    '__pycache__', '.cache',
    // Windows reserved names (case-insensitive)
    'con', 'prn', 'aux', 'nul',
    'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
    'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    // Special directories
    '.', '..'
];

foreach ($path_segments as $segment) {
    $segment_lower = strtolower($segment);
    // Also check without extension for Windows reserved (e.g., "con.txt" is also reserved)
    $segment_base = strtolower(pathinfo($segment, PATHINFO_FILENAME));
    
    if (in_array($segment_lower, $reserved_names) || in_array($segment_base, array_slice($reserved_names, -13))) {
        ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage("Path contains a reserved or conflicting folder name: '$segment'")
            ->withErrors([
                ['field' => 'destination', 'segment' => $segment],
                ['reserved_names' => ['public', '.git', '.venv', 'node_modules', 'vendor', 'Windows reserved names (CON, PRN, AUX, NUL, COM1-9, LPT1-9)']]
            ])
            ->send();
    }
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

// Create parent directories if destination is nested (e.g., 'backends/project1' needs 'backends/')
$parent_dir = dirname($target_destination_path);
if ($parent_dir !== SERVER_ROOT && !is_dir($parent_dir)) {
    if (!mkdir($parent_dir, 0755, true)) {
        releaseLock($lock);
        
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to create parent directory for nested secure folder")
            ->withData([
                'parent_dir' => $parent_dir
            ])
            ->send();
    }
}

// Use the recursive move function (still inside lock)
// Pass empty string for exclude_folder_name since source and destination are siblings
// (the exclusion is only needed for setPublicSpace where dest can be inside source)
if (!recursive_move_template($template_source_path, $target_destination_path, '')) {
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