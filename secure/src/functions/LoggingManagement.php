<?php
require_once __DIR__ . '/utilsManagement.php'; // qs_json_write
/**
 * LoggingManagement.php
 * 
 * Command logging functions for QuickSite v1.5.0
 * Handles logging API commands to daily JSON files
 */

require_once __DIR__ . '/PathManagement.php'; // is_valid_project_name (F1 gate)

if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', SECURE_FOLDER_PATH . '/logs');
}

/**
 * The command log is PER-PROJECT (beta.10 C10 10.1b, Sangio's ruling).
 *
 *   secure/logs/p/<projectId>/commands_<date>.json   project-scoped commands
 *   secure/logs/_global/commands_<date>.json         global-scoped commands
 *
 * WHY DIRECTORIES, NOT A `project` FIELD PER ENTRY (F-C10-2 / F-C10-3):
 * the store previously had no project dimension at all while `history` was
 * DECLARED project-scoped in categories.php. Since any authenticated user can
 * create a project and is its owner, that mismatch let anyone read — and clear —
 * the whole installation's audit log. A per-entry field would fix the read but
 * make `clearCommandHistory` rewrite day-files that hold OTHER projects' records:
 * a containment fix that introduces a cross-project write. With directories,
 * clearing is an unlink inside the caller's own directory, and a reader must
 * construct another project's path to see another project's data — structural,
 * not filter-dependent (the same fail-closed idiom as the L11 serving jail).
 *
 * The `_global` bucket is WRITTEN but served to nobody in beta.10: global
 * commands (account + membership self-service) belong to no project, and there
 * is no operator tier to show them to (superadmin was retired). Recording them
 * keeps the forensic trail for account deletion / invitation acceptance instead
 * of leaving those actions unaudited. Operators manage that directory directly
 * on disk — see docs/ARCHITECTURE.md.
 *
 * `_global` and `p` cannot collide with a project id: is_valid_project_name
 * requires a leading LETTER, so a project can never be named `_global`, and the
 * literal `p` segment separates the project tree from it.
 */
const QS_LOG_GLOBAL_BUCKET = '_global';

/**
 * Resolve the log directory for a project, or the global bucket.
 *
 * @param string|null $project Validated projectId, or null/'' for global.
 * @return string|null Absolute directory path, or NULL when the projectId fails
 *                     the F1 shape gate (fail-closed: the caller must not log
 *                     rather than log to a guessed location).
 */
function qs_log_dir(?string $project = null): ?string {
    if ($project === null || $project === '') {
        return LOGS_PATH . '/' . QS_LOG_GLOBAL_BUCKET;
    }
    // Defence in depth: the dispatcher already validated this, but the value
    // becomes a directory selector here (F1). No separators can survive.
    if (!is_valid_project_name($project)) {
        return null;
    }
    return LOGS_PATH . '/p/' . $project;
}

/**
 * Ensure a log directory exists
 */
function ensureLogsDirectory(?string $dir = null): bool {
    $dir = $dir ?? LOGS_PATH;
    if (!is_dir($dir)) {
        return mkdir($dir, 0755, true);
    }
    return true;
}

/**
 * Get the log file path for a specific date, within a project (or global).
 *
 * @return string|null NULL when the projectId fails the F1 gate.
 */
function getLogFilePath(?string $date = null, ?string $project = null): ?string {
    $date = $date ?? date('Y-m-d');
    $dir  = qs_log_dir($project);
    return $dir === null ? null : $dir . '/commands_' . $date . '.json';
}

/**
 * Generate a unique log entry ID
 */
function generateLogId(): string {
    return 'log_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
}

/**
 * Commands whose request body is NEVER logged, in any form (beta.10 C10 10.1b).
 * The entry itself still records the command, the publisher and the result — so
 * "this user changed their password at 14:02" stays auditable — but the body
 * carries only credentials and adds nothing an auditor needs.
 *
 * The public session commands (login/register) run before the dispatcher
 * installs its logging callback and are therefore not logged at all today; they
 * are listed anyway so the guarantee does not depend on that dispatch detail
 * staying true.
 */
/*
 * changePassword / deleteMyAccount stopped being commands in beta.11 S6 —
 * managing the login you sign in with is not project development, so both are
 * served by /admin/self, which does not run this logger at all. Their entries
 * are KEPT rather than pruned: a deny-list that names something unreachable
 * costs nothing and fails safe, while removing a name is how a body starts being
 * logged the day a command by that name comes back.
 */
const QS_LOG_SKIP_BODY_COMMANDS = [
    'login', 'register', 'logoutSession',
    'changePassword', 'deleteMyAccount',
];

/**
 * Key names whose VALUE is a credential. Matched case-insensitively against every
 * key at every depth of a request body.
 *
 * Deliberate boundary choices, verified against the live command surface:
 *   \bkey\b     matches a bare `key` but NOT the translation commands' `keys`
 *               (setTranslationKeys / deleteTranslationKeys), whose body IS the
 *               content being audited.
 *   \bauth\b    matches setRouteResolver's `auth` block but NOT `author`.
 *   api[_-]?key catches apiKey / api_key / api-key, which \bkey\b cannot see
 *               (no word boundary inside "apiKey").
 */
const QS_LOG_SECRET_KEY_PATTERN =
    '/pass|secret|token|credential|\bkey\b|api[_-]?key|private[_-]?key|\bauth\b|authoriz|signature|\bsalt\b/i';

/**
 * Recursively redact credential-shaped keys anywhere in a body.
 *
 * A matching key has its ENTIRE value replaced — including a whole sub-array, so
 * `credentials: {client_secret: …}` and `auth: {token: …}` are removed wholesale
 * rather than walked into and half-missed. The key itself is KEPT so the audit
 * trail still records that a credential was submitted, only never what it was.
 *
 * @param int $depth Recursion guard for pathological nesting.
 */
function qs_log_redact_secrets(array $body, int $depth = 0): array {
    if ($depth > 8) {
        return ['_note' => 'Nesting too deep to sanitize — body omitted'];
    }
    $out = [];
    foreach ($body as $key => $value) {
        if (preg_match(QS_LOG_SECRET_KEY_PATTERN, (string)$key) === 1) {
            $out[$key] = '[redacted]';
            continue;
        }
        $out[$key] = is_array($value) ? qs_log_redact_secrets($value, $depth + 1) : $value;
    }
    return $out;
}

/**
 * Sanitize a request body for logging — DENY BY DEFAULT (beta.10 C10 10.1b).
 *
 * This used to be an allowlist keyed by COMMAND with `default: return $body`, so
 * every command not explicitly named logged its body verbatim and a NEW command
 * carrying a credential was exposed the moment it shipped — no edit here, no
 * warning. That is how cleartext passwords reached the command log (C10 §8).
 *
 * The rule is now inverted and command-independent: credential-shaped keys are
 * redacted for EVERY command, always, at every depth. The per-command cases below
 * only RESHAPE bulky bodies; the universal redaction runs last, so no case can
 * bypass it — including any case added later.
 */
function sanitizeLogBody(string $command, array $body): ?array {
    if (in_array($command, QS_LOG_SKIP_BODY_COMMANDS, true)) {
        return null;
    }

    // Per-command RESHAPING only (size, not sensitivity). Never the last word.
    switch ($command) {
        case 'uploadAsset':
            // Only log metadata, not file contents
            $body = [
                'filename' => $body['filename'] ?? null,
                'category' => $body['category'] ?? null,
                'size_logged' => isset($body['file']) ? (is_string($body['file']) ? strlen($body['file']) : 'file_upload') : null,
                '_note' => 'File content omitted from log'
            ];
            break;

        case 'editStyles':
            // Log summary for large style changes
            $css = $body['css'] ?? '';
            if (is_string($css) && strlen($css) > 5000) {
                $body = [
                    'css_length' => strlen($css),
                    'css_preview' => substr($css, 0, 500) . '...',
                    '_note' => 'Full CSS truncated (> 5KB)'
                ];
            }
            break;
    }

    // The gate every body passes through, whatever the command.
    return qs_log_redact_secrets($body);
}

/**
 * Create a log entry structure
 */
function createLogEntry(
    string $command,
    string $method,
    array $body,
    array $tokenInfo,
    int $httpStatus,
    string $responseCode,
    float $startTime
): array {
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    // Publisher identity (C5): the resolved user — the token no longer carries a
    // name, so we record the stable userId + display name.
    return [
        'id' => generateLogId(),
        'timestamp' => date('c'), // ISO 8601 format
        'command' => $command,
        'method' => $method,
        'body' => sanitizeLogBody($command, $body),
        'publisher' => [
            'user_id' => $tokenInfo['id'] ?? null,
            'token_name' => $tokenInfo['name'] ?? 'Unknown'
        ],
        'result' => [
            'http_status' => $httpStatus,
            'code' => $responseCode
        ],
        'duration_ms' => $duration
    ];
}

/**
 * Write a log entry to the daily log file
 * Uses file locking to prevent concurrent access issues on Windows
 */
function writeLogEntry(array $entry, ?string $project = null): bool {
    // If the project ceased to exist during this very request (deleteProject),
    // its bucket was just purged — writing here would RESURRECT an orphan
    // directory that no live project owns and no one can ever read (the reader
    // requires an authorized membership, and the project is gone). Route the
    // record to `_global` instead: the deletion stays audited, and the audit of
    // a project's death correctly outlives the project.
    if ($project !== null && $project !== ''
        && !is_dir(SECURE_FOLDER_PATH . '/projects/' . $project)) {
        $project = null;
    }

    $logFile = getLogFilePath(null, $project);
    if ($logFile === null) {
        return false; // invalid projectId — fail closed, never log to a guessed path
    }
    if (!ensureLogsDirectory(dirname($logFile))) {
        return false;
    }

    $lockFile = $logFile . '.lock';
    
    // Acquire exclusive lock using a separate lock file
    $lockHandle = fopen($lockFile, 'c');
    if (!$lockHandle) {
        return false;
    }
    
    // Try to get exclusive lock (blocking with timeout)
    $lockAcquired = false;
    $maxRetries = 10;
    for ($i = 0; $i < $maxRetries; $i++) {
        if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $lockAcquired = true;
            break;
        }
        usleep(50000); // Wait 50ms before retry
    }
    
    if (!$lockAcquired) {
        fclose($lockHandle);
        return false; // Could not acquire lock, skip logging this entry
    }
    
    try {
        // Read existing logs or start fresh
        $logs = [];
        if (file_exists($logFile)) {
            $content = @file_get_contents($logFile);
            if ($content !== false) {
                $logs = json_decode($content, true) ?? [];
            }
        }
        
        // Append new entry
        $logs[] = $entry;
        
        // Write directly to file (we have exclusive lock)
        return qs_json_write($logFile, $logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, LOCK_EX);
    } finally {
        // Always release lock and close handle
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/**
 * Log a command execution (main entry point)
 */
function logCommand(
    string $command,
    string $method,
    array $body,
    array $tokenInfo,
    int $httpStatus,
    string $responseCode,
    float $startTime,
    ?string $project = null
): bool {
    // Skip logging for read-only GET commands that don't modify anything
    // We still log them if they're successful for audit trail
    
    // Skip logging the getCommandHistory command itself to avoid recursion
    if ($command === 'getCommandHistory') {
        return true;
    }
    
    // Determine if this is a success response (2xx status codes)
    $isSuccess = $httpStatus >= 200 && $httpStatus < 300;
    
    // Only log successful commands and auth failures
    $shouldLog = $isSuccess || 
                 (in_array($responseCode, ['auth.invalid_token', 'auth.missing_token', 'auth.permission_denied']));
    
    if (!$shouldLog) {
        return true; // Not an error, just nothing to log
    }
    
    $entry = createLogEntry($command, $method, $body, $tokenInfo, $httpStatus, $responseCode, $startTime);
    return writeLogEntry($entry, $project);
}

/**
 * Get command history for ONE project, with optional filters.
 *
 * @param string $project The AUTHORIZED projectId (the caller has already passed
 *                        the `history` category check for it). Empty → no
 *                        history at all: there is no installation-wide view.
 */
function getCommandHistory(array $filters = [], string $project = ''): array {
    $logs = [];

    // Fail closed. A missing project must never widen into "every project".
    if ($project === '' || qs_log_dir($project) === null) {
        return [
            'entries'    => [],
            'pagination' => ['page' => 1, 'limit' => 0, 'total' => 0, 'pages' => 0],
        ];
    }

    // Date range
    $startDate = $filters['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $filters['end_date'] ?? date('Y-m-d');

    // Iterate through date range
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day'); // Include end date

    while ($current < $end) {
        $logFile = getLogFilePath($current->format('Y-m-d'), $project);
        if ($logFile !== null && file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $dayLogs = json_decode($content, true) ?? [];
            $logs = array_merge($logs, $dayLogs);
        }
        $current->modify('+1 day');
    }
    
    // Apply filters
    if (!empty($filters['command'])) {
        $commandFilter = strtolower($filters['command']);
        $logs = array_filter($logs, fn($l) => 
            stripos($l['command'], $commandFilter) !== false
        );
    }
    
    if (!empty($filters['status'])) {
        $statusFilter = strtolower($filters['status']);
        $logs = array_filter($logs, function($l) use ($statusFilter) {
            // Handle both old format (status: "success") and new format (http_status: 200)
            $httpStatus = $l['result']['http_status'] ?? $l['result']['status'] ?? null;
            
            if (is_numeric($httpStatus)) {
                $isSuccess = $httpStatus >= 200 && $httpStatus < 300;
            } else {
                $isSuccess = $httpStatus === 'success';
            }
            
            return $statusFilter === 'success' ? $isSuccess : !$isSuccess;
        });
    }
    
    if (!empty($filters['token_name'])) {
        $logs = array_filter($logs, fn($l) => 
            stripos($l['publisher']['token_name'] ?? '', $filters['token_name']) !== false
        );
    }
    
    // Re-index array
    $logs = array_values($logs);
    
    // Sort by timestamp descending (newest first)
    usort($logs, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
    
    // Pagination
    $page = max(1, intval($filters['page'] ?? 1));
    $limit = min(500, max(1, intval($filters['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;
    
    $total = count($logs);
    $logs = array_slice($logs, $offset, $limit);
    
    return [
        'entries' => $logs,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ];
}

/**
 * Clear ONE project's command history before a specific date.
 *
 * Scoped by DIRECTORY, so the deletion can only ever touch the caller's own
 * project (F-C10-3: this used to unlink across the whole installation, letting
 * any self-minted project owner erase the entire audit log).
 *
 * @param string $project The AUTHORIZED projectId. Empty → deletes nothing.
 */
function clearCommandHistory(string $beforeDate, string $project = ''): array {
    $empty = [
        'deleted_files' => 0,
        'deleted_entries' => 0,
        'space_freed_bytes' => 0,
        'space_freed_kb' => 0.0,
    ];

    // Fail closed — never fall back to the installation-wide sweep.
    $dir = ($project === '') ? null : qs_log_dir($project);
    if ($dir === null || !is_dir($dir)) {
        return $empty;
    }

    $deleted = 0;
    $entries = 0;
    $bytes = 0;

    $cutoff = new DateTime($beforeDate);

    $files = glob($dir . '/commands_*.json');
    foreach ($files as $file) {
        // Extract date from filename
        if (preg_match('/commands_(\d{4}-\d{2}-\d{2})\.json$/', $file, $matches)) {
            $fileDate = new DateTime($matches[1]);
            if ($fileDate < $cutoff) {
                $size = filesize($file);
                $content = json_decode(file_get_contents($file), true);
                $entryCount = is_array($content) ? count($content) : 0;
                
                if (unlink($file)) {
                    $deleted++;
                    $entries += $entryCount;
                    $bytes += $size;
                    @unlink($file . '.lock'); // the sidecar writeLogEntry created
                }
            }
        }
    }
    
    return [
        'deleted_files' => $deleted,
        'deleted_entries' => $entries,
        'space_freed_bytes' => $bytes,
        'space_freed_kb' => round($bytes / 1024, 2)
    ];
}

/**
 * Get list of available log dates for ONE project.
 *
 * @param string $project The AUTHORIZED projectId. Empty → no dates.
 */
function getLogDates(string $project = ''): array {
    $dir = ($project === '') ? null : qs_log_dir($project);
    if ($dir === null || !is_dir($dir)) {
        return [];
    }

    $dates = [];
    $files = glob($dir . '/commands_*.json');

    foreach ($files as $file) {
        if (preg_match('/commands_(\d{4}-\d{2}-\d{2})\.json$/', $file, $matches)) {
            $content = json_decode(file_get_contents($file), true);
            $dates[] = [
                'date' => $matches[1],
                'entries' => is_array($content) ? count($content) : 0,
                'size_bytes' => filesize($file)
            ];
        }
    }
    
    // Sort by date descending
    usort($dates, fn($a, $b) => strcmp($b['date'], $a['date']));
    
    return $dates;
}
