<?php
/**
 * setRootVariables - Set/update CSS custom properties in :root
 * Method: POST
 * URL: /management/setRootVariables
 * Body: {"variables": {"--color-primary": "#007bff", "--spacing-md": "1rem"}}
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

$params = $trimParametersManagement->params();

// Validate required parameter
if (!isset($params['variables'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: variables')
        ->send();
}

$variables = $params['variables'];

// Validate variables is an object/array
if (!is_array($variables) || empty($variables)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Variables must be a non-empty object')
        ->send();
}

// Validate each variable
foreach ($variables as $name => $value) {
    if (!is_string($name) || !is_string($value)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Variable names and values must be strings')
            ->send();
    }
    
    // Basic CSS value validation - prevent injection
    if (RegexPatterns::match('css_injection', $value)) {
        ApiResponse::create(400, 'validation.invalid_css')
            ->withMessage('Invalid CSS value detected')
            ->send();
    }
}

$styleFile = PUBLIC_FOLDER_ROOT .'/'. PUBLIC_FOLDER_SPACE . '/style/style.css';

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
    $result = $parser->setRootVariables($variables);
    
    // Write updated content
    if (file_put_contents($styleFile, $parser->getContent()) === false) {
        throw new Exception('Failed to write style file');
    }
    
    flock($lock, LOCK_UN);
    fclose($lock);
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Root variables updated successfully')
        ->withData([
            'added' => $result['added'],
            'updated' => $result['updated'],
            'total_changes' => $result['total_changes'],
            'current_variables' => $parser->getRootVariables()
        ])
        ->send();
    
} catch (Exception $e) {
    flock($lock, LOCK_UN);
    fclose($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
