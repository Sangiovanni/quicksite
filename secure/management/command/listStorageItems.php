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
 * Body (optional): { "lang": "fr" } — the language each item's description is
 * answered in, as that language's pages show it: one of the project's languages,
 * the project's default language when absent. Each language keeps its own word;
 * an item with none in that language has no `description`.
 *
 * @return ApiResponse 200 { items: [...], count, scopes, categories, lang,
 *                           defaultLanguage, languages, languageNames } | 400
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';

$params = $trimParametersManagement->params();

$lang = $params['lang'] ?? null;
if ($lang !== null && !qs_project_has_language($lang)) {
    qs_language_not_in_project_response($lang, 'lang')->send();
}
$default = storageDefaultLang();
$lang = $lang ?? $default;

$registry = loadStorageRegistry();

$languages = array_values(array_unique(array_filter(qs_project_language_codes(), fn($l) => $l !== 'default')));
if (empty($languages)) {
    $languages = [$default];
}
$names = [];
foreach ($languages as $code) {
    $names[$code] = qs_language_label($code);
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

    // The description in the language asked, as its pages show it (no other
    // language's word in its place), with an inline seed only for a
    // not-yet-migrated item.
    $desc = storageItemDescription((string) $id, $item, $lang);
    if ($desc !== '') {
        $row['description'] = [$lang => $desc];
    }

    $out[] = $row;
}

ApiResponse::create(200, 'success')
    ->withMessage(count($out) . ' storage item' . (count($out) === 1 ? '' : 's'))
    ->withData([
        'items'           => $out,
        'count'           => count($out),
        'scopes'          => STORAGE_SCOPES,
        'categories'      => STORAGE_CATEGORIES,
        'lang'            => $lang,
        'defaultLanguage' => $default,
        'languages'       => $languages,
        'languageNames'   => $names,
    ])
    ->send();
