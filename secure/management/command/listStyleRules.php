<?php
/**
 * listStyleRules - List all CSS selectors in the stylesheet
 * Method: GET
 * URL: /management/listStyleRules
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
$selectors = $parser->listSelectors();

// Group by media query for better organization
$grouped = [
    'global' => [],
    'media' => []
];

foreach ($selectors as $item) {
    if ($item['mediaQuery'] === null) {
        $grouped['global'][] = $item['selector'];
    } else {
        $mediaKey = $item['mediaQuery'];
        if (!isset($grouped['media'][$mediaKey])) {
            $grouped['media'][$mediaKey] = [];
        }
        $grouped['media'][$mediaKey][] = $item['selector'];
    }
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Style rules listed successfully')
    ->withData([
        'selectors' => $selectors,
        'grouped' => $grouped,
        'total_global' => count($grouped['global']),
        'total_media_queries' => count($grouped['media']),
        'total' => count($selectors)
    ])
    ->send();
