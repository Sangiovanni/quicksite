<?php
/**
 * getIframeSandbox - Read the embed sandbox policy
 *
 * @method GET
 * @url /management/getIframeSandbox
 * @auth required
 * @permission read
 *
 * Returns the INSTALL-WIDE embed policy that governs every project's iframes.
 * The policy is set at deployment (<secure>/management/config/embed-policy.json)
 * and cannot be changed from the panel or by any command — this read exists so a
 * caller (a person or an agent) can see which hosts embed cleanly before adding
 * an <iframe> that would otherwise be silently sandboxed. The answer is the same
 * for every project marker on the install.
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

    // loadConfig() normalises to space-joined strings; present them as token
    // arrays, the shape the policy file uses and the read-only page renders.
    $toTokens = static function (string $s): array {
        $s = trim($s);
        return $s === '' ? [] : preg_split('/\s+/', $s);
    };

    $hosts = [];
    foreach ($config['hosts'] as $host) {
        $hosts[] = [
            'name' => $host['name'],
            'parameters' => $toTokens($host['sandbox']),
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Embed policy retrieved')
        ->withData([
            'default' => $toTokens($config['default']),
            'hosts' => $hosts,
            'valid_permissions' => IframeSandbox::VALID_PERMISSIONS,
            'never_allowed' => IframeSandbox::NEVER_ALLOWED,
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
