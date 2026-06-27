<?php
/**
 * editStorageItem Command — update (and optionally rename) a storage item.
 *
 * @method POST
 * @route  /management/editStorageItem
 * @auth   required (write permission)
 *
 * Replace-all semantic on the item fields (read the current entry first if you
 * want a field-level merge). Pass `newId` to rename.
 *
 * Body: { id, newId?, scope, category, description?, retention?, + cookie fields }
 *
 * @return ApiResponse 200 { item } | 400 | 404 not_found | 409 duplicate | 500
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';

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
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the storage registry')
        ->send();
}

$stored = $result['item'];
$stored['id'] = $targetId;
$stored['consentRequired'] = storageConsentRequired($result['item']);

ApiResponse::create(200, 'storage.updated')
    ->withMessage("Storage item '{$targetId}' updated" . ($targetId !== $id ? " (renamed from '{$id}')" : ''))
    ->withData(['item' => $stored, 'renamedFrom' => ($targetId !== $id ? $id : null)])
    ->send();
