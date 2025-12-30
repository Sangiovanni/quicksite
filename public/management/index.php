<?php
require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LoggingManagement.php';

// Track execution start time for logging
$commandStartTime = microtime(true);

// ============================================================================
// Fatal Error Handler - Catches parse errors and other fatal errors
// ============================================================================
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clear any output that was sent
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Set proper error status
        http_response_code(500);
        header('Content-Type: application/json');
        
        // Build error response
        $errorResponse = [
            'status' => 500,
            'code' => 'server.internal_error',
            'message' => 'A fatal error occurred while processing the request',
            'data' => null
        ];
        
        // In development, include error details
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            $errorResponse['debug'] = [
                'type' => match($error['type']) {
                    E_ERROR => 'E_ERROR',
                    E_PARSE => 'E_PARSE',
                    E_CORE_ERROR => 'E_CORE_ERROR',
                    E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                    default => 'UNKNOWN'
                },
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ];
        }
        
        echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
});

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
            'hint' => 'Add this origin to allowed_origins in ' . SECURE_FOLDER_NAME . '/management/config/auth.php'
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

// Capture request body FIRST (before TrimParametersManagement consumes php://input)
$rawRequestBody = file_get_contents('php://input');
define('REQUEST_BODY_RAW', $rawRequestBody);

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

// Parse request body for logging
$requestBody = json_decode(REQUEST_BODY_RAW, true) ?? [];

// Set up logging callback
ApiResponse::setBeforeSendCallback(function($status, $responseCode) use ($command, $currentTokenInfo, $commandStartTime, $requestBody) {
    logCommand(
        $command,
        $_SERVER['REQUEST_METHOD'],
        $requestBody,
        $currentTokenInfo,
        $status,
        $responseCode,
        $commandStartTime
    );
});

require_once SECURE_FOLDER_PATH . '/management/command/'. $command .'.php';
