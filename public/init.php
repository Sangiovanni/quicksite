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

// PUBLIC_CONTENT_PATH = the live directory where site content lives (style/, assets/, scripts/)
// When a space is defined (e.g. 'quicksite'), content is at PUBLIC_FOLDER_ROOT/quicksite/
// When no space is used, content is at PUBLIC_FOLDER_ROOT directly
if (!defined('PUBLIC_CONTENT_PATH')) {
    define('PUBLIC_CONTENT_PATH', PUBLIC_FOLDER_SPACE !== '' 
        ? PUBLIC_FOLDER_ROOT . '/' . PUBLIC_FOLDER_SPACE 
        : PUBLIC_FOLDER_ROOT);
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
// FIRST-INSTALL: Auto-create config files from .example templates
// ============================================================================
$configDir = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'management' . DIRECTORY_SEPARATOR . 'config';
foreach (['target.php', 'auth.php', 'roles.php'] as $configFile) {
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
            '<p>Add this line inside your nginx <code>server { }</code> block (e.g. in your vhost config or CloudPanel site settings):</p>' .
            '<pre>include ' . $cfgPath . ';</pre></div>' .

            '<div class="step"><h3>Step 2 &mdash; Test and reload nginx</h3>' .
            '<pre>sudo nginx -t &amp;&amp; sudo nginx -s reload</pre>' .
            '<p>On CloudPanel or similar panels, you may need to restart nginx from the panel UI instead.</p></div>' .

            '<div class="step"><h3>Step 3 &mdash; Confirm setup</h3>' .
            '<p>Once you have added the include directive and reloaded nginx, click the button below:</p>' .
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
// PROJECT PATH - Points to the active project in secure/projects/{name}/
// ============================================================================
if (!defined('PROJECT_PATH')) {
    $targetConfigPath = SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'management' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'target.php';
    if (file_exists($targetConfigPath)) {
        $targetConfig = require $targetConfigPath;
        $projectName = $targetConfig['project'] ?? 'quicksite';
        define('PROJECT_PATH', SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . $projectName);
        define('PROJECT_NAME', $projectName);
    } else {
        // target.php is required for multi-project architecture
        http_response_code(500);
        die(
            "<h1>QuickSite Installation Error</h1>" .
            "<p><strong>Missing file:</strong> <code>" . htmlspecialchars($targetConfigPath) . "</code></p>" .
            "<p><strong>What this means:</strong> The management configuration file <code>target.php</code> is missing. " .
            "This file tells QuickSite which project to load.</p>" .
            "<p><strong>Expected structure:</strong><br><code>" . htmlspecialchars(SECURE_FOLDER_PATH) . "/management/config/target.php</code></p>" .
            "<p><strong>Possible causes:</strong></p>" .
            "<ul>" .
            "<li>Incomplete installation - the <code>" . SECURE_FOLDER_NAME . "/management/config/</code> folder was not properly set up</li>" .
            "<li>File was accidentally deleted</li>" .
            "<li>If paths look wrong, check <code>PUBLIC_FOLDER_NAME</code> and <code>SECURE_FOLDER_NAME</code> constants in <code>" . PUBLIC_FOLDER_NAME . "/init.php</code></li>" .
            "</ul>"
        );
    }
}

if(!defined('CONFIG_PATH')){
    define('CONFIG_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'config.php');
}
if (!file_exists(CONFIG_PATH)) {
    http_response_code(500);
    die(
        "<h1>QuickSite Project Error</h1>" .
        "<p><strong>Missing file:</strong> <code>" . htmlspecialchars(CONFIG_PATH) . "</code></p>" .
        "<p><strong>Active project:</strong> <code>" . htmlspecialchars(PROJECT_NAME) . "</code></p>" .
        "<p><strong>What this means:</strong> The project configuration file <code>config.php</code> is missing for project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>'.</p>" .
        "<p><strong>Expected structure:</strong><br><code>" . htmlspecialchars(SECURE_FOLDER_PATH) . "/projects/" . htmlspecialchars(PROJECT_NAME) . "/config.php</code></p>" .
        "<p><strong>Possible causes:</strong></p>" .
        "<ul>" .
        "<li>Project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>' does not exist in <code>" . SECURE_FOLDER_NAME . "/projects/</code></li>" .
        "<li>Project folder exists but <code>config.php</code> was deleted</li>" .
        "<li>Wrong project name in <code>" . SECURE_FOLDER_NAME . "/management/config/target.php</code></li>" .
        "<li>If paths look wrong, check constants in <code>" . PUBLIC_FOLDER_NAME . "/init.php</code></li>" .
        "</ul>"
    );
}
if(!defined('CONFIG')){
    define('CONFIG', require CONFIG_PATH);
}

if(!defined('MULTILINGUAL_SUPPORT')){
    define('MULTILINGUAL_SUPPORT', CONFIG['MULTILINGUAL_SUPPORT']);
}

if(!defined('ROUTES_PATH')){
    define('ROUTES_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'routes.php');
}
if (!file_exists(ROUTES_PATH)) {
    http_response_code(500);
    die(
        "<h1>QuickSite Project Error</h1>" .
        "<p><strong>Missing file:</strong> <code>" . htmlspecialchars(ROUTES_PATH) . "</code></p>" .
        "<p><strong>Active project:</strong> <code>" . htmlspecialchars(PROJECT_NAME) . "</code></p>" .
        "<p><strong>What this means:</strong> The routes definition file <code>routes.php</code> is missing for project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>'.</p>" .
        "<p><strong>Expected structure:</strong><br><code>" . htmlspecialchars(SECURE_FOLDER_PATH) . "/projects/" . htmlspecialchars(PROJECT_NAME) . "/routes.php</code></p>" .
        "<p><strong>Possible causes:</strong></p>" .
        "<ul>" .
        "<li>Project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>' is incomplete - missing <code>routes.php</code></li>" .
        "<li>File was accidentally deleted</li>" .
        "<li>Corrupted project - try recreating with <code>createProject</code> API command</li>" .
        "<li>If paths look wrong, check constants in <code>" . PUBLIC_FOLDER_NAME . "/init.php</code></li>" .
        "</ul>"
    );
}
if(!defined('ROUTES')){
    define('ROUTES', require ROUTES_PATH);
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    if(PUBLIC_FOLDER_SPACE !== ''){
        $host .= '/' . PUBLIC_FOLDER_SPACE . '/';
    }
    else{
        $host .= '/';
    }
    define('BASE_URL', $protocol . $host);
}

// ============================================================================
// SECURITY HEADERS - Applied to all PHP-served responses
// ============================================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
