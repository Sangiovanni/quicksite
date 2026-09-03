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
 * ── DEFAULTS ARE PERMISSIVE, DELIBERATELY ────────────────────────────────────
 *
 * With no `secure/management/config/quota.php`, NOTHING is limited. An existing
 * install must not start refusing uploads because it was updated. The deployer
 * opts in by copying the shipped `.example` and setting numbers that suit their
 * disk — the same shape (and the same fail-safe posture on a malformed file) as
 * `import-policy.php`.
 *
 * Note the asymmetry with `filePolicy.php`, and that it is intentional: a
 * malformed import policy falls back to the SAFE built-in allowlists, because
 * the safe answer there is "refuse more". Here the safe answer is "refuse
 * nothing" — a typo in a quota file must not lock every author out of their own
 * site. Both fail towards not breaking the install; that just points in
 * opposite directions for a deny-list and an allow-budget.
 *
 * ── WHAT "PER USER" MEANS, PRECISELY ─────────────────────────────────────────
 *
 * Usage is the total disk of the projects the CALLER OWNS, measured by
 * `spaceUsage.php` — the same measurement the account space report shows, so the
 * number the panel shows an author IS the number this code enforces against.
 * There is no second measurer.
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
 * straight through, so growth must never be cached: every write path that
 * enforces a quota calls `qs_invalidate_space_cache()` after it writes, and the
 * next check re-walks that project. The sum is therefore exact for growth.
 *
 * What can still age is a SHRINK — deleting a backup, an export or a whole
 * project elsewhere. That leaves the total stale-HIGH, i.e. the quota is
 * briefly stricter than reality, never looser. The existing escape hatch
 * already covers it: the dashboard's refresh control calls
 * GET /admin/self/space-usage?refresh=1, which re-measures AND rewrites the
 * cache entries, so a user who frees space and is refused can make the number
 * move immediately. The refusal message says so.
 */

/**
 * The effective quota: no limits, overridden by the optional config file.
 *
 * Every limit is "0 = unlimited", matching how PHP itself reads `post_max_size`
 * and how `qs_registration_config()` reads its own caps — a caller must never
 * treat 0 as "zero bytes allowed".
 *
 * @return array{max_total_bytes:int, rate_max_uploads:int, rate_period:int, configured:bool}
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
        error_log('quota: ignoring malformed quota.php (' . $e->getMessage() . '); no limits are in force');
        return $quota;
    }
    if (!is_array($configured)) {
        error_log('quota: quota.php did not return an array; no limits are in force');
        return $quota;
    }

    $quota['configured'] = true;

    // Read defensively: a non-int, a negative, or a missing key all mean "leave
    // this axis unlimited" rather than "refuse everything".
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

    return $quota;
}

/**
 * Total bytes the given user's owned projects occupy right now.
 *
 * Consumes `spaceUsage.php` directly rather than running the account report in
 * process: that command's job is to SHAPE the answer for the dashboard (sorted
 * rows, formatted sizes, per-category splits) and all this needs is the sum.
 * The measurement underneath is the same one, so the two can never disagree.
 *
 * @param string $userId Resolved caller id ('' → 0, fail-open like listProjects)
 * @return array{bytes:int, projects:int}
 */
function qs_quota_usage(string $userId): array
{
    if ($userId === '') {
        return ['bytes' => 0, 'projects' => 0];
    }

    $owned = qs_owned_projects($userId);
    $total = 0;
    foreach ($owned as $project) {
        $space  = qs_project_space($project);
        $total += (int)($space['total'] ?? 0);
    }

    return ['bytes' => $total, 'projects' => count($owned)];
}

/**
 * Would writing $incomingBytes for this user cross the storage ceiling?
 *
 * Returns null when the write is allowed — including for every install with no
 * quota file, which is the common case and costs no disk walk at all: the
 * unlimited check short-circuits BEFORE the measurement.
 *
 * ⚠ $ownerId IS THE ACCOUNT THAT RECEIVES THE BYTES, not the one sending them.
 * An asset uploaded into a project is charged to that project's OWNER, because
 * that is whose disk grows. Charging the caller instead let any member with
 * upload rights push an owner past their quota while spending none of their
 * own — the caller's own projects were measured, found small, and waved
 * through. The upload RATE limit is the opposite case and stays on the caller:
 * it bounds what one actor does, not where the bytes land.
 *
 * @param string      $ownerId  Account whose disk receives the bytes
 * @param int         $incomingBytes
 * @param string|null $callerId Who is asking, when that is not the owner.
 *                              Null means caller === owner (the usual case).
 * @return array{message:string, data:array}|null
 */
function qs_quota_check_storage(string $ownerId, int $incomingBytes, ?string $callerId = null): ?array
{
    $quota = qs_quota_config();
    if ($quota['max_total_bytes'] <= 0) {
        return null; // unlimited — do not pay for a measurement nobody reads
    }

    $usage = qs_quota_usage($ownerId);
    $after = $usage['bytes'] + max(0, $incomingBytes);
    if ($after <= $quota['max_total_bytes']) {
        return null;
    }

    $remaining = max(0, $quota['max_total_bytes'] - $usage['bytes']);

    // A caller who is NOT the owner is told the outcome and nothing else. The
    // usage total and project count aggregate every project that owner has,
    // including ones this caller is not a member of and cannot otherwise see,
    // so spelling them out here would disclose them. The quota ceiling itself
    // is install-wide and not owner-specific, so it may still be named.
    if ($callerId !== null && $callerId !== $ownerId) {
        return [
            'message' => 'The owner of this project has no storage space left, so nothing '
                . 'more can be added to it. Ask them to free space — the ceiling on this '
                . 'server is ' . qs_format_size($quota['max_total_bytes']) . ' per account.',
            'data' => [
                'incoming_bytes' => max(0, $incomingBytes),
                'incoming_human' => qs_format_size(max(0, $incomingBytes)),
                'quota_bytes'    => $quota['max_total_bytes'],
                'quota_human'    => qs_format_size($quota['max_total_bytes']),
                'owner_scoped'   => true,
            ],
        ];
    }

    return [
        'message' => 'This would put your projects over your storage quota. They currently use '
            . qs_format_size($usage['bytes']) . ' of ' . qs_format_size($quota['max_total_bytes'])
            . ', and this upload adds ' . qs_format_size(max(0, $incomingBytes)) . '. '
            . ($remaining > 0
                ? 'You have ' . qs_format_size($remaining) . ' left. '
                : 'You have no room left. ')
            . 'Delete backups, exports or unused projects to free space — then refresh the '
            . 'storage figure on the dashboard, because a measurement can be up to '
            . QS_SPACE_CACHE_TTL . ' seconds old after a deletion.',
        'data' => [
            'used_bytes'        => $usage['bytes'],
            'used_human'        => qs_format_size($usage['bytes']),
            'incoming_bytes'    => max(0, $incomingBytes),
            'incoming_human'    => qs_format_size(max(0, $incomingBytes)),
            'quota_bytes'       => $quota['max_total_bytes'],
            'quota_human'       => qs_format_size($quota['max_total_bytes']),
            'remaining_bytes'   => $remaining,
            'remaining_human'   => qs_format_size($remaining),
            'owned_projects'    => $usage['projects'],
        ],
    ];
}

// ============================================================================
// Upload rate — a small state file with flock + temp/rename discipline, keyed
// by sha256 of the user id (the raw id never sits in the state file). Same
// shape as the login and registration throttles in SessionManagement.php; kept
// here rather than there because it is a resource control, not an auth one, and
// its knobs live in quota.php rather than auth.php.
// ============================================================================

function qs_upload_throttle_path(): string
{
    return SECURE_FOLDER_PATH . '/management/config/upload-throttle.json';
}

/**
 * Shared mutate for the upload-throttle file. $fn(array &$data): mixed.
 * Shape: [sha256(userId) => ['window' => int, 'count' => int, 'last' => int]]
 */
function qs_upload_throttle_mutate(callable $fn)
{
    $path = qs_upload_throttle_path();
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

        // Prune entries whose window is long over. Two periods of slack so a
        // caller mid-window is never dropped and silently granted a fresh
        // allowance; a day minimum so a tiny period cannot thrash the file.
        $quota  = qs_quota_config();
        $cutoff = time() - max(86400, $quota['rate_period'] * 2);
        foreach ($data as $key => $entry) {
            if ((int)($entry['last'] ?? 0) < $cutoff) {
                unset($data[$key]);
            }
        }

        $tmp = $path . '.tmp' . getmypid();
        // Encode checked separately from the write: `false . ''` writes an EMPTY
        // file and file_put_contents returns 0, not false, so a check on the
        // write alone lets a failed encode truncate the store. Hashed keys and
        // integers only, so unreachable today — checked because a rate store
        // that silently emptied itself would disable the limit without a trace.
        $json = json_encode($data, JSON_PRETTY_PRINT);
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
 * Seconds the caller must wait before another upload (0 = go ahead).
 * Read-only — recording an attempt is a separate call, so a request refused for
 * some OTHER reason does not spend the caller's allowance.
 *
 * Fixed windows, like the registration throttle: the window number is
 * `intdiv(now, period)`, so an allowance refills on a period boundary rather
 * than sliding. A caller can therefore burst across a boundary; that is the
 * accepted trade for a state file with no per-request history, and the byte
 * ceiling is what bounds the damage of a burst.
 */
function qs_quota_rate_wait(string $userId): int
{
    $quota = qs_quota_config();
    if ($quota['rate_max_uploads'] <= 0 || $userId === '') {
        return 0; // unlimited
    }

    $path = qs_upload_throttle_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data)) {
        return 0;
    }

    $entry = $data[qs_quota_user_key($userId)] ?? null;
    if (!is_array($entry)) {
        return 0;
    }

    $now    = time();
    $period = $quota['rate_period'];
    if ((int)($entry['window'] ?? -1) !== intdiv($now, $period)) {
        return 0; // a window that is not the current one has no hold on us
    }
    if ((int)($entry['count'] ?? 0) < $quota['rate_max_uploads']) {
        return 0;
    }

    return $period - ($now % $period);
}

/** Hash a user id for the throttle store. Ids never sit there in clear. */
function qs_quota_user_key(string $userId): string
{
    return hash('sha256', $userId);
}

/**
 * Record one upload against the caller's window. Called only on the paths that
 * actually consumed disk, AFTER the write succeeded — a refused upload has not
 * spent anything and must not spend an allowance either.
 *
 * No-op when the rate axis is unlimited, so an install with no quota file never
 * creates the state file at all.
 */
function qs_quota_record_upload(string $userId): void
{
    $quota = qs_quota_config();
    if ($quota['rate_max_uploads'] <= 0 || $userId === '') {
        return;
    }

    $key    = qs_quota_user_key($userId);
    $period = $quota['rate_period'];
    qs_upload_throttle_mutate(function (array &$data) use ($key, $period) {
        $now    = time();
        $window = intdiv($now, $period);
        $entry  = $data[$key] ?? null;
        $count  = (is_array($entry) && (int)($entry['window'] ?? -1) === $window)
            ? (int)($entry['count'] ?? 0)
            : 0;
        $data[$key] = ['window' => $window, 'count' => $count + 1, 'last' => $now];
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
