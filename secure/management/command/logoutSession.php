<?php
/**
 * logoutSession Command
 *
 * Ends the CALLER'S session: the session data, its file and its cookie.
 *
 * With `everywhere: true` it also bumps the account's session GENERATION, which
 * ends every OTHER session of this user — a second browser, a phone, a session
 * left open somewhere else — on their next request. That is the whole
 * mechanism: one integer on the user record, stamped into each session at login
 * and compared on every request. Nothing is indexed and nothing needs cleaning
 * up afterwards.
 *
 * AUTHENTICATED. There is no refresh token to present any more, so proving you
 * may end a session is proving you hold it — which is what the ordinary
 * authenticated path already checks. Idempotent by construction: a caller with
 * no session never reaches the command.
 *
 * @method POST
 * @route /management/logoutSession
 * @auth required (global scope — acts only on the caller's own sessions)
 * @param bool $everywhere Optional — also end this account's other sessions
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/securityLog.php';

function __command_logoutSession(array $params = [], array $urlParams = []): ApiResponse {
    $auth = getCurrentAuth();
    if ($auth === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $everywhere = !empty($params['everywhere']);
    $othersEnded = false;
    if ($everywhere) {
        $generation = qs_user_bump_generation((string)$auth['userId']);
        if ($generation === null) {
            return ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage('Could not end the account\'s other sessions');
        }
        $othersEnded = true;
    }

    // Recorded here, in the security log, rather than in the `_global` command
    // bucket. Ending a session is an event about an ACCOUNT, and `_global` is
    // the project-lifecycle trail — one file with two audiences is one file with
    // two retention policies. The record is written before the session is
    // destroyed, while the caller's identity is still resolved.
    qs_security_log(
        QS_SEC_SIGNOUT,
        ['everywhere' => $everywhere, 'other_sessions_ended' => $othersEnded],
        (string)$auth['userId'],
        $auth['user']['name'] ?? null
    );

    qs_session_destroy();

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($everywhere ? 'Signed out everywhere' : 'Signed out')
        ->withData([
            'ended'               => true,
            'other_sessions_ended' => $othersEnded,
        ]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_logoutSession($trimParams->params(), $trimParams->additionalParams())->send();
}
