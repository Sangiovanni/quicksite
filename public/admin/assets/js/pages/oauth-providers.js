/**
 * OAuth providers admin page — list + add/edit modal + delete with
 * in-use block + override-in-project pre-fill (beta.9 A1 Slice 8).
 *
 * Calls listOAuthProviders / addOAuthProvider / editOAuthProvider /
 * deleteOAuthProvider via QuickSiteAdmin.apiRequest.
 *
 * Visual design (locked 2026-06-15, DESIGN_DECISIONS.md "OAuth
 * providers admin page shape"). All styling lives in
 * public/admin/assets/css/oauth-admin.css — this file is structure +
 * behaviour only. Classes follow the BEM-ish convention:
 *   oauth-provider-card, oauth-provider-card__row,
 *   oauth-pill, oauth-pill--admin/--project/--success/...,
 *   oauth-modal-backdrop, oauth-modal-dialog,
 *   oauth-credentials, oauth-extra-params, oauth-in-use-panel.
 *
 * Built with createElement + textContent + named _render* helpers per
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing.
 */
(function () {
    'use strict';

    const PRESET_TEXT_FIELDS = [
        { key: 'authorize_url',       label: 'Authorize URL',       required: true,  placeholder: 'https://provider.com/oauth/authorize' },
        { key: 'token_url',           label: 'Token URL',           required: true,  placeholder: 'https://provider.com/oauth/token' },
        { key: 'userinfo_url',        label: 'Userinfo URL',        required: true,  placeholder: 'https://provider.com/api/me' },
        { key: 'revoke_url',          label: 'Revoke URL',          required: false, placeholder: 'https://provider.com/oauth/revoke (optional, RFC 7009)' },
        { key: 'scope',               label: 'Default scope',       required: true,  placeholder: 'openid email profile (space-separated)' },
        { key: 'userinfo_sub_path',   label: 'User id dot-path',    required: true,  placeholder: 'sub  (or id, user_id, …)' },
        { key: 'userinfo_email_path', label: 'Email dot-path',      required: true,  placeholder: 'email' },
        { key: 'userinfo_name_path',  label: 'Name dot-path',       required: false, placeholder: 'name  (optional)' },
    ];

    const state = {
        providers: [],
        filter: 'all',
    };

    function api(cmd, method, body) {
        const adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return adminApi.apiRequest(cmd, method, body);
    }

    function _el(tag, props, children) {
        const e = document.createElement(tag);
        if (props) {
            for (const k in props) {
                if (k === 'dataset' && typeof props[k] === 'object') {
                    Object.assign(e.dataset, props[k]);
                } else if (k.indexOf('on') === 0 && typeof props[k] === 'function') {
                    e.addEventListener(k.slice(2).toLowerCase(), props[k]);
                } else if (k === 'class') {
                    e.className = props[k];
                } else if (k === 'text') {
                    e.textContent = props[k];
                } else {
                    e.setAttribute(k, props[k]);
                }
            }
        }
        if (children) {
            children.forEach(function (c) {
                if (c == null) return;
                if (typeof c === 'string') e.appendChild(document.createTextNode(c));
                else e.appendChild(c);
            });
        }
        return e;
    }

    function _renderLabel(text, required) {
        const l = _el('label', { class: 'admin-label' });
        l.appendChild(document.createTextNode(text));
        if (required) l.appendChild(_el('span', { class: 'admin-text-danger', text: ' *' }));
        return l;
    }
    function _renderHint(text) { return _el('p', { class: 'admin-hint', text: text }); }
    function _renderGroup(label, child, hint) {
        const g = _el('div', { class: 'admin-form-group' });
        if (label) g.appendChild(label);
        if (child) g.appendChild(child);
        if (hint) g.appendChild(hint);
        return g;
    }

    function _renderSvgIcon(pathD, size) {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('width', String(size || 14));
        svg.setAttribute('height', String(size || 14));
        svg.setAttribute('aria-hidden', 'true');
        const p = document.createElementNS(ns, 'path');
        p.setAttribute('d', pathD);
        svg.appendChild(p);
        return svg;
    }
    const ICON_CHECK = 'M5 12l5 5L20 7';
    const ICON_WARN  = 'M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z';
    const ICON_EDIT  = 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z';
    const ICON_FORK  = 'M6 3v12M18 9V3M18 9a3 3 0 1 1-6 0M6 21a3 3 0 1 0 0-6M18 9c0 6-6 6-6 12';
    const ICON_TRASH = 'M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2';
    const ICON_UNDO  = 'M3 7v6h6M21 17a9 9 0 0 0-15-6.7L3 13';
    const ICON_X     = 'M18 6L6 18M6 6l12 12';
    const ICON_EYE   = 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z';
    const ICON_PLUS  = 'M12 5v14M5 12h14';
    const ICON_MINUS = 'M5 12h14';

    function _renderPill(text, kind, iconNode) {
        const p = _el('span', { class: 'oauth-pill oauth-pill--' + kind });
        if (iconNode) p.appendChild(iconNode);
        p.appendChild(document.createTextNode(text));
        return p;
    }

    function _renderActionBtn(iconD, label, onClick, opts) {
        opts = opts || {};
        const classes = ['admin-btn', 'admin-btn--ghost', 'oauth-provider-card__action'];
        if (opts.disabled) classes.push('oauth-provider-card__action--disabled');
        const b = _el('button', {
            class: classes.join(' '),
            'aria-label': label,
            title: label,
            type: 'button',
            onclick: onClick,
        });
        if (opts.disabled) b.disabled = true;
        b.appendChild(_renderSvgIcon(iconD, 14));
        return b;
    }

    function _renderCard(p) {
        const card = _el('div', { class: 'oauth-provider-card' });
        const row = _el('div', { class: 'oauth-provider-card__row' });

        const main = _el('div', { class: 'oauth-provider-card__main' });
        const pills = _el('div', { class: 'oauth-provider-card__pills' });
        pills.appendChild(_el('span', { class: 'oauth-provider-card__id', text: p.id }));

        const sourceKind  = p.source === 'project-override' ? 'override' : (p.source === 'project' ? 'project' : 'admin');
        const sourceLabel = p.source === 'project-override' ? 'project override' : p.source;
        pills.appendChild(_renderPill(sourceLabel, sourceKind));

        if (p.credentials_status === 'set') {
            pills.appendChild(_renderPill('credentials set', 'success', _renderSvgIcon(ICON_CHECK, 11)));
        } else {
            pills.appendChild(_renderPill('missing credentials', 'danger', _renderSvgIcon(ICON_WARN, 11)));
        }

        const setup = p.setup || {};
        if (setup.fully_set_up) {
            pills.appendChild(_renderPill('fully set up', 'success', _renderSvgIcon(ICON_CHECK, 11)));
        } else if (setup.start_route_exists || setup.callback_route_exists) {
            pills.appendChild(_renderPill('partial setup', 'warning'));
        } else {
            pills.appendChild(_renderPill('no routes yet', 'neutral'));
        }
        if (p.resolver_count > 0) {
            pills.appendChild(_renderPill('in use · ' + p.resolver_count + ' resolver' + (p.resolver_count === 1 ? '' : 's'), 'warning'));
        }
        main.appendChild(pills);

        main.appendChild(_el('div', {
            class: 'oauth-provider-card__summary',
            text: ((p.preset && p.preset.authorize_url) || '—') + ' · scope: ' + (p.scope || '—'),
        }));
        row.appendChild(main);

        const actions = _el('div', { class: 'oauth-provider-card__actions' });
        actions.appendChild(_renderActionBtn(ICON_EDIT, 'Edit', function () { openEditModal(p); }));
        if (p.source === 'admin') {
            actions.appendChild(_renderActionBtn(ICON_FORK, 'Override in this project', function () { openOverrideModal(p); }));
        }
        if (p.source === 'project-override') {
            actions.appendChild(_renderActionBtn(ICON_UNDO, 'Remove override (fall back to admin entry)', function () { openDeleteConfirm(p); }));
        } else {
            actions.appendChild(_renderActionBtn(ICON_TRASH, 'Delete', function () { openDeleteConfirm(p); }));
        }
        row.appendChild(actions);
        card.appendChild(row);
        return card;
    }

    function _renderFilterBar() {
        const bar = _el('div', { class: 'oauth-filter-bar' });
        const counts = {
            all:       state.providers.length,
            admin:     state.providers.filter(function (p) { return p.source === 'admin'; }).length,
            project:   state.providers.filter(function (p) { return p.source === 'project' || p.source === 'project-override'; }).length,
            overrides: state.providers.filter(function (p) { return p.source === 'project-override'; }).length,
        };
        ['all', 'admin', 'project', 'overrides'].forEach(function (key) {
            const text = (key === 'overrides' ? 'Has overrides' : key.charAt(0).toUpperCase() + key.slice(1)) + ' · ' + counts[key];
            const isActive = state.filter === key;
            const cls = 'admin-btn admin-btn--ghost oauth-filter-bar__btn' + (isActive ? ' oauth-filter-bar__btn--active' : '');
            bar.appendChild(_el('button', {
                class: cls,
                type: 'button',
                onclick: function () { state.filter = key; renderList(); },
                text: text,
            }));
        });
        return bar;
    }

    function applyFilter(providers, filter) {
        if (filter === 'admin') return providers.filter(function (p) { return p.source === 'admin'; });
        if (filter === 'project') return providers.filter(function (p) { return p.source === 'project' || p.source === 'project-override'; });
        if (filter === 'overrides') return providers.filter(function (p) { return p.source === 'project-override'; });
        return providers;
    }

    function _renderEmptyState(filter) {
        return _el('div', {
            class: 'oauth-providers-list__empty',
            text: filter === 'all'
                ? 'No providers configured. Click "Add provider" to add one.'
                : 'No providers match the current filter.',
        });
    }

    function renderList() {
        const root = document.getElementById('oauth-providers-list');
        if (!root) return;
        root.textContent = '';
        root.className = 'oauth-providers-list';

        const filtered = applyFilter(state.providers, state.filter);
        if (filtered.length === 0) {
            root.appendChild(_renderEmptyState(state.filter));
        } else {
            filtered.forEach(function (p) { root.appendChild(_renderCard(p)); });
        }

        const filterBar = document.getElementById('oauth-providers-filter-bar');
        if (filterBar) {
            filterBar.textContent = '';
            filterBar.appendChild(_renderFilterBar());
        }
    }

    async function refresh() {
        try {
            const r = await api('listOAuthProviders', 'GET');
            const payload = (r && r.data && (r.data.data || r.data)) || {};
            state.providers = Array.isArray(payload.providers) ? payload.providers : [];
        } catch (e) {
            console.warn('[oauth-providers] load failed:', e);
            state.providers = [];
        }
        renderList();
    }

    // ====================================================================
    // Modal — add / edit / override / delete
    // ====================================================================

    function _renderModalShell(titleText, onClose) {
        const root = document.getElementById('oauth-provider-modal-root');
        if (!root) return null;
        root.textContent = '';

        const backdrop = _el('div', {
            class: 'oauth-modal-backdrop',
            onclick: function (e) { if (e.target === backdrop) onClose(); },
        });
        const dialog = _el('div', { class: 'oauth-modal-dialog' });

        const header = _el('div', { class: 'oauth-modal-header' });
        header.appendChild(_el('h2', { class: 'oauth-modal-header__title', text: titleText }));
        const closeBtn = _el('button', {
            class: 'admin-btn admin-btn--ghost oauth-modal-header__close',
            type: 'button', 'aria-label': 'Close', onclick: onClose,
        });
        closeBtn.appendChild(_renderSvgIcon(ICON_X, 16));
        header.appendChild(closeBtn);
        dialog.appendChild(header);

        backdrop.appendChild(dialog);
        root.appendChild(backdrop);
        return dialog;
    }

    function closeModal() {
        const root = document.getElementById('oauth-provider-modal-root');
        if (root) root.textContent = '';
    }

    function _renderCredentialsSection(existingClientId, existingHint) {
        const section = _el('div', { class: 'oauth-credentials' });
        section.appendChild(_el('h3', { class: 'oauth-credentials__title', text: 'Credentials' }));
        section.appendChild(_renderHint('Stored in oauth-secrets.{php|json} at the same scope. Required if you want this provider to actually authenticate users.'));

        const clientIdInput = _el('input', {
            type: 'text', class: 'admin-input',
            placeholder: 'client_id (from provider console)',
            value: existingClientId || '',
            autocomplete: 'off',
        });
        section.appendChild(_renderGroup(_renderLabel('Client ID', true), clientIdInput));

        const secretRow = _el('div', { class: 'oauth-credentials__secret-row' });
        const clientSecretInput = _el('input', {
            type: 'password', class: 'admin-input',
            placeholder: existingClientId ? '•••••••• (leave empty to keep current)' : 'client_secret (optional for public clients)',
            autocomplete: 'new-password',
        });
        secretRow.appendChild(clientSecretInput);

        if (existingHint) {
            const reveal = _el('button', {
                class: 'admin-btn admin-btn--ghost oauth-credentials__reveal-btn',
                type: 'button', 'aria-label': 'Reveal first characters of stored secret',
                onclick: function () {
                    const revealed = _el('span', { class: 'oauth-credentials__revealed-value', text: existingHint });
                    reveal.replaceWith(revealed);
                },
            });
            reveal.appendChild(_renderSvgIcon(ICON_EYE, 12));
            reveal.appendChild(document.createTextNode(' Show first chars'));
            secretRow.appendChild(reveal);
        }
        section.appendChild(_renderGroup(_renderLabel('Client Secret', !existingClientId), secretRow));

        return {
            element: section,
            read: function () {
                const cid = clientIdInput.value.trim();
                if (cid === '') return null;
                const out = { client_id: cid };
                const secret = clientSecretInput.value;
                if (secret !== '') out.client_secret = secret;
                return out;
            },
        };
    }

    function _credentialHint(provider) {
        if (!provider || provider.credentials_status !== 'set') return null;
        return '(secret stored — actual chars hidden in this preview build)';
    }

    function _renderExtraParamsEditor(initialKv) {
        const wrap = _el('div', { class: 'oauth-extra-params' });
        const rows = _el('div', { class: 'oauth-extra-params__rows' });
        wrap.appendChild(rows);

        function addRow(k, v) {
            const row = _el('div', { class: 'oauth-extra-params__row' });
            const keyI = _el('input', { type: 'text', class: 'admin-input', placeholder: 'key (e.g. access_type)', value: k || '' });
            const valI = _el('input', { type: 'text', class: 'admin-input', placeholder: 'value (e.g. offline)', value: v || '' });
            const rm = _el('button', {
                class: 'admin-btn admin-btn--ghost', type: 'button',
                'aria-label': 'Remove this extra param',
                onclick: function () { row.remove(); },
            });
            rm.appendChild(_renderSvgIcon(ICON_MINUS, 14));
            row.appendChild(keyI); row.appendChild(valI); row.appendChild(rm);
            rows.appendChild(row);
        }

        if (initialKv && typeof initialKv === 'object') {
            Object.keys(initialKv).forEach(function (k) { addRow(k, String(initialKv[k] || '')); });
        }

        const addBtn = _el('button', {
            class: 'admin-btn admin-btn--ghost oauth-extra-params__add',
            type: 'button',
            onclick: function () { addRow('', ''); },
        });
        addBtn.appendChild(_renderSvgIcon(ICON_PLUS, 12));
        addBtn.appendChild(document.createTextNode(' Add extra param'));
        wrap.appendChild(addBtn);

        return {
            element: wrap,
            read: function () {
                const out = {};
                rows.querySelectorAll(':scope > .oauth-extra-params__row').forEach(function (row) {
                    const inputs = row.querySelectorAll('input');
                    const k = inputs[0] ? inputs[0].value.trim() : '';
                    const v = inputs[1] ? inputs[1].value : '';
                    if (k !== '') out[k] = v;
                });
                return out;
            },
        };
    }

    function _openModal(mode, provider) {
        const titleText = mode === 'add' ? 'Add OAuth provider' : (mode === 'override' ? 'Override "' + provider.id + '" in this project' : 'Edit "' + provider.id + '"');
        const dialog = _renderModalShell(titleText, closeModal);
        if (!dialog) return;

        const isEdit = (mode === 'edit');
        const isOverride = (mode === 'override');
        const sourceScope = provider ? provider.source.replace('-override', '') : null;
        const initialScope = mode === 'add' ? 'project' : (isOverride ? 'project' : sourceScope);
        const initialPreset = provider && provider.preset ? provider.preset : {};
        const initialId = provider ? provider.id : '';

        // Scope toggle (radio "cards")
        const scopeWrap = _el('div', { class: 'oauth-scope-toggle' });
        function makeScopeOption(value, labelText, hintText) {
            const opt = _el('label', { class: 'oauth-scope-toggle__option' });
            const head = _el('div', { class: 'oauth-scope-toggle__head' });
            const radio = _el('input', { type: 'radio', name: 'oauth-prov-scope', value: value });
            if (initialScope === value) {
                radio.checked = true;
                opt.classList.add('oauth-scope-toggle__option--checked');
            }
            radio.addEventListener('change', function () {
                scopeWrap.querySelectorAll('.oauth-scope-toggle__option').forEach(function (el) {
                    el.classList.remove('oauth-scope-toggle__option--checked');
                });
                if (radio.checked) opt.classList.add('oauth-scope-toggle__option--checked');
            });
            head.appendChild(radio);
            head.appendChild(_el('span', { class: 'oauth-scope-toggle__label', text: labelText }));
            opt.appendChild(head);
            opt.appendChild(_el('p', { class: 'oauth-scope-toggle__hint', text: hintText }));
            return { opt: opt, radio: radio };
        }
        const scopeAdmin = makeScopeOption('admin', 'Engine catalogue (admin)', 'Available in every project on this install.');
        const scopeProject = makeScopeOption('project', 'This project only', 'Gitignored; per-project override.');
        scopeWrap.appendChild(scopeAdmin.opt);
        scopeWrap.appendChild(scopeProject.opt);
        dialog.appendChild(_renderGroup(_renderLabel('Scope', true), scopeWrap));

        // Provider id
        const idInput = _el('input', {
            type: 'text', class: 'admin-input',
            placeholder: 'lowercase id, e.g. mycorp-sso',
            value: initialId, autocomplete: 'off',
        });
        if (isOverride) idInput.disabled = true;
        dialog.appendChild(_renderGroup(_renderLabel('Provider id', true), idInput, _renderHint('Lowercase letters, digits, hyphens. Must match /^[a-z][a-z0-9-]*$/.')));

        // Preset text fields
        const fieldInputs = {};
        PRESET_TEXT_FIELDS.forEach(function (f) {
            const inp = _el('input', {
                type: 'text', class: 'admin-input',
                placeholder: f.placeholder,
                value: initialPreset[f.key] != null ? String(initialPreset[f.key]) : '',
                autocomplete: 'off',
            });
            fieldInputs[f.key] = inp;
            dialog.appendChild(_renderGroup(_renderLabel(f.label, f.required), inp));
        });

        // Extra params
        const extraEditor = _renderExtraParamsEditor(initialPreset.extra_authorize_params || {});
        dialog.appendChild(_renderGroup(_renderLabel('Extra authorize params'), extraEditor.element, _renderHint('Provider-specific (e.g. Google\'s access_type=offline, prompt=consent).')));

        // Refresh token supported
        const refreshLabel = _el('label', { class: 'oauth-scope-toggle__head' });
        const refreshCb = _el('input', { type: 'checkbox' });
        if (initialPreset.refresh_token_supported) refreshCb.checked = true;
        refreshLabel.appendChild(refreshCb);
        refreshLabel.appendChild(document.createTextNode(' Provider issues refresh tokens with the standard flow'));
        dialog.appendChild(_renderGroup(_renderLabel('Refresh token supported'), refreshLabel, _renderHint('Most do (Google, Amazon). GitHub OAuth Apps and Meta\'s default access tokens do not.')));

        // Comment
        const commentInput = _el('textarea', {
            class: 'admin-input', rows: '2',
            placeholder: 'Optional notes (becomes _comment in the preset entry)',
        });
        commentInput.value = initialPreset._comment != null ? String(initialPreset._comment) : '';
        dialog.appendChild(_renderGroup(_renderLabel('Comment'), commentInput));

        // Credentials
        const credentialsCtl = _renderCredentialsSection(
            (provider && provider.credentials_status === 'set') ? '••••' : '',
            _credentialHint(provider)
        );
        dialog.appendChild(credentialsCtl.element);

        // Actions row
        const actionsRow = _el('div', { class: 'oauth-modal-actions' });
        const errBox = _el('div', { class: 'oauth-modal-actions__error' });
        actionsRow.appendChild(errBox);
        const cancelBtn = _el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', text: 'Cancel', onclick: closeModal });
        const saveBtnText = mode === 'add'
            ? 'Add provider'
            : (mode === 'override' ? 'Create override' : 'Save changes');
        const saveBtn = _el('button', { class: 'admin-btn admin-btn--primary', type: 'button', text: saveBtnText });
        actionsRow.appendChild(cancelBtn);
        actionsRow.appendChild(saveBtn);
        dialog.appendChild(actionsRow);

        saveBtn.addEventListener('click', async function () {
            errBox.textContent = '';
            const scope = scopeAdmin.radio.checked ? 'admin' : 'project';
            const id = idInput.value.trim();
            if (!/^[a-z][a-z0-9-]*$/.test(id)) {
                errBox.textContent = 'Provider id must match /^[a-z][a-z0-9-]*$/.';
                return;
            }
            const preset = {};
            PRESET_TEXT_FIELDS.forEach(function (f) {
                const v = (fieldInputs[f.key].value || '').trim();
                if (v !== '' || f.required) preset[f.key] = v;
            });
            const extra = extraEditor.read();
            preset.extra_authorize_params = Object.keys(extra).length > 0 ? extra : {};
            preset.refresh_token_supported = !!refreshCb.checked;
            const cmt = commentInput.value.trim();
            if (cmt !== '') preset._comment = cmt;

            const creds = credentialsCtl.read();
            const body = { scope: scope, id: id, preset: preset };
            if (creds) body.credentials = creds;

            saveBtn.disabled = true;
            try {
                let r;
                if (isEdit && !isOverride) {
                    body.scope = sourceScope;
                    if (scope !== sourceScope) body.newScope = scope;
                    if (id !== initialId) body.newId = id;
                    r = await api('editOAuthProvider', 'POST', body);
                } else if (isOverride) {
                    r = await api('addOAuthProvider', 'POST', body);
                } else {
                    r = await api('addOAuthProvider', 'POST', body);
                }
                if (!r || !r.ok) {
                    errBox.textContent = (r && r.data && r.data.message) || 'Save failed';
                    saveBtn.disabled = false;
                    return;
                }
                closeModal();
                await refresh();
            } catch (e) {
                errBox.textContent = (e && e.message) || 'Network error';
                saveBtn.disabled = false;
            }
        });
    }

    function openAddModal() { _openModal('add', null); }
    function openEditModal(p) { _openModal('edit', p); }
    function openOverrideModal(p) { _openModal('override', p); }

    // ====================================================================
    // Delete confirmation + in-use block
    // ====================================================================

    function openDeleteConfirm(provider) {
        const isOverride = provider.source === 'project-override';
        const title = isOverride ? 'Remove project override for "' + provider.id + '"?' : 'Delete "' + provider.id + '"?';
        const dialog = _renderModalShell(title, closeModal);
        if (!dialog) return;

        const body = _el('div');
        if (isOverride) {
            body.appendChild(_renderHint('Removes the project-level override. The admin catalogue entry remains; this provider will still resolve via the admin entry.'));
        } else {
            body.appendChild(_renderHint('Removes the preset and credentials at scope "' + provider.source + '". If any routes or oauth-buttons still reference this provider, the deletion is blocked and you\'ll see the list below.'));
        }
        const errPanel = _el('div');
        errPanel.hidden = true;
        body.appendChild(errPanel);
        dialog.appendChild(body);

        const actions = _el('div', { class: 'oauth-modal-actions' });
        actions.appendChild(_el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', text: 'Cancel', onclick: closeModal }));
        const confirmBtn = _el('button', {
            class: 'admin-btn admin-btn--primary oauth-modal-actions__btn--danger',
            type: 'button',
            text: isOverride ? 'Remove override' : 'Delete provider',
        });
        actions.appendChild(confirmBtn);
        dialog.appendChild(actions);

        confirmBtn.addEventListener('click', async function () {
            errPanel.hidden = true;
            errPanel.textContent = '';
            confirmBtn.disabled = true;
            try {
                const scope = provider.source === 'admin' ? 'admin' : 'project';
                const r = await api('deleteOAuthProvider', 'POST', { scope: scope, id: provider.id });
                if (!r || !r.ok) {
                    const data = (r && r.data && (r.data.data || r.data)) || {};
                    const code = (r && r.data && r.data.code) || '';
                    const msg  = (r && r.data && r.data.message) || 'Delete failed';
                    errPanel.hidden = false;
                    errPanel.appendChild(_renderInUsePanel(msg, code === 'oauth.provider.in_use' ? (data.usage || null) : null));
                    confirmBtn.disabled = false;
                    return;
                }
                closeModal();
                await refresh();
            } catch (e) {
                errPanel.hidden = false;
                errPanel.textContent = (e && e.message) || 'Network error';
                confirmBtn.disabled = false;
            }
        });
    }

    function _renderInUsePanel(msg, usage) {
        const wrap = _el('div', { class: 'oauth-in-use-panel' });
        wrap.appendChild(_el('p', { class: 'oauth-in-use-panel__msg', text: msg }));
        if (usage) {
            if (usage.routes && usage.routes.length > 0) {
                wrap.appendChild(_el('div', { class: 'oauth-in-use-panel__group-title', text: 'Resolvers (' + usage.routes.length + ')' }));
                const ul = _el('ul', { class: 'oauth-in-use-panel__list' });
                usage.routes.forEach(function (r) { ul.appendChild(_el('li', { text: '/' + r.route + ' (' + r.kind + ')' })); });
                wrap.appendChild(ul);
            }
            if (usage.buttons && usage.buttons.length > 0) {
                wrap.appendChild(_el('div', { class: 'oauth-in-use-panel__group-title', text: 'oauth-button on pages (' + usage.buttons.length + ')' }));
                const ul = _el('ul', { class: 'oauth-in-use-panel__list' });
                usage.buttons.forEach(function (b) {
                    ul.appendChild(_el('li', { text: '/' + b.page + ' (' + b.count + ' button' + (b.count === 1 ? '' : 's') + ')' }));
                });
                wrap.appendChild(ul);
            }
            wrap.appendChild(_el('p', { class: 'oauth-in-use-panel__hint', text: 'Remove these consumers (delete the routes / oauth-button elements) then try again.' }));
        }
        return wrap;
    }

    // ====================================================================
    // Init
    // ====================================================================

    function init() {
        const addBtn = document.getElementById('btn-add-oauth-provider');
        if (addBtn) addBtn.addEventListener('click', openAddModal);
        const refreshBtn = document.getElementById('btn-refresh-oauth-providers');
        if (refreshBtn) refreshBtn.addEventListener('click', refresh);
        refresh();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
