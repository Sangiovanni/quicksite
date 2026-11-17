/**
 * media-alt.js — a picture's alt text per language: the one rule Media's page (assets.js) reads and saves it
 * by: its badge, its own `<img alt>`, the edit form.
 *
 * Media keeps a picture's `alt` as a translation key. The text of each language lives in the project's word files
 * (setTranslationKeys writes one language, deleteTranslationKeys removes one), and editAsset stores the key as the
 * picture's alt. What reads the key: a placed picture (the editor's picker copies it into the element, and the
 * renderer reads it in the page's language), `og:image:alt` (qs_social_picture_alt()) and Media's own badge.
 *
 *   - An alt shaped like a key (the renderer's test) is a key, whether or not a word file holds it yet: a page that
 *     placed it reads it as one. Its text in a language is that language's word.
 *   - Any other alt is a text written before the alt had languages: it reads the same in every language, and the
 *     first save of a change makes it a key, that text in the project's default language. A text starting with the
 *     renderer's raw marker (`__RAW__`) is a text whatever its shape, read without the marker, as the renderer reads it.
 *   - A key is made on the first save of a text, never before: `alt.` and a name from the picture's file name
 *     (lower case, letters and digits, `_` for anything else, accents dropped; shorter than three characters, a
 *     random name), checked against every word file (getTranslations: a key no page uses still exists), with a
 *     random suffix while it is taken.
 *   - A language's text emptied removes that language's word; the key and the picture's alt stay. A save writes the
 *     languages written and only them: the others stay without a text, never a copy.
 *
 * Nothing here sends a request: plan() says which writes a save makes, each with what takes it back, and run()
 * sends them through the caller's function, taking back what was written when one is refused.
 */
window.QSMediaAlt = (function () {
    'use strict';

    /** The renderer's test of a translation key (JsonToHtmlRenderer, JsonToPhpCompiler, qs_social_picture_alt()). */
    const KEY_SHAPE = /^[a-z0-9_]+(\.[a-z0-9_]+)+$/i;
    /** The group every key Media makes is in. */
    const GROUP = 'alt';
    /** A name shorter than this is no name: a random one instead. */
    const MIN_NAME = 3;
    /** The length of a random name, and of the suffix added to a name taken. */
    const RANDOM_LENGTH = 10;
    const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** The renderer's raw-text marker: a value starting with it is text, never a key, and is shown without it. */
    const RAW = '__RAW__';

    const own = (o, k) => !!o && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k);
    const clean = (s) => String(s == null ? '' : s).trim();
    const text = (s) => { const c = clean(s); return c.indexOf(RAW) === 0 ? c.slice(RAW.length).trim() : c; };

    function isKey(alt) {
        return typeof alt === 'string' && alt.trim().indexOf(RAW) !== 0 && KEY_SHAPE.test(alt.trim());
    }

    /** A file name as a key's name (`Été-2024.JPG` → `ete_2024_jpg`), or '' when it leaves fewer than three characters. */
    function nameFrom(filename) {
        const name = String(filename || '').normalize('NFD').replace(/\p{M}+/gu, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        return name.length >= MIN_NAME ? name : '';
    }

    function randomName(length) {
        const bytes = new Uint8Array(length);
        globalThis.crypto.getRandomValues(bytes);
        let out = '';
        bytes.forEach(b => { out += ALPHABET[b % ALPHABET.length]; });
        return out;
    }

    /** Whether a word tree holds a dot path at all, as a word or as a group. */
    function holds(tree, key) {
        let node = tree;
        for (const part of key.split('.')) {
            if (!own(node, part)) return false;
            node = node[part];
        }
        return true;
    }

    /**
     * A key no word file holds, for a picture: `alt.<name>`, else `alt.<name>_<random>` until free; null when none is
     * found (a hundred tries).
     *
     * @param {string} filename
     * @param {Object[]} trees every word file's tree, as getTranslations answers them
     * @param {function(number): string} [random] the random name (the probes give their own)
     */
    function freeKey(filename, trees, random) {
        const rnd = random || randomName;
        const taken = (k) => (trees || []).some(t => holds(t, k));
        const base = nameFrom(filename);
        let key = GROUP + '.' + (base || rnd(RANDOM_LENGTH));
        for (let i = 0; i < 100 && taken(key); i++) key = GROUP + '.' + (base ? base + '_' : '') + rnd(RANDOM_LENGTH);
        return taken(key) ? null : key;
    }

    /** A key's word in one word tree: '' when the tree lacks it, holds it empty, or holds a group there. */
    function wordOf(tree, key) {
        let node = tree;
        for (const part of key.split('.')) {
            if (!own(node, part)) return '';
            node = node[part];
        }
        return (typeof node === 'string' || typeof node === 'number') ? String(node).trim() : '';
    }

    /**
     * What each language says now: a key, each language's word; a text written before languages, that text in the
     * default language and nothing in the others (it is not theirs to keep once it becomes a key); none, ''.
     *
     * @param {string} alt the picture's alt
     * @param {string[]} langs the project's languages
     * @param {string} defaultLang
     * @param {Object} words {lang: tree}, the word file each language's pages read
     * @returns {Object} {lang: text}
     */
    function texts(alt, langs, defaultLang, words) {
        const a = clean(alt);
        const out = {};
        langs.forEach(l => {
            if (isKey(a)) out[l] = wordOf((words || {})[l], a);
            else out[l] = l === defaultLang ? text(a) : '';
        });
        return out;
    }

    /** The languages a picture has no alt text in, for the badge: none for a text written before languages, which shows in all. */
    function missing(alt, langs, words) {
        const a = clean(alt);
        if (a !== '' && !isKey(a)) return [];
        return langs.filter(l => a === '' || wordOf((words || {})[l], a) === '');
    }

    /** The alt text the panel shows for a picture (its own `<img alt>`): the default language's, or ''. */
    function shown(alt, defaultLang, words) {
        const a = clean(alt);
        if (a === '') return '';
        return isKey(a) ? wordOf((words || {})[defaultLang], a) : text(a);
    }

    /** {a: {b: value}} from a dot key. */
    function nested(key, value) {
        const out = {};
        const parts = key.split('.');
        let ref = out;
        parts.slice(0, -1).forEach(p => { ref[p] = {}; ref = ref[p]; });
        ref[parts[parts.length - 1]] = value;
        return out;
    }

    const set = (lang, key, text) => ({ command: 'setTranslationKeys', body: { language: lang, translations: nested(key, text) } });
    const del = (lang, key) => ({ command: 'deleteTranslationKeys', body: { language: lang, keys: [key] } });

    /**
     * The writes one save makes, in order, each with what takes it back. The words first, then editAsset: the picture
     * names a key only once the key holds a text. An existing key is edited in place; a text written before languages
     * becomes a key when an alt text changes (the default language keeps that text unless it was changed); a picture
     * with no alt gets a key when a text is written. On a one-language project the pages read default.json, so a word
     * removed goes from it too. A key made here is taken back from every file the writer touched (the language's and
     * default.json, which setTranslationKeys keeps in step).
     *
     * @param {Object} o
     * @param {string} o.alt          the picture's alt now ('' for none)
     * @param {string} o.filename     the picture's file name (what editAsset names)
     * @param {string[]} o.langs      the project's languages
     * @param {string} o.defaultLang
     * @param {boolean} o.oneLanguage true on a one-language project
     * @param {Object} o.stored       {lang: text}, as texts() reads them
     * @param {Object} o.drafts       {lang: text}, what the form holds for each language it showed
     * @param {?string} o.newKey      the key to make when one is needed (freeKey() on a fresh read), or null
     * @param {?{from: string, to: string}} o.description the Description field
     * @returns {{steps: Object[], changed: string[], key: ?string, alt: (string|undefined), needsKey: boolean}}
     *          steps: [{command, body, lang, undo: [{command, body}]}]; alt: the picture's new alt, undefined when it
     *          stays; needsKey: a key is needed and o.newKey was null (nothing can be saved)
     */
    function plan(o) {
        const alt = clean(o.alt);
        const kind = alt === '' ? 'none' : (isKey(alt) ? 'key' : 'text');
        const drafts = o.drafts || {};
        const stored = o.stored || {};
        const changed = o.langs.filter(l => own(drafts, l) && clean(drafts[l]) !== clean(stored[l]));
        const final = {};
        o.langs.forEach(l => { final[l] = changed.indexOf(l) !== -1 ? clean(drafts[l]) : clean(stored[l]); });
        const removal = (lang, key) => (o.oneLanguage ? [lang, 'default'] : [lang]).map(l => del(l, key));
        const steps = [];
        let key = kind === 'key' ? alt : null;
        let newAlt;
        let needsKey = false;

        if (kind === 'key') {
            changed.forEach(l => {
                const was = clean(stored[l]);
                if (final[l] !== '') {
                    steps.push(Object.assign(set(l, key, final[l]), { lang: l, undo: was !== '' ? [set(l, key, was)] : removal(l, key) }));
                } else {
                    removal(l, key).forEach(d => steps.push(Object.assign(d, { lang: l, undo: [set(d.body.language, key, was)] })));
                }
            });
        } else if (changed.length) {
            const write = o.langs.filter(l => final[l] !== '');
            if (write.length === 0) {
                // A text written before languages, emptied: the picture has no alt any more.
                if (kind === 'text') newAlt = '';
            } else if (!o.newKey) {
                needsKey = true;
            } else {
                key = o.newKey;
                write.forEach(l => steps.push(Object.assign(set(l, key, final[l]), { lang: l, undo: [del(l, key), del('default', key)] })));
                newAlt = key;
            }
        }

        const body = { filename: o.filename };
        if (newAlt !== undefined) body.alt = newAlt;
        if (o.description && clean(o.description.to) !== clean(o.description.from)) body.description = clean(o.description.to);
        if (!needsKey && Object.keys(body).length > 1) steps.push({ command: 'editAsset', body: body, lang: null, undo: [] });
        return { steps: needsKey ? [] : steps, changed: changed, key: key, alt: newAlt, needsKey: needsKey };
    }

    /** Whether a write did what it was for: a removal of a word a file did not hold has nothing to do, which is fine. */
    function succeeded(call, res) {
        if (res && res.ok) return true;
        return call.command === 'deleteTranslationKeys' && !!res && res.status === 404;
    }

    /**
     * Send a plan's steps in order through `api(command, body)` (a promise of {ok, status, data}). The first refusal
     * stops the save and takes back every step already done, newest first.
     *
     * @returns {Promise<{ok: boolean, failed: ?Object, response: ?Object, notTakenBack: Object[]}>}
     *          failed: the step refused; notTakenBack: the steps whose taking back was itself refused
     */
    async function run(steps, api) {
        const done = [];
        for (const step of steps) {
            let res = null;
            try { res = await api(step.command, step.body); } catch (e) { res = { ok: false, status: 0, data: { message: e.message } }; }
            if (!succeeded(step, res)) {
                const notTakenBack = [];
                for (const prior of done.slice().reverse()) {
                    for (const back of prior.undo) {
                        let r = null;
                        try { r = await api(back.command, back.body); } catch (e) { r = null; }
                        if (!succeeded(back, r)) { notTakenBack.push(prior); break; }
                    }
                }
                return { ok: false, failed: step, response: res, notTakenBack: notTakenBack };
            }
            done.push(step);
        }
        return { ok: true, failed: null, response: null, notTakenBack: [] };
    }

    return { KEY_SHAPE, GROUP, isKey, nameFrom, freeKey, holds, wordOf, texts, missing, shown, nested, plan, run };
})();
