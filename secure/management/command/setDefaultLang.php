<?php
/**
 * setDefaultLang Command
 *
 * Sets the site's default language.
 *
 * On a multilingual site (two or more languages: the switch is the language count) the language must already be one of
 * the project's (LANGUAGES_SUPPORTED); an address with no language in it then shows the site in it, and a language added
 * later starts as a copy of its words. No word moves.
 *
 * On a one-language site it changes the site's one language: the code is a NEW language, from the installation's language
 * list, and it replaces the one the project had (LANGUAGES_SUPPORTED and LANGUAGE_DEFAULT both). The site's words, read
 * from default.json, and its addresses stay as they are; the old language's word file is renamed to the new code. A word
 * file already on disk under the new code (one no listed language reads) refuses it, nothing changed:
 * cleanOrphanTranslations removes it.
 *
 * Everything this command writes is put back when a later step fails.
 *
 * @method PATCH
 * @url /management/setDefaultLang
 * @auth required
 * @permission translation.write
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['lang'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: lang')
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

// --- THE CONFIG, READ FRESH UNDER ITS LOCK (as addLang and deleteLang hold it) ---
$config_path = CONFIG_PATH;

if (!file_exists($config_path)) {
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Configuration file not found")
        ->send();
}

$lockFile = $config_path . '.lock';
$lockHandle = @fopen($lockFile, 'w');
if ($lockHandle === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to create config lock file")
        ->send();
}
if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to acquire config lock")
        ->send();
}

/** Release the config lock, put back anything this command wrote, and answer $response (a refusal, or a 200 that changes nothing). */
$stop = static function (ApiResponse $response) use ($lockHandle, $lockFile): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    qs_small_write_rollback(qs_small_write_journal());
    $response->send();
};

clearstatcache(true, $config_path);
qs_opcache_invalidate($config_path);
// Parse current config (use include to get fresh copy, not cached by require)
$get_fresh_config = function($path) {
    return include $path;
};
$current_config = $get_fresh_config($config_path);

if (!is_array($current_config)) {
    $stop(ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse configuration file"));
}

$oneLanguage = !qs_project_is_multilingual($current_config);
$previousDefault = qs_project_default_language($current_config);
$previousName = qs_language_label((string) $previousDefault);

// Check if already the default
if ($langCode === $previousDefault) {
    $stop(ApiResponse::create(200, 'operation.no_change')
        ->withMessage("Language is already the default")
        ->withData([
            'code' => $langCode,
            'name' => qs_language_label($langCode)
        ]));
}

if ($oneLanguage) {
    // A one-language site: the code is a NEW language, from the installation's language list.
    if (!qs_language_is_listed($langCode)) {
        $stop(qs_language_not_listed_response($langCode, 'lang'));
    }
    $current_config['LANGUAGES_SUPPORTED'] = [$langCode];
} elseif (!in_array($langCode, qs_project_language_codes($current_config), true)) {
    // A multilingual site: an EXISTING language, one of the project's.
    $stop(ApiResponse::create(404, 'not_found.language')
        ->withMessage("Language not found")
        ->withData([
            'code' => $langCode,
            'available_languages' => qs_project_language_codes($current_config),
            'hint' => 'Use addLang to add a new language first'
        ]));
}

// Update default language
$current_config['LANGUAGE_DEFAULT'] = $langCode;

$refusal = qs_project_settings_guard($current_config, ['LANGUAGES_SUPPORTED', 'LANGUAGE_DEFAULT']);
if ($refusal !== null) {
    $stop($refusal);
}

// On a one-language site the old language's word file takes the new code (its words do not change).
$renamed = null;
if ($oneLanguage) {
    $oldFile = PROJECT_PATH . '/translate/' . $previousDefault . '.json';
    $newFile = PROJECT_PATH . '/translate/' . $langCode . '.json';
    if (file_exists($newFile)) {
        $stop(ApiResponse::create(409, 'conflict.duplicate')
            ->withMessage("translate/{$langCode}.json is already on disk, a word file no language of the project reads: "
                . "nothing was changed. cleanOrphanTranslations removes it.")
            ->withData(['code' => $langCode, 'file' => 'translate/' . $langCode . '.json']));
    }
    if (file_exists($oldFile)) {
        qs_small_write_keep($oldFile);
        qs_small_write_keep($newFile);
        if (!@rename($oldFile, $newFile)) {
            $stop(ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to rename the language's translation file"));
        }
        $renamed = ['from' => 'translate/' . $previousDefault . '.json', 'to' => 'translate/' . $langCode . '.json'];
    }
}

// --- UPDATE CONFIG FILE, LAST ---
// Build new config file content using var_export for safety
$new_config_content = "<?php\n\nreturn " . var_export($current_config, true) . ";\n";

// The size check (qs_small_write_refusal): the settings cap, and growth while
// the owner is over quota.
$refusal = qs_small_write_refusal([$config_path => strlen($new_config_content)]);
if ($refusal !== null) {
    $stop($refusal);
}

// Write updated config
qs_small_write_keep($config_path);
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    $stop(ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file"));
}

// Clear opcode cache if available
qs_opcache_invalidate($config_path);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Default language updated successfully')
    ->withData([
        'new_default' => [
            'code' => $langCode,
            'name' => qs_language_label($langCode)
        ],
        'previous_default' => [
            'code' => $previousDefault,
            'name' => $previousName
        ],
        'config_updated' => true,
        'multilingual_enabled' => !$oneLanguage,
        'languages' => $current_config['LANGUAGES_SUPPORTED'],
    ] + ($oneLanguage ? ['translation_file_renamed' => $renamed] : []))
    ->send();
