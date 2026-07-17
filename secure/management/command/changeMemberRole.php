<?php
/**
 * changeMemberRole Command (C8 8.3a)
 *
 * Changes an existing member's role. Rank rule (L9/F6, in-lock): the actor
 * must outrank the member's CURRENT role AND the NEW role (an admin can
 * neither touch another admin nor mint one; the owner manages everything
 * below rank 6). The owner's role is immutable here — transferOwnership is
 * the only door. Self-targeting is refused explicitly.
 *
 * No cache touch: the users.php mirror is roleless (role is authoritative in
 * members.json only) and the target's status stays 'member'.
 *
 * @method POST
 * @route /management/p/<projectId>/changeMemberRole
 * @auth required (project.members — admin, owner)
 *
 * @param string $user_id Target member's user id (required)
 * @param string $role    New role (required; below owner, below the actor)
 *
 * @return ApiResponse Role change result
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
function __command_changeMemberRole(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/changeMemberRole');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $targetId = trim((string)($params['user_id'] ?? ''));
    $newRole  = trim((string)($params['role'] ?? ''));

    if ($targetId === '' || $newRole === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id and role are required')
            ->withErrors([
                'user_id' => $targetId === '' ? 'Required field' : null,
                'role'    => $newRole === '' ? 'Required field' : null,
            ]);
    }
    if ($targetId === $actorId) {
        return ApiResponse::create(400, 'member.cannot_target_self')
            ->withMessage('You cannot change your own role');
    }
    if ($newRole === 'owner') {
        return ApiResponse::create(400, 'member.role_not_assignable')
            ->withMessage("The 'owner' role cannot be assigned — use transferOwnership");
    }
    if (!isValidRole($newRole)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Unknown role')
            ->withErrors(['role' => 'Must be one of the built-in roles']);
    }

    $error = null;
    $failure = null;
    $previousRole = null;
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, $newRole, &$error, &$previousRole) {
        $entry = $m['members'][$targetId] ?? null;
        if (!is_array($entry)) {
            $error = 'member.not_found';
            return false;
        }
        $currentRole = (string)($entry['role'] ?? '');
        $previousRole = $currentRole;
        if ($currentRole === 'owner') {
            $error = 'member.owner_immutable';
            return false;
        }
        $actorRole = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole === null || !canManageRole($actorRole, $currentRole) || !canManageRole($actorRole, $newRole)) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        if ($currentRole === $newRole) {
            $error = 'noop';
            return false; // nothing to write
        }
        $m['members'][$targetId]['role'] = $newRole;
        return true;
    }, $failure);

    if ($error === 'member.not_found') {
        return ApiResponse::create(404, 'member.not_found')
            ->withMessage('This user is not a member of the project');
    }
    if ($error === 'member.owner_immutable') {
        return ApiResponse::create(400, 'member.owner_immutable')
            ->withMessage("The owner's role can only change through transferOwnership");
    }
    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You can only manage members and assign roles of strictly lower rank than your own');
    }
    if ($error === 'noop') {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Member already holds this role')
            ->withData([
                'project'       => $project,
                'user_id'       => $targetId,
                'role'          => $newRole,
                'previous_role' => $previousRole,
                'role_changed'  => false,
            ]);
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Member role updated')
        ->withData([
            'project'       => $project,
            'user_id'       => $targetId,
            'role'          => $newRole,
            'previous_role' => $previousRole,
            'role_changed'  => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_changeMemberRole($trimParams->params(), $trimParams->additionalParams())->send();
}
