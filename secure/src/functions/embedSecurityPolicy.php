<?php
require_once __DIR__ . '/setupSwitch.php';
/**
 * The Embed Security page's switch.
 *
 * ONE question, asked from one place: does this installation show the admin panel's Embed Security page
 * (/admin/embed-security), the read-only view of the embed policy (embed-policy.json)?
 *
 * A deployer may decide its authors have no use for it: the policy is set at deployment and no author can
 * change it from the panel. setup.sh / setup.bat item 11 writes `embed-security.php` with
 * `'show_embed_security' => false`; absent, the page is shown.
 *
 * ⚠⚠ THIS IS NOT A SECURITY CONTROL. Hidden, the page leaves the menu and says it was turned off; the embed
 * policy stays in force exactly as before, and getIframeSandbox keeps answering on /management to every role
 * that holds it. It removes a page's reach, never access, as the console's switch does (consolePolicy.php).
 *
 * The rule (absent means shown; present and unreadable, malformed or anything but boolean `true` means
 * hidden) and its reasons are qs_setup_switch()'s.
 *
 * @return bool true when this installation shows the Embed Security page
 */
function qs_embed_security_shown(): bool
{
    static $shown = null;
    if ($shown !== null) {
        return $shown;
    }
    return $shown = qs_setup_switch('embed-security.php', 'show_embed_security', 'the Embed Security page stays HIDDEN');
}
