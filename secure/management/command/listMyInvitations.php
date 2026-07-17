<?php
/**
 * listMyInvitations Command (C8 8.3a)
 *
 * The caller's membership INBOX: pending invitations, the caller's own join
 * requests (8.3b), and terminal project notices (refused / removed / deleted
 * — the entries dismissProjectNotice clears). Reads the caller's OWN
 * users.php cache (cheap — no all-projects scan), then hydrates each pending
 * entry from that project's AUTHORITATIVE members.json: a stale mirror can
 * never misrepresent an offer, and drift self-corrects TOWARD AUTHORITY —
 * a dangling pending entry is pruned; a pending mirror whose authority
 * already shows MEMBERSHIP is upgraded to a member entry (8.3b — restores
 * picker visibility when an approve/accept mirror write was lost); a mirror
 * whose pending KIND disagrees with the authority's direction is healed to
 * the authority's side. Sponsored proposals (`by` != me) are never mine to
 * see: a drifted mirror pointing at one is pruned, and the row is not listed.
 *
 * Global-scoped: an invitee is NOT a member, so the '/p/<id>/' marker gate
 * would 403 them — self-service commands act only on the caller's own state.
 *
 * @method GET
 * @route /management/listMyInvitations
 * @auth required (membership.self — any authenticated user)
 *
 * @return ApiResponse invitations[] + requests[] + notices[]
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
    $requests = [];
    $notices = [];
    $prune = [];
    $heal = []; // projectId => replacement mirror entry (drift → authority)
    $today = date('Y-m-d');

    foreach (($user['projects'] ?? []) as $projectId => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $projectId = (string)$projectId;
        $status = $entry['status'] ?? 'member';

        if ($status === 'pending_invite' || $status === 'pending_request') {
            // Defensive F1 guard before the path read (cache keys are
            // server-written, but this file is hand-editable).
            if ($projectId === '' || strpbrk($projectId, "/\\") !== false || strpos($projectId, '..') !== false) {
                continue;
            }
            $path = SECURE_FOLDER_PATH . '/projects/' . $projectId . '/config/members.json';
            if (!is_file($path)) {
                $prune[] = $projectId; // project gone → the ask died with it
                continue;
            }
            $authority = json_decode((string)@file_get_contents($path), true);
            if (!is_array($authority)) {
                continue; // unreadable file: leave the mirror alone, list nothing
            }

            // Drift heals toward AUTHORITY. Already a member there (approve/
            // accept landed but the mirror write was lost) → upgrade the
            // entry; a membership is not an inbox item.
            if (isset($authority['members'][$userId])) {
                $heal[$projectId] = [
                    'name'    => qs_project_site_name($projectId),
                    'created' => $today,
                    'status'  => 'member',
                ];
                continue;
            }

            $inv = $authority['invitations'][$userId] ?? null;
            $direction = is_array($inv) ? ($inv['direction'] ?? 'invite') : null;

            if ($direction === 'invite') {
                if ($status !== 'pending_invite') {
                    // mirror said request, authority says invite → heal
                    $heal[$projectId] = [
                        'name'   => qs_project_site_name($projectId),
                        'status' => 'pending_invite',
                        'at'     => $inv['at'] ?? $today,
                    ];
                }
                $row = [
                    'project'      => $projectId,
                    'project_name' => ($heal[$projectId]['name'] ?? null) ?? ($entry['name'] ?? $projectId),
                    'role'         => $inv['role'] ?? null,
                    'invited_by'   => isset($inv['by']) ? qs_public_user_ref((string)$inv['by'], $usersCfg) : null,
                    'at'           => $inv['at'] ?? ($entry['at'] ?? null),
                ];
                if (isset($inv['sponsor']) && is_string($inv['sponsor'])) {
                    $row['sponsored_by'] = qs_public_user_ref($inv['sponsor'], $usersCfg);
                }
                if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                    $row['note'] = $inv['note'];
                }
                $invitations[] = $row;
            } elseif ($direction === 'request' && (($inv['by'] ?? null) === $userId)) {
                // my own join request (a sponsored proposal — by != me — is
                // never mine to see: falls to the prune below)
                if ($status !== 'pending_request') {
                    // mirror said invite, authority says self-request → heal
                    // (name privacy: SITE_NAME only on a public project)
                    $heal[$projectId] = [
                        'name'   => (($authority['visibility'] ?? 'private') === 'public')
                            ? qs_project_site_name($projectId) : $projectId,
                        'status' => 'pending_request',
                        'at'     => $inv['at'] ?? $today,
                    ];
                }
                $row = [
                    'project'      => $projectId,
                    'project_name' => ($heal[$projectId]['name'] ?? null) ?? ($entry['name'] ?? $projectId),
                    'role'         => $inv['role'] ?? null,
                    'at'           => $inv['at'] ?? ($entry['at'] ?? null),
                ];
                if (isset($inv['note']) && is_string($inv['note']) && $inv['note'] !== '') {
                    $row['note'] = $inv['note'];
                }
                $requests[] = $row;
            } else {
                $prune[] = $projectId; // withdrawn/denied/not mine → self-heal
            }
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
        // 'member' entries are not inbox items.
    }

    // Self-heal: prunes + drift-heals in ONE cache write. Secondary write —
    // silent on failure (ruled), the next listing simply retries.
    if ($prune !== [] || $heal !== []) {
        $healed = qs_users_mutate(function (array &$cfg) use ($userId, $prune, $heal) {
            if (!isset($cfg['users'][$userId])) {
                return false;
            }
            foreach ($prune as $projectId) {
                unset($cfg['users'][$userId]['projects'][$projectId]);
            }
            foreach ($heal as $projectId => $entry) {
                $cfg['users'][$userId]['projects'][$projectId] = $entry;
            }
            return true;
        });
        if ($healed !== true) {
            error_log("listMyInvitations: self-heal write failed for '{$userId}'");
        }
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Invitations listed successfully')
        ->withData([
            'invitations'      => $invitations,
            'requests'         => $requests,
            'notices'          => $notices,
            'invitation_count' => count($invitations),
            'request_count'    => count($requests),
            'notice_count'     => count($notices),
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listMyInvitations($trimParams->params(), $trimParams->additionalParams())->send();
}
