<?php
/**
 * deleteStyleRule - Remove a CSS style rule
 * Method: POST
 * URL: /management/deleteStyleRule
 * Body: {
 *   "selector": ".btn-custom",
 *   "mediaQuery": "(max-width: 768px)"  // optional
 * }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';

// Get parameters
$params = $trimParametersManagement->params();

// Validate required parameter
if (!isset($params['selector'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: selector')
        ->send();
}

$selector = trim($params['selector']);
$mediaQuery = isset($params['mediaQuery']) ? trim($params['mediaQuery']) : null;

// Validate selector
if (empty($selector)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Selector cannot be empty')
        ->send();
}

$styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

// Check file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Style file not found')
        ->send();
}

// Use file locking
$lockFile = sys_get_temp_dir() . '/quicksite_style_' . md5($styleFile) . '.lock';
$lock = fopen($lockFile, 'w');

if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    ApiResponse::create(500, 'server.lock_failed')
        ->withMessage('Could not acquire file lock')
        ->send();
}

try {
    // Read current content
    $content = file_get_contents($styleFile);
    if ($content === false) {
        throw new Exception('Failed to read style file');
    }
    
    // Parse and delete
    $parser = new CssParser($content);
    $deleted = $parser->deleteStyleRule($selector, $mediaQuery);
    
    if (!$deleted) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $context = $mediaQuery ? " in @media $mediaQuery" : ' in global scope';
        ApiResponse::create(404, 'selector.not_found')
            ->withMessage("Selector '$selector' not found" . $context)
            ->send();
    }
    
    // Write updated content
    if (file_put_contents($styleFile, $parser->getContent()) === false) {
        throw new Exception('Failed to write style file');
    }
    
    flock($lock, LOCK_UN);
    fclose($lock);
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Style rule deleted successfully')
        ->withData([
            'selector' => $selector,
            'mediaQuery' => $mediaQuery
        ])
        ->send();
    
} catch (Exception $e) {
    flock($lock, LOCK_UN);
    fclose($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
