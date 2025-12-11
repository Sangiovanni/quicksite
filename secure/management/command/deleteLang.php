<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$params = $trimParametersManagement->params();

// Validate required parameter
if (!isset($params['code'])) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([['field' => 'code', 'reason' => 'missing']])
        ->send();
}

// Validate parameter type (must be string, not array/object/etc)
if (!is_string($params['code'])) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'code', 'reason' => 'must be a string', 'received_type' => gettype($params['code'])]])
        ->send();
}

$langCode = trim($params['code']);

// Validate language code format
if (!preg_match('/^[a-z]{2,3}$/', $langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->withErrors([['field' => 'code', 'value' => $langCode]])
        ->send();
}

// Check if language exists
if (!in_array($langCode, CONFIG['LANGUAGES_SUPPORTED'])) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Language not found")
        ->withData([
            'code' => $langCode,
            'existing_languages' => CONFIG['LANGUAGES_SUPPORTED']
        ])
        ->send();
}

// Prevent removing default language
if ($langCode === CONFIG['LANGUAGE_DEFAULT']) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Cannot remove default language")
        ->withErrors([['field' => 'code', 'reason' => 'is_default_language']])
        ->send();
}

// Prevent removing last language
if (count(CONFIG['LANGUAGES_SUPPORTED']) === 1) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Cannot remove last language")
        ->send();
}

// --- DELETE TRANSLATION FILE FIRST (safer - file can be recreated, config corruption is worse) ---
$translation_file = SECURE_FOLDER_PATH . '/translate/' . $langCode . '.json';
$deleted = false;

if (file_exists($translation_file)) {
    $deleted = unlink($translation_file);
    if (!$deleted) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to delete translation file")
            ->withData(['file' => $translation_file])
            ->send();
    }
}

// --- UPDATE CONFIG FILE ---
$config_path = CONFIG_PATH;

// Read current config (use include to get fresh copy, not cached by require)
$get_fresh_config = function($path) {
    return include $path;
};
$current_config = $get_fresh_config($config_path);

if (!is_array($current_config)) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse configuration file")
        ->send();
}

// Remove language from arrays
$current_config['LANGUAGES_SUPPORTED'] = array_values(
    array_filter($current_config['LANGUAGES_SUPPORTED'], fn($lang) => $lang !== $langCode)
);
unset($current_config['LANGUAGES_NAME'][$langCode]);

// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

// Write updated config with exclusive lock
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
    ->withMessage('Language removed successfully')
    ->withData([
        'code' => $langCode,
        'config_updated' => $config_path,
        'translation_file_deleted' => $deleted,
        'remaining_languages' => $current_config['LANGUAGES_SUPPORTED']
    ])
    ->send();