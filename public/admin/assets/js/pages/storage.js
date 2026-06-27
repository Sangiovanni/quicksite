/**
 * Storage registry admin page — list + add/edit modal + delete (beta.9).
 *
 * Calls listStorageItems / addStorageItem / editStorageItem /
 * deleteStorageItem via QuickSiteAdmin.apiRequest. The registry is the
 * GDPR / cookie-consent data layer (see BETA9_STORAGE_REGISTRY.md).
 *
 * Built with createElement + textContent + named _render* helpers per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing. Styling
 * lives in public/admin/assets/css/storage-admin.css.
 */
(function () {
    'use strict';

    // The author's description is authored in the project's default language
    // (the locked "one language now" decision; a later workflow fills the rest).
    var DEFAULT_LANG = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.defaultLang) || 'en';

    var RETENTION_PRESETS = ['session', '1d', '7d', '30d', '90d', '1y', 'persistent'];
    var SAMESITE_OPTIONS = ['Lax', 'Strict', 'None'];

    var state = {
        items: [],
        scopes: ['localStorage', 'sessionStorage', 'cookie'],
        categories: ['essential', 'functional', 'analytics', 'marketing'],
        filter: 'all',
    };

    // Scan / reconcile state. `data` holds the last scanStorageUsage payload
    // ({ buckets, counts }); `expanded` tracks the two collapsible sections.
    var scan = {
        data: null,
        loading: false,
        error: null,
        expanded: { ok: false, orphan: false },
    };

    function api(cmd, method, body) {
        var adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return adminApi.apiRequest(cmd, method, body);
    }

    function _el(tag, props, children) {
        var e = document.createElement(tag);
        if (props) {
            for (var k in props) {
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
        var l = _el('label', { class: 'admin-label' });
        l.appendChild(document.createTextNode(text));
        if (required) l.appendChild(_el('span', { class: 'admin-text-danger', text: ' *' }));
        return l;
    }
    function _renderHint(text) { return _el('p', { class: 'admin-hint', text: text }); }
    function _renderGroup(label, child, hint) {
        var g = _el('div', { class: 'admin-form-group' });
        if (label) g.appendChild(label);
        if (child) g.appendChild(child);
        if (hint) g.appendChild(hint);
        return g;
    }

    function _renderSvgIcon(pathD, size) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('width', String(size || 14));
        svg.setAttribute('height', String(size || 14));
        svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('d', pathD);
        svg.appendChild(p);
        return svg;
    }
    var ICON_EDIT = 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z';
    var ICON_TRASH = 'M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2';
    var ICON_X = 'M18 6L6 18M6 6l12 12';

    function _renderPill(text, kind) {
        return _el('span', { class: 'storage-pill storage-pill--' + kind, text: text });
    }

    function _descOf(item) {
        var d = item.description;
        if (!d || typeof d !== 'object') return '';
        if (d[DEFAULT_LANG]) return d[DEFAULT_LANG];
        var keys = Object.keys(d);
        return keys.length ? String(d[keys[0]] || '') : '';
    }

    function _renderActionBtn(iconD, label, onClick) {
        var b = _el('button', {
            class: 'admin-btn admin-btn--ghost storage-card__action',
            'aria-label': label, title: label, type: 'button', onclick: onClick,
        });
        b.appendChild(_renderSvgIcon(iconD, 14));
        return b;
    }

    function _renderCard(item) {
        var card = _el('div', { class: 'storage-card' });
        var row = _el('div', { class: 'storage-card__row' });

        var main = _el('div', { class: 'storage-card__main' });
        var pills = _el('div', { class: 'storage-card__pills' });
        pills.appendChild(_el('span', { class: 'storage-card__id', text: item.id }));
        pills.appendChild(_renderPill(item.scope || '—', 'scope'));
        pills.appendChild(_renderPill(item.category || '—', 'cat-' + (item.category || 'functional')));
        if (item.consentRequired) {
            pills.appendChild(_renderPill('needs consent', 'consent'));
        } else {
            pills.appendChild(_renderPill('no consent', 'essential'));
        }
        if (item.retention) {
            pills.appendChild(_renderPill('keeps: ' + item.retention, 'retention'));
        }
        main.appendChild(pills);

        var desc = _descOf(item);
        main.appendChild(_el('div', {
            class: 'storage-card__summary',
            text: desc || 'No description yet.',
        }));
        row.appendChild(main);

        var actions = _el('div', { class: 'storage-card__actions' });
        actions.appendChild(_renderActionBtn(ICON_EDIT, 'Edit', function () { openEditModal(item); }));
        actions.appendChild(_renderActionBtn(ICON_TRASH, 'Delete', function () { openDeleteConfirm(item); }));
        row.appendChild(actions);

        card.appendChild(row);
        return card;
    }

    function _renderFilterBar() {
        var bar = _el('div', { class: 'storage-filter-bar' });
        var keys = ['all'].concat(state.scopes);
        keys.forEach(function (key) {
            var count = key === 'all'
                ? state.items.length
                : state.items.filter(function (it) { return it.scope === key; }).length;
            var isActive = state.filter === key;
            var cls = 'admin-btn admin-btn--ghost storage-filter-bar__btn' + (isActive ? ' storage-filter-bar__btn--active' : '');
            bar.appendChild(_el('button', {
                class: cls, type: 'button',
                onclick: function () { state.filter = key; renderList(); },
                text: (key === 'all' ? 'All' : key) + ' · ' + count,
            }));
        });
        return bar;
    }

    function applyFilter(items, filter) {
        if (filter === 'all') return items;
        return items.filter(function (it) { return it.scope === filter; });
    }

    function _renderEmptyState(filter) {
        return _el('div', {
            class: 'storage-list__empty',
            text: filter === 'all'
                ? 'No storage keys declared yet. Click "Add storage key" to declare one.'
                : 'No storage keys with scope "' + filter + '".',
        });
    }

    function renderList() {
        var root = document.getElementById('storage-list');
        if (!root) return;
        root.textContent = '';
        root.className = 'storage-list';

        var filtered = applyFilter(state.items, state.filter);
        if (filtered.length === 0) {
            root.appendChild(_renderEmptyState(state.filter));
        } else {
            filtered.forEach(function (it) { root.appendChild(_renderCard(it)); });
        }

        var filterBar = document.getElementById('storage-filter-bar');
        if (filterBar) {
            filterBar.textContent = '';
            filterBar.appendChild(_renderFilterBar());
        }
    }

    async function refresh() {
        try {
            var r = await api('listStorageItems', 'POST');
            var payload = (r && r.data && (r.data.data || r.data)) || {};
            state.items = Array.isArray(payload.items) ? payload.items : [];
            if (Array.isArray(payload.scopes) && payload.scopes.length) state.scopes = payload.scopes;
            if (Array.isArray(payload.categories) && payload.categories.length) state.categories = payload.categories;
        } catch (e) {
            console.warn('[storage] load failed:', e);
            state.items = [];
        }
        renderList();
    }

    // ====================================================================
    // Modal — add / edit
    // ====================================================================

    function _renderModalShell(titleText, onClose) {
        var root = document.getElementById('storage-modal-root');
        if (!root) return null;
        root.textContent = '';

        var backdrop = _el('div', {
            class: 'storage-modal-backdrop',
            onclick: function (e) { if (e.target === backdrop) onClose(); },
        });
        var dialog = _el('div', { class: 'storage-modal-dialog' });

        var header = _el('div', { class: 'storage-modal-header' });
        header.appendChild(_el('h2', { class: 'storage-modal-header__title', text: titleText }));
        var closeBtn = _el('button', {
            class: 'admin-btn admin-btn--ghost storage-modal-header__close',
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
        var root = document.getElementById('storage-modal-root');
        if (root) root.textContent = '';
    }

    function _renderSelect(options, selected) {
        var sel = _el('select', { class: 'admin-input' });
        options.forEach(function (opt) {
            var o = _el('option', { value: opt, text: opt });
            if (opt === selected) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    function _openModal(mode, item, prefill) {
        var isEdit = (mode === 'edit');
        // Declare-from-scan: the key name is fixed by usage, only used when the
        // scope couldn't be inferred (one-click declare needs a scope).
        var isDeclare = (!isEdit && prefill && prefill.id);
        var titleText = isEdit ? 'Edit "' + item.id + '"' : (isDeclare ? 'Declare "' + prefill.id + '"' : 'Add storage key');
        var dialog = _renderModalShell(titleText, closeModal);
        if (!dialog) return;

        // id
        var idInput = _el('input', {
            type: 'text', class: 'admin-input', autocomplete: 'off',
            placeholder: 'key name, e.g. cartSession',
            value: isEdit ? item.id : (isDeclare ? prefill.id : ''),
        });
        if (isDeclare) idInput.readOnly = true;
        dialog.appendChild(_renderGroup(_renderLabel('Key name', true), idInput,
            _renderHint(isDeclare
                ? 'Found in the build by the scan — declaring it adds it to the registry. The value is provided by the visitor at runtime — never stored here.'
                : 'The storage key as written in code. No spaces. The value is provided by the visitor at runtime — never stored here.')));

        // scope
        var scopeSelect = _renderSelect(state.scopes, isEdit ? item.scope : ((isDeclare && prefill.scope) ? prefill.scope : 'localStorage'));
        dialog.appendChild(_renderGroup(_renderLabel('Scope', true), scopeSelect));

        // category
        var categorySelect = _renderSelect(state.categories, isEdit ? item.category : 'functional');
        dialog.appendChild(_renderGroup(_renderLabel('Category', true), categorySelect,
            _renderHint('Drives consent: "essential" needs no consent; the others do.')));

        // retention (preset + custom)
        var retentionPresetVal = isEdit && item.retention && RETENTION_PRESETS.indexOf(item.retention) === -1 ? '__custom__' : (isEdit ? (item.retention || 'session') : 'session');
        var retentionSelect = _renderSelect(RETENTION_PRESETS.concat(['__custom__']), retentionPresetVal);
        // relabel the custom option
        Array.prototype.forEach.call(retentionSelect.options, function (o) { if (o.value === '__custom__') o.textContent = 'Custom…'; });
        var retentionCustom = _el('input', {
            type: 'text', class: 'admin-input storage-retention-custom',
            placeholder: 'e.g. 6 months, 13 months, until-logout',
            value: (retentionPresetVal === '__custom__') ? (item.retention || '') : '',
        });
        retentionCustom.style.display = (retentionPresetVal === '__custom__') ? '' : 'none';
        retentionSelect.addEventListener('change', function () {
            retentionCustom.style.display = (retentionSelect.value === '__custom__') ? '' : 'none';
        });
        var retentionWrap = _el('div', { class: 'storage-retention' }, [retentionSelect, retentionCustom]);
        dialog.appendChild(_renderGroup(_renderLabel('Retention'), retentionWrap,
            _renderHint('How long the key lives — disclosed on the cookie/privacy page.')));

        // description (single language)
        var descInput = _el('input', {
            type: 'text', class: 'admin-input', autocomplete: 'off',
            placeholder: 'What this key is used for',
            value: isEdit ? _descOf(item) : '',
        });
        dialog.appendChild(_renderGroup(_renderLabel('Description (' + DEFAULT_LANG + ')'), descInput,
            _renderHint('Shown on the generated cookie/privacy page. Other languages are filled later.')));

        // cookie-only conditional fields
        var cookieWrap = _el('div', { class: 'storage-cookie-fields' });
        var domainInput = _el('input', { type: 'text', class: 'admin-input', placeholder: 'auto (or .example.com)', value: (isEdit && item.domain) || '' });
        var pathInput = _el('input', { type: 'text', class: 'admin-input', placeholder: '/', value: (isEdit && item.path) || '' });
        var sameSiteSelect = _renderSelect(SAMESITE_OPTIONS, (isEdit && item.sameSite) || 'Lax');
        var secureLabel = _el('label', { class: 'storage-cookie-secure' });
        var secureCb = _el('input', { type: 'checkbox' });
        if (isEdit && item.secure) secureCb.checked = true;
        secureLabel.appendChild(secureCb);
        secureLabel.appendChild(document.createTextNode(' Secure (HTTPS only)'));
        cookieWrap.appendChild(_renderGroup(_renderLabel('Cookie domain'), domainInput));
        cookieWrap.appendChild(_renderGroup(_renderLabel('Cookie path'), pathInput));
        cookieWrap.appendChild(_renderGroup(_renderLabel('SameSite'), sameSiteSelect));
        cookieWrap.appendChild(_renderGroup(null, secureLabel));
        function syncCookieFields() {
            cookieWrap.style.display = (scopeSelect.value === 'cookie') ? '' : 'none';
        }
        syncCookieFields();
        scopeSelect.addEventListener('change', syncCookieFields);
        dialog.appendChild(cookieWrap);

        // Actions
        var actionsRow = _el('div', { class: 'storage-modal-actions' });
        var errBox = _el('div', { class: 'storage-modal-actions__error' });
        actionsRow.appendChild(errBox);
        actionsRow.appendChild(_el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', text: 'Cancel', onclick: closeModal }));
        var saveBtn = _el('button', { class: 'admin-btn admin-btn--primary', type: 'button', text: isEdit ? 'Save changes' : 'Add storage key' });
        actionsRow.appendChild(saveBtn);
        dialog.appendChild(actionsRow);

        saveBtn.addEventListener('click', async function () {
            errBox.textContent = '';
            var id = idInput.value.trim();
            if (id === '') { errBox.textContent = 'Key name is required.'; return; }
            if (/\s/.test(id)) { errBox.textContent = 'Key name cannot contain spaces.'; return; }

            var body = {
                id: id,
                scope: scopeSelect.value,
                category: categorySelect.value,
            };
            var retention = retentionSelect.value === '__custom__' ? retentionCustom.value.trim() : retentionSelect.value;
            if (retention) body.retention = retention;
            var descText = descInput.value.trim();
            if (descText) { body.description = {}; body.description[DEFAULT_LANG] = descText; }
            if (scopeSelect.value === 'cookie') {
                if (domainInput.value.trim()) body.domain = domainInput.value.trim();
                if (pathInput.value.trim()) body.path = pathInput.value.trim();
                body.secure = !!secureCb.checked;
                body.sameSite = sameSiteSelect.value;
            }
            if (isEdit) {
                body.id = item.id;
                if (id !== item.id) body.newId = id;
            }

            saveBtn.disabled = true;
            try {
                var r = await api(isEdit ? 'editStorageItem' : 'addStorageItem', 'POST', body);
                if (!r || !r.ok) {
                    errBox.textContent = (r && r.data && r.data.message) || 'Save failed';
                    saveBtn.disabled = false;
                    return;
                }
                closeModal();
                await refresh();
                if (scan.data) await runScan();
            } catch (e) {
                errBox.textContent = (e && e.message) || 'Network error';
                saveBtn.disabled = false;
            }
        });
    }

    function openAddModal() { _openModal('add', null); }
    function openEditModal(item) { _openModal('edit', item); }

    // ====================================================================
    // Delete confirmation
    // ====================================================================

    function openDeleteConfirm(item) {
        var dialog = _renderModalShell('Delete "' + item.id + '"?', closeModal);
        if (!dialog) return;

        var body = _el('div');
        body.appendChild(_renderHint('Removes this key from the registry. It does not touch any code that reads or writes the key — the scan slice will flag a now-undeclared key if it is still referenced.'));
        var errPanel = _el('div', { class: 'storage-modal-actions__error' });
        errPanel.hidden = true;
        body.appendChild(errPanel);
        dialog.appendChild(body);

        var actions = _el('div', { class: 'storage-modal-actions' });
        actions.appendChild(_el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', text: 'Cancel', onclick: closeModal }));
        var confirmBtn = _el('button', { class: 'admin-btn admin-btn--primary storage-modal-actions__btn--danger', type: 'button', text: 'Delete key' });
        actions.appendChild(confirmBtn);
        dialog.appendChild(actions);

        confirmBtn.addEventListener('click', async function () {
            errPanel.hidden = true;
            errPanel.textContent = '';
            confirmBtn.disabled = true;
            try {
                var r = await api('deleteStorageItem', 'POST', { id: item.id });
                if (!r || !r.ok) {
                    errPanel.hidden = false;
                    errPanel.textContent = (r && r.data && r.data.message) || 'Delete failed';
                    confirmBtn.disabled = false;
                    return;
                }
                closeModal();
                await refresh();
                if (scan.data) await runScan();
            } catch (e) {
                errPanel.hidden = false;
                errPanel.textContent = (e && e.message) || 'Network error';
                confirmBtn.disabled = false;
            }
        });
    }

    // ====================================================================
    // Scan / reconcile
    // ====================================================================

    // A labelled group of location chips ("writes" / "reads" / "clears" + the
    // page/component/menu labels the scan engine returns). Null when empty.
    function _renderRefChips(label, list) {
        if (!list || !list.length) return null;
        var wrap = _el('span', { class: 'storage-refs' });
        wrap.appendChild(_el('span', { class: 'storage-refs__label', text: label }));
        list.forEach(function (loc) {
            wrap.appendChild(_el('span', { class: 'storage-loc', text: loc }));
        });
        return wrap;
    }

    // One row in a scan bucket. `opts.declare` adds the one-click Declare button
    // (incomplete bucket only).
    function _renderScanRow(row, opts) {
        var r = _el('div', { class: 'storage-scan-row' });

        var mainCol = _el('div', { class: 'storage-scan-row__main' });
        var head = _el('div', { class: 'storage-scan-row__head' });
        head.appendChild(_el('span', { class: 'storage-card__id', text: row.id }));
        var scope = row.inferredScope || row.scope;
        if (scope) head.appendChild(_renderPill(scope, 'scope'));
        if (row.category) head.appendChild(_renderPill(row.category, 'cat-' + row.category));
        mainCol.appendChild(head);

        var refs = _el('div', { class: 'storage-scan-row__refs' });
        var w = _renderRefChips('writes', row.writers);
        var c = _renderRefChips('clears', row.clearers);
        var rd = _renderRefChips('reads', row.readers);
        if (w) refs.appendChild(w);
        if (c) refs.appendChild(c);
        if (rd) refs.appendChild(rd);
        if (refs.childNodes.length) mainCol.appendChild(refs);
        r.appendChild(mainCol);

        if (opts && opts.declare) {
            var act = _el('div', { class: 'storage-scan-row__action' });
            var btn = _el('button', {
                class: 'admin-btn admin-btn--primary storage-scan-row__declare',
                type: 'button', text: 'Declare',
            });
            btn.addEventListener('click', function () { declareIncomplete(row, btn); });
            act.appendChild(btn);
            r.appendChild(act);
        }
        return r;
    }

    function _renderScanSecHead(title, count, subtitle) {
        var head = _el('div', { class: 'storage-scan-sec__head' });
        head.appendChild(_el('h3', { class: 'storage-scan-sec__title', text: title + ' (' + count + ')' }));
        if (subtitle) head.appendChild(_el('p', { class: 'storage-scan-sec__sub', text: subtitle }));
        return head;
    }

    // Incomplete = used-but-undeclared (the GDPR gap). Most prominent; always open.
    function _renderIncompleteSection(list) {
        var sec = _el('section', { class: 'storage-scan-sec storage-scan-sec--incomplete' });
        sec.appendChild(_renderScanSecHead('Undeclared keys', list.length,
            'Used in the build but not in the registry — the GDPR gap. Declare each to add it.'));
        if (!list.length) {
            sec.appendChild(_el('div', { class: 'storage-scan__ok-note', text: 'All used keys are declared. ✓' }));
        } else {
            list.forEach(function (row) { sec.appendChild(_renderScanRow(row, { declare: true })); });
        }
        return sec;
    }

    // Dangling reads = read by a binding but never written. Not a GDPR item; a
    // likely-leftover flag. No Declare.
    function _renderDanglingSection(list) {
        var sec = _el('section', { class: 'storage-scan-sec storage-scan-sec--dangling' });
        sec.appendChild(_renderScanSecHead('Dangling reads', list.length,
            'Read by a binding but never written anywhere — likely a leftover after the writer was deleted. Review these.'));
        list.forEach(function (row) { sec.appendChild(_renderScanRow(row, null)); });
        return sec;
    }

    // OK / Orphan — collapsible, ignorable-by-default sections.
    function _renderCollapsibleSection(key, title, list, subtitle) {
        var sec = _el('section', { class: 'storage-scan-sec storage-scan-sec--' + key });
        var toggle = _el('button', { class: 'storage-scan-sec__toggle', type: 'button' });
        var open = !!scan.expanded[key];
        toggle.appendChild(_el('span', { class: 'storage-scan-sec__chevron', text: open ? '▾' : '▸' }));
        toggle.appendChild(_el('span', { text: title + ' (' + list.length + ')' }));
        toggle.addEventListener('click', function () {
            scan.expanded[key] = !scan.expanded[key];
            renderScanPanel();
        });
        sec.appendChild(toggle);
        if (open) {
            if (subtitle) sec.appendChild(_el('p', { class: 'storage-scan-sec__sub', text: subtitle }));
            if (!list.length) {
                sec.appendChild(_el('div', { class: 'storage-scan__empty-note', text: 'None.' }));
            } else {
                list.forEach(function (row) { sec.appendChild(_renderScanRow(row, null)); });
            }
        }
        return sec;
    }

    function _renderScanHeader() {
        var h = _el('div', { class: 'storage-scan__header' });
        h.appendChild(_el('h2', { class: 'storage-scan__title', text: 'Scan results' }));
        var dismiss = _el('button', {
            class: 'admin-btn admin-btn--ghost storage-scan__dismiss',
            type: 'button', 'aria-label': 'Dismiss scan results',
            onclick: dismissScan,
        });
        dismiss.appendChild(_renderSvgIcon(ICON_X, 16));
        h.appendChild(dismiss);
        return h;
    }

    function dismissScan() {
        scan.data = null;
        scan.error = null;
        var root = document.getElementById('storage-scan-panel');
        if (root) { root.textContent = ''; root.hidden = true; }
    }

    function renderScanPanel() {
        var root = document.getElementById('storage-scan-panel');
        if (!root) return;
        root.textContent = '';

        if (!scan.loading && !scan.error && !scan.data) {
            root.hidden = true;
            return;
        }
        root.hidden = false;
        root.appendChild(_renderScanHeader());

        if (scan.loading) {
            root.appendChild(_el('div', { class: 'storage-scan__loading', text: 'Scanning the build…' }));
            return;
        }
        if (scan.error) {
            root.appendChild(_el('div', { class: 'storage-scan__error', text: scan.error }));
            return;
        }

        var b = scan.data.buckets || {};
        root.appendChild(_renderIncompleteSection(b.incomplete || []));
        if ((b.dangling_read || []).length) {
            root.appendChild(_renderDanglingSection(b.dangling_read));
        }
        root.appendChild(_renderCollapsibleSection('ok', 'OK', b.ok || [],
            'Declared and written in the build — nothing to do.'));
        root.appendChild(_renderCollapsibleSection('orphan', 'Orphans', b.orphan || [],
            'Declared but not referenced in the build. Safe to keep — may be parked.'));
    }

    async function runScan() {
        scan.loading = true;
        scan.error = null;
        renderScanPanel();
        try {
            var r = await api('scanStorageUsage', 'POST');
            var payload = (r && r.data && (r.data.data || r.data)) || {};
            scan.data = (payload && payload.buckets)
                ? payload
                : { buckets: { ok: [], incomplete: [], dangling_read: [], orphan: [] }, counts: {} };
        } catch (e) {
            console.warn('[storage] scan failed:', e);
            scan.error = (e && e.message) || 'Scan failed';
        } finally {
            scan.loading = false;
        }
        renderScanPanel();
    }

    // One-click Declare for an incomplete key (safe defaults: inferred scope +
    // category 'functional'; refine later on the list). When the scope couldn't
    // be inferred, fall back to the prefilled add modal since scope is required.
    async function declareIncomplete(row, btn) {
        if (!row.inferredScope) {
            _openModal('add', null, { id: row.id });
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Declaring…'; }
        try {
            var r = await api('addStorageItem', 'POST', {
                id: row.id,
                scope: row.inferredScope,
                category: 'functional',
            });
            if (!r || !r.ok) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Declare';
                    var msg = (r && r.data && r.data.message) || 'Declare failed';
                    var err = _el('span', { class: 'storage-scan-row__err', text: msg });
                    if (btn.parentNode) btn.parentNode.appendChild(err);
                }
                return;
            }
            await refresh();
            await runScan();
        } catch (e) {
            console.warn('[storage] declare failed:', e);
            if (btn) { btn.disabled = false; btn.textContent = 'Declare'; }
        }
    }

    // ====================================================================
    // Init
    // ====================================================================

    function init() {
        var addBtn = document.getElementById('btn-add-storage-item');
        if (addBtn) addBtn.addEventListener('click', openAddModal);
        var refreshBtn = document.getElementById('btn-refresh-storage');
        if (refreshBtn) refreshBtn.addEventListener('click', refresh);
        var scanBtn = document.getElementById('btn-scan-storage');
        if (scanBtn) scanBtn.addEventListener('click', runScan);
        refresh();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
