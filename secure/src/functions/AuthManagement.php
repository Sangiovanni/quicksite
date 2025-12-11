<?php
/**
 * Authentication & CORS Management Functions
 * 
 * Handles API token validation and CORS header management.
 */

/**
 * Load authentication configuration
 * 
 * @return array Auth configuration
 */
function loadAuthConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/config/auth.php';
    
    if (!file_exists($configPath)) {
        // Return default restrictive config if file missing
        return [
            'authentication' => ['enabled' => true, 'tokens' => []],
            'cors' => ['enabled' => false]
        ];
    }
    
    return require $configPath;
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
        return ['valid' => true, 'token_info' => ['name' => 'Auth Disabled', 'permissions' => ['*']], 'error' => null];
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
    
    return ['valid' => true, 'token_info' => $tokens[$token], 'error' => null];
}

/**
 * Check if token has permission for a specific command
 * 
 * @param array $tokenInfo Token information array
 * @param string $command Command name being accessed
 * @return bool
 */
function hasPermission(array $tokenInfo, string $command): bool {
    $permissions = $tokenInfo['permissions'] ?? [];
    
    // Wildcard = full access
    if (in_array('*', $permissions)) {
        return true;
    }
    
    // Specific command permission
    if (in_array("command:{$command}", $permissions)) {
        return true;
    }
    
    // Category-based permissions
    $readCommands = ['getRoutes', 'getStructure', 'getTranslation', 'getTranslations', 
                     'getTranslationKeys', 'validateTranslations', 'getLangList', 
                     'listAssets', 'getStyles', 'help', 'listTokens'];
    
    $writeCommands = ['editStructure', 'editTranslation', 'addRoute', 'deleteRoute',
                      'addLang', 'deleteLang', 'uploadAsset', 'deleteAsset', 
                      'editStyles', 'editTitle', 'editFavicon'];
    
    $adminCommands = ['setPublicSpace', 'renameSecureFolder', 'renamePublicFolder', 
                      'build', 'generateToken', 'revokeToken'];
    
    if (in_array('read', $permissions) && in_array($command, $readCommands)) {
        return true;
    }
    
    if (in_array('write', $permissions) && in_array($command, $writeCommands)) {
        return true;
    }
    
    if (in_array('admin', $permissions) && in_array($command, $adminCommands)) {
        return true;
    }
    
    return false;
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
        $isAllowed = in_array($origin, $allowedOrigins);
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
