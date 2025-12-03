<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Recursively extract all textKey values from a JSON structure
 */
function extractTextKeys($node, &$keys = []) {
    if (!is_array($node)) {
        return $keys;
    }
    
    // Check if it's a text node
    if (isset($node['textKey'])) {
        $textKey = $node['textKey'];
        
        // Skip __RAW__ prefixed keys (they're not translations)
        if (strpos($textKey, '__RAW__') !== 0) {
            $keys[] = $textKey;
        }
    }
    
    // Check if it has children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractTextKeys($child, $keys);
        }
    }
    
    // Check if it's a component with data
    if (isset($node['component']) && isset($node['data']) && is_array($node['data'])) {
        // Check for label in component data (common in menu/footer)
        if (isset($node['data']['label']) && is_string($node['data']['label'])) {
            $keys[] = $node['data']['label'];
        }
    }
    
    return $keys;
}

/**
 * Load and parse a JSON structure file
 */
function loadJsonStructure($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $json = @file_get_contents($filePath);
    if ($json === false) {
        return null;
    }
    
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    
    return $data;
}

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
                    extractTextKeys($node, $pageKeys);
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
            extractTextKeys($node, $menuKeys);
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
            extractTextKeys($node, $footerKeys);
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