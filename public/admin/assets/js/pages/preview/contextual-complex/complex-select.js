/**
 * Select wizard — kind 'select'.
 *
 * Emits a <select> wrapped as a form field (same shape as Field Row):
 * <div class="field"><label /><select /><span data-error-for /></div>.
 * Sits naturally alongside text inputs inside a Form Scaffold and
 * picks up the same QS.validate hooks.
 *
 * Scope:
 *   - Flat option list (no optgroups).
 *   - Plain text option `value` (no raw-vs-textKey toggle).
 *   - Boolean required / multiple.
 *   - Optional first-option placeholder textKey.
 *
 * Server-side builder: secure/src/classes/complexElements/Select.php
 */
(function () {
    'use strict';

    function renderWizard(container) {
        const W = window.QSComplexWizard;
        const t = W.t;
        const nameEl     = QSDom.el('input', { class: 'admin-input', type: 'text', id: 'ce-sel-name', placeholder: t('select.namePlaceholder'), autocomplete: 'off' });
        const requiredEl = QSDom.el('input', { type: 'checkbox', id: 'ce-sel-required' });
        const multipleEl = QSDom.el('input', { type: 'checkbox', id: 'ce-sel-multiple' });
        const labelMount = QSDom.el('div', { id: 'ce-sel-label-mount' });
        const placeholderMount = QSDom.el('div', { id: 'ce-sel-placeholder-mount' });
        const placeholderGroup = QSDom.el('div', { class: 'admin-form-group', id: 'ce-sel-placeholder-group' }, [
            QSDom.el('label', { class: 'admin-label' }, [t('common.placeholderKey'), ' ', QSDom.el('small', { text: t('select.placeholderOptional') })]),
            placeholderMount,
            QSDom.el('p', { class: 'admin-hint', text: t('select.placeholderHint') })
        ]);
        const optionsContainer = QSDom.el('div', { id: 'ce-sel-options-rows' });
        const idEl       = QSDom.el('input', { class: 'admin-input', type: 'text', id: 'ce-sel-id', placeholder: t('common.idPlaceholder'), autocomplete: 'off' });

        const wrap = QSDom.el('div', {}, [
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-sel-name' }, [t('common.fieldName'), ' ', W.requiredMark()]),
                nameEl,
                QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('common.nameHint'), { name: 'name' }))
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('common.labelKey'), ' ', W.requiredMark()]),
                labelMount
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-checkbox' }, [requiredEl, QSDom.el('span', { text: t('select.required') })]),
                ' ',
                QSDom.el('label', { class: 'admin-checkbox', style: 'margin-left:12px' }, [multipleEl, QSDom.el('span', { text: t('select.multiple') })])
            ]),
            placeholderGroup,
            QSDom.el('hr', { style: 'margin:12px 0' }),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('select.options'), ' ', W.requiredMark()]),
                QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('select.optionsHint'), { value: 'value' })),
                optionsContainer
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-sel-id' }, [QSDom.el('small', { text: t('common.idOverride') })]),
                idEl
            ])
        ]);
        container.appendChild(wrap);

        // ---- textKey pickers (shared primitive) ----
        const labelPicker = W.createTextKeyPicker({
            container: labelMount,
            placeholder: t('select.labelKeyPlaceholder'),
        });
        const placeholderPicker = W.createTextKeyPicker({
            container: placeholderMount,
            placeholder: t('select.placeholderKeyPlaceholder'),
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
        const optionsEditor = W.createRowEditor({
            container: optionsContainer,
            addButtonLabel: t('select.addOption'),
            minRows: 1,
            initialRows: [
                { value: '', labelKey: '' },
                { value: '', labelKey: '' },
            ],
            renderRow: function (data) {
                const d = data || {};
                const valEl = QSDom.el('input', { class: 'admin-input qs-sel-option-row__value', type: 'text', placeholder: t('select.valuePlaceholder'), autocomplete: 'off' });
                const labelMountEl = QSDom.el('div', { class: 'qs-sel-option-row__label-mount' });
                const row = QSDom.el('div', {}, [
                    QSDom.el('div', { class: 'qs-sel-option-row__line' }, [valEl]),
                    QSDom.el('div', { class: 'qs-sel-option-row__line' }, [
                        QSDom.el('span', { class: 'qs-sel-option-row__label-label', text: t('common.rowLabelKey') }),
                        labelMountEl
                    ])
                ]);

                if (d.value) valEl.value = d.value;

                const optLabelPicker = W.createTextKeyPicker({
                    container: labelMountEl,
                    placeholder: t('select.optionKeyPlaceholder'),
                    value: d.labelKey || '',
                });
                optionPickers.set(row, optLabelPicker);

                // Auto-suggest option label from value when both fresh.
                let touched = !!d.labelKey;
                const _origOpt = optLabelPicker.setValue;
                optLabelPicker.setValue = function (v) { if (v) touched = true; _origOpt(v); };
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
            if (!cfg.name) return t('common.errors.nameRequired');
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.name)) {
                return t('select.errors.nameFormat');
            }
            if (!cfg.labelKey) return t('common.errors.labelRequired');
            if (cfg.options.length === 0) return t('select.errors.noOption');
            if (cfg.id && !/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.id)) {
                return t('select.errors.idFormat');
            }
            // Duplicate values check (server enforces too — fail-fast in UI).
            const seen = new Set();
            for (const o of cfg.options) {
                if (seen.has(o.value)) return t('select.errors.duplicate', { value: o.value });
                seen.add(o.value);
            }
            return null;
        }

        function destroy() {
            try { labelPicker.destroy(); } catch (e) {}
            try { placeholderPicker.destroy(); } catch (e) {}
            try { optionsEditor.destroy(); } catch (e) {}
            container.replaceChildren();
        }

        return { getConfig, validate, destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['select'] = {
        label: window.QSComplexWizard.t('select.label'),
        description: window.QSComplexWizard.t('select.description'),
        renderWizard: renderWizard
    };
})();
