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

/** Write the registry. Creates `data/` if missing. Stable key order. */
function saveStorageRegistry(array $registry): bool {
    $path = getStorageRegistryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $items = (isset($registry['items']) && is_array($registry['items'])) ? $registry['items'] : [];
    ksort($items);
    if (empty($items)) {
        $json = "{\n    \"items\": {}\n}\n";
    } else {
        $json = json_encode(
            ['items' => $items],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
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

/** Read a single dot-notation key from translate/<lang>.json (null if absent). */
function _storageReadTranslationKey(string $lang, string $dotKey): ?string {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    if (!file_exists($file)) return null;
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) return null;
    $cur = $decoded;
    foreach (explode('.', $dotKey) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return null;
        $cur = $cur[$part];
    }
    return is_string($cur) ? $cur : null;
}

/** Unset a single dot-notation key from translate/<lang>.json (best-effort). */
function _storageUnsetTranslationKey(string $lang, string $dotKey): void {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    if (!file_exists($file)) return;
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) return;
    $parts = explode('.', $dotKey);
    $leaf  = array_pop($parts);
    $cur = &$decoded;
    foreach ($parts as $part) {
        if (!is_array($cur) || !isset($cur[$part]) || !is_array($cur[$part])) return;
        $cur = &$cur[$part];
    }
    if (!array_key_exists($leaf, $cur)) return;
    unset($cur[$leaf]);
    unset($cur);
    file_put_contents($file, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
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

    // description — optional map of lang => string. NOT stored inline; returned
    // separately so the caller persists it into translate/ via
    // storageWriteDescription() and the stored item stays structure-only.
    $description = null;
    if (isset($input['description'])) {
        if (!is_array($input['description'])) {
            $errors[] = ['field' => 'description', 'reason' => 'invalid_type', 'expected' => 'object {lang: string}'];
        } else {
            $desc = [];
            foreach ($input['description'] as $lang => $text) {
                if (is_string($lang) && is_string($text)) {
                    $desc[$lang] = $text;
                }
            }
            if (!empty($desc)) {
                $description = $desc;
            }
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
