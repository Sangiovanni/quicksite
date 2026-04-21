<?php
/**
 * setRootVariables - Set/update CSS custom properties in :root (light) or [data-theme="dark"]
 * Method: POST
 * URL: /management/setRootVariables
 * Body: {
 *   "variables": {"--color-primary": "#007bff", "--spacing-md": "1rem"},
 *   "themeTarget": "light"   // optional — "light" (default) or "dark"
 * }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsStyleManagement.php';

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

// Resolve themeTarget → CSS scope selector
// "light" (default) → :root   |   "dark" → [data-theme="dark"]
$themeTarget = isset($params['themeTarget']) ? trim($params['themeTarget']) : 'light';
if (!in_array($themeTarget, ['light', 'dark'], true)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('themeTarget must be "light" or "dark"')
        ->send();
}
$cssScope = ($themeTarget === 'dark') ? '[data-theme="dark"]' : ':root';

$styleFile    = cssLivePath();
$projectStyleFile = cssProjectPath();

// Check live stylesheet exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Style file not found')
        ->send();
}

$lock = cssAcquireLock($styleFile);
if ($lock === null) {
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
    $result = $parser->setVariablesInScope($variables, $cssScope);
    
    $updatedContent = $parser->getContent();

    cssWriteAllTargets($updatedContent, $styleFile, $projectStyleFile);
    cssReleaseLock($lock);
    
    ApiResponse::create(200, 'operation.success')
        ->withMessage('Root variables updated successfully')
        ->withData([
            'added' => $result['added'],
            'updated' => $result['updated'],
            'total_changes' => $result['total_changes'],
            'theme_target' => $themeTarget,
            'current_variables' => $parser->getVariablesInScope($cssScope)
        ])
        ->send();
    
} catch (Exception $e) {
    cssReleaseLock($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
