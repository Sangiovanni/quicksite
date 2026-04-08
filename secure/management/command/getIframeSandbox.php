<?php
/**
 * getIframeSandbox - Get iframe sandbox configuration
 *
 * @method GET
 * @url /management/getIframeSandbox
 * @auth required
 * @permission read
 *
 * Returns the current iframe sandbox rules for the active project.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/IframeSandbox.php';

/**
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getIframeSandbox(array $params = [], array $urlParams = []): ApiResponse {
    $config = IframeSandbox::loadConfig();
    $tags = $config['tags'] ?? [];

    // Ensure each valid tag has a key (even if empty) for the UI
    $normalizedTags = [];
    foreach (IframeSandbox::VALID_EMBED_TAGS as $tag) {
        $rules = $tags[$tag] ?? [];
        $normalizedTags[$tag] = empty($rules) ? (object)[] : $rules;
    }

    return ApiResponse::create(200, 'operation.success')
        ->withData([
            'tags' => $normalizedTags,
            'default' => $config['default'],
            'valid_permissions' => IframeSandbox::VALID_PERMISSIONS,
            'never_allowed' => IframeSandbox::NEVER_ALLOWED,
            'valid_tags' => IframeSandbox::VALID_EMBED_TAGS,
        ]);
}

// =============================================================================
// DIRECT EXECUTION
// =============================================================================

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getIframeSandbox($trimParams->params(), $trimParams->additionalParams())->send();
}
