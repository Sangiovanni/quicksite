<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

if (!defined('CONFIG')) {
    ApiResponse::create(500, 'server.internal_error')
        ->withMessage("Configuration not loaded")
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Language list retrieved successfully')
    ->withData([
        'multilingual_enabled' => CONFIG['MULTILINGUAL_SUPPORT'],
        'languages' => CONFIG['LANGUAGES_SUPPORTED'],
        'default_language' => CONFIG['LANGUAGE_DEFAULT'],
        'language_names' => CONFIG['LANGUAGES_NAME']
    ])
    ->send();