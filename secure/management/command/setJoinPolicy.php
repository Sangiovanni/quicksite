<?php
/**
 * setJoinPolicy Command (C8 8.3b)
 *
 * Flips the project's `join_policy` in members.json — the knob that opens or
 * closes the SELF-SERVICE request lane (requestToJoin). Default (and absent
 * value) is 'closed'. It gates ONLY the front door: member-vouched proposals
 * (proposeMember) always reach the admin queue, and flipping to 'closed'
 * never purges requests already pending — they stay adjudicable.
 *
 * Category: project.settings (admin + owner). The same ranks that adjudicate
 * the request queue (approve/deny — which can materialize membership) hold
 * the strictly weaker authority of opening/closing that queue.
 *
 * Posture note: on a PRIVATE project, 'open' means any authenticated account
 * that knows the project id can send a request — and thereby confirm the
 * project exists. That is an explicit owner/admin choice (the "knockable
 * private team" trade); the response carries an advisory note when this
 * combination becomes active. 'closed' private projects stay indistinguishable
 * from nonexistent ones on the request lane.
 *
 * @method POST
 * @route /management/p/<projectId>/setJoinPolicy
 * @auth required (project.settings — admin, owner)
 *
 * @param string $policy 'open' | 'closed' (required)
 *
 * @return ApiResponse Policy state
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
function __command_setJoinPolicy(array $params = [], array $urlParams = []): ApiResponse {
    // C8 containment: marker-only targeting.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/setJoinPolicy');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    $actorId = $actor['id'] ?? null;
    if ($actorId === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $policy = trim((string)($params['policy'] ?? ''));
    if ($policy === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('policy is required')
            ->withErrors(['policy' => "Required field: 'open' or 'closed'"]);
    }
    if (!in_array($policy, ['open', 'closed'], true)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("policy must be 'open' or 'closed'")
            ->withErrors(['policy' => "Must be 'open' or 'closed'"]);
    }

    $error = null;
    $failure = null;
    $previous = 'closed';
    $visibility = 'private';
    $written = qs_members_mutate($project, function (array &$m) use ($actorId, $policy, &$error, &$previous, &$visibility) {
        $previous   = $m['join_policy'] ?? 'closed';
        $visibility = $m['visibility'] ?? 'private';
        // This command writes the trust file — re-verify the actor's authority
        // against the fresh in-lock state (the membership-command discipline):
        // still a member, and the role still grants project.settings.
        $actorRole = $m['members'][$actorId]['role'] ?? null;
        if ($actorRole === null
            || ($actorRole !== 'owner'
                && !in_array('project.settings', loadRolesConfig()[$actorRole]['categories'] ?? [], true))) {
            $error = 'authz.insufficient_rank';
            return false;
        }
        if ($previous === $policy) {
            $error = 'no_change';
            return false; // nothing to write
        }
        $m['join_policy'] = $policy;
        return true;
    }, $failure);

    if ($error === 'authz.insufficient_rank') {
        return ApiResponse::create(403, 'authz.insufficient_rank')
            ->withMessage('Only a project admin or the owner may change the join policy');
    }

    $data = [
        'project'     => $project,
        'join_policy' => $policy,
        'previous'    => $previous,
        'changed'     => ($error !== 'no_change'),
    ];
    if ($policy === 'open' && $visibility === 'private') {
        $data['note'] = 'This project is private: an open join policy lets any authenticated account that knows the project id send a request — and thereby learn the project exists.';
    }

    if ($error === 'no_change') {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage("Join policy already '{$policy}'")
            ->withData($data);
    }
    if ($written !== true) {
        [$status, $code, $message] = qs_members_failure_http($failure);
        return ApiResponse::create($status, $code)->withMessage($message);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Join policy set to '{$policy}'")
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setJoinPolicy($trimParams->params(), $trimParams->additionalParams())->send();
}
