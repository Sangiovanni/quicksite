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

    // AUTHORITY snapshot: members.json. If it is missing/unreadable we refuse
    // rather than guess — reconcile must never invent memberships or, worse,
    // prune real ones off a failed read. loadProjectMembers returns ['members'=>[]]
    // for a missing/corrupt file; a WELL-FORMED members.json always has a members
    // map that is not empty AND an owner (the invariant backstop guarantees exactly
    // one), so anything else means the authority is unreadable — abort (pruning
    // every real membership off a failed read would be the bug).
    $members = loadProjectMembers($project);
    if (!qs_membership_roster_readable($members)) {
        return ApiResponse::create(500, 'members.unreadable')
            ->withMessage('Project membership authority is missing or unreadable — reconcile aborted');
    }

    $today = date('Y-m-d');

    // The DESIRED cache status per user from authority (qs_membership_desired_statuses()):
    // member, then the engaged pending states. A SPONSORED proposal target is NOT
    // engaged — it gets NO mirror, so it is absent from $desired and treated like any
    // other non-listed user (tombstone-preserve or stale-prune). An entry carries no
    // project name (qs_membership_mirror_entry): the inbox reads it when it lists,
    // with the private-project rule.
    $desired = qs_membership_desired_statuses($members);

    $counts = [
        'members'               => 0,
        'pending_invites'       => 0,
        'pending_requests'      => 0,
        'added'                 => 0,
        'healed'                => 0,
        'pruned_stale'          => 0,
        'preserved_tombstones'  => 0,
    ];

    $written = qs_users_mutate(function (array &$cfg) use ($project, $desired, $today, &$counts) {
        foreach ($cfg['users'] as $uid => &$u) {
            $uid = (string)$uid;
            $has = isset($u['projects'][$project]);
            $current = $has ? $u['projects'][$project] : null;
            // The one rule (qs_membership_reconciled_entry()): an engaged status gets
            // its mirror entry with the entry's informational date kept (`created`,
            // `at`) and any name an older entry carried dropped; with no status a
            // tombstone is preserved and a stale positive is pruned (null).
            $want = qs_membership_reconciled_entry($current, $desired[$uid] ?? null, $today);

            if (isset($desired[$uid])) {
                // Authority lists this user in an engaged state — the cache must
                // match it (heal the status; add if missing).
                if ($current !== $want) {
                    if (!$has) {
                        $counts['added']++;
                    } else {
                        $counts['healed']++;
                    }
                    $u['projects'][$project] = $want;
                }
                if ($want['status'] === 'member')          { $counts['members']++; }
                elseif ($want['status'] === 'pending_invite') { $counts['pending_invites']++; }
                elseif ($want['status'] === 'pending_request'){ $counts['pending_requests']++; }
                continue;
            }

            // Authority does NOT list this user for this project.
            if (!$has) {
                continue; // nothing to do
            }
            if ($want !== null) {
                // Non-derivable terminal notice — PRESERVE (the merge rule).
                $counts['preserved_tombstones']++;
            } else {
                // Stale positive (member/pending_* the authority contradicts) — prune.
                unset($u['projects'][$project]);
                $counts['pruned_stale']++;
            }
        }
        unset($u);
        return true;
    });

    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write reconciled membership cache');
    }

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
