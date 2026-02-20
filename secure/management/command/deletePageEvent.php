<?php
/**
 * deletePageEvent - Delete a page-level event interaction
 * 
 * Remove an interaction from body/document-level events by event name and index.
 * 
 * @method DELETE
 * @url /management/deletePageEvent
 * @auth required
 * @permission editStructure
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * @param array $params Body parameters: pageName, event, index
 * @param array $urlParams (unused)
 * @return ApiResponse
 */
function __command_deletePageEvent(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required fields
    $required = ['pageName', 'event', 'index'];
    $missing = [];
    foreach ($required as $field) {
        if (!isset($params[$field]) && $params[$field] !== 0) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameters: ' . implode(', ', $missing));
    }
    
    $pageName = $params['pageName'];
    $event = $params['event'];
    $index = (int)$params['index'];
    
    // Load existing page events
    $eventsFile = PROJECT_PATH . '/data/page-events.json';
    
    if (!file_exists($eventsFile)) {
        return ApiResponse::create(404, 'data.not_found')
            ->withMessage('No page events found');
    }
    
    $content = @file_get_contents($eventsFile);
    $allEvents = json_decode($content, true) ?: [];
    
    // Check page and event exist
    if (!isset($allEvents[$pageName][$event]) || !is_array($allEvents[$pageName][$event])) {
        return ApiResponse::create(404, 'data.not_found')
            ->withMessage("No events found for page '{$pageName}' event '{$event}'");
    }
    
    // Check index is valid
    if ($index < 0 || $index >= count($allEvents[$pageName][$event])) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid index: {$index}");
    }
    
    // Remove the interaction
    $removed = $allEvents[$pageName][$event][$index];
    array_splice($allEvents[$pageName][$event], $index, 1);
    
    // Clean up empty arrays
    if (empty($allEvents[$pageName][$event])) {
        unset($allEvents[$pageName][$event]);
    }
    if (empty($allEvents[$pageName])) {
        unset($allEvents[$pageName]);
    }
    
    // Save
    $result = file_put_contents($eventsFile, json_encode($allEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($result === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save page events');
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Page event deleted successfully')
        ->withData([
            'pageName' => $pageName,
            'event' => $event,
            'index' => $index,
            'removed' => $removed
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deletePageEvent($trimParams->params(), $trimParams->additionalParams())->send();
}
