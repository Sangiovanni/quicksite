<?php
/**
 * listMembers Command (C8 8.3a)
 *
 * The roster of the TARGET project: active members (rank-descending) plus the
 * pending invitations block. Project-scoped on the URL marker
 * ('/management/p/<projectId>/listMembers' — category project.members,
 * admin/owner); the body carries no project parameter.
 *
 * PRIVACY (C8 8.0b): users are referenced as {user_id, name} — the public
 * display name and the opaque id. The PRIVATE username never appears here.
 *
 * @method GET
 * @route /management/p/<projectId>/listMembers
 * @auth required (project.members — admin, owner)
 *
 * @return ApiResponse owner, visibility, members[], invitations[]
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
function __command_listMembers(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: the target project is EXCLUSIVELY the authorized URL
    // marker (PROJECT_NAME, bound by the dispatcher after the category +
    // membership check). No marker → nothing was authorized.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/listMembers');
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

    $invitations = [];
    foreach (($data['invitations'] ?? []) as $userId => $inv) {
        if (!is_array($inv)) {
            continue;
        }
        $ref = qs_public_user_ref((string)$userId, $usersCfg);
        $row = [
            'user_id'    => (string)$userId,
            'name'       => $ref['name'],
            'role'       => $inv['role'] ?? null,
            'direction'  => $inv['direction'] ?? 'invite',
            'invited_by' => isset($inv['by']) ? qs_public_user_ref((string)$inv['by'], $usersCfg) : null,
            'at'         => $inv['at'] ?? null,
        ];
        if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
            $row['note'] = $inv['note'];
        }
        $invitations[] = $row;
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Members listed successfully')
        ->withData([
            'project'          => $project,
            'owner_user_id'    => $data['owner'] ?? null,
            'visibility'       => $data['visibility'] ?? 'private',
            'members'          => $members,
            'invitations'      => $invitations,
            'member_count'     => count($members),
            'invitation_count' => count($invitations),
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listMembers($trimParams->params(), $trimParams->additionalParams())->send();
}
