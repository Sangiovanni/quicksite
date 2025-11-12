<?php
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

if (!array_key_exists('destination', $trimParametersManagement->params())) {
    exit("ERROR: 'destination' parameter is required.\n");
}
    
$relative_path_input = $trimParametersManagement->params()['destination'];


if (!is_valid_relative_path($relative_path_input)) {
  die("ERROR: The provided destination path is invalid or unsafe: " . htmlspecialchars($relative_path_input) . "\n");
}
    
// Define Source and Destination paths
$template_source_path = SERVER_ROOT . DIRECTORY_SEPARATOR .SECURE_FOLDER_NAME;
$target_destination_path = SERVER_ROOT . DIRECTORY_SEPARATOR . $relative_path_input;

print_r("Template Source Path: ". $template_source_path ."<br>");
print_r("Target Destination Path: ". $target_destination_path ."<br>");



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

//Modify PUBLIC_FOLDER_ROOT/PUBLIC_FOLDER_SPACE/init.php (PUBLIC_FOLDER_SPACE constant)
$init_path = PUBLIC_FOLDER_ROOT . DIRECTORY_SEPARATOR .PUBLIC_FOLDER_SPACE . DIRECTORY_SEPARATOR . 'init.php';

print("Init Path to Modify: ". $init_path ."<br>");

// The search pattern MUST match exactly what is in your file, including quotes and spaces.
// We are escaping quotes within the search string for literal matching.
$search3 = "define('SECURE_FOLDER_NAME', '".SECURE_FOLDER_NAME."')";
$replace3 = "define('SECURE_FOLDER_NAME', '" . $relative_path_input . "')";


if (!replace_in_file($init_path, $search3, $replace3)) {
    die("ERROR: Failed to update SECURE_FOLDER_NAME in init.php file in: " . $new_base_path);
}

if(!str_contains($target_destination_path, $template_source_path)){
  cleanup_empty_source_chain($template_source_path, $target_destination_path);
}
