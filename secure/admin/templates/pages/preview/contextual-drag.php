<!-- DRAG MODE Content -->
<div class="preview-contextual-section preview-contextual-section--drag" id="contextual-drag" data-mode="drag" style="display: none;">
    <div class="preview-contextual-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="5 9 2 12 5 15"/>
            <polyline points="9 5 12 2 15 5"/>
            <polyline points="15 19 12 22 9 19"/>
            <polyline points="19 9 22 12 19 15"/>
            <line x1="2" y1="12" x2="22" y2="12"/>
            <line x1="12" y1="2" x2="12" y2="22"/>
        </svg>
        <span><?= __admin('preview.dragModeHint') ?? 'Drag elements to reorder them within their container' ?></span>
    </div>
</div>
