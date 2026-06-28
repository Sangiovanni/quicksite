<?php
/**
 * listStorageItems Command — return the project's storage registry.
 *
 * @method POST
 * @route  /management/listStorageItems
 * @auth   required (read permission)
 *
 * Returns every declared storage item with its derived `consentRequired`,
 * plus the `scopes` + `categories` enums so the admin page can drive its
 * pickers from the single source of truth.
 *
 * @return ApiResponse 200 { items: [...], count, scopes, categories }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';

$registry = loadStorageRegistry();

$descLang = storageDescLang($registry);
$languages = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
    ? array_values(array_filter(CONFIG['LANGUAGES_SUPPORTED'], fn($l) => is_string($l) && $l !== '' && $l !== 'default'))
    : [$descLang];
if (empty($languages)) {
    $languages = [$descLang];
}

$out = [];
foreach ($registry['items'] as $id => $item) {
    if (!is_array($item)) {
        continue;
    }
    $row = $item;
    unset($row['description']); // structure-only now — descriptions live in translate/
    $row['id'] = $id;
    $row['consentRequired'] = storageConsentRequired($item);

    // Card description shows the storage description-language value (raw — no
    // cross-language fallback), with an inline seed only for a not-yet-migrated
    // item. Authoring/translation of other languages happens via the language tool.
    $key = storageDescKey((string) $id);
    $desc = _storageReadTranslationKey($descLang, $key);
    if (($desc === null || $desc === '') && isset($item['description'][$descLang]) && is_string($item['description'][$descLang])) {
        $desc = $item['description'][$descLang];
    }
    if ($desc !== null && $desc !== '') {
        $row['description'] = [$descLang => $desc];
    }

    $out[] = $row;
}

ApiResponse::create(200, 'success')
    ->withMessage(count($out) . ' storage item' . (count($out) === 1 ? '' : 's'))
    ->withData([
        'items'      => $out,
        'count'      => count($out),
        'scopes'     => STORAGE_SCOPES,
        'categories' => STORAGE_CATEGORIES,
        'languages'  => $languages,
        'descLang'   => $descLang,
    ])
    ->send();
