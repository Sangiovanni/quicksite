<?php
/**
 * setCollectedDatum Command — create or update a "data collected" entry in the
 * privacy registry. The id is a stable slug (atoms map to it); the label +
 * purpose are prose authored in the registry description language and stored in
 * translate/ (privacy.collected.<id>.label / .purpose).
 *
 * @method POST
 * @route  /management/setCollectedDatum
 * @auth   required (write permission)
 *
 * Body:
 *   { "id": "email", "label": "Email address", "purpose": "To send login links" }
 *   id + label required; purpose optional (empty clears it).
 *
 * @return ApiResponse 201/200 { datum: { id, label, purpose } } | 400
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
if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $id)) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage("Invalid id '$id'. Use letters, numbers, hyphens and underscores (no spaces).")
        ->withErrors([['field' => 'id', 'reason' => 'invalid_format']])
        ->send();
}

$label = $params['label'] ?? null;
if (!is_string($label) || trim($label) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A label is required')
        ->withErrors([['field' => 'label', 'reason' => 'missing']])
        ->send();
}
$label = trim($label);
$purpose = is_string($params['purpose'] ?? null) ? trim($params['purpose']) : '';

$reg = loadPrivacyRegistry();
$descLang = privacyDescLang($reg);
$isNew = !in_array($id, $reg['collectedData'], true);

privacyAddDatum($reg, $id);
if (!savePrivacyRegistry($reg)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}

// Prose into translate/ (description language). Empty purpose clears its key.
privacyWriteDatumText($id, $descLang, $label, $purpose !== '' ? $purpose : null);
if ($purpose === '') {
    translationUnsetKey($descLang, privacyPurposeKey($id));
}

ApiResponse::create($isNew ? 201 : 200, $isNew ? 'created' : 'success')
    ->withMessage($isNew ? "Collected datum '{$id}' created" : "Collected datum '{$id}' updated")
    ->withData(['datum' => ['id' => $id, 'label' => $label, 'purpose' => $purpose]])
    ->send();
