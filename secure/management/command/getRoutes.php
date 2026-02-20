<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * getRoutes - Retrieves the list of all available routes/pages
 * 
 * @method GET
 * @url /management/getRoutes
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getRoutes(array $params = [], array $urlParams = []): ApiResponse {
    // Flatten nested routes for easy listing
    $flatRoutes = flattenRoutes(ROUTES);
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Routes retrieved successfully')
        ->withData([
            'routes' => ROUTES,           // Nested structure
            'flat_routes' => $flatRoutes, // Flat list of all route paths
            'count' => count($flatRoutes)
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getRoutes()->send();
}