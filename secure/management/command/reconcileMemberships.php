<?php
/**
 * reconcileMemberships Command
 *
 * Heals every user's users.php `projects` status-mirror cache for THIS project
 * against the project's AUTHORITATIVE members.json. The cache is only a
 * mirror — members.json is the sole grant authority — but a drifted cache pollutes
 * the editing picker, the memberships page, and the inbox. Drift comes from a
 * members.json hand-edit, a failed cascade cache-write (deleteProject/removeMember
 * log-and-continue), or legacy entries with no status.
 *
 * Category: project.members (admin + owner) — the same tier that manages the
 * roster; it writes OTHER members' cache entries (only their key for THIS project).
 * The operator's reconcile-memberships tool (secure/tools/) runs the same reconcile
 * (qs_membership_reconcile_project()) for every project at once.
 *
 * MERGE-PRESERVE rule (the non-negotiable): `member`, `pending_invite` and
 * `pending_request` are DERIVABLE from members.json and are rebuilt from it; but
 * `refused`, `removed` and `deleted` are TOMBSTONES that live ONLY in the user's
 * cache (members.json keeps no record of a refusal, a kick, or a dead project).
 * A naive rebuild would erase them — reconcile PRESERVES any tombstone for a user
 * the authority no longer lists, and prunes only STALE POSITIVES (a cache claiming
 * member/pending that the authority contradicts). The approved notice on a member
 * entry is the same kind of record: kept while the authority lists the user as a
 * member, never added to an entry that lacks it, and pruned with an entry the
 * authority contradicts.
 *
 * @method POST
 * @route /management/p/<projectId>/reconcileMemberships
 * @auth required (project.members — admin, owner)
 *
 * @return ApiResponse Reconciliation counts
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
function __command_reconcileMemberships(array $params = [], array $urlParams = []): ApiResponse {
    // Project containment: marker-only targeting (authority is THIS project's members.json).
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/reconcileMemberships');
    }
    $project = PROJECT_NAME;

    $actor = getCurrentUser();
    if (($actor['id'] ?? null) === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    // The reconcile itself is shared with the operator's reconcile-memberships tool
    // (qs_membership_reconcile_project()). Its AUTHORITY is members.json: when that is
    // missing or unreadable it refuses rather than guess — reconcile must never invent
    // memberships or, worse, prune real ones off a failed read. A well-formed
    // members.json always has a members map that is not empty AND an owner (the
    // invariant backstop guarantees exactly one), so anything else means the
    // authority is unreadable.
    //
    // The DESIRED cache status per user comes from the roster
    // (qs_membership_desired_statuses()): member, then the engaged pending states. A
    // SPONSORED proposal target is NOT engaged: it gets NO mirror, and is treated like
    // any other user the roster does not list (notice kept, stale entry pruned). An
    // entry carries no project name (qs_membership_mirror_entry): the inbox reads it
    // when it lists, with the private-project rule.
    $result = qs_membership_reconcile_project($project);
    if (!$result['ok'] && $result['error'] === 'members.unreadable') {
        return ApiResponse::create(500, 'members.unreadable')
            ->withMessage('Project membership authority is missing or unreadable — reconcile aborted');
    }
    if (!$result['ok']) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write reconciled membership cache');
    }
    $counts = $result['counts'];

    $counts['total_changes'] = $counts['added'] + $counts['healed'] + $counts['pruned_stale'];

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Reconciled membership cache for '{$project}' against members.json")
        ->withData([
            'project' => $project,
            'counts'  => $counts,
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_reconcileMemberships($trimParams->params(), $trimParams->additionalParams())->send();
}
