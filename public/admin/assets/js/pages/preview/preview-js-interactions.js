/**
 * Preview JS Interactions Panel Module
 * Handles the JS mode interactions panel for managing element event handlers
 * 
 * Dependencies:
 * - PreviewConfig global (from preview-config.php)
 * 
 * @version 1.0.0
 */
(function() {
    'use strict';

    // ==================== Module State ====================
    
    let currentJsContext = null;
    let availableFunctions = [];
    let availableApiEndpoints = [];
    let currentAvailableEvents = [];
    let editingInteraction = null;
    let currentInteractionsData = null;
    let currentPageName = null;
    let pageEventsExpanded = false;
    
    // DOM element references (cached on init)
    let jsDefault = null;
    let jsInfo = null;
    let jsContent = null;
    let jsAddForm = null;
    let jsFormEvent = null;
    let jsFormActionType = null;
    let jsFormFunctionSection = null;
    let jsFormApiSection = null;
    let jsFormFunction = null;
    let jsFormApi = null;
    let jsFormEndpoint = null;
    let jsFormApiBody = null;
    let jsFormParams = null;
    let jsPreviewCode = null;
    let jsFormSave = null;
    let jsFormCancel = null;
    let jsFormClose = null;
    let jsPanelActions = null;
    let jsAddBtn = null;
    let contextualArea = null;
    
    // Page events DOM references
    let peContainer = null;
    let peBody = null;
    let peList = null;
    let peCount = null;
    let peToggle = null;
    let peAddBtn = null;
    let peForm = null;
    let peFormEvent = null;
    let peFormActionType = null;
    let peFormFunctionSection = null;
    let peFormApiSection = null;
    let peFormFunction = null;
    let peFormApi = null;
    let peFormEndpoint = null;
    let peFormParams = null;
    let peFormPreview = null;
    let peFormSave = null;
    let peFormCancel = null;
    // Page event response bindings refs
    let peBindingsContainer = null;
    let peBindingsRows = null;
    let peBindingsAdd = null;
    // Element-level response bindings refs
    let elBindingsContainer = null;
    let elBindingsRows = null;
    let elBindingsAdd = null;
    
    // Callback references for cross-module communication
    let showToastFn = null;
    let sendToIframeFn = null;
    let reloadPreviewFn = null;
    let getSelectorsLoadedFn = null;
    let loadSelectorsDataFn = null;
    let getCategorizedSelectorsFn = null;
    let getPageStructureClassesFn = null;

    // ==================== Initialization ====================
    
    function init() {
        // Cache DOM references
        jsDefault = document.getElementById('contextual-js-default');
        jsInfo = document.getElementById('contextual-js-info');
        jsContent = document.getElementById('contextual-js-content');
        jsAddForm = document.getElementById('js-add-form');
        jsFormEvent = document.getElementById('js-form-event');
        jsFormActionType = document.getElementById('js-form-action-type');
        jsFormFunctionSection = document.getElementById('js-form-function-section');
        jsFormApiSection = document.getElementById('js-form-api-section');
        jsFormFunction = document.getElementById('js-form-function');
        jsFormApi = document.getElementById('js-form-api');
        jsFormEndpoint = document.getElementById('js-form-endpoint');
        jsFormApiBody = document.getElementById('js-form-api-body');
        jsFormParams = document.getElementById('js-form-params');
        jsPreviewCode = document.getElementById('js-preview-code');
        jsFormSave = document.getElementById('js-form-save');
        jsFormCancel = document.getElementById('js-form-cancel');
        jsFormClose = document.getElementById('js-form-close');
        jsPanelActions = document.getElementById('js-panel-actions');
        jsAddBtn = document.getElementById('js-add-interaction');
        contextualArea = document.getElementById('preview-contextual-area');
        
        // Page events DOM references
        peContainer = document.getElementById('js-page-events');
        peBody = document.getElementById('js-page-events-body');
        peList = document.getElementById('js-page-events-list');
        peCount = document.getElementById('js-page-events-count');
        peToggle = document.getElementById('js-page-events-toggle');
        peAddBtn = document.getElementById('js-page-event-add');
        peForm = document.getElementById('js-page-event-form');
        peFormEvent = document.getElementById('js-page-event-event');
        peFormActionType = document.getElementById('js-page-event-action-type');
        peFormFunctionSection = document.getElementById('js-page-event-function-section');
        peFormApiSection = document.getElementById('js-page-event-api-section');
        peFormFunction = document.getElementById('js-page-event-function');
        peFormApi = document.getElementById('js-page-event-api');
        peFormEndpoint = document.getElementById('js-page-event-endpoint');
        peFormParams = document.getElementById('js-page-event-params');
        peFormPreview = document.getElementById('js-page-event-preview');
        peFormSave = document.getElementById('js-page-event-save');
        peFormCancel = document.getElementById('js-page-event-cancel');
        // Page event response bindings
        peBindingsContainer = document.getElementById('js-page-event-bindings');
        peBindingsRows = document.getElementById('js-page-event-bindings-rows');
        peBindingsAdd = document.getElementById('js-page-event-bindings-add');
        // Element-level response bindings
        elBindingsContainer = document.getElementById('js-form-bindings');
        elBindingsRows = document.getElementById('js-form-bindings-rows');
        elBindingsAdd = document.getElementById('js-form-bindings-add');
        
        // Initialize event handlers
        initEventHandlers();
    }

    // ==================== Event Handlers ====================
    
    function initEventHandlers() {
        // Add interaction button
        if (jsAddBtn) {
            jsAddBtn.addEventListener('click', handleAddClick);
        }
        
        // Cancel/close form buttons
        if (jsFormCancel) {
            jsFormCancel.addEventListener('click', hideAddForm);
        }
        if (jsFormClose) {
            jsFormClose.addEventListener('click', hideAddForm);
        }
        
        // Action type toggle
        if (jsFormActionType) {
            jsFormActionType.addEventListener('change', handleActionTypeChange);
        }
        
        // API dropdown change
        if (jsFormApi) {
            jsFormApi.addEventListener('change', handleApiChange);
        }
        
        // Endpoint dropdown change
        if (jsFormEndpoint) {
            jsFormEndpoint.addEventListener('change', function() {
                updatePreview();
                showBindingsForEndpoint(jsFormEndpoint, elBindingsContainer, elBindingsRows);
            });
        }
        
        // Body input change
        if (jsFormApiBody) {
            jsFormApiBody.addEventListener('input', updatePreview);
        }
        
        // Function dropdown change
        if (jsFormFunction) {
            jsFormFunction.addEventListener('change', handleFunctionChange);
        }
        
        // Event dropdown change
        if (jsFormEvent) {
            jsFormEvent.addEventListener('change', updatePreview);
        }
        
        // Save button
        if (jsFormSave) {
            jsFormSave.addEventListener('click', handleSave);
        }
        
        // ---- Page Events handlers ----
        
        // Toggle expand/collapse
        if (peContainer) {
            var peHeader = peContainer.querySelector('.preview-contextual-js-page-events__header');
            if (peHeader) {
                peHeader.addEventListener('click', togglePageEvents);
            }
        }
        
        // Add page event button
        if (peAddBtn) {
            peAddBtn.addEventListener('click', handlePageEventAdd);
        }
        
        // Cancel page event form
        if (peFormCancel) {
            peFormCancel.addEventListener('click', hidePageEventForm);
        }
        
        // Save page event
        if (peFormSave) {
            peFormSave.addEventListener('click', handlePageEventSave);
        }
        
        // Page event action type toggle
        if (peFormActionType) {
            peFormActionType.addEventListener('change', handlePeActionTypeChange);
        }
        
        // Page event function change
        if (peFormFunction) {
            peFormFunction.addEventListener('change', handlePeFunctionChange);
        }
        
        // Page event API change
        if (peFormApi) {
            peFormApi.addEventListener('change', handlePeApiChange);
        }
        
        // Page event endpoint change
        if (peFormEndpoint) {
            peFormEndpoint.addEventListener('change', function() {
                updatePageEventPreview();
                showBindingsForEndpoint(peFormEndpoint, peBindingsContainer, peBindingsRows);
            });
        }
        
        // Page event event change
        if (peFormEvent) {
            peFormEvent.addEventListener('change', updatePageEventPreview);
        }
        
        // Response binding add buttons
        if (peBindingsAdd) {
            peBindingsAdd.addEventListener('click', function() {
                addBindingRow(peBindingsRows, peFormApi, peFormEndpoint);
            });
        }
        if (elBindingsAdd) {
            elBindingsAdd.addEventListener('click', function() {
                addBindingRow(elBindingsRows, jsFormApi, jsFormEndpoint);
            });
        }
    }

    // ==================== Show/Hide Panel ====================
    
    /**
     * Show JS interactions panel for selected element
     */
    async function show(data) {
        console.log('[PreviewJsInteractions] Show panel:', data);
        
        const element = data.element;
        
        if (!element) {
            console.error('[PreviewJsInteractions] Invalid element data');
            return;
        }
        
        // Store context
        currentJsContext = {
            struct: element.struct,
            nodeId: element.node,
            tag: element.tag || 'unknown'
        };
        
        // Build element display
        let elementDisplay = currentJsContext.tag + ' [' + currentJsContext.nodeId + ']';
        
        // Get fresh DOM references
        const jsElementInfo = document.getElementById('js-element-info');
        const jsDefaultEl = document.getElementById('contextual-js-default');
        const jsInfoEl = document.getElementById('contextual-js-info');
        const jsContentEl = document.getElementById('contextual-js-content');
        const jsPanelActionsEl = document.getElementById('js-panel-actions');
        
        if (jsElementInfo) jsElementInfo.textContent = elementDisplay;
        
        // Show info and content, hide default
        if (jsDefaultEl) jsDefaultEl.style.display = 'none';
        if (jsInfoEl) jsInfoEl.style.display = '';
        if (jsContentEl) jsContentEl.style.display = '';
        if (jsPanelActionsEl) jsPanelActionsEl.style.display = 'flex';
        
        // Expand contextual area if collapsed
        if (contextualArea) {
            contextualArea.classList.remove('preview-contextual-area--collapsed');
        }
        
        // Load selectors data if needed (for searchable picker)
        if (getSelectorsLoadedFn && loadSelectorsDataFn && !getSelectorsLoadedFn()) {
            await loadSelectorsDataFn();
        }
        
        // Request page structure classes from iframe
        if (sendToIframeFn) {
            sendToIframeFn('getPageClasses', {});
        }
        
        // Fetch interactions from API
        await loadInteractions();
    }
    
    /**
     * Hide JS panel and reset to default state
     */
    function hide() {
        currentJsContext = null;
        
        // Reset to default state
        if (jsDefault) jsDefault.style.display = '';
        if (jsInfo) jsInfo.style.display = 'none';
        if (jsContent) jsContent.style.display = 'none';
        
        // Hide the add form if open
        const addForm = document.getElementById('js-add-form');
        const actions = document.getElementById('js-panel-actions');
        if (addForm) addForm.style.display = 'none';
        if (actions) actions.style.display = '';
        
        // Clear selection in iframe
        if (sendToIframeFn) {
            sendToIframeFn('clearJsSelection', {});
        }
    }

    // ==================== Load Interactions ====================
    
    /**
     * Load interactions from API for current context
     */
    async function loadInteractions() {
        if (!currentJsContext) return;
        
        const { struct, nodeId } = currentJsContext;
        
        // Determine structType
        let structType = struct;
        if (struct.startsWith('page-')) {
            structType = 'page';
        } else if (struct === 'menu') {
            structType = 'menu';
        } else if (struct === 'footer') {
            structType = 'footer';
        }
        
        // Extract pageName if struct is a page
        let pageName = null;
        if (struct.startsWith('page-')) {
            pageName = struct.substring(5);
        }
        
        try {
            const url = pageName 
                ? `/management/listInteractions/${structType}/${pageName}/${nodeId}`
                : `/management/listInteractions/${structType}/${nodeId}`;
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${PreviewConfig.authToken}`
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch interactions');
            }
            
            const result = await response.json();
            console.log('[PreviewJsInteractions] Interactions loaded:', result);
            
            // Store available events for the add form
            currentAvailableEvents = result.data?.availableEvents || [];
            
            // Cache interactions data for edit lookups
            currentInteractionsData = result.data;
            
            // Display interactions
            displayInteractions(result.data);
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Failed to load interactions:', error);
            const listEl = document.getElementById('js-interactions-list');
            if (listEl) {
                listEl.innerHTML = '<p class="preview-contextual-js-empty" style="color: var(--danger);">Failed to load interactions</p>';
            }
        }
    }
    
    /**
     * Display interactions in the panel
     */
    function displayInteractions(data) {
        const listEl = document.getElementById('js-interactions-list');
        if (!listEl) return;
        
        if (!data.interactions || data.interactions.length === 0) {
            listEl.innerHTML = `<p class="preview-contextual-js-empty">${PreviewConfig.i18n?.noInteractions || 'No interactions'}</p>`;
            return;
        }
        
        // Group interactions by event
        const byEvent = {};
        data.interactions.forEach((interaction) => {
            const event = interaction.event;
            if (!byEvent[event]) byEvent[event] = [];
            byEvent[event].push(interaction);
        });
        
        // Build interactions list HTML
        let html = '<div class="preview-js-interactions">';
        
        Object.entries(byEvent).forEach(([event, interactions]) => {
            html += `<div class="preview-js-event-group">
                <div class="preview-js-event-header">${event}</div>`;
            
            interactions.forEach((interaction, indexInEvent) => {
                html += `
                    <div class="preview-js-interaction" data-event="${event}" data-index="${indexInEvent}">
                        <div class="preview-js-interaction__body">
                            <div class="preview-js-interaction__function">${interaction.function}(${interaction.params?.join(', ') || ''})</div>
                        </div>
                        <div class="preview-js-interaction__actions">
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--secondary js-edit-btn" data-event="${event}" data-index="${indexInEvent}">
                                ${PreviewConfig.i18n?.edit || 'Edit'}
                            </button>
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--danger js-delete-btn" data-event="${event}" data-index="${indexInEvent}">
                                ${PreviewConfig.i18n?.delete || 'Delete'}
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
        });
        
        html += '</div>';
        listEl.innerHTML = html;
        
        // Attach event listeners to edit/delete buttons
        listEl.querySelectorAll('.js-edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                editInteraction(btn.dataset.event, parseInt(btn.dataset.index));
            });
        });
        
        listEl.querySelectorAll('.js-delete-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                deleteInteraction(btn.dataset.event, parseInt(btn.dataset.index));
            });
        });
    }

    // ==================== Delete Interaction ====================
    
    /**
     * Delete an interaction
     */
    async function deleteInteraction(eventName, index) {
        if (!currentJsContext) return;
        
        const confirmMsg = PreviewConfig.i18n?.confirmDeleteInteraction || 'Delete this interaction?';
        if (!confirm(confirmMsg)) {
            return;
        }
        
        const { struct, nodeId } = currentJsContext;
        
        let structType = struct;
        let pageName = null;
        if (struct.startsWith('page-')) {
            structType = 'page';
            pageName = struct.substring(5);
        }
        
        const body = {
            structType,
            nodeId,
            event: eventName,
            index
        };
        
        if (pageName) {
            body.pageName = pageName;
        }
        
        try {
            const response = await fetch('/management/deleteInteraction', {
                method: 'DELETE',
                headers: {
                    'Authorization': `Bearer ${PreviewConfig.authToken}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body)
            });
            
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || 'Failed to delete interaction');
            }
            
            if (showToastFn) {
                showToastFn(PreviewConfig.i18n?.interactionDeleted || 'Interaction deleted', 'success');
            }
            
            // Refresh list
            await loadInteractions();
            
            // Reload preview
            if (reloadPreviewFn) {
                reloadPreviewFn();
            }
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Delete error:', error);
            if (showToastFn) {
                showToastFn('Error: ' + error.message, 'error');
            }
        }
    }

    // ==================== Edit Interaction ====================
    
    /**
     * Edit an interaction (opens form with pre-filled values)
     */
    async function editInteraction(eventName, index) {
        console.log('[PreviewJsInteractions] Edit interaction:', eventName, index);
        
        if (!currentInteractionsData || !currentJsContext) {
            if (showToastFn) {
                showToastFn(PreviewConfig.i18n?.noInteractionData || 'No interaction data available', 'error');
            }
            return;
        }
        
        // Find the interaction to edit
        const interactions = currentInteractionsData.interactions || [];
        const interactionsForEvent = interactions.filter(i => i.event === eventName);
        
        if (index >= interactionsForEvent.length) {
            if (showToastFn) {
                showToastFn(PreviewConfig.i18n?.interactionNotFound || 'Interaction not found', 'error');
            }
            return;
        }
        
        const interaction = interactionsForEvent[index];
        console.log('[PreviewJsInteractions] Found interaction to edit:', interaction);
        
        // Store edit state
        editingInteraction = {
            event: eventName,
            index: index,
            interaction: interaction
        };
        
        // Show form
        const formHeader = jsAddForm?.querySelector('.preview-contextual-js-form-header strong');
        
        if (jsAddForm) jsAddForm.style.display = 'block';
        if (jsPanelActions) jsPanelActions.style.display = 'none';
        
        // Change header and button text to "Edit"
        if (formHeader) formHeader.textContent = PreviewConfig.i18n?.editInteraction || 'Edit Interaction';
        if (jsFormSave) jsFormSave.textContent = PreviewConfig.i18n?.save || 'Save';
        
        // Populate dropdowns
        populateEventDropdown();
        if (availableFunctions.length === 0) {
            await fetchJsFunctions();
        }
        populateFunctionDropdown();
        
        // Pre-fill event dropdown
        if (jsFormEvent) jsFormEvent.value = interaction.event;
        
        // Pre-fill function dropdown
        if (jsFormFunction) jsFormFunction.value = interaction.function;
        
        // Trigger function change to populate params
        if (jsFormFunction) {
            jsFormFunction.dispatchEvent(new Event('change'));
        }
        
        // Pre-fill params after a short delay
        setTimeout(() => {
            if (jsFormParams) {
                const paramInputs = jsFormParams.querySelectorAll('.preview-contextual-js-form-input');
                const params = interaction.params || [];
                paramInputs.forEach((input, i) => {
                    if (params[i] !== undefined) {
                        input.value = params[i];
                        input.dispatchEvent(new Event('input'));
                    }
                });
            }
            
            updatePreview();
            if (jsFormSave) jsFormSave.disabled = false;
        }, 100);
    }

    // ==================== Add Form Handlers ====================
    
    /**
     * Handle add interaction button click
     */
    async function handleAddClick() {
        console.log('[PreviewJsInteractions] Add interaction clicked');
        
        // Clear edit state
        editingInteraction = null;
        
        // Show form, hide add button
        if (jsAddForm) jsAddForm.style.display = 'block';
        if (jsPanelActions) jsPanelActions.style.display = 'none';
        
        // Reset header and button text
        const formHeader = jsAddForm?.querySelector('.preview-contextual-js-form-header strong');
        if (formHeader) formHeader.textContent = PreviewConfig.i18n?.newInteraction || 'New Interaction';
        if (jsFormSave) jsFormSave.textContent = PreviewConfig.i18n?.addInteraction || 'Add Interaction';
        
        // Populate dropdowns
        populateEventDropdown();
        
        if (availableFunctions.length === 0) {
            await fetchJsFunctions();
        }
        populateFunctionDropdown();
        
        if (availableApiEndpoints.length === 0) {
            await fetchApiEndpoints();
        }
        populateApiDropdown();
        
        // Reset form
        if (jsFormEvent) jsFormEvent.value = '';
        if (jsFormActionType) jsFormActionType.value = 'function';
        if (jsFormFunctionSection) jsFormFunctionSection.style.display = '';
        if (jsFormApiSection) jsFormApiSection.classList.remove('visible');
        if (jsFormFunction) jsFormFunction.value = '';
        if (jsFormApi) jsFormApi.value = '';
        if (jsFormEndpoint) {
            jsFormEndpoint.value = '';
            jsFormEndpoint.disabled = true;
        }
        if (jsFormApiBody) jsFormApiBody.value = '#form';
        if (jsFormParams) jsFormParams.innerHTML = '';
        if (jsPreviewCode) jsPreviewCode.textContent = '-';
        if (jsFormSave) jsFormSave.disabled = true;
    }
    
    /**
     * Hide add form and return to panel view
     */
    function hideAddForm() {
        if (jsAddForm) jsAddForm.style.display = 'none';
        if (jsPanelActions) jsPanelActions.style.display = 'flex';
        if (elBindingsContainer) elBindingsContainer.style.display = 'none';
        if (elBindingsRows) elBindingsRows.innerHTML = '';
        editingInteraction = null;
    }
    
    /**
     * Handle action type change (function vs API)
     */
    function handleActionTypeChange() {
        const actionType = jsFormActionType?.value;
        
        if (actionType === 'api') {
            if (jsFormFunctionSection) jsFormFunctionSection.style.display = 'none';
            if (jsFormApiSection) jsFormApiSection.classList.add('visible');
            if (jsFormFunction) jsFormFunction.value = '';
            if (jsFormParams) jsFormParams.innerHTML = '';
        } else {
            if (jsFormFunctionSection) jsFormFunctionSection.style.display = '';
            if (jsFormApiSection) jsFormApiSection.classList.remove('visible');
            if (jsFormApi) jsFormApi.value = '';
            if (jsFormEndpoint) {
                jsFormEndpoint.value = '';
                jsFormEndpoint.disabled = true;
            }
            // Hide bindings when switching away from API
            if (elBindingsContainer) elBindingsContainer.style.display = 'none';
            if (elBindingsRows) elBindingsRows.innerHTML = '';
        }
        
        updatePreview();
    }
    
    /**
     * Handle API dropdown change
     */
    function handleApiChange() {
        const apiName = jsFormApi?.value || '';
        populateEndpointDropdown(apiName);
        // Reset bindings when API changes (endpoint is reset)
        if (elBindingsContainer) elBindingsContainer.style.display = 'none';
        if (elBindingsRows) elBindingsRows.innerHTML = '';
        updatePreview();
    }
    
    /**
     * Create a form row for a single function argument
     * @param {Object} arg - Argument definition from function spec
     * @param {number} paramIndex - Position index for the param
     * @param {Function} updateFn - Callback on value change
     * @param {boolean} usePicker - Whether to use searchable picker for selector/class
     */
    function _createArgRow(arg, paramIndex, updateFn, usePicker) {
        var row = document.createElement('div');
        row.className = 'preview-contextual-js-form-row';
        
        var label = document.createElement('label');
        label.className = 'preview-contextual-js-form-label';
        label.textContent = arg.name || ('Param ' + (paramIndex + 1));
        if (arg.required !== false) label.innerHTML += ' <span class="required">*</span>';
        row.appendChild(label);
        
        var inputType = arg.inputType || 'text';
        if (usePicker && (inputType === 'selector' || inputType === 'class')) {
            var picker = createSearchablePicker(inputType, arg, paramIndex);
            row.appendChild(picker);
        } else {
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'preview-contextual-js-form-input';
            input.dataset.paramIndex = paramIndex;
            input.dataset.paramName = arg.name || '';
            input.placeholder = arg.description || arg.name || '';
            if (arg.default !== undefined && arg.default !== null) {
                input.value = arg.default;
            }
            input.addEventListener('input', updateFn);
            row.appendChild(input);
        }
        
        return row;
    }

    /**
     * Append an "Advanced" collapsible section with optional args
     * @param {HTMLElement} container - Parent container to append to
     * @param {Array} optionalItems - Array of {arg, index} for optional params
     * @param {Function} updateFn - Callback on value change
     * @param {boolean} usePicker - Whether to use searchable picker
     */
    function _appendAdvancedSection(container, optionalItems, updateFn, usePicker) {
        if (!optionalItems.length) return;
        
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'preview-contextual-js-form-advanced-toggle';
        toggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'class="preview-contextual-js-form-advanced-chevron">' +
            '<polyline points="9 6 15 12 9 18"/></svg>' +
            (PreviewConfig.i18n?.advanced || 'Advanced');
        
        var content = document.createElement('div');
        content.className = 'preview-contextual-js-form-advanced-content';
        content.style.display = 'none';
        
        optionalItems.forEach(function(item) {
            content.appendChild(_createArgRow(item.arg, item.index, updateFn, usePicker));
        });
        
        toggle.addEventListener('click', function() {
            var visible = content.style.display !== 'none';
            content.style.display = visible ? 'none' : '';
            toggle.classList.toggle('preview-contextual-js-form-advanced-toggle--open', !visible);
        });
        
        container.appendChild(toggle);
        container.appendChild(content);
    }

    /**
     * Handle function dropdown change
     */
    function handleFunctionChange() {
        const selectedOption = jsFormFunction?.options[jsFormFunction.selectedIndex];
        const args = selectedOption?.dataset?.args ? JSON.parse(selectedOption.dataset.args) : [];
        
        if (!jsFormParams) return;
        
        jsFormParams.innerHTML = '';
        
        if (args.length === 0) {
            jsFormParams.innerHTML = '<p class="preview-contextual-js-form-hint">' +
                (PreviewConfig.i18n?.noParams || 'No parameters') + '</p>';
        } else {
            var requiredArgs = [];
            var optionalArgs = [];
            
            args.forEach(function(arg, index) {
                if (arg.required === false) {
                    optionalArgs.push({ arg: arg, index: index });
                } else {
                    requiredArgs.push({ arg: arg, index: index });
                }
            });
            
            // Render required args
            requiredArgs.forEach(function(item) {
                jsFormParams.appendChild(_createArgRow(item.arg, item.index, updatePreview, true));
            });
            
            // Render optional args in collapsible "Advanced" section
            _appendAdvancedSection(jsFormParams, optionalArgs, updatePreview, true);
        }
        
        updatePreview();
    }

    // ==================== API Fetching ====================
    
    /**
     * Fetch JS functions from API
     */
    async function fetchJsFunctions() {
        try {
            const response = await fetch('/management/listJsFunctions', {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${PreviewConfig.authToken}`
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch functions');
            }
            
            const result = await response.json();
            availableFunctions = result.data?.functions || [];
            console.log('[PreviewJsInteractions] Functions loaded:', availableFunctions.length);
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Failed to load functions:', error);
            availableFunctions = [];
        }
    }
    
    /**
     * Fetch API endpoints from API Registry
     */
    async function fetchApiEndpoints() {
        try {
            const response = await fetch('/management/listApiEndpoints', {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${PreviewConfig.authToken}`
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch API endpoints');
            }
            
            const result = await response.json();
            
            const apis = result.data?.apis || [];
            availableApiEndpoints = [];
            
            for (const api of apis) {
                for (const ep of (api.endpoints || [])) {
                    availableApiEndpoints.push({
                        api: api.apiId,
                        endpoint: ep.id || ep.name,
                        method: ep.method || 'POST',
                        path: ep.path,
                        description: ep.description,
                        requestSchema: ep.requestSchema || {},
                        responseSchema: ep.responseSchema || null,
                        responseBindings: ep.responseBindings || []
                    });
                }
            }
            
            console.log('[PreviewJsInteractions] API endpoints loaded:', availableApiEndpoints.length);
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Failed to load API endpoints:', error);
            availableApiEndpoints = [];
        }
    }

    // ==================== Dropdown Population ====================
    
    /**
     * Populate event dropdown
     */
    function populateEventDropdown() {
        if (!jsFormEvent) return;
        
        jsFormEvent.innerHTML = `<option value="">${PreviewConfig.i18n?.selectEvent || 'Select event...'}</option>`;
        
        currentAvailableEvents.forEach(event => {
            const option = document.createElement('option');
            option.value = event;
            option.textContent = event;
            jsFormEvent.appendChild(option);
        });
    }
    
    /**
     * Populate function dropdown (grouped by type)
     */
    function populateFunctionDropdown() {
        if (!jsFormFunction) return;
        
        jsFormFunction.innerHTML = `<option value="">${PreviewConfig.i18n?.selectFunction || 'Select function...'}</option>`;
        
        const grouped = {};
        availableFunctions.forEach(fn => {
            const type = fn.type || 'other';
            if (!grouped[type]) grouped[type] = [];
            grouped[type].push(fn);
        });
        
        const typeOrder = ['core', 'custom', 'other'];
        const typeLabels = {
            'core': PreviewConfig.i18n?.functionGroupCore || 'Core Functions',
            'custom': PreviewConfig.i18n?.functionGroupCustom || 'Custom Functions',
            'other': PreviewConfig.i18n?.functionGroupOther || 'Other'
        };
        
        typeOrder.forEach(type => {
            if (!grouped[type] || grouped[type].length === 0) return;
            
            const optgroup = document.createElement('optgroup');
            optgroup.label = typeLabels[type] || type;
            
            grouped[type].forEach(fn => {
                const option = document.createElement('option');
                option.value = fn.name;
                option.textContent = fn.name + ' - ' + (fn.description || '').substring(0, 40);
                option.dataset.args = JSON.stringify(fn.args || []);
                option.dataset.description = fn.description || '';
                optgroup.appendChild(option);
            });
            
            jsFormFunction.appendChild(optgroup);
        });
    }
    
    /**
     * Populate API dropdown
     */
    function populateApiDropdown() {
        if (!jsFormApi || !jsFormEndpoint) return;
        
        jsFormApi.innerHTML = `<option value="">${PreviewConfig.i18n?.selectApi || 'Select API...'}</option>`;
        jsFormEndpoint.innerHTML = `<option value="">${PreviewConfig.i18n?.selectEndpoint || 'Select endpoint...'}</option>`;
        jsFormEndpoint.disabled = true;
        
        const uniqueApis = [...new Set(availableApiEndpoints.map(ep => ep.api))];
        
        uniqueApis.forEach(apiName => {
            const option = document.createElement('option');
            option.value = apiName;
            option.textContent = apiName;
            jsFormApi.appendChild(option);
        });
    }
    
    /**
     * Populate endpoint dropdown based on selected API
     */
    function populateEndpointDropdown(apiName) {
        if (!jsFormEndpoint) return;
        
        jsFormEndpoint.innerHTML = `<option value="">${PreviewConfig.i18n?.selectEndpoint || 'Select endpoint...'}</option>`;
        
        if (!apiName) {
            jsFormEndpoint.disabled = true;
            return;
        }
        
        const endpoints = availableApiEndpoints.filter(ep => ep.api === apiName);
        
        endpoints.forEach(ep => {
            const option = document.createElement('option');
            option.value = ep.endpoint;
            option.textContent = ep.endpoint + ' (' + ep.method + ')';
            option.dataset.method = ep.method || 'POST';
            option.dataset.requestSchema = JSON.stringify(ep.requestSchema || {});
            jsFormEndpoint.appendChild(option);
        });
        
        jsFormEndpoint.disabled = endpoints.length === 0;
    }

    // ==================== Searchable Picker ====================
    
    /**
     * Strip pseudo-classes/elements and complex combinators from a CSS selector,
     * returning only the simple part usable in querySelector.
     * E.g. ".service-card:hover" → ".service-card",  "h2::before" → "h2"
     */
    function stripPseudoFromSelector(sel) {
        if (!sel || typeof sel !== 'string') return sel;
        // Remove ::pseudo-element first, then :pseudo-class
        // Split on first : that's not part of an escaped sequence
        return sel.replace(/::?[a-zA-Z-]+(\([^)]*\))?/g, '').trim() || sel;
    }
    
    /**
     * Create a searchable picker input with dropdown suggestions
     */
    function createSearchablePicker(inputType, arg, index) {
        const wrapper = document.createElement('div');
        wrapper.className = 'preview-js-picker-wrapper';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'preview-contextual-js-form-input preview-js-picker-input';
        input.dataset.paramIndex = index;
        input.dataset.paramName = arg.name || '';
        input.dataset.inputType = inputType;
        input.placeholder = arg.description || arg.name || '';
        input.autocomplete = 'off';
        
        if (arg.default !== undefined && arg.default !== null) {
            input.value = arg.default;
        }
        
        if (inputType === 'selector') {
            input.placeholder = PreviewConfig.i18n?.selectorOrThis || 'Selector or "this"';
        } else if (inputType === 'class') {
            input.placeholder = PreviewConfig.i18n?.searchClass || 'Search class...';
        }
        
        const dropdown = document.createElement('div');
        dropdown.className = 'preview-js-picker-dropdown';
        dropdown.style.display = 'none';
        
        function getItems() {
            const items = [];
            const categorizedSelectors = getCategorizedSelectorsFn ? getCategorizedSelectorsFn() : { ids: [], classes: [], tags: [] };
            const pageStructureClasses = getPageStructureClassesFn ? getPageStructureClassesFn() : [];
            
            if (inputType === 'selector') {
                const seenValues = new Set();
                
                items.push({ value: 'this', label: PreviewConfig.i18n?.thisElement || '(this element)', type: 'special' });
                seenValues.add('this');
                
                (categorizedSelectors.ids || []).forEach(s => {
                    var clean = stripPseudoFromSelector(s.selector);
                    if (clean && !seenValues.has(clean)) {
                        items.push({ value: clean, label: clean, type: 'id' });
                        seenValues.add(clean);
                    }
                });
                
                pageStructureClasses.forEach(cls => {
                    const selector = '.' + cls;
                    if (!seenValues.has(selector)) {
                        items.push({ value: selector, label: selector, type: 'dom' });
                        seenValues.add(selector);
                    }
                });
                
                (categorizedSelectors.classes || []).forEach(s => {
                    var clean = stripPseudoFromSelector(s.selector);
                    if (clean && !seenValues.has(clean)) {
                        items.push({ value: clean, label: clean + ' (CSS)', type: 'class' });
                        seenValues.add(clean);
                    }
                });
                
                (categorizedSelectors.tags || []).forEach(s => {
                    var clean = stripPseudoFromSelector(s.selector);
                    if (clean && !seenValues.has(clean)) {
                        items.push({ value: clean, label: clean, type: 'tag' });
                        seenValues.add(clean);
                    }
                });
            } else if (inputType === 'class') {
                const seenClasses = new Set();
                
                const commonClasses = ['hidden', 'active', 'open', 'visible', 'disabled', 'selected'];
                commonClasses.forEach(cls => {
                    items.push({ value: cls, label: cls + ' (common)', type: 'common' });
                    seenClasses.add(cls);
                });
                
                pageStructureClasses.forEach(cls => {
                    if (!seenClasses.has(cls)) {
                        items.push({ value: cls, label: cls, type: 'dom' });
                        seenClasses.add(cls);
                    }
                });
                
                (categorizedSelectors.classes || []).forEach(s => {
                    var clean = stripPseudoFromSelector(s.selector);
                    const className = (clean || s.selector).replace(/^\./, '');
                    if (!seenClasses.has(className)) {
                        items.push({ value: className, label: className + ' (CSS)', type: 'class' });
                        seenClasses.add(className);
                    }
                });
            }
            
            return items;
        }
        
        function renderDropdown(filter = '') {
            dropdown.innerHTML = '';
            const items = getItems();
            const filterLower = filter.toLowerCase();
            const filtered = items.filter(item => 
                item.value.toLowerCase().includes(filterLower) || 
                item.label.toLowerCase().includes(filterLower)
            );
            
            if (filtered.length === 0) {
                dropdown.style.display = 'none';
                return;
            }
            
            filtered.forEach(item => {
                const option = document.createElement('div');
                option.className = 'preview-js-picker-option';
                option.dataset.value = item.value;
                option.dataset.type = item.type;
                
                const typeIcon = item.type === 'special' ? '⚡' : 
                                 item.type === 'id' ? '#' : 
                                 item.type === 'class' ? '.' : 
                                 item.type === 'dom' ? '◇' :
                                 item.type === 'common' ? '★' : 
                                 item.type === 'tag' ? '<>' : '';
                option.innerHTML = `<span class="preview-js-picker-type">${typeIcon}</span><span>${item.label}</span>`;
                
                option.addEventListener('click', () => {
                    input.value = item.value;
                    dropdown.style.display = 'none';
                    updatePreview();
                });
                
                dropdown.appendChild(option);
            });
            
            dropdown.style.display = '';
        }
        
        input.addEventListener('focus', () => renderDropdown(input.value));
        input.addEventListener('input', () => {
            renderDropdown(input.value);
            updatePreview();
        });
        input.addEventListener('blur', () => {
            setTimeout(() => { dropdown.style.display = 'none'; }, 200);
        });
        
        input.addEventListener('keydown', (e) => {
            const options = dropdown.querySelectorAll('.preview-js-picker-option');
            const current = dropdown.querySelector('.preview-js-picker-option--active');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!current && options.length > 0) {
                    options[0].classList.add('preview-js-picker-option--active');
                } else if (current && current.nextElementSibling) {
                    current.classList.remove('preview-js-picker-option--active');
                    current.nextElementSibling.classList.add('preview-js-picker-option--active');
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (current && current.previousElementSibling) {
                    current.classList.remove('preview-js-picker-option--active');
                    current.previousElementSibling.classList.add('preview-js-picker-option--active');
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (current) {
                    input.value = current.dataset.value;
                    dropdown.style.display = 'none';
                    updatePreview();
                }
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
            }
        });
        
        wrapper.appendChild(input);
        wrapper.appendChild(dropdown);
        
        return wrapper;
    }

    // ==================== Preview & Save ====================
    
    /**
     * Update preview code display
     */
    function updatePreview() {
        const actionType = jsFormActionType?.value || 'function';
        const eventName = jsFormEvent?.value || '';
        
        if (actionType === 'api') {
            const apiName = jsFormApi?.value || '';
            const endpointName = jsFormEndpoint?.value || '';
            const bodySelector = jsFormApiBody?.value || '#form';
            
            if (!apiName || !endpointName) {
                if (jsPreviewCode) jsPreviewCode.textContent = '-';
                if (jsFormSave) jsFormSave.disabled = true;
                return;
            }
            
            let preview = '{{call:fetch:@' + apiName + '/' + endpointName;
            if (bodySelector.trim()) {
                preview += ',body=' + bodySelector.trim();
            }
            preview += '}}';
            
            if (jsPreviewCode) jsPreviewCode.textContent = preview;
            if (jsFormSave) jsFormSave.disabled = !eventName;
        } else {
            const fnName = jsFormFunction?.value || '';
            
            if (!fnName) {
                if (jsPreviewCode) jsPreviewCode.textContent = '-';
                if (jsFormSave) jsFormSave.disabled = true;
                return;
            }
            
            const paramInputs = jsFormParams?.querySelectorAll('.preview-contextual-js-form-input') || [];
            const params = [];
            paramInputs.forEach(input => {
                if (input.value.trim()) {
                    params.push(input.value.trim());
                }
            });
            
            let preview = '{{call:' + fnName;
            if (params.length > 0) {
                preview += ':' + params.join(',');
            }
            preview += '}}';
            
            if (jsPreviewCode) jsPreviewCode.textContent = preview;
            if (jsFormSave) jsFormSave.disabled = !eventName || !fnName;
        }
    }
    
    /**
     * Handle save button click
     */
    async function handleSave() {
        const eventName = jsFormEvent?.value || '';
        const actionType = jsFormActionType?.value || 'function';
        
        let fnName, params;
        
        if (actionType === 'api') {
            const apiName = jsFormApi?.value || '';
            const endpointName = jsFormEndpoint?.value || '';
            const bodySelector = jsFormApiBody?.value || '';
            
            if (!eventName || !apiName || !endpointName) {
                if (showToastFn) {
                    showToastFn(PreviewConfig.i18n?.selectEventApiEndpoint || 'Please select event, API, and endpoint', 'error');
                }
                return;
            }
            
            fnName = 'fetch';
            params = ['@' + apiName + '/' + endpointName];
            if (bodySelector.trim()) {
                params.push('body=' + bodySelector.trim());
            }
            
            // Save response bindings if any
            var bindings = collectBindings(elBindingsRows);
            if (bindings.length > 0) {
                var saved = await saveResponseBindings(apiName, endpointName, bindings);
                if (!saved) return; // abort if bindings save failed
            }
        } else {
            fnName = jsFormFunction?.value || '';
            
            if (!eventName || !fnName || !currentJsContext) {
                if (showToastFn) {
                    showToastFn(PreviewConfig.i18n?.selectEventAndFunction || 'Please select event and function', 'error');
                }
                return;
            }
            
            const paramInputs = jsFormParams?.querySelectorAll('.preview-contextual-js-form-input') || [];
            params = [];
            paramInputs.forEach(input => {
                if (input.value.trim()) {
                    params.push(input.value.trim());
                }
            });
        }
        
        if (!currentJsContext) {
            if (showToastFn) {
                showToastFn(PreviewConfig.i18n?.noElementSelected || 'No element selected', 'error');
            }
            return;
        }
        
        const isEdit = editingInteraction !== null;
        
        const body = {
            structType: currentJsContext.struct.startsWith('page-') ? 'page' : currentJsContext.struct,
            nodeId: currentJsContext.nodeId,
            event: eventName,
            function: fnName,
            params: params
        };
        
        if (currentJsContext.struct.startsWith('page-')) {
            body.pageName = currentJsContext.struct.substring(5);
        }
        
        if (isEdit) {
            body.index = editingInteraction.index;
            body.event = editingInteraction.event;
            // If the user changed the event, send newEvent
            if (eventName !== editingInteraction.event) {
                body.newEvent = eventName;
            }
        }
        
        try {
            if (jsFormSave) {
                jsFormSave.disabled = true;
                jsFormSave.textContent = PreviewConfig.i18n?.saving || 'Saving...';
            }
            
            const endpoint = isEdit ? '/management/editInteraction' : '/management/addInteraction';
            const method = isEdit ? 'PUT' : 'POST';
            
            const response = await fetch(endpoint, {
                method: method,
                headers: {
                    'Authorization': `Bearer ${PreviewConfig.authToken}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body)
            });
            
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || (isEdit ? 'Failed to edit' : 'Failed to add'));
            }
            
            if (showToastFn) {
                showToastFn(
                    isEdit ? (PreviewConfig.i18n?.interactionUpdated || 'Updated') : (PreviewConfig.i18n?.interactionAdded || 'Added'),
                    'success'
                );
            }
            
            hideAddForm();
            await loadInteractions();
            
            if (reloadPreviewFn) {
                reloadPreviewFn();
            }
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Save error:', error);
            if (showToastFn) {
                showToastFn('Error: ' + error.message, 'error');
            }
        } finally {
            if (jsFormSave) {
                jsFormSave.disabled = false;
                jsFormSave.textContent = isEdit 
                    ? (PreviewConfig.i18n?.save || 'Save')
                    : (PreviewConfig.i18n?.addInteraction || 'Add Interaction');
            }
        }
    }

    // ==================== Page Events ====================
    
    /**
     * Set the current page for page events (called by preview.js)
     * Pass null to hide the page events section (e.g. when editing a component)
     */
    function setCurrentPage(pageName) {
        currentPageName = pageName;
        if (peContainer) {
            peContainer.style.display = pageName ? '' : 'none';
        }
        // Reset form when page changes
        hidePageEventForm();
    }
    
    /**
     * Toggle expand/collapse of page events section
     */
    function togglePageEvents() {
        pageEventsExpanded = !pageEventsExpanded;
        if (peBody) peBody.style.display = pageEventsExpanded ? '' : 'none';
        if (peContainer) {
            peContainer.classList.toggle('preview-contextual-js-page-events--expanded', pageEventsExpanded);
        }
    }
    
    /**
     * Load page events from API
     */
    async function loadPageEvents() {
        if (!currentPageName) return;
        
        try {
            var url = '/management/getPageEvents/' + encodeURIComponent(currentPageName);
            var response = await fetch(url, {
                method: 'GET',
                headers: { 'Authorization': 'Bearer ' + PreviewConfig.authToken }
            });
            
            if (!response.ok) throw new Error('Failed to fetch page events');
            var result = await response.json();
            displayPageEvents(result.data);
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Failed to load page events:', error);
            if (peList) peList.innerHTML = '<p class="preview-contextual-js-empty">Failed to load page events</p>';
            if (peCount) peCount.textContent = '0';
        }
    }
    
    /**
     * Display page events in the list
     */
    function displayPageEvents(data) {
        if (!peList) return;
        
        var interactions = data?.interactions || [];
        if (peCount) peCount.textContent = String(interactions.length);
        
        if (interactions.length === 0) {
            peList.innerHTML = '<p class="preview-contextual-js-empty">' +
                (PreviewConfig.i18n?.noInteractions || 'No page events') + '</p>';
            return;
        }
        
        var html = '';
        interactions.forEach(function(interaction) {
            var label = interaction.function + '(' + (interaction.params?.join(', ') || '') + ')';
            html += '<div class="preview-contextual-js-page-events__item">' +
                '<span class="preview-contextual-js-page-events__event-badge">' + interaction.event + '</span>' +
                '<code class="preview-contextual-js-page-events__code">' + label + '</code>' +
                '<button type="button" class="preview-contextual-js-page-events__delete" ' +
                    'data-event="' + interaction.event + '" data-index="' + interaction.index + '" ' +
                    'title="' + (PreviewConfig.i18n?.delete || 'Delete') + '">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                    '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                '</button></div>';
        });
        
        peList.innerHTML = html;
        
        // Attach delete handlers
        peList.querySelectorAll('.preview-contextual-js-page-events__delete').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                deletePageEvent(btn.dataset.event, parseInt(btn.dataset.index));
            });
        });
    }
    
    /**
     * Delete a page event
     */
    async function deletePageEvent(eventName, index) {
        if (!currentPageName) return;
        if (!confirm(PreviewConfig.i18n?.confirmDeleteInteraction || 'Delete this interaction?')) return;
        
        try {
            var response = await fetch('/management/deletePageEvent', {
                method: 'DELETE',
                headers: {
                    'Authorization': 'Bearer ' + PreviewConfig.authToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ pageName: currentPageName, event: eventName, index: index })
            });
            
            var result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Failed to delete');
            
            if (showToastFn) showToastFn(PreviewConfig.i18n?.interactionDeleted || 'Deleted', 'success');
            await loadPageEvents();
            if (reloadPreviewFn) reloadPreviewFn();
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Delete page event error:', error);
            if (showToastFn) showToastFn('Error: ' + error.message, 'error');
        }
    }
    
    /**
     * Show the add page event form
     */
    async function handlePageEventAdd() {
        // Ensure functions/APIs are loaded
        if (availableFunctions.length === 0) await fetchJsFunctions();
        if (availableApiEndpoints.length === 0) await fetchApiEndpoints();
        
        // Populate function dropdown
        _populateFnSelect(peFormFunction);
        _populateApiSelects(peFormApi, peFormEndpoint);
        
        // Show form, hide add button
        if (peForm) peForm.style.display = '';
        if (peAddBtn?.parentElement) peAddBtn.parentElement.style.display = 'none';
        
        // Reset form fields
        if (peFormEvent) peFormEvent.value = 'onload';
        if (peFormActionType) peFormActionType.value = 'function';
        if (peFormFunctionSection) peFormFunctionSection.style.display = '';
        if (peFormApiSection) peFormApiSection.classList.remove('visible');
        if (peFormFunction) peFormFunction.value = '';
        if (peFormParams) peFormParams.innerHTML = '';
        if (peFormPreview) peFormPreview.textContent = '-';
        if (peFormSave) peFormSave.disabled = true;
    }
    
    /**
     * Hide the add page event form
     */
    function hidePageEventForm() {
        if (peForm) peForm.style.display = 'none';
        if (peAddBtn?.parentElement) peAddBtn.parentElement.style.display = '';
        if (peBindingsContainer) peBindingsContainer.style.display = 'none';
        if (peBindingsRows) peBindingsRows.innerHTML = '';
    }
    
    /**
     * Handle action type toggle for page event form
     */
    function handlePeActionTypeChange() {
        var actionType = peFormActionType?.value;
        if (actionType === 'api') {
            if (peFormFunctionSection) peFormFunctionSection.style.display = 'none';
            if (peFormApiSection) peFormApiSection.classList.add('visible');
        } else {
            if (peFormFunctionSection) peFormFunctionSection.style.display = '';
            if (peFormApiSection) peFormApiSection.classList.remove('visible');
            // Hide bindings when switching away from API
            if (peBindingsContainer) peBindingsContainer.style.display = 'none';
            if (peBindingsRows) peBindingsRows.innerHTML = '';
        }
        updatePageEventPreview();
    }
    
    /**
     * Handle function change for page event form
     */
    function handlePeFunctionChange() {
        var selectedOption = peFormFunction?.options[peFormFunction.selectedIndex];
        var args = selectedOption?.dataset?.args ? JSON.parse(selectedOption.dataset.args) : [];
        
        if (!peFormParams) return;
        peFormParams.innerHTML = '';
        
        if (args.length === 0) {
            peFormParams.innerHTML = '<p class="preview-contextual-js-form-hint">' +
                (PreviewConfig.i18n?.noParams || 'No parameters') + '</p>';
        } else {
            var requiredArgs = [];
            var optionalArgs = [];
            
            args.forEach(function(arg, index) {
                if (arg.required === false) {
                    optionalArgs.push({ arg: arg, index: index });
                } else {
                    requiredArgs.push({ arg: arg, index: index });
                }
            });
            
            // Render required args
            requiredArgs.forEach(function(item) {
                peFormParams.appendChild(_createArgRow(item.arg, item.index, updatePageEventPreview, false));
            });
            
            // Render optional args in collapsible "Advanced" section
            _appendAdvancedSection(peFormParams, optionalArgs, updatePageEventPreview, false);
        }
        
        updatePageEventPreview();
    }
    
    /**
     * Handle API change for page event form
     */
    function handlePeApiChange() {
        var apiName = peFormApi?.value || '';
        if (!peFormEndpoint) return;
        
        peFormEndpoint.innerHTML = '<option value="">' +
            (PreviewConfig.i18n?.selectEndpoint || 'Select endpoint...') + '</option>';
        
        if (!apiName) {
            peFormEndpoint.disabled = true;
        } else {
            var endpoints = availableApiEndpoints.filter(function(ep) { return ep.api === apiName; });
            endpoints.forEach(function(ep) {
                var option = document.createElement('option');
                option.value = ep.endpoint;
                option.textContent = ep.endpoint + ' (' + ep.method + ')';
                peFormEndpoint.appendChild(option);
            });
            peFormEndpoint.disabled = endpoints.length === 0;
        }
        
        // Reset bindings when API changes (endpoint is reset)
        if (peBindingsContainer) peBindingsContainer.style.display = 'none';
        if (peBindingsRows) peBindingsRows.innerHTML = '';
        
        updatePageEventPreview();
    }
    
    /**
     * Update preview code for page event form
     */
    function updatePageEventPreview() {
        var actionType = peFormActionType?.value || 'function';
        
        if (actionType === 'api') {
            var apiName = peFormApi?.value || '';
            var endpointName = peFormEndpoint?.value || '';
            
            if (!apiName || !endpointName) {
                if (peFormPreview) peFormPreview.textContent = '-';
                if (peFormSave) peFormSave.disabled = true;
                return;
            }
            
            if (peFormPreview) peFormPreview.textContent = '{{call:fetch:@' + apiName + '/' + endpointName + '}}';
            if (peFormSave) peFormSave.disabled = false;
        } else {
            var fnName = peFormFunction?.value || '';
            if (!fnName) {
                if (peFormPreview) peFormPreview.textContent = '-';
                if (peFormSave) peFormSave.disabled = true;
                return;
            }
            
            var paramInputs = peFormParams?.querySelectorAll('.preview-contextual-js-form-input') || [];
            var params = [];
            paramInputs.forEach(function(input) {
                if (input.value.trim()) params.push(input.value.trim());
            });
            
            var preview = '{{call:' + fnName;
            if (params.length > 0) preview += ':' + params.join(',');
            preview += '}}';
            
            if (peFormPreview) peFormPreview.textContent = preview;
            if (peFormSave) peFormSave.disabled = false;
        }
    }
    
    /**
     * Save page event
     */
    async function handlePageEventSave() {
        if (!currentPageName) return;
        
        var eventName = peFormEvent?.value || 'onload';
        var actionType = peFormActionType?.value || 'function';
        var fnName, params;
        
        if (actionType === 'api') {
            var apiName = peFormApi?.value || '';
            var endpointName = peFormEndpoint?.value || '';
            if (!apiName || !endpointName) {
                if (showToastFn) showToastFn('Select API and endpoint', 'error');
                return;
            }
            fnName = 'fetch';
            params = ['@' + apiName + '/' + endpointName];
            
            // Save response bindings if any
            var bindings = collectBindings(peBindingsRows);
            if (bindings.length > 0) {
                var saved = await saveResponseBindings(apiName, endpointName, bindings);
                if (!saved) return; // abort if bindings save failed
            }
        } else {
            fnName = peFormFunction?.value || '';
            if (!fnName) {
                if (showToastFn) showToastFn('Select a function', 'error');
                return;
            }
            var paramInputs = peFormParams?.querySelectorAll('.preview-contextual-js-form-input') || [];
            params = [];
            paramInputs.forEach(function(input) {
                if (input.value.trim()) params.push(input.value.trim());
            });
        }
        
        try {
            if (peFormSave) {
                peFormSave.disabled = true;
                peFormSave.textContent = PreviewConfig.i18n?.saving || 'Saving...';
            }
            
            var response = await fetch('/management/addPageEvent', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + PreviewConfig.authToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    pageName: currentPageName,
                    event: eventName,
                    function: fnName,
                    params: params
                })
            });
            
            var result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Failed to add');
            
            if (showToastFn) showToastFn(PreviewConfig.i18n?.interactionAdded || 'Added', 'success');
            hidePageEventForm();
            await loadPageEvents();
            if (reloadPreviewFn) reloadPreviewFn();
            
        } catch (error) {
            console.error('[PreviewJsInteractions] Save page event error:', error);
            if (showToastFn) showToastFn('Error: ' + error.message, 'error');
        } finally {
            if (peFormSave) {
                peFormSave.disabled = false;
                peFormSave.textContent = PreviewConfig.i18n?.addInteraction || 'Add';
            }
        }
    }
    
    // ==================== Response Bindings Helpers ====================
    
    /**
     * Find the cached endpoint descriptor for the given API + endpoint name
     */
    function _findEndpointData(apiSelect, endpointSelect) {
        var apiName = apiSelect?.value || '';
        var epName = endpointSelect?.value || '';
        if (!apiName || !epName) return null;
        return availableApiEndpoints.find(function(ep) {
            return ep.api === apiName && ep.endpoint === epName;
        }) || null;
    }
    
    /**
     * Show/hide the response bindings section and populate it when an endpoint is selected.
     */
    function showBindingsForEndpoint(endpointSelect, container, rowsEl) {
        if (!container || !rowsEl) return;
        
        // Determine which API select to use based on context
        var apiSelect = (container.id && container.id.indexOf('page-event') !== -1)
            ? peFormApi : jsFormApi;
        
        var epData = _findEndpointData(apiSelect, endpointSelect);
        
        if (!epData || !epData.responseSchema || !epData.responseSchema.properties ||
            Object.keys(epData.responseSchema.properties).length === 0) {
            container.style.display = 'none';
            rowsEl.innerHTML = '';
            return;
        }
        
        container.style.display = '';
        rowsEl.innerHTML = '';
        
        // Load existing bindings if any
        var existing = epData.responseBindings || [];
        if (existing.length > 0) {
            existing.forEach(function(binding) {
                addBindingRow(rowsEl, apiSelect, endpointSelect, binding);
            });
        } else {
            // Start with one empty row
            addBindingRow(rowsEl, apiSelect, endpointSelect);
        }
    }
    
    /**
     * Add a single binding row as a mini-card:
     * Row 1: [field dropdown] [selector searchable picker]
     * Row 2: [attribute label + input]
     * Delete button floats top-right on hover.
     */
    /**
     * Add a single binding row as a mini-card.
     * For scalar fields: [field dropdown] [selector picker] [attribute input]
     * For array fields:  [field dropdown] [container picker] [empty text input]
     * Delete button floats top-right on hover.
     */
    function addBindingRow(rowsEl, apiSelect, endpointSelect, existing) {
        if (!rowsEl) return;
        
        var epData = _findEndpointData(apiSelect, endpointSelect);
        var fieldsMap = {};
        var fieldNames = [];
        if (epData && epData.responseSchema && epData.responseSchema.properties) {
            fieldsMap = epData.responseSchema.properties;
            fieldNames = Object.keys(fieldsMap);
        }
        
        var row = document.createElement('div');
        row.className = 'preview-contextual-js-response-bindings__row';
        
        // ── Top row: field + selector/container ──
        var topRow = document.createElement('div');
        topRow.className = 'preview-contextual-js-response-bindings__row-top';
        
        // Field dropdown
        var fieldSelect = document.createElement('select');
        fieldSelect.className = 'preview-contextual-js-response-bindings__row-field';
        fieldSelect.title = PreviewConfig.i18n?.responseField || 'Response field';
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '-- field --';
        fieldSelect.appendChild(emptyOpt);
        fieldNames.forEach(function(f) {
            var schema = fieldsMap[f] || {};
            var label = f;
            if (schema.type) label += '  (' + schema.type + (schema.items ? '<' + (schema.items.type || '?') + '>' : '') + ')';
            var opt = document.createElement('option');
            opt.value = f;
            opt.textContent = label;
            opt.dataset.fieldType = schema.type || '';
            if (existing && existing.field === f) opt.selected = true;
            fieldSelect.appendChild(opt);
        });
        topRow.appendChild(fieldSelect);
        
        // Selector/Container: searchable picker (reused for both modes)
        var selectorWrap = document.createElement('div');
        selectorWrap.className = 'preview-contextual-js-response-bindings__row-selector-wrap';
        
        var selectorInput = document.createElement('input');
        selectorInput.type = 'text';
        selectorInput.className = 'preview-contextual-js-response-bindings__row-selector';
        selectorInput.autocomplete = 'off';
        
        var selectorDropdown = document.createElement('div');
        selectorDropdown.className = 'preview-contextual-js-response-bindings__picker-dropdown';
        selectorDropdown.style.display = 'none';
        
        // Pre-fill from existing binding
        if (existing) {
            if (existing.renderMode === 'list' && existing.container) {
                selectorInput.value = existing.container;
            } else if (existing.selector) {
                selectorInput.value = existing.selector;
            }
        }
        
        function getSelectorItems() {
            var items = [];
            var seen = new Set();
            var categorized = getCategorizedSelectorsFn ? getCategorizedSelectorsFn() : { ids: [], classes: [], tags: [] };
            var pageClasses = getPageStructureClassesFn ? getPageStructureClassesFn() : [];
            
            (categorized.ids || []).forEach(function(s) {
                var clean = stripPseudoFromSelector(s.selector);
                if (clean && !seen.has(clean)) {
                    items.push({ value: clean, label: clean, type: 'id' });
                    seen.add(clean);
                }
            });
            pageClasses.forEach(function(cls) {
                var sel = '.' + cls;
                if (!seen.has(sel)) {
                    items.push({ value: sel, label: sel, type: 'dom' });
                    seen.add(sel);
                }
            });
            (categorized.classes || []).forEach(function(s) {
                var clean = stripPseudoFromSelector(s.selector);
                if (clean && !seen.has(clean)) {
                    items.push({ value: clean, label: clean, type: 'class' });
                    seen.add(clean);
                }
            });
            (categorized.tags || []).forEach(function(s) {
                var clean = stripPseudoFromSelector(s.selector);
                if (clean && !seen.has(clean)) {
                    items.push({ value: clean, label: clean, type: 'tag' });
                    seen.add(clean);
                }
            });
            return items;
        }
        
        function renderSelectorDropdown(filter) {
            selectorDropdown.innerHTML = '';
            var items = getSelectorItems();
            var fl = (filter || '').toLowerCase();
            var filtered = items.filter(function(it) {
                return it.value.toLowerCase().indexOf(fl) !== -1 || it.label.toLowerCase().indexOf(fl) !== -1;
            });
            if (filtered.length === 0) { selectorDropdown.style.display = 'none'; return; }
            
            filtered.forEach(function(item) {
                var opt = document.createElement('div');
                opt.className = 'preview-contextual-js-response-bindings__picker-option';
                opt.dataset.value = item.value;
                var icon = item.type === 'id' ? '#' : item.type === 'class' ? '.' : item.type === 'dom' ? '◇' : item.type === 'tag' ? '<>' : '';
                opt.innerHTML = '<span class="preview-contextual-js-response-bindings__picker-type">' + icon + '</span><span>' + item.label + '</span>';
                opt.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    selectorInput.value = item.value;
                    selectorDropdown.style.display = 'none';
                });
                selectorDropdown.appendChild(opt);
            });
            selectorDropdown.style.display = '';
        }
        
        selectorInput.addEventListener('focus', function() { renderSelectorDropdown(selectorInput.value); });
        selectorInput.addEventListener('input', function() { renderSelectorDropdown(selectorInput.value); });
        selectorInput.addEventListener('blur', function() {
            setTimeout(function() { selectorDropdown.style.display = 'none'; }, 150);
        });
        selectorInput.addEventListener('keydown', function(e) {
            var opts = selectorDropdown.querySelectorAll('.preview-contextual-js-response-bindings__picker-option');
            var cur = selectorDropdown.querySelector('.preview-contextual-js-response-bindings__picker-option--active');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!cur && opts.length > 0) { opts[0].classList.add('preview-contextual-js-response-bindings__picker-option--active'); }
                else if (cur && cur.nextElementSibling) { cur.classList.remove('preview-contextual-js-response-bindings__picker-option--active'); cur.nextElementSibling.classList.add('preview-contextual-js-response-bindings__picker-option--active'); }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (cur && cur.previousElementSibling) { cur.classList.remove('preview-contextual-js-response-bindings__picker-option--active'); cur.previousElementSibling.classList.add('preview-contextual-js-response-bindings__picker-option--active'); }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (cur) { selectorInput.value = cur.dataset.value; selectorDropdown.style.display = 'none'; }
            } else if (e.key === 'Escape') { selectorDropdown.style.display = 'none'; }
        });
        
        selectorWrap.appendChild(selectorInput);
        selectorWrap.appendChild(selectorDropdown);
        topRow.appendChild(selectorWrap);
        
        row.appendChild(topRow);
        
        // ── Bottom row: adapts based on field type ──
        var bottomRow = document.createElement('div');
        bottomRow.className = 'preview-contextual-js-response-bindings__row-bottom';
        
        // Scalar mode elements
        var attrLabel = document.createElement('span');
        attrLabel.className = 'preview-contextual-js-response-bindings__row-attr-label';
        attrLabel.textContent = PreviewConfig.i18n?.targetAttribute || 'Attribute:';
        
        var attrInput = document.createElement('input');
        attrInput.type = 'text';
        attrInput.className = 'preview-contextual-js-response-bindings__row-attr';
        attrInput.placeholder = 'e.g. src, href  (blank = textContent)';
        attrInput.title = PreviewConfig.i18n?.targetAttribute || 'Attribute to set (e.g. src, href). Leave blank to set text content.';
        if (existing && existing.attribute) attrInput.value = existing.attribute;
        
        // Array/list mode elements
        var emptyLabel = document.createElement('span');
        emptyLabel.className = 'preview-contextual-js-response-bindings__row-attr-label';
        emptyLabel.textContent = PreviewConfig.i18n?.emptyText || 'Empty text:';
        
        var emptyInput = document.createElement('input');
        emptyInput.type = 'text';
        emptyInput.className = 'preview-contextual-js-response-bindings__row-empty-text';
        emptyInput.placeholder = PreviewConfig.i18n?.emptyTextPlaceholder || 'e.g. No items found  (optional)';
        emptyInput.title = PreviewConfig.i18n?.emptyTextHint || 'Text shown when the array is empty';
        if (existing && existing.emptyText) emptyInput.value = existing.emptyText;
        
        // List mode hint
        var listHint = document.createElement('span');
        listHint.className = 'preview-contextual-js-response-bindings__row-list-hint';
        listHint.textContent = PreviewConfig.i18n?.listModeHint || 'Uses data-bind attributes inside the container\'s first child as item template.';
        
        /**
         * Swap bottom row contents based on field type.
         * Scalar: attribute label + input
         * Array:  empty text label + input + hint
         */
        function updateRowMode(fieldType) {
            bottomRow.innerHTML = '';
            if (fieldType === 'array') {
                // Array/list mode
                row.dataset.renderMode = 'list';
                selectorInput.placeholder = PreviewConfig.i18n?.containerSelector || 'Container selector';
                selectorInput.title = PreviewConfig.i18n?.containerSelectorHint || 'Container element whose first child is the template. Each array item clones it.';
                bottomRow.appendChild(emptyLabel);
                bottomRow.appendChild(emptyInput);
                bottomRow.appendChild(listHint);
            } else {
                // Scalar mode
                delete row.dataset.renderMode;
                selectorInput.placeholder = PreviewConfig.i18n?.targetSelector || 'Target selector';
                selectorInput.title = PreviewConfig.i18n?.targetSelector || 'CSS selector to inject data into';
                bottomRow.appendChild(attrLabel);
                bottomRow.appendChild(attrInput);
            }
        }
        
        // Determine initial mode
        var initialType = '';
        if (existing && existing.renderMode === 'list') {
            initialType = 'array';
        } else {
            // Check fieldType from the selected option (works for both new and existing rows)
            var selOpt = fieldSelect.options[fieldSelect.selectedIndex];
            if (selOpt) initialType = selOpt.dataset.fieldType || '';
            
            // Auto-detect: if loading an existing scalar binding but the field is actually
            // an array type, force list mode (handles pre-list-mode bindings)
            if (existing && !existing.renderMode && initialType === 'array') {
                existing.container = existing.selector;
                delete existing.selector;
            }
        }
        updateRowMode(initialType);
        
        // Switch mode when field selection changes
        fieldSelect.addEventListener('change', function() {
            var opt = fieldSelect.options[fieldSelect.selectedIndex];
            var fType = opt ? (opt.dataset.fieldType || '') : '';
            updateRowMode(fType);
        });
        
        row.appendChild(bottomRow);
        
        // ── Delete button ──
        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'preview-contextual-js-response-bindings__row-delete';
        deleteBtn.title = PreviewConfig.i18n?.delete || 'Remove';
        deleteBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';
        deleteBtn.addEventListener('click', function() { row.remove(); });
        row.appendChild(deleteBtn);
        
        rowsEl.appendChild(row);
    }
    
    /**
     * Collect binding data from a bindings rows container.
     * Returns an array of binding objects:
     * - Scalar: {field, selector, attribute?}
     * - List:   {field, renderMode:'list', container, emptyText?}
     */
    function collectBindings(rowsEl) {
        if (!rowsEl) return [];
        var rows = rowsEl.querySelectorAll('.preview-contextual-js-response-bindings__row');
        var bindings = [];
        rows.forEach(function(row) {
            var field = row.querySelector('.preview-contextual-js-response-bindings__row-field')?.value || '';
            var selectorVal = row.querySelector('.preview-contextual-js-response-bindings__row-selector')?.value?.trim() || '';
            if (!field || !selectorVal) return; // skip incomplete rows
            
            if (row.dataset.renderMode === 'list') {
                // List/array binding
                var binding = { field: field, renderMode: 'list', container: selectorVal };
                var emptyTxt = row.querySelector('.preview-contextual-js-response-bindings__row-empty-text')?.value?.trim() || '';
                if (emptyTxt) binding.emptyText = emptyTxt;
                bindings.push(binding);
            } else {
                // Scalar binding
                var binding = { field: field, selector: selectorVal };
                var attr = row.querySelector('.preview-contextual-js-response-bindings__row-attr')?.value?.trim() || '';
                if (attr) binding.attribute = attr;
                bindings.push(binding);
            }
        });
        return bindings;
    }
    
    /**
     * Save response bindings to the API endpoint via editApi command.
     * @param {string} apiName
     * @param {string} endpointId
     * @param {Array} bindings
     * @returns {Promise<boolean>}
     */
    async function saveResponseBindings(apiName, endpointId, bindings) {
        try {
            var response = await fetch('/management/editApi', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + PreviewConfig.authToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    apiId: apiName,
                    editEndpoint: {
                        id: endpointId,
                        updates: {
                            responseBindings: bindings
                        }
                    }
                })
            });
            var result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Failed to save bindings');
            
            // Update local cache
            var cached = availableApiEndpoints.find(function(ep) {
                return ep.api === apiName && ep.endpoint === endpointId;
            });
            if (cached) cached.responseBindings = bindings;
            
            return true;
        } catch (error) {
            console.error('[PreviewJsInteractions] Failed to save response bindings:', error);
            if (showToastFn) showToastFn('Error saving bindings: ' + error.message, 'error');
            return false;
        }
    }

    /**
     * Helper: Populate a <select> with available functions (grouped by type)
     */
    function _populateFnSelect(selectEl) {
        if (!selectEl) return;
        selectEl.innerHTML = '<option value="">' +
            (PreviewConfig.i18n?.selectFunction || 'Select function...') + '</option>';
        
        var grouped = {};
        availableFunctions.forEach(function(fn) {
            var type = fn.type || 'other';
            if (!grouped[type]) grouped[type] = [];
            grouped[type].push(fn);
        });
        
        var labels = {
            core: PreviewConfig.i18n?.functionGroupCore || 'Core Functions',
            custom: PreviewConfig.i18n?.functionGroupCustom || 'Custom Functions',
            other: PreviewConfig.i18n?.functionGroupOther || 'Other'
        };
        
        ['core', 'custom', 'other'].forEach(function(type) {
            if (!grouped[type] || grouped[type].length === 0) return;
            var optgroup = document.createElement('optgroup');
            optgroup.label = labels[type] || type;
            grouped[type].forEach(function(fn) {
                var option = document.createElement('option');
                option.value = fn.name;
                option.textContent = fn.name + ' - ' + (fn.description || '').substring(0, 40);
                option.dataset.args = JSON.stringify(fn.args || []);
                optgroup.appendChild(option);
            });
            selectEl.appendChild(optgroup);
        });
    }
    
    /**
     * Helper: Populate API and endpoint <select> elements
     */
    function _populateApiSelects(apiSelect, endpointSelect) {
        if (apiSelect) {
            apiSelect.innerHTML = '<option value="">' +
                (PreviewConfig.i18n?.selectApi || 'Select API...') + '</option>';
            var seen = {};
            availableApiEndpoints.forEach(function(ep) {
                if (!seen[ep.api]) {
                    seen[ep.api] = true;
                    var option = document.createElement('option');
                    option.value = ep.api;
                    option.textContent = ep.api;
                    apiSelect.appendChild(option);
                }
            });
        }
        if (endpointSelect) {
            endpointSelect.innerHTML = '<option value="">' +
                (PreviewConfig.i18n?.selectEndpoint || 'Select endpoint...') + '</option>';
            endpointSelect.disabled = true;
        }
    }

    // ==================== Public API ====================
    
    window.PreviewJsInteractions = {
        init: init,
        show: show,
        hide: hide,
        reload: loadInteractions,
        
        // Page events
        setCurrentPage: setCurrentPage,
        loadPageEvents: loadPageEvents,
        
        // Get current context
        getContext: function() { return currentJsContext; },
        
        // Callback setters
        setShowToast: function(fn) { showToastFn = fn; },
        setSendToIframe: function(fn) { sendToIframeFn = fn; },
        setReloadPreview: function(fn) { reloadPreviewFn = fn; },
        setGetSelectorsLoaded: function(fn) { getSelectorsLoadedFn = fn; },
        setLoadSelectorsData: function(fn) { loadSelectorsDataFn = fn; },
        setGetCategorizedSelectors: function(fn) { getCategorizedSelectorsFn = fn; },
        setGetPageStructureClasses: function(fn) { getPageStructureClassesFn = fn; }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
