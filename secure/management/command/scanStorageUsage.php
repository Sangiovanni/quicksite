<?php
/**
 * scanStorageUsage Command — scan the build for storage-key references and
 * reconcile them against the declared registry.
 *
 * @method POST
 * @route  /management/scanStorageUsage
 * @auth   required (read permission)
 *
 * The triggered, warn-style check (NOT blocking) that surfaces undeclared
 * keys + dangling reads. Walks structures (data-storage-* attrs +
 * saveToken/store/clearToken chains), api-endpoints auth sources, and
 * state-store init. See storageScanHelpers.php.
 *
 * @return ApiResponse 200 { buckets: {ok, incomplete, dangling_read, orphan}, counts }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageScanHelpers.php';

$result = scanStorageUsage();
$c = $result['counts'];

ApiResponse::create(200, 'success')
    ->withMessage("{$c['incomplete']} undeclared, {$c['dangling_read']} dangling-read, {$c['orphan']} orphan, {$c['ok']} ok")
    ->withData($result)
    ->send();
