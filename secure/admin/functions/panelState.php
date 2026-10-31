<?php
/**
 * The admin panel's own per-user state.
 *
 * NOT COMMANDS. By design the command surface is a CLI for
 * DEVELOPING a project; which project a person's panel happens to have open is
 * a fact about the panel, not about any project, so it is served here and
 * reached at `/admin/state/…` rather than through `/management`.
 *
 * Today that is two values in the account's users.php entry: `selected_project`
 * (the project the panel edits) and `starred_projects` (the projects the account
 * starred, which come first in each of its project lists).
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
     * never opens a project it cannot edit. The same write repairs the caller's
     * cache entry for that project (a member entry), which the header's menu reads.
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
            // A switch repairs the cache entry of the project it opens, which the check
            // above just proved a membership: the header's menu (getUserProjectIds())
            // lists it from then on, as after the dashboard's repair.
            $projects = $cfg['users'][$userId]['projects'] ?? [];
            if (is_array($projects)) {
                $projects[$project] = qs_membership_reconciled_entry($projects[$project] ?? null, 'member', date('Y-m-d'));
                $cfg['users'][$userId]['projects'] = $projects;
            }
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

if (!function_exists('qs_admin_starred_projects')) {
    /**
     * THIS caller's starred projects (users.php `starred_projects`): project ids, each
     * once. A list in the panel stars only a project it lists, so a star on a project
     * the caller has left, or one since deleted, shows nowhere — and the writer below
     * drops it the next time it writes. An entry without the key has nothing starred.
     *
     * @param array $user the caller's users.php entry
     * @return string[]
     */
    function qs_admin_starred_projects(array $user): array {
        $stored = $user['starred_projects'] ?? [];
        if (!is_array($stored)) {
            return [];
        }
        $ids = [];
        foreach ($stored as $id) {
            if (is_string($id) && is_valid_project_name($id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}

if (!function_exists('qs_admin_set_project_star')) {
    /**
     * Star or unstar a project for THIS caller — the one writer of users.php
     * `starred_projects`. A star is a display preference: it orders the caller's own
     * project lists and decides nothing else.
     *
     * Starring a project the caller is not a member of is refused, as selecting one
     * is; unstarring always goes through, so a star left on a project the caller no
     * longer belongs to can be taken off. Every write also drops the stars of the
     * projects the caller no longer belongs to.
     *
     * @param array  $user    the authenticated caller (needs 'id')
     * @param string $project project id
     * @param mixed  $starred true to star, false to unstar (anything else is refused)
     */
    function qs_admin_set_project_star(array $user, string $project, $starred): ApiResponse {
        $project = trim($project);
        if ($project === '') {
            return ApiResponse::create(400, 'validation.missing_field')
                ->withMessage('project is required')
                ->withErrors(['project' => 'Required field']);
        }
        if (!is_bool($starred)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('starred must be true or false')
                ->withErrors(['starred' => 'Must be true or false']);
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

        // The same refusal whether the project does not exist or the caller is not
        // a member — no membership oracle.
        if ($starred && getUserRoleForProject($userId, $project) === null) {
            return ApiResponse::create(403, 'authz.not_a_member')
                ->withMessage('You are not a member of this project');
        }

        $userMissing = false;
        $starredNow = [];
        $written = qs_users_mutate(function (array &$cfg) use ($userId, $project, $starred, &$userMissing, &$starredNow) {
            if (!isset($cfg['users'][$userId])) {
                $userMissing = true;
                return false;
            }
            $kept = [];
            foreach (qs_admin_starred_projects($cfg['users'][$userId]) as $id) {
                if ($id !== $project && getUserRoleForProject($userId, $id) !== null) {
                    $kept[] = $id;
                }
            }
            if ($starred) {
                $kept[] = $project;
            }
            if ($kept === []) {
                unset($cfg['users'][$userId]['starred_projects']);
            } else {
                $cfg['users'][$userId]['starred_projects'] = $kept;
            }
            $starredNow = $kept;
            return true;
        });
        if ($userMissing) {
            return ApiResponse::create(404, 'resource.not_found')
                ->withMessage('User record not found');
        }
        if ($written !== true) {
            return ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage('Failed to persist the star');
        }

        return ApiResponse::create(200, 'operation.success')
            ->withMessage($starred ? "Project '$project' starred" : "Project '$project' unstarred")
            ->withData([
                'project'          => $project,
                'starred'          => $starred,
                'starred_projects' => $starredNow,
            ]);
    }
}
