<?php
/**
 * getStyleRule - Get CSS styles for a specific selector
 * 
 * @method GET
 * @url /management/getStyleRule/{selector}
 * @url /management/getStyleRule/{selector}/{mediaQuery}
 * @auth required
 * @permission read
 * 
 * Note: Selector should be URL-encoded if it contains special characters
 * Example: /management/getStyleRule/.btn-primary
 * Example with media: /management/getStyleRule/.hero/screen%20and%20(max-width%3A%20768px)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [selector, mediaQuery?]
 * @return ApiResponse
 */
function __command_getStyleRule(array $params = [], array $urlParams = []): ApiResponse {
    $selector = $urlParams[0] ?? null;
    $mediaQuery = isset($urlParams[1]) ? urldecode($urlParams[1]) : null;

    if (empty($selector)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameter: selector');
    }

    // URL decode the selector
    $selector = urldecode($selector);

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
    $rule = $parser->getStyleRule($selector, $mediaQuery);

    if ($rule === null) {
        $context = $mediaQuery ? " in @media $mediaQuery" : ' in global scope';
        return ApiResponse::create(404, 'selector.not_found')
            ->withMessage("Selector '$selector' not found" . $context);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Style rule retrieved successfully')
        ->withData($rule);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlParams = $trimParametersManagement->additionalParams();
    __command_getStyleRule([], $urlParams)->send();
}
