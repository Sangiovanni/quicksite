<?php
/**
 * Account self-service — the caller's own login, their own footprint.
 *
 * NOT commands. The command surface is a CLI for DEVELOPING A PROJECT; managing
 * the account you sign in with is not project development, so it lives here and
 * is served by /admin/self (beta.11 S6).
 *
 * These were `changePassword`, `deleteMyAccount`, `getMySpaceUsage` and
 * `getMyPermissions`. The logic is unchanged: every authorization each one
 * performed as a command it performs here, in the same order. What the
 * /management dispatcher used to contribute was authentication plus a
 * hasPermission() call that, for a global category with access 'any', resolves
 * to "is this caller authenticated" and nothing more — which is exactly what
 * qs_admin_json_boot() establishes before any of these run.
 *
 * The two credential operations keep their own defences, and those were never
 * the dispatcher's: the login backoff (qs_login_throttle_*) and the
 * current-password re-check both live in the function body, so they travel with
 * it. A stolen session token still cannot change a password or delete an
 * account on its own.
 *
 * Must be required AFTER init.php — it depends on SECURE_FOLDER_PATH.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Change the authenticated caller's password.
 *
 * Requires the CURRENT password — a stolen access token alone must not be
 * enough to take the account over — and is throttled per user with the login
 * backoff, so a stolen token cannot become a password brute-force oracle
 * either.
 *
 * On success every OTHER session family of the user is revoked (containment: a
 * password change is the "I suspect theft" action); the session performing the
 * change survives.
 *
 * @param array $params current_password, new_password
 * @return ApiResponse
 */
function qs_account_change_password(array $params): ApiResponse {
    $current = (string)($params['current_password'] ?? '');
    $new = (string)($params['new_password'] ?? '');
    if ($current === '' || $new === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('current_password and new_password are required')
            ->withData(['required' => ['current_password', 'new_password']]);
    }

    $auth = getCurrentAuth();
    if ($auth === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    $user = $auth['user'];
    $userId = $auth['userId'];

    $hash = $user['password_hash'] ?? null;
    if (!is_string($hash) || $hash === '') {
        return ApiResponse::create(400, 'auth.externally_managed')
            ->withMessage('This account has no local password (externally managed)');
    }

    $minLength = qs_registration_config()['min_password_length'];
    if (mb_strlen($new) < $minLength) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('New password is too short')
            ->withErrors(['new_password' => 'Minimum length: ' . $minLength])
            ->withData(['min_length' => $minLength]);
    }

    // Same brute-force backoff as login, keyed on the same credential target.
    $throttleKey = is_string($user['username'] ?? null) && $user['username'] !== '' ? $user['username'] : $userId;
    $wait = qs_login_throttle_check($throttleKey);
    if ($wait > 0) {
        return ApiResponse::create(429, 'auth.throttled')
            ->withMessage('Too many failed attempts — try again later')
            ->withData(['retry_after' => $wait]);
    }

    if (!password_verify($current, $hash)) {
        qs_login_throttle_fail($throttleKey);
        return ApiResponse::create(401, 'auth.invalid_credentials')
            ->withMessage('Current password is incorrect');
    }
    qs_login_throttle_clear($throttleKey);

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $written = qs_users_mutate(function (array &$cfg) use ($userId, $newHash) {
        if (!isset($cfg['users'][$userId])) {
            return false;
        }
        $cfg['users'][$userId]['password_hash'] = $newHash;
        return true;
    });
    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Could not persist the new password');
    }

    // Containment: bump the generation so every session of this user stops
    // being accepted, then re-stamp THIS one so the caller stays signed in.
    $generation = qs_user_bump_generation($userId);
    if ($generation === null) {
        // The password IS changed; only the containment step failed. Say so
        // rather than reporting a clean success the user would misread.
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Password changed, but your other sessions could not be ended — sign out everywhere to be sure');
    }
    qs_session_restamp($generation);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Password changed')
        ->withData(['other_sessions_ended' => true]);
}

/**
 * Permanently delete the authenticated caller's own account.
 *
 * There is no admin lane: QuickSite has no global tier — every authority is
 * per-project — so no principal is entitled to delete someone else's account.
 * Per-project eviction is the removeMember command; the operator lane is
 * users.php itself. This acts ONLY on the caller's own account.
 *
 * Requires the current password (a stolen access token must not be enough to
 * erase an account) on the same throttle as login, plus an explicit confirm.
 *
 * SOLE OWNERSHIP IS REFUSED. Deleting a project's only owner leaves it
 * unownable AND undeletable forever: transferOwnership requires the caller to
 * BE the in-lock owner, and project.delete / project.ownership are owner-only,
 * so no surviving member can ever satisfy the gate again. The caller must
 * transferOwnership or deleteProject first — each keeping its own confirm and
 * its own cascade rather than hiding N site deletions behind one call.
 *
 * Cascade order is safety-ordered: refuse on sole ownership -> clear the
 * members.json footprint -> delete the users.php record -> revoke every
 * session family. The authority record dies only once the footprint is clean,
 * and a cascade failure aborts BEFORE the account is touched.
 *
 * @param array $params current_password, confirm
 * @return ApiResponse
 */
function qs_account_delete(array $params): ApiResponse {
    $current = (string)($params['current_password'] ?? '');
    $confirm = filter_var($params['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($current === '') {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('current_password is required')
            ->withErrors(['current_password' => 'Required field']);
    }
    if (!$confirm) {
        return ApiResponse::create(400, 'validation.confirmation_required')
            ->withMessage('Account deletion must be confirmed')
            ->withErrors(['confirm' => 'Set confirm=true to proceed'])
            ->withData(['warning' => 'This permanently deletes your account, ends every session, and removes you from every project you belong to. It cannot be undone.']);
    }

    $auth = getCurrentAuth();
    if ($auth === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    $user   = $auth['user'];
    $userId = (string)$auth['userId'];

    // Externally-managed accounts (password_hash null) cannot re-authenticate;
    // their embedding platform owns their lifecycle. Same refusal as the
    // password change.
    $hash = $user['password_hash'] ?? null;
    if (!is_string($hash) || $hash === '') {
        return ApiResponse::create(400, 'auth.externally_managed')
            ->withMessage('This account has no local password (externally managed)');
    }

    // Same brute-force backoff as login / the password change, keyed the same way.
    $throttleKey = is_string($user['username'] ?? null) && $user['username'] !== '' ? $user['username'] : $userId;
    $wait = qs_login_throttle_check($throttleKey);
    if ($wait > 0) {
        return ApiResponse::create(429, 'auth.throttled')
            ->withMessage('Too many failed attempts — try again later')
            ->withData(['retry_after' => $wait]);
    }
    if (!password_verify($current, $hash)) {
        qs_login_throttle_fail($throttleKey);
        return ApiResponse::create(401, 'auth.invalid_credentials')
            ->withMessage('Current password is incorrect');
    }
    qs_login_throttle_clear($throttleKey);

    // ---------------------------------------------------------------- scan
    // One pass over every project: what do I own, and where am I listed?
    $owned   = [];
    $touch   = []; // project ids carrying a members/invitation entry keyed by me
    foreach (glob(SECURE_FOLDER_PATH . '/projects/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $projectId = basename($dir);
        $m = loadProjectMembers($projectId);
        if (($m['owner'] ?? null) === $userId) {
            $owned[] = [
                'project'      => $projectId,
                'name'         => qs_project_site_name($projectId),
                'member_count' => count($m['members'] ?? []),
            ];
            continue; // an owned project is a blocker, not a cascade target
        }
        if (isset($m['members'][$userId]) || isset($m['invitations'][$userId])) {
            $touch[] = $projectId;
        }
    }

    // Sole ownership blocks the deletion — see above for why orphaning is not
    // survivable. The caller owns these, so naming them leaks nothing.
    if (!empty($owned)) {
        return ApiResponse::create(409, 'account.sole_owner')
            ->withMessage('You still own ' . count($owned) . ' project(s). Transfer ownership or delete them first, then delete your account.')
            ->withData([
                'owned_projects' => $owned,
                'hint'           => 'Use transferOwnership to hand a project to another member, or deleteProject to destroy it.',
            ]);
    }

    // ------------------------------------------------------------- cascade
    // Remove every members.json entry KEYED BY my user id — my membership, an
    // invitation addressed to me, my own join request, and a proposal someone
    // filed about me. What is deliberately NOT touched: `by` / `sponsor`
    // references to me inside entries about OTHER people — deleting those
    // would destroy a third party's pending invitation. Those references
    // degrade to {user_id, name:null} via qs_public_user_ref, and the shipped
    // accept/approve-time re-validation voids anything that depended on my
    // standing.
    //
    // A cascade failure ABORTS before the account record is touched: a
    // half-deleted state (record gone, membership left behind) is worse than
    // a clean refusal the caller can retry.
    $memberships = 0;
    $invitations = 0;
    foreach ($touch as $projectId) {
        $removedMember = false;
        $removedInvite = false;
        $failure = null;
        $written = qs_members_mutate($projectId, function (array &$m) use ($userId, &$removedMember, &$removedInvite) {
            if (isset($m['members'][$userId])) {
                unset($m['members'][$userId]);
                $removedMember = true;
            }
            if (isset($m['invitations'][$userId])) {
                unset($m['invitations'][$userId]);
                $removedInvite = true;
            }
            return ($removedMember || $removedInvite) ? true : false;
        }, $failure);

        if ($written !== true) {
            if ($failure === null) {
                continue; // nothing to remove after all (raced) — not an error
            }
            error_log("account delete: cascade aborted on '{$projectId}' for '{$userId}' — {$failure}");
            return ApiResponse::create(500, 'members.integrity')
                ->withMessage('Could not detach your membership from a project — your account was NOT deleted. Nothing was changed.')
                ->withData(['project' => $projectId, 'reason' => $failure]);
        }
        if ($removedMember) { $memberships++; }
        if ($removedInvite) { $invitations++; }
    }

    // ------------------------------------------------------------- the record
    // Footprint is clean; drop the identity. The users.php `projects` status
    // mirror (including any tombstones) dies with the record.
    $deleted = qs_users_mutate(function (array &$cfg) use ($userId) {
        if (!isset($cfg['users'][$userId])) {
            return false;
        }
        unset($cfg['users'][$userId]);
        return true;
    });
    if ($deleted !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Could not delete the account record. Your project memberships were already removed — retry, or contact the operator.');
    }

    // Every session of this account is already dead: the record is gone, so no
    // session resolves to a user any more (the panel must not keep rendering
    // pages for a deleted account). This one is destroyed outright so the
    // browser is not left holding a cookie for a session that cannot work.
    qs_session_destroy();

    return ApiResponse::create(200, 'resource.deleted')
        ->withMessage('Your account has been permanently deleted')
        ->withData([
            'deleted'              => true,
            'memberships_removed'  => $memberships,
            'invitations_removed'  => $invitations,
            'sessions_ended'       => true,
        ]);
}

/**
 * Disk footprint of the projects the CALLER OWNS.
 *
 * Answers "how much space do my projects use", aggregated across every project
 * where the caller's role is `owner`, plus a per-project breakdown.
 *
 * The OUTPUT is what is filtered: ownership is resolved per project from the
 * authoritative members.json, so the response can only ever describe projects
 * the caller owns. A caller who owns nothing gets an empty, zeroed report —
 * never a hint that other projects exist. This is deliberately NOT an
 * installation-wide enumeration; no project the caller has no relationship with
 * is named, counted, or implied.
 *
 * Sizes come from a short-lived shared cache (see spaceUsage.php). The project
 * SET is never cached, so ownership changes land immediately; only byte counts
 * can age, and `refresh=true` forces a re-walk.
 *
 * @param array $params refresh (optional)
 * @return ApiResponse
 */
function qs_account_space_usage(array $params): ApiResponse {
    require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php';
    require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';   // qs_quota_config

    $params  = array_merge($_GET, $params);
    $refresh = filter_var($params['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // No resolvable caller → empty report, fail-closed. Mirrors listProjects.
    $user   = getCurrentUser();
    $userId = (string)($user['id'] ?? '');

    $owned = $userId !== '' ? qs_owned_projects($userId) : [];

    // A deleted project must not leave a measurement behind until its TTL lapses.
    qs_prune_space_cache();

    $projects   = [];
    $totContent = 0;
    $totBackups = 0;
    $totExports = 0;
    $totBuilds  = 0;
    $anyStale   = false;

    foreach ($owned as $project) {
        $space = qs_project_space($project, $refresh);

        $totContent += (int)$space['content'];
        $totBackups += (int)$space['backups']['size'];
        $totExports += (int)$space['exports']['size'];
        $totBuilds  += (int)$space['builds']['size'];
        if (!empty($space['cached'])) {
            $anyStale = true;
        }

        $projects[] = [
            'name'           => $project,
            'total'          => (int)$space['total'],
            'total_formatted'=> qs_format_size((int)$space['total']),
            'content'        => (int)$space['content'],
            'backups'        => [
                'size'           => (int)$space['backups']['size'],
                'size_formatted' => qs_format_size((int)$space['backups']['size']),
                'count'          => (int)$space['backups']['count'],
            ],
            'exports'        => [
                'size'           => (int)$space['exports']['size'],
                'size_formatted' => qs_format_size((int)$space['exports']['size']),
                'count'          => (int)$space['exports']['count'],
            ],
            'builds'         => [
                'size'           => (int)$space['builds']['size'],
                'size_formatted' => qs_format_size((int)$space['builds']['size']),
            ],
            'measured_at'    => (int)$space['measured_at'],
        ];
    }

    // Largest first — the point of the panel is "what is eating my space".
    usort($projects, fn($a, $b) => $b['total'] <=> $a['total']);

    $grand = $totContent + $totBackups + $totExports + $totBuilds;

    // Same ceiling qs_quota_check_storage enforces, read from the same config, so
    // the figure on the dashboard and the number in a refusal cannot disagree.
    $quotaLimit = (int) (qs_quota_config()['max_total_bytes'] ?? 0);
    $quotaFree  = $quotaLimit > 0 ? max(0, $quotaLimit - $grand) : 0;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Owner space usage retrieved successfully')
        ->withData([
            'total' => [
                'size'           => $grand,
                'size_formatted' => qs_format_size($grand),
            ],
            // Same shape as getSizeInfo's by_category so the dashboard's stacked
            // bar renders owner-scale without a second component.
            'by_category' => [
                'content' => ['size' => $totContent, 'size_formatted' => qs_format_size($totContent)],
                'backups' => ['size' => $totBackups, 'size_formatted' => qs_format_size($totBackups)],
                'builds'  => ['size' => $totBuilds,  'size_formatted' => qs_format_size($totBuilds)],
                'exports' => ['size' => $totExports, 'size_formatted' => qs_format_size($totExports)],
            ],
            // The ceiling this account is measured against, and what is left of
            // it. `configured` is false on an install with no quota.php — the
            // default — where there is no remaining figure to state at all, and
            // the dashboard hides the row rather than inventing one.
            'quota' => [
                'configured'      => $quotaLimit > 0,
                'limit'           => $quotaLimit,
                'limit_formatted' => $quotaLimit > 0 ? qs_format_size($quotaLimit) : null,
                'free'            => $quotaFree,
                'free_formatted'  => $quotaLimit > 0 ? qs_format_size($quotaFree) : null,
                'over'            => $quotaLimit > 0 && $grand > $quotaLimit,
            ],
            'projects'      => $projects,
            'project_count' => count($projects),
            'cache'         => [
                'ttl'      => QS_SPACE_CACHE_TTL,
                'from_cache' => $anyStale,
                'refreshed'  => $refresh,
            ],
        ]);
}

/**
 * The caller's effective role and the commands it grants.
 *
 * The panel filters its own chrome with this — every `data-requires-command`
 * element and every `canRun()` gate reads the list this returns.
 *
 * The caller is already resolved by the shared gate, so unlike the command this
 * replaces there is no second bearer-token validation here: the gate exits 401
 * before this runs, which is the same refusal one step earlier.
 *
 * @param array $user The authenticated caller (from qs_admin_json_boot)
 * @return ApiResponse
 */
function qs_account_permissions(array $user): ApiResponse {
    // Role + commands for the user's selected project.
    $permissions = getTokenPermissions($user);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Permissions retrieved successfully')
        ->withData([
            'token_name'    => $user['name'] ?? 'Unknown',
            'role'          => $permissions['role'],
            'commands'      => $permissions['commands'],
            'command_count' => count($permissions['commands']),
        ]);
}
