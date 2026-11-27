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
    // A table id: a letter, then letters, digits, hyphens or underscores (the emitted HTML id, and
    // the namespace of the keys a Translatable paste generates).
    var TABLE_ID_PATTERN = /^[a-zA-Z][a-zA-Z0-9_-]*$/;

    var t = window.QSComplexWizard.t;

    // ---- small render helpers --------------------------------------------

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
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

        // -- table id (required when paste mode = Translatable; used to
        //    namespace auto-generated translation keys) --
        var idGroup = _renderGroup();
        idGroup.appendChild(_renderLabel(t('table.id')));
        var tableIdInput = document.createElement('input');
        tableIdInput.type = 'text';
        tableIdInput.className = 'admin-input';
        tableIdInput.id = 'ce-table-id';
        tableIdInput.placeholder = t('table.idPlaceholder');
        tableIdInput.autocomplete = 'off';
        idGroup.appendChild(tableIdInput);
        // The Translatable paste mode's requirement, in the warning colour right under the field:
        // shown only while that mode is chosen and the id is empty or not valid (_syncIdRequirement).
        var idRequirement = _renderHint(t('table.idRequired'));
        idRequirement.classList.add('admin-hint--warning');
        idRequirement.hidden = true;
        idGroup.appendChild(idRequirement);
        idGroup.appendChild(_renderHint(t('table.idHint')));
        wrap.appendChild(idGroup);

        // -- caption picker --
        var capGroup = _renderGroup();
        capGroup.appendChild(_renderLabel(t('table.caption')));
        var capMount = document.createElement('div');
        capGroup.appendChild(capMount);
        wrap.appendChild(capGroup);
        var captionPicker = window.QSComplexWizard.createTextKeyPicker({
            container: capMount,
            placeholder: t('table.captionPlaceholder'),
            value: '',
        });

        // -- hasHead toggle --
        var headLabel = _renderCheckbox('ce-table-has-head', t('table.hasHead'), true);
        wrap.appendChild(_renderGroup(headLabel));
        var hasHeadInput = headLabel.querySelector('input');

        // -- column count --
        var colCountGroup = _renderGroup();
        colCountGroup.appendChild(_renderLabel(t('table.columns'), true));
        var colCountInput = document.createElement('input');
        colCountInput.type = 'number';
        colCountInput.className = 'admin-input';
        colCountInput.id = 'ce-table-cols';
        colCountInput.min = String(MIN_COLS);
        colCountInput.max = String(MAX_COLS);
        colCountInput.value = String(DEFAULT_COLS);
        colCountInput.style.maxWidth = '120px';
        colCountGroup.appendChild(colCountInput);
        colCountGroup.appendChild(_renderHint(t('table.columnsHint', {
            min: QSPanelWords.number(MIN_COLS),
            max: QSPanelWords.number(MAX_COLS),
        })));
        wrap.appendChild(colCountGroup);

        wrap.appendChild(_renderHr());

        // -- paste from spreadsheet (with mode radio) --
        // Two-mode design:
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
        pasteGroup.appendChild(_renderLabel(t('table.paste')));
        pasteGroup.appendChild(_renderHint(t('table.pasteHint')));

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
            t('table.modeRaw'),
            t('table.modeRawHint'),
            true));
        modeRow.appendChild(_renderModeRadio('translatable',
            t('table.modeTranslatable'),
            t('table.modeTranslatableHint'),
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
        langLabel.textContent = t('table.writeTo');
        var langSelect = document.createElement('select');
        langSelect.className = 'admin-input';
        langSelect.id = 'ce-table-paste-lang';
        langSelect.style.maxWidth = '160px';
        // Seed with the current language so the select is never empty even
        // before getLangList resolves.
        (function () {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = t('common.loading');
            langSelect.appendChild(opt);
        })();
        langRow.appendChild(langLabel);
        langRow.appendChild(langSelect);
        pasteGroup.appendChild(langRow);

        var pasteArea = document.createElement('textarea');
        pasteArea.className = 'admin-input';
        pasteArea.id = 'ce-table-paste';
        pasteArea.rows = 4;
        pasteArea.placeholder = t('table.pastePlaceholder');
        pasteGroup.appendChild(pasteArea);

        var pasteActions = document.createElement('div');
        pasteActions.style.marginTop = '6px';
        pasteActions.style.display = 'flex';
        pasteActions.style.gap = '8px';
        var pasteApply = document.createElement('button');
        pasteApply.type = 'button';
        pasteApply.className = 'admin-btn';
        pasteApply.textContent = t('table.pasteApply');
        pasteActions.appendChild(pasteApply);
        var pasteStatus = document.createElement('span');
        pasteStatus.className = 'admin-hint';
        pasteStatus.style.alignSelf = 'center';
        pasteStatus.textContent = t('table.pasteReplaces');
        pasteActions.appendChild(pasteStatus);
        pasteGroup.appendChild(pasteActions);
        wrap.appendChild(pasteGroup);

        wrap.appendChild(_renderHr());

        // -- header row (hidden when !hasHead) --
        var headerGroup = _renderGroup();
        headerGroup.appendChild(_renderLabel(t('table.headerRow')));
        var headerRow = document.createElement('div');
        headerRow.style.display = 'flex';
        headerRow.style.gap = '4px';
        headerGroup.appendChild(headerRow);
        wrap.appendChild(headerGroup);

        // -- body rows --
        var bodyGroup = _renderGroup();
        bodyGroup.appendChild(_renderLabel(t('table.bodyRows'), true));
        bodyGroup.appendChild(_renderHint(t('table.bodyRowsHint')));
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
            row.style.display = 'flex';
            row.style.gap = '4px';
            row.style.marginBottom = '4px';
            var pickers = [];
            for (var c = 0; c < currentColCount; c++) {
                var mount = _renderCellMount();
                row.appendChild(mount);
                var picker = window.QSComplexWizard.createTextKeyPicker({
                    container: mount,
                    placeholder: isHeader ? t('table.headerKeyPlaceholder') : t('table.cellKeyPlaceholder'),
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
                    placeholder: t('table.headerKeyPlaceholder'),
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
            addButtonLabel: t('table.addBodyRow'),
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
                            placeholder: t('table.cellKeyPlaceholder'),
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

        // A mode change toggles the language picker and keeps the id's requirement in step. A radio
        // announces 'change' only when it becomes the checked one, so each radio listens.
        wrap.querySelectorAll('input[name="ce-table-paste-mode"]').forEach(function (r) {
            r.addEventListener('change', function () {
                langRow.style.display = (r.checked && r.value === 'translatable') ? 'flex' : 'none';
                _syncIdRequirement();
            });
        });
        // Every edit of the id (typed, pasted, cut) announces 'input'; no script sets its value.
        tableIdInput.addEventListener('input', _syncIdRequirement);

        // ---- paste helpers ------------------------------------------------

        // The id's requirement shows while the paste mode is Translatable and the id is empty or
        // not valid, and is hidden otherwise.
        function _syncIdRequirement() {
            var tableId = (tableIdInput.value || '').trim();
            idRequirement.hidden = !(_currentPasteMode() === 'translatable' && !TABLE_ID_PATTERN.test(tableId));
        }

        // getCurrentLang() mirrors text-key-picker.js's getCurrentLang.
        function _getCurrentLang() {
            try {
                var sel = document.getElementById('preview-lang');
                if (sel && sel.value) return sel.value;
            } catch (e) {}
            try {
                var cfg = window.PreviewConfig;
                if (cfg && cfg.defaultLang) return cfg.defaultLang;
            } catch (e) {}
            return 'en';
        }

        // The project's languages, from the panel's one list of the site's words (QSSiteWords), read when the
        // wizard mounts: on a one-language site its one language, whose write the shared writer keeps in step with
        // default.json.
        function _fetchLangs() {
            return window.QSSiteWords.read().then(function (list) {
                if (!list) return [_getCurrentLang()];
                return list.multilingual ? list.codes.slice() : [list.defaultLanguage];
            });
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
                pasteStatus.textContent = t('table.nothingPasted');
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
                pasteStatus.textContent = t('table.appliedRaw');
                return;
            }

            // Translatable mode — needs an id + writes translations. Without a valid id the
            // requirement shows under the id field, which takes the focus; the status line goes
            // back to what a paste does rather than repeat the requirement.
            var tableId = (tableIdInput.value || '').trim();
            if (!TABLE_ID_PATTERN.test(tableId)) {
                _syncIdRequirement();
                pasteStatus.textContent = t('table.pasteReplaces');
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
                pasteStatus.textContent = t('table.nothingToTranslate');
                return;
            }

            // Disable the button during the network round-trip + show
            // status. Re-enable in finally.
            pasteApply.disabled = true;
            pasteStatus.textContent = t('table.writing', { language: targetLang });

            // Single request to the target language only, through the one list of the site's words
            // (QSSiteWords.write(): its answer checked). We deliberately do NOT write empty stubs to
            // other languages here — that's the cross-language CSV workflow's job: the others show a gap
            // until translated, which matches user intent ("I only translated this one language").
            // A refusal writes nothing and leaves the pickers as they were.
            window.QSSiteWords.write(targetLang, flat).then(function (done) {
                if (!done.ok) {
                    pasteStatus.textContent = t('table.notWritten', { reason: done.message });
                    return;
                }
                _applyGridToPickers(headerKeysT, bodyKeysT, maxCols);
                pasteStatus.textContent = QSPanelWords.count('complexWizard.table.wrote', Object.keys(flat).length,
                    { id: tableId, language: targetLang });
            }).catch(function (err) {
                console.error('[Table wizard] Translatable paste failed:', err);
                pasteStatus.textContent = t('table.writeFailed');
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
            if (tableId !== '' && !TABLE_ID_PATTERN.test(tableId)) {
                return t('table.errors.idFormat');
            }
            if (currentColCount < MIN_COLS) return t('table.errors.noColumn');
            if (currentColCount > MAX_COLS) return t('table.errors.tooManyColumns', { max: QSPanelWords.number(MAX_COLS) });
            if (hasHeadInput.checked) {
                // Per-cell empty header is allowed (renders as an empty <th>)
                // — but a header with EVERY cell empty is almost certainly
                // a misclick, so warn.
                var allEmpty = headerPickers.every(function (p) { return !(p.getValue() || '').trim(); });
                if (allEmpty) return t('table.errors.headerEmpty');
            }
            var rows = bodyEditor.getRows();
            if (rows.length === 0) return t('table.errors.noBodyRow');
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
        label: t('table.label'),
        description: t('table.description'),
        renderWizard: renderWizard
    };
})();
