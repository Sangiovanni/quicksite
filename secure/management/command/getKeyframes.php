<?php
/**
 * getKeyframes - Get all @keyframes animations
 * 
 * @method GET
 * @url /management/getKeyframes
 * @url /management/getKeyframes/{name}
 * @auth required
 * @permission read
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [name?]
 * @return ApiResponse
 */
function __command_getKeyframes(array $params = [], array $urlParams = []): ApiResponse {
    $name = $urlParams[0] ?? null;

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
    $keyframes = $parser->getKeyframes();

    if ($name !== null) {
        // Return specific keyframe
        if (!isset($keyframes[$name])) {
            return ApiResponse::create(404, 'keyframe.not_found')
                ->withMessage("Keyframe animation '$name' not found");
        }
        
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Keyframe retrieved successfully')
            ->withData([
                'name' => $name,
                'frames' => $keyframes[$name]
            ]);
    } else {
        // Return all keyframes
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Keyframes retrieved successfully')
            ->withData([
                'keyframes' => $keyframes,
                'count' => count($keyframes)
            ]);
    }
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getKeyframes([], $urlSegments)->send();
}
