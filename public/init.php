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

if(!defined('SERVER_ROOT')){
    // Remove only the rightmost occurrence of PUBLIC_FOLDER_NAME from path
    // This prevents issues when folder name appears multiple times in path
    // e.g., C:/wamp64/www/mysite/www -> C:/wamp64/www/mysite/ (not C:/wamp64//mysite/)
    $folderPattern = '/' . preg_quote(PUBLIC_FOLDER_NAME, '/') . '[\\\\\\/]?$/';
    define('SERVER_ROOT', preg_replace($folderPattern, '', PUBLIC_FOLDER_ROOT));
}

if (!defined('SECURE_FOLDER_PATH')) {
    // SECURE_FOLDER_PATH = engine files (admin, management, src)
    define('SECURE_FOLDER_PATH', SERVER_ROOT . DIRECTORY_SEPARATOR . SECURE_FOLDER_NAME);
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
