<?php
/**
 * Admin Command History page (/admin/history)
 *
 * WHY THIS IS ITS OWN PAGE. As a tab on /admin/command, the audit trail would
 * be a feature of the command console — and the console is something an
 * operator can switch off, which would leave the trail reachable only through
 * a special case in command.php and a single
 * dashboard link. An audit trail should not be a sub-view of the thing it
 * audits, so it has its own route, its own nav entry, and its own server-side
 * role gate (AdminRouter::PAGE_PERMISSIONS => getCommandHistory, which the
 * `history` category grants to admin and owner only).
 *
 * /admin/command?tab=history redirects here, so a link or a bookmark to it
 * reaches this page.
 *
 * Static structure lives here; everything dynamic is built by history.js with
 * createElement.
 */

$baseUrl = rtrim(BASE_URL, '/');
require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php'; // qs_inline_script_json()
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-header__title">
            <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <?= adminEscape(__admin('history.title')) ?>
        </h1>
        <p class="admin-page-header__subtitle"><?= adminEscape(__admin('history.subtitle')) ?></p>
    </div>
</div>

<script>
// The history page talks to two commands. Both are project-scoped and both sit
// in the `history` category, so reaching this page at all already implies the
// role — but the clear control is gated again in the markup, and the command
// enforces it a third time server-side.
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.commandUrl = <?= qs_inline_script_json($router->url('command')) ?>;
window.QUICKSITE_CONFIG.consoleEnabled = <?= $router->isConsoleEnabled() ? 'true' : 'false' ?>;
</script>
<?php // The words history.js reads, each part at its own path (QSPanelWords); `common` comes with the layout. ?>
<?= __adminWords(['history.noHistory', 'history.noHistoryFiltered', 'history.exportEmpty', 'history.durationMs', 'history.errors', 'history.filters.success', 'history.filters.error', 'history.columns', 'history.detail', 'history.clear', 'history.pagination']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/history.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/history.js') ?>"></script>

<!-- Filters -->
<div class="admin-card" style="margin-bottom: var(--space-lg);">
    <div class="admin-card__body">
        <div class="admin-filter-row">
            <div class="admin-filter-group">
                <label class="admin-label" for="filter-date"><?= adminEscape(__admin('history.filters.date')) ?></label>
                <div style="display: flex; gap: var(--space-xs); align-items: center;">
                    <input type="date" id="filter-date" class="admin-input" style="flex: 1;">
                    <button type="button" class="admin-btn admin-btn--ghost" id="clear-date-filter"
                            title="<?= adminAttr(__admin('history.filters.showAllDates')) ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="admin-filter-group">
                <label class="admin-label" for="filter-command"><?= adminEscape(__admin('history.filters.command')) ?></label>
                <input type="text" id="filter-command" class="admin-input" placeholder="<?= adminAttr(__admin('history.filters.commandPlaceholder')) ?>">
            </div>

            <div class="admin-filter-group">
                <label class="admin-label" for="filter-status"><?= adminEscape(__admin('history.filters.status')) ?></label>
                <select id="filter-status" class="admin-select">
                    <option value=""><?= adminEscape(__admin('history.filters.all')) ?></option>
                    <option value="success"><?= adminEscape(__admin('history.filters.success')) ?></option>
                    <option value="error"><?= adminEscape(__admin('history.filters.error')) ?></option>
                </select>
            </div>

            <div class="admin-filter-group admin-filter-group--actions">
                <button type="button" class="admin-btn admin-btn--primary" id="history-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <?= adminEscape(__admin('common.search')) ?>
                </button>
                <button type="button" class="admin-btn admin-btn--outline" id="history-reset">
                    <?= adminEscape(__admin('common.reset')) ?>
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
            <?= adminEscape(__admin('history.title')) ?>
        </h2>
        <div>
            <button type="button" class="admin-btn admin-btn--ghost" id="history-export">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <?= adminEscape(__admin('history.exportCsv')) ?>
            </button>
            <button type="button" class="admin-btn admin-btn--danger"
                    id="history-clear" data-requires-command="clearCommandHistory">
                <?= adminEscape(__admin('history.clearHistory')) ?>
            </button>
        </div>
    </div>
    <div class="admin-card__body" id="history-content">
        <div class="admin-loading">
            <span class="admin-spinner"></span>
            <span><?= adminEscape(__admin('common.loading')) ?></span>
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="admin-pagination" id="history-pagination" style="display: none;">
    <button class="admin-btn admin-btn--outline" id="prev-page">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
        <?= adminEscape(__admin('common.previous')) ?>
    </button>
    <span class="admin-pagination__info" id="page-info"></span>
    <button class="admin-btn admin-btn--outline" id="next-page">
        <?= adminEscape(__admin('common.next')) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="9 18 15 12 9 6"/>
        </svg>
    </button>
</div>

<!-- Detail Modal -->
<div class="admin-modal" id="detail-modal" style="display: none;">
    <div class="admin-modal__backdrop" data-action="close-detail"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= adminEscape(__admin('history.columns.details')) ?></h3>
            <button type="button" class="admin-modal__close" data-action="close-detail" data-qs-icon="close"
                    aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        <div class="admin-modal__body" id="detail-content"></div>
    </div>
</div>

<!--
  What Clear History asks, shown in the panel's one confirm (history.js clones it into its body).

  ⚠ DESTRUCTIVE AND IRREVERSIBLE, so the question does not ask "are you sure" over
  an unknown quantity. `clearCommandHistory` answers a PREVIEW when called
  without `confirm` — files, entries and kilobytes that would go — and the
  Delete button stays disabled until that preview has come back. What is being
  destroyed is stated in numbers before anything is destroyed.

  ⚠ The command deletes entries recorded strictly BEFORE the chosen date and
  refuses a future one, so today's entries cannot be cleared. The question says so
  rather than letting the operator discover it.
-->
<template id="history-clear-body">
    <div class="history-clear">
        <p><?= adminEscape(__admin('history.clear.intro')) ?></p>

        <div class="admin-filter-group">
            <label class="admin-label" for="clear-before"><?= adminEscape(__admin('history.clear.beforeLabel')) ?></label>
            <input type="date" id="clear-before" class="admin-input">
            <small><?= adminEscape(__admin('history.clear.beforeHint')) ?></small>
        </div>

        <div id="clear-preview" class="admin-alert admin-alert--info"></div>
    </div>
</template>
