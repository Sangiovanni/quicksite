<?php
/**
 * editStorageItem Command — update (and optionally rename) a storage item.
 *
 * @method POST
 * @route  /management/editStorageItem
 * @auth   required (write permission)
 *
 * Replace-all semantic on the item's registry fields (read the current entry
 * first if you want a field-level merge). Pass `newId` to rename.
 *
 * The description is each language's own word, and a save touches only the
 * languages it names: absent, every word stays (a rename carries every language's
 * word to the new key); a plain string is the default language's word; a
 * {lang: text} map writes each language named (every one a language of the
 * project), an empty text removing that language's word alone.
 *
 * Body: { id, newId?, scope, category, description?, retention?, + cookie fields }
 *
 * @return ApiResponse 200 { item } | 400 | 404 not_found | 409 duplicate | 500
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

$params = $trimParametersManagement->params();

$id = $params['id'] ?? null;
if (is_int($id) || is_float($id)) {
    $id = (string) $id;
}
if (!is_string($id) || $id === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Storage item id is required')
        ->withErrors([['field' => 'id', 'reason' => 'missing']])
        ->send();
}

$registry = loadStorageRegistry();

// Converge any not-yet-keyed descriptions into translate/ before writing. A refused
// write is answered with everything it wrote put back.
$migrated = storageMigrateInlineDescriptions($registry);
if ($migrated['refused'] !== null) {
    qs_translation_write_refusal_response($migrated['refused'])->send();
}

if (!isset($registry['items'][$id])) {
    ApiResponse::create(404, 'storage.not_found')
        ->withMessage("No storage item '{$id}' — use addStorageItem to create it")
        ->withData(['id' => $id])
        ->send();
}

// Optional rename.
$newId = $params['newId'] ?? null;
$targetId = $id;
if (is_string($newId) && $newId !== '' && $newId !== $id) {
    if (isset($registry['items'][$newId])) {
        ApiResponse::create(409, 'storage.duplicate')
            ->withMessage("Cannot rename to '{$newId}' — an item with that id already exists")
            ->withData(['id' => $newId])
            ->send();
    }
    $targetId = $newId;
}

$result = validateStorageItem($targetId, $params);
if (!empty($result['errors'])) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage('Invalid storage item — see errors')
        ->withErrors($result['errors'])
        ->send();
}

if ($targetId !== $id) {
    unset($registry['items'][$id]);
}
$registry['items'][$targetId] = $result['item'];

if (!saveStorageRegistry($registry)) {
    qs_small_write_send_refusal();
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the storage registry')
        ->send();
}

// The description in translate/ (keyed), language by language. A rename first
// carries every language's word to the new key; then each language the save
// names gets its word, or loses it for an empty text; a language not named keeps
// its word. A refused write takes back the registry above too, and every word
// written.
if ($targetId !== $id) {
    $refused = storageRenameDescription($id, $targetId);
    if ($refused !== null) {
        qs_translation_write_refusal_response($refused)->send();
    }
}
if ($result['description'] !== null) {
    $refused = storageWriteDescription($targetId, $result['description']);
    if ($refused !== null) {
        qs_translation_write_refusal_response($refused)->send();
    }
}
qs_small_write_send_refusal();

$stored = $result['item'];
$stored['id'] = $targetId;
$stored['consentRequired'] = storageConsentRequired($result['item']);
$written = array_filter($result['description'] ?? [], fn($t) => $t !== '');
if (!empty($written)) {
    $stored['description'] = $written; // echo for the admin card
}

ApiResponse::create(200, 'storage.updated')
    ->withMessage("Storage item '{$targetId}' updated" . ($targetId !== $id ? " (renamed from '{$id}')" : ''))
    ->withData(['item' => $stored, 'renamedFrom' => ($targetId !== $id ? $id : null)])
    ->send();
