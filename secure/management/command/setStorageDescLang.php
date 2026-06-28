<?php
/**
 * setStorageDescLang Command — change the language storage descriptions are
 * authored in (the registry-level `descLang` on data/storage.json).
 *
 * @method POST
 * @route  /management/setStorageDescLang
 * @auth   required (write permission)
 *
 * Changing the language MOVES every item's description from the current language
 * to the new one in the translate files (true move — the source value is
 * cleared). Existing values in the target language are OVERWRITTEN, so the call
 * requires confirmation: the first call (no `confirm`) reports what would happen
 * (409, needsConfirm); re-call with `confirm: true` to execute.
 *
 * Body:
 *   { "lang": "fr", "confirm": true? }   lang required, in LANGUAGES_SUPPORTED
 *
 * @return ApiResponse
 *   200 { descLang, moved, overwrites }                    executed (or no-op)
 *   409 { from, to, moved, overwrites, needsConfirm:true } confirmation required
 *   400 validation
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';

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

$registry = loadStorageRegistry();
$current = storageDescLang($registry);

// No-op when already the active language.
if ($lang === $current) {
    ApiResponse::create(200, 'success')
        ->withMessage("Description language already '$lang'")
        ->withData(['descLang' => $lang, 'moved' => 0, 'overwrites' => 0])
        ->send();
}

// Preview the move (no writes) so the admin can confirm before overwriting.
$preview = storageMoveDescriptions($current, $lang, false);
$confirm = !empty($params['confirm']);
if (!$confirm) {
    ApiResponse::create(409, 'storage.desclang_confirm')
        ->withMessage("Changing the description language from '$current' to '$lang' will move {$preview['moved']} description(s)"
            . ($preview['overwrites'] > 0 ? " and overwrite {$preview['overwrites']} existing '$lang' description(s)" : '') . '.')
        ->withData([
            'from'         => $current,
            'to'           => $lang,
            'moved'        => $preview['moved'],
            'overwrites'   => $preview['overwrites'],
            'needsConfirm' => true,
        ])
        ->send();
}

// Execute the move + persist the new language.
$result = storageMoveDescriptions($current, $lang, true);
$registry['descLang'] = $lang;
if (!saveStorageRegistry($registry)) {
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage('Failed to write the storage registry')
        ->send();
}

ApiResponse::create(200, 'success')
    ->withMessage("Description language changed to '$lang' ({$result['moved']} moved"
        . ($result['overwrites'] > 0 ? ", {$result['overwrites']} overwritten" : '') . ')')
    ->withData(['descLang' => $lang, 'moved' => $result['moved'], 'overwrites' => $result['overwrites']])
    ->send();
