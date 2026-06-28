<?php
/**
 * translationHelpers.php — pure helpers + callable writer for translation files
 *
 * Extracted from setTranslationKeys.php (and adopted by importStructureTranslations.php)
 * so future translation-writing commands get the same plumbing for free instead of
 * inlining duplicates. The collision-detection + string-vs-branch rule is identical
 * to setTranslationKeys' merge behaviour: a key may be a leaf string OR a parent of
 * nested keys, never both — every mainstream i18n library (i18next, Rails I18n,
 * Symfony Translation, gettext via context) enforces the same constraint.
 *
 * Loading is idempotent — guarded so commands can include both this and the
 * caller's own translation-related helpers without double-declaration errors.
 */

if (!function_exists('convertDotNotationToNested')) {

/**
 * Translation-key validity check — single source of truth.
 *
 * Permissive on purpose. The runtime Translator + setTranslationKeys +
 * the JSON storage all accept any non-empty string as a key. Per-character
 * regexes (the old `translation_key_simple` pattern) tried to enforce
 * `[a-zA-Z0-9._-]+$` but real keys legitimately include:
 *   - `/` for nested-route titles (e.g. page.titles.documentation/commands)
 *   - `$` for component template variables (e.g. reassurance-item1.$icon)
 *   - any UTF-8 character a translator chose for an identifier
 * Trying to keep a character whitelist was whack-a-mole — every new
 * legitimate pattern broke deleteTranslationKeys. This helper replaces
 * the per-character rule with the minimal security floor.
 *
 * Returns true iff `$key` is a string, non-empty after trim, and
 * contains no null byte (PHP's filesystem path-traversal sentinel).
 * The dot-notation key never reaches a filesystem path — only the
 * `language` param does, which is validated separately — so we don't
 * need to block `..` or `/` here.
 */
function isValidTranslationKey($key): bool {
    if (!is_string($key)) return false;
    if (trim($key) === '') return false;
    if (strpos($key, "\0") !== false) return false;
    return true;
}

/**
 * Convert flat dot-notation keys to a nested structure.
 * {"menu.home": "Home"} → {"menu": {"home": "Home"}}
 */
function convertDotNotationToNested(array $flat): array {
    $nested = [];
    foreach ($flat as $key => $value) {
        if (strpos((string)$key, '.') !== false) {
            $parts = explode('.', (string)$key);
            $current = &$nested;
            $lastIndex = count($parts) - 1;
            foreach ($parts as $i => $part) {
                if ($i === $lastIndex) {
                    $current[$part] = $value;
                } else {
                    if (!isset($current[$part]) || !is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
            unset($current);
        } else {
            if (is_array($value)) {
                $nested[$key] = convertDotNotationToNested($value);
            } else {
                $nested[$key] = $value;
            }
        }
    }
    return $nested;
}

/**
 * Recursively merge new translations into existing.
 * New values overwrite existing leaves; nested branches recurse;
 * unspecified keys are preserved.
 */
function mergeTranslations(array $existing, array $new): array {
    foreach ($new as $key => $value) {
        if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
            $existing[$key] = mergeTranslations($existing[$key], $value);
        } else {
            $existing[$key] = $value;
        }
    }
    return $existing;
}

/**
 * Strip keys carrying unresolved `{{` component placeholders. Those are
 * authoring artifacts and must never reach a translation file.
 */
function sanitizePlaceholderKeys(array $data): array {
    $clean = [];
    foreach ($data as $key => $value) {
        if (is_string($key) && strpos($key, '{{') !== false) {
            continue;
        }
        $clean[$key] = is_array($value) ? sanitizePlaceholderKeys($value) : $value;
    }
    return $clean;
}

/**
 * Walk the incoming payload against the existing tree to detect
 * string-vs-branch collisions at the same path. Returns an array of
 * collision descriptors; [] = clean.
 */
function detectTranslationCollisions(array $existing, array $new, string $pathPrefix = ''): array {
    $collisions = [];
    foreach ($new as $key => $value) {
        if (!isset($existing[$key])) continue;
        $path = $pathPrefix === '' ? (string)$key : ($pathPrefix . '.' . $key);
        $existingIsLeaf = !is_array($existing[$key]);
        $newIsLeaf = !is_array($value);

        if ($existingIsLeaf && !$newIsLeaf) {
            $childKey = is_array($value) ? array_key_first($value) : '';
            $newSiblingPath = $pathPrefix === '' ? (string)$childKey : ($pathPrefix . '.' . $childKey);
            $collisions[] = [
                'path' => $path,
                'existingShape' => 'string',
                'newShape' => 'nested branch',
                'suggestion' =>
                    "Key '$path' is already a string. Translation systems can't "
                    . "make it BOTH a string AND a parent of nested keys at the "
                    . "same time. Options: (a) rename the new key as a sibling — "
                    . "e.g. '" . $newSiblingPath . "' instead of '" . $path . "." . $childKey . "'; "
                    . "or (b) delete the existing string at '$path' first, then re-add it as a branch."
            ];
        } elseif (!$existingIsLeaf && $newIsLeaf) {
            $collisions[] = [
                'path' => $path,
                'existingShape' => 'nested branch',
                'newShape' => 'string',
                'suggestion' =>
                    "Key '$path' is already a branch with nested sub-keys. "
                    . "Overwriting it with a string would silently drop those children. "
                    . "Options: (a) rename the new string to a sibling like '$path.text' or '$path.value'; "
                    . "or (b) delete the existing branch at '$path' first via deleteTranslationKeys, then re-add the string."
            ];
        } elseif (!$existingIsLeaf && !$newIsLeaf) {
            foreach (detectTranslationCollisions($existing[$key], $value, $path) as $sub) {
                $collisions[] = $sub;
            }
        }
        // else: both leaves — standard merge, no collision.
    }
    return $collisions;
}

/**
 * Count net additions/updates between existing and new payloads (for
 * reporting in the command's response). Mutates $added / $updated by
 * reference. Brand-new keys count as additions; matched paths with
 * different leaf values count as updates; nested arrays recurse.
 */
function countTranslationChanges(array $existing, array $new, int &$added, int &$updated): void {
    foreach ($new as $key => $value) {
        if (!isset($existing[$key])) {
            if (is_array($value)) {
                $added += countNestedTranslationKeys($value);
            } else {
                $added++;
            }
        } elseif (is_array($value) && is_array($existing[$key])) {
            countTranslationChanges($existing[$key], $value, $added, $updated);
        } elseif ($existing[$key] !== $value) {
            $updated++;
        }
    }
}

/**
 * Count leaf keys recursively. Helper for countTranslationChanges when
 * a whole branch is newly added.
 */
function countNestedTranslationKeys(array $arr): int {
    $count = 0;
    foreach ($arr as $value) {
        if (is_array($value)) {
            $count += countNestedTranslationKeys($value);
        } else {
            $count++;
        }
    }
    return $count;
}

/**
 * Load → optional collision check → merge → write a translation file,
 * with optional default.json sync. Callers handle higher-level input
 * validation (language code format, payload depth/size) — this function
 * assumes both inputs are already trusted.
 *
 * Return shape:
 *   success: ['ok' => true, 'file' => string, 'keysAdded' => int,
 *             'keysUpdated' => int, 'defaultSynced' => bool,
 *             'merged' => array]
 *   collision: ['ok' => false, 'reason' => 'collisions',
 *               'collisions' => array]
 *   io / encode failure: ['ok' => false,
 *                         'reason' => 'json_encode_failed' | 'file_write_failed',
 *                         'message' => string]
 */
function writeTranslationsToFile(string $language, array $newNested, bool $replaceMode = false): array {
    $translationsFile = PROJECT_PATH . '/translate/' . $language . '.json';
    $isDefault = ($language === 'default');

    // Load existing (skip in replace mode — the new payload replaces it)
    $existingTranslations = [];
    if (!$replaceMode && file_exists($translationsFile)) {
        $existingJson = @file_get_contents($translationsFile);
        if ($existingJson !== false) {
            $decoded = json_decode($existingJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingTranslations = $decoded;
            }
        }
    }

    // Collision check (merge mode only). In replace mode we're nuking
    // the file, so by definition no pre-existing shape can clash.
    if (!$replaceMode && !empty($existingTranslations)) {
        $collisions = detectTranslationCollisions($existingTranslations, $newNested);
        if (!empty($collisions)) {
            return [
                'ok' => false,
                'reason' => 'collisions',
                'collisions' => $collisions,
            ];
        }
    }

    // Merge + change counts
    $mergedTranslations = $replaceMode
        ? $newNested
        : mergeTranslations($existingTranslations, $newNested);

    $keysAdded = 0;
    $keysUpdated = 0;
    countTranslationChanges($existingTranslations, $newNested, $keysAdded, $keysUpdated);

    // Encode
    $jsonContent = json_encode(
        $mergedTranslations,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($jsonContent === false) {
        return [
            'ok' => false,
            'reason' => 'json_encode_failed',
            'message' => 'Failed to encode translations to JSON',
        ];
    }

    // Ensure directory exists (importStructureTranslations relied on this)
    $translateDir = dirname($translationsFile);
    if (!is_dir($translateDir)) {
        @mkdir($translateDir, 0755, true);
    }

    if (file_put_contents($translationsFile, $jsonContent, LOCK_EX) === false) {
        return [
            'ok' => false,
            'reason' => 'file_write_failed',
            'message' => 'Failed to write translation file',
        ];
    }

    // Sync to default.json — default always has at least the union of
    // keys from any language update. Non-default languages only.
    $defaultUpdated = false;
    if (!$isDefault) {
        $defaultFile = PROJECT_PATH . '/translate/default.json';
        $existingDefault = [];
        if (file_exists($defaultFile)) {
            $defaultJson = @file_get_contents($defaultFile);
            if ($defaultJson !== false) {
                $decoded = json_decode($defaultJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingDefault = $decoded;
                }
            }
        }
        $mergedDefault = mergeTranslations($existingDefault, $newNested);
        if ($mergedDefault !== $existingDefault) {
            $defaultJsonContent = json_encode(
                $mergedDefault,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($defaultJsonContent !== false
                && file_put_contents($defaultFile, $defaultJsonContent, LOCK_EX) !== false
            ) {
                $defaultUpdated = true;
            }
        }
    }

    return [
        'ok' => true,
        'file' => $translationsFile,
        'keysAdded' => $keysAdded,
        'keysUpdated' => $keysUpdated,
        'defaultSynced' => $defaultUpdated,
        'merged' => $mergedTranslations,
    ];
}

/**
 * Read a single dot-notation key from translate/<lang>.json. Returns null if the
 * file is missing/invalid, the key is absent, or the value isn't a string. Reads
 * ONLY that language file (no fallback) — callers layer their own fallback.
 */
function translationGetKey(string $lang, string $dotKey): ?string {
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

/**
 * Set a single dot-notation key in translate/<lang>.json to a literal value,
 * creating parents as needed. Edits ONLY that language file — no default.json
 * sync (unlike writeTranslationsToFile) — used to empty/patch one key in place.
 */
function translationSetKey(string $lang, string $dotKey, string $value): void {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    if (!file_exists($file)) return;
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) return;
    $parts = explode('.', $dotKey);
    $leaf  = array_pop($parts);
    $cur = &$decoded;
    foreach ($parts as $part) {
        if (!isset($cur[$part]) || !is_array($cur[$part])) { $cur[$part] = []; }
        $cur = &$cur[$part];
    }
    $cur[$leaf] = $value;
    unset($cur);
    file_put_contents($file, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Unset a single dot-notation key from translate/<lang>.json (best-effort). */
function translationUnsetKey(string $lang, string $dotKey): void {
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

} // end if (!function_exists('convertDotNotationToNested'))
