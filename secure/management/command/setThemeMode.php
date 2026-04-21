<?php
/**
 * setThemeMode - Enable/disable dark mode support and configure theme defaults
 *
 * All three parameters are optional — only the ones provided get written to config.
 *
 * @method POST
 * @url    /management/setThemeMode
 * @auth   required
 * @permission admin
 *
 * Body (all optional):
 * {
 *   "enabled":    true|false,          // THEME_MODE_ENABLED
 *   "default":    "light"|"dark"|"system",  // THEME_DEFAULT
 *   "userToggle": true|false           // THEME_USER_TOGGLE_ENABLED
 * }
 */

$params = $trimParametersManagement->params();

// ── Validate parameters ────────────────────────────────────────────────────

$hasEnabled    = array_key_exists('enabled', $params);
$hasDefault    = array_key_exists('default', $params);
$hasUserToggle = array_key_exists('userToggle', $params);

if (!$hasEnabled && !$hasDefault && !$hasUserToggle) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('At least one of: enabled, default, userToggle must be provided')
        ->send();
}

// Coerce "enabled"
$enabled = null;
if ($hasEnabled) {
    $enabled = $params['enabled'];
    if ($enabled === 'true')  $enabled = true;
    if ($enabled === 'false') $enabled = false;
    if (!is_bool($enabled)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('"enabled" must be a boolean (true/false)')
            ->send();
    }
}

// Coerce "default"
$defaultTheme = null;
if ($hasDefault) {
    $defaultTheme = trim((string)$params['default']);
    if (!in_array($defaultTheme, ['light', 'dark', 'system'], true)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('"default" must be "light", "dark", or "system"')
            ->send();
    }
}

// Coerce "userToggle"
$userToggle = null;
if ($hasUserToggle) {
    $userToggle = $params['userToggle'];
    if ($userToggle === 'true')  $userToggle = true;
    if ($userToggle === 'false') $userToggle = false;
    if (!is_bool($userToggle)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('"userToggle" must be a boolean (true/false)')
            ->send();
    }
}

// ── Lock, read, patch, write config ───────────────────────────────────────

$configPath = PROJECT_PATH . '/config.php';
$lockFile   = $configPath . '.lock';
$lockHandle = @fopen($lockFile, 'w');

if ($lockHandle === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage('Failed to create config lock file')
        ->send();
}

if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage('Failed to acquire config lock')
        ->send();
}

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
        ->withMessage('Failed to read configuration file')
        ->send();
}

$config = $freshConfig;
$changes = [];

if ($hasEnabled) {
    $config['THEME_MODE_ENABLED'] = $enabled;
    $changes['THEME_MODE_ENABLED'] = $enabled;
}
if ($hasDefault) {
    $config['THEME_DEFAULT'] = $defaultTheme;
    $changes['THEME_DEFAULT'] = $defaultTheme;
}
if ($hasUserToggle) {
    $config['THEME_USER_TOGGLE_ENABLED'] = $userToggle;
    $changes['THEME_USER_TOGGLE_ENABLED'] = $userToggle;
}

$configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

$result = @file_put_contents($configPath, $configContent, LOCK_EX);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

if ($result === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to update config.php')
        ->send();
}

if (function_exists('opcache_invalidate')) {
    opcache_invalidate($configPath, true);
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Theme mode updated successfully')
    ->withData([
        'changes' => $changes,
        'current' => [
            'THEME_MODE_ENABLED'       => $config['THEME_MODE_ENABLED']       ?? false,
            'THEME_DEFAULT'            => $config['THEME_DEFAULT']            ?? 'light',
            'THEME_USER_TOGGLE_ENABLED'=> $config['THEME_USER_TOGGLE_ENABLED']?? false,
        ]
    ])
    ->send();
