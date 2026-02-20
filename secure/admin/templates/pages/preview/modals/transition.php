<!-- Transition Editor Modal -->
<div class="preview-keyframe-modal transition-editor" id="transition-editor-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content transition-editor__content">
        <div class="preview-keyframe-modal__header">
            <h3><?= __admin('preview.stateAnimationEditor') ?? 'State & Animation Editor' ?></h3>
            <span class="transition-editor__selector" id="transition-editor-selector">.selector</span>
            <button type="button" class="preview-keyframe-modal__close" id="transition-editor-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <div class="preview-keyframe-modal__body transition-editor__body">
            <!-- Split View: Base State vs Trigger State -->
            <div class="transition-editor__states">
                <!-- Base State Panel -->
                <div class="transition-editor__state-panel">
                    <div class="transition-editor__state-header">
                        <span class="transition-editor__state-label"><?= __admin('preview.baseState') ?? 'Base State' ?></span>
                        <code class="transition-editor__state-selector" id="transition-base-selector">.selector</code>
                    </div>
                    <div class="transition-editor__state-properties" id="transition-base-properties">
                        <!-- Base state properties will be rendered here -->
                        <div class="transition-editor__empty"><?= __admin('preview.selectSelectorFirst') ?? 'Select a selector first' ?></div>
                    </div>
                    <!-- Inline Add Property Form for Base State -->
                    <div class="transition-editor__add-property" id="transition-base-add-property">
                        <button type="button" class="transition-editor__add-property-toggle" id="transition-base-add-toggle">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <?= __admin('preview.addProperty') ?? 'Add Property' ?>
                        </button>
                        <div class="transition-editor__add-property-form" id="transition-base-add-form" style="display: none;">
                            <div class="transition-editor__property-selector" id="transition-base-prop-selector"></div>
                            <div class="transition-editor__add-property-row">
                                <div class="transition-editor__value-container" id="transition-base-value-container"></div>
                                <button type="button" class="transition-editor__add-property-confirm" id="transition-base-add-confirm">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </button>
                                <button type="button" class="transition-editor__add-property-cancel" id="transition-base-add-cancel">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Arrow -->
                <div class="transition-editor__arrow">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                </div>
                
                <!-- Trigger State Panel -->
                <div class="transition-editor__state-panel">
                    <div class="transition-editor__state-header">
                        <span class="transition-editor__state-label" id="transition-trigger-label"><?= __admin('preview.triggerState') ?? 'Trigger State' ?></span>
                        <select class="transition-editor__pseudo-select" id="transition-pseudo-select">
                            <option value=":hover">:hover</option>
                            <option value=":focus">:focus</option>
                            <option value=":active">:active</option>
                            <option value=":focus-visible">:focus-visible</option>
                            <option value=":focus-within">:focus-within</option>
                        </select>
                    </div>
                    <div class="transition-editor__state-properties" id="transition-hover-properties">
                        <!-- Trigger state properties will be rendered here -->
                        <div class="transition-editor__empty"><?= __admin('preview.noTriggerStyles') ?? 'No trigger styles defined' ?></div>
                    </div>
                    <!-- Inline Add Property Form for Trigger State -->
                    <div class="transition-editor__add-property" id="transition-trigger-add-property">
                        <button type="button" class="transition-editor__add-property-toggle" id="transition-trigger-add-toggle">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span id="transition-trigger-add-text"><?= __admin('preview.addProperty') ?? 'Add Property' ?></span>
                        </button>
                        <div class="transition-editor__add-property-form" id="transition-trigger-add-form" style="display: none;">
                            <div class="transition-editor__property-selector" id="transition-trigger-prop-selector"></div>
                            <div class="transition-editor__add-property-row">
                                <div class="transition-editor__value-container" id="transition-trigger-value-container"></div>
                                <button type="button" class="transition-editor__add-property-confirm" id="transition-trigger-add-confirm">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </button>
                                <button type="button" class="transition-editor__add-property-cancel" id="transition-trigger-add-cancel">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Transition Settings -->
            <div class="transition-editor__settings">
                <div class="transition-editor__settings-header">
                    <h4 class="transition-editor__settings-title"><?= __admin('preview.transitionSettings') ?? 'Transition Settings' ?></h4>
                    <span class="transition-editor__settings-hint" title="<?= __admin('preview.transitionHint') ?? 'Transitions animate property changes between states. Defined on base, applies to all state changes.' ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                        </svg>
                    </span>
                </div>
                <p class="transition-editor__settings-description"><?= __admin('preview.transitionDescription') ?? 'Smoothly animates property changes when triggered (hover, focus, etc.)' ?></p>
                
                <div class="transition-editor__settings-grid">
                    <!-- Properties to Transition -->
                    <div class="transition-editor__setting">
                        <label><?= __admin('preview.transitionProperty') ?? 'Properties' ?>:</label>
                        <select class="transition-editor__property-select" id="transition-property-select">
                            <option value="all"><?= __admin('preview.allProperties') ?? 'All Properties' ?></option>
                            <option value="specific"><?= __admin('preview.specificProperties') ?? 'Specific Properties...' ?></option>
                        </select>
                        <div class="transition-editor__specific-props" id="transition-specific-props" style="display: none;">
                            <!-- Checkboxes for specific properties -->
                        </div>
                    </div>
                    
                    <!-- Duration -->
                    <div class="transition-editor__setting">
                        <label><?= __admin('preview.transitionDuration') ?? 'Duration' ?>:</label>
                        <div class="transition-editor__input-group">
                            <input type="number" id="transition-duration" class="admin-input" value="0.3" min="0" max="60" step="0.1">
                            <select class="transition-editor__unit-select" id="transition-duration-unit">
                                <option value="s">s</option>
                                <option value="ms">ms</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Delay -->
                    <div class="transition-editor__setting">
                        <label><?= __admin('preview.transitionDelay') ?? 'Delay' ?>:</label>
                        <div class="transition-editor__input-group">
                            <input type="number" id="transition-delay" class="admin-input" value="0" min="0" max="60" step="0.1">
                            <select class="transition-editor__unit-select" id="transition-delay-unit">
                                <option value="s">s</option>
                                <option value="ms">ms</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Timing Function -->
                    <div class="transition-editor__setting transition-editor__setting--wide">
                        <label><?= __admin('preview.timingFunction') ?? 'Timing Function' ?>:</label>
                        <div class="transition-editor__timing-row">
                            <select class="transition-editor__timing-select" id="transition-timing-select">
                                <option value="ease">ease</option>
                                <option value="linear">linear</option>
                                <option value="ease-in">ease-in</option>
                                <option value="ease-out">ease-out</option>
                                <option value="ease-in-out">ease-in-out</option>
                                <option value="cubic-bezier"><?= __admin('preview.customCubicBezier') ?? 'Custom cubic-bezier...' ?></option>
                            </select>
                            <div class="transition-editor__cubic-bezier" id="transition-cubic-bezier" style="display: none;">
                                <span>cubic-bezier(</span>
                                <input type="number" id="transition-bezier-x1" class="admin-input admin-input--mini" value="0.4" min="0" max="1" step="0.1">
                                <span>,</span>
                                <input type="number" id="transition-bezier-y1" class="admin-input admin-input--mini" value="0" step="0.1">
                                <span>,</span>
                                <input type="number" id="transition-bezier-x2" class="admin-input admin-input--mini" value="0.2" min="0" max="1" step="0.1">
                                <span>,</span>
                                <input type="number" id="transition-bezier-y2" class="admin-input admin-input--mini" value="1" step="0.1">
                                <span>)</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Transition Preview String -->
                <div class="transition-editor__preview-value">
                    <label><?= __admin('preview.transitionValue') ?? 'Transition Value' ?>:</label>
                    <code id="transition-preview-code">all 0.3s ease</code>
                </div>
            </div>
            
            <!-- Animation Sections (Phase 10.3) -->
            <div class="transition-editor__animations">
                <div class="transition-editor__animations-info">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span><?= __admin('preview.animationsIndependent') ?? 'Animations are independent from transitions. You can use one, both, or neither.' ?></span>
                </div>
                
                <div class="transition-editor__animations-row">
                <!-- Base Animation (plays on load) -->
                <div class="transition-editor__animation-section">
                    <div class="transition-editor__animation-header">
                        <span class="transition-editor__animation-label">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            <?= __admin('preview.baseAnimation') ?? 'Base Animation' ?>
                        </span>
                        <span class="transition-editor__animation-hint"><?= __admin('preview.playsOnLoad') ?? '(plays on load)' ?></span>
                    </div>
                    <div class="transition-editor__animation-content" id="transition-base-animation">
                        <div class="transition-editor__animation-empty" id="transition-base-animation-empty">
                            <?= __admin('preview.noAnimationSet') ?? 'No animation set' ?>
                        </div>
                        <div class="transition-editor__animation-config" id="transition-base-animation-config" style="display: none;">
                            <div class="transition-editor__animation-name">
                                <span id="transition-base-animation-name">@keyframes name</span>
                                <button type="button" class="transition-editor__animation-preview" id="transition-base-animation-preview-btn" title="<?= __admin('preview.previewAnimation') ?? 'Preview' ?>">
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                        <polygon points="5 3 19 12 5 21 5 3"/>
                                    </svg>
                                </button>
                                <button type="button" class="transition-editor__animation-remove" id="transition-base-animation-remove" title="<?= __admin('common.remove') ?? 'Remove' ?>">
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="transition-editor__animation-settings">
                                <div class="transition-editor__anim-setting">
                                    <label><?= __admin('preview.animDuration') ?? 'Duration' ?>:</label>
                                    <input type="number" id="transition-base-anim-duration" class="admin-input admin-input--mini" value="1000" min="0" step="100">
                                    <span>ms</span>
                                </div>
                                <div class="transition-editor__anim-setting">
                                    <label><?= __admin('preview.iterations') ?? 'Iterations' ?>:</label>
                                    <input type="number" id="transition-base-anim-iterations" class="admin-input admin-input--mini" value="1" min="1" max="100">
                                    <label class="transition-editor__anim-checkbox">
                                        <input type="checkbox" id="transition-base-anim-infinite">
                                        <?= __admin('preview.infinite') ?? '∞' ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="transition-editor__animation-add" id="transition-base-animation-add">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <?= __admin('preview.addAnimation') ?? 'Add Animation' ?>
                    </button>
                </div>
                
                <!-- Trigger Animation (plays on hover/focus/etc) -->
                <div class="transition-editor__animation-section">
                    <div class="transition-editor__animation-header">
                        <span class="transition-editor__animation-label">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5.52 19c.64-2.2 1.84-3 3.22-3h6.52c1.38 0 2.58.8 3.22 3"/>
                                <circle cx="12" cy="10" r="3"/>
                                <circle cx="12" cy="12" r="10"/>
                            </svg>
                            <span id="transition-trigger-animation-label"><?= __admin('preview.triggerAnimation') ?? 'Trigger Animation' ?></span>
                        </span>
                        <span class="transition-editor__animation-hint" id="transition-trigger-animation-hint"><?= __admin('preview.playsOnTrigger') ?? '(plays on :hover)' ?></span>
                    </div>
                    <div class="transition-editor__animation-content" id="transition-trigger-animation">
                        <div class="transition-editor__animation-empty" id="transition-trigger-animation-empty">
                            <?= __admin('preview.noAnimationSet') ?? 'No animation set' ?>
                        </div>
                        <div class="transition-editor__animation-config" id="transition-trigger-animation-config" style="display: none;">
                            <div class="transition-editor__animation-name">
                                <span id="transition-trigger-animation-name">@keyframes name</span>
                                <button type="button" class="transition-editor__animation-preview" id="transition-trigger-animation-preview-btn" title="<?= __admin('preview.previewAnimation') ?? 'Preview' ?>">
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                        <polygon points="5 3 19 12 5 21 5 3"/>
                                    </svg>
                                </button>
                                <button type="button" class="transition-editor__animation-remove" id="transition-trigger-animation-remove" title="<?= __admin('common.remove') ?? 'Remove' ?>">
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="transition-editor__animation-settings">
                                <div class="transition-editor__anim-setting">
                                    <label><?= __admin('preview.animDuration') ?? 'Duration' ?>:</label>
                                    <input type="number" id="transition-trigger-anim-duration" class="admin-input admin-input--mini" value="1000" min="0" step="100">
                                    <span>ms</span>
                                </div>
                                <div class="transition-editor__anim-setting">
                                    <label><?= __admin('preview.iterations') ?? 'Iterations' ?>:</label>
                                    <input type="number" id="transition-trigger-anim-iterations" class="admin-input admin-input--mini" value="1" min="1" max="100">
                                    <label class="transition-editor__anim-checkbox">
                                        <input type="checkbox" id="transition-trigger-anim-infinite">
                                        <?= __admin('preview.infinite') ?? '∞' ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="transition-editor__animation-add" id="transition-trigger-animation-add">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <?= __admin('preview.addAnimation') ?? 'Add Animation' ?>
                    </button>
                </div>
                </div><!-- end animations-row -->
            </div>
        </div>
        
        <div class="preview-keyframe-modal__footer">
            <div class="preview-keyframe-modal__footer-left">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="transition-preview-btn">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <?= __admin('preview.previewHover') ?? 'Preview Hover' ?>
                </button>
            </div>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="transition-cancel"><?= __admin('common.cancel') ?></button>
                <button type="button" class="admin-btn admin-btn--primary" id="transition-save"><?= __admin('common.save') ?></button>
            </div>
        </div>
    </div>
</div>
