<?php
/**
 * deleteStorageItem Command — remove a storage item from the registry.
 *
 * @method POST
 * @route  /management/deleteStorageItem
 * @auth   required (write permission)
 *
 * Body: { id }
 *
 * @return ApiResponse 200 { id } | 400 | 404 not_found | 500
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
        ->withMessage("No storage item '{$id}' to delete")
        ->withData(['id' => $id])
        ->send();
}

unset($registry['items'][$id]);

if (!saveStorageRegistry($registry)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the storage registry')
        ->send();
}

ApiResponse::create(200, 'storage.deleted')
    ->withMessage("Storage item '{$id}' deleted")
    ->withData(['id' => $id])
    ->send();
