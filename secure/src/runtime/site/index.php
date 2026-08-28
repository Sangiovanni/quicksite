<?php
/**
 * ============================================================================
 * QuickSite — front controller of a BUILT site
 * ============================================================================
 *
 * This file is SHIPPED VERBATIM by the `build` command. It is copied, never
 * rewritten: everything that varies between builds — the project id, the
 * public/secure folder names, the URL space — is DATA, read from `qs-site.php`
 * beside it, which the build generates. Nothing here is patched by a regex, so
 * this file can be linted, grepped, opened and reasoned about on its own.
 *
 * WHY IT LIVES IN `src/runtime/`. That directory holds what ships to a SITE
 * rather than what runs the engine — `qs.js` is its browser half, this is its
 * server half. It is deliberately not in `src/classes/` or `src/functions/`
 * (engine code, only partly carried into a build) and not under
 * `management/command/` (a shipped artifact is not a command's private detail).
 *
 * HOW A BUILT SITE DIFFERS FROM THE INSTALL. The install's web root is
 * deliberately FREE — no FallbackResource, no index — so a user's own site can
 * live at the domain root and QuickSite never squats it; the engine lives in
 * namespaced directories (`/admin/`, `/management/`, `/p/`), each with its own
 * .htaccess. A built site is the opposite case: it IS the whole site at its
 * root, and every request must funnel into it. So there was never an entry
 * point in the install to copy — the two answer opposite questions.
 *
 *   your-server/
 *   ├── <public>/[<space>/]   <- document root; THIS FILE sits here
 *   │   ├── index.php  qs-site.php  .htaccess
 *   │   └── style/  assets/  scripts/  sitemap.txt
 *   └── <secure>/             <- sibling, NOT web-accessible
 *       ├── config.php  routes.php  data/aliases.json
 *       ├── src/classes/  src/functions/
 *       ├── templates/pages/<route>/<route>.php   (pre-compiled)
 *       └── translate/
 *
 * WHAT IT DOES, in order: read the environment → bind the constants → install
 * fatal hygiene → bind the project → resolve aliases → route → pick the
 * compiled page (or the 404) → run it. The compiled page requires Page.php
 * itself and renders. There is no JSON parsing and no structure walking at
 * request time; that is the whole point of a build.
 */

/**
 * A built site that cannot boot. Never returns.
 *
 * The visitor gets a short page with no filesystem detail in it — this surface
 * is public, and an anonymous request for a half-uploaded site must not answer
 * with the server's directory layout. The path goes to the error log, which is
 * where the person who can fix it is already looking.
 *
 * @param string $reason  What is wrong, in deployer vocabulary.
 * @param string $absPath The path involved — logged, never printed.
 */
function qs_site_fail(string $reason, string $absPath): void
{
    error_log('QuickSite build: ' . $reason . ' (expected at ' . $absPath . ')');

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<title>Site unavailable</title></head><body>'
       . '<h1>This site is not available</h1>'
       . '<p>It is not correctly deployed: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '.</p>'
       . '<p>If this is your site, check the deployment steps in <code>README.txt</code>'
       . ' and your server error log.</p>'
       . '</body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// 0. Environment — FIRST, because everything below can fail
// ---------------------------------------------------------------------------
// PRODUCTION unless the DEPLOYMENT says otherwise, and it says so through the
// server rather than through anything in the build — the environment is a
// property of where a site is running, not of the artifact that was shipped.
//
// This is what the outbound-URL policy reads to decide whether a resolver may
// call an internal address. In production it may not, which is the SSRF guard
// and is the right default for a public site. It is also what decides whether
// a fatal may print anything about the server. A deployer running the build
// locally against a LAN or loopback API opts in per-vhost:
//
//   Apache:  SetEnv QS_ENVIRONMENT development
//   nginx:   fastcgi_param QS_ENVIRONMENT development;
//
// Only the exact string 'development' counts; anything else, including the
// variable being absent, is production. Without this a built site had no way to
// declare its environment at all, so a deliberate local test was impossible.
//
// ⚠ IT RUNS BEFORE EVERYTHING ELSE, and that ordering is load-bearing twice
// over. It reads nothing but $_SERVER, so it CAN run first — and two things
// below depend on it having done so:
//
//   - the display_errors suppression immediately after it covers the one window
//     the fatal handler cannot: a fatal raised before, or inside, the require
//     that locates the secure folder, which is where the handler itself lives.
//     Same reasoning as the suppression inside qs_register_fatal_handler()
//     (beta.10 C13): where the handler cannot repair the response, at least
//     nothing about the filesystem is printed into it.
//   - qs_is_development() memoises its answer on first call and prefers an
//     ENVIRONMENT constant over any config file. Registering the handler while
//     ENVIRONMENT was still undefined would memoise "production" from a config
//     file a build does not ship, and a development deployment would then never
//     see a fatal's detail no matter what the vhost said.
$qsEnv = $_SERVER['QS_ENVIRONMENT'] ?? $_SERVER['REDIRECT_QS_ENVIRONMENT'] ?? getenv('QS_ENVIRONMENT');
define('ENVIRONMENT', $qsEnv === 'development' ? 'development' : 'production');

if (ENVIRONMENT !== 'development') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// ---------------------------------------------------------------------------
// 1. Parameters
// ---------------------------------------------------------------------------
// qs-site.php refuses to answer unless it is being INCLUDED by this file, so a
// direct request for it is a 404 rather than a blank 200. It is PHP and not
// JSON on purpose: it sits in the document root, so a data file would be
// fetchable and would hand out the secure folder's name — the one thing the
// renaming exists to keep out of reach. PHP is executed, never served.
define('QS_SITE_BOOT', true);

$qsSiteConfigFile = __DIR__ . DIRECTORY_SEPARATOR . 'qs-site.php';
$qsSite = is_file($qsSiteConfigFile) ? require $qsSiteConfigFile : null;

if (!is_array($qsSite)
    || !isset($qsSite['project'], $qsSite['public'], $qsSite['secure'])
    || !is_string($qsSite['project']) || $qsSite['project'] === ''
    || !is_string($qsSite['public'])  || $qsSite['public']  === ''
    || !is_string($qsSite['secure'])  || $qsSite['secure']  === ''
    || !is_string($qsSite['space'] ?? '')
) {
    qs_site_fail('qs-site.php is missing or malformed', $qsSiteConfigFile);
}

// ---------------------------------------------------------------------------
// 2. Where everything is
// ---------------------------------------------------------------------------
// Derived from __DIR__, not from DOCUMENT_ROOT. The install has to read
// DOCUMENT_ROOT because it cannot know its own depth below the web root; a
// build knows exactly, because it created the layout. So the site keeps
// working when the document root is configured oddly, when it is served by
// PHP's built-in server, or when the whole tree is moved.
//
// This file sits at  <SERVER_ROOT>/<public>/<space>/index.php, so climbing out
// of it means one level per segment of <public> plus one per segment of
// <space>. <public> is never empty, so the count is always at least 1.
$qsPublicName  = trim(str_replace('\\', '/', $qsSite['public']), '/');
$qsSecureName  = trim(str_replace('\\', '/', $qsSite['secure']), '/');
$qsPublicSpace = trim(str_replace('\\', '/', (string) ($qsSite['space'] ?? '')), '/');

$qsSegments = static function (string $path): int {
    return $path === '' ? 0 : count(array_filter(explode('/', $path), static fn($p) => $p !== ''));
};
$qsLevelsUp = $qsSegments($qsPublicName) + $qsSegments($qsPublicSpace);
if ($qsLevelsUp < 1) {
    qs_site_fail('qs-site.php names an empty public folder', $qsSiteConfigFile);
}

$qsToNative = static fn(string $path): string => str_replace('/', DIRECTORY_SEPARATOR, $path);

define('PUBLIC_FOLDER_NAME',  $qsPublicName);
define('SECURE_FOLDER_NAME',  $qsSecureName);
define('PUBLIC_FOLDER_SPACE', $qsPublicSpace);
define('SERVER_ROOT',         dirname(__DIR__, $qsLevelsUp));
define('PUBLIC_FOLDER_ROOT',  SERVER_ROOT . DIRECTORY_SEPARATOR . $qsToNative($qsPublicName));
// The directory holding style/, assets/ and scripts/ — which is exactly the
// directory this file is in, space included.
define('PUBLIC_CONTENT_PATH', __DIR__);
define('SECURE_FOLDER_PATH',  SERVER_ROOT . DIRECTORY_SEPARATOR . $qsToNative($qsSecureName));

if (!is_dir(SECURE_FOLDER_PATH)) {
    qs_site_fail('the secure folder is missing', SECURE_FOLDER_PATH);
}

// ---------------------------------------------------------------------------
// 3. Fatal hygiene
// ---------------------------------------------------------------------------
// A PHP fatal happens outside every `try` an application can write, so without
// this the interpreter's own message — class, file, ABSOLUTE PATH and line —
// goes straight into the visitor's page, under whatever status was already set.
// Nothing had failed yet, so that status is 200: a public website answering
// "OK" with the server's directory layout in the body. The install closed this
// for /management, /admin/api and /admin; a built site is the fourth surface,
// and the only one whose reader is the general public.
//
// Registered HERE — the first line after SECURE_FOLDER_PATH resolves — because
// this is the earliest point at which the handler's own file can be located.
// Everything above it is covered instead by qs_site_fail() (which logs the path
// and prints none) and by the display_errors suppression in section 0.
//
// The output buffer is what makes the handler able to REPAIR a response rather
// than only stop leaking into one. A compiled page echoes as it renders, so a
// fatal halfway through would otherwise arrive after headers were sent — and
// the handler bails once that is true, because status and content type are
// already on the wire. Buffering keeps them repairable, so a mid-render fatal
// answers 500 with the error page instead of 200 with half a page. On the
// normal path the buffer is simply flushed at the end of the request: shutdown
// callbacks run BEFORE PHP's final flush, and this one returns immediately when
// the request did not die.
require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php';
qs_register_fatal_handler(QS_FATAL_SHAPE_SITE);
ob_start();

// The site's own response headers, sent from the one component both servers
// run. The .htaccess and the nginx snippet each carry the same three, and they
// still need to — only the web server can put a header on a stylesheet — but
// neither of them covers a PAGE reliably:
//
//   Apache  the .htaccess block is wrapped in <IfModule mod_headers.c>, and a
//           server without that module skips it silently.
//   nginx   a page leaves through the deployer's PHP handler, which is a
//           different location from the one the snippet configures, and
//           add_header does not follow an internal redirect.
//
// So the claim "the .htaccess equivalents, for parity" was true of neither
// server for the thing a visitor actually loads. Here it is true of both, and
// of PHP's built-in server, and of anything else that can run this file.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ---------------------------------------------------------------------------
// 4. The project
// ---------------------------------------------------------------------------
// A build is ONE project, and its files sit at the secure root rather than
// under projects/<id>/ — so PROJECT_PATH is SECURE_FOLDER_PATH. PROJECT_NAME
// is the real project id, because it names this site's browser-storage
// namespace (`qsp_<PROJECT_NAME>_<key>`) and its theme key: a built site that
// claimed a different identity from the same project served under /p/<id>/
// would read back none of the visitor's stored state.
define('PROJECT_PATH', SECURE_FOLDER_PATH);
define('PROJECT_NAME', $qsSite['project']);

// The Content-Security-Policy, from the same writer `/p/<projectId>/` uses.
//
// ⚠ IT IS SENT HERE RATHER THAN WITH THE THREE HEADERS ABOVE because it is the
// only one of the four that is a fact about THIS PROJECT: `connect-src` is
// derived from the project's own API registry, so it needs PROJECT_PATH bound.
// Output is buffered from ob_start() above, so nothing has flushed yet.
//
// A built site used to send NO policy at all — no object-src, no base-uri, no
// frame-ancestors and no script-src restriction — which made the deployed
// artifact strictly less protected than its own preview. That survived a slice
// about preview/build parity because the harness compared rendered DOM and
// never compared response headers.
require_once SECURE_FOLDER_PATH . '/src/functions/contentSecurityPolicy.php';
qs_send_content_security_policy(PROJECT_PATH);

define('CONFIG_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'config.php');
define('ROUTES_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'routes.php');
if (!is_file(CONFIG_PATH)) {
    qs_site_fail('config.php is missing', CONFIG_PATH);
}
if (!is_file(ROUTES_PATH)) {
    qs_site_fail('routes.php is missing', ROUTES_PATH);
}
define('CONFIG', require CONFIG_PATH);
define('ROUTES', require ROUTES_PATH);

define('MULTILINGUAL_SUPPORT',      CONFIG['MULTILINGUAL_SUPPORT'] ?? false);
define('THEME_MODE_ENABLED',        CONFIG['THEME_MODE_ENABLED'] ?? false);
define('THEME_DEFAULT',             CONFIG['THEME_DEFAULT'] ?? 'light');
define('THEME_USER_TOGGLE_ENABLED', CONFIG['THEME_USER_TOGGLE_ENABLED'] ?? false);

// ---------------------------------------------------------------------------
// 5. The base every in-page URL composes against
// ---------------------------------------------------------------------------
// ROOT-RELATIVE, never absolute: a built site is served from wherever the
// deployer points a document root, and a root-relative base survives a domain
// move, a scheme change and a reverse proxy without the site knowing. The URL
// space is the whole of it — mounting the site under a path IS the `space`
// parameter, so there is nothing else to discover at request time.
define('QS_PUBLIC_BASE', '/' . (PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : ''));
define('BASE_URL', QS_PUBLIC_BASE);

// ---------------------------------------------------------------------------
// 6. Aliases, then routing
// ---------------------------------------------------------------------------
// Aliases run BEFORE the router, because an internal alias works by rewriting
// REQUEST_URI. Same function the /p/<id>/ renderer calls, so an alias behaves
// identically in preview and in production.
require_once SECURE_FOLDER_PATH . '/src/functions/aliasRouting.php';
qs_apply_alias_routing();

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();

$route      = $trimParameters->route();
$routePath  = $trimParameters->routePath();
$routeFound = $trimParameters->routeFound();

// ---------------------------------------------------------------------------
// 7. Pick the compiled page
// ---------------------------------------------------------------------------
// The build writes every page at  templates/pages/<route>/<leaf>.php  — one
// convention, no fallbacks, because the build wrote the tree itself and there
// is no legacy layout to tolerate.
//
// Param segments are ':name' in routes.php (it doubles as the URL pattern) and
// '__name' on disk, because NTFS reserves ':' in a path component. The
// translation is asked of the same canonical helper the compiler used to WRITE
// these paths, so the reader and the writer cannot drift apart.
require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';

$templateFile = null;
if ($routeFound && $routePath !== '404' && !empty($route)) {
    $fsRoute      = array_map('paramRouteSegmentToFs', $route);
    $templateFile = PROJECT_PATH . '/templates/pages/' . implode('/', $fsRoute)
                  . '/' . end($fsRoute) . '.php';
}

if ($templateFile === null || !is_file($templateFile)) {
    // The project's own styled 404, which the build always compiles.
    http_response_code(404);
    $notFound = PROJECT_PATH . '/templates/pages/404/404.php';
    if (is_file($notFound)) {
        require $notFound;
    } else {
        echo '<h1>404 — Page Not Found</h1>';
    }
    exit;
}

// ---------------------------------------------------------------------------
// 8. Server-side data
// ---------------------------------------------------------------------------
// A resolver fetches this route's data over HTTP before the page renders, so
// the values are in the HTML at first paint. That is request-time work by
// definition — precompilation cannot make an upstream call in advance — which
// is why a built site runs the same lifecycle the live renderer runs, from the
// same file. Routes without a resolver pay one file-existence check.
//
// ⚠ The lifecycle EXITS on an unrecovered failure, answering with this
// project's own 404 or 500. That is deliberate: a page whose data did not
// arrive must not render as though it did.
//
// ⚠ No editor emulation here, and that is a security boundary rather than an
// omission. The visual editor previews a resolver-backed page by passing mock
// values in the query string; a built site is a public website, and honouring
// that would let any visitor dictate what the page says.
require_once SECURE_FOLDER_PATH . '/src/functions/resolverRuntime.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthRuntime.php';
$qsResolvers = qs_resolvers_for_route($routePath);

// A sign-in route is a resolver whose KIND is an OAuth step rather than a data
// fetch. It replaces the render entirely with a redirect (and, on the callback,
// a session cookie), so it has to be handled before anything renders.
//
// A built site can do this: the flow needs PHP sessions, an outbound HTTPS call
// and a route to come back to, and it has all three. What it does NOT have is
// QuickSite's management API or admin panel — and the flow never wanted them.
// This is the AUTHOR'S site's own sign-in, not QuickSite's.
qs_run_oauth_route($qsResolvers, $routePath, $trimParameters->routeParams());

qs_resolve_route_data($qsResolvers, $routePath, $trimParameters->routeParams());

require $templateFile;
