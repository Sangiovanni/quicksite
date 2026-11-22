/**
 * The Translations page (Site → Translations): a site's words in every language, side by side, and its languages.
 *
 * It reads and writes through the panel's one list of a site's words, QSSiteWords (js/core/site-words.js): the
 * reading the editor's Translation tool takes, the same writes, the same Create. It reads when it opens, again when
 * the list announces a change (quicksite:translations-changed: a write on this page, in the editor of another tab, a
 * workflow, the console) and when the tab comes back into view; a write in progress is never redrawn over.
 *
 * Every control that writes is drawn only for a role that can run its command (translation.write), after the
 * panel's permissions load: a viewer sees the words and the languages, and nothing to press.
 *
 * Template: secure/admin/templates/pages/translations.php (the static structure and the words). Every dynamic part
 * is built here with QSDom.el and named _render* helpers, each returning one Element.
 */
(function () {
    'use strict';

    const W = window.QS_TRANSLATIONS_I18N || {};
    const el = window.QSDom.el;
    const SW = window.QSSiteWords;

    /** A page word, its {name} parts filled. */
    function t(key, params) {
        let s = Object.prototype.hasOwnProperty.call(W, key) ? W[key] : key;
        if (params) {
            Object.keys(params).forEach(function (name) { s = s.split('{' + name + '}').join(String(params[name])); });
        }
        return s;
    }

    const state = {
        list: null,
        states: null,
        failed: false,
        allLanguages: null,          // the installation's list, for Add a language
        filter: { used: true, unset: true, unused: true, same: false, search: '', scope: 'site' },
        editing: null,               // {key, language, text, error, busy} while a word is edited
        pendingReload: false,        // a change announced while a word was edited: read once it is done
        can: { write: false, remove: false, addLang: false, multilingual: false, deleteLang: false, setDefault: false },
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
        drawLanguages();
        drawScope();
        drawAddForm();
        drawTable();
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
                type: 'button', class: 'admin-btn admin-btn--ghost', text: t('makeDefault'),
                onclick: function () { askMakeDefault(lang); },
            }));
        }
        if (list.multilingual && !lang.isDefault && state.can.deleteLang) {
            actions.push(el('button', {
                type: 'button', class: 'admin-btn admin-btn--ghost translations-language__remove', text: t('removeLanguage'),
                onclick: function () { askRemoveLanguage(lang, words); },
            }));
        }
        return el('div', { class: 'translations-language' + (lang.isDefault ? ' translations-language--default' : '') }, [
            head,
            el('div', { class: 'translations-language__facts' }, facts),
            actions.length ? el('div', { class: 'translations-language__actions' }, actions) : null,
        ]);
    }

    function drawLanguageAdd() {
        const list = state.list;
        const canAdd = state.can.addLang && (list.multilingual || state.can.multilingual);
        dom.languageAdd.hidden = !canAdd;
        if (!canAdd) return;
        const taken = new Set(list.codes);
        const offered = (state.allLanguages || []).filter(function (l) { return l && l.code && !taken.has(l.code); });
        dom.languagePick.replaceChildren(...(offered.length
            ? offered.map(function (l) { return el('option', { value: l.code, text: (l.name || l.code) + ' (' + l.code + ')' }); })
            : [el('option', { value: '', text: t('addLanguageNone') })]));
        dom.languagePick.disabled = offered.length === 0;
        dom.languageAddBtn.disabled = offered.length === 0;
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
            case 'exists': return t('keyExists', { key: key, languages: done.languages.map(languageName).join(', ') });
            case 'clash': return t('keyClash', { key: key, languages: done.languages.map(languageName).join(', ') });
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
        dom.count.textContent = t('count', { shown: shown.length, total: all.length });
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
            _renderIconButton(QuickSiteUtils.ICON_PATHS.trash, t('deleteKey', { key: row.key }), 'translations-row__delete',
                function () { askDelete(row); }),
        ])] : [];
        return el('tr', { 'data-key': row.key }, [keyCell].concat(cells, actions));
    }

    function _renderIconButton(icon, label, cls, onClick) {
        const btn = el('button', {
            type: 'button', class: 'admin-btn admin-btn--ghost admin-btn--icon-only ' + cls, 'aria-label': label, title: label,
            onclick: onClick,
        });
        const svg = window.QSDom.iconEl(icon, 0);
        if (svg) btn.appendChild(svg);
        return btn;
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
            parts.push(_renderIconButton(QuickSiteUtils.ICON_PATHS.edit, t('editIn', { language: cell.lang.name }), 'translations-cell__edit',
                function () { startEdit(row.key, cell); }));
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
        const save = el('button', { type: 'button', class: 'admin-btn admin-btn--primary', text: t('common.save'), onclick: saveEdit });
        const cancel = el('button', { type: 'button', class: 'admin-btn', text: t('common.cancel'), onclick: endEdit });
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
     * A question in the panel's one modal (QuickSiteUtils.openModal): a title with its × at the top right, what
     * the question needs to say, and Cancel / the action. The action's answer {ok, message} closes it, or shows the
     * server's words and keeps it open.
     */
    function ask(opts) {
        const id = 'translations-dialog-' + Date.now();
        const error = el('p', { class: 'translations-dialog__error', role: 'alert' });
        const closeBtn = el('button', {
            type: 'button', class: 'admin-modal__close', 'aria-label': t('common.close'), title: t('common.close'), text: '×',
        });
        const cancelBtn = el('button', { type: 'button', class: 'admin-btn', text: t('common.cancel') });
        const actBtn = el('button', {
            type: 'button', class: 'admin-btn admin-btn--' + (opts.danger ? 'danger' : 'primary'), text: opts.action,
        });
        const modal = _renderDialog(id, opts.title, opts.body, error, closeBtn, cancelBtn, actBtn);
        let shown = null;
        const close = function () {
            if (!shown) return;
            const s = shown;
            shown = null;
            s.close();
        };
        closeBtn.addEventListener('click', close);
        cancelBtn.addEventListener('click', close);
        actBtn.addEventListener('click', async function () {
            error.textContent = '';
            window.QSDom.setButtonBusy(actBtn, opts.action);
            actBtn.disabled = true;
            cancelBtn.disabled = true;
            let done;
            try {
                done = await opts.run(modal);
            } finally {
                actBtn.disabled = false;
                cancelBtn.disabled = false;
                actBtn.textContent = opts.action;
            }
            if (done && done.ok) {
                close();
                if (done.toast) window.QuickSiteAdmin.showToast(done.toast, done.toastType || 'success');
                return;
            }
            error.textContent = (done && done.message) || '';
        });
        shown = window.QuickSiteUtils.openModal(modal, {
            focusable: function () {
                return [closeBtn].concat(Array.from(modal.querySelectorAll('input, select')), [cancelBtn, actBtn]);
            },
            first: opts.danger ? cancelBtn : actBtn,
            dismiss: close,
        });
    }

    function _renderDialog(id, title, body, error, closeBtn, cancelBtn, actBtn) {
        return el('div', {
            class: 'admin-modal-dialog translations-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id,
        }, [
            el('div', { class: 'translations-dialog__header' }, [
                el('h2', { class: 'admin-modal-dialog__title translations-dialog__title', id: id, text: title }),
                closeBtn,
            ]),
            el('div', { class: 'translations-dialog__body' }, body.concat([error])),
            el('div', { class: 'admin-modal-dialog__actions translations-dialog__actions' }, [cancelBtn, actBtn]),
        ]);
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
            action: t('common.delete'),
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
                    return {
                        ok: false,
                        message: t('deleteFailed', {
                            languages: done.failures.map(function (f) { return languageName(f.language); }).join(', '),
                            reason: done.failures.map(function (f) { return f.message; }).join(' | '),
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

    function askAddLanguage() {
        const code = dom.languagePick.value;
        if (!code) return;
        const list = state.list;
        const offered = (state.allLanguages || []).find(function (l) { return l.code === code; });
        const name = (offered && offered.name) || code;
        const dflt = list.multilingual ? list.defaultLanguage : 'default';
        const words = SW.leafKeys(list.words[dflt] || {}).length;
        const body = [];
        if (!list.multilingual) body.push(_renderParagraph(t('addLanguageSwitch')));
        body.push(_renderParagraph(words > 0
            ? t('addLanguageCopy', { n: words, default: languageName(dflt) })
            : t('addLanguageEmpty')));
        ask({
            title: t('addLanguageTitle', { language: name }),
            body: body,
            action: t('addLanguageButton'),
            run: async function () {
                const done = await SW.addLanguage(code);
                if (done.ok) return { ok: true, toast: t('addLanguageDone', { language: name }) };
                if (done.switched) {
                    return { ok: true, toast: t('addLanguageSwitchedOnly', { language: name, reason: done.message }), toastType: 'warning' };
                }
                return { ok: false, message: t('languageFailed', { reason: done.message }) };
            },
        });
    }

    function askRemoveLanguage(lang, words) {
        ask({
            title: t('removeLanguageTitle', { language: lang.name }),
            body: [_renderParagraph(t('removeLanguageText', { n: words, language: lang.name }))],
            action: t('removeLanguage'),
            danger: true,
            run: async function () {
                const done = await SW.removeLanguage(lang.code);
                if (done.ok) return { ok: true, toast: t('removeLanguageDone', { language: lang.name }) };
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
            multilingual: can('setMultilingual'),
            deleteLang: can('deleteLang'),
            setDefault: can('setDefaultLang'),
        };
    }

    async function start() {
        if (!bind()) return;
        const admin = window.QuickSiteAdmin;
        if (admin && admin.permissionsReady) {
            try { await admin.permissionsReady; } catch (e) { /* drawn with no writing control */ }
        }
        readPermissions();
        if (state.can.addLang) await loadAllLanguages();
        await load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
