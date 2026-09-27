<?php
/**
 * A project's settings — the config.php keys a command writes — and the one rule each follows.
 *
 * ONE RULE PER SETTING, IN ONE PLACE. Every command that writes a setting checks the value it is
 * about to write with qs_project_settings_guard() and writes nothing when it refuses; the import
 * checks an archive's config.json with qs_project_settings_first_error(). Both ask
 * qs_project_setting_error(), so a setting reaches a project by archive only if a command could
 * have written it, and the two cannot drift apart. A writer may check its own input first, to
 * answer in terms of its own parameter; the rule here is the one that decides.
 *
 * ONE KEY LIST. QS_PROJECT_SETTING_KEYS is what an export carries and what an import takes back;
 * a key missing from it is lost on a round trip.
 *
 *   SITE_NAME                    createProject, cloneProject   text, at most 200 characters, no control characters
 *   LANGUAGES_SUPPORTED          createProject, addLang,       a non-empty list of distinct language codes
 *                                deleteLang
 *   LANGUAGE_DEFAULT             createProject, setDefaultLang a code listed in LANGUAGES_SUPPORTED
 *   MULTILINGUAL_SUPPORT         createProject, setMultilingual true or false
 *   THEME_MODE_ENABLED,          setThemeMode                  true or false
 *   THEME_USER_TOGGLE_ENABLED
 *   THEME_DEFAULT                setThemeMode                  light, dark or system
 *   FAVICON_PATH                 editFavicon,                  /assets/images/ and a file name with a favicon extension
 *                                qs_favicon_repoint()
 *   MAX_BUILD_SIZE_MB            no command; build reads it    a positive whole number
 *
 * A language code's SHAPE is checked here. Whether a NEW code may be used is the installation's
 * language list (languageRegistry.php), which the writers that add a language and the import ask
 * separately: a project keeps a language the list later drops.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/filePolicy.php'; // qs_favicon_extensions

const QS_PROJECT_SETTING_KEYS = [
    'SITE_NAME',
    'LANGUAGES_SUPPORTED',
    'LANGUAGE_DEFAULT',
    'MULTILINGUAL_SUPPORT',
    'THEME_MODE_ENABLED',
    'THEME_DEFAULT',
    'THEME_USER_TOGGLE_ENABLED',
    'FAVICON_PATH',
    'MAX_BUILD_SIZE_MB',
];

/**
 * Why $value cannot be setting $key, or null when it can.
 *
 * @param array $settings the settings it is written beside: LANGUAGE_DEFAULT must be listed in
 *                        their LANGUAGES_SUPPORTED (['en'] when they have none, the list a
 *                        rebuilt config then gets)
 */
function qs_project_setting_error(string $key, $value, array $settings = []): ?string
{
    $codeRule = RegexPatterns::getDescription('language_code');
    switch ($key) {
        case 'SITE_NAME':
            return (is_string($value) && mb_strlen($value, 'UTF-8') <= 200 && !preg_match('/[\x00-\x1F\x7F]/', $value))
                ? null
                : 'SITE_NAME must be text of at most 200 characters, with no control characters.';

        case 'LANGUAGES_SUPPORTED':
            $isCode = static fn($v): bool => is_string($v) && RegexPatterns::match('language_code', $v);
            return (is_array($value) && $value !== [] && array_values($value) === $value
                    && count(array_filter($value, $isCode)) === count($value)
                    && count(array_unique($value)) === count($value))
                ? null
                : "LANGUAGES_SUPPORTED must be a list of distinct language codes ({$codeRule}), not empty.";

        case 'LANGUAGE_DEFAULT':
            $list = $settings['LANGUAGES_SUPPORTED'] ?? ['en'];
            return (is_string($value) && RegexPatterns::match('language_code', $value)
                    && is_array($list) && in_array($value, $list, true))
                ? null
                : "LANGUAGE_DEFAULT must be a language code ({$codeRule}) listed in LANGUAGES_SUPPORTED.";

        case 'MULTILINGUAL_SUPPORT':
        case 'THEME_MODE_ENABLED':
        case 'THEME_USER_TOGGLE_ENABLED':
            return is_bool($value) ? null : "{$key} must be true or false.";

        case 'THEME_DEFAULT':
            return in_array($value, ['light', 'dark', 'system'], true) ? null : 'THEME_DEFAULT must be light, dark or system.';

        case 'FAVICON_PATH':
            $prefix = '/assets/images/';
            $file = (is_string($value) && strpos($value, $prefix) === 0) ? substr($value, strlen($prefix)) : '';
            return ($file !== '' && strlen($file) <= 100 && RegexPatterns::match('file_name_with_ext', $file)
                    && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), qs_favicon_extensions(), true))
                ? null
                : "FAVICON_PATH must be {$prefix} followed by a file name of at most 100 bytes with a favicon extension ("
                    . implode(', ', qs_favicon_extensions()) . ').';

        case 'MAX_BUILD_SIZE_MB':
            return (is_int($value) && $value > 0) ? null : 'MAX_BUILD_SIZE_MB must be a positive whole number.';
    }
    return "{$key} is not a project setting.";
}

/**
 * The first setting in $settings its rule refuses, or null. A setting that is absent (or null)
 * is not checked: the import's rebuild gives it its default. Keys that are not settings are
 * ignored — the import does not take them.
 *
 * @return array{key: string, message: string}|null
 */
function qs_project_settings_first_error(array $settings): ?array
{
    foreach (QS_PROJECT_SETTING_KEYS as $key) {
        if (!isset($settings[$key])) {
            continue;
        }
        $message = qs_project_setting_error($key, $settings[$key], $settings);
        if ($message !== null) {
            return ['key' => $key, 'message' => $message];
        }
    }
    return null;
}

/**
 * The refusal a writer answers when a setting it is about to write breaks its rule, or null when
 * every one of $keys is valid in $config (the whole config the writer is about to write).
 */
function qs_project_settings_guard(array $config, array $keys): ?ApiResponse
{
    foreach ($keys as $key) {
        $message = qs_project_setting_error($key, $config[$key] ?? null, $config);
        if ($message !== null) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage($message)
                ->withErrors([['field' => $key, 'reason' => 'invalid_setting']]);
        }
    }
    return null;
}
