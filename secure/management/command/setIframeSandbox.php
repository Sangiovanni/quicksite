<?php
/**
 * setIframeSandbox - Add or update an iframe sandbox rule
 *
 * @method POST
 * @url /management/setIframeSandbox
 * @auth required
 * @permission manageSettings
 *
 * Body (JSON):
 *   {
 *     "tag": "iframe",
 *     "domain": "youtube.com",
 *     "sandbox": "allow-scripts allow-same-origin"
 *   }
 *
 * Or to update the default:
 *   {
 *     "default": "allow-scripts"
 *   }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/IframeSandbox.php';

/**
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_setIframeSandbox(array $params = [], array $urlParams = []): ApiResponse {

    // Mode 1: Update default only
    if (isset($params['default']) && !isset($params['domain'])) {
        $default = $params['default'];
        if (!is_string($default)) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage('"default" must be a string (sandbox permissions or empty)');
        }

        $invalid = IframeSandbox::validatePermissions($default);
        if (!empty($invalid)) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage('Unknown sandbox permissions: ' . implode(', ', $invalid))
                ->withErrors([['field' => 'default', 'invalid' => $invalid]]);
        }

        $neverUsed = array_intersect(
            preg_split('/\s+/', trim($default)),
            IframeSandbox::NEVER_ALLOWED
        );

        $config = IframeSandbox::loadConfig();
        $config['default'] = IframeSandbox::stripNeverAllowed($default);

        if (!IframeSandbox::saveConfig($config)) {
            return ApiResponse::create(500, 'api.error.write_failed')
                ->withMessage('Failed to save iframe sandbox config');
        }

        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Default sandbox updated')
            ->withData([
                'default' => $config['default'],
                'never_allowed_stripped' => !empty($neverUsed) ? array_values($neverUsed) : null,
            ]);
    }

    // Mode 2: Add or update a domain rule
    $tag = $params['tag'] ?? null;
    $domain = $params['domain'] ?? null;
    $sandbox = $params['sandbox'] ?? '';

    if (!is_string($tag) || trim($tag) === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('"tag" must be a non-empty string (e.g. "iframe", "video", "audio")');
    }

    $tag = strtolower(trim($tag));

    if (!IframeSandbox::isValidTag($tag)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid embed tag: \"{$tag}\". Valid tags: " . implode(', ', IframeSandbox::VALID_EMBED_TAGS))
            ->withErrors([['field' => 'tag', 'invalid' => $tag, 'valid' => IframeSandbox::VALID_EMBED_TAGS]]);
    }

    if (!is_string($domain) || trim($domain) === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('"domain" must be a non-empty string');
    }

    $domain = strtolower(trim($domain));

    if (!IframeSandbox::isValidDomain($domain)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid domain: {$domain}")
            ->withErrors([['field' => 'domain', 'invalid' => $domain]]);
    }

    if (!is_string($sandbox)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('"sandbox" must be a string (permissions or empty)');
    }

    $invalid = IframeSandbox::validatePermissions($sandbox);
    if (!empty($invalid)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Unknown sandbox permissions: ' . implode(', ', $invalid))
            ->withErrors([['field' => 'sandbox', 'invalid' => $invalid]]);
    }

    $neverUsed = array_intersect(
        preg_split('/\s+/', trim($sandbox)),
        IframeSandbox::NEVER_ALLOWED
    );

    $cleanSandbox = IframeSandbox::stripNeverAllowed($sandbox);

    $config = IframeSandbox::loadConfig();
    if (!isset($config['tags'][$tag])) {
        $config['tags'][$tag] = [];
    }
    $existed = isset($config['tags'][$tag][$domain]);
    $config['tags'][$tag][$domain] = $cleanSandbox;

    if (!IframeSandbox::saveConfig($config)) {
        return ApiResponse::create(500, 'api.error.write_failed')
            ->withMessage('Failed to save iframe sandbox config');
    }

    $action = $existed ? 'updated' : 'added';

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Sandbox rule {$action}")
        ->withData([
            'tag' => $tag,
            'domain' => $domain,
            'sandbox' => $cleanSandbox,
            'action' => $action,
            'never_allowed_stripped' => !empty($neverUsed) ? array_values($neverUsed) : null,
        ]);
}

// =============================================================================
// DIRECT EXECUTION
// =============================================================================

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setIframeSandbox($trimParams->params(), $trimParams->additionalParams())->send();
}
