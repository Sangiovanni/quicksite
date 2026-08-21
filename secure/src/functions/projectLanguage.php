<?php
/**
 * Project-language detection — the single point.
 *
 * ⚠ THIS IS THE AUTHOR'S SITE's language, not QuickSite's own.
 *
 * QuickSite runs two independent language systems and they share nothing:
 *
 *   - the AUTHOR'S SITE          Translator + TrimParameters, files under
 *                                PROJECT_PATH/translate/, configured by
 *                                CONFIG['LANGUAGES_SUPPORTED'] and
 *                                MULTILINGUAL_SUPPORT, chosen by a URL path
 *                                segment.  ← this file
 *   - the ADMIN PANEL            AdminTranslation + __admin(), files under
 *                                secure/admin/translations/, chosen by ?lang=,
 *                                then the admin session, then Accept-Language.
 *
 * They look alike and are not. Nothing here may read the admin session, and
 * AdminTranslation may not call anything here.
 *
 * WHY A SHARED FUNCTION FILE RATHER THAN A METHOD ON EITHER CLASS.
 * The answer was previously written four times — twice in TrimParameters (a
 * default seed and a URL override) and twice in Translator (a constructor that
 * re-derived the same thing, and a loadTranslations() fallback that called a
 * method which did not exist). Four copies is how a request ends up with the
 * router on one language and the translator on another, and the fourth copy
 * was a fatal. One function, four callers.
 *
 * WHY `src/functions/` AND NOT `utilsManagement.php`.
 * Both callers travel into a production build, which carries only
 * src/classes/{Page,Translator,TrimParameters,RegexPatterns}.php and
 * src/functions/String.php. utilsManagement.php does not travel, so putting
 * the answer there would make every built site fatal on its first page. This
 * file is copied by the build alongside String.php, for the same reason.
 *
 * The vocabulary, smallest to largest:
 *
 *   qs_project_is_multilingual()      is this project multilingual at all?
 *   qs_project_languages()            the supported codes ([] when it is not)
 *   qs_project_default_language()     the configured fallback
 *   qs_is_project_language($segment)  is this URL segment one of them?
 *   qs_project_language_from_path()   the language this REQUEST's URL names
 *   qs_resolve_project_language()     ← THE answer. Everything else feeds it.
 */

require_once __DIR__ . '/String.php';   // removePrefix()

/**
 * Whether the project serves more than one language.
 *
 * Read from the constant rather than from `count(LANGUAGES_SUPPORTED)`: a
 * project can declare languages while multilingual mode is off, and in that
 * state the URL carries no language segment at all.
 */
function qs_project_is_multilingual(): bool
{
    return defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT;
}

/**
 * The language codes this project serves, in declaration order.
 *
 * Empty when the project is mono-language — which is what makes
 * qs_is_project_language() answer false for every segment there, so a
 * mono-language site never strips a leading path segment as a language.
 *
 * @return string[]
 */
function qs_project_languages(): array
{
    if (!qs_project_is_multilingual()) {
        return [];
    }
    $langs = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
        ? CONFIG['LANGUAGES_SUPPORTED']
        : [];
    return array_values(array_filter($langs, 'is_string'));
}

/**
 * The project's configured fallback language.
 *
 * Never empty: a project with no LANGUAGE_DEFAULT still has to name a
 * translation file, and 'en' is the code createProject seeds.
 */
function qs_project_default_language(): string
{
    $default = (defined('CONFIG') && isset(CONFIG['LANGUAGE_DEFAULT'])) ? CONFIG['LANGUAGE_DEFAULT'] : null;
    return (is_string($default) && $default !== '') ? $default : 'en';
}

/**
 * Whether a single URL segment names one of the project's languages.
 *
 * False for every segment on a mono-language project, because
 * qs_project_languages() is empty there.
 */
function qs_is_project_language(string $segment): bool
{
    return $segment !== '' && in_array($segment, qs_project_languages(), true);
}

/**
 * The language the CURRENT request's URL names, or null when it names none.
 *
 * Reads the same path TrimParameters reads, normalised the same way:
 *   - the optional PUBLIC_FOLDER_SPACE prefix is removed
 *   - on surface B, the `/p/<projectId>` marker is removed
 *
 * The marker matters because surface B rewrites REQUEST_URI part-way through
 * the request: code running before the rewrite sees `/p/<id>/fr/home`, code
 * running after sees `/fr/home`. Stripping the marker when it is there makes
 * this function give the same answer at both points, which is the entire
 * reason for having one function.
 *
 * @param string|null $requestUri Override for testing; defaults to the live request.
 */
function qs_project_language_from_path(?string $requestUri = null): ?string
{
    if (!qs_project_is_multilingual()) {
        return null;
    }
    $uri = $requestUri ?? ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === null || $path === false) {
        return null;
    }
    $path = trim($path, '/');

    $space = defined('PUBLIC_FOLDER_SPACE') ? PUBLIC_FOLDER_SPACE : '';
    if ($space !== '') {
        $path = removePrefix($path, trim($space, '/') . '/');
    }

    $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== ''));

    // Surface B: drop the `/p/<projectId>` marker when it is still on the path.
    if (defined('QS_SURFACE_B_PROJECT')
        && count($parts) >= 2 && $parts[0] === 'p' && $parts[1] === QS_SURFACE_B_PROJECT) {
        $parts = array_slice($parts, 2);
    }

    return (!empty($parts) && qs_is_project_language($parts[0])) ? $parts[0] : null;
}

/**
 * THE answer: what language is this request, for this project?
 *
 * Order:
 *   1. mono-language project            → the default, always
 *   2. an explicit, SUPPORTED candidate → that (the caller already knows)
 *   3. the request URL's leading segment → that
 *   4. otherwise                         → the default
 *
 * An unsupported candidate is discarded rather than trusted, so a caller that
 * passes through a user-controlled value cannot select a translation file that
 * the project does not declare.
 *
 * Always returns a non-empty string. Callers that must distinguish "no
 * language in this URL" from "the default" ask
 * qs_project_language_from_path() instead.
 *
 * @param string|null $candidate A language the caller already resolved, if any.
 */
function qs_resolve_project_language(?string $candidate = null): string
{
    if (!qs_project_is_multilingual()) {
        return qs_project_default_language();
    }
    if ($candidate !== null && qs_is_project_language($candidate)) {
        return $candidate;
    }
    $fromPath = qs_project_language_from_path();
    if ($fromPath !== null) {
        return $fromPath;
    }
    return qs_project_default_language();
}
