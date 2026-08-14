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
 * OWNING the save path is also what makes the two hygiene rules below sound.
 *
 *   1. A READ never mints. `session.use_strict_mode=1` closes session fixation
 *      (an id the caller invented is never adopted) but it does not mean "no
 *      session" — it means "mint a fresh one instead", and minting WRITES A
 *      FILE. A client that ignores Set-Cookie therefore left one empty session
 *      file per request behind, with no account and no credential: an
 *      unauthenticated disk-consumption primitive. So a read-mode boot now
 *      declines outright unless the id names a file that already exists.
 *   2. QuickSite sweeps its own store. PHP's GC cannot: it runs on 0.1% of
 *      session starts and its lifetime is pinned to the LONGEST thing this
 *      install promises (remember-me, 30 days), because one gc_maxlifetime has
 *      to cover every session here. QuickSite knows a session is dead long
 *      before that — its own idle check is what actually expires one — so it
 *      collects on its own rule (qs_session_sweep).
 *
 * Both rules read the store as `sess_<id>` files, which is PHP's `files`
 * handler and nothing else. That coupling is deliberate and checked at the one
 * place it matters (qs_session_file_path returns null for any other handler, and
 * both rules degrade to their pre-existing behaviour when it does).
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
 * sweep_divisor — 1-in-N chance that a login also sweeps the session store
 *                (0 = never; the operator CLI still works). Logins are rare and
 *                already write to disk, which is why the sweep rides one rather
 *                than every request — PHP's own gc_probability/gc_divisor idiom,
 *                with a divisor sized for logins instead of session starts.
 *
 * Every key is optional and absent means the default: an auth.php written
 * before a key existed keeps working.
 *
 * @return array{idle_ttl:int, remember_ttl:int, sweep_divisor:int}
 */
function qs_session_config(): array {
    $cfg = loadAuthConfig()['authentication']['session'] ?? [];
    return [
        'idle_ttl'      => max(300, (int)($cfg['idle_ttl'] ?? 86400)),
        'remember_ttl'  => max(3600, (int)($cfg['remember_ttl'] ?? 2592000)),
        'sweep_divisor' => max(0, (int)($cfg['sweep_divisor'] ?? 10)),
    ];
}

/** Where PHP writes this install's session files (see the file header). */
function qs_session_save_path(): string {
    return SECURE_FOLDER_PATH . '/tmp/sessions';
}

/**
 * The file PHP would keep session $id in, or null when this install is not
 * using the `files` handler.
 *
 * The null case is the whole point of the function existing. Reading the store
 * as files is an assumption, and an assumption that is wrong in a way nobody
 * would notice quickly: if a deployment sets session.save_handler to redis or
 * memcached, "no file" would mean "no session" and EVERY caller would be
 * treated as signed out. Callers therefore ask, and fall back to the old
 * unconditional behaviour when the answer is null.
 *
 * $id is already shape-checked by qs_session_id_shape_ok — no dots, no
 * separators — so it cannot compose a path of its own.
 */
function qs_session_file_path(string $id): ?string {
    if (ini_get('session.save_handler') !== 'files') {
        return null;
    }
    return qs_session_save_path() . '/sess_' . $id;
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
 * (read mode with no session behind the cookie) — the caller is simply
 * anonymous.
 *
 * $forWrite=false starts with read_and_close: the data is read and the session
 * file is released immediately, so concurrent admin requests never serialize
 * behind each other's session lock, AND session_status() falls back to NONE so
 * a later session on the same request (the author's-site OAuth state store
 * names its own) can start cleanly.
 *
 * A READ NEVER MINTS. Reading a session that does not exist has no result worth
 * having — there is nothing to read — but session_start() would still create
 * one, write its file and send a Set-Cookie, because strict mode's answer to an
 * unknown id is "mint a fresh one", not "decline". So read mode declines here
 * instead, and only a deliberate write (logging in, storing a language choice,
 * setting a one-shot flash) ever creates a session. See the file header.
 *
 * WRITE mode is unchanged: it must be able to create, and every caller that
 * wants "open it, but do not invent one" asks qs_session_present() first.
 */
function qs_session_boot(bool $forWrite): bool {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true; // already open for this request (admin page flow)
    }
    if (!$forWrite && !qs_session_present()) {
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
 * is not, which is right for login and wrong for everything else — destroy,
 * touch, restamp and every read use this so they never mint an empty session
 * (and a Set-Cookie) for a caller who has none.
 *
 * "Has none" means BOTH halves: no cookie, a cookie that is not shaped like a
 * session id, or a cookie naming a session that does not exist on disk. The
 * third case is the one that mattered — it is the only one a caller can produce
 * over and over, since a browser adopts the id it is handed and stops asking,
 * while a script that ignores Set-Cookie asks forever.
 */
function qs_session_present(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    $cookie = $_COOKIE[QS_SESSION_COOKIE] ?? null;
    if (!is_string($cookie) || !qs_session_id_shape_ok($cookie)) {
        return false;
    }
    $file = qs_session_file_path($cookie);
    // Non-files handler: we cannot see the store, so answer as before and let
    // session_start() decide. Never report "absent" on a store we cannot read.
    return $file === null || is_file($file);
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

    // Opportunistic housekeeping, on a die (qs_session_sweep_maybe). A login is
    // the right host: infrequent, already writing, and it needs nothing an
    // operator has to remember. Deliberately AFTER the session is established
    // and the cookie is sent, so a sweep can never delay or affect the login
    // that triggered it.
    qs_session_sweep_maybe();

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
    // The cookie name rather than session_name(): this has to be able to expire
    // a cookie without a session ever being opened (the case just below), and
    // boot() always names the session after the same constant anyway.
    $expireCookie = static function (): void {
        if (headers_sent()) {
            return;
        }
        $params = qs_session_cookie_params(0);
        $params['expires'] = time() - 3600;
        unset($params['lifetime']);
        setcookie(QS_SESSION_COOKIE, '', $params);
    };

    if (!qs_session_present() || !qs_session_boot(true)) {
        // Nothing to destroy. If the caller presented a cookie anyway it names
        // a session that is gone — swept, expired, or never real — so expire it
        // rather than leaving the browser to keep sending a dead pointer.
        if (isset($_COOKIE[QS_SESSION_COOKIE])) {
            $expireCookie();
        }
        return;
    }
    $_SESSION = [];
    $expireCookie();
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
// Session store sweep — QuickSite collects on ITS OWN rule (see the file
// header for why PHP's GC cannot). Two entries, per the S2 design:
//   - opportunistically at LOGIN, on a 1-in-N die (qs_session_sweep_maybe);
//   - explicitly from the operator CLI (secure/cli/session-sweep.php).
//
// NOT a routed command, deliberately. Clearing the session store is
// installation-wide and has no principal to authorize it: a per-project role
// cannot mean "sign out everyone on this server", and beta.10 removed every
// installation-wide tier on purpose. The credential for this is filesystem
// access, which is strictly more power than any role could grant.
// ============================================================================

/** A 0-byte session file is only ever transient for the instant between
 *  creation and first write. This grace is that instant, generously rounded —
 *  it exists so a session being created right now is never swept, and for no
 *  other reason. */
const QS_SESSION_SWEEP_EMPTY_GRACE = 3600;

/** Safety valve, not a tuning knob: bounds one pass over a pathologically large
 *  store (the 5393 files that prompted this work would fit ~4 times over). A
 *  capped pass reports it and the next sweep continues. */
const QS_SESSION_SWEEP_MAX_FILES = 20000;

/**
 * Delete session files this install can prove are worthless, and leave
 * everything else alone. Returns a report; never throws.
 *
 * THREE RULES, each with its own reason to be sound:
 *
 *  1. EMPTY (0 bytes), older than the grace above → gone. An empty file holds
 *     no session for anybody, QuickSite or otherwise. This is the litter the
 *     read-mode fix stops producing, and the only rule that acts quickly.
 *  2. Holds a QUICKSITE LOGIN (a `qs_uid` key), last seen longer ago than
 *     `idle_ttl` → gone. This is not a new policy: it is exactly the session
 *     qs_session_auth() already refuses. The file is the last thing left of a
 *     session that stopped being accepted.
 *  3. Holds NO QuickSite login (an anonymous language preference, or another
 *     component's session sharing this save path — the author's-site OAuth
 *     state store starts its own session and inherits the path when QuickSite
 *     booted first), last WRITTEN longer ago than max(idle_ttl, remember_ttl)
 *     → gone. That bar is the longest lifetime this install promises anything,
 *     and it is exactly the gc_maxlifetime PHP is configured with here — so
 *     this rule only ever collects what PHP itself already considers
 *     collectable, and can never outrace a foreign session's own expiry.
 *
 * Anything else is left alone. The sweep is allowed to be slow to notice; it is
 * not allowed to be wrong.
 *
 * CHEAP BY CONSTRUCTION. Every file costs one stat. Contents are read only for
 * a file already stale by mtime, which on a healthy store is none of them:
 * `qs_seen` is written into the file, so the file's mtime is never older than
 * its own `qs_seen`, and a fresh mtime therefore proves a fresh session without
 * opening it.
 *
 * NEVER RACES A LIVE WRITE. A candidate is only removed while this process
 * holds a non-blocking exclusive flock on it — the same lock PHP's own files
 * handler takes for the duration of a write-mode request — and its stats are
 * re-read under that lock, so a file that was rewritten between the scan and
 * the delete is re-judged rather than removed on stale evidence.
 *
 * @param bool     $dryRun report what would go, delete nothing
 * @param int|null $now    injectable clock (tests); null = time()
 * @return array{examined:int, removed:int, bytes:int, empty:int, idle:int,
 *               foreign:int, locked:int, capped:bool, seconds:float,
 *               removed_files:array<int,string>}
 */
function qs_session_sweep(bool $dryRun = false, ?int $now = null): array {
    $started = microtime(true);
    $now     = $now ?? time();
    $knobs   = qs_session_config();
    $idleTtl = $knobs['idle_ttl'];
    $longest = max($knobs['idle_ttl'], $knobs['remember_ttl']);

    $report = ['examined' => 0, 'removed' => 0, 'bytes' => 0, 'empty' => 0,
               'idle' => 0, 'foreign' => 0, 'locked' => 0, 'capped' => false,
               'seconds' => 0.0, 'removed_files' => []];

    $dir = qs_session_save_path();
    // Only this install's own store, and only the files handler. A store we
    // cannot read as files is a store we must not guess about.
    if (ini_get('session.save_handler') !== 'files' || !is_dir($dir)) {
        $report['seconds'] = round(microtime(true) - $started, 4);
        return $report;
    }

    $dh = @opendir($dir);
    if ($dh === false) {
        $report['seconds'] = round(microtime(true) - $started, 4);
        return $report;
    }

    while (($entry = readdir($dh)) !== false) {
        if (strncmp($entry, 'sess_', 5) !== 0) {
            continue; // not a session file (lock files, ., ..)
        }
        if ($report['examined'] >= QS_SESSION_SWEEP_MAX_FILES) {
            $report['capped'] = true;
            break;
        }
        $report['examined']++;

        $file  = $dir . '/' . $entry;
        $size  = @filesize($file);
        $mtime = @filemtime($file);
        if ($size === false || $mtime === false) {
            continue; // vanished mid-scan, or unreadable — not ours to force
        }

        // Pre-filter on mtime alone: a file written within the idle window
        // cannot hold an idle-dead session (mtime >= qs_seen, always), and a
        // 0-byte file inside the grace may be one being created right now.
        if ($size === 0) {
            if ($now - $mtime <= QS_SESSION_SWEEP_EMPTY_GRACE) {
                continue;
            }
        } elseif ($now - $mtime <= $idleTtl) {
            continue;
        }

        $verdict = qs_session_sweep_consider($file, $now, $idleTtl, $longest, $dryRun);
        if ($verdict === null) {
            $report['foreign']++;  // alive, or not ours to judge
            continue;
        }
        if ($verdict === 'locked') {
            $report['locked']++;
            continue;
        }
        $report[$verdict]++; // 'empty' | 'idle'
        $report['bytes'] += $size;
        $report['removed']++;
        if (count($report['removed_files']) < 20) {
            $report['removed_files'][] = $entry;
        }
    }
    closedir($dh);

    $report['seconds'] = round(microtime(true) - $started, 4);
    return $report;
}

/**
 * Judge one candidate under an exclusive lock and, unless this is a dry run,
 * remove it there and then. Returns 'empty' or 'idle' when the file was judged
 * dead, 'locked' when another process owns it, and null when it is alive or not
 * ours to judge.
 *
 * Judging and deleting are one step on purpose: the lock is what makes the
 * verdict current, so acting on it anywhere else would be acting on evidence
 * about a moment that has passed. The scan's own stats are re-read here for the
 * same reason.
 *
 * The unlink is attempted while the lock is held — on POSIX that is the version
 * with no window between deciding and acting. Windows refuses to unlink a file
 * this process still has open, so it gets a second attempt after the handle is
 * closed; that window is bounded by the fact that the file was already judged
 * dead by content AND by age.
 */
function qs_session_sweep_consider(
    string $file, int $now, int $idleTtl, int $longest, bool $dryRun
): ?string {
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return null; // gone, or held open in a way we may not touch
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return 'locked'; // a request owns this session right now
    }

    $verdict = null;
    clearstatcache(true, $file);
    $size  = @filesize($file);
    $mtime = @filemtime($file);
    if ($size !== false && $mtime !== false) {
        if ($size === 0) {
            // Rule 1 — holds nothing, for anybody.
            $verdict = ($now - $mtime > QS_SESSION_SWEEP_EMPTY_GRACE) ? 'empty' : null;
        } else {
            $raw = @stream_get_contents($fh);
            if (is_string($raw) && $raw !== '') {
                if (strpos($raw, 'qs_uid') === false) {
                    // Rule 3 — no QuickSite login in it. Only collectable past
                    // the longest lifetime this install promises anything.
                    $verdict = ($now - $mtime > $longest) ? 'idle' : null;
                } else {
                    // Rule 2 — QuickSite's own idle rule, read off the session.
                    // Tolerant of the serialize_handler in use: the `php`
                    // default writes the key then `i:123;`, `php_serialize`
                    // quotes the key, `php_binary` length-prefixes it. An
                    // unparseable stamp falls back to mtime, so a session is
                    // never deleted on a guess.
                    $seen = $mtime;
                    if (preg_match('/qs_seen[^0-9-]{0,6}i:(-?\d+)/', $raw, $m) === 1
                        && (int)$m[1] > 0) {
                        $seen = (int)$m[1];
                    }
                    $verdict = ($now - $seen > $idleTtl) ? 'idle' : null;
                }
            }
        }
    }

    $removed = false;
    if ($verdict !== null && !$dryRun) {
        $removed = @unlink($file);
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
    if ($verdict !== null && !$dryRun && !$removed && is_file($file)) {
        @unlink($file); // Windows: the handle had to be closed first
    }
    return $verdict;
}

/**
 * Sweep on a 1-in-N die. Called by login, which is the right host for it: it is
 * infrequent, it is already writing to disk, and it needs no scheduler, no
 * cron entry and nothing for an operator to remember. `sweep_divisor` = 0
 * disables it entirely (the CLI entry still works).
 */
function qs_session_sweep_maybe(): void {
    $divisor = qs_session_config()['sweep_divisor'];
    if ($divisor <= 0) {
        return;
    }
    if ($divisor > 1 && random_int(1, $divisor) !== 1) {
        return;
    }
    qs_session_sweep();
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
