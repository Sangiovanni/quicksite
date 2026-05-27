/**
 * Checkbox group wizard — kind 'checkbox-group'.
 *
 * Mirror of complex-radio-group with three deltas:
 *   1. type="checkbox" instead of "radio" (handled server-side).
 *   2. Optional "Submit as array (name[])" toggle.
 *   3. Default selection is MULTI (a <select multiple>) instead of single.
 *
 * Server-side builder: secure/src/classes/complexElements/CheckboxGroup.php
 */
(function () {
    'use strict';

    // ---- small render helpers (copied locally — each wizard is an
    // independent module per the framework, so no cross-wizard helper
    // imports to keep the load order simple) ------------------------------

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

    function _renderArraySubmitCheckbox(id) {
        var l = document.createElement('label');
        l.className = 'admin-checkbox';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = id;
        var s = document.createElement('span');
        s.textContent = 'Submit as array (name="<name>[]") — for PHP-style multi-value submission';
        l.appendChild(cb);
        l.appendChild(s);
        return l;
    }

    function _renderHr() {
        var hr = document.createElement('hr');
        hr.style.margin = '12px 0';
        return hr;
    }

    function _renderOptionRow(data, labelPickers) {
        var d = data || {};
        var row = document.createElement('div');
        row.className = 'qs-cb-option-row';
        row.style.display = 'flex';
        row.style.gap = '8px';
        row.style.alignItems = 'flex-start';

        var valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'admin-input qs-cb-option-row__value';
        valueInput.placeholder = 'value (e.g. newsletter)';
        valueInput.autocomplete = 'off';
        valueInput.style.flex = '0 0 33%';
        if (d.value !== undefined) valueInput.value = d.value;

        var labelMount = document.createElement('div');
        labelMount.className = 'qs-cb-option-row__label-mount';
        labelMount.style.flex = '1';
        labelMount.style.minWidth = '0';

        row.appendChild(valueInput);
        row.appendChild(labelMount);

        var picker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: 'e.g. form.prefs.newsletter',
            value: d.labelKey || '',
        });
        labelPickers.set(row, picker);
        return row;
    }

    // ---- main wizard ------------------------------------------------------

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--checkbox-group';

        // -- name --
        var nameInput = _renderTextInput('ce-cb-name', 'e.g. prefs (letters/digits/_/- only)');
        var nameGroup = _renderGroup();
        nameGroup.appendChild(_renderLabel('Name (shared by every checkbox)', true));
        nameGroup.appendChild(nameInput);
        wrap.appendChild(nameGroup);

        // -- array-submit toggle --
        var arraySubmitLabel = _renderArraySubmitCheckbox('ce-cb-array-submit');
        var arraySubmitGroup = _renderGroup(arraySubmitLabel);
        wrap.appendChild(arraySubmitGroup);

        // -- legendKey --
        var legendGroup = _renderGroup();
        legendGroup.appendChild(_renderLabel('Legend (translation key)', true));
        var legendMount = document.createElement('div');
        legendGroup.appendChild(legendMount);
        wrap.appendChild(legendGroup);
        var legendPicker = window.QSComplexWizard.createTextKeyPicker({
            container: legendMount,
            placeholder: 'e.g. form.prefs.legend',
            value: '',
        });

        // -- layout --
        var layoutGroup = _renderGroup();
        layoutGroup.appendChild(_renderLabel('Layout'));
        layoutGroup.appendChild(_renderLayoutRadios('ce-cb-layout'));
        wrap.appendChild(layoutGroup);

        wrap.appendChild(_renderHr());

        // -- options list --
        var optsGroup = _renderGroup();
        optsGroup.appendChild(_renderLabel('Options', true));
        optsGroup.appendChild(_renderHint('Each option has a value (the literal submitted on form submit) and a label (translation key).'));
        var optsHost = document.createElement('div');
        optsGroup.appendChild(optsHost);
        wrap.appendChild(optsGroup);

        // -- default checked values --
        var defaultGroup = _renderGroup();
        defaultGroup.appendChild(_renderLabel('Default checked (optional)'));
        var defaultSelect = document.createElement('select');
        defaultSelect.className = 'admin-input';
        defaultSelect.id = 'ce-cb-defaults';
        defaultSelect.multiple = true;
        defaultSelect.size = 4;
        defaultGroup.appendChild(defaultSelect);
        defaultGroup.appendChild(_renderHint('Ctrl/Cmd-click to select multiple. Pre-checked checkboxes use these values.'));
        wrap.appendChild(defaultGroup);

        container.appendChild(wrap);

        var layoutRadios = wrap.querySelectorAll('input[name="ce-cb-layout"]');
        var arraySubmitInput = wrap.querySelector('#ce-cb-array-submit');

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
                var v = contentEl.querySelector('.qs-cb-option-row__value');
                return {
                    value: v ? v.value : '',
                    labelKey: picker ? (picker.getValue() || '').trim() : '',
                };
            },
            onChange: function () { syncDefaultDropdown(); }
        });

        // Sync the default-values <select multiple> from the current options.
        // Preserves any prior selections that are still valid.
        function syncDefaultDropdown() {
            var rows = optsEditor.getRows();
            var prevSelected = {};
            Array.from(defaultSelect.selectedOptions).forEach(function (o) { prevSelected[o.value] = true; });
            defaultSelect.textContent = '';
            rows.forEach(function (r) {
                if (!r.value && r.value !== 0) return;
                var o = document.createElement('option');
                o.value = String(r.value);
                o.textContent = String(r.value);
                if (prevSelected[o.value]) o.selected = true;
                defaultSelect.appendChild(o);
            });
        }

        optsHost.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('qs-cb-option-row__value')) {
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
                .filter(function (r) { return r.value !== '' || r.labelKey !== ''; });
            var defaults = Array.from(defaultSelect.selectedOptions).map(function (o) { return o.value; });
            var cfg = {
                name: nameInput.value.trim(),
                legendKey: (legendPicker.getValue() || '').trim(),
                layout: currentLayout(),
                arraySubmit: !!(arraySubmitInput && arraySubmitInput.checked),
                options: rows
            };
            if (defaults.length > 0) cfg.defaultValues = defaults;
            return cfg;
        }

        function validate() {
            var cfg = getConfig();
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.name)) {
                return 'Name must start with a letter and use only letters, digits, hyphens, underscores.';
            }
            if (!cfg.legendKey) return 'Pick a legend translation key.';
            if (cfg.options.length === 0) return 'Add at least one option.';
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
    window.QSComplexWizard.registry['checkbox-group'] = {
        label: 'Checkbox group',
        description: 'A &lt;fieldset&gt; with N &lt;input type=checkbox&gt; sharing the same name, plus a per-group error span. Optional name[] suffix for PHP-array submission.',
        renderWizard: renderWizard
    };
})();
