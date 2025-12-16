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

if(!defined('CONFIG_PATH')){
    define('CONFIG_PATH', SERVER_ROOT . DIRECTORY_SEPARATOR . SECURE_FOLDER_NAME . DIRECTORY_SEPARATOR.'config.php');
}
if (!file_exists(CONFIG_PATH)) {
    // Handle error: configuration file not found
    http_response_code(500);
    die("Configuration file not found at: " . htmlspecialchars(CONFIG_PATH));
}
if(!defined('CONFIG')){
    define('CONFIG', require CONFIG_PATH);
}

if(!defined('MULTILINGUAL_SUPPORT')){
    define('MULTILINGUAL_SUPPORT', CONFIG['MULTILINGUAL_SUPPORT']);
}

if(!defined('ROUTES_PATH')){
    define('ROUTES_PATH', SERVER_ROOT . DIRECTORY_SEPARATOR . SECURE_FOLDER_NAME . DIRECTORY_SEPARATOR.'routes.php');
}
if (!file_exists(ROUTES_PATH)) {
    // Handle error: routes file not found
    http_response_code(500);
    die("Routes file not found at: " . htmlspecialchars(ROUTES_PATH));
}
if(!defined('ROUTES')){
    define('ROUTES', require ROUTES_PATH);
}

if (!defined('SECURE_FOLDER_PATH')) {
    // This value comes directly from the config file, ensuring it's correct
    define('SECURE_FOLDER_PATH', SERVER_ROOT . DIRECTORY_SEPARATOR . SECURE_FOLDER_NAME);
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
