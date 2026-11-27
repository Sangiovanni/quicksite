/**
 * Storage registry admin page — list + add/edit modal + delete.
 *
 * Calls listStorageItems / addStorageItem / editStorageItem /
 * deleteStorageItem via QuickSiteAdmin.apiRequest. The registry is the
 * GDPR / cookie-consent data layer.
 *
 * A description is each language's own word (storage.desc.<id> in that language's
 * word file). The page shows one language at a time, the panel's editing language
 * (QSSiteWords.editingLanguage() / rememberLanguage(), shared with the editor and the
 * Translations page): the top selector and the item dialog's picker choose it, in
 * step. Each of the project's languages is read with listStorageItems' `lang`; a
 * save writes the languages edited and only them.
 *
 * Every control that sends a command carries that command's role mark
 * (data-requires-command), filtered once the role is known.
 *
 * Built with createElement + textContent + named _render* helpers per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing. Styling
 * lives in public/admin/assets/css/storage-admin.css.
 */
(function () {
    'use strict';

    // Until the registry answers: the project's languages come from listStorageItems.
    var DEFAULT_LANG = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.defaultLang) || 'en';

    // The retention presets, as the registry stores them. The generated cookie policy names the same list
    // (consentLayerHelpers.php CONSENT_RETENTION_PRESETS): the two change together.
    var RETENTION_PRESETS = ['session', '1d', '7d', '30d', '90d', '1y', 'persistent', 'signout'];
    // A retention may also be a duration: a whole number from 1 to 9999 and a unit (days, weeks, months, years), stored
    // as 13mo, 2w. The policy reads it by the same rule (consentLayerHelpers.php CONSENT_RETENTION_UNITS and
    // consentRetentionDuration()): the two change together. Any other retention is a text a key already holds (typed
    // before, or sent through the API), kept and shown as written; the dialog offers no free text.
    var RETENTION_UNITS = ['d', 'w', 'mo', 'y'];
    var RETENTION_DURATION = new RegExp('^([1-9][0-9]{0,3})(' + RETENTION_UNITS.join('|') + ')$');

    // The page's words are the `storage` section of the panel's words, which storage.php hands: each is read
    // by its key's whole path through QSPanelWords (core/panel-words.js).

    /** A storage category's word (its id is one of the engine's four, STORAGE_CATEGORIES). */
    function _categoryWord(id) {
        return QSPanelWords.t('storage.categories.' + id);
    }
    /** A retention option's word: a preset's (its value is the preset), or the duration choice's. */
    function _retentionOptionWord(value) {
        if (value === '__custom__') return QSPanelWords.t('storage.keyDialog.retentionCustom');
        return QSPanelWords.t('storage.retentionPresets.' + value);
    }
    /** A duration unit's name in the unit list (days, weeks, months, years). */
    function _retentionUnitName(unit) {
        return QSPanelWords.t('storage.retentionUnitNames.' + unit);
    }
    /** A retention read as a duration: {count: 13, unit: 'mo'} for "13mo", else null. */
    function _retentionDuration(value) {
        var m = RETENTION_DURATION.exec(String(value || ''));
        return m ? { count: parseInt(m[1], 10), unit: m[2] } : null;
    }
    /** A key's retention as the panel shows it: a preset's word, a duration's counted word, or a held text as written. */
    function _retentionWord(retention) {
        if (RETENTION_PRESETS.indexOf(retention) !== -1) return _retentionOptionWord(retention);
        var duration = _retentionDuration(retention);
        return duration ? QSPanelWords.count('storage.retentionUnits.' + duration.unit, duration.count) : retention;
    }
    var SAMESITE_OPTIONS = ['Lax', 'Strict', 'None'];

    var state = {
        items: [],
        scopes: ['localStorage', 'sessionStorage', 'cookie'],
        categories: ['essential', 'functional', 'analytics', 'marketing'],
        languages: [DEFAULT_LANG],     // the PROJECT's languages, never the panel's
        languageNames: {},             // {code: name}, as listStorageItems names them
        defaultLanguage: DEFAULT_LANG,
        lang: DEFAULT_LANG,            // the language the page shows (the editing language)
        words: {},                     // {code: {id: description}}: each language read, as its pages show it
        filter: 'all',
    };

    // Scan / reconcile state. `data` holds the last scanStorageUsage payload
    // ({ buckets, counts }); `expanded` tracks the two collapsible sections.
    var scan = {
        data: null,
        loading: false,
        error: null,
        expanded: { ok: false, orphan: false },
    };

    function api(cmd, method, body) {
        var adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            // Its message is shown: the dialog's error line, the scan panel's error.
            return Promise.reject(new Error(QSPanelWords.t('common.adminUnavailable')));
        }
        return adminApi.apiRequest(cmd, method, body);
    }

    /** A refusal in the server's own words (QSSiteWords.serverWords()), else its message. */
    function _serverWords(r, fallback) {
        if (window.QSSiteWords && typeof window.QSSiteWords.serverWords === 'function' && r && r.data) {
            return window.QSSiteWords.serverWords(r);
        }
        return (r && r.data && r.data.message) || fallback;
    }

    /** The role mark of the command a control sends: the panel's filter hides it from a role without it. */
    function _mark(el, command) {
        el.setAttribute('data-requires-command', command);
        return el;
    }

    /** Hide, under `root`, every control whose command the role cannot run. */
    function _filterControls(root) {
        if (root && window.QuickSiteAdmin && typeof window.QuickSiteAdmin.filterByPermissions === 'function') {
            window.QuickSiteAdmin.filterByPermissions(root);
        }
    }

    function multilingual() { return state.languages.length > 1; }

    /** A language as the selectors name it: its name and its code. */
    function langLabel(code) {
        var name = state.languageNames[code];
        return name && name !== code ? name + ' (' + code + ')' : code;
    }
    function langName(code) { return state.languageNames[code] || code; }

    /** The project's languages as a select, `selected` chosen. */
    function _renderLanguageSelect(selected) {
        var sel = QSDom.el('select', { class: 'admin-input' });
        state.languages.forEach(function (code) {
            var o = QSDom.el('option', { value: code, text: langLabel(code) });
            if (code === selected) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    /** Show the page in a language and remember it as the editing language: the cards and the top selector follow. */
    function setLanguage(code) {
        if (state.languages.indexOf(code) === -1) return;
        state.lang = code;
        if (window.QSSiteWords && typeof window.QSSiteWords.rememberLanguage === 'function') {
            window.QSSiteWords.rememberLanguage(code);
        }
        renderList();
        var sel = document.getElementById('storage-shown-language');
        if (sel && sel.value !== code) sel.value = code;
    }

    /** What a language says now for an item (null when that language could not be read). */
    function storedDescription(id, code) {
        var words = state.words[code];
        if (!words) return null;
        return Object.prototype.hasOwnProperty.call(words, id) ? words[id] : '';
    }

    function _renderLabel(text, required) {
        var l = QSDom.el('label', { class: 'admin-label' });
        l.appendChild(document.createTextNode(text));
        if (required) l.appendChild(QSDom.el('span', { text: ' *' }));
        return l;
    }
    function _renderHint(text) { return QSDom.el('p', { class: 'admin-hint', text: text }); }
    function _renderGroup(label, child, hint) {
        var g = QSDom.el('div', { class: 'admin-form-group' });
        if (label) g.appendChild(label);
        if (child) g.appendChild(child);
        if (hint) g.appendChild(hint);
        return g;
    }

    function _renderPill(text, kind) {
        return QSDom.el('span', { class: 'storage-pill storage-pill--' + kind, text: text });
    }

    /** The slot qs.js actually writes, or null for scopes it does not write. */
    function _physicalKey(item) {
        if (item.scope !== 'localStorage' && item.scope !== 'sessionStorage') {
            return null;   // cookie items are declared here but written elsewhere
        }
        return QuickSiteUtils.storageKeyPrefix() + item.id;
    }

    /** The prefix chip in front of the key input in the add/edit modal: never stored (core/utils.js). */
    function _renderKeyPrefixChip() {
        return QuickSiteUtils.renderStorageKeyPrefixChip(QSPanelWords.t('storage.keyDialog.prefixTitle'));
    }

    /**
     * The key field's hint, which changes with the scope: what the key is (typed, or found by the scan), whether
     * qs.js prefixes it (localStorage and sessionStorage) or not (a cookie), and that its value is never stored.
     */
    function _keyHintText(isDeclare, prefixed) {
        if (isDeclare) {
            return prefixed ? QSPanelWords.t('storage.keyDialog.keyHint.declarePrefixed')
                : QSPanelWords.t('storage.keyDialog.keyHint.declareCookie');
        }
        return prefixed ? QSPanelWords.t('storage.keyDialog.keyHint.prefixed')
            : QSPanelWords.t('storage.keyDialog.keyHint.cookie');
    }

    /**
     * "stored as qsp_<project>_<id>" line, or null when it does not apply: one word holding the key's <code>, its
     * text drawn as the line's label, the flex gap between them.
     */
    function _renderPhysicalKey(item) {
        var physical = _physicalKey(item);
        if (physical === null) return null;
        var key = QSDom.el('code', {
            class: 'storage-card__physical-key',
            text: physical,
            title: QSPanelWords.t('storage.card.storedAsTitle'),
        });
        var line = QSDom.el('div', { class: 'storage-card__physical' });
        QSPanelWords.parts('storage.card.storedAs', { key: key }).forEach(function (part) {
            if (typeof part !== 'string') { line.appendChild(part); return; }
            var text = part.trim();
            if (text) line.appendChild(QSDom.el('span', { class: 'storage-card__physical-label', text: text }));
        });
        return line;
    }

    /** The card's description line: the shown language's word, or what that language lacks. */
    function _renderDescription(item) {
        var desc = storedDescription(item.id, state.lang);
        var text = desc
            || (desc === null ? QSPanelWords.t('storage.languages.unread', { language: langName(state.lang) })
                : multilingual() ? QSPanelWords.t('storage.languages.noDescriptionIn', { language: langName(state.lang) })
                : QSPanelWords.t('storage.languages.noDescription'));
        return QSDom.el('div', {
            class: 'storage-card__summary' + (desc ? '' : ' storage-card__summary--none'),
            text: text,
        });
    }

    function _renderCard(item) {
        var card = QSDom.el('div', { class: 'storage-card' });
        var row = QSDom.el('div', { class: 'storage-card__row' });

        var main = QSDom.el('div', { class: 'storage-card__main' });
        var pills = QSDom.el('div', { class: 'storage-card__pills' });
        pills.appendChild(QSDom.el('span', { class: 'storage-card__id', text: item.id }));
        pills.appendChild(_renderPill(item.scope || '—', 'scope'));
        pills.appendChild(_renderPill(item.category ? _categoryWord(item.category) : '—', 'cat-' + (item.category || 'functional')));
        if (item.consentRequired) {
            pills.appendChild(_renderPill(QSPanelWords.t('storage.card.needsConsent'), 'consent'));
        } else {
            pills.appendChild(_renderPill(QSPanelWords.t('storage.card.noConsent'), 'essential'));
        }
        if (item.retention) {
            pills.appendChild(_renderPill(QSPanelWords.t('storage.card.keeps', { retention: _retentionWord(item.retention) }), 'retention'));
        }
        main.appendChild(pills);

        var physical = _renderPhysicalKey(item);
        if (physical) main.appendChild(physical);

        main.appendChild(_renderDescription(item));
        row.appendChild(main);

        var actions = QSDom.el('div', { class: 'storage-card__actions' });
        actions.appendChild(QSControls.edit(QSPanelWords.t('storage.actions.edit', { id: item.id }),
            function () { openEditModal(item); }, { command: 'editStorageItem', focusKey: 'edit:' + item.id }));
        actions.appendChild(QSControls.bin(QSPanelWords.t('storage.actions.delete', { id: item.id }),
            function () { openDeleteConfirm(item); }, { command: 'deleteStorageItem', focusKey: 'delete:' + item.id }));
        row.appendChild(actions);

        card.appendChild(row);
        return card;
    }

    function _renderFilterBar() {
        var bar = QSDom.el('div', { class: 'storage-filter-bar' });
        var keys = ['all'].concat(state.scopes);
        keys.forEach(function (key) {
            var count = key === 'all'
                ? state.items.length
                : state.items.filter(function (it) { return it.scope === key; }).length;
            var isActive = state.filter === key;
            var cls = 'admin-btn admin-btn--ghost' + (isActive ? ' storage-filter-bar__btn--active' : '');
            bar.appendChild(QSDom.el('button', {
                class: cls, type: 'button',
                onclick: function () { state.filter = key; renderList(); },
                text: key === 'all'
                    ? QSPanelWords.count('storage.filter.all', count)
                    : QSPanelWords.count('storage.filter.scope', count, { scope: key }),
            }));
        });
        return bar;
    }

    function applyFilter(items, filter) {
        if (filter === 'all') return items;
        return items.filter(function (it) { return it.scope === filter; });
    }

    function _renderEmptyState(filter) {
        return QSDom.el('div', {
            class: 'storage-list__empty',
            text: filter === 'all'
                ? QSPanelWords.t('storage.list.empty')
                : QSPanelWords.t('storage.list.emptyScope', { scope: filter }),
        });
    }

    function renderList() {
        var root = document.getElementById('storage-list');
        if (!root) return;
        // A redraw under the focus (a save, a delete, a language) gives it to the control that took its place.
        var focusBack = root.contains(document.activeElement) ? QSDom.rememberFocus() : null;
        root.textContent = '';
        root.className = 'storage-list';

        var filtered = applyFilter(state.items, state.filter);
        if (filtered.length === 0) {
            root.appendChild(_renderEmptyState(state.filter));
        } else {
            filtered.forEach(function (it) { root.appendChild(_renderCard(it)); });
        }
        _filterControls(root);
        if (focusBack) focusBack.restore();

        var filterBar = document.getElementById('storage-filter-bar');
        if (filterBar) {
            filterBar.textContent = '';
            filterBar.appendChild(_renderFilterBar());
        }
    }

    /** One language's read of the registry ({items, …}), or null when it failed. */
    async function _readLanguage(code) {
        try {
            var r = await api('listStorageItems', 'POST', code ? { lang: code } : {});
            var payload = r && r.ok && r.data && (r.data.data || r.data);
            return payload && Array.isArray(payload.items) ? payload : null;
        } catch (e) {
            console.warn('[storage] load failed:', e);
            return null;
        }
    }

    /** {id: description} of one language's read. */
    function _descriptionsOf(items, code) {
        var out = {};
        items.forEach(function (it) {
            var d = it.description;
            out[it.id] = (d && typeof d === 'object' && typeof d[code] === 'string') ? d[code] : '';
        });
        return out;
    }

    /**
     * Read the registry: the project's default language first (the items, the scopes and categories, the
     * project's languages), then each other language's descriptions. The page shows the editing language.
     */
    async function refresh() {
        // The controls drawn depend on the role: wait for it, as every page that draws them does.
        try { await (QuickSiteAdmin.permissionsReady || Promise.resolve()); } catch (e) { /* drawn as a role without them */ }
        var first = await _readLanguage(null);
        state.words = {};
        if (first) {
            state.items = first.items;
            if (Array.isArray(first.scopes) && first.scopes.length) state.scopes = first.scopes;
            if (Array.isArray(first.categories) && first.categories.length) state.categories = first.categories;
            if (Array.isArray(first.languages) && first.languages.length) state.languages = first.languages;
            if (first.languageNames && typeof first.languageNames === 'object') state.languageNames = first.languageNames;
            var answered = typeof first.lang === 'string' && first.lang ? first.lang : state.languages[0];
            state.defaultLanguage = typeof first.defaultLanguage === 'string' && first.defaultLanguage ? first.defaultLanguage : answered;
            state.words[answered] = _descriptionsOf(first.items, answered);
            var others = state.languages.filter(function (code) { return code !== answered; });
            var reads = await Promise.all(others.map(function (code) { return _readLanguage(code); }));
            reads.forEach(function (read, i) { if (read) state.words[others[i]] = _descriptionsOf(read.items, others[i]); });
        } else {
            state.items = [];
        }
        var remembered = window.QSSiteWords && typeof window.QSSiteWords.editingLanguage === 'function'
            ? window.QSSiteWords.editingLanguage(state.languages, state.defaultLanguage) : state.defaultLanguage;
        state.lang = state.languages.indexOf(remembered) !== -1 ? remembered : state.defaultLanguage;
        renderList();
        renderLanguageBar();
        renderLanguageHint();
    }

    // ====================================================================
    // The language shown (multilingual projects only)
    // ====================================================================

    /** The hint above the toolbar: what a language's description is and where it shows. */
    function renderLanguageHint() {
        var host = document.getElementById('storage-desclang-hint');
        if (!host) return;
        if (!multilingual()) { host.hidden = true; host.textContent = ''; return; }
        host.hidden = false;
        host.textContent = QSPanelWords.t('storage.languages.hint');
    }

    /** The top selector: the language the cards show, the item dialog's picker in step with it. */
    function renderLanguageBar() {
        var host = document.getElementById('storage-desc-lang-bar');
        if (!host) return;
        host.textContent = '';
        if (!multilingual()) return; // one language: nothing to choose
        host.appendChild(QSDom.el('label', { class: 'storage-desc-lang-bar__label', for: 'storage-shown-language', text: QSPanelWords.t('storage.languages.shownIn') }));
        var sel = _renderLanguageSelect(state.lang);
        sel.id = 'storage-shown-language';
        sel.classList.add('storage-desc-lang-bar__select');
        sel.addEventListener('change', function () { setLanguage(sel.value); });
        host.appendChild(sel);
    }

    // ====================================================================
    // Modal — add / edit
    // ====================================================================

    /** The page's open dialog ({close} from QuickSiteUtils.openModal), or null. */
    var shownModal = null;
    var modalSeq = 0;

    /**
     * A dialog of the page, in the panel's one modal: its title and its × at the top right. The caller fills it, then
     * shows it with _showModal(); the ×, Escape and the backdrop close it.
     */
    function _renderModalShell(titleText) {
        closeModal();
        var titleId = 'storage-modal-title-' + (++modalSeq);
        var dialog = QSDom.el('div', {
            class: 'storage-modal-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId,
        });
        dialog.appendChild(QSDom.el('div', { class: 'storage-modal-header' }, [
            QSDom.el('h2', { class: 'storage-modal-header__title', id: titleId, text: titleText }),
            QSControls.close(null, closeModal),
        ]));
        return dialog;
    }

    /** Show a filled dialog: the focus on its first control past the header, kept inside, given back on close. */
    function _showModal(dialog) {
        var inside = QuickSiteUtils.focusables(dialog);
        var first = inside.filter(function (el) { return !el.closest('.storage-modal-header'); })[0] || inside[0];
        shownModal = QuickSiteUtils.openModal(dialog, {
            focusable: function () { return QuickSiteUtils.focusables(dialog); },
            first: first,
            dismiss: closeModal,
        });
    }

    function closeModal() {
        if (!shownModal) return;
        var shown = shownModal;
        shownModal = null;
        shown.close();
    }

    /** A select of values; each option shows its value, or the word wordOf(value) gives (a category's, a retention's). */
    function _renderSelect(options, selected, wordOf) {
        var sel = QSDom.el('select', { class: 'admin-input' });
        options.forEach(function (opt) {
            var o = QSDom.el('option', { value: opt, text: wordOf ? wordOf(opt) : opt });
            if (opt === selected) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    /**
     * The key dialog's Retention field, opened on what a key holds ('session' for a new key): the presets, then
     * Custom… (a duration: a whole number and a unit, saved as 13mo). A key that holds another text (typed before, or
     * sent through the API) has one more option, that text as typed, chosen: a save keeps it, another choice replaces
     * it. read() answers the value to save, or null when the duration's number is not a whole number from 1 to 9999.
     */
    function _renderRetentionField(held) {
        var duration = _retentionDuration(held);
        // A preset first: 30d is a preset as well as a duration's form.
        var preset = RETENTION_PRESETS.indexOf(held) !== -1;
        var kept = !preset && !duration;
        var choice = preset ? held : (duration ? '__custom__' : '__text__');
        var values = RETENTION_PRESETS.concat(['__custom__'], kept ? ['__text__'] : []);
        var select = _renderSelect(values, choice, function (value) {
            return value === '__text__' ? QSPanelWords.t('storage.keyDialog.retentionKept', { text: held }) : _retentionOptionWord(value);
        });
        var number = QSDom.el('input', {
            type: 'number', class: 'admin-input storage-retention-number', min: '1', max: '9999', step: '1', inputmode: 'numeric',
            'aria-label': QSPanelWords.t('storage.keyDialog.retentionNumber'),
            value: duration ? String(duration.count) : '',
        });
        var unit = _renderSelect(RETENTION_UNITS, duration ? duration.unit : 'd', _retentionUnitName);
        unit.classList.add('storage-retention-unit');
        unit.setAttribute('aria-label', QSPanelWords.t('storage.keyDialog.retentionUnit'));
        var durationRow = QSDom.el('div', { class: 'storage-retention-duration' }, [number, unit]);
        function sync() {
            durationRow.style.display = select.value === '__custom__' ? '' : 'none';
        }
        select.addEventListener('change', sync);
        sync();
        var wrap = QSDom.el('div', { class: 'storage-retention' }, [select, durationRow]);
        return {
            group: _renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.retention')), wrap,
                _renderHint(QSPanelWords.t('storage.keyDialog.retentionHint'))),
            read: function () {
                if (select.value === '__text__') return held;
                if (select.value !== '__custom__') return select.value;
                var typed = String(number.value).trim();
                var count = /^[0-9]+$/.test(typed) ? parseInt(typed, 10) : 0;
                return count >= 1 && count <= 9999 ? count + unit.value : null;
            },
        };
    }

    function _openModal(mode, item, prefill) {
        var isEdit = (mode === 'edit');
        // Declare-from-scan: the key name is fixed by usage, only used when the
        // scope couldn't be inferred (one-click declare needs a scope).
        var isDeclare = (!isEdit && prefill && prefill.id);
        var titleText = isEdit ? QSPanelWords.t('storage.keyDialog.titleEdit', { id: item.id })
            : (isDeclare ? QSPanelWords.t('storage.keyDialog.titleDeclare', { id: prefill.id })
                : QSPanelWords.t('storage.keyDialog.titleAdd'));
        var dialog = _renderModalShell(titleText);

        // id — shown behind the non-editable prefix chip, so the author reads
        // the WHOLE physical key as they type rather than discovering it in
        // devtools later.
        var idInput = QSDom.el('input', {
            type: 'text', class: 'admin-input storage-keyfield__input', autocomplete: 'off',
            placeholder: QSPanelWords.t('storage.keyDialog.keyPlaceholder'),
            value: isEdit ? item.id : (isDeclare ? prefill.id : ''),
        });
        if (isDeclare) idInput.readOnly = true;
        var keyPrefixChip = _renderKeyPrefixChip();
        var keyField = QSDom.el('div', { class: 'storage-keyfield' }, [keyPrefixChip, idInput]);
        var keyHint = _renderHint('');
        dialog.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.keyName'), true), keyField, keyHint));

        // scope
        var scopeSelect = _renderSelect(state.scopes, isEdit ? item.scope : ((isDeclare && prefill.scope) ? prefill.scope : 'localStorage'));
        dialog.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.scope'), true), scopeSelect));

        // The chip follows the SCOPE: qs.js prefixes localStorage and
        // sessionStorage, and does not write cookies at all. Showing
        // "qsp_<project>_" in front of a cookie name would be a plain lie about
        // what lands in the browser — the one thing this chip exists to prevent.
        function syncKeyPrefix() {
            var prefixed = (scopeSelect.value === 'localStorage' || scopeSelect.value === 'sessionStorage');
            keyPrefixChip.hidden = !prefixed;
            keyHint.textContent = _keyHintText(isDeclare, prefixed);
        }
        scopeSelect.addEventListener('change', syncKeyPrefix);
        syncKeyPrefix();

        // category
        var categorySelect = _renderSelect(state.categories, isEdit ? item.category : 'functional', _categoryWord);
        dialog.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.category'), true), categorySelect,
            _renderHint(QSPanelWords.t('storage.keyDialog.categoryHint'))));

        // retention (a preset, a duration, or the text a key already holds)
        var retention = _renderRetentionField(isEdit ? (item.retention || 'session') : 'session');
        dialog.appendChild(retention.group);

        // description — each language's own word (translate/, keyed; edits are live). One language shows at a
        // time, the page's; what was typed in another is kept until Save, which writes the languages edited.
        var description = _renderDescriptionField(isEdit ? item.id : null, isEdit ? 'editStorageItem' : 'addStorageItem');
        dialog.appendChild(description.group);

        // cookie-only conditional fields
        var cookieWrap = QSDom.el('div', { class: 'storage-cookie-fields' });
        var domainInput = QSDom.el('input', { type: 'text', class: 'admin-input', placeholder: QSPanelWords.t('storage.keyDialog.cookieDomainPlaceholder'), value: (isEdit && item.domain) || '' });
        var pathInput = QSDom.el('input', { type: 'text', class: 'admin-input', placeholder: '/', value: (isEdit && item.path) || '' });
        var sameSiteSelect = _renderSelect(SAMESITE_OPTIONS, (isEdit && item.sameSite) || 'Lax');
        var secureLabel = QSDom.el('label', { class: 'storage-cookie-secure' });
        var secureCb = QSDom.el('input', { type: 'checkbox' });
        if (isEdit && item.secure) secureCb.checked = true;
        secureLabel.appendChild(secureCb);
        secureLabel.appendChild(document.createTextNode(' ' + QSPanelWords.t('storage.keyDialog.cookieSecure')));
        cookieWrap.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.cookieDomain')), domainInput));
        cookieWrap.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.cookiePath')), pathInput));
        cookieWrap.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.keyDialog.cookieSameSite')), sameSiteSelect));
        cookieWrap.appendChild(_renderGroup(null, secureLabel));
        function syncCookieFields() {
            cookieWrap.style.display = (scopeSelect.value === 'cookie') ? '' : 'none';
        }
        syncCookieFields();
        scopeSelect.addEventListener('change', syncCookieFields);
        dialog.appendChild(cookieWrap);

        // The error sits in the dialog's flow, above the buttons, never in their sticky row: it covers no field.
        var errBox = _renderModalError();
        dialog.appendChild(errBox.el);

        // Actions
        var actionsRow = QSDom.el('div', { class: 'storage-modal-actions' });
        actionsRow.appendChild(QSDom.el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', text: QSPanelWords.t('common.cancel'), onclick: closeModal }));
        var saveBtn = QSDom.el('button', {
            class: 'admin-btn admin-btn--primary', type: 'button',
            text: isEdit ? QSPanelWords.t('storage.keyDialog.save') : QSPanelWords.t('storage.keyDialog.add'),
        });
        actionsRow.appendChild(saveBtn);
        dialog.appendChild(actionsRow);

        saveBtn.addEventListener('click', async function () {
            errBox.clear();
            var id = idInput.value.trim();
            if (id === '') { errBox.show(QSPanelWords.t('storage.keyDialog.keyRequired')); return; }
            if (/\s/.test(id)) { errBox.show(QSPanelWords.t('storage.keyDialog.keyNoSpaces')); return; }

            var body = {
                id: id,
                scope: scopeSelect.value,
                category: categorySelect.value,
            };
            var retained = retention.read();
            if (retained === null) { errBox.show(QSPanelWords.t('storage.keyDialog.retentionNumberInvalid')); return; }
            if (retained) body.retention = retained;

            // The description of each language edited, and only those: on an edit an emptied language
            // removes that language's word; a language not edited keeps its word.
            var descriptions = description.changes(isEdit);
            if (Object.keys(descriptions).length) body.description = descriptions;
            if (scopeSelect.value === 'cookie') {
                if (domainInput.value.trim()) body.domain = domainInput.value.trim();
                if (pathInput.value.trim()) body.path = pathInput.value.trim();
                body.secure = !!secureCb.checked;
                body.sameSite = sameSiteSelect.value;
            }
            if (isEdit) {
                body.id = item.id;
                if (id !== item.id) body.newId = id;
            }

            saveBtn.disabled = true;
            try {
                var r = await api(isEdit ? 'editStorageItem' : 'addStorageItem', 'POST', body);
                if (!r || !r.ok) {
                    errBox.show(_serverWords(r, QSPanelWords.t('common.saveFailed')));
                    saveBtn.disabled = false;
                    return;
                }
                closeModal();
                await refresh();
                // A renamed key's pencil is the one that took the edited one's place.
                var renamed = body.newId ? QSDom.byFocusKey('edit:' + body.newId) : null;
                if (renamed) renamed.focus();
                if (scan.data) await runScan();
            } catch (e) {
                errBox.show((e && e.message) || QSPanelWords.t('storage.errors.network'));
                saveBtn.disabled = false;
            }
        });
        _filterControls(dialog);
        _showModal(dialog);
    }

    /** A dialog's error line, in its flow: show() brings it into view above the sticky buttons. */
    function _renderModalError() {
        var el = QSDom.el('p', { class: 'storage-modal-error', role: 'alert', hidden: 'hidden' });
        return {
            el: el,
            show: function (text) {
                el.textContent = text;
                el.hidden = false;
                if (typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' });
            },
            clear: function () { el.textContent = ''; el.hidden = true; },
        };
    }

    /**
     * The item dialog's description, one language at a time: the project's languages in a picker beside the field
     * (a project of two or more, a role that runs the dialog's command), which moves the page's language with it;
     * what was typed in a language is kept while another shows, until Save. changes(isEdit) answers {code: text}:
     * on an edit each language whose text differs from its word ('' removes it), on an add each language typed in.
     *
     * @param {?string} id       the item edited (null for a new one)
     * @param {string}  command  the dialog's command (its role mark)
     */
    function _renderDescriptionField(id, command) {
        var drafts = {};
        var shown = state.lang;
        var inputId = 'storage-desc-' + Date.now();
        var stored = function (code) { return id === null ? '' : storedDescription(id, code); };
        var input = QSDom.el('input', {
            type: 'text', class: 'admin-input storage-desc-row__input', autocomplete: 'off', id: inputId,
            placeholder: QSPanelWords.t('storage.languages.descriptionPlaceholder'),
        });
        var hint = _renderHint('');
        function showLanguage(code) {
            shown = code;
            var word = stored(code);
            input.disabled = word === null && !Object.prototype.hasOwnProperty.call(drafts, code);
            input.value = Object.prototype.hasOwnProperty.call(drafts, code) ? drafts[code] : (word || '');
            hint.textContent = word === null
                ? QSPanelWords.t('storage.languages.unread', { language: langName(code) })
                : multilingual() ? QSPanelWords.t('storage.languages.descriptionHint')
                    : QSPanelWords.t('storage.languages.descriptionHintOne');
        }
        input.addEventListener('input', function () { drafts[shown] = input.value; });
        var row = QSDom.el('div', { class: 'storage-desc-row' });
        if (multilingual() && window.QuickSiteAdmin && window.QuickSiteAdmin.canRun(command)) {
            var picker = _mark(_renderLanguageSelect(shown), command);
            picker.classList.add('storage-desc-row__lang');
            picker.setAttribute('aria-label', QSPanelWords.t('storage.languages.descriptionLanguage'));
            picker.title = QSPanelWords.t('storage.languages.descriptionLanguage');
            picker.addEventListener('change', function () {
                showLanguage(picker.value);
                setLanguage(picker.value);
            });
            row.appendChild(picker);
        }
        row.appendChild(input);
        var label = _renderLabel(QSPanelWords.t('storage.languages.descriptionLabel'));
        label.setAttribute('for', inputId);
        showLanguage(shown);
        return {
            group: _renderGroup(label, row, hint),
            changes: function (isEdit) {
                var out = {};
                Object.keys(drafts).forEach(function (code) {
                    var text = String(drafts[code]).trim();
                    var word = stored(code);
                    if (isEdit) {
                        if (word !== null && text !== String(word).trim()) out[code] = text;
                    } else if (text !== '') {
                        out[code] = text;
                    }
                });
                return out;
            },
        };
    }

    function openAddModal() { _openModal('add', null); }
    function openEditModal(item) { _openModal('edit', item); }

    // ====================================================================
    // Delete confirmation
    // ====================================================================

    /**
     * A key's delete, asked first in the panel's one confirm: the question names the key, the red button says the
     * verb, and a refusal stays in the dialog in the server's words. The list is drawn again before the dialog closes,
     * so the focus goes to the next key's bin.
     */
    async function openDeleteConfirm(item) {
        var deleted = await QuickSiteUtils.confirm(QSPanelWords.t('storage.dialogs.deleteKeyText'), {
            title: QSPanelWords.t('storage.dialogs.deleteKey', { id: item.id }),
            type: 'danger',
            confirmText: QSPanelWords.t('storage.dialogs.delete'),
            action: async function () {
                var r = await api('deleteStorageItem', 'POST', { id: item.id });
                if (!r || !r.ok) return { ok: false, message: _serverWords(r, QSPanelWords.t('storage.dialogs.deleteFailed')) };
                await refresh();
                return true;
            },
        });
        if (deleted && scan.data) await runScan();
    }

    // ====================================================================
    // Scan / reconcile
    // ====================================================================

    // A labelled group of location chips ("writes" / "reads" / "clears" + the
    // page/component/menu labels the scan engine returns). Null when empty.
    function _renderRefChips(label, list) {
        if (!list || !list.length) return null;
        var wrap = QSDom.el('span', { class: 'storage-refs' });
        wrap.appendChild(QSDom.el('span', { class: 'storage-refs__label', text: label }));
        list.forEach(function (loc) {
            wrap.appendChild(QSDom.el('span', { class: 'storage-loc', text: loc }));
        });
        return wrap;
    }

    // One row in a scan bucket. `opts.declare` adds the one-click Declare button
    // (incomplete bucket only).
    function _renderScanRow(row, opts) {
        var r = QSDom.el('div', { class: 'storage-scan-row' });

        var mainCol = QSDom.el('div', { class: 'storage-scan-row__main' });
        var head = QSDom.el('div', { class: 'storage-scan-row__head' });
        head.appendChild(QSDom.el('span', { class: 'storage-card__id', text: row.id }));
        var scope = row.inferredScope || row.scope;
        if (scope) head.appendChild(_renderPill(scope, 'scope'));
        if (row.category) head.appendChild(_renderPill(_categoryWord(row.category), 'cat-' + row.category));
        mainCol.appendChild(head);

        var refs = QSDom.el('div', { class: 'storage-scan-row__refs' });
        var w = _renderRefChips(QSPanelWords.t('storage.scan.writes'), row.writers);
        var c = _renderRefChips(QSPanelWords.t('storage.scan.clears'), row.clearers);
        var rd = _renderRefChips(QSPanelWords.t('storage.scan.reads'), row.readers);
        if (w) refs.appendChild(w);
        if (c) refs.appendChild(c);
        if (rd) refs.appendChild(rd);
        if (refs.childNodes.length) mainCol.appendChild(refs);
        r.appendChild(mainCol);

        if (opts && opts.declare) {
            var act = QSDom.el('div', { class: 'storage-scan-row__action' });
            var btn = _mark(QSDom.el('button', {
                class: 'admin-btn admin-btn--primary',
                type: 'button', text: QSPanelWords.t('storage.scan.declare'), 'data-focus-key': 'declare:' + row.id,
            }), 'addStorageItem');
            btn.addEventListener('click', function () { declareIncomplete(row, btn); });
            act.appendChild(btn);
            r.appendChild(act);
        }
        return r;
    }

    /** A bucket's head: its title (a counted word, the bucket's size in it) and what the bucket means. */
    function _renderScanSecHead(title, subtitle) {
        var head = QSDom.el('div', { class: 'storage-scan-sec__head' });
        head.appendChild(QSDom.el('h3', { class: 'storage-scan-sec__title', text: title }));
        if (subtitle) head.appendChild(QSDom.el('p', { class: 'storage-scan-sec__sub', text: subtitle }));
        return head;
    }

    // Incomplete = used-but-undeclared (the GDPR gap). Most prominent; always open.
    function _renderIncompleteSection(list) {
        var sec = QSDom.el('section', { class: 'storage-scan-sec storage-scan-sec--incomplete' });
        sec.appendChild(_renderScanSecHead(QSPanelWords.count('storage.scan.undeclared', list.length),
            QSPanelWords.t('storage.scan.undeclaredHint')));
        if (!list.length) {
            sec.appendChild(QSDom.el('div', { class: 'storage-scan__ok-note', text: QSPanelWords.t('storage.scan.allDeclared') }));
        } else {
            list.forEach(function (row) { sec.appendChild(_renderScanRow(row, { declare: true })); });
        }
        return sec;
    }

    // Dangling reads = read by a binding but never written. Not a GDPR item; a
    // likely-leftover flag. No Declare.
    function _renderDanglingSection(list) {
        var sec = QSDom.el('section', { class: 'storage-scan-sec' });
        sec.appendChild(_renderScanSecHead(QSPanelWords.count('storage.scan.dangling', list.length),
            QSPanelWords.t('storage.scan.danglingHint')));
        list.forEach(function (row) { sec.appendChild(_renderScanRow(row, null)); });
        return sec;
    }

    // OK / Orphan — collapsible, ignorable-by-default sections. `title` is the bucket's counted word.
    function _renderCollapsibleSection(key, title, list, subtitle) {
        var sec = QSDom.el('section', { class: 'storage-scan-sec storage-scan-sec--' + key });
        var toggle = QSDom.el('button', { class: 'storage-scan-sec__toggle', type: 'button' });
        var open = !!scan.expanded[key];
        toggle.appendChild(QSDom.el('span', { class: 'storage-scan-sec__chevron', text: open ? '▾' : '▸' }));
        toggle.appendChild(QSDom.el('span', { text: title }));
        toggle.addEventListener('click', function () {
            scan.expanded[key] = !scan.expanded[key];
            renderScanPanel();
        });
        sec.appendChild(toggle);
        if (open) {
            if (subtitle) sec.appendChild(QSDom.el('p', { class: 'storage-scan-sec__sub', text: subtitle }));
            if (!list.length) {
                sec.appendChild(QSDom.el('div', { class: 'storage-scan__empty-note', text: QSPanelWords.t('storage.scan.none') }));
            } else {
                list.forEach(function (row) { sec.appendChild(_renderScanRow(row, null)); });
            }
        }
        return sec;
    }

    function _renderScanHeader() {
        var h = QSDom.el('div', { class: 'storage-scan__header' });
        h.appendChild(QSDom.el('h2', { class: 'storage-scan__title', text: QSPanelWords.t('storage.scan.title') }));
        h.appendChild(QSControls.close(QSPanelWords.t('storage.scan.dismiss'), dismissScan));
        return h;
    }

    function dismissScan() {
        scan.data = null;
        scan.error = null;
        var root = document.getElementById('storage-scan-panel');
        if (root) { root.textContent = ''; root.hidden = true; }
    }

    function renderScanPanel() {
        var root = document.getElementById('storage-scan-panel');
        if (!root) return;
        var focusBack = root.contains(document.activeElement) ? QSDom.rememberFocus() : null;
        _drawScanPanel(root);
        if (focusBack) focusBack.restore();
    }

    function _drawScanPanel(root) {
        root.textContent = '';

        if (!scan.loading && !scan.error && !scan.data) {
            root.hidden = true;
            return;
        }
        root.hidden = false;
        root.appendChild(_renderScanHeader());

        if (scan.loading) {
            root.appendChild(QSDom.el('div', { class: 'storage-scan__loading', text: QSPanelWords.t('storage.scan.scanning') }));
            return;
        }
        if (scan.error) {
            root.appendChild(QSDom.el('div', { class: 'storage-scan__error', text: scan.error }));
            return;
        }

        var b = scan.data.buckets || {};
        root.appendChild(_renderIncompleteSection(b.incomplete || []));
        if ((b.dangling_read || []).length) {
            root.appendChild(_renderDanglingSection(b.dangling_read));
        }
        var ok = b.ok || [];
        var orphans = b.orphan || [];
        root.appendChild(_renderCollapsibleSection('ok', QSPanelWords.count('storage.scan.ok', ok.length), ok,
            QSPanelWords.t('storage.scan.okHint')));
        root.appendChild(_renderCollapsibleSection('orphan', QSPanelWords.count('storage.scan.orphans', orphans.length), orphans,
            QSPanelWords.t('storage.scan.orphansHint')));
        _filterControls(root);
    }

    async function runScan() {
        scan.loading = true;
        scan.error = null;
        renderScanPanel();
        try {
            var r = await api('scanStorageUsage', 'POST');
            if (!r || !r.ok) {
                // A refused scan says so: it never reads as a scan that found every key declared.
                scan.error = _serverWords(r, QSPanelWords.t('storage.scan.failed'));
            } else {
                var payload = (r.data && (r.data.data || r.data)) || {};
                scan.data = (payload && payload.buckets)
                    ? payload
                    : { buckets: { ok: [], incomplete: [], dangling_read: [], orphan: [] }, counts: {} };
            }
        } catch (e) {
            console.warn('[storage] scan failed:', e);
            scan.error = (e && e.message) || QSPanelWords.t('storage.scan.failed');
        } finally {
            scan.loading = false;
        }
        renderScanPanel();
    }

    // One-click Declare for an incomplete key (safe defaults: inferred scope +
    // category 'functional'; refine later on the list). When the scope couldn't
    // be inferred, fall back to the prefilled add modal since scope is required.
    async function declareIncomplete(row, btn) {
        if (!row.inferredScope) {
            _openModal('add', null, { id: row.id });
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = QSPanelWords.t('storage.scan.declaring'); }
        try {
            var r = await api('addStorageItem', 'POST', {
                id: row.id,
                scope: row.inferredScope,
                category: 'functional',
            });
            if (!r || !r.ok) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = QSPanelWords.t('storage.scan.declare');
                    var msg = (r && r.data && r.data.message) || QSPanelWords.t('storage.scan.declareFailed');
                    var err = QSDom.el('span', { class: 'storage-scan-row__err', text: msg });
                    if (btn.parentNode) btn.parentNode.appendChild(err);
                }
                return;
            }
            await refresh();
            await runScan();
        } catch (e) {
            console.warn('[storage] declare failed:', e);
            if (btn) { btn.disabled = false; btn.textContent = QSPanelWords.t('storage.scan.declare'); }
        }
    }

    // ====================================================================
    // Generate consent layer
    // ====================================================================

    function _declaredNonEssentialCategories() {
        var set = {};
        state.items.forEach(function (it) {
            var c = it.category || 'functional';
            if (c !== 'essential') set[c] = true;
        });
        return Object.keys(set);
    }

    async function openGenerateConsentModal() {
        var dialog = _renderModalShell(QSPanelWords.t('storage.consent.title'));
        // All body content lives in one container we can clear + rebuild as the
        // state changes (after Generate / Update / Delete), so the modal always
        // reflects the real status (e.g. locks the route once a page exists).
        var content = QSDom.el('div', {});
        dialog.appendChild(content);
        content.appendChild(QSDom.el('p', { class: 'admin-hint', text: QSPanelWords.t('storage.consent.loading') }));
        _showModal(dialog);
        await _refreshConsentContent(content, '');
    }

    // Re-fetch consent status and (re)render the modal body, optionally showing
    // a success message at the top.
    async function _refreshConsentContent(content, message) {
        var status = {};
        try {
            var s = await api('getConsentStatus', 'POST');
            status = (s && s.data && (s.data.data || s.data)) || {};
        } catch (e) { /* treat as fresh */ }
        if (!content.isConnected) return; // modal closed during the fetch
        var dialog = content.closest('.storage-modal-dialog');
        _renderConsentContent(content, status, message);
        // The content drawn again under the focus: it goes to its first control.
        if (dialog && !dialog.contains(document.activeElement)) {
            var first = QuickSiteUtils.focusables(content)[0];
            if (first) first.focus();
        }
    }

    /**
     * A site that declares no non-essential key: the warning, its limit and the offer. `box` is the
     * tick box, which the caller reads when it generates.
     */
    function _renderEssentialBannerOffer(box) {
        var offer = QSDom.el('div', { class: 'admin-alert admin-alert--warning storage-consent-offer' });
        offer.appendChild(QSDom.el('p', { text: QSPanelWords.t('storage.consent.essentialOnly') }));
        offer.appendChild(QSDom.el('p', { text: QSPanelWords.t('storage.consent.essentialOnlyLimit') }));
        var label = QSDom.el('label', { class: 'storage-consent-check' });
        label.appendChild(box);
        label.appendChild(document.createTextNode(' ' + QSPanelWords.t('storage.consent.essentialBanner')));
        offer.appendChild(label);
        return offer;
    }

    function _renderConsentContent(content, status, message) {
        content.textContent = '';

        // A policy page is "present" only when its route still exists.
        var hasPage = !!(status && status.policyRoute && status.policyRouteExists);
        var staleRoute = (status && status.policyRoute && !status.policyRouteExists)
            ? String(status.policyRoute).replace(/^\/+/, '') : '';

        var cats = _declaredNonEssentialCategories();
        content.appendChild(_renderHint(QSPanelWords.t('storage.consent.generateHint')));
        var essentialBannerBox = null;
        if (cats.length) {
            content.appendChild(QSDom.el('p', {
                class: 'admin-hint',
                text: QSPanelWords.t('storage.consent.popupRows', { categories: QSPanelWords.list(cats.map(_categoryWord), 'unit') }),
            }));
        } else {
            essentialBannerBox = QSDom.el('input', { type: 'checkbox' });
            essentialBannerBox.checked = !!(status && status.essentialBanner);
            content.appendChild(_renderEssentialBannerOffer(essentialBannerBox));
        }

        // Policy-page section: locked (display only) when a page exists, an
        // input otherwise — so an existing page can't be duplicated by a typo.
        var routeInput = null;
        if (hasPage) {
            var fixed = QSDom.el('div', { class: 'storage-consent-policy' });
            fixed.appendChild(QSDom.el('span', { class: 'admin-label', text: QSPanelWords.t('storage.consent.policyPage') }));
            fixed.appendChild(QSDom.el('code', { class: 'storage-consent-policy__route', text: status.policyRoute }));
            content.appendChild(_renderGroup(null, fixed,
                _renderHint(QSPanelWords.t('storage.consent.policyPageHint'))));
        } else {
            routeInput = QSDom.el('input', {
                type: 'text', class: 'admin-input', autocomplete: 'off',
                placeholder: QSPanelWords.t('storage.consent.routePlaceholder'),
                value: staleRoute,
            });
            content.appendChild(_renderGroup(_renderLabel(QSPanelWords.t('storage.consent.route')), routeInput,
                _renderHint(staleRoute
                    ? QSPanelWords.t('storage.consent.staleRoute', { route: staleRoute })
                    : QSPanelWords.t('storage.consent.routeHint'))));
        }

        // The banner shows once the layer is on and the site asks its visitors anything.
        var bannerShows = !!(status && status.enabled && status.asks !== false);

        // Asking every visitor again (the stored version bumped) is offered while the banner shows; a
        // change to a category's keys asks again for that category by itself.
        var askAgainCb = null;
        if (bannerShows) {
            askAgainCb = QSDom.el('input', { type: 'checkbox' });
            var askAgainLabel = QSDom.el('label', { class: 'storage-consent-check' });
            askAgainLabel.appendChild(askAgainCb);
            askAgainLabel.appendChild(document.createTextNode(' ' + QSPanelWords.t('storage.consent.askAgain')));
            content.appendChild(_renderGroup(null, askAgainLabel, _renderHint(QSPanelWords.t('storage.consent.askAgainHint'))));
        }

        var resultBox = QSDom.el('div', { class: 'storage-modal-actions__error' });
        resultBox.style.color = 'var(--admin-success)';
        if (message) { resultBox.textContent = message; } else { resultBox.hidden = true; }
        content.appendChild(resultBox);
        var errBox = QSDom.el('div', { class: 'storage-modal-actions__error' });
        errBox.hidden = true;
        content.appendChild(errBox);

        var actions = QSDom.el('div', { class: 'storage-modal-actions' });

        if (hasPage) {
            var delBtn = _mark(QSDom.el('button', { class: 'admin-btn admin-btn--danger', type: 'button', text: QSPanelWords.t('storage.consent.deletePage') }), 'deleteCookiePolicy');
            delBtn.addEventListener('click', async function () {
                if (!(await QuickSiteUtils.confirm(QSPanelWords.t('storage.dialogs.deletePage', { route: status.policyRoute }), {
                    type: 'danger', confirmText: QSPanelWords.t('storage.dialogs.delete'),
                }))) return;
                delBtn.disabled = true; errBox.hidden = true;
                try {
                    var dr = await api('deleteCookiePolicy', 'POST', {});
                    if (!dr || !dr.ok) {
                        errBox.hidden = false;
                        errBox.textContent = (dr && dr.data && dr.data.message) || QSPanelWords.t('storage.consent.deletePageFailed');
                        delBtn.disabled = false;
                        return;
                    }
                    // The command takes the page's link out of the banner: said only where a banner shows.
                    await _refreshConsentContent(content, bannerShows
                        ? QSPanelWords.t('storage.consent.pageDeleted')
                        : QSPanelWords.t('storage.consent.pageDeletedNoBanner'));
                } catch (e) {
                    errBox.hidden = false;
                    errBox.textContent = (e && e.message) || QSPanelWords.t('storage.errors.network');
                    delBtn.disabled = false;
                }
            });
            actions.appendChild(delBtn);
        }

        var genBtn = _mark(QSDom.el('button', {
            class: 'admin-btn admin-btn--primary', type: 'button',
            text: hasPage ? QSPanelWords.t('storage.consent.update') : QSPanelWords.t('storage.consent.generate'),
        }), 'generateConsentLayer,generateCookiePolicy');
        actions.appendChild(genBtn);
        content.appendChild(actions);
        _filterControls(content);

        genBtn.addEventListener('click', function () {
            var policyRoute = hasPage
                ? String(status.policyRoute).replace(/^\/+|\/+$/g, '')
                : (routeInput ? routeInput.value.trim().replace(/^\/+|\/+$/g, '') : '');
            _runConsentGenerate({
                policyRoute: policyRoute, isUpdate: hasPage,
                askAgain: !!(askAgainCb && askAgainCb.checked),
                essentialBanner: essentialBannerBox ? essentialBannerBox.checked : null,
                content: content, genBtn: genBtn, resultBox: resultBox, errBox: errBox,
            });
        });
    }

    async function _runConsentGenerate(opts) {
        var genBtn = opts.genBtn, resultBox = opts.resultBox, errBox = opts.errBox;
        var policyRoute = opts.policyRoute;
        errBox.hidden = true; errBox.textContent = '';
        resultBox.hidden = true;
        genBtn.disabled = true;
        try {
            // 1. Policy page FIRST — it owns consent.json.policyRoute, so the
            //    route is only wired up when a page is actually created. If the
            //    user cancels an overwrite, nothing points at that route.
            var policyMsg = '';   // the policy page's outcome, one sentence
            var toastType = 'success';
            if (policyRoute) {
                var pr = await api('generateCookiePolicy', 'POST', opts.isUpdate ? { route: policyRoute, overwrite: true } : { route: policyRoute });
                var pd = (pr && pr.data && (pr.data.data || pr.data)) || {};
                if (pr && !pr.ok && pd.needsConfirm) {
                    if (await QuickSiteUtils.confirm(QSPanelWords.t('storage.dialogs.overwritePage', { route: policyRoute }), {
                        type: 'danger', confirmText: QSPanelWords.t('storage.dialogs.overwrite'),
                    })) {
                        pr = await api('generateCookiePolicy', 'POST', { route: policyRoute, overwrite: true });
                        pd = (pr && pr.data && (pr.data.data || pr.data)) || {};
                    } else {
                        pr = null;
                        policyMsg = QSPanelWords.t('storage.consent.pageSkipped');
                        toastType = 'warning';
                    }
                }
                if (pr && pr.ok) {
                    var pageRoute = pd.route || ('/' + policyRoute);
                    policyMsg = pd.overwritten
                        ? QSPanelWords.t('storage.consent.pageUpdated', { route: pageRoute })
                        : QSPanelWords.t('storage.consent.pageCreated', { route: pageRoute });
                } else if (pr) {
                    policyMsg = QSPanelWords.t('storage.consent.pageFailed', {
                        reason: (pr.data && pr.data.message) || QSPanelWords.t('common.unknownError'),
                    });
                    toastType = 'warning';
                }
            } else {
                policyMsg = QSPanelWords.t('storage.consent.noPage');
                toastType = 'warning';
            }

            // 2. Consent layer — banner reads the (now-updated) policy route.
            // The author's answer goes only when the offer was on screen: absent keeps what is stored.
            var layerBody = {};
            if (opts.askAgain) layerBody.askAgain = true;
            if (opts.essentialBanner !== null) layerBody.essentialBanner = opts.essentialBanner;
            var r = await api('generateConsentLayer', 'POST', layerBody);
            if (!r || !r.ok) {
                errBox.hidden = false;
                errBox.textContent = (r && r.data && r.data.message) || QSPanelWords.t('storage.consent.generateFailed');
                genBtn.disabled = false;
                return;
            }
            var d = (r.data && (r.data.data || r.data)) || {};
            var langs = d.languagesSeeded ? Object.keys(d.languagesSeeded) : [];

            // The outcome, one word per sentence that applies, the sentences in a row.
            var sentences = [opts.isUpdate
                ? QSPanelWords.t('storage.consent.layerUpdated')
                : QSPanelWords.t('storage.consent.layerGenerated')];
            if (langs.length) sentences.push(QSPanelWords.t('storage.consent.seeded', { languages: QSPanelWords.list(langs, 'unit') }));
            if (d.askedAgain) sentences.push(QSPanelWords.t('storage.consent.askedAgain'));
            sentences.push(policyMsg);
            sentences.push(d.asks === false
                ? QSPanelWords.t('storage.consent.noBanner')
                : QSPanelWords.t('storage.consent.previewBanner'));
            var msg = sentences.join(' ');
            QuickSiteUtils.showToast(msg, toastType);
            // Re-render from fresh status — flips to the locked route + Update/
            // Delete once a page exists, so a re-generate can't change the route.
            await _refreshConsentContent(opts.content, msg);
        } catch (e) {
            errBox.hidden = false;
            errBox.textContent = (e && e.message) || QSPanelWords.t('storage.errors.network');
            genBtn.disabled = false;
        }
    }

    // ====================================================================
    // Init
    // ====================================================================

    function init() {
        var addBtn = document.getElementById('btn-add-storage-item');
        if (addBtn) addBtn.addEventListener('click', openAddModal);
        var refreshBtn = document.getElementById('btn-refresh-storage');
        if (refreshBtn) refreshBtn.addEventListener('click', refresh);
        var scanBtn = document.getElementById('btn-scan-storage');
        if (scanBtn) scanBtn.addEventListener('click', runScan);
        var genConsentBtn = document.getElementById('btn-generate-consent');
        if (genConsentBtn) genConsentBtn.addEventListener('click', openGenerateConsentModal);
        refresh();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
