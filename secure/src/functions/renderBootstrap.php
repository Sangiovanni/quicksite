<?php
/**
 * renderBootstrap.php — beta.10 C15. Tier-2 RENDER BOOTSTRAP: the public-base
 * resolution (the seam 15.2 declared, filled by 15.4).
 *
 * The split (§15.1.6 D6):
 *   - Tier 1 = public/init.php: install-wide constants every entry point needs.
 *     BASE_URL there means "where this INSTALL (panel + management API) is".
 *   - Tier 2 = THIS file: the base the RENDERED PROJECT's URLs compose against —
 *     a separate, render-scoped value. That separation IS the base-URL ↔
 *     internal-path decoupling, expressed structurally.
 *
 * Resolution chain (E2, Sangio 2026-07-24 — first non-empty wins, no stored
 * per-project value by ruling):
 *   1. QS_PUBLIC_BASE_URL server env — the DEPLOYMENT's word, declared per-vhost
 *      (SetEnv / fastcgi_param; .htaccess SetEnv works on shared hosting).
 *   2. Request-derived — always resolves, so "absent" is impossible and local
 *      dev needs zero config: the surfaceB-computed base on the render path
 *      (/p/<id>/ or a mapped domain's root), or the install base + p/<id>/ on
 *      the management path (getSiteMap's no-param default).
 * (getSiteMap's per-call `baseUrl` param rides ABOVE the chain at its own call
 * site — the author's word at generation time, unchanged since beta.8.)
 *
 * Emission (R1, Sangio): in-page links compose against the PATH form
 * (QS_PUBLIC_BASE — host- and scheme-agnostic, survives domain moves and
 * proxies); the ABSOLUTE form (QS_PUBLIC_BASE_ABS) exists only for artifacts
 * whose spec demands it (sitemap.txt; future canonical/og tags).
 *
 * Fail-safe (R4, Sangio): a malformed tier value is error_log'd and falls
 * through — a config typo degrades link targets, it never takes a render down.
 */

require_once __DIR__ . '/projectContext.php'; // qs_request_origin (R6)

if (!function_exists('qs_public_base_normalize')) {
    /**
     * Validate + normalise ONE candidate base value.
     *
     * Accepted shapes (anything else → null, caller logs and falls through):
     *   - absolute http(s) URL  → ['abs' => scheme://host/path/, 'path' => /path/]
     *   - root-relative path    → ['abs' => null,               'path' => /path/]
     * Both forms come back with EXACTLY one trailing slash on every component —
     * the invariant that kills §15.1.1's two measured silent-failure modes
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
     * The public base of the project this request renders/targets — resolved
     * once, in one place (CLAUDE.md centralize: this single helper supersedes
     * the four hand-rolled derivations §1 problem #4 named).
     *
     * @return array{abs: string, path: string, source: string}
     *         abs    always absolute (a path-only env value is completed with
     *                the validated request origin, R6);
     *         path   the R1 form in-page links compose against;
     *         source 'env' | 'derived' (observability for the health surface).
     */
    function qs_resolve_public_base(): array
    {
        // ---- tier 1: the deployment's word --------------------------------
        $env = $_SERVER['QS_PUBLIC_BASE_URL'] ?? $_SERVER['REDIRECT_QS_PUBLIC_BASE_URL'] ?? '';
        if (is_string($env) && $env !== '') {
            $norm = qs_public_base_normalize($env);
            if ($norm !== null) {
                return [
                    'abs'    => $norm['abs'] ?? (qs_request_origin() . $norm['path']),
                    'path'   => $norm['path'],
                    'source' => 'env',
                ];
            }
            // R4: degrade loudly, never die over a config typo.
            error_log(
                "QuickSite: ignoring malformed QS_PUBLIC_BASE_URL='{$env}' "
                . '(expected an absolute http(s) URL or a root-relative path) — using the derived base.'
            );
        }

        // ---- tier 2: derived from the request — always resolves -----------
        if (defined('QS_SURFACE_B')) {
            // Render path: surfaceB already computed the absolute base from the
            // validated origin (/p/<id>/ on the authoring host, / on a mapped
            // domain). Normalisation cannot fail on it by construction.
            $norm = qs_public_base_normalize(BASE_URL);
        } else {
            // Management path (e.g. getSiteMap's no-param default): the install
            // base + this project's /p/ mount. BASE_URL ends with '/'.
            $install = defined('BASE_URL') ? BASE_URL : (qs_request_origin() . '/');
            $project = (defined('PROJECT_NAME') && PROJECT_NAME !== '')
                ? 'p/' . PROJECT_NAME . '/'
                : '';
            $norm = qs_public_base_normalize($install . $project);
        }
        if ($norm === null || $norm['abs'] === null) {
            // Unreachable by construction; belt for a hostile SERVER superglobal.
            return ['abs' => qs_request_origin() . '/', 'path' => '/', 'source' => 'derived'];
        }
        return ['abs' => $norm['abs'], 'path' => $norm['path'], 'source' => 'derived'];
    }
}

// ---------------------------------------------------------------------------
// Render-path constants (defined ONLY under a surface-B render, where this
// file is loaded post-project-context by public/p/index.php; a management
// command that requires this file for the resolver function must NOT grow
// these constants, or the fallback readers would change behaviour there).
// ---------------------------------------------------------------------------
if (defined('QS_SURFACE_B') && !defined('QS_PUBLIC_BASE')) {
    $__qsPublicBase = qs_resolve_public_base();
    define('QS_PUBLIC_BASE', $__qsPublicBase['path']);
    define('QS_PUBLIC_BASE_ABS', $__qsPublicBase['abs']);
    define('QS_PUBLIC_BASE_SOURCE', $__qsPublicBase['source']);
    unset($__qsPublicBase);
}
