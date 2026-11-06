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
 *   { "askAgain": true }   // ask every visitor again: the stored version goes up by one
 *
 * The banner links to the cookie-policy route consent.json records (owned by
 * generateCookiePolicy / deleteCookiePolicy); a body cannot set it.
 *
 * Writes templates/model/json/consent-banner.json + consent-popup.json (one
 * popup toggle row per DECLARED non-essential category), seeds the textKeys'
 * words in every language the pages read (NEW keys only — never clobbers edited
 * copy), and sets data/consent.json enabled=true. Idempotent: re-running refreshes the
 * structures + re-enables, preserving existing translations.
 *
 * @return ApiResponse 200 { categories, languagesSeeded, languagesFailed?, policyRoute, enabled, version, askedAgain }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard
require_once SECURE_FOLDER_PATH . '/src/functions/startingTexts.php';

// askAgain: every visitor is asked again. The stored version goes up by one, so
// every category's fingerprint changes and no recorded choice holds
// (qs_consent_fingerprints()). Read before any write: a value that is not a
// boolean writes nothing.
$params = $trimParametersManagement->params();
$askAgain = false;
if (array_key_exists('askAgain', $params)) {
    $askAgain = filter_var($params['askAgain'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($askAgain === null) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('askAgain must be a boolean')
            ->withErrors([['field' => 'askAgain', 'reason' => 'invalid_type', 'expected' => 'boolean']])
            ->send();
    }
}

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

// 2. Seed the layer's words where a key has none, in every language the pages read
//    (qs_starting_words_seed()): never clobbers edited copy.
$seeded = qs_starting_words_seed(['consent.banner', 'consent.action', 'consent.popup', 'consent.category']);

// 3. Enable the layer (policyRoute is left untouched — owned elsewhere), the
//    version one higher when every visitor is asked again.
$version = max(1, (int) ($config['version'] ?? 1));
$update = ['enabled' => true];
if ($askAgain) {
    $version++;
    $update['version'] = $version;
}
saveConsentConfig(array_merge($config, $update));
qs_small_write_send_refusal();

$answer = [
    'categories'      => $categories,
    'languagesSeeded' => $seeded['languagesSeeded'],
    'policyRoute'     => $policyRoute,
    'enabled'         => true,
    'version'         => $version,
    'askedAgain'      => $askAgain,
];
if ($seeded['languagesFailed'] !== []) {
    $answer['languagesFailed'] = $seeded['languagesFailed'];
}
ApiResponse::create(200, 'success')
    ->withMessage($askAgain ? 'Consent layer generated and enabled; every visitor is asked again' : 'Consent layer generated and enabled')
    ->withData($answer)
    ->send();
