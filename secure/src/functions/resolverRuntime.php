<?php
/**
 * Firing a route's server-side resolvers — the single lifecycle.
 *
 * A resolver fetches data over HTTP *before* the page renders, so the values
 * are in the HTML at first paint. That is a REQUEST-time job by definition: no
 * amount of precompilation can make an upstream call ahead of time. So a built
 * site runs the same lifecycle a live one does, from this file, and the two
 * cannot answer differently.
 *
 * ⚠ WHERE THIS RUNS IN THE REQUEST. After the route is matched and the template
 * file resolved, BEFORE the page is included. The page reads what this leaves
 * behind through `{{resolved:NAME}}`, so it has to be populated first.
 *
 * ⚠ EDITOR EMULATION IS DELIBERATELY NOT HERE. The visual editor previews a
 * resolver-backed page by passing mock values in the URL (`?_editor=1&
 * _emulate=…`) instead of firing the real resolver. That belongs to the admin
 * preview surface and nowhere else: a built site is a public website, and
 * teaching it to accept resolver values from a query parameter would let any
 * visitor dictate what the page says. The renderer's entry point decides which
 * configs to pass in; this function fires whatever it is given.
 */

require_once __DIR__ . '/resolverRegistry.php';
require_once __DIR__ . '/../classes/DataResolver.php';

if (!function_exists('qs_resolver_error_is_config_bug')) {
    /**
     * Is this failure a LOCAL misconfiguration rather than an upstream problem?
     *
     * The distinction decides what the visitor sees. An upstream 404 means "no
     * such product" and deserves the project's own 404 page; a resolver
     * pointing at an endpoint that is not in the registry means the site is
     * broken and the developer needs to see it loudly — even on a route that
     * opted into `onMiss: render-empty`, which is about upstream failures, not
     * about typos.
     *
     * Recognised by status 0 (nothing was sent) plus an error string this
     * engine emits itself.
     */
    function qs_resolver_error_is_config_bug(string $errMsg, int $status): bool
    {
        if ($status !== 0) {
            return false;
        }
        return stripos($errMsg, 'not found in registry') !== false
            || stripos($errMsg, 'API not found')         !== false
            || stripos($errMsg, 'callableFrom')          !== false
            || stripos($errMsg, 'apiKey not configured') !== false
            || stripos($errMsg, 'missing endpoint')      !== false
            || stripos($errMsg, 'missing required field') !== false;
    }
}

if (!function_exists('qs_resolve_route_data')) {
    /**
     * Fire a route's data resolvers and publish what they returned.
     *
     * On success the values land in the per-request stash in two shapes: the
     * FLAT namespace (`{{resolved:title}}`, collision-checked when the resolver
     * was saved) and a per-resolver one (`{{resolved:r0.title}}`), so a route
     * with several resolvers can keep overlapping expose names and address them
     * explicitly.
     *
     * On failure this function DOES NOT RETURN — it answers and exits, because
     * a page whose data did not arrive must not render as though it did:
     *
     *   upstream 4xx        → the project's 404 page, HTTP 404. A missing slug
     *                         is a not-found, not a crash.
     *   upstream 5xx or 0   → the project's 500 page, HTTP 500.
     *   local config bug    → a plain inline 500 naming the error, so the
     *                         developer sees the misconfiguration instead of a
     *                         styled 404 that hides it.
     *
     * Per-resolver `onMiss: render-empty` never reaches any of those: resolveMany
     * absorbs it and the page renders with null values for that resolver's keys.
     *
     * @param array  $resolverConfigs Configs for this route (empty is a no-op).
     * @param string $routePath       Matched route, for diagnostics.
     * @param array  $routeParams     URL path params, an input source.
     */
    function qs_resolve_route_data(array $resolverConfigs, string $routePath, array $routeParams): void
    {
        if (empty($resolverConfigs)) {
            return;
        }

        $resolver = new DataResolver();
        $context  = [
            'routeParams'  => $routeParams,
            'query'        => $_GET,
            // Server-side session inputs are not wired yet; a bearer-authed
            // server fetch with no session token 401s upstream, and public
            // resolvers (auth=none) work today.
            'session'      => [],
            'cookieHeader' => $_SERVER['HTTP_COOKIE'] ?? null,
        ];

        $result = $resolver->resolveMany($resolverConfigs, $context);

        // Cache observability. serverFetchMulti records one status per resolver
        // in order, so a multi-resolver route reports "hit,miss,disabled"
        // instead of each one clobbering the last. Guarded because a template
        // that has already started printing cannot take a header.
        if (!headers_sent() && isset($GLOBALS['__qs_resolver_cache_statuses'])) {
            header('X-QS-Resolver-Cache: ' . implode(',', $GLOBALS['__qs_resolver_cache_statuses']));
        }

        if ($result['ok']) {
            $namespace = $result['exposed'];
            foreach ($result['exposedByIndex'] as $idx => $vars) {
                $namespace['r' . $idx] = $vars;
            }
            qs_set_resolved_vars($namespace);
            return;
        }

        // --- failure -------------------------------------------------------
        $firstError = $result['firstError'] ?? null;
        $status     = (int) ($firstError['status'] ?? 0);
        $errMsg     = (string) ($firstError['error'] ?? 'unknown resolver error');

        $GLOBALS['__qs_resolver_failure'] = [
            'route'  => $routePath,
            'status' => $status,
            'error'  => $errMsg,
        ];

        if (qs_resolver_error_is_config_bug($errMsg, $status)) {
            http_response_code(500);
            echo "<h1>500 — Data resolver misconfigured</h1>\n";
            echo '<p>Route: <code>' . htmlspecialchars($routePath, ENT_QUOTES, 'UTF-8') . "</code></p>\n";
            echo '<p>Error: ' . htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') . "</p>\n";
            echo "<p><small>This is a config bug — the resolver references something that doesn't exist or is invalid. Fix the resolver config (or the API registry) and reload.</small></p>\n";
            exit;
        }

        if ($status >= 400 && $status < 500) {
            http_response_code(404);
            qs_resolver_render_error_page('404', "<h1>404 — Not Found</h1>\n<p>The requested content was not found.</p>\n");
            exit;
        }

        http_response_code(500);
        qs_resolver_render_error_page('500', "<h1>500 — Server Error</h1>\n<p>Something went wrong while fetching data for this page. Please try again later.</p>\n");
        exit;
    }
}

if (!function_exists('qs_resolver_render_error_page')) {
    /**
     * Render the project's own error template for a resolver failure, falling
     * back to plain text when the project has none.
     *
     * Both layouts are accepted — `pages/404/404.php` (what a build writes and
     * what the engine has produced since nested routes landed) and the flat
     * `pages/404.php` that older projects still carry.
     *
     * @param string $code     '404' or '500'.
     * @param string $fallback Plain HTML when no template exists.
     */
    function qs_resolver_render_error_page(string $code, string $fallback): void
    {
        $candidates = [
            PROJECT_PATH . '/templates/pages/' . $code . '/' . $code . '.php',
            PROJECT_PATH . '/templates/pages/' . $code . '.php',
        ];
        foreach ($candidates as $file) {
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        echo $fallback;
    }
}
