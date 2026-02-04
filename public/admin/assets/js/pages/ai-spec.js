/**
 * AI Spec Page JavaScript
 * Extracted from ai/spec.php for browser caching
 * 
 * Dependencies:
 * - QuickSiteAdmin global (from admin-common.js)
 * - specId, isCreateSpec, managementUrl, token passed via data attributes
 */
(function() {
    'use strict';
    
    function init() {
        // Wait for QuickSiteAdmin to be available
        if (typeof QuickSiteAdmin === 'undefined') {
            setTimeout(init, 50);
            return;
        }
        
        // Get configuration from data attributes
        const container = document.querySelector('.ai-spec');
        if (!container) return;
        
        const specId = container.dataset.specId || '';
        const isCreateSpec = container.dataset.isCreateSpec === 'true';
        const isManualWorkflow = container.dataset.isManual === 'true';
        const baseUrl = QuickSiteAdmin.config.baseUrl || '';
        const adminBase = QuickSiteAdmin.config.adminBase || '';
        const managementUrl = QuickSiteAdmin.config.managementBase || (baseUrl + '/management/');
        const token = QuickSiteAdmin.config.token || '';
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    const form = document.getElementById('spec-params-form');
    const promptOutput = document.getElementById('prompt-output');
    const promptLoading = document.getElementById('prompt-loading');
    const copyBtn = document.getElementById('copy-prompt');
    const sendToAiBtn = document.getElementById('send-to-ai');
    const sendToAiText = document.getElementById('send-to-ai-text');
    const promptNextStep = document.getElementById('prompt-next-step');
    const charCount = document.getElementById('char-count');
    const wordCount = document.getElementById('word-count');
    const userPromptTextarea = document.getElementById('user-prompt');
    
    // AI storage keys (v2 - multi-provider support, must match ai-settings.php)
    const AI_STORAGE_KEYS = {
        keysV2: 'quicksite_ai_keys_v2',
        defaultProvider: 'quicksite_ai_default_provider',
        persist: 'quicksite_ai_persist',
        // Legacy v1 keys for migration
        legacyKey: 'quicksite_ai_key',
        legacyProvider: 'quicksite_ai_provider',
        legacyModel: 'quicksite_ai_model'
    };
    
    // Get AI configuration from storage (v2 format with v1 fallback)
    function getAiConfig() {
        const persist = localStorage.getItem(AI_STORAGE_KEYS.persist) === 'true';
        const storage = persist ? localStorage : sessionStorage;
        
        // Try v2 format first
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
        
        return {
            key: null,
            provider: null,
            model: null,
            configured: false,
            allProviders: null
        };
    }
    
    // Initialize provider selector for multi-provider support
    const apiIntegrationWrapper = document.getElementById('api-integration-wrapper');
    const providerSelector = document.getElementById('provider-selector');
    let selectedProviderId = null;
    let selectedModelId = null;
    
    // Known models for reference (with free tier info where applicable)
    // Note: Free tier availability changes - Google Gemini is currently the best free option
    const knownModels = {
        openai: {
            recommended: ['gpt-4o', 'gpt-4-turbo', 'gpt-4'],
            popular: ['gpt-4o-mini', 'gpt-3.5-turbo', 'o1', 'o1-mini'],
            freeTier: false // OpenAI requires billing
        },
        anthropic: {
            recommended: ['claude-sonnet-4-20250514', 'claude-3-5-sonnet-20241022', 'claude-3-opus-20240229'],
            popular: ['claude-3-5-haiku-20241022', 'claude-3-haiku-20240307'],
            freeTier: false // Anthropic requires billing
        },
        google: {
            recommended: ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
            popular: ['gemini-2.0-flash', 'gemini-1.0-pro'],
            freeTier: true, // Google offers generous free tier
            freeTierModels: ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-1.0-pro']
        },
        mistral: {
            recommended: ['mistral-large-latest', 'mistral-medium-latest'],
            popular: ['mistral-small-latest', 'open-mixtral-8x22b', 'open-mistral-7b'],
            freeTier: false // Mistral requires billing
        }
    };
    
    function initializeProviderSelector() {
        const aiConfig = getAiConfig();
        
        // If no providers configured, hide the API integration section entirely
        if (!aiConfig.configured || !aiConfig.allProviders) {
            if (apiIntegrationWrapper) apiIntegrationWrapper.style.display = 'none';
            selectedProviderId = aiConfig.provider;
            selectedModelId = aiConfig.model;
            return;
        }
        
        const providers = Object.entries(aiConfig.allProviders);
        
        // Show the API integration section
        if (apiIntegrationWrapper) apiIntegrationWrapper.style.display = 'block';
        if (!providerSelector) return;
        
        providerSelector.innerHTML = '';
        
        // Build optgroups for each provider with their models
        providers.forEach(([providerId, data]) => {
            const optgroup = document.createElement('optgroup');
            const known = knownModels[providerId] || { recommended: [], popular: [], freeTier: false, freeTierModels: [] };
            
            // Add free tier indicator to provider label
            optgroup.label = data.name + (known.freeTier ? ' 🆓' : '');
            
            // Use enabledModels if available (user-filtered), otherwise all models
            const availableModels = data.enabledModels || data.models || [];
            
            // Sort models: recommended first, then popular, then rest
            const sortedModels = sortModels(availableModels, known.recommended, known.popular, known.freeTierModels || []);
            
            sortedModels.forEach(modelInfo => {
                const option = document.createElement('option');
                option.value = `${providerId}::${modelInfo.model}`;
                
                // Build label with badges
                let label = modelInfo.model;
                if (modelInfo.isRecommended) {
                    label = '⭐ ' + label;
                    if (modelInfo.isFreeTier) {
                        label += ' 🆓';
                    }
                } else if (modelInfo.isFreeTier) {
                    label = '🆓 ' + label;
                } else if (modelInfo.isNew) {
                    label = '🆕 ' + label;
                }
                
                option.textContent = label;
                
                // Select if this is the default model for the default provider
                if (providerId === aiConfig.provider && modelInfo.model === data.defaultModel) {
                    option.selected = true;
                    selectedProviderId = providerId;
                    selectedModelId = modelInfo.model;
                }
                
                optgroup.appendChild(option);
            });
            
            providerSelector.appendChild(optgroup);
        });
        
        // If no selection was made, select the first option
        if (!selectedProviderId && providerSelector.options.length > 0) {
            providerSelector.selectedIndex = 0;
            const firstValue = providerSelector.value;
            if (firstValue) {
                [selectedProviderId, selectedModelId] = firstValue.split('::');
            }
        }
    }
    
    // Sort models: recommended first, then popular, then rest (mark new and free tier ones)
    function sortModels(availableModels, recommended, popular, freeTierModels = []) {
        const allKnown = [...recommended, ...popular];
        
        return availableModels.map(model => {
            const isRecommended = recommended.includes(model);
            const isPopular = popular.includes(model);
            const isNew = !allKnown.some(known => model.includes(known) || known.includes(model));
            const isFreeTier = freeTierModels.some(free => model.includes(free) || free.includes(model));
            
            return {
                model,
                isRecommended,
                isPopular,
                isNew,
                isFreeTier,
                sortOrder: isRecommended ? 0 : (isPopular ? 1 : (isNew ? 3 : 2))
            };
        }).sort((a, b) => a.sortOrder - b.sortOrder);
    }
    
    // Handle provider/model selection change
    if (providerSelector) {
        providerSelector.addEventListener('change', function() {
            const value = this.value;
            if (value && value.includes('::')) {
                [selectedProviderId, selectedModelId] = value.split('::');
            }
        });
    }
    
    // Get config for a specific provider with optional model override
    function getProviderConfig(providerId, modelOverride = null) {
        const aiConfig = getAiConfig();
        if (!aiConfig.allProviders || !aiConfig.allProviders[providerId]) {
            return aiConfig; // Fallback to default
        }
        const provider = aiConfig.allProviders[providerId];
        return {
            key: provider.key,
            provider: providerId,
            model: modelOverride || provider.defaultModel,
            name: provider.name,
            configured: true
        };
    }
    
    // Initialize on page load
    initializeProviderSelector();
    
    // Evaluate condition expression
    function evaluateCondition(condition, formData) {
        // Simple condition parser for expressions like "multilingual === true"
        // Supports: ===, !==, ==, !=, &&, ||
        try {
            // Replace parameter names with their values
            let expr = condition;
            for (const [key, value] of Object.entries(formData)) {
                // Handle boolean-like values
                let actualValue = value;
                if (value === 'true') actualValue = true;
                else if (value === 'false' || value === '') actualValue = false;
                
                // Replace the variable with its JSON value
                const regex = new RegExp(`\\b${key}\\b`, 'g');
                expr = expr.replace(regex, JSON.stringify(actualValue));
            }
            // Also replace any remaining undefined params with false
            expr = expr.replace(/\b[a-zA-Z_][a-zA-Z0-9_]*\b(?!\s*:)/g, (match) => {
                if (match === 'true' || match === 'false' || match === 'null') return match;
                return 'false';
            });
            return eval(expr);
        } catch (e) {
            console.warn('Condition evaluation failed:', condition, e);
            return true; // Default to showing the field
        }
    }
    
    // Update conditional fields visibility
    function updateConditionalFields() {
        if (!form) return;
        
        // Get current form values
        const formData = {};
        form.querySelectorAll('input, select, textarea').forEach(input => {
            if (input.type === 'checkbox') {
                formData[input.name] = input.checked ? 'true' : 'false';
            } else {
                formData[input.name] = input.value;
            }
        });
        
        // Check all conditional fields
        form.querySelectorAll('[data-condition]').forEach(group => {
            const condition = group.dataset.condition;
            const shouldShow = evaluateCondition(condition, formData);
            group.classList.toggle('ai-spec-form__group--hidden', !shouldShow);
        });
    }
    
    // Listen for form changes to update conditionals
    if (form) {
        form.addEventListener('change', updateConditionalFields);
        // Initial evaluation
        updateConditionalFields();
    }
    
    // === Node Selector Functionality ===
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
            
            // Mode toggle
            modeRadios.forEach(radio => {
                radio.addEventListener('change', () => {
                    const isSelectMode = radio.value === 'select';
                    if (selectUI) selectUI.style.display = isSelectMode ? 'flex' : 'none';
                    if (hintUI) hintUI.style.display = isSelectMode ? 'none' : 'block';
                    updateHiddenValue();
                });
            });
            
            // Load nodes using the same API as command-form (structure-nodes endpoint)
            async function loadNodeOptions(structureType, pageName = null) {
                nodeSelect.innerHTML = '<option value="">Loading...</option>';
                nodeSelect.disabled = true;
                actionSelect.disabled = true;
                
                if (!structureType) {
                    nodeSelect.innerHTML = '<option value="">-- Select node --</option>';
                    return;
                }
                
                // Build params for structure-nodes API
                const params = (structureType === 'page') ? [structureType, pageName] : [structureType];
                
                if (structureType === 'page' && !pageName) {
                    nodeSelect.innerHTML = '<option value="">-- Select page first --</option>';
                    return;
                }
                
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
            
            // Structure selection change
            if (structureSelect) {
                structureSelect.addEventListener('change', async () => {
                    const structureType = structureSelect.value;
                    
                    // Show/hide page select based on structure type
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
                            // Load nodes directly for menu/footer
                            if (structureType) {
                                await loadNodeOptions(structureType);
                            }
                        }
                    } else if (structureType) {
                        // No page select, load nodes directly
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
            
            // Page selection change (for page structure type)
            if (pageSelect) {
                pageSelect.addEventListener('change', async () => {
                    const pageName = pageSelect.value;
                    if (pageName) {
                        await loadNodeOptions('page', pageName);
                    } else {
                        nodeSelect.innerHTML = '<option value="">-- Select page first --</option>';
                        nodeSelect.disabled = true;
                        actionSelect.disabled = true;
                    }
                    updateHiddenValue();
                });
            }
            
            // Node selection change
            if (nodeSelect) {
                nodeSelect.addEventListener('change', () => {
                    actionSelect.disabled = !nodeSelect.value;
                    updateHiddenValue();
                });
            }
            
            // Action selection change
            if (actionSelect) {
                actionSelect.addEventListener('change', updateHiddenValue);
            }
            
            // Hint input change
            if (hintInput) {
                hintInput.addEventListener('input', updateHiddenValue);
            }
            
            // Update hidden value with combined data
            function updateHiddenValue() {
                const mode = selector.querySelector(`input[name="${paramId}_mode"]:checked`)?.value || 'select';
                
                if (mode === 'hint') {
                    hiddenInput.value = JSON.stringify({
                        mode: 'hint',
                        hint: hintInput?.value || ''
                    });
                } else {
                    const structure = structureSelect?.value || '';
                    const page = pageSelect?.value || '';
                    const nodeId = nodeSelect?.value || '';
                    const action = actionSelect?.value || '';
                    
                    if (structure === 'page') {
                        if (page && nodeId && action) {
                            hiddenInput.value = JSON.stringify({
                                mode: 'select',
                                structure: 'page',
                                page: page,
                                nodeId: nodeId,
                                action: action
                            });
                        } else {
                            hiddenInput.value = '';
                        }
                    } else if (structure && nodeId && action) {
                        hiddenInput.value = JSON.stringify({
                            mode: 'select',
                            structure: structure,
                            nodeId: nodeId,
                            action: action
                        });
                    } else {
                        hiddenInput.value = '';
                    }
                }
            }
        });
    }
    
    // Initialize node selectors
    initNodeSelectors();
    
    // Update stats
    function updateStats(text) {
        const chars = text.length;
        const words = text.trim() ? text.trim().split(/\s+/).length : 0;
        if (charCount) charCount.textContent = chars.toLocaleString() + ' chars';
        if (wordCount) wordCount.textContent = words.toLocaleString() + ' words';
    }
    
    // Generate prompt
    let specPostCommands = []; // Store post-commands from spec
    let specPostCommandsRaw = []; // Raw definitions for late resolution
    let specUserParams = {}; // User params for late resolution
    let specPreCommandsExecuted = false; // Track if preCommands have been executed
    
    // Execute preCommands before prompt generation
    async function executePreCommands(preCommands) {
        if (!preCommands || preCommands.length === 0) {
            return { success: true, results: [] };
        }
        
        const results = [];
        
        for (const cmd of preCommands) {
            try {
                const cmdUrl = managementUrl + cmd.command + (cmd.urlParams?.length ? '/' + cmd.urlParams.join('/') : '');
                
                const response = await fetch(cmdUrl, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(cmd.params || {})
                });
                
                const data = await response.json();
                
                results.push({
                    command: cmd.command,
                    success: response.ok && data.status < 400,
                    data: data,
                    abortOnFail: cmd.abortOnFail !== false // Default to true
                });
                
                // Check if this command should abort on failure
                if (!response.ok || data.status >= 400) {
                    if (cmd.abortOnFail !== false) {
                        return {
                            success: false,
                            error: data.message || `Command ${cmd.command} failed`,
                            errorData: data,
                            failedCommand: cmd,
                            results: results
                        };
                    }
                }
            } catch (error) {
                results.push({
                    command: cmd.command,
                    success: false,
                    error: error.message
                });
                
                if (cmd.abortOnFail !== false) {
                    return {
                        success: false,
                        error: error.message,
                        failedCommand: cmd,
                        results: results
                    };
                }
            }
        }
        
        return { success: true, results: results };
    }
    
    async function generatePrompt(params = {}) {
        if (promptLoading) promptLoading.style.display = 'flex';
        if (promptOutput) promptOutput.style.display = 'none';
        if (copyBtn) copyBtn.disabled = true;
        if (sendToAiBtn) sendToAiBtn.disabled = true;
        if (promptNextStep) promptNextStep.style.display = 'none';
        specPreCommandsExecuted = false;
        
        try {
            const queryString = new URLSearchParams(params).toString();
            const url = adminBase + '/api/ai-spec/' + specId + (queryString ? '?' + queryString : '');
            
            const response = await fetch(url, {
                headers: {
                    'Authorization': 'Bearer ' + token
                }
            });
            
            const data = await response.json();
            
            if (data.success && data.data?.prompt) {
                // Execute preCommands first (if any)
                const preCommands = data.data.preCommands || [];
                if (preCommands.length > 0) {
                    const loadingText = promptLoading?.querySelector('span');
                    if (loadingText) {
                        loadingText.textContent = 'Executing pre-commands...';
                    }
                    
                    const preResult = await executePreCommands(preCommands);
                    
                    if (!preResult.success) {
                        // PreCommand failed - show error and abort
                        let errorMessage = '❌ Pre-command failed: ' + preResult.error;
                        
                        // Special handling for route already exists
                        if (preResult.failedCommand?.command === 'addRoute' && 
                            preResult.errorData?.message?.includes('already exists')) {
                            errorMessage += '\n\n💡 This route already exists. Use the Edit Page spec to modify an existing page.';
                        }
                        
                        if (promptOutput) promptOutput.value = errorMessage;
                        if (promptLoading) promptLoading.style.display = 'none';
                        if (promptOutput) promptOutput.style.display = 'block';
                        updateStats('');
                        return;
                    }
                    
                    specPreCommandsExecuted = true;
                    
                    // Wait for filesystem to settle after preCommands (route/file creation)
                    await new Promise(resolve => setTimeout(resolve, 1500));
                }
                
                // Store post-commands info for later execution
                // Note: postCommands may not be fully resolved if they depend on config that AI will set
                specPostCommands = data.data.postCommands || [];
                specPostCommandsRaw = data.data.postCommandsRaw || [];
                specUserParams = data.data.userParams || {};
                
                // Get user's custom prompt
                const userPrompt = userPromptTextarea ? userPromptTextarea.value.trim() : '';
                
                // Combine spec prompt with user prompt
                let finalPrompt = data.data.prompt;
                if (userPrompt) {
                    finalPrompt += '\n\n---\n\n**User Request:**\n' + userPrompt;
                }
                
                if (promptOutput) promptOutput.value = finalPrompt;
                if (copyBtn) copyBtn.disabled = false;
                if (sendToAiBtn) sendToAiBtn.disabled = false;
                if (promptNextStep) promptNextStep.style.display = 'inline-flex';
                updateStats(finalPrompt);
            } else {
                if (promptOutput) promptOutput.value = 'Error: ' + (data.error || 'Failed to generate prompt');
                updateStats('');
            }
        } catch (error) {
            if (promptOutput) promptOutput.value = 'Error: ' + error.message;
            updateStats('');
        }
        
        if (promptLoading) promptLoading.style.display = 'none';
        if (promptOutput) promptOutput.style.display = 'block';
    }
    
    // Form submit
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(form);
            const params = {};
            formData.forEach((value, key) => {
                if (value) params[key] = value;
            });
            
            if (isManualWorkflow) {
                // For manual workflows, generate steps preview
                generateManualSteps(params);
            } else {
                // For AI workflows, generate prompt
                generatePrompt(params);
            }
        });
    }
    
    // Manual workflow: Preview steps button (for workflows without params)
    const previewManualStepsBtn = document.getElementById('preview-manual-steps');
    if (previewManualStepsBtn) {
        previewManualStepsBtn.addEventListener('click', function() {
            generateManualSteps({});
        });
    }
    
    // Generate steps for manual workflow
    async function generateManualSteps(params) {
        const loadingEl = document.getElementById('manual-steps-loading');
        const commandList = document.getElementById('manual-command-list');
        const stepCount = document.getElementById('manual-step-count');
        const executeBtn = document.getElementById('execute-manual-workflow');
        
        if (loadingEl) loadingEl.style.display = 'flex';
        if (commandList) commandList.innerHTML = '';
        
        try {
            // Call API to generate steps
            const response = await fetch(adminBase + '/api/workflow-generate-steps', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({
                    workflowId: specId,
                    params: params
                })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Failed to generate steps');
            }
            
            const steps = result.data.steps || [];
            
            if (stepCount) {
                stepCount.textContent = steps.length + ' command' + (steps.length !== 1 ? 's' : '');
            }
            
            // Render steps
            if (commandList) {
                steps.forEach((step, index) => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'command-item';
                    itemEl.innerHTML = `
                        <div class="command-item__header">
                            <span class="command-item__index">${index + 1}</span>
                            <span class="command-item__name">${escapeHtml(step.command)}</span>
                        </div>
                        <div class="command-item__params">
                            ${Object.entries(step.params || {}).map(([key, value]) => 
                                `<div class="command-item__param">
                                    <span class="command-item__param-key">${escapeHtml(key)}:</span>
                                    <span class="command-item__param-value">${escapeHtml(typeof value === 'object' ? JSON.stringify(value) : String(value))}</span>
                                </div>`
                            ).join('')}
                        </div>
                    `;
                    commandList.appendChild(itemEl);
                });
            }
            
            // Store steps for execution
            window._manualWorkflowSteps = steps;
            
            if (executeBtn) executeBtn.disabled = false;
            
        } catch (error) {
            console.error('Error generating steps:', error);
            if (commandList) {
                commandList.innerHTML = `<div class="command-error">Error: ${escapeHtml(error.message)}</div>`;
            }
        }
        
        if (loadingEl) loadingEl.style.display = 'none';
    }
    
    // Execute manual workflow
    const executeManualBtn = document.getElementById('execute-manual-workflow');
    if (executeManualBtn) {
        executeManualBtn.addEventListener('click', function() {
            const steps = window._manualWorkflowSteps || [];
            if (steps.length === 0) {
                alert('No steps to execute');
                return;
            }
            
            // Convert to commands format
            const commands = steps.map(step => ({
                command: step.command,
                ...step.params
            }));
            
            // Execute using dedicated manual workflow executor
            executeManualWorkflowCommands(commands);
        });
    }
    
    /**
     * Execute manual workflow commands (no fresh start logic)
     * This is separate from executeCommands() which is for AI workflows
     */
    async function executeManualWorkflowCommands(commands) {
        if (commandPreviewSection) commandPreviewSection.style.display = 'none';
        if (executionResultsSection) executionResultsSection.style.display = 'block';
        if (executionProgress) executionProgress.style.display = 'flex';
        if (executionResults) executionResults.innerHTML = '';
        
        // Create result items for all commands
        commands.forEach((cmd, index) => {
            const item = document.createElement('div');
            item.className = 'result-item result-item--pending';
            item.id = 'result-' + index;
            item.innerHTML = `
                <span class="result-item__icon">⏳</span>
                <div class="result-item__content">
                    <div class="result-item__command">🧹 ${escapeHtml(cmd.command)}</div>
                    <div class="result-item__message">Pending...</div>
                </div>
            `;
            if (executionResults) executionResults.appendChild(item);
        });
        
        // Execute each command
        let successCount = 0;
        let failCount = 0;
        
        for (let i = 0; i < commands.length; i++) {
            const cmd = commands[i];
            
            if (progressText) {
                progressText.textContent = `Executing command ${i + 1} of ${commands.length}: ${cmd.command}`;
            }
            
            const resultItem = document.getElementById('result-' + i);
            
            try {
                // Build params (everything except 'command' and 'method')
                const params = {};
                for (const [key, value] of Object.entries(cmd)) {
                    if (key !== 'command' && key !== 'method') {
                        params[key] = value;
                    }
                }
                
                // Use method from step (default POST for workflow commands)
                const method = cmd.method || 'POST';
                const url = managementUrl + cmd.command;
                
                const options = {
                    method: method,
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    }
                };
                
                // Send body for POST/PUT/PATCH methods if we have params
                if (method !== 'GET' && method !== 'DELETE' && Object.keys(params).length > 0) {
                    options.body = JSON.stringify(params);
                }
                
                const response = await fetch(url, options);
                const text = await response.text();
                let result;
                try {
                    result = text ? JSON.parse(text) : { status: response.status };
                } catch (e) {
                    result = { status: response.status, message: text || 'No response' };
                }
                
                const isSuccess = response.ok || result.status === 200 || result.success;
                
                if (resultItem) {
                    resultItem.className = 'result-item ' + (isSuccess ? 'result-item--success' : 'result-item--error');
                    resultItem.querySelector('.result-item__icon').textContent = isSuccess ? '✅' : '❌';
                    resultItem.querySelector('.result-item__message').textContent = 
                        result.message || result.data?.message || (isSuccess ? 'Success' : 'Failed');
                }
                
                if (isSuccess) {
                    successCount++;
                } else {
                    failCount++;
                }
                
            } catch (error) {
                failCount++;
                if (resultItem) {
                    resultItem.className = 'result-item result-item--error';
                    resultItem.querySelector('.result-item__icon').textContent = '❌';
                    resultItem.querySelector('.result-item__message').textContent = error.message;
                }
            }
            
            // Small delay between commands
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        
        // Done
        if (executionProgress) executionProgress.style.display = 'none';
        
        // Show summary
        const summary = document.createElement('div');
        summary.style.cssText = 'text-align: center; padding: 12px; margin-top: 8px; border-top: 1px solid var(--admin-border); font-weight: 500;';
        summary.innerHTML = `✅ ${successCount} succeeded` + (failCount > 0 ? ` &nbsp;|&nbsp; ❌ ${failCount} failed` : '');
        if (executionResults) executionResults.appendChild(summary);
    }
    
    // Auto-load steps for manual workflows without parameters
    if (isManualWorkflow && container.dataset.hasNoParams === 'true') {
        // Load steps immediately
        setTimeout(() => generateManualSteps({}), 100);
    }
    
    // Example click - fill user prompt textarea
    document.querySelectorAll('.ai-spec-example').forEach(example => {
        example.addEventListener('click', function() {
            const params = JSON.parse(this.dataset.params || '{}');
            const examplePrompt = this.dataset.prompt || '';
            
            // Fill user prompt textarea with example description
            if (userPromptTextarea && examplePrompt) {
                userPromptTextarea.value = examplePrompt;
                userPromptTextarea.focus();
            }
            
            // Fill form with example params
            if (form) {
                Object.entries(params).forEach(([key, value]) => {
                    const input = form.querySelector(`[name="${key}"]`);
                    if (input) {
                        if (input.type === 'checkbox') {
                            input.checked = value === true || value === 'true';
                        } else {
                            input.value = value;
                        }
                    }
                });
            }
            
            // Highlight the selected example
            document.querySelectorAll('.ai-spec-example').forEach(ex => ex.classList.remove('ai-spec-example--selected'));
            this.classList.add('ai-spec-example--selected');
        });
    });
    
    // Copy button - primary action for manual workflow
    const copyPromptText = document.getElementById('copy-prompt-text');
    
    if (copyBtn) {
        copyBtn.addEventListener('click', async function() {
            try {
                await navigator.clipboard.writeText(promptOutput.value);
                const originalText = copyPromptText?.textContent || 'Copy Prompt';
                if (copyPromptText) copyPromptText.textContent = 'Copied!';
                this.classList.add('admin-btn--success');
                
                // Show the hint for next step
                if (promptNextStep) promptNextStep.style.display = 'flex';
                
                // Also show the AI Response section for pasting
                const aiResponseSection = document.querySelector('.ai-response-section');
                if (aiResponseSection) {
                    aiResponseSection.style.display = 'block';
                    aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                
                setTimeout(() => { 
                    if (copyPromptText) copyPromptText.textContent = originalText;
                    this.classList.remove('admin-btn--success');
                }, 3000);
            } catch (err) {
                // Fallback
                if (promptOutput) {
                    promptOutput.select();
                    document.execCommand('copy');
                }
            }
        });
    }
    
    // Send to AI button
    if (sendToAiBtn) {
        sendToAiBtn.addEventListener('click', async function() {
            const baseConfig = getAiConfig();
            
            // Check if AI is configured
            if (!baseConfig.configured) {
                if (confirm('No AI API key configured. Would you like to configure it now?')) {
                    window.location.href = adminBase + '/ai-settings';
                }
                return;
            }
            
            // Get the selected provider's config with the selected model
            const aiConfig = selectedProviderId 
                ? getProviderConfig(selectedProviderId, selectedModelId) 
                : baseConfig;
            
            const prompt = promptOutput?.value;
            if (!prompt) {
                alert('Please generate a prompt first.');
                return;
            }
            
            // Reset UI state - show fresh AI Response section, hide previous results
            const aiResponseSection = document.querySelector('.ai-response-section');
            const aiResponseInput = document.getElementById('ai-response-input');
            const executionResultsSection = document.querySelector('.execution-results-section');
            
            if (aiResponseSection) {
                aiResponseSection.style.display = 'block';
                if (aiResponseInput) aiResponseInput.value = '';
            }
            if (executionResultsSection) {
                executionResultsSection.style.display = 'none';
            }
            
            // Show loading state with timer
            const originalText = sendToAiText?.textContent || 'Send';
            sendToAiBtn.disabled = true;
            
            // Start countdown timer
            const maxTimeout = 180; // 3 minutes max
            let elapsedSeconds = 0;
            let abortController = new AbortController();
            
            const formatTime = (seconds) => {
                const mins = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${mins}:${secs.toString().padStart(2, '0')}`;
            };
            
            const updateTimer = () => {
                elapsedSeconds++;
                const remaining = maxTimeout - elapsedSeconds;
                if (remaining >= 0 && sendToAiText) {
                    sendToAiText.textContent = `⏳ ${formatTime(elapsedSeconds)} / ${formatTime(maxTimeout)}`;
                }
            };
            
            const timerInterval = setInterval(updateTimer, 1000);
            if (sendToAiText) sendToAiText.textContent = `⏳ 0:00 / ${formatTime(maxTimeout)}`;
            
            // Add cancel functionality - change button temporarily
            const originalOnClick = sendToAiBtn.onclick;
            sendToAiBtn.disabled = false;
            sendToAiBtn.classList.add('admin-btn--danger');
            sendToAiBtn.onclick = () => {
                abortController.abort();
                clearInterval(timerInterval);
                if (sendToAiText) sendToAiText.textContent = originalText;
                sendToAiBtn.disabled = false;
                sendToAiBtn.classList.remove('admin-btn--danger');
                sendToAiBtn.onclick = originalOnClick;
            };
            
            try {
                const response = await fetch(managementUrl + 'callAi', {
                    signal: abortController.signal,
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({
                        key: aiConfig.key,
                        provider: aiConfig.provider,
                        model: selectedModelId || aiConfig.model,
                        messages: [
                            { role: 'user', content: prompt }
                        ],
                        // Request enough tokens for complex JSON responses
                        max_tokens: 16384,
                        // Allow up to 3 minutes for large responses
                        timeout: 180
                    })
                });
                
                const data = await response.json();
                
                // Stop timer and restore button
                clearInterval(timerInterval);
                sendToAiBtn.classList.remove('admin-btn--danger');
                sendToAiBtn.onclick = null;
                
                // Log full response for debugging
                console.log('AI Response:', data);
                console.log(`⏱️ AI request completed in ${formatTime(elapsedSeconds)}`);
                
                // Log rate limit info if available
                if (data.data?.rate_limits) {
                    console.log('📊 Rate Limits:', data.data.rate_limits);
                }
                
                // Log usage info
                if (data.data?.usage) {
                    console.log('📈 Token Usage:', data.data.usage);
                }
                
                // Check for success using status code (2xx = success)
                const isSuccess = data.status >= 200 && data.status < 300;
                
                if (isSuccess) {
                    let content = data.data?.content || '';
                    
                    // If no content but has debug info, log it
                    if (!content && data.data?.debug) {
                        console.warn('AI response parsing issue:', data.data.debug);
                        content = '/* DEBUG: Content could not be parsed. Raw response: */\n' + (data.data.debug.raw_response_preview || 'N/A');
                    }
                    
                    // Strip markdown code fences if present (```json ... ```)
                    content = content.trim();
                    if (content.startsWith('```')) {
                        // Remove opening fence (```json, ```JSON, ``` etc.)
                        content = content.replace(/^```[a-zA-Z]*\n?/, '');
                        // Remove closing fence
                        content = content.replace(/\n?```\s*$/, '');
                    }
                    
                    // Update AI response textarea (section already visible from reset)
                    if (aiResponseInput) {
                        aiResponseInput.value = content;
                        
                        // Auto-scroll to response section
                        if (aiResponseSection) {
                            aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                        
                        // Trigger auto-preview/execute flow if enabled
                        setTimeout(() => {
                            triggerAutoFlow();
                        }, 300);
                        
                        // Show success feedback with time taken
                        if (sendToAiText) sendToAiText.textContent = `✅ ${formatTime(elapsedSeconds)}`;
                        setTimeout(() => {
                            if (sendToAiText) sendToAiText.textContent = originalText;
                            sendToAiBtn.disabled = false;
                        }, 3000);
                    }
                } else {
                    // Show error with helpful hints
                    const errorMsg = data.error || data.message || 'Failed to get AI response';
                    
                    // Provide helpful hints for common errors
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
                    if (sendToAiText) sendToAiText.textContent = originalText;
                    sendToAiBtn.disabled = false;
                    sendToAiBtn.classList.remove('admin-btn--danger');
                    sendToAiBtn.onclick = null;
                }
            } catch (error) {
                // Stop timer on error
                clearInterval(timerInterval);
                
                // Handle abort differently
                if (error.name === 'AbortError') {
                    console.log('AI request cancelled by user');
                    return; // Button already restored by cancel handler
                }
                
                alert('Network error: ' + error.message);
                if (sendToAiText) sendToAiText.textContent = originalText;
                sendToAiBtn.disabled = false;
                sendToAiBtn.classList.remove('admin-btn--danger');
                sendToAiBtn.onclick = null;
            }
        });
    }
    
    // Export button
    const exportBtn = document.getElementById('export-spec');
    if (exportBtn) {
        exportBtn.addEventListener('click', async function() {
            try {
                const response = await fetch(adminBase + '/api/ai-spec-raw/' + specId, {
                    headers: {
                        'Authorization': 'Bearer ' + token
                    }
                });
                const data = await response.json();
                
                if (data.success) {
                    // Create a combined export object
                    const exportData = {
                        spec: data.data.spec,
                        template: data.data.template
                    };
                    
                    // Create and download file
                    const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = specId + '.spec.json';
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
        });
    }
    
    // Generate initial prompt if no parameters required (check via data attribute)
    const hasNoParams = container.dataset.hasNoParams === 'true';
    if (hasNoParams) {
        generatePrompt({});
    }
    
    // === AI Response & Command Preview ===
    const aiResponseSection = document.querySelector('.ai-response-section');
    const aiResponseInput = document.getElementById('ai-response-input');
    const aiResponseError = document.getElementById('ai-response-error');
    const validateBtn = document.getElementById('validate-response');
    const previewBtn = document.getElementById('preview-commands');
    const autoPreviewCheckbox = document.getElementById('auto-preview-checkbox');
    const autoExecuteCheckbox = document.getElementById('auto-execute-checkbox');
    const commandPreviewSection = document.querySelector('.command-preview-section');
    const commandList = document.getElementById('command-list');
    const commandCount = document.getElementById('command-count');
    const backToJsonBtn = document.getElementById('back-to-json');
    const executeBtn = document.getElementById('execute-commands');
    const executionResultsSection = document.querySelector('.execution-results-section');
    const executionProgress = document.getElementById('execution-progress');
    const progressText = document.getElementById('progress-text');
    const executionResults = document.getElementById('execution-results');
    const resetBtn = document.getElementById('reset-workflow');
    const freshStartOption = document.getElementById('fresh-start-option');
    const freshStartCheckbox = document.getElementById('fresh-start-checkbox');
    
    // Auto options storage keys
    const AUTO_OPTIONS_KEYS = {
        autoPreview: 'quicksite_ai_auto_preview',
        autoExecute: 'quicksite_ai_auto_execute'
    };
    
    // Load auto options from localStorage
    function loadAutoOptions() {
        if (autoPreviewCheckbox) {
            autoPreviewCheckbox.checked = localStorage.getItem(AUTO_OPTIONS_KEYS.autoPreview) === 'true';
        }
        if (autoExecuteCheckbox) {
            autoExecuteCheckbox.checked = localStorage.getItem(AUTO_OPTIONS_KEYS.autoExecute) === 'true';
        }
    }
    
    // Save auto options to localStorage
    if (autoPreviewCheckbox) {
        autoPreviewCheckbox.addEventListener('change', function() {
            localStorage.setItem(AUTO_OPTIONS_KEYS.autoPreview, this.checked);
        });
    }
    
    if (autoExecuteCheckbox) {
        autoExecuteCheckbox.addEventListener('change', function() {
            localStorage.setItem(AUTO_OPTIONS_KEYS.autoExecute, this.checked);
        });
    }
    
    // Load settings on page load
    loadAutoOptions();
    
    const freshStartModal = document.getElementById('fresh-start-modal');
    const modalCancelBtn = document.getElementById('modal-cancel');
    const modalProceedBtn = document.getElementById('modal-proceed');
    
    let parsedCommands = [];
    let pendingExecution = false;
    
    // Show AI response section after prompt is generated
    const originalGeneratePrompt = generatePrompt;
    // Note: We use a wrapper approach instead of reassignment
    
    // Validate JSON
    function validateJson() {
        let jsonText = aiResponseInput?.value?.trim() || '';
        if (aiResponseError) aiResponseError.style.display = 'none';
        if (previewBtn) previewBtn.style.display = 'none';
        
        if (!jsonText) {
            if (aiResponseError) {
                aiResponseError.textContent = 'Please paste the JSON response from the AI.';
                aiResponseError.style.display = 'block';
            }
            return null;
        }
        
        // Strip markdown code fences if present (```json ... ```)
        if (jsonText.startsWith('```')) {
            jsonText = jsonText.replace(/^```[a-zA-Z]*\n?/, '').replace(/\n?```\s*$/, '');
            // Update the textarea with cleaned content
            if (aiResponseInput) aiResponseInput.value = jsonText;
        }
        
        try {
            const data = JSON.parse(jsonText);
            
            // Check for commands array
            let commands = null;
            if (Array.isArray(data)) {
                commands = data;
            } else if (data.commands && Array.isArray(data.commands)) {
                commands = data.commands;
            } else if (data.command) {
                commands = [data];
            }
            
            if (!commands || commands.length === 0) {
                if (aiResponseError) {
                    aiResponseError.textContent = 'No commands found. Expected format: {"commands": [...]} or an array of commands.';
                    aiResponseError.style.display = 'block';
                }
                return null;
            }
            
            // Validate each command
            for (let i = 0; i < commands.length; i++) {
                const cmd = commands[i];
                if (!cmd.command) {
                    if (aiResponseError) {
                        aiResponseError.textContent = `Command #${i + 1} is missing the "command" field.`;
                        aiResponseError.style.display = 'block';
                    }
                    return null;
                }
                
                // Normalize command structure: if AI put params directly on command object, wrap them
                // Expected: { command: "X", params: { ... } }
                // AI might generate: { command: "X", code: "fr", name: "French" }
                if (!cmd.params) {
                    const params = {};
                    for (const [key, value] of Object.entries(cmd)) {
                        if (key !== 'command') {
                            params[key] = value;
                        }
                    }
                    if (Object.keys(params).length > 0) {
                        cmd.params = params;
                        // Remove the direct properties now that they're in params
                        for (const key of Object.keys(params)) {
                            delete cmd[key];
                        }
                        console.log(`Normalized command ${i}: ${cmd.command}`, cmd.params);
                    }
                }
            }
            
            return commands;
        } catch (e) {
            if (aiResponseError) {
                aiResponseError.textContent = 'Invalid JSON: ' + e.message;
                aiResponseError.style.display = 'block';
            }
            return null;
        }
    }
    
    // Validate button click
    if (validateBtn) {
        validateBtn.addEventListener('click', function() {
            const commands = validateJson();
            if (commands) {
                // DON'T add postCommands here - they'll be resolved after AI commands execute
                // postCommands depend on config values that AI commands will set (e.g., LANGUAGES_NAME)
                parsedCommands = [...commands];
                if (previewBtn) previewBtn.style.display = 'inline-flex';
                if (aiResponseError) aiResponseError.style.display = 'none';
                
                // Show info if postCommands will be added
                if (specPostCommandsRaw.length > 0) {
                    console.log(`${specPostCommandsRaw.length} post-commands from spec will be resolved and executed after AI commands`);
                }
            }
        });
    }
    
    // Trigger auto-preview and auto-execute flow
    function triggerAutoFlow() {
        const text = aiResponseInput?.value?.trim() || '';
        if (!text) return;
        
        const commands = validateJson();
        if (commands) {
            // DON'T add postCommands here - they'll be resolved after AI commands execute
            parsedCommands = [...commands];
            if (previewBtn) previewBtn.style.display = 'inline-flex';
            if (aiResponseError) aiResponseError.style.display = 'none';
            
            // Auto-preview if enabled
            if (autoPreviewCheckbox?.checked) {
                previewBtn?.click();
                
                // Auto-execute if enabled (with small delay for UX)
                if (autoExecuteCheckbox?.checked) {
                    setTimeout(() => {
                        executeBtn?.click();
                    }, 300);
                }
            }
        }
    }
    
    // Auto-validate on input change (debounced)
    let autoValidateTimeout = null;
    if (aiResponseInput) {
        aiResponseInput.addEventListener('input', function() {
            // Clear previous timeout
            if (autoValidateTimeout) clearTimeout(autoValidateTimeout);
            
            // Debounce: wait 500ms after user stops typing
            autoValidateTimeout = setTimeout(() => {
                triggerAutoFlow();
            }, 500);
        });
    }
    
    // Format parameters for preview
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
    
    // Get HTTP method for command
    function getMethod(cmd) {
        if (cmd.method) return cmd.method.toUpperCase();
        if (cmd.params && Object.keys(cmd.params).length > 0) return 'POST';
        return 'GET';
    }
    
    // Preview commands
    if (previewBtn) {
        previewBtn.addEventListener('click', function() {
            if (!commandList) return;
            commandList.innerHTML = '';
            
            // AI commands are all of parsedCommands (postCommands are resolved later)
            parsedCommands.forEach((cmd, index) => {
                const method = getMethod(cmd);
                const methodClass = 'command-item__method--' + method.toLowerCase();
                
                const item = document.createElement('div');
                item.className = 'command-item';
                item.innerHTML = `
                    <span class="command-item__number">${index + 1}</span>
                    <div class="command-item__content">
                        <div class="command-item__header">
                            <span class="command-item__method ${methodClass}">${method}</span>
                            <span class="command-item__command">${cmd.command}</span>
                        </div>
                        ${cmd.params ? `<div class="command-item__params">${formatParams(cmd.params)}</div>` : ''}
                    </div>
                `;
                commandList.appendChild(item);
            });
            
            // Show placeholder for post-commands that will be resolved later
            if (specPostCommandsRaw.length > 0) {
                const separator = document.createElement('div');
                separator.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
                separator.textContent = '— Post-Commands (resolved after execution) —';
                commandList.appendChild(separator);
                
                specPostCommandsRaw.forEach((cmdDef, index) => {
                    const item = document.createElement('div');
                    item.className = 'command-item command-item--post';
                    item.innerHTML = `
                        <span class="command-item__number">+${index + 1}</span>
                        <div class="command-item__content">
                            <div class="command-item__header">
                                <span class="command-item__method command-item__method--post">POST</span>
                                <span class="command-item__command">🔧 ${cmdDef.command || cmdDef.template || 'auto'}</span>
                                <span class="command-item__badge">Auto</span>
                            </div>
                            ${cmdDef.condition ? `<div class="command-item__params" style="font-style: italic;">Condition: ${cmdDef.condition}</div>` : ''}
                        </div>
                    `;
                    commandList.appendChild(item);
                });
            }
            
            if (commandCount) {
                commandCount.textContent = parsedCommands.length + ' command' + (parsedCommands.length > 1 ? 's' : '');
                if (specPostCommandsRaw.length > 0) {
                    commandCount.textContent += ` (+${specPostCommandsRaw.length} auto)`;
                }
            }
            
            // Show fresh start option for create specs
            if (freshStartOption) {
                if (isCreateSpec) {
                    freshStartOption.style.display = 'flex';
                    if (freshStartCheckbox) freshStartCheckbox.checked = true; // Default to checked
                } else {
                    freshStartOption.style.display = 'none';
                }
            }
            
            if (aiResponseSection) aiResponseSection.style.display = 'none';
            if (commandPreviewSection) commandPreviewSection.style.display = 'block';
        });
    }
    
    // Back to JSON
    if (backToJsonBtn) {
        backToJsonBtn.addEventListener('click', function() {
            if (commandPreviewSection) commandPreviewSection.style.display = 'none';
            if (aiResponseSection) aiResponseSection.style.display = 'block';
        });
    }
    
    // Modal handlers
    if (modalCancelBtn) {
        modalCancelBtn.addEventListener('click', function() {
            if (freshStartModal) freshStartModal.style.display = 'none';
            pendingExecution = false;
        });
    }
    
    if (modalProceedBtn) {
        modalProceedBtn.addEventListener('click', function() {
            if (freshStartModal) freshStartModal.style.display = 'none';
            executeCommands(false); // Execute without fresh start
        });
    }
    
    // Generate fresh start commands (same logic as batch.php)
    async function generateFreshStartCommands() {
        const commands = [];
        
        async function apiCall(command, params = {}) {
            const hasParams = Object.keys(params).length > 0;
            const url = managementUrl + command;
            const options = {
                method: hasParams ? 'POST' : 'GET',
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json'
                }
            };
            if (hasParams) options.body = JSON.stringify(params);
            try {
                const response = await fetch(url, options);
                const text = await response.text();
                return text ? JSON.parse(text) : { status: response.status, message: 'Empty response' };
            } catch (error) {
                console.error(`API call to ${command} failed:`, error);
                return { status: 500, message: error.message };
            }
        }
        
        try {
            // 1. Delete extra languages (keep only default)
            const langResponse = await apiCall('getLangList');
            let defaultLang = 'en'; // fallback
            if ((langResponse.status === 200 || langResponse.success) && langResponse.data) {
                defaultLang = langResponse.data.default_language;
                const allLangs = langResponse.data.languages || [];
                
                // Delete all non-default languages
                const langsToDelete = allLangs.filter(lang => lang !== defaultLang);
                for (const lang of langsToDelete) {
                    commands.push({ command: 'deleteLang', params: { code: lang } });
                }
            }
            
            // 1b. Disable multilingual mode
            commands.push({ command: 'setMultilingual', params: { enabled: false } });
            
            // 2. Delete routes (except 404 and home)
            const routesResponse = await apiCall('getRoutes');
            if ((routesResponse.status === 200 || routesResponse.success) && routesResponse.data?.flat_routes) {
                const protectedRoutes = ['404', 'home'];
                const routesToDelete = routesResponse.data.flat_routes
                    .filter(routeName => !protectedRoutes.includes(routeName))
                    .sort((a, b) => b.length - a.length);
                    
                for (const routeName of routesToDelete) {
                    commands.push({ command: 'deleteRoute', params: { route: routeName } });
                }
            }
            
            // 3. Delete all assets
            const assetsResponse = await apiCall('listAssets');
            if ((assetsResponse.status === 200 || assetsResponse.success) && assetsResponse.data?.assets) {
                for (const [category, files] of Object.entries(assetsResponse.data.assets)) {
                    for (const file of files) {
                        commands.push({ 
                            command: 'deleteAsset', 
                            params: { category: category, filename: file.filename } 
                        });
                    }
                }
            }
            
            // 4. Delete ALL components
            const componentsResponse = await apiCall('listComponents');
            if ((componentsResponse.status === 200 || componentsResponse.success) && componentsResponse.data?.components) {
                console.log('Fresh Start: Found', componentsResponse.data.components.length, 'components to remove:', 
                    componentsResponse.data.components.map(c => c.name).join(', '));
                for (const component of componentsResponse.data.components) {
                    commands.push({ 
                        command: 'editStructure', 
                        params: { type: 'component', name: component.name, structure: [] } 
                    });
                }
            }
            
            // 5. Clear translation keys for DEFAULT language only (except 404.*)
            const translationsResponse = await apiCall('getTranslations');
            if ((translationsResponse.status === 200 || translationsResponse.success) && translationsResponse.data?.translations) {
                const defaultLangKeys = translationsResponse.data.translations[defaultLang];
                if (defaultLangKeys) {
                    const topLevelKeys = Object.keys(defaultLangKeys).filter(key => key !== '404');
                    if (topLevelKeys.length > 0) {
                        commands.push({ 
                            command: 'deleteTranslationKeys', 
                            params: { language: defaultLang, keys: topLevelKeys } 
                        });
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
                    type: 'page',
                    name: '404', 
                    structure: [
                        { tag: 'section', params: { class: 'error-page' }, children: [
                            { tag: 'h1', children: [{ textKey: '404.pageNotFound' }] },
                            { tag: 'p', children: [{ textKey: '404.message' }] }
                        ]}
                    ]
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
    
    // Execute commands (with optional fresh start)
    async function executeCommands(withFreshStart = true) {
        if (commandPreviewSection) commandPreviewSection.style.display = 'none';
        if (executionResultsSection) executionResultsSection.style.display = 'block';
        if (executionProgress) executionProgress.style.display = 'flex';
        if (executionResults) executionResults.innerHTML = '';
        
        let allCommands = [...parsedCommands];
        let freshStartCount = 0;
        
        // If fresh start is enabled, prepend fresh start commands
        if (withFreshStart) {
            if (progressText) progressText.textContent = 'Analyzing project for Fresh Start...';
            const freshStartCommands = await generateFreshStartCommands();
            freshStartCount = freshStartCommands.length;
            allCommands = [...freshStartCommands, ...parsedCommands];
        }
        
        // Create result items for all commands
        allCommands.forEach((cmd, index) => {
            const isFreshStart = index < freshStartCount;
            const item = document.createElement('div');
            item.className = 'result-item result-item--pending';
            item.id = 'result-' + index;
            item.innerHTML = `
                <span class="result-item__icon">⏳</span>
                <div class="result-item__content">
                    <div class="result-item__command">${isFreshStart ? '🧹 ' : ''}${cmd.command}</div>
                    <div class="result-item__message">${isFreshStart ? 'Fresh Start: ' : ''}Pending...</div>
                </div>
            `;
            if (executionResults) executionResults.appendChild(item);
        });
        
        // Add separator after fresh start commands
        if (freshStartCount > 0 && executionResults) {
            const separator = document.createElement('div');
            separator.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
            separator.textContent = '— AI Commands —';
            executionResults.insertBefore(separator, executionResults.children[freshStartCount]);
        }
        
        for (let i = 0; i < allCommands.length; i++) {
            const cmd = allCommands[i];
            const isFreshStart = i < freshStartCount;
            
            // Add delay after Fresh Start completes to allow config.php to fully sync
            if (freshStartCount > 0 && i === freshStartCount) {
                if (progressText) progressText.textContent = '⏳ Waiting for config sync...';
                await new Promise(resolve => setTimeout(resolve, 1500));
            }
            
            if (progressText) {
                progressText.textContent = `${isFreshStart ? '🧹 Fresh Start: ' : ''}Executing command ${i + 1} of ${allCommands.length}: ${cmd.command}`;
            }
            
            const resultItem = document.getElementById('result-' + i);
            
            try {
                // Normalize command structure
                if (!cmd.params) {
                    const params = {};
                    for (const [key, value] of Object.entries(cmd)) {
                        if (key !== 'command') {
                            params[key] = value;
                        }
                    }
                    if (Object.keys(params).length > 0) {
                        cmd.params = params;
                    }
                }
                
                const method = getMethod(cmd);
                const url = managementUrl + cmd.command;
                
                const options = {
                    method: method,
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    }
                };
                
                if (method !== 'GET' && cmd.params) {
                    options.body = JSON.stringify(cmd.params);
                }
                
                // Debug: log what's being sent
                console.log(`[${i}] ${cmd.command}:`, cmd.params ? JSON.stringify(cmd.params) : '(no params)');
                
                const response = await fetch(url, options);
                
                // Handle empty responses gracefully
                const responseText = await response.text();
                let data;
                try {
                    data = responseText ? JSON.parse(responseText) : { status: response.status, message: 'No response body' };
                } catch (parseError) {
                    data = { status: 500, message: 'Invalid JSON response: ' + responseText.substring(0, 100) };
                }
                
                const isSuccess = (data.status >= 200 && data.status < 300) || data.success === true;
                const isAcceptableFailure = isFreshStart && data.status === 404;
                const errorMsg = data.error || data.message || 'Failed';
                
                // Helper to format params for display
                const formatParamsDisplay = (params) => {
                    if (!params) return '(none)';
                    return JSON.stringify(params, null, 2);
                };
                
                // Helper to format response for display
                const formatResponse = (data) => {
                    const display = { ...data };
                    if (display.data && JSON.stringify(display.data).length > 500) {
                        display.data = '(truncated - large data)';
                    }
                    return JSON.stringify(display, null, 2);
                };
                
                const detailsId = `details-${i}`;
                const paramsDisplay = formatParamsDisplay(cmd.params);
                const responseDisplay = formatResponse(data);
                
                if (resultItem) {
                    if (isSuccess || isAcceptableFailure) {
                        resultItem.className = 'result-item result-item--success';
                        resultItem.innerHTML = `
                            <span class="result-item__icon">${isAcceptableFailure ? '⏭️' : '✅'}</span>
                            <div class="result-item__content">
                                <div class="result-item__command">
                                    ${isFreshStart ? '🧹 ' : ''}${cmd.command}
                                    <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                                </div>
                                <div class="result-item__message">${isAcceptableFailure ? 'Skipped (not found)' : (data.message || 'Success')}</div>
                                <div class="result-item__details" id="${detailsId}">
                                    <div class="result-item__details-section">
                                        <div class="result-item__details-label">Parameters:</div>
                                        <div class="result-item__details-value">${paramsDisplay}</div>
                                    </div>
                                    <div class="result-item__details-section">
                                        <div class="result-item__details-label">Response:</div>
                                        <div class="result-item__details-value">${responseDisplay}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        resultItem.className = 'result-item result-item--error';
                        resultItem.innerHTML = `
                            <span class="result-item__icon">❌</span>
                            <div class="result-item__content">
                                <div class="result-item__command">
                                    ${isFreshStart ? '🧹 ' : ''}${cmd.command}
                                    <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                                </div>
                                <div class="result-item__error">${errorMsg}</div>
                                <div class="result-item__details" id="${detailsId}">
                                    <div class="result-item__details-section">
                                        <div class="result-item__details-label">Parameters:</div>
                                        <div class="result-item__details-value">${paramsDisplay}</div>
                                    </div>
                                    <div class="result-item__details-section">
                                        <div class="result-item__details-label">Response:</div>
                                        <div class="result-item__details-value">${responseDisplay}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                }
            } catch (error) {
                const detailsIdErr = `details-err-${i}`;
                const paramsDisplayErr = cmd.params ? JSON.stringify(cmd.params, null, 2) : '(none)';
                if (resultItem) {
                    resultItem.className = 'result-item result-item--error';
                    resultItem.innerHTML = `
                        <span class="result-item__icon">❌</span>
                        <div class="result-item__content">
                            <div class="result-item__command">
                                ${isFreshStart ? '🧹 ' : ''}${cmd.command}
                                <span class="result-item__toggle" onclick="document.getElementById('${detailsIdErr}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                            </div>
                            <div class="result-item__error">${error.message}</div>
                            <div class="result-item__details" id="${detailsIdErr}">
                                <div class="result-item__details-section">
                                    <div class="result-item__details-label">Parameters:</div>
                                    <div class="result-item__details-value">${paramsDisplayErr}</div>
                                </div>
                                <div class="result-item__details-section">
                                    <div class="result-item__details-label">Error Stack:</div>
                                    <div class="result-item__details-value">${error.stack || error.message}</div>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }
        }
        
        // Wait for filesystem to settle after AI commands before post-commands
        if (specPostCommandsRaw.length > 0) {
            if (progressText) progressText.textContent = 'Waiting for filesystem to settle...';
            await new Promise(resolve => setTimeout(resolve, 1500));
        }
        
        // After all AI commands executed, resolve and execute post-commands
        if (specPostCommandsRaw.length > 0) {
            if (progressText) progressText.textContent = 'Resolving post-commands with fresh config...';
            
            // Add separator
            const postSeparator = document.createElement('div');
            postSeparator.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
            postSeparator.textContent = '— Post-Commands (Auto-Generated) —';
            if (executionResults) executionResults.appendChild(postSeparator);
            
            try {
                // Resolve post-commands with fresh config
                const resolveResponse = await fetch(adminBase + '/api/ai-spec-resolve-post', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        postCommandsRaw: specPostCommandsRaw,
                        userParams: specUserParams
                    })
                });
                
                const resolveData = await resolveResponse.json();
                const resolvedPostCommands = resolveData.success ? (resolveData.data?.commands || []) : [];
                
                console.log('Resolved post-commands:', resolvedPostCommands);
                
                if (resolvedPostCommands.length === 0) {
                    const noPostItem = document.createElement('div');
                    noPostItem.style.cssText = 'padding: 8px; color: var(--admin-text-muted); font-size: 12px; text-align: center;';
                    noPostItem.textContent = 'No post-commands to execute (conditions not met)';
                    if (executionResults) executionResults.appendChild(noPostItem);
                } else {
                    // Create result items for post-commands
                    resolvedPostCommands.forEach((cmd, idx) => {
                        const item = document.createElement('div');
                        item.className = 'result-item result-item--pending';
                        item.id = 'result-post-' + idx;
                        item.innerHTML = `
                            <span class="result-item__icon">⏳</span>
                            <div class="result-item__content">
                                <div class="result-item__command">🔧 ${cmd.command}</div>
                                <div class="result-item__message">Post-Command: Pending...</div>
                            </div>
                        `;
                        if (executionResults) executionResults.appendChild(item);
                    });
                    
                    // Execute post-commands
                    for (let j = 0; j < resolvedPostCommands.length; j++) {
                        const cmd = resolvedPostCommands[j];
                        const resultItem = document.getElementById('result-post-' + j);
                        if (progressText) progressText.textContent = `Executing post-command ${j + 1}/${resolvedPostCommands.length}...`;
                        
                        try {
                            const method = getMethod(cmd);
                            const url = managementUrl + cmd.command;
                            
                            const options = {
                                method: method,
                                headers: {
                                    'Authorization': 'Bearer ' + token,
                                    'Content-Type': 'application/json'
                                }
                            };
                            
                            if (method !== 'GET' && cmd.params) {
                                options.body = JSON.stringify(cmd.params);
                            }
                            
                            console.log(`[post-${j}] ${cmd.command}:`, cmd.params ? JSON.stringify(cmd.params) : '(no params)');
                            
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
                            
                            const detailsId = `details-post-${j}`;
                            const paramsDisplay = cmd.params ? JSON.stringify(cmd.params, null, 2) : '(none)';
                            const responseDisplay = JSON.stringify(data, null, 2);
                            
                            if (resultItem) {
                                if (isSuccess) {
                                    resultItem.className = 'result-item result-item--success';
                                    resultItem.innerHTML = `
                                        <span class="result-item__icon">✅</span>
                                        <div class="result-item__content">
                                            <div class="result-item__command">
                                                🔧 ${cmd.command}
                                                <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                                            </div>
                                            <div class="result-item__message">${data.message || 'Success'}</div>
                                            <div class="result-item__details" id="${detailsId}">
                                                <div class="result-item__details-section">
                                                    <div class="result-item__details-label">Parameters:</div>
                                                    <div class="result-item__details-value">${paramsDisplay}</div>
                                                </div>
                                                <div class="result-item__details-section">
                                                    <div class="result-item__details-label">Response:</div>
                                                    <div class="result-item__details-value">${responseDisplay}</div>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                } else {
                                    resultItem.className = 'result-item result-item--error';
                                    resultItem.innerHTML = `
                                        <span class="result-item__icon">❌</span>
                                        <div class="result-item__content">
                                            <div class="result-item__command">
                                                🔧 ${cmd.command}
                                                <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                                            </div>
                                            <div class="result-item__error">${errorMsg}</div>
                                            <div class="result-item__details" id="${detailsId}">
                                                <div class="result-item__details-section">
                                                    <div class="result-item__details-label">Parameters:</div>
                                                    <div class="result-item__details-value">${paramsDisplay}</div>
                                                </div>
                                                <div class="result-item__details-section">
                                                    <div class="result-item__details-label">Response:</div>
                                                    <div class="result-item__details-value">${responseDisplay}</div>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }
                            }
                        } catch (error) {
                            if (resultItem) {
                                resultItem.className = 'result-item result-item--error';
                                resultItem.innerHTML = `
                                    <span class="result-item__icon">❌</span>
                                    <div class="result-item__content">
                                        <div class="result-item__command">🔧 ${cmd.command}</div>
                                        <div class="result-item__error">${error.message}</div>
                                    </div>
                                `;
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Error resolving post-commands:', error);
                const errorItem = document.createElement('div');
                errorItem.className = 'result-item result-item--error';
                errorItem.innerHTML = `
                    <span class="result-item__icon">❌</span>
                    <div class="result-item__content">
                        <div class="result-item__command">Post-Commands Resolution</div>
                        <div class="result-item__error">${error.message}</div>
                    </div>
                `;
                if (executionResults) executionResults.appendChild(errorItem);
            }
        }
        
        if (executionProgress) executionProgress.style.display = 'none';
    }
    
    // Execute commands button
    if (executeBtn) {
        executeBtn.addEventListener('click', async function() {
            const shouldFreshStart = isCreateSpec && freshStartCheckbox?.checked;
            
            // If it's a create spec and fresh start is unchecked, show warning
            if (isCreateSpec && !freshStartCheckbox?.checked) {
                pendingExecution = true;
                if (freshStartModal) freshStartModal.style.display = 'flex';
                return;
            }
            
            // Execute with fresh start if applicable
            executeCommands(shouldFreshStart);
        });
    }
    
    // Reset workflow
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (executionResultsSection) executionResultsSection.style.display = 'none';
            if (aiResponseSection) aiResponseSection.style.display = 'block';
            if (aiResponseInput) aiResponseInput.value = '';
            if (previewBtn) previewBtn.style.display = 'none';
            parsedCommands = [];
        });
    }
    
    // Expose generatePrompt for cases where it needs to be called externally
    window.AiSpec = {
        generatePrompt: generatePrompt
    };
    } // end init()
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
