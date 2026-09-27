<?php
/**
 * The installation's language list: which languages a project may add, and what each is called.
 *
 * ONE LIST FOR THE WHOLE INSTALLATION, KEPT IN CONFIG. The engine reads
 * `<secure>/management/config/languages.json` when the installation has made its own copy, and
 * the shipped `languages.json.example` beside it otherwise. The shape is {"code": "name", ...};
 * a key starting with `_` is a comment.
 *
 * EVERY ENTRY IS CHECKED WHEN THE LIST IS READ, and an entry that breaks a rule is dropped:
 *   - the code is `language_code` — 2 or 3 lowercase letters, an ISO 639 code as BCP 47 writes a
 *     primary language subtag;
 *   - the name is `language_name`, at most 100 bytes.
 * That check is what lets every caller trust the answer to "is this code in the list?": a value
 * that passes can only be one of those strings, so it is safe as a path segment
 * (`translate/<code>.json`), inside a regex, and in `<html lang>`. A copy that cannot be read, or
 * is not a JSON object, is ignored whole and the shipped list is used.
 *
 * THE TWO QUESTIONS every language parameter asks:
 *   - a NEW language (createProject, addLang, an import): qs_language_is_listed();
 *   - an EXISTING one (every other language parameter): qs_project_has_language().
 * Both are strict: the value must be a string and match exactly. A loose in_array() would let the
 * JSON value `true` match any code.
 *
 * NAMES ARE LOOKED UP HERE WHEN THEY ARE SHOWN. A project stores its language codes only, so a
 * code the list no longer holds is shown as the code itself (qs_language_label()).
 *
 * Engine-side only: nothing a build ships may require this file. The site's own language
 * detection is projectLanguage.php, which travels into builds and answers a different question
 * (which language a URL names).
 */

require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/** Where the installation's own copy lives; the shipped list is this path plus `.example`. */
function qs_language_list_path(): string
{
    return SECURE_FOLDER_PATH . '/management/config/languages.json';
}

/**
 * The list, code => name, in the file's order. Read once per request.
 *
 * @return array<string, string>
 */
function qs_language_list(): array
{
    static $list = null;
    if ($list === null) {
        $own = qs_language_list_path();
        $list = (is_file($own) ? qs_language_list_read($own) : null)
            ?? qs_language_list_read($own . '.example')
            ?? [];
    }
    return $list;
}

/**
 * One list file, every entry checked. Null when the file cannot be read or is not a JSON object.
 *
 * @return array<string, string>|null
 */
function qs_language_list_read(string $file): ?array
{
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw);
    if (!($data instanceof stdClass)) {
        return null;
    }
    $list = [];
    foreach (get_object_vars($data) as $code => $name) {
        // A numeric key arrives as an int, which no code is; `_` keys are comments.
        if (!is_string($code) || !RegexPatterns::match('language_code', $code)) {
            continue;
        }
        if (!is_string($name) || $name === '' || strlen($name) > 100
            || !RegexPatterns::match('language_name', $name)) {
            continue;
        }
        $list[$code] = $name;
    }
    return $list;
}

/** Whether a value is a code in the installation's list — the rule for a NEW language. */
function qs_language_is_listed($code): bool
{
    return is_string($code) && array_key_exists($code, qs_language_list());
}

/** The list's name for a code, or null when the list does not hold it. */
function qs_language_name(string $code): ?string
{
    return qs_language_list()[$code] ?? null;
}

/** What to show for one of a project's codes: its name, or the code when the list lacks it. */
function qs_language_label(string $code): string
{
    return qs_language_name($code) ?? $code;
}

/**
 * The codes the current project has, in declaration order — whatever its multilingual mode.
 *
 * @return string[]
 */
function qs_project_language_codes(): array
{
    $langs = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
        ? CONFIG['LANGUAGES_SUPPORTED']
        : [];
    return array_values(array_filter($langs, 'is_string'));
}

/**
 * Whether a value is one of the current project's languages — the rule for an EXISTING one.
 * $acceptDefault admits the literal "default" (the mono-language translation file) where a
 * command takes it.
 */
function qs_project_has_language($code, bool $acceptDefault = false): bool
{
    if (!is_string($code)) {
        return false;
    }
    return ($acceptDefault && $code === 'default') || in_array($code, qs_project_language_codes(), true);
}

/** A refused value, as a message can show it: never raw non-string input, never unbounded. */
function qs_language_value_for_message($value): string
{
    if (!is_string($value)) {
        return is_scalar($value) || $value === null ? var_export($value, true) : '(' . gettype($value) . ')';
    }
    return mb_strlen($value, 'UTF-8') > 24 ? mb_substr($value, 0, 24, 'UTF-8') . '…' : $value;
}

/** The refusal for a NEW language whose code the installation's list does not hold. */
function qs_language_not_listed_response($value, string $field): ApiResponse
{
    $shown = qs_language_value_for_message($value);
    return ApiResponse::create(400, 'validation.unsupported_language')
        ->withMessage("'{$shown}' is not in this installation's language list")
        ->withErrors([[
            'field' => $field,
            'value' => $shown,
            'reason' => 'not_in_language_list',
            'expected' => 'a code from the installation\'s language list (getLanguageList)',
        ]]);
}

/** The refusal for a value that is not one of the current project's languages. */
function qs_language_not_in_project_response($value, string $field, bool $acceptDefault = false): ApiResponse
{
    $shown = qs_language_value_for_message($value);
    $allowed = qs_project_language_codes();
    if ($acceptDefault) {
        $allowed[] = 'default';
    }
    return ApiResponse::create(400, 'validation.unsupported_language')
        ->withMessage("'{$shown}' is not one of this project's languages")
        ->withErrors([[
            'field' => $field,
            'value' => $shown,
            'reason' => 'not_a_project_language',
            'allowed' => $allowed,
        ]]);
}
