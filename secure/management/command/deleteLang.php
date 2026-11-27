<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_config_lock
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php'; // qs_translation_carry_words

/**
 * deleteLang - Remove one of the project's languages and its translation file
 *
 * A project with two or more languages is multilingual (the switch is the language count). Removing down
 * to one language makes the site one-language: its addresses lose their language (/en/x becomes /x, and an
 * old /en/… link stops working), and its pages read default.json. Its words cross with it first: the
 * default language's words, which its pages showed until now, are carried into default.json
 * (qs_translation_carry_words(): the words shown win, a key that is a word in one file and a group in the
 * other refuses with nothing changed). The language's file is then deleted, and config.php is written last:
 * a failure on the way puts back everything the command wrote.
 *
 * The default language cannot be removed (setDefaultLang moves it first), nor the last one.
 *
 * @method DELETE
 * @url /management/deleteLang
 * @auth required
 * @permission translation.write
 */

// This command only removes content: neither the size cap nor the over-quota rule refuses
// its writes, even when a re-save makes a file larger (qs_small_write_removal_only()). Going
// down to one language it carries the remaining language's words into default.json: words the
// project already holds, moved as a whole language file goes, so it stays a removal and an owner
// over quota can still remove a language (Sangio's ruling, 2026-11-22).
qs_small_write_removal_only();

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

/** The language's refusals, read from a config (the request's, then the fresh one under the lock); null when it may go. */
$refusalFor = static function (array $config) use ($langCode): ?ApiResponse {
    // An EXISTING language: it must be one of the project's.
    if (!in_array($langCode, qs_project_language_codes($config), true)) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("Language not found")
            ->withData([
                'code' => $langCode,
                'existing_languages' => qs_project_language_codes($config)
            ]);
    }
    // Prevent removing default language
    if ($langCode === qs_project_default_language($config)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Cannot remove default language")
            ->withErrors([['field' => 'code', 'reason' => 'is_default_language']]);
    }
    // Prevent removing last language
    if (count(qs_project_language_codes($config)) === 1) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Cannot remove last language");
    }
    return null;
};

$refusal = $refusalFor(defined('CONFIG') && is_array(CONFIG) ? CONFIG : []);
if ($refusal !== null) {
    $refusal->send();
}

// --- THE CONFIG, READ FRESH UNDER ITS LOCK (as addLang holds it), AND CHECKED BEFORE CHANGING ANYTHING ---
$config_path = CONFIG_PATH;

$lockHandle = qs_config_lock($config_path, $lockFailure);
if ($lockHandle === null) {
    qs_config_lock_refused($lockFailure)->send();
}

/** Release the config lock, put back everything this command wrote, and answer $response. */
$fail = static function (ApiResponse $response) use ($lockHandle): void {
    qs_config_unlock($lockHandle);
    qs_small_write_rollback(qs_small_write_journal());
    $response->send();
};

clearstatcache(true, $config_path);
qs_opcache_invalidate($config_path);
// Read current config (use include to get fresh copy, not cached by require)
$get_fresh_config = function($path) {
    return include $path;
};
$current_config = $get_fresh_config($config_path);

if (!is_array($current_config)) {
    $fail(ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse configuration file"));
}

// A concurrent request may have changed the languages since this one started.
$refusal = $refusalFor($current_config);
if ($refusal !== null) {
    $fail($refusal);
}

// Whether this removal leaves one language: the multilingual site becomes one-language.
$defaultLang = qs_project_default_language($current_config);
$remaining = array_values(
    array_filter(qs_project_language_codes($current_config), fn($lang) => $lang !== $langCode)
);
$crossing = qs_project_is_multilingual($current_config) && !qs_project_is_multilingual(['LANGUAGES_SUPPORTED' => $remaining]);

// Remove the language from the list
$current_config['LANGUAGES_SUPPORTED'] = $remaining;

$refusal = qs_project_settings_guard($current_config, ['LANGUAGES_SUPPORTED']);
if ($refusal !== null) {
    $fail($refusal);
}

// Every file this command writes is kept just before it is written, so a failure below puts it
// back (qs_small_write_keep()): the removal-only mark means the size check journals nothing.
$translation_file = PROJECT_PATH . '/translate/' . $langCode . '.json';

// --- DOWN TO ONE LANGUAGE: the default language's words cross into default.json ---
$sync = null;
if ($crossing) {
    qs_small_write_keep(PROJECT_PATH . '/translate/default.json');
    $sync = qs_translation_carry_words($defaultLang, 'default');
    if (!$sync['ok']) {
        $fail(qs_translation_write_refusal_response($sync));
    }
}

// --- DELETE THE LANGUAGE'S TRANSLATION FILE ---
$deleted = false;
if (file_exists($translation_file)) {
    qs_small_write_keep($translation_file);
    $deleted = unlink($translation_file);
    if (!$deleted) {
        $fail(ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to delete translation file")
            ->withData(['file' => 'translate/' . $langCode . '.json']));
    }
}

// --- UPDATE CONFIG FILE, LAST ---
// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

// The size check (qs_small_write_refusal): a removal, so it never refuses (marked above).
$refusal = qs_small_write_refusal([$config_path => strlen($new_config_content)]);
if ($refusal !== null) {
    $fail($refusal);
}

// Write updated config with exclusive lock
qs_small_write_keep($config_path);
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    $fail(ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file"));
}

// Clear opcode cache if available
qs_opcache_invalidate($config_path);

qs_config_unlock($lockHandle);

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Language removed successfully')
    ->withData([
        'code' => $langCode,
        'config_updated' => true,
        'translation_file_deleted' => $deleted,
        'remaining_languages' => $current_config['LANGUAGES_SUPPORTED'],
        'multilingual_enabled' => qs_project_is_multilingual($current_config),
    ] + ($sync !== null ? ['sync' => [
        'direction' => $sync['direction'],
        'keys_added' => $sync['added'],
        'keys_replaced' => $sync['replaced'],
    ]] : []))
    ->send();
