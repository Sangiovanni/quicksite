<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// --- SCAN ALL STRUCTURES ---

$allKeys = [];
$scannedFiles = [];

// 1. Scan all page structures
$pagesDir = SECURE_FOLDER_PATH . '/templates/model/json/pages';
if (is_dir($pagesDir)) {
    $pageFiles = glob($pagesDir . '/*.json');
    
    foreach ($pageFiles as $pageFile) {
        $pageName = basename($pageFile, '.json');
        $structure = loadJsonStructure($pageFile);
        
        if ($structure !== null) {
            $pageKeys = [];
            if (is_array($structure)) {
                foreach ($structure as $node) {
                    extractTextKeys($node, $pageKeys, 0, 20); // Max 20 levels depth
                }
            }
            
            $allKeys[$pageName] = array_unique($pageKeys);
            $scannedFiles['pages'][] = $pageName;
        }
    }
}

// 2. Scan menu structure
$menuFile = SECURE_FOLDER_PATH . '/templates/model/json/menu.json';
$menuStructure = loadJsonStructure($menuFile);

if ($menuStructure !== null) {
    $menuKeys = [];
    if (is_array($menuStructure)) {
        foreach ($menuStructure as $node) {
            extractTextKeys($node, $menuKeys, 0, 20); // Max 20 levels depth
        }
    }
    $allKeys['menu'] = array_unique($menuKeys);
    $scannedFiles['menu'] = true;
}

// 3. Scan footer structure
$footerFile = SECURE_FOLDER_PATH . '/templates/model/json/footer.json';
$footerStructure = loadJsonStructure($footerFile);

if ($footerStructure !== null) {
    $footerKeys = [];
    if (is_array($footerStructure)) {
        foreach ($footerStructure as $node) {
            extractTextKeys($node, $footerKeys, 0, 20); // Max 20 levels depth
        }
    }
    $allKeys['footer'] = array_unique($footerKeys);
    $scannedFiles['footer'] = true;
}

// 4. Flatten all keys for easy comparison
$flattenedKeys = [];
foreach ($allKeys as $source => $keys) {
    foreach ($keys as $key) {
        if (!in_array($key, $flattenedKeys)) {
            $flattenedKeys[] = $key;
        }
    }
}

sort($flattenedKeys);

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Translation keys extracted successfully')
    ->withData([
        'keys_by_source' => $allKeys,
        'all_keys' => $flattenedKeys,
        'total_keys' => count($flattenedKeys),
        'scanned_files' => $scannedFiles
    ])
    ->send();