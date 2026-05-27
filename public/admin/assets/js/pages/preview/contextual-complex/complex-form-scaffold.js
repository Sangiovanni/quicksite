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
                // Response shape: { apis: [{ apiId, name, endpoints: [{id, method, name, path,
                //   requestSchema, responseSchema, ...}, ...] }, ...] }.
                // The wizard's UI only uses id/method/name/path for the
                // dropdown — `requestSchema` (and its `required` array) is
                // kept on each endpoint entry so the autoseed button below
                // can read it without a second round-trip.
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

        // ===== auto-seed from endpoint request schema =====================
        // When the picked endpoint declares a requestSchema.properties, we
        // surface a single button + hint inside the API group letting the
        // user pre-fill every field row from the schema. "Never clobber":
        // the button is disabled while any field has a name typed (user
        // must clear first to overwrite). All createElement-based per
        // CLAUDE.md HTML-in-JS hygiene (the surrounding wizard still uses
        // innerHTML — pre-existing tech debt filed in BACKLOG).
        const autoseedGroup = document.createElement('div');
        autoseedGroup.style.marginTop = '8px';
        autoseedGroup.style.padding = '6px 8px';
        autoseedGroup.style.background = 'var(--admin-surface-2, rgba(0,0,0,0.03))';
        autoseedGroup.style.borderRadius = '4px';
        autoseedGroup.style.display = 'none';

        const autoseedHint = document.createElement('div');
        autoseedHint.className = 'admin-hint';
        autoseedHint.style.margin = '0 0 4px';
        autoseedGroup.appendChild(autoseedHint);

        const autoseedBtn = document.createElement('button');
        autoseedBtn.type = 'button';
        autoseedBtn.className = 'admin-btn admin-btn--sm';
        autoseedBtn.textContent = '⤓ Pre-fill from request schema';  // ⤓ glyph
        autoseedGroup.appendChild(autoseedBtn);

        apiGroup.appendChild(autoseedGroup);

        // ---- helpers ------------------------------------------------------

        function _getPickedEndpointData() {
            const apiId = apiEl.value;
            const epId = endpointEl.value;
            if (!apiId || !epId || !_apisCache) return null;
            const api = _apisCache.find(a => a.apiId === apiId);
            if (!api) return null;
            return (api.endpoints || []).find(ep => ep.id === epId) || null;
        }

        // Type inference per the BETA7_FORM_SCAFFOLD_AUTOSEED.md table.
        // Returns one of INPUT_TYPES; defaults to 'text' for unknown shapes
        // (arrays / objects render as text so the user gets a visible
        // field they can then manually convert via the type dropdown).
        //
        // Resolution order:
        //   A. Standard JSON Schema types we know how to map:
        //      - boolean              → checkbox
        //      - number / integer     → number
        //   B. QuickSite-permissive aliasing: if `type` itself names an
        //      HTML5 input type that the form-scaffold supports
        //      (`password`, `email`, `tel`, `url`, `date`, `color`, …),
        //      use it directly. Non-standard per JSON Schema spec, but
        //      matches what users intuitively type into a free-text
        //      schema editor ("I want a password field, so type=password").
        //      Filed in BACKLOG: improve the API schema editor to push
        //      users toward `type: string, format: password` (standard
        //      JSON Schema); this branch is the autoseed reader's
        //      backstop until then.
        //   C. Standard string + format / signals:
        //      Password detection (strongest → weakest):
        //        1. `format: 'password'` — OpenAPI 3 extension; explicit.
        //        2. `writeOnly: true`    — JSON Schema flag for
        //                                  client-sent / server-never-
        //                                  echoed values; almost always
        //                                  a password or secret.
        //        3. property name matches /password/i — last-resort
        //                                  heuristic. Catches
        //                                  `password`, `oldPassword`,
        //                                  `password_confirmation`.
        //                                  False positive risk:
        //                                  `passwordless` would match.
        //      Other formats: email / tel / url / date / time /
        //      date-time / color.
        //      Strings with no signal → text.
        //   D. Unknown shapes (arrays / objects / null) → text fallback.
        function _inferInputType(prop, propName) {
            if (!prop || typeof prop !== 'object') return 'text';
            const t = prop.type;
            // A: standard JSON Schema types.
            if (t === 'boolean') return 'checkbox';
            if (t === 'number' || t === 'integer') return 'number';
            // B: permissive aliasing — accept any supported HTML5
            // input name typed directly into `type` (non-standard but
            // intuitive). Order matters: comes AFTER (A) so JSON
            // Schema's `number` stays a number, not "fall through to
            // INPUT_TYPES check"; comes BEFORE (C) so `type=password`
            // wins without needing format/writeOnly/name signals.
            if (typeof t === 'string' && INPUT_TYPES.indexOf(t) !== -1) return t;
            // C: standard string + format / signals.
            if (t === 'string') {
                const f = (prop.format || '').toLowerCase();
                if (f === 'password') return 'password';
                if (prop.writeOnly === true) return 'password';
                if (f === 'email') return 'email';
                if (f === 'tel') return 'tel';
                if (f === 'url') return 'url';
                if (f === 'date') return 'date';
                if (f === 'time') return 'time';
                if (f === 'date-time' || f === 'datetime') return 'datetime-local';
                if (f === 'color') return 'color';
                if (/password/i.test(propName)) return 'password';
                return 'text';
            }
            // D: unknown shape → text. The user can manually escalate
            // (e.g. to a Select complex element) after save.
            return 'text';
        }

        function _currentNamedFieldCount() {
            return fieldsEditor.getRows().filter(f => f.name && f.name.trim()).length;
        }

        // Refresh the auto-seed group visibility + button label. Called on:
        //  (a) endpoint change (count may go from 0 → N or N → 0),
        //  (b) fields editor change (named-field count → label flip),
        //  (c) form id change (the generated labelKey embeds the id — when
        //      it changes, regenerating an empty picker is harmless, but
        //      we don't auto-regenerate already-seeded keys; user clears
        //      + re-pre-fills if they renamed the form).
        //
        // The button is ALWAYS clickable when the auto-seed group is
        // visible (Flavor B from the design discussion). When fields are
        // already populated, the label flips to a destructive-style "Reset"
        // and the click handler prompts for confirmation before clobbering.
        function _syncAutoSeedUI() {
            const ep = _getPickedEndpointData();
            const props = (ep && ep.requestSchema && ep.requestSchema.properties) || {};
            const propNames = Object.keys(props);
            if (propNames.length === 0) {
                autoseedGroup.style.display = 'none';
                return;
            }
            autoseedGroup.style.display = '';
            autoseedHint.textContent = 'Endpoint declares ' + propNames.length
                + ' request field' + (propNames.length === 1 ? '' : 's') + '.';

            const namedCount = _currentNamedFieldCount();
            if (namedCount > 0) {
                autoseedBtn.textContent = '⤴ Reset to schema fields (clears ' + namedCount + ')';
                autoseedBtn.title = 'Replaces the ' + namedCount + ' current field(s) with schema defaults. Confirmation required.';
            } else {
                autoseedBtn.textContent = '⤓ Pre-fill from request schema';
                autoseedBtn.title = '';
            }
            autoseedBtn.disabled = false;   // always clickable when group is visible
        }

        function _autoseedNow() {
            // Destructive-replace confirmation when there's anything to clobber.
            // `window.confirm` matches how `deletePageEvent` already prompts
            // for destructive actions in the same admin layer — keeps the
            // wizard zero-dependency. A pretty modal is in BACKLOG.
            const namedCount = _currentNamedFieldCount();
            if (namedCount > 0) {
                const ok = window.confirm(
                    'Replace ' + namedCount + ' current field(s) with schema defaults?\n\n'
                    + 'This can\'t be undone.'
                );
                if (!ok) return;
            }

            const ep = _getPickedEndpointData();
            const props = (ep && ep.requestSchema && ep.requestSchema.properties) || {};
            const reqList = Array.isArray(ep && ep.requestSchema && ep.requestSchema.required)
                ? ep.requestSchema.required : [];
            const VALID = /^[a-zA-Z_][\w-]*$/;
            const formId = (idEl.value.trim() || 'form').toLowerCase().replace(/[^a-z0-9]/g, '-');

            const seeded = [];
            Object.keys(props).forEach(function (name) {
                if (!VALID.test(name)) return;  // skip schema entries we can't safely emit
                seeded.push({
                    name: name,
                    type: _inferInputType(props[name], name),
                    required: reqList.indexOf(name) !== -1,
                    // Generate the labelKey the same way the per-field
                    // userTouchedLabel auto-suggest does, so the picker
                    // shows a consistent shape if the user wants to find
                    // / create the key from there. The key may NOT exist
                    // yet — that's intentional, the picker shows an
                    // unresolved key and lets the user create it.
                    labelKey: 'form.' + formId + '.' + name.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.label'
                });
            });

            if (seeded.length === 0) return;
            fieldsEditor.clear();
            seeded.forEach(function (f) { fieldsEditor.addRow(f); });
            _syncAutoSeedUI();   // refresh the label (now showing "Reset…")
        }

        autoseedBtn.addEventListener('click', _autoseedNow);
        endpointEl.addEventListener('change', _syncAutoSeedUI);
        apiEl.addEventListener('change', function () {
            // populateEndpointDropdown also fires from the earlier
            // listener — by then the endpoint value has reset to '',
            // so this just hides the auto-seed group cleanly.
            _syncAutoSeedUI();
        });
        // Field edits should re-check the never-clobber gate too. The row
        // editor doesn't expose a granular per-input change event, but its
        // onChange covers add/remove/reorder. For per-character typing in
        // the name input, listen via delegation on the fields container.
        fieldsContainer.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('qs-fs-field-row__name')) {
                _syncAutoSeedUI();
            }
        });
        // Initial sync — endpoint may already be picked when the wizard
        // re-mounts (shouldn't happen today, but defensive).
        _syncAutoSeedUI();

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
