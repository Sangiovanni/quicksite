<!-- TRANSLATION MODE Content (Beta.9 A4 — Translation Manager panel) -->
<div class="preview-contextual-section preview-contextual-section--translation" id="contextual-translation" data-mode="translation" style="display: none;">

    <!-- Top toolbar: language picker + scope picker + coverage summary -->
    <div class="preview-contextual-translation__toolbar">
        <div class="preview-contextual-translation__toolbar-row">
            <label class="preview-contextual-translation__label" for="translation-lang">
                <?= __admin('preview.translationLanguage', 'Language') ?>:
            </label>
            <select class="preview-contextual-translation__select" id="translation-lang">
                <!-- Options populated by JS on mode entry -->
            </select>

            <label class="preview-contextual-translation__label" for="translation-scope">
                <?= __admin('preview.translationScope', 'Scope') ?>:
            </label>
            <select class="preview-contextual-translation__select" id="translation-scope">
                <!-- Options populated by JS — "Whole site" + each page + each component -->
            </select>
        </div>

        <div class="preview-contextual-translation__toolbar-row preview-contextual-translation__coverage" id="translation-coverage">
            <!-- Populated by JS: coverage % + counts (used / unset / unused). Empty until data loads. -->
            <span class="preview-contextual-translation__coverage-loading">
                <?= __admin('preview.translationLoading', 'Loading…') ?>
            </span>
        </div>
    </div>

    <!-- Filter row: substring search + status chips -->
    <div class="preview-contextual-translation__filter-row">
        <input type="search" class="preview-contextual-translation__filter" id="translation-filter"
               placeholder="<?= __admin('preview.translationFilterPlaceholder', 'Filter keys…') ?>"
               autocomplete="off">
        <div class="preview-contextual-translation__chips" id="translation-status-chips">
            <label class="preview-contextual-translation__chip preview-contextual-translation__chip--used"
                   title="<?= __admin('preview.translationStatusUsedHint', 'Keys referenced by your site AND translated for this language.') ?>">
                <input type="checkbox" checked data-status="used">
                <span class="preview-contextual-translation__chip-dot">🟢</span>
                <span class="preview-contextual-translation__chip-label"><?= __admin('preview.translationStatusUsed', 'Used') ?></span>
                <span class="preview-contextual-translation__chip-count" id="translation-count-used">0</span>
            </label>
            <label class="preview-contextual-translation__chip preview-contextual-translation__chip--unset"
                   title="<?= __admin('preview.translationStatusUnsetHint', 'Keys referenced by your site but missing or empty in this language.') ?>">
                <input type="checkbox" checked data-status="unset">
                <span class="preview-contextual-translation__chip-dot">🔴</span>
                <span class="preview-contextual-translation__chip-label"><?= __admin('preview.translationStatusUnset', 'Unset') ?></span>
                <span class="preview-contextual-translation__chip-count" id="translation-count-unset">0</span>
            </label>
            <label class="preview-contextual-translation__chip preview-contextual-translation__chip--unused"
                   title="<?= __admin('preview.translationStatusUnusedHint', 'Keys translated for this language but no longer referenced by any page or component.') ?>">
                <input type="checkbox" checked data-status="unused">
                <span class="preview-contextual-translation__chip-dot">🟡</span>
                <span class="preview-contextual-translation__chip-label"><?= __admin('preview.translationStatusUnused', 'Unused') ?></span>
                <span class="preview-contextual-translation__chip-count" id="translation-count-unused">0</span>
            </label>
        </div>
    </div>

    <!-- Row list (populated by JS; empty state hidden by default) -->
    <div class="preview-contextual-translation__list" id="translation-list">
        <!-- Empty state: no keys at all. JS toggles this on/off based on data. -->
        <div class="preview-contextual-translation__empty" id="translation-empty" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                <line x1="9" y1="9" x2="9.01" y2="9"/>
                <line x1="15" y1="9" x2="15.01" y2="9"/>
            </svg>
            <p><?= __admin('preview.translationEmptyHint', 'No translation keys yet. Add a text key from Add Element → Text.') ?></p>
        </div>

        <!-- Loading state: shown until first fetch completes. -->
        <div class="preview-contextual-translation__loading" id="translation-loading">
            <span><?= __admin('preview.translationLoading', 'Loading…') ?></span>
        </div>

        <!-- Rows are appended here by JS. -->
        <div class="preview-contextual-translation__rows" id="translation-rows"></div>
    </div>

    <!-- Bottom actions: bulk remove-unused (site-wide, with confirm) -->
    <div class="preview-contextual-translation__actions" id="translation-actions">
        <button type="button" class="admin-btn admin-btn--danger" id="translation-remove-unused" disabled
                title="<?= __admin('preview.translationRemoveUnusedHint', 'Delete all unused keys across the whole site (site-wide, not just current scope).') ?>">
            <?= __admin('preview.translationRemoveUnused', 'Remove all unused (site-wide)') ?>
        </button>
    </div>
</div>
