<?php
// C15 15.2 — QuickSite project RENDERER, relocated to public/p/ so it owns ONLY the
// /p/<projectId>/ namespace (public/p/.htaccess funnels /p/ here). The web ROOT is now
// free — no FallbackResource — so a user's own hand-made site can live there and
// QuickSite never squats the domain root.
//
// C9 surface B — the `/p/<projectId>/` gate. The DISPATCH lives in init.php, because this
// file cannot name secure/ itself: both that folder's NAME and this file's depth below the
// web root move (setup renames the folders, and a URL space nests the install), and nothing
// here knows either yet. init.php does — so this entry point does what /admin/ and
// /management/ do: require init.php by a sibling-relative path, which moves WITH the space
// because init.php moves with it, then let SECURE_FOLDER_PATH resolve everything after.
//
// The marker is an ENTRY-POINT fact, deliberately not a URL one: a project-scoped command is
// '/management/p/<projectId>/<command>' and carries a '/p/' segment of its own, so gating on
// URL shape alone would treat every project-scoped API call as a project view.
define('QS_SURFACE_B_ENTRY', true);
require_once __DIR__ . '/../init.php';

// C15 15.3 — no project, no render. A request that reaches this file without resolving to
// a project named a `/p/` path with no id after the marker. There is no served project left
// to fall back on (that fallback WAS the served-project privilege), and every constant
// below this line is project-scoped, so answer and stop.
if (!defined('QS_SURFACE_B_PROJECT')) {
    qs_sb_deny(404, 'This site is not available.');
}

// C9 surface B — bind the /p/ project (PROJECT_PATH + PUBLIC_CONTENT_PATH together), then
// finish (serve a static asset, or set up the HTML render).
qs_load_project_context(QS_SURFACE_B_PROJECT, true);
qs_surface_b_finish();

// C15 15.4 — tier-2 render bootstrap. Required ONLY by this renderer (tier 1 = init.php's
// install-wide constants, shared by every entry point). Resolves the PUBLIC BASE once
// (QS_PUBLIC_BASE_URL env → request-derived) and defines QS_PUBLIC_BASE (root-relative
// form every in-page URL composes against, R1) + QS_PUBLIC_BASE_ABS (sitemap/spec form).
require_once SECURE_FOLDER_PATH . '/src/functions/renderBootstrap.php';

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
        'baseUrl' => QS_PUBLIC_BASE, // C15 15.4 (R1) — root-relative render base
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
    <link rel="stylesheet" href="<?= QS_PUBLIC_BASE ?>style/style.css?v=<?= $cssVersion ?>">
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
    <?php // Storage-namespace handoff (see PageManagement::render) — the component
          // preview shares the project's origin, so it must share its key prefix. ?>
    <script>window.QS_PROJECT=<?= json_encode(defined('PROJECT_NAME') ? PROJECT_NAME : 'default', JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= QS_PUBLIC_BASE ?>scripts/qs.js"></script>
</body>
</html><?php
    exit;
}

// --- Check for URL aliases BEFORE TrimParameters processes routes ---
// A production build's front controller applies the same aliases through the
// same function, so a redirect that works here works there.
require_once SECURE_FOLDER_PATH . '/src/functions/aliasRouting.php';
qs_apply_alias_routing();

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();

// --- Route Resolution with Nested Routes Support ---
$route = $trimParameters->route();         // e.g., ['guides', 'installation']
$routePath = $trimParameters->routePath(); // e.g., 'guides/installation'
$routeFound = $trimParameters->routeFound();

// Handle 404 — a route that does not exist inside this project. Reaching here means the
// project resolved AND the visitor passed surface B's visibility/membership gate, so the
// project's own styled error page is the right thing to show. (A REFUSED /p/<id>/ never
// gets this far: surface B answers a generic engine page and exits — C15 15.3.)
if (!$routeFound || $routePath === '404') {
    http_response_code(404);
    $candidates = [
        PROJECT_PATH . '/templates/pages/404/404.php',
        PROJECT_PATH . '/templates/pages/404.php', // flat fallback (migration)
    ];
    $templateFile = null;
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            $templateFile = $candidate;
            break;
        }
    }
    if ($templateFile !== null) {
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
        // beta.10 C12 12.5. Same defect class as F-C12-4, on the PUBLIC surface:
        // the str_replace here misses whenever PROJECT_PATH's DIRECTORY_SEPARATOR
        // disagrees with the "/" these paths are built with, and an anonymous
        // visitor to a site with a missing template then gets the absolute server
        // path. Sibling of the OAuth error 12.3 fixed a few hundred lines below;
        // this one uses the shared renderer because it is not an ApiResponse.
        require_once SECURE_FOLDER_PATH . '/src/functions/publicPaths.php';
        echo '<h1>404 - Page Not Found</h1>';
        echo '<p>Template file not found: ' . htmlspecialchars(qs_scrub_path_string($templateFile)) . '</p>';
    }
    exit;
}

// ============================================================================
// SERVER-SIDE DATA RESOLVER (beta.8 A2)
// ============================================================================


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
// The resolver LIFECYCLE — firing a route's resolvers, publishing what they
// returned, and routing a failure to the right page. Shared with a built
// site's front controller, which runs the identical sequence.
require_once SECURE_FOLDER_PATH . '/src/functions/resolverRuntime.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthRuntime.php';
// Beta.8 A2 Slice 7.5.A — array-aware accessor. Routes with no
// resolver return []; single-resolver routes return a 1-element
// array; multi-resolver routes return N elements. DataResolver's
// resolveMany handles all three cases identically via serverFetchMulti.
$__resolverConfigs = getResolversForRoute($routePath);

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
    $__resolverConfigs = [];
}

if (!empty($__resolverConfigs)) {
    // ── sign-in routes ──
    // A resolver whose KIND is an OAuth step replaces the render entirely with
    // a redirect (and, on the callback, a session cookie). Shared with a built
    // site's front controller, which runs the identical sequence — this is the
    // AUTHOR'S site's own sign-in, and it needs nothing from QuickSite's
    // management API or admin panel.
    qs_run_oauth_route($__resolverConfigs, $routePath, $trimParameters->routeParams());
    // ── the data-resolver path (kind=data) ──
    // Fires the route's resolvers, publishes the values the page's
    // {{resolved:NAME}} placeholders read, and — when the data did not arrive —
    // answers with the project's own 404 / 500 and exits rather than rendering
    // a page whose content is missing. A built site runs this same function.
    qs_resolve_route_data($__resolverConfigs, $routePath, $trimParameters->routeParams());
}

require_once $templateFile;