<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * getTranslationKeys - Extracts all translation keys from site structures
 * 
 * @method GET
 * @url /management/getTranslationKeys
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getTranslationKeys(array $params = [], array $urlParams = []): ApiResponse {
    $allKeys = [];
    $scannedFiles = [];

    // 1. Scan all page structures (supports folder structure)
    $pageFiles = scanAllPageJsonFiles();
    foreach ($pageFiles as $pageInfo) {
        $structure = loadJsonStructure($pageInfo['path']);
        
        if ($structure !== null) {
            $pageKeys = [];
            if (is_array($structure)) {
                foreach ($structure as $node) {
                    extractTextKeys($node, $pageKeys, 0, 20); // Max 20 levels depth
                }
            }
            
            $allKeys[$pageInfo['route']] = array_unique($pageKeys);
            $scannedFiles['pages'][] = $pageInfo['route'];
        }
    }

    // 2. Scan menu structure
    $menuFile = PROJECT_PATH . '/templates/model/json/menu.json';
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
    $footerFile = PROJECT_PATH . '/templates/model/json/footer.json';
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
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translation keys extracted successfully')
        ->withData([
            'keys_by_source' => $allKeys,
            'all_keys' => $flattenedKeys,
            'total_keys' => count($flattenedKeys),
            'scanned_files' => $scannedFiles
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getTranslationKeys()->send();
}