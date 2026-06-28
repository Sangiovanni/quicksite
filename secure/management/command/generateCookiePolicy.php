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
$route = trim(str_replace('\\', '/', $route), '/');
$segments = array_values(array_filter(explode('/', $route), fn($s) => $s !== ''));
if (count($segments) < 1 || count($segments) > 5) {
    ApiResponse::create(400, 'route.invalid')
        ->withMessage('Route must have between 1 and 5 segments')
        ->send();
}
foreach ($segments as $seg) {
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $seg)) {
        ApiResponse::create(400, 'route.invalid_segment')
            ->withMessage("Invalid route segment '$seg'. Use lowercase letters, numbers and hyphens (no path parameters).")
            ->send();
    }
}

// ---- Build the page structure --------------------------------------------
$items = loadStorageRegistry()['items'];
$lang = (defined('CONFIG') && !empty(CONFIG['LANGUAGE_DEFAULT'])) ? CONFIG['LANGUAGE_DEFAULT'] : 'en';
$structure = buildCookiePolicyStructure($items, consentOAuthLinks(), $lang);

$jsonRel = '/templates/model/json/pages/' . implode('/', $segments) . '/' . end($segments) . '.json';
$phpRel  = '/templates/pages/' . implode('/', $segments) . '/' . end($segments) . '.php';
$jsonPath = PROJECT_PATH . $jsonRel;
$phpPath  = PROJECT_PATH . $phpRel;

$routes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
$overwritten = _cp_routeExists($segments, $routes);

// Confirm before overwriting an existing route (the admin shows a prompt and
// re-calls with overwrite:true).
$overwrite = !empty($params['overwrite']);
if ($overwritten && !$overwrite) {
    ApiResponse::create(409, 'route.exists')
        ->withMessage("Route /$route already exists — its content would be overwritten with the cookie-policy page.")
        ->withData(['route' => '/' . $route, 'exists' => true, 'needsConfirm' => true])
        ->send();
}

// ---- Create the route when it doesn't exist (cascade parents) ------------
if (!$overwritten) {
    $newRoutes = $routes;
    for ($i = 1; $i <= count($segments); $i++) {
        $chain = array_slice($segments, 0, $i);
        if (!_cp_routeExists($chain, $newRoutes)) {
            $newRoutes = _cp_addRoute($chain, $newRoutes);
            // Each ancestor needs a php + json file; the leaf gets the policy
            // structure written below, ancestors get a default empty page.
            if ($i < count($segments)) {
                _cp_writePage($chain, generate_page_template(implode('/', $chain)),
                    json_encode([['tag' => 'main', 'params' => ['class' => 'container'], 'children' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
    }
    // Persist routes.php + regen the client route schema.
    if (defined('ROUTES_PATH')) {
        file_put_contents(ROUTES_PATH, '<?php return ' . varExportNested($newRoutes) . '; ?>', LOCK_EX);
        if (function_exists('opcache_invalidate')) opcache_invalidate(ROUTES_PATH, true);
    }
    $schemaPath = PUBLIC_CONTENT_PATH . '/scripts/qs-route-schema.js';
    if (function_exists('writeRoutesMetaFile')) writeRoutesMetaFile($newRoutes, $schemaPath);
}

// ---- Write the leaf page (php bootstrap + policy structure) ---------------
$dir = dirname($jsonPath);
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$phpDir = dirname($phpPath);
if (!is_dir($phpDir)) @mkdir($phpDir, 0755, true);

if (!file_exists($phpPath)) {
    file_put_contents($phpPath, generate_page_template($route), LOCK_EX);
}
$jsonOk = file_put_contents($jsonPath, json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
if ($jsonOk === false) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the cookie-policy page structure')
        ->send();
}

// ---- Record the route + seed copy ----------------------------------------
saveConsentConfig(array_merge(loadConsentConfig(), ['policyRoute' => '/' . $route]));

$seed = cookiePolicyTranslationSeed();
$catSeed = consentTranslationSeed(); // category labels used by the table
$languages = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
    ? CONFIG['LANGUAGES_SUPPORTED'] : ['en'];
$languagesSeeded = [];
$languagesFallback = [];
foreach ($languages as $lc) {
    if ($lc === 'default') continue;
    if (!in_array($lc, ['en', 'fr'], true)) {
        $languagesFallback[] = $lc;
    }
    $base = ($lc === 'fr') ? $seed['fr'] : $seed['en'];
    $cats = ($lc === 'fr') ? $catSeed['fr'] : $catSeed['en'];
    // Only the category labels the table needs.
    $catKeys = ['consent.category.essential', 'consent.category.functional', 'consent.category.analytics', 'consent.category.marketing'];
    $merged = $base;
    foreach ($catKeys as $ck) {
        if (isset($cats[$ck])) $merged[$ck] = $cats[$ck];
    }
    $newOnly = consentFilterNewKeys($lc, $merged);
    if (empty($newOnly)) continue;
    $res = writeTranslationsToFile($lc, convertDotNotationToNested($newOnly), false);
    if (!empty($res['ok'])) $languagesSeeded[$lc] = $res['keysAdded'] ?? count($newOnly);
}

ApiResponse::create(200, 'success')
    ->withMessage($overwritten ? "Cookie-policy page regenerated at /$route (existing route overwritten)" : "Cookie-policy page created at /$route")
    ->withData([
        'route'             => '/' . $route,
        'overwritten'       => $overwritten,
        'rows'              => count($items),
        'languagesSeeded'   => $languagesSeeded,
        'languagesFallback' => $languagesFallback,
    ])
    ->send();

// ---- local helpers --------------------------------------------------------

function _cp_routeExists(array $segments, array $routes): bool {
    $cur = $routes;
    foreach ($segments as $s) {
        if (!is_array($cur) || !isset($cur[$s])) return false;
        $cur = $cur[$s];
    }
    return true;
}

function _cp_addRoute(array $segments, array $routes): array {
    $result = $routes;
    $cur = &$result;
    foreach ($segments as $s) {
        if (!isset($cur[$s]) || !is_array($cur[$s])) $cur[$s] = [];
        $cur = &$cur[$s];
    }
    return $result;
}

function _cp_writePage(array $segments, string $php, string $json): void {
    $name = end($segments);
    $phpPath = PROJECT_PATH . '/templates/pages/' . implode('/', $segments) . '/' . $name . '.php';
    $jsonPath = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments) . '/' . $name . '.json';
    foreach ([dirname($phpPath), dirname($jsonPath)] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    if (!file_exists($phpPath)) file_put_contents($phpPath, $php, LOCK_EX);
    if (!file_exists($jsonPath)) file_put_contents($jsonPath, $json, LOCK_EX);
}
