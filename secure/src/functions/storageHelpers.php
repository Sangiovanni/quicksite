<?php
/**
 * storageHelpers.php — read / write / validate the per-project storage
 * registry (`data/storage.json`).
 *
 * The registry is the single declared model of every browser-storage key the
 * site uses (localStorage / sessionStorage / cookie). It powers the
 * `storageKey` picker and the GDPR / cookie-consent surface. See
 * NOTES/planning/BETA9_STORAGE_REGISTRY.md for the locked design.
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
 * convention key `storageDescKey($id)` (e.g. `storage.desc.<id>`), authored in
 * the project default language and translated with the project's language tool.
 * `storageItemDescription()` resolves it (with a transitional inline fallback
 * for not-yet-migrated registries).
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

/** Write the registry. Creates `data/` if missing. Stable key order. Preserves
 *  the registry-level `descLang` (the language storage descriptions are authored
 *  in) when present. */
function saveStorageRegistry(array $registry): bool {
    $path = getStorageRegistryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $items = (isset($registry['items']) && is_array($registry['items'])) ? $registry['items'] : [];
    ksort($items);
    $out = [];
    if (isset($registry['descLang']) && is_string($registry['descLang']) && $registry['descLang'] !== '') {
        $out['descLang'] = $registry['descLang'];
    }
    $out['items'] = empty($items) ? (object) [] : $items;
    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * The language storage descriptions are authored in (registry-level setting).
 * Defaults to the website default until the author changes it on /admin/storage.
 */
function storageDescLang(?array $registry = null): string {
    if ($registry === null) {
        $registry = loadStorageRegistry();
    }
    $l = $registry['descLang'] ?? null;
    return (is_string($l) && $l !== '') ? $l : storageDefaultLang();
}

/**
 * Move every item's description from language $from to language $to in the
 * translate files (true move — the source value is removed). When $execute is
 * false, nothing is written; the return only reports what WOULD happen.
 *
 * @return array{moved:int, overwrites:int} moved = items with a $from value;
 *               overwrites = of those, how many already had a $to value.
 */
function storageMoveDescriptions(string $from, string $to, bool $execute = true): array {
    $registry = loadStorageRegistry();
    $moved = 0;
    $overwrites = 0;
    if ($from === $to) {
        return ['moved' => 0, 'overwrites' => 0];
    }
    foreach (array_keys($registry['items']) as $id) {
        $key = storageDescKey((string) $id);
        $val = _storageReadTranslationKey($from, $key);
        if ($val === null || $val === '') {
            continue;
        }
        $existingTarget = _storageReadTranslationKey($to, $key);
        if ($existingTarget !== null && $existingTarget !== '') {
            $overwrites++;
        }
        $moved++;
        if ($execute) {
            writeTranslationsToFile($to, convertDotNotationToNested([$key => $val]), false);
            // Empty the source key, don't delete it: keeping it present (as "")
            // makes the translate tooling flag the old language as a MISSING
            // translation (the desired re-translation signal) and avoids leaving
            // an empty-object artifact. The privacy helper's move must do the same.
            _storageSetTranslationKey($from, $key, '');
        }
    }
    return ['moved' => $moved, 'overwrites' => $overwrites];
}

/** consentRequired is derived — essential never needs consent. */
function storageConsentRequired(array $item): bool {
    return ($item['category'] ?? 'functional') !== 'essential';
}

/** The project default language (where single-language descriptions are authored). */
function storageDefaultLang(): string {
    return (defined('CONFIG') && isset(CONFIG['LANGUAGE_DEFAULT']) && is_string(CONFIG['LANGUAGE_DEFAULT']))
        ? CONFIG['LANGUAGE_DEFAULT'] : 'en';
}

/** Project languages plus the synthetic `default` bucket. */
function storageAllLangs(): array {
    $langs = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
        ? CONFIG['LANGUAGES_SUPPORTED'] : ['en'];
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
function _storageSetTranslationKey(string $lang, string $dotKey, string $value): void {
    translationSetKey($lang, $dotKey, $value);
}

/**
 * Resolve an item's description in $lang. Canonical home is translate/ under
 * storageDescKey(); falls back to translate/default.json, then to any inline
 * description still present on a not-yet-migrated item (transitional).
 */
function storageItemDescription(string $id, array $item, string $lang): string {
    $key = storageDescKey($id);
    $v = _storageReadTranslationKey($lang, $key);
    if ($v !== null && $v !== '') return $v;
    $v = _storageReadTranslationKey('default', $key);
    if ($v !== null && $v !== '') return $v;
    $d = $item['description'] ?? null;             // transitional inline fallback
    if (is_array($d)) {
        if (isset($d[$lang]) && is_string($d[$lang]) && $d[$lang] !== '') return $d[$lang];
        foreach ($d as $t) { if (is_string($t) && $t !== '') return $t; }
    }
    return '';
}

/** Write an item's description (a {lang: text} map) into the translate files. */
function storageWriteDescription(string $id, array $descMap): void {
    $key = storageDescKey($id);
    foreach ($descMap as $lang => $text) {
        if (!is_string($lang) || $lang === '' || !is_string($text) || $text === '') continue;
        writeTranslationsToFile($lang, convertDotNotationToNested([$key => $text]), false);
    }
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
 * storageDescKey() and strip the inline copy. Returns true if it changed the
 * registry (caller persists). Idempotent — a no-op once every item is keyed.
 */
function storageMigrateInlineDescriptions(array &$registry): bool {
    if (!isset($registry['items']) || !is_array($registry['items'])) return false;
    $changed = false;
    foreach ($registry['items'] as $id => $item) {
        if (!is_array($item) || !array_key_exists('description', $item)) continue;
        if (is_array($item['description']) && !empty($item['description'])) {
            storageWriteDescription((string) $id, $item['description']);
        }
        unset($registry['items'][$id]['description']);
        $changed = true;
    }
    return $changed;
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

    // id — non-empty, no whitespace (storage keys / CSS selectors).
    if ($id === '') {
        $errors[] = ['field' => 'id', 'reason' => 'missing'];
    } elseif (preg_match('/\s/', $id)) {
        $errors[] = ['field' => 'id', 'reason' => 'invalid_format', 'hint' => 'A storage key cannot contain whitespace.'];
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

    // description — authored prose for the storage description-language. Accepts
    // a plain string (stored under storageDescLang()) or a {lang: string} map
    // (API callers). NOT stored inline; returned separately so the caller
    // persists it into translate/ via storageWriteDescription().
    $description = null;
    if (isset($input['description'])) {
        if (is_string($input['description'])) {
            $t = trim($input['description']);
            if ($t !== '') {
                $description = [storageDescLang() => $t];
            }
        } elseif (is_array($input['description'])) {
            $desc = [];
            foreach ($input['description'] as $lang => $text) {
                if (is_string($lang) && is_string($text) && $text !== '') {
                    $desc[$lang] = $text;
                }
            }
            if (!empty($desc)) {
                $description = $desc;
            }
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
