<?php
// C7 — the action's project is the per-request projectId peeled from the URL, resolved
// + validated + membership-checked below, then bound via qs_load_project_context() — which
// binds PROJECT_PATH *and* PUBLIC_CONTENT_PATH together (C15 15.3). Nothing binds a project
// before that point, so this file no longer needs to pre-empt init.php with an early
// PUBLIC_CONTENT_PATH override: init.php defines only the install-wide constants.

require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LoggingManagement.php';
// Loaded HERE, not inside the shutdown handler below: by the time that handler
// runs a fatal has already happened, and a require issued from inside it would
// be one more thing that can fail while we are trying to report a failure.
require_once SECURE_FOLDER_PATH . '/src/functions/environment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php';

// Prevent browsers from caching ANY API response (including 401/404/error responses).
// Must be set before any output and before any early exit (auth failure, public command, etc.).
header('Cache-Control: no-store');

// Track execution start time for logging
$commandStartTime = microtime(true);

// ============================================================================
// Fatal Error Handler - Catches parse errors and other fatal errors
// ============================================================================
// C12: this was an inline copy. It now shares one implementation with the
// admin-api dispatcher, which had NO fatal handling at all and answered the
// same fatal with HTTP 200 and an absolute filesystem path. Two copies of the
// same decision is the drift shape C11 spent a slice removing; the debug block
// is also gated in exactly one place now.
qs_register_fatal_handler(QS_FATAL_SHAPE_ENVELOPE);

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
// `login` is public because it is SELF-authenticating: the credentials in its
// body ARE the authentication, and it is what CREATES the session every other
// command needs. `register` is public and SELF-gating (the registration flag +
// flood controls are enforced inside the command; default = disabled).
//
// `logoutSession` is NOT here: with the PHP-session model there is no refresh
// token to present, so ending a session means proving you hold it — which is
// exactly what the normal authenticated path checks.
$PUBLIC_COMMANDS = ['help', 'login', 'register'];

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
    // Both halves are required — the session cookie AND the session token in
    // the header. A caller missing either is told the same thing: sign in.
    sendUnauthorizedResponse(
        $authResult['error'],
        'Sign in with the login command, then send its session cookie plus the header: Authorization: Bearer <session_token>',
        $authResult['code'] ?? 'auth.unauthorized'
    );
}

$currentUser = $authResult['user'];

// ============================================================================
// Did PHP throw this request's body away? (S2.5)
// ============================================================================
// A body over `post_max_size` never reaches the command: PHP empties $_POST and
// $_FILES and says nothing, so every command downstream sees a request with no
// parameters and answers whatever "you sent nothing" looks like to it. On
// uploadAsset that was "No file source provided. Upload a file or provide a url
// parameter." — a sentence that is simply false about a request that carried a
// file, and that sends the user looking for a bug in their own form.
//
// Answered here rather than per-command because the condition is a fact about
// the REQUEST, not about any one command: the body is gone before dispatch, so
// every command is equally blind to it. Placed after authentication so the
// server's configured limits are not readable by an anonymous caller — the
// Authorization header and the session cookie both survive a discarded body,
// so this costs a genuine caller nothing.
require_once SECURE_FOLDER_PATH . '/src/functions/uploadLimits.php';
$__qsBodyBreach = qs_post_body_discarded();
if ($__qsBodyBreach !== null) {
    ApiResponse::create(413, 'request.body_too_large')
        ->withMessage(qs_post_too_large_message($__qsBodyBreach))
        ->withData(qs_post_too_large_data($__qsBodyBreach))
        ->send();
}

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
    // C12 (F9): the absolute path used to ride out in `data.expected_path`.
    // A caller cannot act on it; an operator reads it from the error log.
    error_log('QuickSite: routes management file not found at ' . ROUTES_MANAGEMENT_PATH);
    ApiResponse::create(500, 'file.not_found')
        ->withMessage('Routes management file not found')
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
    // C12 (F9): this used to answer an unknown command with the ENTIRE routable
    // command list — all 177 names, to any authenticated caller regardless of
    // role or membership. `help` already exposes the commands a caller is
    // actually permitted to run, which is the answer they are entitled to; this
    // handed over the full catalogue. The requested name is echoed back because
    // the caller supplied it and it makes a typo diagnosable.
    ApiResponse::create(404, 'route.not_found')
        ->withMessage('Command not found')
        ->withData([
            'requested_command' => $trimParametersManagement->command(),
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

// Parse request body for logging.
// is_array, NOT `?? []`: null-coalesce only catches a decode FAILURE. A JSON
// SCALAR body ('5', '"s"', 'true', '1.5') decodes to a non-null NON-array, which
// then reached logCommand()'s `array $body` parameter as a TypeError — a fatal
// raised inside ApiResponse's beforeSend callback, i.e. on the way OUT of an
// otherwise-successful request (beta.10 C13 F-C13-10, second carrier).
$decodedBody = json_decode(REQUEST_BODY_RAW, true);
$requestBody = is_array($decodedBody) ? $decodedBody : [];

// The command log is PER-PROJECT (C10 10.1b). The bucket comes from the command's
// SCOPE, never from PROJECT_NAME: a global command is given a benign working
// context from the caller's UX-default project above, so PROJECT_NAME would
// mis-file global actions into whichever project the user happens to have
// selected. Project-scoped => the authorized marker project; global => null,
// which logCommand routes to the write-only `_global` bucket.
$logProject = ($commandScope === 'project') ? $requestedProject : null;

// Set up logging callback
ApiResponse::setBeforeSendCallback(function($status, $responseCode) use ($command, $currentUser, $commandStartTime, $requestBody, $logProject) {
    logCommand(
        $command,
        $_SERVER['REQUEST_METHOD'],
        $requestBody,
        $currentUser,
        $status,
        $responseCode,
        $commandStartTime,
        $logProject
    );
});

require_once SECURE_FOLDER_PATH . '/management/command/'. $command .'.php';
