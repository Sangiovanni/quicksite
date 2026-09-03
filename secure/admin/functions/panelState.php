<?php
/**
 * The admin panel's own per-user state.
 *
 * NOT COMMANDS. Under the beta.11 rule the command surface is a CLI for
 * DEVELOPING a project; which project a person's panel happens to have open is
 * a fact about the panel, not about any project, so it is served here and
 * reached at `/admin/state/…` rather than through `/management`.
 *
 * Today that is one value: `selected_project`. The file is shaped for a second
 * (a dismissed notice, a remembered pane) landing beside it without redesign.
 *
 * Answers are ApiResponse envelopes — the same shape `/management` returns — so
 * the panel's existing error handling reads them unchanged.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';

if (!function_exists('qs_admin_set_selected_project')) {
    /**
     * Set THIS caller's `selected_project` (users.php) — the project their panel
     * EDITS (the header picker/badge, the visual editor's marker, the preview).
     * Which project a DEPLOYMENT serves is a web-server mapping, and nothing
     * here touches it.
     *
     * selected_project is a UX default, NEVER an authz input: the dispatcher
     * re-authorizes every request against the URL project + members.json. We
     * still refuse selecting a project you are not a MEMBER of, so the panel
     * never opens a project it cannot edit.
     *
     * @param array  $user    the authenticated caller (needs 'id')
     * @param string $project project id to make their editing target
     */
    function qs_admin_set_selected_project(array $user, string $project): ApiResponse {
        $project = trim($project);
        if ($project === '') {
            return ApiResponse::create(400, 'validation.missing_field')
                ->withMessage('project is required')
                ->withErrors(['project' => 'Required field']);
        }
        // The value becomes a directory selector downstream (path safety).
        if (!is_valid_project_name($project)) {
            return ApiResponse::create(400, 'project.invalid')
                ->withMessage('Invalid project identifier');
        }

        $userId = (string)($user['id'] ?? '');
        if ($userId === '') {
            return ApiResponse::create(401, 'auth.required')
                ->withMessage('Authentication required');
        }

        // Must be a real member (authoritative members.json). Same refusal
        // whether the project does not exist or the caller is simply not a
        // member — no membership oracle.
        if (getUserRoleForProject($userId, $project) === null) {
            return ApiResponse::create(403, 'authz.not_a_member')
                ->withMessage('You are not a member of this project');
        }

        // Written through THE users.php writer (qs_users_mutate — flock +
        // temp/rename + opcache invalidate), never by hand.
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
}
