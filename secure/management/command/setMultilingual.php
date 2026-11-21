<?php
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_json_write
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php'; // writeTranslationsToFile, the clash check

/**
 * setMultilingual - Enable or disable multilingual support
 * 
 * When disabling (switching to mono-language):
 * - Sets MULTILINGUAL_SUPPORT = false in config.php
 * - Syncs missing keys from LANGUAGE_DEFAULT to default.json
 *
 * When enabling (switching to multilingual):
 * - Sets MULTILINGUAL_SUPPORT = true in config.php
 * - Syncs missing keys from default.json to LANGUAGE_DEFAULT file
 *
 * The sync adds only the words the receiving file lacks, through the shared
 * translation writer. A key that is a word in one file and a group of words in
 * the other refuses the switch with the clash's words, and nothing is changed,
 * the configuration included.
 * 
 * @method POST
 * @url /management/setMultilingual
 * @auth required
 * @permission translation.write
 */

$params = $trimParametersManagement->params();

// Validate 'enabled' parameter
if (!isset($params['enabled'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('The "enabled" parameter is required')
        ->withErrors([['field' => 'enabled', 'reason' => 'required']])
        ->send();
}

$enabled = $params['enabled'];

// Accept string 'true'/'false' as well; the setting's rule decides the rest.
if ($enabled === 'true') {
    $enabled = true;
} elseif ($enabled === 'false') {
    $enabled = false;
}
if (qs_project_setting_error('MULTILINGUAL_SUPPORT', $enabled) !== null) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The "enabled" parameter must be a boolean (true/false)')
        ->withErrors([['field' => 'enabled', 'value' => $enabled, 'expected' => 'boolean']])
        ->send();
}

$configPath = PROJECT_PATH . '/config.php';
$translatePath = PROJECT_PATH . '/translate/';
$defaultFile = $translatePath . 'default.json';

// Use file locking to prevent race conditions with concurrent requests
$lockFile = $configPath . '.lock';
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

// Clear caches and read FRESH config (not the stale CONFIG constant)
clearstatcache(true, $configPath);
qs_opcache_invalidate($configPath);

$freshConfig = @include $configPath;
if (!is_array($freshConfig)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to read configuration file")
        ->send();
}

// Get current state from FRESH config
$currentState = $freshConfig['MULTILINGUAL_SUPPORT'] ?? false;
$defaultLang = qs_project_default_language($freshConfig);

// Check if already in desired state
if ($currentState === $enabled) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    $mode = $enabled ? 'multilingual' : 'mono-language';
    ApiResponse::create(200, 'operation.success')
        ->withMessage("Already in {$mode} mode")
        ->withData([
            'multilingual_enabled' => $enabled,
            'changed' => false
        ])
        ->send();
}

$defaultLangFile = $translatePath . $defaultLang . '.json';

// Load translation files for sync
$defaultTranslations = [];
$langTranslations = [];

if (file_exists($defaultFile)) {
    $content = @file_get_contents($defaultFile);
    if ($content !== false) {
        $defaultTranslations = json_decode($content, true) ?? [];
    }
}

if (file_exists($defaultLangFile)) {
    $content = @file_get_contents($defaultLangFile);
    if ($content !== false) {
        $langTranslations = json_decode($content, true) ?? [];
    }
}

/**
 * The words $source holds that $target lacks, as a tree: what a switch carries over (nothing $target
 * holds is in it, so the writer adds and never replaces). $added counts the keys and groups it adds.
 * Called once setMultilingualClash() found no key that is a word in one file and a group in the other.
 */
function missingTranslations_setMultilingual(array $target, array $source, int &$added): array {
    $missing = [];
    foreach ($source as $key => $value) {
        if (!array_key_exists($key, $target)) {
            $missing[$key] = $value;
            $added += 1 + (is_array($value) ? countKeys($value) : 0);
        } elseif (is_array($value) && is_array($target[$key])) {
            $sub = missingTranslations_setMultilingual($target[$key], $value, $added);
            if ($sub !== []) {
                $missing[$key] = $sub;
            }
        }
    }
    return $missing;
}

/**
 * The refusal of a switch whose sync meets a key that is a word in one file and a group of words in the
 * other (detectTranslationCollisions()), as the shared writer answers a clash; null when there is none.
 */
function setMultilingualClash(array $target, array $source, string $from, string $to): ?array {
    $collisions = detectTranslationCollisions($target, $source);
    if ($collisions === []) {
        return null;
    }
    foreach ($collisions as &$collision) {
        $collision['suggestion'] = "The switch carries the words of $from into $to, which holds another shape at this key: "
            . $collision['suggestion'];
    }
    unset($collision);
    return ['ok' => false, 'reason' => 'collisions', 'collisions' => $collisions];
}

/** Answer a refused switch (the lock released first): nothing was changed. */
function setMultilingualRefuse(array $result, $lockHandle, string $lockFile): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    qs_translation_write_refusal_response($result)->send();
}

$syncedKeys = 0;
$syncDirection = '';

if ($enabled) {
    // Switching TO multilingual
    // Note: We allow enabling multilingual with just 1 language
    // This supports workflows where setMultilingual comes before addLang
    // The sync logic still works correctly with any number of languages
    
    // Sync: default.json -> LANGUAGE_DEFAULT file
    // Add missing keys from default.json to the default language file
    if (!empty($defaultTranslations)) {
        $syncDirection = "default.json → {$defaultLang}.json";
        $clash = setMultilingualClash($langTranslations, $defaultTranslations, 'default.json', "{$defaultLang}.json");
        if ($clash !== null) {
            setMultilingualRefuse($clash, $lockHandle, $lockFile);
        }
        // The words the language file lacks, through the shared writer (its size check; its copy
        // into default.json adds nothing, as the words come from there).
        $missing = missingTranslations_setMultilingual($langTranslations, $defaultTranslations, $syncedKeys);
        if ($missing !== []) {
            $written = writeTranslationsToFile($defaultLang, $missing, false);
            if (!$written['ok']) {
                setMultilingualRefuse(array_merge($written, ['message' => "Failed to update {$defaultLang}.json"]), $lockHandle, $lockFile);
            }
        }
    }
} else {
    // Switching TO mono-language
    // Sync: LANGUAGE_DEFAULT file -> default.json
    // Add missing keys from default language to default.json
    if (!empty($langTranslations)) {
        $syncDirection = "{$defaultLang}.json → default.json";
        $clash = setMultilingualClash($defaultTranslations, $langTranslations, "{$defaultLang}.json", 'default.json');
        if ($clash !== null) {
            setMultilingualRefuse($clash, $lockHandle, $lockFile);
        }
        // The words default.json lacks, through the shared writer (its size check).
        $missing = missingTranslations_setMultilingual($defaultTranslations, $langTranslations, $syncedKeys);
        if ($missing !== []) {
            $written = writeTranslationsToFile('default', $missing, false);
            if (!$written['ok']) {
                setMultilingualRefuse(array_merge($written, ['message' => 'Failed to update default.json']), $lockHandle, $lockFile);
            }
        }
    }
}

/**
 * Count total keys in nested array
 */
function countKeys(array $arr): int {
    $count = 0;
    foreach ($arr as $value) {
        $count++;
        if (is_array($value)) {
            $count += countKeys($value);
        }
    }
    return $count;
}

// Update config.php - use freshConfig as the base (not stale CONFIG constant).
// Only the mode changes: a project stores its language codes, and their names
// come from the installation's language list wherever they are shown.
$config = $freshConfig;
$config['MULTILINGUAL_SUPPORT'] = $enabled;

$configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

// The size check, as the translation file had it (qs_small_write_guard): a
// refusal takes that file back too.
if (!qs_small_write_guard($configPath, strlen($configContent))) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
    qs_small_write_send_refusal();
}

// Write config (we already hold the lock from earlier)
$result = @file_put_contents($configPath, $configContent, LOCK_EX);

// Release the lock we acquired at the top
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

if ($result === false) {
    // The words the sync wrote are taken back: the switch did not happen.
    qs_small_write_rollback(qs_small_write_journal());
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to update config.php')
        ->send();
}

// Clear opcache after writing
qs_opcache_invalidate($configPath);

$mode = $enabled ? 'multilingual' : 'mono-language';

$responseData = [
    'multilingual_enabled' => $enabled,
    'changed' => true,
    'mode' => $mode,
    'default_language' => $defaultLang
];

if ($syncedKeys > 0) {
    $responseData['sync'] = [
        'direction' => $syncDirection,
        'keys_added' => $syncedKeys
    ];
}

ApiResponse::create(200, 'operation.success')
    ->withMessage("Successfully switched to {$mode} mode")
    ->withData($responseData)
    ->send();
