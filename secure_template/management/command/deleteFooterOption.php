<?php
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// --- CONFIGURATION CONSTANTS FOR FOOTER ---
$config_model_file = 'footer.php';
$translation_section = 'footer';

// --- 1. Parameter Validation and Preparation (Identical to Menu) ---

if (!array_key_exists('path', $trimParametersManagement->params()) 
    && !array_key_exists('absoluteLink', $trimParametersManagement->params())){
    die("ERROR: One or more parameters are missing. 'path' or 'absoluteLink' are required.<br />");
}

$path = $trimParametersManagement->params()['path'];
$absoluteLink = $trimParametersManagement->params()['absoluteLink'];

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

// --- 2. Check Existence, Find Label, and Validate Deletion ---

$footerItems = require SECURE_FOLDER_PATH . '/templates/model/' . $config_model_file;

$found_items = array_filter($footerItems, function ($item) use ($path, $absoluteLink) {
    if ($path !== null && isset($item['path']) && $item['path'] === $path) {
        return true;
    }
    if ($absoluteLink !== null && isset($item['absoluteLink']) && $item['absoluteLink'] === $absoluteLink) {
        return true;
    }
    return false;
});

if (empty($found_items)) {
    die("ERROR: No footer option found for the provided path or absolute link to delete.<br />");
}

// Get the label to delete from the first matching item
$item_to_delete = array_shift($found_items);
$deleted_label = $item_to_delete['label'];


// --- 3. Remove Item from Footer Array and Rewrite File ---

// Filter: keep items that do NOT match the criteria (path OR absoluteLink)
$footerItems = array_filter($footerItems, function ($item) use ($path, $absoluteLink) {
    $is_match_path = ($path !== null && isset($item['path']) && $item['path'] === $path);
    $is_match_link = ($absoluteLink !== null && isset($item['absoluteLink']) && $item['absoluteLink'] === $absoluteLink);
    
    return !($is_match_path || $is_match_link);
});

// Reset keys for clean PHP array output
$footerItems = array_values($footerItems); 


// Rewrite the footer.php file using the cleaned array
$footerFilePath = SECURE_FOLDER_PATH . '/templates/model/' . $config_model_file;

$fileContent = "<?php return [\n";
foreach ($footerItems as $item) {
    $fileContent .= " [\n";
    $fileContent .= "   'label' => '".$item['label']."',\n";
    $fileContent .= "   'path' => " . ($item['path'] === null ? 'null' : "'" . addslashes($item['path']) . "'") . ",\n";
    $fileContent .= "   'absoluteLink' => " . ($item['absoluteLink'] === null ? 'null' : "'" . addslashes($item['absoluteLink']) . "'") . ",\n";
    $fileContent .= "   'target' => '" . addslashes($item['target']) . "'\n";
    $fileContent .= " ],\n";
}
$fileContent .= "]; ?>\n";

if(file_put_contents($footerFilePath, $fileContent, LOCK_EX) === false){
    die("ERROR: Failed to write to footer configuration file.<br />");
}

echo "INFO: Footer option removed from " . $config_model_file . " successfully.<br />";


// --- 4. Remove Translation Key from All JSON Files ---

$translationDir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'translate';
$translationSection = 'footer';

// The key to remove (e.g., 'privacy' from 'footer.privacy')
$key_to_remove = str_replace($translation_section . '.', '', $deleted_label); 

$jsonFiles = glob($translationDir . '/*.json');

if (empty($jsonFiles)) {
    echo "WARNING: No translation files found in " . htmlspecialchars($translationDir) . ". Skipping translation update.\n";
} else {
    foreach ($jsonFiles as $filePath) {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if ($data === null || !isset($data[$translation_section]) || !is_array($data[$translation_section])) {
            continue; // Skip invalid JSON or files without the footer section
        }

        if (array_key_exists($key_to_remove, $data[$translation_section])) {
            unset($data[$translation_section][$key_to_remove]);

            // Re-encode and write the file
            $newJsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($filePath, $newJsonContent, LOCK_EX) === false) {
                 echo "ERROR: Failed to write updated translation file: " . htmlspecialchars($filePath) . ".\n";
            } else {
                 echo "INFO: Removed translation key '" . htmlspecialchars($key_to_remove) . "' from " . basename($filePath) . ".\n";
            }
        }
    }
}

echo "<br />SUCCESS: Footer option successfully deleted and unregistered.<br />";