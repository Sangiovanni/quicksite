<?php
/**
 * listOAuthProviders — the OAuth providers this installation offers, and this project's keys for each
 *
 * @method GET
 * @url /management/p/<projectId>/listOAuthProviders
 * @auth required
 * @permission config.read (editor and above)
 *
 * The providers come from the installation's list — one file its operator edits, which no command
 * writes (see oauthProviderHelpers.php). For each one the answer says whether this project has
 * entered its keys:
 *
 *   preview  the keys the sign-in uses on this installation;
 *   build    the keys a build carries to the deployed site — answered only to the project's owner
 *            and admin (whoever may set them), and left out for everyone else.
 *
 * A secret is never answered, only whether one is stored. The client id is answered: it is not a
 * secret, every sign-in sends it to the visitor's browser.
 *
 * Each provider also carries the callback addresses its console must know (the preview's, in full;
 * the deployed site's, as a path on that site), and whether the routes the oauth-button wizard
 * creates already exist.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthStateStore.php';  // _oauthIsHttps
require_once SECURE_FOLDER_PATH . '/src/functions/requestRuntime.php';  // qs_request_host
require_once SECURE_FOLDER_PATH . '/src/functions/renderBootstrap.php'; // qs_render_public_base

/** True when ROUTES holds the literal segment chain. */
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

/** One key set as the panel shows it: the client id and whether a secret is stored. */
function __listOAuthProviders_keySet(?array $set): array {
    return [
        'client_id'  => $set['client_id'] ?? null,
        'secret_set' => isset($set['client_secret']) && $set['client_secret'] !== null,
    ];
}

function __command_listOAuthProviders(array $params = [], array $urlParams = []): ApiResponse {
    $user = getCurrentUser();
    $canManage = $user !== null && defined('PROJECT_NAME')
        && hasPermission($user, 'setOAuthCredentials', (string) PROJECT_NAME);

    $routes  = defined('ROUTES') ? ROUTES : [];
    $keys    = qs_oauth_keys_read();
    $usedBy  = [];
    foreach (qs_oauth_project_routes() as $r) {
        if ($r['provider'] !== null) {
            $usedBy[$r['provider']][] = $r['route'];
        }
    }
    $previewBase = (_oauthIsHttps() ? 'https' : 'http') . '://' . qs_request_host()
        . rtrim(qs_render_public_base(), '/');

    $providers = [];
    foreach (qs_oauth_providers() as $id => $entry) {
        $startExists    = __listOAuthProviders_routeExists($routes, ['auth', 'oauth', $id, 'start']);
        $callbackExists = __listOAuthProviders_routeExists($routes, ['auth', 'oauth', $id, 'callback']);
        $callbackPath   = '/auth/oauth/' . $id . '/callback';
        $credentials = ['preview' => __listOAuthProviders_keySet($keys[$id]['preview'] ?? null)];
        if ($canManage) {
            $credentials['build'] = __listOAuthProviders_keySet($keys[$id]['build'] ?? null);
        }

        $providers[] = [
            'id'                      => $id,
            'name'                    => qs_oauth_provider_name($id, $entry),
            'console_url'             => $entry['console_url'] ?? null,
            'preset'                  => $entry,
            'scope'                   => (string) $entry['scope'],
            'refresh_token_supported' => (bool) ($entry['refresh_token_supported'] ?? false),
            'has_revoke_url'          => isset($entry['revoke_url']),
            'credentials'             => $credentials,
            'credentials_status'      => $credentials['preview']['secret_set'] ? 'set' : 'missing',
            'callback' => [
                'preview_url' => $previewBase . $callbackPath,
                'path'        => $callbackPath,
            ],
            'resolver_count'          => count($usedBy[$id] ?? []),
            'setup' => [
                'start_route_exists'    => $startExists,
                'callback_route_exists' => $callbackExists,
                'fully_set_up'          => $startExists && $callbackExists,
                'start_route_path'      => 'auth/oauth/' . $id . '/start',
                'callback_route_path'   => 'auth/oauth/' . $id . '/callback',
            ],
        ];
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage(count($providers) === 1
            ? '1 OAuth provider listed'
            : count($providers) . ' OAuth providers listed')
        ->withData([
            'providers'  => $providers,
            'count'      => count($providers),
            'can_manage' => $canManage,
        ]);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listOAuthProviders($trimParams->params(), $trimParams->additionalParams())->send();
}
