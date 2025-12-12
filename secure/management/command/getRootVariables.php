<?php
/**
 * getRootVariables - Get all CSS custom properties from :root
 * Method: GET
 * URL: /management/getRootVariables
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';

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
$variables = $parser->getRootVariables();

ApiResponse::create(200, 'operation.success')
    ->withMessage('Root variables retrieved successfully')
    ->withData([
        'variables' => $variables,
        'count' => count($variables)
    ])
    ->send();
