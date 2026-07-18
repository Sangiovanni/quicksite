<?php
/**
 * listMyProposals Command (C8 8.3c)
 *
 * The sponsor's view of their OWN outgoing proposals (proposeMember): for each
 * project the caller is a MEMBER of, the pending direction:'request' entries
 * the caller authored about someone else ('pending_validation'), plus the
 * proposals an admin/owner already approved into a real invitation that still
 * awaits the person's answer ('awaiting_answer' — sponsor kept as attribution).
 * Closes the 8.3b blind-withdraw gap: withdrawJoinRequest {project, user_id}
 * now has a surface to act from. A proposal that is neither listed as pending
 * nor as awaiting was adjudicated (denied, or answered by the person) —
 * refusal reasons are NOT delivered to sponsors (structural 8.3b decision).
 *
 * Read-only: scans ONLY projects where the caller's membership is REAL
 * (getUserProjectIds — authority-checked), reading each project's members.json
 * directly. No cache writes (proposals are mirrored nowhere by design).
 *
 * Global-scoped: the caller's memberships span projects, and the entries
 * returned are exclusively ones the caller authored or sponsors.
 *
 * PRIVACY (C8 8.0b): proposed users are {user_id, name} public references —
 * the PRIVATE username never appears. Project names are SITE_NAMEs: the
 * caller is a member of every project listed, so the display name is theirs
 * to see.
 *
 * @method GET
 * @route /management/listMyProposals
 * @auth required (membership.self — any authenticated user)
 *
 * @return ApiResponse proposals[] + proposal_count
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
function __command_listMyProposals(array $params = [], array $urlParams = []): ApiResponse {
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $usersCfg = loadUsersConfig();
    $proposals = [];

    foreach (getUserProjectIds($user) as $projectId) {
        $authority = loadProjectMembers($projectId);
        foreach (($authority['invitations'] ?? []) as $targetId => $inv) {
            if (!is_array($inv)) {
                continue;
            }
            $targetId = (string)$targetId;
            if ($targetId === $userId) {
                continue; // my own ask is listMyInvitations' business, never a proposal
            }
            $direction = $inv['direction'] ?? 'invite';

            if ($direction === 'request' && (($inv['by'] ?? null) === $userId)) {
                $status = 'pending_validation'; // awaiting approve/denyJoinRequest
            } elseif ($direction === 'invite' && (($inv['sponsor'] ?? null) === $userId)) {
                $status = 'awaiting_answer'; // approved → invitation, person hasn't answered
            } else {
                continue;
            }

            $row = [
                'project'      => $projectId,
                'project_name' => qs_project_site_name($projectId),
                'user'         => qs_public_user_ref($targetId, $usersCfg),
                'role'         => $inv['role'] ?? null,
                'status'       => $status,
                'at'           => $inv['at'] ?? null,
            ];
            if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                $row['note'] = $inv['note'];
            }
            $proposals[] = $row;
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Proposals listed successfully')
        ->withData([
            'proposals'      => $proposals,
            'proposal_count' => count($proposals),
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listMyProposals($trimParams->params(), $trimParams->additionalParams())->send();
}
