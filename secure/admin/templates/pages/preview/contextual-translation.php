<!-- TRANSLATION MODE Content (Translation Manager panel) -->
<div class="preview-contextual-section" id="contextual-translation" data-mode="translation" style="display: none;">

    <!-- Top toolbar: language picker + scope picker + coverage summary -->
    <div class="preview-contextual-translation__toolbar">
        <div class="preview-contextual-translation__toolbar-row">
            <label class="preview-contextual-translation__label" for="translation-lang">
                <?= adminEscape(__admin('preview.translationLanguage')) ?>:
            </label>
            <select class="preview-contextual-translation__select" id="translation-lang">
                <!-- Options populated by JS on mode entry -->
            </select>

            <label class="preview-contextual-translation__label" for="translation-scope">
                <?= adminEscape(__admin('preview.translationScope')) ?>:
            </label>
            <select class="preview-contextual-translation__select" id="translation-scope">
                <!-- Options populated by JS — "Whole site" + each page + each component -->
            </select>
        </div>

        <div class="preview-contextual-translation__toolbar-row preview-contextual-translation__coverage" id="translation-coverage">
            <!-- Populated by JS: coverage % + counts (used / unset / unused). Empty until data loads. -->
            <span>
                <?= adminEscape(__admin('preview.translationLoading')) ?>
            </span>
        </div>
    </div>

    <!-- Filter row: substring search + status chips -->
    <div class="preview-contextual-translation__filter-row">
        <input type="search" class="preview-contextual-translation__filter" id="translation-filter"
               placeholder="<?= adminAttr(__admin('preview.translationFilterPlaceholder')) ?>"
               autocomplete="off">
        <div class="preview-contextual-translation__chips" id="translation-status-chips">
            <label class="preview-contextual-translation__chip"
                   title="<?= adminAttr(__admin('preview.translationStatusUsedHint')) ?>">
                <input type="checkbox" checked data-status="used">
                <span class="preview-contextual-translation__chip-dot">🟢</span>
                <span class="preview-contextual-translation__chip-label"><?= adminEscape(__admin('preview.translationStatusUsed')) ?></span>
                <span class="preview-contextual-translation__chip-count" id="translation-count-used">0</span>
            </label>
            <label class="preview-contextual-translation__chip"
                   title="<?= adminAttr(__admin('preview.translationStatusUnsetHint')) ?>">
                <input type="checkbox" checked data-status="unset">
                <span class="preview-contextual-translation__chip-dot">🔴</span>
                <span class="preview-contextual-translation__chip-label"><?= adminEscape(__admin('preview.translationStatusUnset')) ?></span>
                <span class="preview-contextual-translation__chip-count" id="translation-count-unset">0</span>
            </label>
            <label class="preview-contextual-translation__chip"
                   title="<?= adminAttr(__admin('preview.translationStatusUnusedHint')) ?>">
                <input type="checkbox" checked data-status="unused">
                <span class="preview-contextual-translation__chip-dot">🟡</span>
                <span class="preview-contextual-translation__chip-label"><?= adminEscape(__admin('preview.translationStatusUnused')) ?></span>
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
            <p><?= adminEscape(__admin('preview.translationEmptyHint')) ?></p>
        </div>

        <!-- Loading state: shown until first fetch completes. -->
        <div class="preview-contextual-translation__loading" id="translation-loading">
            <span><?= adminEscape(__admin('preview.translationLoading')) ?></span>
        </div>

        <!-- Rows are appended here by JS. -->
        <div class="preview-contextual-translation__rows" id="translation-rows"></div>
    </div>

    <!-- Bottom actions: bulk remove-unused (site-wide, with confirm) -->
    <div class="preview-contextual-translation__actions" id="translation-actions">
        <button type="button" class="admin-btn admin-btn--danger" id="translation-remove-unused" disabled
                title="<?= adminAttr(__admin('preview.translationRemoveUnusedHint')) ?>">
            <?= adminEscape(__admin('preview.translationRemoveUnused')) ?>
        </button>
    </div>
</div>
