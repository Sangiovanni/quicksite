/**
 * Field Row wizard — kind 'field-row'.
 *
 * Emits one form field row (label + input + [data-error-for] span).
 * Building block for Form Scaffold. The wizard is intentionally small
 * — it's the simplest kind and the reference for the
 * renderWizard/getConfig/validate contract every kind follows.
 *
 * Server-side builder: secure/src/classes/complexElements/FieldRow.php
 */
(function () {
    'use strict';

    const INPUT_TYPES = [
        'text', 'email', 'tel', 'url', 'number', 'password', 'search',
        'date', 'time', 'datetime-local', 'month', 'week', 'color',
        'file', 'hidden', 'checkbox', 'radio', 'range',
        'submit', 'reset', 'button',
        'textarea'
    ];

    // Input types that take a `placeholder` attribute.
    const PLACEHOLDER_TYPES = new Set([
        'text', 'email', 'tel', 'url', 'number', 'password',
        'search', 'date', 'time', 'datetime-local', 'month',
        'week', 'textarea'
    ]);

    /**
     * Wizard renderer — populates `container` with the form, returns
     * a controller {getConfig, validate, destroy}.
     */
    function renderWizard(container) {
        const W = window.QSComplexWizard;
        const t = W.t;
        // ---- markup --------------------------------------------------
        // Translation-key fields are populated with the shared
        // QSComplexWizard.createTextKeyPicker (searchable + inline-create)
        // — same UX as the component-variables panel.
        const nameEl = QSDom.el('input', { class: 'admin-input', type: 'text', id: 'ce-fr-name', placeholder: t('fieldRow.namePlaceholder'), autocomplete: 'off' });
        const typeEl = QSDom.el('select', { class: 'admin-input', id: 'ce-fr-type' });
        const labelMount = QSDom.el('div', { id: 'ce-fr-label-mount' });
        const placeholderMount = QSDom.el('div', { id: 'ce-fr-placeholder-mount' });
        const placeholderGroup = QSDom.el('div', { class: 'admin-form-group', id: 'ce-fr-placeholder-group' }, [
            QSDom.el('label', { class: 'admin-label' }, [t('common.placeholderKey'), ' ', QSDom.el('small', { text: t('common.optional') })]),
            placeholderMount
        ]);
        const requiredEl = QSDom.el('input', { type: 'checkbox', id: 'ce-fr-required' });
        const idEl = QSDom.el('input', { class: 'admin-input', type: 'text', id: 'ce-fr-id', placeholder: t('common.idPlaceholder'), autocomplete: 'off' });

        const wrap = QSDom.el('div', { class: 'qs-complex-wizard qs-complex-wizard--field-row' }, [
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-fr-name' }, [t('common.fieldName'), ' ', W.requiredMark()]),
                nameEl,
                QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('common.nameHint'), { name: 'name' }))
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-fr-type', text: t('fieldRow.type') }),
                typeEl
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('common.labelKey'), ' ', W.requiredMark()]),
                labelMount,
                QSDom.el('p', { class: 'admin-hint', text: t('fieldRow.labelHint') })
            ]),
            placeholderGroup,
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-checkbox' }, [requiredEl, QSDom.el('span', { text: t('fieldRow.required') })])
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-fr-id' }, [QSDom.el('small', { text: t('common.idOverride') })]),
                idEl
            ])
        ]);
        container.appendChild(wrap);

        // Populate the type select.
        INPUT_TYPES.forEach(type => {
            const o = document.createElement('option');
            o.value = type; o.textContent = type;
            if (type === 'text') o.selected = true;
            typeEl.appendChild(o);
        });

        // ---- textKey pickers ----------------------------------------
        // Shared primitive — searchable list + inline "Create" form.
        const labelPicker = W.createTextKeyPicker({
            container: labelMount,
            placeholder: t('fieldRow.labelKeyPlaceholder'),
        });
        const placeholderPicker = W.createTextKeyPicker({
            container: placeholderMount,
            placeholder: t('fieldRow.placeholderKeyPlaceholder'),
        });

        // Hide placeholder row when type doesn't support one.
        function syncPlaceholderVisibility() {
            placeholderGroup.style.display = PLACEHOLDER_TYPES.has(typeEl.value) ? '' : 'none';
        }
        typeEl.addEventListener('change', syncPlaceholderVisibility);
        syncPlaceholderVisibility();

        // Auto-suggest labelKey from name (one-time, until user touches it).
        let userTouchedLabel = false;
        const _origLabelChange = labelPicker.setValue;
        // Wrap setValue to remember the user has touched it explicitly.
        labelPicker.setValue = function (v) {
            if (v && v !== '') userTouchedLabel = true;
            _origLabelChange(v);
        };
        nameEl.addEventListener('input', () => {
            if (!userTouchedLabel && nameEl.value) {
                const suggested = 'form.' + nameEl.value.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.label';
                _origLabelChange(suggested);  // bypass touch-mark
            }
        });

        // ---- controller ---------------------------------------------
        function getConfig() {
            const cfg = {
                name: nameEl.value.trim(),
                type: typeEl.value,
                labelKey: (labelPicker.getValue() || '').trim(),
                required: requiredEl.checked
            };
            const ph = (placeholderPicker.getValue() || '').trim();
            if (ph && PLACEHOLDER_TYPES.has(typeEl.value)) cfg.placeholder = ph;
            const idOverride = idEl.value.trim();
            if (idOverride) cfg.id = idOverride;
            return cfg;
        }

        function validate() {
            if (!nameEl.value.trim()) return t('common.errors.nameRequired');
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(nameEl.value.trim())) {
                return t('fieldRow.errors.nameFormat');
            }
            if (!(labelPicker.getValue() || '').trim()) return t('common.errors.labelRequired');
            if (idEl.value.trim() && !/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(idEl.value.trim())) {
                return t('fieldRow.errors.idFormat');
            }
            return null;
        }

        function destroy() {
            try { labelPicker.destroy(); } catch (e) {}
            try { placeholderPicker.destroy(); } catch (e) {}
            container.replaceChildren();
        }

        return { getConfig, validate, destroy };
    }

    // Register with the shared wizard hub.
    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['field-row'] = {
        label: window.QSComplexWizard.t('fieldRow.label'),
        description: window.QSComplexWizard.t('fieldRow.description'),
        renderWizard: renderWizard
    };
})();
