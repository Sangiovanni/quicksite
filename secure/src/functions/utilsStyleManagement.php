<?php
/**
 * utilsStyleManagement.php
 * 
 * Shared helpers for CSS file path resolution, locking, and dual-write
 * operations (live stylesheet + project backup copy).
 * 
 * All CSS write commands should use these helpers to guarantee both the live
 * stylesheet and the project backup copy stay in sync.
 */

/**
 * Returns the path to the live stylesheet for the current project.
 * This is the file actively served to site visitors.
 */
function cssLivePath(): string {
    return PUBLIC_CONTENT_PATH . '/style/style.css';
}

/**
 * Returns the path to the project backup stylesheet for the current project.
 * This copy mirrors the live file and is used during builds and deployments.
 */
function cssProjectPath(): string {
    return PROJECT_PATH . '/public/style/style.css';
}

/**
 * Acquires an exclusive file lock for CSS write operations.
 * The lock is keyed on the live stylesheet path.
 * 
 * @param string $styleFile Path to the live stylesheet (used to derive the lock key).
 * @return resource|null File handle with lock held, or null if the lock could not be acquired.
 */
function cssAcquireLock(string $styleFile) {
    $lockFile = sys_get_temp_dir() . '/quicksite_style_' . md5($styleFile) . '.lock';
    $lock = fopen($lockFile, 'w');
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return null;
    }
    return $lock;
}

/**
 * Releases and closes a CSS write lock previously acquired by cssAcquireLock().
 * 
 * @param resource $lock File handle returned by cssAcquireLock().
 */
function cssReleaseLock($lock): void {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * Writes CSS content to the live stylesheet and the project backup copy.
 * 
 * If both paths resolve to the same file, the content is written only once.
 * Ensures the project backup directory exists before writing.
 * The caller must hold the lock (via cssAcquireLock) before calling this.
 * 
 * @param string $content    Updated CSS content to write.
 * @param string $livePath   Path to the live stylesheet.
 * @param string $projectPath Path to the project backup stylesheet.
 * @throws Exception If a directory cannot be created or a write fails.
 */
function cssWriteAllTargets(string $content, string $livePath, string $projectPath): void {
    if (file_put_contents($livePath, $content) === false) {
        throw new Exception('Failed to write live style file');
    }

    if ($projectPath !== $livePath) {
        $projectDir = dirname($projectPath);
        if (!is_dir($projectDir) && !mkdir($projectDir, 0755, true)) {
            throw new Exception('Failed to create project style directory');
        }
        if (file_put_contents($projectPath, $content) === false) {
            throw new Exception('Failed to write project style file');
        }
    }
}
