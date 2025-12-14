<?php
/**
 * Admin Command Form Page
 * 
 * Shows the form to execute a specific command.
 * Dynamically generates form fields based on help.php documentation.
 * 
 * @version 1.6.0
 */

// $selectedCommand is already set from command.php

// Commands that change URL structure - need special warning
// Note: renameSecureFolder doesn't change public URLs, so it's not included
$pathChangingCommands = ['setPublicSpace', 'renamePublicFolder'];
$isPathChanging = in_array($selectedCommand, $pathChangingCommands);

// Load command documentation
$commandDoc = null;
$helpPath = SECURE_FOLDER_PATH . '/management/command/help.php';

// We need to fetch the command info - let's create a helper function
function getCommandDocumentation(string $command): ?array {
    // We'll fetch from the API
    $apiUrl = BASE_URL . '/management/help/' . urlencode($command);
    
    // Since we're server-side, we can include the help file directly
    // But it needs the trimParametersManagement context, so let's read from JSON cache if available
    // For now, we'll use a simplified approach
    
    $helpFile = SECURE_FOLDER_PATH . '/management/command/help.php';
    if (!file_exists($helpFile)) {
        return null;
    }
    
    // Extract commands array from help.php (this is a bit hacky but works)
    $content = file_get_contents($helpFile);
    
    // We can't easily parse PHP, so let's use a different approach
    // Check if we have the command in our static mapping
    return null; // Will be loaded via AJAX
}
?>

<?php if ($isPathChanging): ?>
<div class="admin-alert admin-alert--warning" style="margin-bottom: var(--space-lg);">
    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
    <div>
        <strong>⚠️ URL Structure Change</strong>
        <p style="margin: var(--space-xs) 0 0;">
            This command will change your site's URL structure. The admin panel URL will change, 
            and you will be automatically redirected to the new location after execution.
        </p>
    </div>
</div>
<?php endif; ?>

<div class="admin-page-header">
    <div class="admin-breadcrumb">
        <a href="<?= $router->url('command') ?>" class="admin-breadcrumb__link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            <?= __admin('commands.title') ?>
        </a>
    </div>
    <h1 class="admin-page-header__title">
        <code><?= adminEscape($selectedCommand) ?></code>
        <?php if ($isPathChanging): ?>
        <span class="badge badge--warning" style="margin-left: var(--space-sm); font-size: var(--font-size-xs);">
            Changes URLs
        </span>
        <?php endif; ?>
        <button type="button" id="favorite-btn" class="admin-favorite-btn" onclick="toggleFavorite()" title="Add to favorites">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
        </button>
    </h1>
    <p class="admin-page-header__subtitle" id="command-description">
        <?= __admin('common.loading') ?>
    </p>
</div>

<div class="admin-grid admin-grid--cols-2">
    <!-- Command Form -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= __admin('commands.execute') ?></h2>
            <p class="admin-card__subtitle" id="command-method">
                <span class="badge" id="method-badge">...</span>
            </p>
        </div>
        <div class="admin-card__body">
            <form id="command-form" class="admin-command-form" data-command="<?= adminAttr($selectedCommand) ?>">
                <div id="command-params">
                    <div class="admin-loading">
                        <span class="admin-spinner"></span>
                        <span><?= __admin('common.loading') ?></span>
                    </div>
                </div>
                
                <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg">
                        <?= __admin('commands.execute') ?>
                    </button>
                    <button type="reset" class="admin-btn admin-btn--outline">
                        <?= __admin('common.reset') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Documentation Panel -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= __admin('commands.viewDocs') ?></h2>
        </div>
        <div class="admin-card__body" id="command-docs">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Response Area -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title"><?= __admin('commands.response') ?></h2>
    </div>
    <div class="admin-card__body" id="command-response">
        <div class="admin-empty" style="padding: var(--space-lg);">
            <p><?= __admin('commands.tryIt') ?></p>
        </div>
    </div>
</div>

<script>
const COMMAND_NAME = '<?= addslashes($selectedCommand) ?>';

document.addEventListener('DOMContentLoaded', async function() {
    await loadCommandDocumentation();
    updateFavoriteButton();
});

function updateFavoriteButton() {
    const btn = document.getElementById('favorite-btn');
    const isFav = QuickSiteAdmin.isFavorite(COMMAND_NAME);
    btn.classList.toggle('admin-favorite-btn--active', isFav);
    btn.title = isFav ? 'Remove from favorites' : 'Add to favorites';
}

function toggleFavorite() {
    QuickSiteAdmin.toggleFavorite(COMMAND_NAME);
    updateFavoriteButton();
}

async function loadCommandDocumentation() {
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET', null, [COMMAND_NAME]);
        
        if (result.ok && result.data.data) {
            const doc = result.data.data;
            renderCommandForm(doc);
            renderCommandDocs(doc);
            // Initialize enhanced features after form renders
            initEnhancedFeatures();
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
        case 'downloadAsset':
            await initAssetSelectForm();
            break;
        case 'getStructure':
            await initGetStructureForm();
            break;
        case 'deleteRoute':
            await initRouteSelectForm();
            break;
        case 'removeLang':
        case 'getTranslation':
        case 'validateTranslations':
        case 'getUnusedTranslationKeys':
        case 'analyzeTranslations':
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
            initFileUploadForm();
            break;
    }
}

/**
 * Initialize editStructure form with cascading selects
 */
async function initEditStructureForm() {
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
    
    // Function to load node options
    async function loadNodeOptions() {
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
        } catch (error) {
            nodeIdSelect.innerHTML = '<option value="">Error loading nodes</option>';
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
        });
        
        // When name changes, populate nodeId options
        nameSelect.addEventListener('change', loadNodeOptions);
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
 * Initialize uploadAsset form with category select and file upload
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
    
    // Also init file upload styling
    initFileUploadForm();
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
 * Initialize setTranslationKeys form with language select
 */
async function initSetTranslationKeysForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="language"]');
    
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
    }
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
                <p><?= __admin('commands.noParameters') ?></p>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    // Separate required and optional
    const required = paramKeys.filter(k => params[k].required);
    const optional = paramKeys.filter(k => !params[k].required);
    
    if (required.length > 0) {
        html += `<h4 class="admin-form-section-title"><?= __admin('commands.requiredParams') ?></h4>`;
        required.forEach(key => {
            html += renderFormField(key, params[key], true);
        });
    }
    
    if (optional.length > 0) {
        html += `<h4 class="admin-form-section-title" style="margin-top: var(--space-lg);"><?= __admin('commands.optionalParams') ?></h4>`;
        optional.forEach(key => {
            html += renderFormField(key, params[key], false);
        });
    }
    
    paramsContainer.innerHTML = html;
}

function renderFormField(rawName, param, required) {
    const type = param.type || 'string';
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
                <h4><?= __admin('commands.notes') ?></h4>
                <p>${QuickSiteAdmin.escapeHtml(doc.notes)}</p>
            </div>
        `;
    }
    
    // Example
    if (doc.example_get || doc.example_post) {
        html += `
            <div class="admin-doc-section">
                <h4><?= __admin('commands.example') ?></h4>
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
                <h4><?= __admin('commands.successResponse') ?></h4>
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
                <h4><?= __admin('commands.errorResponses') ?></h4>
                <ul class="admin-error-list">
        `;
        Object.entries(doc.error_responses).forEach(([code, message]) => {
            html += `<li><code>${QuickSiteAdmin.escapeHtml(code)}</code>: ${QuickSiteAdmin.escapeHtml(message)}</li>`;
        });
        html += '</ul></div>';
    }
    
    container.innerHTML = html || '<p>No additional documentation available.</p>';
}
</script>

<style>
.admin-breadcrumb {
    margin-bottom: var(--space-md);
}

.admin-breadcrumb__link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    color: var(--admin-text-muted);
    text-decoration: none;
    font-size: var(--font-size-sm);
}

.admin-breadcrumb__link:hover {
    color: var(--admin-text);
}

.admin-page-header__title code {
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    color: var(--admin-accent);
}

.admin-form-actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-xl);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--admin-border);
}

.admin-form-section-title {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--admin-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 var(--space-md) 0;
}

.admin-label__type {
    font-weight: var(--font-weight-normal);
    color: var(--admin-text-light);
    font-size: var(--font-size-xs);
}

.admin-doc-section {
    margin-bottom: var(--space-lg);
}

.admin-doc-section h4 {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--admin-text-muted);
    margin: 0 0 var(--space-sm) 0;
}

.admin-error-list {
    margin: 0;
    padding-left: var(--space-lg);
    font-size: var(--font-size-sm);
}

.admin-error-list li {
    margin-bottom: var(--space-xs);
}

.admin-error-list code {
    background: var(--admin-bg);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
}

.admin-favorite-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    margin-left: var(--space-sm);
    background: none;
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    color: var(--admin-text-muted);
    cursor: pointer;
    vertical-align: middle;
    transition: all var(--transition-fast);
}

.admin-favorite-btn:hover {
    border-color: var(--admin-warning);
    color: var(--admin-warning);
}

.admin-favorite-btn--active {
    background: var(--admin-warning-bg);
    border-color: var(--admin-warning);
    color: var(--admin-warning);
}

.admin-favorite-btn--active svg {
    fill: currentColor;
}

@media (max-width: 1024px) {
    .admin-grid--cols-2 {
        grid-template-columns: 1fr;
    }
}
</style>
