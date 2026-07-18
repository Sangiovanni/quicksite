<?php
/**
 * approveJoinRequest Command (C8 8.3b)
 *
 * Authority's "yes" on a `direction:'request'` entry — with the CONSENT
 * LEDGER rule: membership materializes exactly when BOTH consents exist.
 *   - SELF-REQUEST (by == target): the requester consented by asking, the
 *     approver consents now → membership materializes immediately.
 *   - SPONSORED PROPOSAL (by == a sponsor): the approver consents now but the
 *     TARGET never asked → the entry CONVERTS into a normal invitation
 *     (`direction:'invite'`, `by` = the APPROVER whose rank now backs it,
 *     `sponsor` kept for attribution, note kept as context) and the target is
 *     engaged for the first time (pending_invite mirror). They answer through
 *     the shipped acceptInvitation lane, whose accept-time re-validation
 *     re-checks the APPROVER's rank — the authority chain stays sound with
 *     zero new accept logic.
 *
 * Approve-time re-validation (in-lock, fresh state): the approver's CURRENT
 * rank must outrank the role being GRANTED (canManageRole — a concurrent
 * demotion cannot be outrun); the target account must still resolve in
 * users.php and a sponsored entry's sponsor must STILL be a member (any rank —
 * rank never powered a proposal; a removed/left sponsor voids it, a demoted
 * one does not). Dead entries are pruned + refused (409 request.void) — grants
 * never materialize on dead parties.
 *
 * Optional role at approve (C8 8.3c — supersedes the 8.3b "no role override"
 * rule): the approver MAY name the `role` to grant, defaulting to the stored
 * role (viewer for a self-request, the sponsor's suggestion for a proposal).
 * The rank check runs against whatever role is granted, so an approver can
 * never mint a role at/above their own — the same authority `changeMemberRole`
 * already carries, folded into one atomic step. A self-request materializes
 * directly at that role; a proposal converts to an invitation for it.
 *
 * @method POST
 * @route /management/p/<projectId>/approveJoinRequest
 * @auth required (project.members — admin, owner)
 *
 * @param string $user_id Requester/proposed account's user id (required)
 * @param string $role    Role to grant (optional; defaults to the stored role)
 *
 * @return ApiResponse Approval result
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
function __command_approveJoinRequest(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/approveJoinRequest');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $targetId = trim((string)($params['user_id'] ?? ''));
    if ($targetId === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id is required')
            ->withErrors(['user_id' => 'Required field']);
    }

    // Optional role override (C8 8.3c). Validated up front like inviteMember /
    // changeMemberRole; null = keep the stored role. The in-lock rank check
    // (below) is what actually gates it against the approver's current rank.
    $roleOverride = null;
    $requestedRole = trim((string)($params['role'] ?? ''));
    if ($requestedRole !== '') {
        if ($requestedRole === 'owner') {
            return ApiResponse::create(400, 'member.role_not_assignable')
                ->withMessage('The owner role cannot be granted here — use transferOwnership');
        }
        if (!isValidRole($requestedRole)) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage('Unknown role')
                ->withErrors(['role' => 'Unknown role']);
        }
        $roleOverride = $requestedRole;
    }

    // Cross-file existence read (the inviteMember pattern): resolved before
    // the lock, consumed inside it — a vanished account voids the entry.
    $usersCfg = loadUsersConfig();
    $targetExists = isset($usersCfg['users'][$targetId]);

    $today = date('Y-m-d');
    $error = null;
    $failure = null;
    $grantedRole = null;
    $converted = false;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, $targetExists, $today, $roleOverride, &$error, &$grantedRole, &$converted) {
        $inv = $m['invitations'][$targetId] ?? null;
        if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'request')) {
            $error = 'request.not_found';
            return false;
        }

        // APPROVE-TIME RE-VALIDATION — all against the fresh in-lock state.
        // The role GRANTED = the approver's override, else the stored role
        // (viewer for a self-request, the sponsor's suggestion for a proposal).
        // The rank check is against that role, so an approver can never grant
        // at/above their own rank.
        $storedRole = (string)($inv['role'] ?? '');
        $grantRole  = $roleOverride ?? $storedRole;
        $actorRole  = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole === null || !canManageRole($actorRole, $grantRole)) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        $sponsor = $inv['by'] ?? null;
        $isSelf  = ($sponsor === $targetId);
        if (!$targetExists || (!$isSelf && (!is_string($sponsor) || !isset($m['members'][$sponsor])))) {
            // Requester's account gone, or the sponsor left the roster: the
            // ask is void — prune it, grant nothing.
            unset($m['invitations'][$targetId]);
            $error = 'request.void';
            return true; // write the prune
        }

        if ($isSelf) {
            // Both consents present → membership materializes at the granted role.
            unset($m['invitations'][$targetId]);
            $m['members'][$targetId] = ['role' => $grantRole];
            $grantedRole = $grantRole;
            return true;
        }

        // Sponsored: authority consents, target's consent still missing →
        // convert to a normal invitation carried by the APPROVER's rank.
        $entry = [
            'role'      => $grantRole,
            'direction' => 'invite',
            'by'        => $actorId,
            'sponsor'   => $sponsor,
            'at'        => $today,
        ];
        if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
            $entry['note'] = $inv['note'];
        }
        $m['invitations'][$targetId] = $entry;
        $grantedRole = $grantRole;
        $converted = true;
        return true;
    }, $failure);

    if ($error === 'request.not_found') {
        return ApiResponse::create(404, 'request.not_found')
            ->withMessage('No pending join request or proposal for this user');
    }
    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You can only approve requests for roles of strictly lower rank than your own');
    }
    if ($error === 'request.void') {
        return ApiResponse::create(409, 'request.void')
            ->withMessage('This request is no longer valid (the account or its sponsor is gone); it has been removed');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Status mirror (secondary write, silent-failure by ruling). A member —
    // or an authority-engaged invitee — is entitled to the site's name.
    if ($converted) {
        $mirror = ['name' => qs_project_site_name($project), 'status' => 'pending_invite', 'at' => $today];
    } else {
        $mirror = ['name' => qs_project_site_name($project), 'created' => $today, 'status' => 'member'];
    }
    if (!qs_membership_cache_set($targetId, $project, $mirror)) {
        error_log("approveJoinRequest: cache mirror write failed for '{$targetId}' on '{$project}'");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($converted
            ? 'Proposal validated — the person now has a real invitation to answer'
            : 'Request approved — the requester is now a member')
        ->withData(array_merge([
            'project'  => $project,
            'user_id'  => $targetId,
            'name'     => qs_public_user_ref($targetId, $usersCfg)['name'],
            'role'     => $grantedRole,
            'approved' => true,
            'joined'   => !$converted,
        ], $converted ? ['converted_to_invitation' => true] : []));
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_approveJoinRequest($trimParams->params(), $trimParams->additionalParams())->send();
}
