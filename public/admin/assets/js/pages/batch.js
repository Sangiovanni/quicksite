/**
 * Batch Commands Page JavaScript
 * 
 * Execute multiple commands in sequence.
 * Extracted from batch.php for browser caching.
 * 
 * @version 2.0.0
 * 
 * Dependencies:
 * - QuickSiteAdmin (global)
 * - window.adminTranslations (for t() helper)
 * 
 * Exports:
 * - All functions are global for onclick handlers
 */

(function() {
    'use strict';

    // ============================================
    // Batch Command Queue Management
    // ============================================

    let batchQueue = [];
    let isExecuting = false;

    // Commands that use GET method
    const GET_COMMANDS = [
        'help', 'getRoutes', 'getStructure', 'getTranslation', 'getTranslations',
        'getLangList', 'getTranslationKeys', 'validateTranslations', 'getUnusedTranslationKeys',
        'analyzeTranslations', 'listAssets', 'getStyles', 'getRootVariables', 'listStyleRules',
        'getStyleRule', 'getKeyframes', 'listTokens', 'listComponents', 'listPages',
        'listAliases', 'listBuilds', 'getBuild', 'getCommandHistory'
    ];

    // Available commands (loaded from help API)
    let availableCommands = [];

    // Current preview template reference
    let currentPreviewTemplate = null;

    // ============================================
    // Initialization
    // ============================================

    document.addEventListener('DOMContentLoaded', function() {
        loadSavedQueue();
        loadAvailableCommands();
        initPermissionFiltering();
    });

    function loadSavedQueue() {
        const saved = localStorage.getItem('admin_batch_queue');
        if (saved) {
            try {
                batchQueue = JSON.parse(saved);
                renderQueue();
            } catch (e) {
                console.warn('Could not load saved batch queue:', e);
            }
        }
    }

    async function loadAvailableCommands() {
        try {
            const result = await QuickSiteAdmin.apiRequest('help', 'GET');
            if (result.ok && result.data.data?.commands) {
                availableCommands = Object.keys(result.data.data.commands);
            }
        } catch (e) {
            console.warn('Could not load available commands:', e);
        }
    }

    function initPermissionFiltering() {
        setTimeout(() => {
            if (QuickSiteAdmin.permissions.loaded && !QuickSiteAdmin.permissions.isSuperAdmin) {
                // Hide commands user doesn't have access to
                document.querySelectorAll('.admin-batch-add').forEach(btn => {
                    if (!QuickSiteAdmin.hasPermission(btn.dataset.command)) {
                        btn.classList.add('admin-hidden-permission');
                    }
                });
                
                // Update category counts
                document.querySelectorAll('.admin-batch-category').forEach(cat => {
                    const visible = cat.querySelectorAll('.admin-batch-add:not(.admin-hidden-permission)');
                    const countEl = cat.querySelector('.admin-batch-category__count');
                    if (countEl) {
                        countEl.textContent = visible.length;
                    }
                    if (visible.length === 0) {
                        cat.classList.add('admin-hidden-permission');
                    }
                });
            }
        }, 500);
    }

    // ============================================
    // Queue Operations
    // ============================================

    function saveQueue() {
        localStorage.setItem('admin_batch_queue', JSON.stringify(batchQueue));
    }

    function addToQueue(command) {
        batchQueue.push({
            id: Date.now(),
            command: command,
            params: {}
        });
        saveQueue();
        renderQueue();
        QuickSiteAdmin.showToast(`Added ${command} to queue`, 'success');
    }

    function removeFromQueue(id) {
        batchQueue = batchQueue.filter(item => item.id !== id);
        saveQueue();
        renderQueue();
    }

    function editQueueItem(id) {
        const item = batchQueue.find(i => i.id === id);
        if (!item) return;
        
        // Open command form in modal or redirect
        // Use the admin base URL from QuickSiteAdmin config
        const adminBase = QuickSiteAdmin.config.adminBase || '/admin';
        window.location.href = `${adminBase}/command/${item.command}?batch=1&batchId=${id}`;
    }

    function moveUp(id) {
        const index = batchQueue.findIndex(i => i.id === id);
        if (index > 0) {
            [batchQueue[index - 1], batchQueue[index]] = [batchQueue[index], batchQueue[index - 1]];
            saveQueue();
            renderQueue();
        }
    }

    function moveDown(id) {
        const index = batchQueue.findIndex(i => i.id === id);
        if (index < batchQueue.length - 1) {
            [batchQueue[index], batchQueue[index + 1]] = [batchQueue[index + 1], batchQueue[index]];
            saveQueue();
            renderQueue();
        }
    }

    function clearQueue() {
        if (batchQueue.length === 0) return;
        
        QuickSiteAdmin.confirm(
            'Are you sure you want to clear all commands from the queue?',
            {
                title: 'Clear Queue',
                confirmText: 'Clear All',
                confirmClass: 'danger'
            }
        ).then(confirmed => {
            if (confirmed) {
                batchQueue = [];
                saveQueue();
                renderQueue();
                QuickSiteAdmin.showToast('Queue cleared', 'info');
            }
        });
    }

    function renderQueue() {
        const container = document.getElementById('batch-queue');
        const controls = document.getElementById('batch-controls');
        
        if (batchQueue.length === 0) {
            container.innerHTML = `
                <div class="admin-empty admin-empty--compact">
                    <svg class="admin-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="8" y1="6" x2="21" y2="6"/>
                        <line x1="8" y1="12" x2="21" y2="12"/>
                        <line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/>
                        <line x1="3" y1="12" x2="3.01" y2="12"/>
                        <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    <p class="admin-empty__text">No commands in queue</p>
                    <p class="admin-hint">Add commands from the list on the right</p>
                </div>
            `;
            controls.style.display = 'none';
            return;
        }
        
        controls.style.display = 'block';
        
        let html = '';
        batchQueue.forEach((item, index) => {
            // Build params display string
            let paramsDisplay = 'No params';
            const hasParams = Object.keys(item.params || {}).length > 0;
            const hasUrlParams = (item.urlParams || []).length > 0;
            
            if (hasParams || hasUrlParams) {
                const parts = [];
                if (hasUrlParams) {
                    parts.push(`URL: /${item.urlParams.join('/')}`);
                }
                if (hasParams) {
                    const paramStr = JSON.stringify(item.params);
                    parts.push(paramStr.length > 40 ? paramStr.substring(0, 40) + '...' : paramStr);
                }
                paramsDisplay = parts.join(' | ');
            }
            
            html += `
                <div class="admin-batch-item" data-id="${item.id}">
                    <span class="admin-batch-item__order">${index + 1}</span>
                    <span class="admin-batch-item__name">${QuickSiteAdmin.escapeHtml(item.command)}</span>
                    <span class="admin-batch-item__params" title="${QuickSiteAdmin.escapeHtml(JSON.stringify(item.params || {}))}">${QuickSiteAdmin.escapeHtml(paramsDisplay)}</span>
                    <div class="admin-batch-item__actions">
                        <button type="button" class="admin-batch-item__btn" onclick="editQueueItem(${item.id})" title="Edit parameters">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-batch-item__btn" onclick="moveUp(${item.id})" title="Move up" ${index === 0 ? 'disabled' : ''}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="18 15 12 9 6 15"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-batch-item__btn" onclick="moveDown(${item.id})" title="Move down" ${index === batchQueue.length - 1 ? 'disabled' : ''}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-batch-item__btn admin-batch-item__btn--danger" onclick="removeFromQueue(${item.id})" title="Remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    // ============================================
    // Command Filtering
    // ============================================

    function filterBatchCommands(query) {
        const commands = document.querySelectorAll('.admin-batch-add');
        const categories = document.querySelectorAll('.admin-batch-category');
        const lowerQuery = query.toLowerCase();
        let totalVisible = 0;
        
        commands.forEach(cmd => {
            const name = cmd.dataset.command.toLowerCase();
            const matches = name.includes(lowerQuery);
            const hasPermission = QuickSiteAdmin.hasPermission(cmd.dataset.command);
            
            // Reset classes
            cmd.classList.remove('admin-hidden-permission', 'admin-disabled-permission');
            
            if (!matches) {
                cmd.style.display = 'none';
            } else if (!hasPermission) {
                cmd.style.display = 'none';
                cmd.classList.add('admin-hidden-permission');
            } else {
                cmd.style.display = '';
                totalVisible++;
            }
        });
        
        // If search has ≤3 results, show hidden items as disabled
        if (lowerQuery && totalVisible <= 3) {
            commands.forEach(cmd => {
                const name = cmd.dataset.command.toLowerCase();
                if (name.includes(lowerQuery) && cmd.classList.contains('admin-hidden-permission')) {
                    cmd.classList.remove('admin-hidden-permission');
                    cmd.classList.add('admin-disabled-permission');
                    cmd.style.display = '';
                    cmd.disabled = true;
                }
            });
        }
        
        // Hide empty categories
        categories.forEach(cat => {
            const visible = Array.from(cat.querySelectorAll('.admin-batch-add'))
                .some(c => c.style.display !== 'none');
            cat.style.display = visible ? '' : 'none';
        });
    }

    // ============================================
    // Batch Execution
    // ============================================

    async function executeBatch(clearQueueAfter = true) {
        if (isExecuting || batchQueue.length === 0) return;
        
        isExecuting = true;
        const resultsCard = document.getElementById('batch-results-card');
        const resultsContainer = document.getElementById('batch-results');
        
        resultsCard.style.display = 'block';
        resultsContainer.innerHTML = '';
        
        let successCount = 0;
        let errorCount = 0;
        
        // Small delay between commands (ms) as a best practice for sequential operations
        const COMMAND_DELAY = 50;
        
        // Make a copy of the queue to iterate over
        const queueToExecute = [...batchQueue];
        
        for (let i = 0; i < queueToExecute.length; i++) {
            const item = queueToExecute[i];
            const itemEl = document.querySelector(`.admin-batch-item[data-id="${item.id}"]`);
            
            if (itemEl) {
                itemEl.classList.add('admin-batch-item--running');
                // Auto-scroll to keep running item visible
                itemEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            const startTime = Date.now();
            
            try {
                // Determine HTTP method based on command
                const method = GET_COMMANDS.includes(item.command) ? 'GET' : 'POST';
                const urlParams = item.urlParams || [];
                const data = Object.keys(item.params || {}).length > 0 ? item.params : null;
                
                const result = await QuickSiteAdmin.apiRequest(item.command, method, method === 'GET' ? null : data, urlParams);
                const duration = Date.now() - startTime;
                
                if (itemEl) {
                    itemEl.classList.remove('admin-batch-item--running');
                    itemEl.classList.add(result.ok ? 'admin-batch-item--success' : 'admin-batch-item--error');
                }
                
                if (result.ok) {
                    successCount++;
                } else {
                    errorCount++;
                }
                
                resultsContainer.innerHTML += renderResult(item.command, result, duration);
                
            } catch (error) {
                const duration = Date.now() - startTime;
                errorCount++;
                
                if (itemEl) {
                    itemEl.classList.remove('admin-batch-item--running');
                    itemEl.classList.add('admin-batch-item--error');
                }
                
                resultsContainer.innerHTML += renderResult(item.command, { ok: false, error: error.message }, duration);
            }
            
            // Add delay between commands to allow file system operations to complete
            if (i < queueToExecute.length - 1) {
                await new Promise(resolve => setTimeout(resolve, COMMAND_DELAY));
            }
        }
        
        isExecuting = false;
        
        // Clear the queue if requested
        if (clearQueueAfter) {
            batchQueue = [];
            saveQueue();
            renderQueue();
        }
        
        // Show summary
        const clearMsg = clearQueueAfter ? ' Queue cleared.' : ' Queue kept.';
        QuickSiteAdmin.showToast(
            `Batch complete: ${successCount} succeeded, ${errorCount} failed.${clearMsg}`,
            errorCount > 0 ? 'warning' : 'success'
        );
    }

    function renderResult(command, result, duration) {
        const isSuccess = result.ok;
        const output = isSuccess 
            ? JSON.stringify(result.data, null, 2) 
            : (result.error || result.data?.message || 'Unknown error');
        
        return `
            <div class="admin-batch-result">
                <div class="admin-batch-result__header">
                    <span class="admin-batch-result__status admin-batch-result__status--${isSuccess ? 'success' : 'error'}"></span>
                    <span class="admin-batch-result__command">${QuickSiteAdmin.escapeHtml(command)}</span>
                    <span class="admin-batch-result__time">${duration}ms</span>
                </div>
                <div class="admin-batch-result__output">
                    <pre>${QuickSiteAdmin.escapeHtml(output)}</pre>
                </div>
            </div>
        `;
    }

    function clearResults() {
        document.getElementById('batch-results').innerHTML = '';
        document.getElementById('batch-results-card').style.display = 'none';
        
        // Reset item states
        document.querySelectorAll('.admin-batch-item').forEach(el => {
            el.classList.remove('admin-batch-item--running', 'admin-batch-item--success', 'admin-batch-item--error');
        });
    }

    // ============================================
    // JSON Import Functions
    // ============================================

    function toggleJsonImport() {
        const body = document.getElementById('json-import-body');
        const toggle = document.getElementById('json-import-toggle');
        
        if (body.style.display === 'none') {
            body.style.display = 'block';
            toggle.classList.add('admin-card__toggle--open');
        } else {
            body.style.display = 'none';
            toggle.classList.remove('admin-card__toggle--open');
        }
    }

    function parseJsonInput() {
        const input = document.getElementById('json-import-input').value.trim();
        
        if (!input) {
            return { valid: false, error: 'Please enter JSON commands', commands: [] };
        }
        
        try {
            const parsed = JSON.parse(input);
            
            if (!Array.isArray(parsed)) {
                return { valid: false, error: 'JSON must be an array of commands', commands: [] };
            }
            
            if (parsed.length === 0) {
                return { valid: false, error: 'Array is empty', commands: [] };
            }
            
            // Validate each command
            const commands = parsed.map((item, index) => {
                const result = { index, raw: item };
                
                if (!item || typeof item !== 'object') {
                    result.valid = false;
                    result.error = 'Must be an object';
                    return result;
                }
                
                if (!item.command || typeof item.command !== 'string') {
                    result.valid = false;
                    result.error = 'Missing or invalid "command" property';
                    return result;
                }
                
                result.command = item.command;
                result.params = item.params || {};
                
                // Check if command exists
                if (availableCommands.length > 0 && !availableCommands.includes(item.command)) {
                    result.valid = false;
                    result.error = `Unknown command: ${item.command}`;
                    return result;
                }
                
                if (item.params && typeof item.params !== 'object') {
                    result.valid = false;
                    result.error = '"params" must be an object';
                    return result;
                }
                
                result.valid = true;
                return result;
            });
            
            const allValid = commands.every(c => c.valid);
            
            return { 
                valid: allValid, 
                commands,
                error: allValid ? null : 'Some commands have errors'
            };
            
        } catch (e) {
            return { valid: false, error: `Invalid JSON: ${e.message}`, commands: [] };
        }
    }

    function validateJsonImport() {
        const result = parseJsonInput();
        const preview = document.getElementById('json-import-preview');
        
        if (!result.commands.length && result.error) {
            preview.style.display = 'block';
            preview.innerHTML = `
                <div class="admin-json-preview__title">Validation Result</div>
                <div class="admin-alert admin-alert--error" style="margin: 0;">
                    ${QuickSiteAdmin.escapeHtml(result.error)}
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="admin-json-preview__title">
                Validation Result: ${result.valid ? '✅ Valid' : '⚠️ Has Errors'} 
                (${result.commands.length} command${result.commands.length !== 1 ? 's' : ''})
            </div>
            <div class="admin-json-preview__list">
        `;
        
        result.commands.forEach((cmd, i) => {
            const paramCount = cmd.params ? Object.keys(cmd.params).length : 0;
            html += `
                <div class="admin-json-preview__item admin-json-preview__item--${cmd.valid ? 'valid' : 'invalid'}">
                    <span class="admin-json-preview__cmd">${i + 1}. ${QuickSiteAdmin.escapeHtml(cmd.command || '?')}</span>
                    ${cmd.valid 
                        ? `<span class="admin-json-preview__params">${paramCount} param${paramCount !== 1 ? 's' : ''}</span>`
                        : `<span class="admin-json-preview__error">${QuickSiteAdmin.escapeHtml(cmd.error)}</span>`
                    }
                </div>
            `;
        });
        
        html += '</div>';
        
        preview.style.display = 'block';
        preview.innerHTML = html;
    }

    function importJsonCommands() {
        const result = parseJsonInput();
        
        if (!result.valid) {
            validateJsonImport(); // Show errors
            QuickSiteAdmin.showToast(result.error || 'Invalid JSON commands', 'error');
            return;
        }
        
        // Add all valid commands to queue
        let added = 0;
        result.commands.forEach(cmd => {
            if (cmd.valid) {
                batchQueue.push({
                    id: Date.now() + added, // Ensure unique IDs
                    command: cmd.command,
                    params: cmd.params
                });
                added++;
            }
        });
        
        saveQueue();
        renderQueue();
        
        // Clear input
        document.getElementById('json-import-input').value = '';
        document.getElementById('json-import-preview').style.display = 'none';
        
        QuickSiteAdmin.showToast(`Added ${added} command${added !== 1 ? 's' : ''} to queue`, 'success');
        
        // Collapse the import section
        toggleJsonImport();
    }

    // ============================================
    // Command Templates - Helper Functions
    // ============================================

    /**
     * Execute an API call and return parsed response
     * Helper for dynamic template generators
     * Returns the inner data object from API response
     */
    async function executeApiCall(command, params = {}) {
        const method = GET_COMMANDS.includes(command) ? 'GET' : 'POST';
        const result = await QuickSiteAdmin.apiRequest(command, method, method === 'GET' ? null : params, []);
        
        // result.data is the full API response: { status, code, data: {...actual data...}, message }
        // We want to return the inner data object
        return {
            ok: result.ok,
            status: result.status,
            data: result.data?.data || null,  // Extract the inner data
            message: result.data?.message || null,
            error: result.ok ? null : (result.data?.message || 'Unknown error')
        };
    }

    // ============================================
    // Dynamic Template: Fresh Start
    // ============================================

    /**
     * Fetches current project state and generates commands to wipe it clean
     * - Deletes all routes except 404 and system routes (home stays but gets emptied)
     * - Deletes all assets
     * - Clears all translation keys (keeps languages)
     * - Resets CSS to minimal
     * - Clears menu, footer, components structures
     * - Minimizes 404 page structure
     */
    async function generateFreshStartCommands() {
        const commands = [];
        const summary = { routes: 0, assets: 0, translations: 0, structures: 0, components: 0, langs: 0 };
        
        try {
            // 0. Delete extra languages (keep only default) and disable multilingual mode
            const langResponse = await executeApiCall('getLangList', {});
            if (langResponse.ok && langResponse.data) {
                const defaultLang = langResponse.data.default_language || 'en';
                const allLangs = langResponse.data.languages || [];
                
                // Delete all non-default languages
                const langsToDelete = allLangs.filter(lang => lang !== defaultLang);
                for (const lang of langsToDelete) {
                    commands.push({ command: 'deleteLang', params: { code: lang } });
                    summary.langs++;
                }
            }
            // Disable multilingual mode (resets config state)
            commands.push({ command: 'setMultilingual', params: { enabled: false } });
            
            // 1. Fetch current routes
            // API returns: { routes: {nested object}, flat_routes: ["home", "about", "guides/installation", ...], count: N }
            const routesResponse = await executeApiCall('getRoutes', {});
            if (routesResponse.ok && routesResponse.data?.flat_routes) {
                const routes = routesResponse.data.flat_routes;
                // Delete all routes except 404 and home (home is protected)
                // Delete children first (longer paths) to avoid cascade issues
                const protectedRoutes = ['404', 'home'];
                const routesToDelete = routes
                    .filter(routeName => !protectedRoutes.includes(routeName))
                    .sort((a, b) => b.length - a.length); // Longer paths first (children before parents)
                
                for (const routeName of routesToDelete) {
                    commands.push({ command: 'deleteRoute', params: { route: routeName } });
                    summary.routes++;
                }
            }
            
            // 2. Fetch and delete all assets (format: { assets: { category: [{filename, ...}] } })
            // deleteAsset expects: { category: 'images', filename: 'file.png' }
            const assetsResponse = await executeApiCall('listAssets', {});
            if (assetsResponse.ok && assetsResponse.data?.assets) {
                const assets = assetsResponse.data.assets;
                for (const [category, files] of Object.entries(assets)) {
                    for (const file of files) {
                        commands.push({ 
                            command: 'deleteAsset', 
                            params: { category: category, filename: file.filename } 
                        });
                        summary.assets++;
                    }
                }
            }
            
            // 3. Fetch and delete all components via editStructure
            // Component deletion uses empty structure array: { type: 'component', name: '...', structure: [] }
            const componentsResponse = await executeApiCall('listComponents', {});
            if (componentsResponse.ok && componentsResponse.data?.components) {
                const components = componentsResponse.data.components;
                for (const component of components) {
                    commands.push({ 
                        command: 'editStructure', 
                        params: { 
                            type: 'component', 
                            name: component.name, 
                            structure: []  // Empty structure = delete component
                        } 
                    });
                    summary.components++;
                }
            }
            
            // 4. Fetch all languages and clear translation keys (except 404.*)
            // Use getTranslations (plural) to get all languages
            const translationsResponse = await executeApiCall('getTranslations', {});
            if (translationsResponse.ok && translationsResponse.data?.translations) {
                const allTranslations = translationsResponse.data.translations;
                
                for (const [lang, keys] of Object.entries(allTranslations)) {
                    // Get all top-level keys except '404' (preserve 404 page translations)
                    const topLevelKeys = Object.keys(keys).filter(key => key !== '404');
                    if (topLevelKeys.length > 0) {
                        commands.push({ 
                            command: 'deleteTranslationKeys', 
                            params: { language: lang, keys: topLevelKeys } 
                        });
                        summary.translations += topLevelKeys.length;
                    }
                }
            }
            
            // 5. Clear structures: menu, footer
            commands.push({ command: 'editStructure', params: { type: 'menu', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'footer', structure: [] } });
            summary.structures += 2;
            
            // 6. Empty home page structure
            commands.push({ command: 'editStructure', params: { type: 'page', name: 'home', structure: [] } });
            summary.structures++;
            
            // 7. Minimize 404 page structure (use existing textKeys for translations)
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
            summary.structures++;
            
            // 8. Clear CSS file (single space - the set* commands can recreate sections from scratch)
            commands.push({ command: 'editStyles', params: { content: '/* Fresh Start - CSS cleared */\n' } });
            
            return { commands, summary, error: null };
            
        } catch (error) {
            return { commands: [], summary, error: error.message };
        }
    }

    async function generateFreshStartPreview() {
        const previewBtn = event.target;
        previewBtn.disabled = true;
        previewBtn.textContent = 'Analyzing...';
        
        try {
            const { commands, summary, error } = await generateFreshStartCommands();
            
            if (error) {
                QuickSiteAdmin.showToast('Error analyzing project: ' + error, 'error');
                return;
            }
            
            currentPreviewTemplate = 'fresh-start-generated';
            commandTemplates['fresh-start-generated'] = {
                name: 'Fresh Start',
                commands: commands
            };
            
            const preview = document.getElementById('template-preview');
            const title = document.getElementById('template-preview-title');
            const content = document.getElementById('template-preview-content');
            const count = document.getElementById('template-preview-count');
            
            title.textContent = 'Fresh Start Preview (Generated)';
            content.value = JSON.stringify(commands, null, 2);
            count.innerHTML = `⚠️ <strong>${commands.length} commands</strong> will be generated:\n` +
                `• ${summary.routes} route(s) to delete\n` +
                `• ${summary.assets} asset(s) to delete\n` +
                `• ${summary.components} component(s) to delete\n` +
                `• ${summary.translations} translation key group(s) to clear\n` +
                `• ${summary.structures} structure(s) to reset\n` +
                `• CSS file will be cleared`;
            count.style.whiteSpace = 'pre-line';
            
            preview.style.display = 'block';
            preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            
        } finally {
            previewBtn.disabled = false;
            previewBtn.textContent = 'Analyze & Preview';
        }
    }

    async function generateFreshStart() {
        if (!confirm('⚠️ Fresh Start will generate commands to:\n\n' +
            '• Delete ALL routes (except 404 & home)\n' +
            '• Delete ALL assets\n' +
            '• Delete ALL components\n' +
            '• Clear ALL translation keys (except 404.*)\n' +
            '• Empty menu, footer, home structures\n' +
            '• Minimize 404 page structure\n' +
            '• Clear CSS file (style.css)\n\n' +
            'This will analyze your project and add delete commands to the queue.\n' +
            'Commands won\'t execute until you run them.\n\n' +
            'Continue?')) {
            return;
        }
        
        const loadBtn = event.target;
        loadBtn.disabled = true;
        loadBtn.textContent = 'Generating...';
        
        try {
            const { commands, summary, error } = await generateFreshStartCommands();
            
            if (error) {
                QuickSiteAdmin.showToast('Error generating commands: ' + error, 'error');
                return;
            }
            
            if (commands.length === 0) {
                QuickSiteAdmin.showToast('Project already clean - no commands needed!', 'info');
                return;
            }
            
            // Add all commands to queue
            let added = 0;
            commands.forEach(cmd => {
                batchQueue.push({
                    id: Date.now() + added,
                    command: cmd.command,
                    params: cmd.params
                });
                added++;
            });
            
            saveQueue();
            renderQueue();
            
            QuickSiteAdmin.showToast(
                `Fresh Start: ${commands.length} commands queued (${summary.routes} routes, ${summary.assets} assets, ${summary.components} components)`, 
                'success'
            );
            
            document.getElementById('batch-queue').scrollIntoView({ behavior: 'smooth', block: 'start' });
            
        } finally {
            loadBtn.disabled = false;
            loadBtn.textContent = 'Generate & Load';
        }
    }

    // ============================================
    // Template Management Functions
    // ============================================

    function toggleTemplates() {
        const body = document.getElementById('templates-body');
        const toggle = document.getElementById('templates-toggle');
        const isHidden = body.style.display === 'none';
        
        body.style.display = isHidden ? 'block' : 'none';
        toggle.style.transform = isHidden ? 'rotate(180deg)' : '';
    }

    function previewTemplate(templateId) {
        const template = commandTemplates[templateId];
        if (!template) return;
        
        currentPreviewTemplate = templateId;
        
        const preview = document.getElementById('template-preview');
        const title = document.getElementById('template-preview-title');
        const content = document.getElementById('template-preview-content');
        const count = document.getElementById('template-preview-count');
        
        title.textContent = template.name + ' Preview';
        content.value = JSON.stringify(template.commands, null, 2);
        count.textContent = `${template.commands.length} command${template.commands.length !== 1 ? 's' : ''}`;
        
        if (template.warning) {
            count.innerHTML = template.warning;
        }
        
        preview.style.display = 'block';
        preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeTemplatePreview() {
        document.getElementById('template-preview').style.display = 'none';
        currentPreviewTemplate = null;
    }

    function loadTemplate(templateId) {
        const template = commandTemplates[templateId];
        if (!template) return;
        
        // If template has warning, confirm first
        if (template.warning) {
            if (!confirm(template.warning + '\n\nContinue loading this template?')) {
                return;
            }
        }
        
        loadTemplateCommands(template);
    }

    function loadPreviewedTemplate() {
        if (!currentPreviewTemplate) return;
        
        const template = commandTemplates[currentPreviewTemplate];
        if (!template) return;
        
        loadTemplateCommands(template);
        closeTemplatePreview();
    }

    function loadTemplateCommands(template) {
        let added = 0;
        
        template.commands.forEach(cmd => {
            batchQueue.push({
                id: Date.now() + added,
                command: cmd.command,
                params: cmd.params
            });
            added++;
        });
        
        saveQueue();
        renderQueue();
        
        QuickSiteAdmin.showToast(`Loaded "${template.name}" (${added} commands)`, 'success');
        
        // Scroll to queue
        document.getElementById('batch-queue').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ============================================
    // Static Command Templates
    // ============================================

    const commandTemplates = {
        
        'starter-business': {
            name: 'Starter Business',
            description: 'Business website with menu, footer, 4 pages and teal theme',
            commands: [
                { command: 'addRoute', params: { route: 'about' } },
                { command: 'addRoute', params: { route: 'services' } },
                { command: 'addRoute', params: { route: 'contact' } },
                { command: 'editStructure', params: { 
                    type: 'menu',
                    structure: [
                        { tag: 'nav', params: { class: 'main-nav' }, children: [
                            { tag: 'a', params: { href: '/', class: 'nav-logo' }, children: [{ textKey: 'site.name' }] },
                            { tag: 'ul', params: { class: 'nav-links' }, children: [
                                { tag: 'li', children: [{ tag: 'a', params: { href: '/' }, children: [{ textKey: 'nav.home' }] }] },
                                { tag: 'li', children: [{ tag: 'a', params: { href: '/services' }, children: [{ textKey: 'nav.services' }] }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'footer',
                    structure: [
                        { tag: 'footer', params: { class: 'main-footer' }, children: [
                            { tag: 'div', params: { class: 'footer-content' }, children: [
                                { tag: 'div', params: { class: 'footer-brand' }, children: [
                                    { tag: 'span', params: { class: 'footer-logo' }, children: [{ textKey: 'site.name' }] },
                                    { tag: 'p', children: [{ textKey: 'site.tagline' }] }
                                ]},
                                { tag: 'nav', params: { class: 'footer-nav' }, children: [
                                    { tag: 'a', params: { href: '/about' }, children: [{ textKey: 'nav.about' }] },
                                    { tag: 'a', params: { href: '/contact' }, children: [{ textKey: 'nav.contact' }] }
                                ]}
                            ]},
                            { tag: 'div', params: { class: 'footer-bottom' }, children: [
                                { tag: 'p', children: [{ textKey: 'footer.copyright' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'home', 
                    structure: [
                        { tag: 'section', params: { class: 'hero' }, children: [
                            { tag: 'h1', children: [{ textKey: 'home.hero.title' }] },
                            { tag: 'p', children: [{ textKey: 'home.hero.subtitle' }] },
                            { tag: 'a', params: { href: '/contact', class: 'btn btn-primary' }, children: [{ textKey: 'common.cta' }] }
                        ]},
                        { tag: 'section', params: { class: 'features' }, children: [
                            { tag: 'h2', children: [{ textKey: 'home.features.title' }] },
                            { tag: 'div', params: { class: 'features-grid' }, children: [
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'home.features.f1.title' }] },
                                    { tag: 'p', children: [{ textKey: 'home.features.f1.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'home.features.f2.title' }] },
                                    { tag: 'p', children: [{ textKey: 'home.features.f2.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'home.features.f3.title' }] },
                                    { tag: 'p', children: [{ textKey: 'home.features.f3.desc' }] }
                                ]}
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'about', 
                    structure: [
                        { tag: 'section', params: { class: 'page-header' }, children: [
                            { tag: 'h1', children: [{ textKey: 'about.title' }] },
                            { tag: 'p', children: [{ textKey: 'about.subtitle' }] }
                        ]},
                        { tag: 'section', params: { class: 'about-content' }, children: [
                            { tag: 'h2', children: [{ textKey: 'about.story.title' }] },
                            { tag: 'p', children: [{ textKey: 'about.story.content' }] }
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'services', 
                    structure: [
                        { tag: 'section', params: { class: 'page-header' }, children: [
                            { tag: 'h1', children: [{ textKey: 'services.title' }] },
                            { tag: 'p', children: [{ textKey: 'services.subtitle' }] }
                        ]},
                        { tag: 'section', params: { class: 'services-grid' }, children: [
                            { tag: 'div', params: { class: 'service-card' }, children: [
                                { tag: 'h3', children: [{ textKey: 'services.s1.title' }] },
                                { tag: 'p', children: [{ textKey: 'services.s1.desc' }] }
                            ]},
                            { tag: 'div', params: { class: 'service-card' }, children: [
                                { tag: 'h3', children: [{ textKey: 'services.s2.title' }] },
                                { tag: 'p', children: [{ textKey: 'services.s2.desc' }] }
                            ]},
                            { tag: 'div', params: { class: 'service-card' }, children: [
                                { tag: 'h3', children: [{ textKey: 'services.s3.title' }] },
                                { tag: 'p', children: [{ textKey: 'services.s3.desc' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'contact', 
                    structure: [
                        { tag: 'section', params: { class: 'page-header' }, children: [
                            { tag: 'h1', children: [{ textKey: 'contact.title' }] },
                            { tag: 'p', children: [{ textKey: 'contact.subtitle' }] }
                        ]},
                        { tag: 'section', params: { class: 'contact-info' }, children: [
                            { tag: 'div', params: { class: 'contact-item' }, children: [
                                { tag: 'h3', children: [{ textKey: 'contact.email.label' }] },
                                { tag: 'p', children: [{ textKey: 'contact.email.value' }] }
                            ]},
                            { tag: 'div', params: { class: 'contact-item' }, children: [
                                { tag: 'h3', children: [{ textKey: 'contact.phone.label' }] },
                                { tag: 'p', children: [{ textKey: 'contact.phone.value' }] }
                            ]},
                            { tag: 'div', params: { class: 'contact-item' }, children: [
                                { tag: 'h3', children: [{ textKey: 'contact.address.label' }] },
                                { tag: 'p', children: [{ textKey: 'contact.address.value' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'setTranslationKeys', params: { 
                    language: 'en', 
                    translations: {
                        site: { name: 'YourBusiness', tagline: 'Excellence in every detail' },
                        page: { titles: { home: 'Home', about: 'About Us', services: 'Services', contact: 'Contact' } },
                        nav: { home: 'Home', about: 'About', services: 'Services', contact: 'Contact' },
                        common: { cta: 'Get Started' },
                        footer: { copyright: '2025 YourBusiness. All rights reserved.' },
                        home: { 
                            hero: { title: 'Welcome to YourBusiness', subtitle: 'We deliver exceptional solutions for your needs.' },
                            features: { title: 'Why Choose Us', f1: { title: 'Quality', desc: 'Top-tier quality in everything we do.' }, f2: { title: 'Experience', desc: 'Years of industry expertise.' }, f3: { title: 'Support', desc: '24/7 customer support.' } }
                        },
                        about: { title: 'About Us', subtitle: 'Learn more about our story', story: { title: 'Our Story', content: 'Founded with a vision to deliver excellence...' } },
                        services: { title: 'Our Services', subtitle: 'What we offer', s1: { title: 'Consulting', desc: 'Expert advice for your business.' }, s2: { title: 'Development', desc: 'Custom solutions tailored to you.' }, s3: { title: 'Support', desc: 'Ongoing assistance when you need it.' } },
                        contact: { title: 'Contact Us', subtitle: 'Get in touch', email: { label: 'Email', value: 'contact@yourbusiness.com' }, phone: { label: 'Phone', value: '+1 (555) 123-4567' }, address: { label: 'Address', value: '123 Business St, City, Country' } }
                    }
                }},
                { command: 'editStyles', params: { 
                    content: getStarterBusinessStyles()
                }},
                { command: 'setRootVariables', params: { 
                    variables: {
                        '--color-primary': '#0d9488',
                        '--color-primary-light': '#14b8a6',
                        '--color-primary-dark': '#0f766e',
                        '--color-secondary': '#f59e0b',
                        '--color-bg': '#f8fafc',
                        '--color-bg-card': '#ffffff',
                        '--color-bg-footer': '#1e293b',
                        '--color-text': '#1e293b',
                        '--color-text-muted': '#64748b',
                        '--color-text-light': '#f8fafc',
                        '--color-border': '#e2e8f0',
                        '--shadow-color': 'rgba(15, 23, 42, 0.1)'
                    }
                }},
                { command: 'setMultilingual', params: { enabled: false } }
            ]
        },
        
        'starter-business-multilingual': {
            name: 'Starter Business (Multilingual)',
            description: 'Business website with EN + FR translations',
            commands: [
                { command: 'setMultilingual', params: { enabled: true } },
                { command: 'addLang', params: { code: 'fr', name: 'Francais' } },
                { command: 'editStructure', params: {
                    type: 'component',
                    name: 'lang-switch',
                    structure: {
                        tag: 'div', params: { class: 'lang-switch' }, children: [
                            { tag: 'a', params: { href: '{{__current_page;lang=en}}', class: 'lang-link' }, children: [{ textKey: '__RAW__English' }] },
                            { tag: 'a', params: { href: '{{__current_page;lang=fr}}', class: 'lang-link' }, children: [{ textKey: '__RAW__Français' }] }
                        ]
                    }
                }},
                { command: 'addRoute', params: { route: 'about' } },
                { command: 'addRoute', params: { route: 'services' } },
                { command: 'addRoute', params: { route: 'contact' } },
                { command: 'editStructure', params: { 
                    type: 'menu',
                    structure: [
                        { tag: 'nav', params: { class: 'main-nav' }, children: [
                            { tag: 'a', params: { href: '/', class: 'nav-logo' }, children: [{ textKey: 'site.name' }] },
                            { tag: 'ul', params: { class: 'nav-links' }, children: [
                                { tag: 'li', children: [{ tag: 'a', params: { href: '/' }, children: [{ textKey: 'nav.home' }] }] },
                                { tag: 'li', children: [{ tag: 'a', params: { href: '/services' }, children: [{ textKey: 'nav.services' }] }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'footer',
                    structure: [
                        { tag: 'footer', params: { class: 'main-footer' }, children: [
                            { tag: 'div', params: { class: 'footer-content' }, children: [
                                { tag: 'div', params: { class: 'footer-brand' }, children: [
                                    { tag: 'span', params: { class: 'footer-logo' }, children: [{ textKey: 'site.name' }] },
                                    { tag: 'p', children: [{ textKey: 'site.tagline' }] }
                                ]},
                                { tag: 'nav', params: { class: 'footer-nav' }, children: [
                                    { tag: 'a', params: { href: '/about' }, children: [{ textKey: 'nav.about' }] },
                                    { tag: 'a', params: { href: '/contact' }, children: [{ textKey: 'nav.contact' }] }
                                ]},
                                { tag: 'div', params: { class: 'footer-lang' }, children: [
                                    { tag: 'span', params: { class: 'lang-label' }, children: [{ textKey: 'footer.language' }] },
                                    { component: 'lang-switch' }
                                ]}
                            ]},
                            { tag: 'div', params: { class: 'footer-bottom' }, children: [
                                { tag: 'p', children: [{ textKey: 'footer.copyright' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'home', 
                    structure: [
                        { tag: 'section', params: { class: 'hero' }, children: [
                            { tag: 'h1', children: [{ textKey: 'home.hero.title' }] },
                            { tag: 'p', children: [{ textKey: 'home.hero.subtitle' }] },
                            { tag: 'a', params: { href: '/contact', class: 'btn btn-primary' }, children: [{ textKey: 'common.cta' }] }
                        ]},
                        { tag: 'section', params: { class: 'features' }, children: [
                            { tag: 'h2', children: [{ textKey: 'home.features.title' }] },
                            { tag: 'div', params: { class: 'features-grid' }, children: [
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'home.features.f1.title' }] },
                                    { tag: 'p', children: [{ textKey: 'home.features.f1.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'home.features.f2.title' }] },
                                    { tag: 'p', children: [{ textKey: 'home.features.f2.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'home.features.f3.title' }] },
                                    { tag: 'p', children: [{ textKey: 'home.features.f3.desc' }] }
                                ]}
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'about', 
                    structure: [
                        { tag: 'section', params: { class: 'page-header' }, children: [
                            { tag: 'h1', children: [{ textKey: 'about.title' }] },
                            { tag: 'p', children: [{ textKey: 'about.subtitle' }] }
                        ]},
                        { tag: 'section', params: { class: 'about-content' }, children: [
                            { tag: 'h2', children: [{ textKey: 'about.story.title' }] },
                            { tag: 'p', children: [{ textKey: 'about.story.content' }] }
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'services', 
                    structure: [
                        { tag: 'section', params: { class: 'page-header' }, children: [
                            { tag: 'h1', children: [{ textKey: 'services.title' }] },
                            { tag: 'p', children: [{ textKey: 'services.subtitle' }] }
                        ]},
                        { tag: 'section', params: { class: 'services-grid' }, children: [
                            { tag: 'div', params: { class: 'service-card' }, children: [
                                { tag: 'h3', children: [{ textKey: 'services.s1.title' }] },
                                { tag: 'p', children: [{ textKey: 'services.s1.desc' }] }
                            ]},
                            { tag: 'div', params: { class: 'service-card' }, children: [
                                { tag: 'h3', children: [{ textKey: 'services.s2.title' }] },
                                { tag: 'p', children: [{ textKey: 'services.s2.desc' }] }
                            ]},
                            { tag: 'div', params: { class: 'service-card' }, children: [
                                { tag: 'h3', children: [{ textKey: 'services.s3.title' }] },
                                { tag: 'p', children: [{ textKey: 'services.s3.desc' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'contact', 
                    structure: [
                        { tag: 'section', params: { class: 'page-header' }, children: [
                            { tag: 'h1', children: [{ textKey: 'contact.title' }] },
                            { tag: 'p', children: [{ textKey: 'contact.subtitle' }] }
                        ]},
                        { tag: 'section', params: { class: 'contact-info' }, children: [
                            { tag: 'div', params: { class: 'contact-item' }, children: [
                                { tag: 'h3', children: [{ textKey: 'contact.email.label' }] },
                                { tag: 'p', children: [{ textKey: 'contact.email.value' }] }
                            ]},
                            { tag: 'div', params: { class: 'contact-item' }, children: [
                                { tag: 'h3', children: [{ textKey: 'contact.phone.label' }] },
                                { tag: 'p', children: [{ textKey: 'contact.phone.value' }] }
                            ]},
                            { tag: 'div', params: { class: 'contact-item' }, children: [
                                { tag: 'h3', children: [{ textKey: 'contact.address.label' }] },
                                { tag: 'p', children: [{ textKey: 'contact.address.value' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'setTranslationKeys', params: { 
                    language: 'en', 
                    translations: {
                        site: { name: 'YourBusiness', tagline: 'Excellence in every detail' },
                        page: { titles: { home: 'Home', about: 'About Us', services: 'Services', contact: 'Contact' } },
                        nav: { home: 'Home', about: 'About', services: 'Services', contact: 'Contact' },
                        common: { cta: 'Get Started' },
                        footer: { copyright: '2025 YourBusiness. All rights reserved.', language: 'Language:' },
                        home: { 
                            hero: { title: 'Welcome to YourBusiness', subtitle: 'We deliver exceptional solutions for your needs.' },
                            features: { title: 'Why Choose Us', f1: { title: 'Quality', desc: 'Top-tier quality in everything we do.' }, f2: { title: 'Experience', desc: 'Years of industry expertise.' }, f3: { title: 'Support', desc: '24/7 customer support.' } }
                        },
                        about: { title: 'About Us', subtitle: 'Learn more about our story', story: { title: 'Our Story', content: 'Founded with a vision to deliver excellence...' } },
                        services: { title: 'Our Services', subtitle: 'What we offer', s1: { title: 'Consulting', desc: 'Expert advice for your business.' }, s2: { title: 'Development', desc: 'Custom solutions tailored to you.' }, s3: { title: 'Support', desc: 'Ongoing assistance when you need it.' } },
                        contact: { title: 'Contact Us', subtitle: 'Get in touch', email: { label: 'Email', value: 'contact@yourbusiness.com' }, phone: { label: 'Phone', value: '+1 (555) 123-4567' }, address: { label: 'Address', value: '123 Business St, City, Country' } }
                    }
                }},
                { command: 'setTranslationKeys', params: { 
                    language: 'fr', 
                    translations: {
                        site: { name: 'VotreEntreprise', tagline: 'L\'excellence dans chaque detail' },
                        page: { titles: { home: 'Accueil', about: 'A propos', services: 'Services', contact: 'Contact' } },
                        nav: { home: 'Accueil', about: 'A propos', services: 'Services', contact: 'Contact' },
                        common: { cta: 'Commencer' },
                        footer: { copyright: '2025 VotreEntreprise. Tous droits reserves.', language: 'Langue :' },
                        home: { 
                            hero: { title: 'Bienvenue chez VotreEntreprise', subtitle: 'Nous offrons des solutions exceptionnelles pour vos besoins.' },
                            features: { title: 'Pourquoi nous choisir', f1: { title: 'Qualite', desc: 'Une qualite superieure dans tout ce que nous faisons.' }, f2: { title: 'Experience', desc: 'Des annees d\'expertise dans le secteur.' }, f3: { title: 'Support', desc: 'Assistance client 24h/24 et 7j/7.' } }
                        },
                        about: { title: 'A propos de nous', subtitle: 'Decouvrez notre histoire', story: { title: 'Notre histoire', content: 'Fondee avec la vision de delivrer l\'excellence...' } },
                        services: { title: 'Nos Services', subtitle: 'Ce que nous proposons', s1: { title: 'Conseil', desc: 'Des conseils experts pour votre entreprise.' }, s2: { title: 'Developpement', desc: 'Des solutions sur mesure adaptees a vos besoins.' }, s3: { title: 'Support', desc: 'Une assistance continue quand vous en avez besoin.' } },
                        contact: { title: 'Contactez-nous', subtitle: 'Prenez contact', email: { label: 'Email', value: 'contact@votreentreprise.com' }, phone: { label: 'Telephone', value: '+33 (0)1 23 45 67 89' }, address: { label: 'Adresse', value: '123 Rue des Affaires, Ville, France' } }
                    }
                }},
                { command: 'editStyles', params: { 
                    content: getStarterBusinessMultilingualStyles()
                }},
                { command: 'setRootVariables', params: { 
                    variables: {
                        '--color-primary': '#f59e0b',
                        '--color-primary-light': '#fbbf24',
                        '--color-primary-dark': '#d97706',
                        '--color-secondary': '#10b981',
                        '--color-bg': '#0f172a',
                        '--color-bg-card': '#1e293b',
                        '--color-bg-card-grad': '#334155',
                        '--color-bg-footer': '#020617',
                        '--color-text': '#f8fafc',
                        '--color-text-muted': '#94a3b8',
                        '--color-text-dark': '#0f172a',
                        '--color-border': 'rgba(248, 250, 252, 0.1)',
                        '--shadow-color': 'rgba(0, 0, 0, 0.3)',
                        '--shadow-primary': 'rgba(245, 158, 11, 0.4)'
                    }
                }}
            ]
        },
        
        'landing-page': {
            name: 'Landing Page',
            description: 'Single-page landing with hero, features and CTA',
            commands: [
                { command: 'editStructure', params: { 
                    type: 'menu',
                    structure: [
                        { tag: 'nav', params: { class: 'landing-nav' }, children: [
                            { tag: 'a', params: { href: '#hero', class: 'nav-logo' }, children: [{ textKey: 'site.name' }] },
                            { tag: 'ul', params: { class: 'nav-links' }, children: [
                                { tag: 'li', children: [{ tag: 'a', params: { href: '#features' }, children: [{ textKey: 'nav.features' }] }] },
                                { tag: 'li', children: [{ tag: 'a', params: { href: '#contact' }, children: [{ textKey: 'nav.contact' }] }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'footer',
                    structure: [
                        { tag: 'footer', params: { class: 'landing-footer' }, children: [
                            { tag: 'div', params: { class: 'footer-content' }, children: [
                                { tag: 'p', children: [{ textKey: 'footer.copyright' }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'home', 
                    structure: [
                        { tag: 'section', params: { class: 'hero', id: 'hero' }, children: [
                            { tag: 'div', params: { class: 'hero-content' }, children: [
                                { tag: 'h1', params: { class: 'hero-title' }, children: [{ textKey: 'landing.hero.title' }] },
                                { tag: 'p', params: { class: 'hero-subtitle' }, children: [{ textKey: 'landing.hero.subtitle' }] },
                                { tag: 'div', params: { class: 'hero-cta' }, children: [
                                    { tag: 'a', params: { href: '#contact', class: 'btn btn-primary' }, children: [{ textKey: 'landing.hero.cta' }] },
                                    { tag: 'a', params: { href: '#features', class: 'btn btn-secondary' }, children: [{ textKey: 'common.learnMore' }] }
                                ]}
                            ]}
                        ]},
                        { tag: 'section', params: { class: 'features', id: 'features' }, children: [
                            { tag: 'h2', params: { class: 'section-title' }, children: [{ textKey: 'landing.features.title' }] },
                            { tag: 'div', params: { class: 'features-grid' }, children: [
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'landing.features.f1.title' }] },
                                    { tag: 'p', children: [{ textKey: 'landing.features.f1.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'landing.features.f2.title' }] },
                                    { tag: 'p', children: [{ textKey: 'landing.features.f2.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'landing.features.f3.title' }] },
                                    { tag: 'p', children: [{ textKey: 'landing.features.f3.desc' }] }
                                ]}
                            ]}
                        ]},
                        { tag: 'section', params: { class: 'cta-section', id: 'contact' }, children: [
                            { tag: 'h2', children: [{ textKey: 'landing.cta.title' }] },
                            { tag: 'p', children: [{ textKey: 'landing.cta.subtitle' }] },
                            { tag: 'a', params: { href: '#', class: 'btn btn-primary btn-lg' }, children: [{ textKey: 'landing.cta.button' }] }
                        ]}
                    ]
                }},
                { command: 'setTranslationKeys', params: { 
                    language: 'en', 
                    translations: {
                        site: { name: 'YourBrand' },
                        page: { titles: { home: 'Home' } },
                        nav: { features: 'Features', contact: 'Contact' },
                        common: { learnMore: 'Learn More' },
                        footer: { copyright: '2025 YourBrand. All rights reserved.' },
                        landing: {
                            hero: { title: 'Build Something Amazing', subtitle: 'The modern way to create beautiful websites without the complexity.', cta: 'Get Started Free' },
                            features: { title: 'Why Choose Us', f1: { title: 'Fast & Simple', desc: 'Get up and running in minutes, not hours.' }, f2: { title: 'Fully Customizable', desc: 'Make it yours with powerful customization options.' }, f3: { title: 'Always Reliable', desc: '99.9% uptime guaranteed for your peace of mind.' } },
                            cta: { title: 'Ready to Get Started?', subtitle: 'Join thousands of satisfied customers today.', button: 'Start Your Free Trial' }
                        }
                    }
                }},
                { command: 'editStyles', params: { 
                    content: getLandingPageStyles()
                }},
                { command: 'setRootVariables', params: { 
                    variables: {
                        '--color-primary': '#7c3aed',
                        '--color-primary-dark': '#6d28d9',
                        '--color-primary-light': '#8b5cf6',
                        '--color-secondary': '#ec4899',
                        '--primary-rgb': '124, 58, 237',
                        '--secondary-rgb': '236, 72, 153',
                        '--color-bg': '#fdfcff',
                        '--color-bg-alt': '#faf5ff',
                        '--color-bg-accent': '#f3e8ff',
                        '--color-bg-footer': '#1e1b4b',
                        '--color-bg-footer-dark': '#0f0d24',
                        '--color-text': '#1f2937',
                        '--color-text-muted': '#64748b',
                        '--color-text-light': '#9ca3af',
                        '--color-text-white': '#ffffff',
                        '--color-border-light': '#e9d5ff',
                        '--color-border-footer': 'rgba(139, 92, 246, 0.3)'
                    }
                }},
                { command: 'setMultilingual', params: { enabled: false } }
            ]
        },
        
        'landing-page-multilingual': {
            name: 'Landing Page (Multilingual)',
            description: 'Single-page landing with EN + FR',
            commands: [
                { command: 'setMultilingual', params: { enabled: true } },
                { command: 'addLang', params: { code: 'fr', name: 'Francais' } },
                { command: 'editStructure', params: {
                    type: 'component',
                    name: 'lang-switch',
                    structure: {
                        tag: 'div', params: { class: 'lang-switch' }, children: [
                            { tag: 'a', params: { href: '{{__current_page;lang=en}}', class: 'lang-link' }, children: [{ textKey: '__RAW__English' }] },
                            { tag: 'a', params: { href: '{{__current_page;lang=fr}}', class: 'lang-link' }, children: [{ textKey: '__RAW__Français' }] }
                        ]
                    }
                }},
                { command: 'editStructure', params: { 
                    type: 'menu',
                    structure: [
                        { tag: 'nav', params: { class: 'landing-nav' }, children: [
                            { tag: 'a', params: { href: '#hero', class: 'nav-logo' }, children: [{ textKey: 'site.name' }] },
                            { tag: 'ul', params: { class: 'nav-links' }, children: [
                                { tag: 'li', children: [{ tag: 'a', params: { href: '#features' }, children: [{ textKey: 'nav.features' }] }] },
                                { tag: 'li', children: [{ tag: 'a', params: { href: '#contact' }, children: [{ textKey: 'nav.contact' }] }] }
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'footer',
                    structure: [
                        { tag: 'footer', params: { class: 'landing-footer' }, children: [
                            { tag: 'div', params: { class: 'footer-content' }, children: [
                                { tag: 'p', children: [{ textKey: 'footer.copyright' }] },
                                { tag: 'div', params: { class: 'footer-lang' }, children: [
                                    { tag: 'span', params: { class: 'lang-label' }, children: [{ textKey: 'footer.language' }] },
                                    { component: 'lang-switch' }
                                ]}
                            ]}
                        ]}
                    ]
                }},
                { command: 'editStructure', params: { 
                    type: 'page',
                    name: 'home', 
                    structure: [
                        { tag: 'section', params: { class: 'hero', id: 'hero' }, children: [
                            { tag: 'div', params: { class: 'hero-content' }, children: [
                                { tag: 'h1', params: { class: 'hero-title' }, children: [{ textKey: 'landing.hero.title' }] },
                                { tag: 'p', params: { class: 'hero-subtitle' }, children: [{ textKey: 'landing.hero.subtitle' }] },
                                { tag: 'div', params: { class: 'hero-cta' }, children: [
                                    { tag: 'a', params: { href: '#contact', class: 'btn btn-primary' }, children: [{ textKey: 'landing.hero.cta' }] },
                                    { tag: 'a', params: { href: '#features', class: 'btn btn-secondary' }, children: [{ textKey: 'common.learnMore' }] }
                                ]}
                            ]}
                        ]},
                        { tag: 'section', params: { class: 'features', id: 'features' }, children: [
                            { tag: 'h2', params: { class: 'section-title' }, children: [{ textKey: 'landing.features.title' }] },
                            { tag: 'div', params: { class: 'features-grid' }, children: [
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'landing.features.f1.title' }] },
                                    { tag: 'p', children: [{ textKey: 'landing.features.f1.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'landing.features.f2.title' }] },
                                    { tag: 'p', children: [{ textKey: 'landing.features.f2.desc' }] }
                                ]},
                                { tag: 'div', params: { class: 'feature-card' }, children: [
                                    { tag: 'h3', children: [{ textKey: 'landing.features.f3.title' }] },
                                    { tag: 'p', children: [{ textKey: 'landing.features.f3.desc' }] }
                                ]}
                            ]}
                        ]},
                        { tag: 'section', params: { class: 'cta-section', id: 'contact' }, children: [
                            { tag: 'h2', children: [{ textKey: 'landing.cta.title' }] },
                            { tag: 'p', children: [{ textKey: 'landing.cta.subtitle' }] },
                            { tag: 'a', params: { href: '#', class: 'btn btn-primary btn-lg' }, children: [{ textKey: 'landing.cta.button' }] }
                        ]}
                    ]
                }},
                { command: 'setTranslationKeys', params: { 
                    language: 'en', 
                    translations: {
                        site: { name: 'YourBrand' },
                        page: { titles: { home: 'Home' } },
                        nav: { features: 'Features', contact: 'Contact' },
                        common: { learnMore: 'Learn More' },
                        footer: { copyright: '2025 YourBrand. All rights reserved.', language: 'Language:' },
                        landing: {
                            hero: { title: 'Build Something Amazing', subtitle: 'The modern way to create beautiful websites without the complexity.', cta: 'Get Started Free' },
                            features: { title: 'Why Choose Us', f1: { title: 'Fast & Simple', desc: 'Get up and running in minutes, not hours.' }, f2: { title: 'Fully Customizable', desc: 'Make it yours with powerful customization options.' }, f3: { title: 'Always Reliable', desc: '99.9% uptime guaranteed for your peace of mind.' } },
                            cta: { title: 'Ready to Get Started?', subtitle: 'Join thousands of satisfied customers today.', button: 'Start Your Free Trial' }
                        }
                    }
                }},
                { command: 'setTranslationKeys', params: { 
                    language: 'fr', 
                    translations: {
                        site: { name: 'VotreMarque' },
                        page: { titles: { home: 'Accueil' } },
                        nav: { features: 'Fonctionnalites', contact: 'Contact' },
                        common: { learnMore: 'En savoir plus' },
                        footer: { copyright: '2025 VotreMarque. Tous droits reserves.', language: 'Langue :' },
                        landing: {
                            hero: { title: 'Creez quelque chose d\'incroyable', subtitle: 'La maniere moderne de creer de beaux sites web sans complexite.', cta: 'Commencer gratuitement' },
                            features: { title: 'Pourquoi nous choisir', f1: { title: 'Rapide et simple', desc: 'Soyez operationnel en minutes, pas en heures.' }, f2: { title: 'Entierement personnalisable', desc: 'Faites-le votre avec des options de personnalisation puissantes.' }, f3: { title: 'Toujours fiable', desc: '99.9% de disponibilite garantie pour votre tranquillite d\'esprit.' } },
                            cta: { title: 'Pret a commencer ?', subtitle: 'Rejoignez des milliers de clients satisfaits aujourd\'hui.', button: 'Commencez votre essai gratuit' }
                        }
                    }
                }},
                { command: 'editStyles', params: { 
                    content: getLandingPageMultilingualStyles()
                }},
                { command: 'setRootVariables', params: { 
                    variables: {
                        '--color-primary': '#7c3aed',
                        '--color-primary-dark': '#6d28d9',
                        '--color-primary-light': '#8b5cf6',
                        '--color-secondary': '#ec4899',
                        '--primary-rgb': '124, 58, 237',
                        '--secondary-rgb': '236, 72, 153',
                        '--color-bg': '#fdfcff',
                        '--color-bg-alt': '#faf5ff',
                        '--color-bg-accent': '#f3e8ff',
                        '--color-bg-footer': '#1e1b4b',
                        '--color-bg-footer-dark': '#0f0d24',
                        '--color-text': '#1f2937',
                        '--color-text-muted': '#64748b',
                        '--color-text-light': '#9ca3af',
                        '--color-text-white': '#ffffff',
                        '--color-border-light': '#e9d5ff',
                        '--color-border-footer': 'rgba(139, 92, 246, 0.3)'
                    }
                }}
            ]
        }
    };

    // ============================================
    // Style Getter Functions (for templates)
    // ============================================

    function getStarterBusinessStyles() {
        return `/* GLOBAL STYLES */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: var(--color-text); line-height: 1.6; background: var(--color-bg); }
a { color: var(--color-primary); text-decoration: none; transition: color 0.3s; }
a:hover { color: var(--color-primary-dark); }

/* NAVIGATION */
.main-nav { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: var(--color-bg-card); box-shadow: 0 2px 10px var(--shadow-color); position: sticky; top: 0; z-index: 100; }
.nav-logo { font-size: 1.5rem; font-weight: 700; color: var(--color-primary); }
.nav-links { display: flex; gap: 2rem; list-style: none; }
.nav-links a { color: var(--color-text); font-weight: 500; }
.nav-links a:hover { color: var(--color-primary); }

/* HERO */
.hero { text-align: center; padding: 6rem 2rem; background: linear-gradient(135deg, var(--color-bg-card) 0%, var(--color-bg) 100%); }
.hero h1 { font-size: 3rem; margin-bottom: 1rem; color: var(--color-text); }
.hero p { font-size: 1.25rem; color: var(--color-text-muted); margin-bottom: 2rem; max-width: 600px; margin-left: auto; margin-right: auto; }

/* BUTTONS */
.btn { display: inline-block; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.3s; }
.btn-primary { background: var(--color-primary); color: white; }
.btn-primary:hover { background: var(--color-primary-dark); color: white; }

/* FEATURES */
.features { padding: 5rem 2rem; max-width: 1200px; margin: 0 auto; }
.features h2 { text-align: center; font-size: 2rem; margin-bottom: 3rem; }
.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; }
.feature-card { padding: 2rem; background: var(--color-bg-card); border-radius: 1rem; box-shadow: 0 4px 20px var(--shadow-color); transition: transform 0.3s; }
.feature-card:hover { transform: translateY(-5px); }
.feature-card h3 { color: var(--color-primary); margin-bottom: 0.5rem; }
.feature-card p { color: var(--color-text-muted); }

/* PAGE HEADER */
.page-header { text-align: center; padding: 4rem 2rem; background: var(--color-bg-card); }
.page-header h1 { font-size: 2.5rem; margin-bottom: 0.5rem; }
.page-header p { color: var(--color-text-muted); font-size: 1.1rem; }

/* SERVICES */
.services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; padding: 3rem 2rem; max-width: 1200px; margin: 0 auto; }
.service-card { padding: 2rem; background: var(--color-bg-card); border-radius: 0.5rem; border-left: 4px solid var(--color-primary); box-shadow: 0 2px 10px var(--shadow-color); }
.service-card h3 { color: var(--color-primary); margin-bottom: 0.5rem; }
.service-card p { color: var(--color-text-muted); }

/* CONTACT */
.contact-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; padding: 3rem 2rem; max-width: 900px; margin: 0 auto; }
.contact-item { padding: 1.5rem; background: var(--color-bg-card); border-radius: 0.5rem; text-align: center; box-shadow: 0 2px 10px var(--shadow-color); }
.contact-item h3 { color: var(--color-primary); margin-bottom: 0.5rem; font-size: 0.9rem; text-transform: uppercase; }
.contact-item p { color: var(--color-text); }

/* ABOUT */
.about-content { padding: 3rem 2rem; max-width: 800px; margin: 0 auto; }
.about-content h2 { color: var(--color-primary); margin-bottom: 1rem; }
.about-content p { color: var(--color-text-muted); line-height: 1.8; }

/* FOOTER */
.main-footer { background: var(--color-bg-footer); color: var(--color-text-light); padding: 3rem 2rem 1rem; }
.footer-content { display: flex; justify-content: space-between; align-items: flex-start; max-width: 1200px; margin: 0 auto; flex-wrap: wrap; gap: 2rem; }
.footer-logo { font-size: 1.25rem; font-weight: 700; color: var(--color-primary); }
.footer-brand p { color: var(--color-text-muted); margin-top: 0.5rem; font-size: 0.9rem; }
.footer-nav { display: flex; gap: 1.5rem; }
.footer-nav a { color: var(--color-text-muted); font-size: 0.9rem; }
.footer-nav a:hover { color: var(--color-primary); }
.footer-bottom { text-align: center; padding-top: 2rem; margin-top: 2rem; border-top: 1px solid rgba(255,255,255,0.1); color: var(--color-text-muted); font-size: 0.85rem; }`;
    }

    function getStarterBusinessMultilingualStyles() {
        return `/* GLOBAL STYLES - DARK THEME */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: var(--color-text); line-height: 1.6; background: var(--color-bg); }
a { color: var(--color-primary); text-decoration: none; transition: all 0.3s; }
a:hover { color: var(--color-primary-light); }

/* NAVIGATION */
.main-nav { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: linear-gradient(180deg, var(--color-bg-card) 0%, var(--color-bg-card-grad) 100%); box-shadow: 0 4px 20px var(--shadow-color); position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--color-border); }
.nav-logo { font-size: 1.5rem; font-weight: 700; color: var(--color-primary); text-shadow: 0 0 20px var(--shadow-primary); }
.nav-links { display: flex; gap: 2rem; list-style: none; }
.nav-links a { color: var(--color-text-muted); font-weight: 500; position: relative; }
.nav-links a::after { content: ''; position: absolute; bottom: -4px; left: 0; width: 0; height: 2px; background: var(--color-primary); transition: width 0.3s; }
.nav-links a:hover { color: var(--color-text); }
.nav-links a:hover::after { width: 100%; }

/* HERO */
.hero { text-align: center; padding: 8rem 2rem; background: linear-gradient(135deg, var(--color-bg) 0%, var(--color-bg-card) 50%, var(--color-bg) 100%); position: relative; overflow: hidden; }
.hero::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(circle at 30% 50%, var(--shadow-primary) 0%, transparent 50%); opacity: 0.3; }
.hero h1 { font-size: 3.5rem; margin-bottom: 1.5rem; color: var(--color-text); position: relative; text-shadow: 0 0 40px var(--shadow-primary); }
.hero p { font-size: 1.35rem; color: var(--color-text-muted); margin-bottom: 2.5rem; max-width: 650px; margin-left: auto; margin-right: auto; position: relative; }

/* BUTTONS */
.btn { display: inline-block; padding: 1rem 2rem; border-radius: 0.75rem; font-weight: 600; transition: all 0.3s; position: relative; overflow: hidden; }
.btn-primary { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: var(--color-text-dark); box-shadow: 0 4px 20px var(--shadow-primary); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px var(--shadow-primary); color: var(--color-text-dark); }

/* FEATURES */
.features { padding: 6rem 2rem; max-width: 1200px; margin: 0 auto; background: var(--color-bg); }
.features h2 { text-align: center; font-size: 2.2rem; margin-bottom: 1rem; color: var(--color-text); font-weight: 700; }
.features > p { text-align: center; color: var(--color-text-muted); margin-bottom: 4rem; }
.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; }
.feature-card { padding: 2.5rem; background: linear-gradient(145deg, var(--color-bg-card) 0%, var(--color-bg-card-grad) 100%); border: 1px solid rgba(248, 250, 252, 0.08); border-radius: 1rem; text-align: center; transition: all 0.4s ease; position: relative; overflow: hidden; }
.feature-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); transform: scaleX(0); transition: transform 0.4s ease; }
.feature-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px var(--shadow-color); border-color: rgba(245, 158, 11, 0.3); }
.feature-card:hover::before { transform: scaleX(1); }
.feature-card h3 { color: var(--color-primary); margin-bottom: 1rem; font-size: 1.3rem; font-weight: 600; }
.feature-card p { color: var(--color-text-muted); line-height: 1.7; }

/* PAGE HEADER */
.page-header { text-align: center; padding: 5rem 2rem; background: linear-gradient(180deg, var(--color-bg-card) 0%, var(--color-bg) 100%); border-bottom: 1px solid var(--color-border); }
.page-header h1 { font-size: 2.8rem; margin-bottom: 0.75rem; text-shadow: 0 0 30px var(--shadow-primary); }
.page-header p { color: var(--color-text-muted); font-size: 1.2rem; max-width: 600px; margin: 0 auto; }

/* SERVICES SECTION */
.services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem; padding: 4rem 2rem; max-width: 1200px; margin: 0 auto; }
.service-card { padding: 2.5rem; background: linear-gradient(145deg, var(--color-bg-card) 0%, var(--color-bg-card-grad) 100%); border-radius: 1rem; border-left: 4px solid var(--color-primary); box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2); transition: all 0.3s ease; }
.service-card:hover { transform: translateX(8px); box-shadow: 0 8px 30px var(--shadow-color); border-left-color: var(--color-primary-light); }
.service-card h3 { color: var(--color-primary); margin-bottom: 0.75rem; font-size: 1.25rem; font-weight: 600; }
.service-card p { color: var(--color-text-muted); line-height: 1.7; }

/* CONTACT SECTION */
.contact-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2.5rem; padding: 4rem 2rem; max-width: 900px; margin: 0 auto; }
.contact-item { padding: 2rem; background: linear-gradient(145deg, var(--color-bg-card) 0%, var(--color-bg-card-grad) 100%); border-radius: 1rem; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2); text-align: center; transition: all 0.3s ease; border: 1px solid rgba(248, 250, 252, 0.08); }
.contact-item:hover { transform: scale(1.02); box-shadow: 0 8px 30px var(--shadow-color); border-color: var(--color-primary); }
.contact-item h3 { color: var(--color-primary); margin-bottom: 0.75rem; font-size: 1.1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
.contact-item p { color: var(--color-text); font-size: 1.1rem; }

/* ABOUT SECTION */
.about-content { padding: 4rem 2rem; max-width: 800px; margin: 0 auto; }
.about-content h2 { color: var(--color-text); margin-bottom: 1.5rem; font-size: 1.8rem; position: relative; display: inline-block; }
.about-content h2::after { content: ''; position: absolute; bottom: -8px; left: 0; width: 60px; height: 3px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); border-radius: 2px; }
.about-content p { color: var(--color-text-muted); line-height: 1.9; font-size: 1.1rem; margin-top: 2rem; }

/* FOOTER */
.main-footer { background: linear-gradient(180deg, var(--color-bg-footer) 0%, #000000 100%); color: var(--color-text); padding: 4rem 2rem 1.5rem; position: relative; }
.main-footer::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary), var(--color-primary)); }
.footer-content { display: flex; justify-content: space-between; align-items: flex-start; max-width: 1200px; margin: 0 auto; flex-wrap: wrap; gap: 3rem; }
.footer-logo { font-size: 1.4rem; font-weight: 700; color: var(--color-primary); }
.footer-brand p { color: var(--color-text-muted); margin-top: 0.75rem; font-size: 0.95rem; line-height: 1.6; }
.footer-nav { display: flex; gap: 2rem; }
.footer-nav a { color: var(--color-text-muted); text-decoration: none; font-weight: 500; position: relative; }
.footer-nav a:hover { color: var(--color-primary); }
.footer-lang { display: flex; align-items: center; gap: 1rem; }
.lang-label { color: var(--color-text-muted); font-size: 0.9rem; font-weight: 500; }
.lang-switch { display: flex; gap: 0.5rem; }
.lang-link { color: var(--color-text-muted); text-decoration: none; padding: 0.4rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(245, 158, 11, 0.3); background: rgba(245, 158, 11, 0.1); transition: all 0.3s ease; }
.lang-link:hover { color: var(--color-text-dark); background: var(--color-primary); border-color: var(--color-primary); box-shadow: 0 0 20px var(--shadow-primary); }
.footer-bottom { text-align: center; padding-top: 2.5rem; margin-top: 3rem; border-top: 1px solid rgba(255, 255, 255, 0.08); color: var(--color-text-muted); font-size: 0.9rem; }`;
    }

    function getLandingPageStyles() {
        return `/* GLOBAL STYLES */
html { scroll-behavior: smooth; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: var(--color-text); line-height: 1.7; background: var(--color-bg); }
a { transition: all 0.3s ease; }

/* NAVIGATION */
.landing-nav { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 3rem; position: fixed; top: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); z-index: 100; box-shadow: 0 2px 30px rgba(var(--primary-rgb), 0.1); }
.nav-logo { font-size: 1.7rem; font-weight: 800; color: var(--color-primary); text-decoration: none; letter-spacing: -0.5px; }
.nav-links { display: flex; gap: 2.5rem; list-style: none; }
.nav-links a { text-decoration: none; color: var(--color-text-muted); font-weight: 500; position: relative; padding: 0.5rem 0; }
.nav-links a::after { content: ''; position: absolute; bottom: 0; left: 50%; width: 0; height: 2px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); transition: all 0.3s ease; transform: translateX(-50%); }
.nav-links a:hover { color: var(--color-primary); }
.nav-links a:hover::after { width: 100%; }

/* HERO SECTION */
.hero { min-height: 100vh; display: flex; align-items: center; justify-content: center; text-align: center; padding: 8rem 2rem 6rem; background: linear-gradient(160deg, var(--color-bg) 0%, var(--color-bg-alt) 40%, var(--color-bg-accent) 100%); position: relative; overflow: hidden; }
.hero::before { content: ''; position: absolute; top: 10%; right: 5%; width: 500px; height: 500px; background: radial-gradient(circle, rgba(var(--primary-rgb), 0.15) 0%, transparent 70%); pointer-events: none; border-radius: 50%; animation: float 8s ease-in-out infinite; }
.hero::after { content: ''; position: absolute; bottom: 20%; left: 10%; width: 300px; height: 300px; background: radial-gradient(circle, rgba(var(--secondary-rgb), 0.1) 0%, transparent 70%); pointer-events: none; border-radius: 50%; animation: float 6s ease-in-out infinite reverse; }
@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
.hero-content { position: relative; z-index: 1; max-width: 800px; }
.hero-title { font-size: 4rem; margin-bottom: 1.5rem; font-weight: 800; letter-spacing: -2px; line-height: 1.1; background: linear-gradient(135deg, var(--color-text) 0%, var(--color-primary) 50%, var(--color-secondary) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.hero-subtitle { font-size: 1.35rem; color: var(--color-text-muted); margin-bottom: 3rem; max-width: 550px; margin-left: auto; margin-right: auto; line-height: 1.7; }
.hero-cta { display: flex; gap: 1.25rem; justify-content: center; flex-wrap: wrap; }
.btn { display: inline-block; padding: 1.1rem 2.5rem; border-radius: 50px; text-decoration: none; font-weight: 600; transition: all 0.3s ease; }
.btn-primary { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: var(--color-text-white); box-shadow: 0 8px 30px rgba(var(--primary-rgb), 0.4); }
.btn-primary:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(var(--primary-rgb), 0.5); color: var(--color-text-white); }
.btn-secondary { background: white; color: var(--color-text); border: 2px solid var(--color-border-light); box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.08); }
.btn-secondary:hover { border-color: var(--color-primary); color: var(--color-primary); transform: translateY(-2px); background: var(--color-bg-alt); }

/* FEATURES SECTION */
.features { padding: 7rem 2rem; max-width: 1200px; margin: 0 auto; background: white; }
.section-title { text-align: center; font-size: 2.8rem; margin-bottom: 1rem; color: var(--color-text); font-weight: 700; letter-spacing: -1px; }
.features > p { text-align: center; color: var(--color-text-muted); margin-bottom: 4rem; font-size: 1.1rem; }
.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2.5rem; }
.feature-card { padding: 3rem 2.5rem; background: linear-gradient(145deg, var(--color-bg) 0%, var(--color-bg-alt) 100%); border: 1px solid rgba(var(--primary-rgb), 0.08); border-radius: 1.5rem; text-align: center; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
.feature-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); transform: scaleX(0); transition: transform 0.4s ease; transform-origin: left; }
.feature-card:hover { transform: translateY(-12px); box-shadow: 0 25px 50px rgba(var(--primary-rgb), 0.15); }
.feature-card:hover::before { transform: scaleX(1); }
.feature-card h3 { color: var(--color-primary); margin-bottom: 1rem; font-size: 1.4rem; font-weight: 600; }
.feature-card p { color: var(--color-text-muted); line-height: 1.8; font-size: 1.05rem; }

/* CTA SECTION */
.cta-section { text-align: center; padding: 7rem 2rem; background: linear-gradient(180deg, white 0%, var(--color-bg-alt) 50%, var(--color-bg-accent) 100%); position: relative; }
.cta-section::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(var(--primary-rgb), 0.3), transparent); }
.cta-section h2 { font-size: 2.8rem; margin-bottom: 1rem; color: var(--color-text); font-weight: 700; letter-spacing: -1px; }
.cta-section p { color: var(--color-text-muted); margin-bottom: 2.5rem; font-size: 1.2rem; }
.btn-lg { padding: 1.35rem 3.5rem; font-size: 1.15rem; }

/* FOOTER */
.landing-footer { background: linear-gradient(180deg, var(--color-bg-footer) 0%, var(--color-bg-footer-dark) 100%); color: var(--color-text-white); padding: 2.5rem 2rem; position: relative; }
.landing-footer::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary), var(--color-primary-light)); }
.landing-footer .footer-content { display: flex; justify-content: center; align-items: center; max-width: 1200px; margin: 0 auto; }
.landing-footer p { color: var(--color-text-light); font-size: 0.95rem; }`;
    }

    function getLandingPageMultilingualStyles() {
        return `/* GLOBAL STYLES */
html { scroll-behavior: smooth; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: var(--color-text); line-height: 1.7; background: var(--color-bg); }
a { transition: all 0.3s ease; }

/* NAVIGATION */
.landing-nav { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 3rem; position: fixed; top: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); z-index: 100; box-shadow: 0 2px 30px rgba(var(--primary-rgb), 0.1); }
.nav-logo { font-size: 1.7rem; font-weight: 800; color: var(--color-primary); text-decoration: none; letter-spacing: -0.5px; }
.nav-links { display: flex; gap: 2.5rem; list-style: none; }
.nav-links a { text-decoration: none; color: var(--color-text-muted); font-weight: 500; position: relative; padding: 0.5rem 0; }
.nav-links a::after { content: ''; position: absolute; bottom: 0; left: 50%; width: 0; height: 2px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); transition: all 0.3s ease; transform: translateX(-50%); }
.nav-links a:hover { color: var(--color-primary); }
.nav-links a:hover::after { width: 100%; }

/* HERO SECTION */
.hero { min-height: 100vh; display: flex; align-items: center; justify-content: center; text-align: center; padding: 8rem 2rem 6rem; background: linear-gradient(160deg, var(--color-bg) 0%, var(--color-bg-alt) 40%, var(--color-bg-accent) 100%); position: relative; overflow: hidden; }
.hero::before { content: ''; position: absolute; top: 10%; right: 5%; width: 500px; height: 500px; background: radial-gradient(circle, rgba(var(--primary-rgb), 0.15) 0%, transparent 70%); pointer-events: none; border-radius: 50%; animation: float 8s ease-in-out infinite; }
.hero::after { content: ''; position: absolute; bottom: 20%; left: 10%; width: 300px; height: 300px; background: radial-gradient(circle, rgba(var(--secondary-rgb), 0.1) 0%, transparent 70%); pointer-events: none; border-radius: 50%; animation: float 6s ease-in-out infinite reverse; }
@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
.hero-content { position: relative; z-index: 1; max-width: 800px; }
.hero-title { font-size: 4rem; margin-bottom: 1.5rem; font-weight: 800; letter-spacing: -2px; line-height: 1.1; background: linear-gradient(135deg, var(--color-text) 0%, var(--color-primary) 50%, var(--color-secondary) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.hero-subtitle { font-size: 1.35rem; color: var(--color-text-muted); margin-bottom: 3rem; max-width: 550px; margin-left: auto; margin-right: auto; line-height: 1.7; }
.hero-cta { display: flex; gap: 1.25rem; justify-content: center; flex-wrap: wrap; }
.btn { display: inline-block; padding: 1.1rem 2.5rem; border-radius: 50px; text-decoration: none; font-weight: 600; transition: all 0.3s ease; }
.btn-primary { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: var(--color-text-white); box-shadow: 0 8px 30px rgba(var(--primary-rgb), 0.4); }
.btn-primary:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(var(--primary-rgb), 0.5); color: var(--color-text-white); }
.btn-secondary { background: white; color: var(--color-text); border: 2px solid var(--color-border-light); box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.08); }
.btn-secondary:hover { border-color: var(--color-primary); color: var(--color-primary); transform: translateY(-2px); background: var(--color-bg-alt); }

/* FEATURES SECTION */
.features { padding: 7rem 2rem; max-width: 1200px; margin: 0 auto; background: white; }
.section-title { text-align: center; font-size: 2.8rem; margin-bottom: 1rem; color: var(--color-text); font-weight: 700; letter-spacing: -1px; }
.features > p { text-align: center; color: var(--color-text-muted); margin-bottom: 4rem; font-size: 1.1rem; }
.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2.5rem; }
.feature-card { padding: 3rem 2.5rem; background: linear-gradient(145deg, var(--color-bg) 0%, var(--color-bg-alt) 100%); border: 1px solid rgba(var(--primary-rgb), 0.08); border-radius: 1.5rem; text-align: center; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
.feature-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary)); transform: scaleX(0); transition: transform 0.4s ease; transform-origin: left; }
.feature-card:hover { transform: translateY(-12px); box-shadow: 0 25px 50px rgba(var(--primary-rgb), 0.15); }
.feature-card:hover::before { transform: scaleX(1); }
.feature-card h3 { color: var(--color-primary); margin-bottom: 1rem; font-size: 1.4rem; font-weight: 600; }
.feature-card p { color: var(--color-text-muted); line-height: 1.8; font-size: 1.05rem; }

/* CTA SECTION */
.cta-section { text-align: center; padding: 7rem 2rem; background: linear-gradient(180deg, white 0%, var(--color-bg-alt) 50%, var(--color-bg-accent) 100%); position: relative; }
.cta-section::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(var(--primary-rgb), 0.3), transparent); }
.cta-section h2 { font-size: 2.8rem; margin-bottom: 1rem; color: var(--color-text); font-weight: 700; letter-spacing: -1px; }
.cta-section p { color: var(--color-text-muted); margin-bottom: 2.5rem; font-size: 1.2rem; }
.btn-lg { padding: 1.35rem 3.5rem; font-size: 1.15rem; }

/* FOOTER */
.landing-footer { background: linear-gradient(180deg, var(--color-bg-footer) 0%, var(--color-bg-footer-dark) 100%); color: var(--color-text-white); padding: 2.5rem 2rem; position: relative; }
.landing-footer::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--color-primary), var(--color-secondary), var(--color-primary-light)); }
.landing-footer .footer-content { display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; flex-wrap: wrap; gap: 1.5rem; }
.landing-footer p { color: var(--color-text-light); font-size: 0.95rem; }
.footer-lang { display: flex; align-items: center; gap: 1rem; }
.lang-label { color: var(--color-text-light); font-size: 0.9rem; font-weight: 500; }
.lang-switch { display: flex; gap: 0.5rem; }
.lang-link { color: #C4B5FD; text-decoration: none; padding: 0.4rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 500; border: 1px solid var(--color-border-footer); background: rgba(var(--primary-rgb), 0.1); transition: all 0.3s ease; }
.lang-link:hover { color: var(--color-text-white); background: rgba(var(--primary-rgb), 0.3); border-color: var(--color-primary); box-shadow: 0 0 15px rgba(var(--primary-rgb), 0.4); }`;
    }

    // ============================================
    // Export functions to global scope
    // ============================================

    // Queue operations
    window.addToQueue = addToQueue;
    window.removeFromQueue = removeFromQueue;
    window.editQueueItem = editQueueItem;
    window.moveUp = moveUp;
    window.moveDown = moveDown;
    window.clearQueue = clearQueue;
    
    // Execution
    window.executeBatch = executeBatch;
    window.clearResults = clearResults;
    
    // Filtering
    window.filterBatchCommands = filterBatchCommands;
    
    // JSON Import
    window.toggleJsonImport = toggleJsonImport;
    window.validateJsonImport = validateJsonImport;
    window.importJsonCommands = importJsonCommands;
    
    // Templates
    window.toggleTemplates = toggleTemplates;
    window.previewTemplate = previewTemplate;
    window.closeTemplatePreview = closeTemplatePreview;
    window.loadTemplate = loadTemplate;
    window.loadPreviewedTemplate = loadPreviewedTemplate;
    
    // Fresh Start
    window.generateFreshStartPreview = generateFreshStartPreview;
    window.generateFreshStart = generateFreshStart;

})();
