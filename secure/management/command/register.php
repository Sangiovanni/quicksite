<?php
/**
 * register Command
 *
 * Self-registration: creates a user account from a public display name + a
 * password. The caller does not choose the private username — the server assigns
 * one and returns it in this response, which is the only place it is handed out
 * before the account's first sign-in. PUBLIC + self-gating (listed in the
 * dispatcher's $PUBLIC_COMMANDS) — the command enforces the auth.php
 * `registration.allow_self_registration` flag SERVER-SIDE (default: disabled)
 * plus the registration flood controls (per-IP rate, install-wide hourly cap,
 * absolute account cap).
 *
 * No session and no user id are returned; the new user signs in through `login`
 * with the username this response names. A `username` sent in the body is
 * ignored, like any other parameter this command does not take.
 *
 * This command CANNOT create the first account on an install. While the user
 * registry is empty, the shared mint path requires the first-run setup token
 * (which this command never supplies) and the response is
 * `auth.setup_required` — see the first-run page at /admin/.
 *
 * @method POST
 * @route /management/register
 * @auth none (self-gating via the registration flag)
 * @param string $name     Public display name (how other users identify you)
 * @param string $password Plain password (min length from auth.php
 *                         registration.min_password_length, default 12)
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
// Public command: it answers before the dispatcher installs its logging
// callback, so the security log is written from here.
require_once SECURE_FOLDER_PATH . '/src/functions/securityLog.php';

function __command_register(array $params = [], array $urlParams = []): ApiResponse {
    $name = (string)($params['name'] ?? '');
    $password = (string)($params['password'] ?? '');

    $attempt = qs_auth_attempt_register($name, $password);

    if (!$attempt['ok']) {
        switch ($attempt['error']) {
            case 'registration_disabled':
                return ApiResponse::create(403, 'auth.registration_disabled')
                    ->withMessage('Self-registration is disabled on this installation');
            case 'registration_closed':
                return ApiResponse::create(403, 'auth.registration_closed')
                    ->withMessage('Registration is closed (account limit reached)');
            case 'setup_required':
                // The install has no accounts at all. Registration is not the
                // way in: the first account is created on the first-run page,
                // authorised by a setup token that only somebody with
                // filesystem access to secure/ can read.
                return ApiResponse::create(403, 'auth.setup_required')
                    ->withMessage('This installation has no accounts yet — create the first one at /admin/');
            case 'throttled':
                return ApiResponse::create(429, 'auth.throttled')
                    ->withMessage('Too many registration attempts — try again later')
                    ->withData(['retry_after' => $attempt['retry_after'] ?? 60]);
            case 'missing_fields':
                return ApiResponse::create(400, 'validation.required')
                    ->withMessage('name and password are required')
                    ->withData(['required' => ['name', 'password']]);
            case 'password_too_short':
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage('Password is too short')
                    ->withErrors(['password' => 'Minimum length: ' . ($attempt['min_length'] ?? 12)])
                    ->withData(['min_length' => $attempt['min_length'] ?? 12]);
            default:
                return ApiResponse::create(500, 'server.registration_failed')
                    ->withMessage('Could not register the account');
        }
    }

    // One entry per account created, naming the account by its id. Never the
    // username: it is the private half of a credential, and this trail is read
    // by whoever holds the server's filesystem, not by the account's owner.
    qs_security_log(
        QS_SEC_ACCOUNT_CREATED,
        ['via' => 'self_registration'],
        (string)$attempt['userId']
    );

    // The caller learns the username here and nowhere else until the account
    // first signs in, so the message says so.
    $username = (string)$attempt['username'];
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Account registered. Your username is ' . $username
            . ' — you sign in with it. It is private: save it now, because nothing gives it out again until you have signed in.')
        ->withData(['registered' => true, 'username' => $username]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_register($trimParams->params(), $trimParams->additionalParams())->send();
}
