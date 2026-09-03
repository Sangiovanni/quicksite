<?php
require_once __DIR__ . '/opcacheHygiene.php';
/**
 * The command-console gate (beta.11 S6.5).
 *
 * ONE question, asked from one place: does this installation offer the admin
 * panel's generic command console at /admin/command?
 *
 * ⚠⚠ THIS IS NOT A SECURITY CONTROL AND MUST NOT BE DESCRIBED AS ONE. Every
 * command run from the console goes through the same `hasPermission` check as
 * a direct POST to /management/, so the console confers NO privilege: a
 * signed-in caller can already run every command it lists, by hand, with curl,
 * on an install where this is off. What turning it off removes is
 * DISCOVERABILITY AND REACH — a tenant no longer holds a generic runner for
 * 153 commands, and the blast radius of a future authorization bug is narrower
 * because fewer people are pointed at the surface. That is worth doing. It is
 * not a boundary, and code that later leans on it as one is leaning on nothing.
 *
 * ⚠⚠ ABSENT MEANS ALLOWED — INVERTED FROM deployPolicy.php, DELIBERATELY.
 *
 *   deploy.php  absent ⇒ DENIED.  Deploying writes generated PHP outside the
 *                                 project's own storage. An install nobody
 *                                 configured must not do that.
 *   console.php absent ⇒ ALLOWED. The console writes nothing and grants
 *                                 nothing. A fresh install that has never been
 *                                 configured should have its developer tooling.
 *
 * The two files look alike and default in opposite directions, so the rule is
 * written down rather than left to be inferred: FAIL-CLOSED IS RIGHT FOR A
 * CAPABILITY THAT GRANTS SOMETHING, AND WRONG FOR A VIEW ONTO CAPABILITIES THE
 * CALLER ALREADY HAS. Do not "fix" this to match deploy.
 *
 * THE DEFAULT AND THE FAILURE MODE ANSWER DIFFERENT QUESTIONS, so they differ:
 *
 *   - ABSENT is "never configured" — the fresh install, and it gets the
 *     console.
 *   - A file that is PRESENT but cannot be read is "configured, and the answer
 *     is unreadable" — and it resolves to OFF.
 *
 * That is not an inconsistency. This file only ever gets created by an operator
 * turning the console OFF: on needs no file, and setup declines to write one
 * for it (see item 9 in setup.sh / setup.bat). So the file's mere existence is
 * evidence somebody said no, and resolving a typo in it to the permissive
 * answer would silently re-open the console they closed. The other way round
 * the failure is loud and harmless — the console is missing, the error log says
 * why, and the operator fixes one line.
 *
 * Concretely, ALLOWED requires the file to be absent, or to be a well-formed
 * PHP file returning an array whose `allow_console` is boolean `true`.
 * Everything else — unreadable, a syntax error, a file that is not PHP, a wrong
 * return type, a missing key, `false`, and any truthy-but-not-`true` value like
 * the string 'true', 1 or 'yes' — answers DENIED. The strictness is deploy's,
 * for deploy's reason: a value the operator THINKS is on, refused, is what
 * makes them look at the file rather than at a console that half-works.
 *
 * PRODUCTION-STYLE FAILURE HANDLING, for the two hazards environment.php and
 * deployPolicy.php both document: `@require` does not suppress a `ParseError`,
 * and `require` on a file that is not PHP ECHOES its contents. So the require
 * is wrapped in a try/catch inside an output buffer that is always discarded.
 *
 * ⚠ THE OPCACHE INVALIDATION IS LOAD-BEARING, and the dangerous stale direction
 * is the same one deployPolicy.php's comment describes at length — read it
 * there rather than have it restated here. The case is an operator editing an
 * EXISTING console.php from `true` to `false`: the file is already compiled, so
 * `require` hands back `true` until the cache lets go, which on an install
 * running `opcache.validate_timestamps=0` is never, short of a restart.
 * (Creating the file where none existed needs no invalidation — absence is a
 * filesystem question. It is the edit that needs it.)
 *
 * @return bool True when this installation offers the command console.
 */
function qs_console_allowed(): bool
{
    static $allowed = null;
    if ($allowed !== null) {
        return $allowed;
    }

    $path = __DIR__ . '/../../management/config/console.php';

    // NEVER CONFIGURED ⇒ ON. This is the fresh-install case and it is the whole
    // reason the default is inverted from deploy's.
    if (!is_file($path)) {
        return $allowed = true;
    }

    // Present but unreadable is a decision we cannot read, and the only reason
    // this file exists is that somebody turned the console off.
    if (!is_readable($path)) {
        error_log(
            'QuickSite: console.php is present but unreadable'
            . ' — the command console stays DISABLED.'
        );
        return $allowed = false;
    }

    // force=true, so it applies whether or not timestamp validation is on.
    qs_opcache_invalidate($path);

    $cfg = null;
    ob_start();
    try {
        $cfg = require $path;
    } catch (Throwable $e) {
        error_log(
            'QuickSite: ignoring malformed console.php (' . $e->getMessage() . ')'
            . ' — the command console stays DISABLED.'
        );
        $cfg = null;
    } finally {
        ob_end_clean();
    }

    return $allowed = (is_array($cfg) && ($cfg['allow_console'] ?? null) === true);
}
