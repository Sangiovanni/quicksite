<?php
require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

// ============================================================================
// CORS Handling - Must be before any output
// ============================================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    handlePreflightRequest();
    // Exits here if OPTIONS
}

// Handle CORS for actual requests
if ($origin) {
    $corsAllowed = handleCors($origin);
    if (!$corsAllowed) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 403,
            'code' => 'cors.origin_not_allowed',
            'message' => 'Origin not allowed by CORS policy',
            'origin' => $origin,
            'hint' => 'Add this origin to allowed_origins in ' . SECURE_FOLDER_NAME . '/config/auth.php'
        ]);
        exit;
    }
}

// ============================================================================
// Authentication
// ============================================================================
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

// Apache sometimes puts it in a different place
if (!$authHeader && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
}

$authResult = validateBearerToken($authHeader);

if (!$authResult['valid']) {
    sendUnauthorizedResponse(
        $authResult['error'],
        'Include header: Authorization: Bearer <your-token>'
    );
}

$currentTokenInfo = $authResult['token_info'];

// ============================================================================
// Route Management Setup
// ============================================================================
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

// ============================================================================
// Permission Check
// ============================================================================
if (!hasPermission($currentTokenInfo, $command)) {
    sendForbiddenResponse($command);
}

// ============================================================================
// Execute Command
// ============================================================================
require_once SECURE_FOLDER_PATH . '/management/command/'. $command .'.php';
