<?php
/**
 * setCollectedDatum Command — create or update a "data collected" entry in the
 * privacy registry. The id is a stable slug (atoms map to it); the label +
 * purpose are prose stored in translate/ (privacy.collected.<id>.label /
 * .purpose), each language keeping its own words: a call writes one language's.
 *
 * @method POST
 * @route  /management/setCollectedDatum
 * @auth   required (write permission)
 *
 * Body:
 *   { "id": "email", "label": "Email address", "purpose": "To send login links", "lang": "en" }
 *   id + label required; purpose optional (empty removes that language's purpose);
 *   lang optional: one of the project's languages, the default language when absent.
 *   Every other language's words are left as they are.
 *
 * @return ApiResponse 201/200 { datum: { id, label, purpose, lang } } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

$params = $trimParametersManagement->params();

$id = $params['id'] ?? null;
if (!is_string($id) || trim($id) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A collected-data id is required')
        ->withErrors([['field' => 'id', 'reason' => 'missing']])
        ->send();
}
$id = trim($id);
if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/iD', $id)) {
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

// The language written: a code is part of a file name, so only the project's own are taken.
$lang = $params['lang'] ?? null;
if ($lang !== null && !qs_project_has_language($lang)) {
    qs_language_not_in_project_response($lang, 'lang')->send();
}
$lang = $lang ?? qs_project_default_language();

$reg = loadPrivacyRegistry();
$isNew = !in_array($id, $reg['collectedData'], true);

privacyAddDatum($reg, $id);
if (!savePrivacyRegistry($reg)) {
    qs_small_write_send_refusal();
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}

// Prose into translate/, this language's alone. An empty purpose removes this
// language's purpose (and its copy where the shared writer keeps one). A refused
// write takes back the registry above too.
$refused = privacyWriteDatumText($id, $lang, $label, $purpose !== '' ? $purpose : null);
if ($refused !== null) {
    qs_translation_write_refusal_response($refused)->send();
}
if ($purpose === '') {
    qs_translation_remove_word($lang, privacyPurposeKey($id));
}
qs_small_write_send_refusal();

ApiResponse::create($isNew ? 201 : 200, $isNew ? 'created' : 'success')
    ->withMessage($isNew ? "Collected datum '{$id}' created" : "Collected datum '{$id}' updated")
    ->withData(['datum' => ['id' => $id, 'label' => $label, 'purpose' => $purpose, 'lang' => $lang]])
    ->send();
