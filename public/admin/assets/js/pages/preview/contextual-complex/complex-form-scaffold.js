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
        const W = window.QSComplexWizard;
        const t = W.t;
        // ===== top-level form (id / method / submit mode) ================
        const option = (value) => QSDom.el('option', { value: value, text: value });
        const modeRadio = (value, label, checked) => QSDom.el('label', null, [
            QSDom.el('input', Object.assign({ type: 'radio', name: 'ce-fs-mode', value: value }, checked ? { checked: '' } : {})),
            ' ' + label
        ]);
        const onSubmitBox = (id, call, style) => QSDom.el('label', Object.assign({ class: 'admin-checkbox' }, style ? { style: style } : {}), [
            QSDom.el('input', { type: 'checkbox', id: id, checked: '' }),
            QSDom.el('span', null, W.codeText(t('formScaffold.addOnSubmit'), { call: call }))
        ]);

        const idEl        = QSDom.el('input', { class: 'admin-input', type: 'text', id: 'ce-fs-id', placeholder: t('formScaffold.idPlaceholder'), autocomplete: 'off' });
        const methodEl    = QSDom.el('select', { class: 'admin-input', id: 'ce-fs-method' }, [option('POST'), option('GET')]);
        const apiEl       = QSDom.el('select', { class: 'admin-input', id: 'ce-fs-api' });
        const endpointEl  = QSDom.el('select', { class: 'admin-input', id: 'ce-fs-endpoint' });
        QSDom.setSelectPlaceholder(apiEl, t('formScaffold.pickApi'));
        QSDom.setSelectPlaceholder(endpointEl, t('formScaffold.pickEndpoint'));
        const apiGroup    = QSDom.el('div', { class: 'admin-form-group', id: 'ce-fs-api-group' }, [
            QSDom.el('label', { class: 'admin-label', for: 'ce-fs-api', text: t('formScaffold.api') }),
            apiEl,
            QSDom.el('label', { class: 'admin-label', for: 'ce-fs-endpoint', style: 'margin-top:6px', text: t('formScaffold.endpoint') }),
            endpointEl
        ]);
        const urlEl       = QSDom.el('input', { class: 'admin-input', type: 'text', id: 'ce-fs-url', placeholder: '/api/contact', autocomplete: 'off' });
        const urlMethodEl = QSDom.el('select', { class: 'admin-input', id: 'ce-fs-url-method' }, ['POST', 'GET', 'PUT', 'PATCH', 'DELETE'].map(option));
        const urlGroup    = QSDom.el('div', { class: 'admin-form-group', id: 'ce-fs-url-group', style: 'display:none;' }, [
            QSDom.el('label', { class: 'admin-label', for: 'ce-fs-url', text: t('formScaffold.submitUrl') }),
            urlEl,
            QSDom.el('label', { class: 'admin-label', for: 'ce-fs-url-method', style: 'margin-top:6px', text: t('formScaffold.httpMethod') }),
            urlMethodEl
        ]);
        const validateBox = onSubmitBox('ce-fs-validate', 'validate()');
        const fetchBox    = onSubmitBox('ce-fs-fetch', 'fetch()', 'margin-left:12px');
        const fieldsContainer = QSDom.el('div', { id: 'ce-fs-fields-rows' });
        const submitLabelMount = QSDom.el('div', { id: 'ce-fs-submit-label-mount' });

        const wrap = QSDom.el('div', {}, [
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-fs-id' }, [t('formScaffold.formId'), ' ', W.requiredMark()]),
                idEl,
                QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('formScaffold.idHint'), { id: 'id' }))
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', for: 'ce-fs-method', text: t('formScaffold.htmlMethod') }),
                methodEl
            ]),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label', text: t('formScaffold.submitTo') }),
                QSDom.el('div', { class: 'qs-radio-row' }, [
                    modeRadio('api', t('formScaffold.modeApi'), true),
                    modeRadio('url', t('formScaffold.modeUrl'), false),
                    modeRadio('none', t('formScaffold.modeNone'), false)
                ])
            ]),
            apiGroup,
            urlGroup,
            QSDom.el('div', { class: 'admin-form-group' }, [validateBox, ' ', fetchBox]),
            QSDom.el('hr', { style: 'margin:12px 0' }),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('formScaffold.fields'), ' ', W.requiredMark()]),
                QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('formScaffold.fieldsHint'), { row: '<label> + <input> + [data-error-for]' })),
                fieldsContainer
            ]),
            QSDom.el('hr', { style: 'margin:12px 0' }),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('formScaffold.submitLabel'), ' ', W.requiredMark()]),
                submitLabelMount
            ])
        ]);
        container.appendChild(wrap);

        // ===== element refs ==============================================
        const validateEl = validateBox.querySelector('input');
        const fetchEl    = fetchBox.querySelector('input');

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
                QSDom.setSelectPlaceholder(apiEl, t('formScaffold.pickApi'));
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
            QSDom.setSelectPlaceholder(endpointEl, t('formScaffold.pickEndpoint'));
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
        const submitLabelPicker = W.createTextKeyPicker({
            container: submitLabelMount,
            placeholder: t('formScaffold.submitKeyPlaceholder'),
        });

        // ===== fields list (WizardRowEditor) =============================
        // Each row is its own mini Field-Row form: name + type + label
        // picker + required checkbox. Per-field textKey pickers wired
        // through the same shared primitive as Field Row standalone.
        const fieldPickers = new WeakMap();   // rowEl → labelPicker controller
        const fieldsEditor = W.createRowEditor({
            container: fieldsContainer,
            addButtonLabel: t('formScaffold.addField'),
            minRows: 1,
            initialRows: [
                // Sensible starter row so the user has something to fill in
                // immediately instead of staring at empty space.
                { name: '', type: 'text', labelKey: '', required: false }
            ],
            renderRow: function (data) {
                const d = data || { type: 'text' };
                const nameInput = QSDom.el('input', { class: 'admin-input qs-fs-field-row__name', type: 'text', placeholder: t('formScaffold.fieldNamePlaceholder'), autocomplete: 'off' });
                const requiredInput = QSDom.el('input', { type: 'checkbox', class: 'qs-fs-field-row__required-input' });
                const labelMount = QSDom.el('div', { class: 'qs-fs-field-row__label-mount' });
                const row = QSDom.el('div', {}, [
                    QSDom.el('div', { class: 'qs-fs-field-row__line' }, [
                        nameInput,
                        QSDom.el('select', { class: 'admin-input qs-fs-field-row__type' }, INPUT_TYPES.map(type =>
                            QSDom.el('option', Object.assign({ value: type, text: type }, type === (d.type || 'text') ? { selected: '' } : {})))),
                        QSDom.el('label', { class: 'qs-fs-field-row__required' }, [requiredInput, QSDom.el('span', { text: t('formScaffold.required') })])
                    ]),
                    QSDom.el('div', { class: 'qs-fs-field-row__line' }, [
                        QSDom.el('span', { class: 'qs-fs-field-row__label-label', text: t('common.rowLabelKey') }),
                        labelMount
                    ])
                ]);

                // Restore values from `data`
                if (d.name) nameInput.value = d.name;
                if (d.required) requiredInput.checked = true;

                // Wire label textKey picker per row.
                const labelPicker = W.createTextKeyPicker({
                    container: labelMount,
                    placeholder: t('fieldRow.labelKeyPlaceholder'),
                    value: d.labelKey || '',
                });
                fieldPickers.set(row, labelPicker);

                // Auto-suggest label key from field name when both are empty.
                let userTouchedLabel = !!d.labelKey;
                const _origLabelSet = labelPicker.setValue;
                labelPicker.setValue = function (v) { if (v) userTouchedLabel = true; _origLabelSet(v); };
                nameInput.addEventListener('input', function () {
                    if (!userTouchedLabel && nameInput.value) {
                        const fid = idEl.value.trim() || 'form';
                        _origLabelSet('form.' + fid.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.' + nameInput.value.toLowerCase().replace(/[^a-z0-9]/g, '-') + '.label');
                    }
                });

                return row;
            },
            readRow: function (contentEl) {
                // WizardRowEditor hands readRow the content element (what
                // renderRow returned), not its qs-wizard-row wrapper: the
                // WeakMap is keyed by that element.
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
        // When the picked endpoint declares a requestSchema.properties, a
        // button + hint inside the API group pre-fills every field row from
        // the schema. With fields already named the button resets them, and
        // asks first.
        const autoseedGroup = document.createElement('div');
        autoseedGroup.style.marginTop = '8px';
        autoseedGroup.style.padding = '6px 8px';
        autoseedGroup.style.background = 'var(--admin-bg-tertiary)';
        autoseedGroup.style.borderRadius = '4px';
        autoseedGroup.style.display = 'none';

        const autoseedHint = document.createElement('div');
        autoseedHint.className = 'admin-hint';
        autoseedHint.style.margin = '0 0 4px';
        autoseedGroup.appendChild(autoseedHint);

        const autoseedBtn = document.createElement('button');
        autoseedBtn.type = 'button';
        autoseedBtn.className = 'admin-btn';
        autoseedBtn.textContent = t('formScaffold.prefill');
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

        // Type inference for the form-scaffold auto-seed.
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
        //      The standard form is `type: string, format: password`
        //      (JSON Schema); this branch is the autoseed reader's
        //      backstop for the alias.
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
            const type = prop.type;
            // A: standard JSON Schema types.
            if (type === 'boolean') return 'checkbox';
            if (type === 'number' || type === 'integer') return 'number';
            // B: permissive aliasing — accept any supported HTML5
            // input name typed directly into `type` (non-standard but
            // intuitive). Order matters: comes AFTER (A) so JSON
            // Schema's `number` stays a number, not "fall through to
            // INPUT_TYPES check"; comes BEFORE (C) so `type=password`
            // wins without needing format/writeOnly/name signals.
            if (typeof type === 'string' && INPUT_TYPES.indexOf(type) !== -1) return type;
            // C: standard string + format / signals.
            if (type === 'string') {
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
        // visible. When fields are already populated, its label turns to a
        // destructive-style "Reset" and the click asks before replacing them.
        function _syncAutoSeedUI() {
            const ep = _getPickedEndpointData();
            const props = (ep && ep.requestSchema && ep.requestSchema.properties) || {};
            const propNames = Object.keys(props);
            if (propNames.length === 0) {
                autoseedGroup.style.display = 'none';
                return;
            }
            autoseedGroup.style.display = '';
            autoseedHint.textContent = propNames.length === 1
                ? t('formScaffold.schemaFieldsOne')
                : t('formScaffold.schemaFieldsMany', { n: propNames.length });

            const namedCount = _currentNamedFieldCount();
            if (namedCount > 0) {
                autoseedBtn.textContent = t('formScaffold.reset', { n: namedCount });
                autoseedBtn.title = t('formScaffold.resetTitle', { n: namedCount });
            } else {
                autoseedBtn.textContent = t('formScaffold.prefill');
                autoseedBtn.title = '';
            }
            autoseedBtn.disabled = false;   // always clickable when group is visible
        }

        async function _autoseedNow() {
            // Replacing fields the user has named asks first, with the panel's own dialog.
            const namedCount = _currentNamedFieldCount();
            if (namedCount > 0) {
                const ok = await QuickSiteUtils.confirm(t('formScaffold.confirmMessage', { n: namedCount }), {
                    title: t('formScaffold.confirmTitle'),
                    type: 'danger',
                    confirmText: t('formScaffold.confirmButton'),
                    confirmClass: 'danger'
                });
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
            if (!cfg.id) return t('formScaffold.errors.idRequired');
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.id)) {
                return t('formScaffold.errors.idFormat');
            }
            if (!cfg.submitLabelKey) return t('formScaffold.errors.submitLabelRequired');
            if (cfg.fields.length === 0) return t('formScaffold.errors.noField');
            // Per-field — same rules as Field Row standalone
            for (let i = 0; i < cfg.fields.length; i++) {
                const f = cfg.fields[i];
                if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(f.name)) {
                    return t('formScaffold.errors.fieldName', { name: f.name, row: i + 1 });
                }
                if (!f.labelKey) {
                    return t('formScaffold.errors.fieldLabel', { name: f.name, row: i + 1 });
                }
            }
            if (cfg.fetchOnSubmit && cfg.submitMode === 'api' && (!cfg.apiId || !cfg.endpointId)) {
                return t('formScaffold.errors.noEndpoint');
            }
            if (cfg.fetchOnSubmit && cfg.submitMode === 'url' && !cfg.submitUrl) {
                return t('formScaffold.errors.noUrl');
            }
            // Duplicate field names — server rejects too, fail-fast here.
            const seen = new Set();
            for (const f of cfg.fields) {
                if (seen.has(f.name)) return t('formScaffold.errors.duplicate', { name: f.name });
                seen.add(f.name);
            }
            return null;
        }

        function destroy() {
            try { submitLabelPicker.destroy(); } catch (e) {}
            try { fieldsEditor.destroy(); } catch (e) {}
            container.replaceChildren();
        }

        return { getConfig, validate, destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['form-scaffold'] = {
        label: window.QSComplexWizard.t('formScaffold.label'),
        description: window.QSComplexWizard.t('formScaffold.description'),
        renderWizard: renderWizard
    };
})();
