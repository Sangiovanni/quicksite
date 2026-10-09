<?php
/**
 * session-sweep.php — the operator's entry to the session store sweep. One of the
 * deployer's tools in this folder (see its README.md).
 *
 *   php secure/tools/session-sweep.php              sweep now
 *   php secure/tools/session-sweep.php --dry-run    report what would go, delete nothing
 *   php secure/tools/session-sweep.php --quiet      exit code only (for cron)
 *
 * WHY THIS IS A SCRIPT AND NOT A COMMAND. Clearing the session store is
 * installation-wide and has no principal to authorize it. QuickSite's
 * permissions are per-project — a role cannot express "sign out everyone on
 * this server" — and there is no installation-wide tier, on purpose,
 * because a global permission plus an account-creation path is an escalation
 * ladder. The credential for an installation-wide action is filesystem access
 * to the server, which is strictly more power than any role could grant. So the
 * gate here is "you can run PHP on this box", enforced by living outside the
 * web root and refusing to run under a web SAPI.
 *
 * It is normally unnecessary: a login sweeps on a 1-in-N die once it has been
 * answered (auth.php `authentication.session.sweep_divisor`), which keeps an
 * install in ordinary use tidy with nothing scheduled. This exists for the cases
 * that die does not cover — an install nobody logs into, a store that grew
 * before the read-mode fix landed, or an operator who simply wants it done now.
 *
 * One run is one pass, and a pass examines at most QS_SESSION_SWEEP_MAX_FILES
 * files. A pass that stops there records how far it got, and the next pass —
 * this script again, or a login's — continues from there. Only one pass runs at
 * a time: when another is already running, this one says so and does nothing.
 *
 * What it deletes and why is documented on qs_session_sweep() in
 * secure/src/functions/SessionManagement.php. In short: empty files, sessions
 * QuickSite's own idle rule already refuses, and non-QuickSite sessions past the
 * longest lifetime this install promises anything. Nothing else.
 *
 * Exit codes: 0 = swept (or nothing to do), 1 = refused to run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// The engine folder is this script's parent, whatever setup renamed it to.
define('SECURE_FOLDER_PATH', dirname(__DIR__));
define('SECURE_FOLDER_NAME', basename(SECURE_FOLDER_PATH));
define('SERVER_ROOT', dirname(SECURE_FOLDER_PATH));

require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$argvList = $argv ?? [];
$dryRun   = in_array('--dry-run', $argvList, true);
$quiet    = in_array('--quiet', $argvList, true);

if (in_array('--help', $argvList, true) || in_array('-h', $argvList, true)) {
    echo "QuickSite — session store sweep\n\n"
       . "  php " . basename(__FILE__) . "              sweep now\n"
       . "  php " . basename(__FILE__) . " --dry-run    report only, delete nothing\n"
       . "  php " . basename(__FILE__) . " --quiet      exit code only\n\n"
       . "Removes empty session files, sessions idle past auth.php's idle_ttl,\n"
       . "and non-QuickSite sessions past the longest lifetime this install\n"
       . "promises. Leaves everything else alone.\n";
    exit(0);
}

$report = qs_session_sweep($dryRun);

if ($quiet) {
    exit(0);
}

$verb = $dryRun ? 'would remove' : 'removed';
printf("QuickSite session sweep%s\n", $dryRun ? ' (dry run)' : '');
printf("  store      %s\n", qs_session_save_path());
if ($report['busy']) {
    printf("  NOTE       another pass is running right now; this one did nothing\n");
    exit(0);
}
if ($report['from'] > 0) {
    printf("  started    after the first %d files, which earlier passes examined\n", $report['from']);
}
printf("  examined   %d file%s in %.3fs\n",
    $report['examined'], $report['examined'] === 1 ? '' : 's', $report['seconds']);
printf("  %s %d (%d empty, %d idle) — %s\n",
    $verb, $report['removed'], $report['empty'], $report['idle'],
    qs_cli_bytes($report['bytes']));
// Every examined file that stays: recent enough to be alive without opening it,
// read and judged alive (or not QuickSite's to judge), or locked by a request.
$kept = $report['examined'] - $report['removed'];
printf("  kept       %d (%d recent, %d read and judged alive or not ours, %d locked by a request in flight)\n",
    $kept, $kept - $report['foreign'] - $report['locked'], $report['foreign'], $report['locked']);
if ($report['capped']) {
    if ($dryRun) {
        printf("  NOTE       stopped at the %d-file ceiling; a dry run does not move the\n"
             . "             position, so the next pass starts where this one did\n",
            QS_SESSION_SWEEP_MAX_FILES);
    } else {
        printf("  NOTE       stopped at the %d-file ceiling; run it again to continue\n"
             . "             from there\n",
            QS_SESSION_SWEEP_MAX_FILES);
    }
}
if ($report['removed_files']) {
    printf("  first few  %s%s\n", implode(', ', array_slice($report['removed_files'], 0, 5)),
        $report['removed'] > 5 ? ', …' : '');
}
exit(0);

function qs_cli_bytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}
