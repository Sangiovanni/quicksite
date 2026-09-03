<?php
/**
 * Directory lookups — who exists, and what the roles are.
 *
 * NOT commands. The command surface is a CLI for DEVELOPING A PROJECT; looking
 * a person up in order to invite them, and reading the fixed role catalogue,
 * are facts about the INSTALLATION rather than about any project's content, so
 * they live here and are served by /admin/self (beta.11 S6).
 *
 * These were `findUser` (users.lookup) and `listRoles` (roles.read). Both
 * categories were `scope: global`, `access: 'any'` — so the only thing
 * hasPermission() contributed was "is the caller authenticated", which
 * qs_admin_json_boot() establishes before either of these runs.
 *
 * Must be required AFTER init.php — it depends on SECURE_FOLDER_PATH.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * EXACT public-name lookup — the primitive behind "invite someone".
 *
 * Look the person up by the display name they gave you, confirm the
 * {user_id, name} pair, invite by id. Display names are NOT unique — several
 * matches may return; the opaque user id is the unique PUBLIC identifier that
 * disambiguates.
 *
 * PRIVACY: the response carries user_id + name ONLY. The PRIVATE username is
 * never searchable and never returned. Exact match only — no substring or fuzzy
 * search, so this is not a roster-harvesting surface.
 *
 * The name travels in the request BODY rather than the URL: it is somebody
 * else's display name, and a query string is the one part of a request that
 * reliably lands in access logs and proxy history.
 *
 * @param array $params name
 * @return ApiResponse matches[] of {user_id, name}
 */
function qs_directory_find_user(array $params): ApiResponse {
    $name = trim((string)($params['name'] ?? ''));
    if ($name === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('name is required')
            ->withErrors(['name' => 'Required field']);
    }

    $needle = mb_strtolower($name);
    $matches = [];
    foreach (loadUsersConfig()['users'] ?? [] as $userId => $user) {
        $candidate = $user['name'] ?? null;
        if (is_string($candidate) && $candidate !== '' && mb_strtolower(trim($candidate)) === $needle) {
            $matches[] = ['user_id' => (string)$userId, 'name' => $candidate];
        }
    }

    // Zero matches is a successful search, not an error — names are public
    // display data and their existence is the feature (unlike usernames).
    return ApiResponse::create(200, 'operation.success')
        ->withMessage(count($matches) === 1 ? '1 user found' : count($matches) . ' users found')
        ->withData([
            'query'   => $name,
            'matches' => $matches,
            'count'   => count($matches),
        ]);
}

/**
 * The fixed role catalogue with its metadata.
 *
 * Non-privileged callers see only role names, descriptions and a command COUNT.
 * The owner/admin of the caller's current project additionally see each role's
 * full command list — there is no superadmin, so "privileged" is resolved
 * per-project like every other authority in QuickSite.
 *
 * The caller is already resolved by the shared gate, so unlike the command this
 * replaces there is no second bearer-token validation here.
 *
 * @param array $user The authenticated caller (from qs_admin_json_boot)
 * @return ApiResponse
 */
function qs_directory_list_roles(array $user): ApiResponse {
    $roles = loadRolesConfig();

    if (empty($roles)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No roles configured')
            ->withData([
                'roles' => [],
                'count' => 0,
            ]);
    }

    // Owner/admin of the caller's current project see the full command lists.
    $role = isset($user['id'], $user['selected_project'])
        ? getUserRoleForProject($user['id'], $user['selected_project'])
        : null;
    $isPrivileged = in_array($role, ['owner', 'admin'], true);

    $rolesList = [];
    foreach ($roles as $name => $config) {
        // Commands are expanded from the role's categories, not a flat list.
        $commands = getRoleCommands($name) ?? [];
        $roleData = [
            'name'          => $name,
            'description'   => $config['description'] ?? '',
            'builtin'       => $config['builtin'] ?? false,
            'command_count' => count($commands),
        ];

        // Only privileged users see the full command list
        if ($isPrivileged) {
            $roleData['commands'] = $commands;
        }

        $rolesList[] = $roleData;
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Roles retrieved successfully')
        ->withData([
            'roles'             => $rolesList,
            'count'             => count($rolesList),
            'includes_commands' => $isPrivileged,
        ]);
}
