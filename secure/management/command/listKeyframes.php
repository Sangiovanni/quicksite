<?php
/**
 * listKeyframes - List all @keyframes animations in the stylesheet
 * 
 * @method GET
 * @url /management/listKeyframes
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
function __command_listKeyframes(array $params = [], array $urlParams = []): ApiResponse {
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

    // Parse CSS and get keyframes
    $parser = new CssParser($content);
    $keyframes = $parser->getKeyframes();

    // Format for API response
    $result = [];
    foreach ($keyframes as $name => $frames) {
        $result[] = [
            'name' => $name,
            'frameCount' => count($frames),
            'frames' => array_keys($frames)
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Keyframes listed successfully')
        ->withData([
            'keyframes' => $result,
            'count' => count($result)
        ]);
}

// Direct execution via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listKeyframes($trimParams->params(), $trimParams->additionalParams())->send();
}
