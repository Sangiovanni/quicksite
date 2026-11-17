<?php
/**
 * Request-shaped helpers a SERVED page needs — the validated host, and the
 * project-namespaced cookie name; the request's scheme, the address the deployment
 * declares and the one composer of a full address (qs_full_address()).
 *
 * Both were parked inside much larger files for historical reasons rather than
 * because they belong there: `qs_request_host()` sat in projectContext.php,
 * which is the install's `secure/projects/<id>/` loader, and
 * `qs_project_cookie_name()` sat in storageHelpers.php, which is 390 lines of
 * storage-registry authoring. Neither of those files can travel into a
 * production build, and both of these functions have to — the OAuth flow uses
 * exactly these two and nothing else out of either.
 *
 * The original files require this one, so every existing caller is unchanged
 * and there is one definition of each.
 */

if (!function_exists('qs_request_host')) {
    /**
     * The request's host[:port], VALIDATED — never the raw header.
     *
     * Every URL the engine composes against the request used to read
     * `$_SERVER['HTTP_HOST']` directly, which is attacker-controlled on any
     * catch-all vhost. Validation runs in two stages:
     *
     *   1. SHAPE — an RFC-1123 hostname or a bracketed IPv6 literal, with an
     *      optional port. Anything else (CRLF, slashes, spaces, userinfo, a
     *      scheme) is discarded and the chain falls back to SERVER_NAME, then
     *      to 'localhost', logging so a misconfigured proxy is visible.
     *   2. TRUST (optional) — when the deployment sets QS_TRUSTED_HOSTS
     *      (comma-separated exact host[:port] values, per-vhost SetEnv /
     *      fastcgi_param), a host outside the list is replaced by the FIRST
     *      entry. Degrade rather than die: links point at the canonical host
     *      instead of the request being refused over a config mismatch, and the
     *      spoofed value never reaches any output either way.
     *
     * PRE-INIT SAFE: touches no constants.
     *
     * @return string "host" or "host:port" — never empty, never attacker-shaped.
     */
    function qs_request_host(): string
    {
        $shapeOk = static function ($host): bool {
            if (!is_string($host) || $host === '' || strlen($host) > 255) {
                return false;
            }
            // Bracketed IPv6 literal, optional port: [::1] / [::1]:8443
            if (preg_match('/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/D', $host) === 1) {
                return true;
            }
            // RFC-1123 labels (letters/digits/hyphen, dot-separated), optional port.
            return preg_match(
                '/^[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*(:\d{1,5})?$/D',
                $host
            ) === 1;
        };

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!$shapeOk($host)) {
            $fallback = $_SERVER['SERVER_NAME'] ?? '';
            $host     = $shapeOk($fallback) ? $fallback : 'localhost';
            error_log(
                'QuickSite: rejected malformed Host header'
                . ' — falling back to ' . $host
                . ' (set QS_TRUSTED_HOSTS to pin the canonical host).'
            );
        }

        $trusted = $_SERVER['QS_TRUSTED_HOSTS'] ?? $_SERVER['REDIRECT_QS_TRUSTED_HOSTS'] ?? '';
        if (is_string($trusted) && trim($trusted) !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $trusted)), $shapeOk));
            if ($list !== [] && !in_array($host, $list, true)) {
                error_log(
                    "QuickSite: Host '{$host}' is not in QS_TRUSTED_HOSTS"
                    . " — using canonical '{$list[0]}' instead."
                );
                $host = $list[0];
            }
        }

        return $host;
    }
}

if (!function_exists('qs_request_origin')) {
    /**
     * The request's scheme + validated host, with no trailing slash.
     *
     * The scheme is qs_request_is_https()'s, the one scheme test, so behind a proxy
     * ending HTTPS (`X-Forwarded-Proto: https`) the origin reads https, as every full
     * address a served page carries does. Its callers: the panel's BASE_URL
     * (init.php), `/p/`'s absolute base (surfaceB.php), a sitemap's default base
     * (renderBootstrap.php) and the CORS same-origin test (handleCors()).
     */
    function qs_request_origin(): string
    {
        return (qs_request_is_https() ? 'https://' : 'http://') . qs_request_host();
    }
}

if (!function_exists('qs_request_is_https')) {
    /**
     * Whether this request reached the site over HTTPS: the server says so (`HTTPS` set to anything
     * but "off"), the port is 443, or a proxy in front says `X-Forwarded-Proto: https`.
     *
     * The forwarded scheme can only UPGRADE: any other value of the header changes nothing, so a
     * request cannot make a page served over HTTPS name itself `http://`. The one scheme test of a
     * served page: `_oauthIsHttps()` answers with it, qs_request_origin() takes its scheme from it,
     * and every full address the page carries is composed with it (qs_full_address()).
     *
     * PRE-INIT SAFE: touches no constants.
     */
    function qs_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }
}

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
     * It lives here, beside the composer, because a build carries this file and not renderBootstrap.php.
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

if (!function_exists('qs_declared_public_base')) {
    /**
     * The public address the DEPLOYMENT declares for the site, normalised, or null when it declares none.
     *
     * The server variable `QS_PUBLIC_BASE_URL` (per vhost: SetEnv / fastcgi_param; .htaccess SetEnv on
     * shared hosting): an absolute http(s) address, or a root-relative path the request's own scheme
     * and host complete. The one reader of it: qs_resolve_public_base() (a sitemap's absolute form)
     * and qs_full_address() (every full address a page carries) both ask here.
     *
     * A malformed value is logged, once per request, and read as no value: a config typo degrades an
     * address, it never takes a page down.
     *
     * @return array{abs: ?string, path: string}|null
     */
    function qs_declared_public_base(): ?array
    {
        static $read = [];
        $env = $_SERVER['QS_PUBLIC_BASE_URL'] ?? $_SERVER['REDIRECT_QS_PUBLIC_BASE_URL'] ?? '';
        if (!is_string($env) || $env === '') {
            return null;
        }
        if (array_key_exists($env, $read)) {
            return $read[$env];
        }
        $declared = qs_public_base_normalize($env);
        if ($declared === null) {
            error_log(
                "QuickSite: ignoring malformed QS_PUBLIC_BASE_URL='{$env}' "
                . '(expected an absolute http(s) URL or a root-relative path) — using the derived base.'
            );
        }
        return $read[$env] = $declared;
    }
}

if (!function_exists('qs_full_address')) {
    /**
     * The full address (scheme, host and path) of a path on the site this request serves: the one
     * composer of an absolute address a served page carries, on `/p/<projectId>/` and in a build alike.
     *
     *   - the deployment declared an absolute address (`QS_PUBLIC_BASE_URL`): that address, then the path;
     *   - it declared a path: this request's scheme and host, that path, then the path;
     *   - it declared nothing: this request's scheme and host, the base this request derives, then the path.
     *
     * The scheme is qs_request_is_https()'s (a proxy's `X-Forwarded-Proto: https` upgrades it, nothing
     * downgrades it), the host qs_request_host()'s (its shape check and `QS_TRUSTED_HOSTS`): no request
     * header reaches the address any other way. A declared absolute address is the deployer's word and
     * is taken whole, its scheme included.
     *
     * @param string      $sitePath    a path relative to the site's base, already encoded, no leading
     *                                 slash: '' for the home page, 'fr/about', 'assets/images/x.png'
     * @param string|null $derivedBase the root-relative base this request derives; null reads
     *                                 QS_PUBLIC_BASE (a served page: `/p/<projectId>/`, or a build's `/` or
     *                                 `/<space>/`), else `/`
     */
    function qs_full_address(string $sitePath, ?string $derivedBase = null): string
    {
        $sitePath = ltrim($sitePath, '/');
        $declared = qs_declared_public_base();
        if ($declared !== null && $declared['abs'] !== null) {
            return $declared['abs'] . $sitePath;
        }
        if ($declared !== null) {
            $base = $declared['path'];
        } else {
            $derived = $derivedBase ?? (defined('QS_PUBLIC_BASE') ? (string) QS_PUBLIC_BASE : '/');
            $norm = qs_public_base_normalize($derived !== '' ? $derived : '/');
            $base = $norm !== null ? $norm['path'] : '/';
        }
        return (qs_request_is_https() ? 'https://' : 'http://') . qs_request_host() . $base . $sitePath;
    }
}

/**
 * The author-site OAuth session cookie's bare name (an AUTH CREDENTIAL), namespaced per project by
 * qs_project_cookie_name(). It lives here, beside that function, because the sign-in routes need it
 * on `/p/` before anything else is loaded and in a production build, which carries this file and
 * none of the authoring helpers.
 */
if (!defined('QS_OAUTH_COOKIE')) {
    define('QS_OAUTH_COOKIE', 'qs_oauth_user');
}

/**
 * The sign-in's state store: the one PHP session of the OAuth flow (oauthStateStore.php). It is one
 * name on a host, not namespaced per project, and it lives here for the same reason.
 */
if (!defined('QS_OAUTH_SESSION_COOKIE')) {
    define('QS_OAUTH_SESSION_COOKIE', 'qs_oauth_session');
}

/**
 * The consent record's bare name, namespaced per project by qs_project_cookie_name(). qs.js writes
 * and reads the cookie under its own QS_CONSENT_COOKIE, which holds the same name.
 */
if (!defined('QS_CONSENT_COOKIE')) {
    define('QS_CONSENT_COOKIE', 'consent_prefs');
}

if (!function_exists('qs_project_cookie_name')) {
    /**
     * The physical name of an engine-owned cookie, namespaced by project.
     *
     * A cookie is scoped by origin and path, not by URL prefix, so every
     * project served at `/p/<id>/` on one host shares a single jar. Without the
     * namespace, consent accepted on project A reads back as consented on
     * project B and B never shows its banner.
     *
     * The NAME carries the namespace, never the Path: a built site serves at
     * `/`, where `Path=/` is correct, so deriving `Path=/p/<id>/` would make
     * preview and production disagree. One behaviour everywhere.
     */
    function qs_project_cookie_name(string $bareName, ?string $projectId = null): string
    {
        if ($projectId === null) {
            $projectId = defined('PROJECT_NAME') ? (string) PROJECT_NAME : 'default';
        }
        return 'qsp_' . $projectId . '_' . $bareName;
    }
}

if (!function_exists('qs_site_path')) {
    /**
     * A value a STRANGER wrote (a `?return=`), kept only when it is a path on this site.
     *
     * A path on this site is one `/`, then neither a second `/` nor a backslash, with no control
     * character anywhere. Each refusal is a way a browser reads the value as another host or
     * another scheme: `//host` is protocol-relative; a backslash after the first `/` is read as
     * a slash; a tab, a line feed or a carriage return is dropped before the address is read, so
     * `/<tab>/host` becomes `//host`; a value not starting with `/` (a scheme, a leading space)
     * is not a path at all.
     *
     * The twin of qs.js's check for the same value, which the server never sees there. The caller
     * composes the result against the site's base: the value names a page of the site, not of
     * the host.
     *
     * @param mixed $value Anything a query string can hold: a string, an array, null.
     * @return string|null The value unchanged when it is a path on this site, else null.
     */
    function qs_site_path($value): ?string
    {
        if (!is_string($value) || $value === '' || $value[0] !== '/') {
            return null;
        }
        if (isset($value[1]) && ($value[1] === '/' || $value[1] === '\\')) {
            return null;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1 ? null : $value;
    }
}
