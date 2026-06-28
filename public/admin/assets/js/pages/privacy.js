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

    function _renderCollected(list) {
        if (!list.length) {
            return _el('p', { class: 'privacy-empty', text: 'No "data collected" entries yet. (Authoring lands in the next slice.)' });
        }
        var wrap = _el('div', { class: 'privacy-collected' });
        list.forEach(function (d) {
            var card = _el('div', { class: 'privacy-collected__card' });
            card.appendChild(_el('span', { class: 'privacy-collected__label', text: d.label || d.id }));
            card.appendChild(_el('span', { class: 'privacy-collected__purpose', text: d.purpose || '—' }));
            wrap.appendChild(card);
        });
        return wrap;
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
                    var chip = _el('span', { class: 'privacy-atom' + (mapped ? ' privacy-atom--mapped' : ' privacy-atom--unset') });
                    chip.appendChild(_el('span', { class: 'privacy-atom__field', text: f.field }));
                    chip.appendChild(_el('span', { class: 'privacy-atom__datum', text: mapped ? '→ ' + f.datum : 'unset' }));
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
        root.appendChild(_section('Data collected', String(s.collectedData.length), _renderCollected(s.collectedData)));
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
