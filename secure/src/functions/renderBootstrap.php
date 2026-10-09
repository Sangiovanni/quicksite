<?php
/**
 * renderBootstrap.php — the base a RENDERED project's URLs compose against.
 *
 *   - public/init.php defines the install-wide constants every entry point needs.
 *     BASE_URL there means "where this INSTALL (panel + management API) is".
 *   - THIS file: the base the RENDERED PROJECT's URLs compose against — a separate,
 *     render-scoped value. That separation is what keeps a link off the install's
 *     internal layout.
 *
 * Two forms, and they come from different places:
 *
 *   path  what every in-page URL composes against (QS_PUBLIC_BASE): root-relative,
 *         host- and scheme-agnostic. ALWAYS derived from the request — the
 *         `/p/<projectId>/` the page is being served under on the render path, the
 *         install base + `p/<projectId>/` on the management path (the editor's
 *         fragment render). A page is served where it is served; nothing declared
 *         elsewhere can move its links, its scripts or its stylesheet.
 *   abs   the absolute form, for an artifact whose spec demands one (sitemap.txt).
 *         The QS_PUBLIC_BASE_URL server variable, when the deployment declares it
 *         (per vhost: SetEnv / fastcgi_param; .htaccess SetEnv on shared hosting),
 *         because a sitemap has to name the URL the site will be DEPLOYED at, which
 *         this install cannot derive from the request it is answering. Otherwise the
 *         derived base, made absolute. (getSiteMap's per-call `baseUrl` param rides
 *         above both, at its own call site.)
 *
 * Fail-safe: a malformed QS_PUBLIC_BASE_URL is error_log'd and falls through to the
 * derived base — a config typo degrades a sitemap, it never takes a render down.
 */

require_once __DIR__ . '/projectContext.php'; // qs_request_origin

if (!function_exists('qs_public_base_normalize')) {
    /**
     * Validate + normalise ONE candidate base value.
     *
     * Accepted shapes (anything else → null, caller logs and falls through):
     *   - absolute http(s) URL  → ['abs' => scheme://host/path/, 'path' => /path/]
     *   - root-relative path    → ['abs' => null,               'path' => /path/]
     * Both forms come back with EXACTLY one trailing slash on every component —
     * the invariant that kills two silent-failure modes
     * (no-leading-slash relative links, glued-host absolutes) and the
     * pre-existing `//` in emitted asset URLs.
     *
     * @return array{abs: ?string, path: string}|null
     */
    function qs_public_base_normalize(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\s\0]/', $value) === 1) {
            return null;
        }
        // A base carries no query/fragment — composing against one is malformed.
        if (strpos($value, '?') !== false || strpos($value, '#') !== false) {
            return null;
        }

        if ($value[0] === '/') {
            $path = '/' . trim($value, '/');
            $path = ($path === '/') ? '/' : $path . '/';
            return ['abs' => null, 'path' => $path];
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            if ($parts === false || empty($parts['host'])) {
                return null;
            }
            $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            $path = '/' . trim($parts['path'] ?? '/', '/');
            $path = ($path === '/') ? '/' : $path . '/';
            return ['abs' => $origin . $path, 'path' => $path];
        }

        return null;
    }
}

if (!function_exists('qs_resolve_public_base')) {
    /**
     * The public base of the project this request renders or targets — resolved once,
     * in one place.
     *
     * @return array{abs: string, path: string}
     *         abs   always absolute: the declared QS_PUBLIC_BASE_URL (a path-only value
     *               completed with the validated request origin), else the derived base;
     *         path  the root-relative form in-page URLs compose against — always the
     *               derived one.
     */
    function qs_resolve_public_base(): array
    {
        // ---- the derived base: where this request's project is served ------
        if (defined('QS_SURFACE_B')) {
            // Render path: surface B already computed the absolute /p/<id>/ base
            // from the validated origin. Normalisation cannot fail on it by
            // construction.
            $norm = qs_public_base_normalize(BASE_URL);
        } else {
            // Management path (the editor's fragment render, getSiteMap's
            // default): the install base + this project's /p/ mount. BASE_URL
            // ends with '/'.
            $install = defined('BASE_URL') ? BASE_URL : (qs_request_origin() . '/');
            $project = (defined('PROJECT_NAME') && PROJECT_NAME !== '')
                ? 'p/' . PROJECT_NAME . '/'
                : '';
            $norm = qs_public_base_normalize($install . $project);
        }
        if ($norm === null || $norm['abs'] === null) {
            // Unreachable by construction; belt for a hostile SERVER superglobal.
            $norm = ['abs' => qs_request_origin() . '/', 'path' => '/'];
        }

        // ---- the absolute form: the deployment's word, when it declared one --
        $env = $_SERVER['QS_PUBLIC_BASE_URL'] ?? $_SERVER['REDIRECT_QS_PUBLIC_BASE_URL'] ?? '';
        if (is_string($env) && $env !== '') {
            $declared = qs_public_base_normalize($env);
            if ($declared !== null) {
                return [
                    'abs'  => $declared['abs'] ?? (qs_request_origin() . $declared['path']),
                    'path' => $norm['path'],
                ];
            }
            // Degrade loudly, never die over a config typo.
            error_log(
                "QuickSite: ignoring malformed QS_PUBLIC_BASE_URL='{$env}' "
                . '(expected an absolute http(s) URL or a root-relative path) — using the derived base.'
            );
        }
        return ['abs' => $norm['abs'], 'path' => $norm['path']];
    }
}

if (!function_exists('qs_render_public_base')) {
    /**
     * The base a RENDER composes its relative URLs against — answerable from
     * ANY request context, not only a surface-B one.
     *
     * The constant below exists only under a surface-B render. The editor also asks
     * `/management/` to render a FRAGMENT of a page, and the fallback a reader used
     * there was BASE_URL, which on the management path is where the INSTALL is, not
     * where the PROJECT is served: an inserted `/assets/videos/intro.mp4` came back as
     * `http://host/assets/videos/intro.mp4`, a location that serves nothing, while
     * reloading the preview produced `/p/<id>/assets/videos/intro.mp4`.
     *
     * One function, so the two contexts cannot answer differently:
     *   - surface-B render → the constant already resolved for this request;
     *   - anywhere else    → the same derivation, whose management-path branch
     *     composes the install base with this request's bound project.
     */
    function qs_render_public_base(): string
    {
        if (defined('QS_PUBLIC_BASE')) {
            return QS_PUBLIC_BASE;
        }
        return qs_resolve_public_base()['path'];
    }
}

// ---------------------------------------------------------------------------
// The render-path constant (defined ONLY under a surface-B render, where this
// file is loaded post-project-context by public/p/index.php; a management
// command that requires this file for the resolver function must NOT grow
// it, or the fallback readers would change behaviour there).
// ---------------------------------------------------------------------------
if (defined('QS_SURFACE_B') && !defined('QS_PUBLIC_BASE')) {
    define('QS_PUBLIC_BASE', qs_resolve_public_base()['path']);
}
