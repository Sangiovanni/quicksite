<?php
/**
 * QuickSite — first-account creator (used by setup.sh / setup.bat).
 *
 * QuickSite has no default credential. The installer asks the deployer for a
 * username and password and this script turns them into `management/config/users.php`
 * — so the only account that ever exists is the one the deployer chose.
 *
 * WHY A PHP HELPER AND NOT SHELL: the password rules, the username rule, the id
 * format and the file shape all belong to the application. Re-implementing them in
 * bash AND batch would give three sources of truth that drift. The installers stay
 * thin: collect two strings, hand them over, print what comes back.
 *
 * THE PASSWORD NEVER APPEARS IN A COMMAND LINE. It is read from STDIN, so it cannot
 * be seen in `ps`, in a shell history file, or by another local user reading
 * /proc/<pid>/cmdline. The installers pipe it in from a shell builtin (bash
 * `printf`) or from inside a single PowerShell process (Windows) for the same
 * reason. Nothing here writes the plaintext anywhere — only the bcrypt hash is
 * persisted.
 *
 * MODES
 *   --status   exit 3 if users.php already exists (an installer re-run must never
 *              clobber a live registry), exit 0 if it does not
 *   --rules    print `MINPW=<n>` and `USERRULE=<text>` for the installer's prompts
 *   (default)  read two lines from STDIN — username, then password — validate,
 *              hash, and write users.php
 *
 * EXIT CODES
 *   0 created (or, with --status, "no registry yet")
 *   2 usage error
 *   3 users.php already exists — nothing done
 *   4 validation failed (reason on STDERR; the installer re-prompts)
 *   5 could not write
 *
 * Run directly if you prefer to do it by hand:
 *   printf '%s\n%s\n' 'myname' 'my-password' | php secure/setup/create-account.php
 */

if (PHP_SAPI !== 'cli') {
    // Defence in depth: secure/ is never served, but this script must never be a
    // web-reachable account-creation endpoint even if a deployment misroutes.
    http_response_code(404);
    exit(1);
}

define('SECURE_FOLDER_PATH', dirname(__DIR__));
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$configDir = SECURE_FOLDER_PATH . '/management/config';
$usersPath = $configDir . '/users.php';
$mode      = $argv[1] ?? '';

/**
 * The minimum password length the APPLICATION enforces. Read from auth.php when it
 * exists; from auth.php.example when it does not (the installer normally runs before
 * the first page load, which is what creates auth.php). Never hardcoded here — a
 * deployer who raises the limit must not be able to create a weaker account through
 * the installer than the app would later accept.
 */
function qs_setup_min_password_length(string $configDir): int {
    foreach ([$configDir . '/auth.php', $configDir . '/auth.php.example'] as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $cfg = @include $candidate;
        $min = $cfg['authentication']['registration']['min_password_length'] ?? null;
        if (is_int($min) && $min > 0) {
            return $min;
        }
        if (is_string($min) && ctype_digit($min) && (int)$min > 0) {
            return (int)$min;
        }
    }
    return 12; // last-resort floor, matching the shipped example
}

const QS_SETUP_USERNAME_RULE = '3-32 characters: lowercase letters, digits, - or _';

// ---------------------------------------------------------------- --status
if ($mode === '--status') {
    if (is_file($usersPath)) {
        fwrite(STDOUT, "EXISTS\n");
        exit(3);
    }
    fwrite(STDOUT, "NONE\n");
    exit(0);
}

// ----------------------------------------------------------------- --rules
if ($mode === '--rules') {
    fwrite(STDOUT, 'MINPW=' . qs_setup_min_password_length($configDir) . "\n");
    fwrite(STDOUT, 'USERRULE=' . QS_SETUP_USERNAME_RULE . "\n");
    exit(0);
}

if ($mode !== '') {
    fwrite(STDERR, "Unknown mode '{$mode}'. Use --status, --rules, or no argument.\n");
    exit(2);
}

// -------------------------------------------------------------- create mode
// Idempotence is a safety property, not a convenience: re-running the installer on
// a live install must never destroy the account registry.
if (is_file($usersPath)) {
    fwrite(STDERR, "users.php already exists — leaving it untouched.\n");
    exit(3);
}

$raw = stream_get_contents(STDIN);
if (!is_string($raw) || $raw === '') {
    fwrite(STDERR, "No input received on STDIN (expected: username, then password).\n");
    exit(4);
}
// Split on the first two line breaks only — a password may legitimately contain
// almost anything except a newline.
$lines    = preg_split("/\r\n|\n|\r/", $raw);
$username = trim((string)($lines[0] ?? ''));
$password = (string)($lines[1] ?? '');   // NOT trimmed: leading/trailing spaces are
                                         // legitimate password characters

if (!qs_valid_username($username)) {
    fwrite(STDERR, "Invalid username. Rule: " . QS_SETUP_USERNAME_RULE . "\n");
    exit(4);
}
$minPw = qs_setup_min_password_length($configDir);
if (strlen($password) < $minPw) {
    fwrite(STDERR, "Password too short — minimum {$minPw} characters.\n");
    exit(4);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "Could not hash the password.\n");
    exit(5);
}
// Do not keep the plaintext alive any longer than necessary.
$password = null;
unset($password, $raw, $lines);

// A FRESH random id every time — never the example's usr_CHANGE_ME_default.
$userId = 'usr_' . bin2hex(random_bytes(16));

$registry = [
    'users' => [
        $userId => [
            'name'             => $username,
            'username'         => $username,
            'status'           => 'active',
            'password_hash'    => $hash,
            'selected_project' => null,
            'projects'         => [],
        ],
    ],
];

if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
    fwrite(STDERR, "Could not create {$configDir}\n");
    exit(5);
}

// Same shape qs_users_mutate writes, so the first real mutation is a no-op in style.
$content = "<?php\n/**\n * User Registry (C5) — stable userId => identity.\n"
         . " * Engine plumbing (PHP). Atomic write (temp+rename).\n */\n\nreturn "
         . var_export($registry, true) . ";\n";

$tmp = $usersPath . '.tmp' . getmypid();
if (file_put_contents($tmp, $content, LOCK_EX) === false) {
    @unlink($tmp);
    fwrite(STDERR, "Could not write {$usersPath}\n");
    exit(5);
}
if (!@rename($tmp, $usersPath)) {
    usleep(50000); // Windows: a transient sharing violation can fail the swap once
    if (!@rename($tmp, $usersPath)) {
        @unlink($tmp);
        fwrite(STDERR, "Could not finalise {$usersPath}\n");
        exit(5);
    }
}
@chmod($usersPath, 0640);
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($usersPath, true);
}

fwrite(STDOUT, "CREATED {$username}\n");
exit(0);
