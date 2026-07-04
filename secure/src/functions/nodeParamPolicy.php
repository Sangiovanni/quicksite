<?php

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/classes/UrlPolicy.php';

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
            if (!is_string($name) || !is_string($value) || $value === '') {
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
