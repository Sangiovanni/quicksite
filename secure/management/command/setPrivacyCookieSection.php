<?php
/**
 * setPrivacyCookieSection Command — set how the privacy page treats cookies:
 * "auto" (link the cookie policy if it exists, else hint) or "omit" (no cookie
 * section at all — for sites that handle cookies elsewhere or use none).
 *
 * @method POST
 * @route  /management/setPrivacyCookieSection
 * @auth   required (write permission)
 *
 * Body: { "cookieSection": "auto" | "omit" }
 *
 * @return ApiResponse 200 { cookieSection } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';

$params = $trimParametersManagement->params();

$value = $params['cookieSection'] ?? null;
if (!in_array($value, PRIVACY_COOKIE_SECTION, true)) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage('cookieSection must be "auto" or "omit"')
        ->withErrors([['field' => 'cookieSection', 'reason' => 'invalid', 'expected' => implode('|', PRIVACY_COOKIE_SECTION)]])
        ->send();
}

$reg = loadPrivacyRegistry();
$reg['cookieSection'] = $value;
if (!savePrivacyRegistry($reg)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}

ApiResponse::create(200, 'success')
    ->withMessage("Cookie section set to '$value'")
    ->withData(['cookieSection' => $value])
    ->send();
