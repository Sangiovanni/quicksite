<?php
require_once __DIR__ . '/opcacheHygiene.php';
/**
 * The reader of a setup switch: a config file setup.sh / setup.bat writes only to turn a page of the panel
 * OFF (console.php for the command console, embed-security.php for the Embed Security page). One reader, so
 * the switches cannot drift apart in how they read.
 *
 * ⚠⚠ A SWITCH IS NOT A SECURITY CONTROL. Each one removes a page's reach, never access: the commands behind
 * the page keep answering on /management to every caller whose role allows them.
 *
 * THE RULE, and why it is the same for every switch:
 *
 *   - ABSENT is "never configured", the fresh install, and it means ON: the pages a switch governs grant
 *     nothing, so an install nobody configured keeps them.
 *   - A file that is PRESENT but cannot be read, does not parse, is not PHP, returns no array, lacks the
 *     key, or holds anything but boolean `true` means OFF. The file only ever exists because somebody turned
 *     the page off (setup declines to write one for on), so a typo resolving the permissive way would
 *     silently bring back what they took away. The failure is loud instead: the page is missing and the
 *     error log says why.
 *
 * Two measured hazards are handled: `@require` does not suppress a ParseError, so it is caught; and
 * `require` on a file that is not PHP ECHOES its contents, so it runs inside an output buffer that is always
 * discarded. The file is invalidated in OPcache before it is read: an operator editing an existing switch
 * from true to false would otherwise keep reading the compiled `true` (see consolePolicy.php).
 *
 * @param string $file    the file's name in the config folder
 * @param string $key     the boolean it holds
 * @param string $offText what the log says stays off ("the command console stays DISABLED")
 * @return bool true when the switch is on
 */
function qs_setup_switch(string $file, string $key, string $offText): bool
{
    $path = __DIR__ . '/../../management/config/' . $file;

    if (!is_file($path)) {
        return true;
    }

    if (!is_readable($path)) {
        error_log('QuickSite: ' . $file . ' is present but unreadable — ' . $offText . '.');
        return false;
    }

    // force=true, so it applies whether or not timestamp validation is on.
    qs_opcache_invalidate($path);

    $cfg = null;
    ob_start();
    try {
        $cfg = require $path;
    } catch (Throwable $e) {
        error_log('QuickSite: ignoring malformed ' . $file . ' (' . $e->getMessage() . ') — ' . $offText . '.');
        $cfg = null;
    } finally {
        ob_end_clean();
    }

    return is_array($cfg) && ($cfg[$key] ?? null) === true;
}
