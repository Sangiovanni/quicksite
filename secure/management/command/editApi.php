<?php
/**
 * editApi - Modify an existing API group or its endpoints
 * 
 * @method POST
 * @url /management/editApi
 * @auth required
 * @permission editor
 * 
 * Body (JSON): {
 *     "apiId": "main-backend",           // Required: API to edit
 *     
 *     // API-level updates (all optional):
 *     "name": "New API Name",
 *     "baseUrl": "https://new-api.example.com",
 *     "description": "Updated description",
 *     "auth": { "type": "bearer", "tokenSource": "localStorage:newToken" },
 *     
 *     // Endpoint operations (use ONE of these):
 *     "endpoints": [...],                // Full replacement of all endpoints
 *     "addEndpoint": { ... },            // Add a single endpoint
 *     "editEndpoint": { "id": "...", "updates": {...} },  // Edit endpoint (same id)
 *     "renameEndpoint": { "from": "...", "to": "...", "updates": {...} },  // Rename id + re-point references
 *     "deleteEndpoint": "endpoint-id"    // Delete endpoint by ID
 * }
 * 
 * Endpoint object structure:
 * {
 *     "id": "contact-submit",            // Required: Unique within API
 *     "name": "Submit Contact Form",     // Required: Display name
 *     "path": "/contact",                // Required: Path (appended to baseUrl)
 *     "method": "POST",                  // Required: GET, POST, PUT, PATCH, DELETE
 *     "description": "...",              // Optional
 *     "headers": { "X-Custom": "value" }, // Optional: Extra headers
 *     "requestSchema": { ... },          // Optional: Expected request body schema
 *     "responseSchema": { ... },         // Optional: Expected response schema
 *     "queryParams": [ ... ],            // Optional: For GET requests
 *     "responseBindings": { ... }        // Optional: DOM update mappings
 * }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';
require_once SECURE_FOLDER_PATH . '/src/classes/EnumSyncHelper.php';
require_once SECURE_FOLDER_PATH . '/src/functions/cascadeCleanupHelpers.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_editApi(array $params = [], array $urlParams = []): ApiResponse {
    $apiId = $params['apiId'] ?? null;
    
    if (!$apiId || !is_string($apiId)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing or invalid "apiId" parameter');
    }
    
    // Build updates array from params
    $updates = [];
    
    // API-level fields
    $apiFields = ['name', 'baseUrl', 'description', 'auth'];
    foreach ($apiFields as $field) {
        if (isset($params[$field])) {
            $updates[$field] = $params[$field];
        }
    }
    
    // Endpoint operations
    if (isset($params['endpoints'])) {
        $updates['endpoints'] = $params['endpoints'];
    }
    if (isset($params['addEndpoint'])) {
        $updates['addEndpoint'] = $params['addEndpoint'];
    }
    if (isset($params['editEndpoint'])) {
        $updates['editEndpoint'] = $params['editEndpoint'];
    }
    if (isset($params['deleteEndpoint'])) {
        $updates['deleteEndpoint'] = $params['deleteEndpoint'];
    }
    if (isset($params['renameEndpoint'])) {
        $updates['renameEndpoint'] = $params['renameEndpoint'];
    }

    if (empty($updates)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('No updates provided. Specify at least one field to update.');
    }
    
    $manager = new ApiEndpointManager();
    $result = $manager->editApi($apiId, $updates);
    
    if (!$result['success']) {
        $code = strpos($result['error'], 'not found') !== false ? 404 : 400;
        return ApiResponse::create($code, 'api.error.invalid_parameter')
            ->withMessage($result['error']);
    }
    
    // Regenerate qs-api-config.js for live development
    $apiConfigPath = PUBLIC_CONTENT_PATH . '/scripts/qs-api-config.js';
    $manager->writeCompiledJs($apiConfigPath);

    // Keep qs-enums.js in sync with the new endpoint shape — bindings on
    // a freshly-edited endpoint might reference enums that weren't in
    // the registry yet, and bindings that were removed should drop
    // their unreferenced entries. The sync is forgiving: missing
    // references become warnings (QS.enum falls back gracefully) so a
    // bad binding doesn't block the save.
    $enumSync = EnumSyncHelper::sync();

    // Cascade cleanup when an endpoint was deleted
    $cascadeCleanup = null;
    if (isset($updates['deleteEndpoint'])) {
        $deletedEndpointId = $updates['deleteEndpoint'];
        $pageEventsCleanup = cleanPageEventsForApiEndpoint($apiId, $deletedEndpointId);
        $interactionsCleanup = cleanInteractionsForApiEndpoint($apiId, $deletedEndpointId);
        $cascadeCleanup = [
            'pageEventsRemoved' => count($pageEventsCleanup['removedCalls']),
            'interactionsRemoved' => count($interactionsCleanup['removedInteractions']),
            'modifiedFiles' => $interactionsCleanup['modifiedFiles']
        ];
    }

    // Cascade rename when an endpoint id changed — re-point references
    // (interactions + page events) from the old id to the new one instead of
    // dropping them. refreshEndpoint refs were already rewritten in-config by
    // ApiEndpointManager::editApi.
    $cascadeRename = null;
    if (!empty($result['renamed'])) {
        $from = $result['renamed']['from'];
        $to = $result['renamed']['to'];
        $pageEventsRename = renamePageEventsForApiEndpoint($apiId, $from, $to);
        $interactionsRename = renameInteractionsForApiEndpoint($apiId, $from, $to);
        $cascadeRename = [
            'from' => $from,
            'to' => $to,
            'pageEventsUpdated' => count($pageEventsRename['renamedCalls']),
            'interactionsUpdated' => count($interactionsRename['renamedInteractions']),
            'modifiedFiles' => $interactionsRename['modifiedFiles']
        ];
    }
    
    $responseData = [
        'apiId' => $result['apiId'],
        'api' => $result['api'],
        'endpointCount' => count($result['api']['endpoints'] ?? []),
        'enumSync' => [
            'written' => $enumSync['written'] ?? false,
            'count' => $enumSync['count'] ?? 0,
            'warnings' => $enumSync['warnings'] ?? [],
        ],
    ];
    if ($cascadeCleanup) {
        $responseData['cascadeCleanup'] = $cascadeCleanup;
    }
    if ($cascadeRename) {
        $responseData['cascadeRename'] = $cascadeRename;
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("API '$apiId' updated successfully")
        ->withData($responseData);
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editApi($trimParams->params(), $trimParams->additionalParams())->send();
}
