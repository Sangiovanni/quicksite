<?php
/**
 * Fatal-error hygiene for the JSON dispatchers (beta.10 C12).
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
 * shape that beta was fixing. One implementation, two response shapes, one
 * place where the development gate is consulted.
 *
 * Not a general error handler: it deliberately handles ONLY the fatal classes
 * (E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR / E_USER_ERROR). Warnings
 * and notices are left to PHP's own logging, exactly as before.
 */

require_once __DIR__ . '/environment.php';

/** Response shapes the two dispatchers use. */
const QS_FATAL_SHAPE_ENVELOPE = 'envelope';   // /management — ApiResponse's {status,code,message,data}
const QS_FATAL_SHAPE_ERROR    = 'error';      // /admin/api  — its own {error: …}

/**
 * Register the fatal → JSON-500 converter for the current request.
 *
 * Safe to call more than once per request; only the first registration takes
 * effect, so a dispatcher that includes another cannot double-emit.
 *
 * @param string $shape One of the QS_FATAL_SHAPE_* constants.
 */
function qs_register_fatal_json_handler(string $shape = QS_FATAL_SHAPE_ENVELOPE): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

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
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $body = $shape === QS_FATAL_SHAPE_ERROR
            ? ['error' => 'Internal server error']
            : [
                'status'  => 500,
                'code'    => 'server.internal_error',
                'message' => 'A fatal error occurred while processing the request',
                'data'    => null,
            ];

        // The ONLY place any of this detail is allowed out, and only when the
        // install has deliberately declared itself a development install.
        // function_exists() because a fatal early enough to beat the require at
        // the top of this file must still produce the SAFE body.
        if (function_exists('qs_is_development') && qs_is_development()) {
            $body['debug'] = [
                'type' => qs_fatal_type_name($error['type']),
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ];
        }

        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    });
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
