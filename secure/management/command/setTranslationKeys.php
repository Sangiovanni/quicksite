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
 * @param array keep - Optional, with replace only. Dotted groups of the file that survive the replace, e.g. ["consent", "storage.desc"]
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

$params = $trimParametersManagement->params();

if (!isset($params['language']) || !isset($params['translations'])) {
    $missing = [];
    if (!isset($params['language'])) $missing[] = 'language';
    if (!isset($params['translations'])) $missing[] = 'translations';
    
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter' . (count($missing) > 1 ? 's' : '') . ': ' . implode(', ', $missing))
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

// Length validation (no language code, and not "default", is longer)
if (strlen($language) > 10) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Language code must not exceed 10 characters')
        ->withErrors([
            ['field' => 'language', 'value' => $language, 'max_length' => 10]
        ])
        ->send();
}

// An EXISTING language: one of the project's, or "default" (the mono-language
// translation file). A translation file is never created for any other code.
if (!qs_project_has_language($language, true)) {
    qs_language_not_in_project_response($language, 'language', true)->send();
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

// Optional: what a replace keeps of the file it replaces. Read before any write.
$keep = $params['keep'] ?? [];
$keepValid = is_array($keep) && array_is_list($keep);
if ($keepValid) {
    foreach ($keep as $group) {
        if (!is_string($group) || preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D', $group) !== 1) {
            $keepValid = false;
        }
    }
}
if (!$keepValid) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('keep must be a list of dotted translation groups')
        ->withErrors([['field' => 'keep', 'reason' => 'invalid_format', 'expected' => 'a list such as ["consent", "storage.desc"]']])
        ->send();
}
if ($keep !== [] && !$replaceMode) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('keep is only read with replace: true')
        ->withErrors([['field' => 'keep', 'reason' => 'needs_replace']])
        ->send();
}

// Normalise the incoming payload: flatten dot-notation, strip any
// stray {{ component placeholder keys. Both transforms idempotent.
$newTranslations = convertDotNotationToNested($newTranslations);
$newTranslations = sanitizePlaceholderKeys($newTranslations);

// Hand off to the shared writer (load existing → collision check →
// merge → write → optional default.json sync). Every helper used
// here lives in src/functions/translationHelpers.php so future
// translation-writing commands get the same plumbing for free.
$writeResult = writeTranslationsToFile($language, $newTranslations, $replaceMode, $keep);

// A refusal is answered by the one answer every caller of the writer gives (qs_translation_write_refusal_response()).
if (!$writeResult['ok']) {
    qs_translation_write_refusal_response($writeResult)->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Translation keys updated successfully')
    ->withData([
        'language' => $language,
        'file' => $writeResult['file'],
        'keys_added' => $writeResult['keysAdded'],
        'keys_updated' => $writeResult['keysUpdated'],
        'keys_unchanged' => 'preserved',
        'default_synced' => $writeResult['defaultSynced']
    ])
    ->send();
