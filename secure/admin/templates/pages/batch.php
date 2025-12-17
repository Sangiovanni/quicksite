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

<!-- JSON Import Section -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header admin-card__header--collapsible" onclick="toggleJsonImport()">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="18" x2="12" y2="12"/>
                <line x1="9" y1="15" x2="15" y2="15"/>
            </svg>
            Import JSON Commands
        </h2>
        <svg class="admin-card__toggle" id="json-import-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </div>
    <div class="admin-card__body" id="json-import-body" style="display: none;">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            Paste a JSON array of commands to add them to the queue. Perfect for AI-generated command sequences.
        </p>
        <div class="admin-form-group">
            <label class="admin-label">JSON Commands Array</label>
            <textarea id="json-import-input" class="admin-textarea admin-textarea--code" rows="10" 
                placeholder='[
  {
    "command": "addRoute",
    "params": { "name": "about" }
  },
  {
    "command": "editStructure",
    "params": { "route": "about", "structure": [...] }
  }
]'></textarea>
        </div>
        <div class="admin-form-actions">
            <button type="button" class="admin-btn admin-btn--secondary" onclick="validateJsonImport()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Validate
            </button>
            <button type="button" class="admin-btn admin-btn--primary" onclick="importJsonCommands()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add to Queue
            </button>
        </div>
        <div id="json-import-preview" class="admin-json-preview" style="display: none; margin-top: var(--space-md);"></div>
    </div>
</div>

<!-- Command Templates -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header admin-card__header--collapsible" onclick="toggleTemplates()">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <line x1="3" y1="9" x2="21" y2="9"/>
                <line x1="9" y1="21" x2="9" y2="9"/>
            </svg>
            Command Templates
        </h2>
        <svg class="admin-card__toggle" id="templates-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </div>
    <div class="admin-card__body" id="templates-body" style="display: none;">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            Pre-built command sequences for common tasks. Click to preview, then load into queue.
        </p>
        
        <div class="admin-templates-grid">
            <!-- Fresh Start Template (Dynamic) -->
            <div class="admin-template admin-template--dynamic" data-template="fresh-start">
                <div class="admin-template__header">
                    <span class="admin-template__icon">✨</span>
                    <span class="admin-template__title">Fresh Start</span>
                    <span class="admin-template__badge">Dynamic</span>
                </div>
                <p class="admin-template__desc">Wipe all content to blank slate: delete routes, clear assets, empty translations, reset CSS. Keeps languages & 404 page.</p>
                <div class="admin-template__actions">
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="generateFreshStartPreview()">Analyze & Preview</button>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--primary" onclick="generateFreshStart()">Generate & Load</button>
                </div>
            </div>
            
            <!-- Starter Business Template -->
            <div class="admin-template" data-template="starter-business">
                <div class="admin-template__header">
                    <span class="admin-template__icon">🏢</span>
                    <span class="admin-template__title">Starter Business</span>
                </div>
                <p class="admin-template__desc">Create basic pages (home, about, services, contact) with empty structures ready for content.</p>
                <div class="admin-template__actions">
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="previewTemplate('starter-business')">Preview</button>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--primary" onclick="loadTemplate('starter-business')">Load</button>
                </div>
            </div>
            
            <!-- Common Translations Template -->
            <div class="admin-template" data-template="common-translations">
                <div class="admin-template__header">
                    <span class="admin-template__icon">🌐</span>
                    <span class="admin-template__title">Common Translations</span>
                </div>
                <p class="admin-template__desc">Add frequently used translation keys: navigation, buttons, footer, and common labels.</p>
                <div class="admin-template__actions">
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="previewTemplate('common-translations')">Preview</button>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--primary" onclick="loadTemplate('common-translations')">Load</button>
                </div>
            </div>
            
            <!-- Modern Theme Template -->
            <div class="admin-template" data-template="modern-theme">
                <div class="admin-template__header">
                    <span class="admin-template__icon">🎨</span>
                    <span class="admin-template__title">Modern Theme</span>
                </div>
                <p class="admin-template__desc">Set up CSS variables for a clean, modern design with primary colors and typography.</p>
                <div class="admin-template__actions">
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="previewTemplate('modern-theme')">Preview</button>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--primary" onclick="loadTemplate('modern-theme')">Load</button>
                </div>
            </div>
            
            <!-- Enable Multilingual Template -->
            <div class="admin-template" data-template="multilingual-setup">
                <div class="admin-template__header">
                    <span class="admin-template__icon">🗣️</span>
                    <span class="admin-template__title">Enable Multilingual</span>
                </div>
                <p class="admin-template__desc">Enable multilingual support and add French & Spanish languages.</p>
                <div class="admin-template__actions">
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="previewTemplate('multilingual-setup')">Preview</button>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--primary" onclick="loadTemplate('multilingual-setup')">Load</button>
                </div>
            </div>
            
            <!-- Landing Page Template -->
            <div class="admin-template" data-template="landing-page">
                <div class="admin-template__header">
                    <span class="admin-template__icon">📄</span>
                    <span class="admin-template__title">Landing Page</span>
                </div>
                <p class="admin-template__desc">Create a single-page landing structure with hero, features, and CTA sections.</p>
                <div class="admin-template__actions">
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="previewTemplate('landing-page')">Preview</button>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--primary" onclick="loadTemplate('landing-page')">Load</button>
                </div>
            </div>
        </div>
        
        <!-- Template Preview Modal -->
        <div id="template-preview" class="admin-template-preview" style="display: none;">
            <div class="admin-template-preview__header">
                <strong id="template-preview-title">Template Preview</strong>
                <button type="button" class="admin-btn admin-btn--small admin-btn--ghost" onclick="closeTemplatePreview()">×</button>
            </div>
            <textarea id="template-preview-content" class="admin-textarea admin-textarea--code" rows="12" readonly></textarea>
            <div class="admin-template-preview__actions">
                <span id="template-preview-count" class="admin-hint"></span>
                <button type="button" class="admin-btn admin-btn--primary" onclick="loadPreviewedTemplate()">Load into Queue</button>
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

/* JSON Import Section */
.admin-card__header--collapsible {
    cursor: pointer;
    user-select: none;
    transition: background 0.2s;
}

.admin-card__header--collapsible:hover {
    background: var(--admin-bg-secondary);
}

.admin-card__toggle {
    transition: transform 0.2s;
}

.admin-card__toggle--open {
    transform: rotate(180deg);
}

.admin-textarea--code {
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    line-height: 1.5;
    resize: vertical;
}

.admin-json-preview {
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
}

.admin-json-preview__title {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--admin-text-muted);
    margin-bottom: var(--space-sm);
}

.admin-json-preview__list {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.admin-json-preview__item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    background: var(--admin-bg);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-sm);
}

.admin-json-preview__item--valid {
    border-left: 3px solid var(--admin-success);
}

.admin-json-preview__item--invalid {
    border-left: 3px solid var(--admin-danger);
}

.admin-json-preview__cmd {
    font-family: var(--font-mono);
    color: var(--admin-accent);
}

.admin-json-preview__params {
    color: var(--admin-text-muted);
    font-size: var(--font-size-xs);
}

.admin-json-preview__error {
    color: var(--admin-danger);
    font-size: var(--font-size-xs);
}

/* Templates Section */
.admin-templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.admin-template {
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    transition: all 0.2s;
}

.admin-template:hover {
    border-color: var(--admin-accent);
}

.admin-template__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.admin-template__icon {
    font-size: 1.25rem;
}

.admin-template__title {
    font-weight: var(--font-weight-medium);
}

.admin-template__badge {
    font-size: 0.65rem;
    font-weight: var(--font-weight-medium);
    text-transform: uppercase;
    padding: 0.15rem 0.4rem;
    background: var(--admin-accent);
    color: white;
    border-radius: var(--radius-sm);
    margin-left: auto;
}

.admin-template--dynamic {
    border-color: var(--admin-accent);
    background: linear-gradient(135deg, var(--admin-card-bg) 0%, rgba(79, 70, 229, 0.05) 100%);
}

.admin-template--dynamic:hover {
    border-color: var(--admin-accent);
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.15);
}

.admin-template__desc {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    margin-bottom: var(--space-md);
    line-height: 1.5;
}

.admin-template__actions {
    display: flex;
    gap: var(--space-sm);
}

.admin-template-preview {
    margin-top: var(--space-lg);
    padding: var(--space-md);
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
}

.admin-template-preview__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-md);
}

.admin-template-preview__actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--space-md);
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

// ============================================
// JSON Import Functions
// ============================================

// Available commands (loaded from help API)
let availableCommands = [];

// Load available commands on init
(async function loadAvailableCommands() {
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET');
        if (result.ok && result.data.data?.commands) {
            availableCommands = Object.keys(result.data.data.commands);
        }
    } catch (e) {
        console.warn('Could not load available commands:', e);
    }
})();

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
// Command Templates
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
    const summary = { routes: 0, assets: 0, translations: 0, structures: 0, components: 0 };
    
    try {
        // 1. Fetch current routes (returns array of strings: ['home', 'about', ...])
        const routesResponse = await executeApiCall('getRoutes', {});
        if (routesResponse.ok && routesResponse.data?.routes) {
            const routes = routesResponse.data.routes;
            // Delete all routes except 404 and home (home is protected)
            const protectedRoutes = ['404', 'home'];
            
            for (const routeName of routes) {
                if (!protectedRoutes.includes(routeName)) {
                    commands.push({ command: 'deleteRoute', params: { route: routeName } });
                    summary.routes++;
                }
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
// Static Command Templates
// ============================================

const commandTemplates = {
    
    'starter-business': {
        name: 'Starter Business',
        description: 'Basic business website structure',
        commands: [
            { command: 'addRoute', params: { route: 'about' } },
            { command: 'addRoute', params: { route: 'services' } },
            { command: 'addRoute', params: { route: 'contact' } },
            { command: 'editStructure', params: { 
                type: 'page',
                name: 'home', 
                structure: [
                    { tag: 'section', params: { class: 'hero' }, children: [
                        { tag: 'h1', children: [{ textKey: 'home.hero.title' }] },
                        { tag: 'p', children: [{ textKey: 'home.hero.subtitle' }] },
                        { tag: 'a', params: { href: '/contact', class: 'btn btn-primary' }, children: [{ textKey: 'common.cta' }] }
                    ]}
                ]
            }},
            { command: 'editStructure', params: { 
                type: 'page',
                name: 'about', 
                structure: [
                    { tag: 'section', params: { class: 'page-header' }, children: [
                        { tag: 'h1', children: [{ textKey: 'about.title' }] },
                        { tag: 'p', children: [{ textKey: 'about.intro' }] }
                    ]}
                ]
            }},
            { command: 'editStructure', params: { 
                type: 'page',
                name: 'services', 
                structure: [
                    { tag: 'section', params: { class: 'page-header' }, children: [
                        { tag: 'h1', children: [{ textKey: 'services.title' }] }
                    ]},
                    { tag: 'section', params: { class: 'services-grid' }, children: [
                        { tag: 'div', params: { class: 'service-card' }, children: [
                            { tag: 'h3', children: [{ textKey: 'services.card1.title' }] },
                            { tag: 'p', children: [{ textKey: 'services.card1.desc' }] }
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
                        { tag: 'p', children: [{ textKey: 'contact.intro' }] }
                    ]}
                ]
            }}
        ]
    },
    
    'common-translations': {
        name: 'Common Translations',
        description: 'Frequently used translation keys',
        commands: [
            { command: 'setTranslationKeys', params: { 
                language: 'en', 
                translations: {
                    nav: {
                        home: 'Home',
                        about: 'About',
                        services: 'Services',
                        contact: 'Contact',
                        blog: 'Blog'
                    },
                    common: {
                        cta: 'Get Started',
                        learnMore: 'Learn More',
                        readMore: 'Read More',
                        submit: 'Submit',
                        send: 'Send',
                        back: 'Back',
                        next: 'Next',
                        close: 'Close'
                    },
                    footer: {
                        copyright: '© 2025 Company Name. All rights reserved.',
                        privacy: 'Privacy Policy',
                        terms: 'Terms of Service'
                    }
                }
            }}
        ]
    },
    
    'modern-theme': {
        name: 'Modern Theme',
        description: 'Clean, modern CSS variables',
        commands: [
            { command: 'setRootVariables', params: { 
                variables: {
                    '--color-primary': '#3B82F6',
                    '--color-primary-dark': '#1E40AF',
                    '--color-secondary': '#64748B',
                    '--color-accent': '#F59E0B',
                    '--color-success': '#10B981',
                    '--color-danger': '#EF4444',
                    '--color-bg': '#ffffff',
                    '--color-bg-alt': '#F8FAFC',
                    '--color-text': '#1E293B',
                    '--color-text-muted': '#64748B',
                    '--font-family': "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                    '--font-size-sm': '0.875rem',
                    '--font-size-base': '1rem',
                    '--font-size-lg': '1.125rem',
                    '--font-size-xl': '1.25rem',
                    '--font-size-2xl': '1.5rem',
                    '--font-size-3xl': '2rem',
                    '--spacing-xs': '0.25rem',
                    '--spacing-sm': '0.5rem',
                    '--spacing-md': '1rem',
                    '--spacing-lg': '1.5rem',
                    '--spacing-xl': '2rem',
                    '--radius-sm': '0.25rem',
                    '--radius-md': '0.5rem',
                    '--radius-lg': '1rem',
                    '--shadow-sm': '0 1px 2px rgba(0,0,0,0.05)',
                    '--shadow-md': '0 4px 6px rgba(0,0,0,0.1)',
                    '--shadow-lg': '0 10px 15px rgba(0,0,0,0.1)'
                }
            }}
        ]
    },
    
    'multilingual-setup': {
        name: 'Enable Multilingual',
        description: 'Enable multilingual with French & Spanish',
        commands: [
            { command: 'setMultilingual', params: { enabled: true } },
            { command: 'addLang', params: { code: 'fr', name: 'Français' } },
            { command: 'addLang', params: { code: 'es', name: 'Español' } }
        ]
    },
    
    'landing-page': {
        name: 'Landing Page',
        description: 'Single-page landing structure',
        commands: [
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
                    landing: {
                        hero: {
                            title: 'Build Something Amazing',
                            subtitle: 'The modern way to create beautiful websites without the complexity.',
                            cta: 'Get Started Free'
                        },
                        features: {
                            title: 'Why Choose Us',
                            f1: { title: 'Fast & Simple', desc: 'Get up and running in minutes, not hours.' },
                            f2: { title: 'Fully Customizable', desc: 'Make it yours with powerful customization options.' },
                            f3: { title: 'Always Reliable', desc: '99.9% uptime guaranteed for your peace of mind.' }
                        },
                        cta: {
                            title: 'Ready to Get Started?',
                            subtitle: 'Join thousands of satisfied customers today.',
                            button: 'Start Your Free Trial'
                        }
                    },
                    common: {
                        learnMore: 'Learn More'
                    }
                }
            }}
        ]
    }
};

let currentPreviewTemplate = null;

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
</script>
