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

if (!empty($path)) {
    if(!in_array($path, ROUTES)){
        die("ERROR: The 'path' parameter must be one of the defined routes.<br />");
    }
}

// --- 2. Load, Validate Uniqueness, and Determine Label ---

$footerItems = require SECURE_FOLDER_PATH . '/templates/model/' . $config_model_file;

if($absoluteLink !== null){
    // Assuming get_next_external_link_number() works for any array of links
    $number = get_next_external_link_number($footerItems);
    $label = $translation_section . ".external" . $number; // e.g., 'footer.external2'
}
else {
    $label = $translation_section . "." . $path; // e.g., 'footer.test'
}

// Check for existing duplicates before appending $newFooterItem
$is_duplicate = array_filter($footerItems, function ($item) use ($path, $absoluteLink) {
    if ($path !== null && isset($item['path']) && $item['path'] === $path) {
        return true;
    }
    if ($absoluteLink !== null && isset($item['absoluteLink']) && $item['absoluteLink'] === $absoluteLink) {
        return true;
    }
    return false;
});

if (!empty($is_duplicate)) {
    die("ERROR: A footer option already exists for this path or absolute link.");
}

$newFooterItem = [
    'label' => $label,
    'path' => $path,
    'absoluteLink' => $absoluteLink,
    'target' => $target
];

$footerItems[] = $newFooterItem;

// --- 3. Write New Footer Config File ---

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

echo "INFO: Footer option added to " . $config_model_file . " successfully.<br />";


// --- 4. Update All Translation Files ---

$translationDir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'translate';
$newLabel = $label; 
$newKey = str_replace($translation_section . '.', '', $newLabel); 
$newValue = $newLabel; 

$jsonFiles = glob($translationDir . '/*.json');

if (empty($jsonFiles)) {
    echo "WARNING: No translation files found in " . htmlspecialchars($translationDir) . ". Skipping translation update.\n";
} else {
    foreach ($jsonFiles as $filePath) {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if ($data === null) { continue; } // Skip invalid JSON

        if (isset($data[$translation_section]) && is_array($data[$translation_section])) {
            if (!array_key_exists($newKey, $data[$translation_section])) {
                $data[$translation_section][$newKey] = $newValue;
            }
        } else {
            $data[$translation_section] = [$newKey => $newValue];
        }

        $newJsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($filePath, $newJsonContent, LOCK_EX) === false) {
            echo "ERROR: Failed to write translation file: " . htmlspecialchars($filePath) . ".\n";
        } else {
            echo "INFO: Added translation key '" . htmlspecialchars($newKey) . "' to " . basename($filePath) . ".\n";
        }
    }
}

echo "<br />SUCCESS: Footer option added successfully.<br />";