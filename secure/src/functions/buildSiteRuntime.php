<?php
/**
 * ============================================================================
 * The entry point a BUILT site runs on — emitters and the servability gate
 * ============================================================================
 *
 * A build is precompiled pages PLUS the runtime needed to serve them. This
 * file owns the "plus": the front controller, the parameters it reads, the web
 * server configuration that funnels requests into it, and the check that
 * refuses to call a build finished when it could not serve a request.
 *
 * ⚠ THE FRONT CONTROLLER IS COPIED, NOT GENERATED. It is a real, checked-in
 * file (`src/runtime/site/index.php`) that gets linted, grepped and opened like
 * any other. Everything that varies between builds is DATA — one small
 * generated file the controller reads — so no PHP source is ever rewritten by
 * pattern matching. A regex-patched entry point is invisible until somebody
 * runs a build and serves it, which is exactly how a build could report success
 * for a long time while emitting nothing that could answer a request.
 *
 * The `.htaccess` and nginx bodies below ARE generated, and that is a different
 * thing: they are web-server configuration, not program source, and the one
 * value they carry (the URL space) cannot be read from a file at request time
 * because the server consults them before any code runs.
 */

if (!function_exists('qs_site_runtime_source')) {
    /** The checked-in front controller the build copies into every site. */
    function qs_site_runtime_source(): string
    {
        return SECURE_FOLDER_PATH . '/src/runtime/site/index.php';
    }
}

if (!function_exists('qs_site_config_php')) {
    /**
     * The generated `qs-site.php` — the four values that make one build
     * different from another.
     *
     * PHP rather than JSON, deliberately: this file sits in the document root,
     * so a data file would be fetchable and would disclose the secure folder's
     * name — the single thing the folder renaming exists to keep out of reach
     * of anyone comparing a deployment against the open-source layout. PHP is
     * executed and never served as text, and the guard makes a direct request
     * answer 404 instead of a blank 200.
     *
     * Values go through var_export. All four are already validated before a
     * build starts (the project id by the F1 name check, the folder names and
     * the space by the relative-path check), but a generator is the wrong place
     * to rely on a caller's validation still holding.
     *
     * @param string $project The real project id — becomes PROJECT_NAME.
     * @param string $public  Public folder name/path.
     * @param string $secure  Secure folder name/path.
     * @param string $space   URL space, or '' for none.
     */
    function qs_site_config_php(string $project, string $public, string $secure, string $space): string
    {
        $values = [
            'project' => $project,
            'public'  => $public,
            'secure'  => $secure,
            'space'   => $space,
        ];

        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= "    '" . $key . "' => " . var_export($value, true) . ",\n";
        }

        return <<<PHP
<?php
/**
 * QuickSite — this site's build parameters. GENERATED; edit the site in
 * QuickSite and build again rather than editing this file.
 *
 *   project  the project id, which names this site's browser-storage
 *            namespace (qsp_<project>_<key>) and its theme key
 *   public   the folder that IS the document root
 *   secure   the sibling folder holding the engine, pages and translations
 *   space    the URL path this site is mounted under, or '' for the root
 *
 * index.php reads this. A direct request for it is a 404: it names the secure
 * folder, and that name is what keeps the engine out of reach of anyone
 * matching a deployment against the open-source layout.
 */

if (!defined('QS_SITE_BOOT')) {
    http_response_code(404);
    exit;
}

return [
{$lines}];

PHP;
    }
}

if (!function_exists('qs_site_htaccess')) {
    /**
     * The `.htaccess` that sits WITH the site's content and funnels every
     * request that is not a real file into the front controller.
     *
     * @param string $space URL space, or '' when the site is at the root.
     */
    function qs_site_htaccess(string $space): string
    {
        $space    = trim($space, '/');
        $fallback = '/' . ($space !== '' ? $space . '/' : '') . 'index.php';

        return <<<HTACCESS
# QuickSite production build.
#
# Unlike a QuickSite INSTALL — whose web root is deliberately free so the engine
# never squats the domain — a built site IS the whole site, so every request
# that is not a real file funnels into its front controller.
RewriteEngine On
FallbackResource {$fallback}

# Never expose an auto-generated directory listing. The build ships no per-folder
# index guards (they are refused by the publish allowlist, correctly — they are
# source, not content), so this is what keeps assets/ and style/ from being
# browsable — including on a vhost that turns indexes ON.
Options -Indexes

# Security headers. A built site is a public website, so these are its own
# defaults rather than the install's: nothing here is served into an admin
# frame, and a site that wants to be embedded can relax X-Frame-Options.
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

HTACCESS;
    }
}

if (!function_exists('qs_site_root_htaccess')) {
    /**
     * The `.htaccess` for the DOCUMENT ROOT of a site mounted under a URL
     * space — where the site's content lives one or more levels down and the
     * root itself holds nothing this build put there.
     *
     * Without it a spaced build ships no root configuration at all: the funnel
     * and the headers cover `/<space>/…` and the root gets neither, so a bare
     * `/` answers with a listing of the deployment's folders.
     *
     * It deliberately does NOT funnel. The root is not this site's; the site is
     * at `/<space>/`, and the deployer may well be putting something else there.
     */
    function qs_site_root_htaccess(): string
    {
        return <<<HTACCESS
# QuickSite production build — DOCUMENT ROOT of a site mounted under a URL space.
#
# The site itself is not here; it is in the space subdirectory, which carries its
# own .htaccess with the request funnel. This file only makes sure the root is
# not browsable, so the deployment's folder names are not handed out to anyone
# who asks for "/". Nothing is funnelled: the root is free for whatever else the
# deployer serves from this document root.
Options -Indexes

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

HTACCESS;
    }
}

if (!function_exists('qs_site_nginx_config')) {
    /**
     * The nginx equivalent of the funnel, for deployers whose server does not
     * read `.htaccess` at all.
     *
     * A build used to ship the INSTALL's nginx config — locations for
     * `/admin/`, `/management/`, `/admin/api/` and `/p/`, none of which exist
     * in a build, plus instructions to define a named location "or every
     * project URL answers 500". A deployer must not be handed configuration for
     * namespaces that are not there.
     *
     * @param string $public Public folder name/path — the document root.
     * @param string $secure Secure folder name/path — outside it.
     * @param string $space  URL space, or '' when the site is at the root.
     */
    function qs_site_nginx_config(string $public, string $secure, string $space): string
    {
        $space  = trim($space, '/');
        $prefix = $space !== '' ? '/' . $space : '';
        $entry  = $prefix . '/index.php';
        $date   = date('Y-m-d H:i:s');

        $rootNote = $space !== ''
            ? "# This site is mounted under {$prefix}/. The document root itself is left\n"
            . "# alone — only {$prefix}/ is funnelled — so anything else you serve from\n"
            . "# {$public}/ keeps working.\n"
            : "# This site is served from the root of {$public}/.\n";

        return <<<NGINX
# ==========================================================
# QuickSite production build — nginx configuration
# ==========================================================
# Generated on {$date}
#
# Apache users can ignore this file: the build ships .htaccess files that do
# the same job. nginx does not read .htaccess, so this is the equivalent.
#
# Add this inside your server { } block, then:  nginx -t && nginx -s reload
#
# Document root:  {$public}/
# Engine + pages: {$secure}/     <-- MUST stay outside the document root
{$rootNote}#
# There is no management API and no admin panel in a build, so there is nothing
# here for them.
# ==========================================================

location {$prefix}/ {
    # Real files (style/, assets/, scripts/, sitemap.txt) are served directly;
    # everything else is a page and goes to the front controller.
    try_files \$uri \$uri/ {$entry}\$is_args\$args;

    # No directory listings. The build ships no per-folder index guards.
    autoindex off;

    # The .htaccess equivalents, for parity between the two server configs.
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}

NGINX;
    }
}

if (!function_exists('qs_site_verify_servable')) {
    /**
     * Can this build answer a request? Asked of the finished tree, before the
     * command is allowed to report success.
     *
     * This exists because "the build completed" and "the build can serve" were
     * never the same claim, and only the first was ever checked: a build could
     * write its pages, its styles and its assets, answer 201, and contain no
     * entry point of any kind — the .htaccess funnelling every request to a
     * file that was not there.
     *
     * What it asserts, in the order a request would need them:
     *   1. the funnel target exists — the file .htaccess names
     *   2. its parameters exist, parse, and name the folders this build made
     *   3. the secure folder holds config.php and routes.php
     *   4. the runtime the compiled pages require is present
     *   5. the menu/footer those pages pull in are present
     *   6. every compiled route has its page at the exact path the front
     *      controller will compute — asked through the same helper the
     *      compiler used to write it, so reader and writer cannot drift
     *   7. the 404 page exists, because a wrong URL is a request too
     *
     * It is a STRUCTURAL gate: it proves the request path is complete, not that
     * a page renders. Rendering is proven by serving the build.
     *
     * @param string   $buildFullPath Root of the finished build.
     * @param string   $public        Public folder name/path.
     * @param string   $secure        Secure folder name/path.
     * @param string   $space         URL space, or ''.
     * @param string[] $compiledPages Route paths written by the compiler.
     * @return string[] Human-readable problems; empty means servable.
     */
    function qs_site_verify_servable(
        string $buildFullPath,
        string $public,
        string $secure,
        string $space,
        array $compiledPages
    ): array {
        $problems = [];

        $toNative      = static fn(string $p): string => str_replace('/', DIRECTORY_SEPARATOR, $p);
        $publicRoot    = $buildFullPath . DIRECTORY_SEPARATOR . $toNative(trim($public, '/'));
        $contentPath   = trim($space, '/') !== ''
            ? $publicRoot . DIRECTORY_SEPARATOR . $toNative(trim($space, '/'))
            : $publicRoot;
        $securePath    = $buildFullPath . DIRECTORY_SEPARATOR . $toNative(trim($secure, '/'));

        // 1 + 2 — the funnel target and the parameters it reads.
        $entryPoint = $contentPath . DIRECTORY_SEPARATOR . 'index.php';
        $siteConfig = $contentPath . DIRECTORY_SEPARATOR . 'qs-site.php';

        if (!is_file($entryPoint) || filesize($entryPoint) === 0) {
            $problems[] = 'the entry point is missing: index.php was not written to the public folder';
        }
        if (!is_file($contentPath . DIRECTORY_SEPARATOR . '.htaccess')) {
            $problems[] = 'the request funnel is missing: no .htaccess beside the entry point';
        }
        if (!is_file($siteConfig)) {
            $problems[] = 'the site parameters are missing: qs-site.php was not written';
        } else {
            // Read it the way the entry point does. The guard constant is
            // defined here so the file answers instead of 404ing.
            if (!defined('QS_SITE_BOOT')) {
                define('QS_SITE_BOOT', true);
            }
            $params = require $siteConfig;
            if (!is_array($params)) {
                $problems[] = 'qs-site.php does not return the site parameters';
            } else {
                foreach (['project' => '', 'public' => $public, 'secure' => $secure, 'space' => $space] as $key => $expected) {
                    if (!isset($params[$key]) || !is_string($params[$key])) {
                        $problems[] = "qs-site.php is missing the '{$key}' parameter";
                    } elseif ($expected !== '' && $params[$key] !== $expected) {
                        $problems[] = "qs-site.php says {$key}='{$params[$key]}' but the build used '{$expected}'";
                    }
                }
                if (isset($params['project']) && $params['project'] === '') {
                    $problems[] = "qs-site.php carries no project id, so the built site would store visitor state under a shared namespace";
                }
            }
        }

        // 3 — the project's own data.
        foreach (['config.php', 'routes.php'] as $projectFile) {
            if (!is_file($securePath . DIRECTORY_SEPARATOR . $projectFile)) {
                $problems[] = "the secure folder has no {$projectFile}";
            }
        }

        // 4 — the runtime every compiled page requires.
        $runtime = [
            'src/classes/Page.php',
            'src/classes/Translator.php',
            'src/classes/TrimParameters.php',
            'src/classes/RegexPatterns.php',
            'src/functions/String.php',
            'src/functions/projectLanguage.php',
            'src/functions/routeHelpers.php',
            'src/functions/aliasRouting.php',
        ];
        foreach ($runtime as $relative) {
            if (!is_file($securePath . DIRECTORY_SEPARATOR . $toNative($relative))) {
                $problems[] = "the runtime is incomplete: {$relative} is missing";
            }
        }

        // 5 — what Page.php pulls in on every render.
        foreach (['menu.php', 'footer.php'] as $partial) {
            if (!is_file($securePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $partial)) {
                $problems[] = "templates/{$partial} was not compiled";
            }
        }

        // 6 + 7 — every route resolves to a page, and a wrong URL has one too.
        require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';
        $pagesRoot = $securePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages';
        foreach ($compiledPages as $routePath) {
            $segments = array_values(array_filter(explode('/', (string) $routePath), static fn($s) => $s !== ''));
            if ($segments === []) {
                continue;
            }
            $fsSegments = array_map('paramRouteSegmentToFs', $segments);
            $expected   = $pagesRoot . DIRECTORY_SEPARATOR
                        . implode(DIRECTORY_SEPARATOR, $fsSegments) . DIRECTORY_SEPARATOR
                        . end($fsSegments) . '.php';
            if (!is_file($expected)) {
                $problems[] = "the route '{$routePath}' has no compiled page where routing will look for it";
            }
        }
        if (!is_file($pagesRoot . DIRECTORY_SEPARATOR . '404' . DIRECTORY_SEPARATOR . '404.php')) {
            $problems[] = 'the 404 page was not compiled, so an unknown URL has nothing to answer with';
        }

        return $problems;
    }
}
