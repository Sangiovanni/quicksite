<?php

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/classes/UrlPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';

/**
 * Writer-side node-param safety — the reject-on-store companion to the render
 * gate (beta.10). The renderer already blocks raw `on*` handlers and
 * neutralises dangerous URL schemes AT RENDER; this rejects them at WRITE time
 * too, so stored JSON never holds the payload and the author gets an immediate
 * error instead of a silently-dropped attribute. Shared by addNode, editNode
 * and editStructure so all three apply the identical policy.
 *
 * Returns a human-readable error for the first unsafe param, or null if clean.
 */
if (!function_exists('firstUnsafeParam')) {
    function firstUnsafeParam(array $params): ?string {
        foreach ($params as $name => $value) {
            // ── THE NAME, BEFORE THE VALUE GUARD ──────────────────────────
            //
            // DEFENCE IN DEPTH, not the fix. The load-bearing gate is in the
            // two renderers (TagRegistry::isRenderableAttributeName): existing
            // projects already hold un-gated structures, and no write-side
            // check can retroactively clean data that is already stored. What
            // this adds is an immediate error for the author instead of an
            // attribute that silently disappears from the page.
            //
            // ⚠ It runs BEFORE the value guard on purpose. That guard skips a
            // non-string or empty value, and the compiler's boolean branch
            // emits the bare name — so a hostile name paired with `true` would
            // have walked straight past a check placed after it.
            //
            // Cast because PHP turns a numeric JSON object key into an int.
            $name = (string) $name;
            if (!TagRegistry::isRenderableAttributeName($name)) {
                return "Attribute name '{$name}' is not a valid HTML attribute name "
                     . '(letters, digits, underscore, colon and hyphen only).';
            }

            if (!is_string($value) || $value === '') {
                continue;
            }
            // Raw event handler — must use {{call:...}} (mirrors renderAttribute).
            if (preg_match('/^on[a-z]+$/i', $name) && strpos($value, '{{call:') === false) {
                return "Attribute '{$name}' must use {{call:...}} syntax, not raw JavaScript.";
            }
            // Dangerous URL scheme on a URL-sink attribute (mirrors UrlPolicy).
            // sanitize() returns '#' for a disallowed scheme / control chars;
            // guard the legitimate literal '#' anchor so it isn't rejected.
            if (UrlPolicy::isUrlAttribute($name)
                && UrlPolicy::sanitize($value) === '#'
                && ltrim($value, " \t\n\r\0\x0B\f") !== '#'
            ) {
                return "Attribute '{$name}' uses a disallowed URL scheme (only http, https, mailto, tel are allowed).";
            }
        }
        return null;
    }
}
