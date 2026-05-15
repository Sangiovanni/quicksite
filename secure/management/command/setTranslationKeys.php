<?php
/**
 * setTranslationKeys - Merge/upsert translation keys
 * 
 * Adds new keys, updates existing keys, keeps other keys untouched.
 * This is the safe way to update translations without losing existing content.
 * 
 * @param string language - Language code (e.g., 'en', 'fr') or 'default'
 * @param object translations - Keys to set/update (nested structure supported)
 * @param bool replace - Optional. If true, replaces entire file instead of merging (default: false)
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

$params = $trimParametersManagement->params();

if (!isset($params['language']) || !isset($params['translations'])) {
    $missing = [];
    if (!isset($params['language'])) $missing[] = 'language';
    if (!isset($params['translations'])) $missing[] = 'translations';
    
    ApiResponse::create(400, 'validation.required')
        ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
        ->send();
}

$language = $params['language'];
$newTranslations = $params['translations'];

// Type validation - language must be string
if (!is_string($language)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The language parameter must be a string.')
        ->withErrors([
            ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// SECURITY: Check for path traversal attempts FIRST (before length/format)
if (strpos($language, '..') !== false || 
    strpos($language, '/') !== false || 
    strpos($language, '\\') !== false ||
    strpos($language, "\0") !== false) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Language code contains invalid characters')
        ->withErrors([
            ['field' => 'language', 'reason' => 'path_traversal_attempt']
        ])
        ->send();
}

// Length validation for language code (max 10 chars for locale codes like "zh-Hans-CN")
if (strlen($language) > 10) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Language code must not exceed 10 characters')
        ->withErrors([
            ['field' => 'language', 'value' => $language, 'max_length' => 10]
        ])
        ->send();
}

// SECURITY: Validate language code format
// Also supports "default" for mono-language mode
$isDefault = ($language === 'default');
if (!$isDefault && !RegexPatterns::match('language_code_extended', $language)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid language code format')
        ->withErrors([RegexPatterns::validationError('language_code_extended', 'language', $language)])
        ->send();
}

// Type validation - translations must be array/object
if (!is_array($newTranslations)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The translations parameter must be an object/array.')
        ->withErrors([
            ['field' => 'translations', 'reason' => 'invalid_type', 'expected' => 'object/array']
        ])
        ->send();
}

// SECURITY: Check translation structure depth (prevent deeply nested structures)
if (!validateNestedDepth($newTranslations, 0, 20)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Translation structure too deeply nested (max 20 levels)')
        ->withErrors([
            ['field' => 'translations', 'reason' => 'exceeds max depth of 20']
        ])
        ->send();
}

// SECURITY: Check translation size (prevent huge payloads)
$translationSize = strlen(json_encode($newTranslations));
$maxSize = 5 * 1024 * 1024; // 5MB for translations

if ($translationSize > $maxSize) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Translation data too large (max 5MB)')
        ->withData([
            'size' => $translationSize,
            'max_size' => $maxSize,
            'size_mb' => round($translationSize / 1024 / 1024, 2)
        ])
        ->send();
}

// Optional: replace mode (replaces entire file instead of merging)
$replaceMode = isset($params['replace']) && $params['replace'] === true;

$translations_file = PROJECT_PATH . '/translate/' . $language . '.json';

// Load existing translations (or empty array if file doesn't exist)
// Skip loading if in replace mode - we'll just use the new translations
$existingTranslations = [];
if (!$replaceMode && file_exists($translations_file)) {
    $existingJson = @file_get_contents($translations_file);
    if ($existingJson !== false) {
        $decoded = json_decode($existingJson, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $existingTranslations = $decoded;
        }
    }
}

/**
 * Convert flat dot-notation keys to nested structure
 * e.g., {"menu.home": "Home"} becomes {"menu": {"home": "Home"}}
 */
function convertDotNotationToNested(array $flat): array {
    $nested = [];
    foreach ($flat as $key => $value) {
        // If key contains dots, nest it
        if (strpos($key, '.') !== false) {
            $parts = explode('.', $key);
            $current = &$nested;
            $lastIndex = count($parts) - 1;
            
            foreach ($parts as $i => $part) {
                if ($i === $lastIndex) {
                    // Last part - set the value
                    $current[$part] = $value;
                } else {
                    // Intermediate part - create array if needed
                    if (!isset($current[$part]) || !is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
            unset($current);
        } else {
            // No dots - recurse if value is array, otherwise just set
            if (is_array($value)) {
                $nested[$key] = convertDotNotationToNested($value);
            } else {
                $nested[$key] = $value;
            }
        }
    }
    return $nested;
}

// Convert any dot-notation keys to nested structure
$newTranslations = convertDotNotationToNested($newTranslations);

/**
 * Recursively remove keys containing {{ (unresolved component placeholders)
 * These are invalid translation keys that should never be written to files
 */
function sanitizePlaceholderKeys(array $data): array {
    $clean = [];
    foreach ($data as $key => $value) {
        if (is_string($key) && strpos($key, '{{') !== false) {
            continue; // Skip keys with unresolved placeholders
        }
        $clean[$key] = is_array($value) ? sanitizePlaceholderKeys($value) : $value;
    }
    return $clean;
}
$newTranslations = sanitizePlaceholderKeys($newTranslations);

/**
 * Walk the incoming payload against the existing tree to detect
 * "string-vs-branch" collisions at the same path. Mainstream i18n
 * systems (i18next, Rails I18n, Symfony Translation, gettext via
 * context) all disallow a key being both a leaf string AND a parent
 * of nested keys — the lookup is ambiguous and the rendered output
 * crashes / shows "Array". We reject the write here so the user is
 * forced to a sibling structure that every i18n tool can read.
 *
 * Returns array of collision descriptors, each with:
 *   - path          dot-notation key where the clash is
 *   - existingShape "string" | "nested branch"
 *   - newShape      "string" | "nested branch"  (the opposite)
 *   - suggestion    human-readable next-step
 * Empty array means clean.
 */
function detectTranslationCollisions(array $existing, array $new, string $pathPrefix = ''): array {
    $collisions = [];
    foreach ($new as $key => $value) {
        if (!isset($existing[$key])) continue; // brand-new path, no clash
        $path = $pathPrefix === '' ? (string)$key : ($pathPrefix . '.' . $key);

        $existingIsLeaf = !is_array($existing[$key]);
        $newIsLeaf      = !is_array($value);

        if ($existingIsLeaf && !$newIsLeaf) {
            // existing string, new wants a nested branch under same path
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
            // existing branch (has children), new wants to overwrite with a string
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
            // Both branches — recurse deeper.
            foreach (detectTranslationCollisions($existing[$key], $value, $path) as $sub) {
                $collisions[] = $sub;
            }
        }
        // else: both leaves (string overwrites string) — standard merge, no collision.
    }
    return $collisions;
}

// Run the collision check ONLY for merge mode. In replace mode we're
// nuking the file entirely, so by definition there's no pre-existing
// shape to clash with. JSON syntax itself forbids the collision within
// a single payload, so we don't have to check $new against itself.
if (!$replaceMode && !empty($existingTranslations)) {
    $collisions = detectTranslationCollisions($existingTranslations, $newTranslations);
    if (!empty($collisions)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage($collisions[0]['suggestion'])
            ->withErrors($collisions)
            ->withData(['collisions' => $collisions])
            ->send();
    }
}

/**
 * Recursively merge translations (deep merge)
 * New values overwrite existing, but non-specified keys are preserved
 */
function mergeTranslations(array $existing, array $new): array {
    foreach ($new as $key => $value) {
        if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
            // Both are arrays - recurse
            $existing[$key] = mergeTranslations($existing[$key], $value);
        } else {
            // Overwrite or add
            $existing[$key] = $value;
        }
    }
    return $existing;
}

// Merge new translations into existing
$mergedTranslations = mergeTranslations($existingTranslations, $newTranslations);

// Count what changed
$keysAdded = 0;
$keysUpdated = 0;

function countChanges(array $existing, array $new, int &$added, int &$updated): void {
    foreach ($new as $key => $value) {
        if (!isset($existing[$key])) {
            if (is_array($value)) {
                $added += countNestedKeys($value);
            } else {
                $added++;
            }
        } elseif (is_array($value) && is_array($existing[$key])) {
            countChanges($existing[$key], $value, $added, $updated);
        } elseif ($existing[$key] !== $value) {
            $updated++;
        }
    }
}

function countNestedKeys(array $arr): int {
    $count = 0;
    foreach ($arr as $value) {
        if (is_array($value)) {
            $count += countNestedKeys($value);
        } else {
            $count++;
        }
    }
    return $count;
}

countChanges($existingTranslations, $newTranslations, $keysAdded, $keysUpdated);

// Encode to JSON
$json_content = json_encode($mergedTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json_content === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to encode translations to JSON")
        ->send();
}

// Write to file
if (file_put_contents($translations_file, $json_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write translation file")
        ->send();
}

// Also update default.json to ensure it always has at least the same keys
// This ensures mono-lingual mode works correctly and default always has minimal requirements
$defaultUpdated = false;
if (!$isDefault) {
    $default_file = PROJECT_PATH . '/translate/default.json';
    
    // Load existing default translations
    $existingDefault = [];
    if (file_exists($default_file)) {
        $defaultJson = @file_get_contents($default_file);
        if ($defaultJson !== false) {
            $decoded = json_decode($defaultJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingDefault = $decoded;
            }
        }
    }
    
    // Merge new translations into default (default gets all keys from any language update)
    $mergedDefault = mergeTranslations($existingDefault, $newTranslations);
    
    // Only write if there are actual changes
    if ($mergedDefault !== $existingDefault) {
        $defaultJsonContent = json_encode($mergedDefault, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($defaultJsonContent !== false) {
            if (file_put_contents($default_file, $defaultJsonContent, LOCK_EX) !== false) {
                $defaultUpdated = true;
            }
        }
    }
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Translation keys updated successfully')
    ->withData([
        'language' => $language,
        'file' => $translations_file,
        'keys_added' => $keysAdded,
        'keys_updated' => $keysUpdated,
        'keys_unchanged' => 'preserved',
        'default_synced' => $defaultUpdated
    ])
    ->send();
