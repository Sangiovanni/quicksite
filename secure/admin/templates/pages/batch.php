<?php
/**
 * Admin Batch Commands Page
 * 
 * Execute multiple commands in sequence.
 * Inline JS/CSS extracted to batch.js and admin.css for browser caching.
 * 
 * @version 2.0.0
 */

require_once SECURE_FOLDER_PATH . '/admin/functions/AdminHelper.php';
$categories = getCommandCategories();
$baseUrl = rtrim(BASE_URL, '/');
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
                    <?= __admin('common.clearAll') ?>
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
                    <p class="admin-empty__text"><?= __admin('batch.emptyQueue') ?></p>
                    <p class="admin-hint"><?= __admin('batch.emptyQueueHint') ?></p>
                </div>
            </div>
            
            <div class="admin-batch-controls" style="margin-top: var(--space-lg); display: none;" id="batch-controls">
                <button type="button" class="admin-btn admin-btn--primary admin-btn--full" onclick="executeBatch(true)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <?= __admin('batch.executeAndClear') ?>
                </button>
                <button type="button" class="admin-btn admin-btn--secondary admin-btn--full" onclick="executeBatch(false)" style="margin-top: var(--space-sm);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <?= __admin('batch.executeAndKeep') ?>
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
                       placeholder="<?= __admin('batch.searchPlaceholder') ?>" oninput="filterBatchCommands(this.value)">
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
            <?= __admin('batch.importJson.title') ?>
        </h2>
        <svg class="admin-card__toggle" id="json-import-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </div>
    <div class="admin-card__body" id="json-import-body" style="display: none;">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= __admin('batch.importJson.hint') ?>
        </p>
        <div class="admin-form-group">
            <label class="admin-label"><?= __admin('batch.importJson.label') ?></label>
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
                <?= __admin('common.validate') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" onclick="importJsonCommands()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('batch.importJson.addToQueue') ?>
            </button>
        </div>
        <div id="json-import-preview" class="admin-json-preview" style="display: none; margin-top: var(--space-md);"></div>
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
                <?= __admin('batch.clearResults') ?>
            </button>
        </div>
    </div>
    <div class="admin-card__body">
        <div id="batch-results" class="admin-batch-results"></div>
    </div>
</div>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/batch.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/batch.js') ?>"></script>
