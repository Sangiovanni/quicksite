<?php
/**
 * addInteraction - Add an interaction ({{call:...}}) to a node's event param
 * 
 * @method POST
 * @url /management/addInteraction
 * @auth required
 * @permission editStructure
 * 
 * Adds a {{call:function:params}} to the specified event attribute.
 * If the event already has interactions, appends to existing value.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Include shared interaction helpers (constants, parseCallSyntax, generateCallSyntax, etc.)
require_once __DIR__ . '/_interactionHelpers.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_addInteraction(array $params = [], array $urlParams = []): ApiResponse {
    // ==========================================================================
    // PARAMETER VALIDATION
    // ==========================================================================
    
    $required = ['structType', 'nodeId', 'event', 'function'];
    $missing = [];
    
    foreach ($required as $field) {
        if (!isset($params[$field]) || $params[$field] === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameters: ' . implode(', ', $missing))
            ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing));
    }
    
    $structType = $params['structType'];
    $nodeId = $params['nodeId'];
    $event = $params['event'];
    $function = $params['function'];
    $functionParams = $params['params'] ?? [];
    
    // Validate structType
    $allowedTypes = ['menu', 'footer', 'page', 'component'];
    if (!in_array($structType, $allowedTypes, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Invalid structType. Must be one of: ' . implode(', ', $allowedTypes))
            ->withErrors([['field' => 'structType', 'value' => $structType, 'allowed' => $allowedTypes]]);
    }
    
    // Validate event is a valid event attribute
    $allEvents = array_merge(UNIVERSAL_EVENTS, array_merge(...array_values(TAG_SPECIFIC_EVENTS)));
    if (!in_array($event, $allEvents, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid event: {$event}")
            ->withErrors([['field' => 'event', 'value' => $event, 'hint' => 'Must be a valid HTML event attribute']]);
    }
    
    // Validate function name (alphanumeric + underscore)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $function)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid function name format')
            ->withErrors([['field' => 'function', 'value' => $function, 'hint' => 'Must be valid JS function name']]);
    }
    
    // Ensure params is array
    if (!is_array($functionParams)) {
        $functionParams = [$functionParams];
    }
    
    // ==========================================================================
    // DETERMINE PAGE/COMPONENT NAME
    // ==========================================================================
    
    $pageName = null;
    
    if ($structType === 'page' || $structType === 'component') {
        // pageName is required for page/component types
        if (!isset($params['pageName']) || $params['pageName'] === '') {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage("Missing required parameter: pageName (required for structType='{$structType}')")
                ->withErrors([['field' => 'pageName', 'reason' => 'required for page/component types']]);
        }
        
        $pageName = $params['pageName'];
        
        if ($structType === 'page') {
            $specialPages = ['404', '500', '403', '401'];
            if (!routeExists($pageName, ROUTES) && !in_array($pageName, $specialPages, true)) {
                return ApiResponse::create(404, 'route.not_found')
                    ->withMessage("Page '{$pageName}' does not exist");
            }
        }
    }
    
    // ==========================================================================
    // LOAD STRUCTURE FILE
    // ==========================================================================
    
    if ($structType === 'page') {
        $jsonFile = resolvePageJsonPath($pageName);
        if ($jsonFile === null) {
            return ApiResponse::create(404, 'file.not_found')
                ->withMessage("Structure file not found for page '{$pageName}'");
        }
    } elseif ($structType === 'component') {
        $jsonFile = PROJECT_PATH . '/templates/model/json/components/' . $pageName . '.json';
    } else {
        $jsonFile = PROJECT_PATH . '/templates/model/json/' . $structType . '.json';
    }
    
    if (!file_exists($jsonFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('Structure file not found');
    }
    
    $jsonContent = @file_get_contents($jsonFile);
    if ($jsonContent === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read structure file');
    }
    
    $structure = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Invalid JSON in structure file: ' . json_last_error_msg());
    }
    
    // ==========================================================================
    // FIND AND UPDATE NODE
    // ==========================================================================
    
    // nodeId is directly the path (e.g., "0", "0.1", "2.1.3")
    $actualNodeId = $nodeId;
    
    // Get node using numeric path
    $node = NodeNavigator::getNode($structure, $actualNodeId);
    
    if ($node === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found: {$actualNodeId}")
            ->withData(['nodeId' => $actualNodeId, 'structType' => $structType]);
    }
    
    // Generate the call syntax
    $callSyntax = generateCallSyntax($function, $functionParams);
    
    // Get current event value (if any)
    $currentValue = $node['params'][$event] ?? '';
    
    // Append to existing value (space-separated)
    $newValue = empty($currentValue) ? $callSyntax : $currentValue . ' ' . $callSyntax;
    
    // Update the node params
    $node['params'] = $node['params'] ?? [];
    $node['params'][$event] = $newValue;
    
    // Update structure using NodeNavigator
    $updateResult = NodeNavigator::updateNode($structure, $actualNodeId, $node);
    
    if (!$updateResult['success']) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Failed to update node: ' . ($updateResult['error'] ?? 'Unknown error'));
    }
    
    // ==========================================================================
    // SAVE STRUCTURE FILE
    // ==========================================================================
    
    $updatedStructure = $updateResult['structure'];
    $jsonOutput = json_encode($updatedStructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if (file_put_contents($jsonFile, $jsonOutput, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save structure file');
    }
    
    // NOTE: No PHP rebuild needed - system uses JSON directly for development
    // PHP files are only rebuilt during 'build' command for production
    
    return ApiResponse::create(201, 'operation.success')
        ->withMessage('Interaction added successfully')
        ->withData([
            'event' => $event,
            'interaction' => [
                'function' => $function,
                'params' => $functionParams,
                'raw' => $callSyntax
            ],
            'fullEventValue' => $newValue,
            'nodeId' => $nodeId
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParametersManagement = new TrimParametersManagement();
    __command_addInteraction($trimParametersManagement->params(), [])->send();
}
