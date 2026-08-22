<?php
/**
 * downloadBuild - Streams the project's build as a ZIP archive
 *
 * @method GET
 * @url /management/downloadBuild
 * @auth required
 *
 * Takes no parameters. Retention is N = 1, so there is one build or none.
 *
 * This command USED TO BE A URL GENERATOR: it contained no header() call and no
 * readfile, and answered a JSON envelope whose download_url pointed at a static
 * archive under the project's public/build/. That static path was the only fetch
 * mechanism that existed, and on a public project it served the whole archive to
 * anonymous callers. Builds now live outside public/ where no URL reaches them,
 * and this command is the fetch mechanism — so the download inherits the
 * dispatcher's authentication instead of bypassing it.
 *
 * The archive is built ON DEMAND into a temporary file, streamed, and removed.
 * Nothing is stored: the expanded folder is the only copy of a build on disk,
 * so a download can never be stale against the build it claims to be.
 *
 * Everything the old JSON envelope returned (manifest, sizes, file listing) is
 * covered by getBuild, so nothing was lost in the rewrite.
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/ZipUtilities.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/** Prefix for the on-demand archives, so the sweep below can recognise its own. */
const QS_BUILD_DOWNLOAD_TMP_PREFIX = '.qs-download-';

/**
 * Remove archives a previous download left behind.
 *
 * The happy path unlinks its own file and a shutdown hook covers an aborted
 * transfer, but neither survives the process being killed outright. Sweeping
 * on entry means a leftover costs one download's delay rather than living in
 * the project's quota forever. Only files older than the grace period go, so a
 * concurrent download in progress is never pulled out from under itself.
 */
function qs_build_sweep_stale_downloads(int $graceSeconds = 900): void
{
    $root = qs_build_root();
    if (!is_dir($root)) {
        return;
    }
    foreach ((array) @scandir($root) as $entry) {
        if (!is_string($entry) || strpos($entry, QS_BUILD_DOWNLOAD_TMP_PREFIX) !== 0) {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path) && (time() - (int) @filemtime($path)) > $graceSeconds) {
            @unlink($path);
        }
    }
}

$buildName = qs_build_current();

if ($buildName === null) {
    ApiResponse::create(404, 'build.not_found')
        ->withMessage('This project has no build to download')
        ->withData([
            'exists' => false,
            'hint'   => 'Run build to create one.'
        ])
        ->send();
}

// An incomplete build is refused rather than shipped. A partial carries no
// build_manifest.json, would not deploy, and handing one to the user as a zip
// is how a broken deliverable gets mistaken for a good one.
if (!qs_build_is_complete($buildName)) {
    ApiResponse::create(409, 'build.incomplete')
        ->withMessage('This build is incomplete and cannot be downloaded')
        ->withData([
            'build' => $buildName,
            'hint'  => 'It carries no build_manifest.json, so it did not finish. Remove it with deleteBuild and run build again.'
        ])
        ->send();
}

$buildFolder = qs_build_path($buildName);

qs_build_sweep_stale_downloads();

// The temporary archive lives in qs_build/ — the same volume as its source (no
// cross-device copy), outside public/ (no URL reaches it), and inside the
// project's own space rather than a shared system temp directory.
$tmpZip = qs_build_root() . DIRECTORY_SEPARATOR
        . QS_BUILD_DOWNLOAD_TMP_PREFIX . bin2hex(random_bytes(8)) . '.zip';

// Guarantees removal even if the client aborts mid-transfer and the script dies
// between here and the unlink below.
register_shutdown_function(static function () use ($tmpZip) {
    if (is_file($tmpZip)) {
        @unlink($tmpZip);
    }
});

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to create the download archive')
        ->withData(['build' => $buildName])
        ->send();
}

addDirectoryToZip($zip, $buildFolder, $buildName);

if (!$zip->close() || !is_file($tmpZip)) {
    @unlink($tmpZip);
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to finalise the download archive')
        ->withData(['build' => $buildName])
        ->send();
}

// The name comes from a directory listing, not from request input, so `build`'s
// own charset validation is not in force on it — a directory created out of band
// could carry a quote or a newline and reach Content-Disposition. Reduce it to
// the charset `build` accepts and fall back to a fixed name if nothing survives.
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $buildName);
if ($safeName === null || $safeName === '' || $safeName[0] === '.') {
    $safeName = 'build';
}
$filename = $safeName . '.zip';

// Stream it. Same shape as downloadExport, which is the working control for
// file delivery on this surface.
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpZip);

@unlink($tmpZip);
exit;
