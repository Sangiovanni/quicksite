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
 * Load the union of secrets known to the project — per-project JSON
 * first, admin PHP fallback. Returns map of providerId => true when
 * client_id is present. Used only to compute credentials_status; the
 * actual secret values are NEVER returned by listOAuthProviders.
 */
function __listOAuthProviders_credentialSet(): array {
    $set = [];
    $adminPath = SECURE_FOLDER_PATH . '/admin/config/oauth-secrets.php';
    if (file_exists($adminPath)) {
        $admin = require $adminPath;
        if (is_array($admin)) {
            foreach ($admin as $id => $entry) {
                if (is_string($id) && is_array($entry) && isset($entry['client_id']) && $entry['client_id'] !== '') {
                    $set[$id] = true;
                }
            }
        }
    }
    if (defined('PROJECT_PATH')) {
        $projectPath = PROJECT_PATH . '/data/oauth-secrets.json';
        if (file_exists($projectPath)) {
            $raw = @file_get_contents($projectPath);
            $project = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($project)) {
                foreach ($project as $id => $entry) {
                    if (is_string($id) && is_array($entry) && isset($entry['client_id']) && $entry['client_id'] !== '') {
                        $set[$id] = true;
                    }
                }
            }
        }
    }
    return $set;
}

/**
 * Count how many route-resolvers explicitly reference a provider id
 * (literal `provider: "<id>"` field on oauth-start / oauth-callback /
 * oauth-logout kinds). Param-placeholder references like
 * `provider: "{:provider}"` are NOT counted — they're ambiguous and
 * the in-use guard at delete time runs a fuller scan anyway.
 *
 * Cheap: one file read + a shallow walk over the resolvers sidecar.
 */
function __listOAuthProviders_resolverCounts(): array {
    $counts = [];
    if (!defined('PROJECT_PATH')) {
        return $counts;
    }
    $sidecar = PROJECT_PATH . '/data/route-resolvers.json';
    if (!file_exists($sidecar)) {
        return $counts;
    }
    $raw = @file_get_contents($sidecar);
    $all = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($all)) {
        return $counts;
    }
    foreach ($all as $entry) {
        // Storage shape: single resolver = scalar, multi = array
        $configs = (isset($entry['kind']) && is_string($entry['kind'])) ? [$entry] : $entry;
        if (!is_array($configs)) {
            continue;
        }
        foreach ($configs as $config) {
            if (!is_array($config)) continue;
            $kind = $config['kind'] ?? null;
            if ($kind !== 'oauth-start' && $kind !== 'oauth-callback' && $kind !== 'oauth-logout') {
                continue;
            }
            $provider = $config['provider'] ?? null;
            if (!is_string($provider) || $provider === '' || preg_match('/^\{:\w+\}$/', $provider)) {
                continue;
            }
            $counts[$provider] = ($counts[$provider] ?? 0) + 1;
        }
    }
    return $counts;
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

    $routes        = defined('ROUTES') ? ROUTES : [];
    $credentialSet = __listOAuthProviders_credentialSet();
    $resolverCounts = __listOAuthProviders_resolverCounts();

    $providers = [];
    foreach ($merged as $id => $preset) {
        $startExists    = __listOAuthProviders_routeExists($routes, ['auth', 'oauth', $id, 'start']);
        $callbackExists = __listOAuthProviders_routeExists($routes, ['auth', 'oauth', $id, 'callback']);
        $preset_obj     = is_array($preset) ? $preset : [];

        $providers[] = [
            'id'                      => $id,
            'name'                    => ucfirst(str_replace('-', ' ', $id)),
            'source'                  => $sources[$id],
            'preset'                  => $preset_obj,
            'scope'                   => isset($preset_obj['scope']) ? (string) $preset_obj['scope'] : '',
            'refresh_token_supported' => (bool) ($preset_obj['refresh_token_supported'] ?? false),
            'has_revoke_url'          => isset($preset_obj['revoke_url']) && is_string($preset_obj['revoke_url']) && $preset_obj['revoke_url'] !== '',
            'credentials_status'      => isset($credentialSet[$id]) ? 'set' : 'missing',
            'resolver_count'          => $resolverCounts[$id] ?? 0,
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
