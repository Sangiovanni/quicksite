<?php
/**
 * FIRST RUN — what has to happen the moment an install's very first account
 * comes into being, and can only happen then.
 *
 * WHY THIS IS NOT SETUP'S JOB. `setup.sh` / `setup.bat` run before any account
 * exists, so neither of the two things here has a subject yet: a project cannot
 * be owned by nobody, and an operator list cannot name nobody. Both are written
 * at the single moment the registry stops being empty — the first-run gate
 * (qs_auth_attempt_setup), which is reached exactly once per install.
 *
 * WHAT IT WRITES.
 *
 *   1. A members.json for every SHIPPED project that has none. QuickSite ships a
 *      starter project, and `qs_project_birth_write_members()` only runs at
 *      project CREATION — a project that came with the download never passes
 *      through it. Without a roster `loadProjectMembers()` reads it as
 *      private-with-no-members, so it is invisible and uneditable to everyone,
 *      including the person who just installed it. This is what makes the
 *      starter project actually usable.
 *
 *   2. `secure/management/config/operator.php`, naming that same account.
 *      DISPLAY PREFERENCE, NOT AUTHORIZATION — see the file's own header and
 *      qs_operator_ids() below.
 *
 * ADOPTION IS BY ABSENCE, NOT BY NAME. Any project directory without a roster is
 * adopted, rather than a hardcoded 'quicksite'. The starter's id is not a
 * contract, someone may drop a second project in before first run, and — the
 * load-bearing part — a project with no roster is reachable by NOBODY, so
 * adopting it can never take anything away from anyone. The registry was empty
 * one instruction ago, so there is no other account with a claim.
 *
 * FAILURE IS REPORTED, NEVER FATAL. The account exists by the time this runs and
 * must stay usable: a project that could not be adopted leaves the deployer with
 * a panel they can sign into and fix from, which is strictly better than an
 * account creation that rolls back because a directory was read-only.
 */

require_once __DIR__ . '/AuthManagement.php';

/**
 * Path of the operator list. Gitignored, with a tracked `.example` sibling —
 * the same shape as environment.php and deploy-roots.php: operator-controlled
 * files the runtime reads and no command writes.
 */
function qs_operator_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/operator.php';
}

/**
 * The user ids that see operator notices.
 *
 * THIS GRANTS NOTHING. It decides whether a notice RENDERS, and nothing else —
 * every command keeps the permissions it already had, and no code path may use
 * this list to authorize an action. That is what keeps it a display preference
 * instead of the installation-wide principal beta.10 deliberately removed.
 *
 * DEFAULT-ON-ABSENT: a missing, unreadable or malformed file reads as "nobody",
 * never as "everybody". An install QuickSite created always has this file (first
 * run writes it), so absence means either an install that predates the file — a
 * one-line fix its operator can make from a shell they demonstrably have — or an
 * empty list somebody chose. Guessing "everybody" for either would hand the
 * engine version to every account invited to edit one project's text.
 *
 * @return string[] user ids, in the order the file lists them
 */
function qs_operator_ids(): array {
    $path = qs_operator_path();
    if (!is_file($path)) {
        return [];
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }
    $cfg = @include $path;
    if (!is_array($cfg) || !isset($cfg['operators']) || !is_array($cfg['operators'])) {
        return [];
    }
    $ids = [];
    foreach ($cfg['operators'] as $id) {
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Write the operator list naming exactly $userId.
 *
 * CREATE-IF-ABSENT. An existing file is left alone: it is operator-controlled,
 * and first run is the only caller, so a file already there means somebody put
 * it there deliberately (a re-bootstrap after deleting users.php, say) and their
 * choice outranks ours.
 */
function qs_first_run_write_operator(string $userId): bool {
    $path = qs_operator_path();
    if (is_file($path)) {
        return true;
    }
    if ($userId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $userId) !== 1) {
        // The id is minted by qs_user_create() as 'usr_' + hex, so this cannot
        // trigger today. Checked anyway: the value is interpolated into a PHP
        // literal, and "cannot happen" is not a property this file can assert
        // about a caller it does not own.
        return false;
    }
    $content = <<<PHP
<?php
/**
 * Operator notices — WHO SEES THEM. Written by QuickSite at first run, naming
 * the account that created the install. Edit it by hand to add or remove ids;
 * no command writes this file.
 *
 * IT GRANTS NOTHING. See operator.php.example for the full reasoning.
 */

return [
    'operators' => [
        '{$userId}',
    ],
];

PHP;
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

/**
 * The shipped projects that have no members.json, sorted for a deterministic
 * order (the first one becomes the account's selected project).
 *
 * @return string[] project ids
 */
function qs_first_run_unowned_projects(): array {
    $projectsDir = SECURE_FOLDER_PATH . '/projects';
    if (!is_dir($projectsDir)) {
        return [];
    }
    $dirs = glob($projectsDir . '/*', GLOB_ONLYDIR);
    if ($dirs === false) {
        return [];
    }
    $ids = [];
    foreach ($dirs as $dir) {
        $id = basename($dir);
        // Same id shape the /p/ surface enforces — a directory it could never
        // serve is not a project, whatever else it may be.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            continue;
        }
        if (is_file($dir . '/config/members.json')) {
            continue; // already has a roster — never re-home somebody else's project
        }
        $ids[] = $id;
    }
    sort($ids);
    return $ids;
}

/**
 * Make $userId the owner of every roster-less project, and mirror that into
 * their users.php project cache exactly as createProject does.
 *
 * The roster is written FIRST and the cache second, in that order deliberately:
 * members.json is authoritative (L5) and the cache is derived, so a crash
 * between them leaves a project that works and a picker entry that is missing —
 * recoverable — rather than a picker entry pointing at a project nobody owns.
 *
 * @return array{adopted: string[], failed: string[], selected: ?string, cached: bool}
 */
function qs_first_run_adopt_projects(string $userId): array {
    $adopted = [];
    $failed  = [];
    foreach (qs_first_run_unowned_projects() as $id) {
        $path = SECURE_FOLDER_PATH . '/projects/' . $id;
        if (qs_project_birth_write_members($path, $userId)) {
            $adopted[] = $id;
        } else {
            $failed[] = $id;
            error_log('qs_first_run_adopt_projects: could not write a roster for project '
                . $id . ' — it will not be visible to the account that installed QuickSite');
        }
    }

    // The starter project leads when it is there, so a default install opens on
    // the site it shipped with rather than on whatever sorts first.
    $selected = null;
    if (in_array('quicksite', $adopted, true)) {
        $selected = 'quicksite';
    } elseif ($adopted !== []) {
        $selected = $adopted[0];
    }

    $cached = false;
    if ($adopted !== []) {
        $today = date('Y-m-d');
        $names = [];
        foreach ($adopted as $id) {
            $names[$id] = qs_project_site_name($id);
        }
        $cached = qs_users_mutate(function (array &$cfg) use ($userId, $adopted, $names, $selected, $today) {
            if (!isset($cfg['users'][$userId])) {
                return false;
            }
            foreach ($adopted as $id) {
                // Cache only — no role key. The role is authoritative in
                // members.json (L5/C5), exactly as createProject leaves it.
                $cfg['users'][$userId]['projects'][$id] = [
                    'name'    => $names[$id],
                    'created' => $today,
                ];
            }
            if ($selected !== null) {
                $cfg['users'][$userId]['selected_project'] = $selected;
            }
            return true;
        }) === true;
    }

    return [
        'adopted'  => $adopted,
        'failed'   => $failed,
        'selected' => $selected,
        'cached'   => $cached,
    ];
}

/**
 * Everything first run owes the account that just created the install.
 *
 * @return array{adopted: string[], failed: string[], selected: ?string,
 *               cached: bool, operator: bool}
 */
function qs_first_run_bootstrap(string $userId): array {
    $result = qs_first_run_adopt_projects($userId);
    $result['operator'] = qs_first_run_write_operator($userId);
    if (!$result['operator']) {
        error_log('qs_first_run_bootstrap: could not write ' . qs_operator_path()
            . ' — operator notices will name nobody until it is created by hand');
    }
    return $result;
}
