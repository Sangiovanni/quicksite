<?php
/**
 * transferOwnership Command (C8 8.3a)
 *
 * Rotates project ownership to an EXISTING member — transfer is a role
 * rotation, never an implicit add (invite + accept first). The whole rotation
 * is ONE atomic members.json write (flock + fresh in-lock read + invariant
 * backstop + temp/rename): owner field → new owner, new owner's role →
 * 'owner', old owner → old_owner_role (default 'admin'). There is no
 * read-back-reverse pass — the atomic swap plus the backstop (exactly one
 * owner, owner field ⇔ owner role) IS the integrity guarantee (ruled R3).
 *
 * The target must also still resolve in users.php at transfer time (the
 * cross-file existence check) — ownership never lands on a ghost account.
 *
 * No cache touch: both parties remain members; the mirror is roleless.
 *
 * @method POST
 * @route /management/p/<projectId>/transferOwnership
 * @auth required (project.ownership — owner only)
 *
 * @param string $user_id        New owner's user id (required; must be a member)
 * @param bool   $confirm        Safety confirmation (required, must be true)
 * @param string $old_owner_role Role the departing owner keeps (optional, default 'admin', any role below owner)
 *
 * @return ApiResponse Transfer result
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
function __command_transferOwnership(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/transferOwnership');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $targetId     = trim((string)($params['user_id'] ?? ''));
    $oldOwnerRole = trim((string)($params['old_owner_role'] ?? 'admin'));
    $confirm      = filter_var($params['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($targetId === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id is required')
            ->withErrors(['user_id' => 'Required field']);
    }
    if (!$confirm) {
        return ApiResponse::create(400, 'validation.confirmation_required')
            ->withMessage('Ownership transfer must be confirmed')
            ->withErrors(['confirm' => 'Set confirm=true to proceed'])
            ->withData(['warning' => 'The target member becomes the project owner; you keep old_owner_role (default admin)']);
    }
    if ($targetId === $actorId) {
        return ApiResponse::create(400, 'member.cannot_target_self')
            ->withMessage('You already own this project');
    }
    if (!isValidRole($oldOwnerRole)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Unknown old_owner_role')
            ->withErrors(['old_owner_role' => 'Must be one of the built-in roles']);
    }
    if (roleRank($oldOwnerRole) >= 6) {
        return ApiResponse::create(400, 'member.role_not_assignable')
            ->withMessage('old_owner_role must be below owner');
    }

    // Cross-file check (ruled): the new owner must still resolve in users.php
    // AT TRANSFER TIME — ownership never lands on a deleted/ghost account.
    $usersCfg = loadUsersConfig();
    if (!isset($usersCfg['users'][$targetId])) {
        return ApiResponse::create(404, 'user.not_found')
            ->withMessage('No account with this user id');
    }

    $error = null;
    $failure = null;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, $oldOwnerRole, &$error) {
        // In-lock integrity pre-assert: the owner FIELD must match the
        // role-'owner' member — surfaced, never silently repaired.
        $owner = $m['owner'] ?? null;
        if (!is_string($owner) || $owner === '' || (($m['members'][$owner]['role'] ?? null) !== 'owner')) {
            $error = 'members.integrity';
            return false;
        }
        // The presenting owner must STILL be the owner in-lock (a concurrent
        // transfer loses).
        if ($owner !== $actorId) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        if (!isset($m['members'][$targetId])) {
            $error = 'member.not_a_member';
            return false;
        }
        $m['owner'] = $targetId;
        $m['members'][$targetId]['role'] = 'owner';
        $m['members'][$actorId]['role'] = $oldOwnerRole;
        return true;
    }, $failure);

    if ($error === 'members.integrity') {
        return ApiResponse::create(500, 'members.integrity')
            ->withMessage('The membership file is inconsistent (owner field does not match the owner role) — refusing to transfer');
    }
    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('Only the current owner can transfer ownership');
    }
    if ($error === 'member.not_a_member') {
        return ApiResponse::create(400, 'member.not_a_member')
            ->withMessage('The new owner must already be a member — invite them first');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Ownership transferred')
        ->withData([
            'project'        => $project,
            'new_owner'      => qs_public_user_ref($targetId, $usersCfg),
            'old_owner'      => qs_public_user_ref($actorId, $usersCfg),
            'old_owner_role' => $oldOwnerRole,
            'transferred'    => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_transferOwnership($trimParams->params(), $trimParams->additionalParams())->send();
}
