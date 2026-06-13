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
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

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

// Normalise the incoming payload: flatten dot-notation, strip any
// stray {{ component placeholder keys. Both transforms idempotent.
$newTranslations = convertDotNotationToNested($newTranslations);
$newTranslations = sanitizePlaceholderKeys($newTranslations);

// Hand off to the shared writer (load existing → collision check →
// merge → write → optional default.json sync). C9: every helper used
// here lives in src/functions/translationHelpers.php so future
// translation-writing commands get the same plumbing for free.
$writeResult = writeTranslationsToFile($language, $newTranslations, $replaceMode);

if (!$writeResult['ok']) {
    if ($writeResult['reason'] === 'collisions') {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage($writeResult['collisions'][0]['suggestion'])
            ->withErrors($writeResult['collisions'])
            ->withData(['collisions' => $writeResult['collisions']])
            ->send();
    }
    if ($writeResult['reason'] === 'json_encode_failed') {
        ApiResponse::create(500, 'server.internal_error')
            ->withMessage($writeResult['message'])
            ->send();
    }
    if ($writeResult['reason'] === 'file_write_failed') {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage($writeResult['message'])
            ->send();
    }
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
