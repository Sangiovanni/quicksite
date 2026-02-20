<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * getLangList - Retrieves supported languages and multilingual configuration
 * 
 * @method GET
 * @url /management/getLangList
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getLangList(array $params = [], array $urlParams = []): ApiResponse {
    if (!defined('CONFIG')) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Configuration not loaded");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Language list retrieved successfully')
        ->withData([
            'multilingual_enabled' => CONFIG['MULTILINGUAL_SUPPORT'],
            'languages' => CONFIG['LANGUAGES_SUPPORTED'],
            'default_language' => CONFIG['LANGUAGE_DEFAULT'],
            'language_names' => CONFIG['LANGUAGES_NAME']
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getLangList()->send();
}