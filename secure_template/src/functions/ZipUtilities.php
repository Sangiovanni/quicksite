<?php

/**
 * ZIP Archive Utilities
 * 
 * Helper functions for creating and manipulating ZIP archives.
 */

/**
 * Recursively add a directory and all its contents to a ZIP archive
 * 
 * @param ZipArchive $zip The ZIP archive object
 * @param string $dir Directory path to add
 * @param string $baseDir Base directory path for ZIP internal structure (optional)
 * @return void
 */
function addDirectoryToZip(ZipArchive $zip, string $dir, string $baseDir = ''): void {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $dir . '/' . $file;
        $zipPath = $baseDir . '/' . $file;
        
        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipPath);
            addDirectoryToZip($zip, $filePath, $zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
        }
    }
}

/**
 * Create a ZIP archive from a directory
 * 
 * @param string $sourceDir Source directory to compress
 * @param string $zipFilePath Destination ZIP file path
 * @param string|null $internalBaseName Optional base name for files inside ZIP
 * @return array Result with 'success' (bool), 'zip_path' (string), 'size_bytes' (int), 'error' (string|null)
 */
function createZipFromDirectory(string $sourceDir, string $zipFilePath, ?string $internalBaseName = null): array {
    if (!is_dir($sourceDir)) {
        return [
            'success' => false,
            'error' => 'Source directory does not exist'
        ];
    }
    
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return [
            'success' => false,
            'error' => 'Failed to create ZIP archive'
        ];
    }
    
    $baseName = $internalBaseName ?? basename($sourceDir);
    addDirectoryToZip($zip, $sourceDir, $baseName);
    $zip->close();
    
    $zipSize = file_exists($zipFilePath) ? filesize($zipFilePath) : 0;
    
    return [
        'success' => true,
        'zip_path' => $zipFilePath,
        'size_bytes' => $zipSize,
        'size_mb' => round($zipSize / 1024 / 1024, 2),
        'error' => null
    ];
}
