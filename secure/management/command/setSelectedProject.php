<?php
/**
 * setSelectedProject Command (C9)
 *
 * Sets the CALLER's per-user `selected_project` (users.php) — the project their panel
 * EDITS (drives the header picker/badge, the visual editor's C7 marker, and the preview).
 * Distinct from `switchProject`, which repoints the globally SERVED project (target.php).
 *
 * selected_project is a UX default, NEVER an authz input (the dispatcher re-authorizes
 * every request against the URL project + members.json). But we still refuse selecting a
 * project you are not a MEMBER of, so the panel never opens a project it cannot edit.
 *
 * Global-scoped (user-level) — no `/management/p/<id>/` marker.
 *
 * @method POST
 * @route /management/setSelectedProject
 * @auth required
 * @param string $project  Project id to make the caller's editing target (member-only)
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

function __command_setSelectedProject(array $params = [], array $urlParams = []): ApiResponse {
    $project = trim((string)($params['project'] ?? ''));
    if ($project === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('project is required')
            ->withErrors(['project' => 'Required field']);
    }
    // F1 — the value becomes a directory selector downstream (path safety).
    if (!is_valid_project_name($project)) {
        return ApiResponse::create(400, 'project.invalid')
            ->withMessage('Invalid project identifier');
    }

    $user = getCurrentUser();
    if ($user === null || !isset($user['id'])) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    $userId = $user['id'];

    // Must be a real member (authoritative members.json — L5). Same refusal whether the
    // project does not exist or the user is simply not a member (no membership oracle).
    if (getUserRoleForProject($userId, $project) === null) {
        return ApiResponse::create(403, 'authz.not_a_member')
            ->withMessage('You are not a member of this project');
    }

    // Update the caller's selected_project through THE users.php writer
    // (qs_users_mutate — flock + temp/rename + opcache invalidate, C8).
    $userMissing = false;
    $written = qs_users_mutate(function (array &$cfg) use ($userId, $project, &$userMissing) {
        if (!isset($cfg['users'][$userId])) {
            $userMissing = true;
            return false;
        }
        $cfg['users'][$userId]['selected_project'] = $project;
        return true;
    });
    if ($userMissing) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage('User record not found');
    }
    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to persist selected project');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Now editing project '$project'")
        ->withData([
            'selected_project' => $project,
            'role'             => getUserRoleForProject($userId, $project),
        ]);
}

// Execute via HTTP (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setSelectedProject($trimParams->params(), $trimParams->additionalParams())->send();
}
