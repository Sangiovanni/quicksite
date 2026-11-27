/**
 * The Translations page (Site → Translations): a site's words in every language, side by side, and its languages.
 *
 * It reads and writes through the panel's one list of a site's words, QSSiteWords (js/core/site-words.js): the
 * reading the editor's Translation tool takes, the same writes, the same Create. It reads when it opens, again when
 * the list announces a change (quicksite:translations-changed: a write on this page, in the editor of another tab, a
 * workflow, the console) and when the tab comes back into view; a write in progress is never redrawn over.
 *
 * Every control that writes is drawn only for a role that can run its command (translation.write), after the
 * panel's permissions load: a viewer sees the words and the languages, and nothing to press. The language switch's
 * offer, after an add or a removal, sends content commands (editStructure, deleteNode) and is drawn only for a role
 * that can run them and the reads they need.
 *
 * Template: secure/admin/templates/pages/translations.php (the static structure, and the `translations` section it
 * hands). Every dynamic part is built here with QSDom.el and named _render* helpers, each returning one Element.
 */
(function () {
    'use strict';

    const el = window.QSDom.el;
    const SW = window.QSSiteWords;

    /** A word of this page (translations), its {name} parts filled. */
    function t(name, params) { return QSPanelWords.t('translations.' + name, params); }

    const state = {
        list: null,
        states: null,
        failed: false,
        allLanguages: null,          // the installation's list, for Add a language
        filter: { used: true, unset: true, unused: true, same: false, search: '', scope: 'site' },
        editing: null,               // {key, language, text, error, busy} while a word is edited
        pendingReload: false,        // a change announced while a word was edited: read once it is done
        can: { write: false, remove: false, addLang: false, deleteLang: false, setDefault: false, switchUpdate: false, switchRemove: false },
        offerSeq: 0,                 // the language-switch offer's reads: an older one's answer is dropped
        focusOffer: false,           // a language was removed: its bin is gone, the offer's button takes the focus
    };

    const $ = function (id) { return document.getElementById(id); };
    let dom = null;

    // ------------------------------------------------------------------------------------------------ reading

    async function load() {
        const list = await SW.read({ states: true });
        state.list = list;
        state.states = SW.currentStates();
        state.failed = SW.readFailed();
        drawAll();
    }

    /** The installation's languages, for the Add a language list (getLanguageList, read once). */
    async function loadAllLanguages() {
        if (state.allLanguages) return;
        try {
            const res = await window.QuickSiteAdmin.apiRequest('getLanguageList', 'GET');
            const data = res && res.ok && res.data && res.data.data;
            state.allLanguages = data && Array.isArray(data.languages) ? data.languages : [];
        } catch (e) {
            state.allLanguages = [];
        }
    }

    function reloadSoon() {
        if (state.editing) {
            state.pendingReload = true;
            return;
        }
        load();
    }

    // ------------------------------------------------------------------------------------------------ the list

    /** The project's languages as the page shows them: [{code, file, name, isDefault}], the default first. */
    function languages() {
        const list = state.list;
        if (!list) return [];
        if (!list.multilingual) {
            const code = list.defaultLanguage;
            return [{ code: code, file: 'default', name: list.names[code] || code, isDefault: true }];
        }
        const ordered = list.languages.slice().sort(function (a, b) {
            return (a === list.defaultLanguage ? -1 : 0) - (b === list.defaultLanguage ? -1 : 0);
        });
        return ordered.map(function (code) {
            return { code: code, file: code, name: list.names[code] || code, isDefault: code === list.defaultLanguage };
        });
    }

    function defaultFile() {
        const list = state.list;
        return list && list.multilingual ? list.defaultLanguage : 'default';
    }

    /** What a key holds in each language, and how it stands. */
    function rowOf(key, langs) {
        const list = state.list;
        const dflt = defaultFile();
        const base = SW.word(list, dflt, key);
        const cells = langs.map(function (lang) {
            const word = SW.word(list, lang.file, key);
            const st = SW.stateOf(state.states, lang.file, key);
            const same = list.multilingual && lang.file !== dflt && word !== null && word !== '' && word === base;
            return { lang: lang, word: word, state: st, same: same };
        });
        const named = !!(state.states && state.states.siteKeys.indexOf(key) !== -1);
        return { key: key, cells: cells, named: named };
    }

    function rowStates(row) {
        const s = new Set();
        row.cells.forEach(function (c) { if (c.state) s.add(c.state); });
        return s;
    }

    function inScope(key) {
        if (state.filter.scope === 'site') return true;
        const sources = (state.states && state.states.sources) || {};
        return (sources[state.filter.scope] || []).indexOf(key) !== -1;
    }

    function matchesSearch(row) {
        const needle = state.filter.search;
        if (!needle) return true;
        if (row.key.toLowerCase().indexOf(needle) !== -1) return true;
        return row.cells.some(function (c) { return c.word !== null && c.word.toLowerCase().indexOf(needle) !== -1; });
    }

    // ------------------------------------------------------------------------------------------------ drawing

    function drawAll() {
        const list = state.list;
        dom.readonly.hidden = !permissionsKnown() || state.can.write;
        if (!list) {
            dom.notice.hidden = true;
            dom.table.replaceChildren(_renderMessage(window.QSSiteWords && SW.current() === null && !state.failed
                ? t('noProject') : t('loadFailedNone'), true));
            dom.languages.replaceChildren();
            dom.count.textContent = '';
            return;
        }
        dom.notice.hidden = !state.failed;
        if (state.failed) dom.notice.replaceChildren(t('loadFailed') + ' ', _renderRetry());
        // A reload under the focus (a delete, a word saved in another tab) gives it to the control that took its place.
        const at = document.activeElement;
        const focusBack = at && (dom.table.contains(at) || dom.languages.contains(at)) ? window.QSDom.rememberFocus(at) : null;
        drawLanguages();
        drawScope();
        drawAddForm();
        drawTable();
        if (focusBack && !focusBack.restore()) {
            // What held the focus is gone (a language's bin, the language removed): the offer's button, else the Add row.
            const next = fallbackFocus();
            if (next) next.focus();
        }
    }

    /** Where the focus goes when its control is gone from the languages: the switch offer's button, else the Add row's picker. */
    function fallbackFocus() {
        const offer = !dom.switchOffer.hidden && dom.switchOffer.querySelector('button');
        if (offer) return offer;
        return dom.languageAdd.hidden ? null : dom.languageAdd.querySelector('button:not([hidden]), select');
    }

    function permissionsKnown() {
        const admin = window.QuickSiteAdmin;
        return !!(admin && admin.permissions && admin.permissions.loaded);
    }

    function _renderRetry() {
        return el('button', { type: 'button', class: 'admin-btn admin-btn--ghost', text: t('retry'), onclick: load });
    }

    function _renderMessage(text, isError) {
        return el('p', { class: 'translations-message' + (isError ? ' translations-message--error' : ''), text: text });
    }

    // ---- the languages strip -------------------------------------------------------------------------------

    function drawLanguages() {
        const list = state.list;
        const langs = languages();
        dom.languagesHint.hidden = list.multilingual;
        dom.languages.replaceChildren(...langs.map(_renderLanguage));
        drawLanguageAdd();
    }

    /** One language's card: its name, the default mark, its words, its gaps, and what a writer may do to it. */
    function _renderLanguage(lang) {
        const list = state.list;
        const tree = list.words[lang.file] || {};
        const words = SW.leafKeys(tree).filter(function (k) { return SW.word(list, lang.file, k) !== ''; }).length;
        const byLang = state.states && state.states.byLanguage[lang.file];
        const unset = byLang ? byLang.unset.length : null;
        const facts = [
            el('span', { class: 'translations-language__fact', text: t('languageWords', { n: words }) }),
        ];
        if (unset !== null) {
            facts.push(el('span', {
                class: 'translations-language__fact' + (unset > 0 ? ' translations-language__fact--gap' : ''),
                text: t('languageUnset', { n: unset }),
            }));
        }
        if (list.multilingual && !lang.isDefault) {
            const same = SW.leafKeys(tree).filter(function (k) {
                const w = SW.word(list, lang.file, k);
                return w !== null && w !== '' && w === SW.word(list, defaultFile(), k);
            }).length;
            facts.push(el('span', { class: 'translations-language__fact', text: t('languageSame', { n: same }) }));
        }
        if (!list.multilingual) {
            facts.push(el('code', { class: 'translations-language__file', text: t('languageFile', { file: 'default.json' }) }));
        }
        const head = el('div', { class: 'translations-language__head' }, [
            el('span', { class: 'translations-language__name', text: lang.name }),
            el('code', { class: 'translations-language__code', text: lang.code }),
            lang.isDefault ? el('span', { class: 'admin-badge admin-badge--info', text: t('languageDefault') }) : null,
            editingCode() === lang.code && list.multilingual
                ? el('span', { class: 'admin-badge admin-badge--muted', text: t('languageEditing') }) : null,
        ]);
        const actions = [];
        if (list.multilingual && !lang.isDefault && state.can.setDefault) {
            actions.push(el('button', {
                type: 'button', class: 'admin-btn admin-btn--ghost', text: t('makeDefault'), 'data-focus-key': 'lang-default:' + lang.code,
                onclick: function () { askMakeDefault(lang); },
            }));
        }
        if (list.multilingual && !lang.isDefault && state.can.deleteLang) {
            actions.push(window.QSControls.bin(t('removeLanguageNamed', { language: lang.name }),
                function (e) { askRemoveLanguage(lang, words, e.currentTarget); }, { focusKey: 'lang-delete:' + lang.code }));
        }
        return el('div', { class: 'translations-language' + (lang.isDefault ? ' translations-language--default' : '') }, [
            head,
            el('div', { class: 'translations-language__facts' }, facts),
            actions.length ? el('div', { class: 'translations-language__actions' }, actions) : null,
        ]);
    }

    /**
     * The installation's languages the site does not have: Add makes one the site's next language (addLang), and on a
     * one-language site Make default makes it the site's one language instead (setDefaultLang).
     */
    function drawLanguageAdd() {
        const list = state.list;
        const canAdd = state.can.addLang;
        const canReplace = !list.multilingual && state.can.setDefault;
        dom.languageAdd.hidden = !canAdd && !canReplace;
        if (dom.languageAdd.hidden) return;
        dom.languageAddBtn.hidden = !canAdd;
        dom.languageDefaultBtn.hidden = !canReplace;
        const taken = new Set(list.codes);
        const offered = (state.allLanguages || []).filter(function (l) { return l && l.code && !taken.has(l.code); });
        dom.languagePick.replaceChildren(...(offered.length
            ? offered.map(function (l) { return el('option', { value: l.code, text: (l.name || l.code) + ' (' + l.code + ')' }); })
            : [el('option', { value: '', text: t('addLanguageNone') })]));
        dom.languagePick.disabled = offered.length === 0;
        dom.languageAddBtn.disabled = offered.length === 0;
        dom.languageDefaultBtn.disabled = offered.length === 0;
        if (!dom.languageList && window.QSSearchableSelect) {
            dom.languageList = new window.QSSearchableSelect(dom.languagePick, {});
        }
        if (dom.languageList) dom.languageList.refresh();
    }

    // ---- the filters ---------------------------------------------------------------------------------------

    /** The Where list: the whole site, then each group of sources where the site names keys. */
    function drawScope() {
        const sources = (state.states && state.states.sources) || {};
        const groups = SW.sourceGroups(sources);
        const groupWord = { pages: t('groupPages'), layout: t('groupLayout'), components: t('groupComponents'), site: t('groupSite') };
        // The site's own sources (site:<name>), each named by its words
        const sourceWord = {
            consent: t('source.consent'), media: t('source.media'), api: t('source.api'),
            'link-previews': t('source.link-previews'), storage: t('source.storage'), privacy: t('source.privacy'),
        };
        const options = [el('option', { value: 'site', text: t('scopeAll') })];
        groups.forEach(function (g) {
            options.push(el('optgroup', { label: groupWord[g.group] }, g.sources.map(function (s) {
                const label = g.group === 'site' && sourceWord[s.name] ? sourceWord[s.name] : s.name;
                return el('option', { value: s.id, text: label });
            })));
        });
        if (state.filter.scope !== 'site' && !Object.prototype.hasOwnProperty.call(sources, state.filter.scope)) {
            state.filter.scope = 'site';
        }
        dom.scope.replaceChildren(...options);
        dom.scope.value = state.filter.scope;
        dom.sameChip.hidden = !state.list.multilingual;
    }

    // ---- the Add form --------------------------------------------------------------------------------------

    function editingCode() {
        const list = state.list;
        return list ? SW.editingLanguage(list.codes, list.defaultLanguage) : null;
    }

    function drawAddForm() {
        const list = state.list;
        dom.add.hidden = !state.can.write;
        const langs = languages();
        const current = dom.addLanguage.value || editingCode();
        dom.addLanguage.replaceChildren(...langs.map(function (l) {
            return el('option', { value: l.code, text: l.name + ' (' + l.code + ')' });
        }));
        dom.addLanguage.value = langs.some(function (l) { return l.code === current; }) ? current : list.defaultLanguage;
        dom.addLanguage.closest('.admin-form-group').hidden = !list.multilingual;
    }

    async function submitAdd(e) {
        e.preventDefault();
        const list = state.list;
        if (!list) return;
        dom.addError.textContent = '';
        const key = dom.addKey.value.trim();
        const code = list.multilingual ? dom.addLanguage.value : list.defaultLanguage;
        dom.addCreate.disabled = true;
        let done;
        try {
            done = await SW.create(key, dom.addWord.value, code);
        } finally {
            dom.addCreate.disabled = false;
        }
        if (done.ok) {
            window.QuickSiteAdmin.showToast(t('created', { key: done.key, language: languageName(code) }), 'success');
            dom.addKey.value = '';
            dom.addWord.value = '';
            dom.addKey.focus();
            return;
        }
        if (done.reason === 'exists') {
            state.filter.search = key.toLowerCase();
            dom.search.value = key;
            drawTable();
        }
        dom.addError.textContent = createWords(done, key);
    }

    function createWords(done, key) {
        switch (done.reason) {
            case 'keyInvalid': return t('keyInvalid');
            case 'wordRequired': return t('wordRequired');
            case 'exists': return t('keyExists', { key: key, languages: QSPanelWords.list(done.languages.map(languageName), 'unit') });
            case 'clash': return t('keyClash', { key: key, languages: QSPanelWords.list(done.languages.map(languageName), 'unit') });
            case 'unread': return t('unread');
            default: return t('createFailed', { reason: done.message || '' });
        }
    }

    function languageName(file) {
        const list = state.list;
        if (!list) return file;
        const code = file === 'default' ? list.defaultLanguage : file;
        return list.names[code] || code;
    }

    // ---- the table -----------------------------------------------------------------------------------------

    function drawTable() {
        const list = state.list;
        if (!list) return;
        const langs = languages();
        const all = SW.keys(list, state.states).map(function (key) { return rowOf(key, langs); });
        const pool = all.filter(function (row) { return inScope(row.key) && matchesSearch(row); });
        const counts = { used: 0, unset: 0, unused: 0, same: 0 };
        pool.forEach(function (row) {
            rowStates(row).forEach(function (s) { counts[s]++; });
            if (row.cells.some(function (c) { return c.same; })) counts.same++;
        });
        ['used', 'unset', 'unused', 'same'].forEach(function (s) { dom.counts[s].textContent = String(counts[s]); });
        const shown = pool.filter(function (row) {
            const states = rowStates(row);
            const byState = (state.filter.used && states.has('used')) || (state.filter.unset && states.has('unset'))
                || (state.filter.unused && states.has('unused'));
            if (!byState) return false;
            return !state.filter.same || row.cells.some(function (c) { return c.same; });
        });
        dom.count.textContent = QSPanelWords.count('translations.count', all.length, { shown: QSPanelWords.number(shown.length) });
        if (all.length === 0) {
            dom.table.replaceChildren(_renderMessage(t('noKeys'), false));
            return;
        }
        if (shown.length === 0) {
            dom.table.replaceChildren(_renderMessage(t('noMatches'), false));
            return;
        }
        dom.table.replaceChildren(window.QSDom.tableWrapper(_renderTable(langs, shown)));
    }

    function _renderTable(langs, rows) {
        const editing = editingCode();
        const head = el('tr', null, [
            el('th', { scope: 'col', class: 'translations-table__key', text: t('columnKey') }),
        ].concat(langs.map(function (lang) {
            const marks = [el('span', { class: 'translations-table__lang-name', text: lang.name })];
            marks.push(el('code', { class: 'translations-table__lang-code', text: state.list.multilingual ? lang.code : 'default.json' }));
            return el('th', {
                scope: 'col',
                class: 'translations-table__lang' + (state.list.multilingual && lang.code === editing ? ' translations-table__lang--editing' : ''),
            }, marks);
        })).concat(state.can.remove ? [el('th', { scope: 'col', class: 'translations-table__actions', 'aria-label': t('columnActions') })] : []));
        return el('table', { class: 'admin-table translations-table__table' }, [
            el('thead', null, [head]),
            el('tbody', null, rows.map(_renderRow)),
        ]);
    }

    function _renderRow(row) {
        const keyCell = el('th', { scope: 'row', class: 'translations-table__key' }, [
            el('code', { class: 'translations-key', text: row.key }),
            row.named ? null : el('span', {
                class: 'admin-badge admin-badge--warning translations-key__unused', text: t('unusedMark'), title: t('unusedMarkHint'),
            }),
        ]);
        const cells = row.cells.map(function (cell) { return _renderCell(row, cell); });
        const actions = state.can.remove ? [el('td', { class: 'translations-table__actions' }, [
            window.QSControls.bin(t('deleteKey', { key: row.key }), function () { askDelete(row); }, { focusKey: 'key-delete:' + row.key }),
        ])] : [];
        return el('tr', { 'data-key': row.key }, [keyCell].concat(cells, actions));
    }

    /** One language's word for a key: the word as stored, or the gap; its edit button; or its editor. */
    function _renderCell(row, cell) {
        const editing = state.editing && state.editing.key === row.key && state.editing.language === cell.lang.file;
        const classes = ['translations-cell'];
        if (cell.state) classes.push('translations-cell--' + cell.state);
        if (cell.same) classes.push('translations-cell--same');
        if (editing) return el('td', { class: classes.join(' ') }, [_renderEditor(row, cell)]);
        let shown;
        if (cell.word !== null && cell.word !== '') {
            shown = el('span', { class: 'translations-cell__word', text: cell.word });
        } else if (cell.state === 'unset') {
            shown = el('span', { class: 'translations-cell__gap translations-cell__gap--unset', text: t('gapUnset') });
        } else {
            shown = el('span', { class: 'translations-cell__gap', text: t('gapAbsent') });
        }
        const parts = [shown];
        if (state.can.write) {
            parts.push(window.QSControls.edit(t('editIn', { language: cell.lang.name }), function () { startEdit(row.key, cell); },
                { className: 'translations-cell__edit', focusKey: 'key-edit:' + row.key + '|' + cell.lang.file }));
        }
        if (cell.same) {
            parts.push(el('span', { class: 'translations-cell__same', text: t('sameMark', { language: languageName(defaultFile()) }) }));
        }
        return el('td', { class: classes.join(' ') }, parts);
    }

    function _renderEditor(row, cell) {
        const e = state.editing;
        const area = el('textarea', {
            class: 'admin-input translations-cell__textarea', rows: '3', spellcheck: 'false',
            'aria-label': t('editIn', { language: cell.lang.name }),
        });
        area.value = e.text;
        area.addEventListener('input', function () { e.text = area.value; });
        area.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                ev.preventDefault();
                endEdit();
            } else if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
                ev.preventDefault();
                saveEdit();
            }
        });
        if (e.busy) area.disabled = true;
        const save = el('button', { type: 'button', class: 'admin-btn admin-btn--primary', text: QSPanelWords.t('common.save'), onclick: saveEdit });
        const cancel = el('button', { type: 'button', class: 'admin-btn', text: QSPanelWords.t('common.cancel'), onclick: endEdit });
        if (e.busy) {
            save.disabled = true;
            cancel.disabled = true;
        }
        return el('div', { class: 'translations-cell__editor' }, [
            area,
            e.text === '' ? el('p', { class: 'admin-hint', text: t('emptyWordHint') }) : null,
            e.error ? el('p', { class: 'translations-cell__error', role: 'alert', text: e.error }) : null,
            el('div', { class: 'translations-cell__editor-actions' }, [cancel, save]),
        ]);
    }

    function startEdit(key, cell) {
        state.editing = { key: key, language: cell.lang.file, text: cell.word || '', original: cell.word, error: null, busy: false };
        drawTable();
        focusEditor();
    }

    /** The word being edited takes the focus (an edit opened, a save refused). */
    function focusEditor() {
        const editor = dom.table.querySelector('.translations-cell__editor textarea');
        if (editor) editor.focus();
    }

    function endEdit() {
        state.editing = null;
        if (state.pendingReload) {
            state.pendingReload = false;
            load();
            return;
        }
        drawTable();
    }

    async function saveEdit() {
        const e = state.editing;
        if (!e || e.busy) return;
        if (e.text === (e.original || '') && e.original !== null) {
            endEdit();
            return;
        }
        e.busy = true;
        e.error = null;
        drawTable();
        const words = {};
        words[e.key] = e.text;
        const done = await SW.write(e.language, words);
        e.busy = false;
        if (!done.ok) {
            e.error = t('saveFailed', { reason: done.message });
            drawTable();
            focusEditor();
            return;
        }
        window.QuickSiteAdmin.showToast(t('saved'), 'success');
        state.editing = null;
        state.pendingReload = false;
        load();
    }

    // ------------------------------------------------------------------------------------------------ dialogs

    /**
     * A question in the panel's one confirm (QuickSiteUtils.confirm): its title, what the question needs to say (the
     * body), Cancel and the action's verb. The action runs with the question open: its answer {ok, message} closes it,
     * or shows the server's words and keeps it open, so a choice made in the body is not lost.
     */
    function ask(opts) {
        return window.QuickSiteUtils.confirm('', {
            title: opts.title,
            body: opts.body,
            type: opts.danger ? 'danger' : 'info',
            confirmText: opts.action,
            returnFocus: opts.returnFocus,
            action: async function () {
                const done = await opts.run();
                if (!done || !done.ok) return { ok: false, message: (done && done.message) || '' };
                if (done.toast) window.QuickSiteAdmin.showToast(done.toast, done.toastType || 'success');
                return true;
            },
        });
    }

    function _renderParagraph(text) {
        return el('p', { class: 'translations-dialog__text', text: text });
    }

    function askDelete(row) {
        const list = state.list;
        const holding = row.cells.filter(function (c) { return c.word !== null; });
        const body = [];
        const words = el('dl', { class: 'translations-dialog__words' });
        row.cells.forEach(function (c) {
            words.appendChild(el('dt', { text: c.lang.name }));
            words.appendChild(el('dd', { text: c.word === null ? t('gapAbsent') : (c.word === '' ? t('gapUnset') : c.word) }));
        });
        body.push(words);
        let choice = null;
        if (list.multilingual && holding.length > 0) {
            const name = 'translations-delete-' + Date.now();
            const editing = editingCode();
            const first = holding.some(function (c) { return c.lang.code === editing; }) ? editing : holding[0].lang.code;
            const radios = holding.map(function (c) {
                return _renderChoice(name, c.lang.file, t('deleteOne', { language: c.lang.name }), c.lang.code === first);
            });
            if (row.cells.length > 1) radios.push(_renderChoice(name, '*', t('deleteAll', { n: row.cells.length }), false));
            choice = el('fieldset', { class: 'translations-dialog__choice' }, radios);
            body.push(choice);
        }
        body.push(_renderParagraph(t('deleteText')));
        ask({
            title: t('deleteTitle', { key: row.key }),
            body: body,
            action: QSPanelWords.t('common.delete'),
            danger: true,
            run: async function () {
                let targets;
                if (!list.multilingual) {
                    targets = ['default'];
                } else {
                    const picked = choice ? choice.querySelector('input:checked') : null;
                    const v = picked ? picked.value : '*';
                    targets = v === '*' ? row.cells.map(function (c) { return c.lang.file; }) : [v];
                }
                const done = await SW.remove(targets, [row.key]);
                if (!done.ok) {
                    // A one-language site's key leaves its language's file and default.json: one language, named once.
                    const unique = function (items) { return items.filter(function (v, i) { return items.indexOf(v) === i; }); };
                    return {
                        ok: false,
                        message: t('deleteFailed', {
                            languages: QSPanelWords.list(unique(done.failures.map(function (f) { return languageName(f.language); })), 'unit'),
                            reason: unique(done.failures.map(function (f) { return f.message; })).join(QSPanelWords.t('common.join.semicolon')),
                        }),
                    };
                }
                return { ok: true, toast: t('deleted', { key: row.key }) };
            },
        });
    }

    function _renderChoice(name, value, label, checked) {
        const input = el('input', { type: 'radio', name: name, value: value });
        input.checked = checked;
        return el('label', { class: 'translations-dialog__option' }, [input, el('span', { text: label })]);
    }

    async function askAddLanguage() {
        const code = dom.languagePick.value;
        if (!code) return;
        const list = state.list;
        const offered = (state.allLanguages || []).find(function (l) { return l.code === code; });
        const name = (offered && offered.name) || code;
        const dflt = list.multilingual ? list.defaultLanguage : 'default';
        const words = SW.leafKeys(list.words[dflt] || {}).length;
        const body = [];
        if (!list.multilingual) {
            body.push(_renderParagraph(t('addLanguageSwitch')));
            body.push(_renderParagraph(t('addLanguageAddresses', {
                default: list.defaultLanguage, code: code, defaultName: languageName(list.defaultLanguage),
            })));
        }
        body.push(_renderParagraph(words > 0
            ? QSPanelWords.count('translations.addLanguageCopy', words, { default: languageName(dflt) })
            : t('addLanguageEmpty')));
        const added = await ask({
            title: t('addLanguageTitle', { language: name }),
            body: body,
            action: t('addLanguageButton'),
            run: async function () {
                const done = await SW.addLanguage(code);
                if (done.ok) return { ok: true, toast: t('addLanguageDone', { language: name }) };
                return { ok: false, message: t('languageFailed', { reason: done.message }) };
            },
        });
        if (added) offerSwitch();
    }

    /**
     * Remove a language, asked first. Removing the second language of two leaves the site one language: the question
     * says its addresses lose their language, and names the site's own links that name one (checkStructureMulti),
     * read before it opens.
     */
    async function askRemoveLanguage(lang, words, button) {
        const list = state.list;
        const body = [_renderParagraph(QSPanelWords.count('translations.removeLanguageText', words, { language: lang.name }))];
        if (list.multilingual && list.codes.length === 2) {
            const remaining = list.defaultLanguage;
            body.push(_renderParagraph(t('removeLanguageToOne', {
                language: lang.name, code: lang.code, remaining: languageName(remaining), remainingCode: remaining,
            })));
            if (button) button.disabled = true;
            let links;
            try {
                links = await readLanguageLinks();
            } finally {
                if (button) button.disabled = false;
            }
            body.push(_renderLanguageLinks(links));
        }
        const removed = await ask({
            title: t('removeLanguageTitle', { language: lang.name }),
            body: body,
            action: t('removeLanguage'),
            danger: true,
            returnFocus: fallbackFocus,
            run: async function () {
                const done = await SW.removeLanguage(lang.code);
                if (done.ok) return { ok: true, toast: t('removeLanguageDone', { language: lang.name }) };
                return { ok: false, message: t('languageFailed', { reason: done.message }) };
            },
        });
        if (removed) {
            state.focusOffer = true;
            offerSwitch();
        }
    }

    /**
     * The site's own links that name a language, by source (checkStructureMulti): {ok: true, bySource} or
     * {ok: false, message} in the server's words.
     */
    async function readLanguageLinks() {
        try {
            const res = await window.QuickSiteAdmin.apiRequest('checkStructureMulti', 'GET');
            const data = res && res.ok && res.data && res.data.data;
            if (!data || typeof data.findings_by_source !== 'object' || data.findings_by_source === null) {
                return { ok: false, message: SW.serverWords(res) };
            }
            return { ok: true, bySource: data.findings_by_source };
        } catch (e) {
            return { ok: false, message: String((e && e.message) || e) };
        }
    }

    /**
     * The links a removal down to one language breaks: each source and what it holds, or that there are none. A link is
     * listed once, by its place in the structure: checkStructureMulti reports one finding per pattern a value matches.
     */
    function _renderLanguageLinks(links) {
        if (!links.ok) return _renderParagraph(t('removeLanguageLinksUnread', { reason: links.message }));
        const sources = Array.isArray(links.bySource) ? [] : Object.keys(links.bySource);
        if (!sources.length) return _renderParagraph(t('removeLanguageLinksNone'));
        const found = el('dl', { class: 'translations-dialog__words' });
        let n = 0;
        sources.forEach(function (source) {
            const seen = new Set();
            const values = [];
            (Array.isArray(links.bySource[source]) ? links.bySource[source] : []).forEach(function (f) {
                const place = String(f && f.path !== undefined ? f.path : values.length);
                if (seen.has(place)) return;
                seen.add(place);
                values.push(String(f && f.value !== undefined ? f.value : ''));
            });
            n += values.length;
            found.appendChild(el('dt', { text: source }));
            found.appendChild(el('dd', { text: values.join('\n') }));
        });
        return el('div', {}, [_renderParagraph(t('removeLanguageLinks', { n: n })), found]);
    }

    /**
     * On a one-language site: make the language picked in the Add row the site's one language (setDefaultLang). Its
     * words and its addresses stay as they are.
     */
    function askReplaceLanguage() {
        const code = dom.languagePick.value;
        if (!code) return;
        const list = state.list;
        const offered = (state.allLanguages || []).find(function (l) { return l.code === code; });
        const name = (offered && offered.name) || code;
        ask({
            title: t('makeDefaultOneTitle', { language: name }),
            body: [_renderParagraph(t('makeDefaultOneText', { language: name, current: languageName(list.defaultLanguage) }))],
            action: t('makeDefault'),
            run: async function () {
                const done = await SW.setDefaultLanguage(code);
                if (done.ok) return { ok: true, toast: t('makeDefaultOneDone', { language: name }) };
                return { ok: false, message: t('languageFailed', { reason: done.message }) };
            },
        });
    }

    function askMakeDefault(lang) {
        ask({
            title: t('makeDefaultTitle', { language: lang.name }),
            body: [_renderParagraph(t('makeDefaultText', { language: lang.name }))],
            action: t('makeDefault'),
            run: async function () {
                const done = await SW.setDefaultLanguage(lang.code);
                if (done.ok) return { ok: true, toast: t('makeDefaultDone', { language: lang.name }) };
                return { ok: false, message: t('languageFailed', { reason: done.message }) };
            },
        });
    }

    // ------------------------------------------------------------------------------------------------ the language switch

    /*
     * After an add or a removal changes the language count, the site's language switch (the lang-switch component)
     * may no longer list its languages. The page offers one button, never acting on its own, and only where the switch
     * is placed somewhere and the update-lang-switch workflow's own test says it no longer matches: at two languages
     * or more, Update (the workflow's step, the component rewritten from the language list); at one, Remove (every
     * placement, each named in the question; the component file kept). The page holds no test of its own: it asks the
     * workflow route for the workflow's first phase, no step meaning the switch matches.
     */
    const SWITCH = 'lang-switch';
    const SWITCH_WORKFLOW = 'update-lang-switch';

    /** Where the switch is placed (findComponentUsages): {ok, places: [{type, name, nodeIds}]}; no component file, none. */
    async function readSwitchPlaces() {
        const res = await window.QuickSiteAdmin.apiRequest('findComponentUsages', 'GET', null, [SWITCH]);
        if (res && res.status === 404) return { ok: true, places: [] };
        const data = res && res.ok && res.data && res.data.data;
        if (!data || !data.usages) return { ok: false, message: SW.serverWords(res) };
        const u = data.usages;
        const places = [];
        const add = function (type, name, entry) {
            const ids = entry && Array.isArray(entry.locations) ? entry.locations.map(String) : [];
            if (ids.length) places.push({ type: type, name: name, nodeIds: ids });
        };
        add('menu', null, u.menu);
        add('footer', null, u.footer);
        (Array.isArray(u.pages) ? u.pages : []).forEach(function (p) { add('page', p.name, p); });
        (Array.isArray(u.components) ? u.components : []).forEach(function (c) { add('component', c.name, c); });
        return { ok: true, places: places };
    }

    /** The workflow's first phase, resolved now by the server: {ok, steps}, no step when the switch matches the languages. */
    async function readSwitchSteps() {
        const answer = await window.QuickSiteAPI.postHelper('workflow-generate-steps', { workflowId: SWITCH_WORKFLOW, params: {}, phase: 0 });
        const phase = answer.ok && answer.data && answer.data.phase;
        if (!phase || !Array.isArray(phase.steps)) return { ok: false, message: answer.message };
        return { ok: true, steps: phase.steps };
    }

    function hideSwitchOffer() {
        dom.switchOffer.hidden = true;
        dom.switchOffer.replaceChildren();
    }

    /** Read what the offer needs and draw it, or nothing (no role for it, no switch placed, a switch that matches, a failed read). */
    async function offerSwitch() {
        const seq = ++state.offerSeq;
        const focusAfter = state.focusOffer;
        state.focusOffer = false;
        hideSwitchOffer();
        if (!state.can.switchUpdate && !state.can.switchRemove) return;
        try {
            const list = await SW.read();
            if (seq !== state.offerSeq || !list) return;
            const one = !list.multilingual;
            if (one ? !state.can.switchRemove : !state.can.switchUpdate) return;
            const placed = await readSwitchPlaces();
            if (seq !== state.offerSeq || !placed.ok || placed.places.length === 0) return;
            const plan = await readSwitchSteps();
            if (seq !== state.offerSeq || !plan.ok || plan.steps.length === 0) return;
            dom.switchOffer.replaceChildren(_renderSwitchOffer(one, placed.places));
            dom.switchOffer.hidden = false;
            if (focusAfter) {
                const at = document.activeElement;
                if (!at || at === document.body || dom.languages.contains(at) || dom.languageAdd.contains(at)) {
                    dom.switchOffer.querySelector('button').focus();
                }
            }
        } catch (e) {
            // A read that fails offers nothing: the switch is the author's to update from the editor.
        }
    }

    /** The offer: what it is about and its one button. */
    function _renderSwitchOffer(one, places) {
        const button = el('button', {
            type: 'button', class: 'admin-btn admin-btn--primary',
            text: t(one ? 'switchOffer.removeButton' : 'switchOffer.updateButton'),
        });
        button.addEventListener('click', function () {
            if (one) askRemoveSwitch(places, button); else updateSwitch(button);
        });
        return el('div', { class: 'translations-languages__offer-body' }, [
            el('p', { class: 'translations-languages__offer-text', text: t(one ? 'switchOffer.removeText' : 'switchOffer.updateText') }),
            button,
        ]);
    }

    /** Update: the workflow's step, read again now and sent as its command; the switch then lists the site's languages. */
    async function updateSwitch(button) {
        button.disabled = true;
        let failed = null;
        try {
            const plan = await readSwitchSteps();
            if (!plan.ok) {
                failed = plan.message;
            } else {
                for (const step of plan.steps) {
                    const method = String(step.method || 'POST').toUpperCase();
                    const res = await window.QuickSiteAdmin.apiRequest(step.command, method, method !== 'GET' ? (step.params || null) : null);
                    if (!res || !res.ok) { failed = SW.serverWords(res); break; }
                }
            }
        } catch (e) {
            failed = String((e && e.message) || e);
        }
        if (failed !== null) {
            button.disabled = false;
            window.QuickSiteAdmin.showToast(t('switchOffer.updateFailed', { reason: failed }), 'error');
            return;
        }
        window.QuickSiteAdmin.showToast(t('switchOffer.updateDone'), 'success');
        hideSwitchOffer();
        const next = fallbackFocus();
        if (next) next.focus();
    }

    /** A place's name in Remove's question: the menu, the footer, a page, a component. */
    function _switchPlaceName(place) {
        return t('switchOffer.place.' + place.type, { name: place.name || '' });
    }

    function _renderSwitchPlaces(places) {
        return el('ul', { class: 'translations-dialog__places' }, places.map(function (place) {
            return el('li', { text: QSPanelWords.count('translations.switchOffer.placeTimes', place.nodeIds.length, { place: _switchPlaceName(place) }) });
        }));
    }

    /** Node ids, the last first: removing a node shifts the ids of the siblings after it, never those before. */
    function _lastFirst(a, b) {
        const x = a.split('.'), y = b.split('.');
        for (let i = 0; i < Math.max(x.length, y.length); i++) {
            if (x[i] === undefined) return 1;
            if (y[i] === undefined) return -1;
            const d = (parseInt(y[i], 10) || 0) - (parseInt(x[i], 10) || 0);
            if (d !== 0) return d;
        }
        return 0;
    }

    /** Remove: every placement (deleteNode, the last first in a file), asked first, each place named; the component file stays. */
    async function askRemoveSwitch(places, button) {
        const removed = await ask({
            title: t('switchOffer.removeTitle'),
            body: [_renderParagraph(t('switchOffer.removePlaces')), _renderSwitchPlaces(places)],
            action: t('switchOffer.removeButton'),
            danger: true,
            returnFocus: fallbackFocus,
            run: async function () {
                // Read again: a press after a refusal removes what is still placed, by the ids it has now.
                const now = await readSwitchPlaces();
                if (!now.ok) return { ok: false, message: t('switchOffer.removeFailed', { reason: now.message }) };
                for (const place of now.places) {
                    for (const nodeId of place.nodeIds.slice().sort(_lastFirst)) {
                        const body = { type: place.type, nodeId: nodeId };
                        if (place.name) body.name = place.name;
                        const res = await window.QuickSiteAdmin.apiRequest('deleteNode', 'DELETE', body);
                        if (!res || !res.ok) return { ok: false, message: t('switchOffer.removeFailed', { reason: SW.serverWords(res) }) };
                    }
                }
                return { ok: true, toast: t('switchOffer.removeDone') };
            },
        });
        if (removed) {
            hideSwitchOffer();
        } else if (button.isConnected) {
            button.focus();
        }
    }

    // ------------------------------------------------------------------------------------------------ wiring

    function bind() {
        dom = {
            readonly: $('translations-readonly'),
            notice: $('translations-notice'),
            languagesHint: $('translations-languages-hint'),
            languages: $('translations-languages'),
            languageAdd: $('translations-language-add'),
            languagePick: $('translations-language-pick'),
            languageAddBtn: $('translations-language-add-btn'),
            languageDefaultBtn: $('translations-language-default-btn'),
            switchOffer: $('translations-switch-offer'),
            search: $('translations-search'),
            scope: $('translations-scope'),
            sameChip: $('translations-same-chip'),
            add: $('translations-add'),
            addForm: $('translations-add-form'),
            addKey: $('translations-add-key'),
            addLanguage: $('translations-add-language'),
            addWord: $('translations-add-word'),
            addCreate: $('translations-add-create'),
            addError: $('translations-add-error'),
            count: $('translations-count'),
            table: $('translations-table'),
            counts: {
                used: $('translations-count-used'), unset: $('translations-count-unset'),
                unused: $('translations-count-unused'), same: $('translations-count-same'),
            },
        };
        if (!dom.table) return false;

        dom.search.addEventListener('input', function () {
            state.filter.search = dom.search.value.trim().toLowerCase();
            drawTable();
        });
        dom.scope.addEventListener('change', function () {
            state.filter.scope = dom.scope.value || 'site';
            drawTable();
        });
        document.querySelectorAll('.translations-filters input[data-state]').forEach(function (box) {
            box.addEventListener('change', function () {
                state.filter[box.dataset.state] = box.checked;
                drawTable();
            });
        });
        dom.addForm.addEventListener('submit', submitAdd);
        dom.addLanguage.addEventListener('change', function () {
            // One editing language: the editor's toolbar and its Translation tool start on it too.
            SW.rememberLanguage(dom.addLanguage.value);
            drawLanguages();
            drawTable();
        });
        dom.languageAddBtn.addEventListener('click', askAddLanguage);
        dom.languageDefaultBtn.addEventListener('click', askReplaceLanguage);

        window.addEventListener(SW.EVENT, reloadSoon);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') reloadSoon();
        });
        return true;
    }

    /** What the role may press, once the panel's permissions are known. */
    function readPermissions() {
        const admin = window.QuickSiteAdmin;
        const can = function (cmd) { return !!(admin && typeof admin.canRun === 'function' && admin.canRun(cmd)); };
        state.can = {
            write: can('setTranslationKeys'),
            remove: can('deleteTranslationKeys'),
            addLang: can('addLang'),
            deleteLang: can('deleteLang'),
            setDefault: can('setDefaultLang'),
            // The language switch's offer sends content commands, not language ones, behind the reads it needs:
            // where the switch is placed, and the workflow route (gated as getStructure).
            switchUpdate: can('editStructure') && can('getStructure') && can('findComponentUsages'),
            switchRemove: can('deleteNode') && can('getStructure') && can('findComponentUsages'),
        };
    }

    async function start() {
        if (!bind()) return;
        const admin = window.QuickSiteAdmin;
        if (admin && admin.permissionsReady) {
            try { await admin.permissionsReady; } catch (e) { /* drawn with no writing control */ }
        }
        readPermissions();
        if (state.can.addLang || state.can.setDefault) await loadAllLanguages();
        await load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
