<?php
/**
 * cli.php — the deployer tools' shared part: what each tool needs to run from a
 * shell (the refusal of a web request, the engine folder, its options, its
 * report), and the one way a tool removes a file from a store: under the store's
 * own lock, judged again by the store's own rule once that lock is held, never
 * through a link. Each store's rule lives beside the store's code in
 * <secure>/src/functions/; this file only applies it.
 *
 * Not a tool: it declares no header, so the launcher (../tools.php) does not
 * list it, and it does nothing when run.
 *
 * THE THREE WAYS A STORE IS LOCKED, and how a removal takes each:
 *   - a lock file beside the file (`<file>.lock`: the command log, the security
 *     trail, the write-rate store): qs_tool_remove_beside();
 *   - the file's own lock, which its writer takes while it writes (the resolver
 *     and space caches): qs_tool_remove_self_locked();
 *   - none (the membership repair's cache, written whole and renamed into
 *     place): qs_tool_remove_unlocked(), which removes a file only when it is
 *     still the one it judged.
 * A file another process holds is kept and reported in use: the next run takes
 * it. A file that cannot be read, or that the store's rule cannot judge, is
 * reported and left; so is a link, whatever it leads to.
 *
 * Exit codes every tool shares: 0 = it ran (with nothing to do, or files in use
 * left for the next run); 1 = it refused to run (a web request, an unknown
 * option, a bad value); 2 = it ran, and left something that needs a look: a file
 * it could not read or judge, a removal or a write that failed, or a link.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (!defined('SECURE_FOLDER_PATH')) {
    // The engine folder is the tools folder's parent, whatever setup renamed it to.
    define('SECURE_FOLDER_PATH', dirname(__DIR__, 2));
    define('SECURE_FOLDER_NAME', basename(SECURE_FOLDER_PATH));
    define('SERVER_ROOT', dirname(SECURE_FOLDER_PATH));
}

require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_tree(), qs_delete_tree_leads_elsewhere()

/** Exit codes (see the header). */
const QS_TOOL_EXIT_OK = 0;
const QS_TOOL_EXIT_REFUSED = 1;
const QS_TOOL_EXIT_LEFT = 2;

/**
 * The tool's options from its command line. $spec maps an option to 'flag' or
 * to a default number of days: a days option is written `--name=N`, N a whole
 * number of at least 1. `--help` (or `-h`) prints $help and exits 0; an unknown
 * option or a bad value says why on stderr and exits 1.
 *
 * @return array<string, bool|int> each option by its name without the dashes
 */
function qs_tool_options(array $argv, array $spec, string $help): array {
    $args = array_slice($argv, 1);
    if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
        echo $help;
        exit(QS_TOOL_EXIT_OK);
    }
    $out = [];
    foreach ($spec as $name => $kind) {
        $out[ltrim($name, '-')] = $kind === 'flag' ? false : (int) $kind;
    }
    foreach ($args as $arg) {
        $eq = strpos($arg, '=');
        $name = $eq === false ? $arg : substr($arg, 0, $eq);
        $kind = $spec[$name] ?? null;
        if ($kind === null) {
            qs_tool_refuse('Unknown option: ' . $arg . ' (see --help)');
        }
        if ($kind === 'flag') {
            if ($eq !== false) {
                qs_tool_refuse($name . ' takes no value (see --help)');
            }
            $out[ltrim($name, '-')] = true;
            continue;
        }
        $value = $eq === false ? '' : substr($arg, $eq + 1);
        if (!preg_match('/^[1-9][0-9]{0,5}$/D', $value)) {
            qs_tool_refuse($name . ' wants a whole number of days, 1 or more: ' . $name . '=N (see --help)');
        }
        $out[ltrim($name, '-')] = (int) $value;
    }
    return $out;
}

/** Say why the tool will not run, on stderr, and stop with exit code 1. */
function qs_tool_refuse(string $why): void {
    fwrite(STDERR, $why . "\n");
    exit(QS_TOOL_EXIT_REFUSED);
}

/**
 * A store's folder, `$rel` under the engine folder ('logs', 'cache/rate'), or
 * null with $why: 'absent' when it does not exist (nothing to do); 'link' when
 * it, or a folder on the way to it, is a link (a symlink, or a Windows junction)
 * leading somewhere else, which a tool refuses to enter; 'not a folder' when a
 * file stands where it should be.
 */
function qs_tool_store(string $rel, ?string &$why = null): ?string {
    $path = SECURE_FOLDER_PATH;
    foreach (explode('/', $rel) as $segment) {
        $next = $path . DIRECTORY_SEPARATOR . $segment;
        if (qs_tool_is_link($path, $segment)) {
            $why = 'link';
            return null;
        }
        clearstatcache();
        if (!is_dir($next)) {
            $why = file_exists($next) ? 'not a folder' : 'absent';
            return null;
        }
        $path = $next;
    }
    $why = null;
    return $path;
}

/** The names in a store's folder, sorted, without `.` and `..`. */
function qs_tool_names(string $dir): array {
    $names = array_values(array_diff(@scandir($dir) ?: [], ['.', '..']));
    sort($names, SORT_STRING);
    return $names;
}

/**
 * Whether an entry of a store is a link: a symlink, or a folder whose real path
 * is not where it sits (a Windows junction, which is_link() does not report).
 */
function qs_tool_is_link(string $dir, string $name): bool {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    // is_dir() before any is_link(), from a cleared stat cache: on Windows, PHP answers
    // is_dir() false for a junction after an is_link() on it (as qs_delete_tree() notes).
    clearstatcache();
    if (is_dir($path)) {
        return qs_delete_tree_leads_elsewhere($dir, $name, $path);
    }
    return is_link($path);
}

/**
 * A verdict a store's rule gives: [true, why] removes, [false, why] keeps, and
 * null means the rule cannot judge what it was given (reported, left).
 *
 * Remove a file guarded by a lock file beside it, as its writer takes that lock.
 * The lock is taken without waiting (held by a writer: kept, in use); with it
 * held, the file is judged ($judge receives its content, '' when $read is false,
 * or null when the file is not there), then removed, and its lock with it.
 * $create: whether a real run makes the lock when there is none, for a store a
 * writer may start using at any moment (a lock the run made is removed again
 * when the file stays). A dry run opens an existing lock only, and changes
 * nothing.
 *
 * @return array{state: string, why: string, bytes: int} state: remove | keep | in-use | unreadable | failed | link
 */
function qs_tool_remove_beside(string $file, string $lock, callable $judge, bool $read, bool $create, bool $dryRun): array {
    if (is_link($file) || is_link($lock)) {
        return ['state' => 'link', 'why' => 'a link: left alone', 'bytes' => 0];
    }
    clearstatcache();
    $made = false;
    if (is_file($lock)) {
        $h = @fopen($lock, 'r');
    } elseif ($create && !$dryRun) {
        $h = @fopen($lock, 'c');
        $made = $h !== false;
    } else {
        $h = null;
    }
    if ($h === false) {
        return ['state' => 'unreadable', 'why' => 'its lock cannot be opened', 'bytes' => 0];
    }
    if ($h === null && !is_file($file)) {
        return ['state' => 'keep', 'why' => 'gone already', 'bytes' => 0];
    }
    if ($h !== null && !flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return ['state' => 'in-use', 'why' => 'a writer holds its lock', 'bytes' => 0];
    }
    try {
        clearstatcache();
        $exists = is_file($file);
        $content = null;
        if ($exists) {
            $content = $read ? @file_get_contents($file) : '';
            if ($content === false) {
                return ['state' => 'unreadable', 'why' => 'it cannot be read', 'bytes' => 0];
            }
        }
        $verdict = $judge($content);
        $bytes = $exists ? (int) @filesize($file) : 0;
        if ($verdict === null) {
            return ['state' => 'unreadable', 'why' => 'its store\'s rule cannot judge what it holds', 'bytes' => 0];
        }
        if (!$verdict[0]) {
            if ($made) {
                @unlink($lock);
            }
            return ['state' => 'keep', 'why' => $verdict[1], 'bytes' => 0];
        }
        if ($dryRun) {
            return ['state' => 'remove', 'why' => $verdict[1], 'bytes' => $bytes];
        }
        if ($exists && !@unlink($file)) {
            return ['state' => 'failed', 'why' => 'it could not be removed', 'bytes' => 0];
        }
        // The lock goes while it is held: a writer that opened it before finds,
        // once it holds it, that it is no longer the file at its path.
        if ($h !== null) {
            @unlink($lock);
        }
        return ['state' => 'remove', 'why' => $verdict[1], 'bytes' => $bytes];
    } finally {
        if ($h !== null) {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}

/**
 * Remove a file its writer locks itself while it writes it: the same lock is
 * taken without waiting (held: kept, in use), the file is read through it and
 * judged, and removed while it is held.
 *
 * @return array{state: string, why: string, bytes: int}
 */
function qs_tool_remove_self_locked(string $file, callable $judge, bool $dryRun): array {
    if (is_link($file)) {
        return ['state' => 'link', 'why' => 'a link: left alone', 'bytes' => 0];
    }
    $h = @fopen($file, 'r');
    if ($h === false) {
        clearstatcache();
        return file_exists($file)
            ? ['state' => 'unreadable', 'why' => 'it cannot be opened', 'bytes' => 0]
            : ['state' => 'keep', 'why' => 'gone already', 'bytes' => 0];
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return ['state' => 'in-use', 'why' => 'a writer holds it', 'bytes' => 0];
    }
    try {
        $content = stream_get_contents($h);
        $verdict = $judge($content === false ? false : $content);
        if ($verdict === null) {
            return ['state' => 'unreadable', 'why' => $content === false ? 'it cannot be read' : 'its store\'s rule cannot judge what it holds', 'bytes' => 0];
        }
        if (!$verdict[0]) {
            return ['state' => 'keep', 'why' => $verdict[1], 'bytes' => 0];
        }
        $bytes = strlen((string) $content);
        if (!$dryRun && !@unlink($file)) {
            return ['state' => 'failed', 'why' => 'it could not be removed', 'bytes' => 0];
        }
        return ['state' => 'remove', 'why' => $verdict[1], 'bytes' => $bytes];
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/**
 * Remove a file of a store that takes no lock (its writer writes a whole file
 * and renames it into place): read, judged, and removed only when it is still
 * the file that was judged (the same file, size and time), so a file written
 * again in between is kept.
 *
 * @return array{state: string, why: string, bytes: int}
 */
function qs_tool_remove_unlocked(string $file, callable $judge, bool $dryRun): array {
    if (is_link($file)) {
        return ['state' => 'link', 'why' => 'a link: left alone', 'bytes' => 0];
    }
    clearstatcache();
    $before = @stat($file);
    $content = $before === false ? false : @file_get_contents($file);
    if ($before === false || $content === false) {
        clearstatcache();
        return file_exists($file)
            ? ['state' => 'unreadable', 'why' => 'it cannot be read', 'bytes' => 0]
            : ['state' => 'keep', 'why' => 'gone already', 'bytes' => 0];
    }
    $verdict = $judge($content);
    if ($verdict === null) {
        return ['state' => 'unreadable', 'why' => 'its store\'s rule cannot judge what it holds', 'bytes' => 0];
    }
    if (!$verdict[0]) {
        return ['state' => 'keep', 'why' => $verdict[1], 'bytes' => 0];
    }
    if ($dryRun) {
        return ['state' => 'remove', 'why' => $verdict[1], 'bytes' => strlen($content)];
    }
    clearstatcache();
    $now = @stat($file);
    if ($now === false || $now['ino'] !== $before['ino'] || $now['size'] !== $before['size'] || $now['mtime'] !== $before['mtime']) {
        return ['state' => 'keep', 'why' => 'written again while it was judged', 'bytes' => 0];
    }
    if (!@unlink($file)) {
        return ['state' => 'failed', 'why' => 'it could not be removed', 'bytes' => 0];
    }
    return ['state' => 'remove', 'why' => $verdict[1], 'bytes' => strlen($content)];
}

/**
 * What one run did, or would do: each file it removes (or each change it makes),
 * each one it left for a reason that needs a look, and a count of the files it
 * kept, by reason. Printed as one report; its exit code is the run's.
 */
final class QsToolReport {
    /** @var array<int, array{state: string, path: string, why: string, bytes: int}> */
    private array $lines = [];
    /** @var array<string, int> */
    private array $kept = [];
    private int $examined = 0;

    /**
     * @param string $unit       what "examined" counts, as printed ('' for files)
     * @param bool   $countLines whether each add() and keep() is one examined (files);
     *                           false when the tool counts with examined() (projects)
     */
    public function __construct(private string $unit = '', private bool $countLines = true) {
    }

    /** Record one outcome of qs_tool_remove_*() (or a change), for the file at $path (relative to the engine folder). */
    public function add(string $path, array $outcome): void {
        if ($this->countLines) {
            $this->examined++;
        }
        if ($outcome['state'] === 'keep') {
            $this->kept[$outcome['why']] = ($this->kept[$outcome['why']] ?? 0) + 1;
            return;
        }
        $this->lines[] = ['state' => $outcome['state'], 'path' => $path, 'why' => $outcome['why'], 'bytes' => (int) ($outcome['bytes'] ?? 0)];
    }

    /** Count something kept without being opened ("within its age"). */
    public function keep(string $why): void {
        if ($this->countLines) {
            $this->examined++;
        }
        $this->kept[$why] = ($this->kept[$why] ?? 0) + 1;
    }

    /** Count what was examined, for a report that does not count its lines. */
    public function examined(int $n = 1): void {
        $this->examined += $n;
    }

    /** How many lines of one state the run has. */
    public function count(string $state): int {
        return count(array_filter($this->lines, fn($l) => $l['state'] === $state));
    }

    /** 2 when something needs a look (see the header), else 0. */
    public function exitCode(): int {
        foreach ($this->lines as $l) {
            if (in_array($l['state'], ['unreadable', 'failed', 'link'], true)) {
                return QS_TOOL_EXIT_LEFT;
            }
        }
        return QS_TOOL_EXIT_OK;
    }

    /**
     * The report: a title, the engine folder, one line per file removed or
     * changed and per file left for a reason that needs a look, then the totals.
     * $verbs: what a removal or a change is called, as done and as a dry run says it.
     */
    public function print(string $title, bool $dryRun, array $verbs = ['remove' => ['removed', 'would remove']]): void {
        printf("QuickSite %s%s\n", $title, $dryRun ? ' (dry run)' : '');
        printf("  engine       %s\n", SECURE_FOLDER_PATH);
        $labels = [
            'in-use'     => 'in use',
            'unreadable' => 'could not judge',
            'failed'     => 'FAILED',
            'link'       => 'link, left',
        ];
        foreach ($verbs as $state => [$done, $dry]) {
            $labels[$state] = $dry !== null && $dryRun ? $dry : $done;
        }
        $bytes = 0;
        foreach ($this->lines as $l) {
            printf("  %-12s %s  (%s)\n", $labels[$l['state']] ?? $l['state'], $l['path'], $l['why']);
            $bytes += $l['bytes'];
        }
        // A unit is written plural ('projects'); one is said without its s.
        $unit = $this->unit === '' ? '' : ' ' . ($this->examined === 1 ? rtrim($this->unit, 's') : $this->unit);
        printf("  examined     %d%s\n", $this->examined, $unit);
        foreach (array_keys($verbs) as $state) {
            $n = $this->count($state);
            printf("  %-12s %d%s\n", $labels[$state], $n, $state === 'remove' && $n > 0 ? ' (' . qs_tool_bytes($bytes) . ')' : '');
        }
        $kept = array_sum($this->kept);
        $why = [];
        foreach ($this->kept as $reason => $n) {
            $why[] = $n . ' ' . $reason;
        }
        printf("  kept         %d%s\n", $kept, $why ? ' (' . implode('; ', $why) . ')' : '');
        foreach (['in-use', 'unreadable', 'failed', 'link'] as $state) {
            $n = $this->count($state);
            if ($n > 0) {
                printf("  %-12s %d%s\n", $labels[$state], $n, $state === 'in-use' ? ' (the next run takes them)' : ' (each listed above: they need a look)');
            }
        }
    }
}

/** A size for people: B, KB or MB. */
function qs_tool_bytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

/** A path under the engine folder, written with forward slashes, for a report line. */
function qs_tool_rel(string $path): string {
    $rel = substr($path, strlen(SECURE_FOLDER_PATH) + 1);
    return str_replace('\\', '/', $rel);
}
