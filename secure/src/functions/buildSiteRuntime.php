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
     * ⚠ EVERY BEHAVIOURAL CLAIM BELOW WAS MEASURED on nginx 1.24.0 serving a
     * real build, not reasoned about. Three of them contradicted what this file
     * shipped when it was written from first principles:
     *
     *   - `$uri/` in the try_files list made the HOME PAGE answer 403. A
     *     directory request resolves the directory, finds no index inside it,
     *     and `autoindex off` refuses — and "/" is a directory request. Apache's
     *     FallbackResource does not resolve a directory that way, which is why
     *     the same build served correctly there. It is the same trap the
     *     install's own generator documents for `/admin/`, reached by a
     *     different door. Removing `$uri/` gives byte-for-byte parity with
     *     Apache on every URL, directories included.
     *   - `add_header` inside this location reached ONE of the five response
     *     kinds a built site produces. Pages leave through the vhost's PHP
     *     handler, which is a different location, and `add_header` does not
     *     follow an internal redirect; stylesheets and images are usually
     *     claimed by a vhost's own static-asset regex, which outranks a plain
     *     prefix. The front controller now sends the three headers itself, so
     *     every PAGE carries them on any server; what stays here covers the
     *     static files this location actually serves.
     *   - `nginx -t` does not catch the mistake that actually happens. It
     *     parses; it does not resolve. So the file tells the deployer to fetch
     *     a page, which is the only check that discriminates.
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
        $home   = $prefix . '/';
        $notFnd = $prefix . '/nope';
        $date   = date('Y-m-d H:i:s');

        // The location prefix THIS file declares. It is also the prefix an
        // installation's own generated file declares for a site deployed into
        // its document root — which is why including both is a duplicate.
        $rootShown = $prefix . '/';

        // The two example URLs are shown one above the other with their arrows
        // lined up, so pad the shorter to the longer rather than letting the
        // space length decide whether the block reads as a table.
        $urlWidth = max(strlen($home), strlen($notFnd));
        $homePad  = str_pad($home, $urlWidth);
        $notFndPad = str_pad($notFnd, $urlWidth);

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
# Document root:  {$public}/
# Engine + pages: {$secure}/     <-- MUST stay outside the document root
{$rootNote}#
# There is no management API and no admin panel in a build, so there is nothing
# here for them.
#
# ----------------------------------------------------------
# WHAT YOU MUST ALREADY HAVE
# ----------------------------------------------------------
# This file is a fragment. It is NOT a vhost and it cannot make a site serve on
# its own: it has no server block, no listen, no root, and no PHP handler. It
# assumes you already have a working PHP vhost — one that serves .php files
# from {$public}/ through php-fpm — and it adds the routing that turns that into
# a QuickSite site. If you can put a file with <?php phpinfo(); ?> in
# {$public}/ and see it run, you have what this needs.
#
# Your PHP handler must also refuse a path that is not a real file:
#
#     location ~ \.php$ {
#         try_files \$uri =404;          # <-- this line
#         include        fastcgi_params;
#         fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
#         fastcgi_pass   ...;            # your own php-fpm socket or address
#     }
#
# Without it, a request for /assets/images/logo.png/x.php is handed to PHP,
# which walks back to the part of the path that DOES exist and executes
# logo.png as a script. Measured on nginx 1.24.0 against a real build: the
# image came back with 200 and PHP's own headers on it. With the line, the same
# request answers 404 and every real page still serves. This is a property of
# YOUR handler, not of the block below, which is why it cannot be fixed here.
# Apache is not affected — it answers that request 404 on its own.
#
# ----------------------------------------------------------
# INSTALL IT
# ----------------------------------------------------------
#   1. include /path/to/{$secure}/nginx_routes.conf;   (inside your server { })
#   2. nginx -t && nginx -s reload
#   3. FETCH A PAGE. Step 2 is not a test of this file.
#
# If your vhost has more than one server block, the right one is the block that
# contains `location ~ \.php$`. See the two-server-block section below.
#
# ----------------------------------------------------------
# "MY SITE ALREADY WORKS AND I HAVE NOT ADDED THIS"
# ----------------------------------------------------------
# Expected, on a panel-generated vhost. Nothing is wrong and you have not
# misread the instructions.
#
# A hosting panel's default vhost usually already contains something like
#
#     try_files \$uri \$uri/ /index.php?\$args;
#     index index.php index.html;
#
# because that is what every front-controller application needs — Laravel,
# WordPress, Symfony — and a QuickSite build is one of those. `index index.php`
# resolves "/" to the front controller and the fallback catches every page
# route, so the site serves before this file is included at all. Measured on
# CloudPanel with a real build: every page, the 404 page, styling and language
# switching all correct with no include.
#
# What this file adds on top of that, and it is the whole list:
#
#   - A directory URL — /assets/, /style/ — answers with the SITE's 404 page.
#     Without it those give nginx's grey "403 Forbidden", because the panel's
#     `\$uri/` resolves the directory, finds no index file in it, and refuses.
#   - The three headers below reach your static files.
#   - `autoindex off` is stated rather than left to nginx's default.
#   - The routing is declared by the build instead of inherited from a template
#     the panel may regenerate.
#
# Pages behave identically either way — verified byte for byte on ten URLs. So
# this is worth adding, and it is not an emergency if you have not.
#
# ----------------------------------------------------------
# ⚠ IF THIS SERVER ALSO RUNS A QUICKSITE INSTALLATION
# ----------------------------------------------------------
# That installation has its own generated file, `<its secure>/nginx/
# dynamic_routes.conf`. It is a different file for a different thing, and you
# must not point this include at it: the site would get routing for namespaces
# that are not here and none for the ones that are.
#
# ⚠ AND DO NOT INCLUDE BOTH FILES IN THE SAME SERVER BLOCK. That file emits
# FIVE location blocks, not the three its own header names:
#
#     /admin/api/    /management/    /admin/    /p/    and the DOCUMENT ROOT
#
# — plus one funnel for each build deployed under a URL space. So it declares
# `location {$rootShown}` in exactly the case that matters here: when this site was
# deployed into that installation's own document root. Two location blocks with
# the same prefix are `[emerg] duplicate location "{$rootShown}"`, and nginx then
# refuses to load AT ALL — taking down every site on that server, not just this
# one. If your vhost already includes that file, adding this one stops nginx
# starting.
#
# YOU DO NOT NEED BOTH, and on that server you do not need this one. The
# installation regenerates its file after every deploy, from what is on its own
# disk, so the funnel for this site is already in it. THIS file is for deploying
# onto a server that has no QuickSite installation on it.
#
# ⚠ THE SYMPTOM, IF YOU EVER SEE IT: only "{$home}" serves, and every other URL
# answers nginx's own grey 404 page rather than this site's 404 page. That is
# some `location {$rootShown}` ending in `=404` winning over this site's funnel —
# an installation's free-root form, or a hand-written block of your own.
# "{$home}" still works because `index index.php` resolves the DIRECTORY; nothing
# else does, because the `=404` is hard.
#
# A server-level `try_files \$uri \$uri/ /index.php?\$args;` does NOT rescue
# it, however right it looks: server-level try_files runs only for a request that
# matched NO location, and a prefix location claims every request beneath it.
# Find the block that is winning with `nginx -T | grep -n "location {$rootShown}"`
# and remove the one that is not this site's.
#
# QuickSite checks what it can SEE — the files on its own disk — and does. It
# cannot see your vhost: it is not at a known path, and on a hosting panel it is
# assembled from includes that only `nginx -T` resolves. So this is written down
# rather than checked.
#
# ⚠ nginx -t parses the configuration; it does not resolve it. It will report
# "test is successful" for a setup that answers 500 on every page — a missing
# named location, for instance, is only discovered per request. So a green
# nginx -t means "nginx will start", not "the site works". What tells you the
# site works:
#
#     curl -i http://your-domain{$homePad}    -> 200, and HTML that is your home page
#     curl -i http://your-domain{$notFndPad}    -> 404, and YOUR 404 page, not nginx's
#
# The second one is the real check. If it returns nginx's grey "404 Not Found"
# instead of your site's own 404 page, the funnel below is not being reached —
# the include is in the wrong server block, or another location is winning.
#
# ----------------------------------------------------------
# ⚠ IF YOUR VHOST IS TWO SERVER BLOCKS  (skip this if it is one)
# ----------------------------------------------------------
# Most vhosts are a single server { }. Some hosting panels generate two: a
# public one on :443 that holds the static-asset rules and proxies everything
# else to a second, internal server block (often on :8080) that holds the PHP
# handler. CloudPanel does this.
#
# On that layout an include lands in ONE block and cannot reach the other, so
# where you put this file decides what works:
#
#   - Put it in the block that has the PHP handler (the internal one). That is
#     the block with `location ~ \.php$` in it. Pages will work.
#   - The public block still answers stylesheets, scripts and images from its
#     own regex, from ITS OWN document root, before the request is ever proxied.
#
# Whether that second point is a problem depends on one thing: does the public
# block's root point at this build's {$public}/ too?
#
#   - Both blocks share a root (what a panel normally generates): assets are
#     answered from disk by the public block and the site looks right. The only
#     difference is that those files get the public block's headers instead of
#     the three below — worth knowing, not worth chasing.
#   - The roots differ: every stylesheet, script and image 404s while pages are
#     fine, and you get a site with no styling. The fix is in the public block
#     and has to be made by hand — point it at this build's document root, or
#     exclude this site's paths from its asset regex. Nothing in an included
#     file can do it for you.
# ==========================================================

location {$prefix}/ {
    # Real files (style/, assets/, scripts/, sitemap.txt) are served directly;
    # everything else is a page and goes to the front controller.
    #
    # ⚠ NO \$uri/ IN THIS LIST, DELIBERATELY — the same omission, for the same
    # reason, as the install's own /admin/ block. With \$uri/ present a request
    # for a directory resolves the directory, finds no index file inside it,
    # and answers 403 with nginx's page. That includes "{$home}": the home page
    # of a built site answered 403 while every other URL worked. Without it,
    # directories fall through to the front controller and get this site's own
    # 404 page — which is what Apache does.
    try_files \$uri {$entry}\$is_args\$args;

    # No directory listings. The build ships no per-folder index guards.
    autoindex off;

    # Static files served by THIS location. Pages do not pass through here —
    # they leave through your PHP handler, and nginx does not carry add_header
    # across that boundary — so the front controller sends these three itself
    # on every page. Nothing is missing from a page if you delete them.
    #
    # If your vhost has its own `location ~* \.(css|js|png|...)$` block, that
    # regex outranks this prefix and those files get whatever it sets. To cover
    # them too, move these three lines OUT of this location, up into the
    # server { } block: at that level nginx inherits them into every location
    # that does not set its own. Measured: inside the location they reach
    # sitemap.txt only; at server level they reach every response.
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
            'src/functions/runtimePlaceholders.php',
            'src/functions/runtimeHandoff.php',
            'src/functions/resolverRegistry.php',
            'src/functions/resolverRuntime.php',
            'src/functions/apiRegistry.php',
            'src/functions/serverFetch.php',
            'src/functions/resolverCache.php',
            'src/functions/environment.php',
            'src/functions/errorHygiene.php',
            'src/functions/contentSecurityPolicy.php',
            'src/functions/jsonIo.php',
            'src/classes/DataResolver.php',
            'src/classes/OutboundUrlPolicy.php',
            'src/classes/IframeSandbox.php',
            'src/classes/OAuthHandler.php',
            'src/classes/UrlPolicy.php',
            'src/functions/oauthRuntime.php',
            'src/functions/oauthStateStore.php',
            'src/functions/requestRuntime.php',
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
