<?php
/**
 * The one Content-Security-Policy, for every surface that serves a project's pages.
 *
 * WHY THIS FILE EXISTS. The policy had ONE writer and TWO surfaces. `/p/<id>/`
 * sent a full policy; a built site sent none at all — not `object-src`, not
 * `base-uri`, not `frame-ancestors`, and no `script-src` restriction of any
 * kind. So the artifact a visitor actually reaches was the less protected of
 * the two, and the difference survived a slice whose whole subject was
 * preview-versus-build parity, because that slice's harness compared rendered
 * DOM and never compared response headers.
 *
 * ⚠ A POLICY THAT BLOCKS A SHIPPED FEATURE IS A BUG, NOT A HARDENING. The
 * preview's policy was `default-src 'self'` with `img-src 'self' data:`, and
 * measured in a browser that blocks:
 *
 *   - an external image, which `UrlPolicy` explicitly permits (http and https
 *     are allowed schemes for URL attributes);
 *   - an external iframe, which `IframeSandbox` exists to support — it is a
 *     whole subsystem for per-domain sandbox rules on embeds that the policy
 *     then refused to load.
 *
 * Copying that to a built site would have taken production sites that work
 * today and silently broken their images and embeds. So the line is drawn by
 * what a resource can DO, not by where it comes from:
 *
 *   TIGHT, because these execute or redirect:
 *     script-src   'self' + 'unsafe-inline' (see below), never a remote host
 *     style-src    same — an external stylesheet can exfiltrate through
 *                  selectors, and the project's own dependency-free rule is
 *                  the same call made once already
 *     object-src   'none' — free: `object`, `embed` and `applet` are blocked
 *                  tags, so nothing an author can write needs it
 *     base-uri     'self' — an injected <base> silently re-points every
 *                  relative URL on the page
 *     default-src  'self' — the floor for everything not named here
 *
 *   PERMISSIVE, because these are passive and authors legitimately point them
 *   at other hosts:
 *     img-src / media-src / font-src / frame-src
 *
 * ⚠ `connect-src` IS DERIVED, and that is the property to preserve. It is
 * `'self'` plus the origin of every registered API with at least one
 * client-callable endpoint — see qs_api_client_origins(). A fixed `'self'`
 * blocked every browser-side call an author had registered, which is the entire
 * client half of the API registry. Anything that edits this file has to keep
 * that: a built site that reverted to a bare `'self'` would re-break in
 * production exactly what was just fixed in development.
 *
 * `'unsafe-inline'` in script-src is required TODAY by the engine's own output
 * (the theme toggle, the state-store hydration, compiled page-event handlers),
 * not by anything an author wrote — `script` is a blocked tag, so a project
 * cannot ship JavaScript of its own. It reduces what script-src is worth; it
 * does not reduce what object-src, base-uri and frame-ancestors are worth, and
 * those cost nothing.
 *
 * NOT SET, deliberately: `form-action`. A form posting to an external endpoint
 * is something an author can legitimately build, and neither surface restricts
 * it today; adding it here would be a silent behaviour change dressed as
 * hardening. It does not fall back to `default-src`, so leaving it out leaves
 * it unrestricted, which is the current behaviour stated out loud.
 */

require_once __DIR__ . '/apiRegistry.php';   // qs_api_client_origins()

if (!function_exists('qs_content_security_policy')) {
    /**
     * The policy for one project's pages, as a header VALUE.
     *
     * @param string|null $projectPath The project being served. Defaults to
     *                                 PROJECT_PATH. A built site's project IS
     *                                 its secure root, so the two agree there.
     */
    function qs_content_security_policy(?string $projectPath = null): string
    {
        // 'self' plus whatever this project registered. Empty is normal: a
        // project with no APIs gets a valid policy with a bare 'self'.
        $connect = "'self'";
        foreach (qs_api_client_origins($projectPath) as $origin) {
            $connect .= ' ' . $origin;
        }

        // http alongside https: an https page blocks http subresources as mixed
        // content anyway, so naming it costs nothing there and keeps the policy
        // usable for an http-only intranet or a local deployment.
        $passive = "'self' data: https: http:";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src {$passive}",
            "font-src {$passive}",
            "media-src {$passive}",
            "frame-src 'self' https: http:",
            "connect-src {$connect}",
            "object-src 'none'",
            "base-uri 'self'",
            // Matches the X-Frame-Options: SAMEORIGIN that both surfaces
            // already send. The preview needs same-origin framing for the admin
            // iframe; a built site has no admin and frames nothing of its own,
            // so 'none' was the tempting answer — but it would make the CSP
            // contradict the header sitting beside it, and tightening a
            // deployed site's embeddability is a decision for whoever deploys
            // it, not a side effect of adding a policy. The two agree instead.
            "frame-ancestors 'self'",
        ]);
    }
}

if (!function_exists('qs_send_content_security_policy')) {
    /** Send it, if nothing has flushed yet. */
    function qs_send_content_security_policy(?string $projectPath = null): void
    {
        if (!headers_sent()) {
            header('Content-Security-Policy: ' . qs_content_security_policy($projectPath));
        }
    }
}
