<?php
/**
 * refreshSession Command (C5b)
 *
 * Exchanges a refresh token for a NEW access + refresh pair (ROTATION). The
 * presented refresh token is retired; presenting it again after a short grace
 * window is treated as theft and revokes the whole session family (both the
 * legitimate holder and the thief are logged out — the theft becomes visible).
 * Within the grace window a re-presentation is treated as a legitimate
 * concurrent race (two tabs) and yields a sibling pair without punishment.
 *
 * PUBLIC + self-authenticating (the refresh token in the body IS the
 * credential; no bearer header is consulted).
 *
 * @method POST
 * @route /management/refreshSession
 * @auth none (self-authenticating)
 * @param string $refresh_token The refresh token issued by login/refreshSession
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

function __command_refreshSession(array $params = [], array $urlParams = []): ApiResponse {
    $refresh = (string)($params['refresh_token'] ?? '');
    if (trim($refresh) === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('refresh_token is required')
            ->withData(['required' => ['refresh_token']]);
    }

    $result = qs_session_rotate(trim($refresh));

    if (!($result['ok'] ?? false)) {
        switch ($result['error'] ?? 'invalid') {
            case 'reuse_revoked':
                return ApiResponse::create(401, 'auth.refresh_reuse_revoked')
                    ->withMessage('Refresh token reuse detected — the session family has been revoked. Log in again.');
            case 'user_disabled':
                return ApiResponse::create(401, 'auth.unauthorized')
                    ->withMessage('User account is disabled');
            case 'store':
                return ApiResponse::create(500, 'server.session_store_failed')
                    ->withMessage('Could not rotate the session');
            default: // invalid | expired — uniform refusal, no token oracle
                return ApiResponse::create(401, 'auth.refresh_invalid')
                    ->withMessage('Invalid or expired refresh token');
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Session refreshed')
        ->withData([
            'token_type'         => 'Bearer',
            'access_token'       => $result['access_token'],
            'expires_in'         => $result['access_expires'] - time(),
            'refresh_token'      => $result['refresh_token'],
            'refresh_expires_in' => $result['refresh_expires'] - time(),
        ]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_refreshSession($trimParams->params(), $trimParams->additionalParams())->send();
}
