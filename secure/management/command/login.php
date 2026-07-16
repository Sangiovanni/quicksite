<?php
/**
 * login Command (C5b; username identity C8 8.0b)
 *
 * Exchanges username + password for a SESSION: a short-lived access token
 * (sent as `Authorization: Bearer` on every subsequent request) + a
 * longer-lived refresh token (used only against refreshSession). PUBLIC +
 * self-authenticating (listed in the dispatcher's $PUBLIC_COMMANDS — the
 * credentials in the body ARE the authentication; no bearer header is
 * consulted).
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
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

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
            ->withMessage('Invalid username or password');
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
