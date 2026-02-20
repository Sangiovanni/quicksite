<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * editComponentToNode - Edit a component instance's data bindings
 * 
 * @method POST
 * @url /management/editComponentToNode
 * @auth required
 * @permission editStructure
 * 
 * Edits the data bindings of an existing component node.
 * 
 * Rules:
 * - Can only edit component nodes (nodes with 'component' key)
 * - Can change param-type variables freely (href, src, etc.)
 * - Cannot change textKey-type variable references (use setTranslationKeys)
 * - Validates variable names exist in component definition
 * 
 * Parameters:
 * - type: 'menu', 'footer', 'page', 'component'
 * - name: structure name (required for type=page/component)
 * - nodeId: the component node to edit
 * - data: updated variable bindings (param-type variables only)
 */

/**
 * Extract variables with type detection from component structure
 * (Same as in addComponentToNode.php - consider moving to shared utility)
 */
function extractComponentVariablesForEdit($node, &$variables = []) {
    if (!is_array($node)) {
        return $variables;
    }
    
    $translatableParams = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];
    
    // Check params for placeholders
    if (isset($node['params']) && is_array($node['params'])) {
        foreach ($node['params'] as $paramName => $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $varName) {
                    // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                    if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                        continue;
                    }
                    
                    if (!isset($variables[$varName])) {
                        $type = in_array($paramName, $translatableParams, true) ? 'textKey' : 'param';
                        $variables[$varName] = ['name' => $varName, 'type' => $type, 'paramName' => $paramName];
                    }
                }
            }
        }
    }
    
    // Check textKey for placeholders
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (preg_match_all('/\{\{([^}]+)\}\}/', $node['textKey'], $matches)) {
            foreach ($matches[1] as $varName) {
                // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                    continue;
                }
                
                if (!isset($variables[$varName])) {
                    $variables[$varName] = ['name' => $varName, 'type' => 'textKey', 'paramName' => null];
                }
            }
        }
    }
    
    // Check component data for placeholders
    if (isset($node['data']) && is_array($node['data'])) {
        foreach ($node['data'] as $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $varName) {
                    // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                    if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                        continue;
                    }
                    
                    if (!isset($variables[$varName])) {
                        $variables[$varName] = ['name' => $varName, 'type' => 'textKey', 'paramName' => null];
                    }
                }
            }
        }
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractComponentVariablesForEdit($child, $variables);
        }
    }
    
    return $variables;
}

/**
 * Command function for internal execution via CommandRunner
 */
function __command_editComponentToNode(array $params = [], array $urlParams = []): ApiResponse {
    // Required fields validation
    $required = ['type', 'nodeId'];
    foreach ($required as $field) {
        if (!isset($params[$field]) || $params[$field] === '') {
            return ApiResponse::create(400, 'validation.required')
                ->withErrors([['field' => $field, 'reason' => 'missing']]);
        }
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $nodeId = $params['nodeId'];
    $userData = $params['data'] ?? [];
    
    // Validate structure type
    $allowed_types = ['menu', 'footer', 'page', 'component'];
    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]]);
    }
    
    if (($type === 'page' || $type === 'component') && empty($name)) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'name', 'reason' => "required for type={$type}"]]);
    }
    
    // Validate node ID format
    if (!RegexPatterns::match('node_id', $nodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid nodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'nodeId', $nodeId)]);
    }
    
    // Build JSON file path
    if ($type === 'page') {
        $jsonPath = resolvePageJsonPath($name);
        if ($jsonPath === null) {
            return ApiResponse::create(404, 'error.notFound')
                ->withMessage("Page '{$name}' does not exist");
        }
    } elseif ($type === 'component') {
        $jsonPath = PROJECT_PATH . '/templates/model/json/components/' . $name . '.json';
    } else {
        $jsonPath = PROJECT_PATH . '/templates/model/json/' . $type . '.json';
    }
    
    if (!file_exists($jsonPath)) {
        return ApiResponse::create(404, 'error.notFound')
            ->withMessage("Structure file not found for type={$type}" . ($name ? ", name={$name}" : ""));
    }
    
    // Load structure
    $content = file_get_contents($jsonPath);
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'error.invalidJson')
            ->withMessage("Structure file has invalid JSON");
    }
    
    // Find target node
    $targetNode = NodeNavigator::getNode($structure, $nodeId);
    
    if ($targetNode === null) {
        return ApiResponse::create(404, 'error.notFound')
            ->withMessage("Node not found: {$nodeId}")
            ->withErrors([['field' => 'nodeId', 'reason' => 'not_found', 'value' => $nodeId]]);
    }
    
    // Verify it's a component node
    if (!isset($targetNode['component'])) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Node is not a component node. Use editNode for tag nodes.")
            ->withErrors([['field' => 'nodeId', 'reason' => 'not_component_node', 'value' => $nodeId]]);
    }
    
    $componentName = $targetNode['component'];
    $currentData = $targetNode['data'] ?? [];
    
    // Load component definition to get variable types
    $componentPath = PROJECT_PATH . '/templates/model/json/components/' . $componentName . '.json';
    if (!file_exists($componentPath)) {
        return ApiResponse::create(404, 'error.notFound')
            ->withMessage("Component definition not found: {$componentName}");
    }
    
    $componentContent = file_get_contents($componentPath);
    $componentStructure = json_decode($componentContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'error.invalidJson')
            ->withMessage("Component has invalid JSON: " . json_last_error_msg());
    }
    
    // Extract variables from component definition
    $variables = [];
    extractComponentVariablesForEdit($componentStructure, $variables);
    
    // Validate user data - only param-type variables can be edited
    $errors = [];
    $updatedData = $currentData;
    $changedVariables = [];
    
    foreach ($userData as $varName => $value) {
        // Check if variable exists in component
        if (!isset($variables[$varName])) {
            $errors[] = [
                'field' => "data.{$varName}",
                'reason' => 'unknown_variable',
                'message' => "Variable '{$varName}' does not exist in component '{$componentName}'"
            ];
            continue;
        }
        
        $varInfo = $variables[$varName];
        
        // Check if it's a textKey-type variable (not editable here)
        if ($varInfo['type'] === 'textKey') {
            $errors[] = [
                'field' => "data.{$varName}",
                'reason' => 'textKey_not_editable',
                'message' => "Variable '{$varName}' is a text key. Use setTranslationKeys to edit translation values."
            ];
            continue;
        }
        
        // param-type variable - can be edited
        $oldValue = $currentData[$varName] ?? null;
        if ($oldValue !== $value) {
            $updatedData[$varName] = $value;
            $changedVariables[] = [
                'name' => $varName,
                'type' => $varInfo['type'],
                'oldValue' => $oldValue,
                'newValue' => $value
            ];
        }
    }
    
    // If there are errors, return them
    if (!empty($errors)) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage("Some variables could not be updated")
            ->withErrors($errors);
    }
    
    // If nothing changed, return early
    if (empty($changedVariables)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage("No changes made - all values unchanged")
            ->withData([
                'nodeId' => $nodeId,
                'component' => $componentName,
                'changes' => []
            ]);
    }
    
    // Update the node in structure
    $updatedNode = $targetNode;
    if (!empty($updatedData)) {
        $updatedNode['data'] = $updatedData;
    } else {
        unset($updatedNode['data']);
    }
    
    $updateResult = NodeNavigator::updateNode($structure, $nodeId, $updatedNode);
    
    if (!$updateResult['success']) {
        return ApiResponse::create(500, 'error.internal')
            ->withMessage("Failed to update node: " . ($updateResult['error'] ?? 'unknown error'));
    }
    
    $updatedStructure = $updateResult['structure'];
    
    // Save structure
    $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (file_put_contents($jsonPath, json_encode($updatedStructure, $jsonOptions)) === false) {
        return ApiResponse::create(500, 'error.fileWrite')
            ->withMessage("Failed to save structure file");
    }
    
    // Render the updated component to HTML for live DOM update
    $renderedHtml = null;
    try {
        // Determine structure name for editor mode
        if ($type === 'page') {
            $structureName = 'page-' . str_replace('/', '-', $name);
        } elseif ($type === 'component' && $name) {
            $structureName = 'component-' . $name;
        } else {
            $structureName = $type;
        }
        
        // Create renderer with editor mode enabled
        $lang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
        $translator = new Translator($lang);
        $renderer = new JsonToHtmlRenderer($translator, ['editorMode' => true]);
        
        $renderedHtml = $renderer->renderNodeAtPath($updatedStructure, $nodeId, $structureName);
    } catch (Exception $e) {
        // Non-fatal - we can still return success without rendered HTML
        error_log("Failed to render component HTML: " . $e->getMessage());
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Component '{$componentName}' updated successfully")
        ->withData([
            'nodeId' => $nodeId,
            'component' => $componentName,
            'data' => $updatedData,
            'changes' => $changedVariables,
            'variables' => array_values($variables),
            'html' => $renderedHtml
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editComponentToNode($trimParams->params(), $trimParams->additionalParams())->send();
}
