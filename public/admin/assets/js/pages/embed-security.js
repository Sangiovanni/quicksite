/**
 * Embed Security page — READ-ONLY view of the install-wide embed policy.
 *
 * The policy is set at deployment and cannot be changed from the panel, so this
 * page only READS it (getIframeSandbox) and displays the allowlisted hosts,
 * their sandbox tokens, the default policy and the never-allowed tokens. There
 * are no write controls.
 *
 * Shape from getIframeSandbox:
 *   { default: [token,...], hosts: [{name, parameters:[token,...]}, ...],
 *     valid_permissions: [...], never_allowed: [...] }
 *
 * Strings come from window.QS_EMBED_SECURITY_I18N (the `embedSecurity` sub-tree),
 * emitted by embed-security.php under the same dot paths PHP uses; read through
 * t(), never directly. DOM is built with QSDom (createElement + textContent).
 *
 * @version 5.0.0
 */

(function() {
    'use strict';

    /**
     * Resolve one admin string by its FULL dot path; a path that resolves to
     * nothing returns THE PATH ITSELF, so an unset string is visible on screen
     * rather than hidden behind an English fallback.
     */
    function t(path, params) {
        let node = window.QS_EMBED_SECURITY_I18N || {};
        for (const part of String(path).split('.')) {
            if (node === null || typeof node !== 'object' || !(part in node)) return path;
            node = node[part];
        }
        if (typeof node !== 'string') return path;
        let value = node;
        if (params) {
            for (const name of Object.keys(params)) {
                value = value.split(':' + name).join(String(params[name]));
            }
        }
        return value;
    }

    // ── API ──────────────────────────────────────────────────

    async function loadConfig() {
        const res = await QuickSiteAdmin.apiRequest('getIframeSandbox', 'GET');
        if (!res.ok) {
            showError(document.getElementById('rules-container'), res.data?.error || t('embedSecurity.errors.loadFailed'));
            return null;
        }
        return res.data?.data || res.data;
    }

    // ── Rendering ────────────────────────────────────────────

    /** A row of sandbox tokens as <code> chips, or a muted "block everything". */
    function _renderTokens(tokens) {
        const box = QSDom.el('div');
        if (Array.isArray(tokens) && tokens.length) {
            tokens.forEach((tok, i) => {
                if (i) box.appendChild(document.createTextNode(' '));
                box.appendChild(QSDom.el('code', { text: tok }));
            });
        } else {
            box.appendChild(QSDom.el('span', {
                class: 'admin-muted', text: t('embedSecurity.blockEverything')
            }));
        }
        return box;
    }

    /**
     * One allowed host, read-only: the host name and the tokens it is granted.
     * @param {{name: string, parameters: string[]}} host
     */
    function _renderHostCard({ name, parameters }) {
        return QSDom.el('div', {
            class: 'admin-card admin-card--nested',
            style: 'margin-bottom: var(--space-sm);'
        }, [
            QSDom.el('div', { class: 'admin-card__body' }, [
                QSDom.el('div', {
                    style: 'margin-bottom: var(--space-xs); display: flex; align-items: center; gap: var(--space-xs);'
                }, [
                    QSDom.el('code', {
                        style: 'background: var(--admin-bg-tertiary); padding: 2px 6px; border-radius: 3px; font-size: 0.85em;',
                        text: '<iframe>'
                    }),
                    QSDom.el('strong', { text: name })
                ]),
                _renderTokens(parameters)
            ])
        ]);
    }

    function renderHosts(data) {
        const hosts = Array.isArray(data.hosts) ? data.hosts : [];
        const container = document.getElementById('rules-container');
        QSDom.clear(container);

        if (hosts.length === 0) {
            container.appendChild(QSDom.el('p', {
                class: 'admin-muted', text: t('embedSecurity.noRules')
            }));
            return;
        }
        hosts.forEach(host => container.appendChild(_renderHostCard(host)));
    }

    function renderDefaultPolicy(data) {
        const container = document.getElementById('default-policy-value');
        QSDom.clear(container);
        container.appendChild(_renderTokens(data.default));
    }

    function renderNeverAllowed(data) {
        const list = data.never_allowed || [
            'allow-top-navigation',
            'allow-top-navigation-by-user-activation',
            'allow-popups-to-escape-sandbox'
        ];
        const container = document.getElementById('never-allowed-list');
        const ul = QSDom.el('ul', { style: 'margin: 0; padding-left: var(--space-lg);' });
        list.forEach(p => ul.appendChild(QSDom.el('li', null, [QSDom.el('code', { text: p })])));
        QSDom.clear(container);
        container.appendChild(ul);
    }

    // ── Helpers ──────────────────────────────────────────────

    function showError(container, msg) {
        QSDom.clear(container);
        container.appendChild(QSDom.el('p', { class: 'admin-error', text: msg }));
    }

    // ── Init ─────────────────────────────────────────────────

    async function init() {
        const data = await loadConfig();
        if (!data) return;
        renderHosts(data);
        renderDefaultPolicy(data);
        renderNeverAllowed(data);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
