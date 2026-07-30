<?php

/**
 * File Policy — what may ENTER a project from an archive, and what may be
 * PUBLISHED into a web-served directory.
 *
 * These are two different questions that used to have one answer. An archive
 * entry was filtered against a small blocklist of executable extensions, and
 * anything that survived was later copied verbatim into a deploy root by an
 * ordinary build. So "this file exists in a project" and "this file is served
 * by a web server" were the same decision.
 *
 * Both gates are ALLOWLISTS. A blocklist has to enumerate every dangerous
 * spelling — including case variants on a case-insensitive filesystem, and
 * every extension a given web server happens to map to an interpreter — and it
 * is wrong the moment a server is configured with one the list never heard of.
 *
 * The import gate additionally verifies that an entry's CONTENT matches what
 * its name claims, so a name cannot lie about what a file is.
 *
 * Defaults live here. A deployer may override them in
 * `secure/management/config/import-policy.php` (see the shipped `.example`).
 * A missing or malformed override file leaves the defaults in force — the
 * policy never fails open.
 */

/**
 * The effective policy: built-in defaults merged with the optional override.
 *
 * @return array{import_extensions:string[], publish_extensions:string[], limits:array<string,int>}
 */
function qs_file_policy(): array
{
    static $policy = null;
    if ($policy !== null) {
        return $policy;
    }

    $defaults = [
        // What an archive may carry into a project. Derived from what a
        // legitimate export can actually contain (structural JSON, styles,
        // scripts, and public assets) plus the asset types the engine already
        // accepts at upload and serves on surface B.
        'import_extensions' => [
            // structural + text
            'json', 'css', 'js', 'mjs', 'map', 'txt', 'xml', 'csv', 'pdf',
            // images
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'avif', 'bmp',
            // fonts
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            // media
            'mp4', 'webm', 'ogv', 'ogg', 'mp3', 'wav',
        ],
        // What a build may copy into a web-served directory. Same taxonomy:
        // a file the engine would refuse to import has no business being
        // published either.
        'publish_extensions' => [
            'json', 'css', 'js', 'mjs', 'map', 'txt', 'xml', 'csv', 'pdf',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'avif', 'bmp',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'mp4', 'webm', 'ogv', 'ogg', 'mp3', 'wav',
        ],
        // Archive resource limits. `getFromIndex()` reads an entry fully into
        // memory, so an unbounded archive is an unbounded allocation.
        'limits' => [
            'max_entries'     => 10000,
            'max_total_bytes' => 200 * 1024 * 1024,
            'max_entry_bytes' => 50 * 1024 * 1024,
            'max_ratio'       => 100,
        ],
    ];

    $policy = $defaults;

    $override = SECURE_FOLDER_PATH . '/management/config/import-policy.php';
    if (is_file($override)) {
        // A syntax error in the override is a ParseError, which `@` cannot
        // suppress — without this catch a deployer's typo took every import
        // down with a 500 instead of falling back. Catch it, keep the safe
        // defaults, and make the mistake discoverable in the error log.
        $configured = null;
        try {
            $configured = require $override;
        } catch (Throwable $e) {
            error_log('filePolicy: ignoring malformed import-policy.php (' . $e->getMessage() . '); built-in defaults remain in force');
        }
        if (is_array($configured)) {
            foreach (['import_extensions', 'publish_extensions'] as $key) {
                if (isset($configured[$key]) && is_array($configured[$key])) {
                    $clean = [];
                    foreach ($configured[$key] as $ext) {
                        // An override REPLACES the list, so a deployer can
                        // narrow as well as widen. Entries are normalised the
                        // same way the gates normalise a filename's extension.
                        if (is_string($ext) && $ext !== '') {
                            $clean[] = strtolower(ltrim($ext, '.'));
                        }
                    }
                    if ($clean !== []) {
                        $policy[$key] = $clean;
                    }
                }
            }
            if (isset($configured['limits']) && is_array($configured['limits'])) {
                foreach ($policy['limits'] as $limitKey => $limitDefault) {
                    $value = $configured['limits'][$limitKey] ?? null;
                    if (is_int($value) && $value > 0) {
                        $policy['limits'][$limitKey] = $value;
                    }
                }
            }
        }
    }

    return $policy;
}

/** Archive resource limits (entry count, total/per-entry size, ratio). */
function qs_archive_limits(): array
{
    return qs_file_policy()['limits'];
}

/**
 * Normalise a path's extension the way both gates compare it.
 *
 * `.htaccess` yields `htaccess`, `.HTACCESS` also yields `htaccess` — the
 * lowercasing is what makes a case-insensitive filesystem stop mattering.
 * A file with no extension yields '' and is refused by both gates: it cannot
 * be proven safe and nothing a project legitimately carries needs one.
 */
function qs_policy_extension(string $path): string
{
    return strtolower(pathinfo(str_replace('\\', '/', $path), PATHINFO_EXTENSION));
}

/** May an archive entry with this path enter a project? (extension gate only) */
function qs_import_allows_extension(string $path): bool
{
    $ext = qs_policy_extension($path);
    return $ext !== '' && in_array($ext, qs_file_policy()['import_extensions'], true);
}

/** May a file with this path be copied into a web-served directory? */
function qs_publish_allows_extension(string $path): bool
{
    $ext = qs_policy_extension($path);
    return $ext !== '' && in_array($ext, qs_file_policy()['publish_extensions'], true);
}

/**
 * Magic-byte signatures, as [offset, bytes] pairs. An extension listed here
 * MUST match one of its signatures.
 *
 * Deliberately partial. Formats whose signature is unambiguous and cheap are
 * checked positively; formats where it is not (avif, mp4, mp3) fall through to
 * the generic "must not be text or PHP" rule below. That asymmetry is the safe
 * one: the check always catches text masquerading as a binary asset, and never
 * rejects a legitimate binary whose format this list does not know.
 */
function qs_policy_magic_signatures(): array
{
    return [
        'png'   => [[0, "\x89PNG\r\n\x1a\n"]],
        'gif'   => [[0, 'GIF87a'], [0, 'GIF89a']],
        'jpg'   => [[0, "\xff\xd8\xff"]],
        'jpeg'  => [[0, "\xff\xd8\xff"]],
        'bmp'   => [[0, 'BM']],
        'pdf'   => [[0, '%PDF-']],
        'ico'   => [[0, "\x00\x00\x01\x00"], [0, "\x00\x00\x02\x00"]],
        'webp'  => [[0, 'RIFF'], [8, 'WEBP']],
        'wav'   => [[0, 'RIFF'], [8, 'WAVE']],
        'woff'  => [[0, 'wOFF']],
        'woff2' => [[0, 'wOF2']],
        'ttf'   => [[0, "\x00\x01\x00\x00"], [0, 'true'], [0, 'ttcf']],
        'otf'   => [[0, 'OTTO'], [0, "\x00\x01\x00\x00"]],
        'eot'   => [[34, "\x4c\x50"]],
        'ogg'   => [[0, 'OggS']],
        'ogv'   => [[0, 'OggS']],
        'webm'  => [[0, "\x1a\x45\xdf\xa3"]],
    ];
}

/** Extensions treated as text, where the meaningful check is "is not code". */
function qs_policy_text_extensions(): array
{
    return ['json', 'css', 'js', 'mjs', 'map', 'txt', 'xml', 'csv', 'svg'];
}

/**
 * Does this content open a PHP block?
 *
 * `finfo` alone is not enough and this was measured, not assumed: a file whose
 * whole body is `<?php echo 1; ?>` is reported as `text/x-php`, but the short
 * forms `<? echo 1; ?>` and `<?= 1 ?>` are both reported as plain `text/plain`.
 * A server with `short_open_tag` enabled executes those just the same.
 *
 * `<?xml …` is deliberately NOT matched — an XML declaration is legitimate in
 * .xml and .svg, and 'x' is neither `php`, `=`, nor whitespace.
 */
function qs_policy_has_php_open_tag(string $content): bool
{
    return (bool) preg_match('/<\?(?:php\b|=|\s|$)/i', $content);
}

/**
 * Verify that an entry's CONTENT matches what its name claims.
 *
 * The extension gate is assumed already passed. SVG content is returned
 * SANITISED (via the sanitiser the upload path already uses), so the caller
 * must write back the returned content rather than the original bytes.
 *
 * @return array{ok:bool, reason:string, content:string}
 */
function qs_import_validate_content(string $path, string $content): array
{
    $ext  = qs_policy_extension($path);
    $deny = static fn(string $why): array => ['ok' => false, 'reason' => $why, 'content' => ''];

    // An empty file is inert and legitimate (a placeholder .css, a 0-byte .txt).
    if ($content === '') {
        return ['ok' => true, 'reason' => '', 'content' => ''];
    }

    // 1. Magic bytes, where the format has an unambiguous one.
    $signatures = qs_policy_magic_signatures();
    if (isset($signatures[$ext])) {
        foreach ($signatures[$ext] as [$offset, $bytes]) {
            if (substr($content, $offset, strlen($bytes)) !== $bytes) {
                return $deny("content is not a valid .{$ext} (signature mismatch)");
            }
        }
        return ['ok' => true, 'reason' => '', 'content' => $content];
    }

    $isText = in_array($ext, qs_policy_text_extensions(), true);

    // 2. Nothing may open a PHP block — binary or text.
    if (qs_policy_has_php_open_tag($content)) {
        return $deny("content opens a PHP block but is named .{$ext}");
    }

    // 3. Detected type must not be source code, for either class.
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = (string) finfo_buffer($finfo, $content);
            finfo_close($finfo);
            if ($detected === 'text/x-php' || $detected === 'application/x-php') {
                return $deny("content is detected as PHP source but is named .{$ext}");
            }
            // A binary slot (no signature above, e.g. .avif/.mp4/.mp3) must not
            // hold text at all — that is the shape a disguised payload takes.
            if (!$isText && strncmp($detected, 'text/', 5) === 0) {
                return $deny("content is text but is named .{$ext}");
            }
        }
    }

    // 4. Structured text must actually parse as what it claims.
    if ($ext === 'json') {
        json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $deny('content is not valid JSON but is named .json');
        }
    }

    // 5. SVG is text that can carry script — reuse the sanitiser the upload
    //    path already applies rather than inventing a second rule for it.
    if ($ext === 'svg') {
        require_once SECURE_FOLDER_PATH . '/src/classes/SvgSanitizer.php';
        $clean = SvgSanitizer::sanitize($content);
        if ($clean === false) {
            return $deny('SVG could not be sanitised (malformed XML)');
        }
        return ['ok' => true, 'reason' => '', 'content' => $clean];
    }

    return ['ok' => true, 'reason' => '', 'content' => $content];
}

/**
 * Recursively copy a directory into a web-served location, skipping anything
 * the publish allowlist does not permit.
 *
 * Separate from FileSystem.php's generic `copyDirectory()` on purpose. That
 * function means "copy a directory" and should keep meaning exactly that;
 * publishing policy belongs to the step that publishes. Keeping them apart
 * also avoids `deployBuild.php`, which declares its own global function of the
 * same name — requiring FileSystem.php there would be a fatal redeclare.
 *
 * @param string   $source  Directory to copy from
 * @param string   $dest    Directory to copy into
 * @param string[] $skipped Collects the relative paths that were refused
 * @param string   $prefix  Relative path prefix, for reporting
 * @return bool True on success; false if a directory or file copy failed
 */
function qs_copy_publishable_directory(string $source, string $dest, array &$skipped, string $prefix = ''): bool
{
    if (!is_dir($source)) {
        return false;
    }
    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        return false;
    }

    foreach (scandir($source) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . '/' . $item;
        $destPath   = $dest . '/' . $item;
        $relative   = $prefix === '' ? $item : $prefix . '/' . $item;

        if (is_dir($sourcePath)) {
            if (!qs_copy_publishable_directory($sourcePath, $destPath, $skipped, $relative)) {
                return false;
            }
            continue;
        }

        if (!qs_publish_allows_extension($item)) {
            $skipped[] = $relative;
            continue;
        }

        if (!copy($sourcePath, $destPath)) {
            return false;
        }
    }

    return true;
}
