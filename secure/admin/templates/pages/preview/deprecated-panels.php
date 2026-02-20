<!-- Node Info Panel (DEPRECATED - kept for reference, hidden) -->
<div class="preview-node-panel" id="preview-node-panel" style="display: none !important;">
    <div class="preview-node-panel__header" title="<?= __admin('preview.dragToMove') ?? 'Drag to move' ?>">
        <svg class="preview-panel-drag-icon" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
            <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
            <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
            <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
        </svg>
        <h3 class="preview-node-panel__title"><?= __admin('preview.nodeInfo') ?></h3>
        <button type="button" class="preview-node-panel__close" id="preview-node-close" title="<?= __admin('common.close') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>
    <div class="preview-node-panel__content">
        <div class="preview-node-panel__row">
            <span class="preview-node-panel__label"><?= __admin('preview.structure') ?>:</span>
            <code class="preview-node-panel__value preview-node-panel__value--highlight" id="node-struct">-</code>
        </div>
        <div class="preview-node-panel__row">
            <span class="preview-node-panel__label"><?= __admin('preview.nodeId') ?>:</span>
            <code class="preview-node-panel__value" id="node-id">-</code>
        </div>
        <div class="preview-node-panel__row" id="node-component-row" style="display: none;">
            <span class="preview-node-panel__label"><?= __admin('preview.componentName') ?>:</span>
            <code class="preview-node-panel__value preview-node-panel__value--component" id="node-component">-</code>
        </div>
        <div class="preview-node-panel__row">
            <span class="preview-node-panel__label"><?= __admin('preview.nodeTag') ?>:</span>
            <code class="preview-node-panel__value" id="node-tag">-</code>
        </div>
        <div class="preview-node-panel__row">
            <span class="preview-node-panel__label"><?= __admin('preview.nodeClasses') ?>:</span>
            <code class="preview-node-panel__value" id="node-classes">-</code>
        </div>
        <div class="preview-node-panel__row">
            <span class="preview-node-panel__label"><?= __admin('preview.nodeChildren') ?>:</span>
            <span class="preview-node-panel__value" id="node-children">-</span>
        </div>
        <div class="preview-node-panel__row">
            <span class="preview-node-panel__label"><?= __admin('preview.nodeText') ?>:</span>
            <span class="preview-node-panel__value preview-node-panel__value--truncate" id="node-text">-</span>
        </div>
        <div class="preview-node-panel__row" id="node-textkey-row" style="display: none;">
            <span class="preview-node-panel__label"><?= __admin('preview.textKey') ?? 'Text Key' ?>:</span>
            <code class="preview-node-panel__value preview-node-panel__value--textkey" id="node-textkey">-</code>
        </div>
    </div>
    <div class="preview-node-panel__actions">
        <button type="button" class="admin-btn admin-btn--sm admin-btn--success" id="node-add" title="<?= __admin('preview.addNode') ?? 'Add Node' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; vertical-align: middle;">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
        </button>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" id="node-delete" title="<?= __admin('common.delete') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; vertical-align: middle;">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                <line x1="10" y1="11" x2="10" y2="17"/>
                <line x1="14" y1="11" x2="14" y2="17"/>
            </svg>
        </button>
    </div>
</div>


