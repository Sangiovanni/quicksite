<div class="preview-main">
    <div class="preview-device-row">
        <div class="preview-toolbar__devices">
            <button type="button" class="preview-device-btn preview-device-btn--active" data-device="desktop" title="Desktop (100%)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                    <line x1="8" y1="21" x2="16" y2="21"/>
                    <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
            </button>
            <button type="button" class="preview-device-btn" data-device="tablet" title="Tablet (768px)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="2" width="16" height="20" rx="2" ry="2"/>
                    <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
            </button>
            <button type="button" class="preview-device-btn" data-device="mobile" title="Mobile (375px)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
                    <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Component Warning Banner (shown when editing a component) -->
    <div class="preview-component-warning" id="preview-component-warning" style="display: none;">
        <svg class="preview-component-warning__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <span class="preview-component-warning__text" id="preview-component-warning-text"></span>
    </div>

    <!-- Mobile Context Sections (info + actions, shown when element selected on mobile) -->
    <!-- Each section is independently collapsible -->
    <div class="preview-mobile-sections" id="preview-mobile-sections">
        
        <!-- Section 1: Element Info (collapsible) -->
        <div class="preview-mobile-section preview-mobile-section--info" id="mobile-section-info">
            <button type="button" class="preview-mobile-section__toggle" data-section="info">
                <svg class="preview-mobile-section__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="preview-mobile-section__title"><?= __admin('preview.elementInfo') ?? 'Element Info' ?></span>
                <span class="preview-mobile-section__summary" id="mobile-info-summary">-</span>
            </button>
            <div class="preview-mobile-section__content" id="mobile-info-content">
                <div class="preview-mobile-section__row">
                    <span class="preview-mobile-section__label"><?= __admin('preview.nodeId') ?? 'Node' ?>:</span>
                    <code class="preview-mobile-section__value" id="mobile-ctx-id">-</code>
                </div>
                <div class="preview-mobile-section__row">
                    <span class="preview-mobile-section__label"><?= __admin('preview.nodeTag') ?? 'Tag' ?>:</span>
                    <span class="preview-mobile-section__value" id="mobile-ctx-tag">-</span>
                </div>
                <div class="preview-mobile-section__row">
                    <span class="preview-mobile-section__label"><?= __admin('preview.nodeClasses') ?? 'Classes' ?>:</span>
                    <code class="preview-mobile-section__value" id="mobile-ctx-classes">-</code>
                </div>
                <div class="preview-mobile-section__row">
                    <span class="preview-mobile-section__label"><?= __admin('preview.nodeChildren') ?? 'Children' ?>:</span>
                    <span class="preview-mobile-section__value" id="mobile-ctx-children">-</span>
                </div>
                <div class="preview-mobile-section__row" id="mobile-ctx-textkey-row" style="display: none;">
                    <span class="preview-mobile-section__label"><?= __admin('preview.textKey') ?? 'Text Key' ?>:</span>
                    <code class="preview-mobile-section__value preview-mobile-section__value--textkey" id="mobile-ctx-textkey">-</code>
                </div>
                <div class="preview-mobile-section__row" id="mobile-ctx-component-row" style="display: none;">
                    <span class="preview-mobile-section__label"><?= __admin('preview.componentName') ?? 'Component' ?>:</span>
                    <code class="preview-mobile-section__value" id="mobile-ctx-component">-</code>
                </div>
            </div>
        </div>
        
        <!-- Section 2: Actions (collapsible) -->
        <div class="preview-mobile-section preview-mobile-section--actions" id="mobile-section-actions">
            <button type="button" class="preview-mobile-section__toggle" data-section="actions">
                <svg class="preview-mobile-section__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="preview-mobile-section__title"><?= __admin('preview.actions') ?? 'Actions' ?></span>
                <span class="preview-mobile-section__mode-label" id="mobile-actions-mode">Select</span>
            </button>
            <div class="preview-mobile-section__content" id="mobile-actions-content">
                <!-- Select mode actions -->
                <div class="preview-mobile-section__action-group" data-mode="select">
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--success" id="mobile-ctx-add" title="<?= __admin('preview.addNode') ?? 'Add' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <span><?= __admin('preview.addNode') ?? 'Add' ?></span>
                    </button>
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--info" id="mobile-ctx-duplicate" title="<?= __admin('preview.duplicateNode') ?? 'Duplicate' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <span><?= __admin('preview.duplicateNode') ?? 'Duplicate' ?></span>
                    </button>
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--danger" id="mobile-ctx-delete" title="<?= __admin('common.delete') ?? 'Delete' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                        <span><?= __admin('common.delete') ?? 'Delete' ?></span>
                    </button>
                </div>
                <!-- Drag mode hint -->
                <div class="preview-mobile-section__action-group" data-mode="drag" style="display: none;">
                    <span class="preview-mobile-section__hint"><?= __admin('preview.dragModeHint') ?? 'Drag element to move' ?></span>
                </div>
                <!-- Text mode hint -->
                <div class="preview-mobile-section__action-group" data-mode="text" style="display: none;">
                    <span class="preview-mobile-section__hint"><?= __admin('preview.textModeHintMobile') ?? 'Tap text to edit' ?></span>
                </div>
                <!-- Style mode hint -->
                <div class="preview-mobile-section__action-group" data-mode="style" style="display: none;">
                    <span class="preview-mobile-section__hint"><?= __admin('preview.styleModeHintMobile') ?? 'Use sidebar for styles' ?></span>
                </div>
                <!-- JS mode hint -->
                <div class="preview-mobile-section__action-group" data-mode="js" style="display: none;">
                    <span class="preview-mobile-section__hint"><?= __admin('preview.jsModeHintMobile') ?? 'Use sidebar for interactions' ?></span>
                </div>
            </div>
        </div>
        
    </div>

    <!-- Preview Frame Container -->
    <div class="preview-container" id="preview-container">
        <!-- Miniplayer floating controls (visible only in miniplayer mode) -->
        <div class="preview-miniplayer-controls" id="preview-miniplayer-controls">
            <button type="button" class="preview-miniplayer-controls__btn" id="miniplayer-reload" title="<?= __admin('preview.reload') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
            </button>
            <button type="button" class="preview-miniplayer-controls__btn" id="miniplayer-expand" title="<?= __admin('preview.expand') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 3 21 3 21 9"/>
                    <polyline points="9 21 3 21 3 15"/>
                    <line x1="21" y1="3" x2="14" y2="10"/>
                    <line x1="3" y1="21" x2="10" y2="14"/>
                </svg>
            </button>
            <button type="button" class="preview-miniplayer-controls__btn" id="miniplayer-close" title="<?= __admin('common.close') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="preview-frame-wrapper" id="preview-frame-wrapper">
            <iframe 
                id="preview-iframe"
                class="preview-iframe"
                src="<?= $siteUrl ?>"
                title="Website Preview"
            ></iframe>
            
            <!-- Loading Overlay -->
            <div class="preview-loading" id="preview-loading">
                <div class="preview-loading__spinner"></div>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>

<!-- Device Frame (for tablet/mobile) -->
        <div class="preview-device-frame" id="preview-device-frame"></div>
    </div>
    
    <!-- Global Element Info Bar (always visible at bottom of preview-main) -->
    <div class="preview-element-info" id="preview-element-info">
        <button type="button" class="preview-element-info__toggle" id="preview-element-info-toggle" title="<?= __admin('preview.toggleDetails') ?? 'Toggle details' ?>">
            <svg class="preview-element-info__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
            <span class="preview-element-info__summary" id="preview-element-info-summary">-</span>
        </button>
        <div class="preview-element-info__details" id="preview-element-info-details">
            <div class="preview-element-info__row">
                <span class="preview-element-info__label"><?= __admin('preview.nodeId') ?>:</span>
                <code class="preview-element-info__value" id="info-node-id">-</code>
            </div>
            <div class="preview-element-info__row">
                <span class="preview-element-info__label"><?= __admin('preview.nodeTag') ?>:</span>
                <code class="preview-element-info__value" id="info-node-tag">-</code>
            </div>
            <div class="preview-element-info__row">
                <span class="preview-element-info__label"><?= __admin('preview.nodeClasses') ?>:</span>
                <code class="preview-element-info__value" id="info-node-classes">-</code>
            </div>
            <div class="preview-element-info__row">
                <span class="preview-element-info__label"><?= __admin('preview.nodeChildren') ?>:</span>
                <span class="preview-element-info__value" id="info-node-children">-</span>
            </div>
            <div class="preview-element-info__row" id="info-node-textkey-row" style="display: none;">
                <span class="preview-element-info__label"><?= __admin('preview.textKey') ?? 'Text Key' ?>:</span>
                <code class="preview-element-info__value preview-element-info__value--textkey" id="info-node-textkey">-</code>
            </div>
            <div class="preview-element-info__row" id="info-node-component-row" style="display: none;">
                <span class="preview-element-info__label"><?= __admin('preview.componentName') ?>:</span>
                <code class="preview-element-info__value preview-element-info__value--component" id="info-node-component">-</code>
            </div>
        </div>
    </div>
    </div>

    <!-- Mobile Bottom Toolbar (visible only on mobile) -->
    <div class="preview-mobile-toolbar" id="preview-mobile-toolbar">
        <button type="button" class="preview-mobile-tool preview-mobile-tool--active" data-mode="select">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
                <path d="M13 13l6 6"/>
            </svg>
            <span><?= __admin('preview.toolSelect') ?? 'Select' ?></span>
        </button>
        <button type="button" class="preview-mobile-tool" data-mode="drag">
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
        <button type="button" class="preview-mobile-tool" data-mode="text">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="4 7 4 4 20 4 20 7"/>
                <line x1="9" y1="20" x2="15" y2="20"/>
                <line x1="12" y1="4" x2="12" y2="20"/>
            </svg>
            <span><?= __admin('preview.toolText') ?? 'Text' ?></span>
        </button>
        <button type="button" class="preview-mobile-tool" data-mode="style">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
            </svg>
            <span><?= __admin('preview.toolStyle') ?? 'CSS' ?></span>
        </button>
        <button type="button" class="preview-mobile-tool" data-mode="js">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <span><?= __admin('preview.toolJs') ?? 'Interact' ?></span>
        </button>
    </div>
</div>
