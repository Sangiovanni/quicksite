<?php
/**
 * Route helpers — shared utilities for parameterised routes (beta.8 A1).
 *
 * Param routes use ':name' segments (e.g., 'products/:slug') in
 * routes.php for readability AND to match the doc's URL pattern syntax.
 * NTFS reserves ':' in path components, so any place that maps a route
 * path to a filesystem path must sanitise ':' segments to '__name'.
 *
 * **This file is the canonical source of that sanitisation.** Anywhere
 * that builds a filesystem path from a route path should:
 *   require_once __DIR__ . '/../functions/routeHelpers.php';  // adjust relative path
 *   $fsPath = paramRoutePathToFs($routePath);
 *
 * Avoids drift across inline copies (we already shipped two before
 * consolidating — see public/index.php and the bug-fix path in
 * JsonToHtmlRenderer::renderPage).
 *
 * Locked design 2026-06-04, BETA8_PARAMETERISED_ROUTES.md.
 */

if (!function_exists('paramRoutePathToFs')) {
    /**
     * Translate a route path with `:name` param segments into a
     * filesystem-safe form by replacing each `:name` with `__name`.
     *
     * Routes.php key stays ':slug' (matches doc URL syntax); page
     * folders on disk use '__slug'. Pattern matches ':' only at the
     * START of a segment (after start-of-string or '/') so that any
     * stray ':' elsewhere in a path is preserved untouched.
     *
     * Examples:
     *   'products'           → 'products'
     *   'products/:slug'     → 'products/__slug'
     *   ':lang/products'     → '__lang/products'
     *   'users/:id/profile'  → 'users/__id/profile'
     *
     * @param string $routePath
     * @return string
     */
    function paramRoutePathToFs(string $routePath): string {
        return preg_replace('#(^|/):([a-zA-Z_][a-zA-Z0-9_]*)#', '$1__$2', $routePath);
    }
}

if (!function_exists('paramRouteSegmentToFs')) {
    /**
     * Translate a single route segment from ':name' to '__name'.
     * Pass-through for segments that don't start with ':'. Useful when
     * iterating segments individually (e.g., the dispatcher's
     * resolveTemplateFile builds the path from an array of segments).
     *
     * @param string $segment
     * @return string
     */
    function paramRouteSegmentToFs(string $segment): string {
        return (strlen($segment) > 1 && $segment[0] === ':')
            ? '__' . substr($segment, 1)
            : $segment;
    }
}

if (!function_exists('fsRoutePathToParam')) {
    /**
     * Reverse of paramRoutePathToFs — translate `__name` filesystem
     * segments back to `:name` route-pattern syntax. Used when SCANNING
     * the filesystem (e.g., scanAllPageJsonFiles) to derive route
     * identities from on-disk paths.
     *
     * Without this, admin UIs / API responses would surface 'test/__slug'
     * instead of 'test/:slug' — confusing because the user authored the
     * route as ':slug' in routes.php.
     *
     * Pattern matches `__` only at the start of a segment (after start
     * or '/'), so existing folders named like 'foo__bar' stay untouched.
     *
     * Examples:
     *   'products'           → 'products'
     *   'products/__slug'    → 'products/:slug'
     *   '__lang/products'    → ':lang/products'
     *
     * @param string $fsRoutePath
     * @return string
     */
    function fsRoutePathToParam(string $fsRoutePath): string {
        return preg_replace('#(^|/)__([a-zA-Z][a-zA-Z0-9_]*)#', '$1:$2', $fsRoutePath);
    }
}
