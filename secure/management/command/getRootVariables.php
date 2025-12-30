<?php
/**
 * getRootVariables - Get all CSS custom properties from :root
 * 
 * @method GET
 * @url /management/getRootVariables
 * @auth required
 * @permission read
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getRootVariables(array $params = [], array $urlParams = []): ApiResponse {
    $styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

    // Check file exists
    if (!file_exists($styleFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('Style file not found');
    }

    // Read CSS content
    $content = file_get_contents($styleFile);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read style file');
    }

    // Parse CSS
    $parser = new CssParser($content);
    $variables = $parser->getRootVariables();

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Root variables retrieved successfully')
        ->withData([
            'variables' => $variables,
            'count' => count($variables)
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getRootVariables()->send();
}
