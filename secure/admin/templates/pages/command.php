<?php
/**
 * Admin Command Page
 * 
 * Shows Commands with tabs for History and Docs.
 * If a specific command is selected, shows its form.
 * 
 * @version 1.7.0
 */

$selectedCommand = $router->getCommand();
$categories = getCommandCategories();

// If a specific command is selected, show its page
if ($selectedCommand) {
    require_once __DIR__ . '/command-form.php';
    return;
}

// Get current tab from URL parameter (default: commands)
$currentTab = $_GET['tab'] ?? 'commands';
if (!in_array($currentTab, ['commands', 'history', 'docs'])) {
    $currentTab = 'commands';
}

// Otherwise show the command list with tabs
$baseUrl = rtrim(BASE_URL, '/');
?>

<div class="admin-page-header">
    <div class="admin-page-header__main">
        <h1 class="admin-page-header__title"><?= __admin('commands.title') ?></h1>
        <p class="admin-page-header__subtitle"><?= __admin('commands.subtitle') ?></p>
    </div>
    <div class="admin-page-header__actions">
        <a href="<?= $router->url('batch') ?>" class="admin-btn admin-btn--primary" id="command-queue-btn">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <?= __admin('commands.openQueue') ?>
            <span class="admin-btn__badge" id="queue-count-badge" style="display: none;">0</span>
        </a>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="admin-tabs">
    <a href="<?= $router->url('command') ?>" class="admin-tabs__tab<?= $currentTab === 'commands' ? ' admin-tabs__tab--active' : '' ?>">
        <svg class="admin-tabs__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="16 18 22 12 16 6"/>
            <polyline points="8 6 2 12 8 18"/>
        </svg>
        <?= __admin('commands.tabs.commands') ?>
    </a>
    <a href="<?= $router->url('command') ?>?tab=history" class="admin-tabs__tab<?= $currentTab === 'history' ? ' admin-tabs__tab--active' : '' ?>">
        <svg class="admin-tabs__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
        </svg>
        <?= __admin('commands.tabs.history') ?>
    </a>
    <a href="<?= $router->url('command') ?>?tab=docs" class="admin-tabs__tab<?= $currentTab === 'docs' ? ' admin-tabs__tab--active' : '' ?>">
        <svg class="admin-tabs__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
        <?= __admin('commands.tabs.docs') ?>
    </a>
</div>

<?php if ($currentTab === 'commands'): ?>
<!-- ==================== COMMANDS TAB ==================== -->

<!-- Search -->
<div class="admin-search-bar">
    <svg class="admin-search-bar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/>
        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input 
        type="text" 
        id="command-search" 
        class="admin-input" 
        placeholder="<?= adminAttr(__admin('commands.searchPlaceholder')) ?>"
    >
</div>

<!-- Command Categories -->
<div class="admin-categories" id="command-list">
    <?php foreach ($categories as $categoryKey => $category): ?>
    <div class="admin-category" data-category="<?= adminAttr($categoryKey) ?>">
        <div class="admin-category__header">
            <svg class="admin-category__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <?php echo getCategoryIcon($category['icon']); ?>
            </svg>
            <h3 class="admin-category__title"><?= adminEscape($category['label']) ?></h3>
            <span class="admin-category__count"><?= count($category['commands']) ?></span>
            <svg class="admin-category__toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </div>
        <div class="admin-category__commands">
            <?php foreach ($category['commands'] as $command): ?>
            <a href="<?= $router->url('command', $command) ?>" 
               class="admin-command-link" 
               data-command="<?= adminAttr($command) ?>">
                <span class="admin-command-link__name"><?= adminEscape($command) ?></span>
                <svg class="admin-command-link__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Command page JavaScript -->
<script src="<?= $baseUrl ?>/admin/assets/js/pages/command.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/command.js') ?>"></script>

<?php elseif ($currentTab === 'history'): ?>
<!-- ==================== HISTORY TAB ==================== -->

<script>
// Page config for history.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.commandUrl = '<?= $router->url('command') ?>';
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/history.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/history.js') ?>"></script>

<!-- Filters -->
<div class="admin-card" style="margin-bottom: var(--space-lg);">
    <div class="admin-card__body">
        <div class="admin-filter-row">
            <div class="admin-filter-group">
                <label class="admin-label"><?= __admin('history.filters.date') ?></label>
                <div style="display: flex; gap: var(--space-xs); align-items: center;">
                    <input type="date" id="filter-date" class="admin-input" style="flex: 1;">
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="clearDateFilter()" title="Show all dates (last 7 days)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div class="admin-filter-group">
                <label class="admin-label"><?= __admin('history.filters.command') ?></label>
                <input type="text" id="filter-command" class="admin-input" placeholder="e.g., getStructure, edit, delete...">
            </div>
            
            <div class="admin-filter-group">
                <label class="admin-label"><?= __admin('history.filters.status') ?></label>
                <select id="filter-status" class="admin-select">
                    <option value=""><?= __admin('history.filters.all') ?></option>
                    <option value="success">Success</option>
                    <option value="error">Error</option>
                </select>
            </div>
            
            <div class="admin-filter-group admin-filter-group--actions">
                <button type="button" class="admin-btn admin-btn--primary" onclick="loadHistory()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <?= __admin('common.search') ?>
                </button>
                <button type="button" class="admin-btn admin-btn--outline" onclick="clearFilters()">
                    <?= __admin('common.reset') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- History Table -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <?= __admin('history.title') ?>
        </h2>
        <div class="admin-card__actions">
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="QuickSiteAdmin.exportHistory()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <?= __admin('history.export') ?>
            </button>
        </div>
    </div>
    <div class="admin-card__body" id="history-content">
        <div class="admin-loading">
            <span class="admin-spinner"></span>
            <span><?= __admin('common.loading') ?></span>
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="admin-pagination" id="history-pagination" style="display: none;">
    <button class="admin-btn admin-btn--outline" id="prev-page" onclick="changePage(-1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
        <?= __admin('common.previous') ?>
    </button>
    <span class="admin-pagination__info" id="page-info"></span>
    <button class="admin-btn admin-btn--outline" id="next-page" onclick="changePage(1)">
        <?= __admin('common.next') ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="9 18 15 12 9 6"/>
        </svg>
    </button>
</div>

<!-- Detail Modal -->
<div class="admin-modal" id="detail-modal" style="display: none;">
    <div class="admin-modal__backdrop" onclick="closeModal()"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('history.columns.details') ?></h3>
            <button class="admin-modal__close" onclick="closeModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal__body" id="detail-content"></div>
    </div>
</div>

<?php elseif ($currentTab === 'docs'): ?>
<!-- ==================== DOCS TAB ==================== -->

<!-- Quick Reference -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <?= __admin('docs.quickReference') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-docs-quick">
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.apiBaseUrl') ?></h3>
                <code class="admin-docs-item__code"><?= rtrim(BASE_URL, '/') ?>/management</code>
            </div>
            
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.authentication') ?></h3>
                <p><?= __admin('docs.quickRef.includeTokenVia') ?></p>
                <ul class="admin-docs-list">
                    <li><?= __admin('docs.quickRef.header') ?> <code>X-Auth-Token: your_token</code></li>
                    <li><?= __admin('docs.quickRef.query') ?> <code>?token=your_token</code></li>
                    <li><?= __admin('docs.quickRef.postBody') ?> <code>{"token": "your_token"}</code></li>
                </ul>
            </div>
            
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.responseFormat') ?></h3>
                <pre class="admin-docs-pre">{
  "success": true|false,
  "data": {...} | null,
  "message": "Optional message"
}</pre>
            </div>
        </div>
    </div>
</div>

<!-- Command Search -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <?= __admin('docs.commandReference') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-form-group">
            <input type="text" id="docs-search" class="admin-input" 
                   placeholder="<?= __admin('docs.searchPlaceholder') ?>">
        </div>
        
        <div id="docs-loading" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('docs.loading') ?>
        </div>
        
        <div id="docs-container" class="admin-docs-commands" style="display: none;"></div>
    </div>
</div>

<!-- Docs JavaScript -->
<script>
    window.QuickSiteDocsConfig = {
        commandUrl: '<?= $router->url('command') ?>',
        baseUrl: '<?= rtrim(BASE_URL, '/') ?>'
    };
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/docs.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/docs.js') ?>"></script>

<?php endif; ?>

<?php
/**
 * Get SVG icon paths for categories
 */
function getCategoryIcon(string $icon): string {
    return match($icon) {
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'folder-tree' => '<path d="M13 10h7a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-2.5a1 1 0 0 1-.8-.4l-.9-1.2A1 1 0 0 0 15 3h-2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M13 21h7a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1h-2.5a1 1 0 0 1-.8-.4l-.9-1.2A1 1 0 0 0 15 14h-2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M3 3v16c0 .6.4 1 1 1h6"/>',
        'route' => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4L8.12 15.88M14.47 14.48L20 20M8.12 8.12L12 12"/>',
        'structure' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'nodes' => '<circle cx="5" cy="6" r="2"/><circle cx="12" cy="6" r="2"/><circle cx="19" cy="6" r="2"/><circle cx="5" cy="18" r="2"/><circle cx="12" cy="18" r="2"/><circle cx="19" cy="18" r="2"/><line x1="5" y1="8" x2="5" y2="16"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="19" y1="8" x2="19" y2="16"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'translate' => '<path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2v3"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/>',
        'language' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'palette' => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.555C21.965 6.012 17.461 2 12 2z"/>',
        'css' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'animation' => '<polygon points="5 3 19 12 5 21 5 3"/><line x1="12" y1="12" x2="12" y2="12"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'package' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'history' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'bot' => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/>',
        'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        default => '<circle cx="12" cy="12" r="10"/>'
    };
}
?>
