<?php
// C7 — defer project context. A management request must NOT inherit PROJECT_PATH
// from the global target.php: the action's project is the per-request projectId
// peeled from the URL, resolved + validated + membership-checked below, then bound
// via qs_load_project_context(). init.php still defines all the GLOBAL constants.
define('QS_DEFER_PROJECT_CONTEXT', true);

// C9 per-user editing — for a project-scoped request targeting a NON-reserved project
// (/management/p/<id>/<cmd>), bind the live public/ to THAT project's own public/ BEFORE
// init defines the base, so every command's PUBLIC_CONTENT_PATH read/write self-scopes to
// the edited project (style/assets/scripts/sitemap). The reserved base 'quicksite' + global
// commands keep the base public (D1/D2). PROJECT_PATH itself is still bound later, after F1
// + membership, by qs_load_project_context — this only pre-points the public dir so the many
// commands that read/write PUBLIC_CONTENT_PATH don't all need per-file re-scoping.
if (!defined('PUBLIC_CONTENT_PATH')) {
    $__c9secure = dirname(__DIR__, 2) . '/secure';
    require_once $__c9secure . '/src/functions/projectPublicArtifacts.php';
    $__c9served = qs_served_project($__c9secure);                          // dynamic base = target.php
    $__c9segs = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), fn($s) => $s !== ''));
    for ($__c9i = 0, $__c9n = count($__c9segs); $__c9i < $__c9n - 2; $__c9i++) {
        if ($__c9segs[$__c9i] === 'management' && $__c9segs[$__c9i + 1] === 'p') {
            $__c9pid = rawurldecode($__c9segs[$__c9i + 2]);
            if ($__c9pid !== $__c9served                                    // the SERVED project keeps the base public
                && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $__c9pid)          // F1 shape
                && is_dir($__c9secure . '/projects/' . $__c9pid)) {
                define('PUBLIC_CONTENT_PATH', $__c9secure . '/projects/' . $__c9pid . '/public');
            }
            break;
        }
    }
}

require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LoggingManagement.php';

// Prevent browsers from caching ANY API response (including 401/404/error responses).
// Must be set before any output and before any early exit (auth failure, public command, etc.).
header('Cache-Control: no-store');

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
// Public Routes (no authentication required)
// ============================================================================
$PUBLIC_COMMANDS = ['help'];

// Parse the command early to check if it's public
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$uriPath = parse_url($requestUri, PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $uriPath)));
// Command is the segment after "management"
$earlyCommand = null;
foreach ($segments as $i => $seg) {
    if ($seg === 'management' && isset($segments[$i + 1])) {
        // C7 — skip the optional project marker '/management/p/<projectId>/<command>'
        // so a public command (help) is recognised whether or not it carries one.
        if ($segments[$i + 1] === 'p' && isset($segments[$i + 3])) {
            $earlyCommand = $segments[$i + 3];
        } else {
            $earlyCommand = $segments[$i + 1];
        }
        break;
    }
}

if ($earlyCommand && in_array($earlyCommand, $PUBLIC_COMMANDS, true)) {
    // Set up TrimParametersManagement so the command can read URL segments
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParametersManagement = new TrimParametersManagement();
    // Skip auth entirely — execute the public command directly
    require_once SECURE_FOLDER_PATH . '/management/command/' . $earlyCommand . '.php';
    exit;
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

$currentUser = $authResult['user'];

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
// Per-request project scoping + permission check (C7)
// ============================================================================
// The action's project comes from the URL ('/management/p/<projectId>/<command>'),
// NEVER from selected_project. A project-scoped command is validated as an F1 path
// input, then authorized against the project's AUTHORITATIVE members.json (L5)
// before the command runs. Global commands do not authorize against a project.
$requestedProject = $trimParametersManagement->project();
$commandCategory  = getCommandCategory($command);
$categoriesConfig = loadCategoriesConfig();
$commandScope     = $categoriesConfig[$commandCategory]['scope'] ?? 'project';

if ($commandScope === 'project') {
    // A project-scoped command MUST target a project.
    if ($requestedProject === null || $requestedProject === '') {
        ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/' . $command)
            ->send();
    }
    // F1 — the projectId is request-controlled and becomes a directory selector.
    if (!is_valid_project_name($requestedProject)) {
        ApiResponse::create(400, 'project.invalid')
            ->withMessage('Invalid project identifier')
            ->send();
    }
    // Membership + role, one authoritative check. A non-member, a stranger's
    // projectId, a non-existent project, and an under-privileged member ALL yield
    // the same 403 — no oracle for existence, membership, or role level.
    if (!hasPermission($currentUser, $command, $requestedProject)) {
        sendForbiddenResponse($command);
    }
    // Authorized member only: bind PROJECT_PATH to their project for the command.
    qs_load_project_context($requestedProject, true);
} else {
    // Global command: authz is project-independent. Give it a benign working
    // context from the caller's UX-default project (tolerant — a zero-membership
    // user still gets a defined, empty context; never dies, never leaks). This is
    // NOT an authz input — global access is decided by the category's access rule.
    qs_load_project_context(resolveDefaultProject($currentUser) ?? '', false);
    if (!hasPermission($currentUser, $command, null)) {
        sendForbiddenResponse($command);
    }
}

// ============================================================================
// Execute Command
// ============================================================================

// Parse request body for logging
$requestBody = json_decode(REQUEST_BODY_RAW, true) ?? [];

// Set up logging callback
ApiResponse::setBeforeSendCallback(function($status, $responseCode) use ($command, $currentUser, $commandStartTime, $requestBody) {
    logCommand(
        $command,
        $_SERVER['REQUEST_METHOD'],
        $requestBody,
        $currentUser,
        $status,
        $responseCode,
        $commandStartTime
    );
});

require_once SECURE_FOLDER_PATH . '/management/command/'. $command .'.php';
