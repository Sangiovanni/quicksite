<?php
/**
 * Formatting inside one translated sentence: the markers.
 *
 * A translation's word may carry its own formatting, so each language moves it with its words: en "my very
 * <b>bold words</b>", fr "mes mots <b>vraiment gras</b>". The listed markers are QS_TEXT_MARKERS: `b`, `i`, `u`,
 * `strong` and `em` in pairs, and `br` alone. A marker is written exactly so: lowercase, no attribute, no space
 * inside its brackets; the pairs properly nested (a closing marker closes the last one opened and still open) and
 * every one opened closed. Anything else shows as written. A backslash right before a marker keeps it as text and
 * is not shown (`\<b>` shows `<b>`); backslashes right before a marker count in pairs, a pair showing one backslash
 * (so a backslash can stand before formatted words); every other backslash shows as written.
 *
 * WHERE A MARKER BECOMES AN ELEMENT. Only in a text node the renderer or a build draws (qs_text_markers_html()),
 * inside an element that can show formatting (TagRegistry::PLAIN_TEXT_TAGS lists those that cannot), and only in
 * a word a translation holds: never in a `__RAW__` text, never in a missing word's marker, never in a filled value
 * (a placeholder is filled in each run of text between the markers, after they are read, and escaped). Everywhere
 * else a translation's word reaches (an attribute, the page's title and description, the link-preview tags, the
 * words handed to qs.js, an option's text) the markers are removed and the words kept (qs_text_markers_remove()).
 * MAINTAINING.md lists every reader.
 *
 * The editor's script reads and writes the same grammar (public/admin/assets/js/core/text-markers.js), from this
 * list (TagRegistry::editorPayload()); a build carries this file.
 */

if (!defined('QS_TEXT_MARKERS')) {
    /** The markers a translation's word may carry: a pair, or one alone. The one list (a build carries it). */
    define('QS_TEXT_MARKERS', ['b' => 'pair', 'i' => 'pair', 'u' => 'pair', 'strong' => 'pair', 'em' => 'pair', 'br' => 'void']);
}

if (!function_exists('qs_text_markers_parse')) {
    /**
     * A word read into its parts, in order, each one of:
     *   ['text', string]          words, shown as they are (a pair of backslashes before a marker already one)
     *   ['open', name] / ['close', name]   a pair of markers that works
     *   ['void', name]            a marker alone that works (`br`)
     *   ['kept', marker]          a marker kept as text by a backslash (shown without it)
     *   ['loose', marker]         a marker that does not work (not closed, closing nothing open, misnested):
     *                             shown as written
     * Consecutive words are one part.
     */
    function qs_text_markers_parse(string $word): array
    {
        $raw = [];
        $len = strlen($word);
        $pos = 0;
        $text = '';
        while ($pos < $len) {
            $c = $word[$pos];
            if ($c === '\\') {
                $k = strspn($word, '\\', $pos);
                $marker = qs_text_markers_at($word, $pos + $k);
                if ($marker === null) {
                    $text .= str_repeat('\\', $k);
                    $pos += $k;
                    continue;
                }
                $text .= str_repeat('\\', intdiv($k, 2));
                if ($text !== '') { $raw[] = ['text', $text]; $text = ''; }
                $raw[] = $k % 2 === 1 ? ['kept', $marker[0]] : ['cand', $marker[0], $marker[1], $marker[2]];
                $pos += $k + strlen($marker[0]);
                continue;
            }
            if ($c === '<') {
                $marker = qs_text_markers_at($word, $pos);
                if ($marker !== null) {
                    if ($text !== '') { $raw[] = ['text', $text]; $text = ''; }
                    $raw[] = ['cand', $marker[0], $marker[1], $marker[2]];
                    $pos += strlen($marker[0]);
                    continue;
                }
            }
            $next = strcspn($word, '\\<', $pos + 1) + $pos + 1;
            $text .= substr($word, $pos, $next - $pos);
            $pos = $next;
        }
        if ($text !== '') $raw[] = ['text', $text];

        // The pairs: a closing marker closes the last marker opened and still open, when it is the same one.
        $stack = [];
        $role = [];
        foreach ($raw as $i => $part) {
            if ($part[0] !== 'cand') continue;
            [, , $name, $kind] = $part;
            if ($kind === 'void') { $role[$i] = 'void'; continue; }
            if ($kind === 'open') { $stack[] = $i; continue; }
            if ($stack !== [] && $raw[end($stack)][2] === $name) {
                $role[array_pop($stack)] = 'open';
                $role[$i] = 'close';
            }
        }

        $parts = [];
        foreach ($raw as $i => $part) {
            if ($part[0] === 'cand') {
                $part = isset($role[$i]) ? [$role[$i], $part[2]] : ['loose', $part[1]];
            }
            if ($part[0] === 'text' && $parts !== [] && $parts[count($parts) - 1][0] === 'text') {
                $parts[count($parts) - 1][1] .= $part[1];
                continue;
            }
            $parts[] = $part;
        }
        return $parts;
    }
}

if (!function_exists('qs_text_markers_at')) {
    /**
     * The listed marker written at $pos, as [its text, its name, open|close|void], or null. A closing `br` is no
     * marker: `br` stands alone.
     */
    function qs_text_markers_at(string $word, int $pos): ?array
    {
        if (($word[$pos] ?? '') !== '<') return null;
        $closing = ($word[$pos + 1] ?? '') === '/';
        $from = $pos + ($closing ? 2 : 1);
        $n = strspn($word, 'abcdefghijklmnopqrstuvwxyz', $from);
        if ($n === 0 || ($word[$from + $n] ?? '') !== '>') return null;
        $name = substr($word, $from, $n);
        $kind = QS_TEXT_MARKERS[$name] ?? null;
        if ($kind === null || ($closing && $kind !== 'pair')) return null;
        return [substr($word, $pos, $n + ($closing ? 3 : 2)), $name, $closing ? 'close' : ($kind === 'void' ? 'void' : 'open')];
    }
}

if (!function_exists('qs_text_markers_html')) {
    /**
     * A translation's word as the HTML of a text node: each working marker an element, each run of words between
     * them filled by $fill (the surface's placeholders, in its own order) and then escaped. A word with no marker
     * gives exactly htmlspecialchars($fill($word)), as a text node always did.
     *
     * $editor (the visual editor's frame only): a marker kept by a backslash, or one that does not work, is written
     * in a span saying which (`data-qs-marker="kept"` / `"loose"`), with no style: the editor's inline edit reads it
     * to write the sentence back byte for byte. A page served or built never carries one.
     */
    function qs_text_markers_html(string $word, callable $fill, bool $editor = false): string
    {
        $out = '';
        $run = '';
        $flush = static function () use (&$out, &$run, $fill): void {
            if ($run !== '') {
                $out .= htmlspecialchars($fill($run), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $run = '';
            }
        };
        foreach (qs_text_markers_parse($word) as $part) {
            switch ($part[0]) {
                case 'text':
                    $run .= $part[1];
                    break;
                case 'kept':
                case 'loose':
                    if (!$editor) { $run .= $part[1]; break; }
                    $flush();
                    $out .= '<span data-qs-marker="' . $part[0] . '">' . htmlspecialchars($part[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
                    break;
                case 'open':
                    $flush();
                    $out .= '<' . $part[1] . '>';
                    break;
                case 'close':
                    $flush();
                    $out .= '</' . $part[1] . '>';
                    break;
                case 'void':
                    $flush();
                    $out .= '<' . $part[1] . '>';
                    break;
            }
        }
        $flush();
        return $out;
    }
}

if (!function_exists('qs_text_node_html')) {
    /**
     * What a text node draws for a word: the renderer's and a built page's one call, so both draw it byte for byte.
     *
     * @param string      $word   the word: the lookup's answer, or the component preview's emulated value
     * @param string|null $key    the key looked up; when the lookup answered its missing marker, the text is the
     *                            key's, not a translation's, and takes no marker. Null: no lookup was made
     * @param bool        $plain  inside an element that cannot show formatting (TagRegistry::PLAIN_TEXT_TAGS):
     *                            the markers are removed
     * @param callable    $fill   the surface's placeholders, filled in a run of words (string => string)
     * @param bool        $editor the editor's frame (qs_text_markers_html())
     */
    function qs_text_node_html(string $word, ?string $key, bool $plain, callable $fill, bool $editor = false): string
    {
        if (!qs_text_word_is_translation($word, $key)) {
            return htmlspecialchars($fill($word), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($plain) {
            return htmlspecialchars($fill(qs_text_markers_remove($word)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return qs_text_markers_html($word, $fill, $editor);
    }
}

if (!function_exists('qs_text_word_plain')) {
    /**
     * A looked-up word where formatting cannot show (an attribute, a title, a tag, a word handed to qs.js): its
     * markers removed (qs_text_markers_remove()). $key: the key looked up; the missing marker for it is the key's
     * text, not a translation's, and comes back as it is. Null: the word is a translation's.
     */
    function qs_text_word_plain(string $word, ?string $key = null): string
    {
        return qs_text_word_is_translation($word, $key) ? qs_text_markers_remove($word) : $word;
    }
}

if (!function_exists('qs_text_word_is_translation')) {
    /**
     * Whether a looked-up word is a translation's, and so may carry markers: not when the lookup for $key answered
     * its missing marker (Translator::isMissing()), whose text is the key's. Null $key: no lookup was made.
     */
    function qs_text_word_is_translation(string $word, ?string $key): bool
    {
        if ($key === null) {
            return true;
        }
        require_once __DIR__ . '/../classes/Translator.php';
        return !Translator::isMissing($word, $key . '}');
    }
}

if (!function_exists('qs_text_markers_remove')) {
    /**
     * A translation's word where formatting cannot show: the working markers removed and the words kept, a line
     * break one space between two words (none at either end, none beside a space already there), a marker kept by
     * a backslash shown without it, one that does not work shown as written. A word with no marker comes back as
     * it is. Not escaped: the caller writes it as it writes any text.
     */
    function qs_text_markers_remove(string $word): string
    {
        $out = '';
        $space = false;
        foreach (qs_text_markers_parse($word) as $part) {
            if ($part[0] === 'void') {
                $space = $out !== '' && !ctype_space(substr($out, -1));
                continue;
            }
            if ($part[0] === 'open' || $part[0] === 'close') continue;
            $text = $part[1];
            if ($space && $text !== '' && !ctype_space($text[0])) $out .= ' ';
            $space = false;
            $out .= $text;
        }
        return $out;
    }
}
