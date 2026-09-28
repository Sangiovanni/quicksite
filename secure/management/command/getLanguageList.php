<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';

/**
 * getLanguageList - Returns the installation's language list: every language
 * a project can add, each with its name — and the installation's default
 * language, the one a new project starts in when none is chosen.
 *
 * Unlike getLangList (which returns the project's configured languages),
 * this returns the list itself. It belongs to the installation, not to a
 * project, so it is callable before a project exists.
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
    $languages = [];
    foreach (qs_language_list() as $code => $name) {
        $languages[] = [
            'code' => $code,
            'name' => $name
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Language list retrieved successfully')
        ->withData([
            'languages' => $languages,
            'default_language' => qs_language_default()
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getLanguageList()->send();
}
