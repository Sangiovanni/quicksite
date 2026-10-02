<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';

// Works in either mode, so fresh-start workflows can delete orphaned languages
// before multilingual mode is on.

$params = $trimParametersManagement->params();

// Validate required parameter
if (!isset($params['code'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: code')
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

// An EXISTING language: it must be one of the project's.
if (!qs_project_has_language($langCode)) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Language not found")
        ->withData([
            'code' => $langCode,
            'existing_languages' => qs_project_language_codes()
        ])
        ->send();
}

// Prevent removing default language
if ($langCode === qs_project_default_language()) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Cannot remove default language")
        ->withErrors([['field' => 'code', 'reason' => 'is_default_language']])
        ->send();
}

// Prevent removing last language
if (count(qs_project_language_codes()) === 1) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Cannot remove last language")
        ->send();
}

// --- READ THE CONFIG, AND CHECK WHAT WILL BE WRITTEN BEFORE CHANGING ANYTHING ---
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

// Remove the language from the list
$current_config['LANGUAGES_SUPPORTED'] = array_values(
    array_filter(qs_project_language_codes($current_config), fn($lang) => $lang !== $langCode)
);

$refusal = qs_project_settings_guard($current_config, ['LANGUAGES_SUPPORTED']);
if ($refusal !== null) {
    $refusal->send();
}

// --- DELETE TRANSLATION FILE FIRST (safer - file can be recreated, config corruption is worse) ---
$translation_file = PROJECT_PATH . '/translate/' . $langCode . '.json';
$deleted = false;

if (file_exists($translation_file)) {
    $deleted = unlink($translation_file);
    if (!$deleted) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to delete translation file")
            ->withData(['file' => 'translate/' . $langCode . '.json'])
            ->send();
    }
}

// --- UPDATE CONFIG FILE ---
// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

// Write updated config with exclusive lock
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file")
        ->send();
}

// Clear opcode cache if available
qs_opcache_invalidate($config_path);

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Language removed successfully')
    ->withData([
        'code' => $langCode,
        'config_updated' => true,
        'translation_file_deleted' => $deleted,
        'remaining_languages' => $current_config['LANGUAGES_SUPPORTED']
    ])
    ->send();