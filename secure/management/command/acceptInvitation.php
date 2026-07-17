<?php
/**
 * acceptInvitation Command (C8 8.3a)
 *
 * Consent step: turns the caller's OWN pending invitation into membership.
 * The grant materializes ONLY here — and only after ACCEPT-TIME
 * RE-VALIDATION (locked R4): the inviter must STILL be a member whose rank
 * outranks the offered role. A demoted/removed inviter's invitation is VOID —
 * it is pruned and refused (grants never materialize on dead authority).
 *
 * Enumeration posture: a nonexistent project and an existing project with no
 * invitation for the caller return the SAME 404 invitation.not_found — this
 * command is no project-existence oracle.
 *
 * Global-scoped self-service: the invitee is not yet a member, so the
 * '/p/<id>/' marker gate would 403 them; `project` is an F1-validated DATA
 * parameter and the command touches only the caller's own entries.
 *
 * @method POST
 * @route /management/acceptInvitation
 * @auth required (membership.self — any authenticated user)
 *
 * @param string $project Project id the invitation belongs to (required)
 *
 * @return ApiResponse Join result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_acceptInvitation(array $params = [], array $urlParams = []): ApiResponse {
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
            error_log("acceptInvitation: void-prune mirror removal failed for '{$userId}' on '{$project}'");
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
        error_log("acceptInvitation: cache mirror write failed for '{$userId}' on '{$project}'");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitation accepted — welcome aboard')
        ->withData([
            'project' => $project,
            'role'    => $joinedRole,
            'joined'  => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_acceptInvitation($trimParams->params(), $trimParams->additionalParams())->send();
}
