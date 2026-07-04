<?php

require_once __DIR__ . '/../functions/qsVerbCatalog.php';
require_once __DIR__ . '/Translator.php';

/**
 * CallTransformer — single source of truth for {{call:verb:args}} -> QS.*()
 * transformation + handler validation. Consumed by BOTH JsonToHtmlRenderer
 * (render) and JsonToPhpCompiler (compile), plus PageManagement (page-event
 * chains), so the two engines can't drift (beta.10 R-6 — the CallTransformer
 * twin of UrlPolicy). It replaces two hand-mirrored copies that HAD drifted
 * (compiler lacked the async-chain wrapper, `\,` comma-escaping, and
 * translatable keyword-args).
 *
 * Fixes folded in at extraction:
 *   - F-e: isValidHandler() uses a structural, quote-and-paren-aware scan, so a
 *     legitimate selector arg containing ')' (e.g. QS.hide('input:not(.x)'))
 *     validates instead of being dropped by the old /QS\.[a-zA-Z]+\([^)]*\)/.
 *   - F-a residual: buildCallJs() escapes '\' BEFORE "'", so a trailing
 *     backslash can't turn the closing quote into an escaped one (no more
 *     broken-JS handlers emitted-and-accepted).
 */
class CallTransformer
{
    /** Verbs pulled out of the async wrapper, emitted as a sync prelude. */
    private const CHAIN_SYNC_PRELUDE = ['validate'];

    /** Verbs returning a Promise — trigger async IIFE + `await` wrapping. */
    private const CHAIN_AWAITABLE = ['fetch', 'exchangeMagicLink', 'requestMagicLink', 'logoutServer'];

    /** Per-verb keyword-args carrying translation KEYS resolved at compile time. */
    private const TRANSLATABLE_KEYWORD_ARGS = [
        'fetch' => ['toastSuccessKey', 'toastErrorKey'],
    ];

    private static array $translatablePositionalCache = [];

    /** Allowed verbs: catalog + the not-yet-cataloged applyAuthState. */
    public static function allowedFunctions(): array
    {
        return array_merge(qsVerbNames(), ['applyAuthState']);
    }

    /** Transform every {{call:...}} in $value into QS.*() JS (chain-aware). */
    public static function transform(string $value): string
    {
        if (!preg_match_all('/\{\{call:([a-zA-Z][a-zA-Z0-9]*)(:[^}]*)?\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return $value;
        }
        $allowed = self::allowedFunctions();
        $syncPrelude = [];
        $body = [];
        $hasAwaitable = false;

        foreach ($matches as $m) {
            $fn = $m[1];
            $argsString = isset($m[2]) ? substr($m[2], 1) : '';
            if (!in_array($fn, $allowed, true)) {
                error_log("Unknown QS function: {$fn}");
                // Context-neutral message (was "at render"/"at compile" in the
                // two old copies — unified here).
                $syncPrelude[] = "console.warn('[QS] unknown verb {{call:{$fn}:...}} dropped — verb missing from secure/src/functions/qsVerbCatalog.php')";
                continue;
            }
            $callJs = self::buildCallJs($fn, $argsString);
            if (in_array($fn, self::CHAIN_SYNC_PRELUDE, true)) {
                $syncPrelude[] = $callJs;
            } else {
                $body[] = $callJs;
                if (in_array($fn, self::CHAIN_AWAITABLE, true)) {
                    $hasAwaitable = true;
                }
            }
        }

        $parts = [];
        if (!empty($syncPrelude)) {
            $parts[] = implode(';', $syncPrelude);
        }
        if (!empty($body)) {
            if ($hasAwaitable) {
                $awaited = array_map(fn($c) => 'await ' . $c, $body);
                $parts[] = "(async()=>{" . implode(';', $awaited) . "})().catch(e=>console.warn('[QS] chain aborted:',e))";
            } else {
                $parts[] = implode(';', $body);
            }
        }
        return implode(';', $parts);
    }

    private static function buildCallJs(string $fn, string $argsString): string
    {
        if ($argsString === '') {
            return "QS.{$fn}()";
        }
        $jsKeywords = ['event', 'this'];
        $args = preg_split('/(?<!\\\\),/', $argsString);
        $args = array_map(fn($a) => trim(str_replace('\\,', ',', $a)), $args);

        $translatableKwargs = self::TRANSLATABLE_KEYWORD_ARGS[$fn] ?? [];
        if (!empty($translatableKwargs)) {
            $args = array_map(function ($arg) use ($translatableKwargs) {
                $eq = strpos($arg, '=');
                if ($eq === false) return $arg;
                $key = substr($arg, 0, $eq);
                $val = substr($arg, $eq + 1);
                if ($val === '' || !in_array($key, $translatableKwargs, true)) return $arg;
                return $key . '=' . Translator::translate($val);
            }, $args);
        }

        $translatablePositions = self::getTranslatablePositionalIndices($fn);
        foreach ($translatablePositions as $idx) {
            if (!isset($args[$idx]) || $args[$idx] === '') continue;
            if (strpos($args[$idx], '=') !== false) continue;
            $args[$idx] = self::resolveTranslationKeyOrFallback($args[$idx]);
        }

        $quoted = array_map(function ($arg) use ($jsKeywords) {
            if (in_array($arg, $jsKeywords, true)) return $arg;
            // F-a residual fix: escape '\' BEFORE "'" so a trailing backslash
            // can't escape the closing quote.
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $arg) . "'";
        }, $args);
        return "QS.{$fn}(" . implode(', ', $quoted) . ")";
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

    private static function resolveTranslationKeyOrFallback(string $value): string
    {
        $translated = Translator::translate($value);
        if (strpos($translated, '{translation missing:') === 0) {
            return $value;
        }
        return $translated;
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
