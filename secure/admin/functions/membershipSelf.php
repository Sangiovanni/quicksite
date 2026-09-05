<?php
/**
 * Membership self-service — the caller's own project ACCESS.
 *
 * NOT commands. The command surface is a CLI for DEVELOPING A PROJECT; getting
 * into a project, or out of one, is not project development, so these live here
 * and are served by /admin/self (beta.11 S6).
 *
 * These were the eight `membership.self` commands: listMyInvitations,
 * acceptInvitation, declineInvitation, leaveProject, dismissProjectNotice,
 * requestToJoin, withdrawJoinRequest and listMyProposals.
 *
 * WHY THE MOVE CHANGES NOTHING ABOUT AUTHORIZATION. That category was
 * `scope: global`, `access: 'any'` — deliberately, because an invitee or a
 * petitioner is NOT a member, so the '/p/<id>/' marker gate would have refused
 * them before the command could run. hasPermission() therefore contributed
 * exactly one thing to these: "is the caller authenticated". qs_admin_json_boot()
 * establishes the same fact, with the same credential, before any of these run.
 *
 * Every real gate was always inside the function body and still is: `project`
 * is an F1-validated DATA parameter, each of these touches only entries keyed by
 * or authored by the caller, accept-time re-validation still refuses a grant
 * from dead authority, and the uniform-404 posture that keeps a nonexistent
 * project indistinguishable from "nothing here for you" is unchanged.
 *
 * Must be required AFTER init.php — it depends on SECURE_FOLDER_PATH.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
// Membership self-service is served from /admin/self and never touches the
// command dispatcher. The three calls below record the acts that CHANGE WHO CAN
// REACH A PROJECT; withdrawing a request, dismissing a notice and asking to join
// are queue management and grant nothing, so they are deliberately not recorded.
require_once SECURE_FOLDER_PATH . '/src/functions/securityLog.php';

/**
 * Shared preamble: the caller's id, plus an F1-validated `project` parameter.
 *
 * Six of the eight take exactly one required parameter and validate it exactly
 * the same way, so the check lives in one place rather than six.
 *
 * @param array $params Request body
 * @param string|null $project Set to the validated project id on success
 * @param string|null $userId Set to the caller's user id on success
 * @return ApiResponse|null The refusal to send, or null when both are resolved
 */
function qs_membership_resolve(array $params, ?string &$project, ?string &$userId): ?ApiResponse {
    $project = trim((string)($params['project'] ?? ''));
    if ($project === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('project is required')
            ->withErrors(['project' => 'Required field']);
    }
    // F1 — the value becomes a directory selector downstream.
    if (!is_valid_project_name($project)) {
        return ApiResponse::create(400, 'project.invalid')
            ->withMessage('Invalid project identifier');
    }

    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    return null;
}

/**
 * The caller's membership INBOX: pending invitations, their own join requests,
 * and terminal project notices (refused / removed / deleted).
 *
 * Reads the caller's OWN users.php cache (cheap — no all-projects scan), then
 * hydrates each pending entry from that project's AUTHORITATIVE members.json: a
 * stale mirror can never misrepresent an offer, and drift self-corrects TOWARD
 * AUTHORITY — a dangling pending entry is pruned; a pending mirror whose
 * authority already shows MEMBERSHIP is upgraded to a member entry (restores
 * picker visibility when an approve/accept mirror write was lost); a mirror
 * whose pending KIND disagrees with the authority's direction is healed to the
 * authority's side. Sponsored proposals (`by` != me) are never mine to see: a
 * drifted mirror pointing at one is pruned, and the row is not listed.
 *
 * @return ApiResponse invitations[] + requests[] + notices[]
 */
function qs_membership_list_invitations(): ApiResponse {
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $usersCfg = loadUsersConfig();
    $invitations = [];
    $requests = [];
    $notices = [];
    $prune = [];
    $heal = []; // projectId => replacement mirror entry (drift → authority)
    $today = date('Y-m-d');

    foreach (($user['projects'] ?? []) as $projectId => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $projectId = (string)$projectId;
        $status = $entry['status'] ?? 'member';

        if ($status === 'pending_invite' || $status === 'pending_request') {
            // Defensive F1 guard before the path read (cache keys are
            // server-written, but this file is hand-editable).
            if ($projectId === '' || strpbrk($projectId, "/\\") !== false || strpos($projectId, '..') !== false) {
                continue;
            }
            $path = SECURE_FOLDER_PATH . '/projects/' . $projectId . '/config/members.json';
            if (!is_file($path)) {
                $prune[] = $projectId; // project gone → the ask died with it
                continue;
            }
            $authority = json_decode((string)@file_get_contents($path), true);
            if (!is_array($authority)) {
                continue; // unreadable file: leave the mirror alone, list nothing
            }

            // Drift heals toward AUTHORITY. Already a member there (approve/
            // accept landed but the mirror write was lost) → upgrade the
            // entry; a membership is not an inbox item.
            if (isset($authority['members'][$userId])) {
                $heal[$projectId] = [
                    'name'    => qs_project_site_name($projectId),
                    'created' => $today,
                    'status'  => 'member',
                ];
                continue;
            }

            $inv = $authority['invitations'][$userId] ?? null;
            $direction = is_array($inv) ? ($inv['direction'] ?? 'invite') : null;

            if ($direction === 'invite') {
                if ($status !== 'pending_invite') {
                    // mirror said request, authority says invite → heal
                    $heal[$projectId] = [
                        'name'   => qs_project_site_name($projectId),
                        'status' => 'pending_invite',
                        'at'     => $inv['at'] ?? $today,
                    ];
                }
                $row = [
                    'project'      => $projectId,
                    'project_name' => ($heal[$projectId]['name'] ?? null) ?? ($entry['name'] ?? $projectId),
                    'role'         => $inv['role'] ?? null,
                    'invited_by'   => isset($inv['by']) ? qs_public_user_ref((string)$inv['by'], $usersCfg) : null,
                    'at'           => $inv['at'] ?? ($entry['at'] ?? null),
                ];
                if (isset($inv['sponsor']) && is_string($inv['sponsor'])) {
                    $row['sponsored_by'] = qs_public_user_ref($inv['sponsor'], $usersCfg);
                }
                if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                    $row['note'] = $inv['note'];
                }
                $invitations[] = $row;
            } elseif ($direction === 'request' && (($inv['by'] ?? null) === $userId)) {
                // my own join request (a sponsored proposal — by != me — is
                // never mine to see: falls to the prune below)
                if ($status !== 'pending_request') {
                    // mirror said invite, authority says self-request → heal
                    // (name privacy: SITE_NAME only on a public project)
                    $heal[$projectId] = [
                        'name'   => (($authority['visibility'] ?? 'private') === 'public')
                            ? qs_project_site_name($projectId) : $projectId,
                        'status' => 'pending_request',
                        'at'     => $inv['at'] ?? $today,
                    ];
                }
                $row = [
                    'project'      => $projectId,
                    'project_name' => ($heal[$projectId]['name'] ?? null) ?? ($entry['name'] ?? $projectId),
                    'role'         => $inv['role'] ?? null,
                    'at'           => $inv['at'] ?? ($entry['at'] ?? null),
                ];
                if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                    $row['note'] = $inv['note'];
                }
                $requests[] = $row;
            } else {
                $prune[] = $projectId; // withdrawn/denied/not mine → self-heal
            }
        } elseif (in_array($status, ['refused', 'removed', 'deleted'], true)) {
            $row = [
                'project'      => $projectId,
                'project_name' => $entry['name'] ?? $projectId,
                'status'       => $status,
                'at'           => $entry['at'] ?? null,
            ];
            if (isset($entry['note']) && is_string($entry['note']) && $entry['note'] !== '') {
                $row['note'] = $entry['note'];
            }
            $notices[] = $row;
        }
        // 'member' entries are not inbox items.
    }

    // Self-heal: prunes + drift-heals in ONE cache write. Secondary write —
    // silent on failure (ruled), the next listing simply retries.
    if ($prune !== [] || $heal !== []) {
        $healed = qs_users_mutate(function (array &$cfg) use ($userId, $prune, $heal) {
            if (!isset($cfg['users'][$userId])) {
                return false;
            }
            foreach ($prune as $projectId) {
                unset($cfg['users'][$userId]['projects'][$projectId]);
            }
            foreach ($heal as $projectId => $entry) {
                $cfg['users'][$userId]['projects'][$projectId] = $entry;
            }
            return true;
        });
        if ($healed !== true) {
            error_log("membership inbox: self-heal write failed for '{$userId}'");
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitations listed successfully')
        ->withData([
            'invitations'      => $invitations,
            'requests'         => $requests,
            'notices'          => $notices,
            'invitation_count' => count($invitations),
            'request_count'    => count($requests),
            'notice_count'     => count($notices),
        ]);
}

/**
 * Consent step: turn the caller's OWN pending invitation into membership.
 *
 * The grant materializes ONLY here — and only after ACCEPT-TIME RE-VALIDATION:
 * the inviter must STILL be a member whose rank outranks the offered role. A
 * demoted/removed inviter's invitation is VOID — it is pruned and refused
 * (grants never materialize on dead authority).
 *
 * Enumeration posture: a nonexistent project and an existing project with no
 * invitation for the caller return the SAME 404 — this is no project-existence
 * oracle.
 *
 * @param array $params project
 * @return ApiResponse
 */
function qs_membership_accept(array $params): ApiResponse {
    $project = null; $userId = null;
    if ($refusal = qs_membership_resolve($params, $project, $userId)) {
        return $refusal;
    }

    $error = null;
    $failure = null;
    $joinedRole = null;
    $written = qs_members_mutate($project, function (array &$m) use ($userId, &$error, &$joinedRole) {
        // Defensive: already a member (unreachable while the invariant holds —
        // invitations ∩ members = ∅). Prune any stale invitation and succeed.
        if (isset($m['members'][$userId])) {
            $joinedRole = $m['members'][$userId]['role'] ?? null;
            $error = 'already_member';
            if (isset($m['invitations'][$userId])) {
                unset($m['invitations'][$userId]);
                return true; // write the prune
            }
            return false; // nothing to write
        }

        $inv = $m['invitations'][$userId] ?? null;
        if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'invite')) {
            $error = 'invitation.not_found';
            return false;
        }

        // ACCEPT-TIME RE-VALIDATION: authority is re-checked at
        // materialization, in-lock, against the CURRENT members block.
        $offeredRole = $inv['role'] ?? null;
        $inviter     = $inv['by'] ?? null;
        $inviterRole = is_string($inviter) ? ($m['members'][$inviter]['role'] ?? null) : null;
        if (!is_string($offeredRole) || $inviterRole === null || !canManageRole($inviterRole, $offeredRole)) {
            unset($m['invitations'][$userId]); // dead authority → the offer is void
            $error = 'invitation.void';
            return true; // write the prune
        }

        unset($m['invitations'][$userId]);
        $m['members'][$userId] = ['role' => $offeredRole];
        $joinedRole = $offeredRole;
        return true;
    }, $failure);

    // Uniform not-found: a nonexistent project ('missing'/'invalid_project')
    // answers exactly like "no invitation here" — no existence oracle.
    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'invitation.not_found') {
        return ApiResponse::create(404, 'invitation.not_found')
            ->withMessage('No pending invitation for you on this project');
    }
    if ($error === 'invitation.void') {
        // Mirror prune is best-effort; the authority prune already committed.
        if (!qs_membership_cache_set($userId, $project, null)) {
            error_log("membership accept: void-prune mirror removal failed for '{$userId}' on '{$project}'");
        }
        return ApiResponse::create(409, 'invitation.void')
            ->withMessage('This invitation is no longer valid (the inviter no longer holds the authority that offered it)');
    }
    if ($error === 'already_member') {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('You are already a member of this project')
            ->withData([
                'project'        => $project,
                'role'           => $joinedRole,
                'joined'         => true,
                'already_member' => true,
            ]);
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Status mirror: the caller is now a real member.
    $mirror = [
        'name'    => qs_project_site_name($project),
        'created' => date('Y-m-d'),
        'status'  => 'member',
    ];
    if (!qs_membership_cache_set($userId, $project, $mirror)) {
        error_log("membership accept: cache mirror write failed for '{$userId}' on '{$project}'");
    }

    qs_security_log(QS_SEC_MEMBERSHIP, ['action' => 'accepted_invitation', 'project' => $project], $userId);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitation accepted — welcome aboard')
        ->withData([
            'project' => $project,
            'role'    => $joinedRole,
            'joined'  => true,
        ]);
}

/**
 * The consent model's "no": removes the caller's OWN pending invitation.
 *
 * Self-initiated → plain removal everywhere, NO tombstone; the inviter simply
 * sees the invitation gone from listMembers. Same uniform 404 as accept — a
 * nonexistent project is indistinguishable from "no invitation for you".
 *
 * @param array $params project
 * @return ApiResponse
 */
function qs_membership_decline(array $params): ApiResponse {
    $project = null; $userId = null;
    if ($refusal = qs_membership_resolve($params, $project, $userId)) {
        return $refusal;
    }

    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($userId, &$error) {
        $inv = $m['invitations'][$userId] ?? null;
        if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'invite')) {
            $error = 'invitation.not_found';
            return false;
        }
        unset($m['invitations'][$userId]);
        return true;
    }, $failure);

    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'invitation.not_found') {
        return ApiResponse::create(404, 'invitation.not_found')
            ->withMessage('No pending invitation for you on this project');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Mirror: plain removal — declining is the caller's own decision.
    if (!qs_membership_cache_set($userId, $project, null)) {
        error_log("membership decline: cache mirror removal failed for '{$userId}' on '{$project}'");
    }

    qs_security_log(QS_SEC_MEMBERSHIP, ['action' => 'declined_invitation', 'project' => $project], $userId);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitation declined')
        ->withData([
            'project'  => $project,
            'declined' => true,
        ]);
}

/**
 * Self-service exit: removes the CALLER's own membership.
 *
 * Self-initiated → plain removal everywhere, NO tombstone. The owner cannot
 * leave — a project must never go ownerless (last-owner invariant):
 * transferOwnership first, or deleteProject. A uniform 404 keeps nonexistent
 * projects indistinguishable from "not a member".
 *
 * @param array $params project
 * @return ApiResponse
 */
function qs_membership_leave(array $params): ApiResponse {
    $project = null; $userId = null;
    if ($refusal = qs_membership_resolve($params, $project, $userId)) {
        return $refusal;
    }

    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($userId, &$error) {
        $entry = $m['members'][$userId] ?? null;
        if (!is_array($entry)) {
            $error = 'member.not_found';
            return false;
        }
        if (($entry['role'] ?? null) === 'owner') {
            $error = 'member.owner_immutable';
            return false;
        }
        unset($m['members'][$userId]);
        return true;
    }, $failure);

    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'member.not_found') {
        return ApiResponse::create(404, 'member.not_found')
            ->withMessage('You are not a member of this project');
    }
    if ($error === 'member.owner_immutable') {
        return ApiResponse::create(400, 'member.owner_immutable')
            ->withMessage('The owner cannot leave the project — transfer ownership first (or delete the project)');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Mirror: plain removal — leaving is the caller's own decision.
    if (!qs_membership_cache_set($userId, $project, null)) {
        error_log("membership leave: cache mirror removal failed for '{$userId}' on '{$project}'");
    }

    qs_security_log(QS_SEC_MEMBERSHIP, ['action' => 'left_project', 'project' => $project], $userId);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('You left the project')
        ->withData([
            'project' => $project,
            'left'    => true,
        ]);
}

/**
 * Clear ONE terminal notice (refused | removed | deleted) from the CALLER's own
 * users.php cache — the "OK, seen it" for the notices the inbox surfaces.
 *
 * LIVE states are never dismissable: a membership is ended by leaving the
 * project, a pending invitation by declining it.
 *
 * Pure cache operation: no members.json involved (for a 'deleted' notice the
 * project — and its members.json — no longer exists; that is the point). The
 * project id is shape-checked only (F1), never existence-checked.
 *
 * @param array $params project
 * @return ApiResponse
 */
function qs_membership_dismiss_notice(array $params): ApiResponse {
    $project = null; $userId = null;
    if ($refusal = qs_membership_resolve($params, $project, $userId)) {
        return $refusal;
    }

    $error = null;
    $status = null;
    $written = qs_users_mutate(function (array &$cfg) use ($userId, $project, &$error, &$status) {
        $entry = $cfg['users'][$userId]['projects'][$project] ?? null;
        if (!is_array($entry)) {
            $error = 'notice.not_found';
            return false;
        }
        $status = $entry['status'] ?? 'member';
        if (!in_array($status, ['refused', 'removed', 'deleted'], true)) {
            $error = 'notice.not_dismissable';
            return false;
        }
        unset($cfg['users'][$userId]['projects'][$project]);
        return true;
    });

    if ($error === 'notice.not_found') {
        return ApiResponse::create(404, 'notice.not_found')
            ->withMessage('No notice for this project');
    }
    if ($error === 'notice.not_dismissable') {
        return ApiResponse::create(400, 'notice.not_dismissable')
            ->withMessage('Only terminal notices can be dismissed — end a membership by leaving the project, and a pending invitation by declining it')
            ->withData(['status' => $status]);
    }
    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to persist the dismissal');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Notice dismissed')
        ->withData([
            'project'   => $project,
            'dismissed' => true,
        ]);
}

/**
 * The self-service "knock": an authenticated non-member asks to join a project.
 *
 * The ask lands in the project's members.json `invitations` block as
 * `direction:'request'` with `by` = the caller (self-authored) — structurally
 * unable to grant anything until an admin/owner approves it
 * (approveJoinRequest). The note is MANDATORY: a request always carries its
 * reason. The requested role is FIXED at 'viewer' (rank-1 floor): petitioners
 * don't set terms — the note says what they want, approval materializes viewer,
 * and changeMemberRole handles upgrades.
 *
 * Enumeration posture (visibility × join_policy):
 *   private+closed  → 404 join.unavailable, IDENTICAL to a nonexistent project
 *                     (no existence oracle).
 *   private+open    → 201; the owner OPTED into knockability-by-id. The
 *                     caller's own cache mirror carries the PROJECT ID as its
 *                     display name, never SITE_NAME — a knock may confirm
 *                     existence, not the site's name.
 *   public+closed   → honest 403 join.requests_closed (existence is already
 *                     public via /p/<id>/ serving; a uniform 404 would be
 *                     theater).
 *   public+open     → 201; mirror carries SITE_NAME (public anyway).
 * Self-knowledge is never an oracle: already-member → 409; my own pending
 * invite/request → 409. A SPONSORED proposal about the caller is NOT
 * self-knowledge (they were never engaged): it is treated as absent — uniform
 * 404 where the lane is closed, and the caller's own explicit ask OVERWRITES it
 * where the lane is open (their consent supersedes the sponsor's vouch).
 *
 * Re-request gate: while a `refused` or `removed` tombstone for this project
 * stands in the caller's own cache, a new request is refused (409
 * request.notice_pending) until it is dismissed — an acknowledgment gate,
 * reading only the caller's own data.
 *
 * @param array $params project, note
 * @return ApiResponse
 */
function qs_membership_request_join(array $params): ApiResponse {
    $project = trim((string)($params['project'] ?? ''));
    // Refuse an unstorable note HERE, naming the field, rather than letting it
    // reach the roster writer (which refuses it too, as a backstop).
    if (qs_note_encoding_invalid($params['note'] ?? null)) {
        return ApiResponse::create(400, 'validation.unencodable')
            ->withMessage('The note is not valid UTF-8 text')
            ->withErrors(['note' => 'Must be valid UTF-8 text']);
    }
    $note = qs_clean_note($params['note'] ?? null);

    if ($project === '' || $note === null) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('project and note are required — a join request always carries its reason')
            ->withErrors([
                'project' => $project === '' ? 'Required field' : null,
                'note'    => $note === null ? 'Required field' : null,
            ]);
    }
    // F1 — the value becomes a directory selector downstream.
    if (!is_valid_project_name($project)) {
        return ApiResponse::create(400, 'project.invalid')
            ->withMessage('Invalid project identifier');
    }

    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    // Acknowledgment gate (own cache only — no oracle): a standing refused/
    // removed tombstone for this project must be dismissed before re-asking.
    $ownEntry = $user['projects'][$project] ?? null;
    $ownStatus = is_array($ownEntry) ? ($ownEntry['status'] ?? 'member') : null;
    if ($ownStatus === 'refused' || $ownStatus === 'removed') {
        return ApiResponse::create(409, 'request.notice_pending')
            ->withMessage('A notice for this project is still in your inbox — dismiss it on your memberships page before requesting again');
    }

    $today = date('Y-m-d');
    $error = null;
    $failure = null;
    $visibility = 'private';
    $written = qs_members_mutate($project, function (array &$m) use ($userId, $note, $today, &$error, &$visibility) {
        $visibility = $m['visibility'] ?? 'private';

        // Self-knowledge branches (each reveals only what the caller's own
        // membership/inbox already shows).
        if (isset($m['members'][$userId])) {
            $error = 'member.already_exists';
            return false;
        }
        $inv = $m['invitations'][$userId] ?? null;
        if (is_array($inv)) {
            $direction = $inv['direction'] ?? 'invite';
            if ($direction === 'invite') {
                $error = 'invitation.already_pending';
                return false;
            }
            if (($inv['by'] ?? null) === $userId) {
                $error = 'request.already_pending';
                return false;
            }
            // Sponsored proposal about the caller: NOT self-knowledge — fall
            // through to the lane gate as if absent; an explicit self-ask
            // overwrites it where the lane is open.
        }

        // The lane gate (in-lock — a concurrent setJoinPolicy cannot be outrun).
        $policy = $m['join_policy'] ?? 'closed';
        if ($policy !== 'open') {
            $error = ($visibility === 'public') ? 'requests_closed' : 'unavailable';
            return false;
        }

        if (!isset($m['invitations']) || !is_array($m['invitations'])) {
            $m['invitations'] = [];
        }
        $m['invitations'][$userId] = [
            'role'      => 'viewer',
            'direction' => 'request',
            'by'        => $userId,
            'at'        => $today,
            'note'      => $note,
        ];
        return true;
    }, $failure);

    // Uniform not-found: a nonexistent project answers EXACTLY like a private
    // project whose request lane is closed — no existence oracle.
    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'unavailable') {
        return ApiResponse::create(404, 'join.unavailable')
            ->withMessage('This project does not accept join requests');
    }
    if ($error === 'requests_closed') {
        return ApiResponse::create(403, 'join.requests_closed')
            ->withMessage('This project does not currently accept join requests');
    }
    if ($error === 'member.already_exists') {
        return ApiResponse::create(409, 'member.already_exists')
            ->withMessage('You are already a member of this project');
    }
    if ($error === 'invitation.already_pending') {
        return ApiResponse::create(409, 'invitation.already_pending')
            ->withMessage('You already have a pending invitation for this project — accept or decline it instead');
    }
    if ($error === 'request.already_pending') {
        return ApiResponse::create(409, 'request.already_pending')
            ->withMessage('You already have a pending join request for this project — withdraw it to re-ask');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Status mirror (secondary write, silent-failure by ruling). Name privacy:
    // SITE_NAME only when the project is public; a private project's mirror
    // carries the id the caller already typed.
    $mirror = [
        'name'   => ($visibility === 'public') ? qs_project_site_name($project) : $project,
        'status' => 'pending_request',
        'at'     => $today,
    ];
    if (!qs_membership_cache_set($userId, $project, $mirror)) {
        error_log("membership request: cache mirror write failed for '{$userId}' on '{$project}'");
    }

    return ApiResponse::create(201, 'resource.created')
        ->withMessage('Join request sent — a project admin or the owner will answer it')
        ->withData([
            'project'   => $project,
            'role'      => 'viewer',
            'requested' => true,
            'at'        => $today,
        ]);
}

/**
 * Withdraw a `direction:'request'` entry the CALLER AUTHORED (`by` = caller).
 *
 * Without user_id it targets the caller's own join request; with user_id it
 * targets a proposal the caller sponsored (proposeMember). Self-initiated →
 * plain removal everywhere, NO tombstone.
 *
 * Privacy: the `by === caller` rule doubles as the containment gate — a user
 * probing for a sponsored proposal ABOUT them (by != them, never engaged) gets
 * the same uniform 404 as "nothing there", and a nonexistent project is
 * indistinguishable from "no request of yours".
 *
 * @param array $params project, user_id (optional)
 * @return ApiResponse
 */
function qs_membership_withdraw_request(array $params): ApiResponse {
    $project = null; $userId = null;
    if ($refusal = qs_membership_resolve($params, $project, $userId)) {
        return $refusal;
    }

    $targetId = trim((string)($params['user_id'] ?? ''));
    if ($targetId === '') {
        $targetId = $userId; // my own join request
    }

    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($userId, $targetId, &$error) {
        $inv = $m['invitations'][$targetId] ?? null;
        if (!is_array($inv)
            || (($inv['direction'] ?? 'invite') !== 'request')
            || (($inv['by'] ?? null) !== $userId)) {
            $error = 'request.not_found';
            return false;
        }
        unset($m['invitations'][$targetId]);
        return true;
    }, $failure);

    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'request.not_found') {
        return ApiResponse::create(404, 'request.not_found')
            ->withMessage('No join request of yours on this project');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Mirror: my own request had a pending_request entry in MY cache — plain
    // removal (self-initiated). A sponsored proposal never engaged its target:
    // no cache entry exists anywhere, nothing to touch.
    if ($targetId === $userId) {
        if (!qs_membership_cache_set($userId, $project, null)) {
            error_log("membership withdraw: cache mirror removal failed for '{$userId}' on '{$project}'");
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Join request withdrawn')
        ->withData([
            'project'   => $project,
            'user_id'   => $targetId,
            'withdrawn' => true,
        ]);
}

/**
 * The sponsor's view of their OWN outgoing proposals (proposeMember).
 *
 * For each project the caller is a MEMBER of: the pending direction:'request'
 * entries the caller authored about someone else ('pending_validation'), plus
 * the proposals an admin/owner already approved into a real invitation that
 * still awaits the person's answer ('awaiting_answer' — sponsor kept as
 * attribution). A proposal that is neither listed as pending nor as awaiting was
 * adjudicated (denied, or answered by the person) — refusal reasons are NOT
 * delivered to sponsors.
 *
 * Read-only: scans ONLY projects where the caller's membership is REAL
 * (getUserProjectIds — authority-checked), reading each project's members.json
 * directly. No cache writes (proposals are mirrored nowhere by design).
 *
 * PRIVACY: proposed users are {user_id, name} public references — the PRIVATE
 * username never appears. Project names are SITE_NAMEs: the caller is a member
 * of every project listed, so the display name is theirs to see.
 *
 * @return ApiResponse proposals[] + proposal_count
 */
function qs_membership_list_proposals(): ApiResponse {
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $usersCfg = loadUsersConfig();
    $proposals = [];

    foreach (getUserProjectIds($user) as $projectId) {
        $authority = loadProjectMembers($projectId);
        foreach (($authority['invitations'] ?? []) as $targetId => $inv) {
            if (!is_array($inv)) {
                continue;
            }
            $targetId = (string)$targetId;
            if ($targetId === $userId) {
                continue; // my own ask is the inbox's business, never a proposal
            }
            $direction = $inv['direction'] ?? 'invite';

            if ($direction === 'request' && (($inv['by'] ?? null) === $userId)) {
                $status = 'pending_validation'; // awaiting approve/denyJoinRequest
            } elseif ($direction === 'invite' && (($inv['sponsor'] ?? null) === $userId)) {
                $status = 'awaiting_answer'; // approved → invitation, person hasn't answered
            } else {
                continue;
            }

            $row = [
                'project'      => $projectId,
                'project_name' => qs_project_site_name($projectId),
                'user'         => qs_public_user_ref($targetId, $usersCfg),
                'role'         => $inv['role'] ?? null,
                'status'       => $status,
                'at'           => $inv['at'] ?? null,
            ];
            if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                $row['note'] = $inv['note'];
            }
            $proposals[] = $row;
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Proposals listed successfully')
        ->withData([
            'proposals'      => $proposals,
            'proposal_count' => count($proposals),
        ]);
}
