<!-- Preview Controls - Row 2: the tools, then the device switch. A tool is one button here and nowhere else;
     data-panel-opens says its panel holds its options from the moment it is picked. -->
<div class="preview-toolbar preview-toolbar--row2" id="preview-tools">
    <label class="preview-tools__show-names">
        <input type="checkbox" id="preview-tools-show-names" checked>
        <span><?= __admin('preview.showToolNames') ?? 'Show names' ?></span>
    </label>
    <div class="preview-tools__buttons">
        <button type="button" class="preview-tool preview-tool--active" data-mode="select" title="<?= __admin('preview.toolSelect') ?? 'Select' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
                <path d="M13 13l6 6"/>
            </svg>
            <span><?= __admin('preview.toolSelect') ?? 'Select' ?></span>
        </button>
        <button type="button" class="preview-tool" data-mode="drag" data-panel-opens title="<?= __admin('preview.toolDrag') ?? 'Move' ?>">
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
        <button type="button" class="preview-tool" data-mode="text" title="<?= __admin('preview.toolText') ?? 'Text' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="4 7 4 4 20 4 20 7"/>
                <line x1="9" y1="20" x2="15" y2="20"/>
                <line x1="12" y1="4" x2="12" y2="20"/>
            </svg>
            <span><?= __admin('preview.toolText') ?? 'Text' ?></span>
        </button>
        <button type="button" class="preview-tool" data-mode="style" data-panel-opens title="<?= __admin('preview.toolStyle') ?? 'CSS' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
            </svg>
            <span><?= __admin('preview.toolStyle') ?? 'CSS' ?></span>
        </button>
        <button type="button" class="preview-tool" data-mode="js" data-panel-opens title="<?= __admin('preview.toolJs') ?? 'Interactions' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <span><?= __admin('preview.toolJs') ?? 'Interactions' ?></span>
        </button>
        <button type="button" class="preview-tool" data-mode="translation" data-panel-opens title="<?= __admin('preview.toolTranslation', 'Translations') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 8h12"/>
                <path d="M9 4v4"/>
                <path d="M11 16l3-8 3 8"/>
                <path d="M12.5 13h3"/>
                <path d="M9 8c0 4-2 6-5 8"/>
                <path d="M7 12c1 2 3 4 5 5"/>
            </svg>
            <span><?= __admin('preview.toolTranslation', 'Translations') ?></span>
        </button>
        <button type="button" class="preview-tool" data-mode="ai-tools" data-panel-opens title="<?= __admin('preview.toolAiTools', 'AI tools') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5z"/>
                <path d="M19 14l.5 1.5L21 16l-1.5.5L19 18l-.5-1.5L17 16l1.5-.5z"/>
                <path d="M5 16l.5 1.5L7 18l-1.5.5L5 20l-.5-1.5L3 18l1.5-.5z"/>
            </svg>
            <span><?= __admin('preview.toolAiTools', 'AI tools') ?></span>
        </button>
    </div>
    <div class="preview-toolbar__devices">
        <button type="button" class="preview-device-btn preview-device-btn--active" data-device="desktop" title="<?= __admin('preview.deviceDesktop') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/>
                <line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
        </button>
        <button type="button" class="preview-device-btn" data-device="tablet" title="<?= __admin('preview.deviceTablet') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="4" y="2" width="16" height="20" rx="2" ry="2"/>
                <line x1="12" y1="18" x2="12.01" y2="18"/>
            </svg>
        </button>
        <button type="button" class="preview-device-btn" data-device="mobile" title="<?= __admin('preview.deviceMobile') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
                <line x1="12" y1="18" x2="12.01" y2="18"/>
            </svg>
        </button>
    </div>
</div>
