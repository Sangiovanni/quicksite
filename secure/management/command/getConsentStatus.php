<?php
/**
 * getConsentStatus Command — current state of the consent layer for the active
 * project. Drives the /admin/storage "Generate consent layer" modal so it can
 * pre-fill / lock the policy route and switch Generate ↔ Update ↔ Delete.
 *
 * @method POST
 * @route  /management/getConsentStatus
 * @auth   required (read permission)
 *
 * @return ApiResponse 200 { enabled, generated, policyRoute, policyRouteExists }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';

$cfg = loadConsentConfig();
$policyRoute = $cfg['policyRoute'] ?? null;
$policyRouteExists = (is_string($policyRoute) && trim($policyRoute, '/') !== '')
    ? consentRouteExists($policyRoute)
    : false;

ApiResponse::create(200, 'success')
    ->withMessage('Consent status')
    ->withData([
        'enabled'           => !empty($cfg['enabled']),
        'generated'         => consentLayerGenerated(),
        'policyRoute'       => $policyRoute,
        'policyRouteExists' => $policyRouteExists,
    ])
    ->send();
