<?php
/**
 * JSON file writing — one function, deliberately alone in its own file.
 *
 * WHY IT IS NOT IN utilsManagement.php ANY MORE. Three files that run on every
 * served page — resolverCache, IframeSandbox and the API registry — needed
 * exactly this one function out of that file's 850 lines, and a production
 * build carries the runtime that serves its pages and nothing else. Requiring
 * the whole utility drawer to write one JSON file would have dragged the
 * authoring surface into every deployed site.
 *
 * utilsManagement.php requires this file, so every existing caller keeps
 * working and there is still exactly ONE definition of qs_json_write in the
 * tree — which is the property that matters, since the failure it guards
 * against silently truncates a document to zero bytes.
 */
/**
 * Encode $data as JSON and write it to $path — refusing to write at all when the
 * encode fails. THE one place in the tree that pairs json_encode with a write.
 *
 * Why this exists (beta.10 C13 F-C13-12): json_encode() returns FALSE on malformed
 * UTF-8 (and on depth > 512), and file_put_contents($path, false) writes the empty
 * string and returns int(0). Since 0 !== false, every `if (file_put_contents(...)
 * === false)` guard in the tree PASSES — so the command answers 200 while the
 * document on disk has been truncated to zero bytes. Malformed UTF-8 reaches the
 * writers through the query string (TrimParametersManagement merges $_GET raw, and
 * PHP does not UTF-8-validate query bytes; a JSON body cannot carry it because
 * json_decode refuses malformed UTF-8).
 *
 * The contract, in order:
 *   1. encode; on failure log and return false WITHOUT touching $path — the
 *      existing file is left byte-for-byte unchanged, which is the whole point
 *   2. otherwise write, and report whether the write itself succeeded
 *
 * SessionManagement and AuthManagement already did this by hand; the lesson was
 * learned in three places and never generalised, which is why it is one helper
 * rather than 55 local patches.
 *
 * @param string $path      target file
 * @param mixed  $data      value to encode
 * @param int    $jsonFlags json_encode flags (pass the site's existing flags verbatim)
 * @param int    $fileFlags file_put_contents flags (LOCK_EX where the site used it)
 * @param string $trailer   appended after the JSON — only for the handful of sites
 *                          that deliberately end the file with "\n"
 * @return bool true only when the encode succeeded AND the bytes were written
 */
if (!function_exists('qs_json_write')) {
function qs_json_write(string $path, $data, int $jsonFlags = 0, int $fileFlags = 0, string $trailer = ''): bool
{
    $json = json_encode($data, $jsonFlags);
    if ($json === false) {
        error_log('qs_json_write: refusing to write ' . $path
            . ' — json_encode failed (' . json_last_error_msg() . '); file left unchanged');
        return false;
    }
    // Warnings are suppressed and reported through error_log instead: a raw
    // file_put_contents warning carries an absolute path and, with display_errors
    // on, prints it into the response body (the F9 class C12 closed elsewhere).
    $bytes = @file_put_contents($path, $json . $trailer, $fileFlags);
    if ($bytes === false) {
        error_log('qs_json_write: write failed for ' . $path);
        return false;
    }
    return true;
}
}
