<?php
/**
 * denyJoinRequest Command (C8 8.3b)
 *
 * Authority's "no" on a `direction:'request'` entry. The note is MANDATORY —
 * a refusal always carries its reason (locked R3).
 *
 * Rank rule — identical to approveJoinRequest and cancelInvitation's
 * no-carve-out discipline: the denier must outrank the requested role
 * (canManageRole). Nobody may veto what they could not grant — an admin
 * cannot kill a proposal-for-admin before the owner sees it.
 *
 * Tombstones follow the R4 principle (other-initiated termination):
 *   - SELF-REQUEST: the requester asked and gets answered — a dismissable
 *     `refused` notice (with the reason) lands in THEIR cache. Its display
 *     name inherits the privacy-correct name from their pending entry
 *     (project id for a private project — never SITE_NAME).
 *   - SPONSORED PROPOSAL: the target was NEVER ENGAGED (no mirror, no inbox
 *     row) — denying it must not notify them of anything, a fortiori "must
 *     never read as refusal": they never read anything at all. The entry is
 *     simply removed. Known structural gap: the SPONSOR gets no denial notice
 *     (their own cache key for the project holds their membership entry);
 *     the reason still lands in this response + command history.
 *
 * @method POST
 * @route /management/p/<projectId>/denyJoinRequest
 * @auth required (project.members — admin, owner)
 *
 * @param string $user_id Requester/proposed account's user id (required)
 * @param string $note    Why the request is refused (required, 500 chars)
 *
 * @return ApiResponse Denial result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_denyJoinRequest(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/denyJoinRequest');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $targetId = trim((string)($params['user_id'] ?? ''));
    $note     = qs_clean_note($params['note'] ?? null);
    if ($targetId === '' || $note === null) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id and note are required — a refusal always carries its reason')
            ->withErrors([
                'user_id' => $targetId === '' ? 'Required field' : null,
                'note'    => $note === null ? 'Required field' : null,
            ]);
    }

    $today = date('Y-m-d');
    $error = null;
    $failure = null;
    $wasSelf = false;
    $visibility = 'private';
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $targetId, &$error, &$wasSelf, &$visibility) {
        $visibility = $m['visibility'] ?? 'private';
        $inv = $m['invitations'][$targetId] ?? null;
        if (!is_array($inv) || (($inv['direction'] ?? 'invite') !== 'request')) {
            $error = 'request.not_found';
            return false;
        }
        $actorRole = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole === null || !canManageRole($actorRole, (string)($inv['role'] ?? ''))) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        $wasSelf = (($inv['by'] ?? null) === $targetId);
        unset($m['invitations'][$targetId]);
        return true;
    }, $failure);

    if ($error === 'request.not_found') {
        return ApiResponse::create(404, 'request.not_found')
            ->withMessage('No pending join request or proposal for this user');
    }
    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('You can only deny requests for roles of strictly lower rank than your own');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    if ($wasSelf) {
        // Refused tombstone in the requester's cache (dismissable; blocks
        // re-requesting until acknowledged). Name privacy: inherit the
        // pending_request entry's name; fall back to id-or-SITE_NAME by
        // visibility (never SITE_NAME for a private project).
        $existing = loadUsersConfig()['users'][$targetId]['projects'][$project] ?? null;
        $name = (is_array($existing) && is_string($existing['name'] ?? null) && $existing['name'] !== '')
            ? $existing['name']
            : (($visibility === 'public') ? qs_project_site_name($project) : $project);
        $tombstone = ['name' => $name, 'status' => 'refused', 'at' => $today, 'note' => $note];
        if (!qs_membership_cache_set($targetId, $project, $tombstone)) {
            error_log("denyJoinRequest: refused-tombstone write failed for '{$targetId}' on '{$project}'");
        }
    }
    // Sponsored proposal: the target was never engaged — no cache touch.

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Join request denied')
        ->withData([
            'project' => $project,
            'user_id' => $targetId,
            'denied'  => true,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_denyJoinRequest($trimParams->params(), $trimParams->additionalParams())->send();
}
