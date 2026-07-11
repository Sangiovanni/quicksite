<?php
/**
 * logoutSession Command (C5b)
 *
 * Revokes the SESSION FAMILY the given refresh token belongs to — the access
 * token(s) and refresh token of that login all die together. Idempotent:
 * an unknown/already-revoked token simply reports revoked=false. PUBLIC +
 * self-authenticating (holding the refresh token proves ownership of the
 * session being ended; no bearer header is consulted).
 *
 * @method POST
 * @route /management/logoutSession
 * @auth none (self-authenticating)
 * @param string $refresh_token The session's refresh token
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

function __command_logoutSession(array $params = [], array $urlParams = []): ApiResponse {
    $refresh = (string)($params['refresh_token'] ?? '');
    if (trim($refresh) === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('refresh_token is required')
            ->withData(['required' => ['refresh_token']]);
    }

    $revoked = qs_session_revoke_by_refresh(trim($refresh));

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($revoked ? 'Session revoked' : 'No matching session (already logged out?)')
        ->withData(['revoked' => $revoked]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_logoutSession($trimParams->params(), $trimParams->additionalParams())->send();
}
