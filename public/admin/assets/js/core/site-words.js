/**
 * QSSiteWords — the panel's one list of a site's words: the site's languages, every language's words, and, when a
 * surface asks for them, which keys the site uses and each key's state per language.
 *
 * Every surface of the panel that offers a site's keys or shows its words reads them here, and every write of a
 * site's words updates it. The surfaces are listed in MAINTAINING.md (the site's words row); a new one joins that
 * census.
 *
 * READING (the storage-key picker's rule, for its registry):
 *  - a surface reads when it opens (read()); one cache for the page, for the project edited; reads in flight are
 *    shared;
 *  - a failed read keeps the last good list (null before the first answer), and the surface says it could not read;
 *  - a read that started before a write answered never replaces what the write put in the list: its answer is
 *    dropped and the list read again.
 * read() reads the languages (getLangList) and every language's words (getTranslations). read({ states: true })
 * reads as well where the site names each key (getTranslationKeys) and each key's state per language, by the rule
 * of the panel's helper translation-keys-grouped (validateTranslations and getUnusedTranslationKeys): unset, a
 * key the site needs with no word in that language; unused, a key the language holds that nothing on the site
 * names; used, every other key the language holds.
 *
 * WRITING: write(), remove(), create() and the language commands check every answer and answer {ok, message, …};
 * a refusal changes nothing here and carries the server's words (serverWords()). create() is the panel's one
 * Create: it writes the word in the language being edited only, never over a text any language holds, and a word
 * is required.
 *
 * THE EVENT: a write of a site's words answered for the project edited, made here or by any other script of the
 * page through the panel's API client (api.js announces each answer as quicksite:command-answered), marks the
 * list stale and announces `quicksite:translations-changed` on window: {project, command, language?, keys?,
 * languagesChanged, remote?}. Another tab of this browser on the same project hears it too (BroadcastChannel), with `remote: true`.
 * A surface that is open reads again and redraws: nothing waits for a reload.
 *
 * THE EDITING LANGUAGE: one per project in this browser (QuickSiteStorageKeys.editorLanguage, ':<project>'
 * appended): the editor's toolbar, its Translation tool and the Translations page start on it. A language the
 * project no longer has reads as the project's default.
 *
 * Loaded in the layout's <head>, after storage-keys.js and dom.js: every page has it, and a page's script may
 * name it as it parses. It calls the API client only when asked to read or write.
 */
window.QSSiteWords = (function () {
    'use strict';

    const EVENT = 'quicksite:translations-changed';
    const CHANNEL = 'quicksite-site-words';
    /** The mark this module's own requests carry (api.js passes it back in its answer event). */
    const ORIGIN = 'site-words';

    /**
     * Every command that writes a site's words or changes its languages: the engine's writers of the word files
     * (MAINTAINING.md, the shared translation writer's census) and the four language commands. An answered one
     * announces the event, whichever script of the page sent it.
     */
    const WORD_COMMANDS = Object.freeze([
        'addComplexElement', 'addComponentToNode', 'addLang', 'addStorageItem', 'cleanOrphanTranslations',
        'deleteCollectedDatum', 'deleteLang', 'deleteNode', 'deleteStorageItem', 'deleteTranslationKeys',
        'duplicateNode', 'editNode', 'editStorageItem', 'editTitle', 'generateConsentLayer', 'generateCookiePolicy',
        'generatePrivacyPolicy', 'importStructureTranslations', 'insertSnippet', 'setCollectedDatum',
        'setDefaultLang', 'setMultilingual', 'setPrivacyDescLang', 'setSocialPreview', 'setStorageDescLang',
        'setTranslationKeys',
    ]);
    const LANGUAGE_COMMANDS = Object.freeze(['addLang', 'deleteLang', 'setDefaultLang', 'setMultilingual']);

    // -------- the cache --------------------------------------------------------------------------------------
    let _project = null;      // the project the cache holds
    let _list = null;         // see _readList(); null until a read answers
    let _states = null;       // see _readStates(); null until a read with states answers
    let _stale = true;        // a write answered since the list was read
    let _statesStale = true;
    let _reading = null;      // the list's read in flight
    let _readingStates = null;
    let _generation = 0;      // a write answered makes an older read's answer out of date
    let _failed = false;      // the last read of the list failed (the list offered is an older one, or none)
    let _failedWords = '';    // and why, in the server's words

    function project() {
        const api = window.QuickSiteAPI;
        return (api && typeof api.getCurrentProject === 'function' ? api.getCurrentProject() : null)
            || (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.currentProject) || null;
    }

    /** The cache follows the project edited: another project's words are never offered. */
    function _followProject() {
        const p = project();
        if (p !== _project) {
            _project = p;
            _list = null;
            _states = null;
            _stale = true;
            _statesStale = true;
            _reading = null;
            _readingStates = null;
            _failed = false;
            _generation++;
        }
        return p;
    }

    function _request(command, method, body, urlParams) {
        return window.QuickSiteAdmin.apiRequest(command, method, body || null, urlParams || [], {}, { origin: ORIGIN });
    }

    /** The payload of an answer, or null. */
    function _payload(res) {
        return res && res.ok && res.data && res.data.data && typeof res.data.data === 'object' ? res.data.data : null;
    }

    // -------- reading ----------------------------------------------------------------------------------------

    /**
     * The languages and the words. `languages` are the files the pages read: each language's on a multilingual
     * project, `default` alone otherwise (default.json, as the editor's Translation tool has always shown it).
     */
    async function _readList() {
        const [langRes, wordRes] = await Promise.all([_request('getLangList', 'GET'), _request('getTranslations', 'GET')]);
        const lang = _payload(langRes);
        if (!lang || !Array.isArray(lang.languages)) throw new Error(serverWords(langRes));
        // getTranslations answers 404 when the project has no word file yet: no word, not a failure.
        const noFile = wordRes && wordRes.status === 404;
        const words = _payload(wordRes);
        if (!noFile && (!words || typeof words.translations !== 'object' || words.translations === null)) {
            throw new Error(serverWords(wordRes));
        }
        const multilingual = lang.multilingual_enabled === true;
        const codes = lang.languages.map(String);
        const defaultLanguage = String(lang.default_language || codes[0] || '');
        const languages = multilingual ? codes.slice() : ['default'];
        const trees = (words && words.translations) || {};
        const out = {};
        const files = {};
        languages.forEach(function (l) {
            const tree = trees[l];
            files[l] = !!(tree && typeof tree === 'object');
            out[l] = files[l] && !Array.isArray(tree) ? tree : {};
        });
        return {
            project: _project,
            multilingual: multilingual,
            defaultLanguage: defaultLanguage,
            codes: codes,
            names: (lang.language_names && typeof lang.language_names === 'object') ? lang.language_names : {},
            languages: languages,
            words: out,
            files: files,
        };
    }

    /** Where the site names each key, and each language's states: the helper translation-keys-grouped's rule. */
    async function _readStates(list) {
        const one = list.multilingual ? [] : ['default'];
        const [keysRes, validRes, unusedRes] = await Promise.all([
            _request('getTranslationKeys', 'GET'),
            _request('validateTranslations', 'GET', null, one),
            _request('getUnusedTranslationKeys', 'GET', null, one),
        ]);
        const keys = _payload(keysRes);
        const valid = _payload(validRes);
        const unused = _payload(unusedRes);
        if (!keys || !valid || !unused) throw new Error(serverWords(!keys ? keysRes : (!valid ? validRes : unusedRes)));
        const sources = {};
        Object.keys(keys.keys_by_source || {}).forEach(function (source) {
            const v = keys.keys_by_source[source];
            sources[source] = Array.isArray(v) ? v.map(String) : (v && typeof v === 'object' ? Object.values(v).map(String) : []);
        });
        const byLanguage = {};
        list.languages.forEach(function (l) {
            const missing = ((valid.validation_results || {})[l] || {}).missing_keys || [];
            const notUsed = ((unused.results || {})[l] || {}).unused_keys || [];
            const unset = missing.map(String);
            const unsetSet = new Set(unset);
            const unusedSet = new Set(notUsed.map(String));
            const used = [];
            const unusedKeys = [];
            leafKeys(list.words[l]).forEach(function (k) {
                if (unsetSet.has(k)) return;
                if (unusedSet.has(k)) unusedKeys.push(k);
                else used.push(k);
            });
            byLanguage[l] = { used: used, unset: unset, unused: unusedKeys };
        });
        return { sources: sources, siteKeys: Array.isArray(keys.all_keys) ? keys.all_keys.map(String) : [], byLanguage: byLanguage };
    }

    /**
     * The list, read when a surface opens. Resolves with the list (the last good one when a read fails), or null
     * when no read has ever answered. opts.states: the states too. opts.fresh: read again even when nothing was
     * written since (a surface that must not trust another tab's silence, such as Create).
     */
    function read(opts) {
        opts = opts || {};
        _followProject();
        if (!_project) return Promise.resolve(null);
        const wantList = _stale || !_list || opts.fresh;
        const listDone = wantList ? _readListShared() : Promise.resolve(_list);
        if (!opts.states) return listDone;
        return listDone.then(function (list) {
            if (!list) return list;
            if (!_statesStale && _states && !opts.fresh && !wantList) return list;
            return _readStatesShared(list).then(function () { return _list; });
        });
    }

    function _readListShared() {
        if (_reading) return _reading;
        const generation = _generation;
        const reading = Promise.resolve()
            .then(_readList)
            .then(function (list) {
                if (generation !== _generation) return null;   // a write answered meanwhile: read again below
                _list = list;
                _stale = false;
                _failed = false;
                _failedWords = '';
                return list;
            })
            .catch(function (err) {
                console.warn('[QSSiteWords] could not read the site\'s words:', err && err.message ? err.message : err);
                _failed = true;
                _failedWords = err && err.message ? String(err.message) : '';
                return undefined;
            })
            .then(function (result) {
                if (_reading === reading) _reading = null;
                if (result === null) return _readListShared();
                return _list;
            });
        _reading = reading;
        return reading;
    }

    function _readStatesShared(list) {
        if (_readingStates) return _readingStates;
        const generation = _generation;
        const reading = Promise.resolve()
            .then(function () { return _readStates(list); })
            .then(function (states) {
                if (generation !== _generation) return null;
                _states = states;
                _statesStale = false;
                return states;
            })
            .catch(function (err) {
                console.warn('[QSSiteWords] could not read the keys\' states:', err && err.message ? err.message : err);
                return undefined;
            })
            .then(function (result) {
                if (_readingStates === reading) _readingStates = null;
                if (result === null) {
                    return _readListShared().then(function (fresh) { return fresh ? _readStatesShared(fresh) : _states; });
                }
                return _states;
            });
        _readingStates = reading;
        return reading;
    }

    /** The list as it stands, without reading (null before the first answer). */
    function current() {
        _followProject();
        return _list;
    }

    /** The states as they stand (null until a read with states answered). */
    function currentStates() {
        _followProject();
        return _states;
    }

    /** Whether the last read of the list failed: what read() answered is an older list, or null. */
    function readFailed() {
        return _failed;
    }

    /** Why the last read failed, in the server's words ('' when it did not). */
    function readError() {
        return _failed ? _failedWords : '';
    }

    // -------- reading a list ---------------------------------------------------------------------------------

    /** Every key a word tree holds as a word (a leaf, empty or not), in dot notation. */
    function leafKeys(tree, prefix, out) {
        out = out || [];
        if (!tree || typeof tree !== 'object' || Array.isArray(tree)) return out;
        Object.keys(tree).forEach(function (k) {
            const path = prefix ? prefix + '.' + k : k;
            const v = tree[k];
            if (v && typeof v === 'object' && !Array.isArray(v)) leafKeys(v, path, out);
            else out.push(path);
        });
        return out;
    }

    /** What a tree holds at a key: {kind: 'word', word} | {kind: 'group'} | {kind: 'none'} | {kind: 'underWord'}. */
    function _at(tree, key) {
        let cur = tree;
        const parts = String(key).split('.');
        for (let i = 0; i < parts.length; i++) {
            if (cur === null || typeof cur !== 'object' || Array.isArray(cur)) return { kind: 'underWord' };
            if (!Object.prototype.hasOwnProperty.call(cur, parts[i])) return { kind: 'none' };
            cur = cur[parts[i]];
        }
        if (cur !== null && typeof cur === 'object' && !Array.isArray(cur)) return { kind: 'group' };
        return { kind: 'word', word: cur === null || cur === undefined ? '' : String(cur) };
    }

    /** A language's word for a key as stored (markers and placeholders as written), or null when it holds none. */
    function word(list, language, key) {
        if (!list || !list.words || !list.words[language]) return null;
        const at = _at(list.words[language], key);
        return at.kind === 'word' ? at.word : null;
    }

    /** Every key any language holds as a word, and every key the site uses (states read), sorted. */
    function keys(list, states) {
        const all = new Set();
        if (list) list.languages.forEach(function (l) { leafKeys(list.words[l]).forEach(function (k) { all.add(k); }); });
        if (states && Array.isArray(states.siteKeys)) states.siteKeys.forEach(function (k) { all.add(k); });
        return Array.from(all).sort();
    }

    /** The languages holding a text (a word that is not empty) at a key. */
    function languagesWithText(list, key) {
        if (!list) return [];
        return list.languages.filter(function (l) {
            const w = word(list, l, key);
            return w !== null && w !== '';
        });
    }

    /** A key's state in a language, or null when the language neither holds nor needs it. */
    function stateOf(states, language, key) {
        const s = states && states.byLanguage && states.byLanguage[language];
        if (!s) return null;
        if (s.unset.indexOf(key) !== -1) return 'unset';
        if (s.unused.indexOf(key) !== -1) return 'unused';
        if (s.used.indexOf(key) !== -1) return 'used';
        return null;
    }

    /**
     * The sources where the site names keys, grouped as the scope pickers show them: the pages (a page under its
     * route), the layout (menu, footer), the components (component:<name>) and the site's own (site:<name>).
     * Returns [{group: 'pages'|'layout'|'components'|'site', sources: [{id, name}]}], empty groups left out.
     */
    function sourceGroups(sources) {
        const groups = { pages: [], layout: [], components: [], site: [] };
        Object.keys(sources || {}).sort().forEach(function (id) {
            if (id.indexOf('component:') === 0) groups.components.push({ id: id, name: id.slice('component:'.length) });
            else if (id.indexOf('site:') === 0) groups.site.push({ id: id, name: id.slice('site:'.length) });
            else if (id === 'menu' || id === 'footer') groups.layout.push({ id: id, name: id });
            else groups.pages.push({ id: id, name: id });
        });
        return ['pages', 'layout', 'components', 'site']
            .filter(function (g) { return groups[g].length > 0; })
            .map(function (g) { return { group: g, sources: groups[g] }; });
    }

    // -------- the answers ------------------------------------------------------------------------------------

    /**
     * The server's words for a refused answer: its message, then each error's own words (a clash's suggestion, a
     * hint, a reason), as the writer answers a refusal. A failure that never reached the server says so.
     */
    function serverWords(res) {
        const envelope = (res && res.data) || {};
        const parts = [];
        if (typeof envelope.message === 'string' && envelope.message) parts.push(envelope.message);
        (Array.isArray(envelope.errors) ? envelope.errors : []).forEach(function (e) {
            if (!e || typeof e !== 'object') return;
            const said = e.suggestion || e.hint || (e.key && e.reason ? e.key + ': ' + e.reason : '');
            if (typeof said === 'string' && said && parts.indexOf(said) === -1) parts.push(said);
        });
        if (parts.length === 0 && typeof envelope.error === 'string' && envelope.error) parts.push(envelope.error);
        if (parts.length === 0) parts.push('HTTP ' + (res && res.status !== undefined ? res.status : '?'));
        return parts.join(' — ');
    }

    /** {a: {b: 'x'}} from 'a.b' and 'x', for setTranslationKeys. */
    function nested(words) {
        const out = {};
        Object.keys(words).forEach(function (key) {
            const parts = String(key).split('.');
            let cur = out;
            for (let i = 0; i < parts.length - 1; i++) {
                if (!cur[parts[i]] || typeof cur[parts[i]] !== 'object') cur[parts[i]] = {};
                cur = cur[parts[i]];
            }
            cur[parts[parts.length - 1]] = words[key];
        });
        return out;
    }

    /**
     * The language a write is sent in for a code: the code itself on a multilingual site (the default language's
     * where the site has no such language); on a one-language site its language's code, which the shared writer
     * writes and copies into default.json, the file the pages read, so the two never part.
     */
    function writeLanguage(list, code) {
        if (!list) return code;
        if (list.multilingual) return list.languages.indexOf(code) !== -1 ? code : list.defaultLanguage;
        return list.defaultLanguage || 'default';
    }

    /** Where a list holds a code's words: the code on a multilingual site, `default` on a one-language one. */
    function shownLanguage(list, code) {
        if (!list) return code;
        if (!list.multilingual) return 'default';
        return list.languages.indexOf(code) !== -1 ? code : list.defaultLanguage;
    }

    function _patchWrite(code, words) {
        const language = shownLanguage(_list, code);
        if (!_list || !_list.words[language]) return;
        Object.keys(words).forEach(function (key) {
            const parts = String(key).split('.');
            let cur = _list.words[language];
            for (let i = 0; i < parts.length - 1; i++) {
                if (!cur[parts[i]] || typeof cur[parts[i]] !== 'object' || Array.isArray(cur[parts[i]])) cur[parts[i]] = {};
                cur = cur[parts[i]];
            }
            cur[parts[parts.length - 1]] = words[key];
        });
    }

    function _patchRemove(code, removed) {
        const language = shownLanguage(_list, code);
        if (!_list || !_list.words[language]) return;
        removed.forEach(function (key) {
            const parts = String(key).split('.');
            const trail = [];
            let cur = _list.words[language];
            for (let i = 0; i < parts.length - 1; i++) {
                if (!cur || typeof cur !== 'object') return;
                trail.push([cur, parts[i]]);
                cur = cur[parts[i]];
            }
            if (!cur || typeof cur !== 'object') return;
            delete cur[parts[parts.length - 1]];
            // A group the removal emptied goes too, as deleteTranslationKeys removes it.
            for (let i = trail.length - 1; i >= 0; i--) {
                const parent = trail[i][0], name = trail[i][1];
                if (parent[name] && typeof parent[name] === 'object' && Object.keys(parent[name]).length === 0) delete parent[name];
                else break;
            }
        });
    }

    // -------- the event ----------------------------------------------------------------------------------------

    let _channel = null;
    try {
        if (typeof BroadcastChannel === 'function') _channel = new BroadcastChannel(CHANNEL);
    } catch (e) {
        _channel = null;
    }

    /** Mark the list stale (what was read stays offered until the next read answers) and tell the page and its other tabs. */
    function _announce(detail, remote) {
        _generation++;
        _stale = true;
        _statesStale = true;
        const d = Object.assign({ project: _project || project() }, detail, remote ? { remote: true } : {});
        window.dispatchEvent(new CustomEvent(EVENT, { detail: d }));
        if (!remote && _channel) {
            try { _channel.postMessage(d); } catch (e) { /* a closed channel: this tab was told already */ }
        }
    }

    /**
     * Tell the list a site's words changed by a way it cannot see (a workflow's phase run on the server, a page that
     * rebuilt what it shows): it reads again when next asked, and the open surfaces redraw.
     */
    function changed(detail) {
        _followProject();
        _announce(Object.assign({ command: null }, detail || {}), false);
    }

    if (_channel) {
        _channel.onmessage = function (e) {
            const d = e && e.data;
            if (!d || typeof d !== 'object' || d.project !== project()) return;
            _followProject();
            _announce(d, true);
        };
    }

    // Every answer of the panel's API client: a word command answered for the project edited, sent by another
    // script of the page, makes the list stale here and is announced (this module's own writes announce
    // themselves, with what they wrote).
    window.addEventListener('quicksite:command-answered', function (e) {
        const d = e && e.detail;
        if (!d || !d.ok || d.origin === ORIGIN || WORD_COMMANDS.indexOf(d.command) === -1) return;
        if (d.project && d.project !== project()) return;
        _followProject();
        _announce({ command: d.command, languagesChanged: LANGUAGE_COMMANDS.indexOf(d.command) !== -1 }, false);
    });

    // -------- writing ------------------------------------------------------------------------------------------

    /**
     * Write words in one language: {key: word}. Answers {ok, message?, response}; a refusal changes nothing.
     */
    async function write(code, words) {
        _followProject();
        const language = writeLanguage(_list, code);
        const res = await _request('setTranslationKeys', 'POST', { language: language, translations: nested(words) });
        if (!res || !res.ok) return { ok: false, message: serverWords(res), response: res };
        // The words just written stay offered while the list reads again: an older read's answer is dropped.
        _patchWrite(language, words);
        _announce({ command: 'setTranslationKeys', language: language, keys: Object.keys(words), languagesChanged: false }, false);
        return { ok: true, language: language, response: res };
    }

    /**
     * Remove keys from each language given, one language after another. A language that does not hold them
     * (404) is not a failure. Answers {ok, failures: [{language, message}]}; every language answered ok is
     * removed from the list.
     */
    async function remove(codes, removed) {
        _followProject();
        const failures = [];
        const done = [];
        // A one-language site's key leaves default.json, which the pages read, and its language's file alike.
        const languages = [];
        codes.forEach(function (code) {
            const sent = _list && !_list.multilingual ? [writeLanguage(_list, code), 'default'] : [code];
            sent.forEach(function (l) { if (languages.indexOf(l) === -1) languages.push(l); });
        });
        for (const language of languages) {
            const res = await _request('deleteTranslationKeys', 'POST', { language: language, keys: removed });
            if (res && (res.ok || res.status === 404)) {
                done.push(language);
                _patchRemove(language, removed);
            } else {
                failures.push({ language: language, message: serverWords(res) });
            }
        }
        if (done.length) _announce({ command: 'deleteTranslationKeys', language: done.join(','), keys: removed.slice(), languagesChanged: false }, false);
        return { ok: failures.length === 0, failures: failures, done: done };
    }

    /** Whether a key has the shape the word files take: dotted names, none empty, no space and no placeholder. */
    function keyIsValid(key) {
        if (typeof key !== 'string' || key === '' || key.length > 200) return false;
        if (/\s/.test(key) || key.indexOf('{{') !== -1 || key.indexOf('}}') !== -1) return false;
        return key.split('.').every(function (part) { return part !== ''; });
    }

    /**
     * The panel's one Create: a new key with its word, in the language being edited and nothing in the others (a
     * gap each shows). It reads the list again first, so a key made since in another tab is seen.
     *   {ok: true, key}
     *   {ok: false, reason: 'keyInvalid' | 'wordRequired' | 'exists' | 'clash' | 'unread' | 'refused', message?, languages?}
     * 'exists': a language holds a text at the key (`languages`), so the caller offers the key as it is; 'clash': a
     * language holds a group at the key, or a word where the key would need a group.
     */
    async function create(key, text, language) {
        key = typeof key === 'string' ? key.trim() : '';
        const typed = typeof text === 'string' ? text.trim() : '';
        if (!keyIsValid(key)) return { ok: false, reason: 'keyInvalid' };
        if (typed === '') return { ok: false, reason: 'wordRequired' };
        const list = await read({ fresh: true });
        if (!list || _failed) return { ok: false, reason: 'unread' };
        const holding = languagesWithText(list, key);
        if (holding.length) return { ok: false, reason: 'exists', languages: holding };
        const clashing = list.languages.filter(function (l) {
            const k = _at(list.words[l], key).kind;
            return k === 'group' || k === 'underWord';
        });
        if (clashing.length) return { ok: false, reason: 'clash', languages: clashing };
        const out = {};
        out[key] = typed;
        const done = await write(language, out);
        if (!done.ok) return { ok: false, reason: 'refused', message: done.message };
        return { ok: true, key: key, language: done.language };
    }

    // -------- the languages ------------------------------------------------------------------------------------

    async function _languageCommand(command, body) {
        _followProject();
        const res = await _request(command, 'POST', body);
        if (!res || !res.ok) return { ok: false, message: serverWords(res), response: res };
        _announce({ command: command, languagesChanged: true }, false);
        return { ok: true, response: res };
    }

    /**
     * Add a language, its words copied from the default language's (addLang). A one-language site becomes
     * multilingual first (setMultilingual, which carries default.json's words into the default language's file,
     * so the copy holds them): addLang alone would copy a file the pages never read. Answers {ok, message?,
     * switched}: `switched` when the site is multilingual now, even if the language was then refused.
     */
    async function addLanguage(code) {
        const list = current() || await read();
        let switched = false;
        if (list && !list.multilingual) {
            const turned = await _languageCommand('setMultilingual', { enabled: true });
            if (!turned.ok) return { ok: false, message: turned.message, switched: false };
            switched = true;
        }
        const added = await _languageCommand('addLang', { code: code });
        return Object.assign(added, { switched: switched });
    }

    /** Remove a language: deleteLang deletes its file and keeps no copy, and refuses the default language. */
    function removeLanguage(code) {
        return _languageCommand('deleteLang', { code: code });
    }

    function setDefaultLanguage(code) {
        return _languageCommand('setDefaultLang', { lang: code });
    }

    // -------- the editing language ------------------------------------------------------------------------------

    function _languageStoreKey(forProject) {
        const keyName = window.QuickSiteStorageKeys && window.QuickSiteStorageKeys.editorLanguage;
        const p = forProject || project();
        return keyName && p ? keyName + ':' + p : null;
    }

    /** The language code remembered for the project, as stored (null when none). */
    function rememberedCode(forProject) {
        const k = _languageStoreKey(forProject);
        if (!k) return null;
        try {
            const v = localStorage.getItem(k);
            return v ? String(v) : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * The editing language among `codes` (the project's language codes): the one remembered when the project still
     * has it, else `fallback` (its default). `forProject` names the project for a page script that asks before the
     * layout has named it (the editor, as it parses).
     */
    function editingLanguage(codes, fallback, forProject) {
        const stored = rememberedCode(forProject);
        return stored && Array.isArray(codes) && codes.indexOf(stored) !== -1 ? stored : fallback;
    }

    /** Remember the editing language for the project. */
    function rememberLanguage(code) {
        const k = _languageStoreKey();
        if (!k || !code) return;
        try {
            localStorage.setItem(k, String(code));
        } catch (e) {
            // Storage refused (a private window): the language is not remembered, nothing else changes.
        }
    }


    return Object.freeze({
        EVENT: EVENT,
        WORD_COMMANDS: WORD_COMMANDS,
        LANGUAGE_COMMANDS: LANGUAGE_COMMANDS,
        read: read,
        current: current,
        currentStates: currentStates,
        readFailed: readFailed,
        readError: readError,
        changed: changed,
        leafKeys: leafKeys,
        word: word,
        keys: keys,
        languagesWithText: languagesWithText,
        stateOf: stateOf,
        sourceGroups: sourceGroups,
        serverWords: serverWords,
        nested: nested,
        write: write,
        remove: remove,
        keyIsValid: keyIsValid,
        create: create,
        addLanguage: addLanguage,
        removeLanguage: removeLanguage,
        setDefaultLanguage: setDefaultLanguage,
        rememberedCode: rememberedCode,
        editingLanguage: editingLanguage,
        rememberLanguage: rememberLanguage,
        writeLanguage: writeLanguage,
        shownLanguage: shownLanguage,
    });
})();
