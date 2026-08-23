<?php
/**
 * Fatal-error hygiene for the request dispatchers (beta.10 C12, C13).
 *
 * A PHP fatal happens outside every `try` an application can write: it is not
 * an exception, nothing catches it, and whatever the interpreter prints goes
 * straight to the client. With `display_errors` on — which is the default in
 * many stacks and was on in the install this was measured on — that print
 * includes the ABSOLUTE PATH of the failing file and its line number, under
 * whatever status code was already set. A dispatcher that has not yet failed
 * has status 200, so the caller receives a 200 whose body is a PHP error.
 *
 * `/management` already converted fatals into a clean 500 envelope. `/admin/api`
 * had no equivalent, and the measured difference on the SAME fatal was:
 *
 *     /management   500  {"status":500,"code":"server.internal_error",…}   0 paths
 *     /admin/api    200  <b>Parse error</b>: … in <b>C:\…\config.php</b>    1 path
 *
 * This is that logic, extracted rather than copied. C11 spent a slice unifying
 * seven hand-copied marker binds; adding an eighth copy of anything is the
 * shape that beta was fixing. One implementation, four response shapes, one
 * place where the development gate is consulted.
 *
 * C13 added the third shape and the third caller: the admin PAGE surface
 * (`public/admin/index.php`) also had no fatal handling, so the identical
 * defect was live there — an admin page answering 200 with a stack trace and
 * absolute paths in its body. A page cannot answer with a JSON envelope, hence
 * QS_FATAL_SHAPE_HTML: same status, same redaction, same development gate,
 * different content type. The function is named for what it does (register a
 * fatal handler) rather than for one of the shapes it can emit — a
 * `..._json_handler` that returns HTML is how a fourth hand-rolled copy gets
 * written.
 *
 * The fourth shape and the fourth caller are a BUILT site's front controller.
 * A build had no fatal handling at all, so the identical defect was live on a
 * surface whose visitor is the general public: a fatal reached the page body as
 * a raw error with absolute server paths, at HTTP 200. It gets its own shape
 * rather than reusing the admin one because the audience is different — a
 * visitor cannot "ask whoever administers this installation", and the page must
 * read as the site's, not as a panel's. Everything else is shared: the same
 * status, the same redaction, the same one development gate.
 *
 * Not a general error handler: it deliberately handles ONLY the fatal classes
 * (E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR / E_USER_ERROR). Warnings
 * and notices are left to PHP's own logging, exactly as before.
 */

require_once __DIR__ . '/environment.php';

/** Response shapes the three dispatchers use. */
const QS_FATAL_SHAPE_ENVELOPE = 'envelope';   // /management — ApiResponse's {status,code,message,data}
const QS_FATAL_SHAPE_ERROR    = 'error';      // /admin/api  — its own {error: …}
const QS_FATAL_SHAPE_HTML     = 'html';       // /admin      — the panel's page surface
const QS_FATAL_SHAPE_SITE     = 'site';       // a BUILT site — the author's public website

/**
 * Register the fatal → 500 converter for the current request.
 *
 * Safe to call more than once per request; only the first registration takes
 * effect, so a dispatcher that includes another cannot double-emit.
 *
 * @param string $shape One of the QS_FATAL_SHAPE_* constants.
 */
function qs_register_fatal_handler(string $shape = QS_FATAL_SHAPE_ENVELOPE): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    // beta.10 C13 F-C13-14(a). The shutdown handler below deliberately bails once
    // headers_sent() is true — after that point the status and Content-Type are on
    // the wire and nothing can be repaired. A fatal raised INSIDE that window is
    // therefore uncoverable, and with display_errors on the interpreter prints the
    // error — absolute path included — straight into an already-200 body. Turning
    // the print off closes the DISCLOSURE even where the handler cannot reach;
    // the error still goes to the log, which is where it belongs in production.
    //
    // Lives here rather than in each dispatcher because both of them enter through
    // this one call, and C11 spent a slice deleting hand-copied duplicates.
    require_once __DIR__ . '/environment.php';
    if (!qs_is_development()) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }

    register_shutdown_function(static function () use ($shape) {
        $error = error_get_last();
        if ($error === null
            || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        // Discard whatever the failing request had already produced —
        // a half-written JSON body, a PHP error already printed into the
        // buffer, or stray bytes from a require. The same discipline
        // ApiResponse::send() applies on the success path.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // headers_sent() is the one case nothing can repair: the status and
        // content-type are already on the wire. Emitting a JSON body after a
        // PHP error has been flushed would append confusion to a leak rather
        // than replacing it, so stop instead — the error log still has it.
        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Cache-Control: no-store');

        // The ONLY place any of this detail is allowed out, and only when the
        // install has deliberately declared itself a development install.
        // function_exists() because a fatal early enough to beat the require at
        // the top of this file must still produce the SAFE body.
        $debug = (function_exists('qs_is_development') && qs_is_development())
            ? [
                'type'    => qs_fatal_type_name($error['type']),
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
            ]
            : null;

        if ($shape === QS_FATAL_SHAPE_HTML || $shape === QS_FATAL_SHAPE_SITE) {
            header('Content-Type: text/html; charset=utf-8');
            echo $shape === QS_FATAL_SHAPE_SITE
                ? qs_fatal_site_page($debug)
                : qs_fatal_html_page($debug);
            return;
        }

        header('Content-Type: application/json');
        $body = $shape === QS_FATAL_SHAPE_ERROR
            ? ['error' => 'Internal server error']
            : [
                'status'  => 500,
                'code'    => 'server.internal_error',
                'message' => 'A fatal error occurred while processing the request',
                'data'    => null,
            ];
        if ($debug !== null) {
            $body['debug'] = $debug;
        }

        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    });
}

/**
 * The admin page surface's fatal body.
 *
 * Deliberately a self-contained string rather than a template: by the time this
 * runs the request has already died, and the layout/translation machinery it
 * would have to load is one of the things that can have died with it. It
 * therefore reads no constant, requires no file and calls no helper — the same
 * reason the JSON shapes hand-build their arrays.
 *
 * Inline CSS because the panel's stylesheet is served from a URL this function
 * cannot derive without BASE_URL (which a fatal in init.php would have left
 * undefined) — and because an error page that depends on a second request to
 * look right is an error page that can fail twice.
 *
 * @param array|null $debug The type/message/file/line quadruple, or null in
 *                          production — the argument is the gate's *result*,
 *                          so the decision stays in one place above.
 */
function qs_fatal_html_page(?array $debug): string
{
    $page = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>Something went wrong</title>'
        . '<style>body{margin:0;padding:3rem 1.5rem;background:#12141a;color:#e6e8ee;'
        . 'font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . 'main{max-width:38rem;margin:0 auto}h1{font-size:1.4rem;margin:0 0 .75rem}'
        . 'p{margin:0 0 .75rem;color:#a9b0c0}'
        . 'pre{white-space:pre-wrap;word-break:break-all;background:#1b1e27;border:1px solid #2c3040;'
        . 'border-radius:6px;padding:1rem;font-size:.85rem;color:#e6e8ee}</style></head><body><main>'
        . '<h1>Something went wrong</h1>'
        . '<p>This page could not be completed. The error has been recorded in the server log.</p>'
        . '<p>Go back and try again. If it keeps happening, ask whoever administers this'
        . ' installation to check the PHP error log.</p>';

    if ($debug !== null) {
        $page .= '<p><strong>Development mode</strong> — details below are shown because this'
            . ' install declares <code>environment.php</code> as development.</p><pre>'
            . htmlspecialchars(
                $debug['type'] . ': ' . $debug['message'] . "\n"
                . $debug['file'] . ':' . $debug['line'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '</pre>';
    }

    return $page . '</main></body></html>';
}

/**
 * A BUILT site's fatal body — the author's public website, not a control panel.
 *
 * Written for a visitor, which is the whole reason it is not the admin page
 * above: someone who followed a link to a shop or a blog cannot check a PHP
 * error log and should not be told to. So it says only that the page could not
 * be shown, and offers the one thing a visitor can act on — try again, or go to
 * the home page.
 *
 * Same rules as every other shape: no filesystem detail unless the DEPLOYMENT
 * declares itself development (the caller passes the gate's result, so the
 * decision stays in one place), `noindex` so a transient failure is not
 * indexed, and self-contained markup — by the time this runs the site's own
 * stylesheet may be exactly what failed, and an error page that needs a second
 * request to look right can fail twice.
 *
 * The voice matches `qs_site_fail()` in the front controller, which covers the
 * other half of the same problem: that one answers a site that cannot BOOT
 * (logging the path, printing none), this one answers a site that booted and
 * then died.
 *
 * @param array|null $debug type/message/file/line, or null in production.
 */
function qs_fatal_site_page(?array $debug): string
{
    // The site's own root, which is '/<space>/' when the build is mounted under
    // one. Guarded rather than assumed: a fatal early enough to beat the front
    // controller's constants must still produce a page, and '/' is the right
    // answer for the build layout that has no space.
    $home = htmlspecialchars(
        defined('QS_PUBLIC_BASE') ? (string) QS_PUBLIC_BASE : '/',
        ENT_QUOTES,
        'UTF-8'
    );

    $page = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>Page unavailable</title>'
        . '<style>body{margin:0;padding:4rem 1.5rem;background:#fff;color:#1c1e24;'
        . 'font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . 'main{max-width:34rem;margin:0 auto}h1{font-size:1.5rem;margin:0 0 .75rem}'
        . 'p{margin:0 0 .75rem;color:#4b5060}a{color:inherit}'
        . 'pre{white-space:pre-wrap;word-break:break-all;background:#f4f5f8;'
        . 'border:1px solid #dfe2ea;border-radius:6px;padding:1rem;font-size:.85rem;'
        . 'color:#1c1e24}</style></head><body><main>'
        . '<h1>This page is temporarily unavailable</h1>'
        . '<p>Something went wrong while building this page. Nothing you did caused it.</p>'
        . '<p>Please try again in a moment, or return to the'
        . ' <a href="' . $home . '">home page</a>.</p>';

    if ($debug !== null) {
        $page .= '<p><strong>Development mode</strong> — details below are shown because this'
            . ' deployment sets <code>QS_ENVIRONMENT=development</code>.</p><pre>'
            . htmlspecialchars(
                $debug['type'] . ': ' . $debug['message'] . "\n"
                . $debug['file'] . ':' . $debug['line'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '</pre>';
    }

    return $page . '</main></body></html>';
}

/** Human-readable name for a fatal error constant. */
function qs_fatal_type_name(int $type): string
{
    switch ($type) {
        case E_ERROR:         return 'E_ERROR';
        case E_PARSE:         return 'E_PARSE';
        case E_CORE_ERROR:    return 'E_CORE_ERROR';
        case E_COMPILE_ERROR: return 'E_COMPILE_ERROR';
        case E_USER_ERROR:    return 'E_USER_ERROR';
        default:              return 'UNKNOWN';
    }
}

/**
 * Redact an exception message that is about to reach a response body.
 *
 * PHP's own messages routinely embed absolute paths —
 * `file_get_contents(C:\wamp64\...\x.json): Failed to open stream` — so a
 * handler that helpfully forwards `$e->getMessage()` publishes the install
 * layout without ever intending to. In development the message passes through
 * unchanged; in production the caller gets a fixed string and the real message
 * goes to the error log, where the operator can still find it.
 *
 * @param string $context Short label recorded in the log line.
 */
function qs_safe_error_message(Throwable $e, string $context = ''): string
{
    require_once __DIR__ . '/environment.php';
    if (qs_is_development()) {
        return $e->getMessage();
    }
    error_log(
        'QuickSite' . ($context !== '' ? ' [' . $context . ']' : '')
        . ': ' . get_class($e) . ' — ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine()
    );
    return 'An internal error occurred while processing the request';
}
