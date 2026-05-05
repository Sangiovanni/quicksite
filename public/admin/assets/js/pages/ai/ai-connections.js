/**
 * QuickSite Admin — AI Connections page
 *
 * Thin view over QSConnectionsStore (v3). All probing/testing happens
 * browser-direct via QSAiCall. No PHP `listAiProviders` / `testAiKey`
 * /`detectProvider` calls.
 *
 * Two-step wizard:
 *   1. Pick a kind (cloud provider, Ollama, LM Studio, LiteLLM, vLLM, custom).
 *   2. Fill in name + key/base URL — auto-probe on change to surface models.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    const STORAGE = window.QuickSiteStorageKeys;

    let current = null;            // wizard working draft (Connection partial)
    let probeAbort = null;
    let probeDebounce = null;
    let lastProbe = null;          // { ok, models, message, category? }

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        if (!window.QSConnectionsStore || !window.QSProviderCatalog || !window.QSAiCall) {
            console.error('[ai-connections] core libs missing');
            return;
        }
        // Run silent migration so users coming from v2 see their old keys.
        try { window.QSConnectionsStore.migrateFromV2(); } catch (e) { /* noop */ }

        renderList();
        bindGlobalControls();
        bindModal();
    }

    // ---------- list rendering ----------

    function renderList() {
        const root = document.getElementById('qsac-list');
        if (!root) return;
        const store = window.QSConnectionsStore.loadStore();
        const conns = store.connections || [];
        if (conns.length === 0) {
            root.innerHTML = `
                <div class="admin-empty-state" style="padding: var(--space-lg) 0; text-align:center;">
                    <p class="admin-text-muted">No AI connection yet.</p>
                    <button type="button" class="admin-btn admin-btn--primary" id="qsac-empty-add">+ Add your first connection</button>
                </div>`;
            const btn = document.getElementById('qsac-empty-add');
            if (btn) btn.addEventListener('click', openWizard);
            return;
        }
        root.innerHTML = conns.map((c) => renderCard(c, store.defaultConnectionId === c.id)).join('');

        root.querySelectorAll('[data-qsac-action]').forEach((el) => {
            el.addEventListener('click', onCardAction);
        });
    }

    function renderCard(c, isDefault) {
        const star = isDefault ? '⭐' : '☆';
        const status = c.lastStatus;
        const dot = statusDot(status);
        const label = c.providerType === 'openai-compatible' && c.baseUrl
            ? c.baseUrl
            : (window.QSProviderCatalog.get(c.providerType) || {}).name || c.providerType;
        const keyHint = c.key
            ? '<span class="qsac-key-hint">' + maskKey(c.key) + '</span>'
            : '<span class="qsac-key-hint qsac-key-hint--none">no key</span>';
        const modelCount = (c.enabledModels || c.models || []).length;
        const streamingTag = c.streaming ? 'streaming on' : 'streaming off';

        // Pick a logo: cloud → providerType id; local → preset id (if known)
        // or 'custom' for openai-compatible without a preset.
        // For older connections without _preset, sniff by baseUrl port.
        const logoId = c.type === 'local'
            ? (c._preset || sniffPresetFromUrl(c.baseUrl) || 'custom')
            : c.providerType;
        const emoji = c.type === 'local'
            ? (logoId === 'ollama' ? '🦙' : logoId === 'lm-studio' ? '🎛️' : '⚙️')
            : emojiFor(c.providerType);

        return `
            <div class="qsac-card" data-conn-id="${esc(c.id)}">
                <div class="qsac-card__head">
                    <span class="qsac-card__logo" aria-hidden="true">
                        <img src="/admin/assets/images/providers/${esc(logoId)}.svg" alt=""
                             onerror="this.outerHTML='<span class=&quot;qsac-card__logo-emoji&quot;>${esc(emoji)}</span>'">
                    </span>
                    <span class="qsac-star" data-qsac-action="default" title="Set as default">${star}</span>
                    <span class="qsac-name">${esc(c.name)}</span>
                    <span class="qsac-status" data-qsac-action="test" title="Click to test now">${dot}</span>
                </div>
                <div class="qsac-card__meta">
                    <span class="qsac-type qsac-type--${esc(c.type)}">${c.type === 'local' ? 'Local' : 'Cloud'}</span>
                    <span> · ${esc(label)}</span>
                    <span> · ${keyHint}</span>
                    <span> · ${modelCount} model${modelCount === 1 ? '' : 's'}</span>
                    <span> · ${streamingTag}</span>
                </div>
                <div class="qsac-card__actions">
                    <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-qsac-action="edit">Edit</button>
                    <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-qsac-action="default">Set default</button>
                    <button type="button" class="admin-btn admin-btn--secondary admin-btn--small admin-btn--danger" data-qsac-action="delete">Delete</button>
                </div>
            </div>`;
    }

    function statusDot(status) {
        if (!status) return '<span class="qsac-dot qsac-dot--unknown" title="Never tested">◌</span>';
        const ageMs = Date.now() - (status.at || 0);
        if (status.ok) {
            const cls = ageMs < 5 * 60 * 1000 ? 'qsac-dot--ok' : 'qsac-dot--stale';
            const ago = formatAgo(ageMs);
            return `<span class="qsac-dot ${cls}" title="OK (${ago})">●</span>`;
        }
        return `<span class="qsac-dot qsac-dot--err" title="${esc(status.message || 'Failed')}">●</span>`;
    }

    function formatAgo(ms) {
        if (ms < 1000) return 'just now';
        if (ms < 60_000) return Math.round(ms / 1000) + 's ago';
        if (ms < 3_600_000) return Math.round(ms / 60_000) + 'm ago';
        return Math.round(ms / 3_600_000) + 'h ago';
    }

    function maskKey(key) {
        if (!key) return '';
        const k = String(key);
        if (k.length <= 8) return '•••';
        return k.slice(0, 4) + '…' + k.slice(-4);
    }

    function onCardAction(ev) {
        const card = ev.currentTarget.closest('.qsac-card');
        if (!card) return;
        const id = card.dataset.connId;
        const action = ev.currentTarget.dataset.qsacAction;
        const conn = window.QSConnectionsStore.getConnection(id);
        if (!conn) return;

        if (action === 'default') {
            window.QSConnectionsStore.setDefault(id);
            renderList();
        } else if (action === 'delete') {
            if (!window.confirm(`Delete connection "${conn.name}"?`)) return;
            window.QSConnectionsStore.removeConnection(id);
            renderList();
        } else if (action === 'edit') {
            openWizard(conn);
        } else if (action === 'test') {
            inlineTest(conn);
        }
    }

    async function inlineTest(conn) {
        const card = document.querySelector(`.qsac-card[data-conn-id="${conn.id}"] .qsac-status`);
        if (card) card.innerHTML = '<span class="qsac-dot qsac-dot--unknown">⏳</span>';
        try {
            const r = await window.QSAiCall.test(conn);
            window.QSConnectionsStore.recordStatus(conn.id, { ok: r.ok, message: r.message });
            if (r.ok && r.models && r.models.length) {
                window.QSConnectionsStore.updateConnection(conn.id, {
                    models: r.models,
                    enabledModels: conn.enabledModels && conn.enabledModels.length ? conn.enabledModels : r.models,
                    defaultModel: conn.defaultModel || r.models[0]
                });
            }
        } catch (e) {
            window.QSConnectionsStore.recordStatus(conn.id, { ok: false, message: e.message });
        }
        renderList();
    }

    // ---------- defaults / automation toggles ----------

    function bindGlobalControls() {
        const persist = document.getElementById('qsac-persist');
        const autoPrev = document.getElementById('qsac-auto-preview');
        const autoExec = document.getElementById('qsac-auto-execute');
        if (persist) {
            persist.checked = localStorage.getItem(STORAGE.aiPersist) === 'true';
            persist.addEventListener('change', () => {
                localStorage.setItem(STORAGE.aiPersist, persist.checked ? 'true' : 'false');
            });
        }
        if (autoPrev) {
            autoPrev.checked = localStorage.getItem(STORAGE.aiAutoPreview) !== 'false';
            autoPrev.addEventListener('change', () => {
                localStorage.setItem(STORAGE.aiAutoPreview, autoPrev.checked ? 'true' : 'false');
            });
        }
        if (autoExec) {
            autoExec.checked = localStorage.getItem(STORAGE.aiAutoExecute) === 'true';
            autoExec.addEventListener('change', () => {
                localStorage.setItem(STORAGE.aiAutoExecute, autoExec.checked ? 'true' : 'false');
            });
        }
        const addBtn = document.getElementById('qsac-add-btn');
        if (addBtn) addBtn.addEventListener('click', () => openWizard());
    }

    // ---------- wizard ----------

    function bindModal() {
        document.querySelectorAll('[data-qsac-close]').forEach((el) => {
            el.addEventListener('click', closeWizard);
        });
    }

    function openWizard(existing) {
        current = existing
            ? Object.assign({}, existing, { _editing: existing.id })
            : { type: 'cloud', providerType: null, streaming: true };
        if (probeAbort) { probeAbort.abort(); probeAbort = null; }
        lastProbe = null;
        const modal = document.getElementById('qsac-modal');
        if (modal) modal.style.display = 'block';
        document.getElementById('qsac-modal-title').textContent = existing ? 'Edit connection' : 'Add a connection';
        if (existing) renderWizardForm();
        else renderWizardKindPicker();
    }

    function closeWizard() {
        if (probeAbort) { probeAbort.abort(); probeAbort = null; }
        if (probeDebounce) { clearTimeout(probeDebounce); probeDebounce = null; }
        current = null;
        const modal = document.getElementById('qsac-modal');
        if (modal) modal.style.display = 'none';
    }

    function renderWizardKindPicker() {
        const body = document.getElementById('qsac-modal-body');
        const footer = document.getElementById('qsac-modal-footer');

        // Cloud kinds: pulled from QSProviderCatalog so adding a provider in
        // one place lights it up here automatically. We exclude the synthetic
        // "openai-compatible" entry — that's exposed as the "Custom" tile.
        const allCloud = window.QSProviderCatalog.list
            ? window.QSProviderCatalog.list()
            : Object.values(window.QSProviderCatalog.PROVIDERS || {});
        // Fallback if .list() doesn't exist: enumerate manually.
        const cloudIds = ['openai', 'anthropic', 'google', 'mistral', 'deepseek', 'groq', 'xai', 'openrouter'];
        const cloudKinds = cloudIds
            .map((id) => window.QSProviderCatalog.get(id))
            .filter(Boolean)
            .map((p) => ({ id: p.id, name: p.name, icon: emojiFor(p.id) }));

        const presets = (window.QSLocalPresets && window.QSLocalPresets.list()) || [];

        body.innerHTML = `
            <p>What kind of AI do you want to connect?</p>

            <h4 class="qsac-kinds-heading">Cloud · BYOK</h4>
            <div class="qsac-kinds">
                ${cloudKinds.map((k) => kindTile({
                    kind: 'cloud', dataAttr: `data-provider="${esc(k.id)}"`,
                    logoId: k.id, name: k.name, sub: 'Cloud · BYOK', emojiFallback: k.icon
                })).join('')}
            </div>

            <h4 class="qsac-kinds-heading">Local · runs on your machine</h4>
            <div class="qsac-kinds">
                ${presets.map((p) => kindTile({
                    kind: 'local', dataAttr: `data-preset="${esc(p.id)}"`,
                    logoId: p.id, name: p.name, sub: 'Local', emojiFallback: p.icon
                })).join('')}
                ${kindTile({
                    kind: 'custom', dataAttr: '',
                    logoId: 'custom', name: 'Custom', sub: 'OpenAI-compatible endpoint', emojiFallback: '⚙️'
                })}
            </div>`;

        footer.innerHTML = `<button type="button" class="admin-btn admin-btn--secondary" data-qsac-close>Cancel</button>`;
        footer.querySelector('[data-qsac-close]').addEventListener('click', closeWizard);
        body.querySelectorAll('[data-kind]').forEach((b) => b.addEventListener('click', onKindPick));
    }

    function kindTile({ kind, dataAttr, logoId, name, sub, emojiFallback }) {
        // <img> with onerror swap to emoji span — keeps the page working if a
        // logo file is missing or 404s.
        const safeName = esc(name);
        const safeEmoji = esc(emojiFallback);
        const safeLogo = esc(logoId);
        return `
            <button type="button" class="qsac-kind" data-kind="${esc(kind)}" ${dataAttr}>
                <span class="qsac-kind__icon">
                    <img src="/admin/assets/images/providers/${safeLogo}.svg" alt=""
                         class="qsac-kind__logo"
                         onerror="this.outerHTML='<span class=&quot;qsac-kind__emoji&quot;>${safeEmoji}</span>'">
                </span>
                <span class="qsac-kind__label">${safeName}</span>
                <span class="qsac-kind__sub">${esc(sub)}</span>
            </button>`;
    }

    // Emoji fallbacks per provider id (used only if the SVG is missing).
    function emojiFor(id) {
        const map = {
            openai: '🤖', anthropic: '🟪', google: '🟦', mistral: '🟧',
            deepseek: '🐋', groq: '⚡', xai: '✖️', openrouter: '🧭'
        };
        return map[id] || '☁️';
    }

    // Heuristic for legacy local connections (no _preset stored): infer
    // the preset id from the baseUrl's port.
    function sniffPresetFromUrl(url) {
        if (!url) return null;
        try {
            const u = new URL(url);
            if (u.port === '11434') return 'ollama';
            if (u.port === '1234') return 'lm-studio';
        } catch (_e) { /* ignore parse errors */ }
        return null;
    }

    function onKindPick(ev) {
        const kind = ev.currentTarget.dataset.kind;
        if (kind === 'cloud') {
            const providerType = ev.currentTarget.dataset.provider;
            const cat = window.QSProviderCatalog.get(providerType);
            current = {
                type: 'cloud',
                providerType,
                name: cat ? cat.name : providerType,
                streaming: true
            };
        } else if (kind === 'local') {
            const preset = window.QSLocalPresets.get(ev.currentTarget.dataset.preset);
            current = {
                type: 'local',
                providerType: preset.providerType,
                name: preset.name,
                baseUrl: preset.baseUrl,
                _preset: preset.id,
                streaming: true
            };
        } else {
            current = {
                type: 'local',
                providerType: 'openai-compatible',
                name: 'Custom',
                baseUrl: 'http://localhost:8000/v1',
                streaming: true
            };
        }
        renderWizardForm();
    }

    function renderWizardForm() {
        const body = document.getElementById('qsac-modal-body');
        const footer = document.getElementById('qsac-modal-footer');
        const isLocal = current.type === 'local';
        const cat = window.QSProviderCatalog.get(current.providerType);
        const keyUrl = cat ? cat.keyUrl : null;

        body.innerHTML = `
            <div class="admin-form-group">
                <label class="admin-label" for="qsac-name">Name</label>
                <input type="text" id="qsac-name" class="admin-input" value="${esc(current.name || '')}" placeholder="My ${esc(cat ? cat.name : 'connection')}">
            </div>
            ${isLocal ? `
            <div class="admin-form-group">
                <label class="admin-label" for="qsac-baseurl">Base URL</label>
                <input type="text" id="qsac-baseurl" class="admin-input admin-input--monospace" value="${esc(current.baseUrl || '')}" placeholder="http://localhost:11434/v1">
                <p class="admin-hint">OpenAI-compatible chat-completions root (without <code>/chat/completions</code>).</p>
            </div>` : ''}
            <div class="admin-form-group">
                <label class="admin-label" for="qsac-key">API key${isLocal ? ' (optional)' : ''}</label>
                <div class="admin-input-group">
                    <input type="password" id="qsac-key" class="admin-input admin-input--monospace" autocomplete="off" spellcheck="false" value="${esc(current.key || '')}" placeholder="${isLocal ? '(leave empty if not required)' : 'paste key'}">
                    <button type="button" class="admin-btn admin-btn--icon" id="qsac-key-toggle" title="Show / hide">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                ${keyUrl ? `<p class="admin-hint">Get a key → <a href="${esc(keyUrl)}" target="_blank" rel="noopener">${esc(keyUrl)}</a></p>` : ''}
            </div>
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="qsac-streaming" ${current.streaming !== false ? 'checked' : ''}>
                    <span class="admin-checkbox__label">Streaming (recommended)</span>
                </label>
            </div>
            <div id="qsac-probe" class="qsac-probe qsac-probe--idle">
                <div class="qsac-probe__msg">Enter ${isLocal ? 'a base URL' : 'an API key'} to test the connection.</div>
            </div>
            ${isLocal && current._preset ? renderLocalCorsHint(current._preset) : ''}
        `;

        footer.innerHTML = `
            <button type="button" class="admin-btn admin-btn--secondary" id="qsac-back">${current._editing ? 'Cancel' : '← Back'}</button>
            <button type="button" class="admin-btn admin-btn--primary" id="qsac-save" disabled>${current._editing ? 'Save changes' : 'Add connection'}</button>
        `;
        document.getElementById('qsac-back').addEventListener('click', () => {
            if (current._editing) closeWizard(); else renderWizardKindPicker();
        });
        document.getElementById('qsac-save').addEventListener('click', saveConnection);

        const nameEl = document.getElementById('qsac-name');
        const keyEl = document.getElementById('qsac-key');
        const baseEl = document.getElementById('qsac-baseurl');
        const streamEl = document.getElementById('qsac-streaming');
        const toggleEl = document.getElementById('qsac-key-toggle');

        if (toggleEl) {
            toggleEl.addEventListener('click', () => {
                keyEl.type = keyEl.type === 'password' ? 'text' : 'password';
            });
        }
        nameEl.addEventListener('input', () => { current.name = nameEl.value.trim(); refreshSaveBtn(); });
        keyEl.addEventListener('input', () => { current.key = keyEl.value.trim(); scheduleProbe(); refreshSaveBtn(); });
        if (baseEl) baseEl.addEventListener('input', () => { current.baseUrl = baseEl.value.trim(); scheduleProbe(); refreshSaveBtn(); });
        streamEl.addEventListener('change', () => { current.streaming = streamEl.checked; });

        // Trigger initial probe if we already have what we need (edit mode).
        if (current._editing) scheduleProbe(0);
        refreshSaveBtn();
    }

    function renderLocalCorsHint(presetId) {
        const preset = window.QSLocalPresets.get(presetId);
        if (!preset || !preset.corsHint) return '';
        const os = detectOS();
        const ins = preset.corsHint.instructions || {};
        const text = ins[os] || ins.all || ins.linux || '';
        return `
            <details class="qsac-cors-hint">
                <summary>⚠️ ${esc(preset.corsHint.summary)} — show fix</summary>
                <pre class="qsac-cors-cmd">${esc(text)}</pre>
            </details>`;
    }

    function detectOS() {
        const p = (navigator.userAgent || '').toLowerCase();
        if (p.indexOf('win') !== -1) return 'windows';
        if (p.indexOf('mac') !== -1) return 'macos';
        return 'linux';
    }

    function refreshSaveBtn() {
        const btn = document.getElementById('qsac-save');
        if (!btn) return;
        const hasName = !!(current.name && current.name.length);
        const hasAuth = current.type === 'local' ? !!(current.baseUrl) : !!(current.key);
        // Allow save without successful probe — user can save and test later.
        btn.disabled = !(hasName && hasAuth);
    }

    function scheduleProbe(delay) {
        if (probeDebounce) clearTimeout(probeDebounce);
        const ms = delay === undefined ? 600 : delay;
        probeDebounce = setTimeout(runProbe, ms);
    }

    async function runProbe() {
        if (!current) return;
        const need = current.type === 'local' ? current.baseUrl : current.key;
        if (!need) return;
        if (probeAbort) probeAbort.abort();
        probeAbort = new AbortController();

        setProbeUI('busy', current.type === 'local' ? 'Probing endpoint…' : 'Testing key…');
        try {
            const r = await window.QSAiCall.test(current, probeAbort.signal);
            lastProbe = r;
            if (r.ok) {
                if (r.models && r.models.length) {
                    current.models = r.models;
                    if (!current.enabledModels || !current.enabledModels.length) current.enabledModels = r.models.slice();
                    if (!current.defaultModel) current.defaultModel = r.models[0];
                }
                setProbeUI('ok', `✅ Connected. ${r.models.length} model${r.models.length === 1 ? '' : 's'} found.`);
                renderModelPicker(r.models);
            } else {
                setProbeUI('err', `❌ ${r.message || 'Failed'}` + (r.category ? ` [${r.category}]` : ''));
            }
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            setProbeUI('err', '❌ ' + (e.message || 'Test failed'));
        }
    }

    function setProbeUI(state, msg) {
        const el = document.getElementById('qsac-probe');
        if (!el) return;
        el.className = 'qsac-probe qsac-probe--' + state;
        el.innerHTML = `<div class="qsac-probe__msg">${esc(msg)}</div><div id="qsac-probe-models"></div>`;
    }

    function renderModelPicker(models) {
        const slot = document.getElementById('qsac-probe-models');
        if (!slot || !models || !models.length) return;
        const enabled = new Set(current.enabledModels || models);
        slot.innerHTML = `
            <div class="qsac-models">
                <div class="qsac-models__head">
                    <span>Enabled models</span>
                    <span><a href="#" data-qsac-models="all">all</a> · <a href="#" data-qsac-models="none">none</a></span>
                </div>
                <div class="qsac-models__list">
                    ${models.map((m) => `
                        <label class="qsac-model">
                            <input type="checkbox" data-model="${esc(m)}" ${enabled.has(m) ? 'checked' : ''}>
                            <span>${esc(m)}</span>
                        </label>`).join('')}
                </div>
                <div class="qsac-models__default">
                    Default model:
                    <select id="qsac-default-model">
                        ${models.map((m) => `<option value="${esc(m)}" ${m === current.defaultModel ? 'selected' : ''}>${esc(m)}</option>`).join('')}
                    </select>
                </div>
            </div>`;
        slot.querySelectorAll('[data-model]').forEach((cb) => {
            cb.addEventListener('change', () => {
                const set = new Set(current.enabledModels || []);
                if (cb.checked) set.add(cb.dataset.model); else set.delete(cb.dataset.model);
                current.enabledModels = Array.from(set);
            });
        });
        slot.querySelectorAll('[data-qsac-models]').forEach((a) => {
            a.addEventListener('click', (ev) => {
                ev.preventDefault();
                const all = ev.currentTarget.dataset.qsacModels === 'all';
                current.enabledModels = all ? models.slice() : [];
                slot.querySelectorAll('[data-model]').forEach((cb) => { cb.checked = all; });
            });
        });
        const defSel = document.getElementById('qsac-default-model');
        if (defSel) defSel.addEventListener('change', () => { current.defaultModel = defSel.value; });
    }

    function saveConnection() {
        if (!current || !current.name) return;
        const payload = {
            name: current.name,
            type: current.type,
            providerType: current.providerType,
            baseUrl: current.baseUrl || null,
            key: current.key || null,
            extraHeaders: current.extraHeaders || null,
            models: current.models || [],
            enabledModels: current.enabledModels || current.models || [],
            defaultModel: current.defaultModel || (current.models && current.models[0]) || null,
            streaming: current.streaming !== false,
            lastStatus: lastProbe ? { ok: !!lastProbe.ok, at: Date.now(), message: lastProbe.message || null } : null
        };
        if (current._editing) {
            window.QSConnectionsStore.updateConnection(current._editing, payload);
        } else {
            window.QSConnectionsStore.addConnection(payload);
        }
        closeWizard();
        renderList();
    }

    // ---------- utils ----------

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
})();
