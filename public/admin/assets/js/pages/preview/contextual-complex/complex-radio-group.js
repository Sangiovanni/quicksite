/**
 * Radio group wizard — kind 'radio-group'.
 *
 * Emits a <fieldset> wrapping a <legend> + N (label + radio input) pairs
 * + a <span data-error-for=...> matching the group's name. Same outer
 * "field" class as FieldRow / Select so it slots into a Form Scaffold
 * and reuses the QS.validate per-field error hook.
 *
 * Form fields:
 *  - name (HTML attribute shared by all radios + the data-error-for key)
 *  - legendKey (textKey picker — the group's legend)
 *  - layout (inline | stacked) — adds 'field--inline' class when inline
 *  - default selected value (a <select> rebuilt from the live options)
 *  - options list (N rows of {value, labelKey} via QSComplexWizard.createRowEditor)
 *
 * Per CLAUDE.md HTML-in-JS hygiene: this file uses createElement +
 * textContent for the static form structure (no `innerHTML = '<...>'`
 * strings). The dynamic parts already use createRowEditor +
 * createTextKeyPicker, which return Elements internally.
 *
 * Server-side builder: secure/src/classes/complexElements/RadioGroup.php
 */
(function () {
    'use strict';

    // ---- small render helpers --------------------------------------------
    // Each helper returns ONE Element (per CLAUDE.md). Keeps the main
    // renderWizard body short + each helper independently inspectable.

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
        // Single-child wrapper that matches the existing wizards' spacing.
        var g = document.createElement('div');
        g.className = 'admin-form-group';
        if (child) g.appendChild(child);
        return g;
    }

    function _renderTextInput(id, placeholder) {
        var i = document.createElement('input');
        i.type = 'text';
        i.className = 'admin-input';
        i.id = id;
        if (placeholder) i.placeholder = placeholder;
        i.autocomplete = 'off';
        return i;
    }

    function _renderLayoutRadios(name) {
        // Two-radio inline group: stacked (default) | inline.
        var wrap = document.createElement('div');

        var l1 = document.createElement('label');
        l1.className = 'admin-checkbox';
        l1.style.marginRight = '14px';
        var r1 = document.createElement('input');
        r1.type = 'radio';
        r1.name = name;
        r1.value = 'stacked';
        r1.checked = true;
        var s1 = document.createElement('span');
        s1.textContent = 'Stacked (one per line)';
        l1.appendChild(r1);
        l1.appendChild(s1);

        var l2 = document.createElement('label');
        l2.className = 'admin-checkbox';
        var r2 = document.createElement('input');
        r2.type = 'radio';
        r2.name = name;
        r2.value = 'inline';
        var s2 = document.createElement('span');
        s2.textContent = 'Inline (side-by-side)';
        l2.appendChild(r2);
        l2.appendChild(s2);

        wrap.appendChild(l1);
        wrap.appendChild(l2);
        return wrap;
    }

    function _renderHr() {
        var hr = document.createElement('hr');
        hr.style.margin = '12px 0';
        return hr;
    }

    function _renderOptionRow(data, labelPickers) {
        // One row of the options editor: value <input> + labelKey picker.
        var d = data || {};
        var row = document.createElement('div');
        row.className = 'qs-radio-option-row';
        row.style.display = 'flex';
        row.style.gap = '8px';
        row.style.alignItems = 'flex-start';

        var valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'admin-input qs-radio-option-row__value';
        valueInput.placeholder = 'value (e.g. yes)';
        valueInput.autocomplete = 'off';
        valueInput.style.flex = '0 0 33%';
        if (d.value !== undefined) valueInput.value = d.value;

        var labelMount = document.createElement('div');
        labelMount.className = 'qs-radio-option-row__label-mount';
        labelMount.style.flex = '1';
        labelMount.style.minWidth = '0';

        row.appendChild(valueInput);
        row.appendChild(labelMount);

        // The label picker mounts on the wrapper; we stash the picker
        // on a WeakMap so readRow can extract its current value later.
        var picker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: 'e.g. form.consent.yes',
            value: d.labelKey || '',
        });
        labelPickers.set(row, picker);
        return row;
    }

    // ---- main wizard ------------------------------------------------------

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--radio-group';

        // -- name --
        var nameInput = _renderTextInput('ce-rg-name', 'e.g. consent (letters/digits/_/- only)');
        var nameGroup = _renderGroup();
        nameGroup.appendChild(_renderLabel('Name (shared by every radio)', true));
        nameGroup.appendChild(nameInput);
        wrap.appendChild(nameGroup);

        // -- legendKey (textKey picker) --
        var legendGroup = _renderGroup();
        legendGroup.appendChild(_renderLabel('Legend (translation key)', true));
        var legendMount = document.createElement('div');
        legendGroup.appendChild(legendMount);
        wrap.appendChild(legendGroup);
        var legendPicker = window.QSComplexWizard.createTextKeyPicker({
            container: legendMount,
            placeholder: 'e.g. form.consent.legend',
            value: '',
        });

        // -- layout --
        var layoutGroup = _renderGroup();
        layoutGroup.appendChild(_renderLabel('Layout'));
        layoutGroup.appendChild(_renderLayoutRadios('ce-rg-layout'));
        wrap.appendChild(layoutGroup);

        wrap.appendChild(_renderHr());

        // -- options list --
        var optsGroup = _renderGroup();
        optsGroup.appendChild(_renderLabel('Options', true));
        optsGroup.appendChild(_renderHint('Each option has a value (the literal submitted on form submit) and a label (translation key).'));
        var optsHost = document.createElement('div');
        optsGroup.appendChild(optsHost);
        wrap.appendChild(optsGroup);

        // -- default selected value --
        // Built as a <select> populated from the LIVE option values so the
        // user can only pick a default that actually exists. Rebuilt on
        // every options change via onChange below.
        var defaultGroup = _renderGroup();
        defaultGroup.appendChild(_renderLabel('Default selected (optional)'));
        var defaultSelect = document.createElement('select');
        defaultSelect.className = 'admin-input';
        defaultSelect.id = 'ce-rg-default';
        defaultGroup.appendChild(defaultSelect);
        defaultGroup.appendChild(_renderHint('Pick one of the option values to mark `checked`, or "(none)" to leave the group unchecked.'));
        wrap.appendChild(defaultGroup);

        container.appendChild(wrap);

        // Resolve refs created above.
        var layoutRadios = wrap.querySelectorAll('input[name="ce-rg-layout"]');

        // ---- options row editor ------------------------------------------
        var labelPickers = new WeakMap();
        var optsEditor = window.QSComplexWizard.createRowEditor({
            container: optsHost,
            addButtonLabel: '+ Add option',
            minRows: 1,
            initialRows: [
                { value: '', labelKey: '' },
                { value: '', labelKey: '' },
            ],
            renderRow: function (data) {
                return _renderOptionRow(data, labelPickers);
            },
            readRow: function (contentEl) {
                var picker = labelPickers.get(contentEl);
                var v = contentEl.querySelector('.qs-radio-option-row__value');
                return {
                    value: v ? v.value : '',
                    labelKey: picker ? (picker.getValue() || '').trim() : '',
                };
            },
            onChange: function () { syncDefaultDropdown(); }
        });

        // Sync the default-value <select> from the current options. Called
        // on add/remove/reorder AND whenever a value input changes.
        function syncDefaultDropdown() {
            var rows = optsEditor.getRows();
            var prev = defaultSelect.value;
            defaultSelect.textContent = '';

            var noneOpt = document.createElement('option');
            noneOpt.value = '';
            noneOpt.textContent = '(none — no radio checked at first)';
            defaultSelect.appendChild(noneOpt);

            rows.forEach(function (r) {
                if (!r.value && r.value !== 0) return;
                var o = document.createElement('option');
                o.value = String(r.value);
                o.textContent = String(r.value);
                defaultSelect.appendChild(o);
            });

            // Restore the prior selection if it's still valid.
            var stillValid = Array.from(defaultSelect.options).some(function (o) { return o.value === prev; });
            defaultSelect.value = stillValid ? prev : '';
        }

        // Catch value-input edits (the row editor's onChange fires for
        // add/remove/reorder, NOT for typing). Delegate so we don't have
        // to attach listeners per row.
        optsHost.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('qs-radio-option-row__value')) {
                syncDefaultDropdown();
            }
        });
        syncDefaultDropdown();

        // ---- controller --------------------------------------------------
        function currentLayout() {
            for (var i = 0; i < layoutRadios.length; i++) {
                if (layoutRadios[i].checked) return layoutRadios[i].value;
            }
            return 'stacked';
        }

        function getConfig() {
            var rows = optsEditor.getRows()
                .map(function (r) { return { value: String(r.value || '').trim(), labelKey: (r.labelKey || '').trim() }; })
                .filter(function (r) { return r.value !== '' || r.labelKey !== ''; }); // drop fully-empty rows
            var cfg = {
                name: nameInput.value.trim(),
                legendKey: (legendPicker.getValue() || '').trim(),
                layout: currentLayout(),
                options: rows
            };
            var dv = defaultSelect.value;
            if (dv !== '') cfg.defaultValue = dv;
            return cfg;
        }

        function validate() {
            var cfg = getConfig();
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.name)) {
                return 'Name must start with a letter and use only letters, digits, hyphens, underscores.';
            }
            if (!cfg.legendKey) return 'Pick a legend translation key.';
            if (cfg.options.length === 0) return 'Add at least one option.';
            // Per-row validation (caught server-side too, but cheaper here).
            var seen = {};
            for (var i = 0; i < cfg.options.length; i++) {
                var o = cfg.options[i];
                if (o.value === '') return 'Option #' + (i + 1) + ' is missing a value.';
                if (!o.labelKey) return 'Option #' + (i + 1) + ' is missing a label key.';
                if (Object.prototype.hasOwnProperty.call(seen, o.value)) {
                    return 'Duplicate option value "' + o.value + '" — values must be unique.';
                }
                seen[o.value] = true;
            }
            return null;
        }

        function destroy() {
            try { optsEditor.destroy(); } catch (e) {}
            try { legendPicker.destroy(); } catch (e) {}
            container.textContent = '';
        }

        return { getConfig: getConfig, validate: validate, destroy: destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['radio-group'] = {
        label: 'Radio group',
        description: 'A &lt;fieldset&gt; with N &lt;input type=radio&gt; sharing the same name, plus a per-group error span.',
        renderWizard: renderWizard
    };
})();
