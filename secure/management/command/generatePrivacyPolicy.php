<?php
/**
 * generatePrivacyPolicy Command — generate (or overwrite) the privacy-policy page
 * at an author-chosen route, deterministically from the privacy registry + the
 * API scan.
 *
 * @method POST
 * @route  /management/generatePrivacyPolicy
 * @auth   required (write permission)
 *
 * Body: { "route": "privacy", "overwrite": true? }
 *
 * Builds: a "data we collect" table (collected-data label/purpose), a per-third-
 * party "data sharing" section (derived from atom mappings + host classification),
 * an OAuth sign-in section, a cookie cross-link (link / hint / omit per
 * cookieSection), and a legal disclaimer. Records the route in data/privacy.json
 * and seeds default-language structural copy.
 *
 * @return ApiResponse 200 { route, overwritten, collected, thirdParties, languagesSeeded }
 *         | 409 route.exists | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/policyPageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyScanHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyPageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

$params = $trimParametersManagement->params();

// ---- Validate route -------------------------------------------------------
$route = $params['route'] ?? null;
if (!is_string($route) || trim($route) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A route is required for the privacy-policy page')
        ->withErrors([['field' => 'route', 'reason' => 'missing']])
        ->send();
}
$valid = policyValidateRoute($route);
if ($valid['error']) {
    ApiResponse::create(400, $valid['error']['code'])->withMessage($valid['error']['message'])->send();
}
$route = $valid['route'];
$segments = $valid['segments'];

// ---- Gather the page data from registry + scan ----------------------------
$reg = loadPrivacyRegistry();
$collected = $reg['collectedData'];

// Per-third-party sharing: datums sent to each third-party host (derived).
$byHost = [];
foreach (privacyScanEndpoints() as $ep) {
    $host = $ep['baseUrl'];
    if ($host === '') continue;
    foreach ($ep['fields'] as $field) {
        $d = privacyGetMapping($reg, $ep['key'], $field);
        if ($d !== null) $byHost[$host][$d] = true;
    }
}
$sharing = [];
foreach ($byHost as $host => $datumSet) {
    if (privacyHostKind($reg, $host) !== 'third-party') continue;
    $h = $reg['hosts'][$host] ?? [];
    $sharing[] = [
        'name'     => ($h['name'] ?? '') !== '' ? $h['name'] : $host,
        'url'      => $h['privacyUrl'] ?? null,
        'datumIds' => array_keys($datumSet),
    ];
}

// Cookie cross-link mode.
$cookieCfg = loadConsentConfig();
$cookieRoute = $cookieCfg['policyRoute'] ?? null;
$cookieExists = (is_string($cookieRoute) && trim($cookieRoute, '/') !== '') ? consentRouteExists($cookieRoute) : false;
$cookieMode = ($reg['cookieSection'] === 'omit') ? 'omit' : ($cookieExists ? 'link' : 'hint');

$structure = buildPrivacyPolicyStructure($collected, $sharing, consentOAuthLinks(), ['mode' => $cookieMode, 'route' => $cookieRoute]);

// ---- Overwrite confirm + route create + leaf write ------------------------
$routes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
$overwritten = policyRouteExists($segments, $routes);
if ($overwritten && empty($params['overwrite'])) {
    ApiResponse::create(409, 'route.exists')
        ->withMessage("Route /$route already exists — its content would be overwritten with the privacy-policy page.")
        ->withData(['route' => '/' . $route, 'exists' => true, 'needsConfirm' => true])
        ->send();
}
if (!$overwritten) {
    policyCreateRoute($segments);
}
if (!policyWriteLeaf($segments, $structure)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy-policy page structure')
        ->send();
}

// ---- Record the route + seed structural copy (default language only) ------
$reg['privacyRoute'] = '/' . $route;
savePrivacyRegistry($reg);

$seed = privacyPolicyTranslationSeed();
$defaultLang = (defined('CONFIG') && isset(CONFIG['LANGUAGE_DEFAULT']) && is_string(CONFIG['LANGUAGE_DEFAULT']))
    ? CONFIG['LANGUAGE_DEFAULT'] : 'en';
$languagesSeeded = [];
$flat = ($defaultLang === 'fr') ? $seed['fr'] : $seed['en'];
$newOnly = consentFilterNewKeys($defaultLang, $flat);
if (!empty($newOnly)) {
    $res = writeTranslationsToFile($defaultLang, convertDotNotationToNested($newOnly), false);
    if (!empty($res['ok'])) $languagesSeeded[$defaultLang] = $res['keysAdded'] ?? count($newOnly);
}

ApiResponse::create(200, 'success')
    ->withMessage($overwritten ? "Privacy-policy page regenerated at /$route (existing route overwritten)" : "Privacy-policy page created at /$route")
    ->withData([
        'route'           => '/' . $route,
        'overwritten'     => $overwritten,
        'collected'       => count($collected),
        'thirdParties'    => count($sharing),
        'cookieMode'      => $cookieMode,
        'languagesSeeded' => $languagesSeeded,
    ])
    ->send();
