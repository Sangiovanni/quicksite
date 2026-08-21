<?php

// Define the project CONSTANTS
if (!defined('PUBLIC_FOLDER_ROOT')) {
    define('PUBLIC_FOLDER_ROOT',  $_SERVER['DOCUMENT_ROOT']);
}

if(!defined('PUBLIC_FOLDER_NAME')){
    define('PUBLIC_FOLDER_NAME', 'public');
}

if(!defined('SECURE_FOLDER_NAME')){
    define('SECURE_FOLDER_NAME', 'secure');
}

if(!defined('PUBLIC_FOLDER_SPACE')){
    define('PUBLIC_FOLDER_SPACE', '');
}

// PUBLIC_CONTENT_PATH is deliberately NOT defined here. It is RENDER-SCOPED — the style/,
// assets/ and scripts/ of ONE project — and every project serves from its own public/, so
// there is no installation-wide value it could sensibly take. It is bound beside
// PROJECT_PATH by qs_load_project_context(), which every entry point calls with the project
// the request actually targets (secure/src/functions/projectContext.php).

// ADMIN_ASSET_ROOT = where the admin panel's OWN chrome assets live (public/admin/assets/…).
// Deliberately SEPARATE from the render-scoped PUBLIC_CONTENT_PATH. The panel filemtime()s
// its own JS/CSS for cache-busting; those assets sit at the web root (public/admin/assets/) —
// always, regardless of the content space AND regardless of which project the panel edits.
// The panel binds the project it EDITS, so PUBLIC_CONTENT_PATH is that project's own public/
// and would be the wrong place to look for the panel's own chrome.
// Always the DOCUMENT_ROOT (public/), never space-prefixed.
if (!defined('ADMIN_ASSET_ROOT')) {
    define('ADMIN_ASSET_ROOT', PUBLIC_FOLDER_ROOT);
}

if(!defined('SERVER_ROOT')){
    // Remove only the rightmost occurrence of PUBLIC_FOLDER_NAME from path
    // This prevents issues when folder name appears multiple times in path
    // e.g., C:/wamp64/www/mysite/www -> C:/wamp64/www/mysite/ (not C:/wamp64//mysite/)
    $folderPattern = '/' . preg_quote(PUBLIC_FOLDER_NAME, '/') . '[\\\\\\/]?$/';
    define('SERVER_ROOT', rtrim(preg_replace($folderPattern, '', PUBLIC_FOLDER_ROOT), '/\\'));
}

if (!defined('SECURE_FOLDER_PATH')) {
    // SECURE_FOLDER_PATH = engine files (admin, management, src)
    define('SECURE_FOLDER_PATH', SERVER_ROOT . DIRECTORY_SEPARATOR . SECURE_FOLDER_NAME);
}

// ============================================================================
// SAFETY CHECK: Verify SECURE_FOLDER_PATH exists
// ============================================================================
// If the user renamed public/ without updating PUBLIC_FOLDER_NAME,
// SERVER_ROOT will be wrong and SECURE_FOLDER_PATH won't exist.
if (!is_dir(SECURE_FOLDER_PATH)) {
    http_response_code(500);
    $detectedRoot = htmlspecialchars(PUBLIC_FOLDER_ROOT);
    $computedSecure = htmlspecialchars(SECURE_FOLDER_PATH);
    $publicName = htmlspecialchars(PUBLIC_FOLDER_NAME);
    $actualFolder = basename(PUBLIC_FOLDER_ROOT);
    die(
        '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">' .
        '<title>QuickSite - Configuration Error</title>' .
        '<style>' .
        'body{font-family:system-ui,-apple-system,sans-serif;max-width:720px;margin:60px auto;line-height:1.6;padding:0 20px;color:#333;background:#fafafa}' .
        'h1{color:#c62828;margin-bottom:0.3em}' .
        '.subtitle{color:#666;margin-top:0}' .
        'code{background:#e8eaf0;padding:2px 6px;border-radius:3px;font-size:0.9em}' .
        'pre{background:#1e1e2e;color:#cdd6f4;padding:16px 20px;border-radius:8px;overflow-x:auto;font-size:0.88em;line-height:1.5}' .
        '.fix{margin:20px 0;padding:16px 20px;background:#fff;border-left:4px solid #4caf50;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.08)}' .
        '.fix h3{margin-top:0;color:#2e7d32}' .
        '.diag{margin:20px 0;padding:14px 18px;background:#fff3e0;border-left:4px solid #ff9800;border-radius:4px}' .
        '.diag strong{color:#e65100}' .
        'hr{border:none;border-top:1px solid #ddd;margin:30px 0}' .
        'small{color:#888}' .
        '</style></head><body>' .
        '<h1>QuickSite &mdash; Configuration Error</h1>' .
        '<p class="subtitle">The <code>secure/</code> folder could not be found.</p>' .

        '<div class="diag">' .
        '<strong>What happened:</strong>' .
        '<p style="margin-bottom:0.5em">QuickSite uses <code>PUBLIC_FOLDER_NAME</code> to find the project root. ' .
        'It strips that name from the document root path to locate the sibling <code>secure/</code> folder.</p>' .
        '<ul style="margin-bottom:0">' .
        '<li>Your document root is: <code>' . $detectedRoot . '</code></li>' .
        '<li><code>PUBLIC_FOLDER_NAME</code> is set to: <code>' . $publicName . '</code></li>' .
        '<li>Your actual folder name is: <code>' . htmlspecialchars($actualFolder) . '</code></li>' .
        '</ul>' .
        '<p style="margin-top:0.8em">Because <code>' . $publicName . '</code> does not match <code>' . htmlspecialchars($actualFolder) . '</code>, ' .
        'QuickSite cannot find its way back to the project root and looks for <code>secure/</code> in the wrong place: ' .
        '<code>' . $computedSecure . '</code></p>' .
        '</div>' .

        '<div class="fix"><h3>How to fix</h3>' .
        '<p>Open <code>' . htmlspecialchars($actualFolder) . '/init.php</code> and change line 9:</p>' .
        '<pre>define(\'PUBLIC_FOLDER_NAME\', \'' . $publicName . '\');  // wrong' . "\n" .
        '// change to:' . "\n" .
        'define(\'PUBLIC_FOLDER_NAME\', \'' . htmlspecialchars($actualFolder) . '\');  // correct</pre>' .
        '<p>Then refresh this page.</p></div>' .

        '<div class="fix"><h3>Alternatively, use the setup script</h3>' .
        '<p>The setup scripts handle this automatically:</p>' .
        '<pre># Linux / macOS' . "\n" .
        './setup.sh ' . htmlspecialchars($actualFolder) . "\n\n" .
        '# Windows' . "\n" .
        'setup.bat ' . htmlspecialchars($actualFolder) . '</pre></div>' .

        '<hr><p><small>Once fixed, QuickSite will create config files automatically on the next page load.</small></p>' .
        '</body></html>'
    );
}

// ============================================================================
// SURFACE B: the /p/<projectId>/ gate  (C9)
// ============================================================================
// Runs HERE, and only for the /p/ entry point. Here because SECURE_FOLDER_PATH is
// resolved by now — so surfaceB.php is found whatever the folders were renamed to, and
// however deep a URL space nests the install — while BASE_URL is not derived until far
// below, and the gate defines its own project-scoped BASE_URL that must win. It also sits
// ahead of the first-install and nginx blocks below, so a REFUSED request still writes
// nothing: everything above this line is constant definitions and one is_dir() check.
//
// The guard is the ENTRY POINT, never the URL: '/management/p/<projectId>/<command>' also
// carries a '/p/' segment, and gating on that would refuse every project-scoped API call.
if (defined('QS_SURFACE_B_ENTRY')) {
    // Fatal hygiene, registered BEFORE the gate runs. The other three
    // dispatchers register their own shape at the top of their own entry file;
    // surface B could not, because public/p/index.php cannot name secure/ until
    // this file has resolved SECURE_FOLDER_PATH. So it registers here, at the
    // first line of surface B that is allowed to load engine code — which also
    // puts the visibility gate itself inside the handler's reach.
    //
    // Without it, a fatal anywhere in a project render answered HTTP 200 with
    // PHP's own error text in the body — absolute server path included — to
    // whoever asked, and the only surface facing anonymous internet visitors
    // was the only one that did. HTML shape: a rendered site cannot answer with
    // a JSON envelope.
    //
    // Two separate defects, closed by the same call: the status (200 → 500),
    // which is wrong on every deployment regardless of php.ini, and the
    // disclosure, which qs_register_fatal_handler() also closes by forcing
    // display_errors off on a production install.
    require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php';
    qs_register_fatal_handler(QS_FATAL_SHAPE_HTML);

    require_once SECURE_FOLDER_PATH . '/src/functions/surfaceB.php';
    qs_surface_b_maybe_handle();
}

// ============================================================================
// FIRST-INSTALL: Auto-create config files from .example templates
// ============================================================================
$configDir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'management' . DIRECTORY_SEPARATOR . 'config';
foreach (['auth.php', 'roles.php'] as $configFile) {
    $configFilePath = $configDir . DIRECTORY_SEPARATOR . $configFile;
    $examplePath = $configFilePath . '.example';
    if (!file_exists($configFilePath) && file_exists($examplePath)) {
        copy($examplePath, $configFilePath);
    }
}

// ============================================================================
// FIRST-INSTALL: Auto-generate nginx config if not present
// ============================================================================
$nginxConfigPath = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'nginx' . DIRECTORY_SEPARATOR . 'dynamic_routes.conf';
$nginxSetupPending = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'nginx' . DIRECTORY_SEPARATOR . '.setup_pending';
if (!file_exists($nginxConfigPath)) {
    require_once SECURE_FOLDER_PATH . '/src/functions/NginxConfig.php';
    $nginxResult = write_nginx_dynamic_routes(PUBLIC_FOLDER_SPACE, SECURE_FOLDER_PATH);

    // Create setup_pending flag so the instructions page keeps showing
    if ($nginxResult['success']) {
        @file_put_contents($nginxSetupPending, date('Y-m-d H:i:s'));
    }
}

// Detect nginx — show setup instructions until user confirms completion
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
if (file_exists($nginxSetupPending) && stripos($serverSoftware, 'nginx') !== false) {
    // If the user submitted the confirmation form, remove the flag and continue
    if (isset($_POST['nginx_setup_done'])) {
        @unlink($nginxSetupPending);
    } else {
        $cfgPath = htmlspecialchars(SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'nginx' . DIRECTORY_SEPARATOR . 'dynamic_routes.conf');
        $pubFolder = htmlspecialchars(PUBLIC_FOLDER_NAME);
        // The URL prefix the install serves under — the named location's
        // SCRIPT_FILENAME has to carry it, or a space install posts every project
        // request at an entry point that is not there.
        $nginxPrefix = PUBLIC_FOLDER_SPACE !== '' ? '/' . trim(PUBLIC_FOLDER_SPACE, '/') : '';
        $entryPoint = htmlspecialchars('$document_root' . $nginxPrefix . '/p/index.php');
        // nginx caps a request body at 1 MB by default — under what PHP here
        // accepts — and refuses the excess with its own HTML 413 before PHP
        // runs. The generated include carries the directive for the namespace
        // uploads use; a proxying PUBLIC block terminates the connection
        // itself, so it needs its own copy, which is why the number is printed
        // here too. Derived from this server's post_max_size, never written down.
        require_once SECURE_FOLDER_PATH . '/src/functions/uploadLimits.php';
        $bodySize = htmlspecialchars(qs_nginx_client_max_body_size());
        http_response_code(503);
        die(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' .
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">' .
            '<title>QuickSite - nginx Setup Required</title>' .
            '<style>' .
            'body{font-family:system-ui,-apple-system,sans-serif;max-width:720px;margin:60px auto;line-height:1.6;padding:0 20px;color:#333;background:#fafafa}' .
            'h1{color:#1a1a2e;margin-bottom:0.3em}' .
            '.subtitle{color:#666;margin-top:0;font-size:0.95em}' .
            'code{background:#e8eaf0;padding:2px 6px;border-radius:3px;font-size:0.9em}' .
            'pre{background:#1e1e2e;color:#cdd6f4;padding:16px 20px;border-radius:8px;overflow-x:auto;font-size:0.88em;line-height:1.5}' .
            '.step{margin:20px 0;padding:16px 20px;background:#fff;border-left:4px solid #4a9eff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.08)}' .
            '.step h3{margin-top:0;color:#1a1a2e}' .
            '.hint{font-size:0.9em;color:#666;margin-bottom:0}' .
            // "You must replace this" — highlighted rather than left to be spotted
            // in a block of monospace. Both placeholders use the same token shape
            // AND the same highlight, so one glance finds every hole to fill.
            '.fill{background:#ffe08a;color:#5a4100;font-weight:700;padding:1px 5px;border-radius:3px;border-bottom:2px solid #d99a00}' .
            '.note{margin-top:30px;padding:14px 18px;background:#fff8e1;border-left:4px solid #f9a825;border-radius:4px}' .
            '.note strong{color:#e65100}' .
            '.done-btn{display:inline-block;margin-top:12px;padding:12px 28px;background:#4caf50;color:#fff;border:none;border-radius:6px;font-size:1em;font-weight:600;cursor:pointer}' .
            '.done-btn:hover{background:#43a047}' .
            'hr{border:none;border-top:1px solid #ddd;margin:30px 0}' .
            'small{color:#888}' .
            '</style></head><body>' .
            '<h1>QuickSite &mdash; nginx Setup Required</h1>' .
            '<p class="subtitle">This page will keep showing until you confirm the setup is complete.</p>' .
            '<p>You are running <strong>nginx</strong>, which does not support <code>.htaccess</code> files. ' .
            'QuickSite has generated a routing configuration file for you, but it needs to be included in your nginx server block.</p>' .

            '<div class="step"><h3>Step 1 &mdash; Include the routing config</h3>' .
            '<p>Add this line inside your nginx <code>server { }</code> block (in your vhost config, or CloudPanel site settings &rarr; Vhost):</p>' .
            '<pre>include ' . $cfgPath . ';</pre>' .
            '<p class="hint">On CloudPanel the usual spot is right after the existing ' .
            '<code>include /etc/nginx/global_settings;</code> line. That is one example, not a requirement &mdash; ' .
            'anywhere inside <code>server { }</code> works.</p>' .
            '<p class="hint"><strong>Uploads are in there too.</strong> nginx allows a 1 MB request body ' .
            'by default &mdash; less than the ' . htmlspecialchars(ini_get('post_max_size')) . ' PHP accepts on this server &mdash; and it ' .
            'refuses the rest with its own HTML error page before PHP runs, so QuickSite ' .
            'never gets to explain. The generated file carries ' .
            '<code>client_max_body_size ' . $bodySize . ';</code> on the upload endpoint, computed from ' .
            'that PHP setting. Nothing to do here unless you have two server blocks &mdash; see below.</p></div>' .

            '<div class="step"><h3>Step 2 &mdash; Add the project handler</h3>' .
            '<p>Also inside <code>server { }</code>, add this block. QuickSite cannot generate it, ' .
            'because only your server knows its PHP upstream:</p>' .
            '<p class="hint"><strong>Where to put it:</strong> right next to the ' .
            '<code>location ~ \.php$</code> block that is already in that same ' .
            '<code>server { }</code>. Order does not matter to nginx &mdash; keeping them together ' .
            'just means the line you copy from is next to the line you paste into.</p>' .
            '<pre>location @quicksite_project {' . "\n" .
            '    include        fastcgi_params;' . "\n" .
            '    fastcgi_param  SCRIPT_FILENAME ' . $entryPoint . ';' . "\n" .
            '    fastcgi_pass   <span class="fill">COPY_THIS_FROM_YOUR_OWN_php_BLOCK</span>;' . "\n" .
            '}</pre>' .
            '<p class="hint"><strong>Where to find that last value:</strong> search this same vhost ' .
            'for the word <code>fastcgi_pass</code>. It is already there &mdash; your ' .
            '<code>location ~ \.php$</code> block cannot work without it. Copy that whole line. ' .
            'It looks like <code>unix:/run/php/php8.3-fpm.sock;</code> or ' .
            '<code>127.0.0.1:9000;</code>. (<code>127.0.0.1</code> is loopback, this machine ' .
            'talking to itself &mdash; it exposes nothing, and php-fpm is already listening there ' .
            'for every other PHP request on this site.)</p>' .
            '<p class="hint"><strong>Both steps are required.</strong> Your pages would render without ' .
            'this block, but every stylesheet, script and image would fail &mdash; and once the routing ' .
            'config is included, project URLs answer <code>500</code> until it exists, with ' .
            '<code>could not find named location</code> in your nginx error log.</p>' .
            '<p class="hint">Leave <code>SCRIPT_FILENAME</code> exactly as printed. It is a fixed path on ' .
            'purpose: nothing from the request goes into it, so a URL like ' .
            '<code>/photo.jpg/x.php</code> has no way to make PHP execute the wrong file.</p></div>' .

            '<div class="note">' .
            '<strong>Two server blocks? (CloudPanel and similar)</strong>' .
            '<p style="margin-bottom:0.5em">Some panels generate <em>two</em> <code>server { }</code> blocks: a public one ' .
            'on 443 that proxies to a backend one on 8080, with the static-asset rule ' .
            '(<code>location ~* \.(css|js|png…)$</code>) in the <strong>public</strong> block. ' .
            'The include above goes in the backend block &mdash; so a project stylesheet is ' .
            'answered from disk by the public block and never proxied at all. Pages render, ' .
            'assets 404.</p>' .
            '<p style="margin-bottom:0.5em">If that is your layout, add this to the <strong>public</strong> block too &mdash; ' .
            'the one with the <code>listen 443</code> lines, right after its own ' .
            '<code>location / { }</code>. Order does not matter; keeping them adjacent just puts ' .
            'the line you copy next to the line you paste into.</p>' .
            '<p style="margin-bottom:0.5em">Do not invent a target &mdash; copy the <code>proxy_pass</code> line out of that ' .
            'block\'s own <code>location / { }</code>, whatever it says. On CloudPanel that is ' .
            'literally the line <code>{{varnish_proxy_pass}}</code>; copy it verbatim so the panel ' .
            'keeps filling it in for you:</p>' .
            '<pre>location ^~ ' . htmlspecialchars($nginxPrefix) . '/p/ {' . "\n" .
            '    <span class="fill">COPY_THE_proxy_pass_LINE_FROM_YOUR_location_slash_BLOCK</span>;' . "\n" .
            '    proxy_set_header Host $host;' . "\n" .
            '}</pre>' .
            '<p style="margin-bottom:0.5em">This sends project URLs down the path every other request ' .
            'already takes. It opens nothing: <code>proxy_pass</code> dials out, it does not listen, ' .
            'and the backend block is already listening either way.</p>' .
            '<p style="margin-bottom:0.5em"><strong>And add the upload size to that same public block</strong> ' .
            '&mdash; it is the block that receives the client\'s bytes, so its own 1 MB default ' .
            'rejects a large upload before the backend ever sees it:</p>' .
            '<pre>client_max_body_size ' . $bodySize . ';</pre>' .
            '<p style="margin-bottom:0">One server block only? Ignore this &mdash; steps 1 and 2 are all you need.</p>' .
            '</div>' .

            '<div class="step"><h3>Step 3 &mdash; Test and reload nginx</h3>' .
            '<pre>sudo nginx -t &amp;&amp; sudo nginx -s reload</pre>' .
            '<p>On CloudPanel or similar panels, you may need to restart nginx from the panel UI instead.</p></div>' .

            '<div class="step"><h3>Step 4 &mdash; Confirm setup</h3>' .
            '<p>Once both blocks are in place and nginx is reloaded, click the button below:</p>' .
            '<form method="post"><button type="submit" name="nginx_setup_done" value="1" class="done-btn">I have completed the nginx setup</button></form></div>' .

            '<div class="note">' .
            '<strong>Renamed the public folder?</strong> If you renamed <code>public/</code> to something else ' .
            '(e.g. <code>www</code> or <code>public_html</code>), make sure <code>PUBLIC_FOLDER_NAME</code> ' .
            'matches your folder name in <code>' . $pubFolder . '/init.php</code> (line 9).' .
            '</div>' .

            '<hr>' .
            '<p><small>Generated config: <code>' . $cfgPath . '</code></small></p>' .
            '<p><small>Server: ' . htmlspecialchars($serverSoftware) . '</small></p>' .
            '</body></html>'
        );
    }
}

// ============================================================================
// PROJECT CONTEXT - bound per request, by whoever knows the project
// ============================================================================
// There is no installation-wide "current project". Nothing here reads a pointer:
// each entry point calls qs_load_project_context() itself with the project the
// request actually targets, AFTER validating it (F1) and checking membership.
//
//   - public/p/index.php           the project peeled from /p/<projectId>/
//   - public/management/index.php  the projectId peeled from the URL marker
//   - public/admin/api/index.php   the projectId peeled from the URL marker
//   - public/admin/index.php       the caller's own EDITED project (selected_project)
//
// This file only makes the loader reachable from all four.
require_once SECURE_FOLDER_PATH . '/src/functions/projectContext.php';

// BASE_URL = where this INSTALL (panel + management API) is. C15 15.4 (R6): derived
// through qs_request_origin() — validated Host, optional QS_TRUSTED_HOSTS pin — never
// the raw attacker-controllable header. The PUBLIC base a rendered project's links
// compose against is a SEPARATE render-scoped value (renderBootstrap.php).
if (!defined('BASE_URL')) {
    $origin = qs_request_origin();
    if (PUBLIC_FOLDER_SPACE !== '') {
        define('BASE_URL', $origin . '/' . PUBLIC_FOLDER_SPACE . '/');
    } else {
        define('BASE_URL', $origin . '/');
    }
}

// ============================================================================
// GLOBAL TEMPLATE HELPERS
// ============================================================================
// Function-definition-only files that templates may call without an
// explicit require. The file uses function_exists guards so dispatch-
// path code can also require_once it without double-declare; loading
// here just makes the helpers reachable from any page template.
//
// Beta.9 A1 Slice 2e: isOAuthLoggedIn() / getOAuthUser() — templates
// use these for "Sign in" vs "Welcome, <name>" conditional renders.
require_once SECURE_FOLDER_PATH . '/src/functions/oauthStateStore.php';

// ============================================================================
// SECURITY HEADERS - Applied to all PHP-served responses
// ============================================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
