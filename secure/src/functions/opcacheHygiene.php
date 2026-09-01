<?php
/**
 * OPcache invalidation — the single application point.
 *
 * QuickSite keeps configuration in PHP files it also WRITES: `deploy.php`,
 * `environment.php`'s config, `roles.php`, `auth.php`, a project's `config.php`
 * and `routes.php`. Every one of those is read back with `require`, and
 * `require` on a cached file returns what was COMPILED, not what is on disk.
 *
 * ⚠ THE STALE DIRECTION IS THE DANGEROUS ONE. An operator turning deploying
 * OFF, or moving an install from development BACK to production, is closing a
 * control — and the closed state is exactly the one the cache withholds. With
 * PHP's defaults that is a two-second window, which is already wrong for a
 * control somebody is deliberately closing. On a production install running
 * `opcache.validate_timestamps=0` — a normal tuning — it never lets go at all
 * short of a restart. Measured on the deploy gate: writing `allow_deploy =>
 * false` and immediately re-rendering still showed the deploy control, while
 * DELETING the file worked, because absence is a filesystem question and
 * `require` is a compiled one.
 *
 * So every call passes force=true, and every call is best-effort: OPcache may
 * be absent (the CLI usually has it off), disabled, or — for a file another
 * process will read — in a different php-fpm pool, since OPcache memory is
 * per-pool. Free when the pool is shared, harmless when it is not. Callers that
 * care whether it happened read the return value; most correctly do not.
 *
 * WHY ONE FUNCTION. The same four lines were inlined at more than twenty sites
 * across commands, `src/functions/` and `src/classes/` — some suppressed, some
 * not — and the reasoning above lived in three of them and nowhere else
 * (beta.11 S3.10c). One home, one behaviour, one place to change if a future
 * PHP alters the semantics.
 *
 * WHY `src/functions/` AND NOT `utilsManagement.php`: `environment.php` is one
 * of its callers and travels into a production build, which carries only a
 * handful of files. utilsManagement.php does not travel, so putting this there
 * would make every built site fatal on its first environment read. This file is
 * in build.php's `$functionFiles` for the same reason.
 */

if (!function_exists('qs_opcache_invalidate')) {
    /**
     * Drop a file from OPcache so the next `require` reads what is on disk.
     *
     * @param string $path Absolute path to the PHP file that was just written
     * @return bool True when OPcache accepted the invalidation; false when
     *              OPcache is unavailable, disabled, or did not hold the file.
     */
    function qs_opcache_invalidate(string $path): bool
    {
        if (!function_exists('opcache_invalidate')) {
            return false;
        }
        // force=true, so it applies whether or not timestamp validation is on.
        return (bool) @opcache_invalidate($path, true);
    }
}
