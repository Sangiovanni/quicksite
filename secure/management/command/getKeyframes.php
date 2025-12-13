<?php
/**
 * getKeyframes - Get all @keyframes animations
 * Method: GET
 * URL: /management/getKeyframes
 * URL: /management/getKeyframes/{name}
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';

// Optional: get specific keyframe by name from URL segment: /management/getKeyframes/{name?}
$urlSegments = $trimParametersManagement->additionalParams();
$name = $urlSegments[0] ?? null;

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
$keyframes = $parser->getKeyframes();

if ($name !== null) {
    // Return specific keyframe
    if (!isset($keyframes[$name])) {
        ApiResponse::create(404, 'keyframe.not_found')
            ->withMessage("Keyframe animation '$name' not found")
            ->send();
    }
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Keyframe retrieved successfully')
        ->withData([
            'name' => $name,
            'frames' => $keyframes[$name]
        ])
        ->send();
} else {
    // Return all keyframes
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Keyframes retrieved successfully')
        ->withData([
            'keyframes' => $keyframes,
            'count' => count($keyframes)
        ])
        ->send();
}
