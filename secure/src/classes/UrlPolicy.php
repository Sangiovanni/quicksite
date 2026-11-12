<?php

/**
 * UrlPolicy — single source of truth for URL-attribute scheme SAFETY.
 *
 * Consumed by BOTH JsonToHtmlRenderer (render path) and the pages emitted by
 * JsonToPhpCompiler (deploy path), so the two can't drift.
 *
 * Two responsibilities are deliberately kept SEPARATE:
 *   - SCHEME SAFETY (this class): a value-based ALLOWLIST applied to any
 *     attribute a browser resolves as a URL. Closes a dangerous scheme on
 *     attributes outside a fixed list (e.g. xlink:href / ping) and the
 *     leading/embedded ASCII-control-char scheme dodge.
 *   - URL REWRITING (BASE_URL / language prefix): stays in each engine's
 *     processUrl(), scoped to the classic rewritable set. This class does NOT
 *     rewrite — it only makes a value scheme-safe.
 *
 * Allowlist (by design): http, https, mailto, tel only.
 * data:/blob:/javascript:/vbscript:/file:/everything-else -> neutralised to '#'.
 */
class UrlPolicy
{
    /** Schemes permitted to appear explicitly in a URL attribute value. */
    const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Attributes browsers resolve as URLs — a SUPERSET of the rewritable set.
     * Namespaced refs (xlink:href, xlink:src) are matched by isUrlAttribute()
     * via pattern, not listed here.
     */
    const URL_ATTRIBUTES = [
        'href', 'src', 'srcset', 'poster', 'action', 'formaction',
        'cite', 'data', 'ping', 'background', 'longdesc',
    ];

    /**
     * Classic set that ALSO gets BASE_URL/language rewriting — behaviour here
     * is unchanged from pre-fix (this is exactly the old $urlAttributes list).
     */
    const REWRITABLE_URL_ATTRIBUTES = [
        'href', 'src', 'data', 'poster', 'action', 'formaction', 'cite', 'srcset',
    ];

    /** Is this attribute name a URL sink (needs scheme safety)? */
    public static function isUrlAttribute(string $name): bool
    {
        $n = strtolower($name);
        if (in_array($n, self::URL_ATTRIBUTES, true)) {
            return true;
        }
        // Namespaced URL refs: xlink:href, xlink:src, any future <ns>:href/<ns>:src.
        return (bool) preg_match('/(?:^|:)(?:href|src)$/', $n);
    }

    /** Does this attribute also get BASE_URL/language rewriting? (unchanged set) */
    public static function isRewritableUrlAttribute(string $name): bool
    {
        return in_array(strtolower($name), self::REWRITABLE_URL_ATTRIBUTES, true);
    }

    /**
     * Return a scheme-SAFE version of $value, or '#' if it carries a disallowed
     * scheme or any ASCII control character. Does NOT do BASE_URL rewriting.
     *
     *   - strips leading whitespace/control a browser ignores before scheme
     *     detection (closes the leading-char dodge)
     *   - rejects any value containing an embedded ASCII control char, so
     *     "java<TAB>script:" can't sneak through either
     *   - an explicit scheme not in ALLOWED_SCHEMES -> '#'
     *   - relative / anchor / protocol-relative values pass through unchanged
     */
    public static function sanitize(string $value): string
    {
        // What a browser actually sees after stripping leading ASCII ws/control.
        $probe = ltrim($value, " \t\n\r\0\x0B\f");

        // Any embedded control char => not a legitimate URL. Neutralise.
        if (preg_match('/[\x00-\x1F\x7F]/', $probe)) {
            return '#';
        }

        // Explicit scheme?  scheme = letter then [a-z0-9+.-]* then ':'
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $m)
            && !in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true)) {
            return '#';
        }

        return $probe;
    }

    /**
     * Would sanitize() refuse this value? A value that is `#` itself (a link to
     * the top of the page) is not refused, though sanitize() answers `#` for it.
     */
    public static function isRefused(string $value): bool
    {
        return self::sanitize($value) === '#' && ltrim($value, " \t\n\r\0\x0B\f") !== '#';
    }

    // ── A REFRESH'S ADDRESS ───────────────────────────────────────────────
    //
    // A `<meta http-equiv="refresh">` sends the browser to the address its
    // `content` holds after a delay, so that address is a URL sink like an
    // href, though it sits inside text. The three functions below find it
    // exactly where a browser does and hold it to the same allowlist: the
    // write gate refuses a structure holding a refused one, and both render
    // paths draw one already stored, or filled in when the page is served,
    // with `#` in its place. Nothing else in a refresh is changed, and a
    // refresh with no address (a delay alone) is left as it is.

    /** Is this `http-equiv` value a refresh? Letter case and surrounding whitespace aside. */
    public static function isRefresh(string $httpEquiv): bool
    {
        return strtolower(trim($httpEquiv, " \t\n\r\0\x0B\f")) === 'refresh';
    }

    /**
     * Where a refresh's address sits in its `content`, as [offset, length], or
     * null when the content holds no address: not a refresh a browser accepts,
     * or a delay alone. The steps are the HTML standard's "shared declarative
     * refresh steps", which browsers follow:
     *   - optional whitespace, then the delay: digits, or a dot, then any run of
     *     digits and dots; anything else first and there is no refresh;
     *   - then the end (a delay alone), or whitespace, `;` or `,`: one separator
     *     with whitespace either side;
     *   - then the address: after an optional `url` (any letter case), optional
     *     whitespace, `=` and whitespace, and an optional quote (' or "), which
     *     ends the address where it appears again. When `url` is not followed by
     *     `=`, the whole rest is the address, `url` included.
     */
    public static function refreshAddress(string $content): ?array
    {
        $ws = " \t\n\f\r";   // the standard's ASCII whitespace
        $n = strlen($content);
        $i = strspn($content, $ws);
        $digits = strspn($content, '0123456789', $i);
        if ($digits === 0 && ($i >= $n || $content[$i] !== '.')) {
            return null;
        }
        $i += $digits;
        $i += strspn($content, '0123456789.', $i);
        if ($i < $n) {
            if ($content[$i] !== ';' && $content[$i] !== ',' && strpos($ws, $content[$i]) === false) {
                return null;
            }
            $i += strspn($content, $ws, $i);
            if ($i < $n && ($content[$i] === ';' || $content[$i] === ',')) {
                $i++;
            }
            $i += strspn($content, $ws, $i);
        }
        if ($i >= $n) {
            return null;
        }
        // The whole rest, unless `url =` and a quote say otherwise.
        $start = $i;
        $quoted = true;
        if (strtolower($content[$i]) === 'u') {
            $quoted = false;
            if ($i + 1 < $n && strtolower($content[$i + 1]) === 'r'
                && $i + 2 < $n && strtolower($content[$i + 2]) === 'l') {
                $j = $i + 3;
                $j += strspn($content, $ws, $j);
                if ($j < $n && $content[$j] === '=') {
                    $j++;
                    $j += strspn($content, $ws, $j);
                    $i = $j;
                    $quoted = true;
                }
            }
        }
        if (!$quoted) {
            return [$start, $n - $start];
        }
        $quote = ($i < $n && ($content[$i] === "'" || $content[$i] === '"')) ? $content[$i] : '';
        if ($quote !== '') {
            $i++;
        }
        $end = $quote !== '' ? strpos($content, $quote, $i) : false;
        return [$i, ($end === false ? $n : $end) - $i];
    }

    /**
     * A refresh's `content` as it may be drawn: a refused address replaced by
     * `#`, everything around it kept; any other content as it is.
     */
    public static function safeRefreshContent(string $content): string
    {
        $at = self::refreshAddress($content);
        if ($at === null || !self::isRefused(substr($content, $at[0], $at[1]))) {
            return $content;
        }
        return substr($content, 0, $at[0]) . '#' . substr($content, $at[0] + $at[1]);
    }

    /**
     * A meta's `content` as it may be drawn, its `http-equiv` known only when
     * the page is served (a placeholder fills it): safe when that makes it a
     * refresh, as it is otherwise.
     */
    public static function safeMetaContent($httpEquiv, string $content): string
    {
        return is_string($httpEquiv) && self::isRefresh($httpEquiv) ? self::safeRefreshContent($content) : $content;
    }
}
