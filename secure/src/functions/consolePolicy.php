<?php
require_once __DIR__ . '/setupSwitch.php';
/**
 * The command-console gate.
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
 * the whole command surface, and the blast radius of a future authorization bug is narrower
 * because fewer people are pointed at the surface. That is worth doing. It is
 * not a boundary, and code that later leans on it as one is leaning on nothing.
 *
 * ⚠⚠ ABSENT MEANS ALLOWED, DELIBERATELY. The console writes nothing and grants
 * nothing, so a fresh install that has never been configured should have its
 * developer tooling. environment.php defaults the other way (absent ⇒ production)
 * and looks alike, so the rule is written down rather than left to be inferred:
 * FAIL-CLOSED IS RIGHT FOR A CAPABILITY THAT GRANTS SOMETHING, AND WRONG FOR A VIEW
 * ONTO CAPABILITIES THE CALLER ALREADY HAS. Do not "fix" this to match it.
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
 * for it (see item 8 in setup.sh / setup.bat). So the file's mere existence is
 * evidence somebody said no, and resolving a typo in it to the permissive
 * answer would silently re-open the console they closed. The other way round
 * the failure is loud and harmless — the console is missing, the error log says
 * why, and the operator fixes one line.
 *
 * Concretely, ALLOWED requires the file to be absent, or to be a well-formed
 * PHP file returning an array whose `allow_console` is boolean `true`.
 * Everything else — unreadable, a syntax error, a file that is not PHP, a wrong
 * return type, a missing key, `false`, and any truthy-but-not-`true` value like
 * the string 'true', 1 or 'yes' — answers DENIED. A value the operator THINKS is
 * on, refused, is what makes them look at the file rather than at a console that
 * half-works.
 *
 * PRODUCTION-STYLE FAILURE HANDLING, for two measured hazards:
 *
 *   - `@require` does NOT suppress a `ParseError`, so an operator's typo in the
 *     config must be caught rather than allowed to end the request.
 *   - `require` on a file that is not PHP ECHOES its contents, which would
 *     prepend stray bytes to the JSON envelope. The require runs inside an
 *     output buffer that is always discarded.
 *
 * ⚠ THE OPCACHE INVALIDATION IS LOAD-BEARING, AND THE STALE DIRECTION IS THE
 * DANGEROUS ONE. This is the switch an operator uses to turn the console OFF, and
 * `require` on a cached file returns what was compiled, not what is on disk. With
 * PHP's defaults that is a two-second window, which is already wrong for a control
 * somebody is deliberately closing, and a production install running
 * `opcache.validate_timestamps=0` (a normal tuning) would never pick the change up
 * at all, short of a restart. Measured on a switch of this same shape: writing it
 * to false and immediately re-rendering the page still showed the control it
 * closed, while DELETING the file worked, because absence is a filesystem question
 * rather than a compiled one. The case here is an operator editing an EXISTING
 * console.php from `true` to `false`: the file is already compiled, so `require`
 * hands back `true` until the cache lets go. (Creating the file where none existed
 * needs no invalidation. It is the edit that needs it.)
 *
 * The mechanics live in qs_setup_switch() (setupSwitch.php), shared with the Embed Security page's switch.
 *
 * @return bool True when this installation offers the command console.
 */
function qs_console_allowed(): bool
{
    static $allowed = null;
    if ($allowed !== null) {
        return $allowed;
    }

    // The rule above, read by the one reader every setup switch shares (setupSwitch.php).
    return $allowed = qs_setup_switch('console.php', 'allow_console', 'the command console stays DISABLED');
}
