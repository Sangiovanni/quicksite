<?php
/**
 * setSocialPreview Command
 *
 * Writes what a shared link's card is drawn from: the SITE's social values, or ONE PAGE's, and a page's
 * description in one language. A served page writes them as its description, Open Graph and X tags
 * (socialPreview.php); getSocialPreview reads them back with what each page's tags will say.
 *
 *   site (no route)  picture, cardType, xAccount, themeColor, locales
 *   page (route)     picture, cardType, description (with lang)
 *
 * A value sent empty ("" or null) removes it; a value not sent is kept. `locales` merges language by language,
 * an empty territory removing that language's. The description is the translation key
 * `page.descriptions.<route>`, written as editTitle writes a title (default.json kept in step).
 *
 * In `route.write` (editor and above), beside setSiteMapConfig: both decide what the world sees of the site.
 *
 * @method POST
 * @route /management/p/<projectId>/setSocialPreview
 * @auth required (route.write)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';      // qs_page_name_refusal, SPECIAL_PAGES
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';     // qs_project_has_language
require_once SECURE_FOLDER_PATH . '/src/functions/socialPreviewHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';   // qs_translation_write_refusal_response

/**
 * A refused value, named: 400 validation.invalid_value.
 */
function __setSocialPreview_refuse(string $field, string $reason, string $message): ApiResponse
{
    return ApiResponse::create(400, 'validation.invalid_value')
        ->withMessage($message)
        ->withErrors([['field' => $field, 'reason' => $reason]]);
}

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params    Body parameters
 * @param array $urlParams URL segments (unused)
 */
function __command_setSocialPreview(array $params = [], array $urlParams = []): ApiResponse
{
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/setSocialPreview');
    }

    $route = $params['route'] ?? null;
    if ($route === '') {
        $route = null;
    }
    $isPage = $route !== null;
    $fields = $isPage ? array_merge(QS_SOCIAL_PAGE_FIELDS, ['description', 'lang']) : QS_SOCIAL_SITE_FIELDS;

    // ---- every field is one this target holds -----------------------------------------------------
    foreach (array_keys($params) as $name) {
        $name = (string) $name;
        if ($name === 'route' || in_array($name, $fields, true)) {
            continue;
        }
        $known = in_array($name, QS_SOCIAL_SITE_FIELDS, true) || in_array($name, ['description', 'lang'], true);
        return __setSocialPreview_refuse($name, 'unknown_field', $known
            ? ($isPage ? "'{$name}' is a value of the site: send it without route" : "'{$name}' is a value of a page: send it with route")
            : "'{$name}' is not a social value (" . ($isPage ? 'a page holds ' : 'the site holds ') . implode(', ', $fields) . ')');
    }
    $sent = array_values(array_filter($fields, static fn($f) => array_key_exists($f, $params) && $f !== 'lang'));
    if ($sent === []) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Send at least one value to write: ' . implode(', ', array_diff($fields, ['lang'])))
            ->withErrors([['field' => implode(', ', array_diff($fields, ['lang'])), 'reason' => 'nothing_to_write']]);
    }

    // ---- the target ---------------------------------------------------------------------------------
    if ($isPage) {
        $refusal = qs_social_route_refusal($route, 'route');
        if ($refusal !== null) {
            return $refusal;
        }
        $route = trim((string) $route, '/');
    }

    // ---- every value checked before anything is written ---------------------------------------------
    $changes = [];
    foreach (['picture', 'cardType', 'xAccount', 'themeColor'] as $field) {
        if (!in_array($field, $sent, true)) {
            continue;
        }
        $value = $params[$field];
        if ($value === null || $value === '') {
            $changes[$field] = null;
            continue;
        }
        if (!is_string($value)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage("{$field} must be a string")
                ->withErrors([['field' => $field, 'reason' => 'invalid_type', 'expected' => 'string']]);
        }
        if ($field === 'xAccount' && $value[0] !== '@') {
            $value = '@' . $value;
        }
        // A 3-digit colour is written with each digit doubled (#369 is #336699): Discord reads 6 or 8 digits alone.
        if ($field === 'themeColor' && preg_match('/^#[0-9A-Fa-f]{3}$/D', $value) === 1) {
            $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        $problem = $field === 'picture'
            ? qs_social_picture_problem(PROJECT_PATH, $value)
            : qs_social_value_problem($field, $value);
        if ($problem !== null) {
            return __setSocialPreview_refuse($field, 'invalid_value', ucfirst($problem));
        }
        $changes[$field] = $value;
    }

    $locales = null;
    if (in_array('locales', $sent, true)) {
        if (!is_array($params['locales']) || ($params['locales'] !== [] && array_is_list($params['locales']))) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('locales must be an object of a language code to its territory ({"fr": "FR"})')
                ->withErrors([['field' => 'locales', 'reason' => 'invalid_type', 'expected' => 'object']]);
        }
        $locales = [];
        foreach ($params['locales'] as $code => $territory) {
            $code = (string) $code;
            if (!qs_project_has_language($code)) {
                return qs_language_not_in_project_response($code, 'locales');
            }
            if ($territory === null || $territory === '') {
                $locales[$code] = null;
                continue;
            }
            $territory = is_string($territory) ? strtoupper($territory) : $territory;
            $problem = qs_social_value_problem('locales', [$code => $territory]);
            if ($problem !== null) {
                return __setSocialPreview_refuse('locales', 'invalid_value', ucfirst($problem));
            }
            $locales[$code] = $territory;
        }
    }

    $description = null;
    $lang = null;
    if (in_array('description', $sent, true)) {
        $description = $params['description'] ?? '';
        if (!is_string($description)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('description must be a string')
                ->withErrors([['field' => 'description', 'reason' => 'invalid_type', 'expected' => 'string']]);
        }
        $description = trim($description);
        if (mb_strlen($description, 'UTF-8') > QS_SOCIAL_DESCRIPTION_MAX) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage('description must not exceed ' . QS_SOCIAL_DESCRIPTION_MAX . ' characters')
                ->withErrors([['field' => 'description', 'max_length' => QS_SOCIAL_DESCRIPTION_MAX,
                    'received_length' => mb_strlen($description, 'UTF-8')]]);
        }
        $lang = $params['lang'] ?? null;
        if ($lang === null || $lang === '') {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage('lang is required with description: the language the description is in')
                ->withErrors([['field' => 'lang', 'reason' => 'required']]);
        }
        if (!qs_project_has_language($lang)) {
            return qs_language_not_in_project_response($lang, 'lang');
        }
    } elseif (array_key_exists('lang', $params)) {
        return __setSocialPreview_refuse('lang', 'unknown_field', 'lang names the language of a description: send description with it');
    }

    // ---- the writes: the values, then the description ------------------------------------------------
    // Each goes through the size check; a refusal takes back every file this command wrote before it.
    $values = qs_social_values_read(PROJECT_PATH);
    unset($values['media']);
    $target = $isPage ? ($values['pages'][$route] ?? []) : $values['site'];
    foreach ($changes as $field => $value) {
        if ($value === null) {
            unset($target[$field]);
        } else {
            $target[$field] = $value;
        }
    }
    if ($locales !== null) {
        $merged = $target['locales'] ?? [];
        foreach ($locales as $code => $territory) {
            if ($territory === null) {
                unset($merged[$code]);
            } else {
                $merged[$code] = $territory;
            }
        }
        ksort($merged, SORT_STRING);
        if ($merged === []) {
            unset($target['locales']);
        } else {
            $target['locales'] = $merged;
        }
    }
    if ($isPage) {
        $values['pages'][$route] = $target;
    } else {
        $values['site'] = $target;
    }

    if ($changes !== [] || $locales !== null) {
        if (!qs_social_save(PROJECT_PATH, $values)) {
            if (qs_small_write_refused() !== null) {
                return qs_small_write_refused();
            }
            return ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage('Failed to write the social values');
        }
    }

    $data = ['project' => PROJECT_NAME, 'route' => $isPage ? $route : null, 'values' => (object) $target];
    if ($description !== null) {
        $written = qs_social_write_description(PROJECT_PATH, $route, (string) $lang, $description);
        if (!$written['ok']) {
            // A refused description takes back the social values written above: the command changes nothing.
            return qs_translation_write_refusal_response(array_merge($written, ['message' => 'Failed to write the description']),
                ['language' => $lang]);
        }
        $data['description'] = [
            'lang'            => $lang,
            'text'            => $description !== '' ? $description : null,
            'removed'         => $description === '' ? (bool) $written['removed'] : false,
            'translation_key' => 'page.descriptions.' . $route,
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($isPage ? "Social values of page '{$route}' saved" : 'Social values of the site saved')
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setSocialPreview($trimParams->params(), $trimParams->additionalParams())->send();
}
