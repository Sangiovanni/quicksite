<?php
require_once __DIR__ . '/utilsManagement.php'; // qs_json_write
require_once __DIR__ . '/projectLanguage.php';
/**
 * storageHelpers.php — read / write / validate the per-project storage
 * registry (`data/storage.json`).
 *
 * The registry is the single declared model of every browser-storage key the
 * site uses (localStorage / sessionStorage / cookie). It powers the
 * `storageKey` picker and the GDPR / cookie-consent surface.
 *
 * Item shape:
 *   {
 *     "scope":       "localStorage" | "sessionStorage" | "cookie",      required
 *     "category":    "essential"|"functional"|"analytics"|"marketing",  required
 *     "retention":   "session" | "30d" | "1y" | "<custom>",             optional (string)
 *     // cookie scope only:
 *     "domain": "auto", "path": "/", "secure": true, "sameSite": "Lax"
 *   }
 *
 * The item KEY is the storage key name. The VALUE is provided by the site
 * visitor at runtime — the registry NEVER stores it. `consentRequired` is
 * DERIVED from category (essential → false, everything else → true), never
 * stored.
 *
 * DESCRIPTIONS ARE NOT STORED HERE. The registry is structure-only; the
 * human-readable description lives in `translate/<lang>.json` under the
 * convention key `storageDescKey($id)` (e.g. `storage.desc.<id>`). Each language
 * keeps its own word: a write names its language and touches that language's
 * word alone. `storageItemDescription()` reads a language's word as its pages
 * show it (with a transitional inline fallback for not-yet-migrated registries).
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

const STORAGE_SCOPES = ['localStorage', 'sessionStorage', 'cookie'];
const STORAGE_CATEGORIES = ['essential', 'functional', 'analytics', 'marketing'];
const STORAGE_COOKIE_SAMESITE = ['Strict', 'Lax', 'None'];

function getStorageRegistryPath(): string {
    return PROJECT_PATH . '/data/storage.json';
}

/** Load the registry. Always returns `['items' => array]`. */
function loadStorageRegistry(): array {
    $path = getStorageRegistryPath();
    if (!file_exists($path)) {
        return ['items' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['items' => []];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['items' => []];
    }
    if (!isset($decoded['items']) || !is_array($decoded['items'])) {
        $decoded['items'] = [];
    }
    return $decoded;
}

/** Write the registry. Creates `data/` if missing. Stable key order. The registry
 *  holds its items alone: a top-level field an older registry carries (a
 *  description language) is read as nothing and not written back. */
function saveStorageRegistry(array $registry): bool {
    $path = getStorageRegistryPath();
    $items = (isset($registry['items']) && is_array($registry['items'])) ? $registry['items'] : [];
    ksort($items);
    $out = [];
    $out['items'] = empty($items) ? (object) [] : $items;
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    // The size check, write by write (the registry is saved inside chains of
    // helpers); a refusal is answered by the command (qs_small_write_refused).
    require_once __DIR__ . '/quota.php';
    if (!qs_small_write_guard($path, qs_json_size($out, $flags))) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return qs_json_write($path, $out, $flags, LOCK_EX);
}

/** consentRequired is derived — essential never needs consent. */
function storageConsentRequired(array $item): bool {
    return ($item['category'] ?? 'functional') !== 'essential';
}

/** The project default language (where a description given as a plain string goes). */
function storageDefaultLang(): string {
    return qs_project_default_language();
}

/** Project languages plus the synthetic `default` bucket. */
function storageAllLangs(): array {
    $langs = qs_project_language_codes();
    $langs[] = 'default';
    return array_values(array_unique(array_filter($langs, fn($l) => is_string($l) && $l !== '')));
}

/** Canonical translate key that holds a storage item's description. */
function storageDescKey(string $id): string {
    return 'storage.desc.' . preg_replace('/[^a-z0-9_]/i', '_', $id);
}

// Thin wrappers over the shared translate-key helpers in translationHelpers.php
// (centralised so the privacy registry uses the same plumbing).
function _storageReadTranslationKey(string $lang, string $dotKey): ?string {
    return translationGetKey($lang, $dotKey);
}
function _storageUnsetTranslationKey(string $lang, string $dotKey): void {
    translationUnsetKey($lang, $dotKey);
}

/**
 * An item's description in $lang, as that language's pages show it
 * (qs_translation_shown_word(): default.json on a one-language project), else the
 * inline $lang text of a not-yet-migrated item (transitional). '' when the
 * language has none: another language's word is never offered in its place.
 */
function storageItemDescription(string $id, array $item, string $lang): string {
    $v = qs_translation_shown_word($lang, storageDescKey($id));
    if ($v !== null && $v !== '') return $v;
    $d = $item['description'] ?? null;             // transitional inline fallback
    if (is_array($d) && isset($d[$lang]) && is_string($d[$lang]) && $d[$lang] !== '') return $d[$lang];
    return '';
}

/**
 * Whether any of the project's languages has a description for the item (or a
 * not-yet-migrated item carries an inline one): the cookie policy gives such an
 * item its purpose cell.
 */
function storageItemHasDescription(string $id, array $item): bool {
    if (qs_translation_any_shown_word(storageDescKey($id))) return true;
    $d = $item['description'] ?? null;             // transitional inline copy
    if (is_array($d)) {
        foreach ($d as $t) { if (is_string($t) && trim($t) !== '') return true; }
    }
    return false;
}

/**
 * Write an item's description, language by language: a {lang: text} map whose
 * every language is one of the project's (validateStorageItem() refuses any
 * other; a language that is not is skipped here too, so no other path builds a
 * file name from it). A text writes that language's word through the shared
 * writer; an empty text removes that language's word alone
 * (qs_translation_remove_word()). A language not named keeps its word.
 * Null once done; else the shared writer's refusal, at the first language it
 * refused (the languages after it are not written), for the command to answer
 * with qs_translation_write_refusal_response().
 */
function storageWriteDescription(string $id, array $descMap): ?array {
    require_once __DIR__ . '/languageRegistry.php';
    $key = storageDescKey($id);
    foreach ($descMap as $lang => $text) {
        if (!is_string($lang) || !qs_project_has_language($lang) || !is_string($text)) continue;
        if (trim($text) === '') {
            qs_translation_remove_word($lang, $key);
            continue;
        }
        $written = writeTranslationsToFile($lang, convertDotNotationToNested([$key => $text]), false);
        if (!$written['ok']) {
            return $written;
        }
    }
    return null;
}

/**
 * A renamed item keeps its description in every language: each word file's word
 * under the old key is written under the new key in the same file (the project's
 * languages through the shared writer, then default.json's own word last, so each
 * file holds what it held), and the old key is removed everywhere. Null once
 * done; else the shared writer's refusal (the old key is then left as it was).
 */
function storageRenameDescription(string $fromId, string $toId): ?array {
    $from = storageDescKey($fromId);
    $to = storageDescKey($toId);
    if ($from === $to) return null;
    $langs = array_values(array_filter(storageAllLangs(), fn($l) => $l !== 'default'));
    $langs[] = 'default';
    foreach ($langs as $lang) {
        $word = _storageReadTranslationKey($lang, $from);
        if ($word === null || $word === '') continue;
        $written = writeTranslationsToFile($lang, convertDotNotationToNested([$to => $word]), false);
        if (!$written['ok']) {
            return $written;
        }
    }
    storageClearDescription($fromId);
    return null;
}

/** Remove an item's description key from every project language. */
function storageClearDescription(string $id): void {
    $key = storageDescKey($id);
    foreach (storageAllLangs() as $lang) {
        _storageUnsetTranslationKey($lang, $key);
    }
}

/**
 * One-time convergence: move any inline item descriptions into translate/ under
 * storageDescKey() and strip the inline copy. `changed` is true if it changed the
 * registry (caller persists). Idempotent — a no-op once every item is keyed.
 * A write the shared writer refuses stops it there, that item's inline copy kept:
 * `refused` holds the writer's answer, and the command answers it with
 * qs_translation_write_refusal_response() and saves nothing.
 *
 * @return array{changed: bool, refused: ?array}
 */
function storageMigrateInlineDescriptions(array &$registry): array {
    if (!isset($registry['items']) || !is_array($registry['items'])) return ['changed' => false, 'refused' => null];
    $changed = false;
    foreach ($registry['items'] as $id => $item) {
        if (!is_array($item) || !array_key_exists('description', $item)) continue;
        // Only an inline text is carried: an empty one is no word, and removes none.
        $inline = is_array($item['description'])
            ? array_filter($item['description'], fn($t) => is_string($t) && trim($t) !== '') : [];
        if (!empty($inline)) {
            $refused = storageWriteDescription((string) $id, $inline);
            if ($refused !== null) {
                return ['changed' => $changed, 'refused' => $refused];
            }
        }
        unset($registry['items'][$id]['description']);
        $changed = true;
    }
    return ['changed' => $changed, 'refused' => null];
}

/**
 * Validate + normalise a single item from a raw params payload.
 *
 * @return array{item: array, errors: array} On success `errors` is empty and
 *               `item` is the cleaned shape to store.
 */
function validateStorageItem(string $id, array $input): array {
    $errors = [];
    $item = [];

    // id — non-empty, no whitespace (storage keys / CSS selectors), and never `__proto__`:
    // the page's consent map is written as an object literal, where that name sets the
    // object's prototype instead of making a key, so the key could be neither read under
    // its category nor removed when the category is refused.
    if ($id === '') {
        $errors[] = ['field' => 'id', 'reason' => 'missing'];
    } elseif (preg_match('/\s/', $id)) {
        $errors[] = ['field' => 'id', 'reason' => 'invalid_format', 'hint' => 'A storage key cannot contain whitespace.'];
    } elseif ($id === '__proto__') {
        $errors[] = ['field' => 'id', 'reason' => 'reserved', 'hint' => 'A storage key cannot be __proto__: a page could not read it or remove it under its category.'];
    }

    // scope — required enum.
    $scope = $input['scope'] ?? null;
    if (!in_array($scope, STORAGE_SCOPES, true)) {
        $errors[] = ['field' => 'scope', 'reason' => 'invalid', 'expected' => implode('|', STORAGE_SCOPES)];
    } else {
        $item['scope'] = $scope;
    }

    // category — required enum.
    $category = $input['category'] ?? null;
    if (!in_array($category, STORAGE_CATEGORIES, true)) {
        $errors[] = ['field' => 'category', 'reason' => 'invalid', 'expected' => implode('|', STORAGE_CATEGORIES)];
    } else {
        $item['category'] = $category;
    }

    // description — each language's word, NOT stored inline: returned separately
    // so the caller writes it into translate/ through storageWriteDescription().
    // A plain string is the project's default language's word; a {lang: string}
    // map names each language, every one a language of the project (a code is
    // part of a file name, so nothing else is taken). An empty text means "no
    // word in that language": an edit removes that language's word alone. Absent,
    // null: the item's words are left as they are.
    $description = null;
    if (isset($input['description'])) {
        require_once __DIR__ . '/languageRegistry.php';
        if (is_string($input['description'])) {
            $description = [storageDefaultLang() => trim($input['description'])];
        } elseif (is_array($input['description'])) {
            $desc = [];
            foreach ($input['description'] as $lang => $text) {
                if (!qs_project_has_language($lang)) {
                    $shown = qs_language_value_for_message($lang);
                    $errors[] = ['field' => 'description', 'reason' => 'not_a_project_language', 'value' => $shown,
                        'allowed' => qs_project_language_codes(),
                        'hint' => "'{$shown}' is not one of this project's languages."];
                } elseif (!is_string($text)) {
                    $errors[] = ['field' => 'description', 'reason' => 'invalid_type', 'value' => (string) $lang, 'expected' => 'string'];
                } else {
                    $desc[(string) $lang] = trim($text) === '' ? '' : $text;
                }
            }
            $description = $desc;
        } else {
            $errors[] = ['field' => 'description', 'reason' => 'invalid_type', 'expected' => 'string or {lang: string}'];
        }
    }

    // retention — optional string (an enum value or a custom duration).
    if (isset($input['retention'])) {
        if (!is_string($input['retention'])) {
            $errors[] = ['field' => 'retention', 'reason' => 'invalid_type', 'expected' => 'string'];
        } elseif ($input['retention'] !== '') {
            $item['retention'] = $input['retention'];
        }
    }

    // Cookie-only conditional fields.
    if (($item['scope'] ?? null) === 'cookie') {
        if (isset($input['domain']) && is_string($input['domain'])) {
            $item['domain'] = $input['domain'];
        }
        if (isset($input['path']) && is_string($input['path'])) {
            $item['path'] = $input['path'];
        }
        if (isset($input['secure'])) {
            $item['secure'] = (bool) $input['secure'];
        }
        if (isset($input['sameSite'])) {
            if (in_array($input['sameSite'], STORAGE_COOKIE_SAMESITE, true)) {
                $item['sameSite'] = $input['sameSite'];
            } else {
                $errors[] = ['field' => 'sameSite', 'reason' => 'invalid', 'expected' => implode('|', STORAGE_COOKIE_SAMESITE)];
            }
        }
    }

    return ['item' => $item, 'description' => $description, 'errors' => $errors];
}

/**
 * The physical browser-storage slot for a DECLARED registry key.
 *
 * ⚠ THE REGISTRY STORES THE DECLARED KEY. This composes the prefixed form at
 * the moment it is needed and nothing persists the result. That is not tidiness:
 * the declared key is what the consent category map is keyed by, what the
 * `data-storage-*` attributes name, and what the admin pickers offer — baking a
 * project id into it would put an id inside the very field consent gating
 * matches on, and an import into a project with a different id would match
 * nothing.
 *
 * Mirrors `_storageKey()` in secure/src/runtime/qs.js, which is the runtime's
 * single composition point. A DECLARED cookie is still not prefixed here, and
 * that part remains true: QuickSite is not in the write path for one, so it
 * carries exactly the name the author's own code sets.
 *
 * ⚠ The ENGINE'S OWN cookies are the opposite case — QuickSite writes those,
 * so they ARE namespaced. See qs_project_cookie_name() below.
 *
 * @param string $declaredKey The key as the author declared it
 * @param string $scope       localStorage | sessionStorage | cookie
 * @param string|null $projectId Defaults to the bound project (PROJECT_NAME)
 * @return string The name that appears in the browser
 */
function qs_storage_physical_key(string $declaredKey, string $scope, ?string $projectId = null): string {
    if ($scope !== 'localStorage' && $scope !== 'sessionStorage') {
        return $declaredKey;
    }
    if ($projectId === null) {
        $projectId = defined('PROJECT_NAME') ? (string) PROJECT_NAME : '';
    }
    return 'qsp_' . $projectId . '_' . $declaredKey;
}

/**
 * The physical name of an ENGINE-OWNED cookie for the bound project.
 *
 * ⚠ WHY COOKIES NEED THIS AT ALL. A cookie is scoped by origin and path, not
 * by URL prefix, so every project served at /p/<id>/ on one host shares one
 * jar. Before this, a visitor who accepted analytics on project A had it read
 * back as consented on project B, whose banner then never appeared. That is
 * the same cross-project collision the storage prefix exists to prevent.
 *
 * ⚠ THE NAME IS PREFIXED, NEVER THE PATH. Deriving Path=/p/<id>/ was rejected:
 * a BUILT site serves at /, where Path=/ is correct, so a derived path would
 * make preview and production behave differently — the exact mode split that
 * was rejected when the same question came up about stripping the storage
 * prefix at build time. One behaviour everywhere.
 *
 * ⚠ A COOKIE IS CLEARED BY NAME AND PATH. Every set, read AND clear has to
 * compose through this function. A clear that still uses the bare name does
 * not fail loudly — it silently leaves the cookie in place, which for
 * qs_oauth_user would mean a logout that looks successful and is not.
 *
 * Mirrors _cookieName() in secure/src/runtime/qs.js. The fallback is
 * 'default', matching what Page::render() and PageManagement::render() emit
 * into window.QS_PROJECT when PROJECT_NAME is undefined, so the two sides
 * compose the same name in that case too.
 *
 * @param string $bareName  The engine cookie name (e.g. "consent_prefs")
 * @param string|null $projectId Defaults to the bound project (PROJECT_NAME)
 * @return string The name that appears in the browser
 */
// qs_project_cookie_name() and QS_OAUTH_COOKIE (the author-site OAuth session
// cookie) live in requestRuntime.php — a production build's OAuth flow needs
// both and cannot carry this authoring file.
require_once __DIR__ . '/requestRuntime.php';
