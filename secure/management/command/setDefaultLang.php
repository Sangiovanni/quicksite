<?php
/**
 * setDefaultLang Command
 * 
 * Sets the default language for the site.
 * The language must already exist in LANGUAGES_SUPPORTED.
 * 
 * @version 1.6.0
 * @method PATCH
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// Check if multilingual mode is enabled
if (!MULTILINGUAL_SUPPORT) {
    ApiResponse::create(403, 'mode.requires_multilingual')
        ->withMessage('The setDefaultLang command requires multilingual mode. Use setMultilingual to enable it first.')
        ->withData(['current_mode' => 'mono-language'])
        ->send();
}

$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['lang'])) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([['field' => 'lang', 'reason' => 'missing']])
        ->send();
}

// Validate parameter type (must be string)
if (!is_string($params['lang'])) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'lang', 'reason' => 'must be a string', 'received_type' => gettype($params['lang'])]])
        ->send();
}

$langCode = trim(strtolower($params['lang']));

// Validate language code format (2-3 lowercase letters)
if (!RegexPatterns::match('language_code', $langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->withErrors([RegexPatterns::validationError('language_code', 'lang', $langCode)])
        ->send();
}

// Check if language exists in supported languages
if (!in_array($langCode, CONFIG['LANGUAGES_SUPPORTED'])) {
    ApiResponse::create(404, 'not_found.language')
        ->withMessage("Language not found")
        ->withData([
            'code' => $langCode,
            'available_languages' => CONFIG['LANGUAGES_SUPPORTED'],
            'hint' => 'Use addLang to add a new language first'
        ])
        ->send();
}

// Check if already the default
if ($langCode === CONFIG['LANGUAGE_DEFAULT']) {
    ApiResponse::create(200, 'operation.no_change')
        ->withMessage("Language is already the default")
        ->withData([
            'code' => $langCode,
            'name' => CONFIG['LANGUAGES_NAME'][$langCode] ?? $langCode
        ])
        ->send();
}

// Store previous default for response
$previousDefault = CONFIG['LANGUAGE_DEFAULT'];
$previousName = CONFIG['LANGUAGES_NAME'][$previousDefault] ?? $previousDefault;

// --- UPDATE CONFIG FILE ---
$config_path = CONFIG_PATH;

if (!file_exists($config_path)) {
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Configuration file not found")
        ->send();
}

$config_content = file_get_contents($config_path);
if ($config_content === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read configuration file")
        ->send();
}

// Parse current config (use include to get fresh copy, not cached by require)
$get_fresh_config = function($path) {
    return include $path;
};
$current_config = $get_fresh_config($config_path);

if (!is_array($current_config)) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse configuration file")
        ->send();
}

// Update default language
$current_config['LANGUAGE_DEFAULT'] = $langCode;

// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

// Write updated config
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file")
        ->send();
}

// Clear opcode cache if available
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($config_path, true);
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Default language updated successfully')
    ->withData([
        'new_default' => [
            'code' => $langCode,
            'name' => CONFIG['LANGUAGES_NAME'][$langCode] ?? $langCode
        ],
        'previous_default' => [
            'code' => $previousDefault,
            'name' => $previousName
        ],
        'config_updated' => true
    ])
    ->send();
