<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_param_string
/**
 * deleteKeyframes - Remove a @keyframes animation
 * Method: POST
 * URL: /management/deleteKeyframes
 * Body: {"name": "fadeIn"}
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsStyleManagement.php';

// Get parameters
$params = $trimParametersManagement->params();

// Validate required parameter. qs_param_string, not isset: `?name[]=x` is SET
// but is an array, and reached trim() as a TypeError (beta.10 C13 F-C13-11).
$nameParam = qs_param_string($params, 'name');
if ($nameParam === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: name')
        ->send();
}

$name = trim($nameParam);

// Validate name
if (empty($name)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Animation name cannot be empty')
        ->send();
}

$styleFile = cssLivePath();

// Check file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Style file not found')
        ->send();
}

// Use file locking
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
    
    // Parse and delete
    $parser = new CssParser($content);
    $deleted = $parser->deleteKeyframes($name);
    
    if (!$deleted) {
        cssReleaseLock($lock);
        ApiResponse::create(404, 'keyframe.not_found')
            ->withMessage("Keyframe animation '$name' not found")
            ->send();
    }
    
    // Write updated content to live stylesheet and project backup copy
    cssWriteAllTargets($parser->getContent(), $styleFile, cssProjectPath());

    cssReleaseLock($lock);

    ApiResponse::create(200, 'operation.success')
        ->withMessage('Keyframe animation deleted successfully')
        ->withData([
            'name' => $name
        ])
        ->send();

} catch (Exception $e) {
    cssReleaseLock($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
