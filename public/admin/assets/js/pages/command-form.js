/**
 * Command Form Page JavaScript
 * Extracted from command-form.php for browser caching
 * 
 * Dependencies:
 * - QuickSiteAdmin global (from admin.js)
 * - Configuration passed via data attributes on .admin-command-form-page container
 * 
 * @version 1.0.0
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
        const container = document.querySelector('.admin-command-form-page');
        if (!container) return;
        
        // Get translations from data attributes
        const translations = {
            noParameters: container.dataset.tNoParameters || 'No parameters required',
            requiredParams: container.dataset.tRequiredParams || 'Required Parameters',
            optionalParams: container.dataset.tOptionalParams || 'Optional Parameters',
            notes: container.dataset.tNotes || 'Notes',
            example: container.dataset.tExample || 'Example',
            successResponse: container.dataset.tSuccessResponse || 'Success Response',
            errorResponses: container.dataset.tErrorResponses || 'Error Responses'
        };

const COMMAND_NAME = container.dataset.commandName || '';
const BATCH_URL = container.dataset.batchUrl || '';

// Batch mode detection
const urlParams = new URLSearchParams(window.location.search);
const IS_BATCH_MODE = urlParams.get('batch') === '1';
const BATCH_ID = urlParams.get('batchId');

// Load documentation immediately (init() already waits for DOM ready)
loadCommandDocumentation().then(() => {
    // Initialize batch mode if detected
    if (IS_BATCH_MODE) {
        initBatchMode();
    }
});

/**
 * Initialize batch mode UI and behavior
 */
function initBatchMode() {
    // Show batch mode banner
    document.getElementById('batch-mode-banner').style.display = 'flex';
    
    // Mark form as batch mode (prevents QuickSiteAdmin.handleCommandSubmit from executing)
    const form = document.getElementById('command-form');
    form.dataset.batchMode = 'true';
    
    // Change submit button text
    const submitBtn = document.getElementById('submit-btn');
    submitBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Save to Queue
    `;
    submitBtn.classList.remove('admin-btn--primary');
    submitBtn.classList.add('admin-btn--success');
    
    // Show cancel button
    document.getElementById('cancel-batch-btn').style.display = 'inline-flex';
    
    // Update breadcrumb to go back to batch page
    const breadcrumbLink = document.getElementById('breadcrumb-link');
    if (breadcrumbLink) {
        breadcrumbLink.href = BATCH_URL;
        breadcrumbLink.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            Back to Batch
        `;
    }
    
    // Add batch submit handler
    form.addEventListener('submit', handleBatchSubmit);
}

/**
 * Handle form submission in batch mode - save to queue instead of executing
 */
function handleBatchSubmit(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const form = e.target;
    const formData = new FormData(form);
    const params = {};
    const urlParams = [];
    
    // Collect form data
    for (const [key, value] of formData.entries()) {
        // Skip empty values
        if (!value) continue;
        
        // Skip file inputs (can't serialize files to localStorage)
        const input = form.querySelector(`[name="${key}"]`);
        if (input?.type === 'file') {
            QuickSiteAdmin.showToast('File uploads cannot be added to batch queue', 'warning');
            continue;
        }
        
        // Handle URL parameters
        if (input?.dataset.urlParam !== undefined) {
            if (value) urlParams.push(value);
        } else {
            // Try to parse JSON values
            try {
                params[key] = JSON.parse(value);
            } catch {
                params[key] = value;
            }
        }
    }
    
    // Update the batch queue in localStorage
    const queueKey = 'admin_batch_queue';
    let queue = [];
    
    try {
        queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
    } catch (e) {
        queue = [];
    }
    
    if (BATCH_ID) {
        // Update existing item
        const itemIndex = queue.findIndex(item => item.id === parseInt(BATCH_ID));
        if (itemIndex !== -1) {
            queue[itemIndex].params = params;
            queue[itemIndex].urlParams = urlParams;
            QuickSiteAdmin.showToast(`Updated "${COMMAND_NAME}" in queue`, 'success');
        } else {
            // Item not found, add as new
            queue.push({
                id: Date.now(),
                command: COMMAND_NAME,
                params: params,
                urlParams: urlParams
            });
            QuickSiteAdmin.showToast(`Added "${COMMAND_NAME}" to queue`, 'success');
        }
    } else {
        // Add new item
        queue.push({
            id: Date.now(),
            command: COMMAND_NAME,
            params: params,
            urlParams: urlParams
        });
        QuickSiteAdmin.showToast(`Added "${COMMAND_NAME}" to queue`, 'success');
    }
    
    // Save and redirect back to batch page
    localStorage.setItem(queueKey, JSON.stringify(queue));
    
    setTimeout(() => {
        window.location.href = BATCH_URL;
    }, 500);
}

async function loadCommandDocumentation() {
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET', null, [COMMAND_NAME]);
        
        if (result.ok && result.data.data) {
            const doc = result.data.data;
            renderCommandForm(doc);
            renderCommandDocs(doc);
            // Initialize enhanced features after form renders
            await initEnhancedFeatures();
            
            // If in batch mode, pre-fill form with saved params
            if (IS_BATCH_MODE && BATCH_ID) {
                await prefillFromBatchQueue();
            }
        } else {
            document.getElementById('command-params').innerHTML = `
                <div class="admin-alert admin-alert--error">
                    Command documentation not found
                </div>
            `;
        }
    } catch (error) {
        document.getElementById('command-params').innerHTML = `
            <div class="admin-alert admin-alert--error">
                Failed to load command documentation: ${QuickSiteAdmin.escapeHtml(error.message)}
            </div>
        `;
    }
}

/**
 * Pre-fill form fields from batch queue item
 * This is called after initEnhancedFeatures() so selects are already converted
 * For cascading selects, we need to set values and wait for async population
 */
async function prefillFromBatchQueue() {
    const queueKey = 'admin_batch_queue';
    let queue = [];
    
    try {
        const stored = localStorage.getItem(queueKey);
        queue = JSON.parse(stored || '[]');
    } catch (e) {
        console.error('Failed to parse batch queue:', e);
        return;
    }
    
    const batchIdNum = parseInt(BATCH_ID);
    const item = queue.find(i => i.id === batchIdNum);
    
    if (!item) {
        console.error('Batch item not found:', BATCH_ID);
        return;
    }
    
    const form = document.getElementById('command-form');
    if (!form) {
        console.error('Form not found!');
        return;
    }
    
    const params = item.params || {};
    const urlParamsData = item.urlParams || [];
    
    // Commands with cascading selects need special handling with loading state
    const cascadingCommands = ['editStructure', 'getStructure', 'deleteAsset', 'downloadAsset', 'updateAssetMeta'];
    
    if (cascadingCommands.includes(COMMAND_NAME)) {
        // Show loading overlay while cascading selects load
        showFormLoadingOverlay('Loading saved parameters...');
        
        try {
            await prefillCascadingForm(form, params, urlParamsData);
            QuickSiteAdmin.showToast('Form restored from queue', 'success');
        } catch (e) {
            console.error('Pre-fill error:', e);
            // Show saved params as fallback reminder
            showSavedParamsReminder(params);
        } finally {
            hideFormLoadingOverlay();
        }
    } else {
        // Simple form - just fill all fields
        await prefillSimpleForm(form, params, urlParamsData);
        QuickSiteAdmin.showToast('Form pre-filled with saved parameters', 'info');
    }
}

/**
 * Show a loading overlay on the form
 */
function showFormLoadingOverlay(message = 'Loading...') {
    const form = document.getElementById('command-form');
    if (!form) return;
    
    // Make form container position relative
    const formContainer = form.parentElement;
    formContainer.style.position = 'relative';
    
    // Create overlay
    const overlay = document.createElement('div');
    overlay.id = 'form-loading-overlay';
    overlay.style.cssText = `
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(var(--admin-bg-rgb, 255,255,255), 0.9);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 100;
        border-radius: var(--radius-lg);
    `;
    overlay.innerHTML = `
        <div class="admin-spinner" style="width: 40px; height: 40px; margin-bottom: var(--space-md);"></div>
        <p style="color: var(--admin-text); font-weight: 500;">${QuickSiteAdmin.escapeHtml(message)}</p>
    `;
    
    formContainer.appendChild(overlay);
}

/**
 * Hide the form loading overlay
 */
function hideFormLoadingOverlay() {
    const overlay = document.getElementById('form-loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Show a reminder of saved params for complex forms (fallback when loading fails)
 */
function showSavedParamsReminder(params) {
    // Build a summary of saved params
    const summary = Object.entries(params)
        .filter(([key, value]) => value && key !== 'structure') // Exclude structure (it's long)
        .map(([key, value]) => `<strong>${key}:</strong> ${QuickSiteAdmin.escapeHtml(String(value).substring(0, 50))}`)
        .join('<br>');
    
    // Add notice before the form
    const form = document.getElementById('command-form');
    const existingNotice = document.getElementById('batch-params-notice');
    if (existingNotice) existingNotice.remove();
    
    if (summary) {
        const notice = document.createElement('div');
        notice.id = 'batch-params-notice';
        notice.className = 'admin-alert admin-alert--info';
        notice.style.marginBottom = 'var(--space-md)';
        notice.innerHTML = `
            <strong>Saved parameters for this queued command:</strong><br>
            ${summary}
            <br><small style="opacity: 0.7">Please re-select the options below, then update the queue.</small>
        `;
        form.parentNode.insertBefore(notice, form);
    }
}

/**
 * Pre-fill a simple form (no cascading selects)
 */
async function prefillSimpleForm(form, params, urlParamsData) {
    // Pre-fill URL params (in order they appear in form)
    const urlParamInputs = form.querySelectorAll('[data-url-param]');
    urlParamInputs.forEach((input, index) => {
        if (urlParamsData[index]) {
            input.value = urlParamsData[index];
        }
    });
    
    // Pre-fill regular params
    Object.entries(params).forEach(([key, value]) => {
        setFormFieldValue(form, key, value);
    });
}

/**
 * Pre-fill a form with cascading selects
 * Must set parent selects first and wait for child options to load
 * For editStructure, the structure textarea is the key - always fill that
 */
async function prefillCascadingForm(form, params, urlParamsData) {
    console.log('prefillCascadingForm called with:', params);
    
    // Determine which fields to fill based on command
    if (COMMAND_NAME === 'editStructure' || COMMAND_NAME === 'getStructure') {
        // Order: type → name → nodeId → action → structure (LAST!)
        const typeSelect = form.querySelector('[name="type"]');
        const nameSelect = form.querySelector('[name="name"]');
        const nodeIdSelect = form.querySelector('[name="nodeId"]') || form.querySelector('[name="option"]');
        const actionSelect = form.querySelector('[name="action"]');
        const structureField = form.querySelector('[name="structure"]');
        
        console.log('Found fields:', {
            type: !!typeSelect,
            name: !!nameSelect,
            nodeId: !!nodeIdSelect,
            action: !!actionSelect,
            structure: !!structureField
        });
        
        // Save structure value to fill at the end
        const structureValue = params.structure !== undefined
            ? (typeof params.structure === 'string' ? params.structure : JSON.stringify(params.structure, null, 2))
            : null;
        
        // 1) Set type first
        if (params.type && typeSelect) {
            console.log('Step 1: Setting type to:', params.type);
            typeSelect.value = params.type;
            typeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            
            // 2) For page/component, wait for name select options to load
            if (params.type === 'page' || params.type === 'component') {
                console.log('Step 2: Waiting for name select options...');
                const nameLoaded = await waitForSelectOptions(nameSelect, 3000);
                console.log('Name options loaded:', nameLoaded);
                
                // 3) Set name value
                if (nameLoaded && params.name) {
                    console.log('Step 3: Setting name to:', params.name);
                    nameSelect.value = params.name;
                    nameSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    // 4) Wait for nodeId options to load
                    if (nodeIdSelect) {
                        console.log('Step 4: Waiting for nodeId select options...');
                        const nodeIdLoaded = await waitForSelectOptions(nodeIdSelect, 3000);
                        console.log('NodeId options loaded:', nodeIdLoaded);
                        
                        // 5) Set nodeId
                        if (nodeIdLoaded && params.nodeId) {
                            console.log('Step 5: Setting nodeId to:', params.nodeId);
                            nodeIdSelect.value = params.nodeId;
                        }
                    }
                }
            } else {
                // For menu/footer, wait for nodeId options directly
                if (nodeIdSelect) {
                    console.log('Step 2 (menu/footer): Waiting for nodeId options...');
                    const nodeIdLoaded = await waitForSelectOptions(nodeIdSelect, 3000);
                    console.log('NodeId options loaded:', nodeIdLoaded);
                    
                    if (nodeIdLoaded && params.nodeId) {
                        console.log('Step 3: Setting nodeId to:', params.nodeId);
                        nodeIdSelect.value = params.nodeId;
                    }
                }
            }
        }
        
        // 6) Set action
        if (params.action && actionSelect) {
            console.log('Step 6: Setting action to:', params.action);
            actionSelect.value = params.action;
            // Don't dispatch change event here - it might try to load node content
        }
        
        // 7) Fill structure LAST so it doesn't get overwritten by any auto-load
        if (structureValue && structureField) {
            console.log('Step 7: Filling structure field, length:', structureValue.length);
            // Small delay to ensure any auto-load from action change has started
            await new Promise(r => setTimeout(r, 100));
            structureField.value = structureValue;
            console.log('Structure field filled successfully');
        }
        
    } else if (COMMAND_NAME === 'deleteAsset' || COMMAND_NAME === 'downloadAsset') {
        // Order: type → path
        const typeSelect = form.querySelector('[name="type"]');
        const pathSelect = form.querySelector('[name="path"]');
        
        if (params.type && typeSelect) {
            typeSelect.value = params.type;
            typeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            await waitForSelectOptions(pathSelect, 500);
        }
        
        if (params.path && pathSelect) {
            pathSelect.value = params.path;
        }
    }
    
    // Fill any remaining params not handled above
    const handledKeys = ['type', 'name', 'nodeId', 'option', 'action', 'structure', 'path'];
    Object.entries(params).forEach(([key, value]) => {
        if (!handledKeys.includes(key)) {
            setFormFieldValue(form, key, value);
        }
    });
}

/**
 * Wait for a select element to become enabled AND have options loaded
 * Returns true if options were loaded, false if timeout
 */
function waitForSelectOptions(select, timeout = 3000) {
    return new Promise(resolve => {
        if (!select) {
            console.log('waitForSelectOptions: select is null');
            resolve(false);
            return;
        }
        
        const startTime = Date.now();
        
        const checkInterval = setInterval(() => {
            const elapsed = Date.now() - startTime;
            
            // First check if enabled (might start disabled)
            if (select.disabled) {
                // Still disabled, keep waiting unless timeout
                if (elapsed > timeout) {
                    console.log('waitForSelectOptions: timeout waiting for enable after', elapsed, 'ms');
                    clearInterval(checkInterval);
                    resolve(false);
                }
                return; // Keep waiting
            }
            
            // Select is enabled, now check for options
            if (select.options.length > 1) {
                console.log('waitForSelectOptions: options loaded after', elapsed, 'ms:', select.options.length);
                clearInterval(checkInterval);
                resolve(true);
            } else if (elapsed > timeout) {
                console.log('waitForSelectOptions: timeout after', elapsed, 'ms, options:', select.options.length);
                clearInterval(checkInterval);
                resolve(false);
            }
            // Keep waiting for options
        }, 50);
    });
}

/**
 * Set a single form field value
 */
function setFormFieldValue(form, key, value) {
    const input = form.querySelector(`[name="${key}"]`);
    if (!input) {
        console.warn('setFormFieldValue: field not found:', key);
        return false;
    }
    
    if (input.tagName === 'TEXTAREA') {
        input.value = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
        console.log('setFormFieldValue: set textarea', key, 'length:', input.value.length);
    } else if (input.tagName === 'SELECT') {
        input.value = value;
        console.log('setFormFieldValue: set select', key, 'to:', value);
    } else if (input.type === 'checkbox') {
        input.checked = !!value;
    } else {
        input.value = value;
        console.log('setFormFieldValue: set input', key, 'to:', value);
    }
    return true;
}

/**
 * Initialize enhanced features for complex commands
 */
async function initEnhancedFeatures() {
    const form = document.getElementById('command-form');
    
    // Initialize JSON editors
    form.querySelectorAll('textarea[data-json-editor]').forEach(textarea => {
        QuickSiteAdmin.initJsonEditor(textarea);
    });
    
    // Check for special commands that need enhanced forms
    switch (COMMAND_NAME) {
        case 'editStructure':
            await initEditStructureForm();
            break;
        case 'deleteAsset':
        case 'downloadAsset':
        case 'updateAssetMeta':
            await initAssetSelectForm();
            break;
        case 'listAssets':
            await initListAssetsForm();
            break;
        case 'getStructure':
            await initGetStructureForm();
            break;
        case 'deleteRoute':
            await initRouteSelectForm();
            break;
        case 'removeLang':
        case 'getTranslation':
        case 'getTranslationKeys':
        case 'validateTranslations':
        case 'getUnusedTranslationKeys':
        case 'analyzeTranslations':
        case 'setDefaultLang':
            await initLanguageSelectForm();
            break;
        case 'createAlias':
            await initCreateAliasForm();
            break;
        case 'deleteAlias':
            await initDeleteAliasForm();
            break;
        case 'setTranslationKeys':
            await initSetTranslationKeysForm();
            break;
        case 'deleteTranslationKeys':
            await initDeleteTranslationKeysForm();
            break;
        case 'uploadAsset':
            await initUploadAssetForm();
            break;
        case 'editFavicon':
            await initEditFaviconForm();
            break;
        case 'editTitle':
            await initEditTitleForm();
            break;
        case 'editStyles':
            await initEditStylesForm();
            break;
        case 'setRootVariables':
            await initSetRootVariablesForm();
            break;
        case 'getStyleRule':
        case 'setStyleRule':
        case 'deleteStyleRule':
            await initStyleRuleForm();
            break;
        case 'getKeyframes':
        case 'deleteKeyframes':
            await initKeyframeSelectForm();
            break;
        case 'setKeyframes':
            await initSetKeyframesForm();
            break;
        case 'getBuild':
        case 'deleteBuild':
        case 'downloadBuild':
            await initBuildSelectForm();
            break;
        case 'clearCommandHistory':
            await initClearHistoryForm();
            break;
        case 'generateToken':
            initGenerateTokenForm();
            break;
    }
}

/**
 * Initialize editStructure form with cascading selects
 * Also handles URL parameters for pre-filling from Structure page
 */
async function initEditStructureForm() {
    const form = document.getElementById('command-form');
    
    // Read URL parameters (from Structure page navigation)
    const urlParams = new URLSearchParams(window.location.search);
    const prefillType = urlParams.get('type');
    const prefillName = urlParams.get('name');
    const prefillNodeId = urlParams.get('nodeId');
    const prefillAction = urlParams.get('action');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        typeSelect.required = typeInput.required;
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(typeSelect, 'structure-types', [], 'Select structure type...');
    }
    
    // Convert name input to select
    const nameInput = form.querySelector('[name="name"]');
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        nameSelect.innerHTML = '<option value="">Select type first...</option>';
        nameSelect.disabled = true;
        nameInput.replaceWith(nameSelect);
    }
    
    // Convert action input to select
    const actionInput = form.querySelector('[name="action"]');
    if (actionInput && actionInput.tagName !== 'SELECT') {
        const actionSelect = document.createElement('select');
        actionSelect.name = 'action';
        actionSelect.className = 'admin-select';
        actionInput.replaceWith(actionSelect);
        await QuickSiteAdmin.populateSelect(actionSelect, 'edit-actions', [], 'Select action...');
    }
    
    // Convert nodeId input to select (if exists)
    const nodeIdInput = form.querySelector('[name="nodeId"]');
    if (nodeIdInput && nodeIdInput.tagName !== 'SELECT') {
        const nodeIdSelect = document.createElement('select');
        nodeIdSelect.name = 'nodeId';
        nodeIdSelect.className = 'admin-select';
        nodeIdSelect.innerHTML = '<option value="">Select structure first...</option>';
        nodeIdSelect.disabled = true;
        nodeIdInput.replaceWith(nodeIdSelect);
    }
    
    // Move structure field to the end (before the action buttons)
    const structureGroup = form.querySelector('[name="structure"]')?.closest('.admin-form-group');
    const formActions = form.querySelector('.admin-form-actions');
    if (structureGroup && formActions) {
        formActions.parentNode.insertBefore(structureGroup, formActions);
    }

    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    const nodeIdSelect = form.querySelector('[name="nodeId"]');
    const actionSelect = form.querySelector('[name="action"]');
    const structureTextarea = form.querySelector('[name="structure"]');
    
    // Function to load node options and return them for selection
    async function loadNodeOptions(selectValue = null) {
        if (!nodeIdSelect) return;
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type) {
            nodeIdSelect.innerHTML = '<option value="">Select type first...</option>';
            nodeIdSelect.disabled = true;
            return;
        }
        
        if ((type === 'page' || type === 'component') && !name) {
            nodeIdSelect.innerHTML = '<option value="">Select name first...</option>';
            nodeIdSelect.disabled = true;
            return;
        }
        
        nodeIdSelect.disabled = false;
        
        // Build params based on type
        const params = (type === 'page' || type === 'component') ? [type, name] : [type];
        
        try {
            const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
            nodeIdSelect.innerHTML = '<option value="">(Optional) Select node for targeted edit...</option>';
            QuickSiteAdmin.appendOptionsToSelect(nodeIdSelect, nodes);
            
            // Select the prefilled value if provided
            if (selectValue) {
                nodeIdSelect.value = selectValue;
                // Trigger the node content loading
                await loadNodeContent();
            }
        } catch (error) {
            nodeIdSelect.innerHTML = '<option value="">Error loading nodes</option>';
        }
    }
    
    // Function to load node content when action is 'update' and nodeId is selected
    async function loadNodeContent() {
        if (!structureTextarea || !nodeIdSelect) return;
        
        const action = actionSelect?.value;
        const nodeId = nodeIdSelect?.value;
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        // Only load for update action with a selected nodeId
        if (action !== 'update' || !nodeId) return;
        
        // Build API params
        const apiParams = [type];
        if (type === 'page' || type === 'component') {
            apiParams.push(name);
        }
        apiParams.push(nodeId); // This fetches the specific node
        
        try {
            structureTextarea.placeholder = 'Loading node content...';
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, apiParams);
            
            if (result.ok && result.data.data?.node) {
                // Format the node JSON nicely
                const nodeJson = JSON.stringify(result.data.data.node, null, 2);
                structureTextarea.value = nodeJson;
                structureTextarea.placeholder = 'Node content loaded - modify and submit to update';
                QuickSiteAdmin.showToast('Node content loaded into structure field', 'info');
            }
        } catch (error) {
            console.error('Failed to load node content:', error);
            structureTextarea.placeholder = 'Enter new structure JSON...';
        }
    }
    
    if (typeSelect && nameSelect) {
        typeSelect.addEventListener('change', async () => {
            const type = typeSelect.value;
            nameSelect.disabled = false;
            
            if (type === 'page') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'pages', [], 'Select page...');
            } else if (type === 'component') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'components', [], 'Select component...');
            } else {
                nameSelect.innerHTML = '<option value="">Not required for this type</option>';
                nameSelect.disabled = true;
                // For menu/footer, load nodes directly
                await loadNodeOptions();
            }
            
            // Reset nodeId for page/component (will be populated after name selection)
            if (nodeIdSelect && (type === 'page' || type === 'component')) {
                nodeIdSelect.innerHTML = '<option value="">Select name first...</option>';
                nodeIdSelect.disabled = true;
            }
            
            // Clear structure textarea when type changes
            if (structureTextarea) {
                structureTextarea.value = '';
            }
        });
        
        // When name changes, populate nodeId options
        nameSelect.addEventListener('change', async () => {
            await loadNodeOptions();
            // Clear structure textarea when name changes
            if (structureTextarea) {
                structureTextarea.value = '';
            }
        });
    }
    
    // When nodeId changes and action is 'update', load the node content
    if (nodeIdSelect) {
        nodeIdSelect.addEventListener('change', loadNodeContent);
    }
    
    // When action changes to 'update' with a nodeId, load the node content
    // Also toggle structure required based on action
    if (actionSelect) {
        actionSelect.addEventListener('change', () => {
            loadNodeContent();
            // Structure is not required for 'delete' action
            if (structureTextarea) {
                if (actionSelect.value === 'delete') {
                    structureTextarea.removeAttribute('required');
                    structureTextarea.placeholder = 'Not required for delete action';
                } else {
                    structureTextarea.setAttribute('required', '');
                    structureTextarea.placeholder = 'Enter JSON structure...';
                }
            }
        });
    }
    
    // Pre-fill form from URL parameters (but NOT in batch mode - that's handled separately)
    if (!IS_BATCH_MODE && prefillType) {
        typeSelect.value = prefillType;
        
        // Trigger cascading for name select
        if (prefillType === 'page' || prefillType === 'component') {
            const endpoint = prefillType === 'page' ? 'pages' : 'components';
            await QuickSiteAdmin.populateSelect(nameSelect, endpoint, [], `Select ${prefillType}...`);
            nameSelect.disabled = false;
            
            if (prefillName) {
                nameSelect.value = prefillName;
                // Load node options and select the prefilled nodeId
                await loadNodeOptions(prefillNodeId);
            }
        } else {
            // menu/footer
            nameSelect.innerHTML = '<option value="">Not required for this type</option>';
            nameSelect.disabled = true;
            await loadNodeOptions(prefillNodeId);
        }
    }
    
    // Pre-fill action (but NOT in batch mode)
    if (!IS_BATCH_MODE && prefillAction && actionSelect) {
        actionSelect.value = prefillAction;
        // If action is update and nodeId is set, load the node content
        if (prefillAction === 'update' && prefillNodeId) {
            await loadNodeContent();
        }
    }
}

/**
 * Initialize getStructure form with cascading selects
 */
async function initGetStructureForm() {
    const form = document.getElementById('command-form');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        typeSelect.required = typeInput.required;
        if (typeInput.dataset.urlParam !== undefined) {
            typeSelect.dataset.urlParam = '';
        }
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(typeSelect, 'structure-types', [], 'Select structure type...');
    }
    
    // Convert name input to select
    const nameInput = form.querySelector('[name="name"]');
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        if (nameInput.dataset.urlParam !== undefined) {
            nameSelect.dataset.urlParam = '';
        }
        nameSelect.innerHTML = '<option value="">Select type first...</option>';
        nameSelect.disabled = true;
        nameInput.replaceWith(nameSelect);
    }
    
    // Convert option input to select (for showIds, summary, nodeId)
    const optionInput = form.querySelector('[name="option"]');
    if (optionInput && optionInput.tagName !== 'SELECT') {
        const optionSelect = document.createElement('select');
        optionSelect.name = 'option';
        optionSelect.className = 'admin-select';
        if (optionInput.dataset.urlParam !== undefined) {
            optionSelect.dataset.urlParam = '';
        }
        optionSelect.innerHTML = `
            <option value="">None (full structure)</option>
            <option value="showIds">showIds (add node identifiers)</option>
            <option value="summary">summary (tree overview)</option>
        `;
        optionInput.replaceWith(optionSelect);
    }
    
    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    
    if (typeSelect && nameSelect) {
        typeSelect.addEventListener('change', async () => {
            const type = typeSelect.value;
            nameSelect.disabled = false;
            
            if (type === 'page') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'pages', [], 'Select page...');
            } else if (type === 'component') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'components', [], 'Select component...');
            } else {
                // menu and footer don't need name
                nameSelect.innerHTML = '<option value="">Not required for this type</option>';
                nameSelect.disabled = true;
            }
        });
    }
}

/**
 * Initialize asset-related commands with category and file selection
 */
async function initAssetSelectForm() {
    const form = document.getElementById('command-form');
    
    // Convert category to select
    const categoryInput = form.querySelector('[name="category"]');
    if (categoryInput && categoryInput.tagName !== 'SELECT') {
        const categorySelect = document.createElement('select');
        categorySelect.name = 'category';
        categorySelect.className = 'admin-select';
        categorySelect.required = categoryInput.required;
        categoryInput.replaceWith(categorySelect);
        await QuickSiteAdmin.populateSelect(categorySelect, 'asset-categories', [], 'Select category...');
        
        // Convert filename to select
        const filenameInput = form.querySelector('[name="filename"]');
        if (filenameInput && filenameInput.tagName !== 'SELECT') {
            const filenameSelect = document.createElement('select');
            filenameSelect.name = 'filename';
            filenameSelect.className = 'admin-select';
            filenameSelect.required = filenameInput.required;
            filenameSelect.innerHTML = '<option value="">Select category first...</option>';
            filenameSelect.disabled = true;
            filenameInput.replaceWith(filenameSelect);
            
            // Update filenames when category changes
            categorySelect.addEventListener('change', async () => {
                const category = categorySelect.value;
                if (category) {
                    filenameSelect.disabled = false;
                    await QuickSiteAdmin.populateSelect(filenameSelect, 'assets', [category], 'Select file...');
                } else {
                    filenameSelect.innerHTML = '<option value="">Select category first...</option>';
                    filenameSelect.disabled = true;
                }
            });
        }
    }
}

/**
 * Initialize listAssets form with optional category select
 */
async function initListAssetsForm() {
    const form = document.getElementById('command-form');
    
    // Convert category to select (it's a URL segment parameter)
    const categoryInput = form.querySelector('[name="category"]');
    if (categoryInput && categoryInput.tagName !== 'SELECT') {
        const categorySelect = document.createElement('select');
        categorySelect.name = 'category';
        categorySelect.className = 'admin-select';
        // Not required - it's optional for listAssets
        categorySelect.required = false;
        
        // Preserve URL param attribute if present
        if (categoryInput.dataset.urlParam !== undefined) {
            categorySelect.dataset.urlParam = '';
        }
        
        categoryInput.replaceWith(categorySelect);
        
        // Add "All categories" option first, then populate with categories
        categorySelect.innerHTML = '<option value="">All categories</option>';
        try {
            const categories = await QuickSiteAdmin.fetchHelperData('asset-categories', []);
            categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.value;
                option.textContent = cat.label;
                categorySelect.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }
}

/**
 * Initialize uploadAsset form with category select and multi-file upload
 */
async function initUploadAssetForm() {
    const form = document.getElementById('command-form');
    
    // Convert category to select
    const categoryInput = form.querySelector('[name="category"]');
    if (categoryInput && categoryInput.tagName !== 'SELECT') {
        const categorySelect = document.createElement('select');
        categorySelect.name = 'category';
        categorySelect.className = 'admin-select';
        categorySelect.required = categoryInput.required;
        categoryInput.replaceWith(categorySelect);
        await QuickSiteAdmin.populateSelect(categorySelect, 'asset-categories', [], 'Select category...');
    }
    
    // Initialize multi-file upload instead of single file
    initMultiFileUploadForm();
}

/**
 * Initialize multi-file upload with progress tracking
 */
function initMultiFileUploadForm() {
    const form = document.getElementById('command-form');
    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    // Enable multiple file selection
    fileInput.setAttribute('multiple', 'multiple');
    
    // Create wrapper structure
    const wrapper = document.createElement('div');
    wrapper.className = 'admin-file-input admin-file-input--multi';
    fileInput.parentNode.insertBefore(wrapper, fileInput);
    wrapper.appendChild(fileInput);
    
    // Add label
    const label = document.createElement('div');
    label.className = 'admin-file-input__label';
    label.innerHTML = `
        <svg class="admin-file-input__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <div class="admin-file-input__text">
            <span>Click to select files or drag and drop</span>
            <span class="admin-file-input__hint">You can select multiple files • Max 10MB per file</span>
        </div>
    `;
    wrapper.appendChild(label);
    
    // File list container
    const fileList = document.createElement('div');
    fileList.className = 'admin-file-list';
    fileList.id = 'multi-file-list';
    wrapper.appendChild(fileList);
    
    // Store selected files
    let selectedFiles = [];
    
    // Handle file selection
    fileInput.addEventListener('change', () => {
        const newFiles = Array.from(fileInput.files);
        
        // Add new files to the list (avoid duplicates)
        newFiles.forEach(file => {
            const exists = selectedFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) {
                selectedFiles.push(file);
            }
        });
        
        renderFileList();
    });
    
    // Drag and drop handling
    wrapper.addEventListener('dragover', (e) => {
        e.preventDefault();
        wrapper.classList.add('admin-file-input--dragover');
    });
    
    wrapper.addEventListener('dragleave', () => {
        wrapper.classList.remove('admin-file-input--dragover');
    });
    
    wrapper.addEventListener('drop', (e) => {
        e.preventDefault();
        wrapper.classList.remove('admin-file-input--dragover');
        
        const droppedFiles = Array.from(e.dataTransfer.files);
        droppedFiles.forEach(file => {
            const exists = selectedFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) {
                selectedFiles.push(file);
            }
        });
        
        renderFileList();
    });
    
    // Render file list
    function renderFileList() {
        if (selectedFiles.length === 0) {
            fileList.innerHTML = '';
            fileList.style.display = 'none';
            return;
        }
        
        fileList.style.display = 'block';
        fileList.innerHTML = `
            <div class="admin-file-list__header">
                <span>${selectedFiles.length} file${selectedFiles.length > 1 ? 's' : ''} selected</span>
                <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="clearAllFiles()">
                    Clear All
                </button>
            </div>
            <ul class="admin-file-list__items">
                ${selectedFiles.map((file, index) => `
                    <li class="admin-file-list__item" data-index="${index}">
                        <span class="admin-file-list__icon">${getFileIcon(file.type)}</span>
                        <span class="admin-file-list__name">${file.name}</span>
                        <span class="admin-file-list__size">${formatFileSize(file.size)}</span>
                        <span class="admin-file-list__status" id="file-status-${index}"></span>
                        <button type="button" class="admin-file-list__remove" onclick="removeFile(${index})" title="Remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </li>
                `).join('')}
            </ul>
        `;
    }
    
    // Get icon based on file type
    function getFileIcon(type) {
        if (type.startsWith('image/')) return '🖼️';
        if (type.startsWith('video/')) return '🎬';
        if (type.startsWith('audio/')) return '🎵';
        if (type === 'application/pdf') return '📄';
        if (type.includes('javascript') || type.includes('css')) return '📝';
        return '📁';
    }
    
    // Remove file from list
    window.removeFile = function(index) {
        selectedFiles.splice(index, 1);
        renderFileList();
    };
    
    // Clear all files
    window.clearAllFiles = function() {
        selectedFiles = [];
        fileInput.value = '';
        renderFileList();
    };
    
    // Store reference for form submission
    window.getSelectedFiles = function() {
        return selectedFiles;
    };
    
    // Override form submission for multi-file upload
    const submitBtn = document.getElementById('submit-btn');
    const originalSubmitHandler = form.onsubmit;
    
    form.addEventListener('submit', async function(e) {
        // Check if we have multiple files to upload
        if (selectedFiles.length <= 1) {
            // Single file or no file - let the normal handler work
            return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        // Get category
        const categorySelect = form.querySelector('[name="category"]');
        const category = categorySelect?.value;
        
        if (!category) {
            QuickSiteAdmin.showToast('Please select a category first', 'error');
            return;
        }
        
        submitBtn.disabled = true;
        const originalBtnText = submitBtn.innerHTML;
        
        const responseDiv = document.getElementById('command-response');
        responseDiv.innerHTML = `
            <div class="admin-upload-progress">
                <h4>Uploading ${selectedFiles.length} files...</h4>
                <div id="upload-progress-list"></div>
            </div>
        `;
        
        const progressList = document.getElementById('upload-progress-list');
        const results = [];
        
        // Upload files sequentially
        for (let i = 0; i < selectedFiles.length; i++) {
            const file = selectedFiles[i];
            const statusEl = document.getElementById(`file-status-${i}`);
            
            // Update button
            submitBtn.innerHTML = `<span class="admin-spinner"></span> Uploading ${i + 1}/${selectedFiles.length}...`;
            
            // Update status in file list
            if (statusEl) {
                statusEl.innerHTML = '<span class="admin-spinner" style="width:16px;height:16px;"></span>';
            }
            
            // Add progress item
            progressList.innerHTML += `
                <div class="admin-upload-progress__item" id="progress-item-${i}">
                    <span class="admin-upload-progress__name">${file.name}</span>
                    <span class="admin-upload-progress__status" id="progress-status-${i}">
                        <span class="admin-spinner" style="width:14px;height:14px;"></span> Uploading...
                    </span>
                </div>
            `;
            
            try {
                // Create FormData for this file
                const formData = new FormData();
                formData.append('category', category);
                formData.append('file', file);
                
                const result = await QuickSiteAdmin.apiUpload('uploadAsset', formData);
                results.push({ file: file.name, success: result.ok, data: result.data });
                
                // Update status
                const progressStatus = document.getElementById(`progress-status-${i}`);
                if (result.ok) {
                    if (statusEl) statusEl.innerHTML = '<span style="color:var(--admin-success)">✓</span>';
                    if (progressStatus) progressStatus.innerHTML = '<span style="color:var(--admin-success)">✓ Uploaded</span>';
                } else {
                    if (statusEl) statusEl.innerHTML = '<span style="color:var(--admin-error)">✗</span>';
                    if (progressStatus) progressStatus.innerHTML = `<span style="color:var(--admin-error)">✗ ${result.data?.message || 'Failed'}</span>`;
                }
            } catch (error) {
                results.push({ file: file.name, success: false, error: error.message });
                
                const progressStatus = document.getElementById(`progress-status-${i}`);
                if (statusEl) statusEl.innerHTML = '<span style="color:var(--admin-error)">✗</span>';
                if (progressStatus) progressStatus.innerHTML = `<span style="color:var(--admin-error)">✗ ${error.message}</span>`;
            }
        }
        
        // Summary
        const successCount = results.filter(r => r.success).length;
        const failCount = results.filter(r => !r.success).length;
        
        submitBtn.innerHTML = originalBtnText;
        submitBtn.disabled = false;
        
        // Show summary
        if (failCount === 0) {
            QuickSiteAdmin.showToast(`All ${successCount} files uploaded successfully!`, 'success');
            // Clear the file list
            selectedFiles = [];
            fileInput.value = '';
            renderFileList();
        } else if (successCount === 0) {
            QuickSiteAdmin.showToast(`All ${failCount} uploads failed`, 'error');
        } else {
            QuickSiteAdmin.showToast(`${successCount} uploaded, ${failCount} failed`, 'warning');
        }
        
        // Trigger success event for successful uploads
        if (successCount > 0) {
            form.dispatchEvent(new CustomEvent('command-success', { 
                detail: { results, successCount, failCount } 
            }));
        }
        
    }, true); // Use capture to run before other handlers
}

/**
 * Initialize editFavicon form with image selector from assets/images
 */
async function initEditFaviconForm() {
    const form = document.getElementById('command-form');
    const imageNameInput = form.querySelector('[name="imageName"]');
    
    if (imageNameInput && imageNameInput.tagName !== 'SELECT') {
        const imageSelect = document.createElement('select');
        imageSelect.name = 'imageName';
        imageSelect.className = 'admin-select';
        imageSelect.required = imageNameInput.required;
        
        imageNameInput.replaceWith(imageSelect);
        
        // Load images from assets/images
        imageSelect.innerHTML = '<option value="">Loading images...</option>';
        
        try {
            const images = await QuickSiteAdmin.fetchHelperData('assets', ['images']);
            imageSelect.innerHTML = '<option value="">Select image for favicon...</option>';
            
            // Filter for PNG images (favicon requires PNG)
            images.forEach(img => {
                const option = document.createElement('option');
                option.value = img.value;
                option.textContent = img.label;
                // Highlight PNG files
                if (img.value.toLowerCase().endsWith('.png')) {
                    option.textContent = '✓ ' + img.label;
                }
                imageSelect.appendChild(option);
            });
            
            if (images.length === 0) {
                imageSelect.innerHTML = '<option value="">No images found in assets/images</option>';
            }
        } catch (error) {
            imageSelect.innerHTML = '<option value="">Error loading images</option>';
        }
    }
    
    // Add hint about PNG requirement
    const hint = document.createElement('p');
    hint.className = 'admin-hint';
    hint.innerHTML = '💡 Only PNG images are supported for favicon. Images marked with ✓ are PNG files.';
    const formGroup = form.querySelector('[name="imageName"]')?.parentNode;
    if (formGroup) {
        formGroup.appendChild(hint);
    }
}

/**
 * Initialize editTitle form with route and language selectors
 */
async function initEditTitleForm() {
    const form = document.getElementById('command-form');
    const routeInput = form.querySelector('[name="route"]');
    const langInput = form.querySelector('[name="lang"]');
    const titleInput = form.querySelector('[name="title"]');
    
    // Convert route input to select
    if (routeInput && routeInput.tagName !== 'SELECT') {
        const routeSelect = document.createElement('select');
        routeSelect.name = 'route';
        routeSelect.className = 'admin-select';
        routeSelect.required = routeInput.required;
        routeInput.replaceWith(routeSelect);
        await QuickSiteAdmin.populateSelect(routeSelect, 'routes', [], 'Select route...');
    }
    
    // Convert lang input to select
    if (langInput && langInput.tagName !== 'SELECT') {
        const langSelect = document.createElement('select');
        langSelect.name = 'lang';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        langInput.replaceWith(langSelect);
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], 'Select language...');
    }
    
    // Function to load current title
    const loadCurrentTitle = async () => {
        const routeSelect = form.querySelector('[name="route"]');
        const langSelect = form.querySelector('[name="lang"]');
        
        if (!routeSelect || !langSelect || !titleInput) return;
        
        const route = routeSelect.value;
        const lang = langSelect.value;
        
        if (!route || !lang) return;
        
        try {
            const result = await QuickSiteAdmin.fetchHelperData('page-title', [route, lang]);
            if (result && result.title !== undefined) {
                titleInput.value = result.title;
                titleInput.placeholder = result.title ? 'Current: ' + result.title : 'No title set for this route/language';
            }
        } catch (error) {
            console.error('Error loading current title:', error);
            titleInput.placeholder = 'Enter new title...';
        }
    };
    
    // Add change listeners to load title when route or lang changes
    const routeSelect = form.querySelector('[name="route"]');
    const langSelect = form.querySelector('[name="lang"]');
    
    if (routeSelect) {
        routeSelect.addEventListener('change', loadCurrentTitle);
    }
    if (langSelect) {
        langSelect.addEventListener('change', loadCurrentTitle);
    }
}

/**
 * Initialize build select form (getBuild, deleteBuild, downloadBuild)
 */
async function initBuildSelectForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        nameSelect.required = nameInput.required;
        // Preserve data-url-param attribute if present (for GET commands like getBuild, downloadBuild)
        if (nameInput.dataset.urlParam !== undefined) {
            nameSelect.dataset.urlParam = '';
        }
        nameInput.replaceWith(nameSelect);
        await QuickSiteAdmin.populateSelect(nameSelect, 'builds', [], 'Select build...');
    }
}

/**
 * Initialize clearCommandHistory form with date picker
 */
async function initClearHistoryForm() {
    const form = document.getElementById('command-form');
    const beforeInput = form.querySelector('[name="before"]');
    
    if (beforeInput && beforeInput.type !== 'date') {
        // Convert text input to date input
        const dateInput = document.createElement('input');
        dateInput.type = 'date';
        dateInput.name = 'before';
        dateInput.className = 'admin-input';
        dateInput.required = beforeInput.required;
        
        // Set max date to today
        const today = new Date().toISOString().split('T')[0];
        dateInput.max = today;
        
        // Set a sensible default (30 days ago)
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
        dateInput.value = thirtyDaysAgo.toISOString().split('T')[0];
        
        beforeInput.replaceWith(dateInput);
    }
    
    // Add hint
    const hint = document.createElement('p');
    hint.className = 'admin-hint';
    hint.innerHTML = '💡 All log entries <strong>before</strong> this date will be deleted. Set <code>confirm</code> to false first to preview what will be deleted.';
    const formGroup = form.querySelector('[name="before"]')?.parentNode;
    if (formGroup && !formGroup.querySelector('.admin-hint')) {
        formGroup.appendChild(hint);
    }
}

/**
 * Initialize generateToken form with quick-add buttons for common token names
 */
function initGenerateTokenForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    
    if (!nameInput) return;
    
    // Quick add buttons for common token names
    const quickNames = [
        { label: 'Developer', value: 'Developer Token' },
        { label: 'Collaborator', value: 'Collaborator Token' },
        { label: 'Read-Only', value: 'Read-Only API Access' },
        { label: 'CI/CD', value: 'CI/CD Pipeline' },
        { label: 'External API', value: 'External API Integration' }
    ];
    
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'quick-add-buttons';
    buttonContainer.style.cssText = 'display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;';
    
    quickNames.forEach(item => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'admin-btn admin-btn--small admin-btn--secondary';
        btn.textContent = '+ ' + item.label;
        btn.onclick = () => {
            nameInput.value = item.value;
            nameInput.focus();
        };
        buttonContainer.appendChild(btn);
    });
    
    nameInput.parentNode.appendChild(buttonContainer);
    
    // Add permission selector
    const permInput = form.querySelector('[name="permissions"]');
    if (permInput) {
        // Create permission builder UI
        const permBuilder = document.createElement('div');
        permBuilder.className = 'permission-builder';
        permBuilder.style.cssText = 'margin-top: 0.5rem;';
        
        // Permission select
        const permSelect = document.createElement('select');
        permSelect.className = 'admin-select';
        permSelect.style.cssText = 'display: inline-block; width: auto; min-width: 200px; margin-right: 0.5rem;';
        permSelect.innerHTML = `
            <option value="">-- Select permission --</option>
            <optgroup label="Full Access">
                <option value="*">* (Full access to all commands)</option>
            </optgroup>
            <optgroup label="Permission Categories">
                <option value="read">read (get*, list*, validate*, help)</option>
                <option value="write">write (edit*, add*, delete*, upload*)</option>
                <option value="admin">admin (set*, rename*, build, tokens)</option>
            </optgroup>
            <optgroup label="Common Combinations">
                <option value="read,write">read + write</option>
                <option value="read,write,admin">read + write + admin</option>
            </optgroup>
        `;
        
        // Add button
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'admin-btn admin-btn--small admin-btn--primary';
        addBtn.textContent = '+ Add';
        addBtn.onclick = () => {
            if (!permSelect.value) return;
            
            let currentPerms = [];
            try {
                currentPerms = JSON.parse(permInput.value || '[]');
                if (!Array.isArray(currentPerms)) currentPerms = [];
            } catch {
                currentPerms = [];
            }
            
            // Handle comma-separated values (for combinations)
            const newPerms = permSelect.value.split(',');
            newPerms.forEach(p => {
                if (!currentPerms.includes(p)) {
                    currentPerms.push(p);
                }
            });
            
            permInput.value = JSON.stringify(currentPerms);
            permSelect.value = '';
        };
        
        // Clear button
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'admin-btn admin-btn--small admin-btn--secondary';
        clearBtn.textContent = 'Clear';
        clearBtn.style.marginLeft = '0.5rem';
        clearBtn.onclick = () => {
            permInput.value = '[]';
        };
        
        permBuilder.appendChild(permSelect);
        permBuilder.appendChild(addBtn);
        permBuilder.appendChild(clearBtn);
        permInput.parentNode.appendChild(permBuilder);
        
        // Set default empty array
        if (!permInput.value) {
            permInput.value = '["read"]';
        }
        
        // Add hint
        const hint = document.createElement('p');
        hint.className = 'admin-hint';
        hint.innerHTML = '💡 Permissions control API access. <code>read</code> = view only, <code>write</code> = modify content, <code>admin</code> = system settings. Use <code>*</code> for full access.';
        permInput.parentNode.appendChild(hint);
    }
}

/**
 * Initialize editStyles form - loads current CSS content into textarea
 */
async function initEditStylesForm() {
    const form = document.getElementById('command-form');
    const contentTextarea = form.querySelector('[name="content"]');
    
    if (contentTextarea) {
        // Add loading indicator
        contentTextarea.placeholder = 'Loading current styles...';
        contentTextarea.disabled = true;
        
        // Add helper buttons above textarea
        const helperDiv = document.createElement('div');
        helperDiv.className = 'admin-style-helper';
        helperDiv.innerHTML = `
            <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm); flex-wrap: wrap;">
                <button type="button" id="load-styles-btn" class="admin-btn admin-btn--secondary admin-btn--small">
                    🔄 Reload Current Styles
                </button>
                <button type="button" id="format-css-btn" class="admin-btn admin-btn--outline admin-btn--small">
                    ✨ Format CSS
                </button>
            </div>
            <p class="admin-hint">⚠️ This will replace the entire style.css file. Make sure to include all styles you want to keep.</p>
        `;
        contentTextarea.parentNode.insertBefore(helperDiv, contentTextarea);
        
        const loadBtn = document.getElementById('load-styles-btn');
        const formatBtn = document.getElementById('format-css-btn');
        
        // Function to load current styles
        async function loadCurrentStyles() {
            contentTextarea.disabled = true;
            contentTextarea.placeholder = 'Loading...';
            
            try {
                const data = await QuickSiteAdmin.fetchHelperData('current-styles', []);
                // Normalize line endings for textarea display
                contentTextarea.value = data.content ? data.content.replace(/\r\n/g, '\n') : '';
                contentTextarea.placeholder = 'CSS content...';
            } catch (error) {
                contentTextarea.placeholder = 'Failed to load styles. Enter CSS manually.';
                QuickSiteAdmin.showToast('Failed to load current styles', 'error');
            }
            
            contentTextarea.disabled = false;
        }
        
        // Load styles on init
        await loadCurrentStyles();
        
        // Reload button
        loadBtn.addEventListener('click', async () => {
            await loadCurrentStyles();
            QuickSiteAdmin.showToast('Styles reloaded', 'success');
        });
        
        // Basic CSS formatting (just normalizes whitespace)
        formatBtn.addEventListener('click', () => {
            let css = contentTextarea.value;
            // Basic formatting: ensure newlines after { and ;, before }
            css = css
                .replace(/\s*{\s*/g, ' {\n  ')
                .replace(/;\s*/g, ';\n  ')
                .replace(/\s*}\s*/g, '\n}\n')
                .replace(/\n\s+\n/g, '\n')
                .replace(/  }/g, '}')
                .trim();
            contentTextarea.value = css;
            QuickSiteAdmin.showToast('CSS formatted', 'success');
        });
        
        // Make textarea taller for CSS editing
        contentTextarea.style.minHeight = '400px';
        contentTextarea.style.fontFamily = 'monospace';
    }
}

/**
 * Initialize setRootVariables form with variable selector
 */
async function initSetRootVariablesForm() {
    const form = document.getElementById('command-form');
    const variablesTextarea = form.querySelector('[name="variables"]');
    
    if (variablesTextarea) {
        // Add helper above textarea
        const helperDiv = document.createElement('div');
        helperDiv.className = 'admin-variable-selector';
        helperDiv.innerHTML = `
            <label class="admin-label">Variable Selector</label>
            <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                <select id="var-selector" class="admin-select" style="flex: 1;" size="6">
                    <option value="" disabled>Loading variables...</option>
                </select>
                <div style="display: flex; flex-direction: column; gap: var(--space-xs);">
                    <input type="text" id="var-value-input" class="admin-input" placeholder="New value..." style="width: 180px;">
                    <p class="admin-hint" style="margin: 0;">Current: <span id="var-current-value" style="font-style: italic;">-</span></p>
                    <button type="button" id="add-var-btn" class="admin-btn admin-btn--secondary">
                        Add/Update →
                    </button>
                    <button type="button" id="clear-vars-btn" class="admin-btn admin-btn--outline">
                        Clear All
                    </button>
                </div>
            </div>
            <p class="admin-hint">Select a variable to edit, or type a new value. This merges with existing variables.</p>
        `;
        variablesTextarea.parentNode.insertBefore(helperDiv, variablesTextarea);
        
        const varSelector = document.getElementById('var-selector');
        const varValueInput = document.getElementById('var-value-input');
        const currentValueSpan = document.getElementById('var-current-value');
        const addBtn = document.getElementById('add-var-btn');
        const clearBtn = document.getElementById('clear-vars-btn');
        
        // Store variables data
        let variablesData = [];
        
        // Load variables
        async function loadVariables() {
            varSelector.innerHTML = '<option value="" disabled>Loading...</option>';
            try {
                variablesData = await QuickSiteAdmin.fetchHelperData('root-variables', []);
                varSelector.innerHTML = '';
                
                if (variablesData.length === 0) {
                    varSelector.innerHTML = '<option value="" disabled>No variables found</option>';
                    return;
                }
                
                variablesData.forEach(v => {
                    const option = document.createElement('option');
                    option.value = v.value;
                    option.textContent = v.label;
                    option.dataset.currentValue = v.currentValue;
                    varSelector.appendChild(option);
                });
            } catch (error) {
                varSelector.innerHTML = '<option value="" disabled>Error loading variables</option>';
            }
        }
        
        await loadVariables();
        
        // Show current value on select
        varSelector.addEventListener('change', () => {
            const selected = varSelector.selectedOptions[0];
            if (selected && selected.dataset.currentValue) {
                currentValueSpan.textContent = selected.dataset.currentValue;
                // Pre-fill input with current value for easy editing
                varValueInput.value = selected.dataset.currentValue;
            } else {
                currentValueSpan.textContent = '-';
            }
        });
        
        // Add variable button
        addBtn.addEventListener('click', () => {
            const varName = varSelector.value;
            const varValue = varValueInput.value.trim();
            
            if (!varName) {
                QuickSiteAdmin.showToast('Please select a variable', 'warning');
                return;
            }
            if (!varValue) {
                QuickSiteAdmin.showToast('Please enter a value', 'warning');
                return;
            }
            
            // Parse current variables object
            let variables = {};
            try {
                variables = JSON.parse(variablesTextarea.value || '{}');
            } catch {
                variables = {};
            }
            
            // Add/update variable
            variables[varName] = varValue;
            
            // Update textarea
            variablesTextarea.value = JSON.stringify(variables, null, 2);
            
            // Clear input
            varValueInput.value = '';
            currentValueSpan.textContent = '-';
            
            QuickSiteAdmin.showToast(`Added: ${varName}`, 'success');
        });
        
        // Clear button
        clearBtn.addEventListener('click', () => {
            variablesTextarea.value = '{}';
        });
        
        // Refresh after successful command
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'setRootVariables') {
                await loadVariables();
                variablesTextarea.value = '{}';
                currentValueSpan.textContent = '-';
            }
        });
    }
}

/**
 * Initialize getStyleRule/setStyleRule/deleteStyleRule form with selector picker
 */
async function initStyleRuleForm() {
    const form = document.getElementById('command-form');
    const selectorInput = form.querySelector('[name="selector"]');
    const mediaQueryInput = form.querySelector('[name="mediaQuery"]');
    
    if (selectorInput) {
        // Create a helper section above the selector input
        const helperDiv = document.createElement('div');
        helperDiv.className = 'admin-style-rule-helper';
        helperDiv.innerHTML = `
            <label class="admin-label">CSS Selector Picker</label>
            <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                <select id="rule-selector" class="admin-select" style="flex: 1;" size="8">
                    <option value="" disabled>Loading selectors...</option>
                </select>
            </div>
            <p class="admin-hint">Select a CSS rule from the list above. The selector and media query fields will be filled automatically.</p>
        `;
        selectorInput.parentNode.parentNode.insertBefore(helperDiv, selectorInput.parentNode);
        
        const ruleSelector = document.getElementById('rule-selector');
        
        // Store selectors with their media queries
        let selectorsData = [];
        
        // Load selectors
        async function loadSelectors() {
            ruleSelector.innerHTML = '<option value="" disabled>Loading...</option>';
            try {
                const data = await QuickSiteAdmin.fetchHelperData('style-rules', []);
                ruleSelector.innerHTML = '';
                selectorsData = [];
                
                // Build optgroups
                data.forEach(group => {
                    if (group.type === 'optgroup') {
                        const optgroup = document.createElement('optgroup');
                        optgroup.label = group.label;
                        
                        group.options.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            option.dataset.mediaQuery = opt.mediaQuery || '';
                            optgroup.appendChild(option);
                            
                            selectorsData.push({
                                selector: opt.value,
                                mediaQuery: opt.mediaQuery
                            });
                        });
                        
                        ruleSelector.appendChild(optgroup);
                    }
                });
                
                if (ruleSelector.options.length === 0) {
                    ruleSelector.innerHTML = '<option value="" disabled>No CSS rules found</option>';
                }
            } catch (error) {
                ruleSelector.innerHTML = '<option value="" disabled>Error loading selectors</option>';
            }
        }
        
        await loadSelectors();
        
        // When a selector is chosen, fill in the form fields
        ruleSelector.addEventListener('change', () => {
            const selected = ruleSelector.selectedOptions[0];
            if (selected) {
                // Fill selector input
                if (selectorInput.tagName === 'INPUT') {
                    selectorInput.value = selected.value;
                }
                
                // Fill media query input if present
                if (mediaQueryInput) {
                    const mediaQuery = selected.dataset.mediaQuery || '';
                    if (mediaQueryInput.tagName === 'INPUT') {
                        mediaQueryInput.value = mediaQuery;
                    }
                }
            }
        });
        
        // Refresh after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteStyleRule') {
                await loadSelectors();
            }
        });
    }
}

/**
 * Initialize getKeyframes/deleteKeyframes form with keyframe name selector
 */
async function initKeyframeSelectForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        nameSelect.required = nameInput.required;
        
        // Preserve URL param attribute if present
        if (nameInput.dataset.urlParam !== undefined) {
            nameSelect.dataset.urlParam = '';
        }
        
        nameInput.replaceWith(nameSelect);
        
        // Add empty option for "all keyframes" on getKeyframes
        const isGetCommand = COMMAND_NAME === 'getKeyframes';
        const placeholder = isGetCommand ? 'All keyframes (leave empty)' : 'Select keyframe...';
        
        nameSelect.innerHTML = `<option value="">${placeholder}</option>`;
        
        try {
            const keyframes = await QuickSiteAdmin.fetchHelperData('keyframes', []);
            keyframes.forEach(kf => {
                const option = document.createElement('option');
                option.value = kf.value;
                option.textContent = kf.label;
                nameSelect.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load keyframes:', error);
        }
        
        // Refresh after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteKeyframes') {
                // Reload the select
                nameSelect.innerHTML = `<option value="">${placeholder}</option>`;
                try {
                    const keyframes = await QuickSiteAdmin.fetchHelperData('keyframes', []);
                    keyframes.forEach(kf => {
                        const option = document.createElement('option');
                        option.value = kf.value;
                        option.textContent = kf.label;
                        nameSelect.appendChild(option);
                    });
                } catch (error) {
                    console.error('Failed to reload keyframes:', error);
                }
            }
        });
    }
}

/**
 * Initialize setKeyframes form with name selector/input and frame builder
 */
async function initSetKeyframesForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    const framesTextarea = form.querySelector('[name="frames"]');
    
    if (nameInput && framesTextarea) {
        // Store existing keyframes for reference
        let existingKeyframes = {};
        
        // Add helper section above the name input
        const helperDiv = document.createElement('div');
        helperDiv.className = 'admin-keyframe-builder';
        helperDiv.innerHTML = `
            <label class="admin-label">Keyframe Builder</label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                <div>
                    <label class="admin-label admin-label--small">Animation Name</label>
                    <select id="kf-name-selector" class="admin-select" style="margin-bottom: var(--space-xs);">
                        <option value="">Select existing or type new...</option>
                    </select>
                    <input type="text" id="kf-name-input" class="admin-input" placeholder="Or type new name (e.g., fadeIn)">
                </div>
                <div>
                    <label class="admin-label admin-label--small">Current Frames</label>
                    <div id="kf-current-frames" class="admin-hint" style="background: var(--color-bg-secondary); padding: var(--space-sm); border-radius: var(--radius-sm); min-height: 60px; font-family: monospace; font-size: 12px; overflow: auto; max-height: 100px;">
                        Select an animation to see its frames
                    </div>
                </div>
            </div>
            <div style="border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: var(--space-sm); margin-bottom: var(--space-sm);">
                <label class="admin-label admin-label--small">Add Frame</label>
                <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                    <div style="width: 120px;">
                        <select id="kf-frame-key" class="admin-select">
                            <option value="from">from</option>
                            <option value="to">to</option>
                            <option value="0%">0%</option>
                            <option value="25%">25%</option>
                            <option value="50%">50%</option>
                            <option value="75%">75%</option>
                            <option value="100%">100%</option>
                            <option value="custom">Custom...</option>
                        </select>
                        <input type="text" id="kf-frame-key-custom" class="admin-input" placeholder="e.g., 0%, 100%" style="margin-top: var(--space-xs); display: none;">
                    </div>
                    <div style="flex: 1;">
                        <input type="text" id="kf-frame-value" class="admin-input" placeholder="CSS properties (e.g., opacity: 0; transform: scale(0.5);)">
                    </div>
                    <button type="button" id="kf-add-frame-btn" class="admin-btn admin-btn--secondary">
                        Add Frame
                    </button>
                </div>
            </div>
            <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                <button type="button" id="kf-apply-name-btn" class="admin-btn admin-btn--outline admin-btn--small">
                    Apply Name to Form
                </button>
                <button type="button" id="kf-clear-btn" class="admin-btn admin-btn--outline admin-btn--small">
                    Clear Frames
                </button>
                <button type="button" id="kf-load-existing-btn" class="admin-btn admin-btn--outline admin-btn--small">
                    Load Existing Frames
                </button>
            </div>
            <p class="admin-hint">Build your keyframe animation using the form above. The frames will be added to the JSON below.</p>
        `;
        nameInput.parentNode.parentNode.insertBefore(helperDiv, nameInput.parentNode);
        
        const kfNameSelector = document.getElementById('kf-name-selector');
        const kfNameInput = document.getElementById('kf-name-input');
        const kfCurrentFrames = document.getElementById('kf-current-frames');
        const kfFrameKey = document.getElementById('kf-frame-key');
        const kfFrameKeyCustom = document.getElementById('kf-frame-key-custom');
        const kfFrameValue = document.getElementById('kf-frame-value');
        const kfAddFrameBtn = document.getElementById('kf-add-frame-btn');
        const kfApplyNameBtn = document.getElementById('kf-apply-name-btn');
        const kfClearBtn = document.getElementById('kf-clear-btn');
        const kfLoadExistingBtn = document.getElementById('kf-load-existing-btn');
        
        // Load existing keyframes
        async function loadKeyframes() {
            try {
                const keyframes = await QuickSiteAdmin.fetchHelperData('keyframes', []);
                kfNameSelector.innerHTML = '<option value="">Select existing or type new...</option>';
                existingKeyframes = {};
                
                keyframes.forEach(kf => {
                    const option = document.createElement('option');
                    option.value = kf.value;
                    option.textContent = kf.label;
                    kfNameSelector.appendChild(option);
                    existingKeyframes[kf.value] = kf.frames;
                });
            } catch (error) {
                console.error('Failed to load keyframes:', error);
            }
        }
        
        await loadKeyframes();
        
        // Show/hide custom key input
        kfFrameKey.addEventListener('change', () => {
            kfFrameKeyCustom.style.display = kfFrameKey.value === 'custom' ? 'block' : 'none';
        });
        
        // When selecting existing keyframe, show its frames
        kfNameSelector.addEventListener('change', () => {
            const name = kfNameSelector.value;
            if (name && existingKeyframes[name]) {
                kfNameInput.value = '';
                const frames = existingKeyframes[name];
                let html = '';
                for (const [key, value] of Object.entries(frames)) {
                    html += `<strong>${key}:</strong> ${value}<br>`;
                }
                kfCurrentFrames.innerHTML = html || 'No frames';
            } else {
                kfCurrentFrames.innerHTML = 'Select an animation to see its frames';
            }
        });
        
        // Clear selector when typing new name
        kfNameInput.addEventListener('input', () => {
            if (kfNameInput.value) {
                kfNameSelector.selectedIndex = 0;
                kfCurrentFrames.innerHTML = '(New animation)';
            }
        });
        
        // Add frame button
        kfAddFrameBtn.addEventListener('click', () => {
            let frameKey = kfFrameKey.value;
            if (frameKey === 'custom') {
                frameKey = kfFrameKeyCustom.value.trim();
            }
            const frameValue = kfFrameValue.value.trim();
            
            if (!frameKey) {
                QuickSiteAdmin.showToast('Please select or enter a frame key', 'warning');
                return;
            }
            if (!frameValue) {
                QuickSiteAdmin.showToast('Please enter CSS properties', 'warning');
                return;
            }
            
            // Parse current frames
            let frames = {};
            try {
                frames = JSON.parse(framesTextarea.value || '{}');
            } catch {
                frames = {};
            }
            
            // Add frame
            frames[frameKey] = frameValue;
            
            // Update textarea
            framesTextarea.value = JSON.stringify(frames, null, 2);
            
            // Clear value input
            kfFrameValue.value = '';
            
            QuickSiteAdmin.showToast(`Added frame: ${frameKey}`, 'success');
        });
        
        // Apply name button
        kfApplyNameBtn.addEventListener('click', () => {
            const name = kfNameInput.value || kfNameSelector.value;
            if (name) {
                nameInput.value = name;
                QuickSiteAdmin.showToast(`Name set: ${name}`, 'success');
            } else {
                QuickSiteAdmin.showToast('Please select or enter an animation name', 'warning');
            }
        });
        
        // Clear frames button
        kfClearBtn.addEventListener('click', () => {
            framesTextarea.value = '{}';
            QuickSiteAdmin.showToast('Frames cleared', 'success');
        });
        
        // Load existing frames into textarea
        kfLoadExistingBtn.addEventListener('click', () => {
            const name = kfNameSelector.value;
            if (name && existingKeyframes[name]) {
                framesTextarea.value = JSON.stringify(existingKeyframes[name], null, 2);
                nameInput.value = name;
                QuickSiteAdmin.showToast(`Loaded frames for: ${name}`, 'success');
            } else {
                QuickSiteAdmin.showToast('Please select an existing animation first', 'warning');
            }
        });
        
        // Refresh after successful command
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'setKeyframes') {
                await loadKeyframes();
                framesTextarea.value = '{}';
                nameInput.value = '';
                kfCurrentFrames.innerHTML = 'Select an animation to see its frames';
            }
        });
    }
}

/**
 * Initialize route selection for deleteRoute
 */
async function initRouteSelectForm() {
    const form = document.getElementById('command-form');
    const routeInput = form.querySelector('[name="route"]');
    
    if (routeInput && routeInput.tagName !== 'SELECT') {
        // Convert to select
        const routeSelect = document.createElement('select');
        routeSelect.name = 'route';
        routeSelect.className = 'admin-select';
        routeSelect.required = routeInput.required;
        
        if (routeInput.dataset.urlParam !== undefined) {
            routeSelect.dataset.urlParam = '';
        }
        
        routeInput.replaceWith(routeSelect);
        
        await QuickSiteAdmin.populateSelect(routeSelect, 'routes', [], 'Select route...');
    }
}

/**
 * Initialize language selection for removeLang, getTranslation, etc.
 */
async function initLanguageSelectForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="lang"]');
    
    if (langInput && langInput.tagName !== 'SELECT') {
        // Convert to select
        const langSelect = document.createElement('select');
        langSelect.name = 'lang';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        
        if (langInput.dataset.urlParam !== undefined) {
            langSelect.dataset.urlParam = '';
        }
        
        langInput.replaceWith(langSelect);
        
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], 'Select language...');
    }
}

/**
 * Initialize createAlias form with type and target selects
 */
async function initCreateAliasForm() {
    const form = document.getElementById('command-form');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        
        if (typeInput.dataset.urlParam !== undefined) {
            typeSelect.dataset.urlParam = '';
        }
        
        typeInput.replaceWith(typeSelect);
        
        await QuickSiteAdmin.populateSelect(typeSelect, 'alias-types', [], 'Select alias type...');
    }
    
    // Convert target input to select with routes
    const targetInput = form.querySelector('[name="target"]');
    if (targetInput && targetInput.tagName !== 'SELECT') {
        const targetSelect = document.createElement('select');
        targetSelect.name = 'target';
        targetSelect.className = 'admin-select';
        targetSelect.required = targetInput.required;
        
        if (targetInput.dataset.urlParam !== undefined) {
            targetSelect.dataset.urlParam = '';
        }
        
        targetInput.replaceWith(targetSelect);
        
        // Fetch routes and format with leading slash
        const routesData = await QuickSiteAdmin.fetchHelperData('routes', []);
        targetSelect.innerHTML = '<option value="">Select target route...</option>';
        routesData.forEach(route => {
            const option = document.createElement('option');
            option.value = '/' + route.value; // Add leading slash for target
            option.textContent = '/' + route.label;
            targetSelect.appendChild(option);
        });
    }
}

/**
 * Initialize deleteAlias form with alias select
 */
async function initDeleteAliasForm() {
    const form = document.getElementById('command-form');
    const aliasInput = form.querySelector('[name="alias"]');
    
    if (aliasInput && aliasInput.tagName !== 'SELECT') {
        const aliasSelect = document.createElement('select');
        aliasSelect.name = 'alias';
        aliasSelect.className = 'admin-select';
        aliasSelect.required = aliasInput.required;
        
        if (aliasInput.dataset.urlParam !== undefined) {
            aliasSelect.dataset.urlParam = '';
        }
        
        aliasInput.replaceWith(aliasSelect);
        
        await QuickSiteAdmin.populateSelect(aliasSelect, 'aliases', [], 'Select alias to delete...');
        
        // Listen for successful deletion to refresh the select
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteAlias') {
                // Refresh the select after successful deletion
                await QuickSiteAdmin.populateSelect(aliasSelect, 'aliases', [], 'Select alias to delete...');
                // Reset to default option
                aliasSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize setTranslationKeys form with language select and key selector helper
 */
async function initSetTranslationKeysForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="language"]');
    const translationsTextarea = form.querySelector('[name="translations"]');
    
    // Convert language to select if needed
    if (langInput && langInput.tagName !== 'SELECT') {
        const langSelect = document.createElement('select');
        langSelect.name = 'language';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        
        if (langInput.dataset.urlParam !== undefined) {
            langSelect.dataset.urlParam = '';
        }
        
        langInput.replaceWith(langSelect);
        
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], 'Select language...');
        
        // Add key selector helper above the translations textarea
        if (translationsTextarea) {
            const helperDiv = document.createElement('div');
            helperDiv.className = 'admin-key-selector';
            helperDiv.innerHTML = `
                <label class="admin-label">Translation Key Selector</label>
                <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                    <select id="key-selector" class="admin-select" style="flex: 1;" size="8">
                        <option value="" disabled>Select language first...</option>
                    </select>
                    <div style="display: flex; flex-direction: column; gap: var(--space-xs);">
                        <input type="text" id="value-input" class="admin-input" placeholder="Translation value..." style="width: 200px;">
                        <p class="admin-hint" style="margin: 0;">Current: <span id="current-value" style="font-style: italic;">-</span></p>
                        <button type="button" id="add-translation-btn" class="admin-btn admin-btn--secondary">
                            Add Translation →
                        </button>
                        <button type="button" id="clear-translations-btn" class="admin-btn admin-btn--outline" title="Clear all translations">
                            Clear All
                        </button>
                    </div>
                </div>
                <p class="admin-hint">🟢 Used keys are active in structure. 🟡 Unused keys exist but aren't used. 🔴 Unset keys need translation.</p>
            `;
            translationsTextarea.parentNode.insertBefore(helperDiv, translationsTextarea);
            
            const keySelector = document.getElementById('key-selector');
            const valueInput = document.getElementById('value-input');
            const currentValueSpan = document.getElementById('current-value');
            const addBtn = document.getElementById('add-translation-btn');
            const clearBtn = document.getElementById('clear-translations-btn');
            
            // Store current translations data for showing current values
            let currentTranslations = {};
            
            // Load keys when language changes
            langSelect.addEventListener('change', async () => {
                const lang = langSelect.value;
                if (lang) {
                    try {
                        // Fetch grouped keys (used/unused/unset)
                        const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                        keySelector.innerHTML = '';
                        
                        // Add unset keys first (most important - needs translation)
                        if (data.unset && data.unset.length > 0) {
                            const unsetGroup = document.createElement('optgroup');
                            unsetGroup.label = `🔴 Unset Keys (${data.unset.length}) - Need Translation`;
                            data.unset.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                option.dataset.isUnset = 'true';
                                unsetGroup.appendChild(option);
                            });
                            keySelector.appendChild(unsetGroup);
                        }
                        
                        // Add used keys
                        if (data.used && data.used.length > 0) {
                            const usedGroup = document.createElement('optgroup');
                            usedGroup.label = `🟢 Used Keys (${data.used.length})`;
                            data.used.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                usedGroup.appendChild(option);
                            });
                            keySelector.appendChild(usedGroup);
                        }
                        
                        // Add unused keys
                        if (data.unused && data.unused.length > 0) {
                            const unusedGroup = document.createElement('optgroup');
                            unusedGroup.label = `🟡 Unused Keys (${data.unused.length})`;
                            data.unused.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                unusedGroup.appendChild(option);
                            });
                            keySelector.appendChild(unusedGroup);
                        }
                        
                        // Also fetch full translations for current value display
                        const fullData = await QuickSiteAdmin.fetchHelperData('translation-full', [lang]);
                        currentTranslations = fullData || {};
                    } catch (error) {
                        keySelector.innerHTML = '<option value="" disabled>Error loading keys</option>';
                    }
                } else {
                    keySelector.innerHTML = '<option value="" disabled>Select language first...</option>';
                    currentTranslations = {};
                }
                currentValueSpan.textContent = '-';
            });
            
            // Show current value when key is selected
            keySelector.addEventListener('change', () => {
                const key = keySelector.value;
                if (key) {
                    const isUnset = keySelector.selectedOptions[0]?.dataset.isUnset === 'true';
                    if (isUnset) {
                        currentValueSpan.textContent = '(not set)';
                    } else {
                        const value = getNestedValue(currentTranslations, key);
                        currentValueSpan.textContent = value !== undefined ? `"${value}"` : '(not set)';
                    }
                } else {
                    currentValueSpan.textContent = '-';
                }
            });
            
            // Add translation button
            addBtn.addEventListener('click', () => {
                const key = keySelector.value;
                const value = valueInput.value;
                
                if (!key) {
                    QuickSiteAdmin.showToast('Please select a key', 'warning');
                    return;
                }
                if (!value) {
                    QuickSiteAdmin.showToast('Please enter a value', 'warning');
                    return;
                }
                
                // Parse current translations object
                let translations = {};
                try {
                    translations = JSON.parse(translationsTextarea.value || '{}');
                } catch {
                    translations = {};
                }
                
                // Convert dot notation to nested object and merge
                setNestedValue(translations, key, value);
                
                // Update textarea
                translationsTextarea.value = JSON.stringify(translations, null, 2);
                
                // Clear inputs
                valueInput.value = '';
                currentValueSpan.textContent = '-';
                
                QuickSiteAdmin.showToast(`Added: ${key}`, 'success');
            });
            
            // Clear button
            clearBtn.addEventListener('click', () => {
                translationsTextarea.value = '{}';
            });
            
            // Helper function to refresh key selector
            async function refreshKeySelector() {
                const lang = langSelect.value;
                if (!lang) return;
                
                try {
                    const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                    keySelector.innerHTML = '';
                    
                    // Add unset keys first
                    if (data.unset && data.unset.length > 0) {
                        const unsetGroup = document.createElement('optgroup');
                        unsetGroup.label = `🔴 Unset Keys (${data.unset.length}) - Need Translation`;
                        data.unset.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            option.dataset.isUnset = 'true';
                            unsetGroup.appendChild(option);
                        });
                        keySelector.appendChild(unsetGroup);
                    }
                    
                    // Add used keys
                    if (data.used && data.used.length > 0) {
                        const usedGroup = document.createElement('optgroup');
                        usedGroup.label = `🟢 Used Keys (${data.used.length})`;
                        data.used.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            usedGroup.appendChild(option);
                        });
                        keySelector.appendChild(usedGroup);
                    }
                    
                    // Add unused keys
                    if (data.unused && data.unused.length > 0) {
                        const unusedGroup = document.createElement('optgroup');
                        unusedGroup.label = `🟡 Unused Keys (${data.unused.length})`;
                        data.unused.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            unusedGroup.appendChild(option);
                        });
                        keySelector.appendChild(unusedGroup);
                    }
                    
                    // Refresh full translations too
                    const fullData = await QuickSiteAdmin.fetchHelperData('translation-full', [lang]);
                    currentTranslations = fullData || {};
                } catch (error) {
                    console.error('Error refreshing keys:', error);
                }
            }
            
            // Refresh selector after successful command
            form.addEventListener('command-success', async (e) => {
                if (e.detail.command === 'setTranslationKeys') {
                    await refreshKeySelector();
                    // Clear the textarea for next input
                    translationsTextarea.value = '{}';
                    currentValueSpan.textContent = '-';
                }
            });
        }
    }
}

/**
 * Get nested value from object using dot notation
 */
function getNestedValue(obj, path) {
    return path.split('.').reduce((current, key) => 
        current && current[key] !== undefined ? current[key] : undefined, obj);
}

/**
 * Set nested value in object using dot notation
 */
function setNestedValue(obj, path, value) {
    const keys = path.split('.');
    let current = obj;
    
    for (let i = 0; i < keys.length - 1; i++) {
        const key = keys[i];
        if (!(key in current) || typeof current[key] !== 'object') {
            current[key] = {};
        }
        current = current[key];
    }
    
    current[keys[keys.length - 1]] = value;
}

/**
 * Initialize deleteTranslationKeys form with key selector helper
 */
async function initDeleteTranslationKeysForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="language"]');
    const keysTextarea = form.querySelector('[name="keys"]');
    
    // Convert language to select if needed
    if (langInput && langInput.tagName !== 'SELECT') {
        const langSelect = document.createElement('select');
        langSelect.name = 'language';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        
        if (langInput.dataset.urlParam !== undefined) {
            langSelect.dataset.urlParam = '';
        }
        
        langInput.replaceWith(langSelect);
        
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], 'Select language...');
        
        // Add key selector helper above the keys textarea
        if (keysTextarea) {
            const helperDiv = document.createElement('div');
            helperDiv.className = 'admin-key-selector';
            helperDiv.innerHTML = `
                <label class="admin-label">Key Selector Helper</label>
                <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                    <select id="key-selector" class="admin-select" style="flex: 1;" multiple size="8">
                        <option value="" disabled>Select language first...</option>
                    </select>
                    <div style="display: flex; flex-direction: column; gap: var(--space-xs);">
                        <button type="button" id="add-selected-btn" class="admin-btn admin-btn--secondary">
                            Add Selected →
                        </button>
                        <button type="button" id="add-all-unused-btn" class="admin-btn admin-btn--outline" title="Add all unused keys">
                            Add All Unused
                        </button>
                        <button type="button" id="clear-keys-btn" class="admin-btn admin-btn--outline" title="Clear deletion list">
                            Clear List
                        </button>
                    </div>
                </div>
                <p class="admin-hint">Hold Ctrl/Cmd to select multiple keys. Unused keys are safe to delete.</p>
            `;
            keysTextarea.parentNode.insertBefore(helperDiv, keysTextarea);
            
            const keySelector = document.getElementById('key-selector');
            const addSelectedBtn = document.getElementById('add-selected-btn');
            const addAllUnusedBtn = document.getElementById('add-all-unused-btn');
            const clearKeysBtn = document.getElementById('clear-keys-btn');
            
            // Store unused keys for bulk add
            let unusedKeys = [];
            
            // Load keys when language changes
            langSelect.addEventListener('change', async () => {
                const lang = langSelect.value;
                if (lang) {
                    // Fetch grouped keys (used/unused)
                    keySelector.innerHTML = '<option value="" disabled>Loading...</option>';
                    try {
                        const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                        keySelector.innerHTML = '';
                        
                        // Store unused keys for bulk add
                        unusedKeys = (data.unused || []).map(k => k.value);
                        
                        // Add Used keys optgroup
                        if (data.used && data.used.length > 0) {
                            const usedGroup = document.createElement('optgroup');
                            usedGroup.label = `✓ Used Keys (${data.used.length})`;
                            data.used.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                usedGroup.appendChild(option);
                            });
                            keySelector.appendChild(usedGroup);
                        }
                        
                        // Add Unused keys optgroup
                        if (data.unused && data.unused.length > 0) {
                            const unusedGroup = document.createElement('optgroup');
                            unusedGroup.label = `⚠ Unused Keys (${data.unused.length})`;
                            data.unused.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                unusedGroup.appendChild(option);
                            });
                            keySelector.appendChild(unusedGroup);
                        }
                        
                        if (!data.used?.length && !data.unused?.length) {
                            keySelector.innerHTML = '<option value="" disabled>No keys found</option>';
                        }
                    } catch (error) {
                        keySelector.innerHTML = '<option value="" disabled>Error loading keys</option>';
                    }
                } else {
                    keySelector.innerHTML = '<option value="" disabled>Select language first...</option>';
                    unusedKeys = [];
                }
            });
            
            // Add selected keys button handler
            addSelectedBtn.addEventListener('click', () => {
                const selectedOptions = Array.from(keySelector.selectedOptions);
                if (selectedOptions.length === 0) return;
                
                // Parse current keys
                let currentKeys = [];
                try {
                    currentKeys = JSON.parse(keysTextarea.value || '[]');
                } catch {
                    currentKeys = [];
                }
                
                // Add selected keys
                selectedOptions.forEach(opt => {
                    if (opt.value && !currentKeys.includes(opt.value)) {
                        currentKeys.push(opt.value);
                    }
                });
                
                keysTextarea.value = JSON.stringify(currentKeys, null, 2);
            });
            
            // Add all unused keys button handler
            addAllUnusedBtn.addEventListener('click', () => {
                if (unusedKeys.length === 0) {
                    QuickSiteAdmin.showToast('No unused keys to add', 'warning');
                    return;
                }
                
                // Parse current keys
                let currentKeys = [];
                try {
                    currentKeys = JSON.parse(keysTextarea.value || '[]');
                } catch {
                    currentKeys = [];
                }
                
                // Add all unused keys
                unusedKeys.forEach(key => {
                    if (!currentKeys.includes(key)) {
                        currentKeys.push(key);
                    }
                });
                
                keysTextarea.value = JSON.stringify(currentKeys, null, 2);
                QuickSiteAdmin.showToast(`Added ${unusedKeys.length} unused keys`, 'success');
            });
            
            // Clear keys button handler
            clearKeysBtn.addEventListener('click', () => {
                keysTextarea.value = '[]';
            });
            
            // Helper function to refresh key selector
            async function refreshKeySelector() {
                const lang = langSelect.value;
                if (!lang) return;
                
                keySelector.innerHTML = '<option value="" disabled>Refreshing...</option>';
                try {
                    const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                    keySelector.innerHTML = '';
                    
                    // Store unused keys for bulk add
                    unusedKeys = (data.unused || []).map(k => k.value);
                    
                    // Add Used keys optgroup
                    if (data.used && data.used.length > 0) {
                        const usedGroup = document.createElement('optgroup');
                        usedGroup.label = `✓ Used Keys (${data.used.length})`;
                        data.used.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            usedGroup.appendChild(option);
                        });
                        keySelector.appendChild(usedGroup);
                    }
                    
                    // Add Unused keys optgroup
                    if (data.unused && data.unused.length > 0) {
                        const unusedGroup = document.createElement('optgroup');
                        unusedGroup.label = `⚠ Unused Keys (${data.unused.length})`;
                        data.unused.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            unusedGroup.appendChild(option);
                        });
                        keySelector.appendChild(unusedGroup);
                    }
                    
                    if (!data.used?.length && !data.unused?.length) {
                        keySelector.innerHTML = '<option value="" disabled>No keys found</option>';
                    }
                } catch (error) {
                    keySelector.innerHTML = '<option value="" disabled>Error refreshing keys</option>';
                }
            }
            
            // Refresh selector after successful deletion
            form.addEventListener('command-success', async (e) => {
                if (e.detail.command === 'deleteTranslationKeys') {
                    await refreshKeySelector();
                    // Clear the textarea
                    keysTextarea.value = '[]';
                }
            });
        }
    }
}

/**
 * Initialize file upload forms with drag-and-drop
 */
function initFileUploadForm() {
    const form = document.getElementById('command-form');
    const fileInputs = form.querySelectorAll('input[type="file"]');
    
    fileInputs.forEach(input => {
        // Wrap in styled container
        const wrapper = document.createElement('div');
        wrapper.className = 'admin-file-input';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        
        // Add label
        const label = document.createElement('div');
        label.className = 'admin-file-input__label';
        label.innerHTML = `
            <svg class="admin-file-input__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <div class="admin-file-input__text">
                <span>Click to select a file or drag and drop</span>
                <span class="admin-file-input__hint">Max file size: 10MB</span>
            </div>
        `;
        wrapper.appendChild(label);
        
        // Preview container
        const preview = document.createElement('div');
        preview.className = 'admin-file-input__preview';
        preview.style.display = 'none';
        wrapper.appendChild(preview);
        
        // Handle file selection
        input.addEventListener('change', () => {
            const file = input.files[0];
            if (file) {
                preview.textContent = `Selected: ${file.name} (${formatFileSize(file.size)})`;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });
        
        // Drag and drop handling
        wrapper.addEventListener('dragover', (e) => {
            e.preventDefault();
            wrapper.classList.add('admin-file-input--dragover');
        });
        
        wrapper.addEventListener('dragleave', () => {
            wrapper.classList.remove('admin-file-input--dragover');
        });
        
        wrapper.addEventListener('drop', (e) => {
            e.preventDefault();
            wrapper.classList.remove('admin-file-input--dragover');
            
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });
}

/**
 * Format file size for display
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function renderCommandForm(doc) {
    const form = document.getElementById('command-form');
    const paramsContainer = document.getElementById('command-params');
    const descriptionEl = document.getElementById('command-description');
    const methodBadge = document.getElementById('method-badge');
    
    // Set description
    descriptionEl.textContent = doc.description || '';
    
    // Set method badge
    const method = doc.method || 'GET';
    methodBadge.textContent = method;
    methodBadge.className = 'badge badge--' + method.toLowerCase();
    form.dataset.method = method;
    
    // Generate form fields
    const params = doc.parameters || {};
    const paramKeys = Object.keys(params);
    
    if (paramKeys.length === 0) {
        paramsContainer.innerHTML = `
            <div class="admin-empty" style="padding: var(--space-md);">
                <p>${translations.noParameters}</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    // Separate required and optional
    const required = paramKeys.filter(k => params[k].required);
    const optional = paramKeys.filter(k => !params[k].required);
    
    if (required.length > 0) {
        html += `<h4 class="admin-form-section-title">${translations.requiredParams}</h4>`;
        required.forEach(key => {
            html += renderFormField(key, params[key], true);
        });
    }
    
    if (optional.length > 0) {
        html += `<h4 class="admin-form-section-title" style="margin-top: var(--space-lg);">${translations.optionalParams}</h4>`;
        optional.forEach(key => {
            html += renderFormField(key, params[key], false);
        });
    }
    
    paramsContainer.innerHTML = html;
}

function renderFormField(rawName, param, required) {
    const type = param.type || 'string';
    const uiType = param.ui_type || null; // Custom UI type for special inputs
    const description = param.description || '';
    const example = param.example || '';
    const validation = param.validation || '';
    
    // Detect URL parameters from curly braces in name (e.g., {lang}, {type}, {name?})
    const isUrlParam = rawName.startsWith('{') && rawName.endsWith('}');
    // Strip {} and optional ? marker: {name?} -> name
    let name = rawName;
    if (isUrlParam) {
        name = rawName.slice(1, -1); // Remove { and }
        if (name.endsWith('?')) {
            name = name.slice(0, -1); // Remove trailing ?
        }
    }
    const displayName = isUrlParam ? name + ' (URL segment)' : name;
    
    let inputHtml = '';
    const inputId = 'param-' + name;
    const urlParamAttr = isUrlParam ? 'data-url-param' : '';
    
    // Handle special UI types first
    if (uiType === 'datetime' || uiType === 'date') {
        const includeTime = (uiType === 'datetime');
        const today = new Date().toISOString().split('T')[0];
        const defaultExample = example || today;
        
        inputHtml = `
            <div class="admin-datetime-picker" data-target="${inputId}">
                <div class="admin-datetime-picker__selectors">
                    <div class="admin-form-group admin-form-group--inline">
                        <label class="admin-label admin-label--small">Date</label>
                        <input type="date" id="${inputId}-date" class="admin-input admin-input--date" 
                            data-datetime-date="${inputId}" value="${today}">
                    </div>
                    ${includeTime ? `
                    <div class="admin-form-group admin-form-group--inline">
                        <label class="admin-label admin-label--small">Hour</label>
                        <select id="${inputId}-hour" class="admin-select admin-select--small" data-datetime-hour="${inputId}">
                            ${Array.from({length: 24}, (_, i) => `<option value="${String(i).padStart(2, '0')}" ${i === 0 ? 'selected' : ''}>${String(i).padStart(2, '0')}</option>`).join('')}
                        </select>
                    </div>
                    <div class="admin-form-group admin-form-group--inline">
                        <label class="admin-label admin-label--small">Min</label>
                        <select id="${inputId}-min" class="admin-select admin-select--small" data-datetime-min="${inputId}">
                            ${Array.from({length: 60}, (_, i) => `<option value="${String(i).padStart(2, '0')}" ${i === 0 ? 'selected' : ''}>${String(i).padStart(2, '0')}</option>`).join('')}
                        </select>
                    </div>
                    ` : ''}
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="applyDateTimeToField('${inputId}', ${includeTime})">
                        Apply
                    </button>
                </div>
                <div class="admin-form-group" style="margin-top: var(--space-sm);">
                    <label class="admin-label admin-label--small">Or enter manually:</label>
                    <input type="text" name="${name}" id="${inputId}" class="admin-input" 
                        placeholder="${QuickSiteAdmin.escapeHtml(defaultExample)}" 
                        ${required ? 'required' : ''} ${urlParamAttr}>
                </div>
            </div>
        `;
        
        return `
            <div class="admin-form-group">
                <label class="admin-label ${required ? 'admin-label--required' : ''}" for="${inputId}">
                    ${displayName}
                    <span class="admin-label__type">(${type})</span>
                </label>
                ${inputHtml}
                <p class="admin-hint">${QuickSiteAdmin.escapeHtml(description)}</p>
                ${validation ? `<p class="admin-hint"><strong>Validation:</strong> ${QuickSiteAdmin.escapeHtml(validation)}</p>` : ''}
            </div>
        `;
    }
    
    switch (type) {
        case 'boolean':
            inputHtml = `
                <select name="${name}" id="${inputId}" class="admin-select" ${urlParamAttr}>
                    <option value="">-- Select --</option>
                    <option value="true">true</option>
                    <option value="false">false</option>
                </select>
            `;
            break;
            
        case 'file':
            inputHtml = `
                <input type="file" name="${name}" id="${inputId}" class="admin-input" ${urlParamAttr}>
            `;
            break;
            
        case 'array':
        case 'object':
            const placeholderValue = example ? JSON.stringify(example, null, 2) : (type === 'array' ? '[]' : '{}');
            inputHtml = `
                <textarea name="${name}" id="${inputId}" class="admin-textarea" data-json-editor
                    placeholder='${QuickSiteAdmin.escapeHtml(placeholderValue)}' ${urlParamAttr}>${type === 'array' ? '[]' : '{}'}</textarea>
            `;
            break;
            
        default:
            // Check for select options in validation
            if (validation && validation.includes('|')) {
                const options = validation.split(',')[0]?.split('|').map(o => o.trim()) || [];
                if (options.length > 1) {
                    inputHtml = `<select name="${name}" id="${inputId}" class="admin-select" ${required ? 'required' : ''} ${urlParamAttr}>`;
                    inputHtml += `<option value="">-- Select --</option>`;
                    options.forEach(opt => {
                        inputHtml += `<option value="${QuickSiteAdmin.escapeHtml(opt)}">${QuickSiteAdmin.escapeHtml(opt)}</option>`;
                    });
                    inputHtml += `</select>`;
                    break;
                }
            }
            
            // Fields that should use textarea for better visibility
            const textareaFields = ['structure', 'content', 'data', 'json', 'value', 'keys', 'translations', 'variables', 'properties', 'rule', 'keyframes'];
            const nameLower = name.toLowerCase();
            const needsTextarea = textareaFields.some(f => nameLower.includes(f)) || 
                                  (example && typeof example === 'string' && (example.includes('{') || example.includes('[') || example.length > 50));
            
            // Fields that should have JSON editor
            const jsonEditorFields = ['structure', 'translations', 'keys', 'data', 'json', 'properties', 'variables'];
            const needsJsonEditor = jsonEditorFields.some(f => nameLower.includes(f));
            
            if (needsTextarea) {
                inputHtml = `
                    <textarea name="${name}" id="${inputId}" class="admin-textarea" rows="6"
                        placeholder="${QuickSiteAdmin.escapeHtml(example || '')}" 
                        ${required ? 'required' : ''} ${urlParamAttr} ${needsJsonEditor ? 'data-json-editor' : ''}></textarea>
                `;
                break;
            }
            
            // Default text input
            inputHtml = `
                <input type="text" name="${name}" id="${inputId}" class="admin-input" 
                    placeholder="${QuickSiteAdmin.escapeHtml(example || '')}" 
                    ${required ? 'required' : ''} ${urlParamAttr}>
            `;
    }
    
    return `
        <div class="admin-form-group">
            <label class="admin-label ${required ? 'admin-label--required' : ''}" for="${inputId}">
                ${displayName}
                <span class="admin-label__type">(${type})</span>
            </label>
            ${inputHtml}
            <p class="admin-hint">${QuickSiteAdmin.escapeHtml(description)}</p>
            ${validation ? `<p class="admin-hint"><strong>Validation:</strong> ${QuickSiteAdmin.escapeHtml(validation)}</p>` : ''}
        </div>
    `;
}

function renderCommandDocs(doc) {
    const container = document.getElementById('command-docs');
    
    let html = '';
    
    // Notes
    if (doc.notes) {
        html += `
            <div class="admin-doc-section">
                <h4>${translations.notes}</h4>
                <p>${QuickSiteAdmin.escapeHtml(doc.notes)}</p>
            </div>
        `;
    }
    
    // Example
    if (doc.example_get || doc.example_post) {
        html += `
            <div class="admin-doc-section">
                <h4>${translations.example}</h4>
                <div class="admin-code">
                    <pre>${QuickSiteAdmin.escapeHtml(doc.example_get || doc.example_post)}</pre>
                </div>
            </div>
        `;
    }
    
    // Success Response
    if (doc.success_response) {
        html += `
            <div class="admin-doc-section">
                <h4>${translations.successResponse}</h4>
                <div class="admin-code">
                    <pre>${QuickSiteAdmin.escapeHtml(JSON.stringify(doc.success_response, null, 2))}</pre>
                </div>
            </div>
        `;
    }
    
    // Error Responses
    if (doc.error_responses && Object.keys(doc.error_responses).length > 0) {
        html += `
            <div class="admin-doc-section">
                <h4>${translations.errorResponses}</h4>
                <ul class="admin-error-list">
        `;
        Object.entries(doc.error_responses).forEach(([code, message]) => {
            html += `<li><code>${QuickSiteAdmin.escapeHtml(code)}</code>: ${QuickSiteAdmin.escapeHtml(message)}</li>`;
        });
        html += '</ul></div>';
    }
    
    container.innerHTML = html || '<p>No additional documentation available.</p>';
}

/**
 * Apply date/time picker values to the target input field
 * @param {string} fieldId - The ID of the target input field
 * @param {boolean} includeTime - Whether to include time in the value
 */
function applyDateTimeToField(fieldId, includeTime = true) {
    const dateInput = document.getElementById(fieldId + '-date');
    const targetInput = document.getElementById(fieldId);
    
    if (!dateInput || !targetInput) return;
    
    let value = dateInput.value;
    
    if (!value) {
        // Default to today
        value = new Date().toISOString().split('T')[0];
    }
    
    if (includeTime) {
        const hourSelect = document.getElementById(fieldId + '-hour');
        const minSelect = document.getElementById(fieldId + '-min');
        
        const hour = hourSelect ? hourSelect.value : '00';
        const min = minSelect ? minSelect.value : '00';
        
        value = `${value}T${hour}:${min}:00`;
    }
    
    targetInput.value = value;
    targetInput.focus();
    
    // Trigger change event for any listeners
    targetInput.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Initialize datetime pickers - set up auto-apply on change (optional)
 */
function initDateTimePickers() {
    document.querySelectorAll('.admin-datetime-picker').forEach(picker => {
        const targetId = picker.dataset.target;
        const dateInput = picker.querySelector('[data-datetime-date]');
        
        // Optional: auto-apply when date changes
        if (dateInput) {
            dateInput.addEventListener('change', () => {
                // Only auto-apply if user has interacted with date picker
                const targetInput = document.getElementById(targetId);
                if (targetInput && !targetInput.value) {
                    // Don't auto-apply if user hasn't clicked Apply yet
                }
            });
        }
    });
}

// Initialize datetime pickers (init() already ensures DOM is ready)
initDateTimePickers();
    } // end init()
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
