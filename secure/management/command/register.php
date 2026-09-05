<?php
/**
 * register Command (C8; username identity 8.0b)
 *
 * Self-registration: creates a user account from a public display name + a
 * private username + password. PUBLIC + self-gating (listed in the
 * dispatcher's $PUBLIC_COMMANDS) — the command enforces the auth.php
 * `registration.allow_self_registration` flag SERVER-SIDE (default: disabled)
 * plus the registration flood controls (per-IP rate, install-wide hourly cap,
 * absolute account cap).
 *
 * Enumeration safety: the username is the PRIVATE login identifier, so a
 * duplicate username returns the SAME success response as a real creation
 * (nothing is created, the bcrypt cost is still burned) — no account-existence
 * oracle. No session and no user id are returned; the new user signs in
 * through `login`.
 *
 * This command CANNOT create the first account on an install. While the user
 * registry is empty, the shared mint path requires the first-run setup token
 * (which this command never supplies) and the response is
 * `auth.setup_required` — see the first-run page at /admin/.
 *
 * @method POST
 * @route /management/register
 * @auth none (self-gating via the registration flag)
 * @param string $name     Public display name (how other users identify you;
 *                         must differ from the private username)
 * @param string $username Private login identifier (unique; 3–32 chars,
 *                         lowercase letters / digits / '-' / '_')
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
    $username = (string)($params['username'] ?? '');
    $password = (string)($params['password'] ?? '');

    $attempt = qs_auth_attempt_register($name, $username, $password);

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
                    ->withMessage('name, username and password are required')
                    ->withData(['required' => ['name', 'username', 'password']]);
            case 'invalid_username':
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage('Invalid username')
                    ->withErrors(['username' => 'Use 3-32 characters: lowercase letters, digits, dash or underscore']);
            case 'name_equals_username':
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage('Your public name must be different from your username')
                    ->withErrors(['name' => 'Must differ from your username (the username is private)']);
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

    // Recorded ONLY when an account was really created. The response above is
    // deliberately identical either way, so a duplicate username must leave no
    // trace here either — a log entry per attempt would rebuild, on disk, the
    // account-existence oracle the uniform response exists to deny. The log is
    // server-side and never reaches the caller, but an operator reading two
    // entries where one account exists would be reading a wrong trail.
    if (!empty($attempt['created'])) {
        qs_security_log(
            QS_SEC_ACCOUNT_CREATED,
            ['via' => 'self_registration'],
            isset($attempt['userId']) ? (string)$attempt['userId'] : null
        );
    }

    // UNIFORM success — identical whether the account was created or the
    // username already belonged to someone (attempt['created'] must never
    // leak here).
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Account registered — you can now sign in')
        ->withData(['registered' => true]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_register($trimParams->params(), $trimParams->additionalParams())->send();
}
