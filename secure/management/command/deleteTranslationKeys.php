<?php
/**
 * deleteTranslationKeys - Remove specific translation keys
 * 
 * Deletes specified keys from a language file while preserving others.
 * Supports dot notation for nested keys (e.g., "home.hero.title")
 * 
 * @param string language - Language code (e.g., 'en', 'fr')
 * @param array keys - Array of keys to delete (supports dot notation)
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

$params = $trimParametersManagement->params();

if (!isset($params['language']) || !isset($params['keys'])) {
    $missing = [];
    if (!isset($params['language'])) $missing[] = 'language';
    if (!isset($params['keys'])) $missing[] = 'keys';
    
    ApiResponse::create(400, 'validation.required')
        ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
        ->send();
}

$language = $params['language'];
$keysToDelete = $params['keys'];

// Type validation - language must be string
if (!is_string($language)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The language parameter must be a string.')
        ->withErrors([
            ['field' => 'language', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// SECURITY: Check for path traversal attempts
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

// Length validation for language code
if (strlen($language) > 10) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Language code must not exceed 10 characters')
        ->withErrors([
            ['field' => 'language', 'value' => $language, 'max_length' => 10]
        ])
        ->send();
}

// SECURITY: Validate language code format
if (!preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $language)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid language code format')
        ->withErrors([
            ['field' => 'language', 'value' => $language, 'expected' => 'ISO 639 or BCP 47 format (e.g., en, fr, en-US, zh-Hans)']
        ])
        ->send();
}

// Type validation - keys must be array
if (!is_array($keysToDelete)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The keys parameter must be an array of strings.')
        ->withErrors([
            ['field' => 'keys', 'reason' => 'invalid_type', 'expected' => 'array']
        ])
        ->send();
}

// Validate each key is a string and not empty
foreach ($keysToDelete as $index => $key) {
    if (!is_string($key) || trim($key) === '') {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage("Invalid key at index {$index}")
            ->withErrors([
                ['field' => "keys[{$index}]", 'reason' => 'must be a non-empty string']
            ])
            ->send();
    }
    
    // SECURITY: Validate key format (alphanumeric, dots, underscores, hyphens)
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid key format at index {$index}")
            ->withErrors([
                ['field' => "keys[{$index}]", 'value' => $key, 'reason' => 'contains invalid characters']
            ])
            ->send();
    }
}

$translations_file = SECURE_FOLDER_PATH . '/translate/' . $language . '.json';

// Check if file exists
if (!file_exists($translations_file)) {
    ApiResponse::create(404, 'resource.not_found')
        ->withMessage("Translation file for language '{$language}' not found")
        ->send();
}

// Load existing translations
$existingJson = @file_get_contents($translations_file);
if ($existingJson === false) {
    ApiResponse::create(500, 'server.file_read_failed')
        ->withMessage("Failed to read translation file")
        ->send();
}

$translations = json_decode($existingJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to parse translation file: " . json_last_error_msg())
        ->send();
}

/**
 * Delete a key - handles both flat keys and dot notation for nested
 * First checks if the exact key exists at root level (flat key like "menu.home")
 * Then tries to delete via nested path (menu -> home)
 * Returns true if key was found and deleted, false otherwise
 */
function deleteNestedKey(array &$array, string $dotKey): bool {
    // First, check if this exact key exists at root level (flat key)
    if (isset($array[$dotKey])) {
        unset($array[$dotKey]);
        return true;
    }
    
    // Then try nested deletion using dot notation
    $keys = explode('.', $dotKey);
    $lastKey = array_pop($keys);
    
    $current = &$array;
    foreach ($keys as $key) {
        if (!is_array($current) || !isset($current[$key])) {
            return false; // Path doesn't exist
        }
        $current = &$current[$key];
    }
    
    if (isset($current[$lastKey])) {
        unset($current[$lastKey]);
        return true;
    }
    
    return false;
}

// Track results
$deleted = [];
$notFound = [];

foreach ($keysToDelete as $key) {
    if (deleteNestedKey($translations, $key)) {
        $deleted[] = $key;
    } else {
        $notFound[] = $key;
    }
}

// Clean up empty parent objects after deletion
function cleanEmptyParents(array &$array): void {
    foreach ($array as $key => &$value) {
        if (is_array($value)) {
            cleanEmptyParents($value);
            if (empty($value)) {
                unset($array[$key]);
            }
        }
    }
}

cleanEmptyParents($translations);

// Encode and save
$json_content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json_content === false) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Failed to encode translations to JSON")
        ->send();
}

if (file_put_contents($translations_file, $json_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write translation file")
        ->send();
}

$status = count($deleted) > 0 ? 200 : 404;
$code = count($deleted) > 0 ? 'operation.success' : 'resource.not_found';
$message = count($deleted) > 0 
    ? 'Translation keys deleted successfully' 
    : 'No keys were found to delete';

ApiResponse::create($status, $code)
    ->withMessage($message)
    ->withData([
        'language' => $language,
        'file' => $translations_file,
        'deleted' => $deleted,
        'deleted_count' => count($deleted),
        'not_found' => $notFound,
        'not_found_count' => count($notFound)
    ])
    ->send();
