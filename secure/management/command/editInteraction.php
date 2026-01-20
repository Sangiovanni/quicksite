<?php
/**
 * editInteraction - Edit an existing interaction on a node
 * 
 * @method PUT
 * @url /management/editInteraction
 * @auth required
 * @permission editStructure
 * 
 * Replaces an interaction at a specific index within an event.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Include shared interaction helpers
require_once __DIR__ . '/_interactionHelpers.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_editInteraction(array $params = [], array $urlParams = []): ApiResponse {
    // ==========================================================================
    // PARAMETER VALIDATION
    // ==========================================================================
    
    $required = ['structType', 'nodeId', 'event', 'index', 'function'];
    $missing = [];
    
    foreach ($required as $field) {
        if (!isset($params[$field]) && $params[$field] !== 0) {
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
    $index = (int) $params['index'];
    $function = $params['function'];
    $functionParams = $params['params'] ?? [];
    
    // Validate structType
    $allowedTypes = ['menu', 'footer', 'page', 'component'];
    if (!in_array($structType, $allowedTypes, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Invalid structType. Must be one of: ' . implode(', ', $allowedTypes))
            ->withErrors([['field' => 'structType', 'value' => $structType, 'allowed' => $allowedTypes]]);
    }
    
    // Validate index is non-negative
    if ($index < 0) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Index must be a non-negative integer')
            ->withErrors([['field' => 'index', 'value' => $index]]);
    }
    
    // Validate event is a valid event attribute
    $allEvents = array_merge(UNIVERSAL_EVENTS, array_merge(...array_values(TAG_SPECIFIC_EVENTS)));
    if (!in_array($event, $allEvents, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid event: {$event}")
            ->withErrors([['field' => 'event', 'value' => $event]]);
    }
    
    // Validate function name
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $function)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid function name format')
            ->withErrors([['field' => 'function', 'value' => $function]]);
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
    // FIND NODE
    // ==========================================================================
    
    $node = NodeNavigator::getNode($structure, $nodeId);
    
    if ($node === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found: {$nodeId}");
    }
    
    // ==========================================================================
    // PARSE AND UPDATE INTERACTIONS
    // ==========================================================================
    
    $currentValue = $node['params'][$event] ?? '';
    
    if (empty($currentValue)) {
        return ApiResponse::create(404, 'interaction.not_found')
            ->withMessage("No interactions found on event '{$event}'");
    }
    
    // Parse existing interactions
    $existingInteractions = parseCallSyntax($currentValue);
    
    if ($index >= count($existingInteractions)) {
        return ApiResponse::create(404, 'interaction.not_found')
            ->withMessage("Interaction at index {$index} not found. Event has " . count($existingInteractions) . " interactions.")
            ->withData(['maxIndex' => count($existingInteractions) - 1]);
    }
    
    // Generate new call syntax
    $newCallSyntax = generateCallSyntax($function, $functionParams);
    $oldCallSyntax = $existingInteractions[$index]['raw'];
    
    // Replace the interaction at index
    $existingInteractions[$index] = [
        'function' => $function,
        'params' => $functionParams,
        'raw' => $newCallSyntax
    ];
    
    // Rebuild event value
    $newValue = implode(' ', array_map(fn($i) => $i['raw'], $existingInteractions));
    
    // Update node params
    $node['params'][$event] = $newValue;
    
    // Update structure
    $updateResult = NodeNavigator::updateNode($structure, $nodeId, $node);
    
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
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Interaction updated successfully')
        ->withData([
            'event' => $event,
            'index' => $index,
            'oldInteraction' => $oldCallSyntax,
            'newInteraction' => [
                'function' => $function,
                'params' => $functionParams,
                'raw' => $newCallSyntax
            ],
            'fullEventValue' => $newValue
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParametersManagement = new TrimParametersManagement();
    __command_editInteraction($trimParametersManagement->params(), [])->send();
}
