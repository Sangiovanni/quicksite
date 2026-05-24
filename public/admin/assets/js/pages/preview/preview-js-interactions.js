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
    // Bucketed events from listInteractions (beta.6+):
    //   { common: [...], lessCommon: [...], advanced: [...] }
    // Falls back to a single 'common' bucket built from currentAvailableEvents
    // if the API didn't ship the grouped shape.
    let currentAvailableEventsGrouped = null;
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
    let jsFormFunctionDetails = null;
    let jsFormApi = null;
    let jsFormEndpoint = null;
    let jsFormApiBody = null;
    let jsFormApiBodyRow = null;       // wrapper for show/hide on GET/DELETE
    let jsFormApiMethod = null;        // direct-URL mode method select
    let jsFormApiUrl = null;           // direct-URL mode URL input
    let jsFormApiRegistryFields = null;
    let jsFormApiDirectFields = null;
    let jsFormTargetRow = null;        // read-only target chip row
    let jsFormTargetLabel = null;      // the <code> showing @api/endpoint
    // Path params (Step 2) — only filled when endpoint has :placeholders.
    let jsFormPathParamsRow = null;
    let jsFormPathParamsRows = null;
    // Advanced fold (Steps 4c, 5, 7).
    let jsFormToastSuccessMount = null;
    let jsFormToastErrorMount = null;
    let jsFormToastSuccessSilent = null;
    let jsFormToastErrorSilent = null;
    let jsFormToastSuccessPicker = null;   // textKey picker instance
    let jsFormToastErrorPicker = null;
    let jsFormActionsList = null;
    let jsFormActionsAdd = null;
    // Auth helper hint (AUTH_FLOWS Tier 1) — shown when endpoint
    // responseSchema declares a token-shaped field.
    let jsFormAuthHint = null;
    // Post-fetch action state — one entry per row.
    // { verb: 'hide', paramValues: ['#form'] }
    let postFetchActionsState = [];
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

    // State stores DOM references + state
    let ssContainer = null;
    let ssBody = null;
    let ssList = null;
    let ssCount = null;
    let ssToggle = null;
    let ssAddBtn = null;
    let ssForm = null;
    let ssFormId = null;
    let ssFormApi = null;
    let ssFormEndpoint = null;
    let ssFormFetchOnLoad = null;
    let ssFieldsRows = null;
    let ssFieldAddBtn = null;
    let ssFormPreview = null;
    let ssFormSave = null;
    let ssFormCancel = null;
    let ssExpanded = false;
    let ssCurrentStores = {};   // { storeId => def } for the current page
    let ssEditingId = null;     // null = creating; otherwise the id being edited

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
        jsFormFunctionDetails = document.getElementById('js-form-function-details');
        jsFormApi = document.getElementById('js-form-api');
        jsFormEndpoint = document.getElementById('js-form-endpoint');
        jsFormApiBody = document.getElementById('js-form-api-body');
        jsFormApiBodyRow = document.getElementById('js-form-api-body-row');
        jsFormApiMethod = document.getElementById('js-form-api-method');
        jsFormApiUrl = document.getElementById('js-form-api-url');
        jsFormApiRegistryFields = document.getElementById('js-form-api-registry-fields');
        jsFormApiDirectFields = document.getElementById('js-form-api-direct-fields');
        jsFormTargetRow = document.getElementById('js-form-target-row');
        jsFormTargetLabel = document.getElementById('js-form-target-label');
        jsFormPathParamsRow = document.getElementById('js-form-path-params-row');
        jsFormPathParamsRows = document.getElementById('js-form-path-params-rows');
        jsFormToastSuccessMount = document.getElementById('js-form-toast-success-mount');
        jsFormToastErrorMount   = document.getElementById('js-form-toast-error-mount');
        jsFormToastSuccessSilent = document.getElementById('js-form-toast-success-silent');
        jsFormToastErrorSilent   = document.getElementById('js-form-toast-error-silent');
        jsFormActionsList = document.getElementById('js-form-actions-list');
        jsFormActionsAdd  = document.getElementById('js-form-actions-add');
        jsFormAuthHint    = document.getElementById('js-form-auth-hint');
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

        // State stores DOM references
        ssContainer = document.getElementById('js-state-stores');
        ssBody = document.getElementById('js-state-stores-body');
        ssList = document.getElementById('js-state-stores-list');
        ssCount = document.getElementById('js-state-stores-count');
        ssToggle = document.getElementById('js-state-stores-toggle');
        ssAddBtn = document.getElementById('js-state-store-add');
        ssForm = document.getElementById('js-state-store-form');
        ssFormId = document.getElementById('js-state-store-id');
        ssFormApi = document.getElementById('js-state-store-api');
        ssFormEndpoint = document.getElementById('js-state-store-endpoint');
        ssFormFetchOnLoad = document.getElementById('js-state-store-fetch-on-load');
        ssFieldsRows = document.getElementById('js-state-store-fields-rows');
        ssFieldAddBtn = document.getElementById('js-state-store-field-add');
        ssFormPreview = document.getElementById('js-state-store-preview');
        ssFormSave = document.getElementById('js-state-store-save');
        ssFormCancel = document.getElementById('js-state-store-cancel');

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
                syncTargetLabel();
                syncBodyVisibilityForMethod();
                renderPathParamRows();
                renderAuthHint();
                updatePreview();
                showBindingsForEndpoint(jsFormEndpoint, elBindingsContainer, elBindingsRows);
            });
        }
        
        // Body input change
        if (jsFormApiBody) {
            jsFormApiBody.addEventListener('input', updatePreview);
        }

        // API mode radio (registry vs direct URL)
        document.querySelectorAll('input[name="js-form-api-mode"]').forEach(function(r) {
            r.addEventListener('change', handleApiModeChange);
        });

        // Direct-URL mode: method + URL inputs
        if (jsFormApiMethod) {
            jsFormApiMethod.addEventListener('change', function() {
                syncBodyVisibilityForMethod();
                updatePreview();
            });
        }
        if (jsFormApiUrl) {
            jsFormApiUrl.addEventListener('input', function() {
                renderPathParamRows();   // re-scan :placeholders in the URL
                updatePreview();
            });
        }

        // Advanced fold: toast silent checkboxes.
        if (jsFormToastSuccessSilent) jsFormToastSuccessSilent.addEventListener('change', updatePreview);
        if (jsFormToastErrorSilent)   jsFormToastErrorSilent.addEventListener('change', updatePreview);

        // Post-fetch actions list
        if (jsFormActionsAdd) {
            jsFormActionsAdd.addEventListener('click', function() {
                postFetchActionsState.push({ verb: '', paramValues: [] });
                renderActionsList();
                updatePreview();
            });
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

        // ---- State stores handlers ----

        // Toggle expand/collapse (click on header)
        if (ssContainer) {
            var ssHeader = ssContainer.querySelector('.preview-contextual-js-page-events__header');
            if (ssHeader) ssHeader.addEventListener('click', toggleStateStores);
        }
        // New store button
        if (ssAddBtn) ssAddBtn.addEventListener('click', handleStateStoreAdd);
        // Cancel wizard
        if (ssFormCancel) ssFormCancel.addEventListener('click', hideStateStoreForm);
        // Save wizard
        if (ssFormSave) ssFormSave.addEventListener('click', handleStateStoreSave);
        // Store id input → re-validate
        if (ssFormId) ssFormId.addEventListener('input', updateStateStorePreview);
        // API select → populate endpoints
        if (ssFormApi) ssFormApi.addEventListener('change', handleSsApiChange);
        // Endpoint select → auto-seed fields from schema + re-validate
        if (ssFormEndpoint) ssFormEndpoint.addEventListener('change', handleSsEndpointChange);
        // Add field row
        if (ssFieldAddBtn) {
            ssFieldAddBtn.addEventListener('click', function() {
                if (ssFieldsRows) ssFieldsRows.appendChild(_renderSsFieldRow());
                updateStateStorePreview();
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
            
            // Store available events for the add form (flat list kept for legacy callers)
            currentAvailableEvents = result.data?.availableEvents || [];
            currentAvailableEventsGrouped = result.data?.availableEventsGrouped || null;
            
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

        // Saved interactions don't carry an explicit "authored in API
        // mode" marker — both modes serialise identically as
        //   fnName='fetch', params=['@apiId/endpointId', 'body=...']  (registry)
        //   fnName='fetch', params=['POST', '/api/users', 'body=...']  (direct URL)
        // Detect via the first param: starts with '@' → registry,
        // matches a known HTTP method → direct URL.
        var DIRECT_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        var apiModeDetected = null;  // 'registry' | 'direct' | null
        if (interaction.function === 'fetch' && Array.isArray(interaction.params)) {
            var first = typeof interaction.params[0] === 'string' ? interaction.params[0] : '';
            if (first.charAt(0) === '@') apiModeDetected = 'registry';
            else if (DIRECT_METHODS.indexOf(first.toUpperCase()) !== -1) apiModeDetected = 'direct';
        }

        if (apiModeDetected) {
            // Extract all kwargs from the param list. Both modes use the
            // same "key=value" tail; the leading positional args differ.
            // For registry mode: skip index 0 (the @api/ep ref).
            // For direct mode:   skip indices 0-1 (METHOD, URL).
            var firstKwargIdx = (apiModeDetected === 'direct') ? 2 : 1;
            var kwargs = {};
            for (var i = firstKwargIdx; i < interaction.params.length; i++) {
                var p = interaction.params[i];
                if (typeof p !== 'string') continue;
                var eq = p.indexOf('=');
                if (eq === -1) continue;
                kwargs[p.substring(0, eq)] = p.substring(eq + 1);
            }
            var bodyVal = kwargs['body'] || '';

            // ALWAYS refetch the endpoint catalogue on edit so newly-
            // saved responseSchema / responseBindings / auth changes
            // surface immediately. Module-level cache survives the
            // admin's round-trips otherwise (admin edits api-endpoints.json
            // → preview page already loaded → cache stale). Cost is
            // one round trip (~50ms); cheap given the alternative is
            // "user has to hard-reload" for any admin change to land.
            await fetchApiEndpoints();

            if (jsFormActionType) {
                jsFormActionType.value = 'api';
                jsFormActionType.dispatchEvent(new Event('change'));
            }
            populateApiDropdown();

            // Switch the mode radio to the detected mode + sync visibility.
            document.querySelectorAll('input[name="js-form-api-mode"]').forEach(function(r) {
                r.checked = (r.value === apiModeDetected);
            });
            handleApiModeChange();

            if (apiModeDetected === 'registry') {
                // Parse '@apiId/endpointId'
                var ref = interaction.params[0].substring(1);
                var slashIdx = ref.indexOf('/');
                var apiIdFromRef      = slashIdx > 0 ? ref.substring(0, slashIdx) : '';
                var endpointIdFromRef = slashIdx > 0 ? ref.substring(slashIdx + 1) : '';
                if (jsFormApi) {
                    jsFormApi.value = apiIdFromRef;
                    jsFormApi.dispatchEvent(new Event('change'));
                }
                if (jsFormEndpoint) {
                    jsFormEndpoint.value = endpointIdFromRef;
                    jsFormEndpoint.dispatchEvent(new Event('change'));
                }
                // Path params already rendered by the endpoint-change
                // listener (renderPathParamRows fires there). Re-render
                // now with the saved kwargs so values pre-fill.
                renderPathParamRows(kwargs);
            } else {
                // Direct URL: params[0] = METHOD, params[1] = URL.
                var methodFromParams = interaction.params[0].toUpperCase();
                var urlFromParams = typeof interaction.params[1] === 'string' ? interaction.params[1] : '';
                if (jsFormApiMethod) {
                    jsFormApiMethod.value = methodFromParams;
                }
                if (jsFormApiUrl) {
                    jsFormApiUrl.value = urlFromParams;
                }
                syncBodyVisibilityForMethod();
                renderPathParamRows(kwargs);
            }

            if (jsFormApiBody) {
                jsFormApiBody.value = bodyVal;
            }

            // Restore Advanced-fold values from kwargs. Note: the saved
            // chain stores RESOLVED toast strings (PHP translated them
            // at compile time), but the picker UI shows KEYS — so on
            // edit, we only know the displayed key if the user re-picks
            // it (or if we cache the key alongside in api-endpoints.json,
            // which we don't today). For now, leave the toast pickers
            // empty on edit; user re-picks if they want to change.
            // The `silent` and onSuccess/onError fields ARE the saved
            // values (no translation), so restore them directly.
            ensureToastPickersMounted();
            if (jsFormToastSuccessSilent) jsFormToastSuccessSilent.checked = !!kwargs['silent'];
            if (jsFormToastErrorSilent)   jsFormToastErrorSilent.checked   = !!kwargs['silent'];

            // Load sibling interactions (same event, after this one) as
            // post-fetch actions. `index` is the per-event index passed
            // into editInteraction.
            _loadActionsForEdit(eventName, index);

            updatePreview();
            if (jsFormSave) jsFormSave.disabled = false;
            return;  // skip the function-mode pre-fill block below
        }

        // Function-mode edit: pre-fill function dropdown + params.
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
                        // For <select> form inputs (e.g. eventArg picker), if the
                        // saved value isn't in the options list, inject it as a
                        // "(legacy)" option so the user can still see/edit it.
                        if (input.tagName === 'SELECT') {
                            const v = params[i];
                            const has = Array.from(input.options).some(o => o.value === v);
                            if (!has) {
                                const opt = document.createElement('option');
                                opt.value = v;
                                opt.textContent = v + ' (legacy)';
                                input.appendChild(opt);
                            }
                        }
                        input.value = params[i];
                        input.dispatchEvent(new Event('input'));
                    }
                });
                // Close any picker dropdowns that the synthetic input events opened
                // (they normally open on focus/typing, which we don't want during prefill).
                jsFormParams.querySelectorAll('.preview-js-picker-dropdown').forEach(dd => {
                    dd.style.display = 'none';
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
        
        // Always refetch on form open so admin-side changes (auth /
        // responseSchema / responseBindings) surface immediately.
        await fetchApiEndpoints();
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
        // Reset API mode to registry (default) + clear direct-URL fields.
        document.querySelectorAll('input[name="js-form-api-mode"]').forEach(function(r) {
            r.checked = (r.value === 'registry');
        });
        if (jsFormApiMethod) jsFormApiMethod.value = 'GET';
        if (jsFormApiUrl) jsFormApiUrl.value = '';
        if (jsFormApiRegistryFields) jsFormApiRegistryFields.style.display = '';
        if (jsFormApiDirectFields)   jsFormApiDirectFields.style.display = 'none';
        if (jsFormTargetRow) jsFormTargetRow.style.display = 'none';
        // Reset Advanced fold.
        if (jsFormToastSuccessSilent) jsFormToastSuccessSilent.checked = false;
        if (jsFormToastErrorSilent)   jsFormToastErrorSilent.checked   = false;
        if (jsFormToastSuccessPicker) jsFormToastSuccessPicker.setValue('');
        if (jsFormToastErrorPicker)   jsFormToastErrorPicker.setValue('');
        // Reset path params (no endpoint picked yet).
        if (jsFormPathParamsRow) jsFormPathParamsRow.style.display = 'none';
        if (jsFormPathParamsRows) {
            while (jsFormPathParamsRows.firstChild) {
                jsFormPathParamsRows.removeChild(jsFormPathParamsRows.firstChild);
            }
        }
        // Reset post-fetch actions + auth hint.
        postFetchActionsState = [];
        renderActionsList();
        if (jsFormAuthHint) jsFormAuthHint.style.display = 'none';
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
    async function handleActionTypeChange() {
        const actionType = jsFormActionType?.value;

        if (actionType === 'api') {
            if (jsFormFunctionSection) jsFormFunctionSection.style.display = 'none';
            if (jsFormApiSection) jsFormApiSection.classList.add('visible');
            if (jsFormFunction) jsFormFunction.value = '';
            if (jsFormParams) jsFormParams.innerHTML = '';

            // Lazy-load the API dropdown if it hasn't been populated yet
            // (the Edit flow doesn't proactively call populateApiDropdown
            // the way Add does, so switching mode mid-edit found the
            // dropdown empty). Idempotent: skips refetch when already loaded.
            if (availableApiEndpoints.length === 0) {
                await fetchApiEndpoints();
            }
            // Only repopulate when the dropdown is empty (has just the
            // placeholder option). Avoids stomping a user-mid-pick.
            if (jsFormApi && jsFormApi.options.length <= 1) {
                populateApiDropdown();
            }
            // Toast pickers live in the Advanced fold — mount them now
            // that we know API mode is active. Idempotent.
            ensureToastPickersMounted();
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
        syncTargetLabel();
        updatePreview();
    }

    /**
     * Handle API mode radio change (registry vs direct URL). Just shows
     * the right sub-fields; the saved interaction shape is decided at
     * compile time in updatePreview / handleSave.
     */
    function handleApiModeChange() {
        const mode = getApiMode();
        if (jsFormApiRegistryFields) jsFormApiRegistryFields.style.display = mode === 'direct' ? 'none' : '';
        if (jsFormApiDirectFields)   jsFormApiDirectFields.style.display   = mode === 'direct' ? '' : 'none';
        syncBodyVisibilityForMethod();
        // Auth-token detection only applies to registry mode (direct
        // URL has no schema). Re-render so switching back from direct
        // restores the banner.
        if (mode === 'direct' && jsFormAuthHint) {
            jsFormAuthHint.style.display = 'none';
        } else {
            renderAuthHint();
        }
        updatePreview();
    }

    /**
     * Read the currently-selected API mode ('registry' or 'direct').
     * Defaults to 'registry' when no radio is checked.
     */
    function getApiMode() {
        const checked = document.querySelector('input[name="js-form-api-mode"]:checked');
        return checked ? checked.value : 'registry';
    }

    /**
     * Update the read-only target label that shows the @apiId/endpointId
     * the user just picked. Hides itself when either side is empty.
     * Registry-mode only — direct-URL mode shows the URL field itself.
     */
    function syncTargetLabel() {
        if (!jsFormTargetRow || !jsFormTargetLabel) return;
        const api = jsFormApi?.value || '';
        const ep  = jsFormEndpoint?.value || '';
        if (api && ep) {
            jsFormTargetLabel.textContent = '@' + api + '/' + ep;
            jsFormTargetRow.style.display = '';
        } else {
            jsFormTargetLabel.textContent = '—';
            jsFormTargetRow.style.display = 'none';
        }
    }

    /**
     * Hide the body source row when the selected HTTP method forbids a
     * request body (GET / DELETE). The browser-side QS.fetch already
     * flattens GET-with-body to a query string, but surfacing it in the
     * picker as "Body source" is misleading. Direct-URL mode uses the
     * Method dropdown; registry mode uses the endpoint's declared method.
     */
    function syncBodyVisibilityForMethod() {
        if (!jsFormApiBodyRow) return;
        const method = getEffectiveMethod();
        const noBody = method === 'GET' || method === 'DELETE';
        jsFormApiBodyRow.style.display = noBody ? 'none' : '';
    }

    /**
     * Resolve the effective HTTP method for the current form state.
     * Direct-URL mode: from the Method dropdown.
     * Registry mode: from the selected endpoint's declared method.
     * Default: 'POST' (the historic default for API mode).
     */
    function getEffectiveMethod() {
        if (getApiMode() === 'direct') {
            return (jsFormApiMethod?.value || 'GET').toUpperCase();
        }
        const ep = _findEndpointData(jsFormApi, jsFormEndpoint);
        return (ep?.method || 'POST').toUpperCase();
    }

    /**
     * Extract :placeholder names from an endpoint path (or a free URL).
     * Returns the list in declared order, de-duplicated.
     */
    function _extractPathPlaceholders(pathOrUrl) {
        if (!pathOrUrl || typeof pathOrUrl !== 'string') return [];
        var seen = {};
        var out = [];
        var re = /:([a-zA-Z][a-zA-Z0-9_]*)/g;
        var m;
        while ((m = re.exec(pathOrUrl)) !== null) {
            if (!seen[m[1]]) {
                seen[m[1]] = true;
                out.push(m[1]);
            }
        }
        return out;
    }

    /**
     * Render path-param rows for the currently-selected endpoint (or
     * URL in direct mode). Each row is a labelled text input; the
     * `name` of the input becomes the kwarg key in the compiled call.
     *
     * Optionally pre-fills values from `existingKwargs` (used on edit).
     */
    function renderPathParamRows(existingKwargs) {
        if (!jsFormPathParamsRow || !jsFormPathParamsRows) return;
        existingKwargs = existingKwargs || {};

        // Source: registry endpoint's path, or direct URL.
        var source = '';
        if (getApiMode() === 'direct') {
            source = jsFormApiUrl?.value || '';
        } else {
            var ep = _findEndpointData(jsFormApi, jsFormEndpoint);
            source = ep ? (ep.path || '') : '';
        }

        var names = _extractPathPlaceholders(source);

        // Pull existing values from the rows we're about to discard so
        // the user doesn't lose typing when the path changes mid-edit.
        var prior = {};
        jsFormPathParamsRows.querySelectorAll('input[data-path-param]').forEach(function(inp) {
            prior[inp.dataset.pathParam] = inp.value;
        });

        // Clear + rebuild.
        while (jsFormPathParamsRows.firstChild) {
            jsFormPathParamsRows.removeChild(jsFormPathParamsRows.firstChild);
        }
        if (names.length === 0) {
            jsFormPathParamsRow.style.display = 'none';
            return;
        }

        names.forEach(function(name) {
            var row = document.createElement('div');
            row.className = 'preview-contextual-js-form-path-param-row';

            var lbl = document.createElement('span');
            lbl.className = 'preview-contextual-js-form-path-param-name';
            lbl.textContent = ':' + name;
            row.appendChild(lbl);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'preview-contextual-js-form-input preview-contextual-js-form-path-param-value';
            input.dataset.pathParam = name;
            input.placeholder = 'value or #selector.value';
            input.value = (existingKwargs[name] !== undefined ? existingKwargs[name]
                : prior[name] !== undefined ? prior[name] : '');
            input.addEventListener('input', updatePreview);
            row.appendChild(input);

            jsFormPathParamsRows.appendChild(row);
        });

        jsFormPathParamsRow.style.display = '';
    }

    /**
     * Read the current path-param input values as kwargs. Empty values
     * are skipped so optional placeholders stay literal (`/path/:opt`
     * → runtime warns + keeps the `:opt` so the dev sees what's empty).
     */
    function _collectPathParamKwargs() {
        var out = [];
        if (!jsFormPathParamsRows) return out;
        jsFormPathParamsRows.querySelectorAll('input[data-path-param]').forEach(function(inp) {
            var v = (inp.value || '').trim();
            if (v) out.push([inp.dataset.pathParam, v]);
        });
        return out;
    }

    /**
     * Lazy-mount the toast textKey pickers into their slots. Uses the
     * shared `QSComplexWizard.createTextKeyPicker` primitive (loaded by
     * preview-config.php alongside the Complex Element wizard system).
     * Idempotent — calls after the first one are no-ops.
     */
    function ensureToastPickersMounted() {
        var factory = window.QSComplexWizard && window.QSComplexWizard.createTextKeyPicker;
        if (typeof factory !== 'function') return;  // primitive not loaded yet
        if (!jsFormToastSuccessPicker && jsFormToastSuccessMount) {
            jsFormToastSuccessPicker = factory({
                container:   jsFormToastSuccessMount,
                placeholder: 'e.g. form.contact.success',
                value:       '',
                onChange:    updatePreview,
            });
        }
        if (!jsFormToastErrorPicker && jsFormToastErrorMount) {
            jsFormToastErrorPicker = factory({
                container:   jsFormToastErrorMount,
                placeholder: 'e.g. form.contact.error',
                value:       '',
                onChange:    updatePreview,
            });
        }
    }

    // -----------------------------------------------------------------
    // Auth helper hint (AUTH_FLOWS Tier 1)
    // -----------------------------------------------------------------
    // When the endpoint's responseSchema declares a token-shaped field,
    // surface a one-click "+ Add saveToken" button that drops a pre-
    // filled saveToken row into the post-fetch actions list. The user
    // can then edit storage/key/path or remove it like any other row.

    var TOKEN_LIKE_FIELDS = ['token', 'accessToken', 'refreshToken', 'jwt'];

    /**
     * Walk a flattened response schema and return dot-paths whose
     * leaf segment matches one of the known token-shaped names.
     */
    function _detectTokenFields(endpointData) {
        if (!endpointData || !endpointData.responseSchema) return [];
        var props = endpointData.responseSchema.properties;
        if (!props) return [];
        var flat = _flattenSchema(props);
        var found = [];
        for (var path in flat) {
            if (!Object.prototype.hasOwnProperty.call(flat, path)) continue;
            var lastSeg = path.split('.').pop();
            if (TOKEN_LIKE_FIELDS.indexOf(lastSeg) !== -1) {
                found.push(path);
            }
        }
        return found;
    }

    /**
     * Re-render the auth helper hint banner. Called on endpoint change.
     * Shows nothing when no token-shaped fields are declared.
     */
    function renderAuthHint() {
        if (!jsFormAuthHint) return;
        while (jsFormAuthHint.firstChild) {
            jsFormAuthHint.removeChild(jsFormAuthHint.firstChild);
        }
        var epData = _findEndpointData(jsFormApi, jsFormEndpoint);
        var detected = _detectTokenFields(epData);
        if (detected.length === 0) {
            jsFormAuthHint.style.display = 'none';
            return;
        }
        jsFormAuthHint.style.display = '';

        var msg = document.createElement('span');
        msg.className = 'preview-contextual-js-form-auth-hint__msg';
        msg.appendChild(document.createTextNode(
            (detected.length > 1 ? 'Token fields detected: ' : 'Token field detected: ')
        ));
        detected.forEach(function(path, i) {
            if (i > 0) msg.appendChild(document.createTextNode(', '));
            var code = document.createElement('code');
            code.textContent = path;
            msg.appendChild(code);
        });
        jsFormAuthHint.appendChild(msg);

        // One quick-add button per detected field. Click → push a
        // pre-filled saveToken row into postFetchActionsState and
        // re-render. The user can tune storage / key / path or
        // remove the row.
        detected.forEach(function(path) {
            var leaf = path.split('.').pop();
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'admin-btn admin-btn--xs admin-btn--ghost preview-contextual-js-form-auth-hint__btn';
            btn.textContent = '+ saveToken("' + leaf + '")';
            btn.title = 'Add a saveToken action that stores response.' + path
                + ' into localStorage as "' + leaf + '".';
            btn.addEventListener('click', function() {
                postFetchActionsState.push({
                    verb: 'saveToken',
                    paramValues: ['localStorage', leaf, path]
                });
                renderActionsList();
                updatePreview();
            });
            jsFormAuthHint.appendChild(btn);
        });
    }

    // -----------------------------------------------------------------
    // Post-fetch actions (Step 5)
    // -----------------------------------------------------------------
    // Storage model: each "post-fetch action" is a SIBLING interaction
    // on the same event. The renderer assembles the chain server-side
    // (multiple {{call:...}} in the same attribute become an async
    // chain via transformCallSyntax). The picker tracks them as state
    // here, saves them sequentially in handleSave after the main fetch.

    /**
     * Render the post-fetch actions list from `postFetchActionsState`.
     * Idempotent — fully rebuilds the list each time. Each row contains
     * a verb dropdown + verb-specific arg inputs (reusing _createArgRow)
     * + a delete button.
     */
    function renderActionsList() {
        if (!jsFormActionsList) return;
        while (jsFormActionsList.firstChild) {
            jsFormActionsList.removeChild(jsFormActionsList.firstChild);
        }
        postFetchActionsState.forEach(function(action, idx) {
            jsFormActionsList.appendChild(_buildActionRow(action, idx));
        });
    }

    /**
     * Build one action row. The row stays bound to its state object;
     * mutations from inputs update the state in place and call
     * updatePreview.
     */
    function _buildActionRow(action, idx) {
        var row = document.createElement('div');
        row.className = 'preview-contextual-js-form-action-row';

        // Verb dropdown
        var verbSelect = document.createElement('select');
        verbSelect.className = 'preview-contextual-js-form-action-verb';
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '-- pick verb --';
        verbSelect.appendChild(blank);
        availableFunctions.forEach(function(fn) {
            // Skip 'fetch' — fetch-then-fetch is unusual and easier to
            // author as a separate top-level interaction.
            if (!fn || !fn.name || fn.name === 'fetch') return;
            var opt = document.createElement('option');
            opt.value = fn.name;
            opt.textContent = fn.name;
            verbSelect.appendChild(opt);
        });
        verbSelect.value = action.verb || '';

        // Args container — re-rendered when verb changes.
        var argsContainer = document.createElement('div');
        argsContainer.className = 'preview-contextual-js-form-action-args';

        function rerenderArgs() {
            while (argsContainer.firstChild) argsContainer.removeChild(argsContainer.firstChild);
            var fnSpec = availableFunctions.find(function(f) { return f && f.name === verbSelect.value; });
            if (!fnSpec || !Array.isArray(fnSpec.args)) return;
            fnSpec.args.forEach(function(arg, i) {
                var argRow = _createArgRow(arg, i, function() {
                    // Pull all current values back into state.
                    action.paramValues = [];
                    argsContainer.querySelectorAll('input, select').forEach(function(inp) {
                        var pi = inp.dataset && inp.dataset.paramIndex !== undefined
                            ? parseInt(inp.dataset.paramIndex, 10) : -1;
                        if (pi >= 0) action.paramValues[pi] = inp.value;
                    });
                    updatePreview();
                }, true);
                argsContainer.appendChild(argRow);
            });
            // Pre-fill from action.paramValues if present.
            if (action.paramValues) {
                argsContainer.querySelectorAll('input, select').forEach(function(inp) {
                    var pi = inp.dataset && inp.dataset.paramIndex !== undefined
                        ? parseInt(inp.dataset.paramIndex, 10) : -1;
                    if (pi >= 0 && action.paramValues[pi] !== undefined) {
                        inp.value = action.paramValues[pi];
                    }
                });
            }
        }
        verbSelect.addEventListener('change', function() {
            action.verb = verbSelect.value;
            action.paramValues = [];
            rerenderArgs();
            updatePreview();
        });

        // Delete button
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'preview-contextual-js-form-action-delete';
        del.title = 'Remove action';
        del.textContent = '×';
        del.addEventListener('click', function() {
            postFetchActionsState.splice(idx, 1);
            renderActionsList();
            updatePreview();
        });

        row.appendChild(verbSelect);
        row.appendChild(argsContainer);
        row.appendChild(del);
        rerenderArgs();

        // Wire input/change events on every input/select in this row
        // so the preview updates live as the user types. The selector
        // picker has its own event handlers that don't fire our
        // action-row callback, so this is the safety net. Idempotent
        // via `data-action-wired` guard.
        function wireRowChangeEvents() {
            row.querySelectorAll('input, select').forEach(function(inp) {
                if (inp.dataset.actionWired === '1') return;
                inp.dataset.actionWired = '1';
                inp.addEventListener('input', updatePreview);
                inp.addEventListener('change', updatePreview);
            });
        }
        wireRowChangeEvents();
        // Re-wire after verb change (rerenderArgs builds new inputs).
        verbSelect.addEventListener('change', wireRowChangeEvents);

        return row;
    }

    /**
     * Read action values from the DOM. Source of truth at preview /
     * save time — the cached `postFetchActionsState` is only used for
     * the INITIAL row layout (which rows exist and what verb each
     * holds on first render). Values come from the inputs themselves.
     *
     * Why DOM-source: the selector picker (used for selector/class/
     * matchTarget args) has its own event handlers that don't fire
     * the action-row callback, so the cache could go stale. Reading
     * DOM at use-time avoids that whole class of sync bugs.
     */
    function _collectActionsFromDom() {
        var actions = [];
        if (!jsFormActionsList) return actions;
        jsFormActionsList.querySelectorAll('.preview-contextual-js-form-action-row').forEach(function(row) {
            var verbSelect = row.querySelector('.preview-contextual-js-form-action-verb');
            var verb = verbSelect ? verbSelect.value : '';
            if (!verb) return;
            var paramValues = [];
            row.querySelectorAll('input[data-param-index], select[data-param-index]').forEach(function(inp) {
                var pi = parseInt(inp.dataset.paramIndex, 10);
                if (!isNaN(pi)) paramValues[pi] = inp.value;
            });
            actions.push({ verb: verb, paramValues: paramValues });
        });
        return actions;
    }

    /**
     * Compile the action list into preview-syntax fragments. Reads
     * from the DOM (source of truth) — see _collectActionsFromDom.
     */
    function _previewPostFetchActions() {
        return _collectActionsFromDom()
            .filter(function(a) { return a && a.verb; })
            .map(function(a) {
                var args = (a.paramValues || [])
                    .map(function(v) { return (v || '').trim(); })
                    .filter(function(v) { return v !== ''; })
                    .join(',');
                return ';{{call:' + a.verb + (args ? ':' + args : '') + '}}';
            }).join('');
    }

    /**
     * Persist the post-fetch action list as sibling interactions on
     * the same event. Replace-on-save semantics: existing siblings
     * after the main interaction are deleted, then the picker's
     * current state is added in order.
     *
     * Why replace-on-save: the picker treats the actions list as the
     * source of truth. Trying to diff-edit each row would be much
     * more complex (matching old siblings to new rows by verb/params,
     * detecting moves, etc.). For a small chain (~2-5 actions),
     * delete+add is acceptable.
     *
     * Indices: deleting from highest-to-lowest keeps the remaining
     * indices stable. After the main save, the just-saved interaction
     * sits at some position within its event's list; siblings AFTER it
     * (higher index) are the "post-fetch" chain.
     */
    async function _persistPostFetchActions(ctx) {
        if (!currentJsContext) return;
        var eventName = ctx.eventName;
        var structType = ctx.structType;
        var nodeId     = ctx.nodeId;
        var pageName   = ctx.pageName;

        // Refresh interactions list so we work from the latest server state.
        await loadInteractions();
        var sameEvent = (currentInteractionsData && Array.isArray(currentInteractionsData.interactions))
            ? currentInteractionsData.interactions.filter(function(i) { return i.event === eventName; })
            : [];

        // Where is the main interaction in this event's list?
        // - On Edit: ctx.mainIndex (caller passes body.index used in PUT).
        // - On Add:  always the last one (it was just appended).
        var mainIdx = (ctx.mainIndex !== null && ctx.mainIndex !== undefined)
            ? ctx.mainIndex
            : (sameEvent.length - 1);

        // Delete old siblings AFTER mainIdx, highest-first to keep
        // indices stable as we go.
        for (var j = sameEvent.length - 1; j > mainIdx; j--) {
            var delBody = { structType: structType, nodeId: nodeId, event: eventName, index: j };
            if (pageName) delBody.pageName = pageName;
            try {
                await fetch('/management/deleteInteraction', {
                    method: 'DELETE',
                    headers: {
                        'Authorization': 'Bearer ' + PreviewConfig.authToken,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(delBody),
                });
            } catch (e) {
                console.warn('[PreviewJsInteractions] failed to delete sibling action at index ' + j, e);
            }
        }

        // Add new siblings in picker-state order. Read from the DOM
        // (truth source) instead of the cached state — see
        // _collectActionsFromDom for the rationale.
        var actionsToSave = _collectActionsFromDom();
        for (var k = 0; k < actionsToSave.length; k++) {
            var action = actionsToSave[k];
            if (!action || !action.verb) continue;
            var actionParams = (action.paramValues || [])
                .map(function(v) { return v == null ? '' : String(v); })
                .filter(function(v) { return v.trim() !== ''; });

            var addBody = {
                structType: structType,
                nodeId:     nodeId,
                event:      eventName,
                function:   action.verb,
                params:     actionParams,
            };
            if (pageName) addBody.pageName = pageName;

            try {
                await fetch('/management/addInteraction', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + PreviewConfig.authToken,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(addBody),
                });
            } catch (e) {
                console.warn('[PreviewJsInteractions] failed to add post-fetch action #' + k, e);
            }
        }
    }

    /**
     * Read the existing interactions list and pre-fill the action
     * rows from sibling interactions on the same event that come
     * AFTER the current one being edited. Only call this on edit.
     *
     * Heuristic: any interaction with the same event whose index
     * is greater than the current one becomes a post-fetch action.
     * The runtime applies them in storage order anyway.
     */
    function _loadActionsForEdit(eventName, currentIndex) {
        postFetchActionsState = [];
        if (!currentInteractionsData || !Array.isArray(currentInteractionsData.interactions)) return;
        var sameEvent = currentInteractionsData.interactions
            .filter(function(i) { return i.event === eventName; });
        // currentIndex is the position WITHIN sameEvent. Take everything after.
        for (var i = currentIndex + 1; i < sameEvent.length; i++) {
            var it = sameEvent[i];
            if (!it || !it.function) continue;
            postFetchActionsState.push({
                verb: it.function,
                paramValues: Array.isArray(it.params) ? it.params.slice() : []
            });
        }
        renderActionsList();
    }

    /**
     * Read the Advanced-fold state into a plain options object used by
     * both updatePreview and handleSave. Centralises the parsing so
     * the two stay in sync.
     *
     * Returns: { kwargs: [["key", "value"], ...], errors: [] }
     */
    function _collectAdvancedKwargs() {
        var kwargs = [];
        var errors = [];

        // Toast keys — silent overrides any picker value.
        var successSilent = jsFormToastSuccessSilent?.checked;
        var successKey = jsFormToastSuccessPicker
            ? (jsFormToastSuccessPicker.getValue() || '').trim()
            : '';
        if (successSilent) {
            kwargs.push(['silent', 'true']);
        } else if (successKey) {
            kwargs.push(['toastSuccessKey', successKey]);
        }

        var errorSilent = jsFormToastErrorSilent?.checked;
        var errorKey = jsFormToastErrorPicker
            ? (jsFormToastErrorPicker.getValue() || '').trim()
            : '';
        // Note: `silent` in qs.js opts out of BOTH success and error
        // toasts. Per-side silent would need a separate runtime flag —
        // for the MVP, "error silent" reuses the same kwarg. If only
        // one side is silent, the other side's text gets the toast
        // logic via the explicit Key. If both sides are silent, single
        // 'silent=true' is enough.
        if (errorSilent && !successSilent) {
            // Edge case: error-only silent. Cleanest: still emit
            // 'silent=true' (suppresses both). The user wanted to
            // suppress error toast; we may suppress success too. Flag
            // as a known limitation for now.
            kwargs.push(['silent', 'true']);
        } else if (!errorSilent && errorKey) {
            kwargs.push(['toastErrorKey', errorKey]);
        }

        return { kwargs: kwargs, errors: errors };
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

        var inputType = arg.inputType || 'text';

        // eventArg is auto-injected (no UI). Hide the entire row so it doesn't
        // take up vertical space; the hidden form-input inside is still picked
        // up by the param-collection sites at the correct index.
        if (usePicker && inputType === 'eventArg') {
            row.style.display = 'none';
            row.appendChild(createEventArgInput(arg, paramIndex, updateFn));
            return row;
        }

        var label = document.createElement('label');
        label.className = 'preview-contextual-js-form-label';
        label.textContent = arg.name || ('Param ' + (paramIndex + 1));
        if (arg.required !== false) label.innerHTML += ' <span class="required">*</span>';
        row.appendChild(label);

        if (inputType === 'store') {
            // State-store arg: a dropdown of the current page's stores.
            row.appendChild(_renderStoreArgSelect(arg, paramIndex, updateFn));
        } else if (usePicker && (inputType === 'selector' || inputType === 'class' || inputType === 'matchTarget')) {
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

    /** Store ids defined on the current page (for setState/fetchState args). */
    function _pageStoreIds() {
        return (ssCurrentStores && typeof ssCurrentStores === 'object') ? Object.keys(ssCurrentStores) : [];
    }

    /**
     * Render the `store` arg as a <select> of the page's stores. Carries the
     * `.preview-contextual-js-form-input` class so the param collectors pick it
     * up positionally. Auto-selects the first store so the arg is never empty.
     */
    function _renderStoreArgSelect(arg, paramIndex, updateFn) {
        var sel = document.createElement('select');
        sel.className = 'preview-contextual-js-form-input';
        sel.dataset.paramIndex = paramIndex;
        sel.dataset.paramName = arg.name || '';
        sel.dataset.ssRole = 'store';
        var ids = _pageStoreIds();
        if (ids.length === 0) {
            var none = document.createElement('option');
            none.value = '';
            none.textContent = PreviewConfig.i18n?.stateStoreNonePage || 'No stores on this page';
            sel.appendChild(none);
            sel.disabled = true;
        } else {
            ids.forEach(function(id) {
                var o = document.createElement('option');
                o.value = id;
                o.textContent = id;
                sel.appendChild(o);
            });
            sel.value = ids[0];
        }
        sel.addEventListener('change', updateFn);
        return sel;
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
        toggle.innerHTML = QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.chevronRight, 14, 'preview-contextual-js-form-advanced-chevron') +
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
     * Render the Details panel below the function <select>: full description
     * + example. Hidden when no function is selected.
     */
    function renderFunctionDetails(selectedOption) {
        if (!jsFormFunctionDetails) return;
        const desc = selectedOption?.dataset?.description || '';
        const example = selectedOption?.dataset?.example || '';
        if (!selectedOption || !selectedOption.value || (!desc && !example)) {
            jsFormFunctionDetails.hidden = true;
            jsFormFunctionDetails.innerHTML = '';
            return;
        }
        const i18n = PreviewConfig.i18n || {};
        const exampleLabel = i18n.functionExample || 'Example';
        const parts = [];
        if (desc) {
            const p = document.createElement('p');
            p.className = 'preview-contextual-js-form-details__desc';
            p.textContent = desc;
            parts.push(p);
        }
        if (example) {
            const wrap = document.createElement('div');
            const label = document.createElement('span');
            label.className = 'preview-contextual-js-form-details__example-label';
            label.textContent = exampleLabel;
            const code = document.createElement('code');
            code.className = 'preview-contextual-js-form-details__example';
            code.textContent = example;
            wrap.appendChild(label);
            wrap.appendChild(code);
            parts.push(wrap);
        }
        jsFormFunctionDetails.innerHTML = '';
        parts.forEach(el => jsFormFunctionDetails.appendChild(el));
        jsFormFunctionDetails.hidden = false;
    }

    /**
     * Handle function dropdown change
     */
    function handleFunctionChange() {
        const selectedOption = jsFormFunction?.options[jsFormFunction.selectedIndex];
        const args = selectedOption?.dataset?.args ? JSON.parse(selectedOption.dataset.args) : [];

        renderFunctionDetails(selectedOption);

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
     * Plain-English tooltips per event name. Sourced from the admin
     * translation file (preview.eventTooltips), with the inline map below
     * as a hard-coded EN fallback when the i18n bundle is missing the key.
     */
    const EVENT_TOOLTIPS_FALLBACK = {
        // Common
        onclick:        'When the user clicks the element',
        ondblclick:     'When the user double-clicks the element',
        onmouseenter:   'When the cursor enters the element',
        onmouseleave:   'When the cursor leaves the element',
        onfocus:        'When the element receives keyboard focus',
        onblur:         'When the element loses keyboard focus',
        onkeydown:      'When a key is pressed down while the element is focused',
        onkeyup:        'When a key is released while the element is focused',
        // Tag-specific (Common bucket per tag)
        onsubmit:       'When the form is submitted',
        onreset:        'When the form is reset',
        oninput:        'Every time the value changes (every keystroke)',
        onchange:       'When the value changes and the field loses focus',
        ontoggle:       'When a <details> element is opened or closed',
        onplay:         'When media starts playing',
        onpause:        'When media is paused',
        onended:        'When media playback ends',
        onvolumechange: 'When the media volume changes',
        ontimeupdate:   'While media is playing (fires repeatedly)',
        onload:         'When the element finishes loading',
        onerror:        'When the element fails to load',
        onresize:       'When the window is resized',
        onscroll:       'When the user scrolls',
        // Less common
        onmousemove:    'When the cursor moves over the element',
        onmousedown:    'When a mouse button is pressed over the element',
        onmouseup:      'When a mouse button is released over the element',
        // Advanced
        oncontextmenu:  'When the right-click / context menu is requested',
        ontouchstart:   'When a finger touches the element',
        ontouchend:     'When a finger is lifted off the element',
        ontouchmove:    'When a finger moves across the element',
        onfocusin:      'Focus entered the element or one of its children (bubbles)',
        onfocusout:     'Focus left the element or one of its children (bubbles)',
        onpaste:        'When the user pastes content into the field',
        oncopy:         'When the user copies content from the field',
        oncut:          'When the user cuts content from the field'
    };

    function eventTooltip(ev) {
        const i18nMap = (PreviewConfig.i18n && PreviewConfig.i18n.eventTooltips) || {};
        return i18nMap[ev] || EVENT_TOOLTIPS_FALLBACK[ev] || ev;
    }

    /**
     * Build the bucketed shape from a flat list when the API only returned
     * the legacy availableEvents field. Lets the picker keep working against
     * a pre-beta.6 backend (or a stale cache).
     */
    function bucketsFromFlat(flat) {
        return { common: Array.isArray(flat) ? flat.slice() : [], lessCommon: [], advanced: [] };
    }

    /**
     * Populate event dropdown as three <optgroup>s: Common / Less common / Advanced.
     * Each <option> carries a `title` attribute for native browser tooltips.
     */
    function populateEventDropdown() {
        if (!jsFormEvent) return;

        const i18n = PreviewConfig.i18n || {};
        const tag = currentJsContext?.tag || 'element';
        const buckets = currentAvailableEventsGrouped || bucketsFromFlat(currentAvailableEvents);

        jsFormEvent.innerHTML = '<option value="">' + (i18n.selectEvent || 'Select event...') + '</option>';

        const groups = [
            { key: 'common',     label: (i18n.eventsCommonFor || 'Common for') + ' <' + tag + '>' },
            { key: 'lessCommon', label: i18n.eventsLessCommon || 'Less common' },
            { key: 'advanced',   label: i18n.eventsAdvanced || 'Advanced' }
        ];

        groups.forEach(g => {
            const list = buckets[g.key] || [];
            if (list.length === 0) return;
            const optgroup = document.createElement('optgroup');
            optgroup.label = g.label;
            list.forEach(ev => {
                const option = document.createElement('option');
                option.value = ev;
                option.textContent = ev;
                option.title = eventTooltip(ev);
                optgroup.appendChild(option);
            });
            jsFormEvent.appendChild(optgroup);
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
                // Show only the function name (beta.6: full description lives
                // in the Details panel below the <select>, no truncation needed).
                option.textContent = fn.name;
                option.dataset.args = JSON.stringify(fn.args || []);
                option.dataset.description = fn.description || '';
                option.dataset.example = fn.example || '';
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
     * Render the `event` first-arg.
     *
     * QS.filter (and similar) need the literal `event` keyword as their first
     * arg so they can read `event.target.value`. There's no useful alternative
     * — the string-first-arg path in qs.js shifts all the args and reads from
     * `document.activeElement`, which doesn't help anyone.
     *
     * So we don't expose this arg in the UI at all: just inject a hidden input
     * carrying the literal `event` so the collection sites (which read all
     * `.preview-contextual-js-form-input` in DOM order) still see the right
     * value at the right index.
     */
    function createEventArgInput(arg, paramIndex, updateFn) {
        const wrapper = document.createElement('div');
        wrapper.style.display = 'none';

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.className = 'preview-contextual-js-form-input';
        hidden.dataset.paramIndex = paramIndex;
        hidden.dataset.paramName = arg.name || '';
        hidden.dataset.inputType = 'eventArg';
        hidden.value = 'event';

        wrapper.appendChild(hidden);
        return wrapper;
    }
    
    /**
     * Create a searchable picker input with dropdown suggestions.
     *
     * For inputType === 'matchTarget', renders a GitHub-style tokenfield:
     * each committed selector is a chip; the trailing visible <input> is for
     * typing the next selector. Comma or Enter commits the typed selector as
     * a chip; Backspace at empty typing area deletes the previous chip. A
     * hidden <input> carrying the joined value (the saved param) is what
     * handleSave / updatePreview read via the .preview-contextual-js-form-input
     * selector.
     *
     * For other inputTypes, the visible <input> is itself the form input.
     */
    function createSearchablePicker(inputType, arg, index) {
        const wrapper = document.createElement('div');
        wrapper.className = 'preview-js-picker-wrapper';

        const isTokenMode = inputType === 'matchTarget';

        // ---- Token-mode helpers ----
        function isSelectorPart(part) { return /^[.#> ]/.test(part); }
        function parseSelectorList(str) {
            return (str || '').split(',').map(s => s.trim()).filter(Boolean);
        }
        function isSelectorListValue(str) {
            const parts = parseSelectorList(str);
            return parts.length > 0 && parts.every(isSelectorPart);
        }

        // ---- Build the visible "input" element(s). ----
        // tokenfield: the bordered visual input for matchTarget mode (chips + typing).
        // input: the actual saved-value field (hidden in token mode, visible otherwise).
        // typingInput: the inline typing field inside the tokenfield (token mode only).
        let tokenfield = null;
        let typingInput = null;
        const input = document.createElement('input');
        input.dataset.paramIndex = index;
        input.dataset.paramName = arg.name || '';
        input.dataset.inputType = inputType;
        input.autocomplete = 'off';
        input.className = 'preview-contextual-js-form-input preview-js-picker-input';
        input.type = 'text';
        input.placeholder = arg.description || arg.name || '';

        if (isTokenMode) {
            // In token mode the form-input element is hidden; the tokenfield is what the user sees.
            input.type = 'hidden';
            input.classList.remove('preview-js-picker-input');

            tokenfield = document.createElement('div');
            tokenfield.className = 'preview-js-picker-tokenfield';

            typingInput = document.createElement('input');
            typingInput.type = 'text';
            typingInput.className = 'preview-js-picker-typing';
            typingInput.autocomplete = 'off';
            typingInput.placeholder = PreviewConfig.i18n?.matchTargetHint || 'textContent, .child-class, or data-attr';
        } else if (inputType === 'selector') {
            input.placeholder = PreviewConfig.i18n?.selectorOrThis || 'Selector or "this"';
        } else if (inputType === 'class') {
            input.placeholder = PreviewConfig.i18n?.searchClass || 'Search class...';
        }

        // Initial value
        if (arg.default !== undefined && arg.default !== null) {
            input.value = arg.default;
        }

        // The element the user types into (and we attach key/focus events to).
        const typeEl = isTokenMode ? typingInput : input;

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
            } else if (inputType === 'matchTarget') {
                // Suggestions for QS.filter's matchAttr arg.
                // 1) Special textContent entry (the default).
                // 2) Child CSS selectors (.class / #id) — match a child's text.
                // 3) data-* attributes discovered in the iframe DOM.
                const seenValues = new Set();
                items.push({ value: 'textContent', label: 'textContent (full text)', type: 'special' });
                seenValues.add('textContent');
                
                pageStructureClasses.forEach(cls => {
                    const sel = '.' + cls;
                    if (!seenValues.has(sel)) {
                        items.push({ value: sel, label: sel + ' (child text)', type: 'dom' });
                        seenValues.add(sel);
                    }
                });
                
                (categorizedSelectors.ids || []).forEach(s => {
                    var clean = stripPseudoFromSelector(s.selector);
                    if (clean && !seenValues.has(clean)) {
                        items.push({ value: clean, label: clean + ' (child text)', type: 'id' });
                        seenValues.add(clean);
                    }
                });
                
                (categorizedSelectors.classes || []).forEach(s => {
                    var clean = stripPseudoFromSelector(s.selector);
                    if (clean && !seenValues.has(clean)) {
                        items.push({ value: clean, label: clean + ' (child text)', type: 'class' });
                        seenValues.add(clean);
                    }
                });
                
                // Collect data-* attribute names from the iframe DOM (skip qs-internal ones).
                try {
                    const iframe = document.getElementById('preview-iframe');
                    const iframeDoc = iframe && (iframe.contentDocument || iframe.contentWindow?.document);
                    if (iframeDoc) {
                        const dataAttrs = new Set();
                        iframeDoc.querySelectorAll('[data-qs-node]').forEach(el => {
                            for (const a of el.attributes) {
                                if (a.name.startsWith('data-') && !a.name.startsWith('data-qs-')) {
                                    dataAttrs.add(a.name);
                                }
                            }
                        });
                        Array.from(dataAttrs).sort().forEach(name => {
                            if (!seenValues.has(name)) {
                                items.push({ value: name, label: name + ' (attribute)', type: 'attr' });
                                seenValues.add(name);
                            }
                        });
                    }
                } catch (e) { /* iframe inaccessible — skip */ }
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
                                 item.type === 'attr' ? '[ ]' : 
                                 item.type === 'tag' ? '<>' : '';
                option.innerHTML = `<span class="preview-js-picker-type">${typeIcon}</span><span>${item.label}</span>`;
                
                // Prevent input blur on click — otherwise the typing buffer
                // would be committed as a chip *before* applyPickedValue runs,
                // resulting in two chips (the typed filter + the picked value).
                option.addEventListener('mousedown', (e) => { e.preventDefault(); });
                option.addEventListener('click', () => {
                    applyPickedValue(item.value, item.type);
                    dropdown.style.display = 'none';
                    updatePreview();
                });
                
                dropdown.appendChild(option);
            });
            
            dropdown.style.display = '';
        }

        // ---- Token-mode state & rendering ----
        // `chips` is the source of truth for committed selectors. The hidden
        // input.value is rebuilt from chips (or, when there are no chips, from
        // the typing buffer for free-text values like textContent / data-foo).
        // The typing input is purely a buffer until commit (comma, Enter, blur,
        // or dropdown click for non-selector picks). Dropdown clicks for
        // selector picks DISCARD the typing buffer (it was being used as a
        // search filter, not a value).
        let chips = [];

        function syncHiddenValue() {
            if (!isTokenMode) return;
            if (chips.length > 0) {
                input.value = chips.join(', ');
            } else {
                input.value = typingInput.value;
            }
        }

        function renderChips() {
            if (!isTokenMode) return;
            // Clear all chip elements (keep typingInput).
            Array.from(tokenfield.querySelectorAll('.preview-js-picker-chip')).forEach(c => c.remove());
            chips.forEach((part, i) => {
                const chip = document.createElement('span');
                chip.className = 'preview-js-picker-chip';
                const label = document.createElement('span');
                label.className = 'preview-js-picker-chip-label';
                label.textContent = part;
                const close = document.createElement('button');
                close.type = 'button';
                close.className = 'preview-js-picker-chip-remove';
                close.setAttribute('aria-label', 'Remove ' + part);
                close.textContent = '✕';
                close.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    chips.splice(i, 1);
                    syncHiddenValue();
                    renderChips();
                    updatePreview();
                    typingInput.focus();
                });
                chip.appendChild(label);
                chip.appendChild(close);
                tokenfield.insertBefore(chip, typingInput);
            });
        }

        // Push a selector to chips (no duplicates). Returns true if added.
        function pushChip(value) {
            if (!value || !isSelectorPart(value)) return false;
            if (chips.indexOf(value) !== -1) return false;
            chips.push(value);
            return true;
        }

        // Commit the current typing buffer as a chip when it's a selector.
        // For non-selector text, leave it in the buffer (it stays as the value
        // through syncHiddenValue). Returns true if a chip was added.
        function commitTyping() {
            if (!isTokenMode) return false;
            const raw = typingInput.value.trim().replace(/,+$/, '').trim();
            if (!raw) return false;
            if (isSelectorPart(raw)) {
                const added = pushChip(raw);
                typingInput.value = '';
                syncHiddenValue();
                renderChips();
                updatePreview();
                return added;
            }
            // Non-selector: keep as the bare value (no chips).
            chips = [];
            // typingInput.value already holds it; just sync.
            syncHiddenValue();
            renderChips();
            updatePreview();
            return false;
        }

        // Initialize chips from a pre-existing input.value (prefill from edit).
        function initFromHiddenValue() {
            if (!isTokenMode) return;
            const v = input.value || '';
            if (isSelectorListValue(v)) {
                chips = parseSelectorList(v);
                typingInput.value = '';
            } else {
                chips = [];
                typingInput.value = v;
            }
            syncHiddenValue();
            renderChips();
        }

        function applyPickedValue(value, type) {
            if (isTokenMode) {
                if (isSelectorPart(value)) {
                    // DISCARD the typing buffer (it was a filter, not a value).
                    pushChip(value);
                    typingInput.value = '';
                } else {
                    // Non-selector pick (textContent / data-attr): exclusive value, clear chips.
                    chips = [];
                    typingInput.value = value;
                }
                syncHiddenValue();
                renderChips();
                typingInput.focus();
            } else {
                input.value = value;
            }
        }
        
        // Wire focus/input/blur/keys on the element the user actually types into.
        typeEl.addEventListener('focus', () => renderDropdown(typeEl.value));
        typeEl.addEventListener('input', () => {
            if (isTokenMode) {
                // If user typed a comma, commit-and-continue: split, push valid
                // selector parts to chips, keep the trailing fragment in buffer.
                if (typingInput.value.indexOf(',') !== -1) {
                    const segs = typingInput.value.split(',');
                    const trailing = segs.pop();
                    segs.map(s => s.trim()).filter(Boolean).forEach(s => pushChip(s));
                    typingInput.value = trailing;
                    renderChips();
                }
                syncHiddenValue();
                updatePreview();
                renderDropdown(typingInput.value);
            } else {
                renderDropdown(typeEl.value);
                updatePreview();
            }
        });
        typeEl.addEventListener('blur', () => {
            if (isTokenMode) {
                commitTyping();
            }
            setTimeout(() => { dropdown.style.display = 'none'; }, 200);
        });
        
        typeEl.addEventListener('keydown', (e) => {
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
                    applyPickedValue(current.dataset.value, current.dataset.type);
                    dropdown.style.display = 'none';
                    updatePreview();
                } else if (isTokenMode) {
                    if (commitTyping()) {
                        dropdown.style.display = 'none';
                    }
                }
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
            } else if (e.key === 'Backspace' && isTokenMode && typingInput.value === '') {
                if (chips.length > 0) {
                    chips.pop();
                    syncHiddenValue();
                    renderChips();
                    updatePreview();
                    e.preventDefault();
                }
            }
        });

        // Clicking anywhere on the tokenfield focuses the typing input.
        if (isTokenMode) {
            tokenfield.addEventListener('click', (e) => {
                if (e.target === tokenfield) typingInput.focus();
            });
            // editInteraction prefill: when input.value is set programmatically
            // and 'input' is dispatched on the hidden field, rebuild chips.
            input.addEventListener('input', initFromHiddenValue);
        }

        // Final assembly.
        if (isTokenMode) {
            tokenfield.appendChild(typingInput);
            wrapper.appendChild(tokenfield);
            wrapper.appendChild(input); // hidden form field
            initFromHiddenValue();
        } else {
            wrapper.appendChild(input);
        }
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
            const mode = getApiMode();
            const method = getEffectiveMethod();
            const bodyAllowed = method !== 'GET' && method !== 'DELETE';
            const bodySelector = (jsFormApiBody?.value || '').trim();

            let preview;
            let saveOk = false;

            // All kwargs that go at the tail of the call.
            // Order: path params first (semantic — they describe the
            // request), then Advanced-fold (toast/silent/onSuccess/etc.).
            const pathKwargs = _collectPathParamKwargs();
            const advanced = _collectAdvancedKwargs();
            const allKwargs = pathKwargs.concat(advanced.kwargs);
            const advancedTail = allKwargs.map(function(kv) {
                return kv[0] + '=' + kv[1];
            }).join(',');

            if (mode === 'direct') {
                const url = (jsFormApiUrl?.value || '').trim();
                if (!url) {
                    if (jsPreviewCode) jsPreviewCode.textContent = '-';
                    if (jsFormSave) jsFormSave.disabled = true;
                    return;
                }
                // Direct URL: {{call:fetch:METHOD,URL[,body=#x][,kwargs]}}
                preview = '{{call:fetch:' + method + ',' + url;
                if (bodyAllowed && bodySelector) preview += ',body=' + bodySelector;
                if (advancedTail) preview += ',' + advancedTail;
                preview += '}}';
                saveOk = advanced.errors.length === 0;
            } else {
                const apiName = jsFormApi?.value || '';
                const endpointName = jsFormEndpoint?.value || '';
                if (!apiName || !endpointName) {
                    if (jsPreviewCode) jsPreviewCode.textContent = '-';
                    if (jsFormSave) jsFormSave.disabled = true;
                    return;
                }
                // Registry: {{call:fetch:@api/endpoint[,body=#x][,kwargs]}}
                preview = '{{call:fetch:@' + apiName + '/' + endpointName;
                if (bodyAllowed && bodySelector) preview += ',body=' + bodySelector;
                if (advancedTail) preview += ',' + advancedTail;
                preview += '}}';
                saveOk = advanced.errors.length === 0;
            }

            // Append post-fetch actions to the preview (each starts with ';').
            preview += _previewPostFetchActions();

            if (jsPreviewCode) jsPreviewCode.textContent = preview;
            if (jsFormSave) jsFormSave.disabled = !eventName || !saveOk;
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
            const mode = getApiMode();
            const method = getEffectiveMethod();
            const bodyAllowed = method !== 'GET' && method !== 'DELETE';
            const bodySelector = (jsFormApiBody?.value || '').trim();

            fnName = 'fetch';
            params = [];
            let apiName = '';
            let endpointName = '';

            if (mode === 'direct') {
                const url = (jsFormApiUrl?.value || '').trim();
                if (!eventName || !url) {
                    if (showToastFn) {
                        showToastFn(PreviewConfig.i18n?.selectEventApiUrl || 'Please select event and enter a URL', 'error');
                    }
                    return;
                }
                params.push(method);
                params.push(url);
            } else {
                apiName = jsFormApi?.value || '';
                endpointName = jsFormEndpoint?.value || '';
                if (!eventName || !apiName || !endpointName) {
                    if (showToastFn) {
                        showToastFn(PreviewConfig.i18n?.selectEventApiEndpoint || 'Please select event, API, and endpoint', 'error');
                    }
                    return;
                }
                params.push('@' + apiName + '/' + endpointName);
            }

            if (bodyAllowed && bodySelector) {
                params.push('body=' + bodySelector);
            }

            // Append path-param kwargs (in path-declaration order).
            _collectPathParamKwargs().forEach(function(kv) {
                params.push(kv[0] + '=' + kv[1]);
            });

            // Append Advanced-fold kwargs (toast/silent/onSuccess/onError).
            const advancedSave = _collectAdvancedKwargs();
            if (advancedSave.errors.length > 0) {
                if (showToastFn) showToastFn(advancedSave.errors[0], 'error');
                return;
            }
            advancedSave.kwargs.forEach(function(kv) {
                params.push(kv[0] + '=' + kv[1]);
            });

            // Save response bindings if any (registry mode only — direct
            // URL has no endpoint to attach them to).
            if (mode === 'registry') {
                var bindings = collectBindings(elBindingsRows);
                if (bindings.length > 0) {
                    var saved = await saveResponseBindings(apiName, endpointName, bindings);
                    if (!saved) return; // abort if bindings save failed
                }
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
            
            // Post-fetch actions: only relevant when the main interaction
            // is a fetch (API mode). Persist them as sibling interactions
            // on the same event so the renderer compiles them into the
            // chain. On Edit, the truth is the picker's state — old
            // siblings are deleted, current state re-added in order.
            if (actionType === 'api') {
                await _persistPostFetchActions({
                    eventName: eventName,
                    isEdit: isEdit,
                    structType: body.structType,
                    nodeId: body.nodeId,
                    pageName: body.pageName,
                    mainIndex: isEdit ? body.index : null,
                });
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
        // State stores are page-scoped too — hide on components.
        if (ssContainer) {
            ssContainer.style.display = pageName ? '' : 'none';
        }
        // Reset forms when page changes
        hidePageEventForm();
        hideStateStoreForm();
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
            // Split nested routes (e.g. "documentation/commands") so each segment
            // is encoded individually — encoding the whole string would turn "/" into
            // "%2F", collapsing the path into a single segment the router can't match.
            var url = '/management/getPageEvents/' + currentPageName.split('/').map(encodeURIComponent).join('/');
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
                    QuickSiteUtils.iconClose(14) +
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
    
    // ==================== State Stores ====================

    // Direction options for a store field (glyphs match the §8 UX sketch;
    // the words are translatable via PreviewConfig.i18n).
    var SS_DIRECTIONS = [
        { value: 'request',  label: '→ ' + (PreviewConfig.i18n?.stateStoreDirRequest  || 'request (sent)') },
        { value: 'response', label: '← ' + (PreviewConfig.i18n?.stateStoreDirResponse || 'response (received)') },
        { value: 'both',     label: '⇄ ' + (PreviewConfig.i18n?.stateStoreDirBoth     || 'both (sent + received)') }
    ];

    /**
     * Toggle expand/collapse of the state stores section.
     */
    function toggleStateStores() {
        ssExpanded = !ssExpanded;
        if (ssBody) ssBody.style.display = ssExpanded ? '' : 'none';
        if (ssContainer) {
            ssContainer.classList.toggle('preview-contextual-js-page-events--expanded', ssExpanded);
        }
    }

    function _ssEmpty(text) {
        var p = document.createElement('p');
        p.className = 'preview-contextual-js-empty';
        p.textContent = text;
        return p;
    }

    /**
     * Load the current page's state stores from the API and render them.
     */
    async function loadStateStores() {
        if (!currentPageName) return;
        try {
            var response = await fetch('/management/getStateStores', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + PreviewConfig.authToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ route: currentPageName })
            });
            if (!response.ok) throw new Error('Failed to fetch state stores');
            var result = await response.json();
            // getForRoute returns [] (array) when empty, or an object map otherwise.
            var stores = (result.data && result.data.stores) || {};
            ssCurrentStores = (stores && typeof stores === 'object' && !Array.isArray(stores)) ? stores : {};
            displayStateStores(ssCurrentStores);
        } catch (error) {
            console.error('[PreviewJsInteractions] Failed to load state stores:', error);
            if (ssList) { ssList.textContent = ''; ssList.appendChild(_ssEmpty('Failed to load state stores')); }
            if (ssCount) ssCount.textContent = '0';
        }
    }

    /**
     * Render the store list (one card per store).
     */
    function displayStateStores(stores) {
        if (!ssList) return;
        var ids = Object.keys(stores || {});
        if (ssCount) ssCount.textContent = String(ids.length);
        ssList.textContent = '';
        if (ids.length === 0) {
            ssList.appendChild(_ssEmpty(PreviewConfig.i18n?.noStateStores || 'No state stores yet.'));
            return;
        }
        ids.forEach(function(id) {
            ssList.appendChild(_renderStoreCard(id, stores[id]));
        });
    }

    /**
     * One store summary card: id badge + endpoint/field summary + edit/delete.
     */
    function _renderStoreCard(id, def) {
        var card = document.createElement('div');
        card.className = 'preview-contextual-js-page-events__item preview-contextual-js-state-stores__card';

        var badge = document.createElement('span');
        badge.className = 'preview-contextual-js-page-events__event-badge';
        badge.textContent = id;
        card.appendChild(badge);

        var code = document.createElement('code');
        code.className = 'preview-contextual-js-page-events__code';
        var fieldCount = (def && def.fields) ? Object.keys(def.fields).length : 0;
        var summary = (def && def.endpoint ? def.endpoint : '?') + ' · ' + fieldCount + 'f';
        if (def && def.fetchOnLoad) summary += ' · onload';
        code.textContent = summary;
        card.appendChild(code);

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'preview-contextual-js-page-events__delete';
        editBtn.title = PreviewConfig.i18n?.edit || 'Edit';
        editBtn.innerHTML = QuickSiteUtils.iconEdit(12);
        editBtn.addEventListener('click', function(e) { e.stopPropagation(); handleStateStoreEdit(id); });
        card.appendChild(editBtn);

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'preview-contextual-js-page-events__delete';
        delBtn.title = PreviewConfig.i18n?.delete || 'Delete';
        delBtn.innerHTML = QuickSiteUtils.iconClose(12);
        delBtn.addEventListener('click', function(e) { e.stopPropagation(); deleteStore(id); });
        card.appendChild(delBtn);

        return card;
    }

    /**
     * Delete one store (read-modify-write: send the route's full set minus it).
     */
    async function deleteStore(id) {
        if (!currentPageName) return;
        if (!confirm((PreviewConfig.i18n?.confirmDeleteStateStore || 'Delete state store "%s"?').replace('%s', id))) return;
        var stores = Object.assign({}, ssCurrentStores);
        delete stores[id];
        try {
            var response = await fetch('/management/setStateStores', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + PreviewConfig.authToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ route: currentPageName, stores: stores })
            });
            var result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Failed to delete');
            if (showToastFn) showToastFn(PreviewConfig.i18n?.stateStoreDeleted || 'State store deleted', 'success');
            await loadStateStores();
            if (reloadPreviewFn) reloadPreviewFn();
        } catch (error) {
            console.error('[PreviewJsInteractions] Delete state store error:', error);
            if (showToastFn) showToastFn('Error: ' + error.message, 'error');
        }
    }

    /**
     * Rebuild the wizard's API <select> from the loaded endpoint catalogue.
     */
    function _populateSsApiSelect() {
        if (!ssFormApi) return;
        ssFormApi.textContent = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = PreviewConfig.i18n?.selectApiPlaceholder || '-- Select API --';
        ssFormApi.appendChild(ph);
        var uniqueApis = [...new Set(availableApiEndpoints.map(function(ep) { return ep.api; }))];
        uniqueApis.forEach(function(apiName) {
            var o = document.createElement('option');
            o.value = apiName;
            o.textContent = apiName;
            ssFormApi.appendChild(o);
        });
    }

    /**
     * Populate the wizard's endpoint <select> for the chosen API.
     */
    function handleSsApiChange() {
        var apiName = ssFormApi ? ssFormApi.value : '';
        if (!ssFormEndpoint) return;
        ssFormEndpoint.textContent = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = PreviewConfig.i18n?.selectEndpointPlaceholder || '-- Select endpoint --';
        ssFormEndpoint.appendChild(ph);
        if (!apiName) {
            ssFormEndpoint.disabled = true;
            updateStateStorePreview();
            return;
        }
        var eps = availableApiEndpoints.filter(function(ep) { return ep.api === apiName; });
        eps.forEach(function(ep) {
            var o = document.createElement('option');
            o.value = ep.endpoint;
            o.textContent = ep.endpoint + ' (' + ep.method + ')';
            ssFormEndpoint.appendChild(o);
        });
        ssFormEndpoint.disabled = eps.length === 0;
        updateStateStorePreview();
    }

    /** The availableApiEndpoints entry for the wizard's current API+endpoint. */
    function _selectedSsEndpointData() {
        var api = ssFormApi ? ssFormApi.value : '';
        var ep = ssFormEndpoint ? ssFormEndpoint.value : '';
        if (!api || !ep) return null;
        return availableApiEndpoints.find(function(e) { return e.api === api && e.endpoint === ep; }) || null;
    }

    /**
     * Build a seed field set from the endpoint's declared schemas:
     *   request properties → request fields (carry schema default),
     *   response leaves     → response fields (from = dot-path),
     *   a name in both      → both.
     * Non-exhaustive + fully editable — a starting point, never an assumption.
     */
    function _seedFieldsFromEndpoint(epData) {
        var seeded = [];
        var byName = {};
        var VALID = /^[a-zA-Z_][\w-]*$/;

        var reqProps = (epData && epData.requestSchema && epData.requestSchema.properties) || {};
        Object.keys(reqProps).forEach(function(name) {
            if (!VALID.test(name)) return;
            var d = reqProps[name] || {};
            var entry = { name: name, dir: 'request' };
            if (d.default !== undefined) entry.default = d.default;
            byName[name] = entry;
            seeded.push(entry);
        });

        var respProps = (epData && epData.responseSchema && epData.responseSchema.properties) || {};
        var flat = _flattenSchema(respProps);
        Object.keys(flat).forEach(function(path) {
            var s = flat[path] || {};
            if (s.type === 'object' && s.properties) return;   // skip containers
            var leaf = path.split('.').pop();
            if (!VALID.test(leaf)) return;
            if (byName[leaf]) {
                byName[leaf].dir = 'both';
                byName[leaf].from = path;
                if (s.type === 'array') byName[leaf].append = false;
            } else {
                var entry = { name: leaf, dir: 'response', from: path };
                byName[leaf] = entry;
                seeded.push(entry);
            }
        });

        return seeded;
    }

    /**
     * Auto-seed the fields table from the selected endpoint's schema — but
     * only when the user hasn't entered any named field yet (never clobbers).
     */
    function _maybeAutoSeedFields() {
        if (!ssFieldsRows) return;
        if (Object.keys(collectSsFields()).length > 0) return;
        var ep = _selectedSsEndpointData();
        if (!ep) return;
        var seeded = _seedFieldsFromEndpoint(ep);
        if (seeded.length === 0) return;
        ssFieldsRows.textContent = '';
        seeded.forEach(function(f) { ssFieldsRows.appendChild(_renderSsFieldRow(f.name, f)); });
    }

    /** Endpoint select changed: seed fields from schema (if empty) + re-validate. */
    function handleSsEndpointChange() {
        _maybeAutoSeedFields();
        updateStateStorePreview();
    }

    /**
     * Build one editable field card (createElement; no innerHTML except icons).
     * `def` pre-fills the row when editing an existing store.
     */
    function _renderSsFieldRow(name, def) {
        def = def || {};
        var row = document.createElement('div');
        row.className = 'preview-contextual-js-state-stores__field';

        // Head: name + remove
        var head = document.createElement('div');
        head.className = 'preview-contextual-js-state-stores__field-head';
        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'preview-contextual-js-form-input ss-field-name';
        nameInput.placeholder = PreviewConfig.i18n?.stateStoreFieldName || 'field name';
        if (name) nameInput.value = name;
        nameInput.addEventListener('input', updateStateStorePreview);
        head.appendChild(nameInput);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'preview-contextual-js-page-events__delete ss-field-remove';
        removeBtn.title = PreviewConfig.i18n?.delete || 'Remove';
        removeBtn.innerHTML = QuickSiteUtils.iconClose(12);
        removeBtn.addEventListener('click', function() { row.remove(); updateStateStorePreview(); });
        head.appendChild(removeBtn);
        row.appendChild(head);

        // Direction
        var dirRow = document.createElement('div');
        dirRow.className = 'preview-contextual-js-form-row';
        var dirSelect = document.createElement('select');
        dirSelect.className = 'preview-contextual-js-form-select ss-field-dir';
        SS_DIRECTIONS.forEach(function(d) {
            var o = document.createElement('option');
            o.value = d.value;
            o.textContent = d.label;
            dirSelect.appendChild(o);
        });
        dirSelect.value = def.dir || 'request';
        dirRow.appendChild(dirSelect);
        row.appendChild(dirRow);

        // init + default (request / both)
        var initRow = document.createElement('div');
        initRow.className = 'preview-contextual-js-form-row ss-field-init-row';
        var initInput = document.createElement('input');
        initInput.type = 'text';
        initInput.className = 'preview-contextual-js-form-input ss-field-init';
        initInput.placeholder = PreviewConfig.i18n?.stateStoreInitPlaceholder || 'init: literal · query:x · localStorage:x';
        if (def.init !== undefined && def.init !== null) initInput.value = String(def.init);
        initInput.addEventListener('input', updateStateStorePreview);
        var defaultInput = document.createElement('input');
        defaultInput.type = 'text';
        defaultInput.className = 'preview-contextual-js-form-input ss-field-default';
        defaultInput.placeholder = PreviewConfig.i18n?.stateStoreDefaultPlaceholder || 'default';
        if (def.default !== undefined && def.default !== null) defaultInput.value = String(def.default);
        defaultInput.addEventListener('input', updateStateStorePreview);
        initRow.appendChild(initInput);
        initRow.appendChild(defaultInput);
        row.appendChild(initRow);

        // from + append (response / both)
        var fromRow = document.createElement('div');
        fromRow.className = 'preview-contextual-js-form-row ss-field-from-row';
        var fromInput = document.createElement('input');
        fromInput.type = 'text';
        fromInput.className = 'preview-contextual-js-form-input ss-field-from';
        fromInput.placeholder = PreviewConfig.i18n?.stateStoreFromPlaceholder || 'from: response path e.g. data.items';
        if (def.from) fromInput.value = String(def.from);
        fromInput.addEventListener('input', updateStateStorePreview);
        var appendLabel = document.createElement('label');
        appendLabel.className = 'ss-field-append';
        var appendCb = document.createElement('input');
        appendCb.type = 'checkbox';
        appendCb.className = 'ss-field-append-cb';
        if (def.append) appendCb.checked = true;
        appendCb.addEventListener('change', updateStateStorePreview);
        var appendSpan = document.createElement('span');
        appendSpan.textContent = PreviewConfig.i18n?.stateStoreAppend || 'append (grow list)';
        appendLabel.appendChild(appendCb);
        appendLabel.appendChild(appendSpan);
        fromRow.appendChild(fromInput);
        fromRow.appendChild(appendLabel);
        row.appendChild(fromRow);

        // Direction toggles which sub-rows are visible.
        function syncRow() {
            var dir = dirSelect.value;
            initRow.style.display = (dir === 'request' || dir === 'both') ? '' : 'none';
            fromRow.style.display = (dir === 'response' || dir === 'both') ? '' : 'none';
        }
        dirSelect.addEventListener('change', function() { syncRow(); updateStateStorePreview(); });
        syncRow();

        return row;
    }

    /**
     * Coerce a default value typed as text into the natural JSON scalar
     * (number / boolean) so `page: default 1` stores 1, not "1".
     */
    function _coerceScalar(str) {
        if (/^-?\d+$/.test(str)) return parseInt(str, 10);
        if (/^-?\d*\.\d+$/.test(str)) return parseFloat(str);
        if (str === 'true') return true;
        if (str === 'false') return false;
        return str;
    }

    /**
     * Read every field card into the { name => def } map the backend expects.
     */
    function collectSsFields() {
        var fields = {};
        if (!ssFieldsRows) return fields;
        var rows = ssFieldsRows.querySelectorAll('.preview-contextual-js-state-stores__field');
        rows.forEach(function(row) {
            var name = (row.querySelector('.ss-field-name')?.value || '').trim();
            if (!name) return;
            var dir = row.querySelector('.ss-field-dir')?.value || 'request';
            var fdef = { dir: dir };
            if (dir === 'request' || dir === 'both') {
                var init = (row.querySelector('.ss-field-init')?.value || '').trim();
                if (init !== '') fdef.init = init;
                var dflt = (row.querySelector('.ss-field-default')?.value || '').trim();
                if (dflt !== '') fdef.default = _coerceScalar(dflt);
            }
            if (dir === 'response' || dir === 'both') {
                var from = (row.querySelector('.ss-field-from')?.value || '').trim();
                if (from !== '') fdef.from = from;
                if (row.querySelector('.ss-field-append-cb')?.checked) fdef.append = true;
            }
            fields[name] = fdef;
        });
        return fields;
    }

    /**
     * Refresh the one-line preview and the Save button's enabled state.
     */
    function updateStateStorePreview() {
        var id = (ssFormId?.value || '').trim();
        var api = ssFormApi?.value || '';
        var ep = ssFormEndpoint?.value || '';
        var fieldCount = Object.keys(collectSsFields()).length;
        var idValid = /^[a-zA-Z][\w-]*$/.test(id);
        var valid = idValid && !!api && !!ep && fieldCount > 0;
        if (ssFormPreview) {
            ssFormPreview.textContent = valid
                ? (id + ': @' + api + '/' + ep + ' · ' + fieldCount + ' field' + (fieldCount === 1 ? '' : 's'))
                : '-';
        }
        if (ssFormSave) ssFormSave.disabled = !valid;
    }

    /**
     * Open the wizard to create a new store.
     */
    async function handleStateStoreAdd() {
        ssEditingId = null;
        // Always refetch so admin-side API edits surface immediately (avoids
        // the stale module-cache bug fixed for the interaction forms in Tier 1).
        await fetchApiEndpoints();
        _populateSsApiSelect();
        if (ssFormId) ssFormId.value = '';
        if (ssFormApi) ssFormApi.value = '';
        handleSsApiChange();   // resets endpoint select to placeholder/disabled
        if (ssFormFetchOnLoad) ssFormFetchOnLoad.checked = false;
        if (ssFieldsRows) { ssFieldsRows.textContent = ''; ssFieldsRows.appendChild(_renderSsFieldRow()); }
        if (ssForm) ssForm.style.display = '';
        if (ssAddBtn?.parentElement) ssAddBtn.parentElement.style.display = 'none';
        updateStateStorePreview();
    }

    /**
     * Open the wizard pre-filled to edit an existing store.
     */
    async function handleStateStoreEdit(id) {
        var def = ssCurrentStores[id];
        if (!def) return;
        ssEditingId = id;
        await fetchApiEndpoints();   // always refresh (see handleStateStoreAdd note)
        _populateSsApiSelect();
        if (ssFormId) ssFormId.value = id;
        var m = /^@([^/]+)\/(.+)$/.exec(def.endpoint || '');
        if (m && ssFormApi) {
            ssFormApi.value = m[1];
            handleSsApiChange();
            if (ssFormEndpoint) ssFormEndpoint.value = m[2];
        }
        if (ssFormFetchOnLoad) ssFormFetchOnLoad.checked = !!def.fetchOnLoad;
        if (ssFieldsRows) {
            ssFieldsRows.textContent = '';
            var fields = def.fields || {};
            var names = Object.keys(fields);
            names.forEach(function(fname) { ssFieldsRows.appendChild(_renderSsFieldRow(fname, fields[fname])); });
            if (names.length === 0) ssFieldsRows.appendChild(_renderSsFieldRow());
        }
        if (ssForm) ssForm.style.display = '';
        if (ssAddBtn?.parentElement) ssAddBtn.parentElement.style.display = 'none';
        updateStateStorePreview();
    }

    /**
     * Hide the wizard and restore the New store button.
     */
    function hideStateStoreForm() {
        if (ssForm) ssForm.style.display = 'none';
        if (ssAddBtn?.parentElement) ssAddBtn.parentElement.style.display = '';
        ssEditingId = null;
    }

    /**
     * Save the store (create / edit / rename). setStateStores replaces the
     * whole route store-set, so we send the loaded set with this store applied.
     */
    async function handleStateStoreSave() {
        if (!currentPageName) return;
        var id = (ssFormId?.value || '').trim();
        if (!/^[a-zA-Z][\w-]*$/.test(id)) {
            if (showToastFn) showToastFn(PreviewConfig.i18n?.stateStoreInvalidId || 'Invalid store id (letters, digits, _ or -)', 'error');
            return;
        }
        var api = ssFormApi?.value || '';
        var ep = ssFormEndpoint?.value || '';
        if (!api || !ep) {
            if (showToastFn) showToastFn(PreviewConfig.i18n?.selectApiEndpoint || 'Select an API and endpoint', 'error');
            return;
        }
        var fields = collectSsFields();
        if (Object.keys(fields).length === 0) {
            if (showToastFn) showToastFn(PreviewConfig.i18n?.stateStoreNoFields || 'Add at least one field', 'error');
            return;
        }

        var stores = Object.assign({}, ssCurrentStores);
        if (!ssEditingId && stores[id]) {
            if (showToastFn) showToastFn((PreviewConfig.i18n?.stateStoreIdExists || 'A store named "%s" already exists').replace('%s', id), 'error');
            return;
        }
        if (ssEditingId && ssEditingId !== id) delete stores[ssEditingId];   // rename
        stores[id] = {
            endpoint: '@' + api + '/' + ep,
            fetchOnLoad: !!(ssFormFetchOnLoad && ssFormFetchOnLoad.checked),
            fields: fields
        };

        try {
            if (ssFormSave) { ssFormSave.disabled = true; ssFormSave.textContent = PreviewConfig.i18n?.saving || 'Saving...'; }
            var response = await fetch('/management/setStateStores', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + PreviewConfig.authToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ route: currentPageName, stores: stores })
            });
            var result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Failed to save');
            if (showToastFn) showToastFn(PreviewConfig.i18n?.stateStoreSaved || 'State store saved', 'success');
            hideStateStoreForm();
            await loadStateStores();
            if (reloadPreviewFn) reloadPreviewFn();
        } catch (error) {
            console.error('[PreviewJsInteractions] Save state store error:', error);
            if (showToastFn) showToastFn('Error: ' + error.message, 'error');
        } finally {
            if (ssFormSave) { ssFormSave.disabled = false; ssFormSave.textContent = PreviewConfig.i18n?.saveStateStore || 'Save store'; }
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
    
    // ====================================================================
    // ComponentList binding helpers (see docs/ADMIN_PANEL.md "Component
    // list binding"). The picker lets the user wire an array response
    // field to a hidden component template, mapping each item's fields
    // to the component's data-bind slots, with optional enum resolution.
    //
    // Code organisation:
    //   - Data loading: _loadComponentListOnce + _loadComponentMeta
    //   - Pure walkers: _collect* / _build* / _itemFieldsFor / _flattenSchema
    //   - DOM helpers : _render* (each returns ONE Element, no innerHTML)
    //   - Composer    : _buildComponentListBlock (orchestrates the above)
    // ====================================================================

    // ---- Data loading -------------------------------------------------

    // Module-scope cache. Single fetch per session for the whole list;
    // meta lookups derive from this cache (no second round trip).
    var _componentListCache  = null;       // Promise<Array<componentEntry>>

    function _loadComponentListOnce() {
        if (_componentListCache) return _componentListCache;
        _componentListCache = fetch('/management/listComponents', {
            headers: { 'Authorization': 'Bearer ' + PreviewConfig.authToken }
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            return (j && j.data && Array.isArray(j.data.components)) ? j.data.components : [];
        })
        .catch(function() { return []; });
        return _componentListCache;
    }

    /**
     * Resolve a component's metadata for picker-side use. Derives
     * everything from the cached listComponents response — no extra
     * round trip (each list entry already carries `structure` +
     * `variables`).
     *
     * Returns:
     *   {
     *     name,
     *     structure,           // raw template (with __enums__)
     *     placeholderKeys,     // every {{varname}} found in the template
     *     dataBindKeys,        // every data-bind="varname" in the template
     *     enumKeys,            // short keys declared in __enums__
     *     enumQualified,       // shortKey → '<componentName>.<shortKey>'
     *                          //   (same convention as EnumSyncHelper)
     *   }
     */
    function _loadComponentMeta(name) {
        return _loadComponentListOnce().then(function(list) {
            var entry = (list || []).find(function(c) { return c && c.name === name; });
            var structure = (entry && entry.structure) || {};
            return {
                name: name,
                structure: structure,
                placeholderKeys: _collectPlaceholderKeys(structure),
                dataBindKeys:    _collectDataBindKeys(structure),
                enumKeys:        _collectEnumShortKeys(structure),
                enumQualified:   _buildEnumQualifiedMap(name, structure),
            };
        });
    }

    // ---- Pure walkers (no DOM, no I/O) --------------------------------

    function _collectPlaceholderKeys(node) {
        var keys = {};
        (function walk(n) {
            if (n == null) return;
            if (typeof n === 'string') {
                var re = /\{\{(\$?[\w-]+)\}\}/g, m;
                while ((m = re.exec(n)) !== null) {
                    var k = m[1].replace(/^\$/, '');
                    keys[k] = true;
                }
            } else if (Array.isArray(n)) {
                n.forEach(walk);
            } else if (typeof n === 'object') {
                for (var k in n) if (Object.prototype.hasOwnProperty.call(n, k)) walk(n[k]);
            }
        })(node);
        return Object.keys(keys).sort();
    }

    function _collectDataBindKeys(node) {
        var keys = {};
        (function walk(n) {
            if (!n || typeof n !== 'object') return;
            if (Array.isArray(n)) { n.forEach(walk); return; }
            if (n.params && typeof n.params === 'object' && n.params['data-bind']) {
                keys[String(n.params['data-bind'])] = true;
            }
            if (Array.isArray(n.children)) n.children.forEach(walk);
        })(node);
        return Object.keys(keys).sort();
    }

    function _collectEnumShortKeys(structure) {
        if (!structure || typeof structure !== 'object') return [];
        var enums = structure.__enums__;
        if (!enums || typeof enums !== 'object') return [];
        return Object.keys(enums).sort();
    }

    function _buildEnumQualifiedMap(componentName, structure) {
        var out = {};
        _collectEnumShortKeys(structure).forEach(function(k) {
            out[k] = componentName + '.' + k;
        });
        return out;
    }

    /**
     * Item field names for an array response field. Falls back to []
     * when the schema doesn't declare items.properties.
     */
    function _itemFieldsFor(fieldName, fieldsMap) {
        var schema = fieldsMap[fieldName];
        if (!schema || schema.type !== 'array') return [];
        var items = schema.items;
        if (!items || items.type !== 'object' || !items.properties) return [];
        return Object.keys(items.properties);
    }

    /**
     * Flatten a JSON-Schema-like `properties` map into dot-path keys so
     * the binding's field dropdown can target nested fields like
     * `data.commandsList`. Mostly real-world API responses wrap their
     * payload (`{status, message, data: {...}}`); without this, the
     * picker can only target the wrapper object.
     *
     * Recursion only descends into `type: object` (with declared
     * `properties`). Arrays are returned as-is — their `items.properties`
     * drive the componentList fieldMap, not separate field dropdown
     * entries.
     */
    function _flattenSchema(properties, prefix) {
        var out = {};
        if (!properties || typeof properties !== 'object') return out;
        prefix = prefix || '';
        for (var key in properties) {
            if (!Object.prototype.hasOwnProperty.call(properties, key)) continue;
            var schema = properties[key];
            if (!schema || typeof schema !== 'object') continue;
            var fullPath = prefix ? prefix + '.' + key : key;
            out[fullPath] = schema;
            if (schema.type === 'object' && schema.properties) {
                var nested = _flattenSchema(schema.properties, fullPath);
                for (var nk in nested) {
                    if (Object.prototype.hasOwnProperty.call(nested, nk)) {
                        out[nk] = nested[nk];
                    }
                }
            }
        }
        return out;
    }

    // ---- DOM helpers (each returns ONE Element, zero innerHTML) -------

    /**
     * The "From API field" <select> for one fieldMap row. Auto-defaults
     * to a name-match against the array's item-field schema; falls back
     * to the value the user previously set (if any).
     */
    function _renderFromSelect(targetKey, spec, itemFields) {
        var sel = document.createElement('select');
        sel.className = 'preview-contextual-js-response-bindings__cl-from';

        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '-- skip --';
        sel.appendChild(blank);

        itemFields.forEach(function(f) {
            var opt = document.createElement('option');
            opt.value = f;
            opt.textContent = f;
            sel.appendChild(opt);
        });

        var defaultFrom = (spec && spec.from)
            ? spec.from
            : (itemFields.indexOf(targetKey) !== -1 ? targetKey : '');

        // Preserve a previously-set "From" that the current schema no
        // longer declares — keeps the user's choice visible instead of
        // silently dropping it.
        if (defaultFrom && itemFields.indexOf(defaultFrom) === -1) {
            var custom = document.createElement('option');
            custom.value = defaultFrom;
            custom.textContent = defaultFrom + ' (custom)';
            sel.appendChild(custom);
        }
        sel.value = defaultFrom;
        return sel;
    }

    /**
     * The "Enum" checkbox for one fieldMap row (only used when the
     * target var has an __enums__ entry). Default-checked on first
     * render so enum resolution is opt-out, not opt-in.
     */
    function _renderEnumCheckbox(qualifiedName, spec) {
        var lbl = document.createElement('label');
        lbl.className = 'preview-contextual-js-response-bindings__cl-enum';

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'preview-contextual-js-response-bindings__cl-enum-cb';
        cb.dataset.qualified = qualifiedName;
        cb.checked = spec ? (spec.enum === qualifiedName) : true;
        lbl.appendChild(cb);

        var span = document.createElement('span');
        span.textContent = qualifiedName;
        lbl.appendChild(span);
        return lbl;
    }

    /**
     * One <tr> in the fieldMap table: target var name | From select |
     * Enum checkbox (or em-dash when the var has no enum).
     */
    function _renderFieldMapRow(key, meta, spec, itemFields) {
        var tr = document.createElement('tr');
        tr.className = 'preview-contextual-js-response-bindings__cl-fieldmap-row';
        tr.dataset.targetVar = key;

        var tdName = document.createElement('td');
        tdName.textContent = key;
        tr.appendChild(tdName);

        var tdFrom = document.createElement('td');
        tdFrom.appendChild(_renderFromSelect(key, spec, itemFields));
        tr.appendChild(tdFrom);

        var tdEnum = document.createElement('td');
        if (meta.enumKeys.indexOf(key) !== -1) {
            tdEnum.appendChild(_renderEnumCheckbox(meta.enumQualified[key], spec));
        } else {
            tdEnum.textContent = '—';
            tdEnum.className = 'preview-contextual-js-response-bindings__cl-enum-none';
        }
        tr.appendChild(tdEnum);
        return tr;
    }

    /**
     * The full fieldMap <table> (header + body). One row per target var.
     */
    function _renderFieldMapTable(keys, meta, prefill, itemFields) {
        var table = document.createElement('table');
        table.className = 'preview-contextual-js-response-bindings__cl-fieldmap';

        var thead = document.createElement('thead');
        var trh = document.createElement('tr');
        ['Component var', 'From API field', 'Enum'].forEach(function(t) {
            var th = document.createElement('th');
            th.textContent = t;
            trh.appendChild(th);
        });
        thead.appendChild(trh);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        keys.forEach(function(k) {
            var spec = (prefill && prefill[k]) || null;
            tbody.appendChild(_renderFieldMapRow(k, meta, spec, itemFields));
        });
        table.appendChild(tbody);
        return table;
    }

    /**
     * Yellow banner shown when the selected component's template lacks
     * a data-bind attribute for one or more fieldMap target vars. Built
     * with createElement + textNodes — no innerHTML, no string-glued
     * HTML.
     */
    function _renderWarningBanner(missingKeys, componentName) {
        var div = document.createElement('div');
        div.className = 'preview-contextual-js-response-bindings__cl-warn';

        var strong = document.createElement('strong');
        strong.textContent = 'Warning: ';
        div.appendChild(strong);

        div.appendChild(document.createTextNode(
            'the component template has no data-bind attribute for: '
        ));
        missingKeys.forEach(function(k, i) {
            if (i > 0) div.appendChild(document.createTextNode(', '));
            var code = document.createElement('code');
            code.textContent = k;
            div.appendChild(code);
        });
        div.appendChild(document.createTextNode('. Add '));

        var dbCode = document.createElement('code');
        dbCode.textContent = 'data-bind="<var>"';
        div.appendChild(dbCode);

        div.appendChild(document.createTextNode(' to the matching element in '));

        var fileCode = document.createElement('code');
        fileCode.textContent = componentName + '.json';
        div.appendChild(fileCode);

        div.appendChild(document.createTextNode(
            ' for the runtime binding to apply.'
        ));
        return div;
    }

    /**
     * The "component has no {{placeholders}} or __enums__ to bind"
     * empty-state message.
     */
    function _renderEmptyComponentMessage() {
        var div = document.createElement('div');
        div.className = 'preview-contextual-js-response-bindings__cl-empty';
        div.textContent = 'Component has no {{placeholders}} or __enums__ to bind.';
        return div;
    }

    // ---- Count-mode helpers ------------------------------------------

    // Module-scope: textKey pickers per count-mode binding row.
    // WeakMap so rows can be garbage-collected with their pickers.
    var _countPickers = new WeakMap();

    /**
     * One radio label inside a count-format row. Standalone helper so
     * the markup stays a couple of small DOM ops, not an HTML string.
     */
    function _renderCountFormatLabel(name, value, text) {
        var lbl = document.createElement('label');
        lbl.className = 'preview-contextual-js-response-bindings__count-format-option';
        var inp = document.createElement('input');
        inp.type = 'radio';
        inp.name = name;
        inp.value = value;
        var span = document.createElement('span');
        span.textContent = text;
        lbl.appendChild(inp);
        lbl.appendChild(span);
        return lbl;
    }

    /**
     * One labelled textKey-picker mount for the count-sentence subblock
     * (zero / one / many).
     */
    function _renderSentencePickerSlot(labelText) {
        var row = document.createElement('div');
        row.className = 'preview-contextual-js-response-bindings__count-sentence-row';

        var lbl = document.createElement('span');
        lbl.className = 'preview-contextual-js-response-bindings__count-sentence-label';
        lbl.textContent = labelText;
        row.appendChild(lbl);

        var mount = document.createElement('div');
        mount.className = 'preview-contextual-js-response-bindings__count-sentence-mount';
        row.appendChild(mount);

        return { row: row, mount: mount };
    }

    /**
     * Build the count-mode block: Format radio + (when 'sentence')
     * three textKey-picker mounts + fallback number input. Pickers
     * are stored per-row in the module-level WeakMap so
     * collectBindings can read their current values.
     *
     * Returns { root: Element }.
     */
    function _buildCountBlock(row, existing) {
        var root = document.createElement('div');
        root.className = 'preview-contextual-js-response-bindings__count-block';

        // ── Format radio ──
        var formatRow = document.createElement('div');
        formatRow.className = 'preview-contextual-js-response-bindings__count-format-row';

        var formatLabel = document.createElement('span');
        formatLabel.className = 'preview-contextual-js-response-bindings__row-attr-label';
        formatLabel.textContent = 'Format:';
        formatRow.appendChild(formatLabel);

        var formatName = 'count-format-' + Math.random().toString(36).slice(2, 9);
        var numberLbl   = _renderCountFormatLabel(formatName, 'number',   'Just the number');
        var sentenceLbl = _renderCountFormatLabel(formatName, 'sentence', 'Translated sentence');
        formatRow.appendChild(numberLbl);
        formatRow.appendChild(sentenceLbl);
        root.appendChild(formatRow);

        // ── Sentence sub-block (3 textKey pickers) ──
        var sentenceBlock = document.createElement('div');
        sentenceBlock.className = 'preview-contextual-js-response-bindings__count-sentence';
        var zeroSlot = _renderSentencePickerSlot('Zero:');
        var oneSlot  = _renderSentencePickerSlot('One:');
        var manySlot = _renderSentencePickerSlot('Many:');
        sentenceBlock.appendChild(zeroSlot.row);
        sentenceBlock.appendChild(oneSlot.row);
        sentenceBlock.appendChild(manySlot.row);
        root.appendChild(sentenceBlock);

        // ── Fallback number input ──
        var fallbackRow = document.createElement('div');
        fallbackRow.className = 'preview-contextual-js-response-bindings__count-fallback-row';

        var fbLabel = document.createElement('span');
        fbLabel.className = 'preview-contextual-js-response-bindings__row-attr-label';
        fbLabel.textContent = 'Fallback:';
        fallbackRow.appendChild(fbLabel);

        var fbInput = document.createElement('input');
        fbInput.type = 'number';
        fbInput.className = 'preview-contextual-js-response-bindings__count-fallback';
        fbInput.placeholder = '0';
        fbInput.title = 'Number to show when the field is missing or falsy.';
        if (existing && existing.fallback !== undefined && existing.fallback !== null) {
            fbInput.value = existing.fallback;
        }
        fallbackRow.appendChild(fbInput);
        root.appendChild(fallbackRow);

        // ── Wire up textKey pickers (shared primitive from contextual-complex) ──
        var pickerFactory = window.QSComplexWizard && window.QSComplexWizard.createTextKeyPicker;
        var pickers = { zero: null, one: null, many: null };
        if (typeof pickerFactory === 'function') {
            pickers.zero = pickerFactory({
                container:   zeroSlot.mount,
                placeholder: 'e.g. home.commandsZero',
                value:       (existing && existing.zeroKey) || '',
            });
            pickers.one = pickerFactory({
                container:   oneSlot.mount,
                placeholder: 'e.g. home.commandsOne',
                value:       (existing && existing.oneKey) || '',
            });
            pickers.many = pickerFactory({
                container:   manySlot.mount,
                placeholder: 'e.g. home.commandsMany',
                value:       (existing && existing.manyKey) || '',
            });
        } else {
            // Fallback: plain text inputs if the picker primitive isn't
            // loaded. Saves typing the key by hand instead of breaking.
            [['zero', zeroSlot, 'home.commandsZero'],
             ['one',  oneSlot,  'home.commandsOne'],
             ['many', manySlot, 'home.commandsMany']].forEach(function(triple) {
                var key = triple[0];
                var slot = triple[1];
                var input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'e.g. ' + triple[2];
                input.value = (existing && existing[key + 'Key']) || '';
                slot.mount.appendChild(input);
                pickers[key] = {
                    getValue: function() { return input.value; },
                    setValue: function(v) { input.value = v; },
                    destroy:  function() {}
                };
            });
        }
        _countPickers.set(row, pickers);

        // ── Reactivity: show/hide the sentence subblock on format change ──
        function syncSentenceVisibility(format) {
            sentenceBlock.style.display = (format === 'sentence') ? '' : 'none';
        }
        formatRow.addEventListener('change', function(e) {
            if (e.target && e.target.name === formatName) {
                syncSentenceVisibility(e.target.value);
            }
        });

        // ── Initial state ──
        var initialFormat = (existing && existing.format === 'sentence') ? 'sentence' : 'number';
        (initialFormat === 'sentence' ? sentenceLbl : numberLbl).querySelector('input').checked = true;
        syncSentenceVisibility(initialFormat);

        return { root: root };
    }

    /**
     * The component-picker dropdown (just the <select>; the wrapping
     * row markup lives in the composer below). Options are populated
     * asynchronously once the list cache resolves.
     */
    function _renderComponentSelect() {
        var sel = document.createElement('select');
        sel.className = 'preview-contextual-js-response-bindings__cl-component';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- select component --';
        sel.appendChild(placeholder);
        return sel;
    }

    /**
     * Compose the componentList-specific block: a component-picker +
     * the dynamic fieldMap table + the data-bind coverage warning.
     *
     * All visual chunks come from the _render* helpers above — this
     * function only handles structure and reactivity (which child gets
     * re-rendered when which input changes).
     *
     * collectBindings reads back via the class names baked into the
     * helpers:
     *   - row.querySelector('.preview-contextual-js-response-bindings__cl-component')
     *   - row.querySelectorAll('.preview-contextual-js-response-bindings__cl-fieldmap-row')
     */
    function _buildComponentListBlock(row, fieldsMap, fieldSelect, existing) {
        var root = document.createElement('div');
        root.className = 'preview-contextual-js-response-bindings__cl-block';

        // ── Component picker row ──
        var compRow = document.createElement('div');
        compRow.className = 'preview-contextual-js-response-bindings__cl-comp-row';
        var compLabel = document.createElement('span');
        compLabel.className = 'preview-contextual-js-response-bindings__row-attr-label';
        compLabel.textContent = 'Component:';
        var compSelect = _renderComponentSelect();
        compRow.appendChild(compLabel);
        compRow.appendChild(compSelect);
        root.appendChild(compRow);

        // ── FieldMap area + warning slot ──
        // Both are placeholder containers; the actual content is
        // assembled by renderFieldMap below whenever a component is
        // picked (or the API field changes).
        var fmWrap = document.createElement('div');
        fmWrap.className = 'preview-contextual-js-response-bindings__cl-fieldmap-wrap';
        fmWrap.style.display = 'none';
        root.appendChild(fmWrap);

        var warnSlot = document.createElement('div');
        warnSlot.className = 'preview-contextual-js-response-bindings__cl-warn-slot';
        root.appendChild(warnSlot);

        // ── Populate the component dropdown asynchronously ──
        _loadComponentListOnce().then(function(list) {
            list.forEach(function(c) {
                if (!c || !c.name) return;
                var opt = document.createElement('option');
                opt.value = c.name;
                opt.textContent = c.name;
                compSelect.appendChild(opt);
            });
            // Pre-fill from existing binding (if loading an edit).
            if (existing && existing.component) {
                compSelect.value = existing.component;
                renderFieldMap(existing.component, existing.fieldMap || {});
            }
        });

        // ── Reactivity ──
        compSelect.addEventListener('change', function() {
            renderFieldMap(compSelect.value, {});
        });
        fieldSelect.addEventListener('change', function() {
            // Keep the user's mapping choices when only the API field
            // changes (e.g. they realised it was the wrong array).
            if (compSelect.value) {
                var preserved = _collectCurrentFieldMap(fmWrap);
                renderFieldMap(compSelect.value, preserved);
            }
        });

        /**
         * Inner renderer — clears the slots, loads meta, builds the
         * table + warning (or the empty-state message).
         */
        function renderFieldMap(componentName, prefill) {
            // Clear via DOM API rather than innerHTML='' — safer when
            // listeners or refs point into the old subtree.
            while (fmWrap.firstChild)   fmWrap.removeChild(fmWrap.firstChild);
            while (warnSlot.firstChild) warnSlot.removeChild(warnSlot.firstChild);

            if (!componentName) {
                fmWrap.style.display = 'none';
                return;
            }

            _loadComponentMeta(componentName).then(function(meta) {
                fmWrap.style.display = '';

                // Target vars = union of {{placeholder}} keys + __enums__
                // keys. This is what the runtime needs slot-by-slot.
                var targetVars = {};
                meta.placeholderKeys.forEach(function(k) { targetVars[k] = true; });
                meta.enumKeys.forEach(function(k) { targetVars[k] = true; });
                var keys = Object.keys(targetVars).sort();

                if (keys.length === 0) {
                    fmWrap.appendChild(_renderEmptyComponentMessage());
                    return;
                }

                var itemFields = _itemFieldsFor(fieldSelect.value, fieldsMap);
                fmWrap.appendChild(
                    _renderFieldMapTable(keys, meta, prefill, itemFields)
                );

                // ── data-bind coverage warning ──
                // Template needs `<… data-bind="<k>">` for each target var
                // that should be updated at clone-time. `{{<k>}}`
                // placeholders are server-resolved once (when the hidden
                // template is rendered) and don't help on cloned items.
                var missing = keys.filter(function(k) {
                    return meta.dataBindKeys.indexOf(k) === -1;
                });
                if (missing.length > 0) {
                    warnSlot.appendChild(_renderWarningBanner(missing, componentName));
                }
            });
        }

        return { root: root };
    }

    /**
     * Read back the current fieldMap state from a built block. Used when
     * the user changes the API field after picking a component — we
     * preserve their choices wherever they still make sense.
     */
    function _collectCurrentFieldMap(fmWrap) {
        var out = {};
        if (!fmWrap) return out;
        var rows = fmWrap.querySelectorAll('.preview-contextual-js-response-bindings__cl-fieldmap-row');
        rows.forEach(function(tr) {
            var key = tr.dataset.targetVar;
            if (!key) return;
            var fromSel = tr.querySelector('.preview-contextual-js-response-bindings__cl-from');
            var enumCb  = tr.querySelector('.preview-contextual-js-response-bindings__cl-enum-cb');
            var spec = {};
            if (fromSel && fromSel.value) spec.from = fromSel.value;
            if (enumCb && enumCb.checked && enumCb.dataset.qualified) {
                spec.enum = enumCb.dataset.qualified;
            }
            if (Object.keys(spec).length > 0) out[key] = spec;
        });
        return out;
    }

    /**
     * Add a single binding row as a mini-card.
     * For scalar fields:        [field] [selector]   [attribute input]
     * For array fields (list):  [field] [container]  [empty text + hint]
     * For array fields (compL): [field] [container]  [component + fieldMap + warnings + empty]
     * Delete button floats top-right on hover.
     */
    function addBindingRow(rowsEl, apiSelect, endpointSelect, existing) {
        if (!rowsEl) return;

        var epData = _findEndpointData(apiSelect, endpointSelect);
        var fieldsMap = {};
        var fieldNames = [];
        if (epData && epData.responseSchema && epData.responseSchema.properties) {
            // Flatten so the dropdown can target nested paths like
            // `data.commandsList`. Most real APIs wrap their payload
            // under a top-level `data` (or similar); without this, the
            // picker can only see the wrapper.
            fieldsMap = _flattenSchema(epData.responseSchema.properties);
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
            // Both list and componentList store the container under
            // `container`; scalar mode uses `selector`. Without the
            // componentList branch, edit reopened the row with an
            // empty container input → save serialised an incomplete
            // binding the next time round.
            if ((existing.renderMode === 'list' || existing.renderMode === 'componentList')
                && existing.container) {
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
        
        // ── Render-mode radio (only visible when field is array) ──
        // Two modes today: `list` (data-bind template) and `componentList`
        // (per-item, fieldMap-mapped, optional enum resolution). Count mode
        // is a separate concern (LIVE_COUNT) and not yet wired.
        var modeRow = document.createElement('div');
        modeRow.className = 'preview-contextual-js-response-bindings__row-mode';
        modeRow.style.display = 'none';  // shown only when field is array

        var modeName = 'binding-mode-' + Math.random().toString(36).slice(2, 9);
        function modeLabel(value, text, title) {
            var lbl = document.createElement('label');
            lbl.className = 'preview-contextual-js-response-bindings__row-mode-option';
            lbl.title = title || '';
            var inp = document.createElement('input');
            inp.type = 'radio';
            inp.name = modeName;
            inp.value = value;
            var span = document.createElement('span');
            span.textContent = text;
            lbl.appendChild(inp);
            lbl.appendChild(span);
            return lbl;
        }
        var modeListLbl = modeLabel('list', 'data-bind template',
            'Each item maps to a [data-bind="key"] element inside the container\'s first child.');
        var modeCompLbl = modeLabel('componentList', 'Component per item',
            'Each item clones a hidden component instance. fieldMap controls how API fields map to component vars; enums resolve via QS.enum.');
        var modeCountLbl = modeLabel('count', 'Count',
            'Write the count of the array (or 1 for non-empty values, fallback for missing/falsy) to a target selector. Optional translated-sentence format with zero / one / many strings.');
        modeRow.appendChild(modeListLbl);
        modeRow.appendChild(modeCompLbl);
        modeRow.appendChild(modeCountLbl);

        // ── Bottom row: adapts based on field type + render mode ──
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

        // Array/list mode shared elements
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

        // ─ Mode-specific blocks (built lazily on first pick) ─
        var compListBlock = null;
        function ensureCompListBlock() {
            if (compListBlock) return compListBlock;
            compListBlock = _buildComponentListBlock(row, fieldsMap, fieldSelect, existing);
            return compListBlock;
        }
        var countBlock = null;
        function ensureCountBlock() {
            if (countBlock) return countBlock;
            countBlock = _buildCountBlock(row, existing);
            return countBlock;
        }

        /**
         * Swap bottom row contents based on field type + render mode.
         *   scalar         → attribute label + input
         *   array+list     → empty text + hint
         *   array+compL    → component picker + fieldMap table + empty text
         *   array+count    → format radio + (optional sentence subblock) + fallback
         */
        function updateRowMode(fieldType, mode) {
            bottomRow.innerHTML = '';
            // Default row layout is horizontal flex. componentList and
            // count modes need vertical so their (wide) inner blocks
            // don't squeeze the rest to the right edge — toggled via a
            // modifier class kept in admin.css.
            bottomRow.classList.remove('preview-contextual-js-response-bindings__row-bottom--column');

            if (fieldType === 'array') {
                modeRow.style.display = '';
                var resolved = mode || row.dataset.renderMode || 'list';
                row.dataset.renderMode = resolved;
                // Sync radio selection
                var resolvedLbl =
                    resolved === 'componentList' ? modeCompLbl :
                    resolved === 'count'         ? modeCountLbl :
                                                   modeListLbl;
                resolvedLbl.querySelector('input').checked = true;

                if (resolved === 'count') {
                    // For count, the selector input is the TARGET element
                    // (textContent recipient), not a container with template.
                    selectorInput.placeholder = PreviewConfig.i18n?.targetSelector || 'Target selector';
                    selectorInput.title = 'CSS selector for the element whose textContent gets the count (or the translated sentence).';
                } else {
                    selectorInput.placeholder = PreviewConfig.i18n?.containerSelector || 'Container selector';
                    selectorInput.title = PreviewConfig.i18n?.containerSelectorHint || 'Container element whose first child is the template. Each array item clones it.';
                }

                if (resolved === 'componentList') {
                    bottomRow.classList.add('preview-contextual-js-response-bindings__row-bottom--column');
                    bottomRow.appendChild(ensureCompListBlock().root);
                    // Empty-text gets its own horizontal row beneath the
                    // table so the input has room to breathe.
                    var emptyRow = document.createElement('div');
                    emptyRow.className = 'preview-contextual-js-response-bindings__cl-empty-row';
                    emptyRow.appendChild(emptyLabel);
                    emptyRow.appendChild(emptyInput);
                    bottomRow.appendChild(emptyRow);
                } else if (resolved === 'count') {
                    bottomRow.classList.add('preview-contextual-js-response-bindings__row-bottom--column');
                    bottomRow.appendChild(ensureCountBlock().root);
                } else {
                    bottomRow.appendChild(emptyLabel);
                    bottomRow.appendChild(emptyInput);
                    bottomRow.appendChild(listHint);
                }
            } else {
                modeRow.style.display = 'none';
                delete row.dataset.renderMode;
                selectorInput.placeholder = PreviewConfig.i18n?.targetSelector || 'Target selector';
                selectorInput.title = PreviewConfig.i18n?.targetSelector || 'CSS selector to inject data into';
                bottomRow.appendChild(attrLabel);
                bottomRow.appendChild(attrInput);
            }
        }

        // Determine initial mode
        var initialType = '';
        var initialRenderMode = (existing && existing.renderMode) || null;
        if (initialRenderMode === 'list'
            || initialRenderMode === 'componentList'
            || initialRenderMode === 'count') {
            initialType = 'array';
        } else {
            var selOpt = fieldSelect.options[fieldSelect.selectedIndex];
            if (selOpt) initialType = selOpt.dataset.fieldType || '';

            // Backward-compat: scalar binding loaded but the field is actually
            // array → upgrade to list mode (existing behavior preserved).
            if (existing && !existing.renderMode && initialType === 'array') {
                existing.container = existing.selector;
                delete existing.selector;
                initialRenderMode = 'list';
            }
        }
        updateRowMode(initialType, initialRenderMode);

        // Switch mode when field selection changes
        fieldSelect.addEventListener('change', function() {
            var opt = fieldSelect.options[fieldSelect.selectedIndex];
            var fType = opt ? (opt.dataset.fieldType || '') : '';
            updateRowMode(fType, null);  // null → preserve current renderMode if still applicable
        });

        // Switch mode when user clicks a radio
        modeRow.addEventListener('change', function(e) {
            if (e.target && e.target.name === modeName) {
                var opt = fieldSelect.options[fieldSelect.selectedIndex];
                var fType = opt ? (opt.dataset.fieldType || '') : '';
                updateRowMode(fType, e.target.value);
            }
        });

        row.appendChild(modeRow);
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
     * - Scalar:          {field, selector, attribute?}
     * - List:            {field, renderMode:'list', container, emptyText?}
     * - ComponentList:   {field, renderMode:'componentList', container, component,
     *                    fieldMap: { <var>: {from, enum?}, ... }, emptyText?}
     */
    function collectBindings(rowsEl) {
        if (!rowsEl) return [];
        var rows = rowsEl.querySelectorAll('.preview-contextual-js-response-bindings__row');
        var bindings = [];
        rows.forEach(function(row) {
            var field = row.querySelector('.preview-contextual-js-response-bindings__row-field')?.value || '';
            var selectorVal = row.querySelector('.preview-contextual-js-response-bindings__row-selector')?.value?.trim() || '';
            if (!field || !selectorVal) return; // skip incomplete rows

            var renderMode = row.dataset.renderMode;
            if (renderMode === 'componentList') {
                var componentName = row.querySelector('.preview-contextual-js-response-bindings__cl-component')?.value || '';
                if (!componentName) return; // skip — must pick a component first
                var fmWrap = row.querySelector('.preview-contextual-js-response-bindings__cl-fieldmap-wrap');
                var fieldMap = _collectCurrentFieldMap(fmWrap);
                var binding = {
                    field: field,
                    renderMode: 'componentList',
                    container: selectorVal,
                    component: componentName,
                    fieldMap: fieldMap
                };
                var emptyTxt = row.querySelector('.preview-contextual-js-response-bindings__row-empty-text')?.value?.trim() || '';
                if (emptyTxt) binding.emptyText = emptyTxt;
                bindings.push(binding);
            } else if (renderMode === 'count') {
                // Count mode: `selector` is the target element (not a
                // container). Format radio drives whether keys are
                // collected — only stored when 'sentence' is picked.
                var binding = { field: field, renderMode: 'count', selector: selectorVal };

                var fbInput = row.querySelector('.preview-contextual-js-response-bindings__count-fallback');
                if (fbInput && fbInput.value !== '') {
                    var fbNum = parseInt(fbInput.value, 10);
                    if (!isNaN(fbNum)) binding.fallback = fbNum;
                }

                var formatRadios = row.querySelectorAll('input[type="radio"][name^="count-format-"]');
                var format = 'number';
                formatRadios.forEach(function(r) { if (r.checked) format = r.value; });

                if (format === 'sentence') {
                    binding.format = 'sentence';
                    var pickers = _countPickers.get(row);
                    if (pickers) {
                        var zk = pickers.zero ? (pickers.zero.getValue() || '').trim() : '';
                        var ok = pickers.one  ? (pickers.one.getValue()  || '').trim() : '';
                        var mk = pickers.many ? (pickers.many.getValue() || '').trim() : '';
                        if (zk) binding.zeroKey = zk;
                        if (ok) binding.oneKey  = ok;
                        if (mk) binding.manyKey = mk;
                    }
                }
                bindings.push(binding);
            } else if (renderMode === 'list') {
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
                // Show only the function name (beta.6: Details panel carries
                // the full description / example).
                option.textContent = fn.name;
                option.dataset.args = JSON.stringify(fn.args || []);
                option.dataset.description = fn.description || '';
                option.dataset.example = fn.example || '';
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

        // State stores
        loadStateStores: loadStateStores,
        
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
