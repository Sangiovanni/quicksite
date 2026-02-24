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
 * Migrate old token format (permissions[]) to new format (role)
 * Returns the role name for the token
 * 
 * @param array $tokenInfo Token info with old format
 * @return string Role name
 */
function migrateTokenPermissions(array $tokenInfo): string {
    $permissions = $tokenInfo['permissions'] ?? [];
    
    // Already has new format
    if (isset($tokenInfo['role'])) {
        return $tokenInfo['role'];
    }
    
    // Migration mapping
    if (in_array('*', $permissions)) {
        return '*';
    }
    
    if (in_array('admin', $permissions)) {
        return 'admin';
    }
    
    if (in_array('write', $permissions)) {
        // read+write = editor
        return 'editor';
    }
    
    if (in_array('read', $permissions)) {
        return 'viewer';
    }
    
    // Default to viewer for unknown permissions
    return 'viewer';
}

/**
 * Get token info with normalized format (ensures 'role' exists)
 * Handles migration from old permissions[] format
 * 
 * @param array $tokenInfo Raw token info from config
 * @return array Normalized token info with 'role'
 */
function normalizeTokenInfo(array $tokenInfo): array {
    // If already has 'role', return as-is
    if (isset($tokenInfo['role'])) {
        return $tokenInfo;
    }
    
    // Migrate from old format
    $tokenInfo['role'] = migrateTokenPermissions($tokenInfo);
    
    return $tokenInfo;
}

/**
 * Validate Bearer token from Authorization header
 * 
 * @param string|null $authHeader The Authorization header value
 * @return array ['valid' => bool, 'token_info' => array|null, 'error' => string|null]
 */
function validateBearerToken(?string $authHeader): array {
    $config = loadAuthConfig();
    
    // Check if auth is enabled
    if (!($config['authentication']['enabled'] ?? true)) {
        return ['valid' => true, 'token_info' => ['name' => 'Auth Disabled', 'role' => '*'], 'error' => null];
    }
    
    // No header provided
    if (empty($authHeader)) {
        return ['valid' => false, 'token_info' => null, 'error' => 'Authorization header required'];
    }
    
    // Check Bearer format
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return ['valid' => false, 'token_info' => null, 'error' => 'Invalid Authorization header format. Use: Bearer <token>'];
    }
    
    $token = trim($matches[1]);
    
    // Validate token exists
    $tokens = $config['authentication']['tokens'] ?? [];
    
    if (!isset($tokens[$token])) {
        return ['valid' => false, 'token_info' => null, 'error' => 'Invalid or expired token'];
    }
    
    // Normalize token info (handles migration from old format)
    $tokenInfo = normalizeTokenInfo($tokens[$token]);
    
    return ['valid' => true, 'token_info' => $tokenInfo, 'error' => null];
}

/**
 * Check if token has permission for a specific command
 * 
 * @param array $tokenInfo Token information array (normalized)
 * @param string $command Command name being accessed
 * @return bool
 */
function hasPermission(array $tokenInfo, string $command): bool {
    // Normalize token info in case it's old format
    $tokenInfo = normalizeTokenInfo($tokenInfo);
    
    $role = $tokenInfo['role'] ?? 'viewer';
    
    // Superadmin = full access
    if ($role === '*') {
        return true;
    }
    
    // Get commands for this role
    $allowedCommands = getRoleCommands($role);
    
    if ($allowedCommands === null) {
        // Invalid role - deny access
        return false;
    }
    
    return in_array($command, $allowedCommands, true);
}

/**
 * Get all permissions for a token
 * 
 * @param array $tokenInfo Token information array
 * @return array ['role' => string, 'commands' => string[]]
 */
function getTokenPermissions(array $tokenInfo): array {
    $tokenInfo = normalizeTokenInfo($tokenInfo);
    $role = $tokenInfo['role'] ?? 'viewer';
    
    if ($role === '*') {
        // Superadmin gets all commands plus special commands
        $allCommands = getAllCommands();
        // Add new role management commands
        $specialCommands = ['listRoles', 'getMyPermissions', 'createRole', 'editRole', 'deleteRole'];
        $allCommands = array_unique(array_merge($allCommands, $specialCommands));
        sort($allCommands);
        return [
            'role' => '*',
            'commands' => $allCommands
        ];
    }
    
    $commands = getRoleCommands($role);
    if ($commands === null) {
        $commands = [];
    }
    
    return [
        'role' => $role,
        'commands' => $commands
    ];
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
