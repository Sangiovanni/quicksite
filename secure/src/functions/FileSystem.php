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
 * ⚠ WHY THIS RETURNS A REPORT. Two implementations of `deleteDirectory()` used
 * to exist under one name — this one, which swallowed every failure with
 * `@unlink` and answered only on the final `rmdir`, and a copy inside
 * deleteProject.php that returned on the FIRST failure. Both told the caller a
 * single boolean, so a project that half-deleted was reported as "failed" with
 * no way to learn that most of it was gone, and a locked file deep in the tree
 * looked identical to a permission problem at the root. (Being two global
 * functions of one name, they were also a latent redeclare fatal for any
 * process that loaded both — the class of collision S2.9 fixed for
 * formatBytes.)
 *
 * So it keeps going, and it says what survived.
 *
 * ⚠ PATHS ARE RELATIVE to `$dir`. What survived is diagnostic and travels into
 * API responses; the absolute path of a server directory does not belong
 * there, and the caller already knows the root it asked to delete.
 *
 * Depth-first, children before their parent, so a directory is attempted only
 * once everything under it is gone — a parent that then fails is a real
 * failure and not an artifact of ordering. Symlinks and Windows junctions are
 * removed as links rather than descended into, so a reparse point cannot walk
 * the delete out of the tree.
 *
 * ⚠ `$deferLast` IS NOT MERELY AN ORDERING. Those entries are attempted only
 * once everything else is gone, and are LEFT ALONE when anything else failed.
 * The distinction is the whole point: a caller whose own authority to delete
 * lives inside the tree — deleteProject, whose permission gate reads
 * config/members.json — otherwise destroys that record on the way past and
 * leaves a half-deleted project with no owner, which no retry can finish
 * because the retry is refused. Ordering alone does not fix it: this function
 * continues past failures by design, so a merely-reordered entry still gets
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

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $childRel = $rel === '' ? $item : $rel . '/' . $item;
        if (!qs_delete_tree_entry($dir, $item, $childRel, $report)) {
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
 * @param string $dir      The directory holding it.
 * @param string $item     Its name.
 * @param string $childRel Its path relative to the delete root, for reporting.
 */
function qs_delete_tree_entry(string $dir, string $item, string $childRel, array &$report): bool {
    $path = $dir . DIRECTORY_SEPARATOR . $item;

    // is_dir() follows a symlink/junction; is_link() catches it first so a
    // reparse point is removed as the link it is, not descended into.
    if (is_dir($path) && !is_link($path)) {
        return qs_delete_tree_walk($path, $childRel, $report);
    }
    // A Windows directory junction needs rmdir, not unlink.
    if (@unlink($path) || (is_link($path) && @rmdir($path))) {
        $report['files']++;
        return true;
    }
    qs_delete_tree_note($report, $childRel);
    return false;
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
