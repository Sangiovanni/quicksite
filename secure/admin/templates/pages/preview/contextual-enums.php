<!-- Enums Panel (component-only, shown when Enums button clicked) -->
<div class="preview-contextual-form preview-enums-panel" id="contextual-enums-panel" style="display: none;">
    <div class="preview-contextual-form__header">
        <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
            </svg>
            <?= __admin('preview.enums') ?? 'Enums' ?>
        </h4>
        <button type="button" class="preview-contextual-form__close" id="enums-panel-close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <!-- Loading state -->
    <div class="preview-enums-panel__loading" id="enums-panel-loading">
        <span class="admin-spinner admin-spinner--sm"></span>
        <span><?= __admin('common.loading') ?? 'Loading...' ?></span>
    </div>

    <!-- Empty state (no enums defined) -->
    <div class="preview-enums-panel__empty" id="enums-panel-empty" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px;opacity:0.4;">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
        </svg>
        <span><?= __admin('preview.noEnumsDefined') ?? 'No enums defined for this component' ?></span>
    </div>

    <!-- Cards container (populated by JS) -->
    <div class="preview-enums-panel__cards" id="enums-panel-cards"></div>

    <!-- Add enum button -->
    <div class="preview-enums-panel__add" id="enums-panel-add" style="display: none;">
        <button type="button" class="admin-btn admin-btn--sm admin-btn--success" id="enums-panel-add-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('preview.addEnum') ?? 'Add Enum' ?>
        </button>
    </div>
</div>
