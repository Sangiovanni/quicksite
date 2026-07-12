<?php
/**
 * register Command (C8)
 *
 * Self-registration: creates a user account from name + email + password.
 * PUBLIC + self-gating (listed in the dispatcher's $PUBLIC_COMMANDS) — the
 * command enforces the auth.php `registration.allow_self_registration` flag
 * SERVER-SIDE (default: disabled) plus the registration flood controls
 * (per-IP rate, install-wide hourly cap, absolute account cap).
 *
 * Enumeration safety: a duplicate email returns the SAME success response as
 * a real creation (nothing is created, the bcrypt cost is still burned) — no
 * account-existence oracle. No session and no user id are returned; the new
 * user signs in through `login`.
 *
 * @method POST
 * @route /management/register
 * @auth none (self-gating via the registration flag)
 * @param string $name     Display name
 * @param string $email    Login identifier (unique, valid email)
 * @param string $password Plain password (min length from auth.php
 *                         registration.min_password_length, default 12)
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

function __command_register(array $params = [], array $urlParams = []): ApiResponse {
    $name = (string)($params['name'] ?? '');
    $email = (string)($params['email'] ?? '');
    $password = (string)($params['password'] ?? '');

    $attempt = qs_auth_attempt_register($name, $email, $password);

    if (!$attempt['ok']) {
        switch ($attempt['error']) {
            case 'registration_disabled':
                return ApiResponse::create(403, 'auth.registration_disabled')
                    ->withMessage('Self-registration is disabled on this installation');
            case 'registration_closed':
                return ApiResponse::create(403, 'auth.registration_closed')
                    ->withMessage('Registration is closed (account limit reached)');
            case 'throttled':
                return ApiResponse::create(429, 'auth.throttled')
                    ->withMessage('Too many registration attempts — try again later')
                    ->withData(['retry_after' => $attempt['retry_after'] ?? 60]);
            case 'missing_fields':
                return ApiResponse::create(400, 'validation.required')
                    ->withMessage('name, email and password are required')
                    ->withData(['required' => ['name', 'email', 'password']]);
            case 'invalid_email':
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage('Invalid email address')
                    ->withErrors(['email' => 'Must be a valid email address']);
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

    // UNIFORM success — identical whether the account was created or the email
    // already belonged to someone (attempt['created'] must never leak here).
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
