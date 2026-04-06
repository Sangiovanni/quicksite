<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';

/**
 * getLanguageList - Returns the full master list of available languages
 * 
 * Unlike getLangList (which returns the project's configured languages),
 * this returns all languages that can be added to a project.
 * 
 * @method GET
 * @url /management/getLanguageList
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
function __command_getLanguageList(array $params = [], array $urlParams = []): ApiResponse {
    $allLanguages = WorkflowManager::getAllLanguageNames();
    
    $languages = [];
    foreach ($allLanguages as $code => $name) {
        $languages[] = [
            'code' => $code,
            'name' => $name
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Language list retrieved successfully')
        ->withData([
            'languages' => $languages
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getLanguageList()->send();
}
