<?php
/**
 * oauthStateStore.php — Server-side storage for the OAuth flow (beta.9 A1).
 *
 * Thin abstraction layer over the chosen storage medium. Current
 * implementation: PHP sessions (decision locked 2026-06-14 — see
 * docs/DESIGN_DECISIONS.md "OAuth state + session storage"). The
 * interface is intentionally tiny so a future swap to a file-based or
 * encrypted-at-rest backend stays a one-file change.
 *
 *
 * Two timescales:
 *
 *   Pre-auth state (~10 min, single-use)
 *     Generated at /auth/oauth/:provider/start, validated + consumed at
 *     /auth/oauth/:provider/callback. Stores the state token, PKCE
 *     verifier, provider id, and the requested returnTo URL.
 *
 *   Post-auth session (hours to days)
 *     Created after a successful callback. Maps an opaque sessionId
 *     (the value in the user's HttpOnly cookie) to the user record +
 *     provider tokens kept server-side. Tokens never reach JavaScript —
 *     this is the BFF (Backend-For-Frontend) custody pattern locked in
 *     Q1 of the OAuth design round.
 *
 *
 * Lazy session_start():
 *
 *   PHP sessions activate globally on session_start(). We call it ONLY
 *   inside this helper, so non-auth page renders pay no cost. The
 *   internal helper _oauthEnsureSession() does the one-time setup
 *   (cookie attrs, session name) and short-circuits on repeat calls
 *   within the same request.
 *
 *
 * Function-level guard so commands can include this alongside other
 * helpers without double-declaration errors.
 */

if (!function_exists('storeOAuthState')) {

/**
 * Ensure a PHP session is active for OAuth-related storage. Lazy: only
 * calls session_start() on the first invocation per request. Sets
 * cookie attrs to HttpOnly + Secure + SameSite=Lax so the session
 * cookie itself isn't a CSRF or XSS vector.
 *
 * The session name is namespaced (`qs_oauth_session`) so we don't
 * collide with any other session usage on the same host.
 */
function _oauthEnsureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('qs_oauth_session');
    session_set_cookie_params([
        'lifetime' => 0,       // session cookie (cleared on browser close;
                                // server-side TTL is independent and longer)
        'path'     => '/',
        'secure'   => _oauthIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Initialise the two top-level buckets so callers can blind-write.
    if (!isset($_SESSION['oauth_state']) || !is_array($_SESSION['oauth_state'])) {
        $_SESSION['oauth_state'] = [];
    }
    if (!isset($_SESSION['oauth_session']) || !is_array($_SESSION['oauth_session'])) {
        $_SESSION['oauth_session'] = [];
    }
}

/**
 * Detect whether the current request arrived over HTTPS. Honours the
 * common `X-Forwarded-Proto` reverse-proxy header alongside the direct
 * `$_SERVER['HTTPS']` + port 443 signals.
 *
 * Used to gate the `Secure` cookie attribute — browsers SILENTLY DROP
 * cookies marked `Secure` when the connection is plain HTTP, which
 * breaks the OAuth session round-trip in dev (state stored at /start
 * vanishes because the cookie was never accepted). Auto-detect keeps
 * dev (`http://local.quicksite`) working without sacrificing the
 * `Secure` attribute in production.
 */
function _oauthIsHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

/**
 * Store a pre-auth state record. Identified by `$id` (the value sent to
 * the provider as `state`); cleared on first read.
 */
function storeOAuthState(string $id, array $data, int $ttlSeconds): void {
    _oauthEnsureSession();
    $_SESSION['oauth_state'][$id] = [
        'data'       => $data,
        'expires_at' => time() + max(1, $ttlSeconds),
    ];
}

/**
 * Read AND clear (single-use) a pre-auth state record. Returns null if
 * unknown, expired, or already consumed.
 */
function consumeOAuthState(string $id): ?array {
    _oauthEnsureSession();
    if (!isset($_SESSION['oauth_state'][$id])) {
        return null;
    }
    $entry = $_SESSION['oauth_state'][$id];
    unset($_SESSION['oauth_state'][$id]);
    if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) < time()) {
        return null;
    }
    return is_array($entry['data'] ?? null) ? $entry['data'] : null;
}

/**
 * Create a post-auth session record. The caller owns `$sessionId`
 * generation (it becomes the value in the user's session cookie).
 * `$userData` is whatever the caller wants stored — typically `sub`,
 * `email`, `provider`, and the provider tokens.
 */
function storeOAuthSession(string $sessionId, array $userData, int $ttlSeconds): void {
    _oauthEnsureSession();
    $_SESSION['oauth_session'][$sessionId] = [
        'user'       => $userData,
        'expires_at' => time() + max(1, $ttlSeconds),
    ];
}

/**
 * Look up a post-auth session by its id. Returns null if unknown or
 * expired. Does NOT consume — sessions persist until explicit clear
 * or expiry.
 */
function getOAuthSession(string $sessionId): ?array {
    _oauthEnsureSession();
    if (!isset($_SESSION['oauth_session'][$sessionId])) {
        return null;
    }
    $entry = $_SESSION['oauth_session'][$sessionId];
    if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) < time()) {
        unset($_SESSION['oauth_session'][$sessionId]);
        return null;
    }
    return is_array($entry['user'] ?? null) ? $entry['user'] : null;
}

/**
 * Clear a post-auth session (logout). Idempotent — clearing a non-
 * existent session is not an error.
 */
function clearOAuthSession(string $sessionId): void {
    _oauthEnsureSession();
    unset($_SESSION['oauth_session'][$sessionId]);
}

/**
 * Prune any expired entries from both buckets. Best-effort housekeeping;
 * called opportunistically by storeOAuthState to keep the session record
 * from growing unbounded across long-running browser sessions.
 */
function _oauthPruneExpired(): void {
    _oauthEnsureSession();
    $now = time();
    foreach (['oauth_state', 'oauth_session'] as $bucket) {
        foreach ($_SESSION[$bucket] as $id => $entry) {
            if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) < $now) {
                unset($_SESSION[$bucket][$id]);
            }
        }
    }
}

} // end if (!function_exists('storeOAuthState'))
