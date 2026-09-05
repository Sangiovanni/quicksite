<?php
/**
 * login Command
 *
 * Exchanges username + password for a SESSION. The response sets the session
 * cookie and returns that session's TOKEN; every later request presents both —
 * the cookie as the credential, the token as `Authorization: Bearer` proving
 * the caller is the one the session was handed to (see AuthManagement's
 * validateBearerToken for why both are required). A command-line client needs a
 * cookie jar: `curl -c jar -b jar`.
 *
 * PUBLIC + self-authenticating (listed in the dispatcher's $PUBLIC_COMMANDS —
 * the credentials in the body ARE the authentication; no session is consulted).
 *
 * Refusals are uniform (unknown username, wrong password, passwordless/
 * externally managed account, disabled user all yield the same 401) — no
 * account oracle. Brute force is throttled per username (5 free attempts,
 * doubling cooldown).
 *
 * @method POST
 * @route /management/login
 * @auth none (self-authenticating)
 * @param string $username Login identifier (users.php `username` — private)
 * @param string $password Plain password, verified against `password_hash`
 * @param bool   $remember Optional — give the session cookie a lifetime so it
 *                         survives a browser restart (default: dies with the
 *                         browser session)
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
// This command answers BEFORE the dispatcher installs its logging callback, so
// nothing downstream can record it. The security log is written from here.
require_once SECURE_FOLDER_PATH . '/src/functions/securityLog.php';

function __command_login(array $params = [], array $urlParams = []): ApiResponse {
    $username = (string)($params['username'] ?? '');
    $password = (string)($params['password'] ?? '');
    if (trim($username) === '' || $password === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('username and password are required')
            ->withData(['required' => ['username', 'password']]);
    }

    $attempt = qs_auth_attempt_login($username, $password);

    if (!$attempt['ok']) {
        // The username that was tried, never what was tried with it. The refusal
        // stays uniform to the caller — this record is for the operator, and the
        // distinction between "unknown username" and "wrong password" is
        // deliberately absent here too, because the attempt does not resolve to
        // an account and inventing one would put an oracle in the log.
        qs_security_log(QS_SEC_SIGNIN_FAILURE, [
            'username' => $username,
            'reason'   => $attempt['error'] === 'throttled' ? 'throttled' : 'invalid_credentials',
        ]);

        if ($attempt['error'] === 'throttled') {
            return ApiResponse::create(429, 'auth.throttled')
                ->withMessage('Too many failed attempts — try again later')
                ->withData(['retry_after' => $attempt['retry_after']]);
        }
        return ApiResponse::create(401, 'auth.invalid_credentials')
            ->withMessage('Invalid username or password');
    }

    $user  = $attempt['user'];
    $token = qs_session_establish(
        (string)$user['id'],
        qs_user_generation($user),
        !empty($params['remember'])
    );

    // The session token is NOT recorded — it is the credential this call just
    // minted. `remember` is, because a long-lived session is the thing an
    // operator reading this trail would want to know was created.
    qs_security_log(
        QS_SEC_SIGNIN_SUCCESS,
        ['remember' => !empty($params['remember'])],
        (string)$user['id'],
        $user['name'] ?? null
    );

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Logged in')
        ->withData([
            'token_type'    => 'Bearer',
            'session_token' => $token,
            'user' => [
                'id'               => $user['id'],
                'name'             => $user['name'] ?? '',
                'username'         => $user['username'] ?? null,
                'selected_project' => $user['selected_project'] ?? null,
            ],
        ]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_login($trimParams->params(), $trimParams->additionalParams())->send();
}
