<?php

require_once __DIR__ . '/utilsManagement.php'; // qs_format_size
require_once __DIR__ . '/spaceUsage.php';      // qs_owned_projects, qs_project_space

/**
 * Per-user resource limits — how much disk one account may consume, and how
 * often it may add to it.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * Creating a project and uploading into it are both open to any authenticated
 * account (`projects.create` is a global `access:'any'` category). Without a
 * ceiling, an ordinary signed-in user can fill the disk of a shared install —
 * not by exploiting anything, just by using the product as designed. That is a
 * missing control on a deployment surface rather than a missing feature, which
 * is why it ships with the deployment work.
 *
 * The ceiling is checked by every write that adds a copy's worth of bytes: an
 * upload, an import, a backup, a restore, a clone, a build and a saved export.
 * Each passes its own kind, so the refusal names the write and the way out. A new
 * project is refused to an owner already over the ceiling: what it writes is a few
 * kilobytes, so it is the owner's standing, not its size, that decides.
 *
 * ── SMALL WRITES ─────────────────────────────────────────────────────────────
 *
 * The edits an author makes all day — a structure, the stylesheet, a translation
 * file, the routes, a snippet, a settings file — add a few bytes each, too few to
 * measure the disk for. They are bounded three other ways:
 *
 *   1. A size cap per kind of file (`max_file_bytes`), checked on the bytes about
 *      to be written. Over it the write is refused (413) and nothing is written.
 *   2. While the owner is already over the storage ceiling (the cached figure), a
 *      write that makes its files bigger is refused (507); one that keeps their
 *      size or shrinks them passes, so the owner can clean up. A write that takes
 *      an owner from under the ceiling to a few bytes over it passes: the figure
 *      is cached, and the deployer keeps a margin.
 *   3. A rate of commands per account (`write_rate`), checked by the management
 *      dispatcher before a command runs (429). Every command but a read counts.
 *
 * Nothing caps how many pages, components or snippets an account makes: the file
 * caps and the storage ceiling already bound them. Every command that writes such
 * a file calls qs_small_write_refusal() before its first write.
 *
 * ── DEFAULTS ─────────────────────────────────────────────────────────────────
 *
 * With no `secure/management/config/quota.php`, the storage ceiling and the
 * upload rate are OFF: an existing install must not start refusing uploads
 * because it was updated, and only the deployer knows the size of their disk.
 * The file caps and the write rate are ON, at values measured on real projects
 * and real editing sessions with a wide margin, so that no real session meets
 * them; each is the deployer's to change, and 0 (or an empty `write_rate`)
 * turns it off. The deployer copies the shipped `.example` and sets numbers that
 * suit their disk — the same shape (and the same fail-safe posture on a
 * malformed file) as `import-policy.php`.
 *
 * Note the asymmetry with `filePolicy.php`, and that it is intentional: a
 * malformed import policy falls back to the SAFE built-in allowlists, because
 * the safe answer there is "refuse more". Here the safe answer is "refuse
 * nothing more than the defaults do" — a typo in a quota file must not lock
 * every author out of their own site. Both fail towards not breaking the
 * install; that just points in opposite directions for a deny-list and an
 * allow-budget.
 *
 * ── WHAT "PER USER" MEANS, PRECISELY ─────────────────────────────────────────
 *
 * Usage is the total disk of the projects the CALLER OWNS plus their personal
 * snippets (which live outside every project), measured by `spaceUsage.php` —
 * the same measurement the account space report shows, so the number the panel
 * shows an author IS the number this code enforces against. There is no second
 * measurer.
 *
 * Two consequences worth stating rather than discovering:
 *
 *   1. **Co-owners each carry the full weight of a shared project.** A 1 GB
 *      project owned by two accounts counts 1 GB against BOTH. That is the
 *      conservative direction — neither can park bytes behind the other — and
 *      it falls out of ownership being a set, not a share.
 *
 *   2. **A non-owner member's uploads count against the OWNER, not the
 *      uploader.** Bytes are attributed by the project they land in, and
 *      QuickSite records no per-file uploader. So an account that owns nothing
 *      has an empty usage total and is not bounded by the byte axis at all — it
 *      can only write into projects someone invited it to, which is a trust
 *      relationship an owner established deliberately, and the bytes still land
 *      in that owner's total. The rate axis below is keyed on the CALLER and
 *      does bound such an account.
 *
 * ── STALENESS, AND WHY IT IS SAFE ────────────────────────────────────────────
 *
 * `qs_project_space()` caches a measurement for `QS_SPACE_CACHE_TTL` (300 s).
 * A ceiling read from a stale-LOW number would be a ceiling a burst walks
 * straight through, so growth is never read from the cache where it matters:
 *
 *   1. The project a write lands in is MEASURED AFRESH by the check itself, so
 *      the figure that decides is the disk's, whatever any earlier write did.
 *   2. The owner's other projects come from the cache, and every write that grows
 *      a project calls `qs_invalidate_space_cache()` after it writes, so those
 *      entries are exact for growth too, and the overview moves at once.
 *
 * What can still age is a SHRINK in another project — deleting an export, a
 * build or a whole project. That leaves the total stale-HIGH, i.e. the quota is
 * briefly stricter than reality, never looser. The dashboard's refresh control
 * calls GET /admin/self/space-usage?refresh=1, which re-measures AND rewrites the
 * cache entries, so a user who frees space and is refused can make the number
 * move immediately. The upload refusal says so.
 */

/**
 * The cap per kind of small file when quota.php names none, in bytes. Each is
 * several times the largest real file of its kind; `stylesheet` is also the most
 * the CSS parser reads back (CSS_MAX_BYTES), which no setting can raise.
 */
const QS_FILE_CAP_DEFAULTS = [
    'page'        => 2 * 1024 * 1024,
    'structure'   => 256 * 1024,
    'stylesheet'  => 512 * 1024,
    'translation' => 2 * 1024 * 1024,
    'routes'      => 128 * 1024,
    'snippet'     => 512 * 1024,
    'settings'    => 1024 * 1024,
];

/** The write rate when quota.php names none: one window of an hour. */
const QS_WRITE_RATE_DEFAULT = [['max_commands' => 1000, 'period_seconds' => 3600]];

/** How long the panel's batch runner waits for a window to free before it stops, by default. */
const QS_WRITE_RATE_WAIT_DEFAULT = 60;

/**
 * The effective quota: the defaults, overridden by the optional config file.
 *
 * `max_total_bytes` and the upload rate are "0 = unlimited", matching how PHP
 * itself reads `post_max_size` and how `qs_registration_config()` reads its own
 * caps — a caller must never treat 0 as "zero bytes allowed". A file cap of 0
 * turns that cap off; a write rate with no window turns the rate off.
 *
 * @return array{max_total_bytes:int, rate_max_uploads:int, rate_period:int,
 *               max_file_bytes:array<string,int>, write_rate:list<array{max_commands:int, period_seconds:int}>,
 *               write_rate_wait:int, configured:bool}
 */
function qs_quota_config(): array
{
    static $quota = null;
    if ($quota !== null) {
        return $quota;
    }

    $quota = [
        'max_total_bytes'  => 0,
        'rate_max_uploads' => 0,
        'rate_period'      => 3600,
        'max_file_bytes'   => QS_FILE_CAP_DEFAULTS,
        'write_rate'       => QS_WRITE_RATE_DEFAULT,
        'write_rate_wait'  => QS_WRITE_RATE_WAIT_DEFAULT,
        // Lets a caller distinguish "no file" from "a file that sets no limits".
        // Nothing enforces on it; the panel and the probes read it.
        'configured'       => false,
    ];

    $override = SECURE_FOLDER_PATH . '/management/config/quota.php';
    if (!is_file($override)) {
        return $quota;
    }

    // A syntax error is a ParseError, which `@` cannot suppress — without this
    // catch a deployer's typo would take every upload down with a 500. Same
    // guard, same reason, as filePolicy.php.
    $configured = null;
    try {
        $configured = require $override;
    } catch (Throwable $e) {
        error_log('quota: ignoring malformed quota.php (' . $e->getMessage() . '); the defaults are in force');
        return $quota;
    }
    if (!is_array($configured)) {
        error_log('quota: quota.php did not return an array; the defaults are in force');
        return $quota;
    }

    $quota['configured'] = true;

    // Read defensively: for the storage ceiling and the upload rate, a non-int,
    // a negative, or a missing key all mean "leave this axis unlimited" rather
    // than "refuse everything".
    $bytes = $configured['max_total_bytes'] ?? null;
    if (is_int($bytes) && $bytes > 0) {
        $quota['max_total_bytes'] = $bytes;
    }

    $rate = is_array($configured['upload_rate'] ?? null) ? $configured['upload_rate'] : [];

    $maxUploads = $rate['max_uploads'] ?? null;
    if (is_int($maxUploads) && $maxUploads > 0) {
        $quota['rate_max_uploads'] = $maxUploads;
    }

    // The period only matters when the count does; a nonsensical period with a
    // real count would otherwise silently produce a window of 0 seconds, which
    // is a limit that never fires.
    $period = $rate['period_seconds'] ?? null;
    if (is_int($period) && $period > 0) {
        $quota['rate_period'] = $period;
    }

    // File caps: a kind left out keeps its default; a value that is not a
    // non-negative integer keeps it too, and says so in the log.
    if (array_key_exists('max_file_bytes', $configured)) {
        $caps = $configured['max_file_bytes'];
        if (!is_array($caps)) {
            error_log('quota: max_file_bytes is not an array; the default caps are in force');
            $caps = [];
        }
        foreach ($caps as $kind => $bytes) {
            if (!isset(QS_FILE_CAP_DEFAULTS[$kind])) {
                error_log('quota: max_file_bytes names no kind "' . $kind . '"; ignored');
                continue;
            }
            if (!is_int($bytes) || $bytes < 0) {
                error_log('quota: max_file_bytes.' . $kind . ' is not a whole number of bytes; its default is in force');
                continue;
            }
            $quota['max_file_bytes'][$kind] = $bytes;
        }
    }

    // The write rate: its numbered entries are the windows, `wait_seconds` the
    // batch runner's patience. Present with no valid window means OFF.
    if (array_key_exists('write_rate', $configured)) {
        $writeRate = $configured['write_rate'];
        $quota['write_rate'] = [];
        if (!is_array($writeRate)) {
            if ($writeRate !== 0 && $writeRate !== false && $writeRate !== null) {
                error_log('quota: write_rate is not an array; the write rate is off');
            }
            $writeRate = [];
        }
        foreach ($writeRate as $key => $window) {
            if (!is_int($key)) {
                continue;
            }
            $max = is_array($window) ? ($window['max_commands'] ?? null) : null;
            $len = is_array($window) ? ($window['period_seconds'] ?? null) : null;
            if (!is_int($max) || $max <= 0 || !is_int($len) || $len <= 0) {
                error_log('quota: write_rate entry ' . $key . ' needs a positive max_commands and period_seconds; ignored');
                continue;
            }
            // A window is known by its period (its counter, its header name): two
            // windows of one period are one, the stricter.
            if (isset($quota['write_rate'][$len])) {
                error_log('quota: two write_rate windows of ' . $len . ' seconds; the stricter is in force');
                $max = min($max, $quota['write_rate'][$len]['max_commands']);
            }
            $quota['write_rate'][$len] = ['max_commands' => $max, 'period_seconds' => $len];
        }
        $quota['write_rate'] = array_values($quota['write_rate']);
        $wait = $writeRate['wait_seconds'] ?? null;
        if (is_int($wait) && $wait >= 0) {
            $quota['write_rate_wait'] = $wait;
        } elseif ($wait !== null) {
            error_log('quota: write_rate.wait_seconds is not a whole number of seconds; its default is in force');
        }
    }

    return $quota;
}

/**
 * Whether a storage ceiling is configured. A write that has to measure what it
 * adds asks this first, so an install with no quota file never pays for the walk.
 */
function qs_quota_storage_limited(): bool
{
    return qs_quota_config()['max_total_bytes'] > 0;
}

/**
 * The account a write into an existing project is charged to: the project's
 * OWNER, because that is whose disk grows — not the caller, or any member with
 * write rights could push an owner past their quota while spending none of their
 * own. Falls back to the caller when no owner is recorded, which keeps a
 * malformed members.json enforcing something rather than nothing.
 */
function qs_quota_storage_owner(string $project, string $callerId): string
{
    $owner = (string)(loadProjectMembers($project)['owner'] ?? '');
    return $owner !== '' ? $owner : $callerId;
}

/**
 * Total bytes the given user's owned projects occupy right now.
 *
 * Consumes `spaceUsage.php` directly rather than running the account report in
 * process: that command's job is to SHAPE the answer for the dashboard (sorted
 * rows, formatted sizes, per-category splits) and all this needs is the sum.
 * The measurement underneath is the same one, so the two can never disagree.
 *
 * @param string      $userId       Resolved caller id ('' → 0, fail-open like listProjects)
 * @param string|null $freshProject A project to measure afresh rather than read from
 *                                  the cache: the one a write is about to land in
 * @return array{bytes:int, projects:int}
 */
function qs_quota_usage(string $userId, ?string $freshProject = null): array
{
    if ($userId === '') {
        return ['bytes' => 0, 'projects' => 0];
    }

    $owned = qs_owned_projects($userId);
    $total = qs_personal_snippets_space($userId);
    foreach ($owned as $project) {
        $space  = qs_project_space($project, $project === $freshProject);
        $total += (int)($space['total'] ?? 0);
    }

    return ['bytes' => $total, 'projects' => count($owned)];
}

/**
 * Would a write of $incomingBytes for this owner cross the storage ceiling?
 *
 * Returns null when the write is allowed — including for every install with no
 * quota file, which is the common case and costs no disk walk at all: the
 * unlimited check short-circuits BEFORE the measurement.
 *
 * ⚠ $ownerId IS THE ACCOUNT THAT RECEIVES THE BYTES, not the one sending them.
 * An asset uploaded into a project is charged to that project's OWNER, because
 * that is whose disk grows (qs_quota_storage_owner()). Charging the caller
 * instead let any member with upload rights push an owner past their quota while
 * spending none of their own — the caller's own projects were measured, found
 * small, and waved through. The upload RATE limit is the opposite case and stays
 * on the caller: it bounds what one actor does, not where the bytes land.
 *
 * `$write` describes the write; every key is optional:
 *   - `project` — the existing project the bytes land in. It is measured afresh,
 *     never read from the cache (see STALENESS above). A new project has none.
 *   - `kind` — `upload` (the default; an import shares its words), `backup`,
 *     `restore`, `clone`, `build`, `export` or `create` (a new project, checked
 *     with nothing incoming): the refusal names the write and says what to do.
 *   - `freed` — bytes the same write removes: the older backups max_backups
 *     prunes, the archive the five-export limit drops, what a restore replaces.
 *     Only the net growth is charged. Such a write crosses the ceiling only while
 *     it runs, because it makes its copy before it removes what the copy replaces.
 *   - `landed` — the bytes are already on disk in `project` (a build is checked
 *     once it is made), so the fresh measurement holds them already.
 *
 * @param string      $ownerId  Account whose disk receives the bytes
 * @param int         $incomingBytes
 * @param string|null $callerId Who is asking, when that is not the owner.
 *                              Null means caller === owner (the usual case).
 * @param array       $write    project / kind / freed / landed, as above
 * @return array{message:string, data:array}|null
 */
function qs_quota_check_storage(string $ownerId, int $incomingBytes, ?string $callerId = null, array $write = []): ?array
{
    $quota = qs_quota_config();
    if ($quota['max_total_bytes'] <= 0) {
        return null; // unlimited — do not pay for a measurement nobody reads
    }

    $kind     = (string)($write['kind'] ?? 'upload');
    $incoming = max(0, $incomingBytes);
    $freed    = max(0, (int)($write['freed'] ?? 0));
    $net      = max(0, $incoming - $freed);
    $usage    = qs_quota_usage($ownerId, isset($write['project']) ? (string)$write['project'] : null);
    $used     = empty($write['landed']) ? $usage['bytes'] : max(0, $usage['bytes'] - $incoming);
    if ($used + $net <= $quota['max_total_bytes']) {
        return null;
    }

    $remaining = max(0, $quota['max_total_bytes'] - $used);

    // A caller who is NOT the owner is told the outcome and nothing else. The
    // usage total and project count aggregate every project that owner has,
    // including ones this caller is not a member of and cannot otherwise see,
    // so spelling them out here would disclose them. The quota ceiling itself
    // is install-wide and not owner-specific, so it may still be named.
    if ($callerId !== null && $callerId !== $ownerId) {
        return [
            'message' => qs_quota_owner_scoped_message($kind, $quota['max_total_bytes']),
            'data' => [
                'incoming_bytes' => $incoming,
                'incoming_human' => qs_format_size($incoming),
                'quota_bytes'    => $quota['max_total_bytes'],
                'quota_human'    => qs_format_size($quota['max_total_bytes']),
                'owner_scoped'   => true,
            ],
        ];
    }

    $data = [
        'used_bytes'        => $used,
        'used_human'        => qs_format_size($used),
        'incoming_bytes'    => $incoming,
        'incoming_human'    => qs_format_size($incoming),
        'quota_bytes'       => $quota['max_total_bytes'],
        'quota_human'       => qs_format_size($quota['max_total_bytes']),
        'remaining_bytes'   => $remaining,
        'remaining_human'   => qs_format_size($remaining),
        'owned_projects'    => $usage['projects'],
    ];
    if ($freed > 0) {
        $data['freed_bytes'] = $freed;
        $data['freed_human'] = qs_format_size($freed);
        $data['net_bytes']   = $net;
        $data['net_human']   = qs_format_size($net);
    }

    return [
        'message' => qs_quota_refusal_message($kind, $used, $quota['max_total_bytes'], $incoming, $freed, $remaining),
        'data'    => $data,
    ];
}

/**
 * The refusal an owner reads: their figures, what this write adds, and what to do
 * for this kind of write.
 */
function qs_quota_refusal_message(string $kind, int $used, int $ceiling, int $incoming, int $freed, int $remaining): string
{
    if ($kind === 'create') {
        return 'Your projects already use ' . qs_format_size($used) . ' of your ' . qs_format_size($ceiling)
            . ' storage quota, so no new project can be created. Delete backups, exports or unused projects '
            . 'to free space first.';
    }
    [$opening, $adds, $advice] = match ($kind) {
        'backup'  => ['This backup', 'this backup adds',
                      'Delete an older backup first (or exports or unused projects), then back up again.'],
        'restore' => ['This restore', 'restoring this backup adds',
                      'Free space first by deleting older backups, exports or unused projects, or restore it with '
                      . '"Delete this backup once it is restored" (delete_backup), which frees the space the backup itself takes.'],
        'clone'   => ['This clone', 'the copy adds',
                      'Delete backups, exports or unused projects to free space first.'],
        'build'   => ['This build', 'the build adds',
                      'The build was removed. Delete backups, exports or unused projects to free space, then build again.'],
        'export'  => ['Saving this export', 'the archive adds',
                      'The archive was not kept. Download the export without saving it, or free space first.'],
        default   => ['This', 'this upload adds',
                      'Delete backups, exports or unused projects to free space — then refresh the '
                      . 'storage figure on the dashboard, because a measurement can be up to '
                      . QS_SPACE_CACHE_TTL . ' seconds old after a deletion.'],
    };
    $amount = $freed > 0
        ? qs_format_size(max(0, $incoming - $freed)) . ' (it writes ' . qs_format_size($incoming)
            . ' and removes ' . qs_format_size($freed) . ')'
        : qs_format_size($incoming);

    return $opening . ' would put your projects over your storage quota. They currently use '
        . qs_format_size($used) . ' of ' . qs_format_size($ceiling)
        . ', and ' . $adds . ' ' . $amount . '. '
        . ($remaining > 0
            ? 'You have ' . qs_format_size($remaining) . ' left. '
            : 'You have no room left. ')
        . $advice;
}

/**
 * The refusal a caller who is not the project's owner reads: the outcome and the
 * install-wide ceiling, never the owner's figures.
 */
function qs_quota_owner_scoped_message(string $kind, int $ceiling): string
{
    $tail = ' — the ceiling on this server is ' . qs_format_size($ceiling) . ' per account.';
    return match ($kind) {
        'backup'  => 'The owner of this project has no storage space left for this backup. '
                     . 'Ask them to delete an older backup or free space' . $tail,
        'restore' => 'The owner of this project has no storage space left for this restore. Ask them to free '
                     . 'space, or restore it with "Delete this backup once it is restored" (delete_backup)' . $tail,
        'build'   => 'The owner of this project has no storage space left for this build, so it was removed. '
                     . 'Ask them to free space' . $tail,
        'export'  => 'The owner of this project has no storage space left to keep this export, so it was not '
                     . 'kept. Download the export without saving it, or ask them to free space' . $tail,
        'edit'    => 'The owner of this project has no storage space left, so a change that makes a file larger '
                     . 'cannot be saved (one that keeps a file\'s size or makes it smaller still is). Ask them to free space' . $tail,
        default   => 'The owner of this project has no storage space left, so nothing '
                     . 'more can be added to it. Ask them to free space' . $tail,
    };
}

// ============================================================================
// Small writes — the cap per kind of file, and growth while over the ceiling
// ============================================================================

/**
 * The kind of a small file, from where it lives: `page`, `structure`,
 * `stylesheet`, `translation`, `routes`, `snippet` or `settings`. A project file
 * no other kind claims is `settings`, the general cap. A file the engine derives
 * from another (a page's PHP wrapper, a generated script, the sitemap) or an
 * uploaded asset has no kind and no cap — its source is capped — and neither has
 * a path outside every project and the personal snippets (null).
 */
function qs_small_file_kind(string $path): ?string
{
    $rel = qs_small_file_relative($path);
    if ($rel === null) {
        return null;
    }
    if ($rel['personal']) {
        return 'snippet';
    }
    $p = $rel['path'];
    if (strpos($p, 'templates/pages/') === 0 || (strpos($p, 'public/') === 0 && $p !== 'public/style/style.css')) {
        return null;
    }
    if (preg_match('#^templates/model/json/pages/.+\.json$#', $p)) {
        return 'page';
    }
    if (preg_match('#^templates/model/json/(menu|footer|consent-[a-z]+)\.json$#', $p)
        || preg_match('#^templates/model/json/components/[^/]+\.json$#', $p)) {
        return 'structure';
    }
    if ($p === 'public/style/style.css') {
        return 'stylesheet';
    }
    if (preg_match('#^translate/[^/]+\.json$#', $p)) {
        return 'translation';
    }
    if ($p === 'routes.php') {
        return 'routes';
    }
    if (preg_match('#^snippets/.+\.json$#', $p)) {
        return 'snippet';
    }
    return 'settings';
}

/**
 * Where a small file sits: its project and its path inside it, or — for a
 * personal snippet — its owner and its path inside their snippet folder.
 *
 * @return array{project:?string, owner:?string, path:string, personal:bool}|null
 */
function qs_small_file_relative(string $path): ?array
{
    $norm   = str_replace('\\', '/', $path);
    $secure = rtrim(str_replace('\\', '/', SECURE_FOLDER_PATH), '/');
    if (strpos($norm, $secure . '/snippets/custom/') === 0) {
        $rest = substr($norm, strlen($secure . '/snippets/custom/'));
        $cut  = strpos($rest, '/');
        if ($cut === false) {
            return null;
        }
        return ['project' => null, 'owner' => substr($rest, 0, $cut), 'path' => substr($rest, $cut + 1), 'personal' => true];
    }
    if (strpos($norm, $secure . '/projects/') !== 0) {
        return null;
    }
    $rest = substr($norm, strlen($secure . '/projects/'));
    $cut  = strpos($rest, '/');
    if ($cut === false) {
        return null;
    }
    return ['project' => substr($rest, 0, $cut), 'owner' => null, 'path' => substr($rest, $cut + 1), 'personal' => false];
}

/**
 * The cap in force for a kind (0 = none). The stylesheet's can only go down
 * from what the CSS parser reads back: a sheet larger than CSS_MAX_BYTES would
 * break every command that parses it.
 */
function qs_small_file_cap(string $kind): int
{
    $cap = (int)(qs_quota_config()['max_file_bytes'][$kind] ?? 0);
    if ($kind === 'stylesheet') {
        require_once __DIR__ . '/utilsStyleManagement.php';   // CSS_MAX_BYTES
        return $cap > 0 ? min($cap, CSS_MAX_BYTES) : CSS_MAX_BYTES;
    }
    return $cap;
}

/** How a refusal names a kind of file. */
function qs_small_file_label(string $kind): string
{
    return [
        'page'        => 'a page structure',
        'structure'   => 'a menu, footer, component or consent-layer structure',
        'stylesheet'  => 'the stylesheet',
        'translation' => 'a translation file',
        'routes'      => 'the routes file',
        'snippet'     => 'a snippet',
        'settings'    => 'a project settings file',
    ][$kind] ?? 'a file';
}

/**
 * THE check a command runs before it writes small files. Given every file the
 * command is about to write and the exact number of bytes it will write there
 * (null: the file is removed), it answers the refusal, or null to go on:
 *
 *   1. a file over the cap for its kind, and larger than it is now → 413
 *      `validation.size_limit_exceeded` (a file over a cap lowered after it was
 *      written may still shrink);
 *   2. the owner already over the storage ceiling (the cached figure) and the
 *      files growing in total → 507 `quota.storage_exceeded`. A write that keeps
 *      their size or shrinks them passes, so an owner can always clean up.
 *
 * Called once, with ALL the command's files, before the first of them is
 * written — so a refused command has written nothing. Files the engine derives
 * from these (generated scripts, page wrappers, the sitemap) follow their source
 * and are not passed.
 *
 * A command whose writes happen inside shared helpers, each reading what the one
 * before wrote, cannot list them up front: those helpers use
 * qs_small_write_guard() instead, the same two checks made write by write.
 *
 * @param array<string, int|null> $writes absolute path => bytes about to be written
 */
function qs_small_write_refusal(array $writes): ?ApiResponse
{
    // One entry per file: a path may reach here spelled two ways (a live and a
    // project copy that are the same file, a separator of each kind), and a file
    // counted twice would double its growth.
    $unique = [];
    foreach ($writes as $path => $bytes) {
        $unique[str_replace('\\', '/', (string)$path)] = $bytes;
    }

    $growth = 0;
    $where  = null;
    foreach ($unique as $path => $bytes) {
        $current = qs_small_file_size($path);
        $refusal = qs_small_write_cap_refusal($path, $bytes, $current);
        if ($refusal !== null) {
            return $refusal;
        }
        $growth += ($bytes ?? 0) - $current;
        $where ??= qs_small_file_relative($path);
    }
    return qs_small_write_growth_refusal($growth, $where);
}

/** A file's size on disk now (0 when it does not exist). */
function qs_small_file_size(string $path): int
{
    clearstatcache(true, $path);
    return is_file($path) ? (int)filesize($path) : 0;
}

/** The cap check for one file: the 413 to answer, or null. */
function qs_small_write_cap_refusal(string $path, ?int $bytes, int $current): ?ApiResponse
{
    if ($bytes === null) {
        return null;
    }
    $kind = qs_small_file_kind($path);
    if ($kind === null) {
        return null;
    }
    $cap = qs_small_file_cap($kind);
    // A file already over its cap (the cap was lowered after it was written) may
    // still shrink or keep its size: refusing that would freeze it.
    if ($cap <= 0 || $bytes <= $cap || $bytes <= $current) {
        return null;
    }
    require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
    $rel = qs_small_file_relative($path);
    return ApiResponse::create(413, 'validation.size_limit_exceeded')
        ->withMessage('This change would make ' . qs_small_file_label($kind) . ' ' . qs_format_size($bytes)
            . ', over the ' . qs_format_size($cap) . ' this server allows for it. Nothing was saved.')
        ->withData([
            'kind'      => $kind,
            'file'      => $rel['path'] ?? '',
            'bytes'     => $bytes,
            'max_bytes' => $cap,
            'max_human' => qs_format_size($cap),
            'setting'   => 'max_file_bytes.' . $kind,
        ]);
}

/** The over-quota check for a write that grows its owner's files by $growth: the 507, or null. */
function qs_small_write_growth_refusal(int $growth, ?array $where): ?ApiResponse
{
    if ($growth <= 0 || $where === null || !qs_quota_storage_limited()) {
        return null;
    }
    $callerId = (string)(function_exists('getCurrentUser') ? (getCurrentUser()['id'] ?? '') : '');
    $ownerId  = $where['personal'] ? (string)$where['owner'] : qs_quota_storage_owner((string)$where['project'], $callerId);
    $breach   = qs_quota_check_growth($ownerId, $growth, $callerId === '' ? null : $callerId);
    if ($breach === null) {
        return null;
    }
    require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
    return ApiResponse::create(507, 'quota.storage_exceeded')
        ->withMessage($breach['message'])
        ->withData($breach['data']);
}

/**
 * The write-by-write form of the check, for a command whose files are written by
 * shared helpers (a registry, then translations, then a generated page): each
 * helper calls it just before it writes a file, with the bytes it is about to
 * write (null: it removes the file).
 *
 * The command's guarded files form one journal: the first time a file is guarded
 * its bytes are kept. The cap is checked on this file; the growth is the net
 * growth of every file guarded so far against what they held before the
 * command. On a refusal every guarded file is put back as it was (removed when
 * it did not exist, with any folder the command made for it), the refusal is
 * kept for qs_small_write_refused(), and false is returned: the helper writes
 * nothing and reports failure, and the command answers the refusal.
 *
 * Generated files a chain writes (a page wrapper beside a page) are guarded too,
 * so a refusal takes them back; they have no kind, so no cap.
 */
function qs_small_write_guard(string $path, ?int $bytes): bool
{
    $journal = &qs_small_write_journal();
    if ($journal['refused'] !== null) {
        return false;   // the command was refused already: nothing more is written
    }
    $norm = str_replace('\\', '/', $path);
    if (!array_key_exists($norm, $journal['files'])) {
        $journal['files'][$norm] = is_file($norm) ? (string)@file_get_contents($norm) : null;
        // The folders this write will create, deepest last, so a refusal can take
        // back an empty folder the command made.
        $missing = [];
        for ($dir = dirname($norm); $dir !== '' && $dir !== '.' && !is_dir($dir); $dir = dirname($dir)) {
            $missing[] = $dir;
            if (dirname($dir) === $dir) {
                break;
            }
        }
        foreach (array_reverse($missing) as $dir) {
            $journal['dirs'][] = $dir;
        }
    }

    $refusal = qs_small_write_cap_refusal($norm, $bytes, qs_small_file_size($norm));
    if ($refusal === null) {
        $growth = 0;
        foreach ($journal['files'] as $file => $before) {
            $after   = $file === $norm ? ($bytes ?? 0) : qs_small_file_size($file);
            $growth += $after - ($before === null ? 0 : strlen($before));
        }
        $refusal = qs_small_write_growth_refusal($growth, qs_small_file_relative($norm));
    }
    if ($refusal === null) {
        return true;
    }

    qs_small_write_rollback($journal);
    $journal['refused'] = $refusal;
    return false;
}

/** The refusal qs_small_write_guard() met during this command, or null. */
function qs_small_write_refused(): ?ApiResponse
{
    return qs_small_write_journal()['refused'];
}

/**
 * For a command that answers with ->send(): answer the refusal a guarded helper
 * met, if any (and stop there). Called after each step that writes through one.
 */
function qs_small_write_send_refusal(): void
{
    $refusal = qs_small_write_refused();
    if ($refusal !== null) {
        $refusal->send();
    }
}

/**
 * Keep a file's bytes in the command's journal without checking it: a file the
 * engine derives from a guarded one (the client route schema beside routes.php),
 * so a refusal puts it back byte for byte with its source. Called before it is
 * written.
 */
function qs_small_write_keep(string $path): void
{
    $journal = &qs_small_write_journal();
    $norm = str_replace('\\', '/', $path);
    if (!array_key_exists($norm, $journal['files'])) {
        $journal['files'][$norm] = is_file($norm) ? (string)@file_get_contents($norm) : null;
    }
}

/**
 * What to do, besides putting the guarded files back, when the command is
 * refused: undo what is not a file write (a rename). Registered by the helper
 * that did it.
 */
function qs_small_write_on_rollback(callable $undo): void
{
    $journal = &qs_small_write_journal();
    $journal['undo'][] = $undo;
}

/** The command's journal of guarded files (one per request). */
function &qs_small_write_journal(): array
{
    static $journal = ['files' => [], 'dirs' => [], 'undo' => [], 'refused' => null];
    return $journal;
}

/**
 * Put every guarded file back as it was before the command (a removed one
 * re-created in its folder), remove the folders the command made, and re-derive
 * what was derived from them.
 */
function qs_small_write_rollback(array $journal): void
{
    foreach ($journal['files'] as $file => $before) {
        if ($before === null) {
            if (is_file($file) && !@unlink($file)) {
                error_log('quota: could not remove ' . $file . ' while taking back a refused write');
            }
            continue;
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        if (@file_put_contents($file, $before, LOCK_EX) === false) {
            error_log('quota: could not restore ' . $file . ' while taking back a refused write');
        }
        if (function_exists('qs_opcache_invalidate') && substr($file, -4) === '.php') {
            qs_opcache_invalidate($file);
        }
    }
    foreach (array_reverse($journal['dirs']) as $dir) {
        if (is_dir($dir) && count(scandir($dir) ?: []) === 2) {
            @rmdir($dir);
        }
    }
    foreach ($journal['undo'] as $undo) {
        $undo();
    }
}

/**
 * Is this owner already over the storage ceiling, for a write that grows their
 * files by $growth bytes? Reads the CACHED figure — a small write must not walk
 * the disk — so a few bytes over the ceiling get through by design: the check
 * refuses growth while the owner is over, it does not stop the crossing.
 *
 * @return array{message:string, data:array}|null
 */
function qs_quota_check_growth(string $ownerId, int $growth, ?string $callerId = null): ?array
{
    $ceiling = qs_quota_config()['max_total_bytes'];
    if ($ceiling <= 0 || $growth <= 0) {
        return null;
    }
    $usage = qs_quota_usage($ownerId);
    if ($usage['bytes'] <= $ceiling) {
        return null;
    }

    if ($callerId !== null && $callerId !== $ownerId) {
        return [
            'message' => qs_quota_owner_scoped_message('edit', $ceiling),
            'data'    => [
                'growth_bytes' => $growth,
                'growth_human' => qs_format_size($growth),
                'quota_bytes'  => $ceiling,
                'quota_human'  => qs_format_size($ceiling),
                'owner_scoped' => true,
            ],
        ];
    }
    return [
        'message' => 'Your projects use ' . qs_format_size($usage['bytes']) . ' of your ' . qs_format_size($ceiling)
            . ' storage quota, so a change that makes a file larger cannot be saved; this one adds '
            . qs_format_size($growth) . '. A change that keeps a file\'s size or makes it smaller is still saved. '
            . 'Delete backups, exports or unused projects to free space.',
        'data'    => [
            'used_bytes'     => $usage['bytes'],
            'used_human'     => qs_format_size($usage['bytes']),
            'growth_bytes'   => $growth,
            'growth_human'   => qs_format_size($growth),
            'quota_bytes'    => $ceiling,
            'quota_human'    => qs_format_size($ceiling),
            'owned_projects' => $usage['projects'],
        ],
    ];
}

// ============================================================================
/// ============================================================================
// Rates — the upload rate and the write rate share ONE store and ONE window
// rule. The store is a small file per account, under a lock with temp/rename
// discipline, named by sha256 of the user id (the raw id never appears on disk):
// one account never waits on another's lock, and a deleted account leaves one
// stale file of a few bytes. Kept here rather than beside the login throttle
// because it is a resource control, not an auth one, and its knobs live in
// quota.php rather than auth.php.
//
// A window OPENS at the account's first counted upload or command and lasts its
// period; once it is over the allowance is whole again, and the next counted one
// opens the next window. "1,000 per hour" therefore means an hour from when the
// account starts, not the clock's hour. Windows do not slide: a caller can spend
// a whole allowance at the end of one window and another at the start of the
// next; that is the accepted trade for a store that keeps no per-request history.
// ============================================================================

/** The folder that holds one rate file per account. */
function qs_rate_store_dir(): string
{
    return SECURE_FOLDER_PATH . '/cache/rate';
}

/** Hash a user id for the rate store. Ids never sit there in clear. */
function qs_quota_user_key(string $userId): string
{
    return hash('sha256', $userId);
}

/** One account's rate file, read without a lock (a stale read only errs by one). */
function qs_rate_store_read(string $userId): array
{
    $path = qs_rate_store_dir() . '/' . qs_quota_user_key($userId) . '.json';
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Change one account's rate file under its lock. $fn(array &$data): mixed; its
 * result is returned, or false when the store cannot be written — in which case
 * nothing is enforced rather than everything refused.
 */
function qs_rate_store_mutate(string $userId, callable $fn)
{
    $dir = qs_rate_store_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('quota: cannot create the rate store folder');
        return false;
    }
    $path = $dir . '/' . qs_quota_user_key($userId) . '.json';
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    flock($lock, LOCK_EX);
    try {
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $result = $fn($data);

        $tmp = $path . '.tmp' . getmypid();
        // Encode checked separately from the write: `false . ''` writes an EMPTY
        // file and file_put_contents returns 0, not false, so a check on the
        // write alone lets a failed encode truncate the store. Integers only, so
        // unreachable today — checked because a rate store that silently emptied
        // itself would disable the limit without a trace.
        $json = json_encode($data);
        if ($json === false || file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * THE window rule, for the upload rate and the write rate alike: where one
 * window stands now, from its stored counter ({start, count}, or null).
 *
 * A window is open from `start` for $period seconds. When none is open (no
 * counter, a window that is over, or a counter in another form) the allowance
 * is whole and `start` is $now: the window the next counted one would open, so
 * a caller records {start, used + 1} either way.
 *
 * @return array{used:int, remaining:int, reset:int, resets_at:int, start:int}
 */
function qs_rate_window_state(?array $counter, int $max, int $period, int $now): array
{
    $start = (is_array($counter) && is_int($counter['start'] ?? null)) ? $counter['start'] : null;
    $open  = $start !== null && $start <= $now && $now < $start + $period;
    $used  = $open ? (int)($counter['count'] ?? 0) : 0;
    $start = $open ? $start : $now;
    return [
        'used'      => $used,
        'remaining' => max(0, $max - $used),
        'reset'     => $start + $period - $now,
        'resets_at' => $start + $period,
        'start'     => $start,
    ];
}

/**
 * Seconds the caller must wait before another upload (0 = go ahead).
 * Read-only — recording an attempt is a separate call, so a request refused for
 * some OTHER reason does not spend the caller's allowance.
 */
function qs_quota_rate_wait(string $userId): int
{
    $quota = qs_quota_config();
    if ($quota['rate_max_uploads'] <= 0 || $userId === '') {
        return 0; // unlimited
    }
    $state = qs_rate_window_state(qs_rate_store_read($userId)['upload'] ?? null,
        $quota['rate_max_uploads'], $quota['rate_period'], time());
    return $state['remaining'] > 0 ? 0 : $state['reset'];
}

/**
 * Record one upload against the caller's window. Called only on the paths that
 * actually consumed disk, AFTER the write succeeded — a refused upload has not
 * spent anything and must not spend an allowance either.
 *
 * No-op when the upload rate is unlimited, so it never writes the store for it.
 */
function qs_quota_record_upload(string $userId): void
{
    $quota = qs_quota_config();
    if ($quota['rate_max_uploads'] <= 0 || $userId === '') {
        return;
    }
    $period = $quota['rate_period'];
    $max    = $quota['rate_max_uploads'];
    qs_rate_store_mutate($userId, function (array &$data) use ($period, $max) {
        $state = qs_rate_window_state($data['upload'] ?? null, $max, $period, time());
        $data['upload'] = ['start' => $state['start'], 'count' => $state['used'] + 1];
        return true;
    });
}
/**
 * The sentence a caller gets when the rate limit refuses them.
 */
function qs_quota_rate_message(int $wait): string
{
    $quota = qs_quota_config();

    return 'You have reached the upload limit for this period ('
        . $quota['rate_max_uploads'] . ' per '
        . qs_quota_period_phrase($quota['rate_period'])
        . '). Try again in ' . $wait . ' second' . ($wait === 1 ? '' : 's') . '.';
}

/**
 * A period in words. Whole hours, minutes and days read as such; anything else
 * stays in seconds rather than being rounded into a number that is not the one
 * being enforced.
 */
function qs_quota_period_phrase(int $seconds): string
{
    if ($seconds % 86400 === 0) {
        $n = intdiv($seconds, 86400);
        return $n === 1 ? 'day' : $n . ' days';
    }
    if ($seconds % 3600 === 0) {
        $n = intdiv($seconds, 3600);
        return $n === 1 ? 'hour' : $n . ' hours';
    }
    if ($seconds % 60 === 0) {
        $n = intdiv($seconds, 60);
        return $n === 1 ? 'minute' : $n . ' minutes';
    }
    return $seconds . ' seconds';
}

// ============================================================================
// The write rate — every command but a read, counted per account by the
// management dispatcher before the command runs
// ============================================================================

/**
 * The commands the write rate never counts: every command of these categories,
 * and the reads that share a category with writes. Every other command counts,
 * so a new command counts until it is declared a read here. The public commands
 * (help, login, register) are answered before authentication and never reach
 * the count; they need no entry. The panel's batch
 * runner receives this list (qs_write_rate_panel()), so it counts what the
 * server counts.
 */
const QS_WRITE_RATE_READ_CATEGORIES = ['content.read', 'config.read'];
const QS_WRITE_RATE_READ_COMMANDS = [
    'listBackups', 'downloadExport', 'downloadBuild', 'getCommandHistory', 'listMembers',
    'getProjectRoster', 'listProjects', 'getLanguageList', 'logoutSession',
];

/** Every command the write rate never counts, sorted. */
function qs_write_rate_reads(): array
{
    static $reads = null;
    if ($reads !== null) {
        return $reads;
    }
    if (!function_exists('loadCategoriesConfig')) {
        require_once __DIR__ . '/AuthManagement.php';
    }
    $reads      = QS_WRITE_RATE_READ_COMMANDS;
    $categories = loadCategoriesConfig();
    foreach (QS_WRITE_RATE_READ_CATEGORIES as $category) {
        foreach (($categories[$category]['commands'] ?? []) as $command) {
            $reads[] = $command;
        }
    }
    $reads = array_values(array_unique($reads));
    sort($reads);
    return $reads;
}

/** Does this command count against the write rate? */
function qs_write_rate_counts(string $command): bool
{
    return !in_array($command, qs_write_rate_reads(), true);
}

/**
 * Where each window of the write rate stands for one account — read only.
 * $data and $now let the admission compute it inside the store's lock.
 *
 * @return list<array{max_commands:int, period_seconds:int, used:int, remaining:int, reset:int, resets_at:int, start:int}>
 */
function qs_write_rate_status(string $userId, ?array $data = null, ?int $now = null): array
{
    $now  ??= time();
    $data ??= $userId === '' ? [] : qs_rate_store_read($userId);
    $out    = [];
    foreach (qs_quota_config()['write_rate'] as $w) {
        $state = qs_rate_window_state($data['write'][(string)$w['period_seconds']] ?? null,
            $w['max_commands'], $w['period_seconds'], $now);
        $out[] = ['max_commands' => $w['max_commands'], 'period_seconds' => $w['period_seconds']] + $state;
    }
    return $out;
}

/** A window as an answer or the panel states it: its size, what is left, when it frees. */
function qs_write_rate_public(array $status): array
{
    return array_map(static fn($s) => [
        'max_commands'   => $s['max_commands'],
        'period_seconds' => $s['period_seconds'],
        'remaining'      => $s['remaining'],
        'reset'          => $s['reset'],
        'resets_at'      => gmdate('c', $s['resets_at']),
    ], $status);
}

/**
 * What the panel needs on load: every window as it stands, the batch runner's
 * patience, and the commands that never count. No window = the rate is off and
 * the panel shows nothing.
 */
function qs_write_rate_panel(string $userId): array
{
    return [
        'windows'      => qs_write_rate_public(qs_write_rate_status($userId)),
        'wait_seconds' => qs_quota_config()['write_rate_wait'],
        'reads'        => qs_write_rate_reads(),
        // Names this account in the browser's own storage (the batch runner's
        // kept steps), without putting its id there.
        'account'      => substr(qs_quota_user_key($userId), 0, 16),
    ];
}

/**
 * Admit one command against the caller's write rate. The management dispatcher
 * runs it once the caller is authenticated and allowed the command, before the
 * command runs:
 *
 *   - a read, an install with no window, or a caller with no id: null, nothing
 *     counted, no header;
 *   - every window has room: the command is counted in each (whatever it then
 *     answers), the RateLimit headers say what is left, null;
 *   - a window is full: nothing counted, the headers, Retry-After, and the 429
 *     naming the window that frees last.
 *
 * A store that cannot be written enforces nothing rather than refusing everything.
 */
function qs_write_rate_admit(string $userId, string $command): ?ApiResponse
{
    if (qs_quota_config()['write_rate'] === [] || $userId === '' || !qs_write_rate_counts($command)) {
        return null;
    }
    $result = qs_rate_store_mutate($userId, static function (array &$data) {
        $now    = time();
        $status = qs_write_rate_status('', $data, $now);
        $full   = array_values(array_filter($status, static fn($s) => $s['remaining'] <= 0));
        if ($full !== []) {
            return ['refused' => true, 'status' => $status, 'full' => $full];
        }
        foreach ($status as $s) {
            $data['write'][(string)$s['period_seconds']] = ['start' => $s['start'], 'count' => $s['used'] + 1];
        }
        return ['refused' => false, 'status' => qs_write_rate_status('', $data, $now)];
    });
    if (!is_array($result)) {
        return null;
    }

    qs_write_rate_send_headers($result['status']);
    if (!$result['refused']) {
        return null;
    }

    $full = $result['full'];
    usort($full, static fn($a, $b) => $b['reset'] <=> $a['reset']);
    $binding = $full[0];
    if (!headers_sent()) {
        header('Retry-After: ' . $binding['reset']);
    }
    require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
    return ApiResponse::create(429, 'quota.rate_limited')
        ->withMessage(qs_write_rate_message($binding))
        ->withData([
            'retry_after' => $binding['reset'],
            'resets_at'   => gmdate('c', $binding['resets_at']),
            'window'      => ['max_commands' => $binding['max_commands'], 'period_seconds' => $binding['period_seconds']],
            'windows'     => qs_write_rate_public($result['status']),
        ]);
}

/**
 * The allowance on a counted command's answer, in the two fields of the IETF
 * RateLimit header draft: `RateLimit-Policy` names each window ("3600";q=1000;
 * w=3600) and `RateLimit` says what is left of it and in how many seconds it
 * frees ("3600";r=937;t=1380). A window is named by its period.
 */
function qs_write_rate_send_headers(array $status): void
{
    if ($status === [] || headers_sent()) {
        return;
    }
    $policy = [];
    $left   = [];
    foreach ($status as $s) {
        $name     = '"' . $s['period_seconds'] . '"';
        $policy[] = $name . ';q=' . $s['max_commands'] . ';w=' . $s['period_seconds'];
        $left[]   = $name . ';r=' . $s['remaining'] . ';t=' . $s['reset'];
    }
    header('RateLimit-Policy: ' . implode(', ', $policy));
    header('RateLimit: ' . implode(', ', $left));
    // A caller on an allowed origin can read them only when they are exposed;
    // an origin that is not allowed never gets this far.
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        header('Access-Control-Expose-Headers: RateLimit, RateLimit-Policy, Retry-After', false);
    }
}

/** The sentence a caller gets when the write rate refuses them. */
function qs_write_rate_message(array $window): string
{
    $wait = (int)$window['reset'];
    $in   = $wait < 120
        ? $wait . ' second' . ($wait === 1 ? '' : 's')
        : (int)ceil($wait / 60) . ' minutes';
    return 'You have reached this server\'s limit of ' . number_format((int)$window['max_commands'])
        . ' changes per ' . qs_quota_period_phrase((int)$window['period_seconds'])
        . '. It frees at ' . gmdate('H:i:s', (int)$window['resets_at']) . ' UTC, in ' . $in . '.';
}
