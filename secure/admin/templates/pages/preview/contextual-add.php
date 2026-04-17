<!-- ADD MODE Content -->
<div class="preview-contextual-section preview-contextual-section--add" id="contextual-add" data-mode="add">
    <div class="preview-contextual-default" id="contextual-add-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        <span><?= __admin('preview.addModeHint') ?? 'Select an element first to add siblings or children' ?></span>
    </div>
    
    <div class="preview-contextual-form" id="contextual-add-form" style="display: none;">
        <!-- Mode Header with Back Button -->
        <div class="preview-contextual-form__mode-header">
            <span class="preview-contextual-form__mode-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('preview.addElement') ?? 'Add Element' ?>
            </span>
            <button type="button" class="preview-contextual-form__back-btn" id="add-back-to-select" title="<?= __admin('preview.backToSelect') ?? 'Back to Select' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                <span><?= __admin('common.back') ?? 'Back' ?></span>
            </button>
        </div>
        
        <!-- Element Type Selection: Tabs -->
        <div class="preview-contextual-form__field">
            <div class="preview-contextual-form__tabs" id="add-type-tabs">
                <button type="button" class="preview-contextual-form__tab active" data-type="snippet">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
                    </svg>
                    <span><?= __admin('preview.snippet') ?? 'Snippet' ?></span>
                </button>
                <button type="button" class="preview-contextual-form__tab" data-type="component">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    <span><?= __admin('preview.component') ?? 'Component' ?></span>
                </button>
                <button type="button" class="preview-contextual-form__tab" data-type="tag">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                    </svg>
                    <span><?= __admin('preview.htmlTag') ?? 'HTML Tag' ?></span>
                </button>
            </div>
            <input type="hidden" id="add-type-input" value="snippet">
        </div>

        <!-- TOP Action Button (quick add with defaults) -->
        <div class="preview-contextual-form__actions preview-contextual-form__actions--top">
            <button type="button" class="admin-btn admin-btn--success admin-btn--full" id="add-confirm-top">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('preview.addElement') ?? 'Add Element' ?>
            </button>
        </div>
        
        <!-- Tag Selection (for HTML Tag type) -->
        <div class="preview-contextual-form__field" id="add-tag-field" style="display: none;">
            <label><?= __admin('preview.selectTag') ?? 'Tag' ?>:</label>
            <?php $selectorId = 'add'; include '_tag-selector.php'; ?>
        </div>
        
        <!-- Component Selection (for Component type) -->
        <div class="preview-contextual-form__field" id="add-component-field" style="display: none;">
            <label><?= __admin('preview.selectComponent') ?? 'Select Component' ?>:</label>
            <?php $selectorId = 'add'; include '_component-selector.php'; ?>
        </div>
        
        <!-- Component Variables (for Component type) — right after selector for visibility -->
        <div class="preview-contextual-form__section" id="add-component-vars" style="display: none;">
            <div class="preview-contextual-form__component-vars-list" id="add-component-vars-container">
                <!-- Dynamically populated: textKey info rows + param input rows -->
            </div>
            <div class="preview-contextual-form__info" id="add-component-no-vars" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span><?= __admin('preview.componentNoVars') ?? 'This component has no configurable variables' ?></span>
            </div>
        </div>

        <!-- Snippet Selection (for Snippet type) -->
        <div class="preview-contextual-form__field" id="add-snippet-field">
            <label><?= __admin('preview.selectSnippet') ?? 'Select Snippet' ?>:</label>
            <?php $selectorId = 'add'; include '_snippet-selector.php'; ?>
        </div>

        <!-- COLLAPSIBLE: Preview (for HTML Tag type) -->
        <div class="preview-contextual-form__collapsible" id="add-preview-section" style="display: none;">
            <button type="button" class="preview-contextual-form__collapsible-header" data-storage-key="qs-add-preview-expanded">
                <span class="preview-contextual-form__collapsible-title"><?= __admin('preview.tagPreview') ?? 'Tag Preview' ?></span>
                <svg class="preview-contextual-form__collapsible-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="preview-contextual-form__collapsible-body" id="add-preview-body">
                <!-- The tag-selector preview is moved here via JS -->
            </div>
        </div>
        
        <!-- COLLAPSIBLE: Position Selection -->
        <div class="preview-contextual-form__collapsible" id="add-position-section">
            <button type="button" class="preview-contextual-form__collapsible-header" data-storage-key="qs-add-position-expanded">
                <span class="preview-contextual-form__collapsible-title"><?= __admin('preview.position') ?? 'Position' ?></span>
                <svg class="preview-contextual-form__collapsible-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="preview-contextual-form__collapsible-body" id="add-position-body">
                <div class="position-picker" id="add-position-picker">
                    <!-- Before -->
                    <label class="position-picker__option">
                        <input type="radio" name="add-position" value="before">
                        <div class="position-picker__visual">
                            <svg viewBox="0 0 60 50" class="position-picker__diagram">
                                <rect x="5" y="5" width="50" height="16" rx="2" class="position-picker__new"/>
                                <rect x="5" y="29" width="50" height="16" rx="2" class="position-picker__selected"/>
                            </svg>
                            <span class="position-picker__label"><?= __admin('preview.positionBefore') ?? 'Before' ?></span>
                        </div>
                    </label>
                    
                    <!-- After (default) -->
                    <label class="position-picker__option">
                        <input type="radio" name="add-position" value="after" checked>
                        <div class="position-picker__visual">
                            <svg viewBox="0 0 60 50" class="position-picker__diagram">
                                <rect x="5" y="5" width="50" height="16" rx="2" class="position-picker__selected"/>
                                <rect x="5" y="29" width="50" height="16" rx="2" class="position-picker__new"/>
                            </svg>
                            <span class="position-picker__label"><?= __admin('preview.positionAfter') ?? 'After' ?></span>
                        </div>
                    </label>
                    
                    <!-- Inside -->
                    <label class="position-picker__option">
                        <input type="radio" name="add-position" value="inside">
                        <div class="position-picker__visual">
                            <svg viewBox="0 0 60 50" class="position-picker__diagram">
                                <rect x="3" y="3" width="54" height="44" rx="3" class="position-picker__selected"/>
                                <rect x="10" y="10" width="40" height="14" rx="2" class="position-picker__new"/>
                            </svg>
                            <span class="position-picker__label"><?= __admin('preview.positionInside') ?? 'Inside' ?></span>
                        </div>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Mandatory Parameters Section (dynamic based on tag) -->
        <div class="preview-contextual-form__section" id="add-mandatory-params" style="display: none;">
            <label class="preview-contextual-form__section-label">
                <?= __admin('preview.requiredParams') ?? 'Required Parameters' ?>:
            </label>
            <div class="preview-contextual-form__mandatory-fields" id="add-mandatory-params-container">
                <!-- Dynamically populated -->
            </div>
        </div>
        
        <!-- CSS Class Combobox -->
        <div class="preview-contextual-form__field" id="add-class-field" style="display: none;">
            <label><?= __admin('preview.cssClass') ?? 'CSS Class' ?> <small>(<?= __admin('common.optional') ?? 'optional' ?>)</small>:</label>
            <div class="class-combobox" id="add-class-combobox">
                <div class="class-combobox__input-area">
                    <div class="class-combobox__chips" id="add-class-chips"></div>
                    <input type="text" class="class-combobox__input" id="add-class-input"
                           placeholder="<?= __admin('preview.typeClassName') ?? 'Type a class name…' ?>"
                           autocomplete="off" spellcheck="false">
                </div>
                <div class="class-combobox__dropdown" id="add-class-dropdown" style="display: none;">
                    <div class="class-combobox__suggestions" id="add-class-suggestions"></div>
                </div>
            </div>
            <input type="hidden" id="add-class" value="">
        </div>
        
        <!-- COLLAPSIBLE: Advanced / Custom Parameters (for tags only) -->
        <div class="preview-contextual-form__collapsible" id="add-advanced-section" style="display: none;">
            <button type="button" class="preview-contextual-form__collapsible-header" data-storage-key="qs-add-advanced-expanded">
                <span class="preview-contextual-form__collapsible-title"><?= __admin('preview.advanced') ?? 'Advanced' ?></span>
                <svg class="preview-contextual-form__collapsible-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="preview-contextual-form__collapsible-body" id="add-advanced-body">
                <div class="preview-contextual-form__custom-params" id="add-custom-params-container">
                    <div class="preview-contextual-form__param-list" id="add-custom-params-list">
                        <!-- Dynamically added param rows -->
                    </div>
                    <button type="button" class="preview-contextual-form__add-param-btn" id="add-another-param">
                        + <?= __admin('preview.addAnother') ?? 'Add another' ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- TextKey Info (read-only, informational) -->
        <div class="preview-contextual-form__info" id="add-textkey-info" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <span><?= __admin('preview.textKeyWillGenerate') ?? 'Text key will be auto-generated' ?>: <code id="add-generated-textkey-preview">-</code></span>
        </div>
        
        <!-- Alt Key Info for img/area -->
        <div class="preview-contextual-form__info" id="add-altkey-info" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
            </svg>
            <span><?= __admin('preview.altKeyWillGenerate') ?? 'Alt text key will be auto-generated' ?>: <code id="add-generated-altkey-preview">-</code></span>
        </div>
        
        <!-- BOTTOM Action Buttons -->
        <div class="preview-contextual-form__actions preview-contextual-form__actions--bottom">
            <button type="button" class="admin-btn admin-btn--ghost" id="add-cancel"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--success" id="add-confirm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('preview.addElement') ?? 'Add Element' ?>
            </button>
        </div>
    </div>
</div>
