/**
 * preview-translation.js — Translation Manager panel (Beta.9 A4)
 *
 * Mounted as a sibling to PreviewJsInteractions. Manages the in-editor
 * Translation Manager panel: per-language view, scope filter (whole site /
 * page / component), status chips (used / unset / unused), substring
 * filter, inline value editor, delete + bulk-remove-unused.
 *
 * Activation surface: sidebar "Translations" tool → setMode('translation')
 * in preview.js shows #contextual-translation and calls
 * PreviewTranslation.enter() (lazy data fetch on first entry).
 *
 * Data sources (no new commands — reuses existing):
 *   - QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]):
 *     { used[], unused[], unset[] } per lang
 *   - getTranslationKeys: { keys_by_source } map for the scope picker
 *   - getTranslation/<lang>: full translations for the value column
 *   - setTranslationKeys: write a key's value (per lang)
 *   - deleteTranslationKeys: delete one or many keys
 *
 * See NOTES/planning/BETA9_TRANSLATION_MANAGER.md for design rationale.
 */
window.PreviewTranslation = (function () {
    'use strict';

    // C8 8.X — the /admin/api helper endpoint is project-scoped: it authorizes each
    // arm against the marker project and binds that context. QuickSiteAPI.helperPath
    // is the single owner of the marker convention; this delegates so the URL shape
    // lives in ONE place. Falls back to the bare action if core/api.js is absent —
    // the server then answers 400 rather than silently reading another project.
    function _helperPath(action) {
        return (window.QuickSiteAPI && typeof window.QuickSiteAPI.helperPath === 'function')
            ? window.QuickSiteAPI.helperPath(action)
            : action;
    }

    // ──────────────────────────── State ─────────────────────────────────

    // Module-scope cache. Populated on enter(); cleared on language switch
    // (full refetch) or after writes (partial update).
    var _grouped = null;       // { used: [...], unset: [...], unused: [...] } for current lang
    var _translations = null;  // full translations object for current lang
    var _keysBySource = null;  // { 'page:home': ['home.title', ...], 'component:card': [...], ... }
    var _availableLangs = [];  // ['en', 'fr', ...] minus 'default'
    var _currentLang = null;
    var _currentScope = 'site';   // 'site' | 'page:<name>' | 'component:<name>'
    var _statusFilter = { used: true, unset: true, unused: true };
    var _substringFilter = '';
    var _expandedKey = null;   // Slice 5/6: which row currently has an expanded panel
    var _expandedMode = null;  // Slice 6: 'edit' | 'delete' — what kind of panel
    var _busyKey = null;       // Slice 5/6: key whose row action (save/delete) is in flight
    var _actionError = null;   // Slice 5/6: { key, message } for the active row action

    // Slice 6: bulk remove-unused confirm state.
    var _bulkConfirm = false;  // true → confirm view replaces row list
    var _bulkBusy = false;     // delete in flight
    var _bulkError = null;     // string | null

    // Slice 6+: multi-language delete checkbox state. Reset on each panel open.
    // Per-row default OFF (you're usually editing one language's value).
    // Bulk default ON (orphaned keys are orphaned everywhere).
    var _deleteAllLangs = false;
    var _bulkDeleteAllLangs = true;
    var _entered = false;      // First-entry guard so we only initialise once

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
                _refetchAndRender();
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
    }

    // ──────────────────────────── Public API ────────────────────────────

    /**
     * Called by preview.js when setMode('translation') fires. Lazy-loads
     * data on first entry; no-op on subsequent entries unless an explicit
     * refresh is requested.
     */
    function enter(opts) {
        opts = opts || {};
        if (!_section) return;
        if (_entered && !opts.refresh) return;
        _entered = true;
        _initialLoad();
    }

    /**
     * Called by preview.js when leaving translation mode. Light cleanup —
     * we keep the module-scope cache so re-entering is fast.
     */
    function leave() {
        // No teardown needed today; cache persists for fast re-entry.
        // If this becomes load-bearing later (e.g. iframe detection of
        // stale language), wire a refresh hook here.
    }

    // ──────────────────────────── Slice 3: Data fetch ───────────────────

    /**
     * Initial data load on first enter(). Fetches language list + the
     * site-wide keys_by_source map in parallel (neither depends on a
     * language). Caches the results so language switches only refetch
     * the language-dependent data.
     */
    async function _initialLoad() {
        try {
            var results = await Promise.all([
                QuickSiteAdmin.apiRequest('getLangList', 'GET'),
                QuickSiteAdmin.apiRequest('getTranslationKeys', 'GET')
            ]);
            var langData = _unwrap(results[0]);
            var keysData = _unwrap(results[1]);

            // For monolingual sites, Translator.php loads default.json
            // exclusively (LANGUAGES_SUPPORTED entries are ignored at
            // render time). So the only file worth managing IS default.json
            // — surface that single source instead of pretending the
            // configured "default language" file is what's being shipped.
            var multilingual = langData.multilingual_enabled !== false;
            if (multilingual) {
                _availableLangs = Array.isArray(langData.languages) ? langData.languages : [];
                // In multilingual mode, default.json is plumbing — hide it
                // from the picker per design lock Q.
                _availableLangs = _availableLangs.filter(function (l) { return l !== 'default'; });
            } else {
                _availableLangs = ['default'];
            }

            _keysBySource = keysData.keys_by_source || {};
            // Defensive: PHP's array_unique() leaves gappy indices which
            // json_encode emits as {"0":"a","2":"b"} (object) instead of
            // an array. The server now wraps array_unique in array_values,
            // but coerce here too so a stale OPcache copy doesn't break
            // the panel.
            Object.keys(_keysBySource).forEach(function (k) {
                if (!Array.isArray(_keysBySource[k]) && _keysBySource[k] && typeof _keysBySource[k] === 'object') {
                    _keysBySource[k] = Object.values(_keysBySource[k]);
                }
            });
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
            // Pick a default: previously-selected, or first.
            _currentLang = _currentLang && _availableLangs.indexOf(_currentLang) !== -1
                ? _currentLang
                : _availableLangs[0];
            if (_langSelect) _langSelect.value = _currentLang;
            await _refetchAndRender();
        } catch (e) {
            console.error('[PreviewTranslation] initial load failed:', e);
            _showLoading(false);
        }
    }

    function _setLangPickerVisible(visible) {
        if (_langSelect) _langSelect.style.display = visible ? '' : 'none';
        if (_section) {
            var label = _section.querySelector('label[for="translation-lang"]');
            if (label) label.style.display = visible ? '' : 'none';
        }
    }

    /**
     * Defensive unwrap — QuickSiteAdmin.apiRequest returns
     * {ok, status, data: <ApiResponse envelope>} where the envelope wraps
     * the payload again in `.data`. So actual payload sits at
     * res.data.data. Mirrors the unwrap pattern in route-input.js +
     * text-key-picker.js (they all use `(res.data && (res.data.data || res.data))`).
     */
    function _unwrap(res) {
        return (res && res.data && (res.data.data || res.data)) || {};
    }

    function _populateLangSelect() {
        if (!_langSelect) return;
        _langSelect.textContent = '';
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
        siteOpt.textContent = (window.PreviewConfig && PreviewConfig.i18n
            && PreviewConfig.i18n.translationScopeSite) || 'Whole site';
        _scopeSelect.appendChild(siteOpt);

        // The getTranslationKeys command's keys_by_source shape is:
        //   - 'menu' / 'footer' → literal layout sources
        //   - 'component:<name>' → a component (prefixed because pages and
        //     components share a flat namespace)
        //   - any other key → a page route (raw, no 'page:' prefix)
        var pageGroup = document.createElement('optgroup');
        pageGroup.label = (window.PreviewConfig && PreviewConfig.i18n
            && PreviewConfig.i18n.translationScopePages) || 'Pages';
        var layoutGroup = document.createElement('optgroup');
        layoutGroup.label = (window.PreviewConfig && PreviewConfig.i18n
            && PreviewConfig.i18n.translationScopeLayout) || 'Layout';
        var componentGroup = document.createElement('optgroup');
        componentGroup.label = (window.PreviewConfig && PreviewConfig.i18n
            && PreviewConfig.i18n.translationScopeComponents) || 'Components';

        var LAYOUT_SOURCES = { menu: 1, footer: 1 };
        var sources = Object.keys(_keysBySource).sort();
        sources.forEach(function (source) {
            var opt = document.createElement('option');
            opt.value = source;
            if (source.indexOf('component:') === 0) {
                // Strip the prefix in the display — user sees the bare component name.
                opt.textContent = source.slice('component:'.length);
                componentGroup.appendChild(opt);
            } else if (LAYOUT_SOURCES[source]) {
                opt.textContent = source;
                layoutGroup.appendChild(opt);
            } else {
                // Default: treat as a page route.
                opt.textContent = source;
                pageGroup.appendChild(opt);
            }
        });
        if (pageGroup.children.length) _scopeSelect.appendChild(pageGroup);
        if (layoutGroup.children.length) _scopeSelect.appendChild(layoutGroup);
        if (componentGroup.children.length) _scopeSelect.appendChild(componentGroup);
    }

    /**
     * Refetch the language-dependent data (grouped keys + values), then
     * render. Called on first load + on language switch.
     *
     * Each call is isolated: one failing sub-call doesn't crash the whole
     * panel. We render whatever data we have (empty fallbacks), and the
     * console log identifies the offending endpoint for root-cause.
     */
    async function _refetchAndRender() {
        if (!_currentLang) return;
        _showLoading(true);
        _showEmpty(false);

        var grouped = await _fetchGroupedSafe(_currentLang);
        var translations = await _fetchTranslationsSafe(_currentLang);

        _grouped = grouped || { used: [], unused: [], unset: [] };
        _translations = translations || {};
        _render();
    }

    /**
     * Safe-fetch the translation-keys-grouped helper. Returns the data
     * payload on success, null on any failure. We bypass
     * QuickSiteAdmin.fetchHelperData here because it calls response.json()
     * directly and throws on empty body, masking the real cause. Reading
     * response.text() first lets us log the body excerpt for diagnosis.
     */
    async function _fetchGroupedSafe(lang) {
        var adminBase = (QuickSiteAdmin.config && QuickSiteAdmin.config.adminBase) || '/admin';
        var url = adminBase + '/api/' + _helperPath('translation-keys-grouped') + '/' + encodeURIComponent(lang);
        var token = QuickSiteAdmin.getToken && QuickSiteAdmin.getToken();
        try {
            var resp = await fetch(url, {
                headers: token ? { Authorization: 'Bearer ' + token } : {}
            });
            var text = await resp.text();
            if (!resp.ok) {
                console.error('[PreviewTranslation] grouped helper HTTP', resp.status, url,
                    'body[0..200]:', text.slice(0, 200));
                return null;
            }
            if (text === '' || text == null) {
                console.error('[PreviewTranslation] grouped helper returned EMPTY body', url,
                    'status:', resp.status);
                return null;
            }
            var json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                console.error('[PreviewTranslation] grouped helper returned non-JSON', url,
                    'length:', text.length, 'body[0..200]:', text.slice(0, 200));
                return null;
            }
            if (!json || json.success !== true) {
                console.error('[PreviewTranslation] grouped helper returned error',
                    (json && json.error) || json);
                return null;
            }
            return json.data;
        } catch (e) {
            console.error('[PreviewTranslation] grouped helper fetch threw', url, e);
            return null;
        }
    }

    async function _fetchTranslationsSafe(lang) {
        try {
            var res = await QuickSiteAdmin.apiRequest('getTranslation', 'GET', null, [lang]);
            var trData = _unwrap(res);
            return trData.translations || {};
        } catch (e) {
            console.error('[PreviewTranslation] getTranslation failed', lang, e);
            return null;
        }
    }

    /**
     * Render the current cache (no fetch). Called on filter change, status
     * chip change, scope change. Updates coverage chrome + rebuilds rows.
     */
    function _render() {
        if (!_grouped) return;

        // Slice 7: chip counts + coverage are scope-aware (also substring-aware).
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
        // Coverage = used / (used + unset). Per Q2 lock — unused doesn't
        // distort the denominator. 0/0 → 100% (no work to do).
        var denominator = usedCount + unsetCount;
        var coveragePct = denominator > 0
            ? Math.round((usedCount / denominator) * 100)
            : 100;

        if (_coverageBar) {
            _coverageBar.textContent = '';
            var coverageText = document.createElement('span');
            coverageText.className = 'preview-contextual-translation__coverage-pct';
            // i18n template: "Coverage: {pct}% ({used}/{total})"
            var tpl = (window.PreviewConfig && PreviewConfig.i18n
                && PreviewConfig.i18n.translationCoverage)
                || 'Coverage: {pct}% ({used}/{total})';
            coverageText.textContent = tpl
                .replace('{pct}', String(coveragePct))
                .replace('{used}', String(usedCount))
                .replace('{total}', String(denominator));
            _coverageBar.appendChild(coverageText);
        }
        if (_countUsed) _countUsed.textContent = String(usedCount);
        if (_countUnset) _countUnset.textContent = String(unsetCount);
        if (_countUnused) _countUnused.textContent = String(unusedCount);

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
        // Slice 6: also disable while bulk confirm is open or bulk delete is in flight.
        if (_removeUnusedBtn) {
            _removeUnusedBtn.disabled = siteUnusedCount === 0 || _bulkConfirm || _bulkBusy;
        }

        _renderRows();
    }

    /**
     * Slice 7 helper — apply scope + substring filters (NOT status) to
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

    // ──────────────────────────── Slice 4: Row rendering ────────────────

    var _VALUE_TRUNCATE_AT = 100;

    /**
     * Build, filter, and render the row list. Row order: alphabetical by
     * key name across all statuses (chips narrow, sort stays stable so
     * keys don't jump around when toggling chips).
     */
    function _renderRows() {
        if (!_rowsContainer) return;
        _rowsContainer.textContent = '';

        // Slice 6: bulk-confirm view replaces the row list entirely.
        if (_bulkConfirm) {
            _rowsContainer.appendChild(_renderBulkConfirm());
            return;
        }

        var entries = _buildEntries();
        var filtered = _applyFilters(entries);

        if (filtered.length === 0) {
            var noMatch = document.createElement('p');
            noMatch.className = 'preview-contextual-translation__no-matches';
            noMatch.textContent = (window.PreviewConfig && PreviewConfig.i18n
                && PreviewConfig.i18n.translationNoMatches)
                || 'No keys match the current filters.';
            _rowsContainer.appendChild(noMatch);
            return;
        }

        var frag = document.createDocumentFragment();
        filtered.forEach(function (entry) {
            frag.appendChild(_renderRow(entry));
            // Slice 5/6: expanded panel for the currently-active row.
            if (entry.key === _expandedKey) {
                if (_expandedMode === 'delete') {
                    frag.appendChild(_renderDeleteConfirm(entry));
                } else {
                    frag.appendChild(_renderEditor(entry));
                }
            }
        });
        _rowsContainer.appendChild(frag);

        // Auto-focus the textarea when an editor was just rendered (edit mode only).
        if (_expandedKey && _expandedMode === 'edit') {
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
     * alphabetically by key name.
     *
     * The translation-keys-grouped helper returns each entry as a
     * { value, label } object (see admin/api/index.php case
     * 'translation-keys-grouped'). We extract `.value` but tolerate a
     * bare string too in case the shape changes.
     */
    function _buildEntries() {
        var entries = [];
        function pullKey(item) {
            if (typeof item === 'string') return item;
            if (item && typeof item.value === 'string') return item.value;
            return null;
        }
        function push(items, status) {
            (items || []).forEach(function (item) {
                var key = pullKey(item);
                if (key === null) return;
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

    // Trusted static SVG icons. innerHTML use is fine here per CLAUDE.md
    // HTML-in-JS hygiene carve-out ("small SVG icons and short trusted snippets").
    // Stroke + fill set in CSS so a single rule themes both icons.
    var _ICON_SVG = {
        edit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        setvalue: '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        del: '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14H7L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
    };

    function _renderActionButton(label, iconKey, modifier, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'preview-contextual-translation__row-action'
            + (modifier ? ' preview-contextual-translation__row-action--' + modifier : '');
        btn.setAttribute('aria-label', label);
        btn.title = label;
        btn.innerHTML = _ICON_SVG[iconKey];
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
            valueEl.textContent = (window.PreviewConfig && PreviewConfig.i18n
                && PreviewConfig.i18n.translationUnsetPlaceholder)
                || '(not set)';
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
            ? ((window.PreviewConfig && PreviewConfig.i18n
                && PreviewConfig.i18n.translationActionSetValue) || 'Set value')
            : ((window.PreviewConfig && PreviewConfig.i18n
                && PreviewConfig.i18n.translationActionEdit) || 'Edit');
        var deleteLabel = (window.PreviewConfig && PreviewConfig.i18n
            && PreviewConfig.i18n.translationActionDelete) || 'Delete';

        actions.appendChild(_renderActionButton(
            editLabel,
            entry.status === 'unset' ? 'setvalue' : 'edit',
            null,
            function () { _handleEditKey(entry.key); }
        ));
        actions.appendChild(_renderActionButton(
            deleteLabel,
            'del',
            'danger',
            function () { _handleDeleteKey(entry.key); }
        ));

        row.appendChild(actions);
        return row;
    }

    function _truncate(s, max) {
        if (typeof s !== 'string' || s.length <= max) return s || '';
        return s.slice(0, max - 1) + '…';
    }

    function _handleEditKey(key) {
        // Toggle: clicking Edit on the already-open edit row closes it.
        // Clicking it on a row that's open for delete switches to edit.
        if (_expandedKey === key && _expandedMode === 'edit') {
            _expandedKey = null;
            _expandedMode = null;
        } else {
            _expandedKey = key;
            _expandedMode = 'edit';
        }
        _actionError = null;
        _renderRows();
    }

    function _handleDeleteKey(key) {
        // Toggle: clicking Delete on the already-open delete row closes it.
        if (_expandedKey === key && _expandedMode === 'delete') {
            _expandedKey = null;
            _expandedMode = null;
        } else {
            _expandedKey = key;
            _expandedMode = 'delete';
            _deleteAllLangs = false;  // Reset per-open: per-row defaults OFF.
        }
        _actionError = null;
        _renderRows();
    }

    // ──────────────────────────── Slice 5: Inline editor ────────────────

    /**
     * Build the inline editor panel for an expanded row. Sits directly
     * below its row as a sibling in _rowsContainer.
     */
    function _renderEditor(entry) {
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var editor = document.createElement('div');
        editor.className = 'preview-contextual-translation__row-editor';
        editor.dataset.key = entry.key;

        var textarea = document.createElement('textarea');
        textarea.className = 'preview-contextual-translation__row-editor-textarea';
        textarea.value = entry.value || '';
        textarea.placeholder = i18n.translationEditPlaceholder || 'Translation value…';
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
        cancelBtn.className = 'admin-btn admin-btn--small admin-btn--secondary';
        cancelBtn.textContent = i18n.translationEditCancel || 'Cancel';
        if (_busyKey === entry.key) cancelBtn.disabled = true;
        cancelBtn.addEventListener('click', _handleCancelEdit);
        actions.appendChild(cancelBtn);

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'admin-btn admin-btn--small admin-btn--primary';
        if (_busyKey === entry.key) {
            saveBtn.textContent = i18n.translationEditSaving || 'Saving…';
            saveBtn.disabled = true;
        } else {
            saveBtn.textContent = i18n.translationEditSave || 'Save';
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
        _expandedMode = null;
        _actionError = null;
        _renderRows();
    }

    /**
     * Save a single translation value. Sends dot-notation; the server
     * normalises to nested. On success we refetch grouped+translations
     * so the row's status flips (e.g. unset → used) and counts update.
     */
    async function _handleSaveKey(key, value) {
        if (_busyKey) return;  // Guard against double-clicks.
        _busyKey = key;
        _actionError = null;
        _renderRows();

        try {
            var body = {
                language: _currentLang,
                translations: {}
            };
            body.translations[key] = value;
            var res = await QuickSiteAdmin.apiRequest('setTranslationKeys', 'POST', body);
            var ok = res && res.ok;
            var envelope = res && res.data;
            if (!ok) {
                var msg = (envelope && envelope.message)
                    || (envelope && envelope.error)
                    || ('HTTP ' + (res && res.status));
                throw new Error(msg);
            }
        } catch (e) {
            _actionError = { key: key, message: _formatSaveError(e) };
            _busyKey = null;
            _renderRows();
            return;
        }

        // Success: refetch + close editor. The refetch repopulates _grouped
        // so the just-saved key flips groups naturally (the "stale-sibling
        // sweep" — Slice 5 lock language).
        _busyKey = null;
        _expandedKey = null;
        _expandedMode = null;
        await _refetchAndRender();
        _reloadPreviewIframe();
    }

    function _formatSaveError(e) {
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var tpl = i18n.translationEditSaveError || 'Failed to save: {error}';
        var msg = (e && e.message) ? e.message : String(e);
        return tpl.replace('{error}', msg);
    }

    // ──────────────────────────── Slice 6: Delete flows ─────────────────

    /**
     * Inline delete-confirm panel for a single row. Mirrors the editor
     * mechanism (sibling element after the row) but with a short prompt
     * + [Cancel] [Delete] instead of a textarea.
     */
    function _renderDeleteConfirm(entry) {
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var panel = document.createElement('div');
        panel.className = 'preview-contextual-translation__row-confirm';
        panel.dataset.key = entry.key;

        var prompt = document.createElement('div');
        prompt.className = 'preview-contextual-translation__row-confirm-prompt';
        // i18n template: "Delete {key} from {lang}.json?"
        var tpl = i18n.translationDeletePrompt || 'Delete {key} from {lang}.json?';
        prompt.textContent = tpl
            .replace('{key}', entry.key)
            .replace('{lang}', (_currentLang || '').toUpperCase());
        panel.appendChild(prompt);

        // Show the value being deleted (helpful for unused keys the user
        // might not have seen rendered before).
        if (entry.value) {
            var valuePreview = document.createElement('div');
            valuePreview.className = 'preview-contextual-translation__row-confirm-value';
            valuePreview.textContent = _truncate(entry.value, 200);
            if (entry.value.length > 200) valuePreview.title = entry.value;
            panel.appendChild(valuePreview);
        }

        if (_actionError && _actionError.key === entry.key) {
            var errEl = document.createElement('div');
            errEl.className = 'preview-contextual-translation__row-editor-error';
            errEl.textContent = _actionError.message;
            panel.appendChild(errEl);
        }

        // Multi-language opt-in (hidden if only one language exists).
        var multiLang = _renderMultiLangCheckbox(_deleteAllLangs, function (checked) {
            _deleteAllLangs = checked;
        }, _busyKey === entry.key);
        if (multiLang) panel.appendChild(multiLang);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-translation__row-editor-actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'admin-btn admin-btn--small admin-btn--secondary';
        cancelBtn.textContent = i18n.translationEditCancel || 'Cancel';
        if (_busyKey === entry.key) cancelBtn.disabled = true;
        cancelBtn.addEventListener('click', _handleCancelEdit);
        actions.appendChild(cancelBtn);

        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'admin-btn admin-btn--small admin-btn--danger';
        if (_busyKey === entry.key) {
            deleteBtn.textContent = i18n.translationDeleting || 'Deleting…';
            deleteBtn.disabled = true;
        } else {
            deleteBtn.textContent = i18n.translationConfirmDelete || 'Delete';
        }
        deleteBtn.addEventListener('click', function () {
            _handleConfirmDeleteKey(entry.key);
        });
        actions.appendChild(deleteBtn);

        panel.appendChild(actions);
        return panel;
    }

    /**
     * Build the "Delete from all N languages" opt-in checkbox. Returns
     * null when only one language exists (no choice to surface).
     */
    function _renderMultiLangCheckbox(checked, onChange, disabled) {
        if (!_availableLangs || _availableLangs.length < 2) return null;
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var wrap = document.createElement('label');
        wrap.className = 'preview-contextual-translation__multi-lang';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = !!checked;
        if (disabled) input.disabled = true;
        input.addEventListener('change', function () { onChange(input.checked); });
        wrap.appendChild(input);
        var text = document.createElement('span');
        var tpl = i18n.translationDeleteAllLangs || 'Delete from all {n} languages ({list})';
        text.textContent = tpl
            .replace('{n}', String(_availableLangs.length))
            .replace('{list}', _availableLangs.map(function (l) { return l.toUpperCase(); }).join(', '));
        wrap.appendChild(text);
        return wrap;
    }

    /**
     * Execute the per-row delete after the user confirms. Loops over
     * languages if the multi-lang opt-in is checked.
     */
    async function _handleConfirmDeleteKey(key) {
        if (_busyKey) return;
        _busyKey = key;
        _actionError = null;
        _renderRows();

        var targetLangs = (_availableLangs.length >= 2 && _deleteAllLangs)
            ? _availableLangs.slice()
            : [_currentLang];

        var results = await Promise.all(targetLangs.map(function (lang) {
            return _deleteKeysForLang(lang, [key]);
        }));

        var failures = [];
        results.forEach(function (r, i) {
            if (!r.ok) failures.push({ lang: targetLangs[i], error: r.error });
        });

        if (failures.length > 0) {
            _actionError = { key: key, message: _formatMultiLangDeleteError(failures, results.length) };
            _busyKey = null;
            _renderRows();
            return;
        }

        _busyKey = null;
        _expandedKey = null;
        _expandedMode = null;
        _deleteAllLangs = false;
        await _refetchAndRender();
        _reloadPreviewIframe();
    }

    /**
     * Single-language delete API call. Returns {ok: true} on 200 OR 404
     * (404 = "no keys found" — acceptable in multi-lang mode because the
     * key might not exist in every file, and the desired end state is
     * "key absent" either way).
     */
    async function _deleteKeysForLang(lang, keys) {
        try {
            var res = await QuickSiteAdmin.apiRequest('deleteTranslationKeys', 'POST', {
                language: lang,
                keys: keys
            });
            if (!res) return { ok: false, error: 'no response' };
            if (res.status === 200 || res.status === 404) return { ok: true };
            var envelope = res.data;
            var msg = (envelope && envelope.message)
                || (envelope && envelope.error)
                || ('HTTP ' + res.status);
            return { ok: false, error: msg };
        } catch (e) {
            return { ok: false, error: (e && e.message) || String(e) };
        }
    }

    function _formatMultiLangDeleteError(failures, total) {
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        if (total === 1 || failures.length === 1) {
            // Single-call or single-failure case → plain "Failed to delete" line.
            var tpl = i18n.translationDeleteError || 'Failed to delete: {error}';
            var first = failures[0];
            var label = total > 1
                ? '[' + first.lang.toUpperCase() + '] ' + first.error
                : first.error;
            return tpl.replace('{error}', label);
        }
        var multi = i18n.translationDeleteMultiError
            || 'Failed for {n}/{total} languages: {details}';
        return multi
            .replace('{n}', String(failures.length))
            .replace('{total}', String(total))
            .replace('{details}', failures.map(function (f) {
                return f.lang.toUpperCase() + ': ' + f.error;
            }).join(' | '));
    }

    // ── Bulk remove-unused ──────────────────────────────────────────────

    /**
     * "Remove all unused" button handler — enters the list-first confirm
     * view (per Q4 lock — no bare confirm).
     */
    function _handleRemoveUnused() {
        if (!_grouped || !(_grouped.unused || []).length) return;
        _bulkConfirm = true;
        _bulkError = null;
        _bulkDeleteAllLangs = true;  // Reset per-open: bulk defaults ON.
        // Close any open row panel so the view is unambiguous.
        _expandedKey = null;
        _expandedMode = null;
        _actionError = null;
        _renderRows();
    }

    function _handleCancelBulkDelete() {
        _bulkConfirm = false;
        _bulkError = null;
        _renderRows();
    }

    async function _handleConfirmBulkDelete() {
        if (_bulkBusy) return;
        var keys = _bulkUnusedKeys();
        if (!keys.length) {
            _bulkConfirm = false;
            _renderRows();
            return;
        }
        _bulkBusy = true;
        _bulkError = null;
        _renderRows();

        var targetLangs = (_availableLangs.length >= 2 && _bulkDeleteAllLangs)
            ? _availableLangs.slice()
            : [_currentLang];

        var results = await Promise.all(targetLangs.map(function (lang) {
            return _deleteKeysForLang(lang, keys);
        }));

        var failures = [];
        results.forEach(function (r, i) {
            if (!r.ok) failures.push({ lang: targetLangs[i], error: r.error });
        });

        if (failures.length > 0) {
            _bulkError = _formatMultiLangDeleteError(failures, results.length);
            _bulkBusy = false;
            _renderRows();
            return;
        }

        _bulkBusy = false;
        _bulkConfirm = false;
        await _refetchAndRender();
        _reloadPreviewIframe();
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

    /**
     * Extract the unused keys for the bulk confirm. Uses the same shape
     * tolerance as _buildEntries (helper returns {value,label} objects).
     */
    function _bulkUnusedKeys() {
        var keys = [];
        (_grouped && _grouped.unused || []).forEach(function (item) {
            var k = (typeof item === 'string') ? item
                : (item && typeof item.value === 'string') ? item.value
                : null;
            if (k !== null) keys.push(k);
        });
        return keys;
    }

    /**
     * Render the bulk-delete confirm view that REPLACES the row list
     * (per Q4 lock: list-first, dry-run style). Shows every unused key
     * (with value preview) so the user reviews before pulling the trigger.
     */
    function _renderBulkConfirm() {
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var keys = _bulkUnusedKeys();
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-translation__bulk-confirm';

        var header = document.createElement('div');
        header.className = 'preview-contextual-translation__bulk-confirm-header';
        var tpl = i18n.translationBulkDeleteHeader
            || 'These {n} unused keys will be deleted from {lang}.json:';
        header.textContent = tpl
            .replace('{n}', String(keys.length))
            .replace('{lang}', (_currentLang || '').toUpperCase());
        wrap.appendChild(header);

        var listEl = document.createElement('ul');
        listEl.className = 'preview-contextual-translation__bulk-confirm-list';
        keys.forEach(function (key) {
            var li = document.createElement('li');
            li.className = 'preview-contextual-translation__bulk-confirm-item';
            var keyEl = document.createElement('code');
            keyEl.className = 'preview-contextual-translation__bulk-confirm-key';
            keyEl.textContent = key;
            li.appendChild(keyEl);
            var value = _resolveTranslation(key);
            if (value) {
                var valueEl = document.createElement('span');
                valueEl.className = 'preview-contextual-translation__bulk-confirm-value';
                valueEl.textContent = _truncate(value, 120);
                if (value.length > 120) valueEl.title = value;
                li.appendChild(valueEl);
            }
            listEl.appendChild(li);
        });
        wrap.appendChild(listEl);

        if (_bulkError) {
            var errEl = document.createElement('div');
            errEl.className = 'preview-contextual-translation__row-editor-error';
            errEl.textContent = _bulkError;
            wrap.appendChild(errEl);
        }

        var multiLang = _renderMultiLangCheckbox(_bulkDeleteAllLangs, function (checked) {
            _bulkDeleteAllLangs = checked;
        }, _bulkBusy);
        if (multiLang) wrap.appendChild(multiLang);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-translation__bulk-confirm-actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'admin-btn admin-btn--secondary';
        cancelBtn.textContent = i18n.translationEditCancel || 'Cancel';
        if (_bulkBusy) cancelBtn.disabled = true;
        cancelBtn.addEventListener('click', _handleCancelBulkDelete);
        actions.appendChild(cancelBtn);

        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'admin-btn admin-btn--danger';
        if (_bulkBusy) {
            deleteBtn.textContent = i18n.translationDeleting || 'Deleting…';
            deleteBtn.disabled = true;
        } else {
            var btnTpl = i18n.translationBulkDeleteConfirm || 'Delete {n} keys';
            deleteBtn.textContent = btnTpl.replace('{n}', String(keys.length));
        }
        deleteBtn.addEventListener('click', _handleConfirmBulkDelete);
        actions.appendChild(deleteBtn);

        wrap.appendChild(actions);
        return wrap;
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
    };
})();
