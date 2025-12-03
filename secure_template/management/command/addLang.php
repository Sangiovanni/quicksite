<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$params = $trimParametersManagement->params();

// Validate required parameters
if (!isset($params['code']) || !isset($params['name'])) {
    $missing = [];
    if (!isset($params['code'])) $missing[] = 'code';
    if (!isset($params['name'])) $missing[] = 'name';
    
    ApiResponse::create(400, 'validation.required')
        ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
        ->send();
}

$langCode = $params['code'];
$langName = $params['name'];

// Validate language code format (2-3 lowercase letters)
if (!preg_match('/^[a-z]{2,3}$/', $langCode)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage("Invalid language code format")
        ->withErrors([['field' => 'code', 'value' => $langCode, 'expected' => '2-3 lowercase letters (e.g., en, fr, es)']])
        ->send();
}

// Check if language already exists
if (in_array($langCode, CONFIG['LANGUAGES_SUPPORTED'])) {
    ApiResponse::create(409, 'conflict.duplicate')
        ->withMessage("Language already exists")
        ->withData([
            'code' => $langCode,
            'existing_languages' => CONFIG['LANGUAGES_SUPPORTED']
        ])
        ->send();
}

// --- UPDATE CONFIG FILE ---
$config_path = CONFIG_PATH;

if (!file_exists($config_path)) {
    ApiResponse::create(500, 'file.not_found')
        ->withMessage("Configuration file not found")
        ->send();
}

$config_content = file_get_contents($config_path);
if ($config_content === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage("Failed to read configuration file")
        ->send();
}

// Parse current config
$current_config = require $config_path;

// Add new language
$current_config['LANGUAGES_SUPPORTED'][] = $langCode;
$current_config['LANGUAGES_NAME'][$langCode] = $langName;

// Build new config file content
$new_config_content = "<?php\n\nreturn [\n";
foreach ($current_config as $key => $value) {
    $new_config_content .= "    '{$key}' => ";
    
    if (is_bool($value)) {
        $new_config_content .= $value ? 'true' : 'false';
    } elseif (is_string($value)) {
        $new_config_content .= "'" . addslashes($value) . "'";
    } elseif (is_array($value)) {
        if (array_keys($value) === range(0, count($value) - 1)) {
            // Indexed array
            $new_config_content .= '[' . implode(', ', array_map(fn($v) => "'" . addslashes($v) . "'", $value)) . ']';
        } else {
            // Associative array
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

// --- CREATE TRANSLATION FILE ---
// Copy from default language
$default_lang = CONFIG['LANGUAGE_DEFAULT'];
$source_file = SECURE_FOLDER_PATH . '/translate/' . $default_lang . '.json';
$target_file = SECURE_FOLDER_PATH . '/translate/' . $langCode . '.json';

if (!file_exists($source_file)) {
    // Fallback: create empty translation file
    $empty_translations = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($target_file, $empty_translations, LOCK_EX);
} else {
    // Copy default translations as starting point
    if (!copy($source_file, $target_file)) {
        ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to create translation file")
            ->send();
    }
}

// Success
ApiResponse::create(201, 'operation.success')
    ->withMessage('Language added successfully')
    ->withData([
        'code' => $langCode,
        'name' => $langName,
        'config_updated' => $config_path,
        'translation_file' => $target_file,
        'copied_from' => file_exists($source_file) ? $default_lang : 'empty'
    ])
    ->send();