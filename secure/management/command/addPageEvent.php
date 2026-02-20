<?php
/**
 * addPageEvent - Add a page-level event interaction
 * 
 * Add an interaction ({{call:...}}) to body/document-level events
 * like onload, onresize, or onscroll for a specific page.
 * 
 * @method POST
 * @url /management/addPageEvent
 * @auth required
 * @permission editStructure
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Include shared interaction helpers
require_once SECURE_FOLDER_PATH . '/src/functions/interactionHelpers.php';

/**
 * @param array $params Body parameters: pageName, event, function, params[]
 * @param array $urlParams (unused)
 * @return ApiResponse
 */
function __command_addPageEvent(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required fields
    $required = ['pageName', 'event', 'function'];
    $missing = [];
    foreach ($required as $field) {
        if (!isset($params[$field]) || $params[$field] === '') {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameters: ' . implode(', ', $missing));
    }
    
    $pageName = $params['pageName'];
    $event = $params['event'];
    $function = $params['function'];
    $functionParams = $params['params'] ?? [];
    
    // Validate page exists
    $specialPages = ['404', '500', '403', '401'];
    if (!routeExists($pageName, ROUTES) && !in_array($pageName, $specialPages, true)) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$pageName}' does not exist");
    }
    
    // Validate event is a valid page-level event
    $allowedEvents = ['onload', 'onresize', 'onscroll'];
    if (!in_array($event, $allowedEvents, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid page event: {$event}. Allowed: " . implode(', ', $allowedEvents));
    }
    
    // Validate function name
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $function)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid function name format');
    }
    
    // Ensure params is array
    if (!is_array($functionParams)) {
        $functionParams = [$functionParams];
    }
    
    // Generate call syntax
    $callSyntax = generateCallSyntax($function, $functionParams);
    
    // Load existing page events
    $eventsFile = PROJECT_PATH . '/data/page-events.json';
    $allEvents = [];
    
    if (file_exists($eventsFile)) {
        $content = @file_get_contents($eventsFile);
        if ($content !== false) {
            $allEvents = json_decode($content, true) ?: [];
        }
    }
    
    // Initialize page and event arrays if needed
    if (!isset($allEvents[$pageName])) {
        $allEvents[$pageName] = [];
    }
    if (!isset($allEvents[$pageName][$event])) {
        $allEvents[$pageName][$event] = [];
    }
    
    // Add the interaction
    $allEvents[$pageName][$event][] = $callSyntax;
    
    // Save
    $dataDir = PROJECT_PATH . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    
    $result = file_put_contents($eventsFile, json_encode($allEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($result === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save page events');
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Page event added successfully')
        ->withData([
            'pageName' => $pageName,
            'event' => $event,
            'callSyntax' => $callSyntax,
            'index' => count($allEvents[$pageName][$event]) - 1
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addPageEvent($trimParams->params(), $trimParams->additionalParams())->send();
}
