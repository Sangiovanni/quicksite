<?php
/**
 * Delete Build Command - Removes the project's build
 *
 * Method: POST
 * Endpoint: /management/deleteBuild
 *
 * Takes no parameters. Retention is N = 1, so there is nothing to choose
 * between: the project has one build or none, and this removes it.
 *
 * This is also the only thing that unblocks `build`, which refuses while a
 * build exists rather than overwriting one. An INCOMPLETE build (a partial
 * whose own cleanup failed) is removed by this command too — that is how the
 * user recovers without touching the filesystem.
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

$buildName = qs_build_current();

if ($buildName === null) {
    ApiResponse::create(404, 'build.not_found')
        ->withMessage('This project has no build to delete')
        ->withData(['exists' => false])
        ->send();
}

$buildFolder = qs_build_path($buildName);
$wasComplete = qs_build_is_complete($buildName);

// Size before deletion, for the freed-space report.
$folderSize = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($buildFolder, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $folderSize += $file->getSize();
}

if (!deleteDirectory($buildFolder) || is_dir($buildFolder)) {
    ApiResponse::create(500, 'server.file_delete_failed')
        ->withMessage('Failed to delete the build')
        ->withData([
            'build' => $buildName,
            'hint'  => 'The build directory could not be removed, so build will keep refusing. Check filesystem permissions.'
        ])
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Build deleted successfully')
    ->withData([
        'deleted_build'  => $buildName,
        'was_complete'   => $wasComplete,
        'space_freed_bytes' => $folderSize,
        'space_freed_mb' => round($folderSize / 1024 / 1024, 2)
    ])
    ->send();
