<?php
/**
 * editPageEvent - Edit an existing page-level event interaction
 *
 * Replace an interaction in body/document-level events (onload, onresize,
 * onscroll) at a specific index for a given page. Optionally move the
 * interaction to a different page-level event (newEvent).
 *
 * Mirrors editInteraction.php (which edits element-level interactions)
 * but operates on the simpler data/page-events.json shape:
 *   { "<route>": { "onload": ["{{call:...}}", ...], "onscroll": [...] } }
 *
 * @method PUT
 * @url /management/editPageEvent
 * @auth required
 * @permission editStructure
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Include shared interaction helpers (generateCallSyntax)
require_once SECURE_FOLDER_PATH . '/src/functions/interactionHelpers.php';

/**
 * @param array $params Body parameters: pageName, event, index, function, params[], newEvent?
 * @param array $urlParams (unused)
 * @return ApiResponse
 */
function __command_editPageEvent(array $params = [], array $urlParams = []): ApiResponse {
    // ==========================================================================
    // PARAMETER VALIDATION
    // ==========================================================================

    $required = ['pageName', 'event', 'index', 'function'];
    $missing = [];

    foreach ($required as $field) {
        // Treat literal 0 as present (index can legitimately be 0).
        if (!isset($params[$field]) || ($params[$field] === '' && $params[$field] !== 0)) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameters: ' . implode(', ', $missing))
            ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing));
    }

    $pageName = $params['pageName'];
    $event = $params['event'];
    $index = (int) $params['index'];
    $function = $params['function'];
    $functionParams = $params['params'] ?? [];
    $newEvent = $params['newEvent'] ?? null;

    // Validate index
    if ($index < 0) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Index must be a non-negative integer')
            ->withErrors([['field' => 'index', 'value' => $index]]);
    }

    // Page-level events are a small fixed set — keep this list aligned with
    // addPageEvent / deletePageEvent / JsonToPhpCompiler::compilePageEvents.
    $allowedEvents = ['onload', 'onresize', 'onscroll'];
    if (!in_array($event, $allowedEvents, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid page event: {$event}. Allowed: " . implode(', ', $allowedEvents))
            ->withErrors([['field' => 'event', 'value' => $event, 'allowed' => $allowedEvents]]);
    }

    // Validate newEvent (when provided and different)
    if ($newEvent !== null && $newEvent !== $event) {
        if (!in_array($newEvent, $allowedEvents, true)) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage("Invalid newEvent: {$newEvent}. Allowed: " . implode(', ', $allowedEvents))
                ->withErrors([['field' => 'newEvent', 'value' => $newEvent, 'allowed' => $allowedEvents]]);
        }
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
    // VALIDATE PAGE EXISTS
    // ==========================================================================

    $specialPages = ['404', '500', '403', '401'];
    if (!routeExists($pageName, ROUTES) && !in_array($pageName, $specialPages, true)) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '{$pageName}' does not exist");
    }

    // ==========================================================================
    // LOAD PAGE EVENTS FILE
    // ==========================================================================

    $eventsFile = PROJECT_PATH . '/data/page-events.json';

    if (!file_exists($eventsFile)) {
        return ApiResponse::create(404, 'data.not_found')
            ->withMessage('No page events file found');
    }

    $content = @file_get_contents($eventsFile);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read page events file');
    }

    $allEvents = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Invalid JSON in page events file: ' . json_last_error_msg());
    }
    if (!is_array($allEvents)) {
        $allEvents = [];
    }

    // ==========================================================================
    // LOCATE INTERACTION
    // ==========================================================================

    if (!isset($allEvents[$pageName][$event]) || !is_array($allEvents[$pageName][$event])) {
        return ApiResponse::create(404, 'interaction.not_found')
            ->withMessage("No interactions found for page '{$pageName}' event '{$event}'");
    }

    $eventCalls = $allEvents[$pageName][$event];

    if ($index >= count($eventCalls)) {
        return ApiResponse::create(404, 'interaction.not_found')
            ->withMessage("Interaction at index {$index} not found. Event has " . count($eventCalls) . " interactions.")
            ->withData(['maxIndex' => count($eventCalls) - 1]);
    }

    // ==========================================================================
    // REPLACE OR MOVE
    // ==========================================================================

    $newCallSyntax = generateCallSyntax($function, $functionParams);
    $oldCallSyntax = $eventCalls[$index];

    if ($newEvent !== null && $newEvent !== $event) {
        // Move: splice out of the old event, push onto the new event.
        array_splice($allEvents[$pageName][$event], $index, 1);

        // Clean up: drop empty per-event array.
        if (empty($allEvents[$pageName][$event])) {
            unset($allEvents[$pageName][$event]);
        }

        if (!isset($allEvents[$pageName][$newEvent]) || !is_array($allEvents[$pageName][$newEvent])) {
            $allEvents[$pageName][$newEvent] = [];
        }
        $allEvents[$pageName][$newEvent][] = $newCallSyntax;
        $finalEvent = $newEvent;
        $finalIndex = count($allEvents[$pageName][$newEvent]) - 1;
    } else {
        // Same-event replace at index.
        $allEvents[$pageName][$event][$index] = $newCallSyntax;
        $finalEvent = $event;
        $finalIndex = $index;
    }

    // Clean up: drop empty per-page object (only possible after a move that
    // emptied the source event and the page had no other events).
    if (isset($allEvents[$pageName]) && empty($allEvents[$pageName])) {
        unset($allEvents[$pageName]);
    }

    // ==========================================================================
    // SAVE
    // ==========================================================================

    $jsonOutput = json_encode($allEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($jsonOutput === false) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Failed to encode page events JSON');
    }

    if (file_put_contents($eventsFile, $jsonOutput, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save page events file');
    }

    $responseData = [
        'pageName' => $pageName,
        'event' => $finalEvent,
        'index' => $finalIndex,
        'oldCallSyntax' => $oldCallSyntax,
        'newInteraction' => [
            'function' => $function,
            'params' => $functionParams,
            'raw' => $newCallSyntax
        ]
    ];

    if ($newEvent !== null && $newEvent !== $event) {
        $responseData['eventChanged'] = true;
        $responseData['oldEvent'] = $event;
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Page event updated successfully')
        ->withData($responseData);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editPageEvent($trimParams->params(), $trimParams->additionalParams())->send();
}
