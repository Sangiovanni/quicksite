<?php
/**
 * First-run setup token (C14) — the bootstrap credential for creating the very
 * first account on an install whose user registry is empty.
 *
 * THE MODEL. QuickSite ships no default credential, so a fresh install has
 * nobody to log in as. The first account is created in the browser, and the
 * authorisation to create it is READING A FILE THE WEB SERVER WROTE:
 * `secure/management/config/setup-token.txt`. Whoever can read that file has
 * filesystem access to the install, which is a strictly stronger position than
 * any account they could mint with it — so the file IS the authorisation.
 *
 * WHY IT IS PLAINTEXT AT REST. Storing a hash would leave the plaintext
 * nowhere for the deployer to find, which is the whole point of the mechanism.
 * The protections are that the file lives under `secure/` (never served — the
 * surface-B realpath jail refuses it, asserted in the traversal corpus), that
 * it is consumed the moment it is used, and that the gate ALSO requires the
 * registry to be empty, so a leaked or lingering file grants nothing once an
 * account exists.
 *
 * MINTED ONCE, NEVER REGENERATED. A token that changed per request would be
 * stale by the time the deployer finished reading it. `fopen(…, 'x')` is the
 * atomic create-if-absent primitive on both POSIX and Windows, so concurrent
 * first-run renders cannot mint two different tokens.
 *
 * The rule that a token is REQUIRED does not live here — it lives at
 * `qs_user_create()`, the single mint path, so every creation route inherits it.
 */

/** Token width in random bytes. 32 = 256 bits, rendered as 64 hex characters. */
const QS_SETUP_TOKEN_BYTES = 32;

function qs_setup_token_path(): string {
    return SECURE_FOLDER_PATH . '/management/config/setup-token.txt';
}

/**
 * The token currently on disk, or null when there is none / the file no longer
 * holds a token-shaped value.
 *
 * The shape check is what makes consumption robust: a consumed file is
 * overwritten with a marker, which can never satisfy it.
 */
function qs_setup_token_read(): ?string {
    $path = qs_setup_token_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }
    $token = trim($raw);
    return preg_match('/^[0-9a-f]{' . (QS_SETUP_TOKEN_BYTES * 2) . '}$/', $token) === 1 ? $token : null;
}

/**
 * Return the install's setup token, minting it if this is the first time it is
 * needed. Callers MUST only reach this while the registry is empty.
 *
 * A write failure is reported, never swallowed: the first-run page has to be
 * able to say "the web server cannot write here" instead of showing a form that
 * silently cannot work.
 *
 * @return array {ok:true, token:string, path:string}
 *             | {ok:false, error:'write_failed', path:string}
 */
function qs_setup_token_ensure(): array {
    $path = qs_setup_token_path();
    $existing = qs_setup_token_read();
    if ($existing !== null) {
        return ['ok' => true, 'token' => $existing, 'path' => $path];
    }

    $token = bin2hex(random_bytes(QS_SETUP_TOKEN_BYTES));
    $handle = @fopen($path, 'x'); // atomic: fails if the file already exists
    if ($handle === false) {
        // Either a concurrent minter won the race (retry the read — it may still
        // be mid-write) or this directory is not writable.
        for ($i = 0; $i < 3; $i++) {
            usleep(20000);
            clearstatcache(true, $path);
            $existing = qs_setup_token_read();
            if ($existing !== null) {
                return ['ok' => true, 'token' => $existing, 'path' => $path];
            }
        }
        return ['ok' => false, 'error' => 'write_failed', 'path' => $path];
    }

    $written = @fwrite($handle, $token . "\n");
    @fclose($handle);
    if ($written === false || $written === 0) {
        @unlink($path);
        return ['ok' => false, 'error' => 'write_failed', 'path' => $path];
    }
    // Owner-only. A no-op on Windows, where ACLs govern instead — the file's real
    // protection is living under secure/, not its mode bits.
    @chmod($path, 0600);
    return ['ok' => true, 'token' => $token, 'path' => $path];
}

/**
 * Does $candidate match the token on disk? False for absent, wrong, malformed
 * and already-consumed alike — callers must report all four identically.
 */
function qs_setup_token_verify(string $candidate): bool {
    $stored = qs_setup_token_read();
    // Compare even when there is no token, so "no file" costs the same as "wrong
    // value" (the same discipline as the login gate's dummy hash).
    $reference = $stored ?? str_repeat('0', QS_SETUP_TOKEN_BYTES * 2);
    $matches = hash_equals($reference, trim($candidate));
    return $stored !== null && $matches;
}

/**
 * Burn the token after a successful first-account creation.
 *
 * OVERWRITE, THEN UNLINK — deliberately in that order. If the unlink fails (a
 * virus scanner holding the handle, a directory that denies delete), the file
 * that survives must no longer contain a usable token: otherwise deleting
 * users.php later would silently re-arm it.
 *
 * A READ-ONLY token file defeats both steps at once, so the overwrite is
 * verified rather than assumed: if the value is still readable afterwards the
 * read-only attribute is cleared and the overwrite retried. Anything left after
 * that is beyond this process's reach, and the gate's independent
 * registry-must-be-empty condition is what holds the line.
 *
 * @return bool true when the token value is provably gone
 */
function qs_setup_token_consume(): bool {
    $path = qs_setup_token_path();
    if (!is_file($path)) {
        return true;
    }
    $marker = 'consumed ' . gmdate('c') . "\n";
    @file_put_contents($path, $marker, LOCK_EX);
    clearstatcache(true, $path);
    if (qs_setup_token_read() !== null) {
        // Still a live token: the write was refused. Clear the read-only
        // attribute (chmod is what lifts it on Windows too) and try once more.
        @chmod($path, 0600);
        clearstatcache(true, $path);
        @file_put_contents($path, $marker, LOCK_EX);
        clearstatcache(true, $path);
    }
    @unlink($path);
    clearstatcache(true, $path);
    return !is_file($path) || qs_setup_token_read() === null;
}
