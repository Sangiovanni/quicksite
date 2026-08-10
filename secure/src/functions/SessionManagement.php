<?php
/**
 * Session Lifecycle Management — the PHP session IS the session (beta.11 S1).
 *
 * You log in on arrival and the browser session holds the login. There is no
 * access token, no refresh token, no rotation, no family, and no server-side
 * token store: `$_SESSION` holds the user id, the user's session GENERATION
 * (the kill switch — see AuthManagement's qs_user_generation) and one
 * per-session TOKEN. Everything a session needs is in the session file PHP
 * already keys by its own cookie.
 *
 * THE PER-SESSION TOKEN is not a credential on its own. The cookie identifies
 * the session; the token proves the caller could READ a page of this session
 * (it is embedded at render time and sent back as `Authorization: Bearer`).
 * That pairing is what keeps the cookie-authenticated management API safe from
 * cross-site request forgery: a foreign page can make the browser SEND the
 * cookie, but it cannot read the token, and it cannot set an Authorization
 * header on a cross-origin request without a preflight this install refuses.
 * A leaked token alone therefore grants nothing — the old access token did.
 *
 * Cookie: name QSSESSID, HttpOnly, SameSite=Lax, Secure under HTTPS, path '/'
 * so `/p/<id>/` (surface B) receives it on the same origin. Lax rather than
 * Strict so arriving from a bookmark or an external link does not look like
 * being signed out; cross-site POSTs carry no cookie, and the Authorization
 * pairing above is the actual CSRF gate.
 *
 * Storage: PHP's own session files, in an install-local directory
 * (`secure/tmp/sessions`) rather than the shared system path — otherwise any
 * OTHER application on the same host garbage-collects QuickSite's sessions at
 * ITS gc_maxlifetime and users are signed out mid-work for no visible reason.
 *
 * This file ALSO holds the login-attempt and registration flood controls. They
 * are brute-force protection, entirely independent of how a session is carried,
 * and they were never part of the token machinery — deleting them with it would
 * remove the only backstop against password guessing.
 */

/** The session cookie name — deliberately not PHP's default. */
const QS_SESSION_COOKIE = 'QSSESSID';

/**
 * Session knobs from auth.php (all optional, safe defaults).
 *
 * idle_ttl     — seconds of inactivity after which a session stops being
 *                accepted (the session's own lifetime; sliding, refreshed as
 *                the caller works).
 * remember_ttl — how long the "remember me" cookie survives a browser restart.
 *                Without it the cookie dies with the browser session.
 *
 * @return array{idle_ttl:int, remember_ttl:int}
 */
function qs_session_config(): array {
    $cfg = loadAuthConfig()['authentication']['session'] ?? [];
    return [
        'idle_ttl'     => max(300, (int)($cfg['idle_ttl'] ?? 86400)),
        'remember_ttl' => max(3600, (int)($cfg['remember_ttl'] ?? 2592000)),
    ];
}

/** Where PHP writes this install's session files (see the file header). */
function qs_session_save_path(): string {
    return SECURE_FOLDER_PATH . '/tmp/sessions';
}

/**
 * Is this cookie value shaped like a session id at all? A junk value would make
 * PHP mint (and write) a brand-new empty session for a caller who has none —
 * cheap litter, but avoidable. Matches PHP's own id alphabets across the
 * supported hash/bits-per-character settings, plus the ',' and '-' used by
 * base64-ish ids.
 */
function qs_session_id_shape_ok(string $id): bool {
    return preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id) === 1;
}

/**
 * Configure and start the session. Returns false when there is nothing to open
 * (read mode with no cookie) — the caller is simply anonymous.
 *
 * $forWrite=false starts with read_and_close: the data is read and the session
 * file is released immediately, so concurrent admin requests never serialize
 * behind each other's session lock, AND session_status() falls back to NONE so
 * a later session on the same request (the author's-site OAuth state store
 * names its own) can start cleanly.
 */
function qs_session_boot(bool $forWrite): bool {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true; // already open for this request (admin page flow)
    }
    $cookie = $_COOKIE[QS_SESSION_COOKIE] ?? null;
    if (!$forWrite && (!is_string($cookie) || !qs_session_id_shape_ok($cookie))) {
        return false;
    }

    $knobs = qs_session_config();
    $dir   = qs_session_save_path();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir)) {
        session_save_path($dir);
    }
    // PHP's own GC must outlive the longest thing we promise; our own idle
    // check below is what actually expires a session.
    ini_set('session.gc_maxlifetime', (string)max($knobs['idle_ttl'], $knobs['remember_ttl']));
    ini_set('session.use_strict_mode', '1'); // never adopt a caller-invented id
    session_name(QS_SESSION_COOKIE);
    session_set_cookie_params(qs_session_cookie_params(0));

    // Silenced deliberately: a stray PHP warning (headers already sent on a
    // late call) would land INSIDE a JSON response body. Failure is reported by
    // the return value, which every caller checks.
    return @session_start($forWrite ? [] : ['read_and_close' => true]);
}

/**
 * Is there a session to open at all? A write-mode boot creates one when there
 * is not, which is right for login and wrong for everything else — destroy and
 * touch use this so they never mint an empty session (and a Set-Cookie) for a
 * caller who has none.
 */
function qs_session_present(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    $cookie = $_COOKIE[QS_SESSION_COOKIE] ?? null;
    return is_string($cookie) && qs_session_id_shape_ok($cookie);
}

/**
 * The session cookie's attributes. $lifetime 0 = dies with the browser session
 * ("remember me" off).
 */
function qs_session_cookie_params(int $lifetime): array {
    return [
        'lifetime' => $lifetime,
        'path'     => '/', // surface B lives at /p/<id>/ on the same origin
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Drop the memoised snapshot after anything that changes the session. */
function qs_session_cache_reset(): void {
    $GLOBALS['__qs_session_snapshot'] = null;
    unset($GLOBALS['__qs_session_snapshot_done']);
}

/**
 * The current session's QuickSite state, or null when there is none.
 *
 * Read-only and side-effect-free for the caller: when the session was not
 * already open it is read with read_and_close and $_SESSION is put back exactly
 * as it was found, so a page that later runs its OWN session (the author's-site
 * OAuth store) is unaffected.
 *
 * @return array{uid:string, gen:int, token:string, seen:int, remember:bool}|null
 */
function qs_session_snapshot(): ?array {
    if (!empty($GLOBALS['__qs_session_snapshot_done'])) {
        return $GLOBALS['__qs_session_snapshot'];
    }
    $GLOBALS['__qs_session_snapshot_done'] = true;
    $GLOBALS['__qs_session_snapshot'] = null;

    if (session_status() === PHP_SESSION_ACTIVE) {
        $GLOBALS['__qs_session_snapshot'] = qs_session_extract($_SESSION);
        return $GLOBALS['__qs_session_snapshot'];
    }

    $hadSession = isset($GLOBALS['_SESSION']);
    $prior      = $hadSession ? $_SESSION : null;
    if (!qs_session_boot(false)) {
        return null;
    }
    $GLOBALS['__qs_session_snapshot'] = qs_session_extract($_SESSION);
    // read_and_close leaves $_SESSION populated with OUR data; hand the global
    // back in the state we found it.
    if ($hadSession) {
        $_SESSION = $prior;
    } else {
        unset($GLOBALS['_SESSION']);
    }
    return $GLOBALS['__qs_session_snapshot'];
}

/**
 * Pull the QuickSite keys out of a session array, or null when it holds no
 * login. Shape-checked: a half-written session is not a login.
 */
function qs_session_extract(array $data): ?array {
    $uid   = $data['qs_uid'] ?? null;
    $token = $data['qs_token'] ?? null;
    if (!is_string($uid) || $uid === '' || !is_string($token) || $token === '') {
        return null;
    }
    return [
        'uid'      => $uid,
        'gen'      => (int)($data['qs_gen'] ?? 0),
        'token'    => $token,
        'seen'     => (int)($data['qs_seen'] ?? 0),
        'remember' => !empty($data['qs_remember']),
    ];
}

/**
 * Log a user in: a fresh session id (never reuse the pre-login one — that is
 * the session-fixation rule), the identity, the generation stamp that the kill
 * switch compares against, and the per-session token the pages embed.
 *
 * @return string the per-session token
 */
function qs_session_establish(string $userId, int $generation, bool $remember): string {
    qs_session_boot(true);
    session_regenerate_id(true); // fresh id on privilege change; old file deleted

    $token = bin2hex(random_bytes(32));
    $_SESSION['qs_uid']      = $userId;
    $_SESSION['qs_gen']      = $generation;
    $_SESSION['qs_token']    = $token;
    $_SESSION['qs_seen']     = time();
    $_SESSION['qs_remember'] = $remember;

    // session_regenerate_id already emitted the cookie with the params the
    // session was started with (lifetime 0). "Remember me" needs a longer one,
    // and session_set_cookie_params refuses to run while a session is active —
    // so re-emit the cookie explicitly. Same name and id: the browser keeps the
    // last Set-Cookie for a name, which is this one.
    $lifetime = $remember ? qs_session_config()['remember_ttl'] : 0;
    if (!headers_sent()) {
        $params = qs_session_cookie_params($lifetime);
        $params['expires'] = $lifetime > 0 ? time() + $lifetime : 0;
        unset($params['lifetime']);
        setcookie(session_name(), session_id(), $params);
    }

    qs_session_cache_reset();
    return $token;
}

/**
 * Re-stamp THIS session with a new generation, so a bump that was meant to end
 * the user's OTHER sessions does not also end the one that asked for it. This
 * is the "revoke my other sessions but not the one I am using" half of a
 * password change: bump, then re-stamp.
 */
function qs_session_restamp(int $generation): void {
    if (!qs_session_present()) {
        return;
    }
    $alreadyOpen = session_status() === PHP_SESSION_ACTIVE;
    if (!$alreadyOpen && !qs_session_boot(true)) {
        return;
    }
    if (isset($_SESSION['qs_uid'])) {
        $_SESSION['qs_gen'] = $generation;
    }
    if (!$alreadyOpen) {
        session_write_close();
    }
    qs_session_cache_reset();
}

/**
 * End THIS session: data, file and cookie. Other sessions of the same user are
 * untouched — bumping the user's generation is what ends those (see
 * qs_user_bump_generation).
 */
function qs_session_destroy(): void {
    if (!qs_session_present() || !qs_session_boot(true)) {
        return;
    }
    $_SESSION = [];
    if (!headers_sent()) {
        $params = qs_session_cookie_params(0);
        $params['expires'] = time() - 3600;
        unset($params['lifetime']);
        setcookie(session_name(), '', $params);
    }
    @session_destroy();
    qs_session_cache_reset();
}

/**
 * Slide the idle window forward. Called lazily — only once the stamp is older
 * than a tenth of the idle TTL — so an ordinary request stays a lock-free read
 * and a long working session still never expires under the user.
 */
function qs_session_touch(): void {
    if (headers_sent() || !qs_session_present()) {
        return; // a write here could need a Set-Cookie we can no longer send
    }
    $alreadyOpen = session_status() === PHP_SESSION_ACTIVE;
    if (!$alreadyOpen && !qs_session_boot(true)) {
        return;
    }
    if (!isset($_SESSION['qs_uid'])) {
        return;
    }
    $_SESSION['qs_seen'] = time();
    if (!$alreadyOpen) {
        session_write_close();
    }
    qs_session_cache_reset();
}

// ============================================================================
// Login throttle (brute-force backoff) — a small state file with flock +
// temp/rename discipline. Keyed by sha256 of the lowercased login identifier
// (the USERNAME): the raw identifier never sits in the state file.
//
// Independent of the session model: this is what makes password guessing
// expensive, and it is consulted before any credential is checked.
// ============================================================================

/** Hash a throttle key. Keys are identifiers and IPs — never stored in clear. */
function qs_throttle_hash(string $value): string {
    return hash('sha256', $value);
}

function qs_login_throttle_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/login-throttle.json';
}

/**
 * Seconds the caller must still wait before another attempt for this login
 * identifier (0 = go ahead). Read-only.
 */
function qs_login_throttle_check(string $identifier): int {
    $path = qs_login_throttle_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data)) {
        return 0;
    }
    $entry = $data[qs_throttle_hash(strtolower($identifier))] ?? null;
    if (!is_array($entry)) {
        return 0;
    }
    return max(0, (int)($entry['until'] ?? 0) - time());
}

/**
 * Shared mutate for the throttle file. $fn(array &$data): mixed.
 */
function qs_login_throttle_mutate(callable $fn) {
    $path = qs_login_throttle_path();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    flock($lock, LOCK_EX);
    try {
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $result = $fn($data);
        // prune entries idle for a day
        $cutoff = time() - 86400;
        foreach ($data as $key => $entry) {
            if ((int)($entry['last'] ?? 0) < $cutoff) {
                unset($data[$key]);
            }
        }
        $tmp = $path . '.tmp' . getmypid();
        // Encode checked separately from the write: `false . ''` writes an EMPTY
        // file and file_put_contents returns 0, not false, so a check on the
        // write alone lets a failed encode truncate the store (C11 11.3). This
        // file holds only hashed keys and integers, so nothing unrepresentable
        // can reach it today — checked because a throttle store that silently
        // emptied itself would disable brute-force protection without a trace.
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Record a failed attempt: 5 free tries, then a doubling cooldown
 * (30s, 60s, 120s, … capped at 1h).
 */
function qs_login_throttle_fail(string $identifier): void {
    $key = qs_throttle_hash(strtolower($identifier));
    qs_login_throttle_mutate(function (array &$data) use ($key) {
        $now = time();
        $fails = (int)(($data[$key]['fails'] ?? 0)) + 1;
        $entry = ['fails' => $fails, 'last' => $now, 'until' => 0];
        if ($fails >= 5) {
            $entry['until'] = $now + min(3600, 30 * (2 ** ($fails - 5)));
        }
        $data[$key] = $entry;
        return true;
    });
}

/**
 * Successful login clears the identifier's counter.
 */
function qs_login_throttle_clear(string $identifier): void {
    $key = qs_throttle_hash(strtolower($identifier));
    qs_login_throttle_mutate(function (array &$data) use ($key) {
        unset($data[$key]);
        return true;
    });
}

// ============================================================================
// Registration policy + flood control (C8) — the knobs live in auth.php
// `authentication.registration`; the counters in registration-throttle.json
// (same flock + temp/rename discipline, hashed IP keys — the raw address never
// sits in the state file).
// ============================================================================

/**
 * Registration policy knobs from auth.php (all optional, secure defaults —
 * flag OFF, min password 12, no user cap, 3 attempts/IP/minute, 30 successful
 * registrations/hour install-wide; 0 disables a limit).
 *
 * @return array{allow_self_registration:bool, min_password_length:int,
 *               max_users:int, per_ip_per_minute:int, global_per_hour:int}
 */
function qs_registration_config(): array {
    $cfg = loadAuthConfig()['authentication']['registration'] ?? [];
    $throttle = is_array($cfg['throttle'] ?? null) ? $cfg['throttle'] : [];
    return [
        'allow_self_registration' => (bool)($cfg['allow_self_registration'] ?? false),
        'min_password_length'     => max(1, (int)($cfg['min_password_length'] ?? 12)),
        'max_users'               => max(0, (int)($cfg['max_users'] ?? 0)),
        'per_ip_per_minute'       => max(0, (int)($throttle['per_ip_per_minute'] ?? 3)),
        'global_per_hour'         => max(0, (int)($throttle['global_per_hour'] ?? 30)),
    ];
}

/**
 * The caller's network address for rate limiting. Deliberately REMOTE_ADDR
 * only — X-Forwarded-For is caller-controlled (spoofable) and QuickSite does
 * not know which proxies to trust; behind a reverse proxy this rate-limits
 * the proxy address (deploy-time concern).
 */
function qs_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function qs_registration_throttle_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/registration-throttle.json';
}

/**
 * Shared mutate for the registration-throttle file. $fn(array &$data): mixed.
 * Shape: ['ips' => [sha256(ip) => {minute, count, last}], 'global' => {hour, count}]
 */
function qs_registration_throttle_mutate(callable $fn) {
    $path = qs_registration_throttle_path();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    flock($lock, LOCK_EX);
    try {
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $data['ips'] = is_array($data['ips'] ?? null) ? $data['ips'] : [];
        $data['global'] = is_array($data['global'] ?? null) ? $data['global'] : [];
        $result = $fn($data);
        // prune IP entries idle for an hour (their minute window is long over)
        $cutoff = time() - 3600;
        foreach ($data['ips'] as $key => $entry) {
            if ((int)($entry['last'] ?? 0) < $cutoff) {
                unset($data['ips'][$key]);
            }
        }
        $tmp = $path . '.tmp' . getmypid();
        // Encode checked before the write — see qs_login_throttle_mutate above.
        // Hashed IP keys and integers only, so unreachable; consistent anyway.
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Seconds the caller must wait before another registration attempt
 * (0 = go ahead). Read-only. Checks the per-IP minute window, then the
 * install-wide hourly cap of SUCCESSFUL registrations.
 */
function qs_registration_throttle_check(array $cfg): int {
    $path = qs_registration_throttle_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data)) {
        return 0;
    }
    $now = time();
    if ($cfg['per_ip_per_minute'] > 0) {
        $entry = $data['ips'][qs_throttle_hash(qs_client_ip())] ?? null;
        if (is_array($entry)
            && (int)($entry['minute'] ?? -1) === intdiv($now, 60)
            && (int)($entry['count'] ?? 0) >= $cfg['per_ip_per_minute']) {
            return 60 - ($now % 60);
        }
    }
    if ($cfg['global_per_hour'] > 0) {
        $global = $data['global'] ?? null;
        if (is_array($global)
            && (int)($global['hour'] ?? -1) === intdiv($now, 3600)
            && (int)($global['count'] ?? 0) >= $cfg['global_per_hour']) {
            return 3600 - ($now % 3600);
        }
    }
    return 0;
}

/**
 * Record a registration ATTEMPT against the caller's IP (fixed minute window).
 * Every attempt counts — failed, duplicate, or successful.
 */
function qs_registration_throttle_attempt(): void {
    $key = qs_throttle_hash(qs_client_ip());
    qs_registration_throttle_mutate(function (array &$data) use ($key) {
        $now = time();
        $minute = intdiv($now, 60);
        $entry = $data['ips'][$key] ?? null;
        $count = (is_array($entry) && (int)($entry['minute'] ?? -1) === $minute) ? (int)($entry['count'] ?? 0) : 0;
        $data['ips'][$key] = ['minute' => $minute, 'count' => $count + 1, 'last' => $now];
        return true;
    });
}

/**
 * Record a SUCCESSFUL registration against the install-wide hourly cap.
 * Only real creations count — a duplicate-username attempt must not let an
 * attacker fill the global window and lock legitimate users out.
 */
function qs_registration_record_success(): void {
    qs_registration_throttle_mutate(function (array &$data) {
        $hour = intdiv(time(), 3600);
        $count = ((int)($data['global']['hour'] ?? -1) === $hour) ? (int)($data['global']['count'] ?? 0) : 0;
        $data['global'] = ['hour' => $hour, 'count' => $count + 1];
        return true;
    });
}
