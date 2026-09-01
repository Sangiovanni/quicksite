<?php
require_once __DIR__ . '/opcacheHygiene.php';
/**
 * The self-deploy gate (beta.11 S3.8).
 *
 * ONE question, asked from one place: may this installation deploy a build
 * onto a filesystem path at all?
 *
 * `deployBuild` copies a build — generated PHP included — into SERVER_ROOT or a
 * root listed in `deploy-roots.php`. It needs no credentials of any kind: the
 * install writes to itself. That is a legitimate feature and it is also a
 * capability a deployer may simply not want their users to have. This is the
 * switch for it.
 *
 * TWO INDEPENDENT AXES, and they are easy to confuse:
 *
 *   - THIS gate answers *whether* anyone may deploy. Absent ⇒ nobody may.
 *   - `deploy-roots.php` answers *where* a permitted deploy may write. Absent ⇒
 *     SERVER_ROOT only.
 *
 * A third axis, the role, is enforced by the dispatcher: `deployBuild` sits
 * alone in the `deploy` category, granted to admin and owner. All three must
 * pass; none of them substitutes for another.
 *
 * ⚠ ABSENT MEANS DENIED. This follows `environment.php` (absent ⇒ production),
 * NOT `quota.php` (absent ⇒ no limits). Deploying is the one command that
 * writes outside the project's own storage, so the install that never opted in
 * is the install that must not do it. This is a BEHAVIOUR CHANGE: before this
 * file existed, `deployBuild` worked on an install with no configuration at all.
 *
 * PRODUCTION-STYLE FAILURE HANDLING, for the same two hazards `environment.php`
 * documents and for the same measured reasons:
 *
 *   - `@require` does NOT suppress a `ParseError`, so a deployer's typo in the
 *     config must be caught rather than allowed to end the request.
 *   - `require` on a file that is not PHP ECHOES its contents, which would
 *     prepend stray bytes to the JSON envelope. The require runs inside an
 *     output buffer that is always discarded.
 *
 * Every failure path — absent, unreadable, syntactically broken, not PHP, wrong
 * shape, missing key, wrong type, or any value that is not boolean true —
 * answers DENIED.
 *
 * @return bool True only when a well-formed deploy.php says so.
 */
function qs_deploy_allowed(): bool
{
    static $allowed = null;
    if ($allowed !== null) {
        return $allowed;
    }

    $path = __DIR__ . '/../../management/config/deploy.php';
    if (!is_file($path) || !is_readable($path)) {
        return $allowed = false;
    }

    // ⚠ OPCACHE WOULD OTHERWISE HOLD THE OLD ANSWER, AND THE STALE DIRECTION IS
    // THE DANGEROUS ONE. This is the switch an operator uses to turn deploying
    // OFF; `require` on a cached file returns what was compiled, not what is on
    // disk. With PHP's defaults that is a two-second window, which is already
    // wrong for a control somebody is deliberately closing — and a production
    // install running `opcache.validate_timestamps=0` (a normal tuning) would
    // never pick the change up at all, short of a restart. Measured: writing
    // `allow_deploy => false` and immediately re-rendering the page still
    // showed the deploy control; DELETING the file worked, because absence is a
    // filesystem question rather than a compiled one.
    //
    // force=true, so it applies whether or not timestamp validation is on.
    // loadRolesConfig() does the same thing for the same reason.
    qs_opcache_invalidate($path);

    $cfg = null;
    ob_start();
    try {
        $cfg = require $path;
    } catch (Throwable $e) {
        error_log(
            'QuickSite: ignoring malformed deploy.php (' . $e->getMessage() . ')'
            . ' — deploy stays DISABLED.'
        );
        $cfg = null;
    } finally {
        ob_end_clean();
    }

    // Strictly boolean true. A string 'true', a 1, or a 'yes' is a config the
    // deployer THINKS is on; refusing it is what makes them look at the file
    // rather than at a deploy that half-works.
    return $allowed = (is_array($cfg) && ($cfg['allow_deploy'] ?? null) === true);
}
