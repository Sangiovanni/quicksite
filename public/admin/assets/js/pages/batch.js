/**
 * Batch Commands Page JavaScript
 * 
 * Execute multiple commands in sequence.
 * Extracted from batch.php for browser caching.
 * 
 * @version 3.0.0 - Templates removed, now use Workflows page
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

})();
