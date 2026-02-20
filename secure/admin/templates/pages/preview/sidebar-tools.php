<div class="preview-sidebar__tools" id="preview-sidebar-tools">
    <label class="preview-sidebar__tools-label">
        <input type="checkbox" id="preview-tools-show-names" checked>
        <span><?= __admin('preview.showToolNames') ?? 'Show names' ?></span>
    </label>
    <div class="preview-sidebar__tools-buttons">
        <button type="button" class="preview-sidebar-tool preview-sidebar-tool--active" data-mode="select" title="<?= __admin('preview.toolSelect') ?? 'Select' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
                <path d="M13 13l6 6"/>
            </svg>
            <span><?= __admin('preview.toolSelect') ?? 'Select' ?></span>
        </button>
    <button type="button" class="preview-sidebar-tool" data-mode="drag" title="<?= __admin('preview.toolDrag') ?? 'Move' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="5 9 2 12 5 15"/>
            <polyline points="9 5 12 2 15 5"/>
            <polyline points="15 19 12 22 9 19"/>
            <polyline points="19 9 22 12 19 15"/>
            <line x1="2" y1="12" x2="22" y2="12"/>
            <line x1="12" y1="2" x2="12" y2="22"/>
        </svg>
        <span><?= __admin('preview.toolDrag') ?? 'Move' ?></span>
    </button>
    <button type="button" class="preview-sidebar-tool" data-mode="text" title="<?= __admin('preview.toolText') ?? 'Text' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="4 7 4 4 20 4 20 7"/>
            <line x1="9" y1="20" x2="15" y2="20"/>
            <line x1="12" y1="4" x2="12" y2="20"/>
        </svg>
        <span><?= __admin('preview.toolText') ?? 'Text' ?></span>
    </button>
    <button type="button" class="preview-sidebar-tool" data-mode="style" title="<?= __admin('preview.toolStyle') ?? 'CSS' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
        </svg>
        <span><?= __admin('preview.toolStyle') ?? 'CSS' ?></span>
    </button>
    <button type="button" class="preview-sidebar-tool" data-mode="js" title="<?= __admin('preview.toolJs') ?? 'Interactions' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
        <span><?= __admin('preview.toolJs') ?? 'Interactions' ?></span>
    </button>
    </div>
</div>
