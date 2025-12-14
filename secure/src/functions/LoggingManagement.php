<?php
/**
 * LoggingManagement.php
 * 
 * Command logging functions for QuickSite v1.5.0
 * Handles logging API commands to daily JSON files
 */

if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', SECURE_FOLDER_PATH . '/logs');
}

/**
 * Ensure logs directory exists
 */
function ensureLogsDirectory(): bool {
    if (!is_dir(LOGS_PATH)) {
        return mkdir(LOGS_PATH, 0755, true);
    }
    return true;
}

/**
 * Get the log file path for a specific date
 */
function getLogFilePath(?string $date = null): string {
    $date = $date ?? date('Y-m-d');
    return LOGS_PATH . '/commands_' . $date . '.json';
}

/**
 * Generate a unique log entry ID
 */
function generateLogId(): string {
    return 'log_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
}

/**
 * Sanitize body for logging (remove sensitive data, handle special cases)
 */
function sanitizeLogBody(string $command, array $body): ?array {
    // Commands that should not log body at all
    $skipBodyCommands = [];
    
    if (in_array($command, $skipBodyCommands)) {
        return null;
    }
    
    // Special handling for specific commands
    switch ($command) {
        case 'uploadAsset':
            // Only log metadata, not file contents
            return [
                'filename' => $body['filename'] ?? null,
                'category' => $body['category'] ?? null,
                'size_logged' => isset($body['file']) ? strlen($body['file']) : null,
                '_note' => 'File content omitted from log'
            ];
            
        case 'generateToken':
            // Never log generated token, only request params
            return [
                'name' => $body['name'] ?? null,
                'permissions' => $body['permissions'] ?? null,
                '_note' => 'Generated token omitted from log'
            ];
            
        case 'editStyles':
            // Log summary for large style changes
            $css = $body['css'] ?? '';
            if (strlen($css) > 5000) {
                return [
                    'css_length' => strlen($css),
                    'css_preview' => substr($css, 0, 500) . '...',
                    '_note' => 'Full CSS truncated (> 5KB)'
                ];
            }
            return $body;
            
        default:
            return $body;
    }
}

/**
 * Create a log entry structure
 */
function createLogEntry(
    string $command,
    string $method,
    array $body,
    array $tokenInfo,
    string $status,
    string $responseCode,
    float $startTime
): array {
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    // Create safe token preview (first 7 + last 3 chars)
    $token = $tokenInfo['token'] ?? '';
    $tokenPreview = strlen($token) > 10 
        ? substr($token, 0, 7) . '...' . substr($token, -3)
        : '***';
    
    return [
        'id' => generateLogId(),
        'timestamp' => date('c'), // ISO 8601 format
        'command' => $command,
        'method' => $method,
        'body' => sanitizeLogBody($command, $body),
        'publisher' => [
            'token_preview' => $tokenPreview,
            'token_name' => $tokenInfo['name'] ?? 'Unknown'
        ],
        'result' => [
            'status' => $status,
            'code' => $responseCode
        ],
        'duration_ms' => $duration
    ];
}

/**
 * Write a log entry to the daily log file
 */
function writeLogEntry(array $entry): bool {
    if (!ensureLogsDirectory()) {
        return false;
    }
    
    $logFile = getLogFilePath();
    
    // Read existing logs or start fresh
    $logs = [];
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logs = json_decode($content, true) ?? [];
    }
    
    // Append new entry
    $logs[] = $entry;
    
    // Write back atomically
    $tempFile = $logFile . '.tmp';
    if (file_put_contents($tempFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        return rename($tempFile, $logFile);
    }
    
    return false;
}

/**
 * Log a command execution (main entry point)
 */
function logCommand(
    string $command,
    string $method,
    array $body,
    array $tokenInfo,
    string $status,
    string $responseCode,
    float $startTime
): bool {
    // Skip logging for read-only GET commands that don't modify anything
    // We still log them if they're successful for audit trail
    
    // Skip logging the getCommandHistory command itself to avoid recursion
    if ($command === 'getCommandHistory') {
        return true;
    }
    
    // Only log successful commands and auth failures
    $shouldLog = ($status === 'success') || 
                 (in_array($responseCode, ['auth.invalid_token', 'auth.missing_token', 'auth.permission_denied']));
    
    if (!$shouldLog) {
        return true; // Not an error, just nothing to log
    }
    
    $entry = createLogEntry($command, $method, $body, $tokenInfo, $status, $responseCode, $startTime);
    return writeLogEntry($entry);
}

/**
 * Get command history with optional filters
 */
function getCommandHistory(array $filters = []): array {
    $logs = [];
    
    // Date range
    $startDate = $filters['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $filters['end_date'] ?? date('Y-m-d');
    
    // Iterate through date range
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day'); // Include end date
    
    while ($current < $end) {
        $logFile = getLogFilePath($current->format('Y-m-d'));
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $dayLogs = json_decode($content, true) ?? [];
            $logs = array_merge($logs, $dayLogs);
        }
        $current->modify('+1 day');
    }
    
    // Apply filters
    if (!empty($filters['command'])) {
        $logs = array_filter($logs, fn($l) => $l['command'] === $filters['command']);
    }
    
    if (!empty($filters['status'])) {
        $logs = array_filter($logs, fn($l) => $l['result']['status'] === $filters['status']);
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
 * Clear command history before a specific date
 */
function clearCommandHistory(string $beforeDate): array {
    if (!is_dir(LOGS_PATH)) {
        return [
            'deleted_files' => 0,
            'deleted_entries' => 0,
            'space_freed_bytes' => 0
        ];
    }
    
    $deleted = 0;
    $entries = 0;
    $bytes = 0;
    
    $cutoff = new DateTime($beforeDate);
    
    $files = glob(LOGS_PATH . '/commands_*.json');
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
 * Get list of available log dates
 */
function getLogDates(): array {
    if (!is_dir(LOGS_PATH)) {
        return [];
    }
    
    $dates = [];
    $files = glob(LOGS_PATH . '/commands_*.json');
    
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
