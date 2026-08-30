<?php
/**
 * Nginx configuration generator for QuickSite
 * 
 * Generates dynamic_routes.conf with try_files location blocks
 * for nginx servers where .htaccess is not supported.
 * 
 * Apache users can ignore this entirely — .htaccess files handle routing.
 * Nginx users include the generated config in their server block.
 */

require_once __DIR__ . '/uploadLimits.php';      // qs_nginx_client_max_body_size
require_once __DIR__ . '/deploymentMarker.php'; // qs_deployed_sites

/**
 * Generate nginx location block content for QuickSite routing
 *
 * Creates 5 location blocks in order of specificity:
 *   1. /prefix/admin/api/    — Admin panel AJAX helper
 *   2. /prefix/management/   — Management API
 *   3. /prefix/admin/        — Admin panel
 *   4. /prefix/p/            — Project renderer (surface B, /p/<id>/)
 *   5. /prefix/              — Public root: FREE by default, a front-controller
 *                              funnel once a build is deployed there
 *   (+ one funnel per build deployed under a URL space)
 *
 * ── WHY THE ROOT BLOCK IS NOT A CONSTANT ─────────────────────────────────────
 *
 * The root block is derived from what is ON DISK at the document root, because
 * the two things it has to be are opposites and only the disk can say which:
 *
 *   nothing deployed  ->  try_files $uri $uri/ =404;
 *                         The root belongs to the operator's own site. QuickSite
 *                         serves its projects at /p/ and must not squat /.
 *
 *   a build deployed  ->  try_files $uri <entry>;
 *   at the root           The root now holds a front controller and every page
 *                         route has to reach it.
 *
 * Getting this wrong in either direction is severe, and they fail differently.
 * A `=404` over a deployed build is what it was: the home page serves, because
 * `index index.php` resolves the DIRECTORY, and every other URL hits the hard
 * `=404` — nginx's own grey 404, never the site's. A funnel over a free root is
 * worse and quieter: QuickSite claims every URL on a domain it does not own.
 *
 * ⚠ AND A `location /` OUTRANKS A SERVER-LEVEL `try_files`. A vhost that already
 * carries `try_files $uri $uri/ /index.php?$args;` at server level — which is
 * what every panel generates, because it is what every front-controller app
 * needs — does NOT rescue the case: server-level try_files runs only for a
 * request that matched no location, and `location /` matches everything. So an
 * included `location /` shadows it completely. Measured on nginx 1.24.0.
 *
 * A build deployed under a URL SPACE gets its own funnel and leaves the root
 * alone: mounting a site at /shop/ is precisely the choice to leave / free.
 *
 * ── WHY /p/ IS THE ONLY BLOCK WITH `^~` ──────────────────────────────────────
 *
 * Blocks 1, 2, 3 and 5 are plain prefixes, and a plain prefix LOSES to any regex
 * location in the surrounding vhost. That is safe for them because everything
 * they serve is either extensionless (a command, a panel route) or a real file
 * inside the web root — a regex block looking for it on disk finds it.
 *
 * /p/ is different, and it is the difference that matters: a project's files
 * live in secure/projects/<id>/public/, deliberately OUTSIDE the web root, so
 * PHP can apply the visibility gate before a single byte is sent. Nothing under
 * /p/ can ever be found on disk. A panel-generated vhost almost always carries
 * something like
 *
 *     location ~* ^.+\.(css|js|png|…)$ { expires max; }
 *
 * and a REGEX beats a PREFIX, so every stylesheet, script and image under /p/
 * was answered by that block, which looked in the web root and 404ed — while
 * extensionless page routes, matching no regex, rendered perfectly. `^~` is what
 * takes /p/ out of that competition.
 *
 * ── AND WHY THE FALLBACK IS A NAMED LOCATION ─────────────────────────────────
 *
 * `^~` suppresses regex matching for anything it wins, INCLUDING the vhost's own
 * `location ~ \.php$` handler. So the obvious
 *
 *     location ^~ /p/ { try_files $uri $uri/ /p/index.php; }
 *
 * is a trap: the fallback re-enters location matching, lands back in this same
 * `^~` block with regex still suppressed, and `try_files $uri` now finds
 * /p/index.php ON DISK — nginx serves the engine's source as plain text. The
 * naive version looks identical to this one and discloses the whole renderer.
 *
 * A NAMED location cannot do that: nginx jumps straight to it without re-running
 * location matching, so there is no second pass to be trapped in. The operator
 * defines it once in their vhost (it needs their php-fpm upstream, which this
 * file cannot know). The nested `location ~ \.php$ { return 404; }` closes the
 * remaining hole — a DIRECT request for /p/index.php, which `^~` would otherwise
 * hand to the static file handler.
 *
 * @param string $publicFolderSpace URL prefix (e.g., 'quicksite/test' or '')
 * @param list<array{space:string,project:string}> $deployedSites Builds deployed
 *        into this installation's document root, from qs_deployed_sites(). Empty
 *        (the default) means the root stays free.
 * @return string Nginx configuration content
 */
function generate_nginx_config(string $publicFolderSpace, array $deployedSites = []): string {
    $prefix = $publicFolderSpace !== '' ? '/' . trim($publicFolderSpace, '/') : '';

    // Split the deployments into "owns the root" and "owns a subdirectory". At
    // most one can own the root: two sites cannot both be `<public>/index.php`.
    $rootSite   = null;
    $spaceSites = [];
    foreach ($deployedSites as $site) {
        $space = trim((string) ($site['space'] ?? ''), '/');
        if ($space === '') {
            $rootSite = $site;
        } else {
            $spaceSites[] = ['space' => $space, 'project' => (string) ($site['project'] ?? '')];
        }
    }

    $date = date('Y-m-d H:i:s');

    $config = "# ==========================================================\n";
    $config .= "# QuickSite — nginx dynamic routes configuration\n";
    $config .= "# ==========================================================\n";
    $config .= "# Auto-generated on {$date} by QuickSite\n";
    $config .= "# Do NOT edit manually — rewritten whenever it is regenerated.\n";
    $config .= "#\n";
    $config .= "# WHEN THIS FILE IS WRITTEN: once, when it is absent (any page load\n";
    $config .= "# creates it), and again after every successful build deploy — because\n";
    $config .= "# deploying changes what is at the document root, and the last block in\n";
    $config .= "# this file has to agree with that. Changing the URL space is handled by\n";
    $config .= "# setup deleting this file so the next page load rebuilds it.\n";
    $config .= "#\n";
    $config .= "# Usage — TWO steps, both required:\n";
    $config .= "#   1. include /path/to/secure/nginx/dynamic_routes.conf;   (in server {})\n";
    $config .= "#   2. define the `@quicksite_project` named location — see the block\n";
    $config .= "#      further down. Without it every project URL answers 500.\n";
    $config .= "#\n";
    $config .= "# QuickSite attempts to reload nginx automatically when\n";
    $config .= "# the public space configuration changes (requires sudoers setup).\n";
    $config .= "#\n";
    $config .= "# Manual reload: nginx -t && nginx -s reload\n";
    $config .= "# Cron fallback: secure/cron/nginx_reload.sh (optional)\n";
    $config .= "# ==========================================================\n\n";

    // Admin API (most specific path — must come first)
    $config .= "# Admin panel API (AJAX helper for dynamic form fields)\n";
    $config .= "location {$prefix}/admin/api/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/admin/api/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    // Management API. This is the ONLY namespace that receives file uploads
    // (uploadAsset and importProject; nothing under /admin/api/ takes a file),
    // so the body-size directive belongs here rather than at server level — it
    // raises the ceiling exactly where an upload lands and nowhere else.
    $config .= "# Management API (QuickSite command endpoint)\n";
    $config .= "location {$prefix}/management/ {\n";
    $config .= "    # REQUIRED for uploads. nginx's own default is 1 MB — SMALLER than what\n";
    $config .= "    # PHP on this server accepts (post_max_size = " . ini_get('post_max_size') . "), so without\n";
    $config .= "    # this line nginx refuses files QuickSite says are fine, and refuses them\n";
    $config .= "    # with its own HTML 413 page BEFORE PHP runs — no JSON, no explanation.\n";
    $config .= "    # The value is one megabyte above PHP's own limit on purpose, so PHP is\n";
    $config .= "    # always the component that refuses an oversized upload and can say why.\n";
    $config .= "    # Computed from this server's PHP configuration each time this file is\n";
    $config .= "    # generated — see WHEN THIS FILE IS WRITTEN at the top. It is NOT\n";
    $config .= "    # recomputed in between, so after changing post_max_size: delete this\n";
    $config .= "    # file, load any page to regenerate it, then reload nginx.\n";
    $config .= "    client_max_body_size " . qs_nginx_client_max_body_size() . ";\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/management/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    // Admin panel
    $config .= "# Admin panel\n";
    $config .= "location {$prefix}/admin/ {\n";
    $config .= "    # NO \$uri/ IN THIS LIST, DELIBERATELY. The panel has a PAGE route\n";
    $config .= "    # named /admin/assets, and public/admin/ also holds a real assets/\n";
    $config .= "    # directory (the panel's own css + js). With \$uri/ present, nginx\n";
    $config .= "    # matches that DIRECTORY, finds no index inside it and answers 403 —\n";
    $config .= "    # so the asset manager is the one admin page that cannot be opened.\n";
    $config .= "    # Apache never showed this: FallbackResource does not resolve a\n";
    $config .= "    # directory the way try_files does. Real files under /admin/assets/\n";
    $config .= "    # still resolve through \$uri, and a bare /admin/ still reaches\n";
    $config .= "    # index.php through the fallback below.\n";
    $config .= "    try_files \$uri {$prefix}/admin/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    // Project renderer (surface B) — see the function docblock for why this one
    // block is `^~` and why its fallback is a NAMED location.
    $config .= "# Project renderer — every project is served at /p/<projectId>/ from its own\n";
    $config .= "# folder under secure/projects/, which is OUTSIDE the web root so the\n";
    $config .= "# visibility gate runs before any byte is sent.\n";
    $config .= "#\n";
    $config .= "# `^~` is required: it stops a vhost's own `location ~* \\.(css|js|png|…)$`\n";
    $config .= "# regex from claiming these URLs and 404ing them (a regex outranks a plain\n";
    $config .= "# prefix in nginx). Page routes have no extension and were never affected,\n";
    $config .= "# which is why only styles, scripts and images went missing.\n";
    $config .= "location ^~ {$prefix}/p/ {\n";
    $config .= "    try_files \$uri \$uri/ @quicksite_project;\n";
    $config .= "\n";
    $config .= "    # `^~` also suppresses the vhost's PHP handler here, so a direct request\n";
    $config .= "    # for the entry point must be refused explicitly — otherwise nginx would\n";
    $config .= "    # serve it as a static file and hand out the engine's source.\n";
    $config .= "    location ~ \\.php\$ { return 404; }\n";
    $config .= "}\n\n";

    // The named location the operator must define. It cannot be generated: it needs
    // the deployment's own php-fpm upstream, which QuickSite has no way to know.
    $config .= "# ----------------------------------------------------------------------------\n";
    $config .= "# REQUIRED — add this to your server {} block, NOT to this file.\n";
    $config .= "# This file is regenerated whenever the install layout changes; edits here\n";
    $config .= "# are lost.\n";
    $config .= "#\n";
    $config .= "# WHERE TO FIND THE fastcgi_pass VALUE: search your vhost for the word\n";
    $config .= "# `fastcgi_pass`. It is already there — your `location ~ \\.php$` block cannot\n";
    $config .= "# work without it. Copy that whole line. It looks like one of:\n";
    $config .= "#     fastcgi_pass unix:/run/php/php8.3-fpm.sock;\n";
    $config .= "#     fastcgi_pass 127.0.0.1:9000;\n";
    $config .= "# (127.0.0.1 is loopback — this machine talking to itself. It exposes\n";
    $config .= "# nothing; php-fpm is already listening there for your other PHP requests.)\n";
    $config .= "#\n";
    $config .= "# location @quicksite_project {\n";
    $config .= "#     include        fastcgi_params;\n";
    $config .= "#     fastcgi_param  SCRIPT_FILENAME \$document_root{$prefix}/p/index.php;\n";
    // NOT angle brackets. A `<placeholder>` pasted verbatim fails with "invalid
    // number of arguments", which is caught but says nothing about the fix — it
    // happened in testing. A single token that names the action fails the config
    // test just as loudly and reads as the instruction it is.
    $config .= "#     fastcgi_pass   COPY_THIS_FROM_YOUR_OWN_php_BLOCK;\n";
    $config .= "# }\n";
    $config .= "#\n";
    $config .= "# SCRIPT_FILENAME is HARDCODED to the entry point above, and must stay that\n";
    $config .= "# way: deriving it from \$fastcgi_script_name is what lets a request like\n";
    $config .= "# /uploads/photo.jpg/x.php execute an uploaded file. With a fixed path there\n";
    $config .= "# is no request-controlled component left, so that class cannot arise here.\n";
    $config .= "#\n";
    $config .= "# Without this block every project URL answers 500 and the error log says\n";
    $config .= "# `could not find named location \"@quicksite_project\"`.\n";
    $config .= "# ----------------------------------------------------------------------------\n\n";

    // A build deployed under a URL SPACE gets its own funnel, ABOVE the root block
    // so the file reads most-specific-first. nginx picks the longest matching
    // prefix regardless of order, so this is for the human, not for nginx.
    foreach ($spaceSites as $site) {
        $spacePath = $prefix . '/' . $site['space'];
        $who = $site['project'] !== '' ? " (project \"{$site['project']}\")" : '';
        $config .= "# Deployed site at {$spacePath}/{$who} — front controller funnel.\n";
        $config .= "# The root below is untouched: mounting a site under a URL space is\n";
        $config .= "# exactly the choice to leave the domain root free.\n";
        $config .= "location {$spacePath}/ {\n";
        $config .= "    # NO \$uri/ IN THIS LIST, DELIBERATELY — the same omission, for the\n";
        $config .= "    # same reason, as the /admin/ block above. With \$uri/ present a\n";
        $config .= "    # request for a directory resolves the directory, finds no index\n";
        $config .= "    # file inside it, and answers 403 — including the site's home page.\n";
        $config .= "    try_files \$uri {$spacePath}/index.php\$is_args\$args;\n";
        $config .= "}\n\n";
    }

    // Public root. FREE BY DEFAULT — no fallback into QuickSite: the root serves real
    // static files only (a user's own hand-made site), 404 otherwise. The renderer
    // lives at /p/ above, and that is the only place a PROJECT is served from on this
    // install.
    //
    // The exception is a BUILD deployed here, which puts a front controller at this
    // exact path. Then the root is no longer free — this installation put a site
    // there — and every page route has to reach that front controller.
    $locationPath = $prefix !== '' ? "{$prefix}/" : '/';
    if ($rootSite !== null) {
        $who = ((string) ($rootSite['project'] ?? '')) !== ''
            ? " (project \"{$rootSite['project']}\")" : '';
        $config .= "# Public root — a deployed QuickSite build serves here{$who}.\n";
        $config .= "#\n";
        $config .= "# ⚠ THIS BLOCK IS DERIVED FROM THE DISK. It became a funnel because a\n";
        $config .= "# build was deployed to this document root and its front controller is\n";
        $config .= "# there now. Deploy again after removing it and this returns to the\n";
        $config .= "# free-root form (`try_files \$uri \$uri/ =404;`).\n";
        $config .= "#\n";
        $config .= "# ⚠ DO NOT ALSO INCLUDE THE BUILD'S OWN `nginx_routes.conf` IN THIS\n";
        $config .= "# SERVER BLOCK. It declares the same funnel, and two location blocks\n";
        $config .= "# with the same prefix are `[emerg] duplicate location` — nginx then\n";
        $config .= "# refuses to load AT ALL, taking down every site on this server. This\n";
        $config .= "# file already does that job here; the build's snippet is for deploying\n";
        $config .= "# onto a server that has no QuickSite installation.\n";
        $config .= "location {$locationPath} {\n";
        $config .= "    # NO \$uri/ IN THIS LIST, DELIBERATELY — the same omission, for the\n";
        $config .= "    # same reason, as the /admin/ block above. With \$uri/ present a\n";
        $config .= "    # request for a directory resolves the directory, finds no index\n";
        $config .= "    # file inside it, and answers 403 — including the site's home page.\n";
        $config .= "    try_files \$uri {$locationPath}index.php\$is_args\$args;\n";
        $config .= "}\n";
    } else {
        $config .= "# Public root — free for the user's own site (no QuickSite fallback)\n";
        $config .= "#\n";
        $config .= "# ⚠ THIS BLOCK IS DERIVED FROM THE DISK. It is the free-root form\n";
        $config .= "# because no QuickSite build is deployed at this document root. Deploy\n";
        $config .= "# one here and this becomes a front-controller funnel instead — a\n";
        $config .= "# deployed site whose root stayed `=404` serves its home page and\n";
        $config .= "# answers every other URL with nginx's own grey 404.\n";
        $config .= "location {$locationPath} {\n";
        $config .= "    try_files \$uri \$uri/ =404;\n";
        $config .= "}\n";
    }

    return $config;
}

/**
 * Write nginx dynamic_routes.conf and attempt to reload nginx
 * 
 * Creates secure/nginx/ directory if needed, writes the config file,
 * then attempts to reload nginx directly (requires sudoers setup).
 * If direct reload fails, sets a .pending_reload flag for the optional
 * cron-based fallback script (secure/cron/nginx_reload.sh).
 * 
 * @param string $publicFolderSpace URL prefix (e.g., 'quicksite/test' or '')
 * @param string $secureFolderPath  Absolute path to the secure folder
 * @param string|null $serverRoot   Installation root; defaults to SERVER_ROOT.
 * @param string|null $publicFolderName Public folder name; defaults to PUBLIC_FOLDER_NAME.
 *        Both are parameters rather than constant reads so the generator can be
 *        exercised against a scratch tree without an installation around it.
 * @param list<string> $extraMarkerDirs Deployment folders the caller already
 *        knows about — `deployBuild` passes the one it has just written, so the
 *        deploy in progress is never subject to the marker scan's depth cap.
 * @return array{success: bool, config_path: string, nginx_reloaded: bool, reload_outcome: string, reload_note: string, deployed_sites: list<array<string,string>>, error?: string}
 */
function write_nginx_dynamic_routes(
    string $publicFolderSpace,
    string $secureFolderPath,
    ?string $serverRoot = null,
    ?string $publicFolderName = null,
    array $extraMarkerDirs = []
): array {
    $nginxDir = $secureFolderPath . DIRECTORY_SEPARATOR . 'nginx';
    $configPath = $nginxDir . DIRECTORY_SEPARATOR . 'dynamic_routes.conf';

    // What is actually deployed at the document root — the input the root block
    // is derived from. Unknowable without both of these, in which case the root
    // stays free, which is the safe direction: it never squats a domain.
    $serverRoot = $serverRoot ?? (defined('SERVER_ROOT') ? SERVER_ROOT : null);
    $publicFolderName = $publicFolderName ?? (defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : null);
    $deployedSites = ($serverRoot !== null && $publicFolderName !== null)
        ? qs_deployed_sites($serverRoot, $publicFolderName, $extraMarkerDirs)
        : [];

    // Create nginx directory if it doesn't exist
    if (!is_dir($nginxDir)) {
        if (!mkdir($nginxDir, 0755, true)) {
            // Same shape as every other return from this function. A caller
            // reads `reload_outcome` and `deployed_sites` without checking
            // `success` first — an error branch that omits them turns a failed
            // mkdir into undefined-key warnings in the caller's response.
            return [
                'success' => false,
                'config_path' => $configPath,
                'nginx_reloaded' => false,
                'reload_outcome' => 'not_attempted',
                'reload_note' => 'the nginx directory could not be created, so nothing was written or reloaded',
                'deployed_sites' => $deployedSites,
                'error' => 'Failed to create nginx directory: ' . $nginxDir
            ];
        }
    }

    // Generate and write config
    $content = generate_nginx_config($publicFolderSpace, $deployedSites);

    if (file_put_contents($configPath, $content, LOCK_EX) === false) {
        return [
            'success' => false,
            'config_path' => $configPath,
            'nginx_reloaded' => false,
            'reload_outcome' => 'not_attempted',
            'reload_note' => 'the configuration was not written, so nothing was reloaded',
            'deployed_sites' => $deployedSites,
            'error' => 'Failed to write nginx config: ' . $configPath
        ];
    }

    // Attempt direct nginx reload (requires sudoers setup)
    $reloaded = try_nginx_reload($nginxDir);

    return [
        'success' => true,
        'config_path' => $configPath,
        'nginx_reloaded' => $reloaded['reloaded'],
        'reload_outcome' => $reloaded['outcome'],
        'reload_note' => $reloaded['reason'],
        'deployed_sites' => $deployedSites,
    ];
}

/**
 * Is the web server serving this request nginx?
 *
 * ⚠ THE ONLY CALLER THAT MATTERS IS THE RELOAD, and the answer decides whether
 * this process shells out at all. Under-claiming is the safe direction: an
 * install answering "no" reports that the deployer must reload nginx by hand,
 * which is true advice on a server that has none.
 *
 * `SERVER_SOFTWARE` is set by the SAPI from the server, not by the client. On
 * CLI it is absent, and that is correct too — a command-line run has no business
 * reloading a live web server.
 */
function qs_server_is_nginx(): bool
{
    return stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'nginx') !== false;
}

/**
 * Attempt to test and reload nginx directly via shell
 * 
 * Tries: sudo nginx -t && sudo nginx -s reload
 * 
 * If shell_exec is disabled or sudo is not configured, this fails silently
 * and sets a .pending_reload flag for the optional cron fallback.
 * 
 * To enable direct reload (recommended, no cron needed):
 *   echo 'www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx' | sudo tee /etc/sudoers.d/quicksite-nginx
 *
 * THREE OUTCOMES, AND THE CALLER MUST BE ABLE TO TELL THEM APART. Reporting a
 * reload that did not happen is worse than reporting none: the deployer stops
 * looking, and the running nginx keeps the previous configuration.
 *
 *   'reloaded'       — nginx -t passed and the reload went through.
 *   'pending'        — could not reload; `.pending_reload` is written, which the
 *                      optional cron script picks up. Manual reload still works.
 *   'not_applicable' — this is not an nginx server. Nothing was attempted and no
 *                      flag was written; a flag meaning "reload nginx" on a box
 *                      that runs Apache is a file nothing will ever read.
 *
 * @param string $nginxDir Path to secure/nginx/ directory
 * @return array{reloaded: bool, outcome: string, reason: string}
 */
function try_nginx_reload(string $nginxDir): array {
    $flagPath = $nginxDir . DIRECTORY_SEPARATOR . '.pending_reload';

    // ⚠ ASKED FIRST, BEFORE ANY SHELL-OUT. This file is generated on Apache too
    // — writing it there is inert, because Apache never reads it and .htaccess
    // does the routing — but `sudo nginx -t` is NOT inert: on a Windows/Apache
    // install it is a process spawn per deploy for a binary that is not there.
    // An Apache deploy must behave exactly as it did before this ran at all.
    if (!qs_server_is_nginx()) {
        return [
            'reloaded' => false,
            'outcome' => 'not_applicable',
            'reason' => 'not an nginx server — nothing to reload (Apache reads .htaccess and needs none of this)'
        ];
    }

    // Check if shell_exec is available
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'outcome' => 'pending',
            'reason' => 'shell_exec disabled — set up cron fallback or reload nginx manually'
        ];
    }

    // Check if shell_exec is in disabled_functions
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled)) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'outcome' => 'pending',
            'reason' => 'shell_exec in disabled_functions — set up cron fallback or reload nginx manually'
        ];
    }

    // Try nginx -t first (validate config)
    $testOutput = shell_exec('sudo nginx -t 2>&1');
    if ($testOutput === null || strpos($testOutput, 'successful') === false) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'outcome' => 'pending',
            'reason' => 'nginx -t failed: ' . ($testOutput ?? 'no output (sudo not configured?)')
        ];
    }

    // Config valid — reload
    $reloadOutput = shell_exec('sudo nginx -s reload 2>&1');
    if ($reloadOutput === null || (trim($reloadOutput) !== '' && strpos($reloadOutput, 'error') !== false)) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'outcome' => 'pending',
            'reason' => 'nginx -s reload failed: ' . ($reloadOutput ?? 'no output')
        ];
    }

    // Success — remove any stale flag
    if (file_exists($flagPath)) {
        unlink($flagPath);
    }

    // Log successful reload
    $logDir = dirname($nginxDir) . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($logDir)) {
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'nginx_reload.log';
        file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] OK: nginx reloaded by PHP (public space change)' . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    return [
        'reloaded' => true,
        'outcome' => 'reloaded',
        'reason' => 'nginx reloaded directly via sudo'
    ];
}
