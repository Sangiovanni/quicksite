/**
 * Form Scaffold wizard — kind 'form-scaffold'.
 *
 * The headline kind. Builds a full <form id="..."> with N field rows
 * and a submit button, with `validate` + `fetch` pre-wired on
 * onsubmit (the chain-async transformer handles ordering).
 *
 * Reuses:
 *   - QSComplexWizard.createRowEditor  → manages the fields list.
 *   - QSComplexWizard.createTextKeyPicker → label fields + submit label.
 *
 * Server-side builder: secure/src/classes/complexElements/FormScaffold.php
 */
(function () {
    'use strict';

    const INPUT_TYPES = [
        'text', 'email', 'tel', 'url', 'number', 'password', 'search',
        'date', 'time', 'datetime-local', 'month', 'week', 'color',
        'file', 'hidden', 'checkbox', 'radio', 'range',
        'textarea'
    ];

    // ----- API endpoint cache (shared across instances) -------------------
    let _apisCache = null;
    let _apisPromise = null;

    function loadApisOnce() {
        if (_apisCache !== null) return Promise.resolve(_apisCache);
        if (_apisPromise) return _apisPromise;
        const adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            _apisCache = [];
            return Promise.resolve(_apisCache);
        }
        _apisPromise = (async function () {
            try {
                const r = await adminApi.apiRequest('listApiEndpoints', 'GET');
                const payload = (r && r.data && (r.data.data || r.data)) || {};
                // Response shape: { apis: [{ apiId, name, endpoints: [{id, method, name, path}, ...] }, ...] }
                _apisCache = Array.isArray(payload.apis) ? payload.apis : [];
            } catch (err) {
                console.warn('[FormScaffold] failed to load APIs:', err);
                _apisCache = [];
            } finally {
                _apisPromise = null;
            }
            return _apisCache;
        })();
        return _apisPromise;
    }

    // ---------------------------------------------------------------------

    function renderWizard(container) {
        // ===== top-level form (id / method / submit mode) ================
        const wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--form-scaffold';
        wrap.innerHTML = [
            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-fs-id">Form id <span class="admin-text-danger">*</span></label>',
            '  <input class="admin-input" type="text" id="ce-fs-id" placeholder="e.g. contact-form" autocomplete="off">',
            '  <p class="admin-hint">Used for the HTML <code>id</code> AND as the validation/fetch target selector.</p>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-label" for="ce-fs-method">HTML method</label>',
            '  <select class="admin-input" id="ce-fs-method"><option value="POST">POST</option><option value="GET">GET</option></select>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-label">Submit to</label>',
            '  <div class="qs-radio-row">',
            '    <label><input type="radio" name="ce-fs-mode" value="api" checked> API endpoint</label>',
            '    <label><input type="radio" name="ce-fs-mode" value="url"> Direct URL</label>',
            '    <label><input type="radio" name="ce-fs-mode" value="none"> Nothing yet (wire later)</label>',
            '  </div>',
            '</div>',
            '<div class="admin-form-group" id="ce-fs-api-group">',
            '  <label class="admin-label" for="ce-fs-api">API</label>',
            '  <select class="admin-input" id="ce-fs-api"><option value="">— pick an API —</option></select>',
            '  <label class="admin-label" for="ce-fs-endpoint" style="margin-top:6px">Endpoint</label>',
            '  <select class="admin-input" id="ce-fs-endpoint"><option value="">— pick an endpoint —</option></select>',
            '</div>',
            '<div class="admin-form-group" id="ce-fs-url-group" style="display:none;">',
            '  <label class="admin-label" for="ce-fs-url">Submit URL</label>',
            '  <input class="admin-input" type="text" id="ce-fs-url" placeholder="/api/contact" autocomplete="off">',
            '  <label class="admin-label" for="ce-fs-url-method" style="margin-top:6px">HTTP method (override)</label>',
            '  <select class="admin-input" id="ce-fs-url-method"><option value="POST">POST</option><option value="GET">GET</option><option value="PUT">PUT</option><option value="PATCH">PATCH</option><option value="DELETE">DELETE</option></select>',
            '</div>',
            '<div class="admin-form-group">',
            '  <label class="admin-checkbox"><input type="checkbox" id="ce-fs-validate" checked><span>Add <code>validate()</code> on submit</span></label>',
            '  <label class="admin-checkbox" style="margin-left:12px"><input type="checkbox" id="ce-fs-fetch" checked><span>Add <code>fetch()</code> on submit</span></label>',
            '</div>',

            '<hr style="margin:12px 0">',

            '<div class="admin-form-group">',
            '  <label class="admin-label">Fields <span class="admin-text-danger">*</span></label>',
            '  <p class="admin-hint">Each field becomes a <code>&lt;label&gt; + &lt;input&gt; + [data-error-for]</code> row. Reorder with the ↑↓ buttons, delete with ×.</p>',
            '  <div id="ce-fs-fields-rows"></div>',
            '</div>',

            '<hr style="margin:12px 0">',

            '<div class="admin-form-group">',
            '  <label class="admin-label">Submit button label <span class="admin-text-danger">*</span></label>',
            '  <div id="ce-fs-submit-label-mount"></div>',
            '</div>',
        ].join('');
        container.appendChild(wrap);

        // ===== element refs ==============================================
        const idEl       = wrap.querySelector('#ce-fs-id');
        const methodEl   = wrap.querySelector('#ce-fs-method');
        const apiGroup   = wrap.querySelector('#ce-fs-api-group');
        const urlGroup   = wrap.querySelector('#ce-fs-url-group');
        const apiEl      = wrap.querySelector('#ce-fs-api');
        const endpointEl = wrap.querySelector('#ce-fs-endpoint');
        const urlEl      = wrap.querySelector('#ce-fs-url');
        const urlMethodEl = wrap.querySelector('#ce-fs-url-method');
        const validateEl = wrap.querySelector('#ce-fs-validate');
        const fetchEl    = wrap.querySelector('#ce-fs-fetch');
        const fieldsContainer = wrap.querySelector('#ce-fs-fields-rows');

        // ===== submit-mode visibility ====================================
        function getMode() {
            const r = wrap.querySelector('input[name="ce-fs-mode"]:checked');
            return r ? r.value : 'api';
        }
        function syncModeUI() {
            const mode = getMode();
            apiGroup.style.display = (mode === 'api') ? '' : 'none';
            urlGroup.style.display = (mode === 'url') ? '' : 'none';
        }
        wrap.querySelectorAll('input[name="ce-fs-mode"]').forEach(r => {
            r.addEventListener('change', syncModeUI);
        });
        syncModeUI();

        // ===== API / endpoint dropdowns ==================================
        function populateApiDropdown() {
            loadApisOnce().then(apis => {
                const prev = apiEl.value;
                apiEl.innerHTML = '<option value="">— pick an API —</option>';
                apis.forEach(api => {
                    const opt = document.createElement('option');
                    opt.value = api.apiId;
                    opt.textContent = api.name + ' (' + api.apiId + ')';
                    apiEl.appendChild(opt);
                });
                if (prev) apiEl.value = prev;
                populateEndpointDropdown();
            });
        }
        function populateEndpointDropdown() {
            const apiId = apiEl.value;
            const prev = endpointEl.value;
            endpointEl.innerHTML = '<option value="">— pick an endpoint —</option>';
            if (!apiId || !_apisCache) return;
            const api = _apisCache.find(a => a.apiId === apiId);
            (api && Array.isArray(api.endpoints) ? api.endpoints : []).forEach(ep => {
                const opt = document.createElement('option');
                opt.value = ep.id;
                opt.textContent = (ep.method || 'GET') + ' ' + (ep.path || '') + '  — ' + (ep.name || ep.id);
                endpointEl.appendChild(opt);
            });
            if (prev) endpointEl.value = prev;
        }
        apiEl.addEventListener('change', populateEndpointDropdown);
        populateApiDropdown();

        // ===== submit-label textKey picker ===============================
        const submitLabelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: wrap.querySelector('#ce-fs-submit-label-mount'),
            placeholder: 'e.g. form.contact.submit',
        });

        // ===== fields list (WizardRowEditor) =============================
        // Each row is its own mini Field-Row form: name + type + label
        // picker + required checkbox. Per-field textKey pickers wired
        // through the same shared primitive as Field Row standalone.
        const fieldPickers = new WeakMap();   // rowEl → labelPicker controller
        const fieldsEditor = window.QSComplexWizard.createRowEditor({
            container: fieldsContainer,
            addButtonLabel: '+ Add field',
            minRows: 1,
            initialRows: [
                // Sensible starter row so the user has something to fill in
                // immediately instead of staring at empty space.
                { name: '', type: 'text', labelKey: '', required: false }
            ],
            renderRow: function (data) {
                const d = data || { type: 'text' };
                const row = document.createElement('div');
                row.className = 'qs-fs-field-row';
                row.innerHTML = [
                    '<div class="qs-fs-field-row__line">',
                    '  <input class="admin-input qs-fs-field-row__name" type="text" placeholder="field name" autocomplete="off">',
                    '  <select class="admin-input qs-fs-field-row__type">' +
                        INPUT_TYPES.map(t =>
                            '<option value="' + t + '"' + (t === (d.type || 'text') ? ' selected' : '') + '>' + t + '</option>'
                        ).join('') +
                    '  </select>',
                    '  <label class="qs-fs-field-row__required"><input type="checkbox" class="qs-fs-field-row__required-input"><span>required</span></label>',
                    '</div>',
                    '<div class="qs-fs-field-row__line">',
                    '  <span class="qs-fs-field-row__label-label">Label key:</span>',
                    '  <div class="qs-fs-field-row__label-mount"></div>',
                    '</div>',
                ].join('');

                // Restore values from `data`
                if (d.name) row.querySelector('.qs-fs-field-row__name').value = d.name;
                if (d.required) row.querySelector('.qs-fs-field-row__required-input').checked = true;

                // Wire label textKey picker per row.
                const labelPicker = window.QSComplexWizard.createTextKeyPicker({
                    container: row.querySelector('.qs-fs-field-row__label-mount'),
                    placeholder: 'e.g. form.contact.email.label',
                    value: d.labelKey || '',
                });
                fieldPickers.set(row, labelPicker);

                // Auto-suggest label key from field name when both are empty.
                let userTouchedLabel = !!d.labelKey;
                const _origLabelSet = labelPicker.setValue;
                labelPicker.setValue = function (v) { if (v) userTouchedLabel = true; _origLabelSet(v); };
                const nameInput = row.querySelector('.qs-fs-field-row__name');
                nameInput.addEventListener('input', function () {
                    if (!userTouchedLabel && nameInput.value) {
                        const fid = idEl.value.trim() || 'form';
                        _origLabelSet('form.' + fid.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.' + nameInput.value.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.label');
                    }
                });

                return row;
            },
            readRow: function (contentEl) {
                // WizardRowEditor calls readRow with the content element
                // (the one renderRow returned), not its outer wrapper.
                // Earlier code used .parentElement which pointed at the
                // qs-wizard-row wrapper — the WeakMap missed the entry
                // and labelKey came back empty even when a key was picked.
                const labelPicker = fieldPickers.get(contentEl);
                return {
                    name: contentEl.querySelector('.qs-fs-field-row__name').value.trim(),
                    type: contentEl.querySelector('.qs-fs-field-row__type').value,
                    labelKey: labelPicker ? (labelPicker.getValue() || '').trim() : '',
                    required: contentEl.querySelector('.qs-fs-field-row__required-input').checked
                };
            }
        });

        // ===== controller =================================================
        function getConfig() {
            const cfg = {
                id: idEl.value.trim(),
                method: methodEl.value,
                submitMode: getMode(),
                validateOnSubmit: validateEl.checked,
                fetchOnSubmit: fetchEl.checked,
                submitLabelKey: (submitLabelPicker.getValue() || '').trim(),
                fields: fieldsEditor.getRows().filter(f => f.name)   // drop unnamed rows on save
            };
            if (cfg.submitMode === 'api') {
                cfg.apiId = apiEl.value;
                cfg.endpointId = endpointEl.value;
            } else if (cfg.submitMode === 'url') {
                cfg.submitUrl = urlEl.value.trim();
                cfg.submitMethod = urlMethodEl.value;
            }
            return cfg;
        }

        function validate() {
            const cfg = getConfig();
            if (!cfg.id) return 'Form id is required';
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.id)) {
                return 'Form id must start with a letter (letters, digits, hyphens, underscores)';
            }
            if (!cfg.submitLabelKey) return 'Submit button label key is required';
            if (cfg.fields.length === 0) return 'Add at least one named field';
            // Per-field — same rules as Field Row standalone
            for (let i = 0; i < cfg.fields.length; i++) {
                const f = cfg.fields[i];
                if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(f.name)) {
                    return 'Field "' + f.name + '" (row ' + (i + 1) + '): invalid name';
                }
                if (!f.labelKey) {
                    return 'Field "' + f.name + '" (row ' + (i + 1) + '): label key is required';
                }
            }
            if (cfg.fetchOnSubmit && cfg.submitMode === 'api' && (!cfg.apiId || !cfg.endpointId)) {
                return 'Pick an API and endpoint, or uncheck "Add fetch()"';
            }
            if (cfg.fetchOnSubmit && cfg.submitMode === 'url' && !cfg.submitUrl) {
                return 'Submit URL is required, or uncheck "Add fetch()"';
            }
            // Duplicate field names — server rejects too, fail-fast here.
            const seen = new Set();
            for (const f of cfg.fields) {
                if (seen.has(f.name)) return 'Duplicate field name: "' + f.name + '"';
                seen.add(f.name);
            }
            return null;
        }

        function destroy() {
            try { submitLabelPicker.destroy(); } catch (e) {}
            try { fieldsEditor.destroy(); } catch (e) {}
            container.innerHTML = '';
        }

        return { getConfig, validate, destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['form-scaffold'] = {
        label: 'Form scaffold',
        description: 'Complete <form> with N fields and validate+fetch pre-wired on submit.',
        renderWizard: renderWizard
    };
})();
