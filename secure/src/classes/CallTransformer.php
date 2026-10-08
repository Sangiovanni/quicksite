<?php

require_once __DIR__ . '/../functions/qsVerbCatalog.php';
require_once __DIR__ . '/Translator.php';
// The argument literal and the per-request translation lookup. They live in the
// runtime handoff because a build ships that file and not this one.
require_once __DIR__ . '/../functions/runtimeHandoff.php';

/**
 * CallTransformer — single source of truth for {{call:verb:args}} -> QS.*()
 * transformation + handler validation. Consumed by BOTH JsonToHtmlRenderer
 * (render) and JsonToPhpCompiler (compile), plus PageManagement (page-event
 * chains), so the two engines can't drift (the CallTransformer twin of
 * UrlPolicy). It replaces two hand-mirrored copies that HAD drifted (compiler
 * lacked the async-chain wrapper, `\,` comma-escaping, and translatable
 * keyword-args).
 *
 * Fixes folded in at extraction:
 *   - F-e: isValidHandler() uses a structural, quote-and-paren-aware scan, so a
 *     legitimate selector arg containing ')' (e.g. QS.hide('input:not(.x)'))
 *     validates instead of being dropped by the old /QS\.[a-zA-Z]+\([^)]*\)/.
 *   - every argument is written as a complete single-quoted literal
 *     (qs_js_single_quoted(), in runtimeHandoff.php): valid whatever the value
 *     holds, and no "<" from the value reaches the page.
 *
 * A translatable argument is looked up in the request's language. The live
 * render does that as it renders (transform()); a multilingual build compiles
 * each route once, so it takes the chain as segments (transformSegments()) and
 * writes each translatable argument as a lookup the built page makes when it is
 * served.
 *
 * A target on this site — an argument the catalogue marks `siteUrl` whose value
 * starts with one '/' — is composed the way a link to the same place is: the
 * site's base, and for a page the request's language. The base is where the page
 * is served (`/p/<projectId>/`, a URL space), which only the surface serving it
 * knows, so the target travels as a segment too: the live render composes it
 * with the renderer's own URL function (the composer handed to transform() /
 * resolveSegments()), and a build writes a call to the compiled page's, made
 * each time the page is served.
 */
class CallTransformer
{
    /** Verbs pulled out of the async wrapper, emitted as a sync prelude. */
    private const CHAIN_SYNC_PRELUDE = ['validate'];

    /** Verbs returning a Promise — trigger async IIFE + `await` wrapping. */
    private const CHAIN_AWAITABLE = ['fetch', 'exchangeMagicLink', 'requestMagicLink', 'logoutServer'];

    /** Per-verb keyword-args carrying translation KEYS, looked up in the request's language. */
    private const TRANSLATABLE_KEYWORD_ARGS = [
        'fetch' => ['toastSuccessKey', 'toastErrorKey'],
    ];

    private static array $translatablePositionalCache = [];

    private static array $siteUrlPositionalCache = [];

    /** Allowed verbs: catalog + the not-yet-cataloged applyAuthState. */
    public static function allowedFunctions(): array
    {
        return array_merge(qsVerbNames(), ['applyAuthState']);
    }

    /**
     * Transform every {{call:...}} in $value into QS.*() JS (chain-aware).
     *
     * @param callable|null $composeTarget (string $url, string $kind) → string: a target on
     *                                     this site as the caller composes a link to it
     *                                     ('page' or 'resource'); null writes it as authored
     */
    public static function transform(string $value, ?callable $composeTarget = null): string
    {
        return self::resolveSegments(self::transformSegments($value), $composeTarget);
    }

    /**
     * transform(), with each translatable argument left for the caller to look
     * up and each target on this site left for it to compose: a list of
     * JavaScript strings, ['arg' => <key>, 'keyword' => <name> or null] entries
     * and ['target' => <url>, 'kind' => 'page'|'resource'] entries, in order. One
     * walk of the chain for both uses, so the live render and a build cannot write
     * different JavaScript around the argument.
     *
     * @return array<int, string|array{arg: string, keyword: ?string}|array{target: string, kind: string}>
     */
    public static function transformSegments(string $value): array
    {
        if (!preg_match_all('/\{\{call:([a-zA-Z][a-zA-Z0-9]*)(:[^}]*)?\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return [$value];
        }
        $allowed = self::allowedFunctions();
        $syncPrelude = [];
        $body = [];
        $hasAwaitable = false;

        foreach ($matches as $m) {
            $fn = $m[1];
            $argsString = isset($m[2]) ? substr($m[2], 1) : '';
            if (!in_array($fn, $allowed, true)) {
                // The engine-side detail goes to the SERVER log, where the
                // operator who could act on it can read it. It must not go into
                // the console.warn below: that string is compiled into the built
                // site's public HTML and served to every visitor, so naming an
                // engine source file there would publish the install's internal
                // layout for no benefit to the person reading the console.
                error_log("Unknown QS function: {$fn} (not in the runtime verb catalogue)");
                // Context-neutral message (was "at render"/"at compile" in the
                // two old copies — unified here).
                $syncPrelude[] = ["console.warn('[QS] unknown verb {{call:{$fn}:...}} dropped — not in the QuickSite runtime verb catalogue')"];
                continue;
            }
            $callSegments = self::buildCallSegments($fn, $argsString);
            if (in_array($fn, self::CHAIN_SYNC_PRELUDE, true)) {
                $syncPrelude[] = $callSegments;
            } else {
                $body[] = $callSegments;
                if (in_array($fn, self::CHAIN_AWAITABLE, true)) {
                    $hasAwaitable = true;
                }
            }
        }

        $parts = [];
        if (!empty($syncPrelude)) {
            $parts[] = self::joinSegments($syncPrelude, ';');
        }
        if (!empty($body)) {
            if ($hasAwaitable) {
                $awaited = array_map(fn($c) => array_merge(['await '], $c), $body);
                $parts[] = array_merge(
                    ['(async()=>{'],
                    self::joinSegments($awaited, ';'),
                    ["})().catch(e=>console.warn('[QS] chain aborted:',e))"]
                );
            } else {
                $parts[] = self::joinSegments($body, ';');
            }
        }
        return self::mergeStrings(self::joinSegments($parts, ';'));
    }

    /**
     * Segments joined into JavaScript, each translatable argument looked up now,
     * in this request's language, and each target composed by $composeTarget (as
     * authored when there is none).
     */
    public static function resolveSegments(array $segments, ?callable $composeTarget = null): string
    {
        $js = '';
        foreach ($segments as $segment) {
            if (is_string($segment)) {
                $js .= $segment;
            } elseif (isset($segment['target'])) {
                $js .= qs_call_argument_js($composeTarget !== null
                    ? (string) $composeTarget($segment['target'], $segment['kind'])
                    : $segment['target']);
            } else {
                $js .= qs_translated_call_argument($segment['arg'], $segment['keyword']);
            }
        }
        return $js;
    }

    /** implode() over segment lists. */
    private static function joinSegments(array $lists, string $separator): array
    {
        $joined = [];
        foreach (array_values($lists) as $i => $list) {
            if ($i > 0) {
                $joined[] = $separator;
            }
            foreach ($list as $segment) {
                $joined[] = $segment;
            }
        }
        return $joined;
    }

    /** Adjacent strings folded into one, so a chain with nothing to look up is one string. */
    private static function mergeStrings(array $segments): array
    {
        $merged = [];
        foreach ($segments as $segment) {
            $last = count($merged) - 1;
            if (is_string($segment) && $last >= 0 && is_string($merged[$last])) {
                $merged[$last] .= $segment;
            } else {
                $merged[] = $segment;
            }
        }
        return $merged;
    }

    private static function buildCallSegments(string $fn, string $argsString): array
    {
        if ($argsString === '') {
            return ["QS.{$fn}()"];
        }
        $args = preg_split('/(?<!\\\\),/', $argsString);
        $args = array_map(fn($a) => trim(str_replace('\\,', ',', $a)), $args);

        $translatableKwargs = self::TRANSLATABLE_KEYWORD_ARGS[$fn] ?? [];
        $translatablePositions = self::getTranslatablePositionalIndices($fn);
        $siteUrlPositions = self::getSiteUrlPositions($fn);
        // A fetch takes a URL only in direct-URL mode (a method first). In registry
        // mode (@api/endpoint first) its second argument is an option.
        if ($fn === 'fetch' && strpos($args[0] ?? '', '@') === 0) {
            $siteUrlPositions = [];
        }

        $segments = ["QS.{$fn}("];
        foreach ($args as $i => $arg) {
            if ($i > 0) {
                $segments[] = ', ';
            }
            if (isset($siteUrlPositions[$i]) && self::isSiteTarget($arg)) {
                $segments[] = ['target' => $arg, 'kind' => $siteUrlPositions[$i]];
                continue;
            }
            $segments[] = self::argumentSegment($arg, $i, $translatableKwargs, $translatablePositions);
        }
        $segments[] = ')';
        return $segments;
    }

    /**
     * One argument: a translatable one as ['arg', 'keyword'] for the lookup,
     * anything else written now.
     *
     *   keyword  `name=<key>`, when the verb lists `name` and the key is not empty
     *   position an argument at a position the catalogue marks `translationKey`,
     *            when it is not empty and is not itself a `name=value`
     */
    private static function argumentSegment(string $arg, int $index, array $translatableKwargs, array $translatablePositions)
    {
        $eq = strpos($arg, '=');
        if ($eq !== false && !empty($translatableKwargs)) {
            $key = substr($arg, 0, $eq);
            $val = substr($arg, $eq + 1);
            if ($val !== '' && in_array($key, $translatableKwargs, true)) {
                return ['arg' => $val, 'keyword' => $key];
            }
        }
        if ($eq === false && $arg !== '' && in_array($index, $translatablePositions, true)) {
            return ['arg' => $arg, 'keyword' => null];
        }
        return qs_call_argument_js($arg);
    }

    /**
     * A target on this site: one '/' and then not a second '/' or a backslash (a
     * browser reads both of those as the start of another host). '//host',
     * 'https://…', '#…', '?…' and a relative 'page' are written as authored.
     */
    private static function isSiteTarget(string $arg): bool
    {
        return preg_match('#^/(?![/\\\\])#', $arg) === 1;
    }

    /** The positions the catalogue marks `siteUrl`, as position => 'page'|'resource'. */
    private static function getSiteUrlPositions(string $fn): array
    {
        if (isset(self::$siteUrlPositionalCache[$fn])) {
            return self::$siteUrlPositionalCache[$fn];
        }
        $positions = [];
        foreach (qsVerbCatalog() as $entry) {
            if (($entry['name'] ?? '') !== $fn) continue;
            foreach (($entry['args'] ?? []) as $i => $arg) {
                if (in_array($arg['siteUrl'] ?? null, ['page', 'resource'], true)) {
                    $positions[$i] = $arg['siteUrl'];
                }
            }
            break;
        }
        return self::$siteUrlPositionalCache[$fn] = $positions;
    }

    private static function getTranslatablePositionalIndices(string $fn): array
    {
        if (isset(self::$translatablePositionalCache[$fn])) {
            return self::$translatablePositionalCache[$fn];
        }
        $indices = [];
        foreach (qsVerbCatalog() as $entry) {
            if (($entry['name'] ?? '') !== $fn) continue;
            foreach (($entry['args'] ?? []) as $i => $arg) {
                if (($arg['inputType'] ?? '') === 'translationKey') {
                    $indices[] = $i;
                }
            }
            break;
        }
        return self::$translatablePositionalCache[$fn] = $indices;
    }

    /**
     * Structural validation: the handler must be ONLY our-generated tokens —
     * QS.<verb>(...) calls, console.warn(...) notices, the async chain wrapper,
     * and ';'/whitespace/await between them. Quote- and paren-aware, so a
     * selector arg containing ')' validates (fixes F-e); a foreign identifier
     * (alert, eval, …) still fails.
     */
    public static function isValidHandler(string $handler): bool
    {
        // Strip the async-chain wrapper (fully our-generated) to its body.
        $s = preg_replace(
            "/\\(async\\(\\)=>\\{(.+?)\\}\\)\\(\\)\\.catch\\(e=>console\\.warn\\('\\[QS\\] chain aborted:',\\s*e\\)\\)/s",
            '$1',
            $handler
        );
        $s = preg_replace('/\bawait\s+/', '', $s);

        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ';' || $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }
            if ($c === '/' && $i + 1 < $n && $s[$i + 1] === '*') {
                $end = strpos($s, '*/', $i + 2);
                if ($end === false) return false;
                $i = $end + 2;
                continue;
            }
            $next = self::consumeCall($s, $i);
            if ($next === false) return false;
            $i = $next;
        }
        return true;
    }

    /**
     * Consume one `QS.<verb>(...)` or `console.warn(...)` at offset $i, with a
     * balanced, single-quote-aware paren scan. Returns the offset past the
     * closing ')', or false if malformed.
     */
    private static function consumeCall(string $s, int $i)
    {
        $n = strlen($s);
        if (!preg_match('/^(QS\.[a-zA-Z][a-zA-Z0-9]*|console\.warn)\(/', substr($s, $i), $m)) {
            return false;
        }
        $i += strlen($m[0]); // just past '('
        $depth = 1;
        while ($i < $n) {
            $c = $s[$i];
            if ($c === "'") {                       // single-quoted string
                $i++;
                while ($i < $n) {
                    if ($s[$i] === '\\') { $i += 2; continue; }
                    if ($s[$i] === "'") { $i++; break; }
                    $i++;
                }
                continue;
            }
            if ($c === '(') { $depth++; $i++; continue; }
            if ($c === ')') {
                $depth--; $i++;
                if ($depth === 0) return $i;
                continue;
            }
            $i++;
        }
        return false; // unbalanced
    }
}
