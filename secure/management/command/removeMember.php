<?php
/**
 * removeMember Command (C8 8.3a)
 *
 * Removes a member from the project. Other-initiated termination → the
 * removed user's cache keeps a dismissable 'removed' notice (R4 principle:
 * self-initiated exits leave no tombstone, other-initiated ones do). The
 * optional note travels with the notice (control-stripped, capped).
 *
 * Rank rule (in-lock): canManageRole(actor, target's current role). The owner
 * is un-removable (transferOwnership first); self-removal is refused —
 * leaveProject is the self-service door.
 *
 * @method POST
 * @route /management/p/<projectId>/removeMember
 * @auth required (project.members — admin, owner)
 *
 * @param string $user_id Target member's user id (required)
 * @param string $note    Optional reason shown to the removed user
 *
 * @return ApiResponse Removal result
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
function __command_removeMember(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/removeMember');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $targetId = trim((string)($params['user_id'] ?? ''));
    $note     = qs_clean_note($params['note'] ?? null);

    if ($targetId === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id is required')
            ->withErrors(['user_id' => 'Required field']);
    }
    if ($targetId === $actorId) {
        return ApiResponse::create(400, 'member.cannot_target_self')
            ->withMessage('You cannot remove yourself — use leaveProject');
    }

    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, &$error) {
        $entry = $m['members'][$targetId] ?? null;
        if (!is_array($entry)) {
            $error = 'member.not_found';
            return false;
        }
        $targetRole = (string)($entry['role'] ?? '');
        if ($targetRole === 'owner') {
            $error = 'member.owner_immutable';
            return false;
        }
        $actorRole = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole === null || !canManageRole($actorRole, $targetRole)) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        unset($m['members'][$targetId]);
        return true;
    }, $failure);

    if ($error === 'member.not_found') {
        return ApiResponse::create(404, 'member.not_found')
            ->withMessage('This user is not a member of the project');
    }
    if ($error === 'member.owner_immutable') {
        return ApiResponse::create(400, 'member.owner_immutable')
            ->withMessage('The owner cannot be removed — transfer ownership first');
    }
    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You can only remove members of strictly lower rank than your own');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Mirror: other-initiated termination → dismissable 'removed' notice in
    // the target's own cache (keeps the cached display name when present).
    $usersCfg = loadUsersConfig();
    $cachedName = $usersCfg['users'][$targetId]['projects'][$project]['name'] ?? null;
    $notice = [
        'name'   => is_string($cachedName) && $cachedName !== '' ? $cachedName : qs_project_site_name($project),
        'status' => 'removed',
        'at'     => date('Y-m-d'),
    ];
    if ($note !== null) {
        $notice['note'] = $note;
    }
    if (!qs_membership_cache_set($targetId, $project, $notice)) {
        error_log("removeMember: cache notice write failed for '{$targetId}' on '{$project}'");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Member removed')
        ->withData([
            'project' => $project,
            'user_id' => $targetId,
            'removed' => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_removeMember($trimParams->params(), $trimParams->additionalParams())->send();
}
