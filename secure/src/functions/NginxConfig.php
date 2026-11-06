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

/**
 * Generate nginx location block content for QuickSite routing
 *
 * Creates 7 location blocks in order of specificity:
 *   1. /prefix/admin/api/    — Admin panel AJAX helper
 *   2. /prefix/admin/self/   — The caller's own account and memberships
 *   3. /prefix/admin/state/  — The panel's own per-user state
 *   4. /prefix/management/   — Management API
 *   5. /prefix/admin/        — Admin panel
 *   6. /prefix/p/            — Project renderer (surface B, /p/<id>/)
 *   7. /prefix/              — Public root: always FREE
 *
 * ── WHY THREE BLOCKS UNDER /admin/ AND NOT ONE ───────────────────────────────
 *
 * public/admin/ holds three self-contained front controllers — api/, self/ and
 * state/ — each with its own index.php and its own .htaccess, and each one
 * answers JSON. nginx takes the LONGEST MATCHING PREFIX, so without a block of
 * its own a request for /admin/self/permissions matches `location /admin/`,
 * whose try_files deliberately omits `$uri/` and therefore falls through to the
 * PANEL front controller. AdminRouter has no page called `self` or `state`, so
 * it answers its HTML 404 page to a client parsing JSON.
 *
 * That is not theory: /admin/self/ and /admin/state/ were added after this
 * generator was written, nothing re-checked it, and on every nginx install the
 * project picker, My Account, My Memberships, the members badge and the
 * dashboard account panel were unreachable. Apache never showed it — each
 * directory's own .htaccess is picked up automatically, so no central list has
 * to be kept in step there and none was.
 *
 * ⚠ ADDING A FOURTH FRONT CONTROLLER UNDER public/admin/ MEANS ADDING A BLOCK
 * HERE. Check both directions off the filesystem — that no directory
 * shadows a panel page, and that every front-controller directory has a
 * location block — so the omission is caught here rather than on
 * somebody's nginx.
 *
 * ── WHY THE ROOT BLOCK IS FREE ───────────────────────────────────────────────
 *
 * The root belongs to the operator's own site. QuickSite serves its projects at
 * /p/ and must not squat /: a funnel here would claim every URL on a domain it
 * does not own, and it would do so quietly.
 *
 * So a built site does not belong at this root. It goes in a server block of its
 * own, with the `nginx_routes.conf` it ships. Copied here by hand it serves its
 * home page, because `index index.php` resolves the DIRECTORY, and every other URL
 * hits the hard `=404`: nginx's own grey 404, never the site's.
 *
 * ⚠ AND A `location /` OUTRANKS A SERVER-LEVEL `try_files`. A vhost that already
 * carries `try_files $uri $uri/ /index.php?$args;` at server level — which is
 * what every panel generates, because it is what every front-controller app
 * needs — does NOT rescue that case: server-level try_files runs only for a
 * request that matched no location, and `location /` matches everything. So an
 * included `location /` shadows it completely. Measured on nginx 1.24.0.
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
 * @param string $secureFolderName This installation's secure folder name. A
 *        parameter, so the generator stays runnable against a scratch tree; and
 *        read from the caller rather than hardcoded as "secure" because the
 *        folder is renameable and the header below tells the operator which
 *        file to include.
 * @return string Nginx configuration content
 */
function generate_nginx_config(string $publicFolderSpace, string $secureFolderName = 'secure'): string {
    $prefix = $publicFolderSpace !== '' ? '/' . trim($publicFolderSpace, '/') : '';

    $date = date('Y-m-d H:i:s');

    $config = "# ==========================================================\n";
    $config .= "# QuickSite — nginx dynamic routes configuration\n";
    $config .= "# ==========================================================\n";
    $config .= "# Auto-generated on {$date} by QuickSite\n";
    $config .= "# Do NOT edit manually — rewritten whenever it is regenerated.\n";
    $config .= "#\n";
    $config .= "# WHEN THIS FILE IS WRITTEN: once, when it is absent (any page load\n";
    $config .= "# creates it). Changing the URL space is handled by setup deleting this\n";
    $config .= "# file so the next page load rebuilds it.\n";
    $config .= "#\n";
    $config .= "# Usage — TWO steps, both required:\n";
    $config .= "#   1. include /path/to/{$secureFolderName}/nginx/dynamic_routes.conf;   (in server {})\n";
    $config .= "#   2. define the `@quicksite_project` named location — see the block\n";
    $config .= "#      further down. Without it every project URL answers 500.\n";
    $config .= "#\n";
    $config .= "# QuickSite attempts to reload nginx automatically when\n";
    $config .= "# the public space configuration changes (requires sudoers setup).\n";
    $config .= "#\n";
    $config .= "# Manual reload: nginx -t && nginx -s reload\n";
    $config .= "# Cron fallback: {$secureFolderName}/cron/nginx_reload.sh (optional)\n";
    $config .= "# ==========================================================\n\n";

    // The three JSON front controllers under public/admin/ — see the function
    // docblock for why each needs its own location. They come FIRST because they
    // are the most specific paths; ordering is for the reader, since nginx picks
    // the longest matching prefix regardless of the order they appear in.
    $config .= "# Admin panel API (AJAX helper for dynamic form fields)\n";
    $config .= "location {$prefix}/admin/api/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/admin/api/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    $config .= "# Admin panel account endpoint — the caller's own account and their project\n";
    $config .= "# memberships. Needs its OWN location for the same reason /admin/api/ does:\n";
    $config .= "# nginx picks the longest matching prefix, so without this block a request\n";
    $config .= "# for /admin/self/… falls into the {$prefix}/admin/ block below and is answered\n";
    $config .= "# by the panel's HTML front controller, which has no page by that name and\n";
    $config .= "# returns its 404 PAGE to a caller that is parsing JSON.\n";
    $config .= "location {$prefix}/admin/self/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/admin/self/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    $config .= "# Admin panel state endpoint — the panel's own per-user state (which project\n";
    $config .= "# you have open). Same reason for its own location as the two blocks above.\n";
    $config .= "location {$prefix}/admin/state/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/admin/state/index.php\$is_args\$args;\n";
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
    $config .= "# folder under {$secureFolderName}/projects/, which is OUTSIDE the web root so the\n";
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

    // Public root. FREE — no fallback into QuickSite: the root serves real static
    // files only (a user's own hand-made site), 404 otherwise. The renderer lives at
    // /p/ above, and that is the only place a PROJECT is served from on this install.
    $locationPath = $prefix !== '' ? "{$prefix}/" : '/';
    $config .= "# Public root — free for the user's own site (no QuickSite fallback)\n";
    $config .= "#\n";
    $config .= "# ⚠ NOT FOR A BUILT SITE. A build copied to this document root serves its\n";
    $config .= "# home page and answers every other URL with nginx's own grey 404, because\n";
    $config .= "# this block ends in `=404`. A built site goes in a server block of its own,\n";
    $config .= "# with the `nginx_routes.conf` it ships.\n";
    $config .= "location {$locationPath} {\n";
    $config .= "    try_files \$uri \$uri/ =404;\n";
    $config .= "}\n";

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
 * @return array{success: bool, config_path: string, nginx_reloaded: bool, reload_outcome: string, reload_note: string, error?: string}
 */
function write_nginx_dynamic_routes(string $publicFolderSpace, string $secureFolderPath): array {
    $nginxDir = $secureFolderPath . DIRECTORY_SEPARATOR . 'nginx';
    $configPath = $nginxDir . DIRECTORY_SEPARATOR . 'dynamic_routes.conf';

    // Create nginx directory if it doesn't exist
    if (!is_dir($nginxDir)) {
        if (!mkdir($nginxDir, 0755, true)) {
            // Same shape as every other return from this function, so a caller
            // that reads `reload_outcome` without checking `success` first gets
            // no undefined-key warning from a failed mkdir.
            return [
                'success' => false,
                'config_path' => $configPath,
                'nginx_reloaded' => false,
                'reload_outcome' => 'not_attempted',
                'reload_note' => 'the nginx directory could not be created, so nothing was written or reloaded',
                'error' => 'Failed to create nginx directory: ' . $nginxDir
            ];
        }
    }

    // Generate and write config
    $content = generate_nginx_config($publicFolderSpace, basename(rtrim($secureFolderPath, '/\\')));

    if (file_put_contents($configPath, $content, LOCK_EX) === false) {
        return [
            'success' => false,
            'config_path' => $configPath,
            'nginx_reloaded' => false,
            'reload_outcome' => 'not_attempted',
            'reload_note' => 'the configuration was not written, so nothing was reloaded',
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
    // install it is a process spawn for a binary that is not there. An Apache
    // install must behave exactly as it would if this had never run.
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
