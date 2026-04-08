<?php
/**
 * removeIframeSandbox - Remove an iframe sandbox rule
 *
 * @method POST
 * @url /management/removeIframeSandbox
 * @auth required
 * @permission manageSettings
 *
 * Body (JSON):
 *   { "tag": "iframe", "domain": "youtube.com" }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/IframeSandbox.php';

/**
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_removeIframeSandbox(array $params = [], array $urlParams = []): ApiResponse {
    $tag = $params['tag'] ?? null;
    if (!is_string($tag) || trim($tag) === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('"tag" must be a non-empty string (e.g. "iframe", "video", "audio")');
    }

    $tag = strtolower(trim($tag));

    if (!IframeSandbox::isValidTag($tag)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid embed tag: \"{$tag}\". Valid tags: " . implode(', ', IframeSandbox::VALID_EMBED_TAGS));
    }

    $config = IframeSandbox::loadConfig();
    $tagRules = $config['tags'][$tag] ?? [];

    if (empty($tagRules)) {
        return ApiResponse::create(404, 'operation.not_found')
            ->withMessage("No sandbox rules exist for <{$tag}>");
    }

    $domain = $params['domain'] ?? null;
    if (!is_string($domain) || trim($domain) === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Provide "domain" (string) to identify the rule to remove');
    }

    $domain = strtolower(trim($domain));

    if (!isset($tagRules[$domain])) {
        return ApiResponse::create(404, 'operation.not_found')
            ->withMessage("No rule found for domain '{$domain}' under <{$tag}>");
    }

    $removedSandbox = $tagRules[$domain];
    unset($config['tags'][$tag][$domain]);

    if (!IframeSandbox::saveConfig($config)) {
        return ApiResponse::create(500, 'api.error.write_failed')
            ->withMessage('Failed to save iframe sandbox config');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Sandbox rule removed')
        ->withData([
            'tag' => $tag,
            'domain' => $domain,
            'removed_sandbox' => $removedSandbox,
            'remaining_rules' => count($config['tags'][$tag]),
        ]);
}

// =============================================================================
// DIRECT EXECUTION
// =============================================================================

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_removeIframeSandbox($trimParams->params(), $trimParams->additionalParams())->send();
}
