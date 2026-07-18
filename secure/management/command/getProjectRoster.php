<?php
/**
 * getProjectRoster Command (C8 8.3c)
 *
 * The reduced roster of the TARGET project for EVERY member rank: active
 * members only — {user_id, name, role, rank, is_owner}, rank-descending.
 * The pending invitations/requests block is deliberately ABSENT: adjudication
 * data stays admin/owner (listMembers). This command exists so that a viewer/
 * editor/designer/developer can still see "who is on this project with me"
 * (category project.roster, granted to all member roles).
 *
 * PRIVACY (C8 8.0b): users are referenced as {user_id, name} — the public
 * display name and the opaque id. The PRIVATE username never appears here.
 *
 * @method GET
 * @route /management/p/<projectId>/getProjectRoster
 * @auth required (project.roster — all member roles)
 *
 * @return ApiResponse members[] + member_count
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getProjectRoster(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: the target project is EXCLUSIVELY the authorized URL
    // marker (PROJECT_NAME, bound by the dispatcher after the category +
    // membership check). No marker → nothing was authorized.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/getProjectRoster');
    }
    $project = PROJECT_NAME;

    $data = loadProjectMembers($project);
    $usersCfg = loadUsersConfig();

    $members = [];
    foreach (($data['members'] ?? []) as $userId => $entry) {
        $role = is_array($entry) ? ($entry['role'] ?? null) : null;
        $ref = qs_public_user_ref((string)$userId, $usersCfg);
        $members[] = [
            'user_id'  => (string)$userId,
            'name'     => $ref['name'],
            'role'     => $role,
            'rank'     => roleRank($role),
            'is_owner' => ($role === 'owner'),
        ];
    }
    usort($members, function ($a, $b) {
        if ($a['rank'] !== $b['rank']) {
            return $b['rank'] <=> $a['rank']; // rank-descending
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Roster listed successfully')
        ->withData([
            'project'      => $project,
            'members'      => $members,
            'member_count' => count($members),
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getProjectRoster($trimParams->params(), $trimParams->additionalParams())->send();
}
