<?php
/**
 * getPrivacyStatus Command — full privacy-helper state for /admin/privacy.
 *
 * @method POST
 * @route  /management/getPrivacyStatus
 * @auth   required (read permission)
 *
 * Joins the privacy registry (collected data, host classifications, cookie
 * section, route) with the live API request-schema scan + coverage (unmapped
 * atoms, undeclared-body endpoints, unclassified hosts) and the cookie-page
 * cross-link signal.
 *
 * @return ApiResponse 200 { descLang, languages, collectedData, hosts,
 *                           endpoints, coverage, privacyRoute, privacyRouteExists,
 *                           cookieSection, cookie }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyScanHelpers.php';

ApiResponse::create(200, 'success')
    ->withMessage('Privacy status')
    ->withData(privacyBuildStatus())
    ->send();
