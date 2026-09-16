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

    /**
     * Resolve one admin string by its FULL dot path, from the sub-trees
     * ai-connections.php emits. A path that resolves to nothing returns THE
     * PATH ITSELF, so an unset string is visible on screen.
     *
     * @param {string} path      e.g. 'aiConnections.empty'
     * @param {Object} [params]  :name markers, as PHP's t() does
     * @returns {string}
     */
    function t(path, params) {
        let node = window.QS_AI_CONNECTIONS_I18N || {};
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

    /**
     * A provider logo, falling back to an emoji when the SVG is missing.
     *
     * The markup this replaces carried the fallback as an inline onerror that
     * built a <span> from a JS string inside an HTML attribute inside a
     * template literal — three nested quoting contexts, guarded by one text
     * escaper. A listener needs none of them.
     *
     * @returns {HTMLElement} the <img>, which swaps itself for a <span>
     */
    function _renderLogo(logoId, emojiFallback, imgClass, emojiClass) {
        const img = QSDom.el('img', {
            src: window.QSAC_ASSET_BASE + '/images/providers/' + logoId + '.svg',
            alt: ''
        });
        if (imgClass) img.className = imgClass;
        img.addEventListener('error', function () {
            const span = QSDom.el('span', { class: emojiClass, text: emojiFallback });
            if (img.parentNode) img.parentNode.replaceChild(span, img);
        });
        return img;
    }

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
        QSDom.clear(root);
        if (conns.length === 0) {
            const addBtn = QSDom.el('button', {
                type: 'button',
                class: 'admin-btn admin-btn--primary',
                id: 'qsac-empty-add',
                text: t('aiConnections.emptyAdd'),
                onclick: function () { openWizard(); }
            });
            root.appendChild(QSDom.el('div', {
                class: 'admin-empty-state',
                style: 'padding: var(--space-lg) 0; text-align:center;'
            }, [
                QSDom.el('p', { class: 'admin-text-muted', text: t('aiConnections.empty') }),
                addBtn
            ]));
            return;
        }
        conns.forEach((c) => {
            root.appendChild(renderCard(c, store.defaultConnectionId === c.id));
        });

        root.querySelectorAll('[data-qsac-action]').forEach((el) => {
            el.addEventListener('click', onCardAction);
        });
    }

    /** One connection card. @returns {HTMLElement} */
    function renderCard(c, isDefault) {
        const star = isDefault ? '⭐' : '☆';
        const status = c.lastStatus;
        const label = c.providerType === 'openai-compatible' && c.baseUrl
            ? c.baseUrl
            : (window.QSProviderCatalog.get(c.providerType) || {}).name || c.providerType;
        const keyHint = c.key
            ? QSDom.el('span', { class: 'qsac-key-hint', text: maskKey(c.key) })
            : QSDom.el('span', {
                class: 'qsac-key-hint qsac-key-hint--none',
                text: t('aiConnections.noKey')
            });
        const modelCount = (c.enabledModels || c.models || []).length;
        const streamingTag = t(c.streaming
            ? 'aiConnections.streamingOn' : 'aiConnections.streamingOff');

        // Pick a logo: cloud → providerType id; local → preset id (if known)
        // or 'custom' for openai-compatible without a preset.
        // For older connections without _preset, sniff by baseUrl port.
        const logoId = c.type === 'local'
            ? (c._preset || sniffPresetFromUrl(c.baseUrl) || 'custom')
            : c.providerType;
        const emoji = c.type === 'local'
            ? (logoId === 'ollama' ? '🦙' : logoId === 'lm-studio' ? '🎛️' : '⚙️')
            : emojiFor(c.providerType);

        return QSDom.el('div', { class: 'qsac-card', dataset: { connId: c.id } }, [
            QSDom.el('div', { class: 'qsac-card__head' }, [
                QSDom.el('span', { class: 'qsac-card__logo', 'aria-hidden': 'true' },
                    [_renderLogo(logoId, emoji, '', 'qsac-card__logo-emoji')]),
                QSDom.el('span', {
                    class: 'qsac-star',
                    'data-qsac-action': 'default',
                    title: t('aiConnections.setDefaultTitle'),
                    text: star
                }),
                QSDom.el('span', { class: 'qsac-name', text: c.name }),
                QSDom.el('span', {
                    class: 'qsac-status',
                    'data-qsac-action': 'test',
                    title: t('aiConnections.testTitle')
                }, [statusDot(status)])
            ]),
            QSDom.el('div', { class: 'qsac-card__meta' }, [
                QSDom.el('span', {
                    class: 'qsac-type qsac-type--' + c.type,
                    text: t(c.type === 'local' ? 'aiConnections.typeLocal' : 'aiConnections.typeCloud')
                }),
                QSDom.el('span', { text: ' · ' + label }),
                QSDom.el('span', null, [' · ', keyHint]),
                QSDom.el('span', {
                    text: ' · ' + t(modelCount === 1
                        ? 'aiConnections.modelCountOne'
                        : 'aiConnections.modelCountMany', { count: modelCount })
                }),
                QSDom.el('span', { text: ' · ' + streamingTag })
            ]),
            QSDom.el('div', { class: 'qsac-card__actions' }, [
                _renderCardAction('edit', t('aiConnections.edit')),
                _renderCardAction('default', t('aiConnections.setDefault')),
                _renderCardAction('delete', t('aiConnections.delete'), 'admin-btn--danger')
            ])
        ]);
    }

    /** One card action button. @returns {HTMLElement} */
    function _renderCardAction(action, label, extraClass) {
        return QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--secondary admin-btn--small'
                + (extraClass ? ' ' + extraClass : ''),
            'data-qsac-action': action,
            text: label
        });
    }

    /** The connection's status dot. @returns {HTMLElement} */
    function statusDot(status) {
        if (!status) {
            return QSDom.el('span', {
                class: 'qsac-dot qsac-dot--unknown',
                title: t('aiConnections.neverTested'),
                text: '◌'
            });
        }
        const ageMs = Date.now() - (status.at || 0);
        if (status.ok) {
            const cls = ageMs < 5 * 60 * 1000 ? 'qsac-dot--ok' : 'qsac-dot--stale';
            return QSDom.el('span', {
                class: 'qsac-dot ' + cls,
                title: t('aiConnections.statusOk', { ago: formatAgo(ageMs) }),
                text: '●'
            });
        }
        return QSDom.el('span', {
            class: 'qsac-dot qsac-dot--err',
            title: status.message || t('aiConnections.statusFailed'),
            text: '●'
        });
    }

    function formatAgo(ms) {
        if (ms < 1000) return t('aiConnections.justNow');
        if (ms < 60_000) return t('aiConnections.secondsAgo', { count: Math.round(ms / 1000) });
        if (ms < 3_600_000) return t('aiConnections.minutesAgo', { count: Math.round(ms / 60_000) });
        return t('aiConnections.hoursAgo', { count: Math.round(ms / 3_600_000) });
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
            if (!window.confirm(t('aiConnections.confirmDelete', { name: conn.name }))) return;
            window.QSConnectionsStore.removeConnection(id);
            renderList();
        } else if (action === 'edit') {
            openWizard(conn);
        } else if (action === 'test') {
            inlineTest(conn);
        }
    }

    async function inlineTest(conn) {
        const card = document.querySelector(`.qsac-card[data-conn-id="${CSS.escape(conn.id)}"] .qsac-status`);
        if (card) {
            QSDom.clear(card);
            card.appendChild(QSDom.el('span', {
                class: 'qsac-dot qsac-dot--unknown', text: '⏳'
            }));
        }
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
        const autoExec = document.getElementById('qsac-auto-execute');
        if (persist) {
            persist.checked = localStorage.getItem(STORAGE.aiPersist) === 'true';
            persist.addEventListener('change', () => {
                localStorage.setItem(STORAGE.aiPersist, persist.checked ? 'true' : 'false');
            });
        }
        if (autoExec) {
            autoExec.checked = localStorage.getItem(STORAGE.aiAutoExecute) !== 'false';
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
        document.getElementById('qsac-modal-title').textContent = t(existing
            ? 'aiConnections.editConnection'
            : 'aiConnections.modalTitle');
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

        const cloudRow = QSDom.el('div', { class: 'qsac-kinds' });
        cloudKinds.forEach((k) => cloudRow.appendChild(kindTile({
            kind: 'cloud', data: { provider: k.id },
            logoId: k.id, name: k.name,
            sub: t('aiConnections.kindsCloud'), emojiFallback: k.icon
        })));

        const localRow = QSDom.el('div', { class: 'qsac-kinds' });
        presets.forEach((p) => localRow.appendChild(kindTile({
            kind: 'local', data: { preset: p.id },
            logoId: p.id, name: p.name,
            sub: t('aiConnections.typeLocal'), emojiFallback: p.icon
        })));
        localRow.appendChild(kindTile({
            kind: 'custom', data: null,
            logoId: 'custom', name: t('aiConnections.kindCustom'),
            sub: t('aiConnections.kindCustomSub'), emojiFallback: '⚙️'
        }));

        QSDom.clear(body);
        body.appendChild(QSDom.el('p', { text: t('aiConnections.kindQuestion') }));
        body.appendChild(QSDom.el('h4', {
            class: 'qsac-kinds-heading', text: t('aiConnections.kindsCloud')
        }));
        body.appendChild(cloudRow);
        body.appendChild(QSDom.el('h4', {
            class: 'qsac-kinds-heading', text: t('aiConnections.kindsLocal')
        }));
        body.appendChild(localRow);

        QSDom.clear(footer);
        footer.appendChild(QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--secondary',
            'data-qsac-close': '',
            text: t('common.cancel'),
            onclick: closeWizard
        }));
        body.querySelectorAll('[data-kind]').forEach((b) => b.addEventListener('click', onKindPick));
    }

    /**
     * One pickable "kind" tile in the wizard's first step.
     * @returns {HTMLElement} one <button>
     */
    function kindTile({ kind, data, logoId, name, sub, emojiFallback }) {
        const btn = QSDom.el('button', {
            type: 'button', class: 'qsac-kind', 'data-kind': kind
        }, [
            QSDom.el('span', { class: 'qsac-kind__icon' },
                [_renderLogo(logoId, emojiFallback, 'qsac-kind__logo', 'qsac-kind__emoji')]),
            QSDom.el('span', { class: 'qsac-kind__label', text: name }),
            QSDom.el('span', { class: 'qsac-kind__sub', text: sub })
        ]);
        if (data) Object.assign(btn.dataset, data);
        return btn;
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
                name: t('aiConnections.kindCustom'),
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

        QSDom.clear(body);

        body.appendChild(QSDom.el('div', { class: 'admin-form-group' }, [
            QSDom.el('label', {
                class: 'admin-label', for: 'qsac-name', text: t('aiConnections.nameLabel')
            }),
            QSDom.el('input', {
                type: 'text', id: 'qsac-name', class: 'admin-input',
                value: current.name || '',
                placeholder: t('aiConnections.namePlaceholder', {
                    provider: cat ? cat.name : t('aiConnections.nameFallback')
                })
            })
        ]));

        if (isLocal) {
            body.appendChild(QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', {
                    class: 'admin-label', for: 'qsac-baseurl',
                    text: t('aiConnections.baseUrlLabel')
                }),
                QSDom.el('input', {
                    type: 'text', id: 'qsac-baseurl',
                    class: 'admin-input admin-input--monospace',
                    value: current.baseUrl || '',
                    placeholder: 'http://localhost:11434/v1'
                }),
                _renderPathHint('aiConnections.baseUrlHint', '/chat/completions')
            ]));
        }

        const keyGroup = QSDom.el('div', { class: 'admin-form-group' }, [
            QSDom.el('label', {
                class: 'admin-label', for: 'qsac-key',
                text: t(isLocal ? 'aiConnections.apiKeyLabelOptional' : 'aiConnections.apiKeyLabel')
            }),
            QSDom.el('div', { class: 'admin-input-group' }, [
                QSDom.el('input', {
                    type: 'password', id: 'qsac-key',
                    class: 'admin-input admin-input--monospace',
                    autocomplete: 'off', spellcheck: 'false',
                    value: current.key || '',
                    placeholder: t(isLocal
                        ? 'aiConnections.keyPlaceholderLocal'
                        : 'aiConnections.keyPlaceholderCloud')
                }),
                QSDom.el('button', {
                    type: 'button', class: 'admin-btn admin-btn--icon',
                    id: 'qsac-key-toggle', title: t('aiConnections.keyToggleTitle')
                }, [QSDom.iconEl(QuickSiteUtils.ICON_PATHS.eye, 18)])
            ])
        ]);
        if (keyUrl) {
            keyGroup.appendChild(QSDom.el('p', { class: 'admin-hint' }, [
                t('aiConnections.getKeyPrefix') + ' ',
                QSDom.el('a', {
                    href: keyUrl, target: '_blank', rel: 'noopener', text: keyUrl
                })
            ]));
        }
        body.appendChild(keyGroup);

        const streamingCb = QSDom.el('input', { type: 'checkbox', id: 'qsac-streaming' });
        streamingCb.checked = current.streaming !== false;
        body.appendChild(QSDom.el('div', { class: 'admin-form-group' }, [
            QSDom.el('label', { class: 'admin-checkbox' }, [
                streamingCb,
                QSDom.el('span', {
                    class: 'admin-checkbox__label', text: t('aiConnections.streamingLabel')
                })
            ])
        ]));

        body.appendChild(QSDom.el('div', {
            id: 'qsac-probe', class: 'qsac-probe qsac-probe--idle'
        }, [
            QSDom.el('div', {
                class: 'qsac-probe__msg',
                text: t(isLocal ? 'aiConnections.probeIdleLocal' : 'aiConnections.probeIdleCloud')
            })
        ]));

        if (isLocal && current._preset) {
            const hint = renderLocalCorsHint(current._preset);
            if (hint) body.appendChild(hint);
        }

        QSDom.clear(footer);
        footer.appendChild(QSDom.el('button', {
            type: 'button', class: 'admin-btn admin-btn--secondary', id: 'qsac-back',
            text: current._editing ? t('common.cancel') : t('aiConnections.back')
        }));
        const saveBtn = QSDom.el('button', {
            type: 'button', class: 'admin-btn admin-btn--primary', id: 'qsac-save',
            text: current._editing
                ? t('aiConnections.saveChanges')
                : t('aiConnections.addConnectionBtn')
        });
        saveBtn.disabled = true;
        footer.appendChild(saveBtn);
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

        // Probe at once whenever the form opens already holding what a probe
        // needs: an edited connection, or a local preset whose base URL is
        // pre-filled. Otherwise the model list stays empty until the field is
        // edited, because only an input event schedules a probe.
        if (current.type === 'local' ? current.baseUrl : current.key) scheduleProbe(0);
        refreshSaveBtn();
    }

    /** The CORS advice for a local preset. @returns {HTMLElement|null} */
    function renderLocalCorsHint(presetId) {
        const preset = window.QSLocalPresets.get(presetId);
        if (!preset || !preset.corsHint) return null;
        const os = detectOS();
        const ins = preset.corsHint.instructions || {};
        const text = ins[os] || ins.all || ins.linux || '';
        return QSDom.el('details', { class: 'qsac-cors-hint' }, [
            QSDom.el('summary', {
                text: t('aiConnections.corsSummary', { summary: preset.corsHint.summary })
            }),
            QSDom.el('pre', { class: 'qsac-cors-cmd', text: text })
        ]);
    }

    /**
     * A hint sentence whose :path marker becomes a <code> element — the
     * whole-sentence-key convention, so a translator gets one string rather
     * than three fragments.
     * @returns {HTMLElement} one <p class="admin-hint">
     */
    function _renderPathHint(key, code) {
        const parts = t(key).split(':path');
        return QSDom.el('p', { class: 'admin-hint' }, [
            parts[0],
            QSDom.el('code', { text: code }),
            parts.slice(1).join(':path')
        ]);
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

        setProbeUI('busy', t(current.type === 'local'
            ? 'aiConnections.probeLocal'
            : 'aiConnections.probeCloud'));
        try {
            const r = await window.QSAiCall.test(current, probeAbort.signal);
            lastProbe = r;
            if (r.ok) {
                if (r.models && r.models.length) {
                    current.models = r.models;
                    if (!current.enabledModels || !current.enabledModels.length) current.enabledModels = r.models.slice();
                    if (!current.defaultModel) current.defaultModel = r.models[0];
                }
                setProbeUI('ok', t(r.models.length === 1
                    ? 'aiConnections.probeOkOne'
                    : 'aiConnections.probeOkMany', { count: r.models.length }));
                renderModelPicker(r.models);
            } else {
                setProbeUI('err', r.category
                    ? t('aiConnections.probeErrCategory', {
                        message: r.message || t('aiConnections.failed'),
                        category: r.category })
                    : t('aiConnections.probeErr', {
                        message: r.message || t('aiConnections.failed') }));
            }
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            setProbeUI('err', t('aiConnections.probeErr', {
                message: e.message || t('aiConnections.testFailed') }));
        }
    }

    function setProbeUI(state, msg) {
        const el = document.getElementById('qsac-probe');
        if (!el) return;
        el.className = 'qsac-probe qsac-probe--' + state;
        QSDom.clear(el);
        el.appendChild(QSDom.el('div', { class: 'qsac-probe__msg', text: msg }));
        el.appendChild(QSDom.el('div', { id: 'qsac-probe-models' }));
    }

    function renderModelPicker(models) {
        const slot = document.getElementById('qsac-probe-models');
        if (!slot || !models || !models.length) return;
        const enabled = new Set(current.enabledModels || models);

        const list = QSDom.el('div', { class: 'qsac-models__list' });
        models.forEach((m) => {
            const cb = QSDom.el('input', { type: 'checkbox', dataset: { model: m } });
            cb.checked = enabled.has(m);
            list.appendChild(QSDom.el('label', { class: 'qsac-model' }, [
                cb, QSDom.el('span', { text: m })
            ]));
        });

        const select = QSDom.el('select', { id: 'qsac-default-model' });
        models.forEach((m) => {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = m;
            if (m === current.defaultModel) opt.selected = true;
            select.appendChild(opt);
        });

        QSDom.clear(slot);
        slot.appendChild(QSDom.el('div', { class: 'qsac-models' }, [
            QSDom.el('div', { class: 'qsac-models__head' }, [
                QSDom.el('span', { text: t('aiConnections.enabledModels') }),
                QSDom.el('span', null, [
                    QSDom.el('a', {
                        href: '#', 'data-qsac-models': 'all', text: t('aiConnections.selectAll')
                    }),
                    ' · ',
                    QSDom.el('a', {
                        href: '#', 'data-qsac-models': 'none', text: t('aiConnections.selectNone')
                    })
                ])
            ]),
            list,
            QSDom.el('div', { class: 'qsac-models__default' }, [
                t('aiConnections.defaultModel') + ' ',
                select
            ])
        ]));
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

})();
