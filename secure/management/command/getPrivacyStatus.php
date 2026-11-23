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
 * Body (optional): { "lang": "fr" } — the language the collected data's label and
 * purpose are answered in, as its pages show them: one of the project's languages,
 * the project's default language when absent. Each language keeps its own words; a
 * language with none answers ''.
 *
 * @return ApiResponse 200 { lang, defaultLanguage, languages, languageNames,
 *                           collectedData, hosts, endpoints, coverage, privacyRoute,
 *                           privacyRouteExists, cookieSection, cookie } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyScanHelpers.php';

$params = $trimParametersManagement->params();

$lang = $params['lang'] ?? null;
if ($lang !== null && !qs_project_has_language($lang)) {
    qs_language_not_in_project_response($lang, 'lang')->send();
}

ApiResponse::create(200, 'success')
    ->withMessage('Privacy status')
    ->withData(privacyBuildStatus($lang))
    ->send();
