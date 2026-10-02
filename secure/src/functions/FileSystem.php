<?php

/**
 * FileSystem Utilities
 * 
 * Generic filesystem operations for copying, deleting, and measuring directories.
 * These functions are reusable across multiple commands.
 */

/**
 * Recursively copy a directory and all its contents
 * 
 * @param string $source Source directory path
 * @param string $dest Destination directory path
 * @return bool True on success, false on failure
 */
function copyDirectory(string $source, string $dest): bool {
    if (!is_dir($source)) return false;
    if (!is_dir($dest) && !mkdir($dest, 0755, true)) return false;
    
    foreach (scandir($source) as $item) {
        if ($item == '.' || $item == '..') continue;
        
        $sourcePath = $source . '/' . $item;
        $destPath = $dest . '/' . $item;
        
        if (is_dir($sourcePath)) {
            if (!copyDirectory($sourcePath, $destPath)) return false;
        } else {
            if (!copy($sourcePath, $destPath)) return false;
        }
    }
    return true;
}

/**
 * Recursively delete a directory, reporting what could NOT be removed.
 *
 * THE ONE RECURSIVE DELETE. Every command that removes a tree calls this, so a
 * tree is removed the same way everywhere and on every operating system.
 *
 * It keeps going past a failure and says what survived: a caller learns that a
 * tree half-deleted, and which entry stopped it, rather than one boolean for
 * the whole of it.
 *
 * ⚠ PATHS ARE RELATIVE to `$dir`. What survived is diagnostic and travels into
 * API responses; the absolute path of a server directory does not belong
 * there, and the caller already knows the root it asked to delete.
 *
 * Depth-first, children before their parent, so a directory is attempted only
 * once everything under it is gone — a parent that then fails is a real
 * failure and not an artifact of ordering.
 *
 * A LINK IS REMOVED, NEVER FOLLOWED. An entry that leads somewhere other than
 * where it sits — a symlink, or a Windows junction, which PHP does not report as
 * a link — is removed as the link it is, and whatever it points to is left
 * alone, so a link cannot walk the delete out of the tree.
 *
 * A READ-ONLY FILE IS REMOVED, as Linux removes one from a folder it may write
 * to. Windows refuses to delete a file marked read-only, so the mark is cleared
 * first; a file that still cannot be removed gets its mark back and is reported.
 *
 * ⚠ `$deferLast` IS NOT MERELY AN ORDERING. Those entries are attempted only
 * once everything else is gone, and are LEFT ALONE when anything else failed.
 * The distinction is the whole point: a caller whose own authority to delete
 * lives inside the tree — deleteProject, whose permission gate reads
 * config/members.json — would otherwise destroy that record on the way past and
 * leave a half-deleted project with no owner, which no retry can finish
 * because the retry is refused. Ordering alone does not fix it: this function
 * continues past failures by design, so a merely-reordered entry would still be
 * deleted at the end of a failed run.
 *
 * @param string   $dir       Directory path to delete.
 * @param string[] $deferLast Top-level entry names to remove last, and only if
 *                            everything else was removed.
 * @return array{ok: bool, files: int, dirs: int, survived: string[], retained: string[]}
 *         `ok` is true only when nothing is left. `survived` is what could not
 *         be removed; `retained` is what was deliberately kept because
 *         something else failed. Both are capped at 50 entries — a response is
 *         a diagnosis, not an inventory.
 */
function qs_delete_tree(string $dir, array $deferLast = []): array {
    $report = ['ok' => false, 'files' => 0, 'dirs' => 0, 'survived' => [], 'retained' => []];
    if (!is_dir($dir)) {
        return $report;
    }

    if (empty($deferLast)) {
        $report['ok'] = qs_delete_tree_walk($dir, '', $report);
        return $report;
    }

    // Pass 1 — everything except the deferred entries.
    $ok = true;
    foreach (@scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $deferLast, true)) {
            continue;
        }
        if (!qs_delete_tree_entry($dir, $item, $item, $report)) {
            $ok = false;
        }
    }

    // Something is still there, so the deferred entries stay too.
    if (!$ok) {
        foreach ($deferLast as $item) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . $item)) {
                $report['retained'][] = $item;
            }
        }
        qs_delete_tree_note($report, '.');
        return $report;
    }

    // Pass 2 — the deferred entries, then the directory itself.
    foreach ($deferLast as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!file_exists($path) && !is_link($path)) {
            continue;
        }
        if (!qs_delete_tree_entry($dir, $item, $item, $report)) {
            $ok = false;
        }
    }
    if ($ok && @rmdir($dir)) {
        $report['dirs']++;
        $report['ok'] = true;
        return $report;
    }
    qs_delete_tree_note($report, '.');
    return $report;
}

/** The recursion behind qs_delete_tree(). $rel is the path so far, for reporting. */
function qs_delete_tree_walk(string $dir, string $rel, array &$report): bool {
    $ok = true;
    $items = @scandir($dir);
    if ($items === false) {
        qs_delete_tree_note($report, $rel === '' ? '.' : $rel);
        return false;
    }

    $realDir = realpath($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $childRel = $rel === '' ? $item : $rel . '/' . $item;
        if (!qs_delete_tree_entry($dir, $item, $childRel, $report, $realDir)) {
            $ok = false;
        }
    }

    if (@rmdir($dir)) {
        $report['dirs']++;
        return $ok;
    }
    qs_delete_tree_note($report, $rel === '' ? '.' : $rel);
    return false;
}

/**
 * Remove one entry — a subtree, a file, or a link — and record it either way.
 *
 * @param string            $dir      The directory holding it.
 * @param string            $item     Its name.
 * @param string            $childRel Its path relative to the delete root, for reporting.
 * @param string|false|null $realDir  realpath($dir), when the caller already has it.
 */
function qs_delete_tree_entry(string $dir, string $item, string $childRel, array &$report, $realDir = null): bool {
    $path = $dir . DIRECTORY_SEPARATOR . $item;

    if (is_dir($path) && !qs_delete_tree_leads_elsewhere($dir, $item, $path, $realDir)) {
        return qs_delete_tree_walk($path, $childRel, $report);
    }
    // A file or a link. A link to a directory is removed with rmdir on Windows,
    // a Windows junction always; neither touches what it points to.
    if (@unlink($path) || @rmdir($path) || qs_delete_tree_unlink_read_only($path)) {
        $report['files']++;
        return true;
    }
    qs_delete_tree_note($report, $childRel);
    return false;
}

/**
 * Whether a directory entry is a link: a symlink, or anything whose real path is
 * not the path it sits at — a Windows junction, which is_link() does not report.
 *
 * @param string|false|null $realDir realpath($dir), when the caller already has it
 */
function qs_delete_tree_leads_elsewhere(string $dir, string $item, string $path, $realDir = null): bool {
    if (is_link($path)) {
        return true;
    }
    $realDir = $realDir ?? realpath($dir);
    $real = realpath($path);
    return $realDir === false || $real === false || $real !== $realDir . DIRECTORY_SEPARATOR . $item;
}

/** Remove a file marked read-only; give the mark back when it still cannot be removed. */
function qs_delete_tree_unlink_read_only(string $path): bool {
    if (!is_file($path) || is_writable($path)) {
        return false;
    }
    $mode = @fileperms($path);
    if (!@chmod($path, 0666)) {
        return false;
    }
    if (@unlink($path)) {
        return true;
    }
    if ($mode !== false) {
        @chmod($path, $mode & 0777);
    }
    return false;
}

/**
 * Remove a tree a failed command was building, and log what could not be
 * removed. A rollback has already decided its answer — the failure that caused
 * it — so what survived goes to the PHP error log for the operator: a leftover
 * project folder is why a retry under the same name is refused.
 *
 * @param string $caller Who rolls back, for the log line (a command name).
 */
function qs_delete_tree_rollback(string $dir, string $caller): bool {
    $report = qs_delete_tree($dir);
    if (!$report['ok'] && file_exists($dir)) {
        $left = count($report['survived']);
        error_log("QuickSite: {$caller} could not remove all of '" . basename($dir) . "' while rolling back; "
            . $left . ($left === 1 ? ' entry' : ' entries') . ' left: ' . implode(', ', $report['survived']));
    }
    return $report['ok'];
}

/** Record one survivor, up to the cap. */
function qs_delete_tree_note(array &$report, string $rel): void {
    if (count($report['survived']) < 50) {
        $report['survived'][] = $rel;
    }
}

/**
 * Recursively delete a directory and all its contents.
 *
 * The boolean face of qs_delete_tree(), for callers that only branch on
 * success. Anything that reports a failure to a person should call
 * qs_delete_tree() directly and say what survived.
 *
 * @param string $dir Directory path to delete
 * @return bool True on success, false on failure
 */
function deleteDirectory(string $dir): bool {
    return qs_delete_tree($dir)['ok'];
}

/**
 * Calculate total size of a directory and all its contents
 * 
 * @param string $dir Directory path
 * @return int Total size in bytes
 */
function getDirectorySize(string $dir): int {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}
