<?php
/**
 * Admin Command History Page
 * 
 * Shows the command execution history with filtering options.
 * 
 * @version 1.7.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script>
// Page config for history.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.commandUrl = '<?= $router->url('command') ?>';
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/history.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/history.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('history.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('history.subtitle') ?></p>
    <div class="admin-page-header__actions">
        <button type="button" class="admin-btn admin-btn--secondary" onclick="QuickSiteAdmin.exportHistory()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <?= __admin('history.export') ?>
        </button>
    </div>
</div>

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
