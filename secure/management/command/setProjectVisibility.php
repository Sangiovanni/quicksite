<?php
/**
 * setProjectVisibility Command (C8 8.4)
 *
 * Flips the project's `visibility` in members.json — the knob surface-B reads to
 * decide whether the project is served to the PUBLIC internet ('public') or only
 * to authenticated members ('private', the createProject default). This is the
 * gravest exposure decision a project carries (public = anyone on the internet
 * can read the site), so it is OWNER-ONLY — the same tier as deleteProject and
 * transferOwnership, NOT the admin-tier project.settings that setJoinPolicy uses.
 *
 * Category: project.visibility (owner only). An admin who runs the project
 * day-to-day cannot unilaterally expose it to the world; that stays with the
 * owner.
 *
 * Posture note: making a project PRIVATE while its join_policy is 'open' re-creates
 * the "knockable private team" state (any authenticated account that knows the id
 * can send a join request — and thereby confirm the project exists). The response
 * carries an advisory note when the RESULT is private+open, symmetric with
 * setJoinPolicy. Flipping to public dissolves that concern (existence is public by
 * design). A visibility change never purges the pending request queue.
 *
 * @method POST
 * @route /management/p/<projectId>/setProjectVisibility
 * @auth required (project.visibility — owner)
 *
 * @param string $visibility 'private' | 'public' (required)
 *
 * @return ApiResponse Visibility state
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
function __command_setProjectVisibility(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting (the project is the authorized URL
    // marker; there is no body project param to confuse it with).
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/setProjectVisibility');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $visibility = trim((string)($params['visibility'] ?? ''));
    if ($visibility === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('visibility is required')
            ->withErrors(['visibility' => "Required field: 'private' or 'public'"]);
    }
    if (!in_array($visibility, ['private', 'public'], true)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("visibility must be 'private' or 'public'")
            ->withErrors(['visibility' => "Must be 'private' or 'public'"]);
    }

    $error = null;
    $failure = null;
    $previous = 'private';
    $joinPolicy = 'closed';
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $visibility, &$error, &$previous, &$joinPolicy) {
        $previous   = $m['visibility'] ?? 'private';
        $joinPolicy = $m['join_policy'] ?? 'closed';
        // This command writes the trust file — re-verify authority against the
        // fresh in-lock state (membership-command discipline). OWNER only: a
        // concurrently-transferred owner cannot flip visibility on the way out.
        $actorRole = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole !== 'owner') {
            $error = 'authz.insufficient_rank';
            return false;
        }
        if ($previous === $visibility) {
            $error = 'no_change';
            return false; // nothing to write
        }
        $m['visibility'] = $visibility;
        return true;
    }, $failure);

    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('Only the project owner may change visibility');
    }

    $data = [
        'project'    => $project,
        'visibility' => $visibility,
        'previous'   => $previous,
        'changed'    => ($error !== 'no_change'),
    ];
    if ($visibility === 'private' && $joinPolicy === 'open') {
        $data['note'] = 'This project is private with an OPEN join policy: any authenticated account that knows the project id can send a request — and thereby learn the project exists. Close the join policy to make it indistinguishable from nonexistent.';
    }

    if ($error === 'no_change') {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage("Visibility already '{$visibility}'")
            ->withData($data);
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Visibility set to '{$visibility}'")
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setProjectVisibility($trimParams->params(), $trimParams->additionalParams())->send();
}
