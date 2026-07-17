<?php
/**
 * proposeMember Command (C8 8.3b)
 *
 * The sponsor lane (model A, locked round 4): ANY member — viewer included —
 * may vouch an outsider for membership. The proposal lands in the
 * `invitations` block as `direction:'request'` with `by` = the sponsor and a
 * MANDATORY note (the vouch), and needs admin/owner validation
 * (approveJoinRequest) before the outsider is even ENGAGED: no mirror entry,
 * no inbox row, nothing on the target until authority converts it into a
 * real invitation. The sponsor names the role — rank is the VALIDATOR's
 * problem (canManageRole at approve), not the sponsor's.
 *
 * join_policy does NOT gate proposals: the knob closes the self-service
 * front door (requestToJoin); member-vouched proposals always reach the
 * admin queue.
 *
 * @method POST
 * @route /management/p/<projectId>/proposeMember
 * @auth required (project.propose — every role, viewer+)
 *
 * @param string $user_id Proposed account's user id (required)
 * @param string $role    Suggested role (required; any role below owner)
 * @param string $note    The vouch — why this person (required, 500 chars)
 *
 * @return ApiResponse Proposal summary
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
function __command_proposeMember(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: the target project is EXCLUSIVELY the authorized URL
    // marker. Body project/name keys are ignored by design.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/proposeMember');
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

    if ($targetId === '' || $role === '' || $note === null) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id, role and note are required — a proposal always carries its vouch')
            ->withErrors([
                'user_id' => $targetId === '' ? 'Required field' : null,
                'role'    => $role === '' ? 'Required field' : null,
                'note'    => $note === null ? 'Required field' : null,
            ]);
    }
    if ($role === 'owner') {
        return ApiResponse::create(400, 'member.role_not_assignable')
            ->withMessage("The 'owner' role cannot be proposed — ownership moves only via transferOwnership");
    }
    if (!isValidRole($role)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Unknown role')
            ->withErrors(['role' => 'Must be one of the built-in roles']);
    }

    // Target must be an existing account (opaque 128-bit id — an honest 404
    // is not a practical existence oracle, same reasoning as inviteMember R1).
    $usersCfg = loadUsersConfig();
    if (!isset($usersCfg['users'][$targetId])) {
        return ApiResponse::create(404, 'user.not_found')
            ->withMessage('No account with this user id');
    }

    $today = date('Y-m-d');
    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, $role, $note, $today, &$error) {
        // The sponsor must STILL be a member (any rank) on the fresh in-lock
        // state — a concurrently-removed member cannot plant proposals.
        if (($m['members'][$actorId]['role'] ?? null) === null) {
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
        $m['invitations'][$targetId] = [
            'role'      => $role,
            'direction' => 'request',
            'by'        => $actorId,
            'at'        => $today,
            'note'      => $note,
        ];
        return true;
    }, $failure);

    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You must be a member of this project to propose someone');
    }
    if ($error === 'member.already_exists') {
        return ApiResponse::create(409, 'member.already_exists')
            ->withMessage('This user is already a member of the project');
    }
    if ($error === 'invitation.already_pending') {
        return ApiResponse::create(409, 'invitation.already_pending')
            ->withMessage('This user already has a pending invitation, request or proposal on this project');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Deliberately NO mirror write: the target is not engaged until an
    // admin/owner validates the proposal (approveJoinRequest converts it into
    // a real invitation and only THEN writes the pending_invite mirror).

    return ApiResponse::create(201, 'resource.created')
        ->withMessage('Proposal recorded — a project admin or the owner must validate it before the person is invited')
        ->withData([
            'project'  => $project,
            'user_id'  => $targetId,
            'name'     => qs_public_user_ref($targetId, $usersCfg)['name'],
            'role'     => $role,
            'at'       => $today,
            'proposed' => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_proposeMember($trimParams->params(), $trimParams->additionalParams())->send();
}
