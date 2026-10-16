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
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

// The policy route is owned by generateCookiePolicy / deleteCookiePolicy (set
// only when a page actually exists). Here we just read it for the banner link
// so a half-finished route never gets wired up.
$config = loadConsentConfig();
$policyRoute = $config['policyRoute'] ?? null;
if (!is_string($policyRoute) || trim($policyRoute) === '') {
    $policyRoute = null;
}

$categories = consentDeclaredCategories();

// 1. Build + write the two structure files.
$banner = buildConsentBannerStructure($policyRoute);
$popup  = buildConsentPopupStructure($categories);

// Both files are checked before either is written, so a refusal leaves the
// layer exactly as it was.
foreach (['consent-banner.json' => $banner, 'consent-popup.json' => $popup] as $layerFile => $layerTree) {
    $unsafeStructureParam = qs_first_unsafe_structure_param($layerTree);
    if ($unsafeStructureParam !== null) {
        $unsafeStructureParam['file'] = $layerFile;
        qs_unsafe_structure_param_response($unsafeStructureParam)->send();
    }
}

// Every write of this command passes the size check as it happens
// (qs_small_write_guard); a refusal takes back what it wrote and is answered.
$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (!qs_small_write_guard(consentBannerPath(), qs_json_size($banner, $jsonFlags, "\n"))) {
    qs_small_write_send_refusal();
}
$dir = dirname(consentBannerPath());
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$wroteBanner = qs_json_write(consentBannerPath(), $banner, $jsonFlags, LOCK_EX, "\n");
if (!qs_small_write_guard(consentPopupPath(), qs_json_size($popup, $jsonFlags, "\n"))) {
    qs_small_write_send_refusal();
}
$wrotePopup  = qs_json_write(consentPopupPath(),  $popup,  $jsonFlags, LOCK_EX, "\n");
if (!$wroteBanner || !$wrotePopup) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the consent layer structure files')
        ->send();
}

// 2. Seed default copy (NEW keys only) for the project DEFAULT language only.
//    Other languages are translated via the Translation Manager (the textKeys
//    surface there as missing). fr default → French map; otherwise English.
$seed = consentTranslationSeed();
$defaultLang = qs_project_default_language();
$languagesSeeded = [];
$flat = ($defaultLang === 'fr') ? $seed['fr'] : $seed['en'];
$newOnly = consentFilterNewKeys($defaultLang, $flat);
if (!empty($newOnly)) {
    $res = writeTranslationsToFile($defaultLang, convertDotNotationToNested($newOnly), false);
    qs_small_write_send_refusal();
    if (!empty($res['ok'])) {
        $languagesSeeded[$defaultLang] = $res['keysAdded'] ?? count($newOnly);
    }
}

// 3. Enable the layer (policyRoute is left untouched — owned elsewhere).
saveConsentConfig(array_merge($config, ['enabled' => true]));
qs_small_write_send_refusal();

ApiResponse::create(200, 'success')
    ->withMessage('Consent layer generated and enabled')
    ->withData([
        'categories'      => $categories,
        'languagesSeeded' => $languagesSeeded,
        'policyRoute'     => $policyRoute,
        'enabled'         => true,
    ])
    ->send();
