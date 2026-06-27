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
 *     "description": { "<lang>": "<text>" },                            optional (translatable)
 *     "retention":   "session" | "30d" | "1y" | "<custom>",             optional (string)
 *     // cookie scope only:
 *     "domain": "auto", "path": "/", "secure": true, "sameSite": "Lax"
 *   }
 *
 * The item KEY is the storage key name. The VALUE is provided by the site
 * visitor at runtime — the registry NEVER stores it. `consentRequired` is
 * DERIVED from category (essential → false, everything else → true), never
 * stored.
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

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

    // description — optional map of lang => string.
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
                $item['description'] = $desc;
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

    return ['item' => $item, 'errors' => $errors];
}
