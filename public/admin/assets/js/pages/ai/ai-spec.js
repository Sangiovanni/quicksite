/**
 * AI Spec Page JavaScript
 * Handles AI workflow execution, manual workflow execution, and prompt generation.
 *
 * Dependencies:
 * - QuickSiteAdmin global (from admin-common.js)
 * - specId, isCreateSpec, managementUrl, token passed via data attributes on .ai-spec
 */
(function() {
    'use strict';
    console.log('[ai-spec] v2-debug loaded');

    // ========================================================================
    // 1. UTILITIES — pure functions, no side effects
    // ========================================================================

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatParams(params) {
        if (!params || Object.keys(params).length === 0) return '';
        const parts = [];
        for (const [key, value] of Object.entries(params)) {
            if (typeof value === 'object') {
                parts.push(`${key}={...}`);
            } else if (typeof value === 'string' && value.length > 50) {
                parts.push(`${key}="${value.substring(0, 50)}..."`);
            } else {
                parts.push(`${key}=${JSON.stringify(value)}`);
            }
        }
        return parts.join(', ');
    }

    function getMethod(cmd) {
        if (cmd.method) return cmd.method.toUpperCase();
        if (cmd.params && Object.keys(cmd.params).length > 0) return 'POST';
        return 'GET';
    }

    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    function evaluateCondition(condition, formData) {
        try {
            let expr = condition;
            for (const [key, value] of Object.entries(formData)) {
                let actualValue = value;
                if (value === 'true') actualValue = true;
                else if (value === 'false' || value === '') actualValue = false;
                const regex = new RegExp(`\\b${key}\\b`, 'g');
                expr = expr.replace(regex, JSON.stringify(actualValue));
            }
            expr = expr.replace(/\b[a-zA-Z_][a-zA-Z0-9_]*\b(?!\s*:)/g, (match) => {
                if (match === 'true' || match === 'false' || match === 'null') return match;
                return 'false';
            });
            return eval(expr);
        } catch (e) {
            console.warn('Condition evaluation failed:', condition, e);
            return true;
        }
    }

    function formatParamsDisplay(params) {
        if (!params) return '(none)';
        return JSON.stringify(params, null, 2);
    }

    function formatResponseDisplay(data) {
        const display = { ...data };
        if (display.data && JSON.stringify(display.data).length > 500) {
            display.data = '(truncated - large data)';
        }
        return JSON.stringify(display, null, 2);
    }

    /** Normalize AI command: move top-level keys into .params */
    function normalizeCommand(cmd) {
        if (!cmd.params) {
            const params = {};
            for (const [key, value] of Object.entries(cmd)) {
                if (key !== 'command' && key !== 'method') {
                    params[key] = value;
                }
            }
            if (Object.keys(params).length > 0) {
                cmd.params = params;
                for (const key of Object.keys(params)) delete cmd[key];
            }
        }
        return cmd;
    }

    /** Collect form params, skipping hidden conditional groups */
    function collectFormParams(form) {
        const params = {};
        if (!form) return params;
        form.querySelectorAll('input, select, textarea').forEach(input => {
            if (!input.name) return;
            if (input.closest('.ai-spec-form__group--hidden')) return;
            if (input.type === 'checkbox') {
                params[input.name] = input.checked ? 'true' : 'false';
            } else if (input.value) {
                params[input.name] = input.value;
            }
        });
        return params;
    }

    // ========================================================================
    // 2. HTML TEMPLATE FUNCTIONS — all inline HTML in one place
    // ========================================================================

    /** Pending result item (before execution) */
    function htmlPendingItem(id, command, message, prefix) {
        prefix = prefix || '';
        return `<div class="result-item result-item--pending" id="${id}">
            <span class="result-item__icon">⏳</span>
            <div class="result-item__content">
                <div class="result-item__command">${prefix}${escapeHtml(command)}</div>
                <div class="result-item__message">${escapeHtml(message)}</div>
            </div>
        </div>`;
    }

    /** Result item with expandable details (success or error) */
    function htmlResultItem(opts) {
        // opts: { id, icon, prefix, command, statusClass, statusMessage, detailsId, sections[] }
        // sections: [{ label, value }]
        const toggleBtn = opts.detailsId
            ? ` <span class="result-item__toggle" onclick="document.getElementById('${opts.detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>`
            : '';

        let detailsHtml = '';
        if (opts.detailsId && opts.sections) {
            detailsHtml = `<div class="result-item__details" id="${opts.detailsId}">
                ${opts.sections.map(s => `<div class="result-item__details-section">
                    <div class="result-item__details-label">${s.label}</div>
                    <div class="result-item__details-value">${s.value}</div>
                </div>`).join('')}
            </div>`;
        }

        const messageClass = opts.isError ? 'result-item__error' : 'result-item__message';

        return `<span class="result-item__icon">${opts.icon}</span>
            <div class="result-item__content">
                <div class="result-item__command">${opts.prefix || ''}${opts.command}${toggleBtn}</div>
                <div class="${messageClass}">${opts.statusMessage}</div>
                ${detailsHtml}
            </div>`;
    }

    /** Separator line between command groups */
    function htmlSeparator(text) {
        const el = document.createElement('div');
        el.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
        el.textContent = text;
        return el;
    }

    /** Command preview item (in preview list before execution) */
    function htmlCommandPreview(index, method, command, params) {
        const methodClass = 'command-item__method--' + method.toLowerCase();
        return `<span class="command-item__number">${index}</span>
            <div class="command-item__content">
                <div class="command-item__header">
                    <span class="command-item__method ${methodClass}">${method}</span>
                    <span class="command-item__command">${command}</span>
                </div>
                ${params ? `<div class="command-item__params">${formatParams(params)}</div>` : ''}
            </div>`;
    }

    /** Post-command preview item (placeholder before resolution) */
    function htmlPostCommandPreview(index, command, condition) {
        return `<span class="command-item__number">+${index}</span>
            <div class="command-item__content">
                <div class="command-item__header">
                    <span class="command-item__method command-item__method--post">POST</span>
                    <span class="command-item__command">🔧 ${command}</span>
                    <span class="command-item__badge">Auto</span>
                </div>
                ${condition ? `<div class="command-item__params" style="font-style: italic;">Condition: ${condition}</div>` : ''}
            </div>`;
    }

    /** Manual workflow step preview item */
    function htmlManualStepPreview(index, command, params) {
        const paramEntries = Object.entries(params || {});
        return `<div class="command-item__header">
                <span class="command-item__index">${index}</span>
                <span class="command-item__name">${escapeHtml(command)}</span>
            </div>
            <div class="command-item__params">
                ${paramEntries.map(([key, value]) =>
                    `<div class="command-item__param">
                        <span class="command-item__param-key">${escapeHtml(key)}:</span>
                        <span class="command-item__param-value">${escapeHtml(typeof value === 'object' ? JSON.stringify(value) : String(value))}</span>
                    </div>`
                ).join('')}
            </div>`;
    }

    /** Execution summary line */
    function htmlSummary(successCount, failCount, abortedAt) {
        const el = document.createElement('div');
        el.style.cssText = 'text-align: center; padding: 12px; margin-top: 8px; border-top: 1px solid var(--admin-border); font-weight: 500;';
        let html = `✅ ${successCount} succeeded` + (failCount > 0 ? ` &nbsp;|&nbsp; ❌ ${failCount} failed` : '');
        if (abortedAt) html += ` &nbsp;|&nbsp; ⛔ Aborted at: ${abortedAt}`;
        el.innerHTML = html;
        return el;
    }

    /** Status bar shown at top/bottom of execution results */
    function htmlStatusBar(text, status) {
        const el = document.createElement('div');
        const colors = {
            running: 'background: var(--admin-warning-bg, #fff3cd); color: var(--admin-warning-text, #856404); border-color: var(--admin-warning-border, #ffc107);',
            success: 'background: var(--admin-success-bg, #d4edda); color: var(--admin-success-text, #155724); border-color: var(--admin-success-border, #28a745);',
            error: 'background: var(--admin-error-bg, #f8d7da); color: var(--admin-error-text, #721c24); border-color: var(--admin-error-border, #dc3545);'
        };
        el.className = 'execution-status-bar execution-status-bar--' + status;
        el.style.cssText = 'text-align: center; padding: 10px 16px; font-weight: 600; border: 1px solid; border-radius: 6px; margin: 8px 0; ' + (colors[status] || '');
        el.textContent = text;
        return el;
    }

    // ========================================================================
    // 3. AI PROVIDER MANAGER
    // ========================================================================

    const AI_STORAGE_KEYS = {
        keysV2: QuickSiteStorageKeys.aiKeysV2,
        defaultProvider: QuickSiteStorageKeys.aiDefaultProvider,
        persist: QuickSiteStorageKeys.aiPersist,
        legacyKey: 'quicksite_ai_key',
        legacyProvider: 'quicksite_ai_provider',
        legacyModel: 'quicksite_ai_model'
    };

    class AiProviderManager {
        constructor() {
            this.selectedProvider = null;
            this.selectedModel = null;
        }

        getConfig() {
            const persist = localStorage.getItem(AI_STORAGE_KEYS.persist) === 'true';
            const storage = persist ? localStorage : sessionStorage;

            const storedData = storage.getItem(AI_STORAGE_KEYS.keysV2);
            const defaultProvider = storage.getItem(AI_STORAGE_KEYS.defaultProvider);

            if (storedData && defaultProvider) {
                try {
                    const providers = JSON.parse(storedData);
                    const provider = providers[defaultProvider];
                    if (provider && provider.key) {
                        return {
                            key: provider.key,
                            provider: defaultProvider,
                            model: provider.defaultModel,
                            name: provider.name,
                            models: provider.models || [],
                            enabledModels: provider.enabledModels || provider.models || [],
                            configured: true,
                            allProviders: providers
                        };
                    }
                } catch (e) {
                    console.warn('Failed to parse AI config v2:', e);
                }
            }

            // Fallback to v1 format
            const legacyKey = storage.getItem(AI_STORAGE_KEYS.legacyKey);
            if (legacyKey) {
                return {
                    key: legacyKey,
                    provider: storage.getItem(AI_STORAGE_KEYS.legacyProvider),
                    model: storage.getItem(AI_STORAGE_KEYS.legacyModel),
                    configured: true,
                    allProviders: null
                };
            }

            return { key: null, provider: null, model: null, configured: false, allProviders: null };
        }

        getProviderConfig(providerId, modelOverride) {
            const aiConfig = this.getConfig();
            if (!aiConfig.allProviders || !aiConfig.allProviders[providerId]) return aiConfig;
            const provider = aiConfig.allProviders[providerId];
            return {
                key: provider.key,
                provider: providerId,
                model: modelOverride || provider.defaultModel,
                name: provider.name,
                configured: true
            };
        }

        initSelector(wrapperEl, selectorEl) {
            // Phase 3: prefer the v3 connections store. Falls back to v2
            // (legacy aiKeysV2 path) if no v3 connections exist yet — this
            // happens on a fresh install before the user has added anything.
            const v3 = window.QSConnectionsStore && window.QSConnectionsStore.loadStore();
            const conns = (v3 && v3.connections) || [];

            if (conns.length > 0) {
                if (wrapperEl) wrapperEl.style.display = 'block';
                if (!selectorEl) return;

                selectorEl.innerHTML = '';
                const defaultId = v3.defaultConnectionId;

                conns.forEach((conn) => {
                    const models = (conn.enabledModels && conn.enabledModels.length)
                        ? conn.enabledModels
                        : (conn.models && conn.models.length ? conn.models
                            : (conn.defaultModel ? [conn.defaultModel] : []));
                    if (models.length === 0) return; // can't pick a model — skip
                    const dot = conn.type === 'local' ? '🦙' : '☁️';
                    const optgroup = document.createElement('optgroup');
                    optgroup.label = `${dot} ${conn.name}`;
                    models.forEach((model) => {
                        const option = document.createElement('option');
                        // Use connection id (not provider type) so multiple
                        // connections of the same provider remain distinct.
                        option.value = `${conn.id}::${model}`;
                        option.textContent = model;
                        if (conn.id === defaultId && model === (conn.defaultModel || models[0])) {
                            option.selected = true;
                            this.selectedProvider = conn.id;
                            this.selectedModel = model;
                        }
                        optgroup.appendChild(option);
                    });
                    selectorEl.appendChild(optgroup);
                });

                if (!this.selectedProvider && selectorEl.options.length > 0) {
                    selectorEl.selectedIndex = 0;
                    const firstValue = selectorEl.value;
                    if (firstValue) {
                        [this.selectedProvider, this.selectedModel] = firstValue.split('::');
                    }
                }

                selectorEl.addEventListener('change', () => {
                    const value = selectorEl.value;
                    if (value && value.includes('::')) {
                        [this.selectedProvider, this.selectedModel] = value.split('::');
                        // Promote the picked connection to default so
                        // sendToAi()'s getActive() returns it.
                        try { window.QSConnectionsStore.setDefault(this.selectedProvider); } catch (_e) {}
                    }
                });
                return;
            }

            // ---- legacy v2 fallback ----
            const aiConfig = this.getConfig();
            if (!aiConfig.configured || !aiConfig.allProviders) {
                if (wrapperEl) wrapperEl.style.display = 'none';
                this.selectedProvider = aiConfig.provider;
                this.selectedModel = aiConfig.model;
                return;
            }

            const providers = Object.entries(aiConfig.allProviders);
            if (wrapperEl) wrapperEl.style.display = 'block';
            if (!selectorEl) return;

            selectorEl.innerHTML = '';

            providers.forEach(([providerId, data]) => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = data.name;

                const availableModels = data.enabledModels || data.models || [];
                availableModels.forEach(model => {
                    const option = document.createElement('option');
                    option.value = `${providerId}::${model}`;
                    option.textContent = model;
                    if (providerId === aiConfig.provider && model === data.defaultModel) {
                        option.selected = true;
                        this.selectedProvider = providerId;
                        this.selectedModel = model;
                    }
                    optgroup.appendChild(option);
                });

                selectorEl.appendChild(optgroup);
            });

            if (!this.selectedProvider && selectorEl.options.length > 0) {
                selectorEl.selectedIndex = 0;
                const firstValue = selectorEl.value;
                if (firstValue) {
                    [this.selectedProvider, this.selectedModel] = firstValue.split('::');
                }
            }

            selectorEl.addEventListener('change', () => {
                const value = selectorEl.value;
                if (value && value.includes('::')) {
                    [this.selectedProvider, this.selectedModel] = value.split('::');
                }
            });
        }
    }

    // ========================================================================
    // 4. INIT — single entry point, wiring only
    // ========================================================================

    let initialized = false;

    function init() {
        if (typeof QuickSiteAdmin === 'undefined') {
            setTimeout(init, 50);
            return;
        }
        if (initialized) return;
        initialized = true;

        // Phase 1: silent v2 -> v3 connections-store migration. Idempotent.
        // ai-spec.js still uses the v2 AiProviderManager class for now;
        // this just ensures v3 is primed for the Phase 3 picker rewrite.
        try {
            if (window.QSConnectionsStore) {
                window.QSConnectionsStore.migrateFromV2();
            }
        } catch (e) {
            console.warn('[ai-spec] v2->v3 migration skipped:', e && e.message);
        }

        // --- 4a. Config from DOM ---
        const container = document.querySelector('.ai-spec');
        if (!container) return;

        const config = {
            specId: container.dataset.specId || '',
            isCreateSpec: container.dataset.isCreateSpec === 'true',
            isManualWorkflow: container.dataset.isManual === 'true',
            hasNoParams: container.dataset.hasNoParams === 'true',
            baseUrl: QuickSiteAdmin.config.baseUrl || '',
            adminBase: QuickSiteAdmin.config.adminBase || '',
            managementUrl: QuickSiteAdmin.config.managementBase || (QuickSiteAdmin.config.baseUrl + '/management/'),
            token: QuickSiteAdmin.config.token || ''
        };

        // --- 4b. ALL DOM references ---
        const els = {
            form: document.getElementById('spec-params-form'),
            promptOutput: document.getElementById('prompt-output'),
            promptLoading: document.getElementById('prompt-loading'),
            copyBtn: document.getElementById('copy-prompt'),
            copyBtnText: document.getElementById('copy-prompt-text'),
            sendToAiBtn: document.getElementById('send-to-ai'),
            sendToAiText: document.getElementById('send-to-ai-text'),
            promptNextStep: document.getElementById('prompt-next-step'),
            charCount: document.getElementById('char-count'),
            wordCount: document.getElementById('word-count'),
            userPromptTextarea: document.getElementById('user-prompt'),
            apiIntegrationWrapper: document.getElementById('api-integration-wrapper'),
            providerSelector: document.getElementById('provider-selector'),
            previewManualStepsBtn: document.getElementById('preview-manual-steps'),
            generatePromptBtn: document.getElementById('generate-prompt-btn'),
            executeManualBtn: document.getElementById('execute-manual-workflow'),
            exportBtn: document.getElementById('export-spec'),
            aiResponseSection: document.querySelector('.ai-response-section'),
            aiResponseInput: document.getElementById('ai-response-input'),
            aiResponseError: document.getElementById('ai-response-error'),
            validateBtn: document.getElementById('validate-response'),
            previewBtn: document.getElementById('preview-commands'),
            autoPreviewCheckbox: document.getElementById('auto-preview-checkbox'),
            autoExecuteCheckbox: document.getElementById('auto-execute-checkbox'),
            commandPreviewSection: document.querySelector('.command-preview-section'),
            commandList: document.getElementById('command-list'),
            commandCount: document.getElementById('command-count'),
            backToJsonBtn: document.getElementById('back-to-json'),
            executeBtn: document.getElementById('execute-commands'),
            executionResultsSection: document.querySelector('.execution-results-section'),
            executionProgress: document.getElementById('execution-progress'),
            progressText: document.getElementById('progress-text'),
            executionResults: document.getElementById('execution-results'),
            resetBtn: document.getElementById('reset-workflow'),
            freshStartOption: document.getElementById('fresh-start-option'),
            freshStartCheckbox: document.getElementById('fresh-start-checkbox'),
            freshStartModal: document.getElementById('fresh-start-modal'),
            modalCancelBtn: document.getElementById('modal-cancel'),
            modalProceedBtn: document.getElementById('modal-proceed')
        };

        // --- 4c. Shared state (single object = no closure split-brain) ---
        const state = {
            userParams: {},
            phases: [],
            parsedCommands: [],
            pendingExecution: false,
            autoValidateTimeout: null
        };

        // --- 4d. AI Provider ---
        const aiProvider = new AiProviderManager();
        aiProvider.initSelector(els.apiIntegrationWrapper, els.providerSelector);

        // --- 4e. Event bindings ---
        bindEvents(config, els, state, aiProvider);

        // --- 4f. Initialization ---
        initNodeSelectors();
        initTagSelects();
        updateConditionalFields(els);
        loadAutoOptions(els);
        initPresetLoader(config, els);

        if (config.isManualWorkflow && config.hasNoParams) {
            setTimeout(() => generateManualSteps(config, els, {}), 100);
        }

        // Public API
        window.AiSpec = {
            generatePrompt: (params) => generatePrompt(config, els, state, params || {})
        };

        // --- 4g. Delete workflow button ---
        const deleteBtn = document.getElementById('delete-workflow-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async () => {
                const id = deleteBtn.dataset.id;
                if (!confirm('Delete workflow "' + id + '"? This cannot be undone.')) return;
                
                deleteBtn.disabled = true;
                try {
                    const resp = await fetch(config.adminBase + '/api/workflow-delete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + config.token
                        },
                        body: JSON.stringify({ id })
                    });
                    const result = await resp.json();
                    if (result.success) {
                        window.location.href = config.adminBase + '/workflows';
                    } else {
                        alert(result.error || 'Delete failed');
                        deleteBtn.disabled = false;
                    }
                } catch (err) {
                    alert('Delete failed: ' + err.message);
                    deleteBtn.disabled = false;
                }
            });
        }
    }

    // ========================================================================
    // 5. EVENT BINDINGS — all in one place
    // ========================================================================

    function bindEvents(config, els, state, aiProvider) {

        // -- Form --
        if (els.form) {
            els.form.addEventListener('change', () => updateConditionalFields(els));
            els.form.addEventListener('submit', function(e) {
                e.preventDefault();
                const params = collectFormParams(els.form);
                if (config.isManualWorkflow) {
                    generateManualSteps(config, els, params);
                } else {
                    generatePrompt(config, els, state, params);
                }
            });

            // -- includeAssets lazy validation + auto-check keepAssets --
            const includeAssetsCheckbox = els.form.querySelector('input[name="includeAssets"]');
            if (includeAssetsCheckbox) {
                includeAssetsCheckbox.addEventListener('change', async function() {
                    // Auto-check keepAssets when includeAssets is checked
                    const keepAssetsCheckbox = els.form.querySelector('input[name="keepAssets"]');
                    if (keepAssetsCheckbox && this.checked && !keepAssetsCheckbox.checked) {
                        keepAssetsCheckbox.checked = true;
                        updateConditionalFields(els);
                    }
                    if (!this.checked) return;
                    try {
                        const result = await QuickSiteAdmin.apiRequest('listAssets/starred', 'GET');
                        const totalFiles = result.data?.data?.total_files ?? 0;
                        if (totalFiles === 0) {
                            QuickSiteAdmin.showToast('No starred assets found. Star assets in Asset Management first.', 'warning');
                            this.checked = false;
                            updateConditionalFields(els);
                        } else if (totalFiles > 15) {
                            QuickSiteAdmin.showToast('Only the first 15 starred assets will be included.', 'info');
                        }
                    } catch (e) {
                        // Non-critical — let the workflow proceed
                    }
                });
            }
        }

        // -- Manual workflow --
        if (els.previewManualStepsBtn) {
            els.previewManualStepsBtn.addEventListener('click', () => generateManualSteps(config, els, {}));
        }
        if (els.generatePromptBtn) {
            els.generatePromptBtn.addEventListener('click', () => generatePrompt(config, els, state, {}));
        }
        if (els.executeManualBtn) {
            els.executeManualBtn.addEventListener('click', function() {
                const steps = window._manualWorkflowSteps || [];
                if (steps.length === 0) { alert('No steps to execute'); return; }
                const commands = steps.map(step => {
                    const cmd = { command: step.command, ...step.params };
                    if (step.method) cmd.method = step.method;
                    if (step.abortOnFail != null) cmd.abortOnFail = step.abortOnFail;
                    if (step.retryOn) cmd.retryOn = step.retryOn;
                    if (step.maxRetries) cmd.maxRetries = step.maxRetries;
                    if (step.retryDelayMs) cmd.retryDelayMs = step.retryDelayMs;
                    return cmd;
                });
                executeManualWorkflowCommands(config, els, commands);
            });
        }

        // -- Examples --
        document.querySelectorAll('.ai-spec-example').forEach(example => {
            example.addEventListener('click', function() {
                const params = JSON.parse(this.dataset.params || '{}');
                const examplePrompt = this.dataset.prompt || '';
                if (els.userPromptTextarea && examplePrompt) {
                    els.userPromptTextarea.value = examplePrompt;
                    els.userPromptTextarea.focus();
                }
                if (els.form) {
                    Object.entries(params).forEach(([key, value]) => {
                        const input = els.form.querySelector(`[name="${key}"]`);
                        if (input) {
                            if (input.type === 'checkbox') input.checked = value === true || value === 'true';
                            else input.value = value;
                        }
                    });
                }
                document.querySelectorAll('.ai-spec-example').forEach(ex => ex.classList.remove('ai-spec-example--selected'));
                this.classList.add('ai-spec-example--selected');
            });
        });

        // -- Copy prompt --
        if (els.copyBtn) {
            els.copyBtn.addEventListener('click', async function() {
                try {
                    await navigator.clipboard.writeText(els.promptOutput.value);
                    const originalText = els.copyBtnText?.textContent || 'Copy Prompt';
                    if (els.copyBtnText) els.copyBtnText.textContent = 'Copied!';
                    this.classList.add('admin-btn--success');
                    if (els.promptNextStep) els.promptNextStep.style.display = 'flex';
                    if (els.aiResponseSection) {
                        els.aiResponseSection.style.display = 'block';
                        els.aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    setTimeout(() => {
                        if (els.copyBtnText) els.copyBtnText.textContent = originalText;
                        this.classList.remove('admin-btn--success');
                    }, 3000);
                } catch (err) {
                    if (els.promptOutput) { els.promptOutput.select(); document.execCommand('copy'); }
                }
            });
        }

        // -- Send to AI --
        if (els.sendToAiBtn) {
            els.sendToAiBtn.addEventListener('click', () => sendToAi(config, els, state, aiProvider));
        }

        // -- Export --
        if (els.exportBtn) {
            els.exportBtn.addEventListener('click', () => exportSpec(config, els));
        }

        // -- Auto options persistence --
        const AUTO_KEYS = { autoPreview: QuickSiteStorageKeys.aiAutoPreview, autoExecute: QuickSiteStorageKeys.aiAutoExecute };
        if (els.autoPreviewCheckbox) {
            els.autoPreviewCheckbox.addEventListener('change', function() {
                localStorage.setItem(AUTO_KEYS.autoPreview, this.checked);
            });
        }
        if (els.autoExecuteCheckbox) {
            els.autoExecuteCheckbox.addEventListener('change', function() {
                localStorage.setItem(AUTO_KEYS.autoExecute, this.checked);
            });
        }

        // -- Validate / Preview / Execute AI workflow --
        if (els.validateBtn) {
            els.validateBtn.addEventListener('click', function() {
                const commands = validateJson(els);
                if (commands) {
                    state.parsedCommands = [...commands];
                    if (els.previewBtn) els.previewBtn.style.display = 'inline-flex';
                    if (els.aiResponseError) els.aiResponseError.style.display = 'none';
                }
            });
        }

        if (els.aiResponseInput) {
            els.aiResponseInput.addEventListener('input', function() {
                if (state.autoValidateTimeout) clearTimeout(state.autoValidateTimeout);
                state.autoValidateTimeout = setTimeout(() => triggerAutoFlow(config, els, state), 500);
            });
        }

        if (els.previewBtn) {
            els.previewBtn.addEventListener('click', () => showCommandPreview(config, els, state));
        }

        if (els.backToJsonBtn) {
            els.backToJsonBtn.addEventListener('click', function() {
                if (els.commandPreviewSection) els.commandPreviewSection.style.display = 'none';
                if (els.aiResponseSection) els.aiResponseSection.style.display = 'block';
            });
        }

        if (els.executeBtn) {
            els.executeBtn.addEventListener('click', function() {
                const shouldFreshStart = config.isCreateSpec && els.freshStartCheckbox?.checked;
                if (config.isCreateSpec && !els.freshStartCheckbox?.checked) {
                    state.pendingExecution = true;
                    if (els.freshStartModal) els.freshStartModal.style.display = 'flex';
                    return;
                }
                executeCommands(config, els, state, shouldFreshStart);
            });
        }

        // -- Modal --
        if (els.modalCancelBtn) {
            els.modalCancelBtn.addEventListener('click', function() {
                if (els.freshStartModal) els.freshStartModal.style.display = 'none';
                state.pendingExecution = false;
            });
        }
        if (els.modalProceedBtn) {
            els.modalProceedBtn.addEventListener('click', function() {
                if (els.freshStartModal) els.freshStartModal.style.display = 'none';
                executeCommands(config, els, state, false);
            });
        }

        // -- Reset --
        if (els.resetBtn) {
            els.resetBtn.addEventListener('click', function() {
                if (els.executionResultsSection) els.executionResultsSection.style.display = 'none';
                if (els.aiResponseSection) els.aiResponseSection.style.display = 'block';
                if (els.aiResponseInput) els.aiResponseInput.value = '';
                if (els.previewBtn) els.previewBtn.style.display = 'none';
                state.parsedCommands = [];
            });
        }
    }

    // ========================================================================
    // 6. WORKFLOW FUNCTIONS
    // ========================================================================

    // -- Generate Prompt --

    async function generatePrompt(config, els, state, params) {
        if (els.promptLoading) els.promptLoading.style.display = 'flex';
        if (els.promptOutput) els.promptOutput.style.display = 'none';
        if (els.copyBtn) els.copyBtn.disabled = true;
        if (els.sendToAiBtn) els.sendToAiBtn.disabled = true;
        if (els.promptNextStep) els.promptNextStep.style.display = 'none';

        try {
            const queryString = new URLSearchParams(params).toString();
            const url = config.adminBase + '/api/ai-spec/' + config.specId + (queryString ? '?' + queryString : '');

            const response = await fetch(url, {
                headers: { 'Authorization': 'Bearer ' + config.token }
            });
            const data = await response.json();

            if (data.success && data.data?.prompt) {
                // Store phases and params for later execution
                state.userParams = data.data.userParams || {};
                state.phases = data.data.phases || [];

                const userPrompt = els.userPromptTextarea ? els.userPromptTextarea.value.trim() : '';
                let finalPrompt = data.data.prompt;
                if (userPrompt) {
                    finalPrompt += '\n\n---\n\n**User Request:**\n' + userPrompt;
                } else {
                    // No user prompt: tell the AI to make assumptions
                    finalPrompt += '\n\n---\n\n**User Request:**\n'
                        + 'The user has not provided a specific request. '
                        + 'Make reasonable assumptions based on the context above. '
                        + 'Imagine a modern, professional result with sensible defaults. '
                        + 'Do NOT ask for clarification — just produce a complete output.';
                    // Show visual hint to the user
                    const warningEl = document.getElementById('empty-prompt-warning');
                    if (warningEl) {
                        warningEl.style.display = 'block';
                        setTimeout(() => { warningEl.style.display = 'none'; }, 8000);
                    }
                }

                if (els.promptOutput) els.promptOutput.value = finalPrompt;
                if (els.copyBtn) els.copyBtn.disabled = false;
                if (els.sendToAiBtn) els.sendToAiBtn.disabled = false;
                if (els.promptNextStep) els.promptNextStep.style.display = 'inline-flex';
                updateStats(els, finalPrompt);
            } else {
                if (els.promptOutput) els.promptOutput.value = 'Error: ' + (data.error || 'Failed to generate prompt');
                updateStats(els, '');
            }
        } catch (error) {
            if (els.promptOutput) els.promptOutput.value = 'Error: ' + error.message;
            updateStats(els, '');
        }

        if (els.promptLoading) els.promptLoading.style.display = 'none';
        if (els.promptOutput) els.promptOutput.style.display = 'block';
    }

    // -- Validate JSON response --

    function validateJson(els) {
        let jsonText = els.aiResponseInput?.value?.trim() || '';
        if (els.aiResponseError) els.aiResponseError.style.display = 'none';
        if (els.previewBtn) els.previewBtn.style.display = 'none';

        if (!jsonText) {
            if (els.aiResponseError) {
                els.aiResponseError.textContent = 'Please paste the JSON response from the AI.';
                els.aiResponseError.style.display = 'block';
            }
            return null;
        }

        // Strip markdown code fences
        if (jsonText.startsWith('```')) {
            jsonText = jsonText.replace(/^```[a-zA-Z]*\n?/, '').replace(/\n?```\s*$/, '');
            if (els.aiResponseInput) els.aiResponseInput.value = jsonText;
        }

        try {
            const data = JSON.parse(jsonText);
            let commands = null;
            if (Array.isArray(data)) {
                commands = data;
            } else if (data.commands && Array.isArray(data.commands)) {
                commands = data.commands;
            } else if (data.command) {
                commands = [data];
            }

            if (!commands || commands.length === 0) {
                if (els.aiResponseError) {
                    els.aiResponseError.textContent = 'No commands found. Expected format: {"commands": [...]} or an array of commands.';
                    els.aiResponseError.style.display = 'block';
                }
                return null;
            }

            for (let i = 0; i < commands.length; i++) {
                if (!commands[i].command) {
                    if (els.aiResponseError) {
                        els.aiResponseError.textContent = `Command #${i + 1} is missing the "command" field.`;
                        els.aiResponseError.style.display = 'block';
                    }
                    return null;
                }
                normalizeCommand(commands[i]);
            }

            return commands;
        } catch (e) {
            if (els.aiResponseError) {
                els.aiResponseError.textContent = 'Invalid JSON: ' + e.message;
                els.aiResponseError.style.display = 'block';
            }
            return null;
        }
    }

    // -- Auto-flow (auto-preview + auto-execute) --

    function triggerAutoFlow(config, els, state) {
        const text = els.aiResponseInput?.value?.trim() || '';
        if (!text) return;

        const commands = validateJson(els);
        if (commands) {
            state.parsedCommands = [...commands];
            if (els.previewBtn) els.previewBtn.style.display = 'inline-flex';
            if (els.aiResponseError) els.aiResponseError.style.display = 'none';

            if (els.autoPreviewCheckbox?.checked) {
                els.previewBtn?.click();
                if (els.autoExecuteCheckbox?.checked) {
                    setTimeout(() => els.executeBtn?.click(), 300);
                }
            }
        }
    }

    // -- Show command preview --

    function showCommandPreview(config, els, state) {
        if (!els.commandList) return;
        els.commandList.innerHTML = '';

        state.parsedCommands.forEach((cmd, index) => {
            const method = getMethod(cmd);
            const item = document.createElement('div');
            item.className = 'command-item';
            item.innerHTML = htmlCommandPreview(index + 1, method, cmd.command, cmd.params);
            els.commandList.appendChild(item);
        });

        // Post-workflow placeholders
        const postPhases = (state.phases || []).filter(p => p.type === 'postWorkflow');
        if (postPhases.length > 0) {
            els.commandList.appendChild(htmlSeparator('— Post-Workflows (resolved with fresh data after execution) —'));
            postPhases.forEach((phase, index) => {
                const item = document.createElement('div');
                item.className = 'command-item command-item--post';
                item.innerHTML = htmlPostCommandPreview(index + 1, phase.workflowId, null);
                els.commandList.appendChild(item);
            });
        }

        if (els.commandCount) {
            let text = state.parsedCommands.length + ' command' + (state.parsedCommands.length > 1 ? 's' : '');
            if (postPhases.length > 0) text += ` (+${postPhases.length} post-workflow${postPhases.length > 1 ? 's' : ''})`;
            els.commandCount.textContent = text;
        }

        if (els.freshStartOption) {
            if (config.isCreateSpec) {
                els.freshStartOption.style.display = 'flex';
                if (els.freshStartCheckbox) els.freshStartCheckbox.checked = true;
            } else {
                els.freshStartOption.style.display = 'none';
            }
        }

        if (els.aiResponseSection) els.aiResponseSection.style.display = 'none';
        if (els.commandPreviewSection) els.commandPreviewSection.style.display = 'block';
    }

    // -- Fresh Start Commands --

    async function generateFreshStartCommands(config) {
        const commands = [];

        async function apiCall(command, params) {
            params = params || {};
            const hasParams = Object.keys(params).length > 0;
            const url = config.managementUrl + command;
            const options = {
                method: hasParams ? 'POST' : 'GET',
                headers: { 'Authorization': 'Bearer ' + config.token, 'Content-Type': 'application/json' }
            };
            if (hasParams) options.body = JSON.stringify(params);
            try {
                const response = await fetch(url, options);
                const text = await response.text();
                return text ? JSON.parse(text) : { status: response.status, message: 'Empty response' };
            } catch (error) {
                return { status: 500, message: error.message };
            }
        }

        try {
            // 1. Delete extra languages
            const langResponse = await apiCall('getLangList');
            let defaultLang = 'en';
            if ((langResponse.status === 200 || langResponse.success) && langResponse.data) {
                defaultLang = langResponse.data.default_language;
                const allLangs = langResponse.data.languages || [];
                for (const lang of allLangs.filter(l => l !== defaultLang)) {
                    commands.push({ command: 'deleteLang', params: { code: lang } });
                }
            }
            commands.push({ command: 'setMultilingual', params: { enabled: false } });

            // 2. Delete routes (except 404 and home)
            const routesResponse = await apiCall('getRoutes');
            if ((routesResponse.status === 200 || routesResponse.success) && routesResponse.data?.root_routes) {
                const protectedRoutes = ['404', 'home'];
                routesResponse.data.root_routes
                    .filter(r => !protectedRoutes.includes(r))
                    .forEach(r => commands.push({ command: 'deleteRoute', params: { route: r, force: true } }));
            }

            // 3. Delete all assets
            const assetsResponse = await apiCall('listAssets');
            if ((assetsResponse.status === 200 || assetsResponse.success) && assetsResponse.data?.assets) {
                for (const [category, files] of Object.entries(assetsResponse.data.assets)) {
                    for (const file of files) {
                        commands.push({ command: 'deleteAsset', params: { filename: file.filename } });
                    }
                }
            }

            // 4. Delete ALL components
            const componentsResponse = await apiCall('listComponents');
            if ((componentsResponse.status === 200 || componentsResponse.success) && componentsResponse.data?.components) {
                for (const component of componentsResponse.data.components) {
                    commands.push({ command: 'editStructure', params: { type: 'component', name: component.name, structure: [] } });
                }
            }

            // 5. Clear translation keys (except 404.*)
            const translationsResponse = await apiCall('getTranslations');
            if ((translationsResponse.status === 200 || translationsResponse.success) && translationsResponse.data?.translations) {
                const defaultLangKeys = translationsResponse.data.translations[defaultLang];
                if (defaultLangKeys) {
                    const topLevelKeys = Object.keys(defaultLangKeys).filter(key => key !== '404');
                    if (topLevelKeys.length > 0) {
                        commands.push({ command: 'deleteTranslationKeys', params: { language: defaultLang, keys: topLevelKeys } });
                    }
                }
            }

            // 6. Clear structures
            commands.push({ command: 'editStructure', params: { type: 'menu', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'footer', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'page', name: 'home', structure: [] } });

            // 7. Minimize 404 page
            commands.push({
                command: 'editStructure',
                params: {
                    type: 'page', name: '404',
                    structure: [{ tag: 'section', params: { class: 'error-page' }, children: [
                        { tag: 'h1', children: [{ textKey: '404.pageNotFound' }] },
                        { tag: 'p', children: [{ textKey: '404.message' }] }
                    ]}]
                }
            });

            // 8. Clear CSS
            commands.push({ command: 'editStyles', params: { content: '/* Fresh Start - CSS cleared */\n' } });

            return commands;
        } catch (error) {
            console.error('Error generating fresh start commands:', error);
            return [];
        }
    }

    // -- Execute a single command via API, return { data, isSuccess, errorMsg } --

    async function executeOneCommand(config, cmd) {
        normalizeCommand(cmd);
        const method = getMethod(cmd);
        const url = config.managementUrl + cmd.command;
        const options = {
            method,
            headers: { 'Authorization': 'Bearer ' + config.token, 'Content-Type': 'application/json' }
        };
        if (method !== 'GET' && cmd.params) {
            options.body = JSON.stringify(cmd.params);
        }

        const response = await fetch(url, options);
        const responseText = await response.text();
        let data;
        try {
            data = responseText ? JSON.parse(responseText) : { status: response.status, message: 'No response body' };
        } catch (parseError) {
            data = { status: 500, message: 'Invalid JSON response: ' + responseText.substring(0, 100) };
        }

        const isSuccess = (data.status >= 200 && data.status < 300) || data.success === true;
        const errorMsg = data.error || data.message || 'Failed';

        return { data, isSuccess, errorMsg };
    }

    /** Update a result-item element with success/error/skip content */
    function updateResultItem(resultItem, opts) {
        // opts: { isSuccess, icon, prefix, command, message, isError, detailsId, sections }
        if (!resultItem) return;
        resultItem.className = 'result-item ' + (opts.isError ? 'result-item--error' : 'result-item--success');
        resultItem.innerHTML = htmlResultItem(opts);
    }

    // -- Resolve and execute a sub-workflow phase via API --

    async function resolveAndExecutePhase(config, els, workflowId, params, globalIndex, options) {
        const prefix = options.prefix || '';
        const acceptableFailure = options.acceptableFailureStatus || null;

        const resolveResp = await fetch(config.adminBase + '/api/workflow-resolve-phase', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + config.token, 'Content-Type': 'application/json' },
            body: JSON.stringify({ workflowId, params })
        });
        const resolveData = await resolveResp.json();

        if (!resolveData.success || !resolveData.data?.steps?.length) {
            const noItems = document.createElement('div');
            noItems.style.cssText = 'padding: 8px; color: var(--admin-text-muted); font-size: 12px; text-align: center;';
            noItems.textContent = 'No commands from ' + workflowId;
            if (els.executionResults) els.executionResults.appendChild(noItems);
            return 0;
        }

        const steps = resolveData.data.steps;

        // Create pending items
        steps.forEach((cmd, idx) => {
            const el = document.createElement('div');
            el.innerHTML = htmlPendingItem('result-' + (globalIndex + idx), cmd.command, 'Pending...', prefix);
            if (els.executionResults) els.executionResults.appendChild(el.firstElementChild);
        });

        // Execute each step
        for (let i = 0; i < steps.length; i++) {
            const cmd = steps[i];
            const resultItem = document.getElementById('result-' + (globalIndex + i));
            if (els.progressText) {
                els.progressText.textContent = prefix + workflowId + ': ' + cmd.command + ' (' + (i + 1) + '/' + steps.length + ')';
            }
            const detailsId = 'details-' + (globalIndex + i);
            try {
                const { data, isSuccess, errorMsg } = await executeOneCommand(config, cmd);
                const isAcceptable = acceptableFailure && data.status === acceptableFailure;
                updateResultItem(resultItem, {
                    isSuccess: isSuccess || isAcceptable, isError: !(isSuccess || isAcceptable),
                    icon: isAcceptable ? '⏭️' : (isSuccess ? '✅' : '❌'),
                    prefix, command: cmd.command,
                    statusMessage: isAcceptable ? 'Skipped (not found)' : (isSuccess ? (data.message || 'Success') : errorMsg),
                    detailsId,
                    sections: [
                        { label: 'Parameters:', value: formatParamsDisplay(cmd.params) },
                        { label: 'Response:', value: formatResponseDisplay(data) }
                    ]
                });
            } catch (error) {
                updateResultItem(resultItem, {
                    isSuccess: false, isError: true,
                    icon: '❌', prefix, command: cmd.command,
                    statusMessage: error.message, detailsId,
                    sections: [
                        { label: 'Parameters:', value: formatParamsDisplay(cmd.params) },
                        { label: 'Error:', value: error.stack || error.message }
                    ]
                });
            }
        }

        return steps.length;
    }

    // -- Execute Commands (main AI workflow — phase-based) --

    async function executeCommands(config, els, state, withFreshStart) {
        if (els.commandPreviewSection) els.commandPreviewSection.style.display = 'none';
        if (els.executionResultsSection) els.executionResultsSection.style.display = 'block';
        if (els.executionProgress) els.executionProgress.style.display = 'flex';
        if (els.executionResults) els.executionResults.innerHTML = '';

        // Top status bar
        const topStatusBar = htmlStatusBar('⏳ Workflow running...', 'running');
        if (els.executionResults) els.executionResults.appendChild(topStatusBar);

        let successCount = 0;
        let failCount = 0;
        let phases = state.phases || [];

        // FAILSAFE: if phases is empty, recover from API
        if (phases.length === 0 && config.specId) {
            console.warn('[ai-spec] phases is empty — recovering from API...');
            try {
                const formParams = els.form ? collectFormParams(els.form) : {};
                const queryString = new URLSearchParams(formParams).toString();
                const url = config.adminBase + '/api/workflow-phases/' + config.specId + (queryString ? '?' + queryString : '');
                const resp = await fetch(url, { headers: { 'Authorization': 'Bearer ' + config.token } });
                const result = await resp.json();
                if (result.success && result.data?.phases?.length) {
                    phases = result.data.phases;
                    state.phases = phases;
                    state.userParams = Object.keys(state.userParams || {}).length > 0 ? state.userParams : formParams;
                    console.warn('[ai-spec] RECOVERED phases from API, count:', phases.length);
                }
            } catch (e) {
                console.error('[ai-spec] Failed to recover phases:', e);
            }
        }

        let globalIndex = 0;

        for (const phase of phases) {
            if (phase.type === 'preWorkflow') {
                if (!withFreshStart) continue;

                if (els.executionResults) {
                    els.executionResults.appendChild(htmlSeparator('— Pre-Workflow: ' + phase.workflowId + ' —'));
                }
                if (els.progressText) els.progressText.textContent = 'Resolving ' + phase.workflowId + '...';

                try {
                    const count = await resolveAndExecutePhase(config, els, phase.workflowId, state.userParams || {}, globalIndex, {
                        prefix: '🧹 ', acceptableFailureStatus: 404
                    });
                    globalIndex += count;
                } catch (error) {
                    console.error('Pre-workflow resolution failed:', error);
                    if (els.executionResults) {
                        const errEl = document.createElement('div');
                        errEl.className = 'result-item result-item--error';
                        errEl.innerHTML = htmlResultItem({ icon: '❌', command: phase.workflowId, statusMessage: error.message, isError: true });
                        els.executionResults.appendChild(errEl);
                    }
                }

                // Wait for filesystem to settle
                if (els.progressText) els.progressText.textContent = '⏳ Waiting for sync...';
                await new Promise(resolve => setTimeout(resolve, 1500));

            } else if (phase.type === 'main') {
                // Show separator only if there are other phases
                if (phases.length > 1 && els.executionResults) {
                    els.executionResults.appendChild(htmlSeparator('— AI Commands —'));
                }

                const mainCommands = state.parsedCommands || [];

                // Create pending items
                mainCommands.forEach((cmd) => {
                    const el = document.createElement('div');
                    el.innerHTML = htmlPendingItem('result-' + globalIndex, cmd.command, 'Pending...', '');
                    if (els.executionResults) els.executionResults.appendChild(el.firstElementChild);
                    globalIndex++;
                });

                // Execute each AI command
                const startIdx = globalIndex - mainCommands.length;
                for (let i = 0; i < mainCommands.length; i++) {
                    const cmd = mainCommands[i];
                    const resultItem = document.getElementById('result-' + (startIdx + i));
                    if (els.progressText) {
                        els.progressText.textContent = 'Executing command ' + (i + 1) + ' of ' + mainCommands.length + ': ' + cmd.command;
                    }
                    const detailsId = 'details-' + (startIdx + i);
                    try {
                        const { data, isSuccess, errorMsg } = await executeOneCommand(config, cmd);
                        if (isSuccess) successCount++; else failCount++;
                        updateResultItem(resultItem, {
                            isSuccess, isError: !isSuccess,
                            icon: isSuccess ? '✅' : '❌',
                            prefix: '', command: cmd.command,
                            statusMessage: isSuccess ? (data.message || 'Success') : errorMsg,
                            detailsId,
                            sections: [
                                { label: 'Parameters:', value: formatParamsDisplay(cmd.params) },
                                { label: 'Response:', value: formatResponseDisplay(data) }
                            ]
                        });
                    } catch (error) {
                        failCount++;
                        updateResultItem(resultItem, {
                            isSuccess: false, isError: true,
                            icon: '❌', prefix: '', command: cmd.command,
                            statusMessage: error.message, detailsId,
                            sections: [
                                { label: 'Parameters:', value: formatParamsDisplay(cmd.params) },
                                { label: 'Error:', value: error.stack || error.message }
                            ]
                        });
                    }
                }

            } else if (phase.type === 'postWorkflow') {
                // Wait for filesystem to settle before resolving with fresh data
                if (els.progressText) els.progressText.textContent = '⏳ Waiting for sync...';
                await new Promise(resolve => setTimeout(resolve, 1500));

                if (els.executionResults) {
                    els.executionResults.appendChild(htmlSeparator('— Post-Workflow: ' + phase.workflowId + ' —'));
                }
                if (els.progressText) els.progressText.textContent = 'Resolving ' + phase.workflowId + ' with fresh data...';

                try {
                    const count = await resolveAndExecutePhase(config, els, phase.workflowId, state.userParams || {}, globalIndex, {
                        prefix: '🔧 '
                    });
                    globalIndex += count;
                } catch (error) {
                    console.error('Post-workflow resolution failed:', error);
                    if (els.executionResults) {
                        const errEl = document.createElement('div');
                        errEl.className = 'result-item result-item--error';
                        errEl.innerHTML = htmlResultItem({ icon: '❌', command: phase.workflowId, statusMessage: error.message, isError: true });
                        els.executionResults.appendChild(errEl);
                    }
                }
            }
        }

        // Update top status bar and add bottom summary
        const isDone = failCount === 0 ? 'success' : 'error';
        const doneText = `✅ ${successCount} succeeded` + (failCount > 0 ? ` | ❌ ${failCount} failed` : '') + ' — Workflow complete';
        topStatusBar.textContent = doneText;
        topStatusBar.className = 'execution-status-bar execution-status-bar--' + isDone;
        topStatusBar.style.cssText = topStatusBar.style.cssText.replace(/background:.*?;|color:.*?;|border-color:.*?;/g, '');
        Object.assign(topStatusBar.style, isDone === 'success'
            ? { background: 'var(--admin-success-bg, #d4edda)', color: 'var(--admin-success-text, #155724)', borderColor: 'var(--admin-success-border, #28a745)' }
            : { background: 'var(--admin-error-bg, #f8d7da)', color: 'var(--admin-error-text, #721c24)', borderColor: 'var(--admin-error-border, #dc3545)' }
        );
        if (els.executionResults) els.executionResults.appendChild(htmlSummary(successCount, failCount));

        if (els.executionProgress) els.executionProgress.style.display = 'none';

        // Notify miniplayer to reload now that workflow is complete
        window.dispatchEvent(new CustomEvent('quicksite:workflow-complete'));
    }

    // -- Post-Commands execution (after AI commands) --

    // -- Send to AI --

    // Phase 4 polish helpers (module-local, no module state).

    /** Map ai-call.js error categories → user-facing one-liner + hint. */
    function classifyAiError(error) {
        const cat = error && error.category ? error.category : 'network';
        const raw = (error && error.message) || String(error || '');
        const msg = raw.toLowerCase();
        const presets = {
            cors: ['CORS blocked.', 'The provider rejected the call from this site. For local AI, set OLLAMA_ORIGINS / enable CORS in LM Studio.'],
            auth: ['Auth failed.', 'Check the API key on the AI Connections page.'],
            quota: ['Quota or rate limit reached.', 'Wait a moment, switch model, or top up credits.'],
            network: ['Network error.', 'Endpoint unreachable. Check that the service is running and the URL is correct.'],
            timeout: ['Request timed out.', 'The model took too long. Try a smaller model or shorter prompt.'],
            provider_error: ['Provider error.', 'The AI provider returned an error response.'],
            invalid_request: ['Invalid request.', 'Something is missing or malformed before sending.'],
            aborted: ['Stopped.', '']
        };
        // Refine: some "network" errors are actually CORS at fetch-level.
        let resolved = cat;
        if (cat === 'network' && (msg.includes('cors') || msg.includes('failed to fetch') || msg.includes('networkerror'))) {
            resolved = 'cors';
        }
        const [headline, hint] = presets[resolved] || presets.network;
        return { category: resolved, headline, hint, raw };
    }

    /** Show / hide the inline error ribbon above the textarea. */
    function showErrorRibbon(els, classified) {
        if (!els.aiResponseError) return;
        const c = classified;
        els.aiResponseError.style.display = 'block';
        els.aiResponseError.innerHTML = `
            <div class="ai-error-ribbon ai-error-ribbon--${c.category}">
                <span class="ai-error-ribbon__cat">[${c.category}]</span>
                <strong>${c.headline}</strong>
                ${c.hint ? `<span class="ai-error-ribbon__hint"> ${c.hint}</span>` : ''}
                <button type="button" class="ai-error-ribbon__close" aria-label="Dismiss">×</button>
            </div>`;
        const close = els.aiResponseError.querySelector('.ai-error-ribbon__close');
        if (close) close.addEventListener('click', () => { els.aiResponseError.style.display = 'none'; });
    }
    function hideErrorRibbon(els) {
        if (els.aiResponseError) { els.aiResponseError.style.display = 'none'; els.aiResponseError.innerHTML = ''; }
    }

    /** Mount / update / dismount the live counter pill at the textarea corner. */
    function ensureCounter(els) {
        if (!els.aiResponseInput) return null;
        let pill = document.getElementById('ai-stream-counter');
        if (!pill) {
            pill = document.createElement('div');
            pill.id = 'ai-stream-counter';
            pill.className = 'ai-stream-counter';
            // Anchor relative to the textarea's parent (must be position:relative).
            const parent = els.aiResponseInput.parentElement;
            if (parent && getComputedStyle(parent).position === 'static') {
                parent.style.position = 'relative';
            }
            (parent || document.body).appendChild(pill);
        }
        pill.style.display = 'block';
        return pill;
    }
    function updateCounter(pill, { seconds, chars, model, suffix }) {
        if (!pill) return;
        const tokensApprox = Math.round(chars / 4); // crude; OK for user feedback
        pill.textContent = `${seconds.toFixed(1)}s · ~${tokensApprox} tokens · ${model || '?'}${suffix ? ' · ' + suffix : ''}`;
    }
    function dismissCounter(pill, ms) {
        if (!pill) return;
        setTimeout(() => { pill.style.display = 'none'; }, ms == null ? 4000 : ms);
    }

    /** Typewriter reveal for non-streaming responses. */
    async function typewriter(textareaEl, fullText, signal, charsPerSec) {
        const speed = charsPerSec || 80;
        const totalMs = Math.min(1500, Math.max(200, (fullText.length / speed) * 1000));
        const start = performance.now();
        return new Promise((resolve) => {
            function tick(now) {
                if (signal && signal.aborted) { textareaEl.value = fullText; return resolve(); }
                const t = Math.min(1, (now - start) / totalMs);
                const n = Math.floor(t * fullText.length);
                textareaEl.value = fullText.slice(0, n);
                textareaEl.scrollTop = textareaEl.scrollHeight;
                if (t < 1) requestAnimationFrame(tick); else { textareaEl.value = fullText; resolve(); }
            }
            requestAnimationFrame(tick);
        });
    }

    async function sendToAi(config, els, state, aiProvider) {
        const baseConfig = aiProvider.getConfig();
        if (!baseConfig.configured) {
            if (confirm('No AI API key configured. Would you like to configure it now?')) {
                window.location.href = config.adminBase + '/ai-connections';
            }
            return;
        }

        const aiConfig = aiProvider.selectedProvider
            ? aiProvider.getProviderConfig(aiProvider.selectedProvider, aiProvider.selectedModel)
            : baseConfig;

        const prompt = els.promptOutput?.value;
        if (!prompt) { alert('Please generate a prompt first.'); return; }

        // Reset UI
        if (els.aiResponseSection) {
            els.aiResponseSection.style.display = 'block';
            if (els.aiResponseInput) els.aiResponseInput.value = '';
        }
        if (els.executionResultsSection) els.executionResultsSection.style.display = 'none';
        hideErrorRibbon(els);

        const originalText = els.sendToAiText?.textContent || 'Send';
        els.sendToAiBtn.disabled = true;

        const startedAt = performance.now();
        const counterPill = ensureCounter(els);
        let counterTick = null;
        let lastChars = 0;
        const abortController = new AbortController();
        let userAborted = false;

        // Cancel functionality — morph button into Stop.
        const originalOnClick = els.sendToAiBtn.onclick;
        els.sendToAiBtn.disabled = false;
        els.sendToAiBtn.classList.add('admin-btn--danger');
        if (els.sendToAiText) els.sendToAiText.textContent = '⏹ Stop';
        els.sendToAiBtn.onclick = () => {
            userAborted = true;
            abortController.abort();
        };

        const restoreButton = () => {
            els.sendToAiBtn.classList.remove('admin-btn--danger');
            els.sendToAiBtn.onclick = originalOnClick;
            els.sendToAiBtn.disabled = false;
            if (els.sendToAiText) els.sendToAiText.textContent = originalText;
            if (counterTick) { clearInterval(counterTick); counterTick = null; }
        };

        try {
            // Phase 2: prefer browser-direct streaming via QSAiCall when a
            // v3 connection exists. Falls back to the PHP proxy if the
            // connections store hasn't been populated yet.
            const activeConn = window.QSConnectionsStore && window.QSConnectionsStore.getActive();
            const useDirect = !!(activeConn && window.QSAiCall && window.QSProviderCatalog);

            if (useDirect) {
                const modelToUse = aiProvider.selectedModel || activeConn.defaultModel || aiConfig.model;
                // Mirror current key into the active connection so direct
                // calls work even if migration was incomplete.
                const conn = Object.assign({}, activeConn, { key: activeConn.key || aiConfig.key });
                const willStream = conn.streaming !== false;

                // Live counter — ticks even before first byte arrives.
                counterTick = setInterval(() => {
                    const sec = (performance.now() - startedAt) / 1000;
                    updateCounter(counterPill, { seconds: sec, chars: lastChars, model: modelToUse });
                }, 100);

                let liveBuffer = '';
                const result = await window.QSAiCall.call({
                    connection: conn,
                    model: modelToUse,
                    messages: [{ role: 'user', content: prompt }],
                    options: { max_tokens: 16384, temperature: 0.7 },
                    signal: abortController.signal,
                    onChunk: (deltaText, ctx) => {
                        liveBuffer = ctx.content;
                        lastChars = liveBuffer.length;
                        if (els.aiResponseInput) {
                            els.aiResponseInput.value = liveBuffer;
                            els.aiResponseInput.scrollTop = els.aiResponseInput.scrollHeight;
                        }
                    }
                });

                let content = (result.content || liveBuffer || '').trim();
                if (content.startsWith('```')) {
                    content = content.replace(/^```[a-zA-Z]*\n?/, '').replace(/\n?```\s*$/, '');
                }

                // Non-streaming responses: typewriter reveal so the textarea
                // doesn't jump from empty to full.
                if (!willStream && els.aiResponseInput && content) {
                    await typewriter(els.aiResponseInput, content, abortController.signal);
                    lastChars = content.length;
                }

                // Final counter snapshot.
                const totalSec = (performance.now() - startedAt) / 1000;
                updateCounter(counterPill, { seconds: totalSec, chars: content.length || lastChars, model: modelToUse, suffix: result.viaStream ? 'streamed' : 'whole' });
                dismissCounter(counterPill);

                try { window.QSConnectionsStore.recordStatus(conn.id, { ok: true, used: true, message: result.viaStream ? 'streamed' : 'ok' }); } catch (_e) {}

                if (els.aiResponseInput) {
                    els.aiResponseInput.value = content;
                    if (els.aiResponseSection) {
                        els.aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    setTimeout(() => triggerAutoFlow(config, els, state), 300);
                    if (els.sendToAiText) els.sendToAiText.textContent = `✅ ${formatTime(Math.round(totalSec))}`;
                    setTimeout(restoreButton, 3000);
                }
                return;
            }

            // No connection configured: prompt user to add one.
            if (counterPill) counterPill.style.display = 'none';
            showErrorRibbon(els, classifyAiError({ category: 'invalid_request', message: 'No AI connection is active. Open AI Connections to add one.' }));
            restoreButton();
        } catch (error) {
            // Aborted = leave the partial response in place + mark it.
            if (userAborted || error.name === 'AbortError' || error.category === 'aborted') {
                if (els.aiResponseInput) {
                    const cur = els.aiResponseInput.value;
                    if (cur && !/\n\[stopped\]\s*$/.test(cur)) {
                        els.aiResponseInput.value = cur + '\n[stopped]';
                    }
                }
                const totalSec = (performance.now() - startedAt) / 1000;
                updateCounter(counterPill, { seconds: totalSec, chars: lastChars, model: aiProvider.selectedModel || '', suffix: 'stopped' });
                dismissCounter(counterPill, 6000);
                restoreButton();
                return;
            }
            // Real error → ribbon + restore + record status.
            const classified = classifyAiError(error);
            showErrorRibbon(els, classified);
            if (counterPill) counterPill.style.display = 'none';
            try {
                const ac = window.QSConnectionsStore && window.QSConnectionsStore.getActive();
                if (ac) window.QSConnectionsStore.recordStatus(ac.id, { ok: false, message: classified.headline + ' ' + classified.raw });
            } catch (_e) {}
            restoreButton();
        }
    }

    // -- Export spec --

    async function exportSpec(config, els) {
        try {
            const response = await fetch(config.adminBase + '/api/ai-spec-raw/' + config.specId, {
                headers: { 'Authorization': 'Bearer ' + config.token }
            });
            const data = await response.json();
            if (data.success) {
                const exportData = { spec: data.data.spec, template: data.data.template };
                const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = config.specId + '.spec.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            } else {
                alert('Export failed: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            alert('Export failed: ' + error.message);
        }
    }

    // ========================================================================
    // 7. MANUAL WORKFLOW FUNCTIONS
    // ========================================================================

    async function generateManualSteps(config, els, params) {
        const loadingEl = document.getElementById('manual-steps-loading');
        const manualCommandList = document.getElementById('manual-command-list');
        const stepCount = document.getElementById('manual-step-count');
        const manualExecuteBtn = document.getElementById('execute-manual-workflow');

        if (loadingEl) loadingEl.style.display = 'flex';
        if (manualCommandList) manualCommandList.innerHTML = '';

        try {
            const response = await fetch(config.adminBase + '/api/workflow-generate-steps', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + config.token },
                body: JSON.stringify({ workflowId: config.specId, params })
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Failed to generate steps');

            if (result.data._debug) {
                console.warn('[ai-spec] workflow-generate-steps debug:', result.data._debug);
            }

            const steps = result.data.steps || [];
            if (stepCount) stepCount.textContent = steps.length + ' command' + (steps.length !== 1 ? 's' : '');

            if (manualCommandList) {
                steps.forEach((step, index) => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'command-item';
                    itemEl.innerHTML = htmlManualStepPreview(index + 1, step.command, step.params);
                    manualCommandList.appendChild(itemEl);
                });
            }

            window._manualWorkflowSteps = steps;
            if (manualExecuteBtn) manualExecuteBtn.disabled = false;
        } catch (error) {
            console.error('Error generating steps:', error);
            if (manualCommandList) {
                manualCommandList.innerHTML = `<div class="command-error">Error: ${escapeHtml(error.message)}</div>`;
            }
        }
        if (loadingEl) loadingEl.style.display = 'none';
    }

    async function executeManualWorkflowCommands(config, els, commands) {
        if (els.commandPreviewSection) els.commandPreviewSection.style.display = 'none';
        if (els.executionResultsSection) els.executionResultsSection.style.display = 'block';
        if (els.executionProgress) els.executionProgress.style.display = 'flex';
        if (els.executionResults) els.executionResults.innerHTML = '';

        // Top status bar
        const topStatusBar = htmlStatusBar('⏳ Workflow running...', 'running');
        if (els.executionResults) els.executionResults.appendChild(topStatusBar);

        // Pending items
        commands.forEach((cmd, index) => {
            const el = document.createElement('div');
            el.innerHTML = htmlPendingItem('result-' + index, cmd.command, 'Pending...', '🧹 ');
            if (els.executionResults) els.executionResults.appendChild(el.firstElementChild);
        });

        let successCount = 0;
        let failCount = 0;

        for (let i = 0; i < commands.length; i++) {
            const cmd = commands[i];
            if (els.progressText) {
                els.progressText.textContent = `Executing command ${i + 1} of ${commands.length}: ${cmd.command}`;
            }
            const resultItem = document.getElementById('result-' + i);

            const maxAttempts = (cmd.retryOn && cmd.retryOn.length > 0) ? (cmd.maxRetries || 3) : 1;
            const retryDelay = cmd.retryDelayMs || 1000;
            let lastError = null;
            let lastResult = null;
            let lastResponse = null;
            let succeeded = false;

            for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                try {
                    if (attempt > 1) {
                        if (els.progressText) {
                            els.progressText.textContent = `Retrying ${cmd.command} (attempt ${attempt}/${maxAttempts})...`;
                        }
                        if (resultItem) {
                            resultItem.querySelector('.result-item__message').textContent = `Retry ${attempt}/${maxAttempts} in ${retryDelay / 1000}s...`;
                            resultItem.querySelector('.result-item__icon').textContent = '🔄';
                        }
                        await new Promise(resolve => setTimeout(resolve, retryDelay));
                    }

                    const params = {};
                    for (const [key, value] of Object.entries(cmd)) {
                        if (!['command', 'method', 'abortOnFail', 'retryOn', 'maxRetries', 'retryDelayMs', 'comment', 'condition', 'forEach', 'filter'].includes(key)) params[key] = value;
                    }
                    const method = cmd.method || 'POST';
                    const url = config.managementUrl + cmd.command;
                    const options = {
                        method,
                        headers: { 'Authorization': 'Bearer ' + config.token, 'Content-Type': 'application/json' }
                    };
                    if (method !== 'GET' && method !== 'DELETE' && Object.keys(params).length > 0) {
                        options.body = JSON.stringify(params);
                    }
                    lastResponse = await fetch(url, options);
                    const text = await lastResponse.text();
                    try { lastResult = text ? JSON.parse(text) : { status: lastResponse.status }; }
                    catch (e) { lastResult = { status: lastResponse.status, message: text || 'No response' }; }

                    const isSuccess = lastResponse.ok || lastResult.status === 200 || lastResult.success;
                    if (isSuccess) { succeeded = true; break; }

                    // Check if this status code is retryable
                    if (cmd.retryOn && cmd.retryOn.includes(lastResponse.status) && attempt < maxAttempts) {
                        continue; // retry
                    }
                    break; // non-retryable failure
                } catch (error) {
                    lastError = error;
                    if (cmd.retryOn && attempt < maxAttempts) continue; // retry on network error
                    break;
                }
            }

            if (succeeded) {
                successCount++;
                if (resultItem) {
                    resultItem.className = 'result-item result-item--success';
                    resultItem.querySelector('.result-item__icon').textContent = '✅';
                    resultItem.querySelector('.result-item__message').textContent =
                        lastResult.message || lastResult.data?.message || 'Success';
                }
            } else {
                failCount++;
                if (resultItem) {
                    resultItem.className = 'result-item result-item--error';
                    resultItem.querySelector('.result-item__icon').textContent = '❌';
                    resultItem.querySelector('.result-item__message').textContent =
                        lastError ? lastError.message : (lastResult?.message || lastResult?.data?.message || 'Failed');
                }
                if (cmd.abortOnFail) {
                    topStatusBar.textContent = `✅ ${successCount} succeeded | ❌ ${failCount} failed | ⛔ Aborted at: ${cmd.command}`;
                    topStatusBar.className = 'execution-status-bar execution-status-bar--error';
                    topStatusBar.style.cssText = topStatusBar.style.cssText.replace(/background:.*?;|color:.*?;|border-color:.*?;/g, '');
                    Object.assign(topStatusBar.style, { background: 'var(--admin-error-bg, #f8d7da)', color: 'var(--admin-error-text, #721c24)', borderColor: 'var(--admin-error-border, #dc3545)' });
                    if (els.executionProgress) els.executionProgress.style.display = 'none';
                    if (els.executionResults) els.executionResults.appendChild(htmlSummary(successCount, failCount, cmd.command));
                    window.dispatchEvent(new CustomEvent('quicksite:workflow-complete'));
                    return;
                }
            }
            await new Promise(resolve => setTimeout(resolve, 100));
        }

        // Update top status bar
        const isDone = failCount === 0 ? 'success' : 'error';
        const doneText = `✅ ${successCount} succeeded` + (failCount > 0 ? ` | ❌ ${failCount} failed` : '') + ' — Workflow complete';
        topStatusBar.textContent = doneText;
        topStatusBar.className = 'execution-status-bar execution-status-bar--' + isDone;
        topStatusBar.style.cssText = topStatusBar.style.cssText.replace(/background:.*?;|color:.*?;|border-color:.*?;/g, '');
        Object.assign(topStatusBar.style, isDone === 'success'
            ? { background: 'var(--admin-success-bg, #d4edda)', color: 'var(--admin-success-text, #155724)', borderColor: 'var(--admin-success-border, #28a745)' }
            : { background: 'var(--admin-error-bg, #f8d7da)', color: 'var(--admin-error-text, #721c24)', borderColor: 'var(--admin-error-border, #dc3545)' }
        );

        if (els.executionProgress) els.executionProgress.style.display = 'none';
        if (els.executionResults) els.executionResults.appendChild(htmlSummary(successCount, failCount));

        // Notify miniplayer to reload now that workflow is complete
        window.dispatchEvent(new CustomEvent('quicksite:workflow-complete'));
    }

    // ========================================================================
    // 8. FORM/UI HELPERS
    // ========================================================================

    function updateConditionalFields(els) {
        if (!els.form) return;
        const formData = {};
        els.form.querySelectorAll('input, select, textarea').forEach(input => {
            if (!input.name) return;
            if (input.type === 'checkbox') formData[input.name] = input.checked ? 'true' : 'false';
            else formData[input.name] = input.value;
        });
        els.form.querySelectorAll('[data-condition]').forEach(group => {
            const shouldShow = evaluateCondition(group.dataset.condition, formData);
            group.classList.toggle('ai-spec-form__group--hidden', !shouldShow);
        });
    }

    function initNodeSelectors() {
        document.querySelectorAll('.ai-spec-node-selector').forEach(selector => {
            const paramId = selector.id.replace('node-selector-', '');
            const modeRadios = selector.querySelectorAll(`input[name="${paramId}_mode"]`);
            const selectUI = selector.querySelector('.ai-spec-node-selector__select-ui');
            const hintUI = selector.querySelector('.ai-spec-node-selector__hint-ui');
            const structureSelect = selector.querySelector('.ai-spec-node-selector__structure');
            const pageSelect = selector.querySelector('.ai-spec-node-selector__page');
            const nodeSelect = selector.querySelector('.ai-spec-node-selector__node');
            const actionSelect = selector.querySelector('.ai-spec-node-selector__action');
            const hintInput = selector.querySelector(`input[name="${paramId}_hint"]`);
            const hiddenInput = selector.querySelector(`input[name="${paramId}"]`);

            modeRadios.forEach(radio => {
                radio.addEventListener('change', () => {
                    const isSelectMode = radio.value === 'select';
                    if (selectUI) selectUI.style.display = isSelectMode ? 'flex' : 'none';
                    if (hintUI) hintUI.style.display = isSelectMode ? 'none' : 'block';
                    updateHiddenValue();
                });
            });

            async function loadNodeOptions(structureType, pageName) {
                nodeSelect.innerHTML = '<option value="">Loading...</option>';
                nodeSelect.disabled = true;
                actionSelect.disabled = true;
                if (!structureType) { nodeSelect.innerHTML = '<option value="">-- Select node --</option>'; return; }
                const params = (structureType === 'page') ? [structureType, pageName] : [structureType];
                if (structureType === 'page' && !pageName) { nodeSelect.innerHTML = '<option value="">-- Select page first --</option>'; return; }
                try {
                    const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
                    nodeSelect.innerHTML = '<option value="">-- Select node --</option>';
                    if (nodes && nodes.length > 0) {
                        QuickSiteAdmin.appendOptionsToSelect(nodeSelect, nodes);
                        nodeSelect.disabled = false;
                    } else {
                        nodeSelect.innerHTML = '<option value="">No nodes found</option>';
                    }
                } catch (error) {
                    console.error('Failed to load nodes:', error);
                    nodeSelect.innerHTML = '<option value="">Error loading nodes</option>';
                }
                updateHiddenValue();
            }

            if (structureSelect) {
                structureSelect.addEventListener('change', async () => {
                    const structureType = structureSelect.value;
                    if (pageSelect) {
                        if (structureType === 'page') {
                            pageSelect.style.display = 'block';
                            pageSelect.disabled = false;
                            nodeSelect.innerHTML = '<option value="">-- Select page first --</option>';
                            nodeSelect.disabled = true;
                            actionSelect.disabled = true;
                        } else {
                            pageSelect.style.display = 'none';
                            pageSelect.disabled = true;
                            pageSelect.value = '';
                            if (structureType) await loadNodeOptions(structureType);
                        }
                    } else if (structureType) {
                        await loadNodeOptions(structureType);
                    }
                    if (!structureType) {
                        nodeSelect.innerHTML = '<option value="">-- Select node --</option>';
                        nodeSelect.disabled = true;
                        actionSelect.disabled = true;
                    }
                    updateHiddenValue();
                });
            }

            if (pageSelect) {
                pageSelect.addEventListener('change', async () => {
                    const pageName = pageSelect.value;
                    if (pageName) await loadNodeOptions('page', pageName);
                    else {
                        nodeSelect.innerHTML = '<option value="">-- Select page first --</option>';
                        nodeSelect.disabled = true;
                        actionSelect.disabled = true;
                    }
                    updateHiddenValue();
                });
            }

            if (nodeSelect) {
                nodeSelect.addEventListener('change', () => {
                    actionSelect.disabled = !nodeSelect.value;
                    updateHiddenValue();
                });
            }
            if (actionSelect) actionSelect.addEventListener('change', updateHiddenValue);
            if (hintInput) hintInput.addEventListener('input', updateHiddenValue);

            function updateHiddenValue() {
                const mode = selector.querySelector(`input[name="${paramId}_mode"]:checked`)?.value || 'select';
                if (mode === 'hint') {
                    hiddenInput.value = JSON.stringify({ mode: 'hint', hint: hintInput?.value || '' });
                } else {
                    const structure = structureSelect?.value || '';
                    const page = pageSelect?.value || '';
                    const nodeId = nodeSelect?.value || '';
                    const action = actionSelect?.value || '';
                    if (structure === 'page') {
                        hiddenInput.value = (page && nodeId && action)
                            ? JSON.stringify({ mode: 'select', structure: 'page', page, nodeId, action })
                            : '';
                    } else if (structure && nodeId && action) {
                        hiddenInput.value = JSON.stringify({ mode: 'select', structure, nodeId, action });
                    } else {
                        hiddenInput.value = '';
                    }
                }
            }
        });
    }

    // ---- Tag-Select Component ----

    function initTagSelect(el, options) {
        const search = el.querySelector('.ai-spec-tag-select__search');
        const tagsContainer = el.querySelector('.ai-spec-tag-select__tags');
        const dropdown = el.querySelector('.ai-spec-tag-select__dropdown');
        const hidden = el.querySelector('input[type="hidden"]');
        const container = el.querySelector('.ai-spec-tag-select__container');

        let selected = hidden.value ? hidden.value.split(',').map(s => s.trim()).filter(Boolean) : [];

        function render() {
            // Update hidden value
            hidden.value = selected.join(',');
            hidden.dispatchEvent(new Event('change', { bubbles: true }));

            // Render tags
            tagsContainer.innerHTML = '';
            selected.forEach(val => {
                const opt = options.find(o => o.value === val);
                const tag = document.createElement('span');
                tag.className = 'ai-spec-tag-select__tag';
                tag.innerHTML = escapeHtml(opt ? opt.label : val) +
                    ' <button type="button" class="ai-spec-tag-select__tag-remove" data-val="' + escapeHtml(val) + '">&times;</button>';
                tagsContainer.appendChild(tag);
            });
        }

        function showDropdown(filter) {
            const q = (filter || '').toLowerCase();
            const filtered = options.filter(o =>
                o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q)
            );
            if (!filtered.length) {
                dropdown.innerHTML = '<div class="ai-spec-tag-select__empty">No results</div>';
            } else {
                dropdown.innerHTML = filtered.map(o => {
                    const isSel = selected.includes(o.value);
                    return '<div class="ai-spec-tag-select__option' + (isSel ? ' ai-spec-tag-select__option--selected' : '') +
                        '" data-val="' + escapeHtml(o.value) + '">' +
                        '<span>' + escapeHtml(o.label) + '</span>' +
                        '<span class="ai-spec-tag-select__option-code">' + escapeHtml(o.value) + '</span>' +
                        '</div>';
                }).join('');
            }
            dropdown.classList.add('ai-spec-tag-select__dropdown--open');
        }

        function hideDropdown() {
            dropdown.classList.remove('ai-spec-tag-select__dropdown--open');
        }

        function addValue(val) {
            if (!selected.includes(val)) {
                selected.push(val);
                render();
            }
            search.value = '';
            showDropdown('');
            search.focus();
        }

        function removeValue(val) {
            selected = selected.filter(v => v !== val);
            render();
            if (dropdown.classList.contains('ai-spec-tag-select__dropdown--open')) {
                showDropdown(search.value);
            }
        }

        // Events
        search.addEventListener('focus', () => showDropdown(search.value));
        search.addEventListener('input', () => showDropdown(search.value));
        container.addEventListener('click', () => search.focus());

        search.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !search.value && selected.length) {
                removeValue(selected[selected.length - 1]);
            }
        });

        dropdown.addEventListener('mousedown', e => {
            e.preventDefault(); // Prevent blur on search
            const opt = e.target.closest('.ai-spec-tag-select__option');
            if (opt && !opt.classList.contains('ai-spec-tag-select__option--selected')) {
                addValue(opt.dataset.val);
            }
        });

        tagsContainer.addEventListener('click', e => {
            const btn = e.target.closest('.ai-spec-tag-select__tag-remove');
            if (btn) {
                e.stopPropagation();
                removeValue(btn.dataset.val);
            }
        });

        // Close dropdown on outside click
        document.addEventListener('click', e => {
            if (!el.contains(e.target)) hideDropdown();
        });

        render();
    }

    // Expose globally for inline script init, then init any that loaded before us
    window.initTagSelect = initTagSelect;

    function initTagSelects() {
        document.querySelectorAll('.ai-spec-tag-select').forEach(el => {
            if (el._tagSelectInit) return;
            const optionsJson = el.dataset.options;
            if (optionsJson) {
                try {
                    initTagSelect(el, JSON.parse(optionsJson));
                    el._tagSelectInit = true;
                } catch (e) {
                    console.error('[tag-select] Failed to init:', e);
                }
            }
        });
    }

    function updateStats(els, text) {
        const chars = text.length;
        const words = text.trim() ? text.trim().split(/\s+/).length : 0;
        if (els.charCount) els.charCount.textContent = chars.toLocaleString() + ' chars';
        if (els.wordCount) els.wordCount.textContent = words.toLocaleString() + ' words';
    }

    function loadAutoOptions(els) {
        if (els.autoPreviewCheckbox) {
            els.autoPreviewCheckbox.checked = localStorage.getItem(QuickSiteStorageKeys.aiAutoPreview) === 'true';
        }
        if (els.autoExecuteCheckbox) {
            els.autoExecuteCheckbox.checked = localStorage.getItem(QuickSiteStorageKeys.aiAutoExecute) === 'true';
        }
    }

    /**
     * Initialize the "load preset" select — fetch existing items from the API,
     * populate a <select> above the form parameters, and fill in mapped fields
     * when the user picks an item.  The user can still edit any field afterwards.
     * If the final buildName matches an existing item we set the companion
     * hidden param so the build step is skipped.
     */
    function initPresetLoader(config, els) {
        if (!els.form) return;
        const raw = els.form.dataset.loadPreset;
        if (!raw) return;

        let preset;
        try { preset = JSON.parse(raw); } catch { return; }

        const { command, dataPath, valueField, setParam, fieldMap } = preset;
        if (!command || !fieldMap) return;

        const select = document.getElementById('preset-select');
        if (!select) return;

        // Hidden param (e.g. buildExists)
        const hiddenInput = setParam ? els.form.querySelector(`input[name="${setParam}"]`) : null;

        // Store fetched data rows keyed by the value field
        let dataByKey = {};
        let nameSet = new Set();

        (async () => {
            try {
                const resp = await fetch(config.managementUrl + encodeURIComponent(command), {
                    headers: { 'Authorization': 'Bearer ' + config.token }
                });
                const result = await resp.json();
                if (!resp.ok || !result.data) { select.disabled = false; return; }

                const items = dataPath ? result.data[dataPath] : result.data;
                if (!Array.isArray(items) || !items.length) { select.disabled = false; return; }

                items.forEach(item => {
                    const key = valueField ? item[valueField] : item;
                    if (!key) return;
                    dataByKey[key] = item;
                    nameSet.add(key);
                    const opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = key;
                    select.appendChild(opt);
                });

                select.disabled = false;
            } catch (e) {
                console.warn('Failed to load presets for', command, e);
                select.disabled = false;
            }
        })();

        // When user picks a preset, fill in the mapped fields
        select.addEventListener('change', () => {
            const key = select.value;
            const row = dataByKey[key];
            if (!row) {
                // "New build" selected — reset companion flag
                if (hiddenInput) { hiddenInput.value = 'false'; els.form.dispatchEvent(new Event('change')); }
                return;
            }
            for (const [paramId, dataField] of Object.entries(fieldMap)) {
                const input = els.form.querySelector(`[name="${paramId}"]`);
                if (input) input.value = row[dataField] ?? '';
            }
            if (hiddenInput) { hiddenInput.value = 'true'; els.form.dispatchEvent(new Event('change')); }
        });

        // Also watch the name field: if user edits it to match an existing
        // build ⇒ set buildExists=true, otherwise false.
        const nameParamId = Object.entries(fieldMap).find(([, df]) => df === valueField)?.[0];
        const nameInput = nameParamId ? els.form.querySelector(`[name="${nameParamId}"]`) : null;
        if (nameInput && hiddenInput) {
            const syncMatch = () => {
                hiddenInput.value = nameSet.has(nameInput.value.trim()) ? 'true' : 'false';
                els.form.dispatchEvent(new Event('change'));
            };
            nameInput.addEventListener('input', syncMatch);
            nameInput.addEventListener('change', syncMatch);
        }
    }

    // ========================================================================
    // 9. BOOTSTRAP — with double-init guard
    // ========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
