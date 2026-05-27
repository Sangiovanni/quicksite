/**
 * Table wizard — kind 'table'.
 *
 * Emits a <table> with optional <caption>, optional <thead> (one <tr>
 * of N <th>), and a <tbody> of M rows × N columns of <td>. Every cell
 * is a translation key.
 *
 * Form shape:
 *  - Caption (optional textKey picker)
 *  - Has-header toggle (default ON)
 *  - Column count (number; resizes the grid in place)
 *  - Paste-from-spreadsheet shortcut (TSV / CSV) — overwrites the grid
 *  - Header row (one row of N textKey pickers; hidden when hasHead is off)
 *  - Body rows (row editor; each row hosts N textKey pickers)
 *
 * Server-side builder: secure/src/classes/complexElements/Table.php
 *
 * Implementation notes:
 *  - Per CLAUDE.md HTML-in-JS hygiene: createElement + textContent
 *    throughout; no `innerHTML = '<...>'` strings.
 *  - Column count changes preserve overlapping cell values: existing
 *    pickers within the new column range stay alive (with their picker
 *    cache and their pre-typed values); excess pickers are destroyed.
 *  - The body grid uses createRowEditor so reorder / delete / add-row
 *    keyboard nav come for free.
 *  - The TextKey cache (in text-key-picker.js) is module-scoped and
 *    shared across instances — a 5×5 table fires ONE catalogue fetch
 *    for all 25 pickers combined.
 */
(function () {
    'use strict';

    var MIN_COLS = 1;
    var MAX_COLS = 20;
    var DEFAULT_COLS = 3;
    var DEFAULT_BODY_ROWS = 2;

    // ---- small render helpers --------------------------------------------

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
            star.className = 'admin-text-danger';
            star.textContent = ' *';
            l.appendChild(star);
        }
        return l;
    }
    function _renderHint(text) {
        var p = document.createElement('p');
        p.className = 'admin-hint';
        p.textContent = text;
        return p;
    }
    function _renderGroup(child) {
        var g = document.createElement('div');
        g.className = 'admin-form-group';
        if (child) g.appendChild(child);
        return g;
    }
    function _renderHr() {
        var hr = document.createElement('hr');
        hr.style.margin = '12px 0';
        return hr;
    }
    function _renderCheckbox(id, text, checked) {
        var l = document.createElement('label');
        l.className = 'admin-checkbox';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = id;
        cb.checked = !!checked;
        var s = document.createElement('span');
        s.textContent = text;
        l.appendChild(cb);
        l.appendChild(s);
        return l;
    }

    // Parse a tab-or-comma-separated paste blob into a 2-D array. Tabs win
    // when present on the first non-empty line (matches Excel / Sheets /
    // Numbers' clipboard format when copying a range). Quotes / escaping
    // are NOT supported in this MVP — pasted cells with commas inside a
    // quoted string will mis-split. Document the limitation; CSV users
    // with quoted cells can fix individual cells after pasting.
    function _parseTabularBlob(text) {
        var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
        // Drop trailing blank line that pastes from spreadsheets often carry.
        while (lines.length && lines[lines.length - 1] === '') lines.pop();
        if (lines.length === 0) return [];
        var firstNonEmpty = lines.find(function (l) { return l.trim() !== ''; }) || '';
        var sep = firstNonEmpty.indexOf('\t') !== -1 ? '\t' : ',';
        return lines.map(function (l) {
            return l.split(sep).map(function (c) { return c.trim(); });
        });
    }

    // ---- main wizard ------------------------------------------------------

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--table';

        // -- table id (required when paste mode = Translatable; used to
        //    namespace auto-generated translation keys) --
        var idGroup = _renderGroup();
        idGroup.appendChild(_renderLabel('Table id (HTML id of the <table>; required when using Translatable paste mode)'));
        var tableIdInput = document.createElement('input');
        tableIdInput.type = 'text';
        tableIdInput.className = 'admin-input';
        tableIdInput.id = 'ce-table-id';
        tableIdInput.placeholder = 'e.g. q1Sales';
        tableIdInput.autocomplete = 'off';
        idGroup.appendChild(tableIdInput);
        idGroup.appendChild(_renderHint('Used to namespace auto-generated keys when pasting in Translatable mode (e.g. "table.q1Sales.head.0"). Letters/digits/hyphens/underscores; must start with a letter. Optional when pasting in RAW mode or when typing each cell key manually.'));
        wrap.appendChild(idGroup);

        // -- caption picker --
        var capGroup = _renderGroup();
        capGroup.appendChild(_renderLabel('Caption (optional)'));
        var capMount = document.createElement('div');
        capGroup.appendChild(capMount);
        wrap.appendChild(capGroup);
        var captionPicker = window.QSComplexWizard.createTextKeyPicker({
            container: capMount,
            placeholder: 'e.g. tables.q1Sales.caption (leave blank for no caption)',
            value: '',
        });

        // -- hasHead toggle --
        var headLabel = _renderCheckbox('ce-table-has-head', 'Include header row (<thead>)', true);
        wrap.appendChild(_renderGroup(headLabel));
        var hasHeadInput = headLabel.querySelector('input');

        // -- column count --
        var colCountGroup = _renderGroup();
        colCountGroup.appendChild(_renderLabel('Columns', true));
        var colCountInput = document.createElement('input');
        colCountInput.type = 'number';
        colCountInput.className = 'admin-input';
        colCountInput.id = 'ce-table-cols';
        colCountInput.min = String(MIN_COLS);
        colCountInput.max = String(MAX_COLS);
        colCountInput.value = String(DEFAULT_COLS);
        colCountInput.style.maxWidth = '120px';
        colCountGroup.appendChild(colCountInput);
        colCountGroup.appendChild(_renderHint('Resizing preserves overlapping cells. Limits: ' + MIN_COLS + '–' + MAX_COLS + '.'));
        wrap.appendChild(colCountGroup);

        wrap.appendChild(_renderHr());

        // -- paste from spreadsheet (with mode radio) --
        // Two-mode design (per Option C, the user's preferred hybrid):
        //   RAW         : prefixes each cell with __RAW__ → renders verbatim
        //                 in every language; zero key creation, zero
        //                 translation-file pollution.
        //   Translatable: auto-generates keys (`table.<id>.head.<col>` /
        //                 `table.<id>.body.<r>.<c>`) and writes the pasted
        //                 values via setTranslationKeys for every active
        //                 language (typed value for the current language,
        //                 empty for others — same convention the textKey
        //                 picker uses when creating new keys).
        var pasteGroup = _renderGroup();
        pasteGroup.appendChild(_renderLabel('Paste from spreadsheet (optional)'));
        pasteGroup.appendChild(_renderHint('Paste TSV (copy-paste from Excel / Sheets) or CSV. First row becomes the header when "Include header row" is on.'));

        // Paste mode radio.
        var modeRow = document.createElement('div');
        modeRow.style.display = 'flex';
        modeRow.style.gap = '14px';
        modeRow.style.marginBottom = '6px';

        function _renderModeRadio(value, label, hintBelow, isDefault) {
            var l = document.createElement('label');
            l.className = 'admin-checkbox';
            l.style.alignItems = 'flex-start';
            l.style.gap = '6px';
            var r = document.createElement('input');
            r.type = 'radio';
            r.name = 'ce-table-paste-mode';
            r.value = value;
            r.checked = !!isDefault;
            r.style.marginTop = '2px';
            var col = document.createElement('span');
            col.style.display = 'flex';
            col.style.flexDirection = 'column';
            var top = document.createElement('span');
            top.textContent = label;
            var hint = document.createElement('span');
            hint.className = 'admin-hint';
            hint.style.margin = '0';
            hint.textContent = hintBelow;
            col.appendChild(top);
            col.appendChild(hint);
            l.appendChild(r);
            l.appendChild(col);
            return l;
        }
        modeRow.appendChild(_renderModeRadio('raw',
            'RAW (literal — same in all languages)',
            'Each cell becomes __RAW__<value> — rendered verbatim. No keys created. Fastest.',
            true));
        modeRow.appendChild(_renderModeRadio('translatable',
            'Translatable (auto-generate keys + write values to one language)',
            'Requires a table id (above). Keys become "table.<id>.head.<col>" / "table.<id>.body.<r>.<c>". Values are written via setTranslationKeys for the language you pick below. EMPTY cells are skipped (no orphan keys).',
            false));
        pasteGroup.appendChild(modeRow);

        // Language picker for Translatable mode (hidden when mode = RAW).
        // Populated from getLangList on wizard mount; defaults to the
        // editor's current language. Lets the user paste an EN CSV while
        // editing in FR (or vice-versa) without having to switch the
        // editor's language first.
        var langRow = document.createElement('div');
        langRow.style.display = 'none'; // toggled by mode radio
        langRow.style.marginBottom = '6px';
        langRow.style.alignItems = 'center';
        langRow.style.gap = '8px';
        var langLabel = document.createElement('span');
        langLabel.className = 'admin-hint';
        langLabel.style.margin = '0';
        langLabel.textContent = 'Write values to language:';
        var langSelect = document.createElement('select');
        langSelect.className = 'admin-input';
        langSelect.id = 'ce-table-paste-lang';
        langSelect.style.maxWidth = '160px';
        // Seed with the current language so the select is never empty even
        // before getLangList resolves.
        (function () {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '(loading…)';
            langSelect.appendChild(opt);
        })();
        langRow.appendChild(langLabel);
        langRow.appendChild(langSelect);
        pasteGroup.appendChild(langRow);

        var pasteArea = document.createElement('textarea');
        pasteArea.className = 'admin-input';
        pasteArea.id = 'ce-table-paste';
        pasteArea.rows = 4;
        pasteArea.placeholder = 'Paste your TSV / CSV here…';
        pasteGroup.appendChild(pasteArea);

        var pasteActions = document.createElement('div');
        pasteActions.style.marginTop = '6px';
        pasteActions.style.display = 'flex';
        pasteActions.style.gap = '8px';
        var pasteApply = document.createElement('button');
        pasteApply.type = 'button';
        pasteApply.className = 'admin-btn admin-btn--sm';
        pasteApply.textContent = 'Apply pasted grid';
        pasteActions.appendChild(pasteApply);
        var pasteStatus = document.createElement('span');
        pasteStatus.className = 'admin-hint';
        pasteStatus.style.alignSelf = 'center';
        pasteStatus.textContent = 'Replaces ALL current cells.';
        pasteActions.appendChild(pasteStatus);
        pasteGroup.appendChild(pasteActions);
        wrap.appendChild(pasteGroup);

        wrap.appendChild(_renderHr());

        // -- header row (hidden when !hasHead) --
        var headerGroup = _renderGroup();
        headerGroup.appendChild(_renderLabel('Header row'));
        var headerRow = document.createElement('div');
        headerRow.className = 'qs-table-header-row';
        headerRow.style.display = 'flex';
        headerRow.style.gap = '4px';
        headerGroup.appendChild(headerRow);
        wrap.appendChild(headerGroup);

        // -- body rows --
        var bodyGroup = _renderGroup();
        bodyGroup.appendChild(_renderLabel('Body rows', true));
        bodyGroup.appendChild(_renderHint('Reorder / delete rows with the side toolbar (Alt+↑/↓ also works).'));
        var bodyHost = document.createElement('div');
        bodyGroup.appendChild(bodyHost);
        wrap.appendChild(bodyGroup);

        container.appendChild(wrap);

        // ---- state --------------------------------------------------------
        // currentColCount drives all rebuilds; rowPickers maps each row's
        // content element to its current Picker[]. WeakMap so rows that
        // get removed by the row editor don't pin pickers in memory.
        var currentColCount = DEFAULT_COLS;
        var headerPickers = [];       // Picker[] (length = currentColCount when hasHead)
        var rowPickers = new WeakMap(); // contentEl → Picker[]

        // ---- per-row builder ---------------------------------------------
        function _renderCellMount() {
            var c = document.createElement('div');
            c.style.flex = '1';
            c.style.minWidth = '0';
            return c;
        }

        function _renderRow(data, isHeader) {
            var d = data || { cells: [] };
            var row = document.createElement('div');
            row.className = isHeader ? 'qs-table-header-row-inner' : 'qs-table-body-row';
            row.style.display = 'flex';
            row.style.gap = '4px';
            row.style.marginBottom = '4px';
            var pickers = [];
            for (var c = 0; c < currentColCount; c++) {
                var mount = _renderCellMount();
                row.appendChild(mount);
                var picker = window.QSComplexWizard.createTextKeyPicker({
                    container: mount,
                    placeholder: isHeader ? 'header key' : 'cell key',
                    value: (d.cells && d.cells[c]) || '',
                });
                pickers.push(picker);
            }
            if (!isHeader) rowPickers.set(row, pickers);
            return row;
        }

        // Initial header (one row of N pickers, no row-editor toolbar)
        function _rebuildHeader(values) {
            // Destroy old pickers
            headerPickers.forEach(function (p) { try { p.destroy(); } catch (e) {} });
            headerRow.textContent = '';
            headerPickers = [];
            for (var c = 0; c < currentColCount; c++) {
                var mount = _renderCellMount();
                headerRow.appendChild(mount);
                var picker = window.QSComplexWizard.createTextKeyPicker({
                    container: mount,
                    placeholder: 'header key',
                    value: (values && values[c]) || '',
                });
                headerPickers.push(picker);
            }
            // Hide entire group when hasHead is off.
            headerGroup.style.display = hasHeadInput.checked ? '' : 'none';
        }

        // ---- body row editor ----------------------------------------------
        var bodyEditor = window.QSComplexWizard.createRowEditor({
            container: bodyHost,
            addButtonLabel: '+ Add body row',
            minRows: 1,
            initialRows: (function () {
                var rows = [];
                for (var i = 0; i < DEFAULT_BODY_ROWS; i++) {
                    rows.push({ cells: new Array(DEFAULT_COLS).fill('') });
                }
                return rows;
            })(),
            renderRow: function (data) { return _renderRow(data, false); },
            readRow: function (contentEl) {
                var pickers = rowPickers.get(contentEl) || [];
                return {
                    cells: pickers.map(function (p) { return (p.getValue() || '').trim(); })
                };
            }
        });

        // Initial header.
        _rebuildHeader([]);

        // ---- dimension sync (column count change) -------------------------
        // Preserve overlapping cells: keep pickers in positions < newCount,
        // destroy pickers in positions ≥ newCount, create new pickers when
        // growing. Walks header + every body row.
        function _syncColCount(newCount) {
            newCount = Math.max(MIN_COLS, Math.min(MAX_COLS, Math.floor(newCount)));
            if (newCount === currentColCount) return;
            var oldCount = currentColCount;
            currentColCount = newCount;

            // Header (full rebuild — small N).
            var headerValues = headerPickers.map(function (p) { return p.getValue() || ''; });
            _rebuildHeader(headerValues);

            // Body rows: incremental shrink/grow per row to keep typed values.
            var bodyContents = bodyHost.querySelectorAll(':scope > .qs-wizard-row > .qs-wizard-row__content');
            bodyContents.forEach(function (rowEl) {
                var pickers = rowPickers.get(rowEl) || [];
                if (newCount < oldCount) {
                    // Shrink: destroy + remove the trailing pickers and their mounts.
                    for (var i = oldCount - 1; i >= newCount; i--) {
                        try { pickers[i].destroy(); } catch (e) {}
                        if (rowEl.children[i]) rowEl.removeChild(rowEl.children[i]);
                    }
                    pickers.length = newCount;
                } else {
                    // Grow: add fresh empty pickers at the end.
                    for (var j = oldCount; j < newCount; j++) {
                        var mount = _renderCellMount();
                        rowEl.appendChild(mount);
                        var picker = window.QSComplexWizard.createTextKeyPicker({
                            container: mount,
                            placeholder: 'cell key',
                            value: '',
                        });
                        pickers.push(picker);
                    }
                }
                rowPickers.set(rowEl, pickers);
            });
        }

        // ---- listeners ----------------------------------------------------
        colCountInput.addEventListener('change', function () {
            var n = parseInt(colCountInput.value, 10);
            if (!Number.isFinite(n)) return;
            _syncColCount(n);
            colCountInput.value = String(currentColCount); // clamp display
        });

        hasHeadInput.addEventListener('change', function () {
            headerGroup.style.display = hasHeadInput.checked ? '' : 'none';
        });

        // Toggle the language picker visibility on mode change.
        wrap.querySelectorAll('input[name="ce-table-paste-mode"]').forEach(function (r) {
            r.addEventListener('change', function () {
                langRow.style.display = (r.checked && r.value === 'translatable') ? 'flex' : 'none';
            });
        });

        // ---- paste helpers ------------------------------------------------

        // getCurrentLang() mirrors text-key-picker.js's getCurrentLang.
        function _getCurrentLang() {
            try {
                var sel = document.getElementById('preview-lang-select');
                if (sel && sel.value) return sel.value;
            } catch (e) {}
            try {
                var cfg = window.PreviewConfig;
                if (cfg && cfg.defaultLang) return cfg.defaultLang;
            } catch (e) {}
            return 'en';
        }

        // Per-wizard-instance cache for the project's lang list. Fetched
        // lazily on first Translatable-mode paste; reused on subsequent
        // pastes within the same wizard session.
        var _langsCache = null;
        function _fetchLangs() {
            if (_langsCache) return Promise.resolve(_langsCache);
            var api = window.QuickSiteAdmin;
            if (!api) return Promise.resolve([_getCurrentLang()]);
            // QuickSiteAdmin.apiRequest returns {ok, status, data: <envelope>}
            // where the ApiResponse envelope wraps the payload again in `.data`.
            // So the languages array sits at res.data.data.languages. Use the
            // defensive `(payload.data || payload)` unwrap pattern from
            // text-key-picker.js loadKeysOnce() in case any future apiRequest
            // refactor smooths the double-wrap.
            return api.apiRequest('getLangList', 'GET').then(function (res) {
                var payload = (res && res.data && (res.data.data || res.data)) || {};
                var langs = Array.isArray(payload.languages) ? payload.languages : [];
                if (langs.length === 0) langs = [_getCurrentLang()];
                _langsCache = langs;
                return langs;
            }).catch(function () {
                // Degrade gracefully — write only to current lang if we can't
                // discover the full set.
                _langsCache = [_getCurrentLang()];
                return _langsCache;
            });
        }

        // Build a nested object from a dot-shaped key + value, mirroring
        // text-key-picker.js's nestedFromKey. Composed via _mergeTranslations
        // so many keys land in one tree.
        function _setNested(tree, dotKey, value) {
            var parts = String(dotKey).split('.');
            var node = tree;
            for (var i = 0; i < parts.length - 1; i++) {
                var k = parts[i];
                if (!node[k] || typeof node[k] !== 'object') node[k] = {};
                node = node[k];
            }
            node[parts[parts.length - 1]] = value;
        }

        function _currentPasteMode() {
            var checked = wrap.querySelector('input[name="ce-table-paste-mode"]:checked');
            return checked ? checked.value : 'raw';
        }

        // Apply a (possibly headered) grid to the wizard pickers. Caller
        // decides what the per-cell string is (e.g. RAW-prefixed or
        // generated key).
        function _applyGridToPickers(headerValues, bodyGrid, maxCols) {
            currentColCount = maxCols;
            colCountInput.value = String(maxCols);
            _rebuildHeader(headerValues);
            bodyEditor.clear();
            bodyGrid.forEach(function (row) {
                var cells = row.slice(0, maxCols);
                while (cells.length < maxCols) cells.push('');
                bodyEditor.addRow({ cells: cells });
            });
        }

        // ---- eager language fetch (for the language select) --------------
        // Populates the langSelect with every configured project language
        // and defaults the selection to the editor's current language.
        // Runs on mount so the select is ready when the user picks
        // Translatable mode + pastes — no waiting on round-trips at that
        // moment.
        (function _populateLangSelect() {
            _fetchLangs().then(function (langs) {
                var current = _getCurrentLang();
                langSelect.textContent = '';
                langs.forEach(function (lang) {
                    var opt = document.createElement('option');
                    opt.value = lang;
                    opt.textContent = lang;
                    if (lang === current) opt.selected = true;
                    langSelect.appendChild(opt);
                });
            });
        })();

        // ---- paste handler ------------------------------------------------

        pasteApply.addEventListener('click', function () {
            var grid = _parseTabularBlob(pasteArea.value);
            if (grid.length === 0) {
                pasteStatus.textContent = 'Nothing pasted.';
                return;
            }
            var maxCols = 0;
            grid.forEach(function (row) { if (row.length > maxCols) maxCols = row.length; });
            maxCols = Math.max(MIN_COLS, Math.min(MAX_COLS, maxCols));

            // Split header / body per the hasHead toggle.
            var headerRaw = [];
            var bodyRaw = grid;
            if (hasHeadInput.checked && grid.length > 0) {
                headerRaw = (grid[0] || []).slice(0, maxCols);
                while (headerRaw.length < maxCols) headerRaw.push('');
                bodyRaw = grid.slice(1);
            }
            if (bodyRaw.length === 0) bodyRaw = [new Array(maxCols).fill('')];

            var mode = _currentPasteMode();

            if (mode === 'raw') {
                // Prefix every non-empty cell with __RAW__. Empty cells
                // stay empty (the renderer's `__RAW__` short-circuit only
                // kicks in when the prefix is present).
                var headerKeysRaw = headerRaw.map(function (v) { return v === '' ? '' : '__RAW__' + v; });
                var bodyKeysRaw = bodyRaw.map(function (row) {
                    return row.map(function (v) { return v === '' ? '' : '__RAW__' + v; });
                });
                _applyGridToPickers(headerKeysRaw, bodyKeysRaw, maxCols);
                pasteStatus.textContent = 'Applied as RAW (literal text).';
                return;
            }

            // Translatable mode — needs an id + writes translations.
            var tableId = (tableIdInput.value || '').trim();
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(tableId)) {
                pasteStatus.textContent = 'Translatable mode needs a valid table id above.';
                tableIdInput.focus();
                return;
            }

            // Target language from the picker (falls back to current editor
            // lang if the dropdown hasn't populated yet — shouldn't happen
            // in practice but defends against the race).
            var targetLang = langSelect.value || _getCurrentLang();

            // Build {key: value} payload — ONLY for non-empty cells.
            // Empty pasted cells get NO key at all (avoids orphan keys
            // that would render as "missing translation" everywhere).
            // The corresponding wizard picker for an empty cell is left
            // empty so the user can fill it in later via the Text element
            // wizard or by typing a key directly.
            var flat = {};
            // Parallel maps for the wizard pickers — empty when the
            // pasted cell was empty, else the generated key.
            var headerKeysT = [];
            if (hasHeadInput.checked) {
                headerRaw.forEach(function (v, c) {
                    if (v === '') {
                        headerKeysT.push('');
                    } else {
                        var k = 'table.' + tableId + '.head.' + c;
                        flat[k] = v;
                        headerKeysT.push(k);
                    }
                });
            }
            var bodyKeysT = bodyRaw.map(function (row, r) {
                return row.map(function (v, c) {
                    if (v === '') return '';
                    var k = 'table.' + tableId + '.body.' + r + '.' + c;
                    flat[k] = v;
                    return k;
                });
            });

            if (Object.keys(flat).length === 0) {
                // Defensive: pasting a grid of empty cells in Translatable
                // mode would otherwise hit setTranslationKeys with an empty
                // payload (which the backend likely accepts as a no-op,
                // but skipping the call is cleaner).
                _applyGridToPickers(headerKeysT, bodyKeysT, maxCols);
                pasteStatus.textContent = 'Nothing to translate (all cells empty).';
                return;
            }

            // Disable the button during the network round-trip + show
            // status. Re-enable in finally.
            pasteApply.disabled = true;
            pasteStatus.textContent = 'Writing translations to "' + targetLang + '"…';

            var api = window.QuickSiteAdmin;
            if (!api) {
                pasteStatus.textContent = 'Admin API unavailable.';
                pasteApply.disabled = false;
                return;
            }

            // Single request to the target language only. We deliberately
            // do NOT write empty stubs to other languages here — that's
            // the cross-language CSV workflow's job
            // (BETA7_TABLE_TRANSLATION_CSV.md). Other-language render-time
            // lookups will warn for keys missing in those languages — that
            // matches user intent ("I only translated this one language").
            var tree = {};
            Object.keys(flat).forEach(function (k) { _setNested(tree, k, flat[k]); });
            api.apiRequest('setTranslationKeys', 'POST', {
                language: targetLang,
                translations: tree,
            }).then(function () {
                _applyGridToPickers(headerKeysT, bodyKeysT, maxCols);
                pasteStatus.textContent = 'Wrote ' + Object.keys(flat).length + ' translation key(s) for "' + tableId + '" (' + targetLang + ').';
            }).catch(function (err) {
                console.error('[Table wizard] Translatable paste failed:', err);
                pasteStatus.textContent = 'Translation write failed — see console.';
            }).then(function () {
                pasteApply.disabled = false;
            });
        });

        // ---- controller ---------------------------------------------------
        function getConfig() {
            var cfg = {
                colCount: currentColCount,
                hasHead: !!hasHeadInput.checked,
                bodyRows: bodyEditor.getRows().map(function (r) {
                    var cells = (r.cells || []).slice(0, currentColCount);
                    while (cells.length < currentColCount) cells.push('');
                    return cells;
                })
            };
            var captionKey = (captionPicker.getValue() || '').trim();
            if (captionKey !== '') cfg.captionKey = captionKey;
            if (cfg.hasHead) {
                cfg.headerCells = headerPickers.map(function (p) { return (p.getValue() || '').trim(); });
            }
            // id is optional; emitted on the <table> when provided so the
            // cross-language CSV-translation workflow (planned follow-up)
            // can find this table by id later.
            var tableId = (tableIdInput.value || '').trim();
            if (tableId !== '') cfg.id = tableId;
            return cfg;
        }

        function validate() {
            // id is optional in general but its format is validated when
            // provided — the cross-language translation workflow + the
            // emitted HTML id both need it to match the standard format.
            var tableId = (tableIdInput.value || '').trim();
            if (tableId !== '' && !/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(tableId)) {
                return 'Table id must start with a letter; use only letters, digits, hyphens, underscores.';
            }
            if (currentColCount < MIN_COLS) return 'Add at least one column.';
            if (currentColCount > MAX_COLS) return 'Column count exceeds the limit (' + MAX_COLS + ').';
            if (hasHeadInput.checked) {
                // Per-cell empty header is allowed (renders as an empty <th>)
                // — but a header with EVERY cell empty is almost certainly
                // a misclick, so warn.
                var allEmpty = headerPickers.every(function (p) { return !(p.getValue() || '').trim(); });
                if (allEmpty) return 'Header row is all empty. Turn off "Include header row" if you don\'t want a <thead>.';
            }
            var rows = bodyEditor.getRows();
            if (rows.length === 0) return 'Add at least one body row.';
            // Body cells CAN be empty (renders as empty <td>) — no per-cell
            // validation. The user may want sparse cells.
            return null;
        }

        function destroy() {
            try { captionPicker.destroy(); } catch (e) {}
            headerPickers.forEach(function (p) { try { p.destroy(); } catch (e) {} });
            try { bodyEditor.destroy(); } catch (e) {}
            container.textContent = '';
        }

        return { getConfig: getConfig, validate: validate, destroy: destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['table'] = {
        label: 'Table',
        description: 'A &lt;table&gt; with optional caption, optional thead, and N×M tbody cells. Each cell is a translation key. Spreadsheet paste (TSV/CSV) supported.',
        renderWizard: renderWizard
    };
})();
