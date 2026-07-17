<?php
/**
 * cancelInvitation Command (C8 8.3a)
 *
 * Withdraws a pending invitation before the invitee answers. Plain removal on
 * both sides — a withdrawn offer leaves NO tombstone in the invitee's cache
 * (R4 principle: it is not a decision against them).
 *
 * Rank rule: cancelling an offer is managing that role — the actor must
 * outrank the OFFERED role (canManageRole, in-lock, no inviter carve-out).
 *
 * Direction gate (C8 8.3b): this command withdraws INVITES only. A
 * `direction:'request'` entry (join request / proposal) is answered through
 * approveJoinRequest / denyJoinRequest — cancelling one here would be a
 * silent deny that dodges the mandatory refusal note.
 *
 * @method POST
 * @route /management/p/<projectId>/cancelInvitation
 * @auth required (project.members — admin, owner)
 *
 * @param string $user_id Invitee's user id (required)
 *
 * @return ApiResponse Cancellation result
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
function __command_cancelInvitation(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/cancelInvitation');
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

    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, &$error) {
        $inv = $m['invitations'][$targetId] ?? null;
        if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'invite')) {
            // Requests/proposals are not cancellable offers — approve or deny
            // them (the mandatory-note refusal lane). Uniform not-found.
            $error = 'invitation.not_found';
            return false;
        }
        $actorRole   = $m['members'][$actorId]['role'] ?? null;
        $offeredRole = $inv['role'] ?? '';
        if ($actorRole === null || !canManageRole($actorRole, (string)$offeredRole)) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        unset($m['invitations'][$targetId]);
        return true;
    }, $failure);

    if ($error === 'invitation.not_found') {
        return ApiResponse::create(404, 'invitation.not_found')
            ->withMessage('No pending invitation for this user');
    }
    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You can only cancel invitations for roles of strictly lower rank than your own');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Mirror: plain removal of the invitee's pending entry (no tombstone).
    if (!qs_membership_cache_set($targetId, $project, null)) {
        error_log("cancelInvitation: cache mirror removal failed for '{$targetId}' on '{$project}'");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitation cancelled')
        ->withData([
            'project'   => $project,
            'user_id'   => $targetId,
            'cancelled' => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_cancelInvitation($trimParams->params(), $trimParams->additionalParams())->send();
}
