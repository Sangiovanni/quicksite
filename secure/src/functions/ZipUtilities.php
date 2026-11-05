<?php

/**
 * ZIP Archive Utilities
 * 
 * Helper functions for creating and manipulating ZIP archives.
 */

require_once __DIR__ . '/FileSystem.php'; // qs_delete_tree_leads_elsewhere

/**
 * Recursively add a directory and all its contents to a ZIP archive. A link inside it
 * (a Windows junction included) is left out, its target never read: a walker that
 * follows one puts whatever it leads to in the archive.
 *
 * @param ZipArchive $zip The ZIP archive object
 * @param string $dir Directory path to add
 * @param string $baseDir Base directory path for ZIP internal structure (optional)
 * @return void
 */
function addDirectoryToZip(ZipArchive $zip, string $dir, string $baseDir = ''): void {
    $realDir = realpath($dir);
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;

        $filePath = $dir . '/' . $file;
        $zipPath = $baseDir . '/' . $file;

        if (qs_delete_tree_leads_elsewhere($dir, $file, $filePath, $realDir)) continue;

        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipPath);
            addDirectoryToZip($zip, $filePath, $zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
        }
    }
}

/**
 * A temporary archive a request builds, streams and removes (an export streamed without
 * saving, a build's download). Arm it as soon as its file exists: however the request
 * ends from then on — the archive sent whole, the client gone mid-transfer (the web
 * server stops the script inside the send), a fatal error while the archive is built,
 * or a refusal before a byte is sent — the file is removed. qs_temp_archive_send()
 * streams through a handle it registers here, and the removal closes that handle
 * first: Windows refuses to remove a file that is still open.
 */
function qs_temp_archive_arm(string $path): void {
    $GLOBALS['__qs_temp_archives'][$path] = null;
    register_shutdown_function(static function () use ($path): void {
        $handle = $GLOBALS['__qs_temp_archives'][$path] ?? null;
        if (is_resource($handle)) {
            @fclose($handle);
        }
        if (is_file($path)) {
            @unlink($path);
        }
    });
}

/**
 * Stream an armed temporary archive as the download $filename, remove it and end the
 * request. Returns false, having sent nothing, when the archive cannot be opened; the
 * caller answers that, and the armed removal still runs.
 */
function qs_temp_archive_send(string $path, string $filename): bool {
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $GLOBALS['__qs_temp_archives'][$path] = $handle;
    $stat = fstat($handle);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (is_array($stat) ? (int) $stat['size'] : (int) @filesize($path)));
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');
    header('Expires: 0');
    fpassthru($handle);
    fclose($handle);
    $GLOBALS['__qs_temp_archives'][$path] = null;
    @unlink($path);
    exit;
}
