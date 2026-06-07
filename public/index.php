<?php
require_once 'init.php';

// --- Component Preview Mode (for Visual Editor) ---
// If ?_component={name}&_editor=1 is present, render just the component in isolation
if (isset($_GET['_component']) && isset($_GET['_editor']) && $_GET['_editor'] === '1') {
    $componentName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['_component']); // Sanitize
    
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
    $trimParameters = new TrimParameters();
    $lang = $trimParameters->lang() ?: (CONFIG['LANGUAGE_DEFAULT'] ?? 'en');
    
    require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
    $translator = new Translator($lang);
    
    require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
    $renderer = new JsonToHtmlRenderer($translator, [
        'editorMode' => true,
        'baseUrl' => BASE_URL,
        'lang' => $lang,
    ]);
    
    // Decode emulation overrides from URL parameter (editor variable emulation)
    $emulateOverrides = [];
    if (!empty($_GET['_emulate'])) {
        $decoded = base64_decode($_GET['_emulate'], true);
        if ($decoded !== false) {
            $parsed = json_decode($decoded, true);
            if (is_array($parsed)) {
                foreach ($parsed as $key => $value) {
                    if (is_string($key) && is_string($value) && strlen($value) < 500 && preg_match('/^[\w-]+$/', $key)) {
                        $emulateOverrides[$key] = $value;
                    }
                }
            }
        }
    }
    
    // Render component in isolation
    $componentHtml = $renderer->renderComponent($componentName, [], $emulateOverrides);
    
    // Output minimal HTML wrapper with component
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Component: <?= htmlspecialchars($componentName) ?></title>
    <?php $cssVersion = file_exists(PUBLIC_CONTENT_PATH . '/style/style.css') ? filemtime(PUBLIC_CONTENT_PATH . '/style/style.css') : time(); ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/style/style.css?v=<?= $cssVersion ?>">
    <style>
        /* Component preview container */
        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .component-preview-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }
        .component-preview-label {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 12px;
            color: #666;
            background: #f5f5f5;
            margin-bottom: 12px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px dashed #ddd;
        }
        .component-preview-label code {
            background: #e9ecef;
            color: #333;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="component-preview-wrapper">
        <div class="component-preview-label">
            Component: <code><?= htmlspecialchars($componentName) ?></code>
        </div>
        <?= $componentHtml ?>
    </div>
    <script src="<?= BASE_URL ?>/scripts/qs.js"></script>
</body>
</html><?php
    exit;
}

// --- Check for URL aliases BEFORE TrimParameters processes routes ---
$aliasesFile = PROJECT_PATH . '/data/aliases.json';
$aliasRewrite = null;

if (file_exists($aliasesFile)) {
    $aliases = json_decode(file_get_contents($aliasesFile), true) ?: [];
    
    // Extract the page part from URL (after language code if multilingual)
    $rawUri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    $folder = PUBLIC_FOLDER_SPACE;
    $rawUri = $folder ? preg_replace('#^' . preg_quote(trim($folder, '/'), '#') . '/?#', '', $rawUri) : $rawUri;
    
    $parts = array_filter(explode('/', $rawUri), fn($p) => $p !== '');
    $parts = array_values($parts);
    
    // Skip language code if multilingual
    if (MULTILINGUAL_SUPPORT && count($parts) > 0 && in_array($parts[0], CONFIG['LANGUAGES_SUPPORTED'])) {
        $langCode = array_shift($parts);
    }
    
    // Get the potential page/alias (now supports nested paths)
    $potentialPath = count($parts) > 0 ? '/' . implode('/', $parts) : '';
    
    if (isset($aliases[$potentialPath])) {
        $aliasInfo = $aliases[$potentialPath];
        $targetPath = ltrim($aliasInfo['target'], '/');
        $aliasType = $aliasInfo['type'] ?? 'redirect';
        
        if ($aliasType === 'redirect') {
            // 301 redirect - browser URL changes
            $langPrefix = MULTILINGUAL_SUPPORT ? '/' . ($langCode ?? CONFIG['DEFAULT_LANGUAGE']) : '';
            header('Location: ' . $langPrefix . '/' . $targetPath, true, 301);
            exit;
        } else {
            // Internal rewrite - modify the request so TrimParameters sees the target
            $aliasRewrite = $targetPath;
        }
    }
}

// If we have an alias rewrite, modify REQUEST_URI before TrimParameters
if ($aliasRewrite !== null) {
    $folder = PUBLIC_FOLDER_SPACE ? '/' . trim(PUBLIC_FOLDER_SPACE, '/') : '';
    $langPrefix = MULTILINGUAL_SUPPORT ? '/' . ($langCode ?? CONFIG['DEFAULT_LANGUAGE']) : '';
    $_SERVER['REQUEST_URI'] = $folder . $langPrefix . '/' . $aliasRewrite;
}

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();

// --- Route Resolution with Nested Routes Support ---
$route = $trimParameters->route();         // e.g., ['guides', 'installation']
$routePath = $trimParameters->routePath(); // e.g., 'guides/installation'
$routeFound = $trimParameters->routeFound();

// Handle 404
if (!$routeFound || $routePath === '404') {
    http_response_code(404);
    $templateFile = PROJECT_PATH . '/templates/pages/404/404.php';
    if (!file_exists($templateFile)) {
        // Fallback to flat structure during migration
        $templateFile = PROJECT_PATH . '/templates/pages/404.php';
    }
    if (file_exists($templateFile)) {
        require_once $templateFile;
    } else {
        echo '<h1>404 - Page Not Found</h1>';
    }
    exit;
}

// --- Resolve route to file path ---
// Convention:
//   - Route without children: guides/installation → guides/installation.php
//   - Route with children: guides → guides/guides.php (because it has children)
//
// Beta.8 A1 — param-route segments live in routes.php as ':name' for
// readability + URL pattern match, but NTFS reserves ':' in path
// components. paramRouteSegmentToFs / paramRoutePathToFs (canonical
// helpers in routeHelpers.php) sanitise to '__name' for filesystem use.
require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';

/**
 * Resolve route path to template file
 * Convention: ALL routes use folder structure - route/route.php
 */
function resolveTemplateFile(array $route, string $projectPath): string {
    $basePath = $projectPath . '/templates/pages/';
    $fsRoute = array_map('paramRouteSegmentToFs', $route);
    $routeName = end($fsRoute);
    return $basePath . implode('/', $fsRoute) . '/' . $routeName . '.php';
}

$templateFile = resolveTemplateFile($route, PROJECT_PATH);

// Fallback: try flat structure (for backward compatibility during migration)
if (!file_exists($templateFile)) {
    $fsRoutePath = paramRoutePathToFs($routePath);
    // Try simple flat file: routePath.php (e.g., guides.php)
    $flatFile = PROJECT_PATH . '/templates/pages/' . $fsRoutePath . '.php';
    if (file_exists($flatFile)) {
        $templateFile = $flatFile;
    } else {
        // Try legacy single-segment fallback (root segment, sanitised)
        $legacyFile = PROJECT_PATH . '/templates/pages/' . paramRouteSegmentToFs($route[0]) . '.php';
        if (file_exists($legacyFile)) {
            $templateFile = $legacyFile;
        }
    }
}

// Final check - if still not found, 404
if (!file_exists($templateFile)) {
    http_response_code(404);
    $notFoundFile = PROJECT_PATH . '/templates/pages/404.php';
    if (file_exists($notFoundFile)) {
        require_once $notFoundFile;
    } else {
        echo '<h1>404 - Page Not Found</h1>';
        echo '<p>Template file not found: ' . htmlspecialchars(str_replace(PROJECT_PATH, '', $templateFile)) . '</p>';
    }
    exit;
}

// ============================================================================
// SERVER-SIDE DATA RESOLVER (beta.8 A2)
// ============================================================================

/**
 * Detect whether a resolver failure is a LOCAL config bug (endpoint
 * missing from registry, callableFrom=client, apiKey not configured,
 * etc.) — those go to the inline 500 surface so devs see them loudly,
 * even when the route opted into onMiss: 'render-empty' for upstream
 * failures. Status === 0 + an error string we know we emit from
 * serverFetch / DataResolver = config bug.
 */
function _qsIsResolverConfigBug(string $errMsg, int $status): bool {
    if ($status !== 0) return false;
    return (
        stripos($errMsg, 'not found in registry') !== false ||
        stripos($errMsg, 'API not found')         !== false ||
        stripos($errMsg, 'callableFrom')          !== false ||
        stripos($errMsg, 'apiKey not configured') !== false ||
        stripos($errMsg, 'missing endpoint')      !== false ||
        stripos($errMsg, 'missing required field')!== false
    );
}

// Lifecycle position (locked Q4 in BETA8_DATA_RESOLVER.md): AFTER the
// route/auth gate, BEFORE the page template runs. Templates pick up the
// exposed vars via JsonToHtmlRenderer's {{resolved:NAME}} substitution
// or by calling getResolvedVars() directly in PHP scope.
//
// Only routes with a sidecar config in data/route-resolvers.json fire the
// resolver — overhead is one file read + one missing-key check for routes
// without a resolver. The sidecar is loaded lazily inside
// getResolverForRoute so static routes pay no cost on the hot path
// beyond the helper require.
require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';
$__resolverConfig = getResolverForRoute($routePath);

// ----------------------------------------------------------------------------
// Editor preview emulation (beta.8 A2 Track 2a)
// ----------------------------------------------------------------------------
// The visual editor previews param routes + resolver-bound pages WITHOUT
// firing the real resolver — production data is request-specific and
// the editor needs deterministic, scenario-controllable rendering. The
// editor builds the iframe URL with ?_editor=1&_emulate=<base64-json>;
// the JSON payload carries {routeParams: {...}, resolved: {...}} overrides.
//
// When editor mode is detected:
//   - routeParams from the emulation override what TrimParameters captured
//     (so `:slug` shows the author's "preview slug" everywhere — in
//     {{param:slug}}, in templates that read $trimParameters->routeParams(),
//     in state-store init sources of kind 'param:').
//   - resolved vars from the emulation feed getResolvedVars() so
//     {{resolved:NAME}} substitution renders the author's mock data.
//   - The REAL resolver is skipped entirely. Side effects (upstream API
//     calls, server-side cache writes, rate-limit consumption) belong
//     to production requests, not editor previews.
//
// Emulation values default to empty when ?_emulate is absent — the page
// renders with literal {{param:NAME}} / {{resolved:NAME}} placeholders
// visible, which the editor's inputs panel (Track 2c) lets the author
// fill in.
$__editorMode = isset($_GET['_editor']) && $_GET['_editor'] === '1';
// Beta.8 A2 Track 2e — live-data toggle. When the editor's emulation
// panel switches to "Use Live Data", the iframe URL adds _live=1. In that
// mode the REAL resolver fires (instead of being skipped), but the
// emulated routeParams still override the URL-captured ones — so the
// resolver receives the author's chosen "preview slug" while exercising
// the production fetch path. Useful for validating the page against
// real API responses without leaving the editor.
$__editorLiveMode = $__editorMode && isset($_GET['_live']) && $_GET['_live'] === '1';
$__emulateRouteParams = null;
$__emulateResolved    = null;
if ($__editorMode && !empty($_GET['_emulate'])) {
    $__emulateDecoded = base64_decode($_GET['_emulate'], true);
    if ($__emulateDecoded !== false) {
        $__emulateParsed = json_decode($__emulateDecoded, true);
        if (is_array($__emulateParsed)) {
            if (isset($__emulateParsed['routeParams']) && is_array($__emulateParsed['routeParams'])) {
                // Coerce to string values only (matches the real
                // routeParams() return shape from TrimParameters).
                $__emulateRouteParams = [];
                foreach ($__emulateParsed['routeParams'] as $k => $v) {
                    if (is_string($k) && $k !== '' && (is_string($v) || is_numeric($v))) {
                        $__emulateRouteParams[$k] = (string) $v;
                    }
                }
            }
            if (isset($__emulateParsed['resolved']) && is_array($__emulateParsed['resolved'])) {
                // Resolved supports nested values (objects / arrays) so the
                // dot-path substitution {{resolved:product.name}} works.
                $__emulateResolved = $__emulateParsed['resolved'];
            }
        }
    }
}
if ($__editorMode && $__emulateRouteParams !== null) {
    // Set the global so per-route .php templates that construct a fresh
    // TrimParameters pick up the override too.
    TrimParameters::setEmulatedRouteParams($__emulateRouteParams);
    // Also apply to index.php's already-constructed instance so the
    // route-not-found / template-file-resolution above this point sees
    // the emulated values (404 fallback behaviour stays consistent).
    $trimParameters->setRouteParams($__emulateRouteParams);
}
if ($__editorMode && !$__editorLiveMode && $__emulateResolved !== null) {
    // Skip applying resolved emulation in live mode — the real resolver
    // will populate getResolvedVars() with production data instead.
    setResolvedVars($__emulateResolved);
}

// In editor mode the production resolver is skipped UNLESS the editor
// explicitly requested live data (_live=1). Track 2e.
if ($__editorMode && !$__editorLiveMode) {
    $__resolverConfig = null;
}

if ($__resolverConfig !== null) {
    require_once SECURE_FOLDER_PATH . '/src/classes/DataResolver.php';
    $__resolver = new DataResolver();
    $__resolverContext = [
        'routeParams' => $trimParameters->routeParams(),
        'query'       => $_GET,
        // Server-side session (token, userId, etc.) is wired by Tier 3
        // server-session integration — empty for now. Bearer-authed
        // server fetches without a session token will 401 upstream;
        // public resolvers (auth=none) work today.
        'session'     => [],
        'cookieHeader'=> $_SERVER['HTTP_COOKIE'] ?? null,
    ];
    $__resolverResult = $__resolver->resolve($__resolverConfig, $__resolverContext);

    // Beta.8 A2 Slice 4 — emit cache observability header. serverFetch
    // sets $GLOBALS['__qs_resolver_cache_status'] to 'hit' / 'miss' /
    // 'skip' (no TTL configured) / 'disabled' (auth type forbids cache).
    // DevTools Network tab → response headers shows this on the
    // document request so users can watch cache behaviour without
    // tailing PHP logs. headers_sent() guards against late emission
    // when the template already started outputting.
    if (!headers_sent() && isset($GLOBALS['__qs_resolver_cache_status'])) {
        header('X-QS-Resolver-Cache: ' . $GLOBALS['__qs_resolver_cache_status']);
    }

    if ($__resolverResult['ok']) {
        setResolvedVars($__resolverResult['exposed']);
    } else if (($__resolverConfig['onMiss'] ?? null) === 'render-empty'
               && !_qsIsResolverConfigBug($__resolverResult['error'] ?? '', (int)($__resolverResult['status'] ?? 0))) {
        // Beta.8 A2 Slice 6 — onMiss: 'render-empty' opt-in.
        //
        // The route declared a fallback render path: instead of 404'ing
        // or 500'ing on resolver failure, render the template with each
        // expose key set to null. The {{resolved:NAME}} substitution
        // renders null values as empty strings (NOT as literal
        // placeholders — the key exists in the dict, just with a null
        // value), so the page content carries no stale "{{...}}" text.
        // The template's data-state-show-empty bindings (when paired
        // with a state store on the same endpoint) drive the "no data
        // found" UI.
        //
        // Config bugs still go to the inline 500 surface below — a
        // misconfigured resolver shouldn't silently render a styled
        // not-found template. That's a dev signal worth seeing.
        $__nullExposed = [];
        foreach (($__resolverConfig['expose'] ?? []) as $__varName => $__path) {
            $__nullExposed[$__varName] = null;
        }
        setResolvedVars($__nullExposed);
        // Fall through to template render — no exit.
    } else {
        // Status-aware failure routing (Track 1, after Test B feedback).
        //
        //   Upstream 4xx (item not found / unauthorized / forbidden):
        //     "the requested resource doesn't exist for this URL" — render
        //     the project's 404.php template at HTTP 404. Matches the
        //     /products/red-vase pattern in every CMS — a missing slug is
        //     a not-found, not a server crash.
        //
        //   Upstream 5xx OR transport failure (status=0 from curl error):
        //     "upstream broke" — render the project's 500.php template at
        //     HTTP 500. The server proxied the user's request and the API
        //     it depends on is down or returned a 5xx. Falls back to
        //     plain text when the project has no 500.php.
        //
        //   Local config bug (endpoint missing from registry, apiKey not
        //   configured, callableFrom=client, etc.): the resolver itself is
        //   misconfigured. status=0 + a known config-error text in 'error'.
        //   Render the ugly inline 500 so devs see the misconfig loudly
        //   instead of a styled 404 page that hides the bug.
        //
        //   onMiss: 'render-empty' (Slice 6 — not yet implemented) will
        //   take precedence over all three when configured on the route.
        $__status = (int) ($__resolverResult['status'] ?? 0);
        $__errMsg = $__resolverResult['error'] ?? 'unknown resolver error';
        $__isConfigBug = _qsIsResolverConfigBug($__errMsg, $__status);

        if ($__isConfigBug) {
            http_response_code(500);
            echo "<h1>500 — Data resolver misconfigured</h1>\n";
            echo '<p>Route: <code>' . htmlspecialchars($routePath) . "</code></p>\n";
            echo '<p>Error: ' . htmlspecialchars($__errMsg) . "</p>\n";
            echo "<p><small>This is a config bug — the resolver references something that doesn't exist or is invalid. Fix the resolver config (or the API registry) and reload.</small></p>\n";
            exit;
        }

        if ($__status >= 400 && $__status < 500) {
            // Upstream "not found / unauthorized / forbidden" → render the
            // project's 404 template. Stash the resolver status so the
            // template (or future logging) can branch on it if needed.
            $GLOBALS['__qs_resolver_failure'] = [
                'route'  => $routePath,
                'status' => $__status,
                'error'  => $__errMsg,
            ];
            http_response_code(404);
            $__notFoundFile = PROJECT_PATH . '/templates/pages/404/404.php';
            if (!file_exists($__notFoundFile)) {
                $__notFoundFile = PROJECT_PATH . '/templates/pages/404.php';
            }
            if (file_exists($__notFoundFile)) {
                require_once $__notFoundFile;
            } else {
                echo "<h1>404 — Not Found</h1>\n";
                echo "<p>The requested content was not found.</p>\n";
            }
            exit;
        }

        // Anything else (status >= 500 or status === 0 with non-config
        // error) → upstream / transport failure → 500 template.
        $GLOBALS['__qs_resolver_failure'] = [
            'route'  => $routePath,
            'status' => $__status,
            'error'  => $__errMsg,
        ];
        http_response_code(500);
        $__serverErrFile = PROJECT_PATH . '/templates/pages/500/500.php';
        if (!file_exists($__serverErrFile)) {
            $__serverErrFile = PROJECT_PATH . '/templates/pages/500.php';
        }
        if (file_exists($__serverErrFile)) {
            require_once $__serverErrFile;
        } else {
            echo "<h1>500 — Server Error</h1>\n";
            echo "<p>Something went wrong while fetching data for this page. Please try again later.</p>\n";
        }
        exit;
    }
}

require_once $templateFile;