<?php
/**
 * Request-shaped helpers a SERVED page needs — the validated host, and the
 * project-namespaced cookie name.
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
            if (preg_match('/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host) === 1) {
                return true;
            }
            // RFC-1123 labels (letters/digits/hyphen, dot-separated), optional port.
            return preg_match(
                '/^[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*(:\d{1,5})?$/',
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
     * Split from qs_request_host() for one caller: OAuthHandler decides its own
     * scheme with a test that also honours X-Forwarded-Proto, so handing it a
     * whole origin would silently regress every reverse-proxy deployment from
     * https to http. One validator, two accessors.
     */
    function qs_request_origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        return ($https ? 'https://' : 'http://') . qs_request_host();
    }
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
