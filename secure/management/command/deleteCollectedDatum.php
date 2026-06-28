<?php
/**
 * deleteCollectedDatum Command — remove a "data collected" entry. Nulls any atom
 * mappings that pointed at it and clears its label/purpose keys from translate/.
 *
 * @method POST
 * @route  /management/deleteCollectedDatum
 * @auth   required (write permission)
 *
 * Body: { "id": "email" }
 *
 * @return ApiResponse 200 { id } | 400 | 404
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';

$params = $trimParametersManagement->params();

$id = $params['id'] ?? null;
if (!is_string($id) || trim($id) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A collected-data id is required')
        ->withErrors([['field' => 'id', 'reason' => 'missing']])
        ->send();
}
$id = trim($id);

$reg = loadPrivacyRegistry();
if (!in_array($id, $reg['collectedData'], true)) {
    ApiResponse::create(404, 'privacy.not_found')
        ->withMessage("No collected datum '{$id}'")
        ->withData(['id' => $id])
        ->send();
}

privacyRemoveDatum($reg, $id);
if (!savePrivacyRegistry($reg)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}
privacyClearDatumText($id);

ApiResponse::create(200, 'success')
    ->withMessage("Collected datum '{$id}' deleted")
    ->withData(['id' => $id])
    ->send();
