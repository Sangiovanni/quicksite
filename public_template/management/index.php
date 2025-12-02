<?php
require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

if(!defined('ROUTES_MANAGEMENT_PATH')){
    define('ROUTES_MANAGEMENT_PATH', SERVER_ROOT . '/' . SECURE_FOLDER_NAME . '/management/routes.php');
}
if (!file_exists(ROUTES_MANAGEMENT_PATH)) {
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Routes management file not found")
        ->withData([
            'expected_path' => ROUTES_MANAGEMENT_PATH
        ])
        ->send();
}
if(!defined('ROUTES_MANAGEMENT')){
    define('ROUTES_MANAGEMENT', require ROUTES_MANAGEMENT_PATH);
}

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
$trimParametersManagement = new TrimParametersManagement();

if(in_array($trimParametersManagement->command(), ROUTES_MANAGEMENT)){
    $command = $trimParametersManagement->command();
} else {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Command not found")
        ->withData([
            'requested_command' => $trimParametersManagement->command(),
            'available_commands' => ROUTES_MANAGEMENT
        ])
        ->send();
}

require_once SECURE_FOLDER_PATH . '/management/command/'. $command .'.php';

