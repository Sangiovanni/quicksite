<?php
/**
 * listStorageItems Command — return the project's storage registry.
 *
 * @method POST
 * @route  /management/listStorageItems
 * @auth   required (read permission)
 *
 * Returns every declared storage item with its derived `consentRequired`,
 * plus the `scopes` + `categories` enums so the admin page can drive its
 * pickers from the single source of truth.
 *
 * @return ApiResponse 200 { items: [...], count, scopes, categories }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';

$registry = loadStorageRegistry();

$out = [];
foreach ($registry['items'] as $id => $item) {
    if (!is_array($item)) {
        continue;
    }
    $row = $item;
    $row['id'] = $id;
    $row['consentRequired'] = storageConsentRequired($item);
    $out[] = $row;
}

ApiResponse::create(200, 'success')
    ->withMessage(count($out) . ' storage item' . (count($out) === 1 ? '' : 's'))
    ->withData([
        'items'      => $out,
        'count'      => count($out),
        'scopes'     => STORAGE_SCOPES,
        'categories' => STORAGE_CATEGORIES,
    ])
    ->send();
