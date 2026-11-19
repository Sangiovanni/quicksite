<?php
/**
 * reconcile-memberships.php — brings every account's copy of its memberships
 * (its `projects` in users.php) back to what every project's roster says, and
 * marks an account's entry for a project that no longer exists as deleted. One
 * of the deployer's tools in this folder (see its README.md); the launcher,
 * tools.php, lists it by the three lines below.
 *
 * @qs-tool   reconcile-memberships
 * @qs-line   Reconcile every account's memberships against every project's roster
 * @qs-usage  [--dry-run] [--quiet]
 *
 *   php secure/tools/reconcile-memberships.php              reconcile now
 *   php secure/tools/reconcile-memberships.php --dry-run    list what would change; write nothing
 *   php secure/tools/reconcile-memberships.php --quiet      exit code only (for cron)
 *
 * WHAT IT DOES (AuthManagement.php holds each rule):
 *   - for each project, the reconcileMemberships command's own reconcile
 *     (qs_membership_reconcile_project()): an account the roster lists as a
 *     member, invited or asking gets that entry, an entry the roster contradicts
 *     is removed, a notice (refused, removed, deleted) is kept. A project whose
 *     roster cannot be read is not reconciled, and is listed;
 *   - an account's member or pending entry for a project whose folder is gone
 *     becomes the `deleted` notice, as deleteProject's cascade marks it
 *     (qs_membership_mark_vanished()), the project's id standing for its name.
 * Each project is one users.php write, made only when something differs, under
 * the lock every writer of users.php takes. No roster is ever written.
 *
 * WHY THIS IS A SCRIPT AND NOT A COMMAND: the command reconciles one project for
 * the people who manage it; every project at once is the installation's, which
 * no project role could authorise.
 *
 * Exit codes: 0 = it ran; 1 = it refused to run; 2 = a roster could not be read
 * or a write failed, each listed (lib/cli.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/lib/cli.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php'; // is_valid_project_name()

$opts = qs_tool_options($argv ?? [], [
    '--dry-run' => 'flag',
    '--quiet'   => 'flag',
], "QuickSite — reconcile memberships\n\n"
    . '  php ' . basename(__FILE__) . "              reconcile now\n"
    . '  php ' . basename(__FILE__) . " --dry-run    list what would change; write nothing\n"
    . '  php ' . basename(__FILE__) . " --quiet      exit code only (for cron)\n\n"
    . "Brings each account's copy of its memberships back to what every project's\n"
    . "roster says (the reconcileMemberships command, for every project), and marks\n"
    . "an account's entry for a project that no longer exists as deleted. Never\n"
    . "writes a roster, nor reconciles against one it cannot read.\n"
    . "Exit code: 0 ran, 1 refused to run, 2 left something that needs a look.\n");

$dryRun = $opts['dry-run'];
$report = new QsToolReport('projects', false);
$today = date('Y-m-d');

$projects = qs_tool_store('projects', $why);
if ($projects === null && $why !== 'absent') {
    $report->add('projects', ['state' => 'link', 'why' => $why === 'link' ? 'a link leading out of the engine folder: left alone' : 'not a folder', 'bytes' => 0]);
}
if ($projects !== null) {
    foreach (qs_tool_names($projects) as $name) {
        if (!is_valid_project_name($name) || !is_dir($projects . DIRECTORY_SEPARATOR . $name)) {
            continue;
        }
        $report->examined();
        // Read first: a project with nothing to change costs no write.
        $seen = qs_membership_reconcile_project($name, true, $today);
        if (!$seen['ok']) {
            $report->add('projects/' . $name . '/config/members.json', ['state' => 'unreadable', 'why' => 'its roster is missing or unreadable: this project was not reconciled', 'bytes' => 0]);
            continue;
        }
        $result = ($seen['changes'] === [] || $dryRun) ? $seen : qs_membership_reconcile_project($name, false, $today);
        if (!$result['ok']) {
            $report->add('projects/' . $name, ['state' => $result['error'] === 'members.unreadable' ? 'unreadable' : 'failed',
                'why' => $result['error'] === 'members.unreadable' ? 'its roster could no longer be read' : 'users.php could not be written', 'bytes' => 0]);
            continue;
        }
        rm_changes($report, $result['changes']);
        if ($result['changes'] === []) {
            $report->keep('already in agreement');
        }
    }
}

$vanished = qs_membership_mark_vanished($dryRun, $today);
rm_changes($report, $vanished['changes']);
if (!$vanished['ok']) {
    $report->add('management/config/users.php', ['state' => 'failed', 'why' => 'the deleted notices could not be written', 'bytes' => 0]);
}

if (!$opts['quiet']) {
    $report->print('membership reconcile', $dryRun, ['change' => ['changed', 'would change']]);
}
exit($report->exitCode());

/**
 * One report line per account entry changed: who, which project, its status from
 * what to what, and, when the status stays, the fields of the entry that change.
 */
function rm_changes(QsToolReport $report, array $changes): void {
    foreach ($changes as $c) {
        $why = ($c['from'] ?? 'no entry') . ' → ' . ($c['to'] ?? 'no entry');
        if ($c['from'] !== null && $c['from'] === $c['to']) {
            $before = is_array($c['before']) ? $c['before'] : [];
            $after = is_array($c['after']) ? $c['after'] : [];
            $fields = [];
            foreach (array_keys($before + $after) as $key) {
                if (!array_key_exists($key, $after)) {
                    $fields[] = $key . ' dropped';
                } elseif (!array_key_exists($key, $before)) {
                    $fields[] = $key . ' added';
                } elseif ($before[$key] !== $after[$key]) {
                    $fields[] = $key . ' changed';
                }
            }
            $why .= $fields ? ', its ' . implode(', ', $fields) : ', its entry rewritten';
        }
        $report->add('account ' . $c['account'] . ' · project ' . $c['project'], ['state' => 'change', 'why' => $why, 'bytes' => 0]);
    }
}
