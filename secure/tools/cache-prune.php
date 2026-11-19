<?php
/**
 * cache-prune.php — removes from QuickSite's four caches what each one already
 * ignores, each by its own rule: the write-rate files whose windows are over,
 * the membership repair's files past their time, and the resolver's and the
 * space measurement's entries past their expiry (and a measurement of a project
 * that is gone). One of the deployer's tools in this folder (see its README.md);
 * the launcher, tools.php, lists it by the three lines below.
 *
 * @qs-tool   cache-prune
 * @qs-line   Remove what the four caches already ignore, each by its own rule
 * @qs-usage  [--dry-run] [--quiet]
 *
 *   php secure/tools/cache-prune.php              remove now
 *   php secure/tools/cache-prune.php --dry-run    list what would go; remove nothing
 *   php secure/tools/cache-prune.php --quiet      exit code only (for cron)
 *
 * WHAT GOES, under <secure>/cache/, and whose rule decides it:
 *   - rate/: an account's rate file and its lock, once every window in it is over
 *     (quota.php, qs_rate_file_ended()), under the lock the store's writer takes;
 *     a lock left without its file too. The writer checks, once it holds its
 *     lock, that the lock is still the file at its path (qs_rate_store_mutate()).
 *   - membership/: an account's repair file whose repair is QS_MEMBERSHIP_REPAIR_TTL
 *     (300 s) old or more (AuthManagement.php, qs_membership_repair_file_stale());
 *     never the `token`. The store takes no lock: a file written again while it was
 *     judged is kept.
 *   - resolver/: an entry past its expiry (resolverCache.php,
 *     qs_resolver_cache_entry_state()), under the entry's own lock, which its writer
 *     takes. A malformed or unreadable entry is reported and left (the
 *     cleanResolverCache command deletes those).
 *   - space/: a measurement past its expiry, or of a project that is gone
 *     (spaceUsage.php, qs_space_cache_entry_state(), qs_space_cache_project_gone()),
 *     under its own lock.
 * There is no age to choose: what goes is what the store already ignores. A file
 * a writer holds is kept for the next run; a link, or anything else in a cache
 * folder, is left.
 *
 * WHY THIS IS A SCRIPT AND NOT A COMMAND: the caches are the whole installation's,
 * across every account and project; no project role could authorise this.
 *
 * Exit codes: 0 = it ran; 1 = it refused to run; 2 = it left something that
 * needs a look, each listed (lib/cli.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/lib/cli.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';          // the rate store; spaceUsage.php, AuthManagement.php
require_once SECURE_FOLDER_PATH . '/src/functions/resolverCache.php';

$opts = qs_tool_options($argv ?? [], [
    '--dry-run' => 'flag',
    '--quiet'   => 'flag',
], "QuickSite — cache prune\n\n"
    . '  php ' . basename(__FILE__) . "              remove now\n"
    . '  php ' . basename(__FILE__) . " --dry-run    list what would go; remove nothing\n"
    . '  php ' . basename(__FILE__) . " --quiet      exit code only (for cron)\n\n"
    . "Removes from the caches what each one already ignores: write-rate files\n"
    . "whose windows are over, membership repairs older than " . QS_MEMBERSHIP_REPAIR_TTL . " seconds (never\n"
    . "the token), resolver entries past their expiry, and space measurements past\n"
    . "theirs or of a project that is gone. Never a file a writer holds.\n"
    . "Exit code: 0 ran, 1 refused to run, 2 left something that needs a look.\n");

$dryRun = $opts['dry-run'];
$now = time();
$report = new QsToolReport();

// ── rate/ ─────────────────────────────────────────────────────────────────────
if (($dir = cp_store($report, 'cache/rate')) !== null) {
    foreach (qs_tool_names($dir) as $name) {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (qs_rate_store_file_is_ours($name)) {
            $report->add(qs_tool_rel($path), qs_tool_remove_beside($path, $path . '.lock', static function ($content) use ($now) {
                if ($content === null) {
                    return [false, 'gone already'];
                }
                $data = json_decode($content, true);
                if (!is_array($data)) {
                    return null;
                }
                return qs_rate_file_ended($data, $now) ? [true, 'its windows are over'] : [false, 'with a window still open'];
            }, true, true, $dryRun));
        } elseif (substr($name, -5) === '.lock' && qs_rate_store_file_is_ours(substr($name, 0, -5))) {
            $file = substr($path, 0, -5);
            clearstatcache();
            if (is_file($file) || !is_file($path)) {
                continue; // its rate file's: it goes, or stays, with it (gone with it already, in a real run)
            }
            $report->add(qs_tool_rel($path), qs_tool_remove_beside($file, $path,
                static fn($content) => $content === null ? [true, 'a lock without its rate file'] : [false, 'whose rate file is back'],
                false, false, $dryRun));
        }
    }
}

// ── membership/ ───────────────────────────────────────────────────────────────
if (($dir = cp_store($report, 'cache/membership')) !== null) {
    foreach (qs_tool_names($dir) as $name) {
        if (!qs_membership_repair_file_is_ours($name)) {
            continue; // the token, or not the cache's
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $report->add(qs_tool_rel($path), qs_tool_remove_unlocked($path, static function (string $content) use ($now) {
            $stale = qs_membership_repair_file_stale($content, $now);
            return $stale === null ? null
                : ($stale ? [true, 'its repair is ' . QS_MEMBERSHIP_REPAIR_TTL . ' s old or more'] : [false, 'with a repair still standing']);
        }, $dryRun));
    }
}

// ── resolver/ ─────────────────────────────────────────────────────────────────
if (($dir = cp_store($report, 'cache/resolver')) !== null) {
    foreach (qs_tool_names($dir) as $name) {
        if (!qs_resolver_cache_file_is_ours($name)) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $report->add(qs_tool_rel($path), qs_tool_remove_self_locked($path, static function ($content) use ($now) {
            $state = qs_resolver_cache_entry_state($content, $now);
            if ($state === 'expired') {
                return [true, 'past its expiry'];
            }
            return $state === 'fresh' ? [false, 'not expired'] : null;
        }, $dryRun));
    }
}

// ── space/ ────────────────────────────────────────────────────────────────────
if (($dir = cp_store($report, 'cache/space')) !== null) {
    foreach (qs_tool_names($dir) as $name) {
        $project = substr($name, 0, -5);
        if (substr($name, -5) !== '.json' || !is_valid_project_name($project)) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $report->add(qs_tool_rel($path), qs_tool_remove_self_locked($path, static function ($content) use ($now, $project) {
            if (qs_space_cache_project_gone($project)) {
                return [true, 'its project is gone'];
            }
            $state = qs_space_cache_entry_state($content, $now);
            if ($state === 'expired') {
                return [true, 'past its expiry'];
            }
            return $state === 'fresh' ? [false, 'not expired'] : null;
        }, $dryRun));
    }
}

if (!$opts['quiet']) {
    $report->print('cache prune', $dryRun);
}
exit($report->exitCode());

/** A cache's folder, or null: absent (nothing to do), or refused and reported when it is a link or no folder. */
function cp_store(QsToolReport $report, string $rel): ?string {
    $dir = qs_tool_store($rel, $why);
    if ($dir === null && $why !== 'absent') {
        $report->add($rel, ['state' => 'link', 'why' => $why === 'link' ? 'a link leading out of the engine folder: left alone' : 'not a folder', 'bytes' => 0]);
    }
    return $dir;
}
