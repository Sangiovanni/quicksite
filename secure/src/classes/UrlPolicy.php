<?php

/**
 * UrlPolicy — single source of truth for URL-attribute scheme SAFETY.
 *
 * Consumed by BOTH JsonToHtmlRenderer (render path) and the pages emitted by
 * JsonToPhpCompiler (deploy path), so the two can't drift (beta.10 R-6).
 *
 * Two responsibilities are deliberately kept SEPARATE:
 *   - SCHEME SAFETY (this class): a value-based ALLOWLIST applied to any
 *     attribute a browser resolves as a URL. Closes F-b (dangerous scheme on
 *     attributes outside the old fixed list, e.g. xlink:href / ping) and F-d
 *     (leading/embedded ASCII-control-char scheme dodge).
 *   - URL REWRITING (BASE_URL / language prefix): stays in each engine's
 *     processUrl(), scoped to the classic rewritable set. This class does NOT
 *     rewrite — it only makes a value scheme-safe.
 *
 * Allowlist locked beta.10 (Sangio 2026-07-03): http, https, mailto, tel only.
 * data:/blob:/javascript:/vbscript:/file:/everything-else -> neutralised to '#'.
 * (data: was already blocked on the classic URL attrs pre-fix, so no regression.)
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
     *     detection (closes the F-d leading-char dodge)
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
}
