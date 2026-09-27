<?php
/**
 * setKeyframes - Add or update a @keyframes animation
 * Method: POST
 * URL: /management/setKeyframes
 * Body: {
 *   "name": "fadeIn",
 *   "frames": {
 *     "from": "opacity: 0;",
 *     "to": "opacity: 1;"
 *   },
 *   "allowOverwrite": true  // Required when updating existing keyframe
 * }
 * 
 * Or with percentages:
 * Body: {
 *   "name": "bounce",
 *   "frames": {
 *     "0%, 100%": "transform: translateY(0);",
 *     "50%": "transform: translateY(-20px);"
 *   }
 * }
 * 
 * Note: If a keyframe with the given name already exists and allowOverwrite
 * is not set to true, the request will be rejected with a 409 Conflict error.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsStyleManagement.php';

// Get parameters
$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['name'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: name')
        ->send();
}
if (!isset($params['frames'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: frames')
        ->send();
}

$name = trim($params['name']);
$frames = $params['frames'];

// Validate name (alphanumeric and hyphens only)
if (!RegexPatterns::match('keyframe_name', $name)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Animation name must start with a letter and contain only letters, numbers, hyphens, and underscores')
        ->send();
}

// Validate frames
if (!is_array($frames) || empty($frames)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Frames must be a non-empty object')
        ->send();
}

// Validate each frame
$block = '@keyframes ' . $name . " {\n";
foreach ($frames as $key => $styles) {
    // Validate the frame key: a percentage (decimals allowed), from or to, or a
    // comma-separated list of them
    if (!RegexPatterns::match('keyframe_selector', $key)) {
        ApiResponse::create(400, 'validation.invalid_frame')
            ->withMessage("Invalid frame key: '$key'. Must be a percentage, 'from' or 'to', or a comma-separated list of them")
            ->send();
    }
    
    if (!is_string($styles)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Frame styles must be strings')
            ->send();
    }
    
    // SECURITY: the scan every stylesheet writer runs (qs_css_first_danger)
    $danger = qs_css_first_danger($styles);
    if ($danger !== null) {
        ApiResponse::create(400, 'validation.security')
            ->withMessage('Potentially dangerous CSS pattern detected')
            ->withErrors([
                ['field' => 'frames', 'frame' => (string) $key, 'reason' => 'dangerous_pattern', 'pattern' => $danger]
            ])
            ->send();
    }

    // Brace confinement — a `}` in a frame's declaration block escapes the
    // @keyframes block and emits arbitrary CSS. The denylist does not catch it.
    if (!qs_css_confine($styles)) {
        ApiResponse::create(400, 'validation.security')
            ->withMessage('Frame styles may not contain "{" or "}"')
            ->send();
    }

    $block .= $key . " {\n" . $styles . "\n}\n";
}

// ...and on the block as it will be written, because a denylisted sequence can
// span two frames.
$danger = qs_css_first_danger($block . '}');
if ($danger !== null) {
    ApiResponse::create(400, 'validation.security')
        ->withMessage('Potentially dangerous CSS pattern detected')
        ->withErrors([
            ['field' => 'frames', 'reason' => 'dangerous_pattern', 'pattern' => $danger]
        ])
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
    
    // Parse and check if keyframe already exists
    $parser = new CssParser($content);
    $existingKeyframes = $parser->getKeyframes();
    
    // If keyframe exists and allowOverwrite is not true, reject the request
    $allowOverwrite = isset($params['allowOverwrite']) && $params['allowOverwrite'] === true;
    if (isset($existingKeyframes[$name]) && !$allowOverwrite) {
        cssReleaseLock($lock);
        ApiResponse::create(409, 'keyframe.already_exists')
            ->withMessage("Keyframe '$name' already exists. Set allowOverwrite: true to replace it.")
            ->withData(['name' => $name, 'existingFrames' => array_keys($existingKeyframes[$name])])
            ->send();
    }
    
    // Update keyframes
    $result = $parser->setKeyframes($name, $frames);
    
    // Write updated content to live stylesheet and project backup copy
    cssWriteAllTargets($parser->getContent(), $styleFile, cssProjectPath());

    cssReleaseLock($lock);

    ApiResponse::create(200, 'operation.success')
        ->withMessage('Keyframe animation ' . $result['action'] . ' successfully')
        ->withData([
            'action' => $result['action'],
            'name' => $result['name'],
            'frames' => $result['frames']
        ])
        ->send();

} catch (Exception $e) {
    cssReleaseLock($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
