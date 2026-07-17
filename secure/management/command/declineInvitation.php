<?php
/**
 * declineInvitation Command (C8 8.3a)
 *
 * The consent model's "no": removes the caller's OWN pending invitation.
 * Self-initiated → plain removal everywhere, NO tombstone (R4 principle); the
 * inviter simply sees the invitation gone from listMembers.
 *
 * Enumeration posture: same uniform 404 as acceptInvitation — a nonexistent
 * project is indistinguishable from "no invitation for you".
 *
 * @method POST
 * @route /management/declineInvitation
 * @auth required (membership.self — any authenticated user)
 *
 * @param string $project Project id the invitation belongs to (required)
 *
 * @return ApiResponse Decline result
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
function __command_declineInvitation(array $params = [], array $urlParams = []): ApiResponse {
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
        $inv = $m['invitations'][$userId] ?? null;
        if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'invite')) {
            $error = 'invitation.not_found';
            return false;
        }
        unset($m['invitations'][$userId]);
        return true;
    }, $failure);

    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'invitation.not_found') {
        return ApiResponse::create(404, 'invitation.not_found')
            ->withMessage('No pending invitation for you on this project');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Mirror: plain removal — declining is the caller's own decision.
    if (!qs_membership_cache_set($userId, $project, null)) {
        error_log("declineInvitation: cache mirror removal failed for '{$userId}' on '{$project}'");
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitation declined')
        ->withData([
            'project'  => $project,
            'declined' => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_declineInvitation($trimParams->params(), $trimParams->additionalParams())->send();
}
