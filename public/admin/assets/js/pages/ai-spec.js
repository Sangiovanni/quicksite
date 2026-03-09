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

    // ========================================================================
    // 3. AI PROVIDER MANAGER
    // ========================================================================

    const AI_STORAGE_KEYS = {
        keysV2: 'quicksite_ai_keys_v2',
        defaultProvider: 'quicksite_ai_default_provider',
        persist: 'quicksite_ai_persist',
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
        // DEBUG: Proxy to trace postCommandsRaw mutations
        const _stateTarget = {
            postCommandsRaw: [],
            userParams: {},
            preCommandsExecuted: false,
            parsedCommands: [],
            pendingExecution: false,
            autoValidateTimeout: null
        };
        const state = new Proxy(_stateTarget, {
            set(target, prop, value) {
                if (prop === 'postCommandsRaw') {
                    const caller = new Error().stack.split('\n').slice(1, 4).join(' <- ');
                    console.log('[TRACE postCommandsRaw] SET to:', JSON.stringify(value), '\nCaller:', caller);
                }
                target[prop] = value;
                return true;
            }
        });

        // --- 4d. AI Provider ---
        const aiProvider = new AiProviderManager();
        aiProvider.initSelector(els.apiIntegrationWrapper, els.providerSelector);

        // --- 4e. Event bindings ---
        bindEvents(config, els, state, aiProvider);

        // --- 4f. Initialization ---
        initNodeSelectors();
        updateConditionalFields(els);
        loadAutoOptions(els);

        if (!config.isManualWorkflow && config.hasNoParams) {
            generatePrompt(config, els, state, {});
        }
        if (config.isManualWorkflow && config.hasNoParams) {
            setTimeout(() => generateManualSteps(config, els, {}), 100);
        }

        // Public API
        window.AiSpec = {
            generatePrompt: (params) => generatePrompt(config, els, state, params || {})
        };
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
        }

        // -- Manual workflow --
        if (els.previewManualStepsBtn) {
            els.previewManualStepsBtn.addEventListener('click', () => generateManualSteps(config, els, {}));
        }
        if (els.executeManualBtn) {
            els.executeManualBtn.addEventListener('click', function() {
                const steps = window._manualWorkflowSteps || [];
                if (steps.length === 0) { alert('No steps to execute'); return; }
                const commands = steps.map(step => ({ command: step.command, ...step.params }));
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
        const AUTO_KEYS = { autoPreview: 'quicksite_ai_auto_preview', autoExecute: 'quicksite_ai_auto_execute' };
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
        state.preCommandsExecuted = false;

        try {
            const queryString = new URLSearchParams(params).toString();
            const url = config.adminBase + '/api/ai-spec/' + config.specId + (queryString ? '?' + queryString : '');

            const response = await fetch(url, {
                headers: { 'Authorization': 'Bearer ' + config.token }
            });
            const data = await response.json();
            console.log('[TRACE postCommandsRaw] API response keys:', Object.keys(data.data || {}), 'postCommandsRaw:', JSON.stringify(data.data?.postCommandsRaw));

            if (data.success && data.data?.prompt) {
                // Execute preCommands if any
                const preCommands = data.data.preCommands || [];
                if (preCommands.length > 0) {
                    const loadingText = els.promptLoading?.querySelector('span');
                    if (loadingText) loadingText.textContent = 'Executing pre-commands...';

                    const preResult = await executePreCommands(config, preCommands);

                    if (!preResult.success) {
                        let errorMessage = '❌ Pre-command failed: ' + preResult.error;
                        if (preResult.failedCommand?.command === 'addRoute' &&
                            preResult.errorData?.message?.includes('already exists')) {
                            errorMessage += '\n\n💡 This route already exists. Use the Edit Page spec to modify an existing page.';
                        }
                        if (els.promptOutput) els.promptOutput.value = errorMessage;
                        if (els.promptLoading) els.promptLoading.style.display = 'none';
                        if (els.promptOutput) els.promptOutput.style.display = 'block';
                        updateStats(els, '');
                        return;
                    }

                    state.preCommandsExecuted = true;
                    await new Promise(resolve => setTimeout(resolve, 1500));
                }

                // Store post-commands for later execution
                state.postCommandsRaw = data.data.postCommandsRaw || [];
                state.userParams = data.data.userParams || {};
                console.warn('[ai-spec] generatePrompt: stored postCommandsRaw, count:', state.postCommandsRaw.length, state.postCommandsRaw);

                const userPrompt = els.userPromptTextarea ? els.userPromptTextarea.value.trim() : '';
                let finalPrompt = data.data.prompt;
                if (userPrompt) {
                    finalPrompt += '\n\n---\n\n**User Request:**\n' + userPrompt;
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

    // -- Execute PreCommands --

    async function executePreCommands(config, preCommands) {
        if (!preCommands || preCommands.length === 0) return { success: true, results: [] };
        const results = [];

        for (const cmd of preCommands) {
            try {
                const cmdUrl = config.managementUrl + cmd.command + (cmd.urlParams?.length ? '/' + cmd.urlParams.join('/') : '');
                const response = await fetch(cmdUrl, {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + config.token, 'Content-Type': 'application/json' },
                    body: JSON.stringify(cmd.params || {})
                });
                const data = await response.json();

                results.push({
                    command: cmd.command,
                    success: response.ok && data.status < 400,
                    data: data,
                    abortOnFail: cmd.abortOnFail !== false
                });

                if (!response.ok || data.status >= 400) {
                    if (cmd.abortOnFail !== false) {
                        return { success: false, error: data.message || `Command ${cmd.command} failed`, errorData: data, failedCommand: cmd, results };
                    }
                }
            } catch (error) {
                results.push({ command: cmd.command, success: false, error: error.message });
                if (cmd.abortOnFail !== false) {
                    return { success: false, error: error.message, failedCommand: cmd, results };
                }
            }
        }

        return { success: true, results };
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

        // Post-command placeholders
        if (state.postCommandsRaw.length > 0) {
            els.commandList.appendChild(htmlSeparator('— Post-Commands (resolved after execution) —'));
            state.postCommandsRaw.forEach((cmdDef, index) => {
                const item = document.createElement('div');
                item.className = 'command-item command-item--post';
                item.innerHTML = htmlPostCommandPreview(index + 1, cmdDef.command || cmdDef.template || 'auto', cmdDef.condition);
                els.commandList.appendChild(item);
            });
        }

        if (els.commandCount) {
            let text = state.parsedCommands.length + ' command' + (state.parsedCommands.length > 1 ? 's' : '');
            if (state.postCommandsRaw.length > 0) text += ` (+${state.postCommandsRaw.length} auto)`;
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
            if ((routesResponse.status === 200 || routesResponse.success) && routesResponse.data?.flat_routes) {
                const protectedRoutes = ['404', 'home'];
                routesResponse.data.flat_routes
                    .filter(r => !protectedRoutes.includes(r))
                    .sort((a, b) => b.length - a.length)
                    .forEach(r => commands.push({ command: 'deleteRoute', params: { route: r } }));
            }

            // 3. Delete all assets
            const assetsResponse = await apiCall('listAssets');
            if ((assetsResponse.status === 200 || assetsResponse.success) && assetsResponse.data?.assets) {
                for (const [category, files] of Object.entries(assetsResponse.data.assets)) {
                    for (const file of files) {
                        commands.push({ command: 'deleteAsset', params: { category, filename: file.filename } });
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

    // -- Execute Commands (main AI workflow) --

    async function executeCommands(config, els, state, withFreshStart) {
        if (els.commandPreviewSection) els.commandPreviewSection.style.display = 'none';
        if (els.executionResultsSection) els.executionResultsSection.style.display = 'block';
        if (els.executionProgress) els.executionProgress.style.display = 'flex';
        if (els.executionResults) els.executionResults.innerHTML = '';

        let allCommands = [...state.parsedCommands];
        let freshStartCount = 0;
        console.warn('[ai-spec] executeCommands: state.postCommandsRaw count:', state.postCommandsRaw.length);
        // Fresh Start
        if (withFreshStart) {
            if (els.progressText) els.progressText.textContent = 'Analyzing project for Fresh Start...';
            const freshStartCommands = await generateFreshStartCommands(config);
            freshStartCount = freshStartCommands.length;
            allCommands = [...freshStartCommands, ...state.parsedCommands];
        }

        // Create pending items
        allCommands.forEach((cmd, index) => {
            const isFreshStart = index < freshStartCount;
            const el = document.createElement('div');
            el.innerHTML = htmlPendingItem(
                'result-' + index,
                cmd.command,
                (isFreshStart ? 'Fresh Start: ' : '') + 'Pending...',
                isFreshStart ? '🧹 ' : ''
            );
            if (els.executionResults) els.executionResults.appendChild(el.firstElementChild);
        });

        // Separator after fresh start
        if (freshStartCount > 0 && els.executionResults) {
            els.executionResults.insertBefore(
                htmlSeparator('— AI Commands —'),
                els.executionResults.children[freshStartCount]
            );
        }

        // Execute loop
        for (let i = 0; i < allCommands.length; i++) {
            const cmd = allCommands[i];
            const isFreshStart = i < freshStartCount;

            // Delay after Fresh Start completes for config sync
            if (freshStartCount > 0 && i === freshStartCount) {
                if (els.progressText) els.progressText.textContent = '⏳ Waiting for config sync...';
                await new Promise(resolve => setTimeout(resolve, 1500));
            }

            if (els.progressText) {
                els.progressText.textContent = `${isFreshStart ? '🧹 Fresh Start: ' : ''}Executing command ${i + 1} of ${allCommands.length}: ${cmd.command}`;
            }

            const resultItem = document.getElementById('result-' + i);
            const prefix = isFreshStart ? '🧹 ' : '';
            const detailsId = `details-${i}`;

            try {
                const { data, isSuccess, errorMsg } = await executeOneCommand(config, cmd);
                const isAcceptableFailure = isFreshStart && data.status === 404;
                const paramsDisplay = formatParamsDisplay(cmd.params);
                const responseDisplay = formatResponseDisplay(data);

                if (isSuccess || isAcceptableFailure) {
                    updateResultItem(resultItem, {
                        isSuccess: true, isError: false,
                        icon: isAcceptableFailure ? '⏭️' : '✅',
                        prefix, command: cmd.command,
                        statusMessage: isAcceptableFailure ? 'Skipped (not found)' : (data.message || 'Success'),
                        detailsId,
                        sections: [
                            { label: 'Parameters:', value: paramsDisplay },
                            { label: 'Response:', value: responseDisplay }
                        ]
                    });
                } else {
                    updateResultItem(resultItem, {
                        isSuccess: false, isError: true,
                        icon: '❌', prefix, command: cmd.command,
                        statusMessage: errorMsg,
                        detailsId,
                        sections: [
                            { label: 'Parameters:', value: paramsDisplay },
                            { label: 'Response:', value: responseDisplay }
                        ]
                    });
                }
            } catch (error) {
                updateResultItem(resultItem, {
                    isSuccess: false, isError: true,
                    icon: '❌', prefix, command: cmd.command,
                    statusMessage: error.message,
                    detailsId,
                    sections: [
                        { label: 'Parameters:', value: formatParamsDisplay(cmd.params) },
                        { label: 'Error Stack:', value: error.stack || error.message }
                    ]
                });
            }
        }

        // -- Post-Commands --
        await executePostCommands(config, els, state);

        if (els.executionProgress) els.executionProgress.style.display = 'none';
    }

    // -- Post-Commands execution (after AI commands) --

    /**
     * Extract language info from the AI commands that were just executed.
     * Scans for addLang commands and setMultilingual to infer multilingual + languages.
     * Also fetches the current default language from the server.
     */
    async function extractLangsFromCommands(config, commands) {
        const addedLangs = [];
        let multilingual = false;

        for (const cmd of commands) {
            if (cmd.command === 'addLang' && cmd.params?.code) {
                addedLangs.push(cmd.params.code.toLowerCase());
            }
            if (cmd.command === 'setMultilingual' && cmd.params?.enabled) {
                multilingual = true;
            }
        }

        if (addedLangs.length === 0) return null;

        // Fetch default language from server (it's not in addLang commands)
        let defaultLang = 'en';
        try {
            const resp = await fetch(config.managementUrl + 'getLangList', {
                headers: { 'Authorization': 'Bearer ' + config.token }
            });
            const data = await resp.json();
            if (data.data?.default_language) {
                defaultLang = data.data.default_language;
            }
        } catch (e) { /* use fallback 'en' */ }

        // Build full language list: default + added
        const allLangs = [defaultLang, ...addedLangs.filter(l => l !== defaultLang)];

        return {
            multilingual: multilingual || addedLangs.length > 0,
            languages: allLangs.join(',')
        };
    }

    async function executePostCommands(config, els, state) {
        // Always collect current form params as fallback
        const formParams = els.form ? collectFormParams(els.form) : {};

        // FAILSAFE: if postCommandsRaw is empty, re-fetch from API
        if (state.postCommandsRaw.length === 0) {
            console.warn('[ai-spec] postCommandsRaw is empty — recovering from API...');
            try {
                const container = document.querySelector('.ai-spec');
                const specId = container?.dataset.specId;
                if (specId && config.adminBase && config.token) {
                    const queryString = new URLSearchParams(formParams).toString();
                    const recoveryUrl = config.adminBase + '/api/ai-spec/' + specId + (queryString ? '?' + queryString : '');
                    const recoveryResp = await fetch(recoveryUrl, {
                        headers: { 'Authorization': 'Bearer ' + config.token }
                    });
                    const recoveryData = await recoveryResp.json();
                    if (recoveryData.success && recoveryData.data?.postCommandsRaw) {
                        state.postCommandsRaw = recoveryData.data.postCommandsRaw;
                        state.userParams = recoveryData.data.userParams || formParams;
                        console.warn('[ai-spec] RECOVERED postCommandsRaw from API, count:', state.postCommandsRaw.length);
                    }
                }
            } catch (e) {
                console.error('[ai-spec] Recovery fetch failed:', e);
            }
        }

        if (state.postCommandsRaw.length === 0) return;

        // Extract language info from executed AI commands (best source of truth)
        const extractedLangs = await extractLangsFromCommands(config, state.parsedCommands);

        // Build final params: state.userParams > extracted from AI commands > form params
        const baseParams = Object.keys(state.userParams).length > 0 ? { ...state.userParams } : { ...formParams };
        if (extractedLangs) {
            // Override with extracted values — these reflect what the AI actually did
            if (extractedLangs.multilingual) baseParams.multilingual = 'true';
            if (extractedLangs.languages) baseParams.languages = extractedLangs.languages;
        }
        const finalUserParams = baseParams;
        console.warn('[ai-spec] executePostCommands: postCommandsRaw count:', state.postCommandsRaw.length, 'finalUserParams:', finalUserParams);

        if (els.progressText) els.progressText.textContent = 'Waiting for filesystem to settle...';
        await new Promise(resolve => setTimeout(resolve, 1500));

        if (els.progressText) els.progressText.textContent = 'Resolving post-commands with fresh config...';

        // Add separator
        if (els.executionResults) {
            els.executionResults.appendChild(htmlSeparator('— Post-Commands (Auto-Generated) —'));
        }

        try {
            const resolveResponse = await fetch(config.adminBase + '/api/ai-spec-resolve-post', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + config.token, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    postCommandsRaw: state.postCommandsRaw,
                    userParams: finalUserParams
                })
            });

            const resolveData = await resolveResponse.json();
            const resolvedPostCommands = resolveData.success ? (resolveData.data?.commands || []) : [];

            if (resolvedPostCommands.length === 0) {
                const noPostItem = document.createElement('div');
                noPostItem.style.cssText = 'padding: 8px; color: var(--admin-text-muted); font-size: 12px; text-align: center;';
                noPostItem.textContent = 'No post-commands to execute (conditions not met)';
                if (els.executionResults) els.executionResults.appendChild(noPostItem);
                return;
            }

            // Create pending items
            resolvedPostCommands.forEach((cmd, idx) => {
                const el = document.createElement('div');
                el.innerHTML = htmlPendingItem('result-post-' + idx, cmd.command, 'Post-Command: Pending...', '🔧 ');
                if (els.executionResults) els.executionResults.appendChild(el.firstElementChild);
            });

            // Execute
            for (let j = 0; j < resolvedPostCommands.length; j++) {
                const cmd = resolvedPostCommands[j];
                const resultItem = document.getElementById('result-post-' + j);
                if (els.progressText) els.progressText.textContent = `Executing post-command ${j + 1}/${resolvedPostCommands.length}...`;

                const detailsId = `details-post-${j}`;
                try {
                    const { data, isSuccess, errorMsg } = await executeOneCommand(config, cmd);
                    const paramsDisplay = formatParamsDisplay(cmd.params);
                    const responseDisplay = formatResponseDisplay(data);

                    updateResultItem(resultItem, {
                        isSuccess, isError: !isSuccess,
                        icon: isSuccess ? '✅' : '❌',
                        prefix: '🔧 ', command: cmd.command,
                        statusMessage: isSuccess ? (data.message || 'Success') : errorMsg,
                        detailsId,
                        sections: [
                            { label: 'Parameters:', value: paramsDisplay },
                            { label: 'Response:', value: responseDisplay }
                        ]
                    });
                } catch (error) {
                    if (resultItem) {
                        resultItem.className = 'result-item result-item--error';
                        resultItem.innerHTML = htmlResultItem({
                            icon: '❌', prefix: '🔧 ', command: cmd.command,
                            statusMessage: error.message, isError: true
                        });
                    }
                }
            }
        } catch (error) {
            console.error('Error resolving post-commands:', error);
            const errorItem = document.createElement('div');
            errorItem.className = 'result-item result-item--error';
            errorItem.innerHTML = htmlResultItem({
                icon: '❌', command: 'Post-Commands Resolution',
                statusMessage: error.message, isError: true
            });
            if (els.executionResults) els.executionResults.appendChild(errorItem);
        }
    }

    // -- Send to AI --

    async function sendToAi(config, els, state, aiProvider) {
        const baseConfig = aiProvider.getConfig();
        if (!baseConfig.configured) {
            if (confirm('No AI API key configured. Would you like to configure it now?')) {
                window.location.href = config.adminBase + '/ai-settings';
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

        const originalText = els.sendToAiText?.textContent || 'Send';
        els.sendToAiBtn.disabled = true;

        const maxTimeout = 180;
        let elapsedSeconds = 0;
        const abortController = new AbortController();

        const timerInterval = setInterval(() => {
            elapsedSeconds++;
            if (els.sendToAiText) {
                els.sendToAiText.textContent = `⏳ ${formatTime(elapsedSeconds)} / ${formatTime(maxTimeout)}`;
            }
        }, 1000);
        if (els.sendToAiText) els.sendToAiText.textContent = `⏳ 0:00 / ${formatTime(maxTimeout)}`;

        // Cancel functionality
        const originalOnClick = els.sendToAiBtn.onclick;
        els.sendToAiBtn.disabled = false;
        els.sendToAiBtn.classList.add('admin-btn--danger');
        els.sendToAiBtn.onclick = () => {
            abortController.abort();
            clearInterval(timerInterval);
            if (els.sendToAiText) els.sendToAiText.textContent = originalText;
            els.sendToAiBtn.disabled = false;
            els.sendToAiBtn.classList.remove('admin-btn--danger');
            els.sendToAiBtn.onclick = originalOnClick;
        };

        try {
            const response = await fetch(config.managementUrl + 'callAi', {
                signal: abortController.signal,
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + config.token },
                body: JSON.stringify({
                    key: aiConfig.key,
                    provider: aiConfig.provider,
                    model: aiProvider.selectedModel || aiConfig.model,
                    messages: [{ role: 'user', content: prompt }],
                    max_tokens: 16384,
                    timeout: 180
                })
            });

            const data = await response.json();
            clearInterval(timerInterval);
            els.sendToAiBtn.classList.remove('admin-btn--danger');
            els.sendToAiBtn.onclick = null;

            const isSuccess = data.status >= 200 && data.status < 300;

            if (isSuccess) {
                let content = data.data?.content || '';
                if (!content && data.data?.debug) {
                    console.warn('AI response parsing issue:', data.data.debug);
                    content = '/* DEBUG: Content could not be parsed. Raw response: */\n' + (data.data.debug.raw_response_preview || 'N/A');
                }

                // Strip markdown code fences
                content = content.trim();
                if (content.startsWith('```')) {
                    content = content.replace(/^```[a-zA-Z]*\n?/, '').replace(/\n?```\s*$/, '');
                }

                if (els.aiResponseInput) {
                    els.aiResponseInput.value = content;
                    if (els.aiResponseSection) {
                        els.aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    setTimeout(() => triggerAutoFlow(config, els, state), 300);
                    if (els.sendToAiText) els.sendToAiText.textContent = `✅ ${formatTime(elapsedSeconds)}`;
                    setTimeout(() => {
                        if (els.sendToAiText) els.sendToAiText.textContent = originalText;
                        els.sendToAiBtn.disabled = false;
                    }, 3000);
                }
            } else {
                const errorMsg = data.error || data.message || 'Failed to get AI response';
                let helpHint = '';
                const errLower = errorMsg.toLowerCase();
                if (errLower.includes('quota') || errLower.includes('exceeded') || errLower.includes('limit')) {
                    helpHint = '\n\n💡 This usually means:\n• OpenAI: Add billing/credits to your account\n• Google: Try "gemini-1.5-flash" (better free tier)\n• The model you selected may not be available on free tier';
                } else if (errLower.includes('not found') || errLower.includes('does not exist')) {
                    helpHint = '\n\n💡 This model may not exist or be available. Try a different model from the dropdown.';
                } else if (errLower.includes('invalid') && errLower.includes('key')) {
                    helpHint = '\n\n💡 Your API key appears to be invalid. Check the key in AI Settings.';
                } else if (errLower.includes('rate')) {
                    helpHint = '\n\n💡 Rate limit reached. Wait a moment and try again.';
                }
                alert('AI Error: ' + errorMsg + helpHint);
                if (els.sendToAiText) els.sendToAiText.textContent = originalText;
                els.sendToAiBtn.disabled = false;
                els.sendToAiBtn.classList.remove('admin-btn--danger');
                els.sendToAiBtn.onclick = null;
            }
        } catch (error) {
            clearInterval(timerInterval);
            if (error.name === 'AbortError') { console.log('AI request cancelled by user'); return; }
            alert('Network error: ' + error.message);
            if (els.sendToAiText) els.sendToAiText.textContent = originalText;
            els.sendToAiBtn.disabled = false;
            els.sendToAiBtn.classList.remove('admin-btn--danger');
            els.sendToAiBtn.onclick = null;
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

            try {
                const params = {};
                for (const [key, value] of Object.entries(cmd)) {
                    if (key !== 'command' && key !== 'method') params[key] = value;
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
                const response = await fetch(url, options);
                const text = await response.text();
                let result;
                try { result = text ? JSON.parse(text) : { status: response.status }; }
                catch (e) { result = { status: response.status, message: text || 'No response' }; }

                const isSuccess = response.ok || result.status === 200 || result.success;
                if (resultItem) {
                    resultItem.className = 'result-item ' + (isSuccess ? 'result-item--success' : 'result-item--error');
                    resultItem.querySelector('.result-item__icon').textContent = isSuccess ? '✅' : '❌';
                    resultItem.querySelector('.result-item__message').textContent =
                        result.message || result.data?.message || (isSuccess ? 'Success' : 'Failed');
                }
                if (isSuccess) successCount++; else {
                    failCount++;
                    if (cmd.abortOnFail) {
                        if (els.executionProgress) els.executionProgress.style.display = 'none';
                        if (els.executionResults) els.executionResults.appendChild(htmlSummary(successCount, failCount, cmd.command));
                        return;
                    }
                }
            } catch (error) {
                failCount++;
                if (resultItem) {
                    resultItem.className = 'result-item result-item--error';
                    resultItem.querySelector('.result-item__icon').textContent = '❌';
                    resultItem.querySelector('.result-item__message').textContent = error.message;
                }
                if (cmd.abortOnFail) {
                    if (els.executionProgress) els.executionProgress.style.display = 'none';
                    if (els.executionResults) els.executionResults.appendChild(htmlSummary(successCount, failCount, cmd.command));
                    return;
                }
            }
            await new Promise(resolve => setTimeout(resolve, 100));
        }

        if (els.executionProgress) els.executionProgress.style.display = 'none';
        if (els.executionResults) els.executionResults.appendChild(htmlSummary(successCount, failCount));
    }

    // ========================================================================
    // 8. FORM/UI HELPERS
    // ========================================================================

    function updateConditionalFields(els) {
        if (!els.form) return;
        const formData = {};
        els.form.querySelectorAll('input, select, textarea').forEach(input => {
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

    function updateStats(els, text) {
        const chars = text.length;
        const words = text.trim() ? text.trim().split(/\s+/).length : 0;
        if (els.charCount) els.charCount.textContent = chars.toLocaleString() + ' chars';
        if (els.wordCount) els.wordCount.textContent = words.toLocaleString() + ' words';
    }

    function loadAutoOptions(els) {
        if (els.autoPreviewCheckbox) {
            els.autoPreviewCheckbox.checked = localStorage.getItem('quicksite_ai_auto_preview') === 'true';
        }
        if (els.autoExecuteCheckbox) {
            els.autoExecuteCheckbox.checked = localStorage.getItem('quicksite_ai_auto_execute') === 'true';
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
