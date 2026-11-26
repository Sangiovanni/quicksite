/**
 * QSPanelWords — the one way a panel script reads a panel word, beside QSSiteWords (a site's words).
 *
 * THE WORDS COME IN ONE WAY: a page hands its scripts whole sections of the panel's word files, each at its own
 * path. Its template writes `<?= __adminWords(['preview', 'complexWizard']) ?>` (AdminTranslation.php) before its
 * scripts, which adds them here through qs_inline_script_json(), the encoder that writes "<" escaped; layout.php
 * hands `common` and `nav` on every page. A path may be a part of a section ('complexWizard.storageKeyPicker'). The
 * words are those of the panel's language, English laid under a word that language lacks (AdminTranslation::layOver()).
 *
 * A SCRIPT READS A WORD BY ITS KEY'S WHOLE PATH, never by a name of its own:
 *   t(path, params)         the word, its {name} parts filled from params; a path that holds no word (missing, or a
 *                           section) answers the path itself, so a missing word shows on screen and a scan finds it
 *   count(path, n, params)  a counted word: <path>.one or <path>.other, chosen by the browser's Intl.PluralRules in the
 *                           panel's language (French counts 0 as one, English as other); another category a language's
 *                           rules name (French "many" for a million) is used where its file writes it, else <path>.other,
 *                           the category every count falls in that a language does not set apart; {count} is n,
 *                           formatted in the panel's language
 *   parts(path, params)     the word cut at its {name} parts, each given its value as it is (a string or an element),
 *                           for a sentence that holds an element (a version in a <code>)
 *   has(path)               whether a word is there: a catalog shows its own text where it names no key (a person's own
 *                           snippet)
 *   number(n, options)      a number in the panel's language (Intl.NumberFormat)
 *   date(value, options)    a date and time in the panel's language (Date#toLocaleString)
 *   list(items, type)       items joined the panel language's way (Intl.ListFormat): 'conjunction' (the default) or 'unit'
 *   lang                    the panel's language: <html lang>, which layout.php writes
 *   add(tree)               called only by the blocks __adminWords() writes
 * There is no fallback: a word a script reads and nothing hands shows its path.
 *
 * Loaded first in the layout's <head>: every page has it before any script names it.
 */
window.QSPanelWords = (function () {
    'use strict';

    const words = {};
    const lang = document.documentElement.lang || 'en';
    const own = Object.prototype.hasOwnProperty;
    let rules = null;

    function isSection(value) {
        return value !== null && typeof value === 'object';
    }

    /** Lay a handed tree into the words: sections merge, a word replaces what was there. */
    function merge(into, from) {
        Object.keys(from).forEach(function (key) {
            const value = from[key];
            if (isSection(value) && isSection(into[key])) {
                merge(into[key], value);
            } else {
                into[key] = isSection(value) ? merge({}, value) : value;
            }
        });
        return into;
    }

    function add(tree) {
        if (isSection(tree)) merge(words, tree);
    }

    /** The word at a path, or undefined when the path ends nowhere or at a section. */
    function lookup(path) {
        let node = words;
        const parts = String(path).split('.');
        for (let i = 0; i < parts.length; i++) {
            if (!isSection(node) || !own.call(node, parts[i])) return undefined;
            node = node[parts[i]];
        }
        return typeof node === 'string' ? node : undefined;
    }

    /** One pass over the word: a parameter's value is never read again, so a value holding `{name}` stays as it is. */
    function fill(word, params) {
        if (!params) return word;
        return word.replace(/\{([A-Za-z0-9_]+)\}/g, function (mark, name) {
            return own.call(params, name) ? String(params[name]) : mark;
        });
    }

    function t(path, params) {
        const word = lookup(path);
        return word === undefined ? String(path) : fill(word, params);
    }

    function has(path) {
        return lookup(path) !== undefined;
    }

    /**
     * The word cut at its {name} parts, each part given its value as it is: a string, or an element (a version drawn in
     * a <code>, a count in bold), so a sentence holding an element stays one word. Returns a list of strings and the
     * values, for QSDom.el's children; a part with no value stays as written; a path that holds no word, the path.
     */
    function parts(path, params) {
        const word = lookup(path);
        if (word === undefined) return [String(path)];
        const out = [];
        const re = /\{([A-Za-z0-9_]+)\}/g;
        let last = 0;
        let mark;
        while ((mark = re.exec(word)) !== null) {
            if (!params || !own.call(params, mark[1])) continue;
            if (mark.index > last) out.push(word.slice(last, mark.index));
            const value = params[mark[1]];
            out.push(typeof value === 'object' && value !== null ? value : String(value));
            last = mark.index + mark[0].length;
        }
        if (last < word.length) out.push(word.slice(last));
        return out;
    }

    function number(n, options) {
        return new Intl.NumberFormat(lang, options).format(n);
    }

    function date(value, options) {
        return new Date(value).toLocaleString(lang, options);
    }

    /** Items joined the panel language's way (Intl.ListFormat): 'conjunction' (a, b and c, the default) or 'unit' (a, b, c). */
    function list(items, type) {
        return new Intl.ListFormat(lang, { style: 'long', type: type || 'conjunction' }).format(items.map(String));
    }

    function count(path, n, params) {
        if (rules === null) rules = new Intl.PluralRules(lang);
        const category = path + '.' + rules.select(n);
        const chosen = has(category) ? category : path + '.other';
        return t(chosen, Object.assign({ count: number(n) }, params));
    }

    return { t: t, count: count, parts: parts, has: has, number: number, date: date, list: list, lang: lang, add: add };
})();
