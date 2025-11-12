<?php
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

if (!array_key_exists('destination', $trimParametersManagement->params())) {
    exit("ERROR: 'destination' parameter is required.\n");
}
    
$relative_path_input = $trimParametersManagement->params()['destination'];


if (!is_valid_relative_path($relative_path_input) && !$relative_path_input === '') {
  die("ERROR: The provided destination path is invalid or unsafe: " . htmlspecialchars($relative_path_input) . "\n");
}
    
// Define Source and Destination paths
$template_source_path = PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . PUBLIC_FOLDER_SPACE;
$target_destination_path = PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR . $relative_path_input;

if (!is_dir($template_source_path)) {
  die("FATAL ERROR: Template source folder does not exist at: " . $template_source_path);
}

// Use the recursive copy function to create the new folder AND populate it
if (!recursive_move_template($template_source_path, $target_destination_path, $relative_path_input)) {
  die("ERROR: Failed to copy template contents from: " . $template_source_path . " to: " . $target_destination_path . "\n");
} 
    
// Define the full path to the new directory for convenience
$new_base_path = $target_destination_path; 

// --- START FILE MODIFICATION ---

// 1. Modify $target_destination_path/.htaccess
$htaccess_path = $new_base_path . DIRECTORY_SEPARATOR . '.htaccess';
$search1 = 'FallbackResource /index.php';
if(PUBLIC_FOLDER_SPACE !== ''){
    $search1 = 'FallbackResource /' . PUBLIC_FOLDER_SPACE . '/index.php';
}
$replace1 = 'FallbackResource /' . $relative_path_input . '/index.php';

if (!replace_in_file($htaccess_path, $search1, $replace1)) {
    die("ERROR: Failed to update .htaccess file in: " . $new_base_path);
}

// 2. Modify $target_destination_path/management/.htaccess
$management_htaccess_path = $new_base_path . DIRECTORY_SEPARATOR . 'management' . DIRECTORY_SEPARATOR . '.htaccess';
$search2 = 'FallbackResource /' . PUBLIC_FOLDER_SPACE . '/management/index.php';
$replace2 = 'FallbackResource /' . $relative_path_input . '/management/index.php';

if (!replace_in_file($management_htaccess_path, $search2, $replace2)) {
    die("ERROR: Failed to update management/.htaccess file in: " . $new_base_path);
}

// 3. Modify $target_destination_path/init.php (PUBLIC_FOLDER_SPACE constant)
$init_path = $new_base_path . DIRECTORY_SEPARATOR . 'init.php';

// The search pattern MUST match exactly what is in your file, including quotes and spaces.
// We are escaping quotes within the search string for literal matching.
$search3 = "define('PUBLIC_FOLDER_SPACE', '".PUBLIC_FOLDER_SPACE."');";
$replace3 = "define('PUBLIC_FOLDER_SPACE', '" . $relative_path_input . "');";


if (!replace_in_file($init_path, $search3, $replace3)) {
    die("ERROR: Failed to update PUBLIC_FOLDER_SPACE in init.php file in: " . $new_base_path);
}
if(!str_contains($target_destination_path, $template_source_path)){
  cleanup_empty_source_chain($template_source_path, $target_destination_path);
}
