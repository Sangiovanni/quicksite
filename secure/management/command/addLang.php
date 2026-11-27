<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_json_write, qs_config_lock
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php'; // qs_translation_carry_words

/**
 * addLang - Add a language to the project, from the installation's language list
 *
 * A project with two or more languages is multilingual (the switch is the language count). Adding the
 * second language makes a one-language site multilingual: each page then has an address per language
 * (/en/x, /fr/x; /x still shows the default language). Its words cross with it: the words of default.json,
 * which every page read until now, are carried into the default language's file first
 * (qs_translation_carry_words(): the words shown win, a key that is a word in one file and a group in the
 * other refuses with nothing changed), and the new language's file is a copy of that file, as it is when
 * a third language is added.
 *
 * Everything this command writes (the carried words, config.php, the new file) is put back when a later
 * step fails or is refused: nothing is half-added.
 *
 * @method POST
 * @url /management/addLang
 * @auth required
 * @permission translation.write
 */

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
// (an array would reach strtolower() as a TypeError).
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
            'existing_languages' => qs_project_language_codes()
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

// The config lock (qs_config_lock()), held until every file this command writes is written:
// two addLang calls, or any other config writer, never interleave.
$lockHandle = qs_config_lock($config_path, $lockFailure);
if ($lockHandle === null) {
    qs_config_lock_refused($lockFailure)->send();
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
    qs_config_unlock($lockHandle);
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse configuration file")
        ->send();
}

// Check again under lock if language was added by concurrent request
if (in_array($langCode, qs_project_language_codes($current_config), true)) {
    qs_config_unlock($lockHandle);
    ApiResponse::create(409, 'conflict.duplicate')
        ->withMessage("Language already exists")
        ->withData([
            'code' => $langCode,
            'existing_languages' => qs_project_language_codes($current_config)
        ])
        ->send();
}

// Whether this addition is the second language: the one-language site becomes multilingual.
$crossing = !qs_project_is_multilingual($current_config);

// Add new language
$current_config['LANGUAGES_SUPPORTED'] = array_merge(qs_project_language_codes($current_config), [$langCode]);

$refusal = qs_project_settings_guard($current_config, ['LANGUAGES_SUPPORTED']);
if ($refusal !== null) {
    qs_config_unlock($lockHandle);
    $refusal->send();
}

// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

$default_lang = qs_project_default_language($current_config);
$source_file = PROJECT_PATH . '/translate/' . $default_lang . '.json';
$target_file = PROJECT_PATH . '/translate/' . $langCode . '.json';

/** Release the config lock, put back everything this command wrote, and answer $response. */
$fail = static function (ApiResponse $response) use ($lockHandle): void {
    qs_config_unlock($lockHandle);
    qs_small_write_rollback(qs_small_write_journal());
    $response->send();
};

// --- THE SECOND LANGUAGE: default.json's words cross into the default language's file ---
// Through the shared writer, whose size check guards and journals what it writes.
$sync = null;
if ($crossing) {
    $sync = qs_translation_carry_words('default', $default_lang);
    if (!$sync['ok']) {
        $fail(qs_translation_write_refusal_response($sync));
    }
    clearstatcache(true, $source_file);
}

// The size check on the two other files this command writes, before either is
// (qs_small_write_refusal): config.php, and the new language's translation file
// — a copy of the default language's, as the crossing left it.
$refusal = qs_small_write_refusal([
    $config_path => strlen($new_config_content),
    $target_file => file_exists($source_file) ? (int) filesize($source_file) : qs_json_size([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
]);
if ($refusal !== null) {
    $fail($refusal);
}

// Write updated config (its bytes kept first, so a failure below puts it back)
qs_small_write_keep($config_path);
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    $fail(ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file"));
}

// Clear opcode cache if available
qs_opcache_invalidate($config_path);

// --- CREATE TRANSLATION FILE ---
// Copy from default language ($source_file / $target_file, set for the size check)
qs_small_write_keep($target_file);
if (!file_exists($source_file)) {
    // Fallback: create empty translation file
    if (!qs_json_write($target_file, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, LOCK_EX)) {
        $fail(ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to create translation file"));
    }
} elseif (!copy($source_file, $target_file)) {
    // Copy default translations as starting point
    $fail(ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to create translation file"));
}

// Release config lock
qs_config_unlock($lockHandle);

// Success. The paths are the project's own, relative to it: a response never
// names where the installation keeps its files.
ApiResponse::create(201, 'operation.success')
    ->withMessage('Language added successfully')
    ->withData([
        'code' => $langCode,
        'name' => qs_language_label($langCode),
        'config_updated' => true,
        'translation_file' => 'translate/' . $langCode . '.json',
        'copied_from' => file_exists($source_file) ? $default_lang : 'empty',
        'multilingual_enabled' => true,
    ] + ($sync !== null ? ['sync' => [
        'direction' => $sync['direction'],
        'keys_added' => $sync['added'],
        'keys_replaced' => $sync['replaced'],
    ]] : []))
    ->send();