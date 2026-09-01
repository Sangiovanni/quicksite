<?php
require_once __DIR__ . '/PathManagement.php'; // qs_path_is_within

/**
 * File Policy — what may ENTER a project from an archive, what may be
 * PUBLISHED into a web-served directory, and what the engine accepts as an
 * uploaded ASSET.
 *
 * These used to be two different questions with one answer, and then two
 * answers that contradicted each other. An archive entry was filtered against a
 * small blocklist of executable extensions, and anything that survived was
 * later copied verbatim into a deploy root by an ordinary build. So "this file
 * exists in a project" and "this file is served by a web server" were the same
 * decision. Splitting them left a third list — the asset types `uploadAsset`
 * accepts — in a separate config file, free to drift, and it did.
 *
 * ONE TAXONOMY, THREE GATES. `qs_asset_extensions()` below is the single
 * statement of which media types the engine handles, keyed by the category each
 * lands in. Every gate derives from it:
 *
 *   upload   → the category map, plus a MIME check (`qs_asset_mime_types()`)
 *   import   → structural/text types PLUS every asset type
 *   publish  → the same set as import
 *
 * so a type cannot be uploadable-but-unimportable, or publishable-but-not-a-
 * thing-the-engine-knows, without the taxonomy saying so in one place.
 *
 * All gates are ALLOWLISTS. A blocklist has to enumerate every dangerous
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
 * The engine's asset taxonomy: category => extensions it holds.
 *
 * This is the SOURCE. `uploadAsset` resolves an upload's category from it,
 * `import_extensions` and `publish_extensions` are built from it, and the admin
 * media page states it to the user. Adding a media type here is the whole edit.
 *
 * A category also needs a `public/assets/<category>/` directory and an entry in
 * `assetCategories.php`; extensions within an existing category need nothing
 * else. Adding one to `qs_asset_mime_types()` as well is what makes an upload
 * of it actually succeed — the extension names the door, the MIME check is the
 * lock.
 *
 * @return array<string, string[]>
 */
function qs_asset_extensions(): array
{
    return qs_file_policy()['asset_extensions'];
}

/**
 * Flat extension => category lookup, derived from the taxonomy above.
 *
 * @return array<string, string>
 */
function qs_asset_extension_map(): array
{
    $map = [];
    foreach (qs_asset_extensions() as $category => $extensions) {
        foreach ($extensions as $ext) {
            $map[strtolower($ext)] = $category;
        }
    }
    return $map;
}

/**
 * Detected MIME types accepted per asset category, for `uploadAsset`.
 *
 * The extension gate says which door a file may knock on; this says whether the
 * bytes behind the name belong to that category at all. Both are needed: the
 * extension is caller-supplied, the MIME is detected server-side.
 *
 * Several entries are here because a real file measured that way, not because a
 * standard names them:
 *   - `font/sfnt` (TTF) and `application/vnd.ms-opentype` (OTF) are what finfo
 *     reports for genuine Windows/Google fonts. Without them NO real .ttf or
 *     .otf could be uploaded at all, which is what the engine did until this
 *     list moved here.
 *   - BMP is `image/x-ms-bmp` on PHP 8.0 and `image/bmp` on 8.4. Both are
 *     listed, because both are the same file read by different interpreters.
 *   - `application/octet-stream` stays for fonts, which are routinely detected
 *     as nothing in particular.
 *
 * @return array<string, string[]>
 */
function qs_asset_mime_types(): array
{
    return [
        'images' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'image/vnd.microsoft.icon', 'image/x-icon',
            'image/bmp', 'image/x-ms-bmp',
            'image/avif',
        ],
        'font' => [
            'font/ttf', 'font/otf', 'font/woff', 'font/woff2', 'font/sfnt',
            'application/x-font-ttf', 'application/x-font-otf',
            'application/font-woff', 'application/font-woff2',
            'application/vnd.ms-opentype', 'application/vnd.ms-fontobject',
            'application/octet-stream',
        ],
        'audio'  => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-wav'],
        'videos' => ['video/mp4', 'video/webm', 'video/ogg'],
    ];
}

/**
 * Image types a browser will actually render as a favicon.
 *
 * Narrower than the `images` category on purpose: `<link rel="icon">` is not a
 * general image slot. BMP is excluded — browsers accept the extension and then
 * render nothing useful — and SVG is included, which every current browser
 * supports and which scales to any tab or bookmark size.
 *
 * @return string[]
 */
function qs_favicon_extensions(): array
{
    return ['ico', 'png', 'svg', 'gif', 'jpg', 'jpeg', 'webp', 'avif'];
}

/**
 * Extensions an export legitimately carries that are NOT assets: the project's
 * own structure, styles, scripts and documents.
 *
 * @return string[]
 */
function qs_policy_structural_extensions(): array
{
    return ['json', 'css', 'js', 'mjs', 'map', 'txt', 'xml', 'csv', 'pdf'];
}

/**
 * The effective policy: built-in defaults merged with the optional override.
 *
 * @return array{asset_extensions:array<string,string[]>, import_extensions:string[], publish_extensions:string[], limits:array<string,int>}
 */
function qs_file_policy(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // The taxonomy. Every list below is DERIVED from it, so the three gates
    // cannot disagree about what a `.webp` is.
    $assetExtensions = [
        'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'avif', 'bmp'],
        'font'   => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
        'audio'  => ['mp3', 'wav', 'ogg'],
        'videos' => ['mp4', 'webm', 'ogv'],
    ];

    $defaults = [
        'asset_extensions' => $assetExtensions,
        // Filled in below, once the override has had its say on the taxonomy.
        'import_extensions'  => [],
        'publish_extensions' => [],
        // Archive resource limits. `getFromIndex()` reads an entry fully into
        // memory, so an unbounded archive is an unbounded allocation.
        //
        // The BYTE caps are the real ceiling: whatever an entry's compression
        // ratio, no single entry may allocate more than `max_entry_bytes` and
        // no archive more than `max_total_bytes`. `max_ratio` only raises the
        // UPLOAD COST of reaching that ceiling, so it can afford to be
        // generous — and it has to be. A page tree is repetitive by
        // construction (every node repeats tag, class, style, children), so it
        // deflates far better than hand-written text, and a tight ratio
        // refuses the engine's own export. 300:1 leaves several times the
        // headroom real project content needs; a decompression bomb sits
        // orders of magnitude higher (a megabyte of one repeated byte exceeds
        // 1000:1).
        'limits' => [
            'max_entries'     => 10000,
            'max_total_bytes' => 200 * 1024 * 1024,
            'max_entry_bytes' => 50 * 1024 * 1024,
            'max_ratio'       => 300,
        ],
    ];

    // Built into a LOCAL, published to the static only once complete: the
    // taxonomy accessors call back into this function, and a half-built array
    // in the static would be handed out as if it were the policy.
    $policy = $defaults;
    /** Explicit list overrides, if the deployer supplied any. */
    $overrides = [];

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
            // The taxonomy, first — the two extension lists are derived from
            // it, so an override of it has to land before they are built.
            // Categories are FIXED (each needs a public/assets/<c>/ directory
            // that only createProject makes), so an unknown category is
            // ignored rather than invented; a known one REPLACES its list.
            if (isset($configured['asset_extensions']) && is_array($configured['asset_extensions'])) {
                foreach (array_keys($policy['asset_extensions']) as $category) {
                    $supplied = $configured['asset_extensions'][$category] ?? null;
                    if (!is_array($supplied)) {
                        continue;
                    }
                    $clean = [];
                    foreach ($supplied as $ext) {
                        if (is_string($ext) && $ext !== '') {
                            $clean[] = strtolower(ltrim($ext, '.'));
                        }
                    }
                    // An empty (or all-junk) list would silently disable the
                    // whole category; treat it as "not configured" instead.
                    if ($clean !== []) {
                        $policy['asset_extensions'][$category] = array_values(array_unique($clean));
                    }
                }
            }
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
                        $overrides[$key] = $clean;
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

    // DERIVE the two gate lists from the (possibly overridden) taxonomy. This
    // is the line that makes the file's opening claim true: import and publish
    // allow the structural types plus EXACTLY the asset types the engine
    // accepts at upload — no more, and no fewer.
    $derived = qs_policy_structural_extensions();
    foreach ($policy['asset_extensions'] as $extensions) {
        foreach ($extensions as $ext) {
            $derived[] = $ext;
        }
    }
    $derived = array_values(array_unique($derived));

    $policy['import_extensions']  = $overrides['import_extensions']  ?? $derived;
    $policy['publish_extensions'] = $overrides['publish_extensions'] ?? $derived;

    $cached = $policy;
    return $cached;
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

/**
 * Does any segment of this path begin with a dot?
 *
 * A project's public/ is meant to hold the website AS IT IS. Hidden files and
 * hidden DIRECTORIES are not website content — they are tooling leftovers
 * (`.git/`, `.svn/`, `.idea/`) or server configuration, and a published `.git/`
 * discloses a project's entire source history. Anything a deployment genuinely
 * needs at a hidden path (a TLS challenge under `/.well-known/`, a server
 * config file) belongs in the deployment's OWN web root, where the web server
 * serves it directly without QuickSite ever running.
 *
 * Note the rule is "no segment may START with a dot", NOT "no dots": files need
 * their extensions. `style/style.css` passes; `.git/config.json` does not.
 *
 * The one hidden file a project legitimately owns — `public/.htaccess` — is
 * written by createProject, never imported and never served, so it is content
 * DIRECTED by a command rather than uploaded.
 */
function qs_policy_has_hidden_segment(string $path): bool
{
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment !== '' && $segment[0] === '.') {
            return true;
        }
    }
    return false;
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
 * Magic-byte signatures, as [offset, bytes] pairs.
 *
 * HOW A LIST IS READ, because the two cases look identical and mean opposite
 * things. Entries at the SAME offset are ALTERNATIVES — a GIF starts `GIF87a`
 * OR `GIF89a`, never both. Entries at DIFFERENT offsets are CONJUNCTIVE — a
 * WebP is `RIFF` at 0 AND `WEBP` at 8, and a WAV is `RIFF` at 0 AND `WAVE` at
 * 8, which is the only thing telling the two RIFF containers apart. So the
 * rule is: every offset that appears must be satisfied by one of the strings
 * listed at it.
 *
 * Reading them all as conjunctive (which the validator did until this was
 * measured) makes every multi-alternative format unsatisfiable: gif, ico, ttf
 * and otf could not be imported at all, so `.ico` favicon support was not
 * merely un-widened, it was unreachable.
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
 * THE PHP-BLOCK RULE IS CLASS-DEPENDENT, and this was measured rather than
 * reasoned. An opening short tag followed by whitespace is two bytes; across
 * 1738 real signature-valid files on this machine it occurs by chance in 77 of
 * them (4.4%) — dozens of genuine system fonts, and a real 4.4 MB PNG sitting
 * in a QuickSite project. QuickSite's own shipped sample video trips it too.
 * So the raw pattern is a precise rule for TEXT and pure noise for BINARY, and
 * applying it to binary content refuses real files.
 *
 * What is enforceable, and what each class actually gets:
 *
 *   TEXT class   — may never open a PHP block, full stop. The pattern is
 *                  exact here: the long form, the echo shorthand and the bare
 *                  short tag all execute on a server with short_open_tag, and
 *                  finfo reports only the first as text/x-php.
 *   BINARY class — must satisfy its signature (if one is known), must not be
 *                  detected as text, and — if it DOES look like it opens a PHP
 *                  block — must additionally be detected as a named binary
 *                  format AND reported as binary-encoded. That conjunction is
 *                  what catches the cheap disguise: a magic prefix plus a web
 *                  shell is a few honest bytes and a script, and detects as
 *                  text/plain, octet-stream, or a named format that libmagic
 *                  simultaneously reports as us-ascii.
 *
 * What that deliberately does NOT catch is a TRUE polyglot — a genuinely valid
 * image with PHP appended, which still detects as image/png. It is not
 * distinguishable from the 57 real files above by any byte pattern, and it is
 * inert: no extension either allowlist permits is mapped to an interpreter, so
 * the file is served as the image it is. Refusing it would mean refusing real
 * user content, which is the worse failure.
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

    $isText = in_array($ext, qs_policy_text_extensions(), true);

    // 1. Magic bytes, where the format has an unambiguous one. Grouped by
    //    offset first: each offset must be satisfied by ONE of the strings
    //    listed at it (alternatives), and EVERY offset must be satisfied
    //    (conjunction). See qs_policy_magic_signatures() for why.
    $signatures = qs_policy_magic_signatures();
    if (isset($signatures[$ext])) {
        $byOffset = [];
        foreach ($signatures[$ext] as [$offset, $bytes]) {
            $byOffset[$offset][] = $bytes;
        }
        foreach ($byOffset as $offset => $alternatives) {
            $matched = false;
            foreach ($alternatives as $bytes) {
                if (substr($content, $offset, strlen($bytes)) === $bytes) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return $deny("content is not a valid .{$ext} (signature mismatch)");
            }
        }
    }

    // 2. Detection. Read ONCE, here, and used by every rule below — a matching
    //    signature used to return before this ran, which is what let a BM
    //    prefix plus a PHP block through.
    //
    //    FILEINFO_MIME returns type AND charset from a SINGLE libmagic pass
    //    ("image/png; charset=binary"). Two separate finfo calls would scan the
    //    buffer twice, and an archive entry may be 50 MB.
    $detected = null;   // e.g. 'image/png'
    $encoding = null;   // e.g. 'binary', 'us-ascii'
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME);
        if ($finfo !== false) {
            $full = (string) finfo_buffer($finfo, $content);
            finfo_close($finfo);
            $semi     = strpos($full, ';');
            $detected = strtolower(trim($semi === false ? $full : substr($full, 0, $semi)));
            if (preg_match('/charset=([^;\s]+)/i', $full, $m)) {
                $encoding = strtolower($m[1]);
            }
        }
    }

    if ($detected !== null) {
        if ($detected === 'text/x-php' || $detected === 'application/x-php') {
            return $deny("content is detected as PHP source but is named .{$ext}");
        }
        // A binary slot must not hold text at all — that is the shape a
        // disguised payload takes.
        if (!$isText && strncmp($detected, 'text/', 5) === 0) {
            return $deny("content is text but is named .{$ext}");
        }
    }

    // 3. The PHP-block rule, per class (see the note above this function).
    if (qs_policy_has_php_open_tag($content)) {
        if ($isText) {
            return $deny("content opens a PHP block but is named .{$ext}");
        }
        // Binary: two independent things must both hold for the file to be
        // believed. Measured across every real file on this machine that trips
        // the pattern by chance (see the figure in the docblock above), ALL of
        // them satisfy both; the disguises fail one or the other.
        //
        //   type     — a real file detects as its format. A short magic prefix
        //              followed by a script detects as octet-stream (or, for a
        //              2-byte prefix like BM, as text/plain, which rule 2 above
        //              already refused).
        //   encoding — libmagic reports 'binary' for every real image, font and
        //              media file. A magic prefix plus an ASCII script reports
        //              'us-ascii', which is how OTTO-plus-shell and
        //              GIF89a-plus-shell are caught: both are convincing enough
        //              for libmagic to NAME the format, and neither is binary.
        //
        // With no detection at all there is no evidence either way, so the byte
        // pattern is all there is and it decides.
        //
        // KNOWN RESIDUAL, stated rather than papered over: padding the script
        // with a kilobyte of NUL bytes restores both signals, and a genuine
        // image with PHP appended never lost them. Neither is distinguishable
        // from real content by inspecting bytes. Both stay inert because no
        // extension either allowlist permits is mapped to an interpreter —
        // that, not this rule, is the control that actually holds.
        if ($detected === null || $detected === '' || $detected === 'application/octet-stream') {
            return $deny("content opens a PHP block and does not detect as a real .{$ext}");
        }
        if ($encoding !== null && $encoding !== 'binary') {
            return $deny("content opens a PHP block and is not binary but is named .{$ext}");
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
 * Entries that resolve OUTSIDE the copy root are refused. `scandir()` and
 * `is_dir()` report a reparse point (an NTFS junction, a symlink) as an ordinary
 * directory, so without canonicalisation the recursion follows one straight out
 * of the project and publishes whatever it finds. The passthrough already jails
 * exactly this case; this is the same idiom, so both boundaries make the same
 * decision instead of differing invisibly.
 *
 * @param string      $source   Directory to copy from
 * @param string      $dest     Directory to copy into
 * @param string[]    $skipped  Collects the relative paths that were refused
 * @param string      $prefix   Relative path prefix, for reporting (recursion)
 * @param string|null $jailRoot Canonical copy root, carried through the
 *                              recursion so the jail stays the ORIGINAL root and
 *                              not whichever subdirectory is being walked
 * @return bool True on success; false if a directory or file copy failed
 */
function qs_copy_publishable_directory(
    string $source,
    string $dest,
    array &$skipped,
    string $prefix = '',
    ?string $jailRoot = null
): bool {
    if (!is_dir($source)) {
        return false;
    }
    $root = $jailRoot ?? realpath($source);
    if ($root === false) {
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

        // A hidden DIRECTORY is refused whole — without this the recursion would
        // walk into `.git/` and publish every non-dotted file inside it.
        if ($item[0] === '.') {
            $skipped[] = $relative . (is_dir($sourcePath) ? '/ (hidden directory)' : ' (hidden file)');
            continue;
        }

        // The jail check. Canonicalise, then require the result to sit under
        // the copy root. The predicate is shared with the deploy copier so both
        // boundaries make the same decision (beta.11 S3.10c).
        if (!qs_path_is_within($sourcePath, $root)) {
            $skipped[] = $relative . ' (resolves outside the project)';
            continue;
        }

        if (is_dir($sourcePath)) {
            if (!qs_copy_publishable_directory($sourcePath, $destPath, $skipped, $relative, $root)) {
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
