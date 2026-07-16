<?php
/**
 * changePassword Command (C8)
 *
 * Self-service password change for the AUTHENTICATED caller. Requires the
 * current password (a stolen access token alone must not be enough to take
 * the account over) and is throttled per user with the login backoff (a
 * stolen token must not become a password brute-force oracle either).
 *
 * On success every OTHER session family of the user is revoked (containment:
 * a password change is the "I suspect theft" action) — the session performing
 * the change survives.
 *
 * @method POST
 * @route /management/changePassword
 * @auth required (global scope — acts only on the caller's own account)
 * @param string $current_password The user's current password
 * @param string $new_password     Replacement (min length from auth.php
 *                                 registration.min_password_length, default 12)
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

function __command_changePassword(array $params = [], array $urlParams = []): ApiResponse {
    $current = (string)($params['current_password'] ?? '');
    $new = (string)($params['new_password'] ?? '');
    if ($current === '' || $new === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('current_password and new_password are required')
            ->withData(['required' => ['current_password', 'new_password']]);
    }

    $auth = getCurrentAuth();
    if ($auth === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    $user = $auth['user'];
    $userId = $auth['userId'];

    $hash = $user['password_hash'] ?? null;
    if (!is_string($hash) || $hash === '') {
        return ApiResponse::create(400, 'auth.externally_managed')
            ->withMessage('This account has no local password (externally managed)');
    }

    $minLength = qs_registration_config()['min_password_length'];
    if (mb_strlen($new) < $minLength) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('New password is too short')
            ->withErrors(['new_password' => 'Minimum length: ' . $minLength])
            ->withData(['min_length' => $minLength]);
    }

    // Same brute-force backoff as login, keyed on the same credential target.
    $throttleKey = is_string($user['username'] ?? null) && $user['username'] !== '' ? $user['username'] : $userId;
    $wait = qs_login_throttle_check($throttleKey);
    if ($wait > 0) {
        return ApiResponse::create(429, 'auth.throttled')
            ->withMessage('Too many failed attempts — try again later')
            ->withData(['retry_after' => $wait]);
    }

    if (!password_verify($current, $hash)) {
        qs_login_throttle_fail($throttleKey);
        return ApiResponse::create(401, 'auth.invalid_credentials')
            ->withMessage('Current password is incorrect');
    }
    qs_login_throttle_clear($throttleKey);

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $written = qs_users_mutate(function (array &$cfg) use ($userId, $newHash) {
        if (!isset($cfg['users'][$userId])) {
            return false;
        }
        $cfg['users'][$userId]['password_hash'] = $newHash;
        return true;
    });
    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Could not persist the new password');
    }

    // Containment: every other session of this user dies; this one survives.
    $revoked = qs_session_revoke_user_families($userId, $auth['family']);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Password changed')
        ->withData(['other_sessions_revoked' => $revoked]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_changePassword($trimParams->params(), $trimParams->additionalParams())->send();
}
