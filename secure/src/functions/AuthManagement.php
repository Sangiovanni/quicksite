<?php
/**
 * Authentication & CORS Management Functions
 * 
 * Handles API token validation, role-based permissions, and CORS header management.
 */

/**
 * Load authentication configuration
 * 
 * @return array Auth configuration
 */
function loadAuthConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
    
    if (!file_exists($configPath)) {
        // Return default restrictive config if file missing
        return [
            'authentication' => ['enabled' => true, 'tokens' => []],
            'cors' => ['enabled' => false]
        ];
    }
    
    // Invalidate opcode cache if available (needed for dynamic config changes)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }
    
    return require $configPath;
}

/**
 * Load roles configuration
 * 
 * @return array Role definitions
 */
function loadRolesConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/roles.php';
    
    if (!file_exists($configPath)) {
        return [];
    }
    
    // Invalidate opcode cache if available (needed for dynamic config changes)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }
    
    return require $configPath;
}

/**
 * Load the user registry (token identities). C5.
 *
 * @return array ['users' => [ userId => record ]]
 */
function loadUsersConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/users.php';

    if (!file_exists($configPath)) {
        return ['users' => []];
    }

    // Invalidate opcode cache if available (config changes must be seen live)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }

    return require $configPath;
}

/**
 * Load a project's members.json (AUTHORITATIVE for access — L5). C5.
 * Defensive single-segment guard on $project (F1); empty on anything unsafe.
 *
 * @return array ['owner'=>?string,'visibility'=>string,'members'=>[uid=>['role'=>..]]]
 */
function loadProjectMembers(string $project): array {
    if ($project === '' || strpbrk($project, "/\\") !== false || strpos($project, '..') !== false) {
        return ['members' => []];
    }
    $path = SECURE_FOLDER_PATH . '/projects/' . $project . '/config/members.json';
    if (!is_file($path)) {
        return ['members' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['members' => []];
}

/**
 * A user's authoritative role on a project (members.json, never the users.php
 * cache — L5). C5.
 *
 * @return string|null role name, or null if the user is not a member
 */
function getUserRoleForProject(string $userId, string $project): ?string {
    $members = loadProjectMembers($project);
    return $members['members'][$userId]['role'] ?? null;
}

/**
 * Resolve a user's EFFECTIVE role for the transitional bridge (C5): their role on
 * the selected project, or — if that pointer is stale (project deleted / no longer
 * a member) — a graceful fallback to any project they are genuinely a member of.
 * Never lets a stale selected_project brick access (design requirement). Returns
 * null only when the user has no real membership anywhere. Role always comes from
 * the AUTHORITATIVE members.json (L5). Replaced in C7 by the per-request project.
 *
 * @return string|null role name, or null if the user has no membership
 */
function resolveEffectiveRole(array $user): ?string {
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return null;
    }
    // Prefer the selected project, but only when that membership is real
    $selected = $user['selected_project'] ?? null;
    if ($selected !== null && $selected !== '') {
        $role = getUserRoleForProject($userId, (string)$selected);
        if ($role !== null) {
            return $role;
        }
    }
    // Fallback: first cached project where the membership actually exists
    foreach (array_keys($user['projects'] ?? []) as $project) {
        $role = getUserRoleForProject($userId, (string)$project);
        if ($role !== null) {
            return $role;
        }
    }
    return null;
}

/**
 * Get the master list of all commands from routes.php
 *
 * @return array List of all command names
 */
function getAllCommands(): array {
    $routesPath = SECURE_FOLDER_PATH . '/management/routes.php';
    
    if (!file_exists($routesPath)) {
        return [];
    }
    
    return require $routesPath;
}

/**
 * Get commands for a specific role
 * 
 * @param string $roleName The role name
 * @return array|null Array of command names, or null if role doesn't exist
 */
function getRoleCommands(string $roleName): ?array {
    $roles = loadRolesConfig();
    
    if (!isset($roles[$roleName])) {
        return null;
    }
    
    return $roles[$roleName]['commands'] ?? [];
}

/**
 * Check if a role name is valid (exists in roles.php)
 * 
 * @param string $roleName The role name to validate
 * @return bool
 */
function isValidRole(string $roleName): bool {
    // '*' is special - it's not a role, it's superadmin
    if ($roleName === '*') {
        return true;
    }
    
    $roles = loadRolesConfig();
    return isset($roles[$roleName]);
}

/**
 * Check if a role is a builtin role (cannot be deleted)
 * 
 * @param string $roleName The role name
 * @return bool
 */
function isBuiltinRole(string $roleName): bool {
    $roles = loadRolesConfig();
    return ($roles[$roleName]['builtin'] ?? false) === true;
}

/**
 * Validate Bearer token and resolve it to a USER (C5).
 * token -> userId (auth.php) -> user (users.php). Disabled users are rejected
 * before any command runs (L10). The returned user has its 'id' attached.
 *
 * @param string|null $authHeader The Authorization header value
 * @return array ['valid'=>bool, 'user'=>array|null, 'userId'=>string|null, 'error'=>string|null]
 */
function validateBearerToken(?string $authHeader): array {
    $config = loadAuthConfig();

    // Auth disabled: synthetic full-access principal (dev/testing escape hatch)
    if (!($config['authentication']['enabled'] ?? true)) {
        return ['valid' => true, 'user' => ['name' => 'Auth Disabled', 'status' => 'active', '_authDisabled' => true], 'userId' => null, 'error' => null];
    }

    // No header provided
    if (empty($authHeader)) {
        return ['valid' => false, 'user' => null, 'userId' => null, 'error' => 'Authorization header required'];
    }

    // Check Bearer format
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return ['valid' => false, 'user' => null, 'userId' => null, 'error' => 'Invalid Authorization header format. Use: Bearer <token>'];
    }

    $token = trim($matches[1]);

    // token -> userId
    $tokens = $config['authentication']['tokens'] ?? [];
    $tokenEntry = $tokens[$token] ?? null;
    if ($tokenEntry === null) {
        return ['valid' => false, 'user' => null, 'userId' => null, 'error' => 'Invalid or expired token'];
    }

    // userId -> user
    $userId = $tokenEntry['userId'] ?? null;
    $users = loadUsersConfig();
    $user = ($userId !== null) ? ($users['users'][$userId] ?? null) : null;
    if ($user === null) {
        return ['valid' => false, 'user' => null, 'userId' => null, 'error' => 'Token does not resolve to a user'];
    }

    // L10: disabled user — ALL their tokens die everywhere; short-circuit here
    if (($user['status'] ?? 'active') === 'disabled') {
        return ['valid' => false, 'user' => null, 'userId' => $userId, 'error' => 'User account is disabled'];
    }

    $user['id'] = $userId; // attach resolved id for downstream (authz, logging, ownership)
    return ['valid' => true, 'user' => $user, 'userId' => $userId, 'error' => null];
}

/**
 * Check whether a USER may run a command (C5).
 *
 * TRANSITIONAL BRIDGE — replaced in C6 by category-based per-project RBAC and in
 * C7 by the per-REQUEST project (this reads the user's selected_project). Role is
 * read from the AUTHORITATIVE members.json, never the users.php cache (L5).
 *
 * @param array $user Resolved user (from validateBearerToken; must have 'id')
 * @param string $command Command name being accessed
 * @return bool
 */
function hasPermission(array $user, string $command): bool {
    // Auth-disabled escape hatch resolves to a synthetic full-access principal
    if (!empty($user['_authDisabled'])) {
        return true;
    }

    // Global "any authenticated user" commands (no project membership required)
    static $anyAuth = ['help', 'getMyPermissions', 'getMyProjects', 'listProjects', 'createProject', 'listRoles'];
    if (in_array($command, $anyAuth, true)) {
        return true;
    }

    $role = resolveEffectiveRole($user); // selected project, with graceful fallback
    if ($role === null) {
        return false; // no membership anywhere
    }
    if ($role === 'owner' || $role === 'admin') {
        return true;  // top of the project
    }

    $allowedCommands = getRoleCommands($role);
    return $allowedCommands !== null && in_array($command, $allowedCommands, true);
}

/**
 * Get a USER's effective role + command list for their selected project (C5).
 * Feeds getMyPermissions (admin JS reads {role, commands} to gate the UI).
 * TRANSITIONAL — mirrors hasPermission's bridge; C6 replaces with category RBAC.
 *
 * @param array $user Resolved user (must have 'id')
 * @return array ['role' => string|null, 'commands' => string[]]
 */
function getTokenPermissions(array $user): array {
    // Global commands any authenticated user may run (project-independent)
    $anyAuth = ['help', 'getMyPermissions', 'getMyProjects', 'listProjects', 'createProject', 'listRoles'];

    if (!empty($user['_authDisabled'])) {
        $all = array_values(array_unique(array_merge(getAllCommands(), $anyAuth)));
        sort($all);
        return ['role' => 'owner', 'commands' => $all];
    }

    $role = resolveEffectiveRole($user); // selected project, with graceful fallback

    if ($role === null) {
        // No membership anywhere: only the any-auth globals
        sort($anyAuth);
        return ['role' => null, 'commands' => $anyAuth];
    }

    if ($role === 'owner' || $role === 'admin') {
        $all = array_values(array_unique(array_merge(getAllCommands(), $anyAuth)));
        sort($all);
        return ['role' => $role, 'commands' => $all];
    }

    $commands = getRoleCommands($role) ?? [];
    // array_values() forces a JSON array (roles.php can have non-contiguous keys)
    $commands = array_values(array_unique(array_merge($commands, $anyAuth)));
    return ['role' => $role, 'commands' => $commands];
}

/**
 * Handle CORS preflight and headers
 * 
 * @param string|null $origin The Origin header from request
 * @return bool True if origin is allowed, false otherwise
 */
function handleCors(?string $origin): bool {
    $config = loadAuthConfig();
    $corsConfig = $config['cors'] ?? [];
    
    // CORS disabled
    if (!($corsConfig['enabled'] ?? false)) {
        return true; // Allow request but don't set CORS headers
    }
    
    // No origin = same-origin request, allow it
    if (empty($origin)) {
        return true;
    }
    
    // Same-origin check: if Origin matches the current host, it's not cross-origin
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $selfOrigin = $scheme . '://' . $host;
    if (strcasecmp($origin, $selfOrigin) === 0) {
        return true; // Same-origin, no CORS headers needed
    }
    
    $isAllowed = false;
    
    // Development mode: allow any localhost
    if ($corsConfig['development_mode'] ?? false) {
        if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
            $isAllowed = true;
        }
    }
    
    // Check allowed origins list
    if (!$isAllowed) {
        $allowedOrigins = $corsConfig['allowed_origins'] ?? [];
        // Support wildcard '*' to allow any origin
        $isAllowed = in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins);
    }
    
    if ($isAllowed) {
        // Set CORS headers
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Methods: " . implode(', ', $corsConfig['allowed_methods'] ?? ['GET', 'POST', 'OPTIONS']));
        header("Access-Control-Allow-Headers: " . implode(', ', $corsConfig['allowed_headers'] ?? ['Content-Type', 'Authorization']));
        header("Access-Control-Expose-Headers: " . implode(', ', $corsConfig['expose_headers'] ?? []));
        header("Access-Control-Max-Age: " . ($corsConfig['max_age'] ?? 86400));
        
        if ($corsConfig['allow_credentials'] ?? false) {
            header("Access-Control-Allow-Credentials: true");
        }
        
        return true;
    }
    
    return false;
}

/**
 * Handle OPTIONS preflight request
 * Sends appropriate headers and exits
 */
function handlePreflightRequest(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    
    if (handleCors($origin)) {
        http_response_code(204); // No Content
        exit;
    } else {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 403,
            'code' => 'cors.origin_not_allowed',
            'message' => 'Origin not allowed by CORS policy'
        ]);
        exit;
    }
}

/**
 * Generate a new API token
 * 
 * @param int $length Length of random bytes (default 24 = 48 char hex string)
 * @return string Generated token with prefix
 */
function generateApiToken(int $length = 24): string {
    return 'tvt_' . bin2hex(random_bytes($length));
}

/**
 * Get the current token from the Authorization header
 * 
 * @return string|null The token, or null if not present/invalid format
 */
function getTokenFromRequest(): ?string {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    
    if (empty($authHeader)) {
        return null;
    }
    
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Resolve the current request's USER from the Authorization header (C5).
 * For commands that need the caller's identity (e.g. createProject owner).
 * Returns null if unauthenticated / unresolved / disabled.
 *
 * @return array|null Resolved user (with 'id') or null
 */
function getCurrentUser(): ?array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    }
    $result = validateBearerToken($authHeader);
    return $result['valid'] ? $result['user'] : null;
}

/**
 * Send 401 Unauthorized response
 * 
 * @param string $message Error message
 * @param string|null $hint Optional hint for fixing the error
 */
function sendUnauthorizedResponse(string $message, ?string $hint = null): void {
    http_response_code(401);
    header('Content-Type: application/json');
    header('WWW-Authenticate: Bearer realm="Template Vitrine Management API"');
    
    $response = [
        'status' => 401,
        'code' => 'auth.unauthorized',
        'message' => $message
    ];
    
    if ($hint) {
        $response['hint'] = $hint;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send 403 Forbidden response (for permission denied)
 * 
 * @param string $command The command that was denied
 */
function sendForbiddenResponse(string $command): void {
    http_response_code(403);
    header('Content-Type: application/json');
    
    echo json_encode([
        'status' => 403,
        'code' => 'auth.forbidden',
        'message' => 'Insufficient permissions for this command',
        'command' => $command
    ], JSON_PRETTY_PRINT);
    exit;
}
