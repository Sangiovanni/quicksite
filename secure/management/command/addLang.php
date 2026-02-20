<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// NOTE: addLang works regardless of MULTILINGUAL_SUPPORT setting
// This allows adding languages BEFORE enabling multilingual mode
// (setMultilingual requires 2+ languages to enable)

$params = $trimParametersManagement->params();

// Support multiple parameter names for flexibility:
// - 'code'/'name' (full form)
// - 'lang' (shorthand with auto-generated name)  
// - 'language' (AI often uses this)
$langCode = $params['code'] ?? $params['lang'] ?? $params['language'] ?? null;
$langName = $params['name'] ?? null;

// Validate required parameters
if ($langCode === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Language code is required')
        ->withErrors([['field' => 'code', 'reason' => 'missing', 'hint' => 'Use "code" and "name", or just "lang" for shorthand']])
        ->send();
}

// If name not provided, generate from code (e.g., 'fr' -> 'French', 'es' -> 'Spanish')
if ($langName === null) {
    $commonLanguages = [
        'en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German',
        'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ru' => 'Russian',
        'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic',
        'hi' => 'Hindi', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
        'no' => 'Norwegian', 'fi' => 'Finnish', 'tr' => 'Turkish', 'cs' => 'Czech',
        'el' => 'Greek', 'he' => 'Hebrew', 'th' => 'Thai', 'vi' => 'Vietnamese'
    ];
    $langName = $commonLanguages[strtolower($langCode)] ?? ucfirst($langCode);
}

// Type validation - must be strings
if (!is_string($langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'code', 'reason' => 'must be a string', 'received_type' => gettype($langCode)]])
        ->send();
}

if (!is_string($langName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid parameter type")
        ->withErrors([['field' => 'name', 'reason' => 'must be a string', 'received_type' => gettype($langName)]])
        ->send();
}

$langCode = trim($langCode);
$langName = trim($langName);

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
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($config_path, true);
}

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
if (in_array($langCode, $current_config['LANGUAGES_SUPPORTED'] ?? [])) {
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
if (!isset($current_config['LANGUAGES_NAME'])) {
    $current_config['LANGUAGES_NAME'] = [];
}
$current_config['LANGUAGES_NAME'][$langCode] = $langName;

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

// Release config lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

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