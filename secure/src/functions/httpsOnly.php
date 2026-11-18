<?php
/**
 * The installation's HTTPS-only refusal.
 *
 * Where the deployment declares `QS_HTTPS_ONLY` (qs_https_only()), a request that did not reach the
 * site over HTTPS (qs_request_is_https()) is turned away at the installation's entry points, the
 * panel, the management API and `/p/` alike:
 *
 *   - a GET or HEAD is redirected (302) to the same address in https, on the validated host
 *     (qs_request_host(), never the raw Host header) with its port left out: the port of a plain
 *     HTTP request is never the HTTPS one;
 *   - any other method is refused with 403 and one line of plain text: a body sent over plain HTTP
 *     has already crossed the network in clear, and replaying it elsewhere is the client's call.
 *
 * Both carry `Cache-Control: no-store`, and the redirect is a 302, never a 301: a browser keeps a
 * 301 for good, so turning the variable off would leave it sending people to https. There is no
 * Strict-Transport-Security header; a deployment that wants one sets it in its server.
 *
 * public/init.php calls qs_https_only_enforce() before anything else runs, so a refused request
 * writes nothing. A build never carries this file: its scheme test reads the same variable
 * (requestRuntime.php), and the refusal is the installation's alone.
 */

require_once __DIR__ . '/requestRuntime.php';

if (!function_exists('qs_https_only_host')) {
    /** The validated host without its port: an IPv6 literal keeps its brackets. */
    function qs_https_only_host(): string
    {
        $host = qs_request_host();
        if ($host !== '' && $host[0] === '[') {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        return (string) preg_replace('/:\d+$/D', '', $host);
    }
}

if (!function_exists('qs_https_only_enforce')) {
    /** Turn away a request that did not arrive over HTTPS, where QS_HTTPS_ONLY is on; return otherwise. */
    function qs_https_only_enforce(): void
    {
        // The command line is no request: a CLI's $_SERVER carries the system's environment.
        if (PHP_SAPI === 'cli' || !qs_https_only() || qs_request_is_https()) {
            return;
        }
        header('Cache-Control: no-store');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET' || $method === 'HEAD') {
            // The path and query as the request named them; anything that is not an absolute path
            // (or carries a control character or a space) goes to the site's root instead.
            $target = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            if ($target === '' || $target[0] !== '/' || preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
                $target = '/';
            }
            header('Location: https://' . qs_https_only_host() . $target, true, 302);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "This site is served over HTTPS only.\n";
        exit;
    }
}
