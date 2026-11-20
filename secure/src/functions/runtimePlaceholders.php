<?php
/**
 * The placeholders a page fills when it is served — `{{param:NAME}}` (this address's values), `{{resolved:NAME}}`
 * (what the server-side resolver fetched for this request) and the system ones (`{{__lang}}` and friends, below):
 * one definition of what they mean, which both surfaces call.
 *
 * ⚠ THESE ARE REQUEST-TIME PLACEHOLDERS, not compile-time ones. Their values
 * are not knowable when a page is compiled: a param comes from the URL that is
 * being served right now, and a resolved value comes from an HTTP call made
 * during that request. That is why the compiler cannot fold them into the
 * generated PHP the way it folds a translation key — it has to emit a CALL to
 * these functions, and a built page evaluates them exactly like a live one.
 *
 * WHY A SHARED FILE. The renderer (live `/p/<projectId>/`) had these as private
 * methods and the compiler had no notion of them at all, so a built page shipped
 * `slug=[{{param:slug}}]` and `product=[{{resolved:product}}]` to the visitor as
 * literal text. Giving the compiler its own copy would have produced two
 * definitions of a substitution rule that has real subtleties — an unknown name
 * stays literal so a typo is visible, a null ancestor renders empty, an array
 * renders as compact JSON. Both surfaces call the same function.
 *
 * ⚠ ONE PASS. Every placeholder of a text is replaced at once, from the text as its
 * author wrote it (qs_fill_placeholders()), and no value is ever read again: a value
 * that itself holds placeholder braces (an address's part, a resolver's answer, the
 * page's own address in a system value) is drawn as it is. Filled one kind after
 * another, a value filled early is read again by a later pass, and whichever kind
 * comes first can bring a placeholder in for the next: an address carrying a
 * resolver's placeholder would show a field of the page's data.
 *
 * ESCAPING IS THE CALLER'S JOB. These return raw substituted text; the caller
 * runs it through htmlspecialchars, exactly as the renderer always did.
 */

require_once __DIR__ . '/resolverRegistry.php';
require_once __DIR__ . '/projectLanguage.php';
// A translation's markers: read in a text node, removed from a title (qs_page_title()). A build carries both files.
require_once __DIR__ . '/textMarkers.php';

if (!function_exists('qs_fill_placeholders')) {
    /**
     * Every placeholder of a text filled in one pass, from the text as written: no value is read again (the
     * file's header). Both surfaces call it for a text node, a raw text and an attribute.
     *
     *   {{param:NAME}}           this request's address values; an unknown name stays as written, so a
     *                            typo is visible
     *   {{resolved:NAME[.path]}} what the resolver fetched (qs_resolved_value())
     *   {{__name}}               the system values (qs_system_placeholders()); an unknown name stays as written
     *   {{__name;key=value;…}}   in an attribute alone ($languageSwitch given): {{__current_page;lang=xx}} is
     *                            this page's address in that language, any other the system value of its name;
     *                            in a text it stays as written
     *
     * @param string        $text           the text, as its author wrote it
     * @param array|null    $system         the system values' context (qs_system_placeholders()), or null:
     *                                      no system placeholder is filled (a page title, a description)
     * @param array         $routeParams    this request's address values, e.g. ['slug' => 'red-vase']
     * @param array|null    $resolved       the resolver's values, or null for the request's stash
     * @param callable|null $languageSwitch an attribute's: (string $lang) => this page's address in that language
     */
    function qs_fill_placeholders(string $text, ?array $system, array $routeParams, ?array $resolved = null, ?callable $languageSwitch = null): string
    {
        if (strpos($text, '{{') === false) {
            return $text;
        }
        $map = null;
        return preg_replace_callback(
            '/\{\{(?:(__\w+)(;[^}]+)?|resolved:([a-zA-Z_][a-zA-Z0-9_.]*)|param:([a-zA-Z_][a-zA-Z0-9_]*))\}\}/',
            static function (array $m) use ($system, $routeParams, &$resolved, $languageSwitch, &$map): string {
                if (($m[4] ?? '') !== '') {
                    return isset($routeParams[$m[4]]) ? (string) $routeParams[$m[4]] : $m[0];
                }
                if (($m[3] ?? '') !== '') {
                    if ($resolved === null) {
                        $resolved = qs_get_resolved_vars();
                    }
                    return qs_resolved_value($resolved, $m[3]) ?? $m[0];
                }
                if ($system === null) {
                    return $m[0];
                }
                if (($m[2] ?? '') !== '') {
                    if ($languageSwitch === null) {
                        return $m[0];
                    }
                    $params = qs_placeholder_params(substr($m[2], 1));
                    if ($m[1] === '__current_page' && isset($params['lang'])) {
                        return (string) $languageSwitch($params['lang']);
                    }
                }
                if ($map === null) {
                    $map = qs_system_placeholders($system);
                }
                return isset($map[$m[1]]) ? (string) $map[$m[1]] : $m[0];
            },
            $text
        );
    }
}

if (!function_exists('qs_resolved_value')) {
    /**
     * What `{{resolved:NAME}}` / `{{resolved:NAME.dot.path}}` shows, from the values the resolver fetched for this
     * request; null when the placeholder stays as written. The rules, which the live renderer established:
     *   - no values at all, an unknown name or a wrong-typed path → as written, so a typo is visible
     *   - a NULL ancestor → '', matching how a direct `{{resolved:nullKey}}` renders: this is what makes
     *     `onMiss: render-empty` produce a blank rather than raw `{{...}}` text on the page
     *   - an array or object → compact JSON; a boolean → `true` / `false`
     */
    function qs_resolved_value(array $resolved, string $path): ?string
    {
        if ($resolved === []) {
            return null;
        }
        $cursor = $resolved;
        foreach (explode('.', $path) as $part) {
            if ($cursor === null) {
                return '';
            }
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }
        if (is_array($cursor) || is_object($cursor)) {
            return (string) json_encode($cursor, JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($cursor)) {
            return $cursor ? 'true' : 'false';
        }
        return (string) ($cursor ?? '');
    }
}

if (!function_exists('qs_placeholder_params')) {
    /** A system placeholder's parameters, `key=value;key=value` (each trimmed; a part without `=` ignored). */
    function qs_placeholder_params(string $paramString): array
    {
        $params = [];
        foreach (explode(';', $paramString) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$k, $v] = explode('=', $pair, 2);
                $params[trim($k)] = trim($v);
            }
        }
        return $params;
    }
}

if (!function_exists('qs_holds_language_switch')) {
    /**
     * Whether a value holds a language switch, `{{__current_page;lang=xx}}`: asked BEFORE it is filled, as what it
     * fills in is a complete address, which a URL attribute must not compose against the site's base again. The
     * scan qs_fill_placeholders() makes, so the two cannot drift.
     */
    function qs_holds_language_switch(string $value): bool
    {
        if (!preg_match_all('/\{\{(__\w+)(?:;([^}]+))?\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($matches as $match) {
            if ($match[1] === '__current_page' && isset(qs_placeholder_params($match[2] ?? '')['lang'])) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('qs_apply_runtime_placeholders')) {
    /**
     * The address's values and the resolver's, in one pass, and no system value: what a page title and a
     * description take (qs_fill_placeholders() with no system context).
     *
     * @param array<string,string> $routeParams
     */
    function qs_apply_runtime_placeholders(string $text, array $routeParams, ?array $resolved = null): string
    {
        return qs_fill_placeholders($text, null, $routeParams, $resolved);
    }
}

if (!function_exists('qs_page_title')) {
    /**
     * The title a page shell writes, before escaping: the page's own title with this
     * request's placeholders filled — or, when the page has no title in the language served
     * (translate() answered its missing marker for a page.titles key), the last segment of
     * the address, readable: "get-started" reads "Get started", a parameter's segment shows
     * its value ("/products/red-vase" reads "Red vase"), the home page reads "Home" and an
     * address no route matches "404". Both shells call it, PageManagement on /p/ and Page in
     * a build, so every page gets the same title whatever its template.
     *
     * ⚠ The fallback is never filled: it comes from the address, and an address carrying
     * `{{resolved:…}}` keeps that text as written.
     *
     * A title shows no formatting: the title's markers (textMarkers.php) are removed and its words
     * kept, before its placeholders are filled, so no filled value is ever read as one.
     *
     * @param string $title The title the page's template passed (usually a translate() answer)
     * @param object|null $route This request's TrimParameters (route(), routeParams(), routeFound())
     */
    function qs_page_title(string $title, $route): string
    {
        $routeParams = $route !== null ? $route->routeParams() : [];
        require_once __DIR__ . '/../classes/Translator.php';
        if (!Translator::isMissing($title, 'page.titles.')) {
            return qs_apply_runtime_placeholders(qs_text_markers_remove($title), $routeParams);
        }
        $segments = $route !== null ? $route->route() : [];
        $segment = $segments === [] ? 'home' : (string) $segments[count($segments) - 1];
        if ($segment !== '' && $segment[0] === ':') {
            $segment = (string) ($routeParams[substr($segment, 1)] ?? substr($segment, 1));
        }
        $text = trim((string) preg_replace('/[\s_-]+/u', ' ', $segment));
        if ($text === '') {
            $text = 'home';
        }
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }
}

/**
 * ---------------------------------------------------------------------------
 * SYSTEM PLACEHOLDERS — `{{__lang}}` and friends.
 * ---------------------------------------------------------------------------
 *
 * These REPORT a value; none of them composes a URL. An author who needs a URL
 * writes a root-relative one and lets the URL policy compose it, once.
 *
 * ⚠ ONE SOURCE FOR BOTH SURFACES. The renderer derived these in a method of
 * its own; the compiler GENERATED PHP that re-derived them into
 * a compiled page, including its own regexes for stripping the URL space and
 * the language prefix. Two derivations of one answer, and they had drifted: the
 * generated language stripper interpolated a language list into a pattern and
 * fell back to a hardcoded `(en|fr)` when CONFIG was absent, while the renderer
 * read CONFIG directly. The language question already has a single answer in
 * projectLanguage.php, which travels into a build — so both surfaces ask it.
 */

if (!function_exists('qs_system_placeholders')) {
    /**
     * The system placeholder map for THIS request.
     *
     * @param array $ctx Optional overrides. `lang` is supplied by a caller that
     *                   already resolved it (the renderer's context, a compiled
     *                   page's $lang) so one request cannot answer two ways.
     * @return array<string,string>
     */
    function qs_system_placeholders(array $ctx = []): array
    {
        // The page part of the address: the site's base taken off once, as the router
        // takes it off (the URL space in a build; on /p/, the marker surface B removed).
        $currentPage = qs_path_without_base((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''));

        // The language segment, asked of the single detection point rather than
        // matched against an interpolated list. A mono-language project answers
        // null here, so nothing is stripped — which is the behaviour a
        // mono-language site needs and the old hardcoded fallback could not give.
        $lang = qs_project_language_from_path();
        if ($lang !== null) {
            $currentPage = removePrefix($currentPage, $lang . '/');
        }

        $resolvedLang = $ctx['lang'] ?? null;
        if (!is_string($resolvedLang) || $resolvedLang === '') {
            $resolvedLang = qs_resolve_project_language();
        }

        // The leaf of the current page, ALWAYS derived from current_page.
        //
        // ⚠ Deliberately not taken from a caller-supplied context. The
        // renderer's context['page'] is populated differently depending on
        // who built it — a page loader passes routePath() (the whole path),
        // PageManagement passes page() (the leaf) — so honouring it made
        // {{__current_route}} mean 'en/deep' in preview and 'deep' in a
        // build for the same nested route. One derivation, no caller can
        // disagree with it.
        $route = $currentPage === '' ? 'home' : basename($currentPage);

        return [
            '__current_page'  => $currentPage,
            '__lang'          => $resolvedLang,
            '__public_folder' => defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : 'public',
            '__current_route' => $route,
        ];
    }
}
