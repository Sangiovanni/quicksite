/**
 * preview-translation.js — Translation Manager panel
 *
 * Mounted as a sibling to PreviewJsInteractions. Manages the in-editor
 * Translation Manager panel: per-language view, scope filter (whole site /
 * page / layout / component / the site's own texts), status chips (used /
 * unset / unused), substring filter, inline value editor, delete +
 * bulk-remove-unused (each delete asked in the panel's one confirm).
 *
 * Activation surface: sidebar "Translations" tool → setMode('translation')
 * in preview.js shows #contextual-translation and calls
 * PreviewTranslation.enter(), which reads the data again on every entry.
 *
 * It reads and writes through the panel's one list of a site's words,
 * QSSiteWords (js/core/site-words.js), as the Translations page does: the
 * languages, each language's words, the keys' sources and their states per
 * language come from one reading, so a language switch reads nothing; a
 * write goes through QSSiteWords.write() / remove(), and every write of the
 * site's words announced (quicksite:translations-changed) redraws the panel
 * while it is open.
 *
 * One editing language: the panel opens on the toolbar's language and keeps
 * its own picker; a change in either moves both (preview.js connect()).
 */
window.PreviewTranslation = (function () {
    'use strict';

    // ──────────────────────────── State ─────────────────────────────────

    // What the panel shows of the one list (QSSiteWords): read again on every
    // enter() and on every change announced while it is open; a language
    // switch takes the language from the list as it stands.
    var _grouped = null;       // { used: [...], unset: [...], unused: [...] } for current lang
    var _translations = null;  // full translations object for current lang
    var _keysBySource = null;  // { 'page:home': ['home.title', ...], 'component:card': [...], ... }
    var _availableLangs = [];  // ['en', 'fr', ...] minus 'default'
    var _currentLang = null;
    var _currentScope = 'site';   // 'site' | 'page:<name>' | 'component:<name>'
    var _statusFilter = { used: true, unset: true, unused: true };
    var _substringFilter = '';
    var _expandedKey = null;   // the row whose inline editor is open (one at a time)
    var _busyKey = null;       // key whose save is in flight
    var _actionError = null;   // { key, message } for the open editor's refused save
    var _entered = false;      // set by the first enter(): only that one shows the loading state
    var _loadSeq = 0;          // the latest load; an older load's answer is dropped
    var _active = false;       // between enter() and leave(): a change announced redraws the panel
    var _pendingReload = false; // a change announced while a word was edited: read once the editor closes
    var _hooks = { language: null, setLanguage: null };   // the toolbar's language (connect())

    // DOM refs — bound on init().
    var _section = null;
    var _langSelect = null;
    var _scopeSelect = null;
    var _coverageBar = null;
    var _filterInput = null;
    var _statusChips = null;
    var _countUsed = null;
    var _countUnset = null;
    var _countUnused = null;
    var _rowsContainer = null;
    var _loadingEl = null;
    var _emptyEl = null;
    var _removeUnusedBtn = null;

    // ──────────────────────────── Init ──────────────────────────────────

    function init() {
        _section = document.getElementById('contextual-translation');
        if (!_section) {
            console.warn('[PreviewTranslation] #contextual-translation missing — panel disabled');
            return;
        }
        _langSelect = document.getElementById('translation-lang');
        _scopeSelect = document.getElementById('translation-scope');
        _coverageBar = document.getElementById('translation-coverage');
        _filterInput = document.getElementById('translation-filter');
        _statusChips = document.getElementById('translation-status-chips');
        _countUsed = document.getElementById('translation-count-used');
        _countUnset = document.getElementById('translation-count-unset');
        _countUnused = document.getElementById('translation-count-unused');
        _rowsContainer = document.getElementById('translation-rows');
        _loadingEl = document.getElementById('translation-loading');
        _emptyEl = document.getElementById('translation-empty');
        _removeUnusedBtn = document.getElementById('translation-remove-unused');

        // Wire input events (no data fetch yet — first fetch fires on enter()).
        if (_langSelect) {
            _langSelect.addEventListener('change', function () {
                _currentLang = _langSelect.value || _currentLang;
                _applyLanguage();
                // One editing language: the toolbar follows, and the frame shows this language.
                if (_hooks.setLanguage) _hooks.setLanguage(_currentLang);
            });
        }
        if (_scopeSelect) {
            _scopeSelect.addEventListener('change', function () {
                _currentScope = _scopeSelect.value || 'site';
                _render();  // scope filter is client-side; no refetch
            });
        }
        if (_filterInput) {
            _filterInput.addEventListener('input', function () {
                _substringFilter = (_filterInput.value || '').trim().toLowerCase();
                _render();
            });
        }
        if (_statusChips) {
            _statusChips.querySelectorAll('input[type="checkbox"][data-status]').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    _statusFilter[cb.dataset.status] = cb.checked;
                    _render();
                });
            });
        }
        if (_removeUnusedBtn) {
            _removeUnusedBtn.addEventListener('click', _handleRemoveUnused);
        }
        // A write of the site's words anywhere (this panel, a picker's Create, inline edit, another tab):
        // the panel shows the words as they are now, a word being edited kept until its editor closes.
        if (window.QSSiteWords) {
            window.addEventListener(QSSiteWords.EVENT, function () {
                if (!_active) return;
                if (_busyKey || _expandedKey) {
                    _pendingReload = true;
                    return;
                }
                _load(true);
            });
        }
    }

    // ──────────────────────────── Public API ────────────────────────────

    /**
     * Called by preview.js when setMode('translation') fires. Fetches the
     * data on every entry: a key written elsewhere since the last opening
     * (the wizards' key picker, the Text tool, Add Element → Text) shows
     * without a reload. The first entry shows the loading state; a later
     * one keeps the rows on screen until the new data is in.
     */
    function enter() {
        if (!_section) return;
        _active = true;
        var quiet = _entered;
        _entered = true;
        _load(quiet);
    }

    /**
     * Called by preview.js when leaving translation mode. Nothing to tear
     * down: the rows stay on screen at the next entry while enter() reads.
     */
    function leave() {
        _active = false;
    }

    /**
     * preview.js hands over the toolbar's language: hooks.language() reads it,
     * hooks.setLanguage(code) moves it (and the frame) when this panel's own
     * picker changes.
     */
    function connect(hooks) {
        _hooks.language = hooks && typeof hooks.language === 'function' ? hooks.language : null;
        _hooks.setLanguage = hooks && typeof hooks.setLanguage === 'function' ? hooks.setLanguage : null;
    }

    /** The toolbar's language changed while the panel is open: the panel shows it. */
    function setLanguage(code) {
        if (!code || code === _currentLang || _availableLangs.indexOf(code) === -1) return;
        _currentLang = code;
        if (_langSelect) _langSelect.value = code;
        _expandedKey = null;
        _actionError = null;
        _applyLanguage();
    }

    // ──────────────────────────── Data fetch ────────────────────────────

    /**
     * The data load of every enter() and of every change announced while the
     * panel is open: the one list of the site's words (QSSiteWords), with the
     * keys' sources and their states per language. A language switch reads
     * nothing more (_applyLanguage()). `quiet` keeps the rows on screen
     * instead of the loading state.
     *
     * On a one-language site the only file the pages read is default.json,
     * so that is the one the panel shows (the list's `default`); on a
     * multilingual one, each language's own file.
     */
    async function _load(quiet) {
        var seq = ++_loadSeq;
        if (!quiet) {
            _showLoading(true);
            _showEmpty(false);
        }
        var list = window.QSSiteWords ? await QSSiteWords.read({ states: true }) : null;
        if (seq !== _loadSeq) return;  // a newer load answers instead
        var states = list ? QSSiteWords.currentStates() : null;
        if (!list || !states) {
            _showLoading(false);
            _showReadFailed();
            return;
        }
        _availableLangs = list.languages.slice();
        _keysBySource = states.sources || {};
        _populateLangSelect();
        _populateScopeSelect();
        // Hide the language selector + label when there's no real
        // choice (monolingual, or multilingual with a single lang).
        _setLangPickerVisible(_availableLangs.length > 1);

        if (_availableLangs.length === 0) {
            _showLoading(false);
            _showEmpty(true);
            return;
        }
        // The toolbar's language, which is the one remembered for the project; the default language
        // where it names none the site has.
        var wanted = list.multilingual ? (_hooks.language ? _hooks.language() : _currentLang) : 'default';
        _currentLang = wanted && _availableLangs.indexOf(wanted) !== -1 ? wanted
            : (_availableLangs.indexOf(list.defaultLanguage) !== -1 ? list.defaultLanguage : _availableLangs[0]);
        if (_langSelect) _langSelect.value = _currentLang;
        _applyLanguage();
    }

    /** The list could not be read and nothing was read before: the panel says so instead of a stale view. */
    function _showReadFailed() {
        if (!_rowsContainer) return;
        _showEmpty(false);
        var p = document.createElement('p');
        p.className = 'preview-contextual-translation__no-matches';
        p.textContent = QSPanelWords.t('translations.loadFailedNone');
        _rowsContainer.replaceChildren(p);
    }

    /** Show the current language from the list as it stands (no read). */
    function _applyLanguage() {
        var list = window.QSSiteWords ? QSSiteWords.current() : null;
        var states = window.QSSiteWords ? QSSiteWords.currentStates() : null;
        if (!list || !states || !_currentLang) return;
        var g = (states.byLanguage && states.byLanguage[_currentLang]) || { used: [], unset: [], unused: [] };
        _grouped = { used: g.used, unset: g.unset, unused: g.unused };
        _translations = list.words[_currentLang] || {};
        _render();
    }

    function _setLangPickerVisible(visible) {
        if (_langSelect) _langSelect.style.display = visible ? '' : 'none';
        if (_section) {
            var label = _section.querySelector('label[for="translation-lang"]');
            if (label) label.style.display = visible ? '' : 'none';
        }
    }

    function _populateLangSelect() {
        if (!_langSelect) return;
        _langSelect.textContent = '';
        _langSelect.disabled = false;
        _availableLangs.forEach(function (lang) {
            var opt = document.createElement('option');
            opt.value = lang;
            opt.textContent = lang.toUpperCase();
            _langSelect.appendChild(opt);
        });
        if (_availableLangs.length === 0) {
            // No languages declared. Show a placeholder so the picker isn't
            // confusing — the empty state below will explain.
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '—';
            _langSelect.appendChild(placeholder);
            _langSelect.disabled = true;
        }
    }

    function _populateScopeSelect() {
        if (!_scopeSelect) return;
        _scopeSelect.textContent = '';
        // Always include "Whole site" first.
        var siteOpt = document.createElement('option');
        siteOpt.value = 'site';
        siteOpt.textContent = QSPanelWords.t('preview.translationScopeSite');
        _scopeSelect.appendChild(siteOpt);

        // The sources where the site names keys, grouped as the Translations page groups them
        // (QSSiteWords.sourceGroups()): the pages by their routes, the layout (menu, footer), the
        // components (their bare names), and the site's own texts (site:<name>), each named by its
        // word on the Translations page (translations.source.<name>).
        var groupLabels = {
            pages: QSPanelWords.t('preview.translationScopePages'),
            layout: QSPanelWords.t('preview.translationScopeLayout'),
            components: QSPanelWords.t('preview.translationScopeComponents'),
            site: QSPanelWords.t('translations.groupSite')
        };
        QSSiteWords.sourceGroups(_keysBySource).forEach(function (group) {
            var optgroup = document.createElement('optgroup');
            optgroup.label = groupLabels[group.group];
            group.sources.forEach(function (source) {
                var opt = document.createElement('option');
                opt.value = source.id;
                opt.textContent = group.group === 'site' ? QSPanelWords.t('translations.source.' + source.name) : source.name;
                optgroup.appendChild(opt);
            });
            _scopeSelect.appendChild(optgroup);
        });

        // A reload keeps the scope chosen, unless its source is gone (then the whole site).
        if (_currentScope !== 'site' && !Object.prototype.hasOwnProperty.call(_keysBySource, _currentScope)) {
            _currentScope = 'site';
        }
        _scopeSelect.value = _currentScope;
    }

    /**
     * Render the current cache (no fetch). Called on filter change, status
     * chip change, scope change. Updates coverage chrome + rebuilds rows.
     */
    function _render() {
        if (!_grouped) return;

        // Chip counts + coverage are scope-aware (also substring-aware).
        // The pool here is "entries after scope + substring filters" — status
        // is excluded because each chip IS the status filter, so its count
        // would otherwise depend on its own checked state.
        var entries = _buildEntries();
        var pool = _applyScopeAndSubstring(entries);
        var usedCount = 0, unsetCount = 0, unusedCount = 0;
        pool.forEach(function (e) {
            if (e.status === 'used') usedCount++;
            else if (e.status === 'unset') unsetCount++;
            else if (e.status === 'unused') unusedCount++;
        });
        // Coverage = used / (used + unset). By design, unused doesn't
        // distort the denominator. 0/0 → 100% (no work to do).
        var denominator = usedCount + unsetCount;
        var coveragePct = denominator > 0
            ? Math.round((usedCount / denominator) * 100)
            : 100;

        if (_coverageBar) {
            _coverageBar.textContent = '';
            var coverageText = document.createElement('span');
            coverageText.className = 'preview-contextual-translation__coverage-pct';
            coverageText.textContent = QSPanelWords.t('preview.translationCoverage', {
                pct: QSPanelWords.number(coveragePct),
                used: QSPanelWords.number(usedCount),
                total: QSPanelWords.number(denominator)
            });
            _coverageBar.appendChild(coverageText);
        }
        if (_countUsed) _countUsed.textContent = QSPanelWords.number(usedCount);
        if (_countUnset) _countUnset.textContent = QSPanelWords.number(unsetCount);
        if (_countUnused) _countUnused.textContent = QSPanelWords.number(unusedCount);

        // Site-wide totals (NOT scoped) drive the "no data" empty state
        // and the bulk remove-unused button. The button label says
        // "site-wide" + the empty state says "No translation keys yet" —
        // both need the global view, not the current scope.
        var siteTotalKeys = (_grouped.used || []).length
            + (_grouped.unset || []).length
            + (_grouped.unused || []).length;
        var siteUnusedCount = (_grouped.unused || []).length;

        if (siteTotalKeys === 0) {
            _showLoading(false);
            _showEmpty(true);
            if (_removeUnusedBtn) _removeUnusedBtn.disabled = true;
            if (_rowsContainer) _rowsContainer.textContent = '';
            return;
        }
        _showLoading(false);
        _showEmpty(false);
        if (_removeUnusedBtn) _removeUnusedBtn.disabled = siteUnusedCount === 0;

        _renderRows();
    }

    /**
     * Apply scope + substring filters (NOT status) to
     * an entry list. Used to compute chip counts + coverage % so they
     * reflect the user's drill-down view without depending on the chips'
     * own checked state.
     */
    function _applyScopeAndSubstring(entries) {
        var scopeSet = null;
        if (_currentScope !== 'site') {
            var sourceKeys = (_keysBySource && _keysBySource[_currentScope]) || [];
            scopeSet = {};
            sourceKeys.forEach(function (k) { scopeSet[k] = 1; });
        }
        var needle = _substringFilter;
        if (!scopeSet && !needle) return entries;
        return entries.filter(function (entry) {
            if (scopeSet && !scopeSet[entry.key]) return false;
            if (needle && entry.key.toLowerCase().indexOf(needle) === -1) return false;
            return true;
        });
    }

    // ──────────────────────────── Row rendering ─────────────────────────

    var _VALUE_TRUNCATE_AT = 100;

    /**
     * Build, filter, and render the row list. Row order: alphabetical by
     * key name across all statuses (chips narrow, sort stays stable so
     * keys don't jump around when toggling chips).
     */
    function _renderRows() {
        if (!_rowsContainer) return;
        _rowsContainer.textContent = '';

        var entries = _buildEntries();
        var filtered = _applyFilters(entries);

        if (filtered.length === 0) {
            var noMatch = document.createElement('p');
            noMatch.className = 'preview-contextual-translation__no-matches';
            noMatch.textContent = QSPanelWords.t('preview.translationNoMatches');
            _rowsContainer.appendChild(noMatch);
            return;
        }

        var frag = document.createDocumentFragment();
        filtered.forEach(function (entry) {
            frag.appendChild(_renderRow(entry));
            // The open editor, under its row.
            if (entry.key === _expandedKey) frag.appendChild(_renderEditor(entry));
        });
        _rowsContainer.appendChild(frag);

        // Auto-focus the textarea when an editor was just rendered.
        if (_expandedKey) {
            var ta = _rowsContainer.querySelector('.preview-contextual-translation__row-editor-textarea');
            if (ta) {
                ta.focus();
                // Place cursor at end so existing value is easy to edit.
                var len = ta.value.length;
                try { ta.setSelectionRange(len, len); } catch (e) { /* ignore */ }
            }
        }
    }

    /**
     * Build a unified entry list — { key, status, value } — across all
     * three groups. Used + unused get their value from _translations
     * (dot-notation lookup); unset get an empty string. Sorted
     * alphabetically by key name. The groups hold the keys themselves
     * (QSSiteWords' states).
     */
    function _buildEntries() {
        var entries = [];
        function push(items, status) {
            (items || []).forEach(function (key) {
                if (typeof key !== 'string') return;
                var value = status === 'unset' ? '' : (_resolveTranslation(key) || '');
                entries.push({ key: key, status: status, value: value });
            });
        }
        push(_grouped.used, 'used');
        push(_grouped.unset, 'unset');
        push(_grouped.unused, 'unused');
        entries.sort(function (a, b) {
            return a.key < b.key ? -1 : (a.key > b.key ? 1 : 0);
        });
        return entries;
    }

    /**
     * Apply scope, substring, and status filters to a built entry list.
     * Scope filter for non-'site': unused keys never match a specific
     * scope (they belong to no source by definition), so they drop out.
     */
    function _applyFilters(entries) {
        var scopeSet = null;
        if (_currentScope !== 'site') {
            var sourceKeys = (_keysBySource && _keysBySource[_currentScope]) || [];
            scopeSet = {};
            sourceKeys.forEach(function (k) { scopeSet[k] = 1; });
        }
        var needle = _substringFilter;
        return entries.filter(function (entry) {
            if (!_statusFilter[entry.status]) return false;
            if (scopeSet && !scopeSet[entry.key]) return false;
            if (needle && entry.key.toLowerCase().indexOf(needle) === -1) return false;
            return true;
        });
    }

    /**
     * Resolve a dot-notation key against _translations. Returns the
     * string leaf, or null if any segment is missing or the leaf isn't
     * a string. Empty-string leaves return ''.
     */
    function _resolveTranslation(key) {
        if (!_translations) return null;
        var segments = key.split('.');
        var current = _translations;
        for (var i = 0; i < segments.length; i++) {
            if (current == null || typeof current !== 'object' || !(segments[i] in current)) {
                return null;
            }
            current = current[segments[i]];
        }
        return typeof current === 'string' ? current : null;
    }

    var _STATUS_DOTS = { used: '🟢', unset: '🔴', unused: '🟡' };

    // The row edit's own icon, inner SVG markup for QSDom.iconEl ("set value" is the
    // catalogue's plus). Its size, stroke and fill come from the panel's CSS.
    var _ICON_EDIT = '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>';

    /** The row's own edit button (edit, or set a value). Returns ONE Element. */
    function _renderActionButton(label, iconMarkup, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'preview-contextual-translation__row-action';
        btn.setAttribute('aria-label', label);
        btn.title = label;
        btn.appendChild(QSDom.iconEl(iconMarkup, 0));
        btn.addEventListener('click', onClick);
        return btn;
    }

    /**
     * Build ONE row Element for an entry. textContent only for the value
     * (translation values are user-controlled and can contain anything).
     */
    function _renderRow(entry) {
        var row = document.createElement('div');
        row.className = 'preview-contextual-translation__row '
            + 'preview-contextual-translation__row--' + entry.status;
        row.dataset.key = entry.key;
        row.dataset.status = entry.status;

        var dot = document.createElement('span');
        dot.className = 'preview-contextual-translation__row-status';
        dot.textContent = _STATUS_DOTS[entry.status] || '';
        row.appendChild(dot);

        var keyEl = document.createElement('code');
        keyEl.className = 'preview-contextual-translation__row-key';
        keyEl.textContent = entry.key;
        row.appendChild(keyEl);

        var valueEl = document.createElement('span');
        valueEl.className = 'preview-contextual-translation__row-value';
        if (entry.status === 'unset') {
            valueEl.classList.add('preview-contextual-translation__row-value--unset');
            valueEl.textContent = QSPanelWords.t('preview.translationUnsetPlaceholder');
        } else {
            valueEl.textContent = _truncate(entry.value, _VALUE_TRUNCATE_AT);
            if (entry.value.length > _VALUE_TRUNCATE_AT) {
                valueEl.title = entry.value;
            }
        }
        row.appendChild(valueEl);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-translation__row-actions';

        var editLabel = entry.status === 'unset'
            ? QSPanelWords.t('preview.translationActionSetValue')
            : QSPanelWords.t('preview.translationActionEdit');
        var deleteLabel = QSPanelWords.t('translations.deleteKey', { key: entry.key });

        actions.appendChild(_renderActionButton(
            editLabel,
            entry.status === 'unset' ? QuickSiteUtils.ICON_PATHS.plus : _ICON_EDIT,
            function () { _handleEditKey(entry.key); }
        ));
        actions.appendChild(window.QSControls.bin(deleteLabel, function () { _handleDeleteKey(entry); },
            { focusKey: 'translation-delete:' + entry.key }));

        row.appendChild(actions);
        return row;
    }

    function _truncate(s, max) {
        if (typeof s !== 'string' || s.length <= max) return s || '';
        return s.slice(0, max - 1) + '…';
    }

    function _handleEditKey(key) {
        // Toggle: clicking Edit on the row whose editor is open closes it; on another row, opens that one's.
        _expandedKey = _expandedKey === key ? null : key;
        _actionError = null;
        _renderRows();
    }

    // ──────────────────────────── Inline editor ─────────────────────────

    /**
     * Build the inline editor panel for an expanded row. Sits directly
     * below its row as a sibling in _rowsContainer.
     */
    function _renderEditor(entry) {
        var editor = document.createElement('div');
        editor.className = 'preview-contextual-translation__row-editor';
        editor.dataset.key = entry.key;

        var textarea = document.createElement('textarea');
        textarea.className = 'preview-contextual-translation__row-editor-textarea';
        textarea.value = entry.value || '';
        textarea.placeholder = QSPanelWords.t('preview.translationEditPlaceholder');
        textarea.spellcheck = false;
        textarea.rows = 3;
        if (_busyKey === entry.key) textarea.disabled = true;
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                _handleCancelEdit();
            } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                _handleSaveKey(entry.key, textarea.value);
            }
        });
        editor.appendChild(textarea);

        // Error slot — populated only when a save fails.
        if (_actionError && _actionError.key === entry.key) {
            var errEl = document.createElement('div');
            errEl.className = 'preview-contextual-translation__row-editor-error';
            errEl.textContent = _actionError.message;
            editor.appendChild(errEl);
        }

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-translation__row-editor-actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'admin-btn';
        cancelBtn.textContent = QSPanelWords.t('preview.translationEditCancel');
        if (_busyKey === entry.key) cancelBtn.disabled = true;
        cancelBtn.addEventListener('click', _handleCancelEdit);
        actions.appendChild(cancelBtn);

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'admin-btn admin-btn--primary';
        if (_busyKey === entry.key) {
            saveBtn.textContent = QSPanelWords.t('preview.translationEditSaving');
            saveBtn.disabled = true;
        } else {
            saveBtn.textContent = QSPanelWords.t('preview.translationEditSave');
        }
        saveBtn.addEventListener('click', function () {
            _handleSaveKey(entry.key, textarea.value);
        });
        actions.appendChild(saveBtn);

        editor.appendChild(actions);
        return editor;
    }

    function _handleCancelEdit() {
        _expandedKey = null;
        _actionError = null;
        if (_pendingReload) {
            _pendingReload = false;
            _load(true);
            return;
        }
        _renderRows();
    }

    /**
     * Save a single translation value, through the one list of the site's
     * words (QSSiteWords.write(): the answer checked, a refusal's words
     * shown and nothing changed). On success the panel reads the list again,
     * so the row's status flips (e.g. unset → used) and counts update.
     */
    async function _handleSaveKey(key, value) {
        if (_busyKey) return;  // Guard against double-clicks.
        _busyKey = key;
        _actionError = null;
        _renderRows();

        var words = {};
        words[key] = value;
        var done = await QSSiteWords.write(_currentLang, words);
        if (!done.ok) {
            _actionError = { key: key, message: _formatSaveError(new Error(done.message)) };
            _busyKey = null;
            _renderRows();
            return;
        }

        _busyKey = null;
        _expandedKey = null;
        _pendingReload = false;
        await _load(true);
        _reloadPreviewIframe();
    }

    function _formatSaveError(e) {
        var msg = (e && e.message) ? e.message : String(e);
        return QSPanelWords.t('preview.translationEditSaveError', { error: msg });
    }

    // ──────────────────────────── Delete flows ──────────────────────────

    /**
     * A row's delete, asked in the panel's one confirm: the key and the language in the title, the word it holds as
     * the message (a key the person may never have seen on a page), and with several languages the choice of deleting
     * it from all of them (off by default: a word is usually edited in one language). The delete runs with the
     * question open (_deleteAndReload()).
     */
    function _handleDeleteKey(entry) {
        var allLangs = false;
        var multiLang = _renderMultiLangCheckbox(allLangs, function (checked) { allLangs = checked; });
        QuickSiteUtils.confirm(_truncate(entry.value, 200), {
            title: QSPanelWords.t('preview.translationDeletePrompt', {
                key: entry.key,
                lang: (_currentLang || '').toUpperCase()
            }),
            type: 'danger',
            confirmText: QSPanelWords.t('preview.translationConfirmDelete'),
            body: multiLang,
            action: function () {
                return _deleteAndReload(_targetLangs(allLangs), [entry.key]);
            }
        });
    }

    /**
     * "Remove all unused", asked list first in the panel's one confirm: every unused key with its word in a list that
     * scrolls, and with several languages the choice of deleting them from all of them (on by default: a key nothing
     * names is unused in every language). The delete runs with the question open (_deleteAndReload()).
     */
    function _handleRemoveUnused() {
        var keys = _bulkUnusedKeys();
        if (!keys.length) return;
        var allLangs = true;
        var multiLang = _renderMultiLangCheckbox(allLangs, function (checked) { allLangs = checked; });
        QuickSiteUtils.confirm(QSPanelWords.count('preview.translationRemoveUnusedList', keys.length, {
            lang: (_currentLang || '').toUpperCase()
        }), {
            title: QSPanelWords.count('preview.translationRemoveUnusedTitle', keys.length),
            type: 'danger',
            confirmText: QSPanelWords.count('preview.translationRemoveUnusedConfirm', keys.length),
            body: [_renderUnusedList(keys), multiLang],
            action: function () {
                return _deleteAndReload(_targetLangs(allLangs), keys);
            }
        });
    }

    /** The languages a delete writes: every one when the question's choice says so, else the one shown. */
    function _targetLangs(allLangs) {
        return (_availableLangs.length >= 2 && allLangs) ? _availableLangs.slice() : [_currentLang];
    }

    /**
     * A delete question's action: the keys deleted in each language given. A refusal answers its words, which keep
     * the question open; done, an open editor closes and the list is read again before the question closes, so the
     * focus finds the control that took the deleted row's place.
     */
    async function _deleteAndReload(langs, keys) {
        var done = await _deleteKeys(langs, keys);
        if (!done.ok) return { ok: false, message: _formatMultiLangDeleteError(done.failures, done.total) };
        _expandedKey = null;
        _actionError = null;
        _pendingReload = false;
        await _load(true);
        _reloadPreviewIframe();
        return true;
    }

    /**
     * A delete question's "Delete from all N languages" choice. Returns ONE Element, or null when only one language
     * exists (no choice to surface).
     */
    function _renderMultiLangCheckbox(checked, onChange) {
        if (!_availableLangs || _availableLangs.length < 2) return null;
        var wrap = document.createElement('label');
        wrap.className = 'preview-contextual-translation__multi-lang';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = !!checked;
        input.addEventListener('change', function () { onChange(input.checked); });
        wrap.appendChild(input);
        var text = document.createElement('span');
        // Shown with two languages or more only, so the word is always plural.
        text.textContent = QSPanelWords.t('preview.translationDeleteAllLangs', {
            n: QSPanelWords.number(_availableLangs.length),
            list: _availableLangs.map(function (l) { return l.toUpperCase(); }).join(', ')
        });
        wrap.appendChild(text);
        return wrap;
    }

    /** The unused keys a bulk delete lists, each with its word. Returns ONE Element, which scrolls in the question. */
    function _renderUnusedList(keys) {
        var listEl = document.createElement('ul');
        listEl.className = 'preview-contextual-translation__unused-list';
        keys.forEach(function (key) {
            var li = document.createElement('li');
            li.className = 'preview-contextual-translation__unused-item';
            var keyEl = document.createElement('code');
            keyEl.className = 'preview-contextual-translation__unused-key';
            keyEl.textContent = key;
            li.appendChild(keyEl);
            var value = _resolveTranslation(key);
            if (value) {
                var valueEl = document.createElement('span');
                valueEl.className = 'preview-contextual-translation__unused-value';
                valueEl.textContent = _truncate(value, 120);
                if (value.length > 120) valueEl.title = value;
                li.appendChild(valueEl);
            }
            listEl.appendChild(li);
        });
        return listEl;
    }

    /**
     * Delete keys in each language given, through the one list of the site's
     * words (QSSiteWords.remove(): a language that does not hold them is no
     * failure). Answers {ok, failures: [{lang, error}], total}.
     */
    async function _deleteKeys(langs, keys) {
        var done = await QSSiteWords.remove(langs, keys);
        return {
            ok: done.ok,
            total: done.done.length + done.failures.length,
            failures: done.failures.map(function (f) { return { lang: f.language, error: f.message }; })
        };
    }

    function _formatMultiLangDeleteError(failures, total) {
        if (total === 1 || failures.length === 1) {
            // Single-call or single-failure case → plain "Failed to delete" line.
            var first = failures[0];
            var label = total > 1
                ? '[' + first.lang.toUpperCase() + '] ' + first.error
                : first.error;
            return QSPanelWords.t('preview.translationDeleteError', { error: label });
        }
        // Here {total} is two languages or more, so the word is always plural.
        return QSPanelWords.t('preview.translationDeleteMultiError', {
            n: QSPanelWords.number(failures.length),
            total: QSPanelWords.number(total),
            details: failures.map(function (f) {
                return f.lang.toUpperCase() + ': ' + f.error;
            }).join(' | ')
        });
    }

    /**
     * Ask the host editor to reload the preview iframe so users see the
     * translation change live. Uses the shared PreviewState helper — same
     * mechanism every other edit flow (style, JS interactions, animations)
     * uses after a write. No-op if PreviewState isn't available.
     */
    function _reloadPreviewIframe() {
        if (window.PreviewState && typeof PreviewState.reloadPreview === 'function') {
            PreviewState.reloadPreview();
        }
    }

    /** The unused keys, for the bulk delete. */
    function _bulkUnusedKeys() {
        return ((_grouped && _grouped.unused) || []).filter(function (k) { return typeof k === 'string'; });
    }

    function _showLoading(show) {
        if (_loadingEl) _loadingEl.style.display = show ? '' : 'none';
    }
    function _showEmpty(show) {
        if (_emptyEl) _emptyEl.style.display = show ? '' : 'none';
    }

    // ──────────────────────────── Boot ──────────────────────────────────

    // Init on DOM ready (same timing as PreviewJsInteractions).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public surface
    return {
        enter: enter,
        leave: leave,
        connect: connect,
        setLanguage: setLanguage,
    };
})();
