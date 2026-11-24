<!-- Enums Panel (component-only, shown when Enums button clicked) -->
<div class="preview-contextual-form" id="contextual-enums-panel" style="display: none;">
    <div class="preview-contextual-form__header">
        <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
            </svg>
            <?= adminEscape(__admin('preview.enums')) ?>
        </h4>
        <button type="button" class="admin-modal__close" id="enums-panel-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
    </div>

    <!-- Loading state -->
    <div class="preview-enums-panel__loading" id="enums-panel-loading">
        <span class="admin-spinner"></span>
        <span><?= adminEscape(__admin('common.loading')) ?></span>
    </div>

    <!-- Empty state (no enums defined) -->
    <div class="preview-enums-panel__empty" id="enums-panel-empty" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px;opacity:0.4;">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
        </svg>
        <span><?= adminEscape(__admin('preview.noEnumsDefined')) ?></span>
    </div>

    <!-- Cards container (populated by JS) -->
    <div class="preview-enums-panel__cards" id="enums-panel-cards"></div>

    <!-- Add enum button -->
    <div class="preview-enums-panel__add" id="enums-panel-add" style="display: none;">
        <button type="button" class="admin-btn admin-btn--success" id="enums-panel-add-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= adminEscape(__admin('preview.addEnum')) ?>
        </button>
    </div>
</div>
