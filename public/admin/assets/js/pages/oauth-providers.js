/**
 * OAuth providers admin page — the providers the installation offers, and this project's keys.
 *
 * Calls listOAuthProviders / setOAuthCredentials via QuickSiteAdmin.apiRequest.
 *
 * The providers come from a file the installation's operator edits; this page only lists them.
 * For each one it shows a how-to (the provider's console, the callback addresses to register,
 * a note on the provider's own rules) and the project's two key sets:
 *   preview  the keys the sign-in uses on this installation;
 *   build    the keys a build carries to the deployed site — present in the list answer only for
 *            the project's owner and admin, so only they see this form.
 * A secret is never shown, only whether one is stored.
 *
 * Styling lives in public/admin/assets/css/oauth-admin.css; strings come from the page
 * (window.QS_OAUTH_I18N). Built with createElement + textContent + named _render* helpers.
 */
(function () {
    'use strict';

    const T = window.QS_OAUTH_I18N || {};
    const state = { providers: [], canManage: false, failed: false };

    const ICON_CHECK = 'M5 12l5 5L20 7';
    const ICON_WARN  = 'M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z';
    const ICON_COPY  = 'M9 9h11v11H9zM5 15H4V4h11v1';
    const ICON_LINK  = 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3';

    function api(cmd, method, body) {
        const adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return adminApi.apiRequest(cmd, method, body);
    }

    /** A page string with its {name} placeholders filled. */
    function t(key, vars) {
        let s = typeof T[key] === 'string' ? T[key] : '';
        Object.keys(vars || {}).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
        return s;
    }

    function _renderPill(text, kind, iconD) {
        const p = QSDom.el('span', { class: 'oauth-pill oauth-pill--' + kind });
        if (iconD) p.appendChild(QSDom.svgIcon(iconD, 11));
        p.appendChild(document.createTextNode(text));
        return p;
    }

    function _renderKeyPill(label, set) {
        const ok = !!(set && set.secret_set);
        return _renderPill(label + ' · ' + (ok ? t('statusSet') : t('statusMissing')), ok ? 'success' : 'danger', ok ? ICON_CHECK : ICON_WARN);
    }

    function _renderRoutesPill(setup) {
        if (setup.fully_set_up) return _renderPill(t('routesReady'), 'success', ICON_CHECK);
        if (setup.start_route_exists || setup.callback_route_exists) return _renderPill(t('routesPartial'), 'warning');
        return _renderPill(t('routesNone'), 'neutral');
    }

    function _renderHead(p) {
        const pills = QSDom.el('div', { class: 'oauth-provider-card__pills' }, [
            QSDom.el('span', { class: 'oauth-provider-card__name', text: p.name }),
            QSDom.el('span', { class: 'oauth-provider-card__id', text: p.id }),
        ]);
        pills.appendChild(_renderKeyPill(t('keysPreview'), p.credentials && p.credentials.preview));
        if (p.credentials && p.credentials.build) {
            pills.appendChild(_renderKeyPill(t('keysBuild'), p.credentials.build));
        }
        pills.appendChild(_renderRoutesPill(p.setup || {}));
        if (p.resolver_count > 0) {
            pills.appendChild(_renderPill(p.resolver_count === 1 ? t('usedByOne') : t('usedByMany', { count: p.resolver_count }), 'warning'));
        }
        return pills;
    }

    /** An address to register in a console, with a button that copies it. */
    function _renderAddress(text) {
        const copyBtn = QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--ghost oauth-address__copy',
            'aria-label': t('copy'),
            title: t('copy'),
            onclick: function () {
                const utils = window.QuickSiteUtils;
                if (utils && typeof utils.copyToClipboard === 'function') utils.copyToClipboard(text, t('copied'));
            },
        });
        copyBtn.appendChild(QSDom.svgIcon(ICON_COPY, 14));
        return QSDom.el('div', { class: 'oauth-address' }, [
            QSDom.el('code', { class: 'oauth-address__value', text: text }),
            copyBtn,
        ]);
    }

    /** The provider's console link, when the list gives a web address for it. */
    function _renderConsoleLink(p) {
        const url = typeof p.console_url === 'string' ? p.console_url : '';
        if (!/^https?:\/\//i.test(url)) return null;
        const a = QSDom.el('a', {
            class: 'oauth-howto__console',
            href: url,
            target: '_blank',
            rel: 'noopener noreferrer',
        });
        a.appendChild(QSDom.svgIcon(ICON_LINK, 12));
        a.appendChild(document.createTextNode(' ' + t('howStep1Link', { provider: p.name })));
        return a;
    }

    function _renderHowTo(p) {
        const callback = p.callback || {};
        const steps = QSDom.el('ol', { class: 'oauth-howto__steps' }, [
            QSDom.el('li', null, [t('howStep1'), ' ', _renderConsoleLink(p)]),
            QSDom.el('li', null, [t('howStep2'), _renderAddress(callback.preview_url || '')]),
            QSDom.el('li', null, [t('howStep3'), _renderAddress(t('howStep3Example', { path: callback.path || '' }))]),
            QSDom.el('li', null, [t('howStep4')]),
        ]);
        const details = QSDom.el('details', { class: 'oauth-howto' }, [
            QSDom.el('summary', { class: 'oauth-howto__summary', text: t('howTo') }),
            steps,
        ]);
        const note = T.notes && typeof T.notes[p.id] === 'string' ? T.notes[p.id] : '';
        if (note !== '') details.appendChild(QSDom.el('p', { class: 'oauth-howto__note', text: note }));
        return details;
    }

    /** One key set: a form for whoever may set keys, its status otherwise. */
    function _renderKeySet(p, setName, title, hint) {
        const current = (p.credentials && p.credentials[setName]) || { client_id: null, secret_set: false };
        const section = QSDom.el('section', { class: 'oauth-keyset' }, [
            QSDom.el('h3', { class: 'oauth-keyset__title', text: title }),
            QSDom.el('p', { class: 'admin-hint', text: hint }),
        ]);
        if (!state.canManage) {
            section.appendChild(QSDom.el('p', { class: 'oauth-keyset__readonly' }, [
                QSDom.el('strong', { text: current.secret_set ? t('statusSet') : t('statusMissing') }),
                current.client_id ? ' · ' + t('clientId') + ': ' + current.client_id : '',
            ]));
            return section;
        }

        const idInput = QSDom.el('input', {
            type: 'text', class: 'admin-input', autocomplete: 'off', spellcheck: 'false',
            'aria-label': t('clientId'),
        });
        idInput.value = current.client_id || '';
        const secretInput = QSDom.el('input', {
            type: 'password', class: 'admin-input', autocomplete: 'new-password',
            placeholder: current.secret_set ? t('secretKeep') : t('secretNew'),
            'aria-label': t('clientSecret'),
        });
        const status = QSDom.el('span', { class: 'oauth-keyset__status', role: 'status', 'aria-live': 'polite' });

        const saveBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--primary', text: t('save') });
        saveBtn.addEventListener('click', function () {
            saveSet(p, setName, idInput.value.trim(), secretInput.value, saveBtn, status);
        });
        const actions = QSDom.el('div', { class: 'oauth-keyset__actions' }, [saveBtn]);
        if (current.client_id) {
            const clearBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--ghost oauth-keyset__clear', text: t('clear') });
            clearBtn.addEventListener('click', function () { clearSet(p, setName, title, clearBtn, status); });
            actions.appendChild(clearBtn);
        }
        actions.appendChild(status);

        section.appendChild(QSDom.el('label', { class: 'admin-label', text: t('clientId') }));
        section.appendChild(idInput);
        section.appendChild(QSDom.el('label', { class: 'admin-label', text: t('clientSecret') }));
        section.appendChild(secretInput);
        section.appendChild(actions);
        return section;
    }

    function _renderCard(p) {
        const sets = QSDom.el('div', { class: 'oauth-keysets' }, [
            _renderKeySet(p, 'preview', t('keysPreview'), t('keysPreviewHint')),
        ]);
        if (p.credentials && p.credentials.build) {
            sets.appendChild(_renderKeySet(p, 'build', t('keysBuild'), t('keysBuildHint')));
        }
        const card = QSDom.el('div', { class: 'oauth-provider-card' }, [
            QSDom.el('div', { class: 'oauth-provider-card__row' }, [
                QSDom.el('div', { class: 'oauth-provider-card__main' }, [_renderHead(p)]),
            ]),
            _renderHowTo(p),
            sets,
        ]);
        if (!state.canManage) card.appendChild(QSDom.el('p', { class: 'admin-hint oauth-provider-card__readonly', text: t('readOnly') }));
        return card;
    }

    function renderList() {
        const root = document.getElementById('oauth-providers-list');
        if (!root) return;
        QSDom.clear(root);
        if (state.failed) {
            root.appendChild(QSDom.el('div', { class: 'oauth-providers-list__empty', text: t('loadFailed') }));
            return;
        }
        if (state.providers.length === 0) {
            root.appendChild(QSDom.el('div', { class: 'oauth-providers-list__empty', text: t('none') }));
            return;
        }
        state.providers.forEach(function (p) { root.appendChild(_renderCard(p)); });
    }

    async function refresh() {
        try {
            const r = await api('listOAuthProviders', 'GET');
            const payload = (r && r.data && (r.data.data || r.data)) || {};
            state.failed = !(r && r.ok);
            state.providers = Array.isArray(payload.providers) ? payload.providers : [];
            state.canManage = payload.can_manage === true;
        } catch (e) {
            console.warn('[oauth-providers] load failed:', e);
            state.failed = true;
            state.providers = [];
        }
        renderList();
    }

    async function saveSet(p, setName, clientId, secret, btn, status) {
        const body = { provider: p.id, set: setName, client_id: clientId };
        if (secret !== '') body.client_secret = secret;
        btn.disabled = true;
        status.textContent = '';
        try {
            const r = await api('setOAuthCredentials', 'POST', body);
            if (!r || !r.ok) {
                status.textContent = (r && r.data && r.data.message) || t('saveFailed');
                btn.disabled = false;
                return;
            }
            toast(t('saved'));
            await refresh();
        } catch (e) {
            status.textContent = (e && e.message) || t('saveFailed');
            btn.disabled = false;
        }
    }

    async function clearSet(p, setName, title, btn, status) {
        const utils = window.QuickSiteUtils;
        const message = t('clearConfirm', { set: title, provider: p.name });
        const ok = utils && typeof utils.confirm === 'function'
            ? await utils.confirm(message, { type: 'danger', confirmText: t('clear'), confirmClass: 'danger' })
            : false;
        if (!ok) return;
        btn.disabled = true;
        status.textContent = '';
        try {
            const r = await api('setOAuthCredentials', 'POST', { provider: p.id, set: setName, clear: true });
            if (!r || !r.ok) {
                status.textContent = (r && r.data && r.data.message) || t('saveFailed');
                btn.disabled = false;
                return;
            }
            toast(t('cleared'));
            await refresh();
        } catch (e) {
            status.textContent = (e && e.message) || t('saveFailed');
            btn.disabled = false;
        }
    }

    function toast(message) {
        const utils = window.QuickSiteUtils;
        if (utils && typeof utils.showToast === 'function') utils.showToast(message, 'success');
    }

    function init() {
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
