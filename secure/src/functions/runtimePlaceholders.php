<?php
/**
 * `{{param:NAME}}` and `{{resolved:NAME}}` — one definition of what they mean.
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
 * renders as compact JSON. Both surfaces now call the same two functions.
 *
 * ESCAPING IS THE CALLER'S JOB. These return raw substituted text; the caller
 * runs it through htmlspecialchars, exactly as the renderer always did.
 */

require_once __DIR__ . '/resolverRegistry.php';
require_once __DIR__ . '/projectLanguage.php';

if (!function_exists('qs_apply_route_params')) {
    /**
     * Replace `{{param:NAME}}` with this request's URL path params.
     *
     * An unknown name is left as the literal placeholder, on purpose: a
     * silently-empty value hides a typo, a visible `{{param:slgu}}` does not.
     *
     * @param string               $text        Text possibly containing placeholders.
     * @param array<string,string> $routeParams Matched params, e.g. ['slug' => 'red-vase'].
     */
    function qs_apply_route_params(string $text, array $routeParams): string
    {
        if (empty($routeParams) || strpos($text, '{{param:') === false) {
            return $text;
        }
        return preg_replace_callback(
            '/\{\{param:([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            static function ($m) use ($routeParams) {
                return $routeParams[$m[1]] ?? $m[0];
            },
            $text
        );
    }
}

if (!function_exists('qs_apply_resolved')) {
    /**
     * Replace `{{resolved:NAME}}` and `{{resolved:NAME.dot.path}}` with values
     * the server-side resolver fetched for this request.
     *
     * Source order: an explicit array when the caller has one, otherwise the
     * per-request stash the resolver lifecycle wrote.
     *
     * Rules, all of which the live renderer already established:
     *   - unknown name or wrong-typed path  → the literal placeholder survives,
     *     so a typo is visible
     *   - a NULL ancestor                   → empty string, matching how a
     *     direct `{{resolved:nullKey}}` renders. This is what makes
     *     `onMiss: render-empty` produce a blank rather than raw `{{...}}`
     *     text on the page
     *   - array / object                    → compact JSON
     *   - bool                              → `true` / `false`
     *
     * @param string     $text     Text possibly containing placeholders.
     * @param array|null $resolved Explicit values; null falls back to the stash.
     */
    function qs_apply_resolved(string $text, ?array $resolved = null): string
    {
        if (strpos($text, '{{resolved:') === false) {
            return $text;
        }
        if ($resolved === null) {
            $resolved = qs_get_resolved_vars();
        }
        if (empty($resolved)) {
            return $text;
        }
        return preg_replace_callback(
            '/\{\{resolved:([a-zA-Z_][a-zA-Z0-9_.]*)\}\}/',
            static function ($m) use ($resolved) {
                $cursor = $resolved;
                foreach (explode('.', $m[1]) as $part) {
                    if ($cursor === null) {
                        return '';
                    }
                    if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                        return $m[0];
                    }
                    $cursor = $cursor[$part];
                }
                if (is_array($cursor) || is_object($cursor)) {
                    return json_encode($cursor, JSON_UNESCAPED_SLASHES);
                }
                if (is_bool($cursor)) {
                    return $cursor ? 'true' : 'false';
                }
                return (string) ($cursor ?? '');
            },
            $text
        );
    }
}

if (!function_exists('qs_apply_runtime_placeholders')) {
    /**
     * Both substitutions, in the order the live renderer applies them.
     *
     * ⚠ RESOLVED FIRST, PARAMS SECOND, and the order is load-bearing. A route
     * param comes straight out of the URL, so it is visitor-controlled; doing
     * params first would let `/products/{{resolved:secret}}` land a real
     * placeholder in the text and have the resolved pass expand it. Substituting
     * resolved values first means anything a param introduces afterwards is
     * never re-scanned.
     *
     * This is the entry point compiled pages call.
     *
     * @param array<string,string> $routeParams
     */
    function qs_apply_runtime_placeholders(string $text, array $routeParams, ?array $resolved = null): string
    {
        return qs_apply_route_params(qs_apply_resolved($text, $resolved), $routeParams);
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
 * ⚠ ONE SOURCE FOR BOTH SURFACES. The renderer derived these in
 * getSystemPlaceholders(); the compiler GENERATED PHP that re-derived them into
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
        $currentPage = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');

        // The URL space, when the site is mounted under one.
        $space = defined('PUBLIC_FOLDER_SPACE') ? trim(PUBLIC_FOLDER_SPACE, '/') : '';
        if ($space !== '') {
            $currentPage = removePrefix($currentPage, $space . '/');
        }

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

if (!function_exists('qs_apply_system_placeholders')) {
    /**
     * Replace every `{{__name}}` in a string.
     *
     * ⚠ THE LANGUAGE-SWITCH FORM IS NOT HANDLED HERE.
     * `{{__current_page;lang=xx}}` resolves to a COMPLETE URL — base, language
     * and route already in place — so whoever substitutes it must also know not
     * to compose it against the base a second time. Both surfaces detect it
     * before calling this, and both exempt the result from URL rewriting.
     *
     * An unrecognised name is left as the literal placeholder, matching how an
     * unknown `{{param:}}` behaves: a visible `{{__lnag}}` is a typo the author
     * can see, an empty string is not.
     */
    function qs_apply_system_placeholders(string $text, array $ctx = []): string
    {
        if (strpos($text, '{{__') === false) {
            return $text;
        }
        $map = qs_system_placeholders($ctx);
        return preg_replace_callback(
            '/\{\{(__\w+)\}\}/',
            static function ($m) use ($map) {
                return $map[$m[1]] ?? $m[0];
            },
            $text
        );
    }
}
