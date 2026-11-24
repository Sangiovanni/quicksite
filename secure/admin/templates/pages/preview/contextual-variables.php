<!-- Variables Panel (component-only, shown when Variables button clicked) -->
<div class="preview-contextual-form preview-variables-panel" id="contextual-variables-panel" style="display: none;">
    <div class="preview-contextual-form__header">
        <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
            </svg>
            <?= adminEscape(__admin('preview.variables')) ?>
        </h4>
        <button type="button" class="admin-modal__close" id="variables-panel-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
    </div>

    <!-- Loading state -->
    <div class="preview-variables-panel__loading" id="variables-panel-loading">
        <span class="admin-spinner"></span>
        <span><?= adminEscape(__admin('common.loading')) ?></span>
    </div>

    <!-- Empty state (no variables for selected node) -->
    <div class="preview-variables-panel__empty" id="variables-panel-empty" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px;opacity:0.4;">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
        </svg>
        <span><?= adminEscape(__admin('preview.noVariablesForNode')) ?></span>
    </div>

    <!-- Cards container (populated by JS) -->
    <div class="preview-variables-panel__cards" id="variables-panel-cards"></div>

    <!-- Info footer: nodes without textKey -->
    <div class="preview-variables-panel__footer" id="variables-panel-footer" style="display: none;">
        <small id="variables-panel-footer-text"></small>
    </div>
</div>
