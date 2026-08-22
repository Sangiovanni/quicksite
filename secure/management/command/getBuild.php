<?php
/**
 * getBuild - Returns the project's build
 *
 * @method GET
 * @url /management/getBuild
 * @auth required
 * @permission read
 *
 * Takes no parameters. Retention is N = 1, so a project has one build or none,
 * and this command reports whichever it is. It replaces the old listBuilds:
 * a 0-or-1 element array carried strictly less than this does.
 *
 * Reports COMPLETENESS. build_manifest.json is written last, so its absence
 * means the build did not finish. `build` removes its own partial on failure,
 * but if that removal itself fails the survivor is reported here as incomplete
 * rather than passing for a usable build.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Command function for internal execution via CommandRunner
 *
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getBuild(array $params = [], array $urlParams = []): ApiResponse {
    $buildName = qs_build_current();

    if ($buildName === null) {
        return ApiResponse::create(404, 'build.not_found')
            ->withMessage('This project has no build')
            ->withData([
                'exists' => false,
                'hint'   => 'Run build to create one.'
            ]);
    }

    $buildFolder = qs_build_path($buildName);
    $complete    = qs_build_is_complete($buildName);

    $buildData = [
        'exists'   => true,
        'name'     => $buildName,
        'path'     => $buildFolder,
        'complete' => $complete
    ];

    // Merge the manifest when the build finished. A build with no manifest has
    // no trustworthy metadata to report, so nothing is invented for it.
    if ($complete) {
        $manifest = json_decode((string) file_get_contents($buildFolder . '/build_manifest.json'), true);
        if (is_array($manifest)) {
            $buildData = array_merge($buildData, $manifest);
        }
    } else {
        $buildData['warning'] = 'This build is incomplete — it carries no build_manifest.json, so it did not finish. Remove it with deleteBuild before building again.';
    }

    // Size and file count
    $folderSize = 0;
    $fileCount = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildFolder, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $folderSize += $file->getSize();
        $fileCount++;
    }
    $buildData['size_bytes'] = $folderSize;
    $buildData['size_mb'] = round($folderSize / 1024 / 1024, 2);
    $buildData['file_count'] = $fileCount;

    // No download_url: the build is not reachable by URL. downloadBuild zips it
    // on demand and streams the bytes; nothing is served statically.
    $buildData['download_with'] = 'downloadBuild';

    // List top-level contents (public and secure folders)
    $contents = [];
    foreach (scandir($buildFolder) as $item) {
        if ($item === '.' || $item === '..') continue;

        $itemPath = $buildFolder . '/' . $item;
        $itemInfo = [
            'name' => $item,
            'type' => is_dir($itemPath) ? 'directory' : 'file'
        ];

        if (is_file($itemPath)) {
            $itemInfo['size_bytes'] = filesize($itemPath);
        }

        $contents[] = $itemInfo;
    }
    $buildData['contents'] = $contents;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($complete
            ? 'Build details retrieved successfully'
            : 'Build details retrieved — the build is INCOMPLETE')
        ->withData($buildData);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getBuild()->send();
}
