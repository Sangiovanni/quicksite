<?php
require_once __DIR__ . '/utilsManagement.php'; // qs_json_write
/**
 * Owner space usage — per-project disk measurement + a TTL cache.
 *
 * Backs GET /admin/self/space-usage: "how much disk do the projects I OWN consume", so an
 * owner sees their whole footprint without the command ever reporting a project
 * they have no relationship with.
 *
 * ── Two rules make the cache safe ────────────────────────────────────────────
 *
 * 1. **The project SET is never cached.** `qs_owned_projects()` is recomputed on
 *    every call from the authoritative `members.json`, never from the derived
 *    `users.php` projects cache — that derived cache is exactly what produced the
 *    stale-pointer bug. Resolving the set costs a few small JSON reads; only the
 *    recursive directory walk is expensive, and only that is cached. So creating,
 *    deleting, importing or transferring a project is reflected IMMEDIATELY, and a
 *    stale entry can only ever make a byte count slightly old — never show you a
 *    project you no longer own, nor hide one you just gained.
 *
 * 2. **Entries are keyed by PROJECT, not by user.** A project's size is the same
 *    number for everyone, so one shared entry serves every owner and co-owner
 *    (maximal hit rate, no per-user fan-out). The file is server-side only and each
 *    request reads entries solely for projects the caller owns — the same
 *    read-what-you-own discipline members.json itself uses.
 *
 * Staleness escape hatch: the account space report accepts `refresh=true`, which bypasses
 * and rewrites the entries — that is what the dashboard's refresh control calls
 * after you delete a backup and want the number to move now.
 */

require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // getDirectorySize

/**
 * Seconds a measured project size stays fresh.
 *
 * Deliberately a constant rather than a config key: the value only trades "how old
 * may a byte count be" against "how often do we walk the disk", and `refresh=true`
 * already covers the impatient case. If this ever needs to differ per install, it
 * should become a real config knob rather than being edited here.
 */
const QS_SPACE_CACHE_TTL = 300;

/** Where measured sizes live — mirrors the resolver cache convention. */
function qs_space_cache_path(): string {
    return SECURE_FOLDER_PATH . '/cache/space';
}

/**
 * The projects the given user OWNS, always freshly resolved.
 *
 * Ownership comes from each project's own members.json (authoritative), matching
 * how listProjects filters. A project directory with no readable membership simply
 * does not appear.
 *
 * @param string $userId Resolved caller id
 * @return string[] Project ids, sorted
 */
function qs_owned_projects(string $userId): array {
    if ($userId === '') {
        return [];
    }
    $owned = [];
    foreach (glob(SECURE_FOLDER_PATH . '/projects/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $project = basename($dir);
        if (getUserRoleForProject($userId, $project) === 'owner') {
            $owned[] = $project;
        }
    }
    sort($owned);
    return $owned;
}

/**
 * Recursive byte total for a directory (0 when absent), by the one measure every
 * command uses (FileSystem.php): plain files only, a link neither followed nor
 * counted, an unreadable folder skipped. The quota charges what a copy would copy.
 */
function qs_dir_size(string $path): int {
    return getDirectorySize($path);
}

/** Count of immediate entries matching a glob (0 when absent). */
function qs_count_glob(string $pattern): int {
    return count(glob($pattern) ?: []);
}

/**
 * Measure ONE project. Never returns names of anything inside it — sizes and
 * counts only. (Backup names are not disclosed by the project-scoped report
 * either; an aggregate is not the place to introduce them.)
 *
 * @param string $project Validated project id
 * @return array{total:int,content:int,backups:array,exports:array,builds:array}
 */
function qs_measure_project(string $project): array {
    $dir     = SECURE_FOLDER_PATH . '/projects/' . $project;
    $total   = qs_dir_size($dir);
    $backups = qs_dir_size($dir . '/backups');
    $exports = qs_dir_size($dir . '/exports');
    $builds  = qs_dir_size($dir . '/qs_build');

    return [
        'total'   => $total,
        // What is left once the regenerable artifacts are set aside.
        'content' => max(0, $total - $backups - $exports - $builds),
        'backups' => ['size' => $backups, 'count' => qs_count_glob($dir . '/backups/*')],
        'exports' => ['size' => $exports, 'count' => qs_count_glob($dir . '/exports/*.zip')],
        'builds'  => ['size' => $builds],
    ];
}

/**
 * Measured size for a project, from cache when fresh.
 *
 * Best-effort like the resolver cache: any filesystem failure degrades to a live
 * measurement rather than an error — a slow answer beats a wrong one.
 *
 * @param string $project Validated project id
 * @param bool   $refresh Bypass and rewrite the entry
 * @return array The measurement, plus 'cached' + 'measured_at'
 */
function qs_project_space(string $project, bool $refresh = false): array {
    $file = qs_space_cache_path() . '/' . $project . '.json';

    if (!$refresh && is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') {
            $entry = json_decode($raw, true);
            if (is_array($entry) && isset($entry['expires_at'], $entry['space'])) {
                if ((int)$entry['expires_at'] >= time()) {
                    return $entry['space'] + ['cached' => true, 'measured_at' => (int)($entry['stored_at'] ?? 0)];
                }
                @unlink($file); // lazy delete on expired read
            }
        }
    }

    $space = qs_measure_project($project);
    $now   = time();

    $dir = qs_space_cache_path();
    if (is_dir($dir) || (@mkdir($dir, 0755, true) || is_dir($dir))) {
        $written = qs_json_write($file, [
            'project'    => $project,
            'stored_at'  => $now,
            'expires_at' => $now + QS_SPACE_CACHE_TTL,
            'space'      => $space,
        ], JSON_UNESCAPED_SLASHES, LOCK_EX);
        if (!$written) {
            error_log('[space-cache] write failed: ' . $file);
        }
    } else {
        error_log('[space-cache] mkdir failed: ' . $dir);
    }

    return $space + ['cached' => false, 'measured_at' => $now];
}

/**
 * Drop ONE project's measurement, so the next read re-walks it.
 *
 * Called by every write the storage quota is checked for — an upload, a
 * backup, a restore, a clone, a build, a saved export — after it writes, and
 * by deleting a backup. The check itself measures the project it writes to
 * afresh (quota.php); this keeps the owner's OTHER projects exact too, and makes
 * the space overview move at once. Invalidating costs one directory walk on the
 * next read, and only when something actually changed, so reads keep hitting
 * the cache.
 *
 * A shrink elsewhere (a deleted export, build or project) still ages out on the
 * TTL, which leaves a total stale-HIGH: a quota briefly stricter than reality,
 * never looser. The account space report's refresh flag (`refresh=true`) is the
 * user-facing escape hatch for that.
 */
function qs_invalidate_space_cache(string $project): void {
    if ($project === '') {
        return;
    }
    @unlink(qs_space_cache_path() . '/' . $project . '.json');
}

/**
 * Drop cache entries for projects that no longer exist, so a deleted project
 * cannot leave a measurement lying around until its TTL lapses.
 */
function qs_prune_space_cache(): void {
    foreach (glob(qs_space_cache_path() . '/*.json') ?: [] as $file) {
        $project = basename($file, '.json');
        if (!is_dir(SECURE_FOLDER_PATH . '/projects/' . $project)) {
            @unlink($file);
        }
    }
}

// qs_format_size() lives in utilsManagement.php (required at the top of this
// file): uploadLimits.php calls it too, and it was never specific to space
// measurement. A second copy is how duplicate formatBytes() helpers come about.
