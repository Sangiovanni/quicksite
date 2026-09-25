<?php
/**
 * securityLog.php — the installation-wide security trail.
 *
 * WHAT THIS IS FOR, AND WHY IT IS NOT THE COMMAND LOG.
 *
 * Signing in, failing to sign in, signing out, creating an account, changing a
 * password, deleting an account and joining or leaving a project are events the
 * command log cannot record. Three structural reasons:
 *
 *   1. `login` and `register` answer BEFORE the dispatcher installs its logging
 *      callback — they are public commands that exit early, so no callback of
 *      any kind has been registered when they respond.
 *   2. Account and membership self-service are not commands: they are served
 *      from /admin/self, which never touches the command dispatcher.
 *   3. The admin panel's own forms — sign-in, sign-out, registration, the
 *      first-run page — are not commands either. AdminRouter writes their
 *      records, with the same events and payloads as the commands write.
 *
 * These are also not commands in the sense the command log means. They belong to
 * an ACCOUNT and an INSTALLATION, not to a project, so the per-project trail has
 * no bucket for them that anyone can read — and folding them into `_global`
 * would give one file two audiences and two retention policies.
 *
 * WHAT READS IT: nothing, deliberately, in this release. The operator reads the
 * file on the server; filesystem access IS the credential, the same reasoning
 * that makes session-sweep a script rather than a command — clearing or reading
 * an installation-wide store is not something a per-project role could authorise,
 * and QuickSite has no tier above a project owner.
 *
 *   <secure>/logs/_security/security_<YYYY-MM-DD>.json
 *
 * The `_` prefix marks it as not a project bucket, matching `_global`; a project
 * can never be named either, because is_valid_project_name requires a leading
 * letter.
 *
 * TWO RULES THIS FILE MUST NEVER BREAK
 *
 *   - **No credential is ever written.** Not a password, not a token, not a
 *     session id. Every detail payload goes through the same deny-by-default
 *     redaction the command log uses (qs_log_redact_secrets), so a caller that
 *     hands this function a body containing a password gets `[redacted]` rather
 *     than a leak. A failed sign-in records neither what was tried nor the
 *     username it was tried with, but a keyed digest of that username
 *     (qs_security_username_digest): the username is half of a credential, and
 *     whatever was typed into its field — sometimes a password — is to be
 *     treated as one.
 *   - **A logging failure never breaks authentication.** Every path returns a
 *     bool and none throws. If the disk is full, the sign-in still succeeds or
 *     fails on its own merits.
 */

require_once __DIR__ . '/LoggingManagement.php'; // qs_log_append, qs_log_redact_secrets, generateLogId

/** The bucket, beside `_global` and the `p/` project tree. */
const QS_SECURITY_LOG_BUCKET = '_security';

/**
 * Event names. A closed vocabulary, so a reader can grep one string and a probe
 * can assert every emitting site uses a name that exists.
 */
const QS_SEC_SIGNIN_SUCCESS   = 'auth.signin_success';
const QS_SEC_SIGNIN_FAILURE   = 'auth.signin_failure';
const QS_SEC_SIGNOUT          = 'auth.signout';
const QS_SEC_UNAUTHENTICATED  = 'auth.unauthenticated_request';
const QS_SEC_ACCOUNT_CREATED  = 'account.created';
const QS_SEC_PASSWORD_CHANGED = 'account.password_changed';
const QS_SEC_ACCOUNT_DELETED  = 'account.deleted';
const QS_SEC_MEMBERSHIP       = 'membership.changed';

/** Every event name this file defines. Used by the probe's non-vacuity control. */
function qs_security_events(): array {
    return [
        QS_SEC_SIGNIN_SUCCESS, QS_SEC_SIGNIN_FAILURE, QS_SEC_SIGNOUT,
        QS_SEC_UNAUTHENTICATED, QS_SEC_ACCOUNT_CREATED, QS_SEC_PASSWORD_CHANGED,
        QS_SEC_ACCOUNT_DELETED, QS_SEC_MEMBERSHIP,
    ];
}

/** Today's security file. */
function qs_security_log_path(?string $date = null): string {
    $date = $date ?? date('Y-m-d');
    return LOGS_PATH . '/' . QS_SECURITY_LOG_BUCKET . '/security_' . $date . '.json';
}

/**
 * The request's origin, as far as it can be trusted.
 *
 * ⚠ REMOTE_ADDR ONLY. `X-Forwarded-For` and friends are caller-supplied headers:
 * behind a proxy they are the useful value, and directly exposed they are a
 * field an attacker writes. Recording the forwarded value unconditionally would
 * let anyone forge their own audit trail, so this records what the web server
 * observed. An operator behind a reverse proxy reads their proxy's own log for
 * the client address.
 */
function qs_security_source(): array {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return [
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        // Bounded: a user agent is caller-controlled and unbounded in principle.
        'user_agent' => $ua === '' ? null : mb_substr($ua, 0, 256),
    ];
}

/**
 * Record one security event.
 *
 * @param string      $event  One of the QS_SEC_* names.
 * @param array       $detail Event-specific fields. Redacted before writing, so
 *                            it is safe to pass a request body straight in.
 * @param string|null $userId The account the event is about, when known. Null
 *                            for a failed sign-in, where no account is resolved.
 * @param string|null $name   That account's public display name, when known.
 * @return bool               True when the entry was written. Never throws.
 */
function qs_security_log(
    string $event,
    array $detail = [],
    ?string $userId = null,
    ?string $name = null
): bool {
    try {
        $entry = [
            'id'        => generateLogId(),
            'timestamp' => date('c'),
            'event'     => $event,
            'actor'     => [
                'user_id' => $userId,
                'name'    => $name,
            ],
            'source'    => qs_security_source(),
            'detail'    => qs_log_redact_secrets($detail),
        ];

        return qs_log_append(qs_security_log_path(), $entry);
    } catch (Throwable $e) {
        // A security record is worth having and never worth a 500. The operator
        // finds out from the PHP error log that the trail has a hole in it.
        error_log('QuickSite securityLog: could not record ' . $event . ' (' . $e->getMessage() . ')');
        return false;
    }
}

/**
 * Record how a sign-in attempt ended — the one payload both doors write: the
 * `login` command and the admin panel's login form.
 *
 *   success → auth.signin_success, naming the account, and whether a "remember
 *             me" session was created (never the session token);
 *   refusal → auth.signin_failure, naming no account — the attempt does not
 *             resolve to one, and inventing one would put an oracle in the trail
 *             — with the keyed digest of the username that was typed and whether
 *             the refusal was the throttle.
 *
 * @param array $attempt qs_auth_attempt_login()'s result
 * @return bool          True when the entry was written. Never throws.
 */
function qs_security_log_signin(array $attempt, string $typedUsername, bool $remember = false): bool {
    if (!empty($attempt['ok'])) {
        $user = is_array($attempt['user'] ?? null) ? $attempt['user'] : [];
        return qs_security_log(
            QS_SEC_SIGNIN_SUCCESS,
            ['remember' => $remember],
            (string)($user['id'] ?? ''),
            $user['name'] ?? null
        );
    }
    return qs_security_log(QS_SEC_SIGNIN_FAILURE, [
        'username_digest' => qs_security_username_digest($typedUsername),
        'reason'          => ($attempt['error'] ?? '') === 'throttled' ? 'throttled' : 'invalid_credentials',
    ]);
}

/**
 * THE TRAIL'S KEY — for the digest a failed sign-in records in place of the
 * username that was typed.
 *
 * Keyed, not a plain hash: the usernames this install assigns come from about
 * 676 million possibilities and chosen ones from far fewer, so a plain hash of
 * either could be reversed by trying them all. Keyed, the digest can only be
 * recomputed by whoever also holds this file, which stays in the config
 * directory while log files travel (backups, aggregation). Two failures against
 * one name still carry one digest, which is what the record is for.
 *
 * Minted the setupToken.php way: random bytes, written on first use with the
 * atomic create-if-absent fopen, then only ever read. Never regenerated — a new
 * key would stop every earlier digest matching its name. Gitignored, never
 * logged, never part of any response.
 */
const QS_SECURITY_KEY_BYTES = 32;

function qs_security_key_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/security-trail-key.txt';
}

/**
 * The trail's key as raw bytes, minted if this is its first use; null when it
 * can be neither read nor written.
 */
function qs_security_key(): ?string {
    $path = qs_security_key_path();
    $read = static function () use ($path): ?string {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $hex = is_string($raw) ? trim($raw) : '';
        return preg_match('/^[0-9a-f]{' . (QS_SECURITY_KEY_BYTES * 2) . '}$/', $hex) === 1 ? hex2bin($hex) : null;
    };

    $key = $read();
    if ($key !== null) {
        return $key;
    }
    $handle = @fopen($path, 'x'); // atomic: fails if the file already exists
    if ($handle === false) {
        // Either a concurrent first use won the race (retry the read — it may
        // still be mid-write) or this directory is not writable.
        for ($i = 0; $i < 3; $i++) {
            usleep(20000);
            clearstatcache(true, $path);
            $key = $read();
            if ($key !== null) {
                return $key;
            }
        }
        return null;
    }
    $key = random_bytes(QS_SECURITY_KEY_BYTES);
    $written = @fwrite($handle, bin2hex($key) . "\n");
    @fclose($handle);
    if ($written === false || $written === 0) {
        @unlink($path);
        return null;
    }
    // Owner-only. A no-op on Windows, where ACLs govern instead — the file's real
    // protection is living under secure/, not its mode bits.
    @chmod($path, 0600);
    return $key;
}

/**
 * What a failed sign-in records instead of the username typed: an HMAC-SHA256
 * of it, keyed with the trail's key, taken of the name as the login gate reads
 * it (trimmed, lower-cased) so that "Bob" and " bob " are one name here as they
 * are there.
 *
 * Null when the key is unavailable, and then no identifier is recorded at all —
 * never the name in clear, and never an unkeyed hash of it. Never throws.
 */
function qs_security_username_digest(string $typed): ?string {
    try {
        $key = qs_security_key();
    } catch (Throwable $e) {
        $key = null;
    }
    if ($key === null) {
        error_log('QuickSite securityLog: the trail key could not be read or created, so a failed sign-in is recorded without its username digest');
        return null;
    }
    return hash_hmac('sha256', strtolower(trim($typed)), $key);
}
