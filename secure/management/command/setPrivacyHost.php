<?php
/**
 * setPrivacyHost Command — classify an API host (baseUrl) as a server you operate
 * or a third party. Third parties carry an optional display name + privacy-policy
 * URL (rendered on the privacy page's data-sharing section).
 *
 * @method POST
 * @route  /management/setPrivacyHost
 * @auth   required (write permission)
 *
 * Body:
 *   { "baseUrl": "https://hooks.x.com", "kind": "third-party",
 *     "name": "X", "privacyUrl": "https://x.com/privacy" }
 *   kind: "self" | "third-party". name/privacyUrl apply to third parties only.
 *
 * @return ApiResponse 200 { host: { baseUrl, kind, name?, privacyUrl? } } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';

$params = $trimParametersManagement->params();

$baseUrl = $params['baseUrl'] ?? null;
if (!is_string($baseUrl) || trim($baseUrl) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A baseUrl is required')
        ->withErrors([['field' => 'baseUrl', 'reason' => 'missing']])
        ->send();
}
$baseUrl = trim($baseUrl);

$kind = $params['kind'] ?? null;
if (!in_array($kind, PRIVACY_HOST_KINDS, true)) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage('kind must be "self" or "third-party"')
        ->withErrors([['field' => 'kind', 'reason' => 'invalid', 'expected' => implode('|', PRIVACY_HOST_KINDS)]])
        ->send();
}

$name = is_string($params['name'] ?? null) ? trim($params['name']) : null;
$privacyUrl = is_string($params['privacyUrl'] ?? null) ? trim($params['privacyUrl']) : null;

$reg = loadPrivacyRegistry();
privacySetHost($reg, $baseUrl, $kind, $name, $privacyUrl);
if (!savePrivacyRegistry($reg)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}

ApiResponse::create(200, 'success')
    ->withMessage("Host '{$baseUrl}' classified as " . ($kind === 'self' ? 'your server' : 'third party'))
    ->withData(['host' => array_merge(['baseUrl' => $baseUrl], $reg['hosts'][$baseUrl])])
    ->send();
