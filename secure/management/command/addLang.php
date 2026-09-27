<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_json_write
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';

// NOTE: addLang works regardless of MULTILINGUAL_SUPPORT setting
// This allows adding languages BEFORE enabling multilingual mode

$params = $trimParametersManagement->params();

// The code only: a language's name comes from the installation's language list
// wherever it is shown. Three spellings are accepted ('language' is the one AI
// callers often use); the first present wins.
$langCode = $params['code'] ?? $params['lang'] ?? $params['language'] ?? null;

// Validate required parameters
if ($langCode === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Language code is required')
        ->withErrors([['field' => 'code', 'reason' => 'missing', 'hint' => 'Send the language code as "code" (or "lang")']])
        ->send();
}

// Type validation - must be a string, checked before anything reads it as one
// (an array reached strtolower() as a TypeError, beta.10 C13 F-C13-11).
if (!is_string($langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'code', 'reason' => 'must be a string', 'received_type' => gettype($langCode)]])
        ->send();
}

$langCode = trim($langCode);

// A NEW language: its code must be in the installation's language list.
if (!qs_language_is_listed($langCode)) {
    qs_language_not_listed_response($langCode, 'code')->send();
}

// Check if language already exists
if (qs_project_has_language($langCode)) {
    ApiResponse::create(409, 'conflict.duplicate')
        ->withMessage("Language already exists")
        ->withData([
            'code' => $langCode,
            'existing_languages' => CONFIG['LANGUAGES_SUPPORTED']
        ])
        ->send();
}

// --- UPDATE CONFIG FILE ---
$config_path = CONFIG_PATH;

if (!file_exists($config_path)) {
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Configuration file not found")
        ->send();
}

// Use file locking to prevent race conditions when multiple addLang calls run in parallel
$lockFile = $config_path . '.lock';
$lockHandle = @fopen($lockFile, 'w');
if ($lockHandle === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to create config lock file")
        ->send();
}

// Acquire exclusive lock (blocking)
if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to acquire config lock")
        ->send();
}

// Clear PHP's file stat cache to ensure fresh read
clearstatcache(true, $config_path);

// Clear opcache if available
qs_opcache_invalidate($config_path);

// Parse current config (use include to get fresh copy, not cached by require)
$get_fresh_config = function($path) {
    return include $path;
};
$current_config = $get_fresh_config($config_path);

if (!is_array($current_config)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse configuration file")
        ->send();
}

// Check again under lock if language was added by concurrent request
if (in_array($langCode, $current_config['LANGUAGES_SUPPORTED'] ?? [], true)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    ApiResponse::create(409, 'conflict.duplicate')
        ->withMessage("Language already exists")
        ->withData([
            'code' => $langCode,
            'existing_languages' => $current_config['LANGUAGES_SUPPORTED']
        ])
        ->send();
}

// Add new language
$current_config['LANGUAGES_SUPPORTED'][] = $langCode;

$refusal = qs_project_settings_guard($current_config, ['LANGUAGES_SUPPORTED']);
if ($refusal !== null) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    $refusal->send();
}

// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

// Write updated config
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file")
        ->send();
}

// Clear opcode cache if available
qs_opcache_invalidate($config_path);

// --- CREATE TRANSLATION FILE ---
// Copy from default language
$default_lang = CONFIG['LANGUAGE_DEFAULT'];
$source_file = PROJECT_PATH . '/translate/' . $default_lang . '.json';
$target_file = PROJECT_PATH . '/translate/' . $langCode . '.json';

if (!file_exists($source_file)) {
    // Fallback: create empty translation file
    qs_json_write($target_file, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, LOCK_EX);
} else {
    // Copy default translations as starting point
    if (!copy($source_file, $target_file)) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to create translation file")
            ->send();
    }
}

// Release config lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

// Success. The paths are the project's own, relative to it: a response never
// names where the installation keeps its files.
ApiResponse::create(201, 'operation.success')
    ->withMessage('Language added successfully')
    ->withData([
        'code' => $langCode,
        'name' => qs_language_label($langCode),
        'config_updated' => true,
        'translation_file' => 'translate/' . $langCode . '.json',
        'copied_from' => file_exists($source_file) ? $default_lang : 'empty'
    ])
    ->send();