<?php
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
    
    // Validate names don't contain slashes
    if (strpos($sourceName, '/') !== false || strpos($newName, '/') !== false) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Component names cannot contain slashes');
    }
    
    // Validate new name format (alphanumeric with hyphens)
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $newName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Component name must start with a letter and contain only letters, numbers, and hyphens')
            ->withData(['invalid' => $newName]);
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
    
    // Write to new file (re-encode to ensure consistent formatting)
    $newContent = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($newFile, $newContent) === false) {
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
