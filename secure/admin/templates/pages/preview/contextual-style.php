<!-- STYLE MODE Content -->
<div class="preview-contextual-section preview-contextual-section--style" id="contextual-style" data-mode="style" style="display: none;">
    <div class="preview-contextual-default" id="contextual-style-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
        </svg>
        <span><?= adminEscape(__admin('preview.styleModeHint')) ?></span>
    </div>
    <!-- Advanced top row: Source (raw stylesheet editor). Role-gated on
         editStyles and left out of the simple view (editorTools.php, style.source):
         admin.js filterByPermissions adds .admin-hidden-permission when the user
         lacks the command. Hidden until Style mode activates (preview.js toggles
         display alongside the tabs row). -->
    <div class="preview-contextual-style-advanced" id="contextual-style-advanced"<?= qs_editor_control_attrs('style.source') ?> style="display: none;">
        <button type="button" class="preview-contextual-style-source-btn" id="contextual-style-source-btn" data-tab="source" title="<?= adminAttr(__admin('preview.styleSourceHint')) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                <polyline points="16 18 22 12 16 6"/>
                <polyline points="8 6 2 12 8 18"/>
            </svg>
            <span><?= adminEscape(__admin('preview.styleSource')) ?></span>
        </button>
    </div>
    <div class="preview-contextual-style-tabs" id="contextual-style-tabs" style="display: none;">
        <button type="button" class="preview-contextual-style-tab preview-contextual-style-tab--active" data-tab="theme">
            <?= adminEscape(__admin('preview.themeVariables')) ?>
        </button>
        <button type="button" class="preview-contextual-style-tab" data-tab="selectors">
            <?= adminEscape(__admin('preview.selectors')) ?>
        </button>
        <button type="button" class="preview-contextual-style-tab" data-tab="animations"<?= qs_editor_control_attrs('style.animations') ?>>
            <?= adminEscape(__admin('preview.animations')) ?>
        </button>
    </div>
    <div id="contextual-style-content" style="display: none;">
        <!-- Theme Variables Panel -->
        <div class="preview-theme-panel" id="theme-panel" data-tab="theme">
            <div class="preview-theme-loading" id="theme-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= adminEscape(__admin('common.loading')) ?></span>
            </div>
            
            <div class="preview-theme-content" id="theme-content" style="display: none;">
                <!-- Theme Mode Config (collapsible) -->
                <div class="preview-theme-section preview-theme-section--config" id="theme-mode-config">
                    <button type="button" class="preview-theme-config-toggle" id="theme-config-toggle" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        <?= adminEscape(__admin('preview.themeModeConfig')) ?>
                        <svg class="preview-theme-config-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div class="preview-theme-config-body" id="theme-config-body" style="display: none;">
                        <div class="preview-theme-config-row">
                            <span class="preview-theme-config-label"><?= adminEscape(__admin('preview.themeModeEnabled')) ?></span>
                            <label>
                                <input type="checkbox" id="theme-config-enabled" <?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? 'checked' : '' ?>>
                                <span class="preview-toggle__track"></span>
                            </label>
                        </div>
                        <div class="preview-theme-config-row">
                            <span class="preview-theme-config-label"><?= adminEscape(__admin('preview.themeDefault')) ?></span>
                            <select id="theme-config-default" class="preview-toolbar__select preview-toolbar__select--sm">
                                <option value="light" <?= ($editConfig['THEME_DEFAULT'] ?? 'light') === 'light' ? 'selected' : '' ?>>☀ <?= adminEscape(__admin('preview.light')) ?></option>
                                <option value="dark"  <?= ($editConfig['THEME_DEFAULT'] ?? 'light') === 'dark'  ? 'selected' : '' ?>>🌙 <?= adminEscape(__admin('preview.dark')) ?></option>
                                <option value="system"<?= ($editConfig['THEME_DEFAULT'] ?? 'light') === 'system'? 'selected' : '' ?>>⚙ <?= adminEscape(__admin('preview.system')) ?></option>
                            </select>
                        </div>
                        <div class="preview-theme-config-row">
                            <span class="preview-theme-config-label"><?= adminEscape(__admin('preview.themeUserToggle')) ?></span>
                            <label>
                                <input type="checkbox" id="theme-config-usertoggle" <?= ($editConfig['THEME_USER_TOGGLE_ENABLED'] ?? false) ? 'checked' : '' ?>>
                                <span class="preview-toggle__track"></span>
                            </label>
                        </div>
                        <button type="button" class="admin-btn admin-btn--primary" id="theme-config-save-btn" style="margin-top: 8px; width: 100%;">
                            <?= adminEscape(__admin('preview.saveThemeConfig')) ?>
                        </button>
                    </div>
                </div>

                <!-- Light / Dark scope switcher (always rendered; visibility managed by JS/config) -->
                <div class="preview-theme-scope" id="theme-scope-switcher" style="display: <?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : 'none' ?>;">
                    <button type="button" class="preview-theme-scope__btn preview-theme-scope__btn--active" data-scope="light">
                        <span aria-hidden="true">☀</span> <?= adminEscape(__admin('preview.light')) ?>
                    </button>
                    <button type="button" class="preview-theme-scope__btn" data-scope="dark">
                        <span aria-hidden="true">🌙</span> <?= adminEscape(__admin('preview.dark')) ?>
                    </button>
                </div>
                <div class="preview-theme-section" id="theme-colors-section">
                    <h4 class="preview-theme-section__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                        </svg>
                        <?= adminEscape(__admin('preview.themeColors')) ?>
                    </h4>
                    <div class="preview-theme-grid" id="theme-colors-grid">
                        <!-- Color inputs populated by JS -->
                    </div>
                    <!-- Quick-add variable -->
                    <div class="preview-theme-add" data-section="colors">
                        <button type="button" class="preview-theme-add__toggle" data-action="toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span><?= adminEscape(__admin('preview.themeAddVariable')) ?></span>
                        </button>
                        <div class="preview-theme-add__form" data-form hidden>
                            <div class="preview-theme-add__scope-banner" data-scope-banner></div>
                            <label class="preview-theme-add__also-other" data-also-other-row<?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : ' hidden' ?>>
                                <input type="checkbox" data-also-other>
                                <span><?= adminEscape(__admin('preview.themeAddVariableAlsoOtherScope')) ?></span>
                            </label>
                            <div class="preview-theme-add__row">
                                <input type="text"
                                       class="preview-theme-add__input"
                                       data-input-name
                                       placeholder="--color-name"
                                       aria-label="<?= adminAttr(__admin('preview.themeAddVariableNameLabel')) ?>">
                                <input type="text"
                                       class="preview-theme-add__input"
                                       data-input-value
                                       placeholder="<?= adminAttr(__admin('preview.themeAddVariableValueExampleColor')) ?>"
                                       aria-label="<?= adminAttr(__admin('preview.themeAddVariableValueLabel')) ?>">
                            </div>
                            <div class="preview-theme-add__actions">
                                <button type="button" class="admin-btn admin-btn--ghost" data-action="cancel">
                                    <?= adminEscape(__admin('common.cancel')) ?>
                                </button>
                                <button type="button" class="admin-btn admin-btn--primary" data-action="submit">
                                    <?= adminEscape(__admin('preview.themeAddVariableSubmit')) ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fonts Section -->
                <div class="preview-theme-section" id="theme-fonts-section">
                    <h4 class="preview-theme-section__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <polyline points="4,7 4,4 20,4 20,7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>
                        </svg>
                        <?= adminEscape(__admin('preview.themeFonts')) ?>
                    </h4>
                    <div class="preview-theme-grid" id="theme-fonts-grid">
                        <!-- Font inputs populated by JS -->
                    </div>
                    <!-- Quick-add variable -->
                    <div class="preview-theme-add" data-section="fonts">
                        <button type="button" class="preview-theme-add__toggle" data-action="toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span><?= adminEscape(__admin('preview.themeAddVariable')) ?></span>
                        </button>
                        <div class="preview-theme-add__form" data-form hidden>
                            <div class="preview-theme-add__scope-banner" data-scope-banner></div>
                            <label class="preview-theme-add__also-other" data-also-other-row<?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : ' hidden' ?>>
                                <input type="checkbox" data-also-other>
                                <span><?= adminEscape(__admin('preview.themeAddVariableAlsoOtherScope')) ?></span>
                            </label>
                            <div class="preview-theme-add__row">
                                <input type="text"
                                       class="preview-theme-add__input"
                                       data-input-name
                                       placeholder="--font-name"
                                       aria-label="<?= adminAttr(__admin('preview.themeAddVariableNameLabel')) ?>">
                                <input type="text"
                                       class="preview-theme-add__input"
                                       data-input-value
                                       placeholder="<?= adminAttr(__admin('preview.themeAddVariableValueExampleFont')) ?>"
                                       aria-label="<?= adminAttr(__admin('preview.themeAddVariableValueLabel')) ?>">
                            </div>
                            <div class="preview-theme-add__actions">
                                <button type="button" class="admin-btn admin-btn--ghost" data-action="cancel">
                                    <?= adminEscape(__admin('common.cancel')) ?>
                                </button>
                                <button type="button" class="admin-btn admin-btn--primary" data-action="submit">
                                    <?= adminEscape(__admin('preview.themeAddVariableSubmit')) ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Spacing Section -->
                <div class="preview-theme-section" id="theme-spacing-section">
                    <h4 class="preview-theme-section__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>
                        </svg>
                        <?= adminEscape(__admin('preview.themeSpacing')) ?>
                    </h4>
                    <div class="preview-theme-grid" id="theme-spacing-grid">
                        <!-- Spacing inputs populated by JS -->
                    </div>
                    <!-- Quick-add variable -->
                    <div class="preview-theme-add" data-section="spacing">
                        <button type="button" class="preview-theme-add__toggle" data-action="toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span><?= adminEscape(__admin('preview.themeAddVariable')) ?></span>
                        </button>
                        <div class="preview-theme-add__form" data-form hidden>
                            <div class="preview-theme-add__scope-banner" data-scope-banner></div>
                            <label class="preview-theme-add__also-other" data-also-other-row<?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : ' hidden' ?>>
                                <input type="checkbox" data-also-other>
                                <span><?= adminEscape(__admin('preview.themeAddVariableAlsoOtherScope')) ?></span>
                            </label>
                            <div class="preview-theme-add__row">
                                <input type="text"
                                       class="preview-theme-add__input"
                                       data-input-name
                                       placeholder="--spacing-name"
                                       aria-label="<?= adminAttr(__admin('preview.themeAddVariableNameLabel')) ?>">
                                <input type="text"
                                       class="preview-theme-add__input"
                                       data-input-value
                                       placeholder="<?= adminAttr(__admin('preview.themeAddVariableValueExampleSpacing')) ?>"
                                       aria-label="<?= adminAttr(__admin('preview.themeAddVariableValueLabel')) ?>">
                            </div>
                            <div class="preview-theme-add__actions">
                                <button type="button" class="admin-btn admin-btn--ghost" data-action="cancel">
                                    <?= adminEscape(__admin('common.cancel')) ?>
                                </button>
                                <button type="button" class="admin-btn admin-btn--primary" data-action="submit">
                                    <?= adminEscape(__admin('preview.themeAddVariableSubmit')) ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Other Variables Section -->
                <div class="preview-theme-section" id="theme-other-section" style="display: none;">
                    <h4 class="preview-theme-section__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        <?= adminEscape(__admin('preview.themeOther')) ?>
                    </h4>
                    <div class="preview-theme-grid" id="theme-other-grid">
                        <!-- Other variables populated by JS -->
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="preview-theme-actions">
                    <button type="button" class="admin-btn admin-btn--ghost" id="theme-reset-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="1,4 1,10 7,10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                        </svg>
                        <?= adminEscape(__admin('common.reset')) ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--primary" id="theme-save-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <?= adminEscape(__admin('preview.saveTheme')) ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Selectors Panel -->
        <div class="preview-selectors-panel" id="selectors-panel" data-tab="selectors" style="display: none;">
            <!-- Selector Search -->
            <div class="preview-selectors-search">
                <div class="preview-selectors-search__wrapper">
                    <svg class="preview-selectors-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input type="text" 
                           id="selector-search-input" 
                           class="preview-selectors-search__input" 
                           placeholder="<?= adminAttr(__admin('preview.searchSelectors')) ?>"
                           autocomplete="off">
                    <button type="button" class="preview-selectors-search__clear" id="selector-search-clear" data-qs-icon="row" aria-label="<?= adminAttr(__admin('common.clear')) ?>" title="<?= adminAttr(__admin('common.clear')) ?>" style="display: none;"></button>
                </div>
            </div>
            
            <!-- Selector Loading -->
            <div class="preview-selectors-loading" id="selectors-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= adminEscape(__admin('common.loading')) ?></span>
            </div>
            
            <!-- Selector Groups (populated by JS) -->
            <div class="preview-selectors-groups" id="selectors-groups" style="display: none;">
                <!-- Tags Group -->
                <div class="preview-selectors-group" data-group="tags">
                    <button type="button" class="preview-selectors-group__header preview-selectors-group__header--expanded">
                        <svg class="preview-selectors-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        <span class="preview-selectors-group__title"><?= adminEscape(__admin('preview.selectorsTags')) ?></span>
                        <span class="preview-selectors-group__count" id="selectors-tags-count">0</span>
                    </button>
                    <div class="preview-selectors-group__list" id="selectors-tags-list"></div>
                </div>
                
                <!-- Classes Group -->
                <div class="preview-selectors-group" data-group="classes">
                    <button type="button" class="preview-selectors-group__header preview-selectors-group__header--expanded">
                        <svg class="preview-selectors-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        <span class="preview-selectors-group__title"><?= adminEscape(__admin('preview.selectorsClasses')) ?></span>
                        <span class="preview-selectors-group__count" id="selectors-classes-count">0</span>
                    </button>
                    <div class="preview-selectors-group__list" id="selectors-classes-list"></div>
                </div>
                
                <!-- IDs Group -->
                <div class="preview-selectors-group" data-group="ids">
                    <button type="button" class="preview-selectors-group__header preview-selectors-group__header--expanded">
                        <svg class="preview-selectors-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        <span class="preview-selectors-group__title"><?= adminEscape(__admin('preview.selectorsIds')) ?></span>
                        <span class="preview-selectors-group__count" id="selectors-ids-count">0</span>
                    </button>
                    <div class="preview-selectors-group__list" id="selectors-ids-list"></div>
                </div>
                
                <!-- Attribute Selectors Group -->
                <div class="preview-selectors-group" data-group="attributes">
                    <button type="button" class="preview-selectors-group__header preview-selectors-group__header--expanded">
                        <svg class="preview-selectors-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        <span class="preview-selectors-group__title"><?= adminEscape(__admin('preview.selectorsAttributes')) ?></span>
                        <span class="preview-selectors-group__count" id="selectors-attributes-count">0</span>
                    </button>
                    <div class="preview-selectors-group__list" id="selectors-attributes-list"></div>
                </div>
                
                <!-- Media Query Selectors Group -->
                <div class="preview-selectors-group" data-group="media">
                    <button type="button" class="preview-selectors-group__header">
                        <svg class="preview-selectors-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        <span class="preview-selectors-group__title"><?= adminEscape(__admin('preview.selectorsMedia')) ?></span>
                        <span class="preview-selectors-group__count" id="selectors-media-count">0</span>
                    </button>
                    <div class="preview-selectors-group__list" id="selectors-media-list" style="display: none;"></div>
                </div>
            </div>
            
            <!-- Selected Selector Info (shows when a selector is selected) -->
            <div class="preview-selector-selected" id="selector-selected" style="display: none;">
                <div class="preview-selector-selected__header">
                    <span class="preview-selector-selected__label"><?= adminEscape(__admin('preview.selectedSelector')) ?>:</span>
                    <code class="preview-selector-selected__value" id="selector-selected-value"></code>
                    <span class="preview-rule-media" id="selector-selected-media" hidden></span>
                    <button type="button" class="preview-selector-selected__clear" id="selector-selected-clear" data-qs-icon="row" aria-label="<?= adminAttr(__admin('common.clear')) ?>" title="<?= adminAttr(__admin('common.clear')) ?>"></button>
                </div>
                <div class="preview-selector-selected__info">
                    <span class="preview-selector-selected__matches" id="selector-matches"></span>
                </div>
                <div class="preview-selector-selected__actions">
                    <button type="button" class="admin-btn admin-btn--ghost admin-action admin-action--edit" id="selector-edit-btn" data-qs-icon="edit"
                            aria-label="<?= adminAttr(__admin('preview.editStyles')) ?>" title="<?= adminAttr(__admin('preview.editStyles')) ?>"></button>
                    <button type="button" class="admin-btn admin-btn--outline" id="selector-copy-from-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <?= adminEscape(__admin('preview.copyStyleFrom')) ?>
                    </button>
                    <button type="button" class="admin-btn" id="selector-animate-btn"<?= qs_editor_control_attrs('style.animate') ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <?= adminEscape(__admin('preview.animate')) ?>
                    </button>
                </div>
                <!-- Copy From selector picker (hidden by default) -->
                <div class="preview-selector-copy-picker" id="selector-copy-picker" style="display: none;">
                    <div class="preview-selector-copy-picker__header">
                        <span><?= adminEscape(__admin('preview.copyStyleFromLabel')) ?></span>
                        <button type="button" class="admin-modal__close" id="selector-copy-picker-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
                    </div>
                    <input type="text" class="admin-input admin-input--sm preview-selector-copy-picker__search" id="selector-copy-search" placeholder="<?= adminAttr(__admin('preview.searchSelectors')) ?>">
                    <div class="preview-selector-copy-picker__list" id="selector-copy-list"></div>
                </div>
            </div>
        </div>
        
        <!-- Animations Panel -->
        <div class="preview-animations-panel" id="animations-panel" data-tab="animations" style="display: none;">
            <!-- Animations Loading -->
            <div class="preview-animations-loading" id="animations-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= adminEscape(__admin('common.loading')) ?></span>
            </div>
            
            <!-- Motion Content. Its DOM ids keep the JS module's own names
                 ("animations", "triggers", "keyframes"); only the words say Motion.
                 Selectors with motion come first (the primary editing surface); the
                 Keyframes library is the secondary "toolbox" view below it. -->
            <div class="preview-animations-content" id="animations-content" style="display: none;">

                <!-- Selectors with motion (primary, expanded by default) -->
                <div class="preview-animations-section" id="animated-selectors-section">
                    <div class="preview-animations-section__header">
                        <h4 class="preview-animations-section__title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <?= adminEscape(__admin('preview.animatedSelectors')) ?>
                        </h4>
                    </div>

                    <!-- Transitions Group -->
                    <div class="preview-animations-group" data-group="transitions">
                        <button type="button" class="preview-animations-group__header preview-animations-group__header--expanded">
                            <svg class="preview-animations-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                            <span class="preview-animations-group__title"><?= adminEscape(__admin('preview.transitions')) ?></span>
                            <span class="preview-animations-group__count" id="transitions-count">0</span>
                        </button>
                        <div class="preview-animations-group__list" id="transitions-list">
                            <!-- Transition selectors will be populated by JS -->
                        </div>
                    </div>

                    <!-- Animations Group -->
                    <div class="preview-animations-group" data-group="animations">
                        <button type="button" class="preview-animations-group__header preview-animations-group__header--expanded">
                            <svg class="preview-animations-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                            <span class="preview-animations-group__title"><?= adminEscape(__admin('preview.animationsProperty')) ?></span>
                            <span class="preview-animations-group__count" id="animations-count">0</span>
                        </button>
                        <div class="preview-animations-group__list" id="animations-list">
                            <!-- Animation selectors will be populated by JS -->
                        </div>
                    </div>

                    <!-- Hover-state-only Group (pseudo-state property changes
                         without a transition declared). data-group="triggers"
                         + DOM ids kept for backward compat with the JS module. -->
                    <div class="preview-animations-group preview-animations-group--triggers" data-group="triggers" title="<?= adminAttr(__admin('preview.hoverStateOnlyTooltip')) ?>">
                        <button type="button" class="preview-animations-group__header">
                            <svg class="preview-animations-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                            <span class="preview-animations-group__title"><?= adminEscape(__admin('preview.triggersNoTransition')) ?></span>
                            <svg class="preview-animations-group__info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                            </svg>
                            <span class="preview-animations-group__count preview-animations-group__count--muted" id="triggers-count">0</span>
                        </button>
                        <div class="preview-animations-group__list preview-animations-group__list--collapsed" id="triggers-list">
                            <!-- Trigger-only selectors will be populated by JS -->
                        </div>
                    </div>

                    <!-- Empty State for Selectors with motion -->
                    <div class="preview-animations-empty" id="animated-empty" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <span><?= adminEscape(__admin('preview.noAnimatedSelectors')) ?></span>
                    </div>
                </div>

                <!-- Keyframes library (secondary, browse the toolbox) -->
                <div class="preview-animations-section" id="keyframes-section">
                    <div class="preview-animations-section__header">
                        <h4 class="preview-animations-section__title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            <?= adminEscape(__admin('preview.keyframesLibrary')) ?>
                        </h4>
                        <span class="preview-animations-section__count" id="keyframes-count">0</span>
                        <button type="button" class="admin-btn admin-btn--ghost" id="keyframe-add-btn" title="<?= adminAttr(__admin('preview.createKeyframe')) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="preview-animations-empty" id="keyframes-empty" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <span><?= adminEscape(__admin('preview.noKeyframes')) ?></span>
                    </div>

                    <div class="preview-keyframes-list" id="keyframes-list">
                        <!-- Keyframe items will be populated by JS -->
                    </div>
                </div>

            </div>
        </div>
        
        <!-- Source Panel (sidebar controls). The actual code editor
             mounts in the canvas (#preview-source-canvas); this sidebar
             carries the metadata, Save/Cancel actions, dirty indicator,
             and Refine link. -->
        <div class="preview-source-panel" id="source-panel" data-tab="source" style="display: none;">
            <div class="preview-source-sidebar">
                <div class="preview-source-sidebar__row">
                    <span class="preview-source-sidebar__label"><?= adminEscape(__admin('preview.styleSourceFile')) ?>:</span>
                    <code class="preview-source-sidebar__file" id="source-sidebar-file">style.css</code>
                </div>
                <!-- Dirty indicator. Hidden when content === server. JS toggles
                     the --dirty modifier when state changes. -->
                <div class="preview-source-sidebar__status preview-source-sidebar__status--clean" id="source-sidebar-status">
                    <span class="preview-source-sidebar__status-dot" aria-hidden="true">●</span>
                    <span id="source-sidebar-status-text"><?= adminEscape(__admin('preview.styleSourceClean')) ?></span>
                </div>
                <div class="preview-source-sidebar__hint">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                    <span><?= adminEscape(__admin('preview.styleSourceEditInCanvas')) ?></span>
                </div>
                <div class="preview-source-sidebar__actions">
                    <button type="button"
                            id="source-sidebar-save-btn"
                            class="admin-btn admin-btn--primary"
                            disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <span id="source-sidebar-save-label"><?= adminEscape(__admin('preview.styleSourceSave')) ?></span>
                    </button>
                    <button type="button"
                            id="source-sidebar-cancel-btn"
                            class="admin-btn admin-btn--ghost"
                            disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="1 4 1 10 7 10"/>
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                        </svg>
                        <span><?= adminEscape(__admin('preview.styleSourceCancel')) ?></span>
                    </button>
                </div>
                <!-- Refine link navigates in the SAME tab.
                     Opening in a new tab risked the user refining there + coming
                     back here with stale content unaware. JS click handler shows
                     the dirty-confirm prompt if needed; native beforeunload is
                     suppressed for this one navigation via _navigationConsented. -->
                <a class="preview-source-sidebar__refine-link"
                   id="source-sidebar-refine-link"
                   href="<?= rtrim(BASE_URL, '/') ?>/admin/optimize">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    <span><?= adminEscape(__admin('preview.styleSourceRefine')) ?></span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="opacity: 0.6;">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Style Editor Panel - shows when editing a selector -->
        <div class="preview-style-editor" id="style-editor" style="display: none;">
            <div class="preview-style-editor__header">
                <button type="button" class="preview-style-editor__back" id="style-editor-back" title="<?= adminAttr(__admin('common.back')) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </button>
                <div class="preview-style-editor__title">
                    <span class="preview-style-editor__label" id="style-editor-label" title="<?= adminAttr(__admin('preview.clickToGoBack')) ?>"><?= adminEscape(__admin('preview.editingSelector')) ?>:</span>
                    <code class="preview-style-editor__selector" id="style-editor-selector"></code>
                    <span class="preview-rule-media" id="style-editor-media" hidden></span>
                </div>
                <span class="preview-style-editor__badge" id="style-editor-badge" title="<?= adminAttr(__admin('preview.affectedElementsHint')) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span id="style-editor-count">0</span>
                </span>
            </div>
            
            <!-- Loading State -->
            <div class="preview-style-editor__loading" id="style-editor-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= adminEscape(__admin('common.loading')) ?></span>
            </div>
            
            <!-- Empty State (selector has no styles) -->
            <div class="preview-style-editor__empty" id="style-editor-empty" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span><?= adminEscape(__admin('preview.noStylesDefined')) ?></span>
                <button type="button" class="admin-btn admin-btn--ghost" id="style-editor-add-first">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= adminEscape(__admin('preview.addProperty')) ?>
                </button>
            </div>
            
            <!-- Properties List -->
            <div class="preview-style-editor__properties" id="style-editor-properties" style="display: none;">
                <!-- Property rows will be inserted here by JS -->
            </div>
            
            <!-- Add Property Row -->
            <div class="preview-style-editor__add" id="style-editor-add" style="display: none;">
                <button type="button" class="preview-style-editor__add-btn" id="style-editor-add-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= adminEscape(__admin('preview.addProperty')) ?>
                </button>
            </div>
            
            <!-- Actions -->
            <div class="preview-style-editor__actions" id="style-editor-actions" style="display: none;">
                <button type="button" class="admin-btn admin-btn--ghost" id="style-editor-reset">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="1,4 1,10 7,10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                    </svg>
                    <?= adminEscape(__admin('common.reset')) ?>
                </button>
                <button type="button" class="admin-btn admin-btn--primary" id="style-editor-save">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                    </svg>
                    <?= adminEscape(__admin('common.save')) ?>
                </button>
            </div>
        </div>
    </div>
</div>
