<?php
/**
 * generateCookiePolicy Command — generate (or overwrite) the cookie-policy page
 * at an author-chosen route, as a deterministic table from the registry.
 *
 * @method POST
 * @route  /management/generateCookiePolicy
 * @auth   required (write permission)
 *
 * Body:
 *   { "route": "cookies" }   required — the route to host the policy page
 *
 * Builds a page structure (one table row per declared key + an OAuth-provider
 * privacy-link section + a legal-review note) and writes it at the route. If
 * the route already exists, its structure is OVERWRITTEN (warned). Seeds EN/FR
 * copy (new keys only) and records the route in data/consent.json so the banner
 * links to it.
 *
 * @return ApiResponse 200 { route, overwritten, rows, languagesSeeded }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/policyPageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

$params = $trimParametersManagement->params();

// ---- Validate route -------------------------------------------------------
$route = $params['route'] ?? null;
if (!is_string($route) || trim($route) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A route is required for the cookie-policy page')
        ->withErrors([['field' => 'route', 'reason' => 'missing']])
        ->send();
}
$valid = policyValidateRoute($route);
if ($valid['error']) {
    ApiResponse::create(400, $valid['error']['code'])->withMessage($valid['error']['message'])->send();
}
$route = $valid['route'];
$segments = $valid['segments'];

// ---- Build the page structure --------------------------------------------
// Ensure every item's description is keyed in translate/ before the page
// references storageDescKey() — converges any not-yet-migrated inline copies.
$registry = loadStorageRegistry();
if (storageMigrateInlineDescriptions($registry)) {
    saveStorageRegistry($registry);
}
$items = $registry['items'];
$structure = buildCookiePolicyStructure($items, consentOAuthLinks());

$routes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
$overwritten = policyRouteExists($segments, $routes);

// Confirm before overwriting an existing route (the admin shows a prompt and
// re-calls with overwrite:true).
$overwrite = !empty($params['overwrite']);
if ($overwritten && !$overwrite) {
    ApiResponse::create(409, 'route.exists')
        ->withMessage("Route /$route already exists — its content would be overwritten with the cookie-policy page.")
        ->withData(['route' => '/' . $route, 'exists' => true, 'needsConfirm' => true])
        ->send();
}

// ---- Create the route (cascade parents) + write the leaf page -------------
if (!$overwritten) {
    policyCreateRoute($segments);
}
if (!policyWriteLeaf($segments, $structure)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the cookie-policy page structure')
        ->send();
}

// ---- Record the route + seed copy ----------------------------------------
saveConsentConfig(array_merge(loadConsentConfig(), ['policyRoute' => '/' . $route]));

// Seed structural copy (NEW keys only) for the project DEFAULT language only.
// Other languages are translated via the Translation Manager (the textKeys
// surface there as missing). Per-item descriptions are NOT seeded here — they
// live independently in translate/ under storageDescKey(), authored via the
// storage registry and resolved live.
$seed = cookiePolicyTranslationSeed();
$catSeed = consentTranslationSeed(); // category labels used by the table
$defaultLang = (defined('CONFIG') && isset(CONFIG['LANGUAGE_DEFAULT']) && is_string(CONFIG['LANGUAGE_DEFAULT']))
    ? CONFIG['LANGUAGE_DEFAULT'] : 'en';
$languagesSeeded = [];
$base = ($defaultLang === 'fr') ? $seed['fr'] : $seed['en'];
$cats = ($defaultLang === 'fr') ? $catSeed['fr'] : $catSeed['en'];
$catKeys = ['consent.category.essential', 'consent.category.functional', 'consent.category.analytics', 'consent.category.marketing'];
$structural = $base;
foreach ($catKeys as $ck) {
    if (isset($cats[$ck])) $structural[$ck] = $cats[$ck];
}
$newOnly = consentFilterNewKeys($defaultLang, $structural);
if (!empty($newOnly)) {
    $res = writeTranslationsToFile($defaultLang, convertDotNotationToNested($newOnly), false);
    if (!empty($res['ok'])) $languagesSeeded[$defaultLang] = $res['keysAdded'] ?? count($newOnly);
}

ApiResponse::create(200, 'success')
    ->withMessage($overwritten ? "Cookie-policy page regenerated at /$route (existing route overwritten)" : "Cookie-policy page created at /$route")
    ->withData([
        'route'           => '/' . $route,
        'overwritten'     => $overwritten,
        'rows'            => count($items),
        'languagesSeeded' => $languagesSeeded,
    ])
    ->send();
