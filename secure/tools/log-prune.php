<?php
/**
 * log-prune.php — removes the days of the command log and of the security trail
 * past their age, the locks left without their day file, and the log folder of a
 * project that no longer exists. One of the deployer's tools in this folder (see
 * its README.md); the launcher, tools.php, lists it by the three lines below.
 *
 * @qs-tool   log-prune
 * @qs-line   Remove the logs' days past their age, stray locks and vanished projects' logs
 * @qs-usage  [--dry-run] [--quiet] [--days=N] [--security-days=N]
 *
 *   php secure/tools/log-prune.php                 remove now
 *   php secure/tools/log-prune.php --dry-run       list what would go; remove nothing
 *   php secure/tools/log-prune.php --quiet         exit code only (for cron)
 *   php secure/tools/log-prune.php --days=30 --security-days=180
 *
 * WHAT GOES, under <secure>/logs/ (each rule is LoggingManagement.php's):
 *   - a command log day file (`commands_<date>.json`, in `_global` and in each
 *     project's folder `p/<project>`) older than --days (QS_LOG_PRUNE_DAYS, 90),
 *     with its lock;
 *   - a security trail day file (`_security/security_<date>.json`) older than
 *     --security-days (QS_SECURITY_LOG_PRUNE_DAYS, 365), with its lock;
 *   - a lock left without its day file;
 *   - the folder of a project that no longer exists, whole: deleteProject removes
 *     it itself, so one is left only where a project folder was removed by hand.
 * NEVER: a file dated today or a day beside it, nor a project folder holding one
 * (QS_LOG_PRUNE_MARGIN_DAYS: the web server's PHP may date its files in another
 * time zone than this one); a day file or a lock a writer holds (each is taken
 * without waiting, as the writer takes it); a link; any other file.
 *
 * WHY THIS IS A SCRIPT AND NOT A COMMAND: as for session-sweep.php, the logs are
 * the whole installation's, and no project role could authorise a change to them.
 *
 * Exit codes: 0 = it ran; 1 = it refused to run; 2 = it left something that
 * needs a look, each listed (lib/cli.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/lib/cli.php';
require_once SECURE_FOLDER_PATH . '/src/functions/securityLog.php'; // QS_SECURITY_LOG_PRUNE_DAYS; LoggingManagement.php's rules

$opts = qs_tool_options($argv ?? [], [
    '--dry-run'       => 'flag',
    '--quiet'         => 'flag',
    '--days'          => QS_LOG_PRUNE_DAYS,
    '--security-days' => QS_SECURITY_LOG_PRUNE_DAYS,
], "QuickSite — log prune\n\n"
    . '  php ' . basename(__FILE__) . "                     remove now\n"
    . '  php ' . basename(__FILE__) . " --dry-run           list what would go; remove nothing\n"
    . '  php ' . basename(__FILE__) . " --quiet             exit code only (for cron)\n"
    . '  php ' . basename(__FILE__) . ' --days=N            the command log\'s age in days (default ' . QS_LOG_PRUNE_DAYS . ")\n"
    . '  php ' . basename(__FILE__) . ' --security-days=N   the security trail\'s age in days (default ' . QS_SECURITY_LOG_PRUNE_DAYS . ")\n\n"
    . "Removes the command log's day files older than --days, in _global and in\n"
    . "every project's folder; the security trail's older than --security-days;\n"
    . "the locks left without their day file; and the log folder of a project\n"
    . "that no longer exists. Never a file of today or a day beside it, never one\n"
    . "a writer holds.\n"
    . "Exit code: 0 ran, 1 refused to run, 2 left something that needs a look.\n");

$dryRun = $opts['dry-run'];
$now = time();
$report = new QsToolReport();

$logs = qs_tool_store('logs', $why);
if ($logs === null && $why !== 'absent') {
    $report->add('logs', ['state' => 'link', 'why' => $why === 'link' ? 'a link leading out of the engine folder: left alone' : 'not a folder', 'bytes' => 0]);
}
if ($logs !== null) {
    lp_bucket($report, $logs, QS_LOG_GLOBAL_BUCKET, 'commands', $opts['days'], $now, $dryRun);
    lp_bucket($report, $logs, QS_SECURITY_LOG_BUCKET, 'security', $opts['security-days'], $now, $dryRun);
    $projects = $logs . DIRECTORY_SEPARATOR . 'p';
    if (qs_tool_is_link($logs, 'p')) {
        $report->add('logs/p', ['state' => 'link', 'why' => 'a link: left alone', 'bytes' => 0]);
    } elseif (is_dir($projects)) {
        foreach (qs_tool_names($projects) as $name) {
            if (qs_tool_is_link($projects, $name)) {
                $report->add('logs/p/' . $name, ['state' => 'link', 'why' => 'a link: left alone', 'bytes' => 0]);
            } elseif (!is_dir($projects . DIRECTORY_SEPARATOR . $name)) {
                continue; // a file: not the log's
            } elseif (qs_log_project_vanished($name)) {
                lp_vanished($report, $projects, $name, $now, $dryRun);
            } elseif (is_valid_project_name($name)) {
                lp_bucket($report, $projects, $name, 'commands', $opts['days'], $now, $dryRun);
            }
        }
    }
}

if (!$opts['quiet']) {
    $report->print('log prune', $dryRun);
}
exit($report->exitCode());

/**
 * One folder of day files: each day file past its age goes with its lock, each
 * lock without its day file goes, under that lock taken without waiting.
 */
function lp_bucket(QsToolReport $report, string $parent, string $name, string $kind, int $days, int $now, bool $dryRun): void {
    $dir = $parent . DIRECTORY_SEPARATOR . $name;
    if (qs_tool_is_link($parent, $name)) {
        $report->add(qs_tool_rel($dir), ['state' => 'link', 'why' => 'a link: left alone', 'bytes' => 0]);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    $cutoff = qs_log_prune_cutoff($days, $now);
    foreach (qs_tool_names($dir) as $entry) {
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (($day = qs_log_day_of($entry, $kind)) !== null) {
            if ($day >= $cutoff || qs_log_day_is_recent($day, $now)) {
                $report->keep('within their age');
                continue;
            }
            $report->add(qs_tool_rel($path), qs_tool_remove_beside($path, $path . '.lock',
                static fn($content) => $content === null ? [false, 'gone already'] : [true, 'older than ' . $days . ' days'],
                false, false, $dryRun));
        } elseif (($day = qs_log_day_of($entry, $kind, true)) !== null) {
            $file = substr($path, 0, -strlen('.lock'));
            clearstatcache();
            if (is_file($file) || !is_file($path)) {
                continue; // its day file's: it goes, or stays, with it (gone with it already, in a real run)
            }
            if (qs_log_day_is_recent($day, $now)) {
                $report->keep('locks of today or a day beside it');
                continue;
            }
            $report->add(qs_tool_rel($path), qs_tool_remove_beside($file, $path,
                static fn($content) => $content === null ? [true, 'a lock without its day file'] : [false, 'whose day file is back'],
                false, false, $dryRun));
        }
    }
}

/**
 * The log folder of a project that no longer exists goes whole, through the one
 * recursive delete, unless it holds a file of the last days: a project of that
 * name may be being made right now.
 */
function lp_vanished(QsToolReport $report, string $parent, string $name, int $now, bool $dryRun): void {
    $dir = $parent . DIRECTORY_SEPARATOR . $name;
    $entries = qs_tool_names($dir);
    foreach ($entries as $entry) {
        $day = qs_log_day_of($entry, 'commands') ?? qs_log_day_of($entry, 'commands', true);
        if ($day !== null && qs_log_day_is_recent($day, $now)) {
            $report->keep('logs of a vanished project, written in the last days');
            return;
        }
    }
    $count = count($entries);
    $bytes = getDirectorySize($dir);
    $why = 'the log of a project that no longer exists, ' . $count . ' file' . ($count === 1 ? '' : 's');
    if ($dryRun) {
        $report->add(qs_tool_rel($dir), ['state' => 'remove', 'why' => $why, 'bytes' => $bytes]);
        return;
    }
    if (!qs_log_project_vanished($name)) {
        $report->keep('whose project exists again');
        return;
    }
    $removal = qs_delete_tree($dir);
    $report->add(qs_tool_rel($dir), $removal['ok']
        ? ['state' => 'remove', 'why' => $why, 'bytes' => $bytes]
        : ['state' => 'failed', 'why' => 'could not remove ' . implode(', ', $removal['survived']), 'bytes' => 0]);
}
