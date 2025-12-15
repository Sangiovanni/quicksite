<?php
/**
 * Admin Batch Commands Page
 * 
 * Execute multiple commands in sequence.
 * 
 * @version 1.6.0
 */

require_once SECURE_FOLDER_PATH . '/admin/functions/AdminHelper.php';
$categories = getCommandCategories();
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('batch.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('batch.subtitle') ?></p>
</div>

<div class="admin-grid admin-grid--cols-2">
    <!-- Command Queue -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="8" y1="6" x2="21" y2="6"/>
                    <line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/>
                    <line x1="3" y1="6" x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/>
                    <line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
                <?= __admin('batch.queue') ?>
            </h2>
            <div class="admin-card__actions">
                <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="clearQueue()">
                    Clear All
                </button>
            </div>
        </div>
        <div class="admin-card__body">
            <div id="batch-queue" class="admin-batch-queue">
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
            </div>
            
            <div class="admin-batch-controls" style="margin-top: var(--space-lg); display: none;" id="batch-controls">
                <button type="button" class="admin-btn admin-btn--primary admin-btn--full" onclick="executeBatch()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    Execute Queue
                </button>
            </div>
        </div>
    </div>
    
    <!-- Add Commands -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <?= __admin('batch.addCommands') ?>
            </h2>
        </div>
        <div class="admin-card__body">
            <div class="admin-form-group">
                <input type="text" id="batch-search" class="admin-input" 
                       placeholder="Search commands..." oninput="filterBatchCommands(this.value)">
            </div>
            
            <div class="admin-batch-commands" id="batch-commands">
                <?php foreach ($categories as $catKey => $category): ?>
                <div class="admin-batch-category" data-category="<?= adminEscape($catKey) ?>">
                    <div class="admin-batch-category__header">
                        <span class="admin-batch-category__label"><?= adminEscape($category['label']) ?></span>
                        <span class="admin-batch-category__count"><?= count($category['commands']) ?></span>
                    </div>
                    <div class="admin-batch-category__commands">
                        <?php foreach ($category['commands'] as $cmd): ?>
                        <button type="button" class="admin-batch-add" 
                                data-command="<?= adminEscape($cmd) ?>"
                                onclick="addToQueue('<?= adminEscape($cmd) ?>')">
                            <span class="admin-batch-add__name"><?= adminEscape($cmd) ?></span>
                            <svg class="admin-batch-add__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Execution Results -->
<div class="admin-card" style="margin-top: var(--space-lg); display: none;" id="batch-results-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <?= __admin('batch.results') ?>
        </h2>
        <div class="admin-card__actions">
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="clearResults()">
                Clear Results
            </button>
        </div>
    </div>
    <div class="admin-card__body">
        <div id="batch-results" class="admin-batch-results"></div>
    </div>
</div>

<style>
.admin-batch-queue {
    min-height: 200px;
    max-height: 400px;
    overflow-y: auto;
}

.admin-batch-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}

.admin-batch-item__order {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--admin-accent);
    color: #000;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-bold);
    flex-shrink: 0;
}

.admin-batch-item__name {
    flex: 1;
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
}

.admin-batch-item__params {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.admin-batch-item__actions {
    display: flex;
    gap: var(--space-xs);
}

.admin-batch-item__btn {
    padding: var(--space-xs);
    background: transparent;
    border: none;
    color: var(--admin-text-muted);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: all 0.2s;
}

.admin-batch-item__btn:hover {
    background: var(--admin-surface);
    color: var(--admin-text);
}

.admin-batch-item__btn--danger:hover {
    color: var(--admin-danger);
}

.admin-batch-item--running {
    border-color: var(--admin-accent);
    animation: pulse 1s infinite;
}

.admin-batch-item--success {
    border-color: var(--admin-success);
}

.admin-batch-item--error {
    border-color: var(--admin-danger);
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.admin-batch-commands {
    max-height: 400px;
    overflow-y: auto;
}

.admin-batch-category {
    margin-bottom: var(--space-md);
}

.admin-batch-category__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) 0;
    border-bottom: 1px solid var(--admin-border);
    margin-bottom: var(--space-sm);
}

.admin-batch-category__label {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--admin-text-muted);
}

.admin-batch-category__count {
    font-size: var(--font-size-xs);
    padding: 2px 6px;
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-sm);
    color: var(--admin-text-light);
}

.admin-batch-category__commands {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}

.admin-batch-add {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    font-family: var(--font-mono);
    color: var(--admin-text);
    cursor: pointer;
    transition: all 0.2s;
}

.admin-batch-add:hover {
    border-color: var(--admin-accent);
    background: var(--admin-surface);
}

.admin-batch-add__icon {
    width: 14px;
    height: 14px;
    color: var(--admin-accent);
}

.admin-batch-results {
    max-height: 400px;
    overflow-y: auto;
}

.admin-batch-result {
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}

.admin-batch-result__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.admin-batch-result__status {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.admin-batch-result__status--success {
    background: var(--admin-success);
}

.admin-batch-result__status--error {
    background: var(--admin-danger);
}

.admin-batch-result__command {
    font-family: var(--font-mono);
    font-weight: var(--font-weight-medium);
}

.admin-batch-result__time {
    margin-left: auto;
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

.admin-batch-result__output {
    font-size: var(--font-size-sm);
    padding: var(--space-sm);
    background: var(--admin-bg);
    border-radius: var(--radius-sm);
    max-height: 150px;
    overflow-y: auto;
}

.admin-batch-result__output pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

.admin-empty--compact {
    padding: var(--space-xl);
}

.admin-empty--compact .admin-empty__icon {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-md);
}
</style>

<script>
// Batch command queue
let batchQueue = [];
let isExecuting = false;

document.addEventListener('DOMContentLoaded', function() {
    loadSavedQueue();
});

function loadSavedQueue() {
    const saved = localStorage.getItem('admin_batch_queue');
    if (saved) {
        try {
            batchQueue = JSON.parse(saved);
            renderQueue();
        } catch (e) {}
    }
}

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
    window.location.href = `<?= $router->url('command') ?>/${item.command}?batch=1&batchId=${id}`;
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

function filterBatchCommands(query) {
    const commands = document.querySelectorAll('.admin-batch-add');
    const categories = document.querySelectorAll('.admin-batch-category');
    const lowerQuery = query.toLowerCase();
    
    commands.forEach(cmd => {
        const name = cmd.dataset.command.toLowerCase();
        cmd.style.display = name.includes(lowerQuery) ? '' : 'none';
    });
    
    // Hide empty categories
    categories.forEach(cat => {
        const visible = Array.from(cat.querySelectorAll('.admin-batch-add'))
            .some(c => c.style.display !== 'none');
        cat.style.display = visible ? '' : 'none';
    });
}

// Commands that use GET method
const GET_COMMANDS = [
    'help', 'getRoutes', 'getStructure', 'getTranslation', 'getTranslations',
    'getLangList', 'getTranslationKeys', 'validateTranslations', 'getUnusedTranslationKeys',
    'analyzeTranslations', 'listAssets', 'getStyles', 'getRootVariables', 'listStyleRules',
    'getStyleRule', 'getKeyframes', 'listTokens', 'listComponents', 'listPages',
    'listAliases', 'listBuilds', 'getBuild', 'getCommandHistory'
];

async function executeBatch() {
    if (isExecuting || batchQueue.length === 0) return;
    
    isExecuting = true;
    const resultsCard = document.getElementById('batch-results-card');
    const resultsContainer = document.getElementById('batch-results');
    
    resultsCard.style.display = 'block';
    resultsContainer.innerHTML = '';
    
    let successCount = 0;
    let errorCount = 0;
    
    for (let i = 0; i < batchQueue.length; i++) {
        const item = batchQueue[i];
        const itemEl = document.querySelector(`.admin-batch-item[data-id="${item.id}"]`);
        
        if (itemEl) {
            itemEl.classList.add('admin-batch-item--running');
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
    }
    
    isExecuting = false;
    
    // Show summary
    QuickSiteAdmin.showToast(
        `Batch complete: ${successCount} succeeded, ${errorCount} failed`,
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
</script>
