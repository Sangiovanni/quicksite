<?php
/**
 * Session Lifecycle Management (C5b) — access + refresh tokens with rotation
 * and reuse-detection.
 *
 * Replaces the lifetime bearer token: a short-lived ACCESS token is what every
 * request presents (validateBearerToken checks it here); a longer-lived REFRESH
 * token is exchangeable at one endpoint for a new pair. Each refresh ROTATES
 * the refresh token; presenting an already-rotated refresh token after a short
 * grace window is a theft signal and revokes the whole session FAMILY.
 *
 * Storage: sessions.json (runtime STATE, not config — deliberately JSON, not a
 * PHP array file: it is machine-rewritten on every login/refresh, so a PHP
 * emitter here would be a permanently hot F10 writer surface and would need
 * opcache_invalidate on every read). Tokens are stored sha256-HASHED (a file
 * leak is not a credential leak — F7). Writes are serialized with flock on a
 * sidecar lock file and land via temp + rename (L12 atomic).
 *
 * This file is loaded via AuthManagement.php (require at its top); the
 * disabled-user check in qs_session_rotate calls loadUsersConfig from there.
 *
 * Shape:
 *   families: famId -> { userId, created, revoked, revokedAt?, reason? }
 *   access:   sha256(token) -> { family, expires }
 *   refresh:  sha256(token) -> { family, expires, rotatedAt (null = active) }
 */

/**
 * Session lifecycle knobs from auth.php (all optional, safe defaults).
 *
 * @return array{access_ttl:int, refresh_ttl:int, reuse_grace:int}
 */
function qs_session_config(): array {
    $cfg = loadAuthConfig()['authentication']['session'] ?? [];
    return [
        'access_ttl'  => max(60, (int)($cfg['access_ttl'] ?? 900)),
        'refresh_ttl' => max(3600, (int)($cfg['refresh_ttl'] ?? 2592000)),
        'reuse_grace' => max(0, (int)($cfg['reuse_grace'] ?? 60)),
    ];
}

function qs_sessions_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/sessions.json';
}

/**
 * Read the sessions store (shape-defaulted; tolerant of a missing/corrupt file
 * — sessions are re-creatable state, never precious data).
 */
function qs_sessions_read(): array {
    $path = qs_sessions_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data)) {
        $data = [];
    }
    return [
        'families' => is_array($data['families'] ?? null) ? $data['families'] : [],
        'access'   => is_array($data['access'] ?? null) ? $data['access'] : [],
        'refresh'  => is_array($data['refresh'] ?? null) ? $data['refresh'] : [],
    ];
}

/**
 * Serialized read-modify-write on the sessions store: flock (mutual exclusion
 * between concurrent logins/refreshes) + prune + temp-file + rename (atomic
 * swap for lock-free readers). $fn receives the store by reference and its
 * return value is passed through.
 *
 * @param callable $fn function(array &$data): mixed
 * @return mixed the callback's return value (false on lock/write failure)
 */
function qs_sessions_mutate(callable $fn) {
    $path = qs_sessions_path();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    flock($lock, LOCK_EX);
    try {
        $data = qs_sessions_read();
        $result = $fn($data);
        qs_sessions_prune($data);
        $tmp = $path . '.tmp' . getmypid();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            // Windows: a transient sharing violation (AV scan) can fail the swap once.
            usleep(50000);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Drop expired tokens and dead families (in place, called under the write lock).
 * Rotated refresh entries are KEPT until their natural expiry — that retention
 * IS the reuse-detection window.
 */
function qs_sessions_prune(array &$data): void {
    $now = time();
    foreach ($data['access'] as $hash => $entry) {
        if (($entry['expires'] ?? 0) <= $now || !isset($data['families'][$entry['family'] ?? ''])) {
            unset($data['access'][$hash]);
        }
    }
    foreach ($data['refresh'] as $hash => $entry) {
        if (($entry['expires'] ?? 0) <= $now || !isset($data['families'][$entry['family'] ?? ''])) {
            unset($data['refresh'][$hash]);
        }
    }
    // A family with no live tokens is done; a revoked family is kept (tombstone
    // for audit) until every token that pointed at it has expired out above.
    $live = [];
    foreach ($data['access'] as $entry) { $live[$entry['family']] = true; }
    foreach ($data['refresh'] as $entry) { $live[$entry['family']] = true; }
    foreach ($data['families'] as $famId => $fam) {
        if (!isset($live[$famId])) {
            unset($data['families'][$famId]);
        }
    }
}

function qs_session_hash(string $rawToken): string {
    return hash('sha256', $rawToken);
}

/**
 * Mint a brand-new session family for a user — THE single issuance path (C5b):
 * the `login` command, the admin panel, and (C8) createUser/register all come
 * through here. Returns RAW tokens (only moment they exist unhashed).
 *
 * @return array|false {access_token, access_expires, refresh_token, refresh_expires, family}
 */
function qs_session_issue(string $userId) {
    $knobs = qs_session_config();
    $now = time();
    $family  = 'fam_' . bin2hex(random_bytes(8));
    $access  = 'qsa_' . bin2hex(random_bytes(24));
    $refresh = 'qsr_' . bin2hex(random_bytes(24));
    $result = [
        'access_token'    => $access,
        'access_expires'  => $now + $knobs['access_ttl'],
        'refresh_token'   => $refresh,
        'refresh_expires' => $now + $knobs['refresh_ttl'],
        'family'          => $family,
    ];
    $ok = qs_sessions_mutate(function (array &$data) use ($result, $userId, $now, $family, $access, $refresh) {
        $data['families'][$family] = ['userId' => $userId, 'created' => $now, 'revoked' => false];
        $data['access'][qs_session_hash($access)]   = ['family' => $family, 'expires' => $result['access_expires']];
        $data['refresh'][qs_session_hash($refresh)] = ['family' => $family, 'expires' => $result['refresh_expires'], 'rotatedAt' => null];
        return true;
    });
    return $ok === true ? $result : false;
}

/**
 * Per-request access-token check (read-only, lock-free).
 *
 * @return array{valid:bool, userId:?string, family:?string, error:?string}
 *         error: 'invalid' | 'expired' (expired drives the client's refresh)
 */
function qs_session_validate_access(string $rawToken): array {
    $no = static function (string $error): array {
        return ['valid' => false, 'userId' => null, 'family' => null, 'error' => $error];
    };
    if ($rawToken === '') {
        return $no('invalid');
    }
    $data = qs_sessions_read();
    $entry = $data['access'][qs_session_hash($rawToken)] ?? null;
    if ($entry === null) {
        return $no('invalid');
    }
    $family = $data['families'][$entry['family'] ?? ''] ?? null;
    if ($family === null || !empty($family['revoked'])) {
        return $no('invalid');
    }
    if (($entry['expires'] ?? 0) <= time()) {
        return $no('expired');
    }
    return ['valid' => true, 'userId' => (string)$family['userId'], 'family' => (string)$entry['family'], 'error' => null];
}

/**
 * Exchange a refresh token for a fresh pair (ROTATION), with reuse-detection.
 *
 *  - active token            -> mark it rotated, mint a new pair (same family;
 *                               sliding refresh TTL)
 *  - rotated, within grace   -> legit concurrent race (two tabs / a retry):
 *                               mint a SIBLING pair, no punishment
 *  - rotated, after grace    -> theft signal: REVOKE the whole family
 *  - expired / unknown       -> plain refusal
 *  - user disabled           -> refusal + family revoke (L10 reaches refresh too)
 *
 * @return array {ok:true, access_token, access_expires, refresh_token,
 *                refresh_expires, family, userId}
 *             | {ok:false, error:'invalid'|'expired'|'reuse_revoked'|'user_disabled'|'store'}
 */
function qs_session_rotate(string $rawRefresh): array {
    if ($rawRefresh === '') {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $knobs = qs_session_config();
    $result = qs_sessions_mutate(function (array &$data) use ($rawRefresh, $knobs) {
        $now = time();
        $hash = qs_session_hash($rawRefresh);
        $entry = $data['refresh'][$hash] ?? null;
        if ($entry === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $famId = (string)($entry['family'] ?? '');
        $family = $data['families'][$famId] ?? null;
        if ($family === null || !empty($family['revoked'])) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if (($entry['expires'] ?? 0) <= $now) {
            return ['ok' => false, 'error' => 'expired'];
        }

        // Reuse-detection: an already-rotated token coming back.
        $rotatedAt = $entry['rotatedAt'] ?? null;
        if ($rotatedAt !== null && ($now - (int)$rotatedAt) > $knobs['reuse_grace']) {
            qs_session_mark_family_revoked($data, $famId, 'refresh_reuse');
            error_log("QuickSite auth: refresh-token reuse detected — session family {$famId} revoked (user {$family['userId']})");
            return ['ok' => false, 'error' => 'reuse_revoked'];
        }

        // L10: a disabled user's sessions die at the refresh boundary too.
        $userId = (string)$family['userId'];
        if (function_exists('loadUsersConfig')) {
            $user = loadUsersConfig()['users'][$userId] ?? null;
            if ($user === null || ($user['status'] ?? 'active') === 'disabled') {
                qs_session_mark_family_revoked($data, $famId, 'user_disabled');
                return ['ok' => false, 'error' => 'user_disabled'];
            }
        }

        if ($rotatedAt === null) {
            $data['refresh'][$hash]['rotatedAt'] = $now; // normal rotation
        }
        // (within-grace reuse falls through: mint a sibling, old entry untouched)

        $access  = 'qsa_' . bin2hex(random_bytes(24));
        $refresh = 'qsr_' . bin2hex(random_bytes(24));
        $pair = [
            'ok'              => true,
            'access_token'    => $access,
            'access_expires'  => $now + $knobs['access_ttl'],
            'refresh_token'   => $refresh,
            'refresh_expires' => $now + $knobs['refresh_ttl'], // sliding window
            'family'          => $famId,
            'userId'          => $userId,
        ];
        $data['access'][qs_session_hash($access)]   = ['family' => $famId, 'expires' => $pair['access_expires']];
        $data['refresh'][qs_session_hash($refresh)] = ['family' => $famId, 'expires' => $pair['refresh_expires'], 'rotatedAt' => null];
        return $pair;
    });
    return is_array($result) ? $result : ['ok' => false, 'error' => 'store'];
}

/**
 * In-place family revoke (under the caller's write lock): tombstone the family
 * and drop every token pointing at it.
 */
function qs_session_mark_family_revoked(array &$data, string $famId, string $reason): void {
    if (!isset($data['families'][$famId])) {
        return;
    }
    $data['families'][$famId]['revoked']   = true;
    $data['families'][$famId]['revokedAt'] = time();
    $data['families'][$famId]['reason']    = $reason;
    foreach ($data['access'] as $hash => $entry) {
        if (($entry['family'] ?? '') === $famId) {
            unset($data['access'][$hash]);
        }
    }
    foreach ($data['refresh'] as $hash => $entry) {
        if (($entry['family'] ?? '') === $famId) {
            unset($data['refresh'][$hash]);
        }
    }
}

/**
 * Revoke a whole session family by id (logout, admin action, theft response).
 */
function qs_session_revoke_family(string $famId, string $reason = 'logout'): bool {
    $result = qs_sessions_mutate(function (array &$data) use ($famId, $reason) {
        $known = isset($data['families'][$famId]);
        qs_session_mark_family_revoked($data, $famId, $reason);
        return $known;
    });
    return $result === true;
}

/**
 * Logout by refresh token: revoke the family it belongs to. Idempotent —
 * unknown/expired tokens simply report false.
 */
function qs_session_revoke_by_refresh(string $rawRefresh): bool {
    if ($rawRefresh === '') {
        return false;
    }
    $result = qs_sessions_mutate(function (array &$data) use ($rawRefresh) {
        $entry = $data['refresh'][qs_session_hash($rawRefresh)] ?? null;
        if ($entry === null) {
            return false;
        }
        qs_session_mark_family_revoked($data, (string)$entry['family'], 'logout');
        return true;
    });
    return $result === true;
}

// ============================================================================
// Login throttle (brute-force backoff) — separate small state file, same
// flock + temp/rename discipline. Keyed by sha256 of the lowercased email
// (the raw identifier never sits in the state file).
// ============================================================================

function qs_login_throttle_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/login-throttle.json';
}

/**
 * Seconds the caller must still wait before another attempt for this email
 * (0 = go ahead). Read-only.
 */
function qs_login_throttle_check(string $email): int {
    $path = qs_login_throttle_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data)) {
        return 0;
    }
    $entry = $data[qs_session_hash(strtolower($email))] ?? null;
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
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false || !@rename($tmp, $path)) {
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
function qs_login_throttle_fail(string $email): void {
    $key = qs_session_hash(strtolower($email));
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
 * Successful login clears the email's counter.
 */
function qs_login_throttle_clear(string $email): void {
    $key = qs_session_hash(strtolower($email));
    qs_login_throttle_mutate(function (array &$data) use ($key) {
        unset($data[$key]);
        return true;
    });
}

/**
 * Revoke EVERY session family belonging to a user except (optionally) one (C8).
 * Password change: the new password invalidates every other device/session
 * (theft containment) while the session that performed the change survives.
 * Also serves a future admin force-logout.
 *
 * @return int number of families revoked
 */
function qs_session_revoke_user_families(string $userId, ?string $exceptFamily = null, string $reason = 'password_change'): int {
    $result = qs_sessions_mutate(function (array &$data) use ($userId, $exceptFamily, $reason) {
        $count = 0;
        foreach ($data['families'] as $famId => $fam) {
            if (($fam['userId'] ?? '') !== $userId || !empty($fam['revoked']) || $famId === $exceptFamily) {
                continue;
            }
            qs_session_mark_family_revoked($data, (string)$famId, $reason);
            $count++;
        }
        return $count;
    });
    return is_int($result) ? $result : 0;
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
 * the proxy address (deploy-time concern, beta.11 checklist).
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
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false || !@rename($tmp, $path)) {
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
        $entry = $data['ips'][qs_session_hash(qs_client_ip())] ?? null;
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
    $key = qs_session_hash(qs_client_ip());
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
 * Only real creations count — a duplicate-email attempt must not let an
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
