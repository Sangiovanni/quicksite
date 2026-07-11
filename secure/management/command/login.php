<?php
/**
 * login Command (C5b)
 *
 * Exchanges email + password for a SESSION: a short-lived access token (sent
 * as `Authorization: Bearer` on every subsequent request) + a longer-lived
 * refresh token (used only against refreshSession). PUBLIC + self-authenticating
 * (listed in the dispatcher's $PUBLIC_COMMANDS — the credentials in the body
 * ARE the authentication; no bearer header is consulted).
 *
 * Refusals are uniform (unknown email, wrong password, passwordless/externally
 * managed account, disabled user all yield the same 401) — no account oracle.
 * Brute force is throttled per email (5 free attempts, doubling cooldown).
 *
 * @method POST
 * @route /management/login
 * @auth none (self-authenticating)
 * @param string $email    Login identifier (users.php `email`)
 * @param string $password Plain password, verified against `password_hash`
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

function __command_login(array $params = [], array $urlParams = []): ApiResponse {
    $email = (string)($params['email'] ?? '');
    $password = (string)($params['password'] ?? '');
    if (trim($email) === '' || $password === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('email and password are required')
            ->withData(['required' => ['email', 'password']]);
    }

    $attempt = qs_auth_attempt_login($email, $password);

    if (!$attempt['ok']) {
        if ($attempt['error'] === 'throttled') {
            return ApiResponse::create(429, 'auth.throttled')
                ->withMessage('Too many failed attempts — try again later')
                ->withData(['retry_after' => $attempt['retry_after']]);
        }
        if ($attempt['error'] === 'server') {
            return ApiResponse::create(500, 'server.session_store_failed')
                ->withMessage('Could not create the session');
        }
        return ApiResponse::create(401, 'auth.invalid_credentials')
            ->withMessage('Invalid email or password');
    }

    $user = $attempt['user'];
    $session = $attempt['session'];
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Logged in')
        ->withData([
            'token_type'         => 'Bearer',
            'access_token'       => $session['access_token'],
            'expires_in'         => $session['access_expires'] - time(),
            'refresh_token'      => $session['refresh_token'],
            'refresh_expires_in' => $session['refresh_expires'] - time(),
            'user' => [
                'id'               => $user['id'],
                'name'             => $user['name'] ?? '',
                'email'            => $user['email'] ?? null,
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
