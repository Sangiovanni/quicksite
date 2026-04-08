/**
 * Embed Security Settings Page JavaScript
 * 
 * Manages embed sandbox rules via the QuickSite API.
 * Config format: { "tags": { "iframe": { "domain": "sandbox" }, "video": {...} }, "default": "" }
 * 
 * @version 4.0.0
 */

(function() {
    'use strict';

    const config = window.QUICKSITE_CONFIG || {};
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
            showError(document.getElementById('rules-container'), res.data?.error || 'Failed to load config');
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

        if (allRules.length === 0) {
            container.innerHTML = '<p class="admin-muted">No rules configured yet. All embeds will use the default policy.</p>';
            return;
        }

        container.innerHTML = allRules.map(({ tag, domain, sandbox }) => {
            const perms = sandbox
                ? sandbox.split(' ').map(p => '<code>' + escapeHtml(p) + '</code>').join(' ')
                : '<span class="admin-muted">Block everything</span>';

            return `
                <div class="admin-card admin-card--nested" style="margin-bottom: var(--space-sm);">
                    <div class="admin-card__body" style="display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-md);">
                        <div style="flex: 1; min-width: 0;">
                            <div style="margin-bottom: var(--space-xs); display: flex; align-items: center; gap: var(--space-xs);">
                                <code style="background: var(--admin-bg-tertiary); padding: 2px 6px; border-radius: 3px; font-size: 0.85em;">&lt;${escapeHtml(tag)}&gt;</code>
                                <strong>${escapeHtml(domain)}</strong>
                            </div>
                            <div>
                                ${perms}
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--space-xs); flex-shrink: 0;">
                            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="EmbedSecurity.editRule('${escapeAttr(tag)}','${escapeAttr(domain)}')" title="Edit rule">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            </button>
                            <button type="button" class="admin-btn admin-btn--small admin-btn--danger" onclick="EmbedSecurity.deleteRule('${escapeAttr(tag)}','${escapeAttr(domain)}')" title="Delete rule">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>`;
        }).join('');
    }

    function renderTagSelector() {
        const sel = document.getElementById('rule-tag');
        sel.innerHTML = validTags.map(t =>
            `<option value="${escapeAttr(t)}">&lt;${escapeHtml(t)}&gt;</option>`
        ).join('');
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
            opt.textContent = val + ' (custom)';
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
        container.innerHTML = '<ul style="margin: 0; padding-left: var(--space-lg);">' +
            list.map(p => '<li><code>' + escapeHtml(p) + '</code></li>').join('') +
            '</ul>';
    }

    function renderPermissionCheckboxes() {
        const container = document.getElementById('permission-checkboxes');
        container.innerHTML = VALID_PERMISSIONS.map(p => `
            <div class="admin-checkbox-group">
                <input type="checkbox" id="perm-${p}" value="${p}" class="admin-checkbox">
                <label for="perm-${p}" class="admin-checkbox-label"><code>${escapeHtml(p)}</code></label>
            </div>
        `).join('');
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
            QuickSiteAdmin.showToast('Please select a tag', 'error');
            return;
        }

        if (!domain) {
            QuickSiteAdmin.showToast('Please enter a domain', 'error');
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
            QuickSiteAdmin.showToast(res.data?.error || res.data?.message || 'Failed to save rule', 'error');
            return;
        }

        QuickSiteAdmin.showToast('Rule saved successfully', 'success');
        closeModal();
        await refresh();
    }

    async function deleteRule(tag, domain) {
        if (!confirm('Delete sandbox rule for <' + tag + '> "' + domain + '"?')) return;

        const res = await QuickSiteAdmin.apiRequest('removeIframeSandbox', 'POST', { tag, domain });
        if (!res.ok) {
            QuickSiteAdmin.showToast(res.data?.error || res.data?.message || 'Failed to delete rule', 'error');
            return;
        }

        QuickSiteAdmin.showToast('Rule removed', 'success');
        await refresh();
    }

    async function saveDefault() {
        const val = document.getElementById('default-policy').value;
        const res = await QuickSiteAdmin.apiRequest('setIframeSandbox', 'POST', { default: val });
        if (!res.ok) {
            QuickSiteAdmin.showToast(res.data?.error || res.data?.message || 'Failed to save default', 'error');
            return;
        }
        QuickSiteAdmin.showToast('Default policy saved', 'success');
    }

    function editRule(tag, domain) {
        editingTag = tag;
        editingDomain = domain;
        const tagRules = currentTags[tag] || {};
        openModal('Edit Sandbox Rule', tag, domain, tagRules[domain] || '');
    }

    function addRule() {
        editingTag = null;
        editingDomain = null;
        openModal('Add Sandbox Rule', null, null, null);
    }

    // ── Helpers ──────────────────────────────────────────────

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    function showError(container, msg) {
        container.innerHTML = '<p class="admin-error">' + escapeHtml(msg) + '</p>';
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

    // Expose for inline onclick handlers
    window.EmbedSecurity = { editRule, deleteRule };

    document.addEventListener('DOMContentLoaded', init);
})();
