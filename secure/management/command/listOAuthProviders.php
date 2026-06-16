<?php
/**
 * listOAuthProviders — List available OAuth provider presets
 *
 * @method GET
 * @url /management/listOAuthProviders
 * @auth required
 * @permission viewer
 *
 * Returns the union of:
 *   - admin  catalogue: secure/admin/config/oauth-presets.json
 *   - project overrides: secure/projects/<active>/data/oauth-presets.json
 *
 * Per the Slice 2.5 lookup order, per-project entries override admin
 * entries at PROVIDER level (full-entry replace, not field-level merge).
 * This endpoint mirrors that semantic for the wizard's picker: the
 * effective preset (whichever wins per provider) is what the wizard
 * shows + uses.
 *
 * Each provider entry includes a `setup` summary describing whether
 * the per-provider routes already exist in the active project's
 * routes.php. The oauth-button wizard uses this to drive its
 * "already-set-up" warning before reusing an existing setup.
 *
 * Beta.9 A1 Slice 4 (locked 2026-06-15).
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Read + decode a JSON presets file. Returns [] on missing / invalid
 * file. Caller filters ignore-marker keys (starting with `_`).
 */
function __listOAuthProviders_readPresets(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Check whether ROUTES has a literal segment chain set. Used to detect
 * existing /auth/oauth/<provider>/start + /callback for the setup
 * summary.
 */
function __listOAuthProviders_routeExists(array $routes, array $segments): bool {
    $current = $routes;
    foreach ($segments as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    return true;
}

function __command_listOAuthProviders(array $params = [], array $urlParams = []): ApiResponse {
    $adminPath   = SECURE_FOLDER_PATH . '/admin/config/oauth-presets.json';
    $projectPath = defined('PROJECT_PATH')
        ? PROJECT_PATH . '/data/oauth-presets.json'
        : null;

    $admin   = __listOAuthProviders_readPresets($adminPath);
    $project = $projectPath !== null ? __listOAuthProviders_readPresets($projectPath) : [];

    $merged = [];
    $sources = [];

    foreach ($admin as $key => $preset) {
        if (!is_string($key) || $key === '' || $key[0] === '_' || !is_array($preset)) {
            continue;
        }
        $merged[$key]  = $preset;
        $sources[$key] = 'admin';
    }
    foreach ($project as $key => $preset) {
        if (!is_string($key) || $key === '' || $key[0] === '_' || !is_array($preset)) {
            continue;
        }
        $merged[$key]  = $preset;
        $sources[$key] = isset($sources[$key]) ? 'project-override' : 'project';
    }

    $routes = defined('ROUTES') ? ROUTES : [];

    $providers = [];
    foreach ($merged as $id => $preset) {
        $startExists    = __listOAuthProviders_routeExists($routes, ['auth', 'oauth', $id, 'start']);
        $callbackExists = __listOAuthProviders_routeExists($routes, ['auth', 'oauth', $id, 'callback']);

        $providers[] = [
            'id'                      => $id,
            'name'                    => ucfirst(str_replace('-', ' ', $id)),
            'source'                  => $sources[$id],
            'scope'                   => isset($preset['scope']) ? (string) $preset['scope'] : '',
            'refresh_token_supported' => (bool) ($preset['refresh_token_supported'] ?? false),
            'has_revoke_url'          => isset($preset['revoke_url']) && is_string($preset['revoke_url']) && $preset['revoke_url'] !== '',
            'setup' => [
                'start_route_exists'    => $startExists,
                'callback_route_exists' => $callbackExists,
                'fully_set_up'          => $startExists && $callbackExists,
                'start_route_path'      => 'auth/oauth/' . $id . '/start',
                'callback_route_path'   => 'auth/oauth/' . $id . '/callback',
            ],
        ];
    }

    usort($providers, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return ApiResponse::create(200, 'operation.success')
        ->withMessage(count($providers) === 1
            ? '1 OAuth provider listed'
            : count($providers) . ' OAuth providers listed')
        ->withData([
            'providers' => $providers,
            'count'     => count($providers),
        ]);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listOAuthProviders($trimParams->params(), $trimParams->additionalParams())->send();
}
