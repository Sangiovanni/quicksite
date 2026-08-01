<?php
/**
 * The runtime-environment gate (beta.10 C12).
 *
 * ONE question, asked from one place: is this install running in development?
 *
 * Before this file the answer lived in a `private static` inside
 * `OutboundUrlPolicy`, so the SSRF guard could ask it and nothing else could.
 * The management dispatcher therefore had its own, different test — it looked
 * only at a bootstrap `ENVIRONMENT` constant and never read
 * `management/config/environment.php`. A deployer who set that file to
 * 'development' got the SSRF internal-range lift but NOT the matching debug
 * output: one setting, two answers. This is the single source of truth.
 *
 * Resolution order:
 *   1. An `ENVIRONMENT` constant, when a bootstrap defines one (highest
 *      precedence, so an embedding host can pin the answer).
 *   2. `secure/management/config/environment.php` (gitignored; ships as
 *      `.example`).
 *   3. Production.
 *
 * PRODUCTION IS THE DEFAULT AND EVERY FAILURE PATH LEADS BACK TO IT. Absent,
 * unreadable, syntactically broken, not-PHP, wrong shape, missing key, wrong
 * type, or any value other than the exact string 'development' — all produce
 * production. Only a well-formed file saying exactly 'development' opens the
 * gate.
 *
 * Two hazards this function exists to contain, both measured rather than
 * assumed (C12 slice 12.0, suite `12_0_leak_surface.php` §D):
 *
 *   - **A syntax error must not take the request down.** `@require` does NOT
 *     suppress a `ParseError` — verified on PHP 8.0.30 and 8.4.0 — so the old
 *     `@require` meant a deployer's typo fatally ended every request that
 *     touched the outbound-URL policy. This is the identical defect C11 fixed
 *     for `import-policy.php`; the precedent it set (`filePolicy.php`: catch,
 *     keep safe defaults, log so the mistake is discoverable) is followed here.
 *
 *   - **A malformed config must not inject bytes into a response.** `require`
 *     on a file that is not PHP ECHOES its contents. A config file full of
 *     stray text would prepend that text to whatever the request was about to
 *     return — corrupting a JSON envelope exactly the way a UTF-8 BOM does.
 *     The require runs inside an output buffer that is always discarded.
 *
 * Deliberately dependency-free and PRE-INIT SAFE: it defines nothing, requires
 * nothing, and locates the config relative to `__DIR__` rather than through
 * `SECURE_FOLDER_PATH`, so it is callable before the bootstrap has run. That is
 * what lets the fatal handlers — which must work when init.php itself failed —
 * ask the question at all.
 *
 * @return bool True only in a deliberately-configured development install.
 */
function qs_is_development(): bool
{
    static $isDev = null;
    if ($isDev !== null) {
        return $isDev;
    }

    if (defined('ENVIRONMENT')) {
        return $isDev = (ENVIRONMENT === 'development');
    }

    $path = __DIR__ . '/../../management/config/environment.php';
    if (!is_file($path) || !is_readable($path)) {
        return $isDev = false;
    }

    $cfg = null;
    // The buffer catches a non-PHP file's contents; the catch keeps a syntax
    // error from ending the request. Both paths fall through to production.
    ob_start();
    try {
        $cfg = require $path;
    } catch (Throwable $e) {
        error_log(
            'QuickSite: ignoring malformed environment.php (' . $e->getMessage() . ')'
            . ' — running as PRODUCTION.'
        );
        $cfg = null;
    } finally {
        ob_end_clean();
    }

    $value = (is_array($cfg) && isset($cfg['environment']) && is_string($cfg['environment']))
        ? $cfg['environment']
        : 'production';

    return $isDev = ($value === 'development');
}

/**
 * The environment name, for the rare caller that wants to SAY which mode it is
 * in rather than branch on it. Never returns anything but these two strings —
 * an unrecognised configured value reads as production here too, so a log line
 * can never claim a mode the gate does not actually grant.
 */
function qs_environment_name(): string
{
    return qs_is_development() ? 'development' : 'production';
}
