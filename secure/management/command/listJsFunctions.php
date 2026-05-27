<?php
/**
 * listJsFunctions Command
 *
 * Returns available QS.* JavaScript functions that can be used with {{call:...}} syntax.
 *
 * The verb catalog itself lives in secure/src/functions/qsVerbCatalog.php — the
 * single source of truth also consumed by JsonToHtmlRenderer (runtime allowlist)
 * and JsonToPhpCompiler (build allowlist). To add a verb, edit the catalog once;
 * this command + both compilers pick it up automatically.
 *
 * @method GET
 * @route /management/listJsFunctions
 * @auth required
 *
 * @return ApiResponse List of functions with signatures, descriptions, and type (core/custom)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/qsVerbCatalog.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 *
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_listJsFunctions(array $params = [], array $urlParams = []): ApiResponse {

    $coreFunctions = qsVerbCatalog();

    // Decorate each entry with type='core'. (Custom-functions support is a
    // future hook — when it lands, merge them in below and tag type='custom'.)
    foreach ($coreFunctions as &$func) {
        $func['type'] = 'core';
    }
    unset($func);

    $allFunctions = $coreFunctions;
    $coreNames = array_map(fn($f) => $f['name'], $coreFunctions);
    $allNames = $coreNames;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Available QS.* functions for {{call:...}} syntax')
        ->withData([
            'functions' => $allFunctions,
            'count' => count($allFunctions),
            'core_count' => count($coreFunctions),
            'custom_count' => 0,
            'names' => $allNames,
            'core_names' => $coreNames,
            'custom_names' => [],
            'syntax' => '{{call:functionName:arg1,arg2,...}}',
            'special_keywords' => ['event', 'this'],
            'library_paths' => [
                'core' => '/scripts/qs.js'
            ]
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listJsFunctions($trimParams->params(), $trimParams->additionalParams())->send();
}
