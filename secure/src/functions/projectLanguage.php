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
 * Both callers travel into a production build, which carries a fixed list of
 * engine files — src/classes/{Page,Translator,TrimParameters}.php and
 * src/functions/String.php among them. utilsManagement.php does not travel, so
 * putting the answer there would make every built site fatal on its first page.
 * This file is copied by the build alongside String.php, for the same reason.
 *
 * The vocabulary, smallest to largest:
 *
 *   qs_project_is_multilingual()      is this project multilingual at all?
 *   qs_project_default_language()     its default language, else 'en'
 *   qs_project_language_codes()       its languages, whatever the mode
 *   qs_project_languages()            the supported codes ([] when it is not)
 *   qs_is_project_language($segment)  is this URL segment one of them?
 *   qs_path_without_base($path)       a request path with the site's base taken off, once
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
 * ONE FALLBACK FOR A PROJECT THAT EXISTS. Every place that reads an existing
 * project's default language or its languages asks the two functions below,
 * never CONFIG directly, so a project whose config.php leaves a setting out is
 * read the same way everywhere: by the router, the translator, the link
 * builder, a build's compiled pages, every command and the admin panel.
 *   - its default language: LANGUAGE_DEFAULT, else 'en';
 *   - its languages: LANGUAGES_SUPPORTED, else its default language alone.
 * The fallback is 'en', QuickSite's shipped default language — not the
 * installation's own default, which a built site cannot read: a build carries
 * no installation config. A NEW project's language is a different question
 * (languageRegistry.php).
 *
 * Both take the settings to read: a config.php array a caller already holds
 * (an export, a project listing, the panel's edited project), or nothing for
 * the current project's CONFIG.
 */
function qs_project_language_settings(?array $config): array
{
    if ($config !== null) {
        return $config;
    }
    return (defined('CONFIG') && is_array(CONFIG)) ? CONFIG : [];
}

/**
 * The project's default language. Never empty: a project still has to name a
 * translation file.
 */
function qs_project_default_language(?array $config = null): string
{
    $default = qs_project_language_settings($config)['LANGUAGE_DEFAULT'] ?? null;
    return (is_string($default) && $default !== '') ? $default : 'en';
}

/**
 * The project's language codes, in declaration order, whatever its
 * multilingual mode. Never empty: a project with no list speaks its default
 * language.
 *
 * @return string[]
 */
function qs_project_language_codes(?array $config = null): array
{
    $settings = qs_project_language_settings($config);
    $langs = (isset($settings['LANGUAGES_SUPPORTED']) && is_array($settings['LANGUAGES_SUPPORTED']))
        ? array_values(array_filter($settings['LANGUAGES_SUPPORTED'], static fn($l): bool => is_string($l) && $l !== ''))
        : [];
    return $langs !== [] ? $langs : [qs_project_default_language($settings)];
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
    return qs_project_is_multilingual() ? qs_project_language_codes() : [];
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
 * A request path with the site's base taken off: the part the router reads, with no leading or
 * trailing slash.
 *
 * The base is taken off ONCE, by the rule of the surface serving the request:
 *   - a built site, and every other entry point: the URL space (PUBLIC_FOLDER_SPACE);
 *   - surface B (`/p/<projectId>/`): everything up to and including the first `p` segment and the
 *     one after it, which is how surface B binds the project — the space, if any, is part of it.
 *     Surface B then rewrites REQUEST_URI to the part after the marker, and from that point there
 *     is nothing left to take off. Taking the space off again by name would take a page's own first
 *     segment when it is spelled like the space: under the space `web`, `/web/p/<id>/web/x` is the
 *     page `web/x`.
 *
 * Every reader of the request path asks this, so the router, the alias rewrite, the language
 * reader and the system placeholders agree on where the page part starts.
 */
function qs_path_without_base(string $path): string
{
    $path = trim($path, '/');
    if (defined('QS_SURFACE_B')) {
        if (!empty($GLOBALS['__qs_sb']['rewritten'])) {
            return $path;
        }
        $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== ''));
        $count = count($parts);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($parts[$i] === 'p') {
                return implode('/', array_slice($parts, $i + 2));
            }
        }
        return $path;
    }
    $space = defined('PUBLIC_FOLDER_SPACE') ? trim((string) PUBLIC_FOLDER_SPACE, '/') : '';
    return $space !== '' ? removePrefix($path, $space . '/') : $path;
}

/**
 * The language the CURRENT request's URL names, or null when it names none.
 *
 * Reads the same path TrimParameters reads, with the base taken off the same way
 * (qs_path_without_base()). Surface B rewrites REQUEST_URI part-way through the request: code
 * running before the rewrite sees `/p/<id>/fr/home`, code running after sees `/fr/home`, and both
 * get the same answer.
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

    $parts = array_values(array_filter(explode('/', qs_path_without_base($path)), static fn($p) => $p !== ''));

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
