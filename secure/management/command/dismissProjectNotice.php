<?php
/**
 * dismissProjectNotice Command (C8 8.3a)
 *
 * Clears ONE terminal notice (refused | removed | deleted) from the CALLER's
 * own users.php cache — the "OK, seen it" for the notices listMyInvitations
 * surfaces. LIVE states are never dismissable: a membership is ended by
 * leaveProject, a pending invitation by declineInvitation.
 *
 * Pure cache operation: no members.json involved (for a 'deleted' notice the
 * project — and its members.json — no longer exists; that is the point). The
 * project id is shape-checked only (F1), never existence-checked.
 *
 * @method POST
 * @route /management/dismissProjectNotice
 * @auth required (membership.self — any authenticated user)
 *
 * @param string $project Project id the notice refers to (required)
 *
 * @return ApiResponse Dismissal result
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
function __command_dismissProjectNotice(array $params = [], array $urlParams = []): ApiResponse {
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
    $status = null;
    $written = qs_users_mutate(function (array &$cfg) use ($userId, $project, &$error, &$status) {
        $entry = $cfg['users'][$userId]['projects'][$project] ?? null;
        if (!is_array($entry)) {
            $error = 'notice.not_found';
            return false;
        }
        $status = $entry['status'] ?? 'member';
        if (!in_array($status, ['refused', 'removed', 'deleted'], true)) {
            $error = 'notice.not_dismissable';
            return false;
        }
        unset($cfg['users'][$userId]['projects'][$project]);
        return true;
    });

    if ($error === 'notice.not_found') {
        return ApiResponse::create(404, 'notice.not_found')
            ->withMessage('No notice for this project');
    }
    if ($error === 'notice.not_dismissable') {
        return ApiResponse::create(400, 'notice.not_dismissable')
            ->withMessage('Only terminal notices can be dismissed — leave a project with leaveProject, decline an invitation with declineInvitation')
            ->withData(['status' => $status]);
    }
    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to persist the dismissal');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Notice dismissed')
        ->withData([
            'project'   => $project,
            'dismissed' => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_dismissProjectNotice($trimParams->params(), $trimParams->additionalParams())->send();
}
