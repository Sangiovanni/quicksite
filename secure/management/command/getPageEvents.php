<?php
/**
 * getPageEvents - Get page-level events (onload, onresize, etc.)
 * 
 * These events apply to the <body> or document level for a specific page.
 * Used for triggering API calls, animations, or setup on page load.
 * 
 * @method GET
 * @url /management/getPageEvents/{pageName}
 * @auth required
 * @permission read
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Include shared interaction helpers for parseCallSyntax
require_once SECURE_FOLDER_PATH . '/src/functions/interactionHelpers.php';

/**
 * @param array $params Body parameters (unused)
 * @param array $urlParams [pageName]
 * @return ApiResponse
 */
function __command_getPageEvents(array $params = [], array $urlParams = []): ApiResponse {
    $pageName = $urlParams[0] ?? null;
    
    if (!$pageName) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameter: pageName');
    }
    
    // Validate page exists
    $specialPages = ['404', '500', '403', '401'];
    if (!routeExists($pageName, ROUTES) && !in_array($pageName, $specialPages, true)) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$pageName}' does not exist");
    }
    
    // Load page events file
    $eventsFile = PROJECT_PATH . '/data/page-events.json';
    $allEvents = [];
    
    if (file_exists($eventsFile)) {
        $content = @file_get_contents($eventsFile);
        if ($content !== false) {
            $allEvents = json_decode($content, true) ?: [];
        }
    }
    
    $pageEvents = $allEvents[$pageName] ?? [];
    
    // Available page-level events
    $availableEvents = ['onload', 'onresize', 'onscroll'];
    
    // Parse interactions per event
    $interactions = [];
    foreach ($pageEvents as $event => $calls) {
        if (!is_array($calls)) continue;
        foreach ($calls as $index => $callSyntax) {
            $parsed = parseCallSyntax($callSyntax);
            if (!empty($parsed)) {
                $interactions[] = [
                    'event' => $event,
                    'index' => $index,
                    'function' => $parsed[0]['function'],
                    'params' => $parsed[0]['params'],
                    'raw' => $callSyntax
                ];
            }
        }
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Page events retrieved')
        ->withData([
            'pageName' => $pageName,
            'events' => $pageEvents,
            'interactions' => $interactions,
            'availableEvents' => $availableEvents,
            'count' => count($interactions)
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getPageEvents($trimParams->params(), $trimParams->additionalParams())->send();
}
