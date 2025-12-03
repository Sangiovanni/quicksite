<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

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
$translations = $params['translations'];

// Validate translations is an object/array
if (!is_array($translations)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Translations must be an object/array")
        ->send();
}

$translations_file = SECURE_FOLDER_PATH . '/translate/' . $language . '.json';

// Encode to JSON
$json_content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

ApiResponse::create(200, 'operation.success')
    ->withMessage('Translations updated successfully')
    ->withData([
        'language' => $language,
        'file' => $translations_file
    ])
    ->send();