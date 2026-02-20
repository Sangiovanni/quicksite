<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * addComponentToNode - Add a component instance to a structure
 * 
 * @method POST
 * @url /management/addComponentToNode
 * @auth required
 * @permission editStructure
 * 
 * Adds a component instance as a sibling or first child of the target node.
 * 
 * Auto-generates translation keys for textKey-type variables in format:
 * {structureName}.{componentName}{N}.{variableName}
 * Example: home.menuCard1.title, home.menuCard1.desc
 * 
 * Parameters:
 * - type: 'menu', 'footer', 'page', 'component'
 * - name: structure name (required for type=page/component)
 * - targetNodeId: existing node ID for relative positioning
 * - position: 'before', 'after', 'inside' (inside = first child)
 * - component: component name (from listComponents)
 * - data: variable bindings (optional overrides, auto-generated for textKey types)
 */

/**
 * Extract variables with type detection from component structure
 * 
 * Filters out:
 * - System placeholders starting with __ (e.g., __current_page)
 * - Placeholders with parameters (containing ;) as they are fixed values
 */
function extractComponentVariables($node, &$variables = []) {
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
            extractComponentVariables($child, $variables);
        }
    }
    
    return $variables;
}

/**
 * Count existing instances of a component in a structure
 */
function countComponentInstances($nodes, $componentName, $count = 0) {
    if (!is_array($nodes)) {
        return $count;
    }
    
    foreach ($nodes as $node) {
        if (isset($node['component']) && $node['component'] === $componentName) {
            $count++;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $count = countComponentInstances($node['children'], $componentName, $count);
        }
    }
    
    return $count;
}

/**
 * Command function for internal execution via CommandRunner
 */
function __command_addComponentToNode(array $params = [], array $urlParams = []): ApiResponse {
    // Required fields validation
    $required = ['type', 'targetNodeId', 'component'];
    foreach ($required as $field) {
        if (!isset($params[$field]) || $params[$field] === '') {
            return ApiResponse::create(400, 'validation.required')
                ->withErrors([['field' => $field, 'reason' => 'missing']]);
        }
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $targetNodeId = $params['targetNodeId'];
    $position = $params['position'] ?? 'after';
    $componentName = $params['component'];
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
    
    // Handle special 'root' targetNodeId (add child to structure root)
    $isRootTarget = ($targetNodeId === 'root');
    if ($isRootTarget) {
        $position = 'inside';
    } elseif (!RegexPatterns::match('node_id', $targetNodeId)) {
        // Validate node ID format
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid targetNodeId format. Use dot notation like '0.2.1' or 'root'")
            ->withErrors([RegexPatterns::validationError('node_id', 'targetNodeId', $targetNodeId)]);
    }
    
    // Validate position
    $allowedPositions = ['before', 'after', 'inside'];
    if (!in_array($position, $allowedPositions, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid position. Must be one of: " . implode(', ', $allowedPositions))
            ->withErrors([['field' => 'position', 'value' => $position, 'allowed' => $allowedPositions]]);
    }
    
    // Validate component name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $componentName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid component name format")
            ->withErrors([['field' => 'component', 'value' => $componentName]]);
    }
    
    // Load component definition
    $componentPath = PROJECT_PATH . '/templates/model/json/components/' . $componentName . '.json';
    if (!file_exists($componentPath)) {
        return ApiResponse::create(404, 'error.notFound')
            ->withMessage("Component not found: {$componentName}")
            ->withErrors([['field' => 'component', 'reason' => 'not_found', 'value' => $componentName]]);
    }
    
    $componentContent = @file_get_contents($componentPath);
    if ($componentContent === false) {
        return ApiResponse::create(500, 'error.fileRead')
            ->withMessage("Failed to read component file");
    }
    
    $componentStructure = json_decode($componentContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'error.invalidJson')
            ->withMessage("Component has invalid JSON: " . json_last_error_msg());
    }
    
    // Extract variables with type detection
    $variables = [];
    extractComponentVariables($componentStructure, $variables);
    
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
    
    // Find target node and check if it's a component (can't insert inside components)
    if ($isRootTarget) {
        $targetNode = $structure;
    } else {
        $targetNode = NodeNavigator::getNode($structure, $targetNodeId);
    }
    
    if ($targetNode === null) {
        return ApiResponse::create(404, 'error.notFound')
            ->withMessage("Target node not found: {$targetNodeId}")
            ->withErrors([['field' => 'targetNodeId', 'reason' => 'not_found', 'value' => $targetNodeId]]);
    }
    
    // Block "inside" position for component nodes (components are atomic)
    if ($position === 'inside' && isset($targetNode['component'])) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Cannot insert inside a component node. Components are atomic units.")
            ->withErrors([['field' => 'position', 'reason' => 'component_is_atomic', 'targetNode' => $targetNodeId]]);
    }
    
    // Get structure name for translation key prefix
    $structureName = $name ?? $type;
    
    // Count existing instances of this component to generate unique suffix
    $instanceCount = countComponentInstances($structure, $componentName);
    $instanceNumber = $instanceCount + 1;
    
    // Build data bindings with auto-generated textKeys
    $finalData = [];
    $generatedTextKeys = [];
    
    foreach ($variables as $varName => $varInfo) {
        if (isset($userData[$varName])) {
            // User provided value
            $finalData[$varName] = $userData[$varName];
        } elseif ($varInfo['type'] === 'textKey') {
            // Auto-generate textKey for text-type variables
            $textKey = "{$structureName}.{$componentName}{$instanceNumber}.{$varName}";
            $finalData[$varName] = $textKey;
            $generatedTextKeys[$varName] = $textKey;
        }
        // param-type variables without user value are left empty (or could use defaults)
    }
    
    // Build the component node
    $newNode = [
        'component' => $componentName
    ];
    
    // Only add data if there are bindings
    if (!empty($finalData)) {
        $newNode['data'] = $finalData;
    }
    
    // Insert the node
    if ($isRootTarget) {
        // Direct insertion into root's children (prepend like normal 'inside')
        if (!isset($structure['children'])) $structure['children'] = [];
        array_unshift($structure['children'], $newNode);
        $insertedAt = 0;
        $newNodeId = '0';
        $result = ['success' => true, 'structure' => $structure, 'insertedAt' => $insertedAt];
    } else {
        $result = NodeNavigator::insertNode($structure, $targetNodeId, $newNode, $position);
    }

    if (!$result['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage($result['error'] ?? 'Failed to insert component node');
    }

    // Compute newNodeId from insertion result
    if (!$isRootTarget) {
        $targetIndices = explode('.', $targetNodeId);
        $parentPath = array_slice($targetIndices, 0, -1);
        $insertedAt = $result['insertedAt'];
        $newNodeId = empty($parentPath) ? (string)$insertedAt : implode('.', $parentPath) . '.' . $insertedAt;
    }
    
    $updatedStructure = $result['structure'];
    // Create translation entries for generated textKeys
    $translationsCreated = [];
    if (!empty($generatedTextKeys)) {
        $translationsPath = PROJECT_PATH . '/translate';
        $defaultFile = $translationsPath . '/default.json';
        
        if (file_exists($defaultFile)) {
            $translations = json_decode(file_get_contents($defaultFile), true) ?? [];
            
            foreach ($generatedTextKeys as $varName => $textKey) {
                // Use dot notation to set nested key
                $keys = explode('.', $textKey);
                $ref = &$translations;
                foreach ($keys as $i => $k) {
                    if ($i === count($keys) - 1) {
                        // Last key - set empty value if not exists
                        if (!isset($ref[$k])) {
                            $ref[$k] = '';
                            $translationsCreated[] = $textKey;
                        }
                    } else {
                        // Intermediate key - ensure array exists
                        if (!isset($ref[$k]) || !is_array($ref[$k])) {
                            $ref[$k] = [];
                        }
                        $ref = &$ref[$k];
                    }
                }
            }
            
            // Save translations
            file_put_contents($defaultFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    
    // Save structure
    $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (file_put_contents($jsonPath, json_encode($updatedStructure, $jsonOptions)) === false) {
        return ApiResponse::create(500, 'error.fileWrite')
            ->withMessage("Failed to save structure file");
    }
    
    // Render the newly added component to HTML for live DOM update
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
        
        $renderedHtml = $renderer->renderNodeAtPath($updatedStructure, $newNodeId, $structureName);
    } catch (Exception $e) {
        // Non-fatal - we can still return success without rendered HTML
        error_log("Failed to render component HTML: " . $e->getMessage());
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Component '{$componentName}' added successfully")
        ->withData([
            'nodeId' => $newNodeId,
            'component' => $componentName,
            'position' => $position,
            'targetNodeId' => $targetNodeId,
            'data' => $finalData,
            'variables' => array_values($variables),
            'generatedTextKeys' => $generatedTextKeys,
            'translationsCreated' => $translationsCreated,
            'instanceNumber' => $instanceNumber,
            'html' => $renderedHtml
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addComponentToNode($trimParams->params(), $trimParams->additionalParams())->send();
}
