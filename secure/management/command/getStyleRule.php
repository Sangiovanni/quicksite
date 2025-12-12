<?php
/**
 * getStyleRule - Get CSS styles for a specific selector
 * Method: GET
 * URL: /management/getStyleRule/{selector}
 * URL: /management/getStyleRule/{selector}/{mediaQuery}
 * 
 * Note: Selector should be URL-encoded if it contains special characters
 * Example: /management/getStyleRule/.btn-primary
 * Example with media: /management/getStyleRule/.hero/screen%20and%20(max-width%3A%20768px)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';

// Get selector from URL parameter
$urlParams = $trimParametersManagement->additionalParams();
$selector = $urlParams[0] ?? null;
$mediaQuery = isset($urlParams[1]) ? urldecode($urlParams[1]) : null;

if (empty($selector)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: selector')
        ->send();
}

// URL decode the selector
$selector = urldecode($selector);

$styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

// Check file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Style file not found')
        ->send();
}

// Read CSS content
$content = file_get_contents($styleFile);
if ($content === false) {
    ApiResponse::create(500, 'server.file_read_failed')
        ->withMessage('Failed to read style file')
        ->send();
}

// Parse CSS
$parser = new CssParser($content);
$rule = $parser->getStyleRule($selector, $mediaQuery);

if ($rule === null) {
    $context = $mediaQuery ? " in @media $mediaQuery" : ' in global scope';
    ApiResponse::create(404, 'selector.not_found')
        ->withMessage("Selector '$selector' not found" . $context)
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Style rule retrieved successfully')
    ->withData($rule)
    ->send();
