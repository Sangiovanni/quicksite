<?php
/**
 * generateConsentLayer Command — seed (or re-seed) the consent banner + popup
 * structures from the registry and enable the consent layer.
 *
 * @method POST
 * @route  /management/generateConsentLayer
 * @auth   required (write permission)
 *
 * Body (all optional):
 *   { "policyRoute": "/cookies" }   // route the cookie-policy page lives at
 *
 * Writes templates/model/json/consent-banner.json + consent-popup.json (one
 * popup toggle row per DECLARED non-essential category), seeds EN/FR default
 * copy for the textKeys (NEW keys only — never clobbers edited copy), and sets
 * data/consent.json enabled=true. Idempotent: re-running refreshes the
 * structures + re-enables, preserving existing translations.
 *
 * @return ApiResponse 200 { categories, languagesSeeded, policyRoute }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

$params = $trimParametersManagement->params();

// Resolve the policy route: explicit param → existing config → null.
$config = loadConsentConfig();
$policyRoute = $params['policyRoute'] ?? ($config['policyRoute'] ?? null);
if (is_string($policyRoute)) {
    $policyRoute = trim($policyRoute);
    if ($policyRoute === '') $policyRoute = null;
} else {
    $policyRoute = null;
}

$categories = consentDeclaredCategories();

// 1. Build + write the two structure files.
$banner = buildConsentBannerStructure($policyRoute);
$popup  = buildConsentPopupStructure($categories);

$dir = dirname(consentBannerPath());
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$wroteBanner = file_put_contents(consentBannerPath(), json_encode($banner, $jsonFlags) . "\n", LOCK_EX);
$wrotePopup  = file_put_contents(consentPopupPath(),  json_encode($popup,  $jsonFlags) . "\n", LOCK_EX);
if ($wroteBanner === false || $wrotePopup === false) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the consent layer structure files')
        ->send();
}

// 2. Seed default copy (NEW keys only) for each supported language.
//    fr → French map; every other language → English map.
$seed = consentTranslationSeed();
$languages = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
    ? CONFIG['LANGUAGES_SUPPORTED']
    : ['en'];
$languagesSeeded = [];
foreach ($languages as $lang) {
    $flat = ($lang === 'fr') ? $seed['fr'] : $seed['en'];
    $newOnly = consentFilterNewKeys($lang, $flat);
    if (empty($newOnly)) continue;
    $nested = convertDotNotationToNested($newOnly);
    $res = writeTranslationsToFile($lang, $nested, false);
    if (!empty($res['ok'])) {
        $languagesSeeded[$lang] = $res['keysAdded'] ?? count($newOnly);
    }
}

// 3. Enable the layer.
saveConsentConfig(array_merge($config, ['enabled' => true, 'policyRoute' => $policyRoute]));

ApiResponse::create(200, 'success')
    ->withMessage('Consent layer generated and enabled')
    ->withData([
        'categories'      => $categories,
        'languagesSeeded' => $languagesSeeded,
        'policyRoute'     => $policyRoute,
        'enabled'         => true,
    ])
    ->send();
