/**
 * Embed Security Settings Page JavaScript
 *
 * Manages embed sandbox rules via the QuickSite API.
 * Config format: { "tags": { "iframe": { "domain": "sandbox" }, "video": {...} }, "default": "" }
 *
 * Strings come from window.QS_EMBED_SECURITY_I18N, emitted by
 * embed-security.php: the `embedSecurity` and `common` translation sub-trees
 * under the same dot paths PHP uses. Read it through t(), never directly.
 *
 * DOM is built with createElement + textContent through QSDom and named
 * _render* helpers, per the CLAUDE.md HTML-in-JS hygiene rule.
 *
 * @version 4.0.0
 */

(function() {
    'use strict';

    const config = window.QUICKSITE_CONFIG || {};

    /**
     * Resolve one admin string by its FULL dot path; a path that resolves to
     * nothing returns THE PATH ITSELF, so an unset string is visible on
     * screen rather than hidden behind an English fallback.
     *
     * @param {string} path      e.g. 'embedSecurity.noRules'
     * @param {Object} [params]  :name markers, as PHP's t() does
     * @returns {string}
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

    const VALID_PERMISSIONS = [
        'allow-scripts',
        'allow-same-origin',
        'allow-forms',
        'allow-popups',
        'allow-modals',
        'allow-orientation-lock',
        'allow-pointer-lock',
        'allow-presentation',
        'allow-downloads'
    ];

    let currentTags = {};    // { iframe: { domain: sandbox }, video: {...}, ... }
    let validTags = [];      // ['iframe', 'video', 'audio']
    let editingTag = null;
    let editingDomain = null;

    // ── API helpers ──────────────────────────────────────────

    async function loadConfig() {
        const res = await QuickSiteAdmin.apiRequest('getIframeSandbox', 'GET');
        if (!res.ok) {
            showError(document.getElementById('rules-container'), res.data?.error || t('embedSecurity.errors.loadFailed'));
            return null;
        }
        return res.data?.data || res.data;
    }

    // ── Rendering ────────────────────────────────────────────

    function renderRules(data) {
        currentTags = data.tags || {};
        validTags = data.valid_tags || Object.keys(currentTags);
        const container = document.getElementById('rules-container');

        // Flatten all tag rules into a single list for display
        const allRules = [];
        for (const tag of validTags) {
            const rules = currentTags[tag] || {};
            for (const [domain, sandbox] of Object.entries(rules)) {
                allRules.push({ tag, domain, sandbox });
            }
        }

        QSDom.clear(container);

        if (allRules.length === 0) {
            container.appendChild(QSDom.el('p', {
                class: 'admin-muted', text: t('embedSecurity.noRules')
            }));
            return;
        }

        allRules.forEach(rule => container.appendChild(_renderRuleCard(rule)));
    }

    /**
     * One sandbox rule's card, with its edit and delete buttons already bound.
     *
     * The buttons carry closures rather than an inline onclick built from the
     * tag and domain, so no value is ever spliced into markup: that is what
     * retired this page's own escapeAttr(), which escaped for a JS string
     * inside an attribute and left &, < and > alone.
     *
     * @param {{tag: string, domain: string, sandbox: string}} rule
     * @returns {HTMLElement}
     */
    function _renderRuleCard({ tag, domain, sandbox }) {
        const permsBox = QSDom.el('div');
        if (sandbox) {
            sandbox.split(' ').forEach((p, i) => {
                if (i) permsBox.appendChild(document.createTextNode(' '));
                permsBox.appendChild(QSDom.el('code', { text: p }));
            });
        } else {
            permsBox.appendChild(QSDom.el('span', {
                class: 'admin-muted', text: t('embedSecurity.blockEverything')
            }));
        }

        return QSDom.el('div', {
            class: 'admin-card admin-card--nested',
            style: 'margin-bottom: var(--space-sm);'
        }, [
            QSDom.el('div', {
                class: 'admin-card__body',
                style: 'display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-md);'
            }, [
                QSDom.el('div', { style: 'flex: 1; min-width: 0;' }, [
                    QSDom.el('div', {
                        style: 'margin-bottom: var(--space-xs); display: flex; align-items: center; gap: var(--space-xs);'
                    }, [
                        QSDom.el('code', {
                            style: 'background: var(--admin-bg-tertiary); padding: 2px 6px; border-radius: 3px; font-size: 0.85em;',
                            text: '<' + tag + '>'
                        }),
                        QSDom.el('strong', { text: domain })
                    ]),
                    permsBox
                ]),
                QSDom.el('div', {
                    style: 'display: flex; gap: var(--space-xs); flex-shrink: 0;'
                }, [
                    _renderIconButton('secondary', QuickSiteUtils.ICON_PATHS.edit,
                        t('embedSecurity.editRuleTitle'), () => editRule(tag, domain)),
                    _renderIconButton('danger', QuickSiteUtils.ICON_PATHS.trash,
                        t('embedSecurity.deleteRuleTitle'), () => deleteRule(tag, domain))
                ])
            ])
        ]);
    }

    /** One small icon-only button. @returns {HTMLElement} */
    function _renderIconButton(variant, iconPath, title, onClick) {
        return QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--small admin-btn--' + variant,
            title: title,
            onclick: onClick
        }, [QSDom.iconEl(iconPath, 14)]);
    }

    function renderTagSelector() {
        const sel = document.getElementById('rule-tag');
        QSDom.clear(sel);
        validTags.forEach(tag => {
            const opt = document.createElement('option');
            opt.value = tag;
            opt.textContent = '<' + tag + '>';
            sel.appendChild(opt);
        });
    }

    function renderDefaultPolicy(data) {
        const sel = document.getElementById('default-policy');
        const val = data.default || '';
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === val) {
                sel.selectedIndex = i;
                return;
            }
        }
        if (val) {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = t('embedSecurity.customPolicy', { value: val });
            sel.appendChild(opt);
            sel.value = val;
        }
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

    function renderPermissionCheckboxes() {
        const container = document.getElementById('permission-checkboxes');
        QSDom.clear(container);
        VALID_PERMISSIONS.forEach(p => {
            container.appendChild(QSDom.el('div', { class: 'admin-checkbox-group' }, [
                QSDom.el('input', {
                    type: 'checkbox', id: 'perm-' + p, value: p, class: 'admin-checkbox'
                }),
                QSDom.el('label', { for: 'perm-' + p, class: 'admin-checkbox-label' },
                    [QSDom.el('code', { text: p })])
            ]));
        });
    }

    // ── Modal ────────────────────────────────────────────────

    function openModal(title, tag, domain, sandbox) {
        document.getElementById('rule-modal-title').textContent = title;

        const tagSelect = document.getElementById('rule-tag');
        tagSelect.value = tag || validTags[0] || 'iframe';
        tagSelect.disabled = !!tag;

        const domainInput = document.getElementById('rule-domain');
        domainInput.value = domain || '';
        domainInput.disabled = !!domain;

        const perms = sandbox ? sandbox.split(' ') : [];
        VALID_PERMISSIONS.forEach(p => {
            const cb = document.getElementById('perm-' + p);
            if (cb) cb.checked = perms.includes(p);
        });

        document.getElementById('rule-modal').style.display = '';
        if (!domain) domainInput.focus();
    }

    function closeModal() {
        document.getElementById('rule-modal').style.display = 'none';
        document.getElementById('rule-tag').disabled = false;
        document.getElementById('rule-domain').disabled = false;
        editingTag = null;
        editingDomain = null;
    }

    // ── Actions ──────────────────────────────────────────────

    async function saveRule() {
        const tagSelect = document.getElementById('rule-tag');
        const tag = tagSelect.value;
        const domainInput = document.getElementById('rule-domain');
        const domain = domainInput.value.trim().toLowerCase();

        if (!tag) {
            QuickSiteAdmin.showToast(t('embedSecurity.validation.selectTag'), 'error');
            return;
        }

        if (!domain) {
            QuickSiteAdmin.showToast(t('embedSecurity.validation.enterDomain'), 'error');
            domainInput.focus();
            return;
        }

        const selected = VALID_PERMISSIONS.filter(p => {
            const cb = document.getElementById('perm-' + p);
            return cb && cb.checked;
        });
        const sandbox = selected.join(' ');

        const res = await QuickSiteAdmin.apiRequest('setIframeSandbox', 'POST', { tag, domain, sandbox });
        if (!res.ok) {
            QuickSiteAdmin.showToast(res.data?.error || res.data?.message || t('embedSecurity.errors.saveFailed'), 'error');
            return;
        }

        QuickSiteAdmin.showToast(t('embedSecurity.toast.ruleSaved'), 'success');
        closeModal();
        await refresh();
    }

    async function deleteRule(tag, domain) {
        if (!confirm(t('embedSecurity.confirmDelete', { tag: tag, domain: domain }))) return;

        const res = await QuickSiteAdmin.apiRequest('removeIframeSandbox', 'POST', { tag, domain });
        if (!res.ok) {
            QuickSiteAdmin.showToast(res.data?.error || res.data?.message || t('embedSecurity.errors.deleteFailed'), 'error');
            return;
        }

        QuickSiteAdmin.showToast(t('embedSecurity.toast.ruleRemoved'), 'success');
        await refresh();
    }

    async function saveDefault() {
        const val = document.getElementById('default-policy').value;
        const res = await QuickSiteAdmin.apiRequest('setIframeSandbox', 'POST', { default: val });
        if (!res.ok) {
            QuickSiteAdmin.showToast(res.data?.error || res.data?.message || t('embedSecurity.errors.saveDefaultFailed'), 'error');
            return;
        }
        QuickSiteAdmin.showToast(t('embedSecurity.toast.defaultSaved'), 'success');
    }

    function editRule(tag, domain) {
        editingTag = tag;
        editingDomain = domain;
        const tagRules = currentTags[tag] || {};
        openModal(t('embedSecurity.editSandboxRule'), tag, domain, tagRules[domain] || '');
    }

    function addRule() {
        editingTag = null;
        editingDomain = null;
        openModal(t('embedSecurity.addSandboxRule'), null, null, null);
    }

    // ── Helpers ──────────────────────────────────────────────

    function showError(container, msg) {
        QSDom.clear(container);
        container.appendChild(QSDom.el('p', { class: 'admin-error', text: msg }));
    }

    // ── Init ─────────────────────────────────────────────────

    async function refresh() {
        const data = await loadConfig();
        if (!data) return;
        renderRules(data);
        renderTagSelector();
        renderDefaultPolicy(data);
        renderNeverAllowed(data);
    }

    async function init() {
        renderPermissionCheckboxes();

        document.getElementById('btn-add-rule').addEventListener('click', addRule);
        document.getElementById('btn-save-rule').addEventListener('click', saveRule);
        document.getElementById('btn-save-default').addEventListener('click', saveDefault);

        document.querySelectorAll('[data-close-modal]').forEach(el => {
            el.addEventListener('click', closeModal);
        });
        document.getElementById('rule-modal').addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });

        await refresh();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
