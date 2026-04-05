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

// Load documentation immediately (init() already waits for DOM ready)
loadCommandDocumentation();

async function loadCommandDocumentation() {
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET', null, [COMMAND_NAME]);
        
        if (result.ok && result.data.data) {
            const doc = result.data.data;
            renderCommandForm(doc);
            renderCommandDocs(doc);
            // Initialize enhanced features after form renders
            await initEnhancedFeatures();
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
            await initDeleteAssetForm();
            break;
        case 'downloadAsset':
        case 'editAsset':
            await initAssetSelectForm();
            if (COMMAND_NAME === 'editAsset') initEditAssetExtensionHint();
            break;
        case 'listAssets':
            await initListAssetsForm();
            break;
        case 'getStructure':
            await initGetStructureForm();
            break;
        case 'deleteRoute':
        case 'setRouteLayout':
            await initRouteSelectForm();
            break;
        case 'addRoute':
            await initAddRouteParentSelect();
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
        case 'deployBuild':
            await initBuildSelectForm();
            break;
        case 'clearCommandHistory':
            await initClearHistoryForm();
            break;
        case 'generateToken':
            await initGenerateTokenForm();
            break;
        case 'revokeToken':
            await initRevokeTokenForm();
            break;
        case 'findComponentUsages':
            await initFindComponentUsagesForm();
            break;
        case 'renameComponent':
            await initRenameComponentForm();
            break;
        case 'duplicateComponent':
            await initDuplicateComponentForm();
            break;
        case 'addComponentToNode':
            await initAddComponentToNodeForm();
            break;
        case 'editComponentToNode':
            await initEditComponentToNodeForm();
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
    
    // Pre-fill form from URL parameters
    if (prefillType) {
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
    
    // Pre-fill action
    if (prefillAction && actionSelect) {
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
 * Initialize asset-related commands with category filter and file selection.
 * Category is no longer a form param (auto-detected from extension).
 * A category filter dropdown is added for UX convenience.
 */
async function initAssetSelectForm() {
    const form = document.getElementById('command-form');
    
    // Convert filename input to a select
    const filenameInput = form.querySelector('[name="filename"]');
    if (filenameInput && filenameInput.tagName !== 'SELECT') {
        // Add a category filter (not a form param, just for UX)
        const filterGroup = document.createElement('div');
        filterGroup.className = 'admin-form-group';
        filterGroup.innerHTML = `
            <label class="admin-label">Filter by category</label>
            <select class="admin-select" id="asset-category-filter">
                <option value="">All categories</option>
            </select>
        `;
        filenameInput.closest('.admin-form-group')?.before(filterGroup);
        
        const categoryFilter = document.getElementById('asset-category-filter');
        
        // Populate category filter options
        try {
            const categories = await QuickSiteAdmin.fetchHelperData('asset-categories', []);
            categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.value;
                option.textContent = cat.label;
                categoryFilter.appendChild(option);
            });
        } catch (e) { /* non-critical */ }
        
        const filenameSelect = document.createElement('select');
        filenameSelect.name = 'filename';
        filenameSelect.className = 'admin-select';
        filenameSelect.required = filenameInput.required;
        filenameSelect.innerHTML = '<option value="">Loading files...</option>';
        filenameInput.replaceWith(filenameSelect);
        
        // Load all assets initially (no category filter)
        await populateAssetFilenames(filenameSelect, '');
        
        // Re-populate when category filter changes
        categoryFilter.addEventListener('change', async () => {
            await populateAssetFilenames(filenameSelect, categoryFilter.value);
        });
        
        // Refresh select after successful asset operations
        form.addEventListener('command-success', (e) => {
            const { command, data, result } = e.detail || {};
            if (command === 'deleteAsset') {
                // Single delete: remove filename from select
                if (data?.filename) {
                    const option = filenameSelect.querySelector(`option[value="${CSS.escape(data.filename)}"]`);
                    if (option) option.remove();
                }
                // Batch delete: remove all deleted filenames
                if (result?.deleted) {
                    result.deleted.forEach(d => {
                        const option = filenameSelect.querySelector(`option[value="${CSS.escape(d.filename)}"]`);
                        if (option) option.remove();
                    });
                }
                filenameSelect.value = '';
            }
            if (command === 'editAsset' && result?.oldFilename) {
                // Rename: update the option value and text
                const option = filenameSelect.querySelector(`option[value="${CSS.escape(result.oldFilename)}"]`);
                if (option) {
                    option.value = result.filename;
                    option.textContent = result.filename;
                    filenameSelect.value = result.filename;
                }
            }
        });
    }
}

/**
 * Populate a select with asset filenames, optionally filtered by category.
 */
async function populateAssetFilenames(selectEl, category) {
    try {
        const args = category ? [category] : [];
        await QuickSiteAdmin.populateSelect(selectEl, 'assets', args, 'Select file...');
    } catch (e) {
        selectEl.innerHTML = '<option value="">Failed to load files</option>';
    }
}

/**
 * For editAsset: show the file extension as a read-only suffix next to newFilename input.
 * Updates when the filename select changes.
 */
function initEditAssetExtensionHint() {
    const form = document.getElementById('command-form');
    const filenameSelect = form.querySelector('[name="filename"]');
    const newFilenameInput = form.querySelector('[name="newFilename"]');
    if (!filenameSelect || !newFilenameInput) return;

    // Create suffix element
    const wrapper = document.createElement('div');
    wrapper.className = 'admin-input-group';
    wrapper.style.display = 'flex';
    wrapper.style.alignItems = 'center';
    wrapper.style.gap = '0';
    newFilenameInput.parentNode.insertBefore(wrapper, newFilenameInput);
    wrapper.appendChild(newFilenameInput);
    newFilenameInput.style.borderTopRightRadius = '0';
    newFilenameInput.style.borderBottomRightRadius = '0';
    newFilenameInput.style.borderRight = 'none';

    const suffix = document.createElement('span');
    suffix.className = 'admin-input-suffix';
    suffix.style.cssText = 'padding:0.5rem 0.75rem;background:var(--admin-bg-tertiary,#374151);border:1px solid var(--admin-border,#4b5563);border-top-right-radius:0.375rem;border-bottom-right-radius:0.375rem;color:var(--admin-text-secondary,#9ca3af);font-family:monospace;white-space:nowrap;';
    suffix.textContent = '';
    wrapper.appendChild(suffix);

    // Hint below the input
    const hint = document.createElement('small');
    hint.style.cssText = 'display:block;margin-top:0.25rem;color:var(--admin-text-tertiary,#6b7280);font-size:0.75rem;';
    hint.textContent = 'Enter new name without extension — it is preserved automatically';
    wrapper.parentNode.insertBefore(hint, wrapper.nextSibling);

    // Update suffix when filename changes
    function updateSuffix() {
        const selected = filenameSelect.value;
        if (selected) {
            const ext = selected.includes('.') ? '.' + selected.split('.').pop() : '';
            suffix.textContent = ext;
        } else {
            suffix.textContent = '';
        }
    }

    filenameSelect.addEventListener('change', updateSuffix);
    updateSuffix();

    // Update placeholder based on selection
    newFilenameInput.setAttribute('placeholder', 'new-name (without extension)');
}

/**
 * Initialize deleteAsset form with checkbox-based multi-select.
 */
async function initDeleteAssetForm() {
    const form = document.getElementById('command-form');

    // Remove the auto-generated filename input
    const filenameInput = form.querySelector('[name="filename"]');
    const filenameGroup = filenameInput?.closest('.admin-form-group');
    if (!filenameGroup) return;

    // Build the container
    const container = document.createElement('div');
    container.className = 'admin-form-group';
    container.innerHTML = `
        <label class="admin-label">Files to delete</label>
        <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.5rem;">
            <label style="display:flex;align-items:center;gap:0.3rem;font-size:0.8rem;color:var(--admin-text-secondary,#9ca3af);white-space:nowrap;cursor:pointer;">
                <input type="checkbox" id="delete-select-all"> Select all
            </label>
        </div>
        <div id="delete-file-list" class="admin-checkbox-list" style="max-height:320px;overflow-y:auto;border:1px solid var(--admin-border,#4b5563);border-radius:0.375rem;padding:0.25rem 0;">
            <div style="padding:0.75rem;color:var(--admin-text-secondary)">Loading files...</div>
        </div>
        <small id="delete-count-hint" style="display:block;margin-top:0.25rem;color:var(--admin-text-tertiary,#6b7280);font-size:0.75rem;"></small>
    `;
    filenameGroup.replaceWith(container);

    const selectAllCheckbox = document.getElementById('delete-select-all');
    const fileListEl = document.getElementById('delete-file-list');
    const countHint = document.getElementById('delete-count-hint');

    // Load and render file checkboxes
    async function loadFiles() {
        fileListEl.innerHTML = '<div style="padding:0.75rem;color:var(--admin-text-secondary)">Loading...</div>';
        try {
            const files = await QuickSiteAdmin.fetchHelperData('assets', []);
            if (!files || files.length === 0) {
                fileListEl.innerHTML = '<div style="padding:0.75rem;color:var(--admin-text-secondary)">No files found</div>';
                updateCount();
                return;
            }

            fileListEl.innerHTML = files.map(f => `
                <label class="admin-checkbox-list__item" style="display:flex;align-items:center;gap:0.5rem;padding:0.375rem 0.75rem;cursor:pointer;" title="${QuickSiteAdmin.escapeHtml(f.value)}">
                    <input type="checkbox" class="delete-file-cb" value="${QuickSiteAdmin.escapeHtml(f.value)}">
                    <span style="flex:1;font-size:0.875rem;">${QuickSiteAdmin.escapeHtml(f.label)}</span>
                </label>
            `).join('');
            updateCount();
        } catch (e) {
            fileListEl.innerHTML = '<div style="padding:0.75rem;color:var(--admin-error)">Failed to load files</div>';
        }
    }

    function getChecked() {
        return Array.from(fileListEl.querySelectorAll('.delete-file-cb:checked')).map(cb => cb.value);
    }

    function updateCount() {
        const checked = getChecked();
        countHint.textContent = checked.length > 0 ? `${checked.length} file${checked.length > 1 ? 's' : ''} selected for deletion` : '';
        selectAllCheckbox.checked = fileListEl.querySelectorAll('.delete-file-cb').length > 0 &&
            fileListEl.querySelectorAll('.delete-file-cb:not(:checked)').length === 0;
    }

    selectAllCheckbox.addEventListener('change', () => {
        fileListEl.querySelectorAll('.delete-file-cb').forEach(cb => {
            cb.checked = selectAllCheckbox.checked;
        });
        updateCount();
    });

    fileListEl.addEventListener('change', updateCount);

    await loadFiles();

    // Override form submission: send batch or single delete
    form.addEventListener('submit', async function(e) {
        const checked = getChecked();
        if (checked.length === 0) return; // let normal validation handle it

        e.preventDefault();
        e.stopPropagation();

        const submitBtn = document.getElementById('submit-btn');
        submitBtn.disabled = true;
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.innerHTML = `${QuickSiteUtils.htmlSpinner()} Deleting ${checked.length} file${checked.length > 1 ? 's' : ''}...`;

        const responseDiv = document.getElementById('command-response');

        try {
            let result;
            if (checked.length === 1) {
                result = await QuickSiteAdmin.apiRequest('deleteAsset', 'DELETE', { filename: checked[0] });
            } else {
                result = await QuickSiteAdmin.apiRequest('deleteAsset', 'DELETE', { filenames: checked });
            }

            QuickSiteAdmin.displayResponse(responseDiv, result);

            if (result.ok) {
                // Remove deleted files from the checkbox list using the checked array
                // (204 responses have no body, so we rely on what we sent)
                checked.forEach(fname => {
                    const cb = fileListEl.querySelector(`.delete-file-cb[value="${CSS.escape(fname)}"]`);
                    if (cb) cb.closest('.admin-checkbox-list__item')?.remove();
                });
                updateCount();

                const msg = checked.length === 1 ? 'File deleted!' : `${checked.length} files deleted!`;
                QuickSiteAdmin.showToast(msg, 'success');
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Delete failed', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.displayResponse(responseDiv, { ok: false, status: 0, data: { error: error.message } });
            QuickSiteAdmin.showToast('Delete failed: ' + error.message, 'error');
        }

        submitBtn.innerHTML = originalBtnText;
        submitBtn.disabled = false;
    }, true);
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
 * Initialize uploadAsset form — single file upload (atomic command).
 * For multi-file upload, use the dedicated Asset Management page (/admin/assets).
 * Category is auto-detected from file extension (no category select needed).
 */
async function initUploadAssetForm() {
    const form = document.getElementById('command-form');
    
    // Fetch allowed extensions for hint display
    let allExtensions = [];
    try {
        const extensionsMap = await QuickSiteAdmin.fetchHelperData('asset-extensions');
        allExtensions = Object.values(extensionsMap).flat();
    } catch (e) { /* extensions hint is non-critical */ }
    
    // Set file accept attribute to all allowed extensions
    const fileInput = form.querySelector('input[type="file"]');
    if (fileInput && allExtensions.length) {
        fileInput.setAttribute('accept', allExtensions.map(e => '.' + e).join(','));
    }
    
    // Show allowed extensions hint below file input
    if (fileInput && allExtensions.length) {
        const hint = document.createElement('small');
        hint.className = 'admin-form-hint';
        hint.textContent = `Allowed: ${allExtensions.join(', ')} · Category auto-detected from extension`;
        const group = fileInput.closest('.admin-form-group');
        if (group) group.appendChild(hint);
    }
    
    // Add "or" divider + URL field after file input group
    const fileGroup = fileInput?.closest('.admin-form-group');
    const urlInput = form.querySelector('[name="url"]');
    if (fileGroup && urlInput) {
        const urlGroup = urlInput.closest('.admin-form-group');
        if (urlGroup) {
            const divider = document.createElement('div');
            divider.className = 'admin-url-upload__divider';
            divider.innerHTML = '<span>or</span>';
            fileGroup.after(divider);
            divider.after(urlGroup);
        }
        
        // Mutual visual disable: selecting a file dims the URL, typing a URL dims the file
        urlInput.addEventListener('input', () => {
            if (urlInput.value.trim()) {
                fileGroup.classList.add('admin-file-input--dimmed');
            } else {
                fileGroup.classList.remove('admin-file-input--dimmed');
            }
        });
        
        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    const urlGroup = urlInput.closest('.admin-form-group');
                    if (urlGroup) urlGroup.classList.add('admin-url-upload--dimmed');
                    urlInput.value = '';
                } else {
                    const urlGroup = urlInput.closest('.admin-form-group');
                    if (urlGroup) urlGroup.classList.remove('admin-url-upload--dimmed');
                }
            });
        }
    }
    
    // Add link to Asset Management page for multi-file uploads
    const pageHeader = document.querySelector('.admin-page-header');
    if (pageHeader) {
        const tip = document.createElement('p');
        tip.className = 'admin-form-hint';
        tip.style.marginTop = 'var(--space-sm)';
        tip.innerHTML = 'Need to upload multiple files? Use the <a href="' + 
            (window.QUICKSITE_CONFIG?.adminBase || '/admin') + '/assets">Asset Management</a> page.';
        pageHeader.appendChild(tip);
    }
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

        // Refresh build list after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteBuild') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'builds', [], 'Select build...');
                nameSelect.selectedIndex = 0;
            }
        });
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
async function initGenerateTokenForm() {
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
    
    // Replace role text input with a select populated from listRoles API
    const roleInput = form.querySelector('[name="role"]');
    if (roleInput && roleInput.tagName !== 'SELECT') {
        const roleSelect = document.createElement('select');
        roleSelect.name = 'role';
        roleSelect.id = roleInput.id;
        roleSelect.className = 'admin-select';
        roleSelect.required = roleInput.required;
        roleSelect.innerHTML = '<option value="">Loading roles...</option>';
        roleInput.replaceWith(roleSelect);
        
        try {
            const result = await QuickSiteAdmin.apiRequest('listRoles', 'GET');
            if (result.ok && result.data.data) {
                const roles = result.data.data.roles || [];
                let html = '<option value="">-- Select role --</option>';
                html += '<option value="*">* - Superadmin (full access to all commands)</option>';
                roles.forEach(r => {
                    const count = r.command_count || 0;
                    html += `<option value="${QuickSiteAdmin.escapeHtml(r.name)}">${QuickSiteAdmin.escapeHtml(r.name)} - ${QuickSiteAdmin.escapeHtml(r.description)} (${count} commands)</option>`;
                });
                roleSelect.innerHTML = html;
            } else {
                roleSelect.innerHTML = '<option value="">-- Select role --</option>' +
                    '<option value="*">*</option>' +
                    '<option value="viewer">viewer</option>' +
                    '<option value="editor">editor</option>' +
                    '<option value="designer">designer</option>' +
                    '<option value="developer">developer</option>' +
                    '<option value="admin">admin</option>';
            }
        } catch (e) {
            console.error('Failed to load roles:', e);
            roleSelect.innerHTML = '<option value="">-- Select role --</option>' +
                '<option value="*">*</option>' +
                '<option value="viewer">viewer</option>' +
                '<option value="editor">editor</option>' +
                '<option value="designer">designer</option>' +
                '<option value="developer">developer</option>' +
                '<option value="admin">admin</option>';
        }
    }
}

/**
 * Initialize revokeToken form - replaces token_preview text input with a select
 * populated from listTokens API
 */
async function initRevokeTokenForm() {
    const form = document.getElementById('command-form');
    const tokenInput = form.querySelector('[name="token_preview"]');
    
    if (!tokenInput || tokenInput.tagName === 'SELECT') return;
    
    const tokenSelect = document.createElement('select');
    tokenSelect.name = 'token_preview';
    tokenSelect.id = tokenInput.id;
    tokenSelect.className = 'admin-select';
    tokenSelect.required = tokenInput.required;
    tokenSelect.innerHTML = '<option value="">Loading tokens...</option>';
    tokenInput.replaceWith(tokenSelect);
    
    async function loadTokenOptions() {
        const result = await QuickSiteAdmin.apiRequest('listTokens', 'GET');
        if (result.ok && result.data.data) {
            const tokens = result.data.data.tokens || [];
            let html = '<option value="">-- Select token to revoke --</option>';
            tokens.forEach(t => {
                const preview = t.token_preview || '';
                const name = t.name || 'Unnamed';
                const role = t.role || t.permissions?.join(', ') || '?';
                const isCurrent = t.is_current ? ' (current - cannot revoke)' : '';
                html += `<option value="${QuickSiteAdmin.escapeHtml(preview)}" ${t.is_current ? 'disabled' : ''}>` +
                    `${QuickSiteAdmin.escapeHtml(preview)} - ${QuickSiteAdmin.escapeHtml(name)} [${QuickSiteAdmin.escapeHtml(role)}]${isCurrent}</option>`;
            });
            tokenSelect.innerHTML = html;
            return true;
        }
        return false;
    }

    try {
        const loaded = await loadTokenOptions();
        if (!loaded) {
            tokenSelect.replaceWith(tokenInput);
        }
    } catch (e) {
        console.error('Failed to load tokens:', e);
        tokenSelect.replaceWith(tokenInput);
    }

    // Refresh token list after successful revoke
    form.addEventListener('command-success', async (e) => {
        if (e.detail.command === 'revokeToken') {
            try {
                await loadTokenOptions();
                tokenSelect.selectedIndex = 0;
            } catch (err) {
                console.error('Failed to refresh tokens:', err);
            }
        }
    });
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

        // Refresh route list after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteRoute') {
                await QuickSiteAdmin.populateSelect(routeSelect, 'routes', [], 'Select route...');
                routeSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize addRoute form: convert parent field to route select dropdown
 */
async function initAddRouteParentSelect() {
    const form = document.getElementById('command-form');
    const parentInput = form.querySelector('[name="parent"]');
    
    if (parentInput && parentInput.tagName !== 'SELECT') {
        const parentSelect = document.createElement('select');
        parentSelect.name = 'parent';
        parentSelect.className = 'admin-select';
        // parent is optional — no required attribute
        
        if (parentInput.dataset.urlParam !== undefined) {
            parentSelect.dataset.urlParam = '';
        }
        
        parentInput.replaceWith(parentSelect);
        
        // Add "None (root level)" as first option
        const noneOption = document.createElement('option');
        noneOption.value = '';
        noneOption.textContent = 'None (root level)';
        parentSelect.appendChild(noneOption);
        
        await QuickSiteAdmin.populateSelect(parentSelect, 'routes', [], 'None (root level)');

        // Refresh route list after successful add
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'addRoute') {
                await QuickSiteAdmin.populateSelect(parentSelect, 'routes', [], 'None (root level)');
            }
        });
    }
}

/**
 * Initialize findComponentUsages form with component select
 */
async function initFindComponentUsagesForm() {
    const form = document.getElementById('command-form');
    const componentInput = form.querySelector('[name="component"]');
    
    if (componentInput && componentInput.tagName !== 'SELECT') {
        const componentSelect = document.createElement('select');
        componentSelect.name = 'component';
        componentSelect.className = 'admin-select';
        componentSelect.required = componentInput.required;
        
        if (componentInput.dataset.urlParam !== undefined) {
            componentSelect.dataset.urlParam = '';
        }
        
        componentInput.replaceWith(componentSelect);
        await QuickSiteAdmin.populateSelect(componentSelect, 'components', [], 'Select component...');
    }
}

/**
 * Initialize renameComponent form with oldName component select
 */
async function initRenameComponentForm() {
    const form = document.getElementById('command-form');
    const oldNameInput = form.querySelector('[name="oldName"]');
    
    if (oldNameInput && oldNameInput.tagName !== 'SELECT') {
        const oldNameSelect = document.createElement('select');
        oldNameSelect.name = 'oldName';
        oldNameSelect.className = 'admin-select';
        oldNameSelect.required = oldNameInput.required;
        oldNameInput.replaceWith(oldNameSelect);
        await QuickSiteAdmin.populateSelect(oldNameSelect, 'components', [], 'Select component to rename...');

        // Refresh component list after successful rename
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'renameComponent') {
                await QuickSiteAdmin.populateSelect(oldNameSelect, 'components', [], 'Select component to rename...');
                oldNameSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize duplicateComponent form with source component select
 */
async function initDuplicateComponentForm() {
    const form = document.getElementById('command-form');
    const sourceInput = form.querySelector('[name="source"]');
    
    if (sourceInput && sourceInput.tagName !== 'SELECT') {
        const sourceSelect = document.createElement('select');
        sourceSelect.name = 'source';
        sourceSelect.className = 'admin-select';
        sourceSelect.required = sourceInput.required;
        sourceInput.replaceWith(sourceSelect);
        await QuickSiteAdmin.populateSelect(sourceSelect, 'components', [], 'Select component to duplicate...');

        // Refresh component list after successful duplicate
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'duplicateComponent') {
                await QuickSiteAdmin.populateSelect(sourceSelect, 'components', [], 'Select component to duplicate...');
                sourceSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize addComponentToNode form with cascading selects and dynamic data fields
 */
async function initAddComponentToNodeForm() {
    const form = document.getElementById('command-form');
    
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
    
    // Convert targetNodeId input to select
    const targetNodeIdInput = form.querySelector('[name="targetNodeId"]');
    if (targetNodeIdInput && targetNodeIdInput.tagName !== 'SELECT') {
        const targetNodeIdSelect = document.createElement('select');
        targetNodeIdSelect.name = 'targetNodeId';
        targetNodeIdSelect.className = 'admin-select';
        targetNodeIdSelect.required = targetNodeIdInput.required;
        targetNodeIdSelect.innerHTML = '<option value="">Select structure first...</option>';
        targetNodeIdSelect.disabled = true;
        targetNodeIdInput.replaceWith(targetNodeIdSelect);
    }
    
    // Convert position input to select
    const positionInput = form.querySelector('[name="position"]');
    if (positionInput && positionInput.tagName !== 'SELECT') {
        const positionSelect = document.createElement('select');
        positionSelect.name = 'position';
        positionSelect.className = 'admin-select';
        positionSelect.innerHTML = `
            <option value="">Select position...</option>
            <option value="inside">Inside (as child)</option>
            <option value="before">Before</option>
            <option value="after">After</option>
        `;
        positionInput.replaceWith(positionSelect);
    }
    
    // Convert component input to select
    const componentInput = form.querySelector('[name="component"]');
    if (componentInput && componentInput.tagName !== 'SELECT') {
        const componentSelect = document.createElement('select');
        componentSelect.name = 'component';
        componentSelect.className = 'admin-select';
        componentSelect.required = componentInput.required;
        componentInput.replaceWith(componentSelect);
        await QuickSiteAdmin.populateSelect(componentSelect, 'components', [], 'Select component to add...');
    }
    
    // Find or create data field container
    const dataInput = form.querySelector('[name="data"]');
    let dataContainer = null;
    if (dataInput) {
        // Create a container for the dynamic variable fields
        dataContainer = document.createElement('div');
        dataContainer.className = 'admin-component-data-builder';
        dataContainer.innerHTML = `
            <div class="admin-component-data-header">
                <span class="admin-label">Component Data (Variables)</span>
                <span class="admin-hint">Select a component to see its variables</span>
            </div>
            <div class="admin-component-data-fields" id="component-data-fields">
                <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                    Select a component to configure its variables
                </p>
            </div>
        `;
        dataInput.parentNode.insertBefore(dataContainer, dataInput);
        dataInput.style.display = 'none'; // Hide the original JSON textarea
    }
    
    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    const targetNodeIdSelect = form.querySelector('[name="targetNodeId"]');
    const componentSelect = form.querySelector('[name="component"]');
    
    // Function to load node options
    async function loadNodeOptions() {
        if (!targetNodeIdSelect) return;
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type) {
            targetNodeIdSelect.innerHTML = '<option value="">Select type first...</option>';
            targetNodeIdSelect.disabled = true;
            return;
        }
        
        if ((type === 'page' || type === 'component') && !name) {
            targetNodeIdSelect.innerHTML = '<option value="">Select name first...</option>';
            targetNodeIdSelect.disabled = true;
            return;
        }
        
        targetNodeIdSelect.disabled = false;
        
        const params = (type === 'page' || type === 'component') ? [type, name] : [type];
        
        try {
            const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
            targetNodeIdSelect.innerHTML = '<option value="">Select target node...</option>';
            QuickSiteAdmin.appendOptionsToSelect(targetNodeIdSelect, nodes);
        } catch (error) {
            targetNodeIdSelect.innerHTML = '<option value="">Error loading nodes</option>';
        }
    }
    
    // Function to build dynamic data fields based on component variables
    async function buildDataFields(componentName) {
        const fieldsContainer = document.getElementById('component-data-fields');
        if (!fieldsContainer || !componentName) {
            if (fieldsContainer) {
                fieldsContainer.innerHTML = `
                    <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                        Select a component to configure its variables
                    </p>
                `;
            }
            return;
        }
        
        try {
            const componentData = await QuickSiteAdmin.fetchHelperData('component-variables', [componentName]);
            const variables = componentData.variables || [];
            
            if (variables.length === 0) {
                fieldsContainer.innerHTML = `
                    <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                        This component has no variables
                    </p>
                `;
                return;
            }
            
            // Build the variable fields table
            let html = '<div class="admin-data-table">';
            
            for (const variable of variables) {
                const varName = variable.name;
                const varType = variable.type || 'string';
                const inputId = `data-var-${varName}`;
                
                html += `
                    <div class="admin-data-row" data-var-name="${varName}" data-var-type="${varType}">
                        <div class="admin-data-label">
                            <label for="${inputId}" class="admin-label">${varName}</label>
                            <span class="admin-badge admin-badge--small">${varType}</span>
                        </div>
                        <div class="admin-data-input">
                `;
                
                if (varType === 'textKey') {
                    // Translation key selector
                    html += `
                        <div class="admin-textkey-selector">
                            <select id="${inputId}-select" class="admin-select admin-select--textkey" data-target="${inputId}">
                                <option value="">Loading translation keys...</option>
                            </select>
                            <span class="admin-hint">or enter manually:</span>
                            <input type="text" id="${inputId}" class="admin-input" data-var-input="${varName}" placeholder="e.g., page.home.title">
                        </div>
                    `;
                } else {
                    // Regular text input
                    html += `
                        <input type="text" id="${inputId}" class="admin-input" data-var-input="${varName}" placeholder="Enter ${varName}...">
                    `;
                }
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            html += '</div>';
            fieldsContainer.innerHTML = html;
            
            // Initialize textKey selectors with translation keys
            const textKeySelects = fieldsContainer.querySelectorAll('.admin-select--textkey');
            if (textKeySelects.length > 0) {
                await initTextKeySelectors(textKeySelects);
            }
            
            // Set up event listeners to sync to hidden data field
            setupDataFieldSync();
            
        } catch (error) {
            console.error('Failed to load component variables:', error);
            fieldsContainer.innerHTML = `
                <p class="admin-hint admin-hint--error" style="text-align: center; padding: var(--space-md);">
                    Error loading component variables
                </p>
            `;
        }
    }
    
    // Initialize textKey selectors with translation keys
    async function initTextKeySelectors(selects) {
        try {
            // Get available languages first
            const languages = await QuickSiteAdmin.fetchHelperData('languages', []);
            const defaultLang = languages[0]?.value || 'en';
            
            // Fetch translation keys
            const keys = await QuickSiteAdmin.fetchHelperData('translation-keys', [defaultLang]);
            
            selects.forEach(select => {
                const targetId = select.dataset.target;
                select.innerHTML = '<option value="">Select a translation key...</option>';
                
                keys.forEach(key => {
                    const option = document.createElement('option');
                    option.value = key.value;
                    option.textContent = key.label;
                    select.appendChild(option);
                });
                
                // When select changes, update the input field
                select.addEventListener('change', () => {
                    const input = document.getElementById(targetId);
                    if (input && select.value) {
                        input.value = select.value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        } catch (error) {
            console.error('Failed to load translation keys:', error);
            selects.forEach(select => {
                select.innerHTML = '<option value="">Error loading keys</option>';
            });
        }
    }
    
    // Sync all variable inputs to the hidden data JSON field
    function setupDataFieldSync() {
        const dataField = form.querySelector('[name="data"]');
        if (!dataField) return;
        
        const varInputs = document.querySelectorAll('[data-var-input]');
        varInputs.forEach(input => {
            input.addEventListener('input', () => {
                const data = {};
                varInputs.forEach(inp => {
                    const varName = inp.dataset.varInput;
                    const value = inp.value.trim();
                    if (value) {
                        data[varName] = value;
                    }
                });
                dataField.value = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
            });
        });
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
                await loadNodeOptions();
            }
            
            if (targetNodeIdSelect && (type === 'page' || type === 'component')) {
                targetNodeIdSelect.innerHTML = '<option value="">Select name first...</option>';
                targetNodeIdSelect.disabled = true;
            }
        });
        
        nameSelect.addEventListener('change', loadNodeOptions);
    }
    
    // When component is selected, build the data fields
    if (componentSelect) {
        componentSelect.addEventListener('change', () => {
            buildDataFields(componentSelect.value);
        });
    }
}

/**
 * Initialize editComponentToNode form with cascading selects and dynamic data fields
 */
async function initEditComponentToNodeForm() {
    const form = document.getElementById('command-form');
    
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
    
    // Convert targetNodeId input to select
    const targetNodeIdInput = form.querySelector('[name="targetNodeId"]');
    if (targetNodeIdInput && targetNodeIdInput.tagName !== 'SELECT') {
        const targetNodeIdSelect = document.createElement('select');
        targetNodeIdSelect.name = 'targetNodeId';
        targetNodeIdSelect.className = 'admin-select';
        targetNodeIdSelect.required = targetNodeIdInput.required;
        targetNodeIdSelect.innerHTML = '<option value="">Select structure first...</option>';
        targetNodeIdSelect.disabled = true;
        targetNodeIdInput.replaceWith(targetNodeIdSelect);
    }
    
    // Find or create data field container
    const dataInput = form.querySelector('[name="data"]');
    let dataContainer = null;
    if (dataInput) {
        dataContainer = document.createElement('div');
        dataContainer.className = 'admin-component-data-builder';
        dataContainer.innerHTML = `
            <div class="admin-component-data-header">
                <span class="admin-label">Component Data (Variables)</span>
                <span class="admin-hint">Select a component node to see and edit its data</span>
            </div>
            <div class="admin-component-data-fields" id="edit-component-data-fields">
                <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                    Select a component node to edit its data
                </p>
            </div>
        `;
        dataInput.parentNode.insertBefore(dataContainer, dataInput);
        dataInput.style.display = 'none';
    }
    
    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    const targetNodeIdSelect = form.querySelector('[name="targetNodeId"]');
    
    // Store component nodes data for extracting component names
    let componentNodesData = [];
    
    // Function to load node options - only show component nodes
    async function loadComponentNodes() {
        if (!targetNodeIdSelect) return;
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type) {
            targetNodeIdSelect.innerHTML = '<option value="">Select type first...</option>';
            targetNodeIdSelect.disabled = true;
            componentNodesData = [];
            return;
        }
        
        if ((type === 'page' || type === 'component') && !name) {
            targetNodeIdSelect.innerHTML = '<option value="">Select name first...</option>';
            targetNodeIdSelect.disabled = true;
            componentNodesData = [];
            return;
        }
        
        targetNodeIdSelect.disabled = false;
        
        const params = (type === 'page' || type === 'component') ? [type, name] : [type];
        
        try {
            const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
            componentNodesData = nodes.filter(n => n.label && n.label.includes('[component:'));
            
            if (componentNodesData.length === 0) {
                targetNodeIdSelect.innerHTML = '<option value="">No component nodes found</option>';
            } else {
                targetNodeIdSelect.innerHTML = '<option value="">Select component node...</option>';
                QuickSiteAdmin.appendOptionsToSelect(targetNodeIdSelect, componentNodesData);
            }
        } catch (error) {
            targetNodeIdSelect.innerHTML = '<option value="">Error loading nodes</option>';
            componentNodesData = [];
        }
        
        // Clear data fields
        const fieldsContainer = document.getElementById('edit-component-data-fields');
        if (fieldsContainer) {
            fieldsContainer.innerHTML = `
                <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                    Select a component node to edit its data
                </p>
            `;
        }
    }
    
    // Extract component name from node label (e.g., "div [component:hero]" -> "hero")
    function getComponentNameFromNode(nodeValue) {
        const node = componentNodesData.find(n => n.value === nodeValue);
        if (!node || !node.label) return null;
        
        const match = node.label.match(/\[component:([^\]]+)\]/);
        return match ? match[1] : null;
    }
    
    // Build dynamic data fields for the component
    async function buildDataFields(componentName, currentData = {}) {
        const fieldsContainer = document.getElementById('edit-component-data-fields');
        if (!fieldsContainer || !componentName) {
            if (fieldsContainer) {
                fieldsContainer.innerHTML = `
                    <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                        Select a component node to edit its data
                    </p>
                `;
            }
            return;
        }
        
        try {
            const componentData = await QuickSiteAdmin.fetchHelperData('component-variables', [componentName]);
            const variables = componentData.variables || [];
            
            if (variables.length === 0) {
                fieldsContainer.innerHTML = `
                    <p class="admin-hint" style="text-align: center; padding: var(--space-md);">
                        This component has no variables
                    </p>
                `;
                return;
            }
            
            let html = '<div class="admin-data-table">';
            
            for (const variable of variables) {
                const varName = variable.name;
                const varType = variable.type || 'string';
                const inputId = `edit-data-var-${varName}`;
                const currentValue = currentData[varName] || '';
                
                html += `
                    <div class="admin-data-row" data-var-name="${varName}" data-var-type="${varType}">
                        <div class="admin-data-label">
                            <label for="${inputId}" class="admin-label">${varName}</label>
                            <span class="admin-badge admin-badge--small">${varType}</span>
                        </div>
                        <div class="admin-data-input">
                `;
                
                if (varType === 'textKey') {
                    html += `
                        <div class="admin-textkey-selector">
                            <select id="${inputId}-select" class="admin-select admin-select--textkey" data-target="${inputId}">
                                <option value="">Loading translation keys...</option>
                            </select>
                            <span class="admin-hint">or enter manually:</span>
                            <input type="text" id="${inputId}" class="admin-input" data-var-input="${varName}" 
                                placeholder="e.g., page.home.title" value="${escapeHtml(currentValue)}">
                        </div>
                    `;
                } else {
                    html += `
                        <input type="text" id="${inputId}" class="admin-input" data-var-input="${varName}" 
                            placeholder="Enter ${varName}..." value="${escapeHtml(currentValue)}">
                    `;
                }
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            html += '</div>';
            fieldsContainer.innerHTML = html;
            
            // Initialize textKey selectors
            const textKeySelects = fieldsContainer.querySelectorAll('.admin-select--textkey');
            if (textKeySelects.length > 0) {
                await initTextKeySelectors(textKeySelects, currentData);
            }
            
            setupDataFieldSync();
            
        } catch (error) {
            console.error('Failed to load component variables:', error);
            fieldsContainer.innerHTML = `
                <p class="admin-hint admin-hint--error" style="text-align: center; padding: var(--space-md);">
                    Error loading component variables
                </p>
            `;
        }
    }
    
    // Helper to escape HTML in values
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    
    // Initialize textKey selectors
    async function initTextKeySelectors(selects, currentData = {}) {
        try {
            const languages = await QuickSiteAdmin.fetchHelperData('languages', []);
            const defaultLang = languages[0]?.value || 'en';
            const keys = await QuickSiteAdmin.fetchHelperData('translation-keys', [defaultLang]);
            
            selects.forEach(select => {
                const targetId = select.dataset.target;
                const input = document.getElementById(targetId);
                const currentValue = input?.value || '';
                
                select.innerHTML = '<option value="">Select a translation key...</option>';
                
                keys.forEach(key => {
                    const option = document.createElement('option');
                    option.value = key.value;
                    option.textContent = key.label;
                    if (key.value === currentValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                
                select.addEventListener('change', () => {
                    if (input && select.value) {
                        input.value = select.value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        } catch (error) {
            console.error('Failed to load translation keys:', error);
            selects.forEach(select => {
                select.innerHTML = '<option value="">Error loading keys</option>';
            });
        }
    }
    
    // Sync variable inputs to hidden data field
    function setupDataFieldSync() {
        const dataField = form.querySelector('[name="data"]');
        if (!dataField) return;
        
        const varInputs = document.querySelectorAll('[data-var-input]');
        
        // Initial sync
        const data = {};
        varInputs.forEach(inp => {
            const varName = inp.dataset.varInput;
            const value = inp.value.trim();
            if (value) {
                data[varName] = value;
            }
        });
        dataField.value = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
        
        // Listen for changes
        varInputs.forEach(input => {
            input.addEventListener('input', () => {
                const data = {};
                varInputs.forEach(inp => {
                    const varName = inp.dataset.varInput;
                    const value = inp.value.trim();
                    if (value) {
                        data[varName] = value;
                    }
                });
                dataField.value = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
            });
        });
    }
    
    // Fetch current node data when a component node is selected
    async function loadCurrentNodeData(nodeId) {
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        const componentName = getComponentNameFromNode(nodeId);
        if (!componentName) {
            return;
        }
        
        try {
            // Fetch the node to get current data
            const params = [type];
            if (type === 'page' || type === 'component') {
                params.push(name);
            }
            params.push(nodeId);
            
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, params);
            const currentData = result.data?.data?.node?.data || {};
            
            await buildDataFields(componentName, currentData);
        } catch (error) {
            console.error('Failed to load node data:', error);
            await buildDataFields(componentName, {});
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
                await loadComponentNodes();
            }
            
            if (targetNodeIdSelect && (type === 'page' || type === 'component')) {
                targetNodeIdSelect.innerHTML = '<option value="">Select name first...</option>';
                targetNodeIdSelect.disabled = true;
            }
        });
        
        nameSelect.addEventListener('change', loadComponentNodes);
    }
    
    // When a component node is selected, load its current data and build fields
    if (targetNodeIdSelect) {
        targetNodeIdSelect.addEventListener('change', async () => {
            const nodeId = targetNodeIdSelect.value;
            if (nodeId) {
                await loadCurrentNodeData(nodeId);
            }
        });
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

        // Refresh language list after successful removal
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'removeLang') {
                await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], 'Select language...');
                langSelect.selectedIndex = 0;
            }
        });
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
            ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.upload, null, 'admin-file-input__icon')}
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
    
    // Explicit ui_type overrides type heuristics
    if (uiType === 'textarea') {
        inputHtml = `
            <textarea name="${name}" id="${inputId}" class="admin-textarea" rows="4"
                placeholder="${QuickSiteAdmin.escapeHtml(example || '')}" 
                ${required ? 'required' : ''} ${urlParamAttr}></textarea>
        `;
    } else if (uiType === 'text') {
        inputHtml = `
            <input type="text" name="${name}" id="${inputId}" class="admin-input" 
                placeholder="${QuickSiteAdmin.escapeHtml(example || '')}" 
                ${required ? 'required' : ''} ${urlParamAttr}>
        `;
    } else if (uiType === 'checkbox') {
        inputHtml = `
            <label class="admin-checkbox" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                <input type="checkbox" name="${name}" id="${inputId}" value="true" ${urlParamAttr}>
                <span>${QuickSiteAdmin.escapeHtml(description)}</span>
            </label>
        `;
    } else {
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
    } // end ui_type else
    
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
