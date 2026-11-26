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
 * Its words: ai-connections.php hands the `aiConnections` section; every word is read by its key's whole path
 * through QSPanelWords.
 */
(function () {
    'use strict';

    const STORAGE = window.QuickSiteStorageKeys;

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
                text: QSPanelWords.t('aiConnections.emptyAdd'),
                onclick: function () { openWizard(); }
            });
            root.appendChild(QSDom.el('div', {
                style: 'padding: var(--space-lg) 0; text-align:center;'
            }, [
                QSDom.el('p', { text: QSPanelWords.t('aiConnections.empty') }),
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
                text: QSPanelWords.t('aiConnections.noKey')
            });
        const modelCount = (c.enabledModels || c.models || []).length;
        const streamingTag = QSPanelWords.t(c.streaming
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
                    title: QSPanelWords.t('aiConnections.setDefaultTitle'),
                    text: star
                }),
                QSDom.el('span', { class: 'qsac-name', text: c.name }),
                QSDom.el('span', {
                    class: 'qsac-status',
                    'data-qsac-action': 'test',
                    title: QSPanelWords.t('aiConnections.testTitle')
                }, [statusDot(status)])
            ]),
            QSDom.el('div', { class: 'qsac-card__meta' }, [
                QSDom.el('span', {
                    class: 'qsac-type qsac-type--' + c.type,
                    text: QSPanelWords.t(c.type === 'local' ? 'aiConnections.typeLocal' : 'aiConnections.typeCloud')
                }),
                QSDom.el('span', { text: ' · ' + label }),
                QSDom.el('span', null, [' · ', keyHint]),
                QSDom.el('span', {
                    text: ' · ' + QSPanelWords.count('aiConnections.modelCount', modelCount)
                }),
                QSDom.el('span', { text: ' · ' + streamingTag })
            ]),
            QSDom.el('div', { class: 'qsac-card__actions' }, [
                QSDom.el('button', {
                    type: 'button', class: 'admin-btn', 'data-qsac-action': 'default', text: QSPanelWords.t('aiConnections.setDefault')
                }),
                QSControls.edit(QSPanelWords.t('aiConnections.editNamed', { name: c.name }), null, {
                    focusKey: 'edit:' + c.id, attrs: { 'data-qsac-action': 'edit' }
                }),
                QSControls.bin(QSPanelWords.t('aiConnections.deleteNamed', { name: c.name }), null, {
                    focusKey: 'delete:' + c.id, attrs: { 'data-qsac-action': 'delete' }
                })
            ])
        ]);
    }

    /** The connection's status dot. @returns {HTMLElement} */
    function statusDot(status) {
        if (!status) {
            return QSDom.el('span', {
                class: 'qsac-dot qsac-dot--unknown',
                title: QSPanelWords.t('aiConnections.neverTested'),
                text: '◌'
            });
        }
        const ageMs = Date.now() - (status.at || 0);
        if (status.ok) {
            const cls = ageMs < 5 * 60 * 1000 ? 'qsac-dot--ok' : 'qsac-dot--stale';
            return QSDom.el('span', {
                class: 'qsac-dot ' + cls,
                title: QSPanelWords.t('aiConnections.statusOk', { ago: formatAgo(ageMs) }),
                text: '●'
            });
        }
        return QSDom.el('span', {
            class: 'qsac-dot qsac-dot--err',
            title: status.message || QSPanelWords.t('aiConnections.statusFailed'),
            text: '●'
        });
    }

    function formatAgo(ms) {
        if (ms < 1000) return QSPanelWords.t('aiConnections.justNow');
        if (ms < 60_000) return QSPanelWords.t('aiConnections.secondsAgo', { count: Math.round(ms / 1000) });
        if (ms < 3_600_000) return QSPanelWords.t('aiConnections.minutesAgo', { count: Math.round(ms / 60_000) });
        return QSPanelWords.t('aiConnections.hoursAgo', { count: Math.round(ms / 3_600_000) });
    }

    function maskKey(key) {
        if (!key) return '';
        const k = String(key);
        if (k.length <= 8) return '•••';
        return k.slice(0, 4) + '…' + k.slice(-4);
    }

    async function onCardAction(ev) {
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
            // The list is drawn again before the question closes, so the focus goes to the next card's bin.
            await QuickSiteUtils.confirm('', {
                title: QSPanelWords.t('aiConnections.confirmDelete', { name: conn.name }),
                type: 'danger',
                confirmText: QSPanelWords.t('aiConnections.delete'),
                action: () => {
                    window.QSConnectionsStore.removeConnection(id);
                    renderList();
                    return true;
                }
            });
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
            // "Run without stopping" is off until switched on (the AI tools runner reads it the same way).
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
        if (modal) modal.style.display = 'flex';
        document.getElementById('qsac-modal-title').textContent = QSPanelWords.t(existing
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
            sub: QSPanelWords.t('aiConnections.kindsCloud'), emojiFallback: k.icon
        })));

        const localRow = QSDom.el('div', { class: 'qsac-kinds' });
        presets.forEach((p) => localRow.appendChild(kindTile({
            kind: 'local', data: { preset: p.id },
            logoId: p.id, name: p.name,
            sub: QSPanelWords.t('aiConnections.typeLocal'), emojiFallback: p.icon
        })));
        localRow.appendChild(kindTile({
            kind: 'custom', data: null,
            logoId: 'custom', name: QSPanelWords.t('aiConnections.kindCustom'),
            sub: QSPanelWords.t('aiConnections.kindCustomSub'), emojiFallback: '⚙️'
        }));

        QSDom.clear(body);
        body.appendChild(QSDom.el('p', { text: QSPanelWords.t('aiConnections.kindQuestion') }));
        body.appendChild(QSDom.el('h4', {
            class: 'qsac-kinds-heading', text: QSPanelWords.t('aiConnections.kindsCloud')
        }));
        body.appendChild(cloudRow);
        body.appendChild(QSDom.el('h4', {
            class: 'qsac-kinds-heading', text: QSPanelWords.t('aiConnections.kindsLocal')
        }));
        body.appendChild(localRow);

        QSDom.clear(footer);
        footer.appendChild(QSDom.el('button', {
            type: 'button',
            class: 'admin-btn',
            'data-qsac-close': '',
            text: QSPanelWords.t('common.cancel'),
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
                name: QSPanelWords.t('aiConnections.kindCustom'),
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
                class: 'admin-label', for: 'qsac-name', text: QSPanelWords.t('aiConnections.nameLabel')
            }),
            QSDom.el('input', {
                type: 'text', id: 'qsac-name', class: 'admin-input',
                value: current.name || '',
                placeholder: QSPanelWords.t('aiConnections.namePlaceholder', {
                    provider: cat ? cat.name : QSPanelWords.t('aiConnections.nameFallback')
                })
            })
        ]));

        if (isLocal) {
            body.appendChild(QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', {
                    class: 'admin-label', for: 'qsac-baseurl',
                    text: QSPanelWords.t('aiConnections.baseUrlLabel')
                }),
                QSDom.el('input', {
                    type: 'text', id: 'qsac-baseurl',
                    class: 'admin-input',
                    value: current.baseUrl || '',
                    placeholder: 'http://localhost:11434/v1'
                }),
                // One sentence, its {path} the path drawn as code.
                QSDom.el('p', { class: 'admin-hint' }, QSPanelWords.parts('aiConnections.baseUrlHint', {
                    path: QSDom.el('code', { text: '/chat/completions' })
                }))
            ]));
        }

        const keyGroup = QSDom.el('div', { class: 'admin-form-group' }, [
            QSDom.el('label', {
                class: 'admin-label', for: 'qsac-key',
                text: QSPanelWords.t(isLocal ? 'aiConnections.apiKeyLabelOptional' : 'aiConnections.apiKeyLabel')
            }),
            QSDom.el('div', {}, [
                QSDom.el('input', {
                    type: 'password', id: 'qsac-key',
                    class: 'admin-input',
                    autocomplete: 'off', spellcheck: 'false',
                    value: current.key || '',
                    placeholder: QSPanelWords.t(isLocal
                        ? 'aiConnections.keyPlaceholderLocal'
                        : 'aiConnections.keyPlaceholderCloud')
                }),
                QSDom.el('button', {
                    type: 'button', class: 'admin-btn',
                    id: 'qsac-key-toggle', title: QSPanelWords.t('aiConnections.keyToggleTitle')
                }, [QSDom.iconEl(QuickSiteUtils.ICON_PATHS.eye, 18)])
            ])
        ]);
        if (keyUrl) {
            keyGroup.appendChild(QSDom.el('p', { class: 'admin-hint' }, [
                QSPanelWords.t('aiConnections.getKeyPrefix') + ' ',
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
                    text: QSPanelWords.t('aiConnections.streamingLabel')
                })
            ])
        ]));
        // A stored limit the catalog would ignore is shown, and saved, as the default.
        if (!_outputLimitValid(current.maxOutputTokens)) current.maxOutputTokens = null;
        body.appendChild(_renderOutputLimitField(current));

        body.appendChild(QSDom.el('div', {
            id: 'qsac-probe', class: 'qsac-probe qsac-probe--idle'
        }, [
            QSDom.el('div', {
                class: 'qsac-probe__msg',
                text: QSPanelWords.t(isLocal ? 'aiConnections.probeIdleLocal' : 'aiConnections.probeIdleCloud')
            })
        ]));

        if (isLocal && current._preset) {
            const hint = renderLocalCorsHint(current._preset);
            if (hint) body.appendChild(hint);
        }

        QSDom.clear(footer);
        footer.appendChild(QSDom.el('button', {
            type: 'button', class: 'admin-btn', id: 'qsac-back',
            text: current._editing ? QSPanelWords.t('common.cancel') : QSPanelWords.t('aiConnections.back')
        }));
        const saveBtn = QSDom.el('button', {
            type: 'button', class: 'admin-btn admin-btn--primary', id: 'qsac-save',
            text: current._editing
                ? QSPanelWords.t('aiConnections.saveChanges')
                : QSPanelWords.t('aiConnections.addConnectionBtn')
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
        const limitEl = document.getElementById('qsac-max-output');
        limitEl.addEventListener('input', () => {
            const raw = limitEl.value.trim();
            current.maxOutputTokens = raw === '' ? NaN : Number(raw);
            refreshSaveBtn();
        });

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
                text: QSPanelWords.t('aiConnections.corsSummary', { summary: preset.corsHint.summary })
            }),
            QSDom.el('pre', { class: 'qsac-cors-cmd', text: text })
        ]);
    }

    function detectOS() {
        const p = (navigator.userAgent || '').toLowerCase();
        if (p.indexOf('win') !== -1) return 'windows';
        if (p.indexOf('mac') !== -1) return 'macos';
        return 'linux';
    }

    /**
     * The output limit's field: the most the AI may write in one answer, in tokens. It shows the
     * connection's own limit, or the catalog's default until one is typed.
     * @returns {HTMLElement}
     */
    function _renderOutputLimitField(conn) {
        const cat = window.QSProviderCatalog;
        const range = cat.MAX_OUTPUT_TOKENS_RANGE;
        return QSDom.el('div', { class: 'admin-form-group' }, [
            QSDom.el('label', {
                class: 'admin-label', for: 'qsac-max-output', text: QSPanelWords.t('aiConnections.maxOutputTokensLabel')
            }),
            QSDom.el('input', {
                type: 'number', id: 'qsac-max-output', class: 'admin-input',
                min: String(range.min), max: String(range.max), step: '1',
                value: String(cat.maxOutputTokens(conn))
            }),
            QSDom.el('p', { class: 'admin-hint', id: 'qsac-max-output-hint', text: _outputLimitHint(true) })
        ]);
    }

    /** The limit's hint: what it is, or the range it must be in. @returns {string} */
    function _outputLimitHint(valid) {
        const cat = window.QSProviderCatalog;
        return valid
            ? QSPanelWords.t('aiConnections.maxOutputTokensHint', { default: cat.DEFAULT_MAX_OUTPUT_TOKENS })
            : QSPanelWords.t('aiConnections.maxOutputTokensInvalid', cat.MAX_OUTPUT_TOKENS_RANGE);
    }

    /** Whether the limit typed may be saved: none typed (the shown value stays), or a whole number in range. */
    function _outputLimitValid(n) {
        if (n === undefined || n === null) return true;
        const range = window.QSProviderCatalog.MAX_OUTPUT_TOKENS_RANGE;
        return Number.isInteger(n) && n >= range.min && n <= range.max;
    }

    function refreshSaveBtn() {
        const btn = document.getElementById('qsac-save');
        if (!btn) return;
        const hasName = !!(current.name && current.name.length);
        const hasAuth = current.type === 'local' ? !!(current.baseUrl) : !!(current.key);
        const limitOk = _outputLimitValid(current.maxOutputTokens);
        const limitEl = document.getElementById('qsac-max-output');
        const hintEl = document.getElementById('qsac-max-output-hint');
        if (limitEl) limitEl.classList.toggle('admin-input--error', !limitOk);
        if (hintEl) hintEl.textContent = _outputLimitHint(limitOk);
        // Allow save without successful probe — user can save and test later.
        btn.disabled = !(hasName && hasAuth && limitOk);
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

        setProbeUI('busy', QSPanelWords.t(current.type === 'local'
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
                setProbeUI('ok', QSPanelWords.count('aiConnections.probeOk', r.models.length));
                renderModelPicker(r.models);
            } else {
                setProbeUI('err', r.category
                    ? QSPanelWords.t('aiConnections.probeErrCategory', {
                        message: r.message || QSPanelWords.t('aiConnections.failed'),
                        category: r.category })
                    : QSPanelWords.t('aiConnections.probeErr', {
                        message: r.message || QSPanelWords.t('aiConnections.failed') }));
            }
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            setProbeUI('err', QSPanelWords.t('aiConnections.probeErr', {
                message: e.message || QSPanelWords.t('aiConnections.testFailed') }));
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
                QSDom.el('span', { text: QSPanelWords.t('aiConnections.enabledModels') }),
                QSDom.el('span', null, [
                    QSDom.el('a', {
                        href: '#', 'data-qsac-models': 'all', text: QSPanelWords.t('aiConnections.selectAll')
                    }),
                    ' · ',
                    QSDom.el('a', {
                        href: '#', 'data-qsac-models': 'none', text: QSPanelWords.t('aiConnections.selectNone')
                    })
                ])
            ]),
            list,
            QSDom.el('div', { class: 'qsac-models__default' }, [
                QSPanelWords.t('aiConnections.defaultModel') + ' ',
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
            maxOutputTokens: Number.isInteger(current.maxOutputTokens) ? current.maxOutputTokens : null,
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
