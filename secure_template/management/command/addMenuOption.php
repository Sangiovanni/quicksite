<?php
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';


if (!array_key_exists('path', $trimParametersManagement->params()) 
  && !array_key_exists('absoluteLink', $trimParametersManagement->params())){
  die("ERROR:  one or more parameters are missing. path and absoluteLink are required.<br />");
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

if (!empty($path)) {
  if(!in_array($path, ROUTES)){
    die("ERROR: The 'path' parameter must be one of the defined routes.<br />");
  }
}
$menuItems = require_once SECURE_FOLDER_PATH . '/templates/model/menu.php';

if($absoluteLink !== null){
  $number = get_next_external_link_number($menuItems);
  $label = "menu.external".$number;
}
else {
  $label = "menu.".$path;
}



// Check for existing duplicates before appending $newMenuItem
$is_duplicate = array_filter($menuItems, function ($item) use ($path, $absoluteLink) {
    // Check if the path is already registered (for internal links)
    if ($path !== null && isset($item['path']) && $item['path'] === $path) {
        return true;
    }
    // Check if the absoluteLink is already registered (for external links)
    if ($absoluteLink !== null && isset($item['absoluteLink']) && $item['absoluteLink'] === $absoluteLink) {
        return true;
    }
    return false;
});

if (!empty($is_duplicate)) {
    die("ERROR: A menu option already exists for this path or absolute link.");
}

$newMenuItem = [
    'label' => $label,
    'path' => $path,
    'absoluteLink' => $absoluteLink,
    'target' => $target
];

$menuItems[] = $newMenuItem;

$menuFilePath = SECURE_FOLDER_PATH . '/templates/model/menu.php';

$fileContent = "<?php return [\n";
foreach ($menuItems as $item) {
    $fileContent .= "    [\n";
    $fileContent .= "        'label' => '".$item['label']."',\n";
    $fileContent .= "        'path' => " . ($item['path'] === null ? 'null' : "'" . addslashes($item['path']) . "'") . ",\n";
    $fileContent .= "        'absoluteLink' => " . ($item['absoluteLink'] === null ? 'null' : "'" . addslashes($item['absoluteLink']) . "'") . ",\n";
    $fileContent .= "        'target' => '" . addslashes($item['target']) . "'\n";
    $fileContent .= "    ],\n";
}
$fileContent .= "]; ?>\n";

if(!file_put_contents($menuFilePath, $fileContent, LOCK_EX)){
    die("ERROR: Failed to write to menu configuration file.<br />");
}

echo "Menu option added successfully.<br />";


// --- 3. Update All Translation Files ---

// 1. Define the translation directory and the section key
$translationDir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'translate';
$translationSection = 'menu';
$newLabel = $label; // e.g., 'menu.test' or 'menu.external2'

// The value for the new entry. We remove the 'menu.' prefix for the key.
$newKey = str_replace('menu.', '', $newLabel); 
$newValue = $newLabel; // Use the label itself as the placeholder translation

// 2. Iterate through all JSON files in the directory
$jsonFiles = glob($translationDir . '/*.json');

if (empty($jsonFiles)) {
    echo "WARNING: No translation files found in " . htmlspecialchars($translationDir) . ". Skipping translation update.\n";
} else {
    foreach ($jsonFiles as $filePath) {
        
        // A. Read the file content
        $content = file_get_contents($filePath);
        if ($content === false) {
            echo "WARNING: Failed to read translation file: " . htmlspecialchars($filePath) . ". Skipping.\n";
            continue;
        }

        // B. Decode JSON to a PHP array
        $data = json_decode($content, true);
        if ($data === null) {
            echo "WARNING: Invalid JSON in file: " . htmlspecialchars($filePath) . ". Skipping.\n";
            continue;
        }

        // C. Modify the array: Add the new key/value to the target section
        if (isset($data[$translationSection]) && is_array($data[$translationSection])) {
            // Check if the key already exists (shouldn't if addMenuOption validation is good)
            if (!array_key_exists($newKey, $data[$translationSection])) {
                $data[$translationSection][$newKey] = $newValue;
            }
        } else {
            // Handle case where 'menu' section is missing (e.g., create it)
            $data[$translationSection] = [$newKey => $newValue];
        }

        // D. Encode the PHP array back to JSON (using JSON_PRETTY_PRINT for readability)
        $newJsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($newJsonContent === false) {
             echo "WARNING: Failed to encode JSON for file: " . htmlspecialchars($filePath) . ". Skipping.\n";
             continue;
        }

        // E. Write the file back
        if (file_put_contents($filePath, $newJsonContent, LOCK_EX) === false) {
            echo "ERROR: Failed to write translation file: " . htmlspecialchars($filePath) . ".\n";
            // Do NOT die here, let the loop continue to try other files
        } else {
            echo "INFO: Added '" . htmlspecialchars($newKey) . "' to " . basename($filePath) . ".\n";
        }
    }
}