<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

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
 * - Requires at least 2 languages configured
 * 
 * @method POST
 * @url /management/setMultilingual
 * @auth required
 * @permission admin
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

// Must be boolean
if (!is_bool($enabled)) {
    // Accept string 'true'/'false' as well
    if ($enabled === 'true') {
        $enabled = true;
    } elseif ($enabled === 'false') {
        $enabled = false;
    } else {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The "enabled" parameter must be a boolean (true/false)')
            ->withErrors([['field' => 'enabled', 'value' => $enabled, 'expected' => 'boolean']])
            ->send();
    }
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
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($configPath, true);
}

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
$defaultLang = $freshConfig['LANGUAGE_DEFAULT'] ?? 'en';

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
 * Deep merge arrays - adds missing keys from source to target
 */
function deepMergeTranslations(array $target, array $source): array {
    foreach ($source as $key => $value) {
        if (!array_key_exists($key, $target)) {
            // Key doesn't exist in target, add it
            $target[$key] = $value;
        } elseif (is_array($value) && is_array($target[$key])) {
            // Both are arrays, recurse
            $target[$key] = deepMergeTranslations($target[$key], $value);
        }
        // If key exists and isn't an array-to-array case, keep target's value
    }
    return $target;
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
        $beforeCount = countKeys($langTranslations);
        $langTranslations = deepMergeTranslations($langTranslations, $defaultTranslations);
        $afterCount = countKeys($langTranslations);
        $syncedKeys = $afterCount - $beforeCount;
        
        // Save updated language file
        $result = @file_put_contents(
            $defaultLangFile, 
            json_encode($langTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        if ($result === false) {
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Failed to update {$defaultLang}.json")
                ->send();
        }
    }
} else {
    // Switching TO mono-language
    // Sync: LANGUAGE_DEFAULT file -> default.json
    // Add missing keys from default language to default.json
    if (!empty($langTranslations)) {
        $syncDirection = "{$defaultLang}.json → default.json";
        $beforeCount = countKeys($defaultTranslations);
        $defaultTranslations = deepMergeTranslations($defaultTranslations, $langTranslations);
        $afterCount = countKeys($defaultTranslations);
        $syncedKeys = $afterCount - $beforeCount;
        
        // Save updated default file
        $result = @file_put_contents(
            $defaultFile, 
            json_encode($defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        if ($result === false) {
            ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage('Failed to update default.json')
                ->send();
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

// Update config.php - use freshConfig as the base (not stale CONFIG constant)
$config = $freshConfig;
$config['MULTILINGUAL_SUPPORT'] = $enabled;

// Common language display names
$commonLanguages = [
    'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch',
    'it' => 'Italiano', 'pt' => 'Português', 'nl' => 'Nederlands', 'ru' => 'Русский',
    'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어', 'ar' => 'العربية',
    'hi' => 'हिन्दी', 'pl' => 'Polski', 'sv' => 'Svenska', 'da' => 'Dansk',
    'no' => 'Norsk', 'fi' => 'Suomi', 'tr' => 'Türkçe', 'cs' => 'Čeština',
    'el' => 'Ελληνικά', 'he' => 'עברית', 'th' => 'ไทย', 'vi' => 'Tiếng Việt'
];

if ($enabled) {
    // Ensure LANGUAGES_NAME includes all supported languages with proper display names
    $languagesName = $config['LANGUAGES_NAME'] ?? [];
    foreach ($config['LANGUAGES_SUPPORTED'] as $langCode) {
        if (!isset($languagesName[$langCode])) {
            $languagesName[$langCode] = $commonLanguages[$langCode] ?? ucfirst($langCode);
        }
    }
    $config['LANGUAGES_NAME'] = $languagesName;
} else {
    // When disabling multilingual, reset LANGUAGES_NAME to just the default language
    // This ensures a clean state for future multilingual setup
    $config['LANGUAGES_NAME'] = [
        $defaultLang => $commonLanguages[$defaultLang] ?? ucfirst($defaultLang)
    ];
}

$configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

// Write config (we already hold the lock from earlier)
$result = @file_put_contents($configPath, $configContent, LOCK_EX);

// Release the lock we acquired at the top
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

if ($result === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to update config.php')
        ->send();
}

// Clear opcache after writing
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($configPath, true);
}

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
