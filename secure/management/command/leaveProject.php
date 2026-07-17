<?php
/**
 * leaveProject Command (C8 8.3a)
 *
 * Self-service exit: removes the CALLER's own membership. Self-initiated →
 * plain removal everywhere, NO tombstone (R4 principle). The owner cannot
 * leave — a project must never go ownerless (last-owner invariant):
 * transferOwnership first, or deleteProject.
 *
 * Global-scoped on purpose: it acts only on the caller's own entry, and a
 * uniform 404 keeps nonexistent projects indistinguishable from "not a
 * member".
 *
 * @method POST
 * @route /management/leaveProject
 * @auth required (membership.self — any authenticated user)
 *
 * @param string $project Project id to leave (required)
 *
 * @return ApiResponse Leave result
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
function __command_leaveProject(array $params = [], array $urlParams = []): ApiResponse {
    $project = trim((string)($params['project'] ?? ''));
    if ($project === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('project is required')
            ->withErrors(['project' => 'Required field']);
    }
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
        error_log("leaveProject: cache mirror removal failed for '{$userId}' on '{$project}'");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('You left the project')
        ->withData([
            'project' => $project,
            'left'    => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_leaveProject($trimParams->params(), $trimParams->additionalParams())->send();
}
