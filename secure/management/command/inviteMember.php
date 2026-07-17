<?php
/**
 * inviteMember Command (C8 8.3a)
 *
 * Offers project membership to an existing account — CONSENT model: the
 * invitation goes into the project's members.json `invitations` block (a
 * pending entry is STRUCTURALLY unable to grant access — every access check
 * reads `members` only) and materializes only when the invitee accepts
 * (acceptInvitation), where the inviter's authority is re-validated.
 *
 * Targeting is by user_id ONLY (the unique PUBLIC identifier). The public
 * display name is looked up with findUser; the PRIVATE username is never a
 * membership target (a username-targeted invite would be an existence oracle
 * on the login identifier).
 *
 * Rank rule (model A delegation, L9): the actor may offer only roles of
 * STRICTLY LOWER rank than their own (canManageRole), checked in-lock against
 * the actor's CURRENT role. 'owner' is never assignable — transferOwnership.
 *
 * @method POST
 * @route /management/p/<projectId>/inviteMember
 * @auth required (project.members — admin, owner)
 *
 * @param string $user_id Target account's user id (required)
 * @param string $role    Offered role (required; any role below owner the actor outranks)
 * @param string $note    Optional message to the invitee (control-stripped, 500 chars)
 *
 * @return ApiResponse Invitation summary
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
function __command_inviteMember(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: the target project is EXCLUSIVELY the authorized URL
    // marker. Body project/name keys are ignored by design.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/inviteMember');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $targetId = trim((string)($params['user_id'] ?? ''));
    $role     = trim((string)($params['role'] ?? ''));
    $note     = qs_clean_note($params['note'] ?? null);

    if ($targetId === '' || $role === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id and role are required')
            ->withErrors([
                'user_id' => $targetId === '' ? 'Required field' : null,
                'role'    => $role === '' ? 'Required field' : null,
            ]);
    }
    if ($role === 'owner') {
        return ApiResponse::create(400, 'member.role_not_assignable')
            ->withMessage("The 'owner' role cannot be offered — use transferOwnership on an existing member");
    }
    if (!isValidRole($role)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Unknown role')
            ->withErrors(['role' => 'Must be one of the built-in roles']);
    }

    // Target must be an existing account. user_id is a 128-bit opaque id —
    // an honest 404 here is not a practical existence oracle (C8 8.3a R1).
    $usersCfg = loadUsersConfig();
    $target = $usersCfg['users'][$targetId] ?? null;
    if ($target === null) {
        return ApiResponse::create(404, 'user.not_found')
            ->withMessage('No account with this user id');
    }

    $today = date('Y-m-d');
    $invitation = ['role' => $role, 'direction' => 'invite', 'by' => $actorId, 'at' => $today];
    if ($note !== null) {
        $invitation['note'] = $note;
    }

    // All state checks run IN-LOCK against the fresh members.json — the rank
    // check uses the actor's CURRENT role (a concurrent demotion can't be
    // outrun), and member/pending conflicts can't race.
    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, $role, $invitation, &$error) {
        $actorRole = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole === null || !canManageRole($actorRole, $role)) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        if (isset($m['members'][$targetId])) {
            $error = 'member.already_exists';
            return false;
        }
        if (isset($m['invitations'][$targetId])) {
            $error = 'invitation.already_pending';
            return false;
        }
        if (!isset($m['invitations']) || !is_array($m['invitations'])) {
            $m['invitations'] = [];
        }
        $m['invitations'][$targetId] = $invitation;
        return true;
    }, $failure);

    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You can only offer roles of strictly lower rank than your own');
    }
    if ($error === 'member.already_exists') {
        return ApiResponse::create(409, 'member.already_exists')
            ->withMessage('This user is already a member of the project');
    }
    if ($error === 'invitation.already_pending') {
        return ApiResponse::create(409, 'invitation.already_pending')
            ->withMessage('This user already has a pending invitation — cancel it first to change the offer');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Status mirror (secondary write): the invitee's own cache gains the
    // pending entry their listMyInvitations inbox reads. Failure is silent by
    // ruling — access is already correct (members.json committed), the mirror
    // heals via 8.4 reconcile.
    $mirror = ['name' => qs_project_site_name($project), 'status' => 'pending_invite', 'at' => $today];
    if (!qs_membership_cache_set($targetId, $project, $mirror)) {
        error_log("inviteMember: cache mirror write failed for '{$targetId}' on '{$project}'");
    }

    return ApiResponse::create(201, 'resource.created')
        ->withMessage('Invitation sent')
        ->withData([
            'project' => $project,
            'user_id' => $targetId,
            'name'    => qs_public_user_ref($targetId, $usersCfg)['name'],
            'role'    => $role,
            'at'      => $today,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_inviteMember($trimParams->params(), $trimParams->additionalParams())->send();
}
