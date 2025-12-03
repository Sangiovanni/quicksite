<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$params = $trimParametersManagement->params();

// Validate required parameter
if (!isset($params['code'])) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([['field' => 'code', 'reason' => 'missing']])
        ->send();
}

$langCode = $params['code'];

// Validate language code format
if (!preg_match('/^[a-z]{2,3}$/', $langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->withErrors([['field' => 'code', 'value' => $langCode]])
        ->send();
}

// Check if language exists
if (!in_array($langCode, CONFIG['LANGUAGES_SUPPORTED'])) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Language not found")
        ->withData([
            'code' => $langCode,
            'existing_languages' => CONFIG['LANGUAGES_SUPPORTED']
        ])
        ->send();
}

// Prevent removing default language
if ($langCode === CONFIG['LANGUAGE_DEFAULT']) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Cannot remove default language")
        ->withErrors([['field' => 'code', 'reason' => 'is_default_language']])
        ->send();
}

// Prevent removing last language
if (count(CONFIG['LANGUAGES_SUPPORTED']) === 1) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Cannot remove last language")
        ->send();
}

// --- UPDATE CONFIG FILE ---
$config_path = CONFIG_PATH;

$config_content = file_get_contents($config_path);
if ($config_content === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read configuration file")
        ->send();
}

// Parse current config
$current_config = require $config_path;

// Remove language
$current_config['LANGUAGES_SUPPORTED'] = array_values(
    array_filter($current_config['LANGUAGES_SUPPORTED'], fn($lang) => $lang !== $langCode)
);
unset($current_config['LANGUAGES_NAME'][$langCode]);

// Build new config file content (same logic as addLang)
$new_config_content = "<?php\n\nreturn [\n";
foreach ($current_config as $key => $value) {
    $new_config_content .= "    '{$key}' => ";
    
    if (is_bool($value)) {
        $new_config_content .= $value ? 'true' : 'false';
    } elseif (is_string($value)) {
        $new_config_content .= "'" . addslashes($value) . "'";
    } elseif (is_array($value)) {
        if (array_keys($value) === range(0, count($value) - 1)) {
            $new_config_content .= '[' . implode(', ', array_map(fn($v) => "'" . addslashes($v) . "'", $value)) . ']';
        } else {
            $new_config_content .= "[\n";
            foreach ($value as $k => $v) {
                $new_config_content .= "        '{$k}' => '" . addslashes($v) . "',\n";
            }
            $new_config_content .= "    ]";
        }
    }
    
    $new_config_content .= ",\n";
}
$new_config_content .= "]; ?>\n";

// Write updated config
if (file_put_contents($config_path, $new_config_content, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to write configuration file")
        ->send();
}

// --- DELETE TRANSLATION FILE ---
$translation_file = SECURE_FOLDER_PATH . '/translate/' . $langCode . '.json';
$deleted = false;

if (file_exists($translation_file)) {
    $deleted = @unlink($translation_file);
    if (!$deleted) {
        error_log("Warning: Failed to delete translation file: {$translation_file}");
    }
}

// Success
ApiResponse::create(200, 'operation.success')
    ->withMessage('Language removed successfully')
    ->withData([
        'code' => $langCode,
        'config_updated' => $config_path,
        'translation_file_deleted' => $deleted,
        'remaining_languages' => $current_config['LANGUAGES_SUPPORTED']
    ])
    ->send();