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
        
        <!-- Tag Selection (for HTML Tag type) -->
        <div class="preview-contextual-form__field" id="add-tag-field" style="display: none;">
            <label><?= __admin('preview.selectTag') ?? 'Tag' ?>:</label>
            <?php $selectorId = 'add'; include '_tag-selector.php'; ?>
            <small class="preview-contextual-form__hint">* <?= __admin('preview.requiresParams') ?? 'Requires additional parameters' ?></small>
        </div>
        
        <!-- Component Selection (for Component type) -->
        <div class="preview-contextual-form__field" id="add-component-field" style="display: none;">
            <label for="add-component"><?= __admin('preview.selectComponent') ?? 'Select Component' ?>:</label>
            <select id="add-component" class="admin-input admin-input--sm">
                <option value=""><?= __admin('preview.selectComponentPlaceholder') ?? '-- Select a component --' ?></option>
            </select>
        </div>
        
        <!-- Snippet Selection (for Snippet type) -->
        <div class="preview-contextual-form__field" id="add-snippet-field">
            <label><?= __admin('preview.selectSnippet') ?? 'Select Snippet' ?>:</label>
            
            <!-- Snippet Category Tabs -->
            <div class="snippet-selector__categories" id="add-snippet-categories">
                <!-- Populated dynamically via JS -->
            </div>
            
            <!-- Snippet Cards Grid -->
            <div class="snippet-selector__cards" id="add-snippet-cards">
                <div class="snippet-selector__loading">
                    <div class="spinner"></div>
                    <?= __admin('common.loading') ?? 'Loading...' ?>
                </div>
            </div>
            
            <!-- Snippet Preview Panel -->
            <div class="snippet-selector__preview" id="add-snippet-preview" style="display: none;">
                <div class="snippet-selector__preview-header">
                    <span class="snippet-selector__preview-title" id="add-snippet-preview-title"></span>
                    <span class="snippet-selector__preview-source" id="add-snippet-preview-source"></span>
                </div>
                <p class="snippet-selector__preview-desc" id="add-snippet-preview-desc"></p>
                <div class="snippet-selector__preview-frame-container">
                    <iframe class="snippet-selector__preview-frame" id="add-snippet-preview-frame" sandbox="allow-same-origin"></iframe>
                </div>
                <!-- Delete button for project snippets only -->
                <div class="snippet-selector__preview-actions" id="add-snippet-preview-actions" style="display: none;">
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" id="delete-snippet-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            <line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>
                        </svg>
                        <?= __admin('preview.deleteSnippet') ?? 'Delete Snippet' ?>
                    </button>
                </div>
            </div>
            
            <input type="hidden" id="add-snippet" value="">
        </div>
        
        <!-- Position Selection - Visual Picker -->
        <div class="preview-contextual-form__field">
            <label><?= __admin('preview.position') ?? 'Position' ?>:</label>
            <div class="position-picker" id="add-position-picker">
                <!-- Before -->
                <label class="position-picker__option">
                    <input type="radio" name="add-position" value="before">
                    <div class="position-picker__visual">
                        <svg viewBox="0 0 60 50" class="position-picker__diagram">
                            <!-- New element (solid) -->
                            <rect x="5" y="5" width="50" height="16" rx="2" class="position-picker__new"/>
                            <!-- Selected element (dashed) -->
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
                            <!-- Selected element (dashed) -->
                            <rect x="5" y="5" width="50" height="16" rx="2" class="position-picker__selected"/>
                            <!-- New element (solid) -->
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
                            <!-- Parent/Selected element (dashed, larger) -->
                            <rect x="3" y="3" width="54" height="44" rx="3" class="position-picker__selected"/>
                            <!-- New element inside (solid, smaller) -->
                            <rect x="10" y="10" width="40" height="14" rx="2" class="position-picker__new"/>
                        </svg>
                        <span class="position-picker__label"><?= __admin('preview.positionInside') ?? 'Inside' ?></span>
                    </div>
                </label>
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
        
        <!-- CSS Class Input -->
        <div class="preview-contextual-form__field" id="add-class-field" style="display: none;">
            <label for="add-class"><?= __admin('preview.cssClass') ?? 'CSS Class' ?> <small>(<?= __admin('common.optional') ?? 'optional' ?>)</small>:</label>
            <input type="text" id="add-class" class="admin-input admin-input--sm" placeholder="my-class another-class">
        </div>
        

        
        <!-- Custom Parameters Section (expandable) - only for tags -->
        <div class="preview-contextual-form__section" id="add-custom-params-section" style="display: none;">
            <button type="button" class="preview-contextual-form__expand-btn" id="add-expand-params">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('preview.addCustomParam') ?? 'Add custom parameter' ?>
            </button>
            <div class="preview-contextual-form__custom-params" id="add-custom-params-container" style="display: none;">
                <div class="preview-contextual-form__param-list" id="add-custom-params-list">
                    <!-- Dynamically added param rows -->
                </div>
                <button type="button" class="preview-contextual-form__add-param-btn" id="add-another-param">
                    + <?= __admin('preview.addAnother') ?? 'Add another' ?>
                </button>
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
        
        <!-- Component Variables (for Component type) -->
        <div class="preview-contextual-form__section" id="add-component-vars" style="display: none;">
            <label class="preview-contextual-form__section-label">
                <?= __admin('preview.componentVariables') ?? 'Component Variables' ?>:
            </label>
            <div class="preview-contextual-form__component-vars-list" id="add-component-vars-container">
                <!-- Dynamically populated: textKey vars (read-only), param vars (input) -->
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
        
        <!-- Action Buttons -->
        <div class="preview-contextual-form__actions">
            <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="add-cancel"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--sm admin-btn--success" id="add-confirm"><?= __admin('preview.addElement') ?? 'Add Element' ?></button>
        </div>
    </div>
</div>
