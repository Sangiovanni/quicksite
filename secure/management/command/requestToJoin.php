<?php
/**
 * requestToJoin Command (C8 8.3b)
 *
 * The self-service "knock": an authenticated non-member asks to join a
 * project. The ask lands in the project's members.json `invitations` block as
 * `direction:'request'` with `by` = the caller (self-authored) — structurally
 * unable to grant anything until an admin/owner approves it
 * (approveJoinRequest). The note is MANDATORY: a request always carries its
 * reason.
 *
 * The requested role is FIXED at 'viewer' (rank-1 floor): petitioners don't
 * set terms — the note says what they want, approval materializes viewer, and
 * changeMemberRole handles upgrades.
 *
 * Enumeration posture (visibility × join_policy):
 *   private+closed  → 404 join.unavailable, IDENTICAL to a nonexistent
 *                     project (no existence oracle — the 8.3a template).
 *   private+open    → 201; the owner OPTED into knockability-by-id (documented
 *                     on setJoinPolicy). The caller's own cache mirror carries
 *                     the PROJECT ID as its display name, never SITE_NAME — a
 *                     knock may confirm existence, not the site's name.
 *   public+closed   → honest 403 join.requests_closed (existence is already
 *                     public via /p/<id>/ serving; a uniform 404 here would be
 *                     theater).
 *   public+open     → 201; mirror carries SITE_NAME (public anyway).
 * Self-knowledge is never an oracle: already-member → 409; my own pending
 * invite/request → 409. A SPONSORED proposal about the caller is NOT
 * self-knowledge (they were never engaged): it is treated as absent — uniform
 * 404 where the lane is closed, and the caller's own explicit ask OVERWRITES
 * it where the lane is open (their consent supersedes the sponsor's vouch).
 *
 * Re-request gate: while a `refused` or `removed` tombstone for this project
 * stands in the caller's own cache, a new request is refused (409
 * request.notice_pending) until dismissProjectNotice — an acknowledgment
 * gate, reading only the caller's own data.
 *
 * @method POST
 * @route /management/requestToJoin
 * @auth required (membership.self — any authenticated user)
 *
 * @param string $project Project id to knock on (required)
 * @param string $note    Why you want to join (required, control-stripped, 500 chars)
 *
 * @return ApiResponse Request summary
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
function __command_requestToJoin(array $params = [], array $urlParams = []): ApiResponse {
    $project = trim((string)($params['project'] ?? ''));
    $note    = qs_clean_note($params['note'] ?? null);

    if ($project === '' || $note === null) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('project and note are required — a join request always carries its reason')
            ->withErrors([
                'project' => $project === '' ? 'Required field' : null,
                'note'    => $note === null ? 'Required field' : null,
            ]);
    }
    // F1 — the value becomes a directory selector downstream.
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

    // Acknowledgment gate (own cache only — no oracle): a standing refused/
    // removed tombstone for this project must be dismissed before re-asking.
    $ownEntry = $user['projects'][$project] ?? null;
    $ownStatus = is_array($ownEntry) ? ($ownEntry['status'] ?? 'member') : null;
    if ($ownStatus === 'refused' || $ownStatus === 'removed') {
        return ApiResponse::create(409, 'request.notice_pending')
            ->withMessage('A notice for this project is still in your inbox — dismiss it (dismissProjectNotice) before requesting again');
    }

    $today = date('Y-m-d');
    $error = null;
    $failure = null;
    $visibility = 'private';
    $written = qs_members_mutate($project, function (array &$m) use ($userId, $note, $today, &$error, &$visibility) {
        $visibility = $m['visibility'] ?? 'private';

        // Self-knowledge branches (each reveals only what the caller's own
        // membership/inbox already shows).
        if (isset($m['members'][$userId])) {
            $error = 'member.already_exists';
            return false;
        }
        $inv = $m['invitations'][$userId] ?? null;
        if (is_array($inv)) {
            $direction = $inv['direction'] ?? 'invite';
            if ($direction === 'invite') {
                $error = 'invitation.already_pending';
                return false;
            }
            if (($inv['by'] ?? null) === $userId) {
                $error = 'request.already_pending';
                return false;
            }
            // Sponsored proposal about the caller: NOT self-knowledge — fall
            // through to the lane gate as if absent; an explicit self-ask
            // overwrites it where the lane is open.
        }

        // The lane gate (in-lock — a concurrent setJoinPolicy cannot be outrun).
        $policy = $m['join_policy'] ?? 'closed';
        if ($policy !== 'open') {
            $error = ($visibility === 'public') ? 'requests_closed' : 'unavailable';
            return false;
        }

        if (!isset($m['invitations']) || !is_array($m['invitations'])) {
            $m['invitations'] = [];
        }
        $m['invitations'][$userId] = [
            'role'      => 'viewer',
            'direction' => 'request',
            'by'        => $userId,
            'at'        => $today,
            'note'      => $note,
        ];
        return true;
    }, $failure);

    // Uniform not-found: a nonexistent project answers EXACTLY like a private
    // project whose request lane is closed — no existence oracle.
    if ($failure === 'missing' || $failure === 'invalid_project' || $error === 'unavailable') {
        return ApiResponse::create(404, 'join.unavailable')
            ->withMessage('This project does not accept join requests');
    }
    if ($error === 'requests_closed') {
        return ApiResponse::create(403, 'join.requests_closed')
            ->withMessage('This project does not currently accept join requests');
    }
    if ($error === 'member.already_exists') {
        return ApiResponse::create(409, 'member.already_exists')
            ->withMessage('You are already a member of this project');
    }
    if ($error === 'invitation.already_pending') {
        return ApiResponse::create(409, 'invitation.already_pending')
            ->withMessage('You already have a pending invitation for this project — accept or decline it instead');
    }
    if ($error === 'request.already_pending') {
        return ApiResponse::create(409, 'request.already_pending')
            ->withMessage('You already have a pending join request for this project — withdraw it to re-ask');
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    // Status mirror (secondary write, silent-failure by ruling). Name privacy:
    // SITE_NAME only when the project is public; a private project's mirror
    // carries the id the caller already typed.
    $mirror = [
        'name'   => ($visibility === 'public') ? qs_project_site_name($project) : $project,
        'status' => 'pending_request',
        'at'     => $today,
    ];
    if (!qs_membership_cache_set($userId, $project, $mirror)) {
        error_log("requestToJoin: cache mirror write failed for '{$userId}' on '{$project}'");
    }

    return ApiResponse::create(201, 'resource.created')
        ->withMessage('Join request sent — a project admin or the owner will answer it')
        ->withData([
            'project'   => $project,
            'role'      => 'viewer',
            'requested' => true,
            'at'        => $today,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_requestToJoin($trimParams->params(), $trimParams->additionalParams())->send();
}
