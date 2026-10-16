<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_json_write
/**
 * duplicateComponent - Create a copy of an existing component with a new name
 * 
 * @method POST
 * @url /management/duplicateComponent
 * @auth required
 * @permission editStructure
 * 
 * Creates a copy of an existing component. The new component is independent
 * and references to the original are NOT updated (it's a true copy).
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

/**
 * Command function for duplicateComponent
 * 
 * @param array $params Body parameters: source, name (new name)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_duplicateComponent(array $params = [], array $urlParams = []): ApiResponse {
    $sourceName = $params['source'] ?? $params['from'] ?? null;
    $newName = $params['name'] ?? $params['to'] ?? null;
    
    // Validate required parameters
    if (!$sourceName) {
        return ApiResponse::create(400, 'validation.missing_parameter')
            ->withMessage('Source component name is required')
            ->withData(['missing' => 'source']);
    }
    
    if (!$newName) {
        return ApiResponse::create(400, 'validation.missing_parameter')
            ->withMessage('New component name is required')
            ->withData(['missing' => 'name']);
    }
    
    // The one component-name rule every command shares (qs_component_name_refusal()):
    // each name is one name, never a path, and the new one is a name a page can place.
    $refusal = qs_component_name_refusal($sourceName, 'source') ?? qs_component_name_refusal($newName, 'name');
    if ($refusal !== null) {
        return $refusal;
    }
    
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';
    $sourceFile = $componentsDir . '/' . $sourceName . '.json';
    $newFile = $componentsDir . '/' . $newName . '.json';
    
    // Ensure components directory exists
    if (!is_dir($componentsDir)) {
        if (!mkdir($componentsDir, 0755, true)) {
            return ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage("Failed to create components directory");
        }
    }
    
    // Check source component exists
    if (!file_exists($sourceFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Source component '{$sourceName}' does not exist")
            ->withData(['component' => $sourceName]);
    }
    
    // Check new name doesn't already exist
    if (file_exists($newFile)) {
        return ApiResponse::create(409, 'file.already_exists')
            ->withMessage("Component '{$newName}' already exists")
            ->withData(['component' => $newName]);
    }
    
    // Read source content
    $content = @file_get_contents($sourceFile);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage("Failed to read source component")
            ->withData(['file' => $sourceFile]);
    }
    
    // Validate it's valid JSON
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'validation.invalid_json')
            ->withMessage("Source component contains invalid JSON")
            ->withData(['error' => json_last_error_msg()]);
    }
    
    // The copy is a new structure file, so it is checked like any other write.
    $unsafeStructureParam = qs_first_unsafe_structure_param($structure);
    if ($unsafeStructureParam !== null) {
        return qs_unsafe_structure_param_response($unsafeStructureParam);
    }

    // Write to new file (re-encode to ensure consistent formatting), unless it
    // would pass the structure cap or grow while the owner is over quota
    // (qs_small_write_refusal)
    $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $refusal = qs_small_write_refusal([$newFile => qs_json_size($structure, $jsonFlags)]);
    if ($refusal !== null) {
        return $refusal;
    }
    if (!qs_json_write($newFile, $structure, $jsonFlags)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to create new component file")
            ->withData(['file' => $newFile]);
    }
    
    return ApiResponse::create(201, 'operation.created')
        ->withMessage("Component '{$newName}' created as copy of '{$sourceName}'")
        ->withData([
            'source' => $sourceName,
            'name' => $newName,
            'file' => 'components/' . $newName . '.json',
            'size' => strlen($newContent)
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_duplicateComponent($trimParams->params(), $trimParams->additionalParams())->send();
}
