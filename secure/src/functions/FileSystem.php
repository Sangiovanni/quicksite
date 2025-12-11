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
 * Recursively delete a directory and all its contents
 * 
 * @param string $dir Directory path to delete
 * @return bool True on success, false on failure
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) return false;
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
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
