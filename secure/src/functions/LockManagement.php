<?php

/**
 * Lock Management Utilities
 * 
 * Generic file-based locking mechanism for preventing concurrent operations.
 * Provides mutual exclusion for critical sections across PHP processes.
 */

/**
 * Acquire an exclusive lock for an operation
 * 
 * @param string $lockFileName Name of the lock file (e.g., 'build', 'backup')
 * @param string|null $serverRoot Optional server root path (defaults to SERVER_ROOT constant)
 * @return array|false Returns ['handle' => resource, 'file' => string] on success, false on failure
 */
function acquireLock(string $lockFileName, ?string $serverRoot = null) {
    $serverRoot = $serverRoot ?? (defined('SERVER_ROOT') ? SERVER_ROOT : dirname(__DIR__, 3));
    $lockFile = $serverRoot . DIRECTORY_SEPARATOR . '.' . $lockFileName . '.lock';
    
    $lockHandle = fopen($lockFile, 'c');
    if (!$lockHandle) {
        return false;
    }
    
    // Try to acquire exclusive lock (non-blocking)
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return false;
    }
    
    return [
        'handle' => $lockHandle,
        'file' => $lockFile
    ];
}

/**
 * Release a previously acquired lock
 * 
 * @param array $lock Lock array returned by acquireLock()
 * @return bool True on success, false on failure
 */
function releaseLock(array $lock): bool {
    if (!isset($lock['handle']) || !isset($lock['file'])) {
        return false;
    }
    
    flock($lock['handle'], LOCK_UN);
    fclose($lock['handle']);
    @unlink($lock['file']);
    
    return true;
}

/**
 * Check if a lock is currently held
 * 
 * @param string $lockFileName Name of the lock file
 * @param string|null $serverRoot Optional server root path
 * @return bool True if locked, false if available
 */
function isLocked(string $lockFileName, ?string $serverRoot = null): bool {
    $serverRoot = $serverRoot ?? (defined('SERVER_ROOT') ? SERVER_ROOT : dirname(__DIR__, 3));
    $lockFile = $serverRoot . DIRECTORY_SEPARATOR . '.' . $lockFileName . '.lock';
    
    if (!file_exists($lockFile)) {
        return false;
    }
    
    $lockHandle = @fopen($lockFile, 'r');
    if (!$lockHandle) {
        return false;
    }
    
    $canLock = flock($lockHandle, LOCK_EX | LOCK_NB);
    if ($canLock) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return false;
    }
    
    fclose($lockHandle);
    return true;
}
