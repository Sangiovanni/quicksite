<?php
/**
 * addStorageItem Command — declare a new storage key in the registry.
 *
 * @method POST
 * @route  /management/addStorageItem
 * @auth   required (write permission)
 *
 * Body:
 *   {
 *     "id":          "authToken",                      required (the key name)
 *     "scope":       "localStorage|sessionStorage|cookie", required
 *     "category":    "essential|functional|analytics|marketing", required
 *     "description": { "en": "..." },                  optional (translatable)
 *     "retention":   "session" | "30d" | "1y" | "...", optional
 *     // cookie scope only:
 *     "domain": "auto", "path": "/", "secure": true, "sameSite": "Lax"
 *   }
 *
 * @return ApiResponse 201 { item } | 400 validation | 409 duplicate | 500
 *
 * @example
 *   {"id":"cartSession","scope":"localStorage","category":"functional",
 *    "description":{"en":"Saved shopping cart"},"retention":"30d"}
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

if (isset($registry['items'][$id])) {
    ApiResponse::create(409, 'storage.duplicate')
        ->withMessage("A storage item '{$id}' already exists — use editStorageItem to change it")
        ->withData(['id' => $id])
        ->send();
}

$result = validateStorageItem($id, $params);
if (!empty($result['errors'])) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage('Invalid storage item — see errors')
        ->withErrors($result['errors'])
        ->send();
}

$registry['items'][$id] = $result['item'];
if (!saveStorageRegistry($registry)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the storage registry')
        ->send();
}

$stored = $result['item'];
$stored['id'] = $id;
$stored['consentRequired'] = storageConsentRequired($result['item']);

ApiResponse::create(201, 'storage.created')
    ->withMessage("Storage item '{$id}' declared")
    ->withData(['item' => $stored])
    ->send();
