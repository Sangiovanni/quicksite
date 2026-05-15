/**
 * Field Row wizard — first real Complex Element kind.
 *
 * Emits one form field row (label + input + [data-error-for] span).
 * Building block for Form Scaffold. The wizard is intentionally small
 * — it's the simplest kind and serves as the reference template for
 * every later kind's renderWizard/getConfig/validate contract.
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
        // ---- markup --------------------------------------------------
        // Translation-key fields are populated with the shared
        // QSComplexWizard.createTextKeyPicker (searchable + inline-create)
        // — same UX as the component-variables panel.
        const wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--field-row';
        wrap.innerHTML = [
            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-fr-name">Field name <span class="admin-text-danger">*</span></label>',
            '  <input class="admin-input" type="text" id="ce-fr-name" placeholder="e.g. email" autocomplete="off">',
            '  <p class="admin-hint">HTML <code>name</code> attribute. Letters, digits, hyphens, underscores; starts with a letter.</p>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-fr-type">Type</label>',
            '  <select class="admin-input" id="ce-fr-type"></select>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-label">Label translation key <span class="admin-text-danger">*</span></label>',
            '  <div id="ce-fr-label-mount"></div>',
            '  <p class="admin-hint">Pick an existing key or type a new one to create it on save.</p>',
            '</div>',
            '<div class="admin-form-group" id="ce-fr-placeholder-group">',
            '  <label class="admin-label">Placeholder translation key <small>(optional)</small></label>',
            '  <div id="ce-fr-placeholder-mount"></div>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-checkbox">',
            '    <input type="checkbox" id="ce-fr-required">',
            '    <span>Required field</span>',
            '  </label>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-fr-id"><small>Override HTML id (optional)</small></label>',
            '  <input class="admin-input" type="text" id="ce-fr-id" placeholder="defaults to field name" autocomplete="off">',
            '</div>'
        ].join('');
        container.appendChild(wrap);

        // Populate the type select.
        const typeEl = wrap.querySelector('#ce-fr-type');
        INPUT_TYPES.forEach(t => {
            const o = document.createElement('option');
            o.value = t; o.textContent = t;
            if (t === 'text') o.selected = true;
            typeEl.appendChild(o);
        });

        const nameEl           = wrap.querySelector('#ce-fr-name');
        const placeholderGroup = wrap.querySelector('#ce-fr-placeholder-group');
        const requiredEl       = wrap.querySelector('#ce-fr-required');
        const idEl             = wrap.querySelector('#ce-fr-id');

        // ---- textKey pickers ----------------------------------------
        // Shared primitive — searchable list + inline "Create" form.
        const labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: wrap.querySelector('#ce-fr-label-mount'),
            placeholder: 'e.g. form.contact.email.label',
        });
        const placeholderPicker = window.QSComplexWizard.createTextKeyPicker({
            container: wrap.querySelector('#ce-fr-placeholder-mount'),
            placeholder: 'e.g. form.contact.email.placeholder',
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
            if (!nameEl.value.trim()) return 'Field name is required';
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(nameEl.value.trim())) {
                return 'Field name must start with a letter and contain only letters, digits, hyphens, underscores';
            }
            if (!(labelPicker.getValue() || '').trim()) return 'Label translation key is required';
            if (idEl.value.trim() && !/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(idEl.value.trim())) {
                return 'HTML id must start with a letter and contain only letters, digits, hyphens, underscores';
            }
            return null;
        }

        function destroy() {
            try { labelPicker.destroy(); } catch (e) {}
            try { placeholderPicker.destroy(); } catch (e) {}
            container.innerHTML = '';
        }

        return { getConfig, validate, destroy };
    }

    // Register with the shared wizard hub.
    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['field-row'] = {
        label: 'Field row',
        description: 'A single form field (label + input + error span), ready for QS.validate.',
        renderWizard: renderWizard
    };
})();
