<?php

// qs_format_size lives in utilsManagement.php, which is where every shared
// helper lives — but utilsManagement's own body requires SECURE_FOLDER_PATH, so
// requiring it unconditionally makes THIS file unloadable outside a booted
// engine. It has to be loadable: qs_nginx_client_max_body_size() below is
// called by the nginx config generator, and README documents a standalone
// `php -r` invocation of that generator with no constants defined at all.
//
// Conditional, therefore, and NOT lazy-inside-the-functions: every engine
// caller has the constant, so they load exactly what they always loaded and
// nothing about their behaviour changes. Only the constant-less nginx path
// takes the other branch, and it touches nothing that needs the utilities.
if (defined('SECURE_FOLDER_PATH')) {
    require_once __DIR__ . '/utilsManagement.php'; // qs_format_size
}

/**
 * Upload limits — what this SERVER accepts, and what QuickSite accepts on top.
 *
 * Three different ceilings decide whether an upload survives, and they fail in
 * three different places:
 *
 *   1. `post_max_size`        — the whole request body. Exceeded, PHP throws the
 *                               body away BEFORE the command runs: `$_POST` and
 *                               `$_FILES` are both empty and nothing in the
 *                               request says why. The command then reports
 *                               whatever a request with no file looks like to
 *                               it, which is never the truth.
 *   2. `upload_max_filesize`  — one file inside a body that did fit. PHP reports
 *                               this properly, as `UPLOAD_ERR_INI_SIZE`.
 *   3. QuickSite's own per-category cap — applied after the file has arrived.
 *
 * Only (2) and (3) can be answered from inside the command. (1) has to be
 * detected from `CONTENT_LENGTH`, because that header is the only part of an
 * over-sized request that still reaches PHP.
 *
 * ⚠ **The limits are READ FROM PHP, never hard-coded.** They differ per server,
 * and they differ per DIRECTORY — both are `PHP_INI_PERDIR`, so a `.htaccess` or
 * a pool config can change them for one path and not another. A number written
 * into the source would be wrong on somebody's install and, worse, would be
 * wrong while looking authoritative.
 *
 * ⚠ **`php://input` is NOT empty when `post_max_size` is exceeded** (measured on
 * this stack: a 3 MB body against a 1 MB limit reads back all 3 MB). The widely
 * repeated "empty POST + empty input" test is therefore wrong here, and a check
 * built on it would never fire. The test used below compares `CONTENT_LENGTH`
 * against the limit, which is what PHP itself compares.
 *
 * The per-category caps are QuickSite's own policy and are deliberately still
 * constants here — making them deployer-configurable belongs with the rest of
 * the resource-limit work, not with this file's job of telling the truth about
 * what the server already enforces.
 */

/**
 * Parse a PHP shorthand byte value ("50M", "8M", "1G", "512K", "0") to bytes.
 *
 * Returns 0 for "no limit" — which is what PHP means by both `0` and a negative
 * value on these two directives. Callers must treat 0 as "unbounded", never as
 * "nothing is allowed".
 */
function qs_ini_bytes(?string $shorthand): int
{
    $raw = trim((string) $shorthand);
    if ($raw === '') {
        return 0;
    }

    $unit   = strtolower(substr($raw, -1));
    $number = (float) $raw; // leading numeric prefix; a trailing unit letter is ignored

    switch ($unit) {
        case 'g': $number *= 1024 * 1024 * 1024; break;
        case 'm': $number *= 1024 * 1024;        break;
        case 'k': $number *= 1024;               break;
    }

    $bytes = (int) $number;
    return $bytes > 0 ? $bytes : 0;
}

/**
 * A multipart body is bigger than the file it carries: a boundary, a
 * `Content-Disposition` line and a `Content-Type` line wrap every field.
 *
 * Measured on this install, a single file field costs ~200 bytes. The budget
 * below is deliberately generous because it is subtracted from the advertised
 * limit — an over-estimate means the number shown in the UI is one a user can
 * actually reach, and an under-estimate means it is not.
 */
const QS_MULTIPART_OVERHEAD_BUDGET = 4096;

/**
 * QuickSite's own per-category ceilings, in bytes.
 *
 * Single source of truth: `uploadAsset` enforces these, and the admin panel
 * shows them. They used to exist only inside the command, so the UI could not
 * name a limit it was about to enforce.
 */
function qs_asset_size_limits(): array
{
    return [
        'images' => 5 * 1024 * 1024,
        'font'   => 2 * 1024 * 1024,
        'audio'  => 10 * 1024 * 1024,
        'videos' => 50 * 1024 * 1024,
    ];
}

/**
 * The effective limits for the CURRENT request path.
 *
 * `effective` is the number that actually decides an upload: the smallest of
 * QuickSite's category cap, `upload_max_filesize`, and what is left of
 * `post_max_size` once the multipart wrapper is paid for. It is the only figure
 * worth showing a user, because any of the three can be the binding one — on a
 * server where `post_max_size` equals `upload_max_filesize` (a common default,
 * and this install's own configuration) the binding constraint is `post_max_size`
 * for EVERY file, and `upload_max_filesize` can never be reached at all.
 *
 * `transport_max` is the same figure with QuickSite's policy taken OUT: the
 * largest file this server will carry at all. It is what an upload with no
 * asset category is bounded by — an import archive, for instance, which is not
 * an asset and has no per-category cap.
 *
 * @return array{
 *   post_max_size:int, upload_max_filesize:int, file_uploads:bool,
 *   category:array<string,int>, effective:array<string,int>,
 *   effective_max:int, transport_max:int
 * }
 */
function qs_upload_limits(): array
{
    $postMax   = qs_ini_bytes(ini_get('post_max_size'));
    $uploadMax = qs_ini_bytes(ini_get('upload_max_filesize'));

    // 0 means unbounded on both directives. Fold that into a comparison-friendly
    // shape so the min() below does not treat "no limit" as "zero bytes".
    $bodyRoom = $postMax > 0 ? max(0, $postMax - QS_MULTIPART_OVERHEAD_BUDGET) : PHP_INT_MAX;
    $fileRoom = $uploadMax > 0 ? $uploadMax : PHP_INT_MAX;

    $categories = qs_asset_size_limits();
    $effective  = [];
    foreach ($categories as $name => $cap) {
        $effective[$name] = (int) min($cap, $fileRoom, $bodyRoom);
    }

    return [
        'post_max_size'       => $postMax,
        'upload_max_filesize' => $uploadMax,
        'file_uploads'        => (bool) ini_get('file_uploads'),
        'category'            => $categories,
        'effective'           => $effective,
        'effective_max'       => $effective === [] ? 0 : max($effective),
        'transport_max'       => (int) min($fileRoom, $bodyRoom),
    ];
}

/**
 * The `client_max_body_size` an nginx front end needs, in nginx's own notation
 * ("51m", or "0" for unlimited).
 *
 * ⚠ nginx's DEFAULT IS 1 MB — smaller than what a typical PHP install accepts,
 * so an nginx deployment refuses uploads QuickSite advertises as fine, and does
 * it in the one way that hides the reason: nginx answers **413 with its own
 * HTML error page before PHP runs at all**, so no QuickSite code executes and
 * nothing in the response is JSON. Apache's `LimitRequestBody` defaults to
 * unlimited, which is why an Apache install cannot reproduce it.
 *
 * The value is DERIVED, never written down. `post_max_size` is the directive
 * that bounds a whole request body — the same thing `client_max_body_size`
 * bounds — and it is `PHP_INI_PERDIR`, so it differs per server and can differ
 * per directory on one server. A number in the source would be wrong on
 * somebody's install while looking authoritative.
 *
 * It is deliberately LARGER than PHP's limit, not equal to it. The goal is that
 * PHP is always the component that refuses an oversized upload, because PHP's
 * refusal is a JSON answer naming the real limit and nginx's is an HTML page
 * naming nothing. Equal values would leave nginx refusing the first byte over.
 * The margin is one whole megabyte, and the result is rounded up to a whole
 * megabyte so the emitted directive reads like something a human wrote.
 *
 * ⚠ Read from the SAPI that is actually serving. On a stack whose CLI php.ini
 * differs from the web server's — WAMP ships exactly that — asking the CLI
 * produces a number the web server never enforces.
 */
function qs_nginx_client_max_body_size(): string
{
    $postMax = qs_ini_bytes(ini_get('post_max_size'));
    if ($postMax <= 0) {
        return '0'; // PHP is unbounded; 0 means the same thing to nginx
    }

    $mb = (int) ceil($postMax / (1024 * 1024)) + 1;
    return $mb . 'm';
}

/**
 * Did PHP discard this request's body for exceeding `post_max_size`?
 *
 * Returns null when it did not — including for every request that is simply not
 * a POST, and every POST that parsed normally.
 *
 * The condition is deliberately narrow in the safe direction: it additionally
 * requires `$_POST` and `$_FILES` to be empty, so a request that PHP *did*
 * parse is never refused by this check even if a proxy misreported
 * `CONTENT_LENGTH`. The cost of a false negative is the old confusing message;
 * the cost of a false positive would be refusing a working upload.
 *
 * @return array{content_length:int, post_max_size:int}|null
 */
function qs_post_body_discarded(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return null;
    }

    $postMax = qs_ini_bytes(ini_get('post_max_size'));
    if ($postMax <= 0) {
        return null; // unbounded — PHP discards nothing
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= $postMax) {
        return null;
    }

    if (!empty($_POST) || !empty($_FILES)) {
        return null; // PHP parsed it after all; do not second-guess that
    }

    return ['content_length' => $contentLength, 'post_max_size' => $postMax];
}

/**
 * The sentence a caller gets when the body was discarded.
 *
 * Names the actual number rather than the directive: "post_max_size" is a fact
 * about the server's configuration file, not an answer to "how big may my file
 * be", and a user who has never opened a php.ini cannot act on it.
 */
function qs_post_too_large_message(array $breach): string
{
    $limits = qs_upload_limits();

    return 'The request is larger than this server accepts. It was '
        . qs_format_size($breach['content_length'])
        . ' and the server accepts at most '
        . qs_format_size($breach['post_max_size'])
        . ' per request, so PHP discarded it before QuickSite could read it. '
        . 'The largest file this server will carry is '
        . qs_format_size($limits['transport_max'])
        . '. Raising it means raising post_max_size (and upload_max_filesize) in the server\'s PHP configuration.';
}

/**
 * The structured half of that answer, for a client that wants the numbers.
 */
function qs_post_too_large_data(array $breach): array
{
    $limits = qs_upload_limits();

    return [
        'content_length'          => $breach['content_length'],
        'content_length_human'    => qs_format_size($breach['content_length']),
        'post_max_size'           => $limits['post_max_size'],
        'post_max_size_human'     => qs_format_size($limits['post_max_size']),
        'upload_max_filesize'     => $limits['upload_max_filesize'],
        'upload_max_filesize_human' => qs_format_size($limits['upload_max_filesize']),
        'max_file_size'           => $limits['transport_max'],
        'max_file_size_human'     => qs_format_size($limits['transport_max']),
    ];
}
