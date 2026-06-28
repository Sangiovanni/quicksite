<?php
/**
 * setPrivacyDescLang Command — change the language collected-data prose is
 * authored in (the registry-level `descLang` on data/privacy.json). Mirrors
 * setStorageDescLang.
 *
 * Changing the language MOVES every datum's label + purpose from the current
 * language to the new one in the translate files (empty-not-delete: the source
 * key is kept as "" so it surfaces as a missing translation). Existing target
 * values are OVERWRITTEN, so the call requires confirmation: first call (no
 * confirm) previews (409, needsConfirm); re-call with confirm:true to execute.
 *
 * @method POST
 * @route  /management/setPrivacyDescLang
 * @auth   required (write permission)
 *
 * Body: { "lang": "fr", "confirm": true? }
 *
 * @return ApiResponse
 *   200 { descLang, moved, overwrites }
 *   409 { from, to, moved, overwrites, needsConfirm:true }
 *   400 validation
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';

$params = $trimParametersManagement->params();

$lang = $params['lang'] ?? null;
if (!is_string($lang) || trim($lang) === '') {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('A target language is required')
        ->withErrors([['field' => 'lang', 'reason' => 'missing']])
        ->send();
}
$lang = trim($lang);

$supported = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
    ? CONFIG['LANGUAGES_SUPPORTED'] : ['en'];
if (!in_array($lang, $supported, true)) {
    ApiResponse::create(400, 'validation.invalid')
        ->withMessage("Language '$lang' is not in the project's supported languages")
        ->withData(['supported' => array_values($supported)])
        ->send();
}

$reg = loadPrivacyRegistry();
$current = privacyDescLang($reg);

if ($lang === $current) {
    ApiResponse::create(200, 'success')
        ->withMessage("Description language already '$lang'")
        ->withData(['descLang' => $lang, 'moved' => 0, 'overwrites' => 0])
        ->send();
}

$preview = privacyMoveDescriptions($current, $lang, false);
$confirm = !empty($params['confirm']);
if (!$confirm) {
    ApiResponse::create(409, 'privacy.desclang_confirm')
        ->withMessage("Changing the description language from '$current' to '$lang' will move {$preview['moved']} value(s)"
            . ($preview['overwrites'] > 0 ? " and overwrite {$preview['overwrites']} existing '$lang' value(s)" : '') . '.')
        ->withData([
            'from'         => $current,
            'to'           => $lang,
            'moved'        => $preview['moved'],
            'overwrites'   => $preview['overwrites'],
            'needsConfirm' => true,
        ])
        ->send();
}

$result = privacyMoveDescriptions($current, $lang, true);
$reg['descLang'] = $lang;
if (!savePrivacyRegistry($reg)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the privacy registry')
        ->send();
}

ApiResponse::create(200, 'success')
    ->withMessage("Description language changed to '$lang' ({$result['moved']} moved"
        . ($result['overwrites'] > 0 ? ", {$result['overwrites']} overwritten" : '') . ')')
    ->withData(['descLang' => $lang, 'moved' => $result['moved'], 'overwrites' => $result['overwrites']])
    ->send();
