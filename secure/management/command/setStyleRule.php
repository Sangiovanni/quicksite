<?php
/**
 * setStyleRule - Add or update a CSS style rule
 * Method: POST
 * URL: /management/setStyleRule
 * Body: {
 *   "selector": ".btn-custom",
 *   "styles": "background: #007bff; color: white; padding: 10px 20px;",
 *   "mediaQuery": "(max-width: 768px)",  // optional
 *   "removeProperties": ["border", "margin"]  // optional - properties to remove
 * }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// Get parameters
$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['selector'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: selector')
        ->send();
}
if (!isset($params['styles'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: styles')
        ->send();
}

$selector = trim($params['selector']);
$styles = $params['styles'];
$mediaQuery = isset($params['mediaQuery']) ? trim($params['mediaQuery']) : null;
$removeProperties = isset($params['removeProperties']) && is_array($params['removeProperties']) 
    ? array_map('trim', $params['removeProperties']) 
    : [];

// Convert styles array/object to string if necessary
if (is_array($styles)) {
    $styleLines = [];
    foreach ($styles as $property => $value) {
        $styleLines[] = $property . ': ' . $value . ';';
    }
    $styles = implode("\n    ", $styleLines);
}

// Validate selector
if (empty($selector)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Selector cannot be empty')
        ->send();
}

// Validate styles - allow empty styles if removeProperties is provided
if (empty($styles) && empty($removeProperties)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Styles cannot be empty (unless removeProperties is provided)')
        ->send();
}

// Security validation - prevent CSS injection attacks
$dangerousPatterns = [
    '/javascript\s*:/i',
    '/expression\s*\(/i',
    '/url\s*\(\s*javascript/i',
    '/@import/i',
    '/<\s*script/i',
    '/<\s*style/i',
];

// Validate against dangerous patterns
$textToCheck = $styles . ' ' . $selector;
foreach ($dangerousPatterns as $pattern) {
    if (preg_match($pattern, $textToCheck)) {
        ApiResponse::create(400, 'validation.security')
            ->withMessage('Potentially dangerous CSS pattern detected')
            ->send();
    }
}

// Validate media query format if provided
if ($mediaQuery !== null && !RegexPatterns::match('media_query_basic', $mediaQuery)) {
    // Allow common media query formats
    if (!RegexPatterns::match('media_query_chars', $mediaQuery)) {
        ApiResponse::create(400, 'validation.invalid_media_query')
            ->withMessage('Invalid media query format')
            ->send();
    }
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
    
    // Parse and update
    $parser = new CssParser($content);
    $result = $parser->setStyleRule($selector, $styles, $mediaQuery, $removeProperties);
    
    // Write updated content
    if (file_put_contents($styleFile, $parser->getContent()) === false) {
        throw new Exception('Failed to write style file');
    }
    
    flock($lock, LOCK_UN);
    fclose($lock);
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Style rule ' . $result['action'] . ' successfully')
        ->withData([
            'action' => $result['action'],
            'selector' => $result['selector'],
            'mediaQuery' => $result['mediaQuery'],
            'styles' => $styles
        ])
        ->send();
    
} catch (Exception $e) {
    flock($lock, LOCK_UN);
    fclose($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
