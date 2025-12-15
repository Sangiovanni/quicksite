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
$urlChangingCommands = ['setPublicSpace']; // Changes admin URL, auto-redirect
$serverConfigCommands = ['renamePublicFolder']; // Requires server config change

$isUrlChanging = in_array($selectedCommand, $urlChangingCommands);
$isServerConfig = in_array($selectedCommand, $serverConfigCommands);

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

<?php if ($isUrlChanging): ?>
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

<?php if ($isServerConfig): ?>
<div class="admin-alert admin-alert--error" style="margin-bottom: var(--space-lg);">
    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <div>
        <strong>🚨 Server Configuration Required</strong>
        <p style="margin: var(--space-xs) 0 0;">
            <strong>Advanced users only!</strong> This command renames the physical public folder. 
            After execution, your site will be <strong>inaccessible</strong> until you update your 
            server configuration (Apache VirtualHost, nginx config, etc.) to point to the new folder name.
        </p>
        <p style="margin: var(--space-xs) 0 0; font-size: var(--font-size-sm); opacity: 0.9;">
            Use case: switching between test/production environments sharing the same server root with different DNS configurations.
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
        <?php if ($isUrlChanging): ?>
        <span class="badge badge--warning" style="margin-left: var(--space-sm); font-size: var(--font-size-xs);">
            Changes URLs
        </span>
        <?php endif; ?>
        <?php if ($isServerConfig): ?>
        <span class="badge badge--error" style="margin-left: var(--space-sm); font-size: var(--font-size-xs);">
            ⚠️ Server Config Required
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
