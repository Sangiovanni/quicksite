<!-- Variable Emulation Panel (component-only, shown when Emulation button clicked) -->
<div class="preview-contextual-form preview-emulation-panel" id="contextual-emulation-panel" style="display: none;">
    <div class="preview-contextual-form__header">
        <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <?= __admin('preview.emulation') ?? 'Variable Emulation' ?>
        </h4>
        <button type="button" class="preview-contextual-form__close" id="emulation-panel-close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <!-- Loading state -->
    <div class="preview-emulation-panel__loading" id="emulation-panel-loading">
        <span class="admin-spinner admin-spinner--sm"></span>
        <span><?= __admin('common.loading') ?? 'Loading...' ?></span>
    </div>

    <!-- Empty state (no variables) -->
    <div class="preview-emulation-panel__empty" id="emulation-panel-empty" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px;opacity:0.4;">
            <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <span><?= __admin('preview.noVariablesToEmulate') ?? 'No variables found in this component' ?></span>
    </div>

    <!-- Variables form container (populated by JS) -->
    <div class="preview-emulation-panel__fields" id="emulation-panel-fields"></div>

    <!-- Action buttons -->
    <div class="preview-emulation-panel__actions" id="emulation-panel-actions" style="display: none;">
        <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="emulation-apply-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                <polygon points="5 3 19 12 5 21 5 3"/>
            </svg>
            <?= __admin('preview.emulationApply') ?? 'Apply Preview' ?>
        </button>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--outline" id="emulation-reset-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
            </svg>
            <?= __admin('preview.emulationReset') ?? 'Reset All' ?>
        </button>
    </div>
</div>
