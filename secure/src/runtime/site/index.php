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
 * WHAT IT DOES, in order: bind the constants → bind the project → resolve
 * aliases → route → pick the compiled page (or the 404) → run it. The compiled
 * page requires Page.php itself and renders. There is no JSON parsing and no
 * structure walking at request time; that is the whole point of a build.
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
// 0. Parameters
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
// 1. Where everything is
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
// 2. The project
// ---------------------------------------------------------------------------
// A build is ONE project, and its files sit at the secure root rather than
// under projects/<id>/ — so PROJECT_PATH is SECURE_FOLDER_PATH. PROJECT_NAME
// is the real project id, because it names this site's browser-storage
// namespace (`qsp_<PROJECT_NAME>_<key>`) and its theme key: a built site that
// claimed a different identity from the same project served under /p/<id>/
// would read back none of the visitor's stored state.
define('PROJECT_PATH', SECURE_FOLDER_PATH);
define('PROJECT_NAME', $qsSite['project']);

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
// 3. The base every in-page URL composes against
// ---------------------------------------------------------------------------
// ROOT-RELATIVE, never absolute: a built site is served from wherever the
// deployer points a document root, and a root-relative base survives a domain
// move, a scheme change and a reverse proxy without the site knowing. The URL
// space is the whole of it — mounting the site under a path IS the `space`
// parameter, so there is nothing else to discover at request time.
define('QS_PUBLIC_BASE', '/' . (PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : ''));
define('BASE_URL', QS_PUBLIC_BASE);

// ---------------------------------------------------------------------------
// 4. Aliases, then routing
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
// 5. Pick the compiled page
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

require $templateFile;
