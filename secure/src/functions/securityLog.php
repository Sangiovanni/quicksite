<?php
/**
 * securityLog.php — the installation-wide security trail.
 *
 * WHAT THIS IS FOR, AND WHY IT IS NOT THE COMMAND LOG.
 *
 * Signing in, failing to sign in, signing out, changing a password, deleting an
 * account and joining or leaving a project were recorded NOWHERE. Two structural
 * reasons, neither of which the command log can fix:
 *
 *   1. `login` and `register` answer BEFORE the dispatcher installs its logging
 *      callback — they are public commands that exit early, so no callback of
 *      any kind has been registered when they respond.
 *   2. Account and membership self-service stopped being commands: they are
 *      served from /admin/self, which never touches the command dispatcher.
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
 *     than a leak. A failed sign-in records the username that was tried, which
 *     is the point of the record, and never what was tried with it.
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
