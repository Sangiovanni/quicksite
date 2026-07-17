<?php
/**
 * listMyInvitations Command (C8 8.3a)
 *
 * The caller's membership INBOX: pending invitations + terminal project
 * notices (refused / removed / deleted — the entries dismissProjectNotice
 * clears). Reads the caller's OWN users.php cache (cheap — no all-projects
 * scan), then hydrates each pending invitation from that project's
 * AUTHORITATIVE members.json: a stale mirror can never misrepresent an offer,
 * and a dangling pending entry (offer withdrawn while a mirror write failed)
 * is silently pruned — drift self-corrects toward authority.
 *
 * Global-scoped: an invitee is NOT a member, so the '/p/<id>/' marker gate
 * would 403 them — self-service commands act only on the caller's own state.
 *
 * @method GET
 * @route /management/listMyInvitations
 * @auth required (membership.self — any authenticated user)
 *
 * @return ApiResponse invitations[] + notices[]
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
function __command_listMyInvitations(array $params = [], array $urlParams = []): ApiResponse {
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $usersCfg = loadUsersConfig();
    $invitations = [];
    $notices = [];
    $prune = [];

    foreach (($user['projects'] ?? []) as $projectId => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $projectId = (string)$projectId;
        $status = $entry['status'] ?? 'member';

        if ($status === 'pending_invite') {
            // Defensive F1 guard before the path read (cache keys are
            // server-written, but this file is hand-editable).
            if ($projectId === '' || strpbrk($projectId, "/\\") !== false || strpos($projectId, '..') !== false) {
                continue;
            }
            $path = SECURE_FOLDER_PATH . '/projects/' . $projectId . '/config/members.json';
            if (!is_file($path)) {
                $prune[] = $projectId; // project gone → the offer died with it
                continue;
            }
            $authority = json_decode((string)@file_get_contents($path), true);
            if (!is_array($authority)) {
                continue; // unreadable file: leave the mirror alone, list nothing
            }
            $inv = $authority['invitations'][$userId] ?? null;
            if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'invite')) {
                $prune[] = $projectId; // withdrawn (or not an invite) → self-heal
                continue;
            }
            $row = [
                'project'      => $projectId,
                'project_name' => $entry['name'] ?? $projectId,
                'role'         => $inv['role'] ?? null,
                'invited_by'   => isset($inv['by']) ? qs_public_user_ref((string)$inv['by'], $usersCfg) : null,
                'at'           => $inv['at'] ?? ($entry['at'] ?? null),
            ];
            if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                $row['note'] = $inv['note'];
            }
            $invitations[] = $row;
        } elseif (in_array($status, ['refused', 'removed', 'deleted'], true)) {
            $row = [
                'project'      => $projectId,
                'project_name' => $entry['name'] ?? $projectId,
                'status'       => $status,
                'at'           => $entry['at'] ?? null,
            ];
            if (isset($entry['note']) && is_string($entry['note']) && $entry['note'] !== '') {
                $row['note'] = $entry['note'];
            }
            $notices[] = $row;
        }
        // 'member' entries are not inbox items; 'pending_request' = 8.3b.
    }

    // Self-heal: drop dangling pending entries in ONE cache write. Secondary
    // write — silent on failure (ruled), the next listing simply retries.
    if ($prune !== []) {
        $healed = qs_users_mutate(function (array &$cfg) use ($userId, $prune) {
            if (!isset($cfg['users'][$userId])) {
                return false;
            }
            foreach ($prune as $projectId) {
                unset($cfg['users'][$userId]['projects'][$projectId]);
            }
            return true;
        });
        if ($healed !== true) {
            error_log("listMyInvitations: self-heal prune failed for '{$userId}'");
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitations listed successfully')
        ->withData([
            'invitations'      => $invitations,
            'notices'          => $notices,
            'invitation_count' => count($invitations),
            'notice_count'     => count($notices),
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listMyInvitations($trimParams->params(), $trimParams->additionalParams())->send();
}
