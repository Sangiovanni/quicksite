<!-- STYLE MODE Content -->
<div class="preview-contextual-section preview-contextual-section--style" id="contextual-style" data-mode="style" style="display: none;">
    <div class="preview-contextual-default" id="contextual-style-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
        </svg>
        <span><?= __admin('preview.styleModeHint') ?? 'Click an element to edit its style, or use the sections below' ?></span>
    </div>
    <!-- Style sections will be added in Phase 8.3+ -->
    <!-- Advanced top row: Source (raw stylesheet editor). Role-gated on
         editStyles — admin.js filterByPermissions adds .admin-hidden-permission
         when the user lacks the command. Hidden until Style mode activates
         (preview.js toggles display alongside the tabs row). -->
    <div class="preview-contextual-style-advanced" id="contextual-style-advanced" data-requires-command="editStyles" style="display: none;">
        <button type="button" class="preview-contextual-style-source-btn" id="contextual-style-source-btn" data-tab="source" title="<?= __admin('preview.styleSourceHint', 'Edit the full style.css source') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                <polyline points="16 18 22 12 16 6"/>
                <polyline points="8 6 2 12 8 18"/>
            </svg>
            <span><?= __admin('preview.styleSource', 'Source') ?></span>
        </button>
    </div>
    <div class="preview-contextual-style-tabs" id="contextual-style-tabs" style="display: none;">
        <button type="button" class="preview-contextual-style-tab preview-contextual-style-tab--active" data-tab="theme">
            <?= __admin('preview.themeVariables') ?? 'Theme' ?>
        </button>
        <button type="button" class="preview-contextual-style-tab" data-tab="selectors">
            <?= __admin('preview.selectors') ?? 'Selectors' ?>
        </button>
        <button type="button" class="preview-contextual-style-tab" data-tab="animations">
            <?= __admin('preview.animations') ?? 'Animations' ?>
        </button>
    </div>
    <div class="preview-contextual-style-content" id="contextual-style-content" style="display: none;">
        <!-- Theme Variables Panel -->
        <div class="preview-theme-panel" id="theme-panel" data-tab="theme">
            <div class="preview-theme-loading" id="theme-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= __admin('common.loading') ?>...</span>
            </div>
            
            <div class="preview-theme-content" id="theme-content" style="display: none;">
                <!-- Theme Mode Config (collapsible) -->
                <div class="preview-theme-section preview-theme-section--config" id="theme-mode-config">
                    <button type="button" class="preview-theme-config-toggle" id="theme-config-toggle" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        <?= __admin('preview.themeModeConfig') ?? 'Theme Mode Settings' ?>
                        <svg class="preview-theme-config-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div class="preview-theme-config-body" id="theme-config-body" style="display: none;">
                        <div class="preview-theme-config-row">
                            <span class="preview-theme-config-label"><?= __admin('preview.themeModeEnabled') ?? 'Dark mode support' ?></span>
                            <label class="preview-toggle">
                                <input type="checkbox" id="theme-config-enabled" <?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? 'checked' : '' ?>>
                                <span class="preview-toggle__track"></span>
                            </label>
                        </div>
                        <div class="preview-theme-config-row">
                            <span class="preview-theme-config-label"><?= __admin('preview.themeDefault') ?? 'Default mode' ?></span>
                            <select id="theme-config-default" class="preview-toolbar__select preview-toolbar__select--sm">
                                <option value="light" <?= ($editConfig['THEME_DEFAULT'] ?? 'light') === 'light' ? 'selected' : '' ?>>☀ <?= __admin('preview.light') ?? 'Light' ?></option>
                                <option value="dark"  <?= ($editConfig['THEME_DEFAULT'] ?? 'light') === 'dark'  ? 'selected' : '' ?>>🌙 <?= __admin('preview.dark') ?? 'Dark' ?></option>
                                <option value="system"<?= ($editConfig['THEME_DEFAULT'] ?? 'light') === 'system'? 'selected' : '' ?>>⚙ <?= __admin('preview.system') ?? 'System' ?></option>
                            </select>
                        </div>
                        <div class="preview-theme-config-row">
                            <span class="preview-theme-config-label"><?= __admin('preview.themeUserToggle') ?? 'Visitor can switch' ?></span>
                            <label class="preview-toggle">
                                <input type="checkbox" id="theme-config-usertoggle" <?= ($editConfig['THEME_USER_TOGGLE_ENABLED'] ?? false) ? 'checked' : '' ?>>
                                <span class="preview-toggle__track"></span>
                            </label>
                        </div>
                        <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="theme-config-save-btn" style="margin-top: 8px; width: 100%;">
                            <?= __admin('preview.saveThemeConfig') ?? 'Save Settings' ?>
                        </button>
                    </div>
                </div>

                <!-- Light / Dark scope switcher (always rendered; visibility managed by JS/config) -->
                <div class="preview-theme-scope" id="theme-scope-switcher" style="display: <?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : 'none' ?>;">
                    <button type="button" class="preview-theme-scope__btn preview-theme-scope__btn--active" data-scope="light">
                        <span aria-hidden="true">☀</span> <?= __admin('preview.light') ?? 'Light' ?>
                    </button>
                    <button type="button" class="preview-theme-scope__btn" data-scope="dark">
                        <span aria-hidden="true">🌙</span> <?= __admin('preview.dark') ?? 'Dark' ?>
                    </button>
                </div>
                <div class="preview-theme-section" id="theme-colors-section">
                    <h4 class="preview-theme-section__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                        </svg>
                        <?= __admin('preview.themeColors') ?? 'Colors' ?>
                    </h4>
                    <div class="preview-theme-grid preview-theme-grid--colors" id="theme-colors-grid">
                        <!-- Color inputs populated by JS -->
                    </div>
                    <!-- A3 slice 6 — quick-add variable -->
                    <div class="preview-theme-add" data-section="colors">
                        <button type="button" class="preview-theme-add__toggle" data-action="toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span><?= __admin('preview.themeAddVariable', 'Add variable') ?></span>
                        </button>
                        <div class="preview-theme-add__form" data-form hidden>
                            <div class="preview-theme-add__scope-banner" data-scope-banner></div>
                            <label class="preview-theme-add__also-other" data-also-other-row<?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : ' hidden' ?>>
                                <input type="checkbox" data-also-other>
                                <span><?= __admin('preview.themeAddVariableAlsoOtherScope', 'Also add to the other scope') ?></span>
                            </label>
                            <div class="preview-theme-add__row">
                                <input type="text"
                                       class="preview-theme-add__input preview-theme-add__input--name"
                                       data-input-name
                                       placeholder="--color-name"
                                       aria-label="<?= __admin('preview.themeAddVariableNameLabel', 'Name') ?>">
                                <input type="text"
                                       class="preview-theme-add__input preview-theme-add__input--value"
                                       data-input-value
                                       placeholder="<?= __admin('preview.themeAddVariableValueExampleColor', 'e.g. #c2703e') ?>"
                                       aria-label="<?= __admin('preview.themeAddVariableValueLabel', 'Value') ?>">
                            </div>
                            <div class="preview-theme-add__actions">
                                <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-action="cancel">
                                    <?= __admin('common.cancel') ?? 'Cancel' ?>
                                </button>
                                <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-action="submit">
                                    <?= __admin('preview.themeAddVariableSubmit', 'Add') ?>
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
                        <?= __admin('preview.themeFonts') ?? 'Fonts' ?>
                    </h4>
                    <div class="preview-theme-grid preview-theme-grid--fonts" id="theme-fonts-grid">
                        <!-- Font inputs populated by JS -->
                    </div>
                    <!-- A3 slice 6 — quick-add variable -->
                    <div class="preview-theme-add" data-section="fonts">
                        <button type="button" class="preview-theme-add__toggle" data-action="toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span><?= __admin('preview.themeAddVariable', 'Add variable') ?></span>
                        </button>
                        <div class="preview-theme-add__form" data-form hidden>
                            <div class="preview-theme-add__scope-banner" data-scope-banner></div>
                            <label class="preview-theme-add__also-other" data-also-other-row<?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : ' hidden' ?>>
                                <input type="checkbox" data-also-other>
                                <span><?= __admin('preview.themeAddVariableAlsoOtherScope', 'Also add to the other scope') ?></span>
                            </label>
                            <div class="preview-theme-add__row">
                                <input type="text"
                                       class="preview-theme-add__input preview-theme-add__input--name"
                                       data-input-name
                                       placeholder="--font-name"
                                       aria-label="<?= __admin('preview.themeAddVariableNameLabel', 'Name') ?>">
                                <input type="text"
                                       class="preview-theme-add__input preview-theme-add__input--value"
                                       data-input-value
                                       placeholder="<?= __admin('preview.themeAddVariableValueExampleFont', 'e.g. Roboto, sans-serif') ?>"
                                       aria-label="<?= __admin('preview.themeAddVariableValueLabel', 'Value') ?>">
                            </div>
                            <div class="preview-theme-add__actions">
                                <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-action="cancel">
                                    <?= __admin('common.cancel') ?? 'Cancel' ?>
                                </button>
                                <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-action="submit">
                                    <?= __admin('preview.themeAddVariableSubmit', 'Add') ?>
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
                        <?= __admin('preview.themeSpacing') ?? 'Spacing' ?>
                    </h4>
                    <div class="preview-theme-grid preview-theme-grid--spacing" id="theme-spacing-grid">
                        <!-- Spacing inputs populated by JS -->
                    </div>
                    <!-- A3 slice 6 — quick-add variable -->
                    <div class="preview-theme-add" data-section="spacing">
                        <button type="button" class="preview-theme-add__toggle" data-action="toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <span><?= __admin('preview.themeAddVariable', 'Add variable') ?></span>
                        </button>
                        <div class="preview-theme-add__form" data-form hidden>
                            <div class="preview-theme-add__scope-banner" data-scope-banner></div>
                            <label class="preview-theme-add__also-other" data-also-other-row<?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : ' hidden' ?>>
                                <input type="checkbox" data-also-other>
                                <span><?= __admin('preview.themeAddVariableAlsoOtherScope', 'Also add to the other scope') ?></span>
                            </label>
                            <div class="preview-theme-add__row">
                                <input type="text"
                                       class="preview-theme-add__input preview-theme-add__input--name"
                                       data-input-name
                                       placeholder="--spacing-name"
                                       aria-label="<?= __admin('preview.themeAddVariableNameLabel', 'Name') ?>">
                                <input type="text"
                                       class="preview-theme-add__input preview-theme-add__input--value"
                                       data-input-value
                                       placeholder="<?= __admin('preview.themeAddVariableValueExampleSpacing', 'e.g. 1rem, 16px') ?>"
                                       aria-label="<?= __admin('preview.themeAddVariableValueLabel', 'Value') ?>">
                            </div>
                            <div class="preview-theme-add__actions">
                                <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-action="cancel">
                                    <?= __admin('common.cancel') ?? 'Cancel' ?>
                                </button>
                                <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-action="submit">
                                    <?= __admin('preview.themeAddVariableSubmit', 'Add') ?>
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
                        <?= __admin('preview.themeOther') ?? 'Other' ?>
                    </h4>
                    <div class="preview-theme-grid preview-theme-grid--other" id="theme-other-grid">
                        <!-- Other variables populated by JS -->
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="preview-theme-actions">
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="theme-reset-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="1,4 1,10 7,10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                        </svg>
                        <?= __admin('common.reset') ?? 'Reset' ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="theme-save-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <?= __admin('preview.saveTheme') ?? 'Save Theme' ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Selectors Panel (Phase 8.4) -->
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
                           placeholder="<?= __admin('preview.searchSelectors') ?? 'Search selectors...' ?>"
                           autocomplete="off">
                    <button type="button" class="preview-selectors-search__clear" id="selector-search-clear" title="<?= __admin('common.clear') ?? 'Clear' ?>" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Selector Loading -->
            <div class="preview-selectors-loading" id="selectors-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= __admin('common.loading') ?>...</span>
            </div>
            
            <!-- Selector Groups (populated by JS) -->
            <div class="preview-selectors-groups" id="selectors-groups" style="display: none;">
                <!-- Tags Group -->
                <div class="preview-selectors-group" data-group="tags">
                    <button type="button" class="preview-selectors-group__header preview-selectors-group__header--expanded">
                        <svg class="preview-selectors-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        <span class="preview-selectors-group__title"><?= __admin('preview.selectorsTags') ?? 'Tags' ?></span>
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
                        <span class="preview-selectors-group__title"><?= __admin('preview.selectorsClasses') ?? 'Classes' ?></span>
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
                        <span class="preview-selectors-group__title"><?= __admin('preview.selectorsIds') ?? 'IDs' ?></span>
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
                        <span class="preview-selectors-group__title"><?= __admin('preview.selectorsAttributes') ?? 'Attributes' ?></span>
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
                        <span class="preview-selectors-group__title"><?= __admin('preview.selectorsMedia') ?? 'Media Queries' ?></span>
                        <span class="preview-selectors-group__count" id="selectors-media-count">0</span>
                    </button>
                    <div class="preview-selectors-group__list" id="selectors-media-list" style="display: none;"></div>
                </div>
            </div>
            
            <!-- Selected Selector Info (shows when a selector is selected) -->
            <div class="preview-selector-selected" id="selector-selected" style="display: none;">
                <div class="preview-selector-selected__header">
                    <span class="preview-selector-selected__label"><?= __admin('preview.selectedSelector') ?? 'Selected' ?>:</span>
                    <code class="preview-selector-selected__value" id="selector-selected-value"></code>
                    <button type="button" class="preview-selector-selected__clear" id="selector-selected-clear" title="<?= __admin('common.clear') ?? 'Clear' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="preview-selector-selected__info">
                    <span class="preview-selector-selected__matches" id="selector-matches">
                        <?= __admin('preview.affectsElements') ?? 'Affects' ?> <strong id="selector-match-count">0</strong> <?= __admin('preview.elements') ?? 'elements' ?>
                    </span>
                </div>
                <div class="preview-selector-selected__actions">
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="selector-edit-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        <?= __admin('preview.editStyles') ?? 'Edit Styles' ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--outline" id="selector-copy-from-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <?= __admin('preview.copyStyleFrom') ?? 'Copy From…' ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="selector-animate-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <?= __admin('preview.animate') ?? 'Animate' ?>
                    </button>
                </div>
                <!-- Copy From selector picker (hidden by default) -->
                <div class="preview-selector-copy-picker" id="selector-copy-picker" style="display: none;">
                    <div class="preview-selector-copy-picker__header">
                        <span><?= __admin('preview.copyStyleFromLabel') ?? 'Copy styles from:' ?></span>
                        <button type="button" class="preview-selector-copy-picker__close" id="selector-copy-picker-close" title="<?= __admin('common.cancel') ?? 'Cancel' ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <input type="text" class="admin-input admin-input--sm preview-selector-copy-picker__search" id="selector-copy-search" placeholder="<?= __admin('preview.searchSelectors') ?? 'Search selectors…' ?>">
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
                <span><?= __admin('common.loading') ?>...</span>
            </div>
            
            <!-- Motion Content (A3-companion: reordered + relabeled. DOM ids
                 stay on the old "animations" / "triggers" / "keyframes" names
                 since the JS module's internal wiring is unchanged — only the
                 user-facing labels move via i18n. Selectors-with-motion comes
                 first (primary editing surface); Keyframes library is the
                 secondary "toolbox" view below it. -->
            <div class="preview-animations-content" id="animations-content" style="display: none;">

                <!-- Selectors with motion (primary, expanded by default) -->
                <div class="preview-animations-section" id="animated-selectors-section">
                    <div class="preview-animations-section__header">
                        <h4 class="preview-animations-section__title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <?= __admin('preview.animatedSelectors') ?? 'Selectors with motion' ?>
                        </h4>
                    </div>

                    <!-- Transitions Group -->
                    <div class="preview-animations-group" data-group="transitions">
                        <button type="button" class="preview-animations-group__header preview-animations-group__header--expanded">
                            <svg class="preview-animations-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                            <span class="preview-animations-group__title"><?= __admin('preview.transitions') ?? 'Transitions' ?></span>
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
                            <span class="preview-animations-group__title"><?= __admin('preview.animationsProperty') ?? 'Animations' ?></span>
                            <span class="preview-animations-group__count" id="animations-count">0</span>
                        </button>
                        <div class="preview-animations-group__list" id="animations-list">
                            <!-- Animation selectors will be populated by JS -->
                        </div>
                    </div>

                    <!-- Hover-state-only Group (pseudo-state property changes
                         without a transition declared). data-group="triggers"
                         + DOM ids kept for backward compat with the JS module. -->
                    <div class="preview-animations-group preview-animations-group--triggers" data-group="triggers" title="<?= htmlspecialchars(__admin('preview.hoverStateOnlyTooltip', 'Selectors that change appearance in :hover, :focus, etc., without a transition declared.'), ENT_QUOTES) ?>">
                        <button type="button" class="preview-animations-group__header">
                            <svg class="preview-animations-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                            <span class="preview-animations-group__title"><?= __admin('preview.triggersNoTransition') ?? 'Hover-state-only' ?></span>
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
                        <span><?= __admin('preview.noAnimatedSelectors') ?? 'No selectors with transitions or animations' ?></span>
                    </div>
                </div>

                <!-- Keyframes library (secondary, browse the toolbox) -->
                <div class="preview-animations-section" id="keyframes-section">
                    <div class="preview-animations-section__header">
                        <h4 class="preview-animations-section__title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            <?= __admin('preview.keyframesLibrary') ?? 'Keyframes library' ?>
                        </h4>
                        <span class="preview-animations-section__count" id="keyframes-count">0</span>
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="keyframe-add-btn" title="<?= __admin('preview.createKeyframe') ?? 'Create new @keyframes' ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="preview-animations-empty" id="keyframes-empty" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <span><?= __admin('preview.noKeyframes') ?? 'No @keyframes defined yet' ?></span>
                    </div>

                    <div class="preview-keyframes-list" id="keyframes-list">
                        <!-- Keyframe items will be populated by JS -->
                    </div>
                </div>

            </div>
        </div>
        
        <!-- Source Panel (A3 — sidebar controls). The actual code editor
             mounts in the canvas (#preview-source-canvas); this sidebar
             carries the metadata, Save/Cancel actions, dirty indicator,
             and Refine link. -->
        <div class="preview-source-panel" id="source-panel" data-tab="source" style="display: none;">
            <div class="preview-source-sidebar">
                <div class="preview-source-sidebar__row">
                    <span class="preview-source-sidebar__label"><?= __admin('preview.styleSourceFile', 'File') ?>:</span>
                    <code class="preview-source-sidebar__file" id="source-sidebar-file">style.css</code>
                </div>
                <!-- Dirty indicator. Hidden when content === server. JS toggles
                     the --dirty modifier when state changes. -->
                <div class="preview-source-sidebar__status preview-source-sidebar__status--clean" id="source-sidebar-status">
                    <span class="preview-source-sidebar__status-dot" aria-hidden="true">●</span>
                    <span class="preview-source-sidebar__status-text" id="source-sidebar-status-text"><?= __admin('preview.styleSourceClean', 'All saved') ?></span>
                </div>
                <div class="preview-source-sidebar__hint">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                    <span><?= __admin('preview.styleSourceEditInCanvas', 'Edit style.css in the canvas') ?></span>
                </div>
                <div class="preview-source-sidebar__actions">
                    <button type="button"
                            id="source-sidebar-save-btn"
                            class="admin-btn admin-btn--primary admin-btn--sm"
                            disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <span id="source-sidebar-save-label"><?= __admin('preview.styleSourceSave', 'Save') ?></span>
                    </button>
                    <button type="button"
                            id="source-sidebar-cancel-btn"
                            class="admin-btn admin-btn--ghost admin-btn--sm"
                            disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="1 4 1 10 7 10"/>
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                        </svg>
                        <span><?= __admin('preview.styleSourceCancel', 'Cancel') ?></span>
                    </button>
                </div>
                <!-- Refine link navigates in the SAME tab (slice 4 review).
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
                    <span><?= __admin('preview.styleSourceRefine', 'Refine in CSS Refiner') ?></span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="opacity: 0.6;">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Style Editor Panel (Phase 8.5) - shows when editing a selector -->
        <div class="preview-style-editor" id="style-editor" style="display: none;">
            <div class="preview-style-editor__header">
                <button type="button" class="preview-style-editor__back" id="style-editor-back" title="<?= __admin('common.back') ?? 'Back' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </button>
                <div class="preview-style-editor__title">
                    <span class="preview-style-editor__label" id="style-editor-label" title="<?= __admin('preview.clickToGoBack') ?? 'Click to go back' ?>"><?= __admin('preview.editingSelector') ?? 'Editing' ?>:</span>
                    <code class="preview-style-editor__selector" id="style-editor-selector"></code>
                </div>
                <span class="preview-style-editor__badge" id="style-editor-badge" title="<?= __admin('preview.affectedElementsHint') ?? 'Number of elements affected by this selector' ?>">
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
                <span><?= __admin('common.loading') ?>...</span>
            </div>
            
            <!-- Empty State (selector has no styles) -->
            <div class="preview-style-editor__empty" id="style-editor-empty" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span><?= __admin('preview.noStylesDefined') ?? 'No styles defined for this selector' ?></span>
                <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="style-editor-add-first">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= __admin('preview.addProperty') ?? 'Add Property' ?>
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
                    <?= __admin('preview.addProperty') ?? 'Add Property' ?>
                </button>
            </div>
            
            <!-- Actions -->
            <div class="preview-style-editor__actions" id="style-editor-actions" style="display: none;">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="style-editor-reset">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="1,4 1,10 7,10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                    </svg>
                    <?= __admin('common.reset') ?? 'Reset' ?>
                </button>
                <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="style-editor-save">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                    </svg>
                    <?= __admin('common.save') ?? 'Save' ?>
                </button>
            </div>
        </div>
    </div>
</div>
