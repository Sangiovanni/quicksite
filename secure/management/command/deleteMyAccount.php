<?php
/**
 * deleteMyAccount Command (C8 8.2)
 *
 * Self-service, irreversible account deletion for the AUTHENTICATED caller.
 * There is no admin lane: QuickSite has no global tier — every authority is
 * per-project — so no principal is entitled to delete someone else's account.
 * Per-project eviction is removeMember; the operator lane is users.php itself
 * (GAP A). This command acts ONLY on the caller's own account.
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
 * @method POST
 * @route /management/deleteMyAccount
 * @auth required (account.self — any authenticated user; global, no marker)
 *
 * @param string $current_password The caller's current password (required)
 * @param bool   $confirm          Safety confirmation (required, must be true)
 *
 * @return ApiResponse Deletion result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_deleteMyAccount(array $params = [], array $urlParams = []): ApiResponse {
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
    // their embedding platform owns their lifecycle. Same refusal as changePassword.
    $hash = $user['password_hash'] ?? null;
    if (!is_string($hash) || $hash === '') {
        return ApiResponse::create(400, 'auth.externally_managed')
            ->withMessage('This account has no local password (externally managed)');
    }

    // Same brute-force backoff as login/changePassword, keyed the same way.
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

    // Sole ownership blocks the deletion — see the header for why orphaning is
    // not survivable. The caller owns these, so naming them leaks nothing.
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
            error_log("deleteMyAccount: cascade aborted on '{$projectId}' for '{$userId}' — {$failure}");
            return ApiResponse::create(500, 'members.integrity')
                ->withMessage('Could not detach your membership from a project — your account was NOT deleted. Nothing was changed.')
                ->withData(['project' => $projectId, 'reason' => $failure]);
        }
        if ($removedMember) { $memberships++; }
        if ($removedInvite) { $invitations++; }
    }

    // ------------------------------------------------------- the record
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

    // Every session dies, including this one and the admin panel's separate
    // family. Without this the panel keeps rendering ungated pages until the
    // access token expires (F-C8-8.2-1).
    $revoked = qs_session_revoke_user_families($userId, null, 'account_deleted');

    return ApiResponse::create(200, 'resource.deleted')
        ->withMessage('Your account has been permanently deleted')
        ->withData([
            'deleted'              => true,
            'memberships_removed'  => $memberships,
            'invitations_removed'  => $invitations,
            'sessions_revoked'     => $revoked,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteMyAccount($trimParams->params(), $trimParams->additionalParams())->send();
}
