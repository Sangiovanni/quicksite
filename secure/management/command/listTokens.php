<?php
/**
 * listTokens - List API tokens (without revealing full token values)
 * 
 * @method GET
 * @url /management/listTokens
 * @auth required
 * @permission admin
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_listTokens(array $params = [], array $urlParams = []): ApiResponse {
    $config = loadAuthConfig();

    // Detect the currently-used token
    $currentToken = getTokenFromRequest();
    // Also check session token (admin panel)
    if (!$currentToken && !empty($_SESSION['admin_token'])) {
        $currentToken = $_SESSION['admin_token'];
    }

    // Build safe token list (mask token values). Tokens now resolve to a user
    // (C5); the name comes from users.php and the role is per-project (shown for
    // the user's selected project as a best-effort hint).
    $users = loadUsersConfig();
    $tokens = [];
    foreach ($config['authentication']['tokens'] as $token => $info) {
        // Show only first 8 and last 4 characters
        $masked = substr($token, 0, 8) . '...' . substr($token, -4);

        $uid  = $info['userId'] ?? null;
        $user = ($uid !== null) ? ($users['users'][$uid] ?? null) : null;
        $proj = $user['selected_project'] ?? null;
        $role = ($uid !== null && $proj !== null) ? (getUserRoleForProject($uid, $proj) ?? 'none') : 'none';

        $tokens[] = [
            'token_preview' => $masked,
            'name' => $user['name'] ?? 'Unknown',
            'user_id' => $uid,
            'role' => $role,
            'created' => $info['created'] ?? 'unknown',
            'is_current' => ($token === $currentToken),
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Tokens retrieved successfully')
        ->withData([
            'total_tokens' => count($tokens),
            'tokens' => $tokens,
            'auth_enabled' => $config['authentication']['enabled'] ?? true,
            'development_mode' => $config['cors']['development_mode'] ?? false
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listTokens()->send();
}
