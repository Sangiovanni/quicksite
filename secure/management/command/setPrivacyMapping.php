<?php
/**
 * setPrivacyMapping Command — map a scanned atom (endpoint, field) to a collected
 * datum, or unset it. The recipient (your server / a third party) is derived from
 * the endpoint's host classification, never stored here.
 *
 * @method POST
 * @route  /management/setPrivacyMapping
 * @auth   required (write permission)
 *
 * Body:
 *   { "endpoint": "apiId/endpointId", "field": "email", "datum": "email" }
 *   datum empty / null / "__unset__" clears the mapping.
 *
 * @return ApiResponse 200 { endpoint, field, datum } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';

$params = $trimParametersManagement->params();

$endpoint = $params['endpoint'] ?? null;
$field = $params['field'] ?? null;
if (!is_string($endpoint) || trim($endpoint) === '' || !is_string($field) || trim($field) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('endpoint and field are required')
        ->withErrors([['field' => 'endpoint|field', 'reason' => 'missing']])
        ->send();
}
$endpoint = trim($endpoint);
$field = trim($field);

// Normalise the datum: empty / null / sentinel → unset.
$datumRaw = $params['datum'] ?? null;
$datum = (is_string($datumRaw) && trim($datumRaw) !== '' && $datumRaw !== '__unset__') ? trim($datumRaw) : null;

$reg = loadPrivacyRegistry();

// A non-null datum must be a declared collected-data id.
if ($datum !== null && !in_array($datum, $reg['collectedData'], true)) {
    ApiResponse::create(400, 'privacy.unknown_datum')
        ->withMessage("No collected datum '{$datum}' — create it first")
        ->withData(['datum' => $datum])
        ->send();
}

privacySetMapping($reg, $endpoint, $field, $datum);
if (!savePrivacyRegistry($reg)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}

ApiResponse::create(200, 'success')
    ->withMessage($datum !== null ? "Mapped {$endpoint}.{$field} → {$datum}" : "Unset {$endpoint}.{$field}")
    ->withData(['endpoint' => $endpoint, 'field' => $field, 'datum' => $datum])
    ->send();
