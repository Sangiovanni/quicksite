<?php
/**
 * listComponents - List all available reusable components
 * 
 * @method GET
 * @url /management/listComponents
 * @auth required
 * @permission read
 * 
 * Returns all components from secure/templates/model/json/components/
 * with their structure, metadata, and variable type detection.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/componentPolicy.php'; // the one reader of a component's slots

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_listComponents(array $params = [], array $urlParams = []): ApiResponse {
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';

    // Check directory exists
    if (!is_dir($componentsDir)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Components directory not found, no components available')
            ->withData([
                'components' => [],
                'count' => 0
            ]);
    }

    // Get all JSON files
    $files = glob($componentsDir . '/*.json');
    $components = [];

    foreach ($files as $file) {
        $name = basename($file, '.json');
        
        // Read component structure
        $content = @file_get_contents($file);
        if ($content === false) {
            continue; // Skip unreadable files
        }
        
        $structure = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Include but mark as invalid
            $components[] = [
                'name' => $name,
                'file' => $name . '.json',
                'valid' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg(),
                'structure' => null,
                'slots' => [],
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
            continue;
        }
        
        // The slots, from the one reader (componentPolicy.php): their names (the legacy
        // list, every slot), and the list with types, enums resolved
        $slots = array_map(static fn(array $slot) => $slot['name'], array_values(qs_component_variables($structure)));
        $variables = qs_component_variable_list($structure);
        $usesComponents = qs_components_called($structure);
        
        $components[] = [
            'name' => $name,
            'file' => $name . '.json',
            'valid' => true,
            'structure' => $structure,
            'slots' => $slots,                          // Legacy: just names
            'variables' => $variables,                  // New: [{name, type}, ...]
            'uses_components' => $usesComponents,
            'size' => filesize($file),
            'modified' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }

    // Sort by name
    usort($components, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Components listed successfully')
        ->withData([
            'components' => $components,
            'count' => count($components),
            'directory' => SECURE_FOLDER_NAME . '/projects/' . PROJECT_NAME . '/templates/model/json/components/'
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listComponents()->send();
}
