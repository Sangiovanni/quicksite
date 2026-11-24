<!-- DRAG MODE Content -->
<div class="preview-contextual-section preview-contextual-section--drag" id="contextual-drag" data-mode="drag" style="display: none;">
    <div class="preview-contextual-default" id="contextual-drag-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="5 9 2 12 5 15"/>
            <polyline points="9 5 12 2 15 5"/>
            <polyline points="15 19 12 22 9 19"/>
            <polyline points="19 9 22 12 19 15"/>
            <line x1="2" y1="12" x2="22" y2="12"/>
            <line x1="12" y1="2" x2="12" y2="22"/>
        </svg>
        <span><?= adminEscape(__admin('preview.dragModeHint')) ?></span>
    </div>
    <div class="preview-contextual-info" id="contextual-drag-info" style="display: none;">
        <!-- Navigation Buttons (same as select mode, for Phase 1 tree navigation) -->
        <div class="preview-contextual-info__nav" id="ctx-drag-nav">
            <button type="button" class="preview-nav-btn" id="ctx-drag-nav-parent" title="<?= adminAttr(__admin('preview.goToParent')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="18 15 12 9 6 15"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-drag-nav-prev" title="<?= adminAttr(__admin('preview.goToPrevSibling')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-drag-nav-next" title="<?= adminAttr(__admin('preview.goToNextSibling')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-drag-nav-child" title="<?= adminAttr(__admin('preview.goToFirstChild')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>

        <!-- Move tool options (lock, undo, redo) -->
        <div class="preview-sidebar-tool-options" id="preview-drag-options">
            <button type="button" class="preview-sidebar-tool-option" id="preview-drag-lock" title="<?= adminAttr(__admin('preview.dragLock')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                <span><?= adminEscape(__admin('preview.dragLock')) ?></span>
            </button>
            <button type="button" class="preview-sidebar-tool-option" id="preview-drag-undo" title="<?= adminAttr(__admin('preview.dragUndo')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1 4 1 10 7 10"/>
                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                </svg>
                <span><?= adminEscape(__admin('preview.dragUndo')) ?></span>
            </button>
            <button type="button" class="preview-sidebar-tool-option" id="preview-drag-redo" title="<?= adminAttr(__admin('preview.dragRedo')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.13-9.36L23 10"/>
                </svg>
                <span><?= adminEscape(__admin('preview.dragRedo')) ?></span>
            </button>
        </div>
        <div class="preview-sidebar-tool-hint" id="preview-drag-hint"></div>
    </div>
</div>
