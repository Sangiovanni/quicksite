<?php
//header('Content-Type: application/json');
require_once '../init.php';

if(!defined('ROUTES_MANAGEMENT_PATH')){
    define('ROUTES_MANAGEMENT_PATH', SERVER_ROOT . '/' . SECURE_FOLDER_NAME . '/management/routes.php');
}
if (!file_exists(ROUTES_MANAGEMENT_PATH)) {
    // Handle error: routes file not found
    http_response_code(500);
    die("Routes management file not found at: " . htmlspecialchars(ROUTES_MANAGEMENT_PATH));
}
if(!defined('ROUTES_MANAGEMENT')){
    define('ROUTES_MANAGEMENT', require ROUTES_MANAGEMENT_PATH);
}

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
$trimParametersManagement = new TrimParametersManagement();

if(in_array($trimParametersManagement->command(), ROUTES_MANAGEMENT)){
    $command = $trimParametersManagement->command();
} else {
    $command = '404';
}

if($command == "404"){
    http_response_code(404);
    die("Command not found: " . htmlspecialchars($trimParametersManagement->command()));
}

require_once SECURE_FOLDER_PATH . '/management/command/'. $command .'.php';

