<?php
/**
 * Admin Command History page (/admin/history)
 *
 * WHY THIS IS ITS OWN PAGE (beta.11 S6.6). History used to be a tab on
 * /admin/command. That made the audit trail a feature of the command console —
 * and S6.5 made the console something an operator can switch off, which left
 * the trail reachable only through a special case in command.php and a single
 * dashboard link. An audit trail should not be a sub-view of the thing it
 * audits, so it has its own route, its own nav entry, and its own server-side
 * role gate (AdminRouter::PAGE_PERMISSIONS => getCommandHistory, which the
 * `history` category grants to admin and owner only).
 *
 * The old /admin/command?tab=history URL redirects here, so the dashboard link
 * and any bookmark keep working.
 *
 * Static structure lives here; everything dynamic is built by history.js with
 * createElement.
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<div class="admin-page-header">
    <div class="admin-page-header__main">
        <h1 class="admin-page-header__title"><?= __admin('history.title') ?></h1>
        <p class="admin-page-header__subtitle"><?= __admin('history.subtitle') ?></p>
    </div>
</div>

<script>
// The history page talks to two commands. Both are project-scoped and both sit
// in the `history` category, so reaching this page at all already implies the
// role — but the clear control is gated again in the markup, and the command
// enforces it a third time server-side.
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.commandUrl = '<?= $router->url('command') ?>';
window.QUICKSITE_CONFIG.consoleEnabled = <?= $router->isConsoleEnabled() ? 'true' : 'false' ?>;

// The strings history.js builds in JS. The layout emits only a small subset of
// the translation tree into QUICKSITE_CONFIG (common, dashboard, …) and never
// carried `history`, so every JS-side label on this page silently fell back to
// its English literal — including for a French panel. Emitted per-page here,
// the same way builds.php emits QS_BUILDS_I18N.
window.QS_HISTORY_I18N = <?= json_encode([
    'common' => [
        'loading' => __admin('common.loading'),
    ],
    'history' => [
        'noHistory'         => __admin('history.noHistory'),
        'noHistoryFiltered' => __admin('history.noHistoryFiltered'),
        'exportEmpty'       => __admin('history.exportEmpty'),
        'columns' => [
            'timestamp' => __admin('history.columns.timestamp'),
            'command'   => __admin('history.columns.command'),
            'method'    => __admin('history.columns.method'),
            'user'      => __admin('history.columns.user'),
            'status'    => __admin('history.columns.status'),
            'duration'  => __admin('history.columns.duration'),
            'details'   => __admin('history.columns.details'),
        ],
        'detail' => [
            'id'          => __admin('history.detail.id'),
            'timestamp'   => __admin('history.detail.timestamp'),
            'command'     => __admin('history.detail.command'),
            'method'      => __admin('history.detail.method'),
            'duration'    => __admin('history.detail.duration'),
            'publisher'   => __admin('history.detail.publisher'),
            'userId'      => __admin('history.detail.userId'),
            'params'      => __admin('history.detail.params'),
            'requestBody' => __admin('history.detail.requestBody'),
            'response'    => __admin('history.detail.response'),
        ],
        'filters' => [
            'success' => __admin('history.filters.success'),
            'error'   => __admin('history.filters.error'),
        ],
        'errors' => [
            'loadFailed' => __admin('history.errors.loadFailed'),
        ],
        'clear' => [
            'pickDate'        => __admin('history.clear.pickDate'),
            'nothing'         => __admin('history.clear.nothing'),
            'noDays'          => __admin('history.clear.noDays'),
            'storedDays'      => __admin('history.clear.storedDays'),
            'dayGranularHint' => __admin('history.clear.dayGranularHint'),
            'done'            => __admin('history.clear.done'),
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/history.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/history.js') ?>"></script>

<!-- Filters -->
<div class="admin-card" style="margin-bottom: var(--space-lg);">
    <div class="admin-card__body">
        <div class="admin-filter-row">
            <div class="admin-filter-group">
                <label class="admin-label" for="filter-date"><?= __admin('history.filters.date') ?></label>
                <div style="display: flex; gap: var(--space-xs); align-items: center;">
                    <input type="date" id="filter-date" class="admin-input" style="flex: 1;">
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="clear-date-filter"
                            title="<?= adminAttr(__admin('history.filters.showAllDates')) ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="admin-filter-group">
                <label class="admin-label" for="filter-command"><?= __admin('history.filters.command') ?></label>
                <input type="text" id="filter-command" class="admin-input" placeholder="e.g., getStructure, edit, delete...">
            </div>

            <div class="admin-filter-group">
                <label class="admin-label" for="filter-status"><?= __admin('history.filters.status') ?></label>
                <select id="filter-status" class="admin-select">
                    <option value=""><?= __admin('history.filters.all') ?></option>
                    <option value="success"><?= __admin('history.filters.success') ?></option>
                    <option value="error"><?= __admin('history.filters.error') ?></option>
                </select>
            </div>

            <div class="admin-filter-group admin-filter-group--actions">
                <button type="button" class="admin-btn admin-btn--primary" id="history-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <?= __admin('common.search') ?>
                </button>
                <button type="button" class="admin-btn admin-btn--outline" id="history-reset">
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
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="history-export">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <?= __admin('history.exportCsv', 'Export CSV') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm admin-btn--danger"
                    id="history-clear" data-requires-command="clearCommandHistory">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                <?= __admin('history.clearHistory') ?>
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
    <button class="admin-btn admin-btn--outline" id="prev-page">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
        <?= __admin('common.previous') ?>
    </button>
    <span class="admin-pagination__info" id="page-info"></span>
    <button class="admin-btn admin-btn--outline" id="next-page">
        <?= __admin('common.next') ?>
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
            <h3 class="admin-modal__title"><?= __admin('history.columns.details') ?></h3>
            <button class="admin-modal__close" data-action="close-detail">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal__body" id="detail-content"></div>
    </div>
</div>

<!--
  Clear Modal.

  ⚠ DESTRUCTIVE AND IRREVERSIBLE, so the dialog does not ask "are you sure" over
  an unknown quantity. `clearCommandHistory` answers a PREVIEW when called
  without `confirm` — files, entries and kilobytes that would go — and the
  confirm button stays disabled until that preview has come back. What is being
  destroyed is stated in numbers before anything is destroyed.

  ⚠ The command deletes entries recorded strictly BEFORE the chosen date and
  refuses a future one, so today's entries cannot be cleared. The dialog says so
  rather than letting the operator discover it.
-->
<div class="admin-modal" id="clear-modal" style="display: none;">
    <div class="admin-modal__backdrop" data-action="close-clear"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('history.clearHistory') ?></h3>
            <button class="admin-modal__close" data-action="close-clear">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal__body">
            <p><?= __admin('history.clear.intro', 'Deletes stored command history for this project. This cannot be undone.') ?></p>

            <div class="admin-filter-group" style="margin: var(--space-md) 0;">
                <label class="admin-label" for="clear-before"><?= __admin('history.clear.beforeLabel', 'Delete whole days before') ?></label>
                <input type="date" id="clear-before" class="admin-input">
                <small class="admin-form-hint"><?= __admin('history.clear.beforeHint', 'History is stored one file per day and is deleted a whole day at a time. Days from this date onwards are kept, so today is never deleted.') ?></small>
            </div>

            <div id="clear-preview" class="admin-alert admin-alert--info"></div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--outline" data-action="close-clear">
                <?= __admin('common.cancel') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--danger" id="clear-confirm" disabled>
                <?= __admin('history.clear.confirmButton', 'Delete permanently') ?>
            </button>
        </div>
    </div>
</div>
