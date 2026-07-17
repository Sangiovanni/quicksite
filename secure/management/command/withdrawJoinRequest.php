<?php
/**
 * withdrawJoinRequest Command (C8 8.3b)
 *
 * Withdraws a `direction:'request'` entry the CALLER AUTHORED (`by` = caller):
 * without user_id it targets the caller's own join request (requestToJoin);
 * with user_id it targets a proposal the caller sponsored (proposeMember).
 * Self-initiated → plain removal everywhere, NO tombstone (R4 principle).
 *
 * Privacy: the `by === caller` rule doubles as the containment gate — a user
 * probing for a sponsored proposal ABOUT them (by != them, never engaged)
 * gets the same uniform 404 as "nothing there", and a nonexistent project is
 * indistinguishable from "no request of yours" (the 8.3a discipline).
 *
 * @method POST
 * @route /management/withdrawJoinRequest
 * @auth required (membership.self — any authenticated user)
 *
 * @param string $project Project id the request/proposal sits on (required)
 * @param string $user_id Proposal target's user id (optional — omit for your own request)
 *
 * @return ApiResponse Withdrawal result
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
function __command_withdrawJoinRequest(array $params = [], array $urlParams = []): ApiResponse {
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
            error_log("withdrawJoinRequest: cache mirror removal failed for '{$userId}' on '{$project}'");
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

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_withdrawJoinRequest($trimParams->params(), $trimParams->additionalParams())->send();
}
