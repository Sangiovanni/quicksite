/**
 * The visual editor's srcset field (PreviewSrcsetField): a `srcset` edited as rows, each a picture and its descriptor,
 * in place of a text box. preview.js draws it in the Add form's parameters box, on a <source>'s one-of row and as the
 * optional row an <img> offers, in Add and in Edit Params. Its rules (the split, what rows can hold, the line the rows
 * write, each row's problem) are core/srcset.js's (QSSrcset); this file is the field's screen.
 *
 * What the person sees:
 *   - one kind of descriptor for the whole list, in plain words: lighter files on small screens (widths) or sharper on
 *     high-resolution screens (densities);
 *   - rows, each a picture from Media (the editor's own picker, which hands back the picture's width and format) or an
 *     address, with its width (filled from Media, typed for any other address) or its density (a slider, 1x to 4x by
 *     0.5), + to add a row and x to remove one; the rows sorted by their descriptor once one is set;
 *   - in widths mode, `sizes`: the whole width by default, a few plain choices, and free text;
 *   - on a <source>, `type`: filled from the pictures' format when they share one, asked when they do not;
 *   - the written srcset under the rows, read only, and a switch to edit it as text.
 * A problem (a duplicate descriptor, a width missing, an address the split would cut) is said in its row and is never
 * saved; Save asks problem() first. A value the rows cannot hold opens as text, a line saying why.
 *
 * Nothing is rewritten that was not changed: the field keeps the value it opened with, and gives it back byte for byte
 * while the rows still write what that value's own rows write (and while the text still reads as it opened). Only a
 * change writes the rows' line. `sizes` and `type` the same.
 *
 * The field's inputs are never parameters by themselves: its root carries data-param-field, which the Add form's
 * read of its parameters box leaves out, and the field answers its parameters through params() alone.
 */
window.PreviewSrcsetField = (function () {
    'use strict';

    /** The plain choices of `sizes` in widths mode; Other… takes free text. The first is the default. */
    const SIZES_CHOICES = [
        { value: '100vw', text: () => QSPanelWords.t('preview.srcsetSizesFull') },
        { value: '50vw', text: () => QSPanelWords.t('preview.srcsetSizesHalf') },
        { value: '33vw', text: () => QSPanelWords.t('preview.srcsetSizesThird') }
    ];
    const SIZES_OTHER = 'other';
    /** The kind a new field starts on (Sangio, 2026-11-23: a convention, not a setting). */
    const DEFAULT_KIND = 'width';

    let fieldCount = 0;

    /** A line break's two spellings read as a text box reads them: what a textarea gives back for a value set in it. */
    function asTextarea(value) {
        return String(value).replace(/\r\n?/g, '\n');
    }

    /** Whether a typed text writes nothing: white space and commas alone. */
    function blank(text) {
        return /^[ \t\n\f\r,]*$/.test(text);
    }

    /** The words of a row's problem (QSSrcset.check()'s codes). */
    function rowProblemText(code, descriptor) {
        if (code === 'duplicate') return QSPanelWords.t('preview.srcsetRowDuplicate', { descriptor: descriptor });
        if (code === 'width') return QSPanelWords.t('preview.srcsetRowWidth');
        if (code === 'space') return QSPanelWords.t('preview.srcsetRowSpace');
        if (code === 'comma') return QSPanelWords.t('preview.srcsetRowComma');
        return '';
    }

    /** The words of why rows cannot hold a value (QSSrcset.read()'s codes). */
    function reasonText(r) {
        if (r.code === 'skipped') return QSPanelWords.t('preview.srcsetReasonSkipped', { candidate: r.candidate });
        if (r.code === 'mixed') return QSPanelWords.t('preview.srcsetReasonMixed');
        if (r.code === 'duplicate') return QSPanelWords.t('preview.srcsetReasonDuplicate', { descriptor: r.descriptor });
        if (r.code === 'height') return QSPanelWords.t('preview.srcsetReasonHeight', { candidate: r.candidate });
        if (r.code === 'density') return QSPanelWords.t('preview.srcsetReasonDensity', { descriptor: r.descriptor });
        return '';
    }

    /** One choice of the kind: a radio and its words. Returns ONE Element. */
    function _renderKindChoice(name, value, words, checked) {
        const radio = QSDom.el('input', { type: 'radio', name: name, value: value });
        radio.checked = checked;
        return QSDom.el('label', { class: 'preview-srcset-field__kind' }, [radio, QSDom.el('span', { text: words })]);
    }

    /** An option of a select. Returns ONE Element. */
    function _renderOption(value, text) {
        return QSDom.el('option', { value: value, text: text });
    }

    /**
     * A field. opts:
     *   param        the parameter it writes ('srcset');
     *   withType     true on a <source>: the field offers `type`;
     *   closable     true on an <img>: an × at its top right closes it (opts.onClose);
     *   pickPicture  (input, onPick) opens the editor's Media picker on a row's address; onPick(asset) gets the picture;
     *   adopt        (name) a value another row of the form holds for `sizes` or `type`, taken from it, or null:
     *                asked when the field first shows that control, so one place holds each name.
     * Returns { root, open(params) → the names it took, params(), problem(), reset(), destroy() }.
     */
    function create(opts) {
        const o = opts || {};
        const param = o.param || 'srcset';
        const id = 'qs-srcset-' + (++fieldCount);

        let mode = 'rows';
        let kind = DEFAULT_KIND;
        let rows = [];
        // What the node held when the field opened (Add: nothing). line: what its own rows write, when rows hold it.
        let original = { value: '', holdable: true, line: '' };
        // What the node held for each (null: nothing known; '' it had none), whether the person changed it, whether shown.
        const sizes = { original: null, touched: false, shown: false };
        // type.auto: filled from the pictures' format while the person has not chosen one.
        const type = { original: null, touched: false, shown: false, auto: true };

        // ── the screen ────────────────────────────────────────────────────────
        const root = QSDom.el('div', { class: 'preview-srcset-field' + (o.closable ? ' preview-srcset-field--closable' : ''), 'data-param-field': param });
        if (o.closable) {
            // The field's × at its top right: the panel's close, placed by the field's own class
            root.appendChild(QSControls.close(QSPanelWords.t('preview.srcsetOfferClose'),
                () => { if (typeof o.onClose === 'function') o.onClose(); }, { className: 'preview-srcset-field__close' }));
        }

        const kinds = QSDom.el('div', { class: 'preview-srcset-field__kinds', role: 'radiogroup' }, [
            _renderKindChoice(id + '-kind', 'width', QSPanelWords.t('preview.srcsetKindWidths'), kind === 'width'),
            _renderKindChoice(id + '-kind', 'density', QSPanelWords.t('preview.srcsetKindDensities'), kind === 'density')
        ]);
        const list = QSDom.el('div', { class: 'preview-srcset-field__rows' });
        const addBtn = QSDom.el('button', { type: 'button', class: 'preview-contextual-form__add-param-btn preview-srcset-field__add' },
            ['+ ', QSPanelWords.t('preview.srcsetAddPicture')]);

        const sizesSelect = QSDom.el('select', { class: 'admin-input admin-input--sm preview-srcset-field__sizes-select', id: id + '-sizes' },
            SIZES_CHOICES.map((c) => _renderOption(c.value, c.text())).concat([_renderOption(SIZES_OTHER, QSPanelWords.t('preview.srcsetSizesOther'))]));
        const sizesText = QSDom.el('input', { type: 'text', class: 'admin-input admin-input--sm preview-srcset-field__sizes-text',
            placeholder: QSPanelWords.t('preview.srcsetSizesPlaceholder'), 'aria-label': QSPanelWords.t('preview.srcsetSizesPlaceholder'), hidden: '' });
        const sizesBox = QSDom.el('div', { class: 'preview-srcset-field__line', hidden: '' }, [
            QSDom.el('label', { class: 'preview-srcset-field__label', for: id + '-sizes', text: QSPanelWords.t('preview.srcsetSizesLabel') }),
            sizesSelect,
            sizesText
        ]);

        const typeSelect = QSDom.el('select', { class: 'admin-input admin-input--sm preview-srcset-field__type-select', id: id + '-type' },
            [_renderOption('', QSPanelWords.t('preview.srcsetTypeNone'))].concat(QSSrcset.formats().map((f) => _renderOption(f, f))));
        const typeHint = QSDom.el('small', { class: 'preview-srcset-field__hint', hidden: '' });
        const typeBox = QSDom.el('div', { class: 'preview-srcset-field__line', hidden: '' }, [
            QSDom.el('label', { class: 'preview-srcset-field__label', for: id + '-type', text: QSPanelWords.t('preview.srcsetTypeLabel') }),
            typeSelect,
            typeHint
        ]);

        const textarea = QSDom.el('textarea', { class: 'admin-input admin-input--sm preview-srcset-field__textarea', rows: '3',
            spellcheck: 'false', 'aria-label': param });
        const reasonLine = QSDom.el('small', { class: 'preview-srcset-field__reason', hidden: '' });
        const textBox = QSDom.el('div', { class: 'preview-srcset-field__text', hidden: '' }, [textarea, reasonLine]);

        const written = QSDom.el('code', { class: 'preview-srcset-field__written-line' });
        const writtenBox = QSDom.el('div', { class: 'preview-srcset-field__written' }, [
            QSDom.el('span', { class: 'preview-srcset-field__label', text: QSPanelWords.t('preview.srcsetWritten') }),
            written
        ]);
        const switchBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--outline preview-srcset-field__switch',
            text: QSPanelWords.t('preview.srcsetEditAsText') });

        root.append(kinds, list, addBtn, textBox, sizesBox, typeBox, writtenBox, switchBtn);

        // ── the rows ──────────────────────────────────────────────────────────
        /** A row's data: address, width, density, and media ({path, width, mime}) while its picture is Media's. */
        function newRow(data) {
            const d = data || {};
            const row = {
                address: String(d.address || ''),
                width: typeof d.width === 'number' ? d.width : null,
                density: typeof d.density === 'number' ? d.density : (kind === 'density' ? QSSrcset.nextDensity(rows) : 1),
                media: d.media || null,
                el: null
            };
            row.el = _renderRow(row);
            return row;
        }

        /** One row: its picture, its descriptor, its ×, and the line saying its problem. Returns ONE Element. */
        function _renderRow(row) {
            const address = QSDom.el('input', { type: 'text', class: 'admin-input admin-input--sm preview-srcset-field__address',
                placeholder: QSPanelWords.t('preview.srcsetAddressLabel'), 'aria-label': QSPanelWords.t('preview.srcsetAddressLabel'),
                spellcheck: 'false', autocomplete: 'off' });
            address.value = row.address;
            const browse = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--outline preview-asset-browse-btn',
                title: QSPanelWords.t('preview.browseAssets') }, ['📁']);
            // The row's small ×: the field writes nothing until the form is saved
            const remove = QSControls.removeRow(QSPanelWords.t('preview.srcsetRemovePicture'), null);
            const descriptor = QSDom.el('div', { class: 'preview-srcset-field__descriptor' });
            const problem = QSDom.el('small', { class: 'preview-srcset-field__problem', hidden: '' });
            const el = QSDom.el('div', { class: 'preview-srcset-field__row' }, [
                QSDom.el('div', { class: 'preview-srcset-field__picture' }, [address, browse]),
                descriptor,
                remove,
                problem
            ]);
            row.addressInput = address;
            row.descriptorBox = descriptor;
            row.problemLine = problem;
            _fillDescriptor(row);

            address.addEventListener('input', () => {
                row.address = address.value;
                // An address typed over a Media picture is the person's own: its width is theirs to give.
                if (row.media && row.media.path !== row.address) {
                    row.media = null;
                    _fillDescriptor(row);
                }
                update();
            });
            browse.addEventListener('click', () => {
                if (typeof o.pickPicture !== 'function') return;
                o.pickPicture(address, (asset) => {
                    const w = Number(asset && asset.width);
                    row.address = String((asset && asset.path) || address.value);
                    address.value = row.address;
                    row.media = { path: row.address, width: isFinite(w) && w > 0 && Math.floor(w) === w ? w : null, mime: (asset && asset.mime_type) || '' };
                    if (row.media.width !== null) row.width = row.media.width;
                    _fillDescriptor(row);
                    sortRows();
                    update();
                });
            });
            remove.addEventListener('click', () => {
                rows = rows.filter((r) => r !== row);
                el.remove();
                if (!rows.length) addRow();
                update();
            });
            return el;
        }

        /** The row's descriptor control for the field's kind: a width in pixels, or the density slider. */
        function _fillDescriptor(row) {
            row.descriptorBox.replaceChildren();
            if (kind === 'width') {
                const fromMedia = !!(row.media && row.media.width !== null);
                const input = QSDom.el('input', { type: 'number', min: '1', step: '1', inputmode: 'numeric',
                    class: 'admin-input admin-input--sm preview-srcset-field__width',
                    'aria-label': QSPanelWords.t('preview.srcsetWidthLabel'),
                    title: fromMedia ? QSPanelWords.t('preview.srcsetWidthFromMedia') : QSPanelWords.t('preview.srcsetWidthLabel') });
                if (fromMedia) input.readOnly = true;
                input.value = row.width === null ? '' : String(row.width);
                input.addEventListener('input', () => {
                    const v = input.value.trim();
                    row.width = /^[0-9]+$/.test(v) ? Number(v) : null;
                    update();
                });
                input.addEventListener('change', () => { sortRows(); update(); });
                row.descriptorBox.append(input, QSDom.el('span', { class: 'preview-srcset-field__unit', text: QSPanelWords.t('preview.srcsetWidthUnit') }));
            } else {
                const slider = QSDom.el('input', { type: 'range', min: String(QSSrcset.DENSITY_MIN), max: String(QSSrcset.DENSITY_MAX),
                    step: String(QSSrcset.DENSITY_STEP), class: 'preview-srcset-field__density',
                    'aria-label': QSPanelWords.t('preview.srcsetDensityLabel'), title: QSPanelWords.t('preview.srcsetDensityLabel') });
                slider.value = String(row.density);
                const shown = QSDom.el('output', { class: 'preview-srcset-field__density-value', text: QSSrcset.descriptorText('density', row) });
                slider.addEventListener('input', () => {
                    row.density = Number(slider.value);
                    shown.textContent = QSSrcset.descriptorText('density', row);
                    update();
                });
                slider.addEventListener('change', () => { sortRows(); update(); });
                row.descriptorBox.append(slider, shown);
            }
        }

        function addRow(data) {
            const row = newRow(data);
            rows.push(row);
            list.appendChild(row.el);
            return row;
        }

        /** The rows in their descriptor's order, on screen too; the focus kept where it was. */
        function sortRows() {
            const active = document.activeElement;
            rows = QSSrcset.sorted(kind, rows);
            rows.forEach((r) => list.appendChild(r.el));
            if (active && root.contains(active) && document.activeElement !== active && typeof active.focus === 'function') active.focus();
        }

        function setRows(data) {
            list.replaceChildren();
            rows = [];
            (data || []).forEach((d) => addRow(d));
            if (!rows.length) addRow();
            sortRows();
        }

        function rowData() {
            return rows.map((r) => ({ address: r.address, width: r.width, density: r.density }));
        }

        // ── what the field writes ─────────────────────────────────────────────
        /** The srcset as the field holds it: the value it opened with while nothing changed. */
        function srcsetValue() {
            if (mode === 'rows') {
                const line = QSSrcset.write(kind, rowData());
                return original.holdable && line === original.line ? original.value : line;
            }
            const text = textarea.value;
            if (asTextarea(text) === asTextarea(original.value)) return original.value;
            return blank(text) ? '' : text;
        }

        /** The kind the text holds: widths when any of its files has a width. */
        function textKind() {
            return QSSrcset.candidates(textarea.value).some((c) => QSSrcset.descriptorsOf(c.descriptors).width !== null) ? 'width' : 'density';
        }

        /** `sizes` as the field holds it: what the node held ('' none) until the person changes it. */
        function sizesValue() {
            if (!sizes.touched && sizes.original !== null) return sizes.original;
            const v = sizesSelect.value === SIZES_OTHER ? sizesText.value : sizesSelect.value;
            return v.trim() === '' ? '' : v;
        }

        /** `type` the same; while the pictures fill it, what they fill. */
        function typeValue() {
            if (!type.auto && !type.touched && type.original !== null) return type.original;
            return typeSelect.value;
        }

        function setSizes(value) {
            const choice = SIZES_CHOICES.find((c) => c.value === value);
            sizesSelect.value = choice ? choice.value : SIZES_OTHER;
            sizesText.value = choice ? '' : value;
            sizesText.hidden = sizesSelect.value !== SIZES_OTHER;
        }

        function setType(value) {
            if (value && !Array.from(typeSelect.options).some((op) => op.value === value)) typeSelect.appendChild(_renderOption(value, value));
            typeSelect.value = value;
        }

        /** The format the rows' pictures share ('' none known), and whether they differ. */
        function sharedFormat() {
            const formats = rows.filter((r) => r.address !== '').map((r) => QSSrcset.formatOf(r.address, r.media && r.media.mime));
            if (!formats.length) return { format: '', differ: false };
            const first = formats[0];
            const same = formats.every((f) => f === first);
            return { format: same ? first : '', differ: !same && formats.every((f) => f !== '') };
        }

        // ── keeping the screen in step ────────────────────────────────────────
        function update() {
            const value = srcsetValue();
            const rowsMode = mode === 'rows';
            list.hidden = !rowsMode;
            addBtn.hidden = !rowsMode;
            kinds.hidden = !rowsMode;
            textBox.hidden = rowsMode;
            writtenBox.hidden = !rowsMode;
            switchBtn.textContent = rowsMode ? QSPanelWords.t('preview.srcsetEditAsText') : QSPanelWords.t('preview.srcsetEditAsRows');

            // Each row's problem, said in the row.
            const check = QSSrcset.check(kind, rowData());
            rows.forEach((r, i) => {
                const code = check.rows[i];
                r.problemLine.textContent = code ? rowProblemText(code, QSSrcset.descriptorText(kind, r)) : '';
                r.problemLine.hidden = !code;
                r.addressInput.classList.toggle('admin-input--error', code === 'space' || code === 'comma');
                const descriptorInput = r.descriptorBox.querySelector('input');
                if (descriptorInput) descriptorInput.classList.toggle('admin-input--error', code === 'width' || code === 'duplicate');
            });
            written.textContent = value === '' ? QSPanelWords.t('preview.srcsetWrittenEmpty') : value;
            written.classList.toggle('preview-srcset-field__written-line--empty', value === '');

            // Text mode: why rows cannot hold the text, when they cannot.
            const reading = rowsMode ? null : QSSrcset.read(textarea.value);
            reasonLine.replaceChildren();
            if (reading && !reading.ok) reasonLine.append(QSPanelWords.t('preview.srcsetCannotHold'), ' ', reasonText(reading));
            reasonLine.hidden = !(reading && !reading.ok);

            // sizes: in widths mode, once the srcset holds a file. First shown: take a value another row of the form holds.
            const sizesNow = value !== '' && (rowsMode ? kind : textKind()) === 'width';
            if (sizesNow && !sizes.shown && sizes.original === null && typeof o.adopt === 'function') {
                const taken = o.adopt('sizes');
                if (taken !== null && taken !== undefined) { sizes.original = String(taken); setSizes(sizes.original); }
            }
            sizes.shown = sizesNow;
            sizesBox.hidden = !sizesNow;

            // type: on a <source> with a srcset; filled from the pictures while the person has not chosen.
            const typeNow = !!o.withType && value !== '';
            if (typeNow && !type.shown && type.original === null && typeof o.adopt === 'function') {
                const taken = o.adopt('type');
                if (taken !== null && taken !== undefined) { type.original = String(taken); type.auto = false; setType(type.original); }
            }
            type.shown = typeNow;
            typeBox.hidden = !typeNow;
            if (typeNow) {
                const shared = rowsMode ? sharedFormat() : { format: '', differ: false };
                // While the pictures fill it, it follows them: their format, or none once they differ or one is unknown.
                if (type.auto && rowsMode) setType(shared.format);
                let hint = '';
                if (!rowsMode || (!shared.format && !shared.differ)) hint = typeSelect.value ? '' : QSPanelWords.t('preview.srcsetTypeUnknown');
                else if (shared.differ) hint = QSPanelWords.t('preview.srcsetTypeDiffer');
                else if (type.auto || typeSelect.value === shared.format) hint = QSPanelWords.t('preview.srcsetTypeFilled');
                else hint = QSPanelWords.t('preview.srcsetTypeSeen', { format: shared.format });
                typeHint.textContent = hint;
                typeHint.hidden = hint === '';
            }
        }

        // ── the person's moves ────────────────────────────────────────────────
        kinds.addEventListener('change', (e) => {
            const next = e.target && e.target.value;
            if (next !== 'width' && next !== 'density' || next === kind) return;
            if (next === 'density') {
                const densities = QSSrcset.densitiesFor(rows.map((r) => (r.width !== null ? r.width : (r.media ? r.media.width : null))));
                rows.forEach((r, i) => { r.density = densities[i]; });
            } else {
                // Media's width where the picture is Media's; a width typed before the switch is kept.
                rows.forEach((r) => { if (r.media && r.media.width !== null) r.width = r.media.width; });
            }
            kind = next;
            rows.forEach((r) => _fillDescriptor(r));
            sortRows();
            update();
        });
        addBtn.addEventListener('click', () => {
            const row = addRow();
            update();
            row.addressInput.focus();
        });
        sizesSelect.addEventListener('change', () => {
            sizes.touched = true;
            sizesText.hidden = sizesSelect.value !== SIZES_OTHER;
            if (!sizesText.hidden) sizesText.focus();
            update();
        });
        sizesText.addEventListener('input', () => { sizes.touched = true; update(); });
        typeSelect.addEventListener('change', () => { type.auto = false; type.touched = true; update(); });
        textarea.addEventListener('input', update);
        switchBtn.addEventListener('click', () => {
            if (mode === 'rows') {
                textarea.value = srcsetValue();
                mode = 'text';
                update();
                textarea.focus();
                return;
            }
            const reading = QSSrcset.read(textarea.value);
            if (!reading.ok) { update(); return; }   // the line under the text says why
            // A row whose address was a Media picture keeps what Media said of it.
            const media = {};
            rows.forEach((r) => { if (r.media) media[r.address] = r.media; });
            if (reading.kind) kind = reading.kind;
            kinds.querySelectorAll('input').forEach((r) => { r.checked = r.value === kind; });
            setRows(reading.rows.map((d) => Object.assign({}, d, { media: media[d.address] || null })));
            mode = 'rows';
            update();
        });

        // ── what preview.js asks ──────────────────────────────────────────────
        /** Open on what the node holds (Edit Params): the srcset, and `sizes` / `type` where the field shows them. */
        function open(params) {
            const p = params || {};
            const value = p[param] == null ? '' : String(p[param]);
            const reading = QSSrcset.read(value);
            original = { value: value, holdable: reading.ok, line: '' };
            sizes.original = null;
            sizes.touched = false;
            type.original = null;
            type.touched = false;
            type.auto = true;
            const taken = [param];
            if (reading.ok) {
                mode = 'rows';
                if (reading.kind) kind = reading.kind;
                kinds.querySelectorAll('input').forEach((r) => { r.checked = r.value === kind; });
                setRows(reading.rows);
                original.line = QSSrcset.write(kind, rowData());
            } else {
                mode = 'text';
                textarea.value = value;
                setRows([]);
            }
            // sizes where the field shows it (a srcset of widths): the node's, or none ('') when it has none, which a
            // browser reads as the whole width, the control's default. With densities, or no srcset, the field neither
            // shows nor takes it: it stays the node's own row.
            const shownKind = mode === 'rows' ? kind : textKind();
            if (value !== '' && shownKind === 'width') {
                sizes.original = p.sizes != null ? String(p.sizes) : '';
                setSizes(sizes.original === '' ? SIZES_CHOICES[0].value : sizes.original);
                if (p.sizes != null) taken.push('sizes');
            }
            if (o.withType && value !== '') {
                type.original = p.type != null ? String(p.type) : '';
                setType(type.original);
                if (p.type != null) taken.push('type');
                // What the node holds is kept: the pictures fill it only where it already says their format.
                type.auto = mode === 'rows' && type.original !== '' && sharedFormat().format === type.original;
            }
            update();
            return taken;
        }

        /** The parameters the field writes: { srcset, sizes, type }, each only when it has a value and is shown. */
        function params() {
            const out = {};
            const value = srcsetValue();
            if (value !== '') out[param] = value;
            if (!sizesBox.hidden) {
                const s = sizesValue();
                if (s !== '') out.sizes = s;
            }
            if (!typeBox.hidden) {
                const t = typeValue();
                if (t !== '') out.type = t;
            }
            return out;
        }

        /**
         * What stops a Save: a row's problem, or a changed text a browser would read wrongly, said with the parameter's
         * name; null when nothing does.
         */
        function problem() {
            update();
            if (mode === 'rows') {
                const check = QSSrcset.check(kind, rowData());
                if (!check.first) return null;
                const row = rows[check.first.index];
                if (row && row.addressInput) row.addressInput.focus();
                return QSPanelWords.t('preview.srcsetProblem', { param: param, problem: rowProblemText(check.first.code, check.first.descriptor) });
            }
            if (asTextarea(textarea.value) === asTextarea(original.value)) return null;
            const reading = QSSrcset.read(textarea.value);
            if (reading.ok || !reading.fault) return null;
            textarea.focus();
            return QSPanelWords.t('preview.srcsetProblem', { param: param, problem: reasonText(reading) });
        }

        /** Empty and whole again (Save & add another): one empty row, the kind kept, `sizes` at its default. */
        function reset() {
            original = { value: '', holdable: true, line: '' };
            sizes.original = null;
            sizes.touched = false;
            sizes.shown = false;
            type.original = null;
            type.touched = false;
            type.shown = false;
            type.auto = true;
            mode = 'rows';
            textarea.value = '';
            setSizes(SIZES_CHOICES[0].value);
            setType('');
            setRows([]);
            update();
        }

        function destroy() {
            root.remove();
        }

        setSizes(SIZES_CHOICES[0].value);
        setRows([]);
        update();

        return { root: root, open: open, params: params, problem: problem, reset: reset, destroy: destroy };
    }

    return { create: create };
})();
