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
 * @param array $params Body parameters — optional: themeTarget ("light"|"dark")
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getRootVariables(array $params = [], array $urlParams = []): ApiResponse {
    $styleFile = PUBLIC_CONTENT_PATH . '/style/style.css';

    // Resolve themeTarget → CSS scope selector ("light" or omitted → :root, "dark" → [data-theme="dark"])
    $themeTarget = isset($params['themeTarget']) ? trim($params['themeTarget']) : 'light';
    if (!in_array($themeTarget, ['light', 'dark'], true)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('themeTarget must be "light" or "dark"');
    }
    $cssScope = ($themeTarget === 'dark') ? '[data-theme="dark"]' : ':root';

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
    $variables = $parser->getVariablesInScope($cssScope);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Root variables retrieved successfully')
        ->withData([
            'variables' => $variables,
            'count' => count($variables),
            'theme_target' => $themeTarget
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    // Forward parsed request params so query/body themeTarget is honored.
    $params = isset($trimParametersManagement) ? $trimParametersManagement->params() : [];
    $urlParams = isset($trimParametersManagement) ? $trimParametersManagement->additionalParams() : [];
    __command_getRootVariables($params, $urlParams)->send();
}
