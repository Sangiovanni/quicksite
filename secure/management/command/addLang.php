<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// Check if multilingual mode is enabled
if (!MULTILINGUAL_SUPPORT) {
    ApiResponse::create(403, 'mode.requires_multilingual')
        ->withMessage('The addLang command requires multilingual mode. Use setMultilingual to enable it first.')
        ->withData(['current_mode' => 'mono-language'])
        ->send();
}

$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['code']) || !isset($params['name'])) {
    $missing = [];
    if (!isset($params['code'])) $missing[] = 'code';
    if (!isset($params['name'])) $missing[] = 'name';
    
    ApiResponse::create(400, 'validation.required')
        ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
        ->send();
}

// Validate parameter types (must be strings, not arrays/objects/etc)
if (!is_string($params['code'])) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'code', 'reason' => 'must be a string', 'received_type' => gettype($params['code'])]])
        ->send();
}

if (!is_string($params['name'])) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'name', 'reason' => 'must be a string', 'received_type' => gettype($params['name'])]])
        ->send();
}

$langCode = trim($params['code']);
$langName = trim($params['name']);

// Validate language code format (2-3 lowercase letters)
if (!RegexPatterns::match('language_code', $langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->withErrors([RegexPatterns::validationError('language_code', 'code', $langCode)])
        ->send();
}

// Validate language name length and content
if (strlen($langName) === 0 || strlen($langName) > 100) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language name length")
        ->withErrors([['field' => 'name', 'reason' => 'must be between 1 and 100 characters']])
        ->send();
}

// Validate language name contains only safe characters
// Uses Unicode \p{L} to support all scripts (Latin, Cyrillic, Arabic, CJK, etc.)
if (!RegexPatterns::match('language_name', $langName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language name format")
        ->withErrors([RegexPatterns::validationError('language_name', 'name', $langName)])
        ->send();
}

// Check if language already exists
if (in_array($langCode, CONFIG['LANGUAGES_SUPPORTED'])) {
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

// Add new language
$current_config['LANGUAGES_SUPPORTED'][] = $langCode;
$current_config['LANGUAGES_NAME'][$langCode] = $langName;

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

// --- CREATE TRANSLATION FILE ---
// Copy from default language
$default_lang = CONFIG['LANGUAGE_DEFAULT'];
$source_file = PROJECT_PATH . '/translate/' . $default_lang . '.json';
$target_file = PROJECT_PATH . '/translate/' . $langCode . '.json';

if (!file_exists($source_file)) {
    // Fallback: create empty translation file
    $empty_translations = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($target_file, $empty_translations, LOCK_EX);
} else {
    // Copy default translations as starting point
    if (!copy($source_file, $target_file)) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to create translation file")
            ->send();
    }
}

// Success
ApiResponse::create(201, 'operation.success')
    ->withMessage('Language added successfully')
    ->withData([
        'code' => $langCode,
        'name' => $langName,
        'config_updated' => $config_path,
        'translation_file' => $target_file,
        'copied_from' => file_exists($source_file) ? $default_lang : 'empty'
    ])
    ->send();