<?php
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// --- 1. Parameter Validation and Preparation ---

if (!array_key_exists('path', $trimParametersManagement->params()) 
    && !array_key_exists('absoluteLink', $trimParametersManagement->params())){
    die("ERROR: One or more parameters are missing. 'path' or 'absoluteLink' are required.<br />");
}

$path = $trimParametersManagement->params()['path'];
$absoluteLink = $trimParametersManagement->params()['absoluteLink'];

// Harmonize parameters, prioritizing 'path' if both are present
if(!empty($path)){
    $absoluteLink = null;
    $target = "_self";
}

if(!empty($absoluteLink)){
    $path = null;
    $target = '_blank';
    if (!is_valid_absolute_url($absoluteLink)) {
        die("ERROR: The 'absoluteLink' parameter is not a valid URL (must include http:// or https://).<br />");
    }
}

if(empty($path) && empty($absoluteLink)){
    die("ERROR: Either 'path' or 'absoluteLink' must be provided.<br />");
}

// NOTE: We don't need the check against ROUTES here, as that only validated addition.
// Existence is checked below against the menuItems array.


// --- 2. Check Existence and Find Item to Delete ---

$menuItems = require SECURE_FOLDER_PATH . '/templates/model/menu.php';

$found_items = array_filter($menuItems, function ($item) use ($path, $absoluteLink) {
    // Check for internal path match
    if ($path !== null && isset($item['path']) && $item['path'] === $path) {
        return true;
    }
    // Check for external link match
    if ($absoluteLink !== null && isset($item['absoluteLink']) && $item['absoluteLink'] === $absoluteLink) {
        return true;
    }
    return false;
});

if (empty($found_items)) {
    die("ERROR: No menu option found for the provided path or absolute link to delete.<br />");
}

// Since uniqueness is enforced during creation, we only need the first match for the label.
$item_to_delete = array_shift($found_items);
$deleted_label = $item_to_delete['label']; // e.g., 'menu.test' or 'menu.external1'


// --- 3. Remove Item from Menu Array and Rewrite File ---

// Use array_filter to remove the matching item(s) (in case of unforeseen duplicates)
$menuItems = array_filter($menuItems, function ($item) use ($path, $absoluteLink) {
    // Keep items that are NOT the one we are deleting.
    
    // Condition to identify the item(s) to be deleted:
    $is_match_path = ($path !== null && isset($item['path']) && $item['path'] === $path);
    $is_match_link = ($absoluteLink !== null && isset($item['absoluteLink']) && $item['absoluteLink'] === $absoluteLink);
    
    // Return TRUE to KEEP, FALSE to DELETE.
    return !($is_match_path || $is_match_link);
});

// Reset keys for clean PHP array output
$menuItems = array_values($menuItems); 


// Rewrite the menu.php file using the cleaned array (same logic as add)
$menuFilePath = SECURE_FOLDER_PATH . '/templates/model/menu.php';

$fileContent = "<?php return [\n";
foreach ($menuItems as $item) {
    $fileContent .= " [\n";
    $fileContent .= "   'label' => '".$item['label']."',\n";
    $fileContent .= "   'path' => " . ($item['path'] === null ? 'null' : "'" . addslashes($item['path']) . "'") . ",\n";
    $fileContent .= "   'absoluteLink' => " . ($item['absoluteLink'] === null ? 'null' : "'" . addslashes($item['absoluteLink']) . "'") . ",\n";
    $fileContent .= "   'target' => '" . addslashes($item['target']) . "'\n";
    $fileContent .= " ],\n";
}
$fileContent .= "]; ?>\n";

if(file_put_contents($menuFilePath, $fileContent, LOCK_EX) === false){
    die("ERROR: Failed to write to menu configuration file.<br />");
}

echo "INFO: Menu option removed from menu.php successfully.<br />";


// --- 4. Remove Translation Key from All JSON Files ---

$translationDir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'translate';
$translationSection = 'menu';

// The key to remove (e.g., 'test' from 'menu.test')
$key_to_remove = str_replace('menu.', '', $deleted_label); 

$jsonFiles = glob($translationDir . '/*.json');

if (empty($jsonFiles)) {
    echo "WARNING: No translation files found in " . htmlspecialchars($translationDir) . ". Skipping translation update.\n";
} else {
    foreach ($jsonFiles as $filePath) {
        
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        // Check 1: Ensure JSON decoded successfully and 'menu' section exists
        if ($data === null || !isset($data[$translationSection]) || !is_array($data[$translationSection])) {
            echo "WARNING: Invalid JSON or missing 'menu' section in " . basename($filePath) . ". Skipping.\n";
            continue;
        }

        // Check 2: Remove the key if it exists in the 'menu' section
        if (array_key_exists($key_to_remove, $data[$translationSection])) {
            unset($data[$translationSection][$key_to_remove]);

            // Re-encode and write the file
            $newJsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($filePath, $newJsonContent, LOCK_EX) === false) {
                 echo "ERROR: Failed to write updated translation file: " . htmlspecialchars($filePath) . ".\n";
            } else {
                 echo "INFO: Removed translation key '" . htmlspecialchars($key_to_remove) . "' from " . basename($filePath) . ".\n";
            }
        } else {
             echo "INFO: Translation key '" . htmlspecialchars($key_to_remove) . "' was already missing from " . basename($filePath) . ".\n";
        }
    }
}

echo "<br />SUCCESS: Menu option for '" . htmlspecialchars($path ?? $absoluteLink) . "' successfully deleted and unregistered.<br />";