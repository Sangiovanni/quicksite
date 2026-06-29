/**
 * Privacy helper admin page (beta.9). Read-only view of getPrivacyStatus:
 * coverage summary, collected data, host classification, endpoint atoms, and the
 * OAuth/magic-link auto-seed. CRUD + mapping + host classification land in later
 * slices.
 *
 * Built with createElement + textContent + named _render* helpers per the
 * CLAUDE.md HTML-in-JS hygiene rule (no innerHTML string-glueing). Styling lives
 * in public/admin/assets/css/privacy-admin.css. Mirrors storage.js.
 */
(function () {
    'use strict';

    var DEFAULT_LANG = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.defaultLang) || 'en';

    var state = {
        status: null,
        descLang: DEFAULT_LANG,
        languages: [DEFAULT_LANG],
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

    function _pill(text, kind) {
        return _el('span', { class: 'privacy-pill privacy-pill--' + kind, text: text });
    }

    function _section(title, sub, body) {
        var s = _el('section', { class: 'privacy-section' });
        var head = _el('div', { class: 'privacy-section__head' });
        head.appendChild(_el('h2', { class: 'privacy-section__title', text: title }));
        if (sub) head.appendChild(_el('span', { class: 'privacy-section__count', text: sub }));
        s.appendChild(head);
        if (body) s.appendChild(body);
        return s;
    }

    // ---- coverage summary -------------------------------------------------

    function _renderCoverage(cov) {
        var box = _el('div', { class: 'privacy-coverage' + (cov.complete ? ' privacy-coverage--ok' : '') });
        var items = [
            { label: 'fields mapped', value: cov.mappedAtoms + ' / ' + cov.totalAtoms, warn: cov.unmappedAtoms > 0 },
            { label: 'unverifiable endpoints', value: String((cov.undeclaredEndpoints || []).length), warn: (cov.undeclaredEndpoints || []).length > 0 },
            { label: 'unclassified hosts', value: String(cov.unclassifiedHosts), warn: cov.unclassifiedHosts > 0 },
        ];
        items.forEach(function (it) {
            var cell = _el('div', { class: 'privacy-coverage__cell' + (it.warn ? ' privacy-coverage__cell--warn' : '') });
            cell.appendChild(_el('span', { class: 'privacy-coverage__value', text: it.value }));
            cell.appendChild(_el('span', { class: 'privacy-coverage__label', text: it.label }));
            box.appendChild(cell);
        });
        var status = _el('div', { class: 'privacy-coverage__status' });
        status.appendChild(_pill(cov.complete ? 'Complete' : 'Incomplete', cov.complete ? 'ok' : 'warn'));
        box.appendChild(status);
        return box;
    }

    // ---- collected data ---------------------------------------------------

    function _slug(s) {
        return String(s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }
    function _svgIcon(pathD) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2'); svg.setAttribute('width', '14'); svg.setAttribute('height', '14');
        svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path'); p.setAttribute('d', pathD); svg.appendChild(p);
        return svg;
    }
    var ICON_EDIT = 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z';
    var ICON_TRASH = 'M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2';

    function _renderSelect(options, selected) {
        var sel = _el('select', { class: 'admin-input' });
        options.forEach(function (opt) {
            var o = _el('option', { value: opt, text: opt });
            if (opt === selected) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    function _iconBtn(pathD, label, onClick) {
        var b = _el('button', { class: 'admin-btn admin-btn--ghost privacy-collected__action', 'aria-label': label, title: label, type: 'button', onclick: onClick });
        b.appendChild(_svgIcon(pathD));
        return b;
    }

    function _renderCollectedSection(list) {
        var s = _el('section', { class: 'privacy-section' });
        var head = _el('div', { class: 'privacy-section__head' });
        head.appendChild(_el('h2', { class: 'privacy-section__title', text: 'Data collected' }));
        head.appendChild(_el('span', { class: 'privacy-section__count', text: String(list.length) }));

        var actions = _el('div', { class: 'privacy-section__actions' });
        if (state.languages.length > 1) {
            actions.appendChild(_el('span', { class: 'privacy-desclang__label', text: 'Language:' }));
            var langSel = _renderSelect(state.languages, state.descLang);
            langSel.classList.add('privacy-desclang__select');
            langSel.addEventListener('change', function () { onDescLangChange(langSel); });
            actions.appendChild(langSel);
        }
        actions.appendChild(_el('button', { class: 'admin-btn admin-btn--primary privacy-add-btn', type: 'button', text: '+ Add data collected', onclick: function () { openDatumModal(null); } }));
        head.appendChild(actions);
        s.appendChild(head);

        if (!list.length) {
            s.appendChild(_el('p', { class: 'privacy-empty', text: 'No "data collected" entries yet. Add one, then map the fields below to it.' }));
            return s;
        }
        var wrap = _el('div', { class: 'privacy-collected' });
        list.forEach(function (d) {
            var card = _el('div', { class: 'privacy-collected__card' });
            var body = _el('div', { class: 'privacy-collected__body' });
            body.appendChild(_el('span', { class: 'privacy-collected__label', text: d.label || d.id }));
            body.appendChild(_el('span', { class: 'privacy-collected__purpose', text: d.purpose || '—' }));
            card.appendChild(body);
            var acts = _el('div', { class: 'privacy-collected__actions' });
            acts.appendChild(_iconBtn(ICON_EDIT, 'Edit', function () { openDatumModal(d); }));
            acts.appendChild(_iconBtn(ICON_TRASH, 'Delete', function () { onDeleteDatum(d); }));
            card.appendChild(acts);
            wrap.appendChild(card);
        });
        s.appendChild(wrap);
        return s;
    }

    // ---- collected-data modal + actions -----------------------------------

    function closeModal() {
        var root = document.getElementById('privacy-modal-root');
        if (root) root.textContent = '';
    }

    function openDatumModal(datum, opts) {
        opts = opts || {};
        var isEdit = !!datum;
        var root = document.getElementById('privacy-modal-root');
        if (!root) return;
        root.textContent = '';

        var backdrop = _el('div', { class: 'privacy-modal-backdrop', onclick: function (e) { if (e.target === backdrop) closeModal(); } });
        var dialog = _el('div', { class: 'privacy-modal-dialog' });
        dialog.appendChild(_el('h2', { class: 'privacy-modal__title', text: isEdit ? 'Edit data collected' : 'Add data collected' }));

        var labelInput = _el('input', { type: 'text', class: 'admin-input', autocomplete: 'off', placeholder: 'e.g. Email address', value: isEdit ? (datum.label || '') : (opts.prefillLabel || '') });
        dialog.appendChild(_el('label', { class: 'admin-label', text: 'Label (' + state.descLang + ')' }));
        dialog.appendChild(labelInput);

        var purposeInput = _el('input', { type: 'text', class: 'admin-input', autocomplete: 'off', placeholder: 'What you do with it', value: isEdit ? (datum.purpose || '') : '' });
        dialog.appendChild(_el('label', { class: 'admin-label', text: 'Purpose (' + state.descLang + ')' }));
        dialog.appendChild(purposeInput);
        dialog.appendChild(_el('p', { class: 'admin-hint', text: isEdit ? 'Editing is live — no regenerate needed.' : 'A stable id is derived from the label; the label stays editable afterwards.' }));

        var errBox = _el('div', { class: 'privacy-modal__error', hidden: 'hidden' });
        dialog.appendChild(errBox);

        var actions = _el('div', { class: 'privacy-modal__actions' });
        actions.appendChild(_el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', text: 'Cancel', onclick: closeModal }));
        var saveBtn = _el('button', { class: 'admin-btn admin-btn--primary', type: 'button', text: isEdit ? 'Save' : 'Add' });
        actions.appendChild(saveBtn);
        dialog.appendChild(actions);

        saveBtn.addEventListener('click', async function () {
            errBox.hidden = true; errBox.textContent = '';
            var label = labelInput.value.trim();
            if (!label) { errBox.hidden = false; errBox.textContent = 'A label is required.'; return; }
            var id = isEdit ? datum.id : _slug(label);
            if (!id) { errBox.hidden = false; errBox.textContent = 'Could not derive an id from that label — use letters or numbers.'; return; }
            saveBtn.disabled = true;
            try {
                var r = await api('setCollectedDatum', 'POST', { id: id, label: label, purpose: purposeInput.value.trim() });
                if (!r || !r.ok) {
                    errBox.hidden = false; errBox.textContent = (r && r.data && r.data.message) || 'Save failed'; saveBtn.disabled = false; return;
                }
                closeModal();
                if (typeof opts.afterSave === 'function') { await opts.afterSave(id); }
                else { await refresh(); }
            } catch (e) {
                errBox.hidden = false; errBox.textContent = (e && e.message) || 'Network error'; saveBtn.disabled = false;
            }
        });

        backdrop.appendChild(dialog);
        root.appendChild(backdrop);
        labelInput.focus();
    }

    async function onDeleteDatum(d) {
        if (!window.confirm('Delete "' + (d.label || d.id) + '"? Any field mapped to it becomes unset.')) return;
        try {
            var r = await api('deleteCollectedDatum', 'POST', { id: d.id });
            if (!r || !r.ok) { window.alert((r && r.data && r.data.message) || 'Delete failed'); return; }
            await refresh();
        } catch (e) { window.alert((e && e.message) || 'Network error'); }
    }

    async function onDescLangChange(sel) {
        var target = sel.value, current = state.descLang;
        if (target === current) return;
        sel.disabled = true;
        try {
            var preview = await api('setPrivacyDescLang', 'POST', { lang: target });
            var pdata = (preview && preview.data && (preview.data.data || preview.data)) || {};
            var moved = pdata.moved || 0, overwrites = pdata.overwrites || 0;
            var msg = 'Change description language from "' + current + '" to "' + target + '"?\n\nThis moves ' + moved + ' value' + (moved === 1 ? '' : 's') + ' into "' + target + '".';
            if (overwrites > 0) msg += '\n\n⚠ ' + overwrites + ' existing "' + target + '" value' + (overwrites === 1 ? '' : 's') + ' will be OVERWRITTEN.';
            if (!window.confirm(msg)) { sel.value = current; sel.disabled = false; return; }
            var done = await api('setPrivacyDescLang', 'POST', { lang: target, confirm: true });
            if (!done || !done.ok) { sel.value = current; sel.disabled = false; window.alert((done && done.data && done.data.message) || 'Failed to change language'); return; }
            await refresh();
        } catch (e) { sel.value = current; sel.disabled = false; console.warn('[privacy] descLang change failed:', e); }
    }

    // ---- atom -> datum mapping (click an atom chip) -----------------------

    var _atomMenuEl = null;
    function _closeAtomMenu() {
        if (_atomMenuEl && _atomMenuEl.parentNode) _atomMenuEl.parentNode.removeChild(_atomMenuEl);
        _atomMenuEl = null;
        document.removeEventListener('mousedown', _onMenuOutside, true);
    }
    function _onMenuOutside(e) {
        if (_atomMenuEl && !_atomMenuEl.contains(e.target)) _closeAtomMenu();
    }

    function openAtomMenu(endpoint, field, currentDatum, anchorEl) {
        _closeAtomMenu();
        var menu = _el('div', { class: 'privacy-atom-menu' });
        menu.appendChild(_el('div', { class: 'privacy-atom-menu__head', text: 'Map "' + field + '" to:' }));
        var listing = _el('div', { class: 'privacy-atom-menu__list' });
        var data = (state.status && state.status.collectedData) || [];
        if (!data.length) {
            listing.appendChild(_el('div', { class: 'privacy-atom-menu__empty', text: 'No data collected yet.' }));
        }
        data.forEach(function (d) {
            listing.appendChild(_el('button', {
                type: 'button',
                class: 'privacy-atom-menu__item' + (d.id === currentDatum ? ' privacy-atom-menu__item--active' : ''),
                text: d.label || d.id,
                onclick: function () { _closeAtomMenu(); setMapping(endpoint, field, d.id); },
            }));
        });
        menu.appendChild(listing);
        if (currentDatum) {
            menu.appendChild(_el('button', { type: 'button', class: 'privacy-atom-menu__action', text: 'Unset', onclick: function () { _closeAtomMenu(); setMapping(endpoint, field, null); } }));
        }
        menu.appendChild(_el('button', {
            type: 'button', class: 'privacy-atom-menu__action privacy-atom-menu__action--new',
            text: '+ New data collected from this field',
            onclick: function () { _closeAtomMenu(); openDatumModal(null, { prefillLabel: field, afterSave: function (id) { return setMapping(endpoint, field, id); } }); },
        }));

        document.body.appendChild(menu);
        var rect = anchorEl.getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 4) + 'px';
        menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - menu.offsetWidth - 12)) + 'px';
        _atomMenuEl = menu;
        setTimeout(function () { document.addEventListener('mousedown', _onMenuOutside, true); }, 0);
    }

    function setMapping(endpoint, field, datumId) {
        return api('setPrivacyMapping', 'POST', { endpoint: endpoint, field: field, datum: datumId || '__unset__' })
            .then(function (r) {
                if (!r || !r.ok) { window.alert((r && r.data && r.data.message) || 'Mapping failed'); return; }
                return refresh();
            })
            .catch(function (e) { window.alert((e && e.message) || 'Network error'); });
    }

    // ---- hosts ------------------------------------------------------------

    function _renderHosts(hosts) {
        if (!hosts.length) {
            return _el('p', { class: 'privacy-empty', text: 'No API hosts in the registry.' });
        }
        var wrap = _el('div', { class: 'privacy-hosts' });
        hosts.forEach(function (h) {
            var row = _el('div', { class: 'privacy-host' });
            var main = _el('div', { class: 'privacy-host__main' });
            main.appendChild(_el('code', { class: 'privacy-host__url', text: h.baseUrl }));
            var kind = h.kind === 'third-party' ? _pill('third party' + (h.name ? ': ' + h.name : ''), 'third')
                : h.kind === 'self' ? _pill('your server', 'self')
                : _pill('unclassified', 'warn');
            main.appendChild(kind);
            row.appendChild(main);
            row.appendChild(_el('span', { class: 'privacy-host__apis', text: (h.apiIds || []).join(', ') }));
            wrap.appendChild(row);
        });
        return wrap;
    }

    // ---- endpoints / atoms ------------------------------------------------

    function _renderEndpoints(endpoints) {
        if (!endpoints.length) {
            return _el('p', { class: 'privacy-empty', text: 'No endpoints in the API registry.' });
        }
        var wrap = _el('div', { class: 'privacy-endpoints' });
        endpoints.forEach(function (ep) {
            var card = _el('div', { class: 'privacy-endpoint' });
            var head = _el('div', { class: 'privacy-endpoint__head' });
            head.appendChild(_pill(ep.method, 'method'));
            head.appendChild(_el('code', { class: 'privacy-endpoint__key', text: ep.key }));
            if (ep.undeclaredBody) head.appendChild(_pill('no declared body', 'warn'));
            card.appendChild(head);

            if (ep.fields && ep.fields.length) {
                var atoms = _el('div', { class: 'privacy-endpoint__atoms' });
                ep.fields.forEach(function (f) {
                    var mapped = f.datum != null;
                    var chip = _el('button', {
                        type: 'button',
                        class: 'privacy-atom' + (mapped ? ' privacy-atom--mapped' : ' privacy-atom--unset'),
                        title: 'Click to map',
                    });
                    chip.appendChild(_el('span', { class: 'privacy-atom__field', text: f.field }));
                    chip.appendChild(_el('span', { class: 'privacy-atom__datum', text: mapped ? '→ ' + f.datum : 'unset' }));
                    chip.addEventListener('click', function () { openAtomMenu(ep.key, f.field, f.datum, chip); });
                    atoms.appendChild(chip);
                });
                card.appendChild(atoms);
            } else if (!ep.undeclaredBody) {
                // (undeclared-body endpoints already carry the head pill)
                card.appendChild(_el('span', { class: 'privacy-endpoint__nofields', text: 'no fields sent' }));
            }
            wrap.appendChild(card);
        });
        return wrap;
    }

    // ---- auth seed --------------------------------------------------------

    function _renderAuthSeed(seed) {
        if (!seed || (!seed.oauth.wired && !seed.magicLink.wired)) {
            return _el('p', { class: 'privacy-empty', text: 'No sign-in flows detected (OAuth / magic-link). Nothing to auto-suggest.' });
        }
        var wrap = _el('div', { class: 'privacy-authseed' });
        if (seed.oauth.wired) {
            var o = _el('div', { class: 'privacy-authseed__row' });
            o.appendChild(_el('span', { class: 'privacy-authseed__name', text: 'OAuth sign-in' }));
            o.appendChild(_el('span', { class: 'privacy-authseed__detail', text: 'providers: ' + seed.oauth.providers.map(function (p) { return p.name; }).join(', ') + ' · collects: ' + seed.oauth.collectedSuggestions.join(', ') }));
            wrap.appendChild(o);
        }
        if (seed.magicLink.wired) {
            var m = _el('div', { class: 'privacy-authseed__row' });
            m.appendChild(_el('span', { class: 'privacy-authseed__name', text: 'Magic-link sign-in' }));
            m.appendChild(_el('span', { class: 'privacy-authseed__detail', text: 'collects: ' + seed.magicLink.collectedSuggestions.join(', ') }));
            wrap.appendChild(m);
        }
        return wrap;
    }

    // ---- cookie cross-link note ------------------------------------------

    function _renderCookieNote(s) {
        var note = _el('p', { class: 'admin-hint privacy-cookie-note' });
        if (s.cookieSection === 'omit') {
            note.textContent = 'Cookie section: omitted (set by you). The privacy page will not mention cookies.';
        } else if (s.cookie && s.cookie.policyRoute && s.cookie.policyRouteExists) {
            note.textContent = 'Cookie policy detected at ' + s.cookie.policyRoute + ' — the privacy page will link to it.';
        } else {
            note.textContent = 'No cookie-policy page yet. You may want to generate one in Storage, or mark cookies as not applicable (a later slice).';
        }
        return note;
    }

    function renderHint() {
        var host = document.getElementById('privacy-desclang-hint');
        if (!host) return;
        if (state.languages.length <= 1) { host.hidden = true; host.textContent = ''; return; }
        host.hidden = false;
        host.textContent = 'Collected-data descriptions are authored in your description language ("' + state.descLang
            + '"). Other languages are translated with the visual-editor language tool.';
    }

    function render() {
        var root = document.getElementById('privacy-root');
        if (!root) return;
        root.textContent = '';
        root.className = 'privacy-root';
        var s = state.status;
        if (!s) {
            root.appendChild(_el('p', { class: 'privacy-empty', text: 'Could not load privacy status.' }));
            return;
        }
        root.appendChild(_renderCoverage(s.coverage));
        root.appendChild(_renderCookieNote(s));
        root.appendChild(_renderCollectedSection(s.collectedData));
        root.appendChild(_section('API hosts', String(s.hosts.length), _renderHosts(s.hosts)));
        root.appendChild(_section('Detected sign-in', null, _renderAuthSeed(s.authSeed)));
        root.appendChild(_section('Endpoints sending data', String(s.endpoints.length), _renderEndpoints(s.endpoints)));
    }

    async function refresh() {
        try {
            var r = await api('getPrivacyStatus', 'POST');
            var payload = (r && r.data && (r.data.data || r.data)) || null;
            state.status = payload;
            if (payload) {
                if (typeof payload.descLang === 'string' && payload.descLang) state.descLang = payload.descLang;
                if (Array.isArray(payload.languages) && payload.languages.length) state.languages = payload.languages;
            }
        } catch (e) {
            console.warn('[privacy] load failed:', e);
            state.status = null;
        }
        render();
        renderHint();
    }

    function init() {
        refresh();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
