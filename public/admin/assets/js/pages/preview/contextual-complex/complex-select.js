/**
 * Select wizard — kind 'select'.
 *
 * Emits a <select> wrapped as a form field (same shape as Field Row):
 * <div class="field"><label /><select /><span data-error-for /></div>.
 * Sits naturally alongside text inputs inside a Form Scaffold and
 * picks up the same QS.validate hooks.
 *
 * MVP scope (per COMPLEX_ELEMENTS.txt with deferred items captured):
 *   - Flat option list (no optgroups — defer to a follow-up sprint).
 *   - Plain text option `value` (no raw-vs-textKey toggle).
 *   - Boolean required / multiple.
 *   - Optional first-option placeholder textKey.
 *
 * Server-side builder: secure/src/classes/complexElements/Select.php
 */
(function () {
    'use strict';

    function renderWizard(container) {
        const wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--select';
        wrap.innerHTML = [
            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-sel-name">Field name <span class="admin-text-danger">*</span></label>',
            '  <input class="admin-input" type="text" id="ce-sel-name" placeholder="e.g. country" autocomplete="off">',
            '  <p class="admin-hint">HTML <code>name</code> attribute. Letters, digits, hyphens, underscores; starts with a letter.</p>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-label">Label translation key <span class="admin-text-danger">*</span></label>',
            '  <div id="ce-sel-label-mount"></div>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-checkbox"><input type="checkbox" id="ce-sel-required"><span>Required</span></label>',
            '  <label class="admin-checkbox" style="margin-left:12px"><input type="checkbox" id="ce-sel-multiple"><span>Multiple (multi-select)</span></label>',
            '</div>',
            '<div class="admin-form-group" id="ce-sel-placeholder-group">',
            '  <label class="admin-label">Placeholder translation key <small>(optional — empty first option)</small></label>',
            '  <div id="ce-sel-placeholder-mount"></div>',
            '  <p class="admin-hint">When set, the first option is empty/disabled/selected and shows this translated text. Ignored if "Multiple" is checked (browser limitation).</p>',
            '</div>',

            '<hr style="margin:12px 0">',

            '<div class="admin-form-group">',
            '  <label class="admin-label">Options <span class="admin-text-danger">*</span></label>',
            '  <p class="admin-hint">Each option has a literal <code>value</code> (submitted on form post) and a translation key for the visible label.</p>',
            '  <div id="ce-sel-options-rows"></div>',
            '</div>',

            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-sel-id"><small>Override HTML id (optional)</small></label>',
            '  <input class="admin-input" type="text" id="ce-sel-id" placeholder="defaults to field name" autocomplete="off">',
            '</div>',
        ].join('');
        container.appendChild(wrap);

        const nameEl     = wrap.querySelector('#ce-sel-name');
        const requiredEl = wrap.querySelector('#ce-sel-required');
        const multipleEl = wrap.querySelector('#ce-sel-multiple');
        const placeholderGroup = wrap.querySelector('#ce-sel-placeholder-group');
        const optionsContainer = wrap.querySelector('#ce-sel-options-rows');
        const idEl       = wrap.querySelector('#ce-sel-id');

        // ---- textKey pickers (shared primitive) ----
        const labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: wrap.querySelector('#ce-sel-label-mount'),
            placeholder: 'e.g. form.contact.country',
        });
        const placeholderPicker = window.QSComplexWizard.createTextKeyPicker({
            container: wrap.querySelector('#ce-sel-placeholder-mount'),
            placeholder: 'e.g. form.contact.country.choose',
        });

        // Hide placeholder row when multi-select (browser ignores the
        // disabled+selected combo on <select multiple>).
        function syncPlaceholderVisibility() {
            placeholderGroup.style.display = multipleEl.checked ? 'none' : '';
        }
        multipleEl.addEventListener('change', syncPlaceholderVisibility);
        syncPlaceholderVisibility();

        // Auto-suggest label key from field name (until user touches it).
        let userTouchedLabel = false;
        const _origLabelSet = labelPicker.setValue;
        labelPicker.setValue = function (v) { if (v) userTouchedLabel = true; _origLabelSet(v); };
        nameEl.addEventListener('input', () => {
            if (!userTouchedLabel && nameEl.value) {
                _origLabelSet('form.' + nameEl.value.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.label');
            }
        });

        // ---- options list (WizardRowEditor) ----
        const optionPickers = new WeakMap();
        const optionsEditor = window.QSComplexWizard.createRowEditor({
            container: optionsContainer,
            addButtonLabel: '+ Add option',
            minRows: 1,
            initialRows: [
                { value: '', labelKey: '' },
                { value: '', labelKey: '' },
            ],
            renderRow: function (data) {
                const d = data || {};
                const row = document.createElement('div');
                row.className = 'qs-sel-option-row';
                row.innerHTML = [
                    '<div class="qs-sel-option-row__line">',
                    '  <input class="admin-input qs-sel-option-row__value" type="text" placeholder="value (literal, e.g. us)" autocomplete="off">',
                    '</div>',
                    '<div class="qs-sel-option-row__line">',
                    '  <span class="qs-sel-option-row__label-label">Label key:</span>',
                    '  <div class="qs-sel-option-row__label-mount"></div>',
                    '</div>',
                ].join('');

                if (d.value) row.querySelector('.qs-sel-option-row__value').value = d.value;

                const optLabelPicker = window.QSComplexWizard.createTextKeyPicker({
                    container: row.querySelector('.qs-sel-option-row__label-mount'),
                    placeholder: 'e.g. form.country.us',
                    value: d.labelKey || '',
                });
                optionPickers.set(row, optLabelPicker);

                // Auto-suggest option label from value when both fresh.
                let touched = !!d.labelKey;
                const _origOpt = optLabelPicker.setValue;
                optLabelPicker.setValue = function (v) { if (v) touched = true; _origOpt(v); };
                const valEl = row.querySelector('.qs-sel-option-row__value');
                valEl.addEventListener('input', function () {
                    if (!touched && valEl.value) {
                        const fname = nameEl.value.trim() || 'select';
                        _origOpt('form.' +
                            fname.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.' +
                            valEl.value.toLowerCase().replace(/[^a-z0-9]/g, '-'));
                    }
                });

                return row;
            },
            readRow: function (contentEl) {
                const optLabelPicker = optionPickers.get(contentEl);
                return {
                    value: contentEl.querySelector('.qs-sel-option-row__value').value,  // empty value is allowed
                    labelKey: optLabelPicker ? (optLabelPicker.getValue() || '').trim() : ''
                };
            }
        });

        // ---- controller ----
        function getConfig() {
            const cfg = {
                name: nameEl.value.trim(),
                labelKey: (labelPicker.getValue() || '').trim(),
                required: requiredEl.checked,
                multiple: multipleEl.checked,
                options: optionsEditor.getRows().filter(o => o.labelKey)  // drop empty-label rows
            };
            const ph = (placeholderPicker.getValue() || '').trim();
            if (ph && !cfg.multiple) cfg.placeholderKey = ph;
            const idOverride = idEl.value.trim();
            if (idOverride) cfg.id = idOverride;
            return cfg;
        }

        function validate() {
            const cfg = getConfig();
            if (!cfg.name) return 'Field name is required';
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.name)) {
                return 'Field name: invalid format';
            }
            if (!cfg.labelKey) return 'Label translation key is required';
            if (cfg.options.length === 0) return 'Add at least one option with a label key';
            if (cfg.id && !/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.id)) {
                return 'HTML id: invalid format';
            }
            // Duplicate values check (server enforces too — fail-fast in UI).
            const seen = new Set();
            for (const o of cfg.options) {
                if (seen.has(o.value)) return 'Duplicate option value: "' + o.value + '"';
                seen.add(o.value);
            }
            return null;
        }

        function destroy() {
            try { labelPicker.destroy(); } catch (e) {}
            try { placeholderPicker.destroy(); } catch (e) {}
            try { optionsEditor.destroy(); } catch (e) {}
            container.innerHTML = '';
        }

        return { getConfig, validate, destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['select'] = {
        label: 'Select (dropdown)',
        description: '<select> with options, wrapped as a form field (label + select + error span).',
        renderWizard: renderWizard
    };
})();
