<?php
/**
 * Visual Preview Page
 * 
 * Live preview of the website with responsive controls.
 * Phase 1 & 2 of the Visual Editor feature.
 * 
 * @version 2.0.0
 */

// Get multilingual config
$isMultilingual = CONFIG['MULTILINGUAL_SUPPORT'] ?? false;
$defaultLang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
$languages = $isMultilingual ? (CONFIG['LANGUAGES_SUPPORTED'] ?? [$defaultLang]) : [$defaultLang];

// Get site URL for iframe (start with default language)
// Add ?_editor=1 to enable editor mode data attributes
$siteUrl = rtrim(BASE_URL, '/') . '/';
if ($isMultilingual) {
    $siteUrl .= $defaultLang . '/';
}
$siteUrl .= '?_editor=1';

// Get available routes for navigation
$routes = [];
$routesFile = PROJECT_PATH . '/routes.php';
if (file_exists($routesFile)) {
    $routesData = require $routesFile;
    // Flatten routes
    $flattenRoutes = function($routes, $prefix = '') use (&$flattenRoutes) {
        $result = [];
        foreach ($routes as $key => $value) {
            if (is_numeric($key)) continue;
            $path = $prefix ? "{$prefix}/{$key}" : $key;
            $result[] = $path;
            if (is_array($value) && !empty($value)) {
                $result = array_merge($result, $flattenRoutes($value, $path));
            }
        }
        return $result;
    };
    $routes = $flattenRoutes($routesData);
}

// Get available components
$components = [];
$componentsDir = PROJECT_PATH . '/templates/model/json/components';
if (is_dir($componentsDir)) {
    foreach (glob($componentsDir . '/*.json') as $file) {
        $components[] = basename($file, '.json');
    }
    sort($components);
}
?>

<!-- Preview Page Wrapper (for miniplayer state) -->
<div class="preview-page" id="preview-page">

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
        <?= __admin('preview.title') ?>
    </h1>
    <p class="admin-page-header__subtitle">
        <?= __admin('preview.subtitle') ?>
    </p>
</div>

<!-- Preview Controls -->
<div class="preview-toolbar">
    <!-- Editor Mode Controls -->
    <div class="preview-toolbar__group">
        <span class="preview-toolbar__label"><?= __admin('preview.mode') ?>:</span>
        <div class="preview-toolbar__modes">
            <button type="button" class="preview-mode-btn preview-mode-btn--active" data-mode="select" title="<?= __admin('preview.modeSelect') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
                    <path d="M13 13l6 6"/>
                </svg>
            </button>
            <button type="button" class="preview-mode-btn" data-mode="drag" title="<?= __admin('preview.modeDrag') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="5 9 2 12 5 15"/>
                    <polyline points="9 5 12 2 15 5"/>
                    <polyline points="15 19 12 22 9 19"/>
                    <polyline points="19 9 22 12 19 15"/>
                    <line x1="2" y1="12" x2="22" y2="12"/>
                    <line x1="12" y1="2" x2="12" y2="22"/>
                </svg>
            </button>
            <button type="button" class="preview-mode-btn" data-mode="text" title="<?= __admin('preview.modeText') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="4 7 4 4 20 4 20 7"/>
                    <line x1="9" y1="20" x2="15" y2="20"/>
                    <line x1="12" y1="4" x2="12" y2="20"/>
                </svg>
            </button>
            <button type="button" class="preview-mode-btn" data-mode="style" title="<?= __admin('preview.modeStyle') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Device Size Controls -->
    <div class="preview-toolbar__group">
        <span class="preview-toolbar__label"><?= __admin('preview.device') ?>:</span>
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
    
    <!-- Language Selector (for multilingual sites) -->
    <?php if ($isMultilingual && count($languages) > 1): ?>
    <div class="preview-toolbar__group">
        <label class="preview-toolbar__label" for="preview-lang"><?= __admin('preview.language') ?>:</label>
        <select id="preview-lang" class="preview-toolbar__select preview-toolbar__select--sm">
            <?php foreach ($languages as $lang): ?>
                <option value="<?= adminEscape($lang) ?>" <?= $lang === $defaultLang ? 'selected' : '' ?>><?= strtoupper(adminEscape($lang)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <!-- Page Navigation -->
    <div class="preview-toolbar__group preview-toolbar__group--grow">
        <label class="preview-toolbar__label" for="preview-route"><?= __admin('preview.page') ?>:</label>
        <select id="preview-route" class="preview-toolbar__select">
            <?php foreach ($routes as $route): ?>
                <option value="<?= adminEscape($route) ?>"><?= adminEscape($route) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <!-- Component Selector (Future: component isolation view) -->
    <?php if (!empty($components)): ?>
    <div class="preview-toolbar__group">
        <label class="preview-toolbar__label" for="preview-component"><?= __admin('preview.component') ?>:</label>
        <select id="preview-component" class="preview-toolbar__select preview-toolbar__select--sm" disabled title="<?= __admin('preview.componentSoon') ?>">
            <option value=""><?= __admin('preview.fullPage') ?></option>
            <?php foreach ($components as $component): ?>
                <option value="<?= adminEscape($component) ?>"><?= adminEscape($component) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <!-- Actions -->
    <div class="preview-toolbar__group">
        <button type="button" id="preview-reload" class="admin-btn admin-btn--ghost" title="<?= __admin('preview.reload') ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/>
                <polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            <span><?= __admin('preview.reload') ?></span>
        </button>
        <a href="<?= $siteUrl ?>" target="_blank" class="admin-btn admin-btn--ghost" title="<?= __admin('preview.openNewTab') ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <polyline points="15 3 21 3 21 9"/>
                <line x1="10" y1="14" x2="21" y2="3"/>
            </svg>
            <span><?= __admin('preview.openNewTab') ?></span>
        </a>
        <!-- Miniplayer Toggle -->
        <button type="button" id="preview-miniplayer-toggle" class="admin-btn admin-btn--ghost" title="<?= __admin('preview.miniplayer') ?>">
            <svg class="admin-btn__icon preview-miniplayer-icon--minimize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="14" y="14" width="8" height="8" rx="1"/>
                <path d="M4 4h12v12H4z" opacity="0.3"/>
                <path d="M20 10V4h-6"/>
            </svg>
            <svg class="admin-btn__icon preview-miniplayer-icon--expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <path d="M9 3v18M3 9h18"/>
            </svg>
            <span class="preview-miniplayer-text--minimize"><?= __admin('preview.miniplayer') ?></span>
            <span class="preview-miniplayer-text--expand" style="display: none;"><?= __admin('preview.expand') ?></span>
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     CONTEXTUAL AREA - Phase 8: Dynamic content based on selected mode
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="preview-contextual-area" id="preview-contextual-area">
    <!-- Collapse/Expand Toggle -->
    <button type="button" class="preview-contextual-toggle" id="preview-contextual-toggle" title="<?= __admin('preview.togglePanel') ?? 'Toggle panel' ?>">
        <svg class="preview-contextual-toggle__icon preview-contextual-toggle__icon--collapse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="18 15 12 9 6 15"/>
        </svg>
        <svg class="preview-contextual-toggle__icon preview-contextual-toggle__icon--expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </button>
    
    <div class="preview-contextual-content" id="preview-contextual-content">
        <!-- SELECT MODE Content (active by default) -->
        <div class="preview-contextual-section preview-contextual-section--select preview-contextual-section--active" id="contextual-select" data-mode="select">
            <div class="preview-contextual-default" id="contextual-select-default">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
                    <path d="M13 13l6 6"/>
                </svg>
                <span><?= __admin('preview.selectModeHint') ?? 'Click any element in the preview to inspect it' ?></span>
            </div>
            <div class="preview-contextual-info" id="contextual-select-info" style="display: none;">
                <!-- Element info will be populated here -->
                <div class="preview-contextual-info__main">
                    <div class="preview-contextual-info__row">
                        <span class="preview-contextual-info__label"><?= __admin('preview.structure') ?>:</span>
                        <code class="preview-contextual-info__value preview-contextual-info__value--struct" id="ctx-node-struct">-</code>
                    </div>
                    <div class="preview-contextual-info__row">
                        <span class="preview-contextual-info__label"><?= __admin('preview.nodeId') ?>:</span>
                        <code class="preview-contextual-info__value" id="ctx-node-id">-</code>
                    </div>
                    <div class="preview-contextual-info__row" id="ctx-node-component-row" style="display: none;">
                        <span class="preview-contextual-info__label"><?= __admin('preview.componentName') ?>:</span>
                        <code class="preview-contextual-info__value preview-contextual-info__value--component" id="ctx-node-component">-</code>
                    </div>
                    <div class="preview-contextual-info__row">
                        <span class="preview-contextual-info__label"><?= __admin('preview.nodeTag') ?>:</span>
                        <code class="preview-contextual-info__value" id="ctx-node-tag">-</code>
                    </div>
                    <div class="preview-contextual-info__row">
                        <span class="preview-contextual-info__label"><?= __admin('preview.nodeClasses') ?>:</span>
                        <code class="preview-contextual-info__value" id="ctx-node-classes">-</code>
                    </div>
                    <div class="preview-contextual-info__row">
                        <span class="preview-contextual-info__label"><?= __admin('preview.nodeChildren') ?>:</span>
                        <span class="preview-contextual-info__value" id="ctx-node-children">-</span>
                    </div>
                    <div class="preview-contextual-info__row">
                        <span class="preview-contextual-info__label"><?= __admin('preview.nodeText') ?>:</span>
                        <span class="preview-contextual-info__value preview-contextual-info__value--truncate" id="ctx-node-text">-</span>
                    </div>
                    <div class="preview-contextual-info__row" id="ctx-node-textkey-row" style="display: none;">
                        <span class="preview-contextual-info__label"><?= __admin('preview.textKey') ?? 'Text Key' ?>:</span>
                        <code class="preview-contextual-info__value preview-contextual-info__value--textkey" id="ctx-node-textkey">-</code>
                    </div>
                </div>
                <div class="preview-contextual-info__actions">
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--success" id="ctx-node-add" title="<?= __admin('preview.addNode') ?? 'Add Element' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <span><?= __admin('common.add') ?? 'Add' ?></span>
                    </button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="ctx-node-edit" title="<?= __admin('preview.editElement') ?? 'Edit Element' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        <span><?= __admin('common.edit') ?? 'Edit' ?></span>
                    </button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" id="ctx-node-delete" title="<?= __admin('common.delete') ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                        <span><?= __admin('common.delete') ?? 'Delete' ?></span>
                    </button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="ctx-node-style" title="<?= __admin('preview.editStyle') ?? 'Edit Style' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                        </svg>
                        <span><?= __admin('preview.style') ?? 'Style' ?></span>
                    </button>
                </div>
            </div>
        </div>
        
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
        
        <!-- TEXT MODE Content -->
        <div class="preview-contextual-section preview-contextual-section--text" id="contextual-text" data-mode="text" style="display: none;">
            <div class="preview-contextual-default">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="4 7 4 4 20 4 20 7"/>
                    <line x1="9" y1="20" x2="15" y2="20"/>
                    <line x1="12" y1="4" x2="12" y2="20"/>
                </svg>
                <span><?= __admin('preview.textModeHint') ?? 'Click any text in the preview to edit it inline' ?></span>
            </div>
        </div>
        
        <!-- STYLE MODE Content -->
        <div class="preview-contextual-section preview-contextual-section--style" id="contextual-style" data-mode="style" style="display: none;">
            <div class="preview-contextual-default" id="contextual-style-default">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                </svg>
                <span><?= __admin('preview.styleModeHint') ?? 'Click an element to edit its style, or use the sections below' ?></span>
            </div>
            <!-- Style sections will be added in Phase 8.3+ -->
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
                        <!-- Colors Section -->
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
                        <div class="preview-selectors-info" id="selector-info">
                            <span id="selector-count">0</span> <?= __admin('preview.selectorsFound') ?? 'selectors' ?>
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
                            <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="selector-animate-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <polygon points="5 3 19 12 5 21 5 3"/>
                                </svg>
                                <?= __admin('preview.animate') ?? 'Animate' ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Animations Panel (Phase 9.2) -->
                <div class="preview-animations-panel" id="animations-panel" data-tab="animations" style="display: none;">
                    <!-- Animations Loading -->
                    <div class="preview-animations-loading" id="animations-loading">
                        <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                        </svg>
                        <span><?= __admin('common.loading') ?>...</span>
                    </div>
                    
                    <!-- Animations Content -->
                    <div class="preview-animations-content" id="animations-content" style="display: none;">
                        
                        <!-- @keyframes Library Section -->
                        <div class="preview-animations-section" id="keyframes-section">
                            <div class="preview-animations-section__header">
                                <h4 class="preview-animations-section__title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <polygon points="5 3 19 12 5 21 5 3"/>
                                    </svg>
                                    <?= __admin('preview.keyframesLibrary') ?? '@keyframes Library' ?>
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
                        
                        <!-- Animated Selectors Section -->
                        <div class="preview-animations-section" id="animated-selectors-section">
                            <div class="preview-animations-section__header">
                                <h4 class="preview-animations-section__title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                    <?= __admin('preview.animatedSelectors') ?? 'Animated Selectors' ?>
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
                            
                            <!-- Triggers Without Transition Group (pseudo-states that change properties without transition) -->
                            <div class="preview-animations-group preview-animations-group--triggers" data-group="triggers">
                                <button type="button" class="preview-animations-group__header">
                                    <svg class="preview-animations-group__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                    <span class="preview-animations-group__title"><?= __admin('preview.triggersNoTransition') ?? 'Triggers (No Transition)' ?></span>
                                    <span class="preview-animations-group__count preview-animations-group__count--muted" id="triggers-count">0</span>
                                </button>
                                <div class="preview-animations-group__list preview-animations-group__list--collapsed" id="triggers-list">
                                    <!-- Trigger-only selectors will be populated by JS -->
                                </div>
                            </div>
                            
                            <!-- Empty State for Animated Selectors -->
                            <div class="preview-animations-empty" id="animated-empty" style="display: none;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                </svg>
                                <span><?= __admin('preview.noAnimatedSelectors') ?? 'No selectors with transitions or animations' ?></span>
                            </div>
                        </div>
                        
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
    </div>
</div>

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
        <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="node-edit-element" title="<?= __admin('preview.editElement') ?? 'Edit Element' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; vertical-align: middle;">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
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

<!-- Style Panel (DEPRECATED - kept for reference, hidden) -->
<div class="preview-style-panel" id="preview-style-panel" style="display: none !important;">
    <div class="preview-style-panel__header" title="<?= __admin('preview.dragToMove') ?? 'Drag to move' ?>">
        <svg class="preview-panel-drag-icon" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
            <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
            <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
            <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
        </svg>
        <h3 class="preview-style-panel__title"><?= __admin('preview.styleEditor') ?? 'Style Editor' ?></h3>
        <button type="button" class="preview-style-panel__close" id="preview-style-close" title="<?= __admin('common.close') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>
    
    <div class="preview-style-panel__info">
        <div class="preview-style-panel__selector-row">
            <span class="preview-style-panel__label"><?= __admin('preview.selector') ?? 'Selector' ?>:</span>
            <code class="preview-style-panel__selector-value" id="style-selector">-</code>
        </div>
        <div class="preview-style-panel__notice">
            <?= __admin('preview.styleNotice') ?? 'Quick CSS tweaks. For complex styling, edit the SCSS file directly.' ?>
        </div>
    </div>
    
    <div class="preview-style-panel__content" id="style-panel-content">
        <!-- Spacing -->
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">padding</label>
            <input type="text" class="preview-style-input" data-prop="padding" placeholder="e.g. 1rem">
        </div>
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">margin</label>
            <input type="text" class="preview-style-input" data-prop="margin" placeholder="e.g. 1rem auto">
        </div>
        
        <!-- Colors (using QSColorPicker) -->
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">color</label>
            <input type="text" class="preview-style-input preview-style-input--color" data-prop="color" placeholder="#000000">
        </div>
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">background</label>
            <input type="text" class="preview-style-input preview-style-input--color" data-prop="background-color" placeholder="#ffffff">
        </div>
        
        <!-- Typography -->
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">font-size</label>
            <input type="text" class="preview-style-input" data-prop="font-size" placeholder="e.g. 16px">
        </div>
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">font-weight</label>
            <select class="preview-style-input" data-prop="font-weight">
                <option value="">-</option>
                <option value="400">400 (normal)</option>
                <option value="500">500</option>
                <option value="600">600</option>
                <option value="700">700 (bold)</option>
            </select>
        </div>
        
        <!-- Size -->
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">width</label>
            <input type="text" class="preview-style-input" data-prop="width" placeholder="e.g. 100%">
        </div>
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">max-width</label>
            <input type="text" class="preview-style-input" data-prop="max-width" placeholder="e.g. 1200px">
        </div>
        
        <!-- Border -->
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">border-radius</label>
            <input type="text" class="preview-style-input" data-prop="border-radius" placeholder="e.g. 8px">
        </div>
        
        <!-- Layout (for flex containers) -->
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">display</label>
            <select class="preview-style-input" data-prop="display">
                <option value="">-</option>
                <option value="block">block</option>
                <option value="flex">flex</option>
                <option value="grid">grid</option>
                <option value="none">none</option>
            </select>
        </div>
        <div class="preview-style-panel__row">
            <label class="preview-style-panel__prop-label">gap</label>
            <input type="text" class="preview-style-input" data-prop="gap" placeholder="e.g. 1rem">
        </div>
    </div>
    
    <div class="preview-style-panel__actions">
        <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="style-apply">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <?= __admin('common.apply') ?? 'Apply' ?>
        </button>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="style-reset">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                <path d="M3 3v5h5"/>
            </svg>
            <?= __admin('common.reset') ?? 'Reset' ?>
        </button>
    </div>
</div>

<!-- Add Node Modal -->
<div class="preview-add-node-modal" id="preview-add-node-modal" style="display: none;">
    <div class="preview-add-node-modal__backdrop"></div>
    <div class="preview-add-node-modal__content">
        <div class="preview-add-node-modal__header">
            <h3><?= __admin('preview.addElement') ?? 'Add Element' ?></h3>
            <button type="button" class="preview-add-node-modal__close" id="add-node-modal-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="preview-add-node-modal__body">
            <!-- Element Type Selection: Radio buttons -->
            <div class="preview-add-node-modal__field">
                <label><?= __admin('preview.elementType') ?? 'Type' ?>:</label>
                <div class="preview-add-node-modal__type-selector">
                    <label class="preview-add-node-modal__radio">
                        <input type="radio" name="add-node-type" value="tag" checked>
                        <span><?= __admin('preview.htmlTag') ?? 'HTML Tag' ?></span>
                    </label>
                    <label class="preview-add-node-modal__radio">
                        <input type="radio" name="add-node-type" value="component">
                        <span><?= __admin('preview.component') ?? 'Component' ?></span>
                    </label>
                </div>
            </div>
            
            <!-- Tag Selection (for HTML Tag type) -->
            <div class="preview-add-node-modal__field" id="add-node-tag-field">
                <label for="add-node-tag"><?= __admin('preview.selectTag') ?? 'Tag' ?>:</label>
                <select id="add-node-tag" class="admin-input">
                    <optgroup label="<?= __admin('preview.layoutTags') ?? 'Layout' ?>">
                        <option value="div">div</option>
                        <option value="section">section</option>
                        <option value="article">article</option>
                        <option value="header">header</option>
                        <option value="footer">footer</option>
                        <option value="nav">nav</option>
                        <option value="main">main</option>
                        <option value="aside">aside</option>
                        <option value="figure">figure</option>
                        <option value="figcaption">figcaption</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.textTags') ?? 'Text' ?>">
                        <option value="p">p (paragraph)</option>
                        <option value="h1">h1</option>
                        <option value="h2">h2</option>
                        <option value="h3">h3</option>
                        <option value="h4">h4</option>
                        <option value="h5">h5</option>
                        <option value="h6">h6</option>
                        <option value="span">span</option>
                        <option value="strong">strong</option>
                        <option value="em">em</option>
                        <option value="small">small</option>
                        <option value="mark">mark</option>
                        <option value="blockquote">blockquote</option>
                        <option value="pre">pre</option>
                        <option value="code">code</option>
                        <option value="q">q (quote)</option>
                        <option value="cite">cite</option>
                        <option value="abbr">abbr</option>
                        <option value="time">time</option>
                        <option value="address">address</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.interactiveTags') ?? 'Interactive' ?>">
                        <option value="a">a (link) *</option>
                        <option value="button">button</option>
                        <option value="details">details</option>
                        <option value="summary">summary</option>
                        <option value="dialog">dialog</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.listTags') ?? 'Lists' ?>">
                        <option value="ul">ul (unordered list)</option>
                        <option value="ol">ol (ordered list)</option>
                        <option value="li">li (list item)</option>
                        <option value="dl">dl (description list)</option>
                        <option value="dt">dt (term)</option>
                        <option value="dd">dd (description)</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.mediaTags') ?? 'Media' ?>">
                        <option value="img">img * (image)</option>
                        <option value="picture">picture</option>
                        <option value="video">video *</option>
                        <option value="audio">audio *</option>
                        <option value="iframe">iframe *</option>
                        <option value="embed">embed *</option>
                        <option value="object">object *</option>
                        <option value="source">source *</option>
                        <option value="track">track *</option>
                        <option value="canvas">canvas</option>
                        <option value="svg">svg</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.formTags') ?? 'Form' ?>">
                        <option value="form">form *</option>
                        <option value="input">input *</option>
                        <option value="textarea">textarea *</option>
                        <option value="label">label *</option>
                        <option value="select">select *</option>
                        <option value="option">option</option>
                        <option value="optgroup">optgroup</option>
                        <option value="fieldset">fieldset</option>
                        <option value="legend">legend</option>
                        <option value="datalist">datalist</option>
                        <option value="output">output</option>
                        <option value="progress">progress</option>
                        <option value="meter">meter</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.tableTags') ?? 'Table' ?>">
                        <option value="table">table</option>
                        <option value="thead">thead</option>
                        <option value="tbody">tbody</option>
                        <option value="tfoot">tfoot</option>
                        <option value="tr">tr</option>
                        <option value="th">th</option>
                        <option value="td">td</option>
                        <option value="caption">caption</option>
                        <option value="colgroup">colgroup</option>
                        <option value="col">col</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.otherTags') ?? 'Other' ?>">
                        <option value="br">br (line break)</option>
                        <option value="hr">hr (horizontal rule)</option>
                        <option value="wbr">wbr (word break)</option>
                    </optgroup>
                </select>
                <small class="preview-add-node-modal__hint">* <?= __admin('preview.requiresParams') ?? 'Requires additional parameters' ?></small>
            </div>
            
            <!-- Position Selection -->
            <div class="preview-add-node-modal__field">
                <label for="add-node-position"><?= __admin('preview.position') ?? 'Position' ?>:</label>
                <select id="add-node-position" class="admin-input">
                    <option value="after"><?= __admin('preview.positionAfter') ?? 'After selected element' ?></option>
                    <option value="before"><?= __admin('preview.positionBefore') ?? 'Before selected element' ?></option>
                    <option value="inside"><?= __admin('preview.positionInside') ?? 'Inside (as first child)' ?></option>
                </select>
            </div>
            
            <!-- Mandatory Parameters Section (dynamic based on tag) -->
            <div class="preview-add-node-modal__section" id="add-node-mandatory-params" style="display: none;">
                <label class="preview-add-node-modal__section-label">
                    <?= __admin('preview.requiredParams') ?? 'Required Parameters' ?>:
                </label>
                <div class="preview-add-node-modal__mandatory-fields" id="mandatory-params-container">
                    <!-- Dynamically populated -->
                </div>
            </div>
            
            <!-- CSS Class Input -->
            <div class="preview-add-node-modal__field" id="add-node-class-field">
                <label for="add-node-class"><?= __admin('preview.cssClass') ?? 'CSS Class' ?> <small>(<?= __admin('common.optional') ?? 'optional' ?>)</small>:</label>
                <input type="text" id="add-node-class" class="admin-input" placeholder="my-class another-class">
            </div>
            
            <!-- Custom Parameters Section (expandable) -->
            <div class="preview-add-node-modal__section">
                <button type="button" class="preview-add-node-modal__expand-btn" id="add-node-expand-params">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= __admin('preview.addCustomParam') ?? 'Add custom parameter' ?>
                </button>
                <div class="preview-add-node-modal__custom-params" id="custom-params-container" style="display: none;">
                    <div class="preview-add-node-modal__param-list" id="custom-params-list">
                        <!-- Dynamically added param rows -->
                    </div>
                    <button type="button" class="preview-add-node-modal__add-param-btn" id="add-another-param">
                        + <?= __admin('preview.addAnother') ?? 'Add another' ?>
                    </button>
                </div>
            </div>
            
            <!-- TextKey Info (read-only, informational) -->
            <div class="preview-add-node-modal__info" id="add-node-textkey-info" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span><?= __admin('preview.textKeyWillGenerate') ?? 'Text key will be auto-generated' ?>: <code id="generated-textkey-preview">-</code></span>
            </div>
            
            <!-- Alt Key Info for img/area (read-only, informational) -->
            <div class="preview-add-node-modal__info" id="add-node-altkey-info" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span><?= __admin('preview.altKeyWillGenerate') ?? 'Alt text key will be auto-generated' ?>: <code id="generated-altkey-preview">-</code></span>
            </div>
            
            <!-- Component Selection (for Component type) -->
            <div class="preview-add-node-modal__field" id="add-node-component-field" style="display: none;">
                <label for="add-node-component"><?= __admin('preview.selectComponent') ?? 'Select Component' ?>:</label>
                <select id="add-node-component" class="admin-input">
                    <option value=""><?= __admin('preview.selectComponentPlaceholder') ?? '-- Select a component --' ?></option>
                </select>
            </div>
            
            <!-- Component Variables (for Component type) -->
            <div class="preview-add-node-modal__section" id="add-node-component-vars" style="display: none;">
                <label class="preview-add-node-modal__section-label">
                    <?= __admin('preview.componentVariables') ?? 'Component Variables' ?>:
                </label>
                <div class="preview-add-node-modal__component-vars-list" id="component-vars-container">
                    <!-- Dynamically populated: textKey vars (read-only), param vars (input) -->
                </div>
                <div class="preview-add-node-modal__info" id="component-no-vars" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span><?= __admin('preview.componentNoVars') ?? 'This component has no configurable variables' ?></span>
                </div>
            </div>
        </div>
        <div class="preview-add-node-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="add-node-cancel"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--success" id="add-node-confirm"><?= __admin('preview.addElement') ?? 'Add Element' ?></button>
        </div>
    </div>
</div>

<!-- Edit Element Modal -->
<div class="preview-add-node-modal" id="preview-edit-node-modal" style="display: none;">
    <div class="preview-add-node-modal__backdrop"></div>
    <div class="preview-add-node-modal__content">
        <div class="preview-add-node-modal__header">
            <h3><?= __admin('preview.editElement') ?? 'Edit Element' ?></h3>
            <button type="button" class="preview-add-node-modal__close" id="edit-node-modal-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="preview-add-node-modal__body">
            <!-- Node info (read-only) -->
            <div class="preview-add-node-modal__info preview-add-node-modal__info--node">
                <span><?= __admin('preview.editing') ?? 'Editing' ?>: <code id="edit-node-id">-</code></span>
            </div>
            
            <!-- Component notice (shown for component nodes) -->
            <div class="preview-add-node-modal__warning" id="edit-node-component-warning" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px; flex-shrink: 0;">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span><?= __admin('preview.componentEditNotice') ?? 'This is a component node. Use editComponentToNode to edit component variables.' ?></span>
            </div>
            
            <!-- Text node notice (shown for text-only nodes) -->
            <div class="preview-add-node-modal__warning" id="edit-node-textonly-warning" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px; flex-shrink: 0;">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span><?= __admin('preview.textOnlyEditNotice') ?? 'This is a text node. Edit translations using the Translations page.' ?></span>
            </div>
            
            <!-- Tag editing content -->
            <div id="edit-node-tag-content">
                <!-- Tag Selection -->
                <div class="preview-add-node-modal__field">
                    <label for="edit-node-tag"><?= __admin('preview.tag') ?? 'Tag' ?>:</label>
                    <select id="edit-node-tag" class="admin-input">
                        <optgroup label="<?= __admin('preview.layoutTags') ?? 'Layout' ?>">
                            <option value="div">div</option>
                            <option value="section">section</option>
                            <option value="article">article</option>
                            <option value="header">header</option>
                            <option value="footer">footer</option>
                            <option value="nav">nav</option>
                            <option value="main">main</option>
                            <option value="aside">aside</option>
                            <option value="figure">figure</option>
                            <option value="figcaption">figcaption</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.textTags') ?? 'Text' ?>">
                            <option value="p">p (paragraph)</option>
                            <option value="h1">h1</option>
                            <option value="h2">h2</option>
                            <option value="h3">h3</option>
                            <option value="h4">h4</option>
                            <option value="h5">h5</option>
                            <option value="h6">h6</option>
                            <option value="span">span</option>
                            <option value="strong">strong</option>
                            <option value="em">em</option>
                            <option value="small">small</option>
                            <option value="mark">mark</option>
                            <option value="blockquote">blockquote</option>
                            <option value="pre">pre</option>
                            <option value="code">code</option>
                            <option value="q">q (quote)</option>
                            <option value="cite">cite</option>
                            <option value="abbr">abbr</option>
                            <option value="time">time</option>
                            <option value="address">address</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.interactiveTags') ?? 'Interactive' ?>">
                            <option value="a">a (link) *</option>
                            <option value="button">button</option>
                            <option value="details">details</option>
                            <option value="summary">summary</option>
                            <option value="dialog">dialog</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.listTags') ?? 'Lists' ?>">
                            <option value="ul">ul (unordered list)</option>
                            <option value="ol">ol (ordered list)</option>
                            <option value="li">li (list item)</option>
                            <option value="dl">dl (description list)</option>
                            <option value="dt">dt (term)</option>
                            <option value="dd">dd (description)</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.mediaTags') ?? 'Media' ?>">
                            <option value="img">img * (image)</option>
                            <option value="picture">picture</option>
                            <option value="video">video *</option>
                            <option value="audio">audio *</option>
                            <option value="iframe">iframe *</option>
                            <option value="embed">embed *</option>
                            <option value="object">object *</option>
                            <option value="source">source *</option>
                            <option value="track">track *</option>
                            <option value="canvas">canvas</option>
                            <option value="svg">svg</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.formTags') ?? 'Form' ?>">
                            <option value="form">form *</option>
                            <option value="input">input *</option>
                            <option value="textarea">textarea *</option>
                            <option value="label">label *</option>
                            <option value="select">select *</option>
                            <option value="option">option</option>
                            <option value="optgroup">optgroup</option>
                            <option value="fieldset">fieldset</option>
                            <option value="legend">legend</option>
                            <option value="datalist">datalist</option>
                            <option value="output">output</option>
                            <option value="progress">progress</option>
                            <option value="meter">meter</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.tableTags') ?? 'Table' ?>">
                            <option value="table">table</option>
                            <option value="thead">thead</option>
                            <option value="tbody">tbody</option>
                            <option value="tfoot">tfoot</option>
                            <option value="tr">tr</option>
                            <option value="th">th</option>
                            <option value="td">td</option>
                            <option value="caption">caption</option>
                            <option value="colgroup">colgroup</option>
                            <option value="col">col</option>
                        </optgroup>
                        <optgroup label="<?= __admin('preview.otherTags') ?? 'Other' ?>">
                            <option value="br">br (line break)</option>
                            <option value="hr">hr (horizontal rule)</option>
                            <option value="wbr">wbr (word break)</option>
                        </optgroup>
                    </select>
                    <small class="preview-add-node-modal__hint">* <?= __admin('preview.requiresParams') ?? 'Requires additional parameters' ?></small>
                </div>
                
                <!-- Current/Required Parameters Section -->
                <div class="preview-add-node-modal__section" id="edit-node-params-section">
                    <label class="preview-add-node-modal__section-label">
                        <?= __admin('preview.parameters') ?? 'Parameters' ?>:
                    </label>
                    <div class="preview-add-node-modal__param-list" id="edit-params-container">
                        <!-- Dynamically populated with existing params -->
                    </div>
                    <button type="button" class="preview-add-node-modal__add-param-btn" id="edit-add-param">
                        + <?= __admin('preview.addParameter') ?? 'Add Parameter' ?>
                    </button>
                </div>
                
                <!-- TextKey info (read-only) -->
                <div class="preview-add-node-modal__info" id="edit-node-textkey-info" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                        <polyline points="4 7 4 4 20 4 20 7"/>
                        <line x1="9" y1="20" x2="15" y2="20"/>
                        <line x1="12" y1="4" x2="12" y2="20"/>
                    </svg>
                    <span><?= __admin('preview.textKeyReference') ?? 'Text Key' ?>: <code id="edit-node-textkey-value">-</code></span>
                </div>
                
                <!-- AltKey info (read-only, for img/area) -->
                <div class="preview-add-node-modal__info" id="edit-node-altkey-info" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <span><?= __admin('preview.altKeyReference') ?? 'Alt Key' ?>: <code id="edit-node-altkey-value">-</code></span>
                </div>
            </div>
        </div>
        <div class="preview-add-node-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="edit-node-cancel"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--primary" id="edit-node-confirm"><?= __admin('preview.saveChanges') ?? 'Save Changes' ?></button>
        </div>
    </div>
</div>

<!-- Edit Component Modal -->
<div class="preview-add-node-modal" id="preview-edit-component-modal" style="display: none;">
    <div class="preview-add-node-modal__backdrop"></div>
    <div class="preview-add-node-modal__content">
        <div class="preview-add-node-modal__header">
            <h3><?= __admin('preview.editComponent') ?? 'Edit Component' ?></h3>
            <button type="button" class="preview-add-node-modal__close" id="edit-component-modal-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="preview-add-node-modal__body">
            <!-- Node info (read-only) -->
            <div class="preview-add-node-modal__info preview-add-node-modal__info--node">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/>
                    <path d="M2 12l10 5 10-5"/>
                </svg>
                <span><?= __admin('preview.component') ?? 'Component' ?>: <code id="edit-component-name">-</code></span>
            </div>
            <div class="preview-add-node-modal__info preview-add-node-modal__info--node">
                <span><?= __admin('preview.nodeId') ?? 'Node ID' ?>: <code id="edit-component-node-id">-</code></span>
            </div>
            
            <!-- Component Variables Section -->
            <div class="preview-add-node-modal__section" id="edit-component-vars-section">
                <label class="preview-add-node-modal__section-label">
                    <?= __admin('preview.componentVariables') ?? 'Component Variables' ?>:
                </label>
                <div class="preview-add-node-modal__component-vars-list" id="edit-component-vars-container">
                    <!-- Dynamically populated: param vars (editable input), textKey vars (read-only) -->
                </div>
                
                <!-- Info about textKey variables -->
                <div class="preview-add-node-modal__info" id="edit-component-textkey-notice" style="display: none; margin-top: 1rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span><?= __admin('preview.textKeyEditNotice') ?? 'Text content is edited through the Translations page. The keys shown above are translation references.' ?></span>
                </div>
            </div>
            
            <!-- No variables message -->
            <div class="preview-add-node-modal__info" id="edit-component-no-vars" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span><?= __admin('preview.componentNoEditableVars') ?? 'This component has no editable variables.' ?></span>
            </div>
        </div>
        <div class="preview-add-node-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="edit-component-cancel"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--primary" id="edit-component-confirm"><?= __admin('preview.saveChanges') ?? 'Save Changes' ?></button>
        </div>
    </div>
</div>

<!-- Keyframe Editor Modal (Phase 9.3) -->
<div class="preview-keyframe-modal" id="preview-keyframe-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content">
        <div class="preview-keyframe-modal__header">
            <h3 id="keyframe-modal-title"><?= __admin('preview.editKeyframe') ?? 'Edit Keyframe' ?></h3>
            <button type="button" class="preview-keyframe-modal__close" id="keyframe-modal-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="preview-keyframe-modal__body">
            <!-- Keyframe Name -->
            <div class="preview-keyframe-modal__field">
                <label for="keyframe-name"><?= __admin('preview.keyframeName') ?? 'Name' ?> <span class="admin-required">*</span>:</label>
                <input type="text" id="keyframe-name" class="admin-input" placeholder="fadeIn" required>
                <small class="preview-keyframe-modal__hint"><?= __admin('preview.keyframeNameHint') ?? 'Letters, numbers, hyphens. Start with letter.' ?></small>
            </div>
            
            <!-- Timeline -->
            <div class="preview-keyframe-modal__timeline-section">
                <div class="preview-keyframe-modal__timeline-header">
                    <label><?= __admin('preview.keyframeTimeline') ?? 'Timeline' ?>:</label>
                    <small class="preview-keyframe-modal__timeline-hint"><?= __admin('preview.clickToAddFrame') ?? 'Click on timeline to add frames' ?></small>
                </div>
                <div class="preview-keyframe-modal__timeline" id="keyframe-timeline">
                    <!-- Timeline markers populated by JS -->
                </div>
            </div>
            
            <!-- Frames Container (each frame's properties) -->
            <div class="preview-keyframe-modal__frames" id="keyframe-frames">
                <!-- Frame editors populated by JS -->
            </div>
        </div>
        <div class="preview-keyframe-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="keyframe-preview-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                <?= __admin('preview.previewAnimation') ?? 'Preview' ?>
            </button>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="keyframe-cancel"><?= __admin('common.cancel') ?></button>
                <button type="button" class="admin-btn admin-btn--primary" id="keyframe-save"><?= __admin('common.save') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Transform Editor Modal (Phase 9.3.1 Step 5) -->
<div class="preview-keyframe-modal transform-editor" id="transform-editor-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content transform-editor__content">
        <div class="preview-keyframe-modal__header">
            <h3><?= __admin('preview.transformEditor') ?? 'Transform Editor' ?></h3>
            <button type="button" class="preview-keyframe-modal__close" id="transform-editor-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <div class="preview-keyframe-modal__body transform-editor__body">
            <!-- Current Transform Preview -->
            <div class="transform-editor__preview">
                <label><?= __admin('preview.currentTransform') ?? 'Current Transform' ?>:</label>
                <code id="transform-current-value" class="transform-editor__current">none</code>
            </div>
            
            <!-- Functions List -->
            <div class="transform-editor__functions">
                <div class="transform-editor__functions-header">
                    <label><?= __admin('preview.activeFunctions') ?? 'Active Functions' ?>:</label>
                    <div class="transform-editor__add-wrapper">
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="transform-add-btn">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <?= __admin('preview.addFunction') ?? 'Add Function' ?>
                        </button>
                        <div class="transform-editor__dropdown" id="transform-add-dropdown">
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.translate') ?? 'Translate' ?></div>
                                <button type="button" data-fn="translateX">translateX</button>
                                <button type="button" data-fn="translateY">translateY</button>
                                <button type="button" data-fn="translateZ">translateZ</button>
                                <button type="button" data-fn="translate">translate (X, Y)</button>
                                <button type="button" data-fn="translate3d">translate3d</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.rotate') ?? 'Rotate' ?></div>
                                <button type="button" data-fn="rotate">rotate</button>
                                <button type="button" data-fn="rotateX">rotateX</button>
                                <button type="button" data-fn="rotateY">rotateY</button>
                                <button type="button" data-fn="rotateZ">rotateZ</button>
                                <button type="button" data-fn="rotate3d">rotate3d</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.scale') ?? 'Scale' ?></div>
                                <button type="button" data-fn="scale">scale (X, Y)</button>
                                <button type="button" data-fn="scaleX">scaleX</button>
                                <button type="button" data-fn="scaleY">scaleY</button>
                                <button type="button" data-fn="scaleZ">scaleZ</button>
                                <button type="button" data-fn="scale3d">scale3d</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.skew') ?? 'Skew' ?></div>
                                <button type="button" data-fn="skew">skew (X, Y)</button>
                                <button type="button" data-fn="skewX">skewX</button>
                                <button type="button" data-fn="skewY">skewY</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.other') ?? 'Other' ?></div>
                                <button type="button" data-fn="perspective">perspective</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="transform-editor__functions-list" id="transform-functions-list">
                    <!-- Function rows will be rendered here -->
                </div>
            </div>
        </div>
        
        <div class="preview-keyframe-modal__footer">
            <div class="preview-keyframe-modal__footer-left">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" id="transform-clear">
                    <?= __admin('preview.clearAll') ?? 'Clear All' ?>
                </button>
            </div>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="transform-cancel"><?= __admin('common.cancel') ?></button>
                <button type="button" class="admin-btn admin-btn--primary" id="transform-apply"><?= __admin('preview.apply') ?? 'Apply' ?></button>
            </div>
        </div>
    </div>
</div>

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

<!-- Animation Preview Modal (Phase 9.5) -->
<div class="preview-keyframe-modal animation-preview" id="animation-preview-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content animation-preview__content">
        <div class="preview-keyframe-modal__header">
            <h3><?= __admin('preview.animationPreview') ?? 'Animation Preview' ?></h3>
            <span class="animation-preview__keyframe-name" id="animation-preview-name">@keyframes name</span>
            <button type="button" class="preview-keyframe-modal__close" id="animation-preview-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <div class="preview-keyframe-modal__body animation-preview__body">
            <!-- Preview Stage -->
            <div class="animation-preview__stage" id="animation-preview-stage">
                <img src="<?= $baseUrl ?>/admin/assets/images/favicon.png" alt="Preview" class="animation-preview__logo" id="animation-preview-logo">
            </div>
            
            <!-- Animation Controls -->
            <div class="animation-preview__controls">
                <!-- Duration -->
                <div class="animation-preview__control">
                    <label for="animation-preview-duration"><?= __admin('preview.animDuration') ?? 'Duration' ?>:</label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-duration" class="admin-input" value="1000" min="100" max="10000" step="100">
                        <span class="animation-preview__unit">ms</span>
                    </div>
                </div>
                
                <!-- Timing Function -->
                <div class="animation-preview__control">
                    <label for="animation-preview-timing"><?= __admin('preview.timingFunction') ?? 'Timing Function' ?>:</label>
                    <select id="animation-preview-timing" class="admin-select">
                        <option value="ease" selected>ease</option>
                        <option value="linear">linear</option>
                        <option value="ease-in">ease-in</option>
                        <option value="ease-out">ease-out</option>
                        <option value="ease-in-out">ease-in-out</option>
                    </select>
                </div>
                
                <!-- Delay -->
                <div class="animation-preview__control">
                    <label for="animation-preview-delay"><?= __admin('preview.animDelay') ?? 'Delay' ?>:</label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-delay" class="admin-input" value="0" min="0" max="5000" step="100">
                        <span class="animation-preview__unit">ms</span>
                    </div>
                </div>
                
                <!-- Iteration Count -->
                <div class="animation-preview__control">
                    <label for="animation-preview-count"><?= __admin('preview.animIterations') ?? 'Iterations' ?>:</label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-count" class="admin-input" value="1" min="1" max="100" step="1">
                        <label class="animation-preview__checkbox">
                            <input type="checkbox" id="animation-preview-infinite">
                            <span><?= __admin('preview.infinite') ?? 'Infinite' ?></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Generated Animation CSS -->
            <div class="animation-preview__css-preview">
                <label><?= __admin('preview.generatedCSS') ?? 'Generated CSS' ?>:</label>
                <code id="animation-preview-css">animation: 1000ms ease 0ms keyframeName; animation-iteration-count: 1;</code>
            </div>
        </div>
        
        <div class="preview-keyframe-modal__footer">
            <div class="preview-keyframe-modal__footer-left">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="animation-preview-play">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <?= __admin('preview.playAnimation') ?? 'Play Animation' ?>
                </button>
            </div>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="animation-preview-done"><?= __admin('common.close') ?? 'Close' ?></button>
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
    
    <!-- Resize Handle at bottom (drag to adjust preview height) -->
    <div class="preview-resize-handle" id="preview-resize-handle" title="<?= __admin('preview.dragToResize') ?? 'Drag to resize' ?>">
        <div class="preview-resize-handle__bar"></div>
    </div>
</div>

<!-- Color Picker (needed before inline script) -->
<script src="<?= $baseUrl ?>/admin/assets/js/colorpicker.js"></script>

<!-- Preview JavaScript -->
<script>
(function() {
    'use strict';
    
    // DOM Elements
    const iframe = document.getElementById('preview-iframe');
    const container = document.getElementById('preview-container');
    const wrapper = document.getElementById('preview-frame-wrapper');
    const previewResizeHandle = document.getElementById('preview-resize-handle');
    const loading = document.getElementById('preview-loading');
    const routeSelect = document.getElementById('preview-route');
    const langSelect = document.getElementById('preview-lang');
    const componentSelect = document.getElementById('preview-component');
    const reloadBtn = document.getElementById('preview-reload');
    const deviceBtns = document.querySelectorAll('.preview-device-btn');
    const modeBtns = document.querySelectorAll('.preview-mode-btn');
    
    // Node panel elements
    const nodePanel = document.getElementById('preview-node-panel');
    const nodeClose = document.getElementById('preview-node-close');
    const nodeStructEl = document.getElementById('node-struct');
    const nodeIdEl = document.getElementById('node-id');
    const nodeComponentRow = document.getElementById('node-component-row');
    const nodeComponentEl = document.getElementById('node-component');
    const nodeTagEl = document.getElementById('node-tag');
    const nodeClassesEl = document.getElementById('node-classes');
    const nodeChildrenEl = document.getElementById('node-children');
    const nodeTextEl = document.getElementById('node-text');
    const nodeTextKeyRow = document.getElementById('node-textkey-row');
    const nodeTextKeyEl = document.getElementById('node-textkey');
    const nodeDeleteBtn = document.getElementById('node-delete');
    
    // Contextual area elements (Phase 8)
    const contextualArea = document.getElementById('preview-contextual-area');
    const contextualToggle = document.getElementById('preview-contextual-toggle');
    const contextualSections = document.querySelectorAll('.preview-contextual-section');
    const ctxSelectDefault = document.getElementById('contextual-select-default');
    const ctxSelectInfo = document.getElementById('contextual-select-info');
    const ctxNodeStruct = document.getElementById('ctx-node-struct');
    const ctxNodeId = document.getElementById('ctx-node-id');
    const ctxNodeComponentRow = document.getElementById('ctx-node-component-row');
    const ctxNodeComponent = document.getElementById('ctx-node-component');
    const ctxNodeTag = document.getElementById('ctx-node-tag');
    const ctxNodeClasses = document.getElementById('ctx-node-classes');
    const ctxNodeChildren = document.getElementById('ctx-node-children');
    const ctxNodeText = document.getElementById('ctx-node-text');
    const ctxNodeTextKeyRow = document.getElementById('ctx-node-textkey-row');
    const ctxNodeTextKey = document.getElementById('ctx-node-textkey');
    const ctxNodeAdd = document.getElementById('ctx-node-add');
    const ctxNodeEdit = document.getElementById('ctx-node-edit');
    const ctxNodeDelete = document.getElementById('ctx-node-delete');
    const ctxNodeStyle = document.getElementById('ctx-node-style');
    
    // Theme panel elements (Phase 8.3)
    const styleTabs = document.getElementById('contextual-style-tabs');
    const styleContent = document.getElementById('contextual-style-content');
    const themePanel = document.getElementById('theme-panel');
    const themeLoading = document.getElementById('theme-loading');
    const themeContent = document.getElementById('theme-content');
    const themeColorsGrid = document.getElementById('theme-colors-grid');
    const themeFontsGrid = document.getElementById('theme-fonts-grid');
    const themeSpacingGrid = document.getElementById('theme-spacing-grid');
    const themeOtherGrid = document.getElementById('theme-other-grid');
    const themeOtherSection = document.getElementById('theme-other-section');
    const themeResetBtn = document.getElementById('theme-reset-btn');
    const themeSaveBtn = document.getElementById('theme-save-btn');
    const selectorsPanel = document.getElementById('selectors-panel');
    
    // Selector browser elements (Phase 8.4)
    const selectorSearchInput = document.getElementById('selector-search-input');
    const selectorSearchClear = document.getElementById('selector-search-clear');
    const selectorCount = document.getElementById('selector-count');
    const selectorsLoading = document.getElementById('selectors-loading');
    const selectorsGroups = document.getElementById('selectors-groups');
    const selectorTagsList = document.getElementById('selectors-tags-list');
    const selectorClassesList = document.getElementById('selectors-classes-list');
    const selectorIdsList = document.getElementById('selectors-ids-list');
    const selectorAttributesList = document.getElementById('selectors-attributes-list');
    const selectorMediaList = document.getElementById('selectors-media-list');
    const selectorTagsCount = document.getElementById('selectors-tags-count');
    const selectorClassesCount = document.getElementById('selectors-classes-count');
    const selectorIdsCount = document.getElementById('selectors-ids-count');
    const selectorAttributesCount = document.getElementById('selectors-attributes-count');
    const selectorMediaCount = document.getElementById('selectors-media-count');
    const selectorSelected = document.getElementById('selector-selected');
    const selectorSelectedValue = document.getElementById('selector-selected-value');
    const selectorSelectedClear = document.getElementById('selector-selected-clear');
    const selectorMatchCount = document.getElementById('selector-match-count');
    const selectorEditBtn = document.getElementById('selector-edit-btn');
    const selectorAnimateBtn = document.getElementById('selector-animate-btn');
    
    // Style Editor elements (Phase 8.5)
    const styleEditor = document.getElementById('style-editor');
    const styleEditorBack = document.getElementById('style-editor-back');
    const styleEditorLabel = document.getElementById('style-editor-label');
    const styleEditorSelector = document.getElementById('style-editor-selector');
    const styleEditorCount = document.getElementById('style-editor-count');
    const styleEditorLoading = document.getElementById('style-editor-loading');
    const styleEditorEmpty = document.getElementById('style-editor-empty');
    const styleEditorProperties = document.getElementById('style-editor-properties');
    const styleEditorAdd = document.getElementById('style-editor-add');
    const styleEditorAddBtn = document.getElementById('style-editor-add-btn');
    const styleEditorAddFirst = document.getElementById('style-editor-add-first');
    const styleEditorActions = document.getElementById('style-editor-actions');
    const styleEditorReset = document.getElementById('style-editor-reset');
    const styleEditorSave = document.getElementById('style-editor-save');
    
    // Animations panel elements (Phase 9.2)
    const animationsPanel = document.getElementById('animations-panel');
    const animationsLoading = document.getElementById('animations-loading');
    const animationsContent = document.getElementById('animations-content');
    const keyframesCount = document.getElementById('keyframes-count');
    const keyframesEmpty = document.getElementById('keyframes-empty');
    const keyframesList = document.getElementById('keyframes-list');
    const keyframeAddBtn = document.getElementById('keyframe-add-btn');
    const transitionsCount = document.getElementById('transitions-count');
    const transitionsList = document.getElementById('transitions-list');
    const animationsCount = document.getElementById('animations-count');
    const animationsList = document.getElementById('animations-list');
    const animatedEmpty = document.getElementById('animated-empty');
    
    // Keyframe Editor Modal elements (Phase 9.3)
    const keyframeModal = document.getElementById('preview-keyframe-modal');
    const keyframeModalTitle = document.getElementById('keyframe-modal-title');
    const keyframeModalClose = document.getElementById('keyframe-modal-close');
    const keyframeNameInput = document.getElementById('keyframe-name');
    const keyframeTimeline = document.getElementById('keyframe-timeline');
    const keyframeFramesContainer = document.getElementById('keyframe-frames');
    const keyframeAddFrameBtn = document.getElementById('keyframe-add-frame');
    const keyframePreviewBtn = document.getElementById('keyframe-preview-btn');
    const keyframeCancelBtn = document.getElementById('keyframe-cancel');
    const keyframeSaveBtn = document.getElementById('keyframe-save');
    
    // Configuration
    const baseUrl = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
    const adminUrl = <?= json_encode($router->url('')) ?>;
    const managementUrl = <?= json_encode(rtrim(BASE_URL, '/') . '/management/') ?>;
    const authToken = <?= json_encode($router->getToken()) ?>;
    const structureUrl = <?= json_encode($router->url('structure')) ?>;
    const multilingual = <?= json_encode(CONFIG['MULTILINGUAL_SUPPORT'] ?? false) ?>;
    const defaultLang = <?= json_encode(CONFIG['LANGUAGE_DEFAULT'] ?? 'en') ?>;
    
    // Device sizes
    const devices = {
        desktop: { width: '100%', height: '100%' },
        tablet: { width: '768px', height: '1024px' },
        mobile: { width: '375px', height: '667px' }
    };
    
    // State
    let currentDevice = 'desktop';
    let currentMode = 'select';
    let currentComponent = '';
    let overlayInjected = false;
    
    // Theme variables state (Phase 8.3)
    let themeVariablesLoaded = false;
    let originalThemeVariables = {};  // Original values from CSS file
    let currentThemeVariables = {};   // Current working values (modified)
    let activeStyleTab = 'theme';     // 'theme' or 'selectors'
    
    // Selector browser state (Phase 8.4)
    let selectorsLoaded = false;
    let allSelectors = [];            // All selectors from CSS
    let categorizedSelectors = { tags: [], classes: [], ids: [], attributes: [], media: {} };
    let currentSelectedSelector = null;  // Currently selected selector
    let hoveredSelector = null;       // Currently hovered selector (for highlight)
    
    // Style Editor state (Phase 8.5)
    let styleEditorVisible = false;
    let editingSelector = null;       // Selector being edited
    let editingSelectorCount = 0;     // Number of matching elements
    let originalStyles = {};          // Original property values from CSS
    let currentStyles = {};           // Current working values (modified)
    let newProperties = [];           // Newly added properties
    let deletedProperties = [];       // Original properties that have been deleted
    let stylePreviewInjected = false; // Whether live preview style is injected
    
    // Animations tab state (Phase 9.2)
    let animationsLoaded = false;     // Whether animations data has been loaded
    let keyframesData = [];           // All @keyframes from CSS
    let animatedSelectorsData = {     // Selectors with transition/animation properties
        transitions: [],
        animations: [],
        triggersWithoutTransition: []
    };
    let keyframePreviewActive = null; // Name of keyframe being previewed
    
    // Keyframe Editor state (Phase 9.3)
    let keyframeEditorMode = 'edit';  // 'edit' or 'create'
    let editingKeyframeName = null;   // Original name (for rename detection)
    let keyframeFrames = {};          // Current frame data: { '0%': { opacity: '0' }, '100%': { opacity: '1' } }
    let selectedFramePercent = null;  // Currently selected frame in timeline
    
    // ==================== Property Type Registry (Phase 9.3.1) ====================
    
    /**
     * Property type definitions for keyframe editor
     * Maps CSS property names to input types and configurations
     */
    const KEYFRAME_PROPERTY_TYPES = {
        // Opacity - range slider 0-1
        opacity: { type: 'range', min: 0, max: 1, step: 0.01 },
        
        // Color properties - color picker
        'color': { type: 'color' },
        'background-color': { type: 'color' },
        'border-color': { type: 'color' },
        'border-top-color': { type: 'color' },
        'border-right-color': { type: 'color' },
        'border-bottom-color': { type: 'color' },
        'border-left-color': { type: 'color' },
        'outline-color': { type: 'color' },
        'fill': { type: 'color' },
        'stroke': { type: 'color' },
        'text-decoration-color': { type: 'color' },
        'caret-color': { type: 'color' },
        
        // Length properties - number + unit dropdown
        'width': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'auto'] },
        'height': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'auto'] },
        'min-width': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh'] },
        'min-height': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh'] },
        'max-width': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'none'] },
        'max-height': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'none'] },
        'top': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'right': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'bottom': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'left': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-top': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-right': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-bottom': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-left': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'padding': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-top': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-right': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-bottom': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-left': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'gap': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'row-gap': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'column-gap': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'border-width': { type: 'length', units: ['px', 'em'] },
        'border-radius': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'font-size': { type: 'length', units: ['px', 'em', 'rem', '%', 'vw'] },
        'line-height': { type: 'length', units: ['px', 'em', 'rem', '%', ''] },  // '' = unitless
        'letter-spacing': { type: 'length', units: ['px', 'em', 'normal'] },
        'word-spacing': { type: 'length', units: ['px', 'em', 'normal'] },
        'outline-width': { type: 'length', units: ['px', 'em'] },
        'outline-offset': { type: 'length', units: ['px', 'em'] },
        
        // Enumerated values - dropdown
        'visibility': { type: 'enum', values: ['visible', 'hidden', 'collapse'] },
        'display': { type: 'enum', values: ['block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'none', 'contents'] },
        'overflow': { type: 'enum', values: ['visible', 'hidden', 'scroll', 'auto', 'clip'] },
        'overflow-x': { type: 'enum', values: ['visible', 'hidden', 'scroll', 'auto', 'clip'] },
        'overflow-y': { type: 'enum', values: ['visible', 'hidden', 'scroll', 'auto', 'clip'] },
        'position': { type: 'enum', values: ['static', 'relative', 'absolute', 'fixed', 'sticky'] },
        'pointer-events': { type: 'enum', values: ['auto', 'none'] },
        'cursor': { type: 'enum', values: ['auto', 'default', 'pointer', 'grab', 'grabbing', 'text', 'crosshair', 'move', 'not-allowed', 'wait', 'progress', 'help', 'none'] },
        'text-align': { type: 'enum', values: ['left', 'center', 'right', 'justify', 'start', 'end'] },
        'text-decoration': { type: 'enum', values: ['none', 'underline', 'overline', 'line-through'] },
        'font-weight': { type: 'enum', values: ['normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900'] },
        'font-style': { type: 'enum', values: ['normal', 'italic', 'oblique'] },
        'white-space': { type: 'enum', values: ['normal', 'nowrap', 'pre', 'pre-wrap', 'pre-line', 'break-spaces'] },
        'flex-direction': { type: 'enum', values: ['row', 'row-reverse', 'column', 'column-reverse'] },
        'flex-wrap': { type: 'enum', values: ['nowrap', 'wrap', 'wrap-reverse'] },
        'justify-content': { type: 'enum', values: ['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly', 'start', 'end'] },
        'align-items': { type: 'enum', values: ['flex-start', 'flex-end', 'center', 'baseline', 'stretch', 'start', 'end'] },
        'align-content': { type: 'enum', values: ['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'stretch', 'start', 'end'] },
        'align-self': { type: 'enum', values: ['auto', 'flex-start', 'flex-end', 'center', 'baseline', 'stretch'] },
        
        // Number properties (unitless)
        'z-index': { type: 'number', step: 1 },
        'flex-grow': { type: 'number', min: 0, step: 1 },
        'flex-shrink': { type: 'number', min: 0, step: 1 },
        'order': { type: 'number', step: 1 },
        
        // Angle properties
        'rotate': { type: 'angle', units: ['deg', 'rad', 'turn'] },
        
        // Scale (unitless numbers, can be space-separated for X Y)
        'scale': { type: 'text' },  // e.g., "1.5" or "1.2 0.8"
        
        // Complex properties - text input fallback (Phase 2 for specialized editors)
        'transform': { type: 'transform' },  // Phase 9.3.1 Step 5: Transform Sub-Editor
        'filter': { type: 'text' },
        'box-shadow': { type: 'text' },
        'text-shadow': { type: 'text' },
        'clip-path': { type: 'text' },
        'background': { type: 'text' },
        'background-image': { type: 'text' },
        'transition': { type: 'text' },
        'animation': { type: 'text' },
        'translate': { type: 'text' },
        'skew': { type: 'text' }
    };
    
    /**
     * CSS Properties organized by category for property selector dropdowns
     * Used by QSPropertySelector class
     */
    const CSS_PROPERTY_CATEGORIES = {
        'Layout': [
            'display', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'box-sizing', 'overflow', 'overflow-x', 'overflow-y'
        ],
        'Flexbox': [
            'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
            'gap', 'row-gap', 'column-gap', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
            'align-self', 'order'
        ],
        'Grid': [
            'grid-template-columns', 'grid-template-rows', 'grid-gap', 'grid-column-gap',
            'grid-row-gap', 'grid-auto-flow', 'grid-column', 'grid-row', 'place-items',
            'place-content', 'place-self'
        ],
        'Position': [
            'position', 'top', 'right', 'bottom', 'left', 'z-index', 'inset'
        ],
        'Typography': [
            'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
            'letter-spacing', 'word-spacing', 'text-align', 'text-decoration', 
            'text-transform', 'white-space', 'word-break', 'text-overflow'
        ],
        'Colors': [
            'color', 'background-color', 'background', 'background-image',
            'border-color', 'outline-color', 'fill', 'stroke'
        ],
        'Borders': [
            'border', 'border-width', 'border-style', 'border-color', 'border-radius',
            'border-top', 'border-right', 'border-bottom', 'border-left',
            'outline', 'outline-width', 'outline-style', 'outline-offset'
        ],
        'Effects': [
            'opacity', 'visibility', 'box-shadow', 'text-shadow', 'filter',
            'backdrop-filter', 'mix-blend-mode', 'clip-path'
        ],
        'Transform': [
            'transform', 'transform-origin', 'perspective', 'translate', 'rotate', 'scale'
        ],
        'Transition': [
            'transition', 'transition-property', 'transition-duration', 
            'transition-timing-function', 'transition-delay'
        ],
        'Animation': [
            'animation', 'animation-name', 'animation-duration', 'animation-timing-function',
            'animation-delay', 'animation-iteration-count', 'animation-direction', 'animation-fill-mode'
        ],
        'Other': [
            'cursor', 'pointer-events', 'user-select', 'content', 'list-style',
            'object-fit', 'object-position', 'aspect-ratio'
        ]
    };
    
    /**
     * QSPropertySelector - Reusable searchable CSS property selector dropdown
     * Can be used anywhere a property needs to be selected from a categorized list
     * 
     * @example
     * const selector = new QSPropertySelector({
     *     container: document.getElementById('my-container'),
     *     onSelect: (property) => console.log('Selected:', property),
     *     excludeProperties: ['color', 'background'] // optional
     * });
     */
    class QSPropertySelector {
        constructor(options) {
            this.container = options.container;
            this.onSelect = options.onSelect || (() => {});
            this.excludeProperties = new Set(options.excludeProperties || []);
            this.placeholder = options.placeholder || <?= json_encode(__admin('preview.selectProperty') ?? 'Select property...') ?>;
            this.searchPlaceholder = options.searchPlaceholder || <?= json_encode(__admin('preview.searchProperties') ?? 'Search properties...') ?>;
            this.currentValue = options.currentValue || '';
            this.showCategoryLabels = options.showCategoryLabels !== false;
            
            this.dropdownEl = null;
            this.triggerEl = null;
            this.isOpen = false;
            this.focusedIndex = -1;
            this.allItems = [];
            
            this._closeHandler = null;
            
            this.render();
        }
        
        render() {
            // Clear container
            this.container.innerHTML = '';
            this.container.className = 'qs-property-selector';
            
            // Create trigger button
            this.triggerEl = document.createElement('button');
            this.triggerEl.type = 'button';
            this.triggerEl.className = 'qs-property-selector__trigger';
            this.triggerEl.innerHTML = `
                <span class="qs-property-selector__text">${this.currentValue || this.placeholder}</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            `;
            
            this.triggerEl.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.isOpen) {
                    this.close();
                } else {
                    this.open();
                }
            });
            
            this.container.appendChild(this.triggerEl);
        }
        
        open() {
            if (this.isOpen) return;
            this.isOpen = true;
            this.triggerEl.classList.add('qs-property-selector__trigger--open');
            
            // Create dropdown
            this.dropdownEl = document.createElement('div');
            this.dropdownEl.className = 'qs-property-selector__dropdown';
            
            // Position dropdown using fixed positioning (to escape overflow containers)
            const triggerRect = this.triggerEl.getBoundingClientRect();
            this.dropdownEl.style.top = (triggerRect.bottom + 4) + 'px';
            this.dropdownEl.style.left = triggerRect.left + 'px';
            this.dropdownEl.style.minWidth = Math.max(triggerRect.width, 200) + 'px';
            
            // Search input
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'qs-property-selector__search';
            searchInput.placeholder = this.searchPlaceholder;
            searchInput.value = this.currentValue || '';
            this.dropdownEl.appendChild(searchInput);
            
            // List container
            const list = document.createElement('div');
            list.className = 'qs-property-selector__list';
            this.dropdownEl.appendChild(list);
            
            // Render initial list
            this._renderList(list, searchInput.value);
            
            // Search filtering with keyboard nav
            searchInput.addEventListener('input', (e) => {
                this._renderList(list, e.target.value);
            });
            
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.focusedIndex = Math.min(this.focusedIndex + 1, this.allItems.length - 1);
                    this._updateFocusedItem();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.focusedIndex = Math.max(this.focusedIndex - 1, 0);
                    this._updateFocusedItem();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (this.focusedIndex >= 0 && this.allItems[this.focusedIndex]) {
                        this.allItems[this.focusedIndex].click();
                    } else if (e.target.value.trim()) {
                        // Allow custom property entry
                        const customVal = e.target.value.trim();
                        if (!this.excludeProperties.has(customVal)) {
                            this.select(customVal);
                        } else {
                            showToast(<?= json_encode(__admin('preview.propertyAlreadyExists') ?? 'Property already exists') ?>, 'warning');
                        }
                    }
                } else if (e.key === 'Escape') {
                    this.close();
                }
            });
            
            // Append to document.body so it escapes overflow containers
            document.body.appendChild(this.dropdownEl);
            
            // Focus search
            setTimeout(() => searchInput.focus(), 0);
            
            // Close on outside click
            this._closeHandler = (e) => {
                if (!this.container.contains(e.target) && !this.dropdownEl?.contains(e.target)) {
                    this.close();
                }
            };
            setTimeout(() => document.addEventListener('click', this._closeHandler), 0);
        }
        
        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            
            if (this.dropdownEl) {
                this.dropdownEl.remove();
                this.dropdownEl = null;
            }
            this.triggerEl.classList.remove('qs-property-selector__trigger--open');
            
            if (this._closeHandler) {
                document.removeEventListener('click', this._closeHandler);
                this._closeHandler = null;
            }
            
            this.focusedIndex = -1;
            this.allItems = [];
        }
        
        select(property) {
            this.currentValue = property;
            this.triggerEl.querySelector('.qs-property-selector__text').textContent = property;
            this.close();
            this.onSelect(property);
        }
        
        setValue(property) {
            this.currentValue = property;
            this.triggerEl.querySelector('.qs-property-selector__text').textContent = property || this.placeholder;
        }
        
        getValue() {
            return this.currentValue;
        }
        
        setExcludeProperties(properties) {
            this.excludeProperties = new Set(properties);
        }
        
        destroy() {
            this.close();
            this.container.innerHTML = '';
        }
        
        _renderList(listEl, filter = '') {
            listEl.innerHTML = '';
            this.allItems = [];
            const filterLower = filter.toLowerCase().trim();
            
            // Render each category
            for (const [category, properties] of Object.entries(CSS_PROPERTY_CATEGORIES)) {
                // Filter by search AND exclude existing properties
                const filtered = properties.filter(p => 
                    (!filterLower || p.toLowerCase().includes(filterLower)) &&
                    !this.excludeProperties.has(p)
                );
                
                if (filtered.length === 0) continue;
                
                // Category group
                const group = document.createElement('div');
                group.className = 'qs-property-selector__group';
                
                // Category label
                if (this.showCategoryLabels) {
                    const label = document.createElement('div');
                    label.className = 'qs-property-selector__group-label';
                    label.textContent = category;
                    group.appendChild(label);
                }
                
                // Property items
                for (const prop of filtered) {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'qs-property-selector__item';
                    item.dataset.property = prop;
                    
                    const propType = KEYFRAME_PROPERTY_TYPES[prop];
                    const typeLabel = propType?.type && propType.type !== 'text' ? propType.type : '';
                    
                    item.innerHTML = `
                        <span>${prop}</span>
                        ${typeLabel ? `<span class="qs-property-selector__item-type">${typeLabel}</span>` : ''}
                    `;
                    
                    item.addEventListener('click', () => this.select(prop));
                    item.addEventListener('mouseenter', () => {
                        this.focusedIndex = this.allItems.indexOf(item);
                        this._updateFocusedItem();
                    });
                    
                    group.appendChild(item);
                    this.allItems.push(item);
                }
                
                listEl.appendChild(group);
            }
            
            // Add "Custom" option for manual entry (but warn if property exists)
            if (filterLower && !this.allItems.some(item => item.dataset.property === filterLower)) {
                if (this.excludeProperties.has(filterLower) || this.excludeProperties.has(filter)) {
                    // Show warning
                    const warningItem = document.createElement('div');
                    warningItem.className = 'qs-property-selector__warning';
                    warningItem.innerHTML = `
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span><?= __admin('preview.propertyAlreadyExists') ?? 'Property already exists' ?></span>
                    `;
                    listEl.appendChild(warningItem);
                } else {
                    const customItem = document.createElement('button');
                    customItem.type = 'button';
                    customItem.className = 'qs-property-selector__item qs-property-selector__item--custom';
                    customItem.innerHTML = `
                        <span><?= __admin('preview.useCustomProperty') ?? 'Use custom:' ?> <strong>${filter}</strong></span>
                    `;
                    customItem.addEventListener('click', () => this.select(filter));
                    listEl.appendChild(customItem);
                    this.allItems.push(customItem);
                }
            }
            
            // Show empty state
            if (this.allItems.length === 0 && !filterLower) {
                listEl.innerHTML = `<div class="qs-property-selector__empty"><?= __admin('preview.noPropertiesFound') ?? 'No properties found' ?></div>`;
            }
            
            this.focusedIndex = -1;
        }
        
        _updateFocusedItem() {
            this.allItems.forEach((item, i) => {
                item.classList.toggle('qs-property-selector__item--focused', i === this.focusedIndex);
            });
            if (this.focusedIndex >= 0 && this.allItems[this.focusedIndex]) {
                this.allItems[this.focusedIndex].scrollIntoView({ block: 'nearest' });
            }
        }
    }
    
    /**
     * QSValueInput - Reusable CSS value input component (Phase 10.3)
     * Creates appropriate input controls based on CSS property type:
     * - range: Slider with value display
     * - length: Number input + unit dropdown
     * - enum: Dropdown with predefined values
     * - number: Number input with step
     * - color: Text input with optional color swatch
     * - text: Plain text input (default)
     * 
     * Supports two class naming conventions:
     * - 'qs-value-input' (default): Uses BEM-style classes (qs-value-input--range, qs-value-input__range)
     * - 'preview-style-property': Uses existing Selectors panel classes (preview-style-property__range-container)
     */
    class QSValueInput {
        /**
         * @param {object} options
         * @param {HTMLElement} options.container - Container element
         * @param {string} options.property - CSS property name
         * @param {string} options.value - Initial value
         * @param {function} options.onChange - Callback when value changes
         * @param {function} options.onBlur - Callback on blur (for live preview)
         * @param {string} options.className - CSS class prefix ('qs-value-input' or 'preview-style-property')
         * @param {boolean} options.showColorSwatch - Whether to show color swatch for color properties
         * @param {function} options.onColorPick - Callback when color swatch is clicked (receives property, value, swatchEl)
         */
        constructor(options) {
            this.container = options.container;
            this.property = options.property || '';
            this.currentValue = options.value || '';
            this.onChange = options.onChange || (() => {});
            this.onBlur = options.onBlur || (() => {});
            this.classPrefix = options.className || 'qs-value-input';
            this.showColorSwatch = options.showColorSwatch !== false; // Default true
            this.onColorPick = options.onColorPick || null;
            
            // Use legacy class names for preview-style-property prefix
            this.useLegacyClasses = this.classPrefix === 'preview-style-property';
            
            this.inputEl = null;
            this.unitSelectEl = null;
            this.colorSwatchEl = null;
            
            this._render();
        }
        
        /**
         * Check if property is a color property
         */
        _isColorProperty() {
            const colorProps = ['color', 'background', 'border', 'outline', 'fill', 'stroke', 'shadow', 'caret'];
            const propType = KEYFRAME_PROPERTY_TYPES[this.property];
            return propType?.type === 'color' || colorProps.some(cp => this.property.includes(cp));
        }
        
        /**
         * Get class name based on naming convention
         */
        _getClass(type) {
            if (this.useLegacyClasses) {
                // Legacy class names for Selectors panel
                const legacyMap = {
                    'container-range': 'preview-style-property__range-container',
                    'range': 'preview-style-property__range',
                    'range-value': 'preview-style-property__range-value',
                    'container-length': 'preview-style-property__length-container',
                    'number': 'preview-style-property__length-input',
                    'unit': 'preview-style-property__unit-select',
                    'select': 'preview-style-property__enum-select',
                    'input': 'preview-style-property__input',
                    'color-swatch': 'preview-style-property__color'
                };
                return legacyMap[type] || `${this.classPrefix}__${type}`;
            }
            // New BEM-style class names
            return `${this.classPrefix}__${type}`;
        }
        
        _render() {
            this.container.innerHTML = '';
            this.colorSwatchEl = null;
            this.container.className = this.useLegacyClasses ? '' : this.classPrefix;
            
            const propType = KEYFRAME_PROPERTY_TYPES[this.property] || { type: 'text' };
            
            switch (propType.type) {
                case 'range':
                    this._renderRange(propType);
                    break;
                case 'length':
                    this._renderLength(propType);
                    break;
                case 'enum':
                    this._renderEnum(propType);
                    break;
                case 'number':
                    this._renderNumber(propType);
                    break;
                case 'color':
                    this._renderText(); // Color swatch handled externally
                    break;
                case 'angle':
                    this._renderLength(propType); // Same as length but with angle units
                    break;
                default:
                    this._renderText();
            }
        }
        
        _renderRange(propType) {
            if (this.useLegacyClasses) {
                this.container.className = this._getClass('container-range');
            } else {
                this.container.classList.add(`${this.classPrefix}--range`);
            }
            
            const range = document.createElement('input');
            range.type = 'range';
            range.className = this._getClass('range');
            range.min = propType.min ?? 0;
            range.max = propType.max ?? 1;
            range.step = propType.step ?? 0.01;
            range.value = parseFloat(this.currentValue) || propType.min || 0;
            
            const valueDisplay = document.createElement('span');
            valueDisplay.className = this._getClass('range-value');
            valueDisplay.textContent = range.value;
            
            range.addEventListener('input', () => {
                this.currentValue = range.value;
                valueDisplay.textContent = range.value;
                this.onChange(range.value);
            });
            
            range.addEventListener('blur', () => this.onBlur(this.currentValue));
            
            this.inputEl = range;
            this.container.appendChild(range);
            this.container.appendChild(valueDisplay);
        }
        
        _renderLength(propType) {
            if (this.useLegacyClasses) {
                this.container.className = this._getClass('container-length');
            } else {
                this.container.classList.add(`${this.classPrefix}--length`);
            }
            
            // Parse current value
            const parsed = this._parseLength(this.currentValue, propType);
            
            const numInput = document.createElement('input');
            numInput.type = 'text';
            numInput.className = this._getClass('number');
            numInput.value = parsed.num;
            numInput.placeholder = '0';
            
            const unitSelect = document.createElement('select');
            unitSelect.className = this._getClass('unit');
            for (const unit of (propType.units || ['px', '%', 'em', 'rem'])) {
                const opt = document.createElement('option');
                opt.value = unit;
                opt.textContent = unit || '—';
                if (unit === parsed.unit) opt.selected = true;
                unitSelect.appendChild(opt);
            }
            
            const emitChange = () => {
                const num = numInput.value.trim();
                const unit = unitSelect.value;
                // Handle special values like 'auto', 'none', 'normal'
                if (unit === 'auto' || unit === 'none' || unit === 'normal') {
                    this.currentValue = unit;
                } else {
                    this.currentValue = num + unit;
                }
                this.onChange(this.currentValue);
            };
            
            numInput.addEventListener('input', emitChange);
            numInput.addEventListener('blur', () => this.onBlur(this.currentValue));
            unitSelect.addEventListener('change', emitChange);
            
            this.inputEl = numInput;
            this.unitSelectEl = unitSelect;
            this.container.appendChild(numInput);
            this.container.appendChild(unitSelect);
        }
        
        _renderEnum(propType) {
            if (!this.useLegacyClasses) {
                this.container.classList.add(`${this.classPrefix}--enum`);
            }
            
            const select = document.createElement('select');
            select.className = this._getClass('select');
            
            for (const val of (propType.values || [])) {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                if (val === this.currentValue) opt.selected = true;
                select.appendChild(opt);
            }
            
            select.addEventListener('change', () => {
                this.currentValue = select.value;
                this.onChange(this.currentValue);
                this.onBlur(this.currentValue);
            });
            
            this.inputEl = select;
            this.container.appendChild(select);
        }
        
        _renderNumber(propType) {
            if (!this.useLegacyClasses) {
                this.container.classList.add(`${this.classPrefix}--number`);
            }
            
            const input = document.createElement('input');
            input.type = 'number';
            input.className = this._getClass('input');
            input.value = parseFloat(this.currentValue) || 0;
            input.step = propType.step ?? 1;
            if (propType.min !== undefined) input.min = propType.min;
            if (propType.max !== undefined) input.max = propType.max;
            
            input.addEventListener('input', () => {
                this.currentValue = input.value;
                this.onChange(this.currentValue);
            });
            
            input.addEventListener('blur', () => this.onBlur(this.currentValue));
            
            this.inputEl = input;
            this.container.appendChild(input);
        }
        
        _renderText() {
            if (!this.useLegacyClasses) {
                this.container.classList.add(`${this.classPrefix}--text`);
            }
            
            // Add color swatch for color properties
            const isColor = this._isColorProperty();
            if (isColor && this.showColorSwatch) {
                this._renderColorSwatch();
            }
            
            const input = document.createElement('input');
            input.type = 'text';
            input.className = this._getClass('input');
            input.value = this.currentValue;
            input.placeholder = isColor ? '#ffffff' : '';
            
            input.addEventListener('input', () => {
                this.currentValue = input.value;
                this.onChange(this.currentValue);
                // Update color swatch
                if (this.colorSwatchEl) {
                    this.colorSwatchEl.style.background = this.currentValue || '#ffffff';
                }
            });
            
            input.addEventListener('blur', () => this.onBlur(this.currentValue));
            
            this.inputEl = input;
            this.container.appendChild(input);
        }
        
        /**
         * Render color swatch button
         */
        _renderColorSwatch() {
            const swatch = document.createElement('button');
            swatch.type = 'button';
            swatch.className = this._getClass('color-swatch');
            swatch.style.background = this.currentValue || '#ffffff';
            swatch.title = <?= json_encode(__admin('preview.clickToPickColor') ?? 'Click to pick color') ?>;
            
            swatch.addEventListener('click', () => {
                if (this.onColorPick) {
                    this.onColorPick(this.property, this.currentValue, swatch);
                }
            });
            
            this.colorSwatchEl = swatch;
            this.container.appendChild(swatch);
        }
        
        _parseLength(value, propType) {
            if (!value || value === 'auto' || value === 'none' || value === 'normal') {
                return { num: '', unit: value || (propType.units?.[0] || 'px') };
            }
            const match = String(value).match(/^(-?[\d.]+)(.*)$/);
            if (match) {
                const unit = match[2].trim() || propType.units?.[0] || 'px';
                return { num: match[1], unit };
            }
            return { num: '', unit: propType.units?.[0] || 'px' };
        }
        
        /**
         * Set property and re-render input
         * @param {string} property - CSS property name
         */
        setProperty(property) {
            this.property = property;
            this._render();
        }
        
        /**
         * Set value
         * @param {string} value - CSS value
         */
        setValue(value) {
            this.currentValue = value;
            
            // Update input element based on type
            const propType = KEYFRAME_PROPERTY_TYPES[this.property] || { type: 'text' };
            
            if (propType.type === 'range') {
                if (this.inputEl) this.inputEl.value = parseFloat(value) || 0;
                const displayClass = this._getClass('range-value');
                const display = this.container.querySelector(`.${displayClass}`);
                if (display) display.textContent = value;
            } else if (propType.type === 'length' || propType.type === 'angle') {
                const parsed = this._parseLength(value, propType);
                if (this.inputEl) this.inputEl.value = parsed.num;
                if (this.unitSelectEl) this.unitSelectEl.value = parsed.unit;
            } else if (this.inputEl) {
                this.inputEl.value = value;
            }
            
            // Update color swatch if present
            if (this.colorSwatchEl) {
                this.colorSwatchEl.style.background = value || '#ffffff';
            }
        }
        
        /**
         * Get current value
         * @returns {string}
         */
        getValue() {
            return this.currentValue;
        }
        
        /**
         * Focus the input
         */
        focus() {
            if (this.inputEl) this.inputEl.focus();
        }
        
        /**
         * Destroy and cleanup
         */
        destroy() {
            this.container.innerHTML = '';
        }
    }
    
    /**
     * Get the input type configuration for a CSS property
     * @param {string} propertyName - CSS property name
     * @returns {object} Configuration object with type and settings
     */
    function getPropertyInputType(propertyName) {
        const prop = propertyName.toLowerCase().trim();
        return KEYFRAME_PROPERTY_TYPES[prop] || { type: 'text' };
    }
    
    /**
     * Parse a CSS length value into number and unit parts
     * @param {string} value - CSS value like "100px", "50%", "1.5em"
     * @returns {object} { num: number, unit: string }
     */
    function parseLength(value) {
        if (!value || value === 'auto' || value === 'none' || value === 'normal') {
            return { num: '', unit: value || '' };
        }
        const match = String(value).match(/^(-?[\d.]+)(.*)$/);
        if (match) {
            return { num: parseFloat(match[1]), unit: match[2].trim() || 'px' };
        }
        return { num: '', unit: value };
    }
    
    /**
     * Parse a CSS angle value into number and unit parts
     * @param {string} value - CSS value like "45deg", "1.5rad"
     * @returns {object} { num: number, unit: string }
     */
    function parseAngle(value) {
        if (!value) return { num: 0, unit: 'deg' };
        const match = String(value).match(/^(-?[\d.]+)(.*)$/);
        if (match) {
            return { num: parseFloat(match[1]), unit: match[2].trim() || 'deg' };
        }
        return { num: 0, unit: 'deg' };
    }
    
    /**
     * Render a property-specific input based on the property type
     * @param {string} property - CSS property name
     * @param {string} value - Current value
     * @param {number} frameIndex - Frame index
     * @param {number} propIndex - Property index
     * @returns {string} HTML string for the input
     */
    function renderPropertyValueInput(property, value, frameIndex, propIndex) {
        const config = getPropertyInputType(property);
        const dataAttrs = `data-frame="${frameIndex}" data-prop="${propIndex}" data-field="value"`;
        
        switch (config.type) {
            case 'range':
                const rangeVal = parseFloat(value) || config.min || 0;
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--range">
                        <input type="range" class="preview-keyframe-modal__property-range" 
                               min="${config.min}" max="${config.max}" step="${config.step}"
                               value="${rangeVal}" ${dataAttrs}>
                        <span class="preview-keyframe-modal__property-range-value">${rangeVal}</span>
                    </div>`;
            
            case 'color':
                const colorVal = escapeHTML(value || '#000000');
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--color">
                        <button type="button" class="preview-keyframe-modal__color-picker-btn" 
                                style="background: ${colorVal};"
                                title="<?= __admin('preview.clickToPickColor') ?? 'Click to pick color' ?>"></button>
                        <input type="text" class="preview-keyframe-modal__property-value preview-keyframe-modal__property-value--color" 
                               value="${colorVal}" ${dataAttrs}
                               placeholder="<?= __admin('preview.colorValue') ?? '#000000 or rgba(...)' ?>">
                    </div>`;
            
            case 'length':
                const lengthParsed = parseLength(value);
                const unitOptions = config.units.map(u => 
                    `<option value="${u}" ${lengthParsed.unit === u ? 'selected' : ''}>${u || '(none)'}</option>`
                ).join('');
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--length">
                        <input type="number" class="preview-keyframe-modal__property-number" 
                               value="${lengthParsed.num}" step="any" ${dataAttrs}>
                        <select class="preview-keyframe-modal__property-unit" 
                                data-frame="${frameIndex}" data-prop="${propIndex}" data-field="unit">
                            ${unitOptions}
                        </select>
                    </div>`;
            
            case 'angle':
                const angleParsed = parseAngle(value);
                const angleUnitOptions = config.units.map(u => 
                    `<option value="${u}" ${angleParsed.unit === u ? 'selected' : ''}>${u}</option>`
                ).join('');
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--angle">
                        <input type="number" class="preview-keyframe-modal__property-number" 
                               value="${angleParsed.num}" step="any" ${dataAttrs}>
                        <select class="preview-keyframe-modal__property-unit" 
                                data-frame="${frameIndex}" data-prop="${propIndex}" data-field="unit">
                            ${angleUnitOptions}
                        </select>
                    </div>`;
            
            case 'enum':
                const enumOptions = config.values.map(v => 
                    `<option value="${v}" ${value === v ? 'selected' : ''}>${v}</option>`
                ).join('');
                return `
                    <select class="preview-keyframe-modal__property-enum" ${dataAttrs}>
                        ${enumOptions}
                    </select>`;
            
            case 'number':
                const numVal = value !== '' ? parseFloat(value) : '';
                return `
                    <input type="number" class="preview-keyframe-modal__property-value preview-keyframe-modal__property-value--number" 
                           value="${numVal}" step="${config.step || 1}" 
                           ${config.min !== undefined ? `min="${config.min}"` : ''} ${dataAttrs}>`;
            
            case 'transform':
                // Transform editor - text input + Edit button
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--transform">
                        <input type="text" class="preview-keyframe-modal__property-value preview-keyframe-modal__property-value--transform" 
                               value="${escapeHTML(value || 'none')}" 
                               placeholder="<?= __admin('preview.transformValue') ?? 'translateX(10px) rotate(5deg)' ?>" ${dataAttrs}>
                        <button type="button" class="preview-keyframe-modal__transform-edit-btn admin-btn admin-btn--xs admin-btn--secondary"
                                data-frame="${frameIndex}" data-prop="${propIndex}"
                                title="<?= __admin('preview.openTransformEditor') ?? 'Open Transform Editor' ?>">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            <?= __admin('preview.edit') ?? 'Edit' ?>
                        </button>
                    </div>`;
            
            default: // text fallback
                return `
                    <input type="text" class="preview-keyframe-modal__property-value" 
                           value="${escapeHTML(value)}" 
                           placeholder="<?= __admin('preview.keyframePropertyValue') ?? 'value' ?>" ${dataAttrs}>`;
        }
    }
    
    /**
     * Attach event handlers for property value inputs based on their type
     * @param {HTMLElement} propEl - The property row element
     * @param {number} frameIndex - Frame index
     * @param {number} propIndex - Property index
     * @param {string} property - CSS property name
     */
    function attachPropertyValueHandlers(propEl, frameIndex, propIndex, property) {
        const config = getPropertyInputType(property);
        
        switch (config.type) {
            case 'range':
                // Range slider with live value display
                const rangeInput = propEl.querySelector('.preview-keyframe-modal__property-range');
                const rangeValue = propEl.querySelector('.preview-keyframe-modal__property-range-value');
                if (rangeInput) {
                    rangeInput.addEventListener('input', (e) => {
                        const val = e.target.value;
                        rangeValue.textContent = val;
                        keyframeFrames[frameIndex].properties[propIndex].value = val;
                    });
                }
                break;
            
            case 'color':
                // Color picker button + text input
                const colorInput = propEl.querySelector('.preview-keyframe-modal__property-value--color');
                const colorBtn = propEl.querySelector('.preview-keyframe-modal__color-picker-btn');
                if (colorInput) {
                    // Manual input change
                    colorInput.addEventListener('input', (e) => {
                        const val = e.target.value;
                        keyframeFrames[frameIndex].properties[propIndex].value = val;
                        if (colorBtn) {
                            colorBtn.style.background = val;
                        }
                    });
                    
                    // Initialize QSColorPicker attached to the input
                    if (typeof QSColorPicker !== 'undefined') {
                        const picker = new QSColorPicker(colorInput, {
                            showAlpha: true,
                            onChange: (color) => {
                                keyframeFrames[frameIndex].properties[propIndex].value = color;
                                if (colorBtn) {
                                    colorBtn.style.background = color;
                                }
                            }
                        });
                        
                        // Also open picker when button is clicked
                        if (colorBtn) {
                            colorBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                picker.open();
                            });
                        }
                    } else if (colorBtn) {
                        // Fallback: focus the text input when button clicked
                        colorBtn.addEventListener('click', () => {
                            colorInput.focus();
                            colorInput.select();
                        });
                    }
                }
                break;
            
            case 'length':
            case 'angle':
                // Number input + unit dropdown
                const numInput = propEl.querySelector('.preview-keyframe-modal__property-number');
                const unitSelect = propEl.querySelector('.preview-keyframe-modal__property-unit');
                if (numInput && unitSelect) {
                    const updateValue = () => {
                        const num = numInput.value;
                        const unit = unitSelect.value;
                        // Handle special units like 'auto', 'none', 'normal'
                        let combinedValue;
                        if (unit === 'auto' || unit === 'none' || unit === 'normal') {
                            combinedValue = unit;
                        } else if (num === '' || num === null) {
                            combinedValue = '';
                        } else {
                            combinedValue = num + unit;
                        }
                        keyframeFrames[frameIndex].properties[propIndex].value = combinedValue;
                    };
                    
                    numInput.addEventListener('input', updateValue);
                    unitSelect.addEventListener('change', () => {
                        // If selecting 'auto', 'none', or 'normal', clear the number
                        const unit = unitSelect.value;
                        if (unit === 'auto' || unit === 'none' || unit === 'normal') {
                            numInput.value = '';
                            numInput.disabled = true;
                        } else {
                            numInput.disabled = false;
                        }
                        updateValue();
                    });
                    
                    // Initial state check for special units
                    if (['auto', 'none', 'normal'].includes(unitSelect.value)) {
                        numInput.disabled = true;
                    }
                }
                break;
            
            case 'enum':
                // Enum dropdown
                const enumSelect = propEl.querySelector('.preview-keyframe-modal__property-enum');
                if (enumSelect) {
                    enumSelect.addEventListener('change', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                break;
            
            case 'number':
                // Unitless number input
                const plainNumInput = propEl.querySelector('.preview-keyframe-modal__property-value--number');
                if (plainNumInput) {
                    plainNumInput.addEventListener('input', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                break;
            
            case 'transform':
                // Transform editor - text input + Edit button
                const transformTextInput = propEl.querySelector('.preview-keyframe-modal__property-value--transform');
                const transformEditBtn = propEl.querySelector('.preview-keyframe-modal__transform-edit-btn');
                
                // Text input for manual editing
                if (transformTextInput) {
                    transformTextInput.addEventListener('input', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                
                // Edit button to open Transform Editor modal
                if (transformEditBtn) {
                    transformEditBtn.addEventListener('click', () => {
                        const currentValue = keyframeFrames[frameIndex].properties[propIndex].value || '';
                        
                        // Live preview target is optional - we don't have direct access
                        // to the selected element from the keyframe modal context
                        openTransformEditor(currentValue, (newValue) => {
                            // Update the stored value
                            keyframeFrames[frameIndex].properties[propIndex].value = newValue;
                            // Update the text input
                            if (transformTextInput) {
                                transformTextInput.value = newValue;
                            }
                        }, null);
                    });
                }
                break;
            
            default:
                // Text input (fallback)
                const textInput = propEl.querySelector('.preview-keyframe-modal__property-value');
                if (textInput) {
                    textInput.addEventListener('input', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                break;
        }
    }
    
    // ==================== Transform Sub-Editor (Phase 9.3.1 Step 5) ====================
    
    /**
     * Transform function definitions
     * Maps function names to their parameter configs
     */
    const TRANSFORM_FUNCTIONS = {
        // Translation functions
        translateX: { params: ['x'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        translateY: { params: ['y'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        translateZ: { params: ['z'], units: ['px', 'em', 'rem'], category: 'translate' },
        translate: { params: ['x', 'y'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        translate3d: { params: ['x', 'y', 'z'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        
        // Rotation functions
        rotate: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotateX: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotateY: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotateZ: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotate3d: { params: ['x', 'y', 'z', 'angle'], units: ['deg'], category: 'rotate', special: true },
        
        // Scale functions
        scale: { params: ['x', 'y'], units: [], category: 'scale', unitless: true },
        scaleX: { params: ['x'], units: [], category: 'scale', unitless: true },
        scaleY: { params: ['y'], units: [], category: 'scale', unitless: true },
        scaleZ: { params: ['z'], units: [], category: 'scale', unitless: true },
        scale3d: { params: ['x', 'y', 'z'], units: [], category: 'scale', unitless: true },
        
        // Skew functions
        skew: { params: ['x', 'y'], units: ['deg', 'rad', 'turn'], category: 'skew' },
        skewX: { params: ['x'], units: ['deg', 'rad', 'turn'], category: 'skew' },
        skewY: { params: ['y'], units: ['deg', 'rad', 'turn'], category: 'skew' },
        
        // Other
        perspective: { params: ['d'], units: ['px'], category: 'other' }
    };
    
    /**
     * Parse a CSS transform string into an array of function objects
     * @param {string} transformStr - e.g., "translateY(-10px) rotate(5deg) scale(1.1)"
     * @returns {Array} Array of { fn: 'translateY', args: [{ num: -10, unit: 'px' }] }
     */
    function parseTransformString(transformStr) {
        if (!transformStr || transformStr === 'none') return [];
        
        const functions = [];
        // Match function calls: name(args)
        const regex = /(\w+)\(([^)]+)\)/g;
        let match;
        
        while ((match = regex.exec(transformStr)) !== null) {
            const fnName = match[1];
            const argsStr = match[2];
            const config = TRANSFORM_FUNCTIONS[fnName];
            
            if (!config) continue; // Unknown function, skip
            
            // Parse arguments (comma or space separated)
            const argParts = argsStr.split(/[,\s]+/).filter(a => a.trim());
            const args = [];
            
            for (let i = 0; i < argParts.length; i++) {
                const argStr = argParts[i].trim();
                
                if (config.unitless) {
                    // Unitless number (scale)
                    args.push({ num: parseFloat(argStr) || 0, unit: '' });
                } else if (config.special && fnName === 'rotate3d' && i < 3) {
                    // rotate3d first 3 params are unitless vector
                    args.push({ num: parseFloat(argStr) || 0, unit: '' });
                } else {
                    // Parse number + unit
                    const numMatch = argStr.match(/^(-?[\d.]+)(.*)$/);
                    if (numMatch) {
                        args.push({ 
                            num: parseFloat(numMatch[1]) || 0, 
                            unit: numMatch[2].trim() || config.units[0] || ''
                        });
                    } else {
                        args.push({ num: 0, unit: config.units[0] || '' });
                    }
                }
            }
            
            // Fill missing args with defaults
            while (args.length < config.params.length) {
                const defaultUnit = config.unitless ? '' : (config.units[0] || '');
                args.push({ num: 0, unit: defaultUnit });
            }
            
            functions.push({ fn: fnName, args });
        }
        
        return functions;
    }
    
    /**
     * Serialize transform functions array back to CSS string
     * @param {Array} functions - Array of { fn, args }
     * @returns {string} CSS transform string
     */
    function serializeTransform(functions) {
        if (!functions || functions.length === 0) return 'none';
        
        return functions.map(({ fn, args }) => {
            const config = TRANSFORM_FUNCTIONS[fn];
            const argStrs = args.map((arg, i) => {
                if (config.unitless || (config.special && fn === 'rotate3d' && i < 3)) {
                    return String(arg.num);
                }
                return `${arg.num}${arg.unit}`;
            });
            return `${fn}(${argStrs.join(', ')})`;
        }).join(' ');
    }
    
    // Transform Editor state
    let transformEditorOpen = false;
    let transformEditorCallback = null;  // Called with final value when Apply clicked
    let transformFunctions = [];          // Current transform functions being edited
    let transformEditorTarget = null;     // Element to preview on
    
    /**
     * Open the Transform Editor modal
     * @param {string} initialValue - Current transform CSS value
     * @param {function} onApply - Callback with new transform value
     * @param {HTMLElement} previewTarget - Element in iframe to preview on
     */
    function openTransformEditor(initialValue, onApply, previewTarget) {
        transformEditorCallback = onApply;
        transformEditorTarget = previewTarget;
        transformFunctions = parseTransformString(initialValue);
        transformEditorOpen = true;
        
        renderTransformEditor();
        document.getElementById('transform-editor-modal').classList.add('preview-keyframe-modal--visible');
    }
    
    /**
     * Close the Transform Editor modal
     * @param {boolean} apply - If true, call callback with current value
     */
    function closeTransformEditor(apply = false) {
        if (apply && transformEditorCallback) {
            const value = serializeTransform(transformFunctions);
            transformEditorCallback(value);
        }
        
        // Remove preview
        if (transformEditorTarget) {
            transformEditorTarget.style.transform = '';
        }
        
        transformEditorOpen = false;
        transformEditorCallback = null;
        transformEditorTarget = null;
        transformFunctions = [];
        
        document.getElementById('transform-editor-modal').classList.remove('preview-keyframe-modal--visible');
    }
    
    /**
     * Render the Transform Editor UI
     */
    function renderTransformEditor() {
        const currentValue = serializeTransform(transformFunctions);
        const currentDisplay = document.getElementById('transform-current-value');
        const functionsContainer = document.getElementById('transform-functions-list');
        
        if (currentDisplay) {
            currentDisplay.textContent = currentValue || 'none';
        }
        
        if (!functionsContainer) return;
        functionsContainer.innerHTML = '';
        
        if (transformFunctions.length === 0) {
            functionsContainer.innerHTML = `
                <div class="transform-editor__empty">
                    <?= __admin('preview.transformEmpty') ?? 'No transform functions. Click "Add Function" to start.' ?>
                </div>`;
            return;
        }
        
        transformFunctions.forEach((func, index) => {
            const config = TRANSFORM_FUNCTIONS[func.fn];
            if (!config) return;
            
            const row = document.createElement('div');
            row.className = 'transform-editor__function-row';
            row.dataset.index = index;
            
            // Build input fields based on params
            let inputsHTML = '';
            func.args.forEach((arg, argIndex) => {
                const paramName = config.params[argIndex] || '';
                
                if (config.unitless || (config.special && func.fn === 'rotate3d' && argIndex < 3)) {
                    // Unitless number input
                    inputsHTML += `
                        <div class="transform-editor__param">
                            <label>${paramName}</label>
                            <input type="number" step="any" value="${arg.num}" 
                                   data-func="${index}" data-arg="${argIndex}" class="transform-editor__input">
                        </div>`;
                } else {
                    // Number + unit dropdown
                    const unitOptions = config.units.map(u => 
                        `<option value="${u}" ${arg.unit === u ? 'selected' : ''}>${u}</option>`
                    ).join('');
                    
                    inputsHTML += `
                        <div class="transform-editor__param">
                            <label>${paramName}</label>
                            <div class="transform-editor__input-group">
                                <input type="number" step="any" value="${arg.num}" 
                                       data-func="${index}" data-arg="${argIndex}" class="transform-editor__input">
                                <select data-func="${index}" data-arg="${argIndex}" class="transform-editor__unit">
                                    ${unitOptions}
                                </select>
                            </div>
                        </div>`;
                }
            });
            
            row.innerHTML = `
                <div class="transform-editor__drag-handle" title="<?= __admin('preview.dragToReorder') ?? 'Drag to reorder' ?>">⋮⋮</div>
                <div class="transform-editor__function-name">${func.fn}</div>
                <div class="transform-editor__params">${inputsHTML}</div>
                <button type="button" class="transform-editor__delete" title="<?= __admin('preview.removeFunction') ?? 'Remove function' ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            `;
            
            // Event: Input change
            row.querySelectorAll('.transform-editor__input').forEach(input => {
                input.addEventListener('input', (e) => {
                    const funcIdx = parseInt(e.target.dataset.func);
                    const argIdx = parseInt(e.target.dataset.arg);
                    transformFunctions[funcIdx].args[argIdx].num = parseFloat(e.target.value) || 0;
                    updateTransformPreview();
                    updateTransformCurrentDisplay();
                });
            });
            
            // Event: Unit change
            row.querySelectorAll('.transform-editor__unit').forEach(select => {
                select.addEventListener('change', (e) => {
                    const funcIdx = parseInt(e.target.dataset.func);
                    const argIdx = parseInt(e.target.dataset.arg);
                    transformFunctions[funcIdx].args[argIdx].unit = e.target.value;
                    updateTransformPreview();
                    updateTransformCurrentDisplay();
                });
            });
            
            // Event: Delete function
            row.querySelector('.transform-editor__delete').addEventListener('click', () => {
                transformFunctions.splice(index, 1);
                renderTransformEditor();
                updateTransformPreview();
            });
            
            functionsContainer.appendChild(row);
        });
        
        // Make rows draggable for reordering
        setupTransformDragReorder(functionsContainer);
    }
    
    /**
     * Update the current value display
     */
    function updateTransformCurrentDisplay() {
        const display = document.getElementById('transform-current-value');
        if (display) {
            display.textContent = serializeTransform(transformFunctions) || 'none';
        }
    }
    
    /**
     * Apply live preview to target element
     */
    function updateTransformPreview() {
        if (transformEditorTarget) {
            transformEditorTarget.style.transform = serializeTransform(transformFunctions);
        }
    }
    
    /**
     * Setup drag-and-drop reordering for function rows
     */
    function setupTransformDragReorder(container) {
        let draggedEl = null;
        let draggedIndex = -1;
        
        container.querySelectorAll('.transform-editor__function-row').forEach(row => {
            const handle = row.querySelector('.transform-editor__drag-handle');
            
            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                draggedEl = row;
                draggedIndex = parseInt(row.dataset.index);
                row.classList.add('transform-editor__function-row--dragging');
                
                const onMouseMove = (e) => {
                    const rows = Array.from(container.querySelectorAll('.transform-editor__function-row'));
                    const y = e.clientY;
                    
                    rows.forEach((r, idx) => {
                        if (r === draggedEl) return;
                        const rect = r.getBoundingClientRect();
                        const midY = rect.top + rect.height / 2;
                        
                        if (y < midY && idx < draggedIndex) {
                            container.insertBefore(draggedEl, r);
                            draggedIndex = idx;
                        } else if (y > midY && idx > draggedIndex) {
                            container.insertBefore(draggedEl, r.nextSibling);
                            draggedIndex = idx;
                        }
                    });
                };
                
                const onMouseUp = () => {
                    row.classList.remove('transform-editor__function-row--dragging');
                    
                    // Reorder transformFunctions array based on DOM order
                    const newOrder = [];
                    container.querySelectorAll('.transform-editor__function-row').forEach(r => {
                        const oldIdx = parseInt(r.dataset.index);
                        newOrder.push(transformFunctions[oldIdx]);
                    });
                    transformFunctions = newOrder;
                    
                    // Re-render to update indices
                    renderTransformEditor();
                    updateTransformPreview();
                    updateTransformCurrentDisplay();
                    
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                };
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        });
    }
    
    /**
     * Add a new transform function
     * @param {string} fnName - Function name (e.g., 'translateX', 'rotate')
     */
    function addTransformFunction(fnName) {
        const config = TRANSFORM_FUNCTIONS[fnName];
        if (!config) return;
        
        // Create default args
        const args = config.params.map((param, i) => {
            if (config.unitless || (config.special && fnName === 'rotate3d' && i < 3)) {
                return { num: fnName.startsWith('scale') ? 1 : 0, unit: '' };
            }
            return { num: 0, unit: config.units[0] || '' };
        });
        
        transformFunctions.push({ fn: fnName, args });
        renderTransformEditor();
        updateTransformPreview();
        
        // Close dropdown
        document.getElementById('transform-add-dropdown').classList.remove('transform-editor__dropdown--open');
    }
    
    /**
     * Toggle add function dropdown
     */
    function toggleTransformDropdown() {
        const dropdown = document.getElementById('transform-add-dropdown');
        dropdown.classList.toggle('transform-editor__dropdown--open');
    }
    
    // ==================== Transition Editor ====================
    
    // Transition Editor state
    let transitionEditorSelector = null;
    let transitionEditorBaseProperties = {};
    let transitionEditorHoverProperties = {};
    let transitionEditorCurrentPseudo = ':hover';
    let transitionEditorCallback = null;
    let transitionEditorBaseAnimation = null;    // { name, duration, iterations, infinite }
    let transitionEditorTriggerAnimation = null; // { name, duration, iterations, infinite }
    
    // Transition Editor DOM references
    const transitionEditorModal = document.getElementById('transition-editor-modal');
    const transitionEditorSelectorEl = document.getElementById('transition-editor-selector');
    const transitionBaseSelectorEl = document.getElementById('transition-base-selector');
    const transitionBasePropsEl = document.getElementById('transition-base-properties');
    const transitionHoverPropsEl = document.getElementById('transition-hover-properties');
    const transitionPseudoSelect = document.getElementById('transition-pseudo-select');
    const transitionPropertySelect = document.getElementById('transition-property-select');
    const transitionSpecificProps = document.getElementById('transition-specific-props');
    const transitionDuration = document.getElementById('transition-duration');
    const transitionDurationUnit = document.getElementById('transition-duration-unit');
    const transitionDelay = document.getElementById('transition-delay');
    const transitionDelayUnit = document.getElementById('transition-delay-unit');
    const transitionTimingSelect = document.getElementById('transition-timing-select');
    const transitionCubicBezier = document.getElementById('transition-cubic-bezier');
    const transitionPreviewCode = document.getElementById('transition-preview-code');
    const transitionTriggerLabel = document.getElementById('transition-trigger-label');
    const transitionAddHoverText = document.getElementById('transition-add-hover-text');
    
    // Inline property editor DOM references (Phase 10.2)
    const transitionBaseAddToggle = document.getElementById('transition-base-add-toggle');
    const transitionBaseAddForm = document.getElementById('transition-base-add-form');
    const transitionBasePropSelectorContainer = document.getElementById('transition-base-prop-selector');
    const transitionBaseValueContainer = document.getElementById('transition-base-value-container');
    const transitionBaseAddConfirm = document.getElementById('transition-base-add-confirm');
    const transitionBaseAddCancel = document.getElementById('transition-base-add-cancel');
    const transitionTriggerAddToggle = document.getElementById('transition-trigger-add-toggle');
    const transitionTriggerAddForm = document.getElementById('transition-trigger-add-form');
    const transitionTriggerPropSelectorContainer = document.getElementById('transition-trigger-prop-selector');
    const transitionTriggerValueContainer = document.getElementById('transition-trigger-value-container');
    const transitionTriggerAddConfirm = document.getElementById('transition-trigger-add-confirm');
    const transitionTriggerAddCancel = document.getElementById('transition-trigger-add-cancel');
    
    // QSPropertySelector and QSValueInput instances (Phase 10.3 - unified components)
    let transitionBasePropSelector = null;
    let transitionTriggerPropSelector = null;
    let transitionBaseValueInput = null;
    let transitionTriggerValueInput = null;
    
    // Animation section DOM references (Phase 10.3)
    const transitionBaseAnimEmpty = document.getElementById('transition-base-animation-empty');
    const transitionBaseAnimConfig = document.getElementById('transition-base-animation-config');
    const transitionBaseAnimName = document.getElementById('transition-base-animation-name');
    const transitionBaseAnimDuration = document.getElementById('transition-base-anim-duration');
    const transitionBaseAnimIterations = document.getElementById('transition-base-anim-iterations');
    const transitionBaseAnimInfinite = document.getElementById('transition-base-anim-infinite');
    const transitionBaseAnimAddBtn = document.getElementById('transition-base-animation-add');
    const transitionBaseAnimPreviewBtn = document.getElementById('transition-base-animation-preview-btn');
    const transitionBaseAnimRemoveBtn = document.getElementById('transition-base-animation-remove');
    const transitionTriggerAnimEmpty = document.getElementById('transition-trigger-animation-empty');
    const transitionTriggerAnimConfig = document.getElementById('transition-trigger-animation-config');
    const transitionTriggerAnimName = document.getElementById('transition-trigger-animation-name');
    const transitionTriggerAnimDuration = document.getElementById('transition-trigger-anim-duration');
    const transitionTriggerAnimIterations = document.getElementById('transition-trigger-anim-iterations');
    const transitionTriggerAnimInfinite = document.getElementById('transition-trigger-anim-infinite');
    const transitionTriggerAnimAddBtn = document.getElementById('transition-trigger-animation-add');
    const transitionTriggerAnimPreviewBtn = document.getElementById('transition-trigger-animation-preview-btn');
    const transitionTriggerAnimRemoveBtn = document.getElementById('transition-trigger-animation-remove');
    const transitionTriggerAnimHint = document.getElementById('transition-trigger-animation-hint');
    
    // Animation Preview Modal DOM references (Phase 9.5)
    const animationPreviewModal = document.getElementById('animation-preview-modal');
    const animationPreviewName = document.getElementById('animation-preview-name');
    const animationPreviewStage = document.getElementById('animation-preview-stage');
    const animationPreviewLogo = document.getElementById('animation-preview-logo');
    const animationPreviewDuration = document.getElementById('animation-preview-duration');
    const animationPreviewTiming = document.getElementById('animation-preview-timing');
    const animationPreviewDelay = document.getElementById('animation-preview-delay');
    const animationPreviewCount = document.getElementById('animation-preview-count');
    const animationPreviewInfinite = document.getElementById('animation-preview-infinite');
    const animationPreviewCss = document.getElementById('animation-preview-css');
    const animationPreviewPlayBtn = document.getElementById('animation-preview-play');
    const animationPreviewCloseBtn = document.getElementById('animation-preview-close');
    const animationPreviewDoneBtn = document.getElementById('animation-preview-done');
    let currentPreviewKeyframe = null;
    
    /**
     * Open the Transition Editor for a selector
     * @param {string} selector - Base CSS selector (without pseudo-class)
     * @param {function} onSave - Callback when transition is saved
     */
    async function openTransitionEditor(selector, onSave = null) {
        transitionEditorSelector = selector;
        transitionEditorCallback = onSave;
        
        // Update header
        if (transitionEditorSelectorEl) transitionEditorSelectorEl.textContent = selector;
        if (transitionBaseSelectorEl) transitionBaseSelectorEl.textContent = selector;
        
        // Reset state
        transitionEditorBaseProperties = {};
        transitionEditorHoverProperties = {};
        transitionEditorCurrentPseudo = transitionPseudoSelect?.value || ':hover';
        
        // Reset controls to defaults
        if (transitionDuration) transitionDuration.value = 0.3;
        if (transitionDelay) transitionDelay.value = 0;
        if (transitionTimingSelect) transitionTimingSelect.value = 'ease';
        if (transitionPropertySelect) transitionPropertySelect.value = 'all';
        if (transitionCubicBezier) transitionCubicBezier.style.display = 'none';
        if (transitionSpecificProps) transitionSpecificProps.style.display = 'none';
        
        // Update labels to match current pseudo-class
        updateTriggerStateLabels();
        
        // Show modal
        transitionEditorModal?.classList.add('preview-keyframe-modal--visible');
        
        // Load selector states
        await loadTransitionSelectorStates(selector);
        
        // Update preview
        updateTransitionPreviewCode();
    }
    
    /**
     * Load base and pseudo-state styles for the selector
     */
    async function loadTransitionSelectorStates(selector) {
        const pseudoClass = transitionEditorCurrentPseudo;
        const hoverSelector = selector + pseudoClass;
        
        try {
            // Fetch base and hover styles in parallel
            // Note: selector is passed as route parameter, not query string
            const [baseResponse, hoverResponse] = await Promise.all([
                fetch(managementUrl + 'getStyleRule/' + encodeURIComponent(selector), {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                }),
                fetch(managementUrl + 'getStyleRule/' + encodeURIComponent(hoverSelector), {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                })
            ]);
            
            const baseData = await baseResponse.json();
            const hoverData = await hoverResponse.json();
            
            // Extract properties - API returns styles as a string, need to parse it
            transitionEditorBaseProperties = {};
            transitionEditorHoverProperties = {};
            transitionEditorBaseAnimation = null;
            transitionEditorTriggerAnimation = null;
            
            if (baseData.status === 200 && baseData.data?.styles) {
                transitionEditorBaseProperties = parseStylesString(baseData.data.styles);
                
                // Check if there's already a transition defined
                if (transitionEditorBaseProperties.transition) {
                    parseExistingTransition(transitionEditorBaseProperties.transition);
                }
                
                // Check if there's already an animation defined
                if (transitionEditorBaseProperties.animation) {
                    transitionEditorBaseAnimation = parseAnimationValue(transitionEditorBaseProperties.animation);
                }
            }
            
            if (hoverData.status === 200 && hoverData.data?.styles) {
                transitionEditorHoverProperties = parseStylesString(hoverData.data.styles);
                
                // Check if there's an animation on the trigger state
                if (transitionEditorHoverProperties.animation) {
                    transitionEditorTriggerAnimation = parseAnimationValue(transitionEditorHoverProperties.animation);
                }
            }
            
            // Render property panels
            renderTransitionBaseProperties();
            renderTransitionHoverProperties();
            
            // Render animation sections
            renderAnimationSection('base');
            renderAnimationSection('trigger');
            
            // Re-initialize property selectors with updated exclusion lists
            initTransitionPropertySelectors();
            
        } catch (error) {
            console.error('Failed to load selector states:', error);
            showToast(<?= json_encode(__admin('preview.loadTransitionFailed') ?? 'Failed to load transition data') ?>, 'error');
        }
    }
    
    /**
     * Parse animation CSS value into structured object
     * @param {string} animationValue - CSS animation value like "1000ms ease 0s fadeIn" or "fadeIn 1s infinite"
     * @returns {object|null} - { name, duration, timing, delay, iterations, infinite }
     */
    function parseAnimationValue(animationValue) {
        if (!animationValue || animationValue === 'none') return null;
        
        // Animation shorthand: name duration timing-function delay iteration-count direction fill-mode play-state
        // Or: duration timing-function delay name (alternative order)
        const parts = animationValue.trim().split(/\s+/);
        
        let name = null;
        let duration = 1000;
        let iterations = 1;
        let infinite = false;
        
        for (const part of parts) {
            if (part === 'infinite') {
                infinite = true;
            } else if (/^\d+$/.test(part)) {
                // Plain number - could be iterations
                iterations = parseInt(part);
            } else if (/^\d+(\.\d+)?(ms|s)$/.test(part)) {
                // Duration
                duration = part.endsWith('ms') ? parseFloat(part) : parseFloat(part) * 1000;
            } else if (!['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'forwards', 'backwards', 'both', 'none', 'normal', 'reverse', 'alternate', 'alternate-reverse', 'running', 'paused'].includes(part) && !/^cubic-bezier/.test(part)) {
                // Probably the animation name
                name = part;
            }
        }
        
        // Also check for animation-name and animation-iteration-count properties
        if (!name && transitionEditorBaseProperties['animation-name']) {
            name = transitionEditorBaseProperties['animation-name'];
        }
        
        return name ? { name, duration, iterations, infinite } : null;
    }
    
    /**
     * Render animation section (base or trigger)
     * @param {string} target - 'base' or 'trigger'
     */
    function renderAnimationSection(target) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        const emptyEl = target === 'base' ? transitionBaseAnimEmpty : transitionTriggerAnimEmpty;
        const configEl = target === 'base' ? transitionBaseAnimConfig : transitionTriggerAnimConfig;
        const nameEl = target === 'base' ? transitionBaseAnimName : transitionTriggerAnimName;
        const durationEl = target === 'base' ? transitionBaseAnimDuration : transitionTriggerAnimDuration;
        const iterationsEl = target === 'base' ? transitionBaseAnimIterations : transitionTriggerAnimIterations;
        const infiniteEl = target === 'base' ? transitionBaseAnimInfinite : transitionTriggerAnimInfinite;
        const addBtn = target === 'base' ? transitionBaseAnimAddBtn : transitionTriggerAnimAddBtn;
        
        if (!animation) {
            // No animation set
            if (emptyEl) emptyEl.style.display = '';
            if (configEl) configEl.style.display = 'none';
            if (addBtn) addBtn.style.display = '';
        } else {
            // Animation is set
            if (emptyEl) emptyEl.style.display = 'none';
            if (configEl) configEl.style.display = '';
            if (addBtn) addBtn.style.display = 'none';
            
            if (nameEl) nameEl.textContent = `@keyframes ${animation.name}`;
            if (durationEl) durationEl.value = animation.duration;
            if (iterationsEl) iterationsEl.value = animation.iterations;
            if (infiniteEl) infiniteEl.checked = animation.infinite;
        }
        
        // Update trigger animation hint
        if (target === 'trigger' && transitionTriggerAnimHint) {
            const pseudo = transitionEditorCurrentPseudo || ':hover';
            transitionTriggerAnimHint.textContent = `(${<?= json_encode(__admin('preview.playsOn') ?? 'plays on') ?>} ${pseudo})`;
        }
    }
    
    /**
     * Parse existing transition value and populate controls
     */
    function parseExistingTransition(transitionValue) {
        // Simple parse: "all 0.3s ease" or "background-color 0.5s ease-in-out 0.1s"
        const parts = transitionValue.trim().split(/\s+/);
        
        if (parts.length >= 1) {
            // Property
            const prop = parts[0];
            if (transitionPropertySelect) {
                if (prop === 'all') {
                    transitionPropertySelect.value = 'all';
                } else {
                    // For now, treat as 'all' - specific properties need more complex parsing
                    transitionPropertySelect.value = 'all';
                }
            }
        }
        
        if (parts.length >= 2) {
            // Duration
            const dur = parseFloat(parts[1]);
            if (!isNaN(dur) && transitionDuration) {
                transitionDuration.value = dur;
            }
        }
        
        if (parts.length >= 3) {
            // Timing function
            const timing = parts[2];
            if (transitionTimingSelect) {
                const validTimings = ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out'];
                if (validTimings.includes(timing)) {
                    transitionTimingSelect.value = timing;
                } else if (timing.startsWith('cubic-bezier')) {
                    transitionTimingSelect.value = 'cubic-bezier';
                    // Parse cubic-bezier values
                    const match = timing.match(/cubic-bezier\(([^)]+)\)/);
                    if (match) {
                        const vals = match[1].split(',').map(v => parseFloat(v.trim()));
                        if (vals.length === 4) {
                            document.getElementById('transition-bezier-x1').value = vals[0];
                            document.getElementById('transition-bezier-y1').value = vals[1];
                            document.getElementById('transition-bezier-x2').value = vals[2];
                            document.getElementById('transition-bezier-y2').value = vals[3];
                        }
                    }
                    if (transitionCubicBezier) transitionCubicBezier.style.display = '';
                }
            }
        }
        
        if (parts.length >= 4) {
            // Delay
            const delay = parseFloat(parts[3]);
            if (!isNaN(delay) && transitionDelay) {
                transitionDelay.value = delay;
            }
        }
    }
    
    /**
     * Render base state properties panel
     */
    function renderTransitionBaseProperties() {
        if (!transitionBasePropsEl) return;
        
        const props = Object.entries(transitionEditorBaseProperties)
            .filter(([key]) => key !== 'transition' && key !== 'animation'); // Don't show transition/animation in the list
        
        if (props.length === 0) {
            transitionBasePropsEl.innerHTML = `
                <div class="transition-editor__empty"><?= __admin('preview.noBaseStyles') ?? 'No base styles defined' ?></div>
            `;
            return;
        }
        
        transitionBasePropsEl.innerHTML = props.map(([prop, value]) => `
            <div class="transition-editor__property" data-property="${escapeHtml(prop)}" data-target="base">
                <div class="transition-editor__property-info">
                    <span class="transition-editor__property-name">${escapeHtml(prop)}</span>
                    <span class="transition-editor__property-value" title="${escapeHtml(value)}">${escapeHtml(value)}</span>
                </div>
                <div class="transition-editor__property-actions">
                    <button type="button" class="transition-editor__property-btn transition-editor__property-btn--delete" 
                            data-action="delete" title="<?= __admin('common.delete') ?? 'Delete' ?>">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');
        
        // Add event listeners for delete buttons
        transitionBasePropsEl.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const propEl = e.target.closest('.transition-editor__property');
                const prop = propEl?.dataset.property;
                if (prop) deleteTransitionProperty('base', prop);
            });
        });
    }
    
    /**
     * Render trigger state properties panel
     */
    function renderTransitionHoverProperties() {
        if (!transitionHoverPropsEl) return;
        
        const props = Object.entries(transitionEditorHoverProperties);
        
        if (props.length === 0) {
            transitionHoverPropsEl.innerHTML = `
                <div class="transition-editor__empty"><?= __admin('preview.noTriggerStyles') ?? 'No trigger styles defined' ?></div>
            `;
            return;
        }
        
        transitionHoverPropsEl.innerHTML = props.map(([prop, value]) => `
            <div class="transition-editor__property" data-property="${escapeHtml(prop)}" data-target="trigger">
                <div class="transition-editor__property-info">
                    <span class="transition-editor__property-name">${escapeHtml(prop)}</span>
                    <span class="transition-editor__property-value" title="${escapeHtml(value)}">${escapeHtml(value)}</span>
                </div>
                <div class="transition-editor__property-actions">
                    <button type="button" class="transition-editor__property-btn transition-editor__property-btn--delete" 
                            data-action="delete" title="<?= __admin('common.delete') ?? 'Delete' ?>">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');
        
        // Add event listeners for delete buttons
        transitionHoverPropsEl.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const propEl = e.target.closest('.transition-editor__property');
                const prop = propEl?.dataset.property;
                if (prop) deleteTransitionProperty('trigger', prop);
            });
        });
        
        // Update specific properties checkboxes if needed
        updateSpecificPropertiesCheckboxes();
    }
    
    /**
     * Delete a property from base or trigger state
     * @param {string} target - 'base' or 'trigger'
     * @param {string} property - Property name to delete
     */
    async function deleteTransitionProperty(target, property) {
        const selector = target === 'base' 
            ? transitionEditorSelector 
            : transitionEditorSelector + transitionEditorCurrentPseudo;
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: '',  // Empty styles for merge
                    removeProperties: [property]  // Delete this property
                })
            });
            
            const result = await response.json();
            
            if (result.status === 200) {
                showToast(<?= json_encode(__admin('preview.propertyDeleted') ?? 'Property deleted') ?>, 'success');
                // Reload the properties
                await loadTransitionSelectorStates(transitionEditorSelector);
            } else {
                throw new Error(result.message || 'Failed to delete property');
            }
        } catch (error) {
            console.error('Failed to delete property:', error);
            showToast(<?= json_encode(__admin('preview.deletePropertyFailed') ?? 'Failed to delete property') ?>, 'error');
        }
    }
    
    /**
     * Add a property to base or trigger state
     * @param {string} target - 'base' or 'trigger'
     * @param {string} property - Property name
     * @param {string} value - Property value
     */
    async function addTransitionProperty(target, property, value) {
        if (!property || !value) return;
        
        const selector = target === 'base' 
            ? transitionEditorSelector 
            : transitionEditorSelector + transitionEditorCurrentPseudo;
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: `${property}: ${value};`  // Style string format
                })
            });
            
            const result = await response.json();
            
            if (result.status === 200) {
                showToast(<?= json_encode(__admin('preview.propertyAdded') ?? 'Property added') ?>, 'success');
                // Reload the properties
                await loadTransitionSelectorStates(transitionEditorSelector);
                // Refresh iframe
                const iframe = document.getElementById('preview-frame');
                if (iframe?.contentWindow) {
                    iframe.contentWindow.location.reload();
                }
            } else {
                throw new Error(result.message || 'Failed to add property');
            }
        } catch (error) {
            console.error('Failed to add property:', error);
            showToast(<?= json_encode(__admin('preview.addPropertyFailed') ?? 'Failed to add property') ?>, 'error');
        }
    }
    
    /**
     * Initialize property selector dropdowns using QSPropertySelector class
     * and value input using QSValueInput class
     * This creates searchable, categorized property dropdowns with smart value inputs
     */
    function initTransitionPropertySelectors() {
        // Get existing properties to exclude
        const baseExclude = Object.keys(transitionEditorBaseProperties);
        const triggerExclude = Object.keys(transitionEditorHoverProperties);
        
        // Shared color picker callback
        const handleColorPick = (property, value, swatchEl) => {
            openColorPickerForTransition(property, value, swatchEl);
        };
        
        // Initialize base property selector
        if (transitionBasePropSelectorContainer) {
            if (transitionBasePropSelector) {
                transitionBasePropSelector.destroy();
            }
            transitionBasePropSelector = new QSPropertySelector({
                container: transitionBasePropSelectorContainer,
                excludeProperties: baseExclude,
                onSelect: (property) => {
                    // Update value input to match property type
                    if (transitionBaseValueContainer) {
                        if (transitionBaseValueInput) {
                            transitionBaseValueInput.destroy();
                        }
                        transitionBaseValueInput = new QSValueInput({
                            container: transitionBaseValueContainer,
                            property: property,
                            value: '',
                            onChange: () => {},
                            onBlur: () => {},
                            onColorPick: handleColorPick
                        });
                        transitionBaseValueInput.focus();
                    }
                }
            });
        }
        
        // Initialize base value input (default text type)
        if (transitionBaseValueContainer) {
            if (transitionBaseValueInput) {
                transitionBaseValueInput.destroy();
            }
            transitionBaseValueInput = new QSValueInput({
                container: transitionBaseValueContainer,
                property: '',
                value: '',
                onChange: () => {},
                onBlur: () => {},
                onColorPick: handleColorPick
            });
        }
        
        // Initialize trigger property selector
        if (transitionTriggerPropSelectorContainer) {
            if (transitionTriggerPropSelector) {
                transitionTriggerPropSelector.destroy();
            }
            transitionTriggerPropSelector = new QSPropertySelector({
                container: transitionTriggerPropSelectorContainer,
                excludeProperties: triggerExclude,
                onSelect: (property) => {
                    // Update value input to match property type
                    if (transitionTriggerValueContainer) {
                        if (transitionTriggerValueInput) {
                            transitionTriggerValueInput.destroy();
                        }
                        transitionTriggerValueInput = new QSValueInput({
                            container: transitionTriggerValueContainer,
                            property: property,
                            value: '',
                            onChange: () => {},
                            onBlur: () => {},
                            onColorPick: handleColorPick
                        });
                        transitionTriggerValueInput.focus();
                    }
                }
            });
        }
        
        // Initialize trigger value input (default text type)
        if (transitionTriggerValueContainer) {
            if (transitionTriggerValueInput) {
                transitionTriggerValueInput.destroy();
            }
            transitionTriggerValueInput = new QSValueInput({
                container: transitionTriggerValueContainer,
                property: '',
                value: '',
                onChange: () => {},
                onBlur: () => {},
                onColorPick: handleColorPick
            });
        }
    }
    
    // Track active transition color picker for cleanup
    let activeTransitionColorPicker = null;
    let activeTransitionColorPickerInput = null;
    
    /**
     * Open color picker for State & Animation Editor
     * Uses the same QSColorPicker as the Selectors panel for consistency
     * @param {string} property - CSS property name
     * @param {string} currentColor - Current color value
     * @param {HTMLElement} swatchEl - The color swatch element
     */
    function openColorPickerForTransition(property, currentColor, swatchEl) {
        // Use QSColorPicker if available (same as Selectors panel)
        if (typeof QSColorPicker === 'undefined') {
            console.warn('QSColorPicker not available');
            return;
        }
        
        // Clean up previous picker if any
        if (activeTransitionColorPicker) {
            activeTransitionColorPicker.destroy();
            activeTransitionColorPicker = null;
        }
        if (activeTransitionColorPickerInput?.parentNode) {
            activeTransitionColorPickerInput.remove();
        }
        
        // Create a temporary input for the color picker (positioned near the swatch)
        const tempInput = document.createElement('input');
        tempInput.type = 'text';
        tempInput.value = currentColor || '';
        tempInput.style.cssText = 'position:absolute;opacity:0;pointer-events:none;width:1px;height:1px;';
        swatchEl.parentNode.appendChild(tempInput);
        activeTransitionColorPickerInput = tempInput;
        
        // Get color variables from theme (same as Selectors panel)
        const colorVariables = {};
        if (typeof originalThemeVariables === 'object' && originalThemeVariables) {
            for (const [name, value] of Object.entries(originalThemeVariables)) {
                // Include color-related variables
                if (name.includes('color') || name.includes('bg') || name.includes('text')) {
                    colorVariables[name] = value;
                }
            }
        }
        
        // Create picker attached to temp input (same as Selectors panel)
        const picker = new QSColorPicker(tempInput, {
            position: 'auto',
            cssVariables: Object.keys(colorVariables).length > 0 ? colorVariables : null,
            onChange: (color) => {
                // Extract just the color part if it's a var() (for swatch display)
                let swatchColor = color;
                if (color.startsWith('var(')) {
                    const varName = color.match(/var\(([^)]+)\)/)?.[1];
                    if (varName && colorVariables[varName]) {
                        swatchColor = colorVariables[varName];
                    }
                }
                
                // Update the swatch
                if (swatchEl) swatchEl.style.background = swatchColor;
                
                // Update the value input based on which form is visible
                if (transitionBaseAddForm?.style.display !== 'none' && transitionBaseValueInput) {
                    transitionBaseValueInput.setValue(color);
                } else if (transitionTriggerAddForm?.style.display !== 'none' && transitionTriggerValueInput) {
                    transitionTriggerValueInput.setValue(color);
                }
            }
        });
        
        activeTransitionColorPicker = picker;
        
        // Open the picker via temp input click
        tempInput.click();
    }
    
    /**
     * Toggle inline add property form
     * @param {string} target - 'base' or 'trigger'
     * @param {boolean} show - Whether to show or hide
     */
    function toggleAddPropertyForm(target, show) {
        const toggle = target === 'base' ? transitionBaseAddToggle : transitionTriggerAddToggle;
        const form = target === 'base' ? transitionBaseAddForm : transitionTriggerAddForm;
        const propSelector = target === 'base' ? transitionBasePropSelector : transitionTriggerPropSelector;
        const valueInput = target === 'base' ? transitionBaseValueInput : transitionTriggerValueInput;
        const valueContainer = target === 'base' ? transitionBaseValueContainer : transitionTriggerValueContainer;
        
        if (toggle) toggle.style.display = show ? 'none' : '';
        if (form) form.style.display = show ? '' : 'none';
        
        if (show) {
            if (propSelector) propSelector.setValue('');
            // Reset value input to default text type
            if (valueInput && valueContainer) {
                valueInput.destroy();
                const newInput = new QSValueInput({
                    container: valueContainer,
                    property: '',
                    value: '',
                    onChange: () => {},
                    onBlur: () => {},
                    onColorPick: (property, value, swatchEl) => openColorPickerForTransition(property, value, swatchEl)
                });
                if (target === 'base') {
                    transitionBaseValueInput = newInput;
                } else {
                    transitionTriggerValueInput = newInput;
                }
            }
        }
    }
    
    /**
     * Open animation picker dropdown (Phase 10.3)
     * @param {string} target - 'base' or 'trigger'
     */
    function openAnimationPicker(target) {
        // Use keyframesData from Animations tab (should already be loaded)
        if (!keyframesData || keyframesData.length === 0) {
            // Try to fetch keyframes
            fetch(managementUrl + 'getKeyframes', {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 200 && data.data) {
                    keyframesData = data.data;
                    showAnimationPickerDropdown(target);
                } else {
                    showToast(<?= json_encode(__admin('preview.noKeyframesFound') ?? 'No @keyframes found. Create one in the Animations tab first.') ?>, 'warning');
                }
            })
            .catch(() => {
                showToast(<?= json_encode(__admin('preview.errorLoadingKeyframes') ?? 'Error loading keyframes') ?>, 'error');
            });
        } else {
            showAnimationPickerDropdown(target);
        }
    }
    
    /**
     * Show animation picker dropdown
     * @param {string} target - 'base' or 'trigger'
     */
    function showAnimationPickerDropdown(target) {
        if (!keyframesData || keyframesData.length === 0) {
            showToast(<?= json_encode(__admin('preview.noKeyframesFound') ?? 'No @keyframes found. Create one in the Animations tab first.') ?>, 'warning');
            return;
        }
        
        // Create dropdown
        const existingDropdown = document.querySelector('.animation-picker-dropdown');
        if (existingDropdown) existingDropdown.remove();
        
        const addBtn = target === 'base' ? transitionBaseAnimAddBtn : transitionTriggerAnimAddBtn;
        if (!addBtn) return;
        
        const rect = addBtn.getBoundingClientRect();
        
        const dropdown = document.createElement('div');
        dropdown.className = 'animation-picker-dropdown';
        dropdown.style.cssText = `
            position: fixed;
            left: ${rect.left}px;
            top: ${rect.bottom + 4}px;
            background: var(--qsb-bg-secondary, #2d2d2d);
            border: 1px solid var(--qsb-border, #404040);
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 100002;
            max-height: 200px;
            overflow-y: auto;
            min-width: 180px;
        `;
        
        dropdown.innerHTML = keyframesData.map(kf => `
            <div class="animation-picker-dropdown__item" data-name="${escapeHtml(kf.name)}" style="
                padding: 8px 12px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
                border-bottom: 1px solid var(--qsb-border, #404040);
            ">
                <span style="color: var(--qsb-accent, #4fc3f7);">@keyframes</span>
                <span style="color: var(--qsb-text, #fff);">${escapeHtml(kf.name)}</span>
            </div>
        `).join('');
        
        // Add click handlers
        dropdown.querySelectorAll('.animation-picker-dropdown__item').forEach(item => {
            item.addEventListener('click', () => {
                const name = item.dataset.name;
                addAnimationToSelector(target, name);
                dropdown.remove();
            });
            item.addEventListener('mouseenter', () => {
                item.style.background = 'var(--qsb-bg-hover, #3a3a3a)';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = '';
            });
        });
        
        // Close on click outside
        const closeDropdown = (e) => {
            if (!dropdown.contains(e.target) && e.target !== addBtn) {
                dropdown.remove();
                document.removeEventListener('click', closeDropdown);
            }
        };
        setTimeout(() => document.addEventListener('click', closeDropdown), 10);
        
        document.body.appendChild(dropdown);
    }
    
    /**
     * Add animation to selector
     * @param {string} target - 'base' or 'trigger'
     * @param {string} keyframeName - Name of the @keyframes
     */
    async function addAnimationToSelector(target, keyframeName) {
        const selector = target === 'base' ? transitionEditorSelector : 
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;
        
        // Default animation value
        const animationValue = `${keyframeName} 1000ms ease forwards`;
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: `animation: ${animationValue};`
                })
            });
            
            const result = await response.json();
            if (result.status === 200) {
                showToast(<?= json_encode(__admin('preview.animationAdded') ?? 'Animation added') ?>, 'success');
                
                // Update local state
                if (target === 'base') {
                    transitionEditorBaseAnimation = { name: keyframeName, duration: 1000, iterations: 1, infinite: false };
                    transitionEditorBaseProperties.animation = animationValue;
                } else {
                    transitionEditorTriggerAnimation = { name: keyframeName, duration: 1000, iterations: 1, infinite: false };
                    transitionEditorHoverProperties.animation = animationValue;
                }
                
                renderAnimationSection(target);
                refreshPreviewFrame();
            } else {
                showToast(result.message || <?= json_encode(__admin('preview.errorAddingAnimation') ?? 'Error adding animation') ?>, 'error');
            }
        } catch (error) {
            console.error('Failed to add animation:', error);
            showToast(<?= json_encode(__admin('preview.errorAddingAnimation') ?? 'Error adding animation') ?>, 'error');
        }
    }
    
    /**
     * Remove animation from selector
     * @param {string} target - 'base' or 'trigger'
     */
    async function removeAnimation(target) {
        const selector = target === 'base' ? transitionEditorSelector : 
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: '',
                    removeProperties: ['animation']
                })
            });
            
            const result = await response.json();
            if (result.status === 200) {
                showToast(<?= json_encode(__admin('preview.animationRemoved') ?? 'Animation removed') ?>, 'success');
                
                // Update local state
                if (target === 'base') {
                    transitionEditorBaseAnimation = null;
                    delete transitionEditorBaseProperties.animation;
                } else {
                    transitionEditorTriggerAnimation = null;
                    delete transitionEditorHoverProperties.animation;
                }
                
                renderAnimationSection(target);
                refreshPreviewFrame();
            } else {
                showToast(result.message || <?= json_encode(__admin('preview.errorRemovingAnimation') ?? 'Error removing animation') ?>, 'error');
            }
        } catch (error) {
            console.error('Failed to remove animation:', error);
            showToast(<?= json_encode(__admin('preview.errorRemovingAnimation') ?? 'Error removing animation') ?>, 'error');
        }
    }
    
    /**
     * Preview animation using the Animation Preview Modal
     * @param {string} target - 'base' or 'trigger'
     */
    function previewAnimation(target) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        if (!animation || !animation.name) {
            showToast(<?= json_encode(__admin('preview.noAnimationToPreview') ?? 'No animation to preview') ?>, 'warning');
            return;
        }
        
        // Find the keyframe data
        const keyframe = keyframesData?.find(kf => kf.name === animation.name);
        if (!keyframe) {
            showToast(<?= json_encode(__admin('preview.keyframeNotFound') ?? 'Keyframe not found') ?>, 'error');
            return;
        }
        
        // Use existing animation preview modal
        if (typeof openAnimationPreviewModal === 'function') {
            openAnimationPreviewModal(keyframe);
        } else {
            showToast(<?= json_encode(__admin('preview.previewNotAvailable') ?? 'Preview not available') ?>, 'warning');
        }
    }
    
    /**
     * Update animation setting (duration, iterations, infinite)
     * @param {string} target - 'base' or 'trigger'
     * @param {string} setting - 'duration', 'iterations', or 'infinite'
     */
    async function updateAnimationSetting(target, setting) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        if (!animation || !animation.name) return;
        
        const selector = target === 'base' ? transitionEditorSelector : 
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;
        
        // Get updated values from inputs
        const durationEl = target === 'base' ? transitionBaseAnimDuration : transitionTriggerAnimDuration;
        const iterationsEl = target === 'base' ? transitionBaseAnimIterations : transitionTriggerAnimIterations;
        const infiniteEl = target === 'base' ? transitionBaseAnimInfinite : transitionTriggerAnimInfinite;
        
        const duration = durationEl?.value || animation.duration;
        const iterations = infiniteEl?.checked ? 'infinite' : (iterationsEl?.value || animation.iterations);
        const infinite = infiniteEl?.checked || false;
        
        // Build animation value
        const animationValue = `${animation.name} ${duration}ms ease ${iterations === 'infinite' ? 'infinite' : ''} forwards`.replace(/\s+/g, ' ').trim();
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: `animation: ${animationValue};`
                })
            });
            
            const result = await response.json();
            if (result.status === 200) {
                // Update local state
                if (target === 'base') {
                    transitionEditorBaseAnimation = { ...animation, duration: parseInt(duration), iterations: infinite ? 'infinite' : parseInt(iterationsEl?.value || 1), infinite };
                    transitionEditorBaseProperties.animation = animationValue;
                } else {
                    transitionEditorTriggerAnimation = { ...animation, duration: parseInt(duration), iterations: infinite ? 'infinite' : parseInt(iterationsEl?.value || 1), infinite };
                    transitionEditorHoverProperties.animation = animationValue;
                }
                
                refreshPreviewFrame();
            }
        } catch (error) {
            console.error('Failed to update animation:', error);
        }
    }
    
    /**
     * Update specific properties checkboxes based on hover properties
     */
    function updateSpecificPropertiesCheckboxes() {
        if (!transitionSpecificProps) return;
        
        // Get all unique properties from both base and hover
        const allProps = new Set([
            ...Object.keys(transitionEditorBaseProperties),
            ...Object.keys(transitionEditorHoverProperties)
        ]);
        
        // Filter out transition/animation
        const propsToShow = [...allProps].filter(p => 
            p !== 'transition' && p !== 'animation'
        );
        
        if (propsToShow.length === 0) {
            transitionSpecificProps.innerHTML = `
                <div class="transition-editor__empty"><?= __admin('preview.noPropertiesToTransition') ?? 'No properties to transition' ?></div>
            `;
            return;
        }
        
        transitionSpecificProps.innerHTML = propsToShow.map(prop => `
            <label class="transition-editor__checkbox-label">
                <input type="checkbox" class="transition-editor__prop-checkbox" value="${escapeHtml(prop)}" checked>
                ${escapeHtml(prop)}
            </label>
        `).join('');
        
        // Add change listeners
        transitionSpecificProps.querySelectorAll('.transition-editor__prop-checkbox').forEach(cb => {
            cb.addEventListener('change', updateTransitionPreviewCode);
        });
    }
    
    /**
     * Build transition value string from controls
     */
    function buildTransitionValue() {
        const property = transitionPropertySelect?.value || 'all';
        const durationVal = transitionDuration?.value || 0.3;
        const durationUnit = transitionDurationUnit?.value || 's';
        const duration = durationVal + durationUnit;
        const delayVal = parseFloat(transitionDelay?.value || 0);
        const delayUnit = transitionDelayUnit?.value || 's';
        const delay = delayVal > 0 ? delayVal + delayUnit : null;
        const timing = transitionTimingSelect?.value || 'ease';
        
        let timingValue = timing;
        if (timing === 'cubic-bezier') {
            const x1 = document.getElementById('transition-bezier-x1')?.value || 0.4;
            const y1 = document.getElementById('transition-bezier-y1')?.value || 0;
            const x2 = document.getElementById('transition-bezier-x2')?.value || 0.2;
            const y2 = document.getElementById('transition-bezier-y2')?.value || 1;
            timingValue = `cubic-bezier(${x1}, ${y1}, ${x2}, ${y2})`;
        }
        
        if (property === 'specific') {
            // Get selected properties
            const selectedProps = [];
            transitionSpecificProps?.querySelectorAll('.transition-editor__prop-checkbox:checked').forEach(cb => {
                selectedProps.push(cb.value);
            });
            
            if (selectedProps.length === 0) {
                return 'none';
            }
            
            // Build multiple transitions
            return selectedProps.map(prop => 
                `${prop} ${duration} ${timingValue}${delay ? ' ' + delay : ''}`
            ).join(', ');
        }
        
        // Single transition for all
        let value = `all ${duration} ${timingValue}`;
        if (delay) {
            value += ` ${delay}`;
        }
        return value;
    }
    
    /**
     * Update the transition preview code display
     */
    function updateTransitionPreviewCode() {
        if (!transitionPreviewCode) return;
        transitionPreviewCode.textContent = buildTransitionValue();
    }
    
    /**
     * Update trigger state labels based on selected pseudo-class
     */
    function updateTriggerStateLabels() {
        const pseudo = transitionEditorCurrentPseudo || ':hover';
        const pseudoName = pseudo.replace(':', '');
        const pseudoCapitalized = pseudoName.charAt(0).toUpperCase() + pseudoName.slice(1);
        
        // Update the trigger state panel label
        if (transitionTriggerLabel) {
            transitionTriggerLabel.textContent = `${pseudo} ${<?= json_encode(__admin('preview.state') ?? 'State') ?>}`;
        }
        
        // Update the "Add State Style" button text
        if (transitionAddHoverText) {
            transitionAddHoverText.textContent = `${<?= json_encode(__admin('preview.addStyle') ?? 'Add') ?>} ${pseudo} ${<?= json_encode(__admin('preview.style') ?? 'Style') ?>}`;
        }
    }
    
    /**
     * Close the Transition Editor
     */
    function closeTransitionEditor() {
        transitionEditorModal?.classList.remove('preview-keyframe-modal--visible');
        transitionEditorSelector = null;
        transitionEditorCallback = null;
    }
    
    /**
     * Save the transition
     */
    async function saveTransition() {
        if (!transitionEditorSelector) return;
        
        const transitionValue = buildTransitionValue();
        
        try {
            // Save transition to the base selector
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: transitionEditorSelector,
                    styles: `transition: ${transitionValue};`
                })
            });
            
            const result = await response.json();
            
            if (result.status === 200) {
                showToast(<?= json_encode(__admin('preview.transitionSaved') ?? 'Transition saved successfully') ?>, 'success');
                
                // Refresh iframe to show changes
                const iframe = document.getElementById('preview-frame');
                if (iframe?.contentWindow) {
                    iframe.contentWindow.location.reload();
                }
                
                // Call callback if provided
                if (typeof transitionEditorCallback === 'function') {
                    transitionEditorCallback(transitionValue);
                }
                
                // Reload animations tab data
                if (animationsLoaded) {
                    animationsLoaded = false;
                    loadAnimationsTab();
                }
                
                closeTransitionEditor();
            } else {
                throw new Error(result.message || 'Failed to save');
            }
            
        } catch (error) {
            console.error('Failed to save transition:', error);
            showToast(<?= json_encode(__admin('preview.saveTransitionFailed') ?? 'Failed to save transition') ?>, 'error');
        }
    }
    
    /**
     * Preview hover effect in iframe
     */
    function previewTransitionHover() {
        const iframe = document.getElementById('preview-frame');
        if (!iframe?.contentDocument || !transitionEditorSelector) return;
        
        try {
            const elements = iframe.contentDocument.querySelectorAll(transitionEditorSelector);
            if (elements.length === 0) {
                showToast(<?= json_encode(__admin('preview.noElementsFound') ?? 'No elements found for selector') ?>, 'warning');
                return;
            }
            
            // Apply hover styles temporarily
            elements.forEach(el => {
                // Store original inline styles
                el.dataset.originalStyle = el.getAttribute('style') || '';
                
                // Apply hover properties
                Object.entries(transitionEditorHoverProperties).forEach(([prop, value]) => {
                    el.style.setProperty(prop, value);
                });
            });
            
            // Revert after delay
            setTimeout(() => {
                elements.forEach(el => {
                    // Restore original inline styles
                    const original = el.dataset.originalStyle;
                    if (original) {
                        el.setAttribute('style', original);
                    } else {
                        el.removeAttribute('style');
                    }
                    delete el.dataset.originalStyle;
                });
            }, 1500);
            
            showToast(<?= json_encode(__admin('preview.previewingHover') ?? 'Previewing hover effect') ?>, 'info');
            
        } catch (error) {
            console.error('Failed to preview hover:', error);
        }
    }
    
    // Transition Editor Event Listeners
    if (transitionEditorModal) {
        // Close button
        document.getElementById('transition-editor-close')?.addEventListener('click', closeTransitionEditor);
        document.getElementById('transition-cancel')?.addEventListener('click', closeTransitionEditor);
        
        // Save button
        document.getElementById('transition-save')?.addEventListener('click', saveTransition);
        
        // Preview button
        document.getElementById('transition-preview-btn')?.addEventListener('click', previewTransitionHover);
        
        // Backdrop click to close
        transitionEditorModal.querySelector('.preview-keyframe-modal__backdrop')?.addEventListener('click', closeTransitionEditor);
        
        // Pseudo-class select change
        transitionPseudoSelect?.addEventListener('change', async (e) => {
            transitionEditorCurrentPseudo = e.target.value;
            updateTriggerStateLabels();
            await loadTransitionSelectorStates(transitionEditorSelector);
        });
        
        // Property select change (all vs specific)
        transitionPropertySelect?.addEventListener('change', (e) => {
            if (e.target.value === 'specific') {
                transitionSpecificProps.style.display = '';
            } else {
                transitionSpecificProps.style.display = 'none';
            }
            updateTransitionPreviewCode();
        });
        
        // Timing function change
        transitionTimingSelect?.addEventListener('change', (e) => {
            if (e.target.value === 'cubic-bezier') {
                transitionCubicBezier.style.display = '';
            } else {
                transitionCubicBezier.style.display = 'none';
            }
            updateTransitionPreviewCode();
        });
        
        // Duration/delay changes
        transitionDuration?.addEventListener('input', updateTransitionPreviewCode);
        transitionDelay?.addEventListener('input', updateTransitionPreviewCode);
        transitionDurationUnit?.addEventListener('change', updateTransitionPreviewCode);
        transitionDelayUnit?.addEventListener('change', updateTransitionPreviewCode);
        
        // Cubic bezier changes
        ['transition-bezier-x1', 'transition-bezier-y1', 'transition-bezier-x2', 'transition-bezier-y2'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', updateTransitionPreviewCode);
        });
        
        // Initialize property selectors (uses QSPropertySelector class)
        initTransitionPropertySelectors();
        
        // Base state inline add property form
        transitionBaseAddToggle?.addEventListener('click', () => toggleAddPropertyForm('base', true));
        transitionBaseAddCancel?.addEventListener('click', () => toggleAddPropertyForm('base', false));
        transitionBaseAddConfirm?.addEventListener('click', async () => {
            const prop = transitionBasePropSelector?.getValue();
            const value = transitionBaseValueInput?.getValue();
            if (prop && value) {
                await addTransitionProperty('base', prop, value);
                toggleAddPropertyForm('base', false);
            }
        });
        
        // Keyboard events for base value input container
        transitionBaseValueContainer?.addEventListener('keydown', async (e) => {
            if (e.key === 'Enter') {
                const prop = transitionBasePropSelector?.getValue();
                const value = transitionBaseValueInput?.getValue();
                if (prop && value) {
                    await addTransitionProperty('base', prop, value);
                    toggleAddPropertyForm('base', false);
                }
            } else if (e.key === 'Escape') {
                toggleAddPropertyForm('base', false);
            }
        });
        
        // Trigger state inline add property form
        transitionTriggerAddToggle?.addEventListener('click', () => toggleAddPropertyForm('trigger', true));
        transitionTriggerAddCancel?.addEventListener('click', () => toggleAddPropertyForm('trigger', false));
        transitionTriggerAddConfirm?.addEventListener('click', async () => {
            const prop = transitionTriggerPropSelector?.getValue();
            const value = transitionTriggerValueInput?.getValue();
            if (prop && value) {
                await addTransitionProperty('trigger', prop, value);
                toggleAddPropertyForm('trigger', false);
            }
        });
        
        // Keyboard events for trigger value input container
        transitionTriggerValueContainer?.addEventListener('keydown', async (e) => {
            if (e.key === 'Enter') {
                const prop = transitionTriggerPropSelector?.getValue();
                const value = transitionTriggerValueInput?.getValue();
                if (prop && value) {
                    await addTransitionProperty('trigger', prop, value);
                    toggleAddPropertyForm('trigger', false);
                }
            } else if (e.key === 'Escape') {
                toggleAddPropertyForm('trigger', false);
            }
        });
        
        // Animation section event listeners (Phase 10.3)
        transitionBaseAnimAddBtn?.addEventListener('click', () => openAnimationPicker('base'));
        transitionTriggerAnimAddBtn?.addEventListener('click', () => openAnimationPicker('trigger'));
        transitionBaseAnimRemoveBtn?.addEventListener('click', () => removeAnimation('base'));
        transitionTriggerAnimRemoveBtn?.addEventListener('click', () => removeAnimation('trigger'));
        transitionBaseAnimPreviewBtn?.addEventListener('click', () => previewAnimation('base'));
        transitionTriggerAnimPreviewBtn?.addEventListener('click', () => previewAnimation('trigger'));
        
        // Animation duration change listeners
        transitionBaseAnimDuration?.addEventListener('change', () => updateAnimationSetting('base', 'duration'));
        transitionTriggerAnimDuration?.addEventListener('change', () => updateAnimationSetting('trigger', 'duration'));
        transitionBaseAnimIterations?.addEventListener('change', () => updateAnimationSetting('base', 'iterations'));
        transitionTriggerAnimIterations?.addEventListener('change', () => updateAnimationSetting('trigger', 'iterations'));
        transitionBaseAnimInfinite?.addEventListener('change', () => updateAnimationSetting('base', 'infinite'));
        transitionTriggerAnimInfinite?.addEventListener('change', () => updateAnimationSetting('trigger', 'infinite'));
    }

    // ==================== Draggable Panels ====================

    /**
     * Make a panel draggable by its header
     * @param {HTMLElement} panel - The panel element
     * @param {HTMLElement} header - The header element to drag from
     * @param {string} storageKey - localStorage key for position persistence
     */
    function makePanelDraggable(panel, header, storageKey) {
        if (!panel || !header) return;
        
        let isDragging = false;
        let startX, startY, startLeft, startTop;
        
        // Restore saved position
        const savedPos = localStorage.getItem(storageKey);
        if (savedPos) {
            try {
                const pos = JSON.parse(savedPos);
                panel.style.left = pos.left + 'px';
                panel.style.top = pos.top + 'px';
                panel.classList.add(panel.classList[0] + '--dragged');
            } catch (e) {}
        }
        
        header.addEventListener('mousedown', (e) => {
            // Don't drag if clicking on close button
            if (e.target.closest('button')) return;
            
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            
            const rect = panel.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;
            
            // Add dragged class to disable default positioning
            panel.classList.add(panel.classList[0] + '--dragged');
            
            // Disable text selection while dragging
            document.body.style.userSelect = 'none';
            
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;
            
            let newLeft = startLeft + deltaX;
            let newTop = startTop + deltaY;
            
            // Keep within viewport
            const panelRect = panel.getBoundingClientRect();
            const maxLeft = window.innerWidth - panelRect.width;
            const maxTop = window.innerHeight - panelRect.height;
            
            newLeft = Math.max(0, Math.min(newLeft, maxLeft));
            newTop = Math.max(0, Math.min(newTop, maxTop));
            
            panel.style.left = newLeft + 'px';
            panel.style.top = newTop + 'px';
        });
        
        document.addEventListener('mouseup', () => {
            if (!isDragging) return;
            isDragging = false;
            
            // Re-enable text selection
            document.body.style.userSelect = '';
            
            // Save position
            const rect = panel.getBoundingClientRect();
            localStorage.setItem(storageKey, JSON.stringify({
                left: rect.left,
                top: rect.top
            }));
        });
    }
    
    // Initialize draggable panels
    const nodePanelHeader = nodePanel?.querySelector('.preview-node-panel__header');
    makePanelDraggable(nodePanel, nodePanelHeader, 'qs_node_panel_pos');
    
    // ==================== URL Building ====================
    
    function getCurrentLang() {
        return langSelect ? langSelect.value : defaultLang;
    }
    
    function buildUrl(route, component = '') {
        let url = baseUrl + '/';
        if (multilingual) {
            url += getCurrentLang() + '/';
        }
        if (route) {
            url += route;
        }
        // Always add _editor=1 for editor mode
        url += (url.includes('?') ? '&' : '?') + '_editor=1';
        // Add component isolation parameter if set
        if (component) {
            url += '&_component=' + encodeURIComponent(component);
        }
        return url;
    }
    
    // ==================== Loading ====================
    
    function showLoading() {
        loading.classList.add('preview-loading--visible');
    }
    
    function hideLoading() {
        loading.classList.remove('preview-loading--visible');
    }
    
    let loadingTimeout = null;
    function startLoadingTimeout() {
        clearTimeout(loadingTimeout);
        loadingTimeout = setTimeout(hideLoading, 5000);
    }
    
    // ==================== Navigation ====================
    
    function reloadPreview() {
        showLoading();
        startLoadingTimeout();
        overlayInjected = false;
        iframe.src = iframe.src;
    }
    
    function navigateTo(route, component = '') {
        showLoading();
        startLoadingTimeout();
        overlayInjected = false;
        iframe.src = buildUrl(route, component);
    }
    
    // ==================== Device ====================
    
    function setDevice(device) {
        currentDevice = device;
        const size = devices[device];
        
        deviceBtns.forEach(btn => {
            btn.classList.toggle('preview-device-btn--active', btn.dataset.device === device);
        });
        
        wrapper.style.width = size.width;
        wrapper.style.height = size.height;
        container.classList.toggle('preview-container--device', device !== 'desktop');
    }
    
    // ==================== Editor Mode ====================
    
    // Store preselection data for style mode (Phase 8.2)
    let styleModePreselect = null;
    let isSwitchingMode = false; // Debounce flag to prevent rapid mode switching

    function setMode(mode, preselect = null) {
        // Debounce rapid mode switches to prevent layout thrashing
        if (isSwitchingMode) return;
        isSwitchingMode = true;
        
        currentMode = mode;
        
        modeBtns.forEach(btn => {
            btn.classList.toggle('preview-mode-btn--active', btn.dataset.mode === mode);
        });
        
        // Update container class for mode-specific styling
        container.dataset.mode = mode;
        
        // Send mode change to iframe
        sendToIframe('setMode', { mode });
        
        // Store preselection for style mode (used in Phase 8.4+)
        if (mode === 'style' && preselect) {
            styleModePreselect = preselect;
            console.log('[Preview] Style mode preselection:', preselect);
            // TODO Phase 8.4: Auto-select the selector in the selector browser
        } else if (mode !== 'style') {
            styleModePreselect = null;
        }
        
        // Update contextual area sections
        updateContextualSection(mode);
        
        // Hide node panel when switching away from select mode
        if (mode !== 'select') {
            hideNodePanel();
        }
        
        // Hide style panel when switching away from style mode
        if (mode !== 'style') {
            hideStylePanel();
        }
        
        // Reset debounce flag after a short delay
        requestAnimationFrame(() => {
            isSwitchingMode = false;
        });
    }
    
    // ==================== Contextual Area (Phase 8) ====================
    
    function updateContextualSection(mode) {
        // Hide all sections
        contextualSections.forEach(section => {
            section.style.display = 'none';
            section.classList.remove('preview-contextual-section--active');
        });
        
        // Show the section matching the current mode
        const activeSection = document.getElementById('contextual-' + mode);
        if (activeSection) {
            activeSection.style.display = '';
            activeSection.classList.add('preview-contextual-section--active');
        }
        
        // Reset select mode info display when switching away
        if (mode !== 'select' && ctxSelectInfo) {
            ctxSelectInfo.style.display = 'none';
            ctxSelectDefault.style.display = '';
        }
        
        // Phase 8.3: Show/hide style tabs and content when in style mode
        if (mode === 'style') {
            if (styleTabs) styleTabs.style.display = '';
            if (styleContent) styleContent.style.display = '';
            // Load theme variables if not already loaded
            if (!themeVariablesLoaded) {
                loadThemeVariables();
            }
        } else {
            if (styleTabs) styleTabs.style.display = 'none';
            if (styleContent) styleContent.style.display = 'none';
        }
    }
    
    function toggleContextualArea() {
        contextualArea.classList.toggle('preview-contextual-area--collapsed');
    }
    
    // ==================== Preview Resize ====================
    
    const PREVIEW_HEIGHT_KEY = 'quicksite-preview-height';
    const MIN_PREVIEW_HEIGHT = 200;
    const MAX_PREVIEW_HEIGHT = window.innerHeight - 150; // Leave space for toolbar
    
    /**
     * Initialize preview area resize functionality
     */
    function initPreviewResize() {
        if (!previewResizeHandle || !container) return;
        
        // Restore saved height
        const savedHeight = localStorage.getItem(PREVIEW_HEIGHT_KEY);
        if (savedHeight) {
            const height = parseInt(savedHeight, 10);
            if (height >= MIN_PREVIEW_HEIGHT && height <= MAX_PREVIEW_HEIGHT) {
                container.style.setProperty('--preview-height', height + 'px');
            }
        }
        
        let isResizing = false;
        let startY = 0;
        let startHeight = 0;
        
        previewResizeHandle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            isResizing = true;
            startY = e.clientY;
            startHeight = container.offsetHeight;
            
            container.classList.add('preview-container--resizing');
            previewResizeHandle.classList.add('preview-resize-handle--active');
            
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });
        
        function onMouseMove(e) {
            if (!isResizing) return;
            
            // Handle at bottom: dragging DOWN = larger, dragging UP = smaller
            const deltaY = e.clientY - startY;
            let newHeight = startHeight + deltaY;
            
            // Clamp to min/max
            const currentMax = window.innerHeight - 150;
            newHeight = Math.max(MIN_PREVIEW_HEIGHT, Math.min(currentMax, newHeight));
            
            container.style.setProperty('--preview-height', newHeight + 'px');
        }
        
        function onMouseUp(e) {
            if (!isResizing) return;
            
            isResizing = false;
            container.classList.remove('preview-container--resizing');
            previewResizeHandle.classList.remove('preview-resize-handle--active');
            
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            
            // Save the final height
            const finalHeight = container.offsetHeight;
            localStorage.setItem(PREVIEW_HEIGHT_KEY, finalHeight.toString());
        }
    }
    
    // Initialize preview resize
    initPreviewResize();
    
    function showContextualInfo(data) {
        // Format structure name for display
        let structDisplay = data.struct || '-';
        if (structDisplay.startsWith('page-')) {
            structDisplay = 'Page: ' + structDisplay.substring(5);
        } else if (structDisplay === 'menu') {
            structDisplay = 'Menu';
        } else if (structDisplay === 'footer') {
            structDisplay = 'Footer';
        }
        
        // Update contextual info fields
        ctxNodeStruct.textContent = structDisplay;
        ctxNodeId.textContent = data.isComponent ? data.componentNode : (data.node || '-');
        ctxNodeTag.textContent = data.tag || '-';
        ctxNodeClasses.textContent = data.classes || '-';
        ctxNodeChildren.textContent = data.childCount !== undefined ? data.childCount : '-';
        ctxNodeText.textContent = data.textContent || '-';
        
        // Show/hide component row
        if (data.isComponent && data.component) {
            ctxNodeComponent.textContent = data.component;
            ctxNodeComponentRow.style.display = '';
        } else {
            ctxNodeComponentRow.style.display = 'none';
        }
        
        // Show/hide textKey row
        if (data.textKeys && data.textKeys.length > 0) {
            ctxNodeTextKey.textContent = data.textKeys.join(', ');
            ctxNodeTextKeyRow.style.display = '';
        } else {
            ctxNodeTextKeyRow.style.display = 'none';
        }
        
        // Show info, hide default message
        ctxSelectDefault.style.display = 'none';
        ctxSelectInfo.style.display = '';
        
        // Expand contextual area if collapsed
        contextualArea.classList.remove('preview-contextual-area--collapsed');
    }
    
    function hideContextualInfo() {
        // Show default message, hide info
        ctxSelectDefault.style.display = '';
        ctxSelectInfo.style.display = 'none';
    }
    
    // ==================== Theme Variables (Phase 8.3) ====================
    
    /**
     * Load theme variables from the API
     */
    async function loadThemeVariables() {
        if (!themeLoading || !themeContent) return;
        
        // Show loading state
        themeLoading.style.display = '';
        themeContent.style.display = 'none';
        
        try {
            const response = await fetch(managementUrl + 'getRootVariables', {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            const data = await response.json();
            
            if (data.status === 200 && data.data?.variables) {
                originalThemeVariables = { ...data.data.variables };
                currentThemeVariables = { ...data.data.variables };
                themeVariablesLoaded = true;
                
                // Populate the theme editor UI
                populateThemeEditor(data.data.variables);
                
                // Show content, hide loading
                themeLoading.style.display = 'none';
                themeContent.style.display = '';
            } else {
                throw new Error(data.message || 'Failed to load theme variables');
            }
        } catch (error) {
            console.error('Error loading theme variables:', error);
            themeLoading.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" style="color: #ef4444;">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span style="color: #ef4444;">${<?= json_encode(__admin('preview.themeLoadError') ?? 'Failed to load theme variables') ?>}</span>
            `;
        }
    }
    
    /**
     * Categorize and populate the theme editor with variables
     */
    function populateThemeEditor(variables) {
        const categories = {
            colors: [],
            fonts: [],
            spacing: [],
            other: []
        };
        
        // Categorize variables by prefix
        for (const [name, value] of Object.entries(variables)) {
            if (name.startsWith('--color-') || name.includes('color') || isColorValue(value)) {
                categories.colors.push({ name, value });
            } else if (name.startsWith('--font-') || name.includes('font')) {
                categories.fonts.push({ name, value });
            } else if (name.startsWith('--spacing-') || name.startsWith('--gap-') || name.startsWith('--margin-') || name.startsWith('--padding-') || name.includes('size') || isSizeValue(value)) {
                categories.spacing.push({ name, value });
            } else {
                categories.other.push({ name, value });
            }
        }
        
        // Render each category
        renderColorInputs(themeColorsGrid, categories.colors);
        renderFontInputs(themeFontsGrid, categories.fonts);
        renderSpacingInputs(themeSpacingGrid, categories.spacing);
        renderOtherInputs(themeOtherGrid, categories.other);
        
        // Show/hide "Other" section based on content
        if (themeOtherSection) {
            themeOtherSection.style.display = categories.other.length > 0 ? '' : 'none';
        }
    }
    
    /**
     * Check if a value looks like a color
     */
    function isColorValue(value) {
        if (!value) return false;
        value = value.trim().toLowerCase();
        return value.startsWith('#') || 
               value.startsWith('rgb') || 
               value.startsWith('hsl') ||
               value.startsWith('var(--color');
    }
    
    /**
     * Check if a value looks like a size (px, rem, em, etc.)
     */
    function isSizeValue(value) {
        if (!value) return false;
        return /^\d+(\.\d+)?(px|rem|em|%|vh|vw|vmin|vmax)$/.test(value.trim());
    }
    
    /**
     * Format variable name for display (--color-primary -> Color Primary)
     */
    function formatVariableName(name) {
        return name
            .replace(/^--/, '')
            .split('-')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }
    
    /**
     * Render color inputs
     */
    function renderColorInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        if (variables.length === 0) {
            container.innerHTML = `<p class="preview-theme-empty">${<?= json_encode(__admin('preview.noColorVariables') ?? 'No color variables found') ?>}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-color';
            item.innerHTML = `
                <div class="preview-theme-color__swatch" style="background-color: ${value};" data-var="${name}"></div>
                <div class="preview-theme-color__info">
                    <span class="preview-theme-color__name">${formatVariableName(name)}</span>
                    <input type="text" class="preview-theme-color__value" value="${value}" data-var="${name}" data-original="${value}">
                </div>
            `;
            
            const swatch = item.querySelector('.preview-theme-color__swatch');
            const input = item.querySelector('.preview-theme-color__value');
            
            // Initialize QSColorPicker on the input if available
            if (typeof QSColorPicker !== 'undefined') {
                new QSColorPicker(input, {
                    onChange: (color) => {
                        swatch.style.backgroundColor = color;
                        handleVariableChange(name, color);
                        previewThemeVariable(name, color);
                    }
                });
            }
            
            // Big swatch click → trigger input click (opens QSColorPicker)
            swatch.addEventListener('click', () => input.click());
            
            // Manual input change handler (for typing hex values)
            input.addEventListener('change', (e) => {
                handleVariableChange(name, e.target.value);
                swatch.style.backgroundColor = e.target.value;
            });
            input.addEventListener('input', (e) => {
                // Live preview on input
                previewThemeVariable(name, e.target.value);
                swatch.style.backgroundColor = e.target.value;
            });
            
            container.appendChild(item);
        });
    }
    
    /**
     * Render font inputs
     */
    function renderFontInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        if (variables.length === 0) {
            container.innerHTML = `<p class="preview-theme-empty">${<?= json_encode(__admin('preview.noFontVariables') ?? 'No font variables found') ?>}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}">
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    /**
     * Render spacing inputs
     */
    function renderSpacingInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        if (variables.length === 0) {
            container.innerHTML = `<p class="preview-theme-empty">${<?= json_encode(__admin('preview.noSpacingVariables') ?? 'No spacing variables found') ?>}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}" placeholder="e.g. 1rem, 16px">
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    /**
     * Render other inputs
     */
    function renderOtherInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        if (variables.length === 0) return;
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}">
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    /**
     * Handle variable value change
     */
    function handleVariableChange(name, value) {
        currentThemeVariables[name] = value;
    }
    
    /**
     * Live preview a theme variable change in the iframe
     */
    function previewThemeVariable(name, value) {
        currentThemeVariables[name] = value;
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;
            
            // Find or create the preview style element
            let previewStyle = iframeDoc.getElementById('quicksite-theme-preview');
            if (!previewStyle) {
                previewStyle = iframeDoc.createElement('style');
                previewStyle.id = 'quicksite-theme-preview';
                iframeDoc.head.appendChild(previewStyle);
            }
            
            // Build CSS from all modified variables
            const modifiedVars = Object.entries(currentThemeVariables)
                .filter(([k, v]) => v !== originalThemeVariables[k])
                .map(([k, v]) => `${k}: ${v};`)
                .join('\n');
            
            previewStyle.textContent = `:root {\n${modifiedVars}\n}`;
        } catch (e) {
            console.warn('Could not preview theme variable:', e);
        }
    }
    
    /**
     * Save theme variables to the server
     */
    async function saveThemeVariables() {
        if (!themeSaveBtn) return;
        
        // Disable button and show loading
        themeSaveBtn.disabled = true;
        const originalText = themeSaveBtn.innerHTML;
        themeSaveBtn.innerHTML = `
            <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                <circle cx="12" cy="12" r="10" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
            </svg>
            ${<?= json_encode(__admin('common.saving') ?? 'Saving...') ?>}
        `;
        
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (authToken) headers['Authorization'] = `Bearer ${authToken}`;
            
            const response = await fetch(managementUrl + 'setRootVariables', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ variables: currentThemeVariables })
            });
            
            const data = await response.json();
            
            if (data.status === 200 || data.status === 201) {
                // Update original values to current
                originalThemeVariables = { ...currentThemeVariables };
                
                // Show success toast
                showToast(<?= json_encode(__admin('preview.themeSaved') ?? 'Theme variables saved successfully') ?>, 'success');
                
                // Reload iframe to get fresh styles
                reloadPreview();
            } else {
                throw new Error(data.message || 'Failed to save theme variables');
            }
        } catch (error) {
            console.error('Error saving theme variables:', error);
            showToast(<?= json_encode(__admin('preview.themeSaveError') ?? 'Failed to save theme variables') ?>, 'error');
        } finally {
            // Restore button
            themeSaveBtn.disabled = false;
            themeSaveBtn.innerHTML = originalText;
        }
    }
    
    /**
     * Reset theme variables to original values
     */
    function resetThemeVariables() {
        currentThemeVariables = { ...originalThemeVariables };
        
        // Update all inputs
        document.querySelectorAll('[data-var]').forEach(el => {
            const varName = el.dataset.var;
            const originalValue = originalThemeVariables[varName];
            
            if (el.classList.contains('preview-theme-color__swatch')) {
                el.style.backgroundColor = originalValue;
            } else if (el.classList.contains('preview-theme-color__value') || el.classList.contains('preview-theme-input__field')) {
                el.value = originalValue;
            }
        });
        
        // Remove preview style from iframe
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            const previewStyle = iframeDoc?.getElementById('quicksite-theme-preview');
            if (previewStyle) {
                previewStyle.remove();
            }
        } catch (e) {
            console.warn('Could not reset iframe preview:', e);
        }
        
        showToast(<?= json_encode(__admin('preview.themeReset') ?? 'Theme variables reset to original') ?>, 'info');
    }
    
    /**
     * Initialize style tab switching
     */
    function initStyleTabs() {
        if (!styleTabs) return;
        
        const tabs = styleTabs.querySelectorAll('.preview-contextual-style-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.dataset.tab;
                if (tabName === activeStyleTab) return;
                
                // Update active tab button
                tabs.forEach(t => t.classList.remove('preview-contextual-style-tab--active'));
                tab.classList.add('preview-contextual-style-tab--active');
                
                // Show/hide panels
                activeStyleTab = tabName;
                if (themePanel) themePanel.style.display = tabName === 'theme' ? '' : 'none';
                if (selectorsPanel) selectorsPanel.style.display = tabName === 'selectors' ? '' : 'none';
                if (animationsPanel) animationsPanel.style.display = tabName === 'animations' ? '' : 'none';
                
                // Load selectors when switching to selectors tab (Phase 8.4)
                if (tabName === 'selectors' && !selectorsLoaded) {
                    loadStyleSelectors();
                }
                
                // Load animations when switching to animations tab (Phase 9.2)
                if (tabName === 'animations' && !animationsLoaded) {
                    loadAnimationsTab();
                }
            });
        });
        
        // Initialize animations group collapsing
        initAnimationsGroups();
    }
    
    // ==================== Animations Tab (Phase 9.2) ====================
    
    // Helper function to escape HTML (alias for QuickSiteAdmin.escapeHtml)
    const escapeHtml = (text) => {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };
    
    /**
     * Initialize animations group collapsing
     */
    function initAnimationsGroups() {
        const groups = document.querySelectorAll('.preview-animations-group');
        groups.forEach(group => {
            const header = group.querySelector('.preview-animations-group__header');
            const list = group.querySelector('.preview-animations-group__list');
            
            if (header && list) {
                header.addEventListener('click', () => {
                    const isExpanded = header.classList.contains('preview-animations-group__header--expanded');
                    header.classList.toggle('preview-animations-group__header--expanded');
                    list.classList.toggle('preview-animations-group__list--collapsed', isExpanded);
                });
            }
        });
    }
    
    /**
     * Load animations data from APIs (keyframes and animated selectors)
     */
    async function loadAnimationsTab() {
        if (!animationsLoading || !animationsContent) return;
        
        // Show loading state
        animationsLoading.style.display = '';
        animationsContent.style.display = 'none';
        
        try {
            // Fetch both keyframes and animated selectors in parallel
            const [keyframesResponse, selectorsResponse] = await Promise.all([
                fetch(managementUrl + 'listKeyframes', {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                }),
                fetch(managementUrl + 'getAnimatedSelectors', {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                })
            ]);
            
            const keyframesData_response = await keyframesResponse.json();
            const selectorsData = await selectorsResponse.json();
            
            // Process keyframes
            if (keyframesData_response.status === 200 && keyframesData_response.data?.keyframes) {
                keyframesData = keyframesData_response.data.keyframes;
                populateKeyframesList();
            } else {
                keyframesData = [];
                populateKeyframesList();
            }
            
            // Process animated selectors
            if (selectorsData.status === 200 && selectorsData.data) {
                animatedSelectorsData = {
                    transitions: selectorsData.data.transitions || [],
                    animations: selectorsData.data.animations || [],
                    triggersWithoutTransition: selectorsData.data.triggersWithoutTransition || []
                };
                populateAnimatedSelectorsList();
            } else {
                animatedSelectorsData = { transitions: [], animations: [], triggersWithoutTransition: [] };
                populateAnimatedSelectorsList();
            }
            
            animationsLoaded = true;
            
            // Show content, hide loading
            animationsLoading.style.display = 'none';
            animationsContent.style.display = '';
            
        } catch (error) {
            console.error('Failed to load animations:', error);
            animationsLoading.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>${<?= json_encode(__admin('preview.loadAnimationsFailed') ?? 'Failed to load animations') ?>}</span>
            `;
        }
    }
    
    /**
     * Populate the keyframes list UI
     */
    function populateKeyframesList() {
        if (!keyframesList || !keyframesCount || !keyframesEmpty) return;
        
        const count = keyframesData.length;
        keyframesCount.textContent = count;
        
        if (count === 0) {
            keyframesList.innerHTML = '';
            keyframesEmpty.style.display = '';
            return;
        }
        
        keyframesEmpty.style.display = 'none';
        
        keyframesList.innerHTML = keyframesData.map(kf => `
            <div class="preview-keyframe-item" data-keyframe="${escapeHtml(kf.name)}">
                <div class="preview-keyframe-item__info">
                    <span class="preview-keyframe-item__name">@keyframes ${escapeHtml(kf.name)}</span>
                    <span class="preview-keyframe-item__frames">${kf.frameCount} ${kf.frameCount === 1 ? <?= json_encode(__admin('preview.frame') ?? 'frame') ?> : <?= json_encode(__admin('preview.frames') ?? 'frames') ?>}</span>
                </div>
                <div class="preview-keyframe-item__actions">
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--preview" 
                            data-action="preview" title="${<?= json_encode(__admin('preview.previewAnimation') ?? 'Preview animation') ?>}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--edit" 
                            data-action="edit" title="${<?= json_encode(__admin('common.edit') ?? 'Edit') ?>}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--delete" 
                            data-action="delete" title="${<?= json_encode(__admin('common.delete') ?? 'Delete') ?>}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');
        
        // Add event listeners
        keyframesList.querySelectorAll('.preview-keyframe-item__btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const item = btn.closest('.preview-keyframe-item');
                const keyframeName = item?.dataset.keyframe;
                const action = btn.dataset.action;
                
                if (!keyframeName) return;
                
                switch (action) {
                    case 'preview':
                        previewKeyframe(keyframeName);
                        break;
                    case 'edit':
                        editKeyframe(keyframeName);
                        break;
                    case 'delete':
                        deleteKeyframe(keyframeName);
                        break;
                }
            });
        });
    }
    
    /**
     * Initialize keyframe editor event listeners (called once at page load)
     */
    function initKeyframeEditor() {
        // Add click listener to keyframe-add-btn
        if (keyframeAddBtn) {
            keyframeAddBtn.addEventListener('click', () => createNewKeyframe());
        }
        
        // Keyframe modal event listeners
        if (keyframeModalClose) {
            keyframeModalClose.addEventListener('click', closeKeyframeModal);
        }
        if (keyframeCancelBtn) {
            keyframeCancelBtn.addEventListener('click', closeKeyframeModal);
        }
        if (keyframeSaveBtn) {
            keyframeSaveBtn.addEventListener('click', saveKeyframe);
        }
        if (keyframePreviewBtn) {
            keyframePreviewBtn.addEventListener('click', previewKeyframeAnimation);
        }
        // Close modal on backdrop click
        if (keyframeModal) {
            keyframeModal.addEventListener('click', (e) => {
                if (e.target === keyframeModal || e.target.classList.contains('preview-keyframe-modal__backdrop')) {
                    closeKeyframeModal();
                }
            });
        }
    }
    
    /**
     * Initialize Transform Editor event handlers
     */
    function initTransformEditorHandlers() {
        const transformModal = document.getElementById('transform-editor-modal');
        const transformClose = document.getElementById('transform-editor-close');
        const transformCancel = document.getElementById('transform-cancel');
        const transformApply = document.getElementById('transform-apply');
        const transformClear = document.getElementById('transform-clear');
        const transformAddBtn = document.getElementById('transform-add-btn');
        const transformDropdown = document.getElementById('transform-add-dropdown');
        
        // Close button
        if (transformClose) {
            transformClose.addEventListener('click', () => closeTransformEditor(false));
        }
        
        // Cancel button
        if (transformCancel) {
            transformCancel.addEventListener('click', () => closeTransformEditor(false));
        }
        
        // Apply button
        if (transformApply) {
            transformApply.addEventListener('click', () => closeTransformEditor(true));
        }
        
        // Clear All button
        if (transformClear) {
            transformClear.addEventListener('click', () => {
                transformFunctions = [];
                renderTransformEditor();
                updateTransformPreview();
            });
        }
        
        // Add Function button
        if (transformAddBtn) {
            transformAddBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleTransformDropdown();
            });
        }
        
        // Dropdown function buttons
        if (transformDropdown) {
            transformDropdown.querySelectorAll('[data-fn]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    addTransformFunction(e.target.dataset.fn);
                });
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!transformDropdown.contains(e.target) && e.target !== transformAddBtn) {
                    transformDropdown.classList.remove('transform-editor__dropdown--open');
                }
            });
        }
        
        // Close modal on backdrop click
        if (transformModal) {
            transformModal.addEventListener('click', (e) => {
                if (e.target === transformModal || e.target.classList.contains('preview-keyframe-modal__backdrop')) {
                    closeTransformEditor(false);
                }
            });
        }
    }
    
    /**
     * Populate the animated selectors list UI (grouped by base selector with pseudo-states)
     */
    function populateAnimatedSelectorsList() {
        if (!transitionsList || !animationsList || !transitionsCount || !animationsCount || !animatedEmpty) return;
        
        const transCount = animatedSelectorsData.transitions.length;
        const animCount = animatedSelectorsData.animations.length;
        const triggerCount = animatedSelectorsData.triggersWithoutTransition?.length || 0;
        
        transitionsCount.textContent = transCount;
        animationsCount.textContent = animCount;
        
        // Triggers count
        const triggersCountEl = document.getElementById('triggers-count');
        const triggersList = document.getElementById('triggers-list');
        if (triggersCountEl) triggersCountEl.textContent = triggerCount;
        
        // Show/hide empty state (now considers triggers too)
        const hasContent = transCount > 0 || animCount > 0 || triggerCount > 0;
        animatedEmpty.style.display = hasContent ? 'none' : '';
        
        // Populate transitions (grouped by base selector)
        if (transCount > 0) {
            transitionsList.innerHTML = animatedSelectorsData.transitions.map(item => renderAnimatedSelectorGroup(item, 'transition')).join('');
        } else {
            transitionsList.innerHTML = '<div class="preview-animated-selector--empty">' + <?= json_encode(__admin('preview.noTransitions') ?? 'No selectors with transitions') ?> + '</div>';
        }
        
        // Populate animations (grouped by base selector)
        if (animCount > 0) {
            animationsList.innerHTML = animatedSelectorsData.animations.map(item => renderAnimatedSelectorGroup(item, 'animation')).join('');
        } else {
            animationsList.innerHTML = '<div class="preview-animated-selector--empty">' + <?= json_encode(__admin('preview.noAnimationSelectors') ?? 'No selectors with animations') ?> + '</div>';
        }
        
        // Populate triggers without transition
        if (triggersList) {
            if (triggerCount > 0) {
                triggersList.innerHTML = animatedSelectorsData.triggersWithoutTransition.map(item => renderTriggerGroup(item)).join('');
            } else {
                triggersList.innerHTML = '<div class="preview-animated-selector--empty">' + <?= json_encode(__admin('preview.noTriggersWithoutTransition') ?? 'All triggers have transitions') ?> + '</div>';
            }
        }
        
        // Add click listeners for clickable selectors (opens State & Animation Editor)
        document.querySelectorAll('.preview-animated-selector--clickable').forEach(item => {
            item.addEventListener('click', (e) => {
                const selector = item.dataset.selector;
                if (selector) {
                    openTransitionEditor(selector);
                }
            });
        });
        
        // Add click listeners for clickable group headers (opens State & Animation Editor)
        document.querySelectorAll('.preview-animated-selector-group__header--clickable').forEach(header => {
            header.addEventListener('click', (e) => {
                // Don't trigger if clicking the toggle button
                if (e.target.closest('.preview-animated-selector-group__toggle')) return;
                const selector = header.dataset.selector;
                if (selector) {
                    openTransitionEditor(selector);
                }
            });
        });
        
        // Add click listeners for clickable state items (opens State & Animation Editor)
        document.querySelectorAll('.preview-animated-selector-state--clickable').forEach(state => {
            state.addEventListener('click', (e) => {
                const selector = state.dataset.selector;
                if (selector) {
                    // Extract base selector (remove pseudo-class)
                    const baseSelector = selector.replace(/:(hover|focus|active|visited|focus-within|focus-visible)$/i, '');
                    openTransitionEditor(baseSelector);
                }
            });
        });
        
        // Add toggle listeners for groups with states
        document.querySelectorAll('.preview-animated-selector-group__toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const group = toggle.closest('.preview-animated-selector-group');
                if (group) {
                    group.classList.toggle('preview-animated-selector-group--collapsed');
                }
            });
        });
    }
    
    /**
     * Render a grouped animated selector with its pseudo-states
     */
    function renderAnimatedSelectorGroup(item, type) {
        const hasStates = item.states && item.states.length > 0;
        
        // Details for the base selector
        let detailsHtml = '';
        if (type === 'transition' && item.parsed) {
            detailsHtml = formatTransitionDetails(item.parsed);
        } else if (type === 'animation' && item.parsed) {
            detailsHtml = formatAnimationDetails(item.parsed);
        } else if (item.properties) {
            const prop = type === 'transition' ? item.properties.transition : item.properties.animation;
            detailsHtml = escapeHtml(prop || '');
        }
        
        // Orphan hint HTML (for transitions without direct states but with related triggers)
        let orphanHintHtml = '';
        if (item.isOrphan && item.relatedTriggers && item.relatedTriggers.length > 0) {
            const relatedList = item.relatedTriggers.map(r => `<code>${escapeHtml(r.selector)}</code>`).join(', ');
            orphanHintHtml = `
                <div class="preview-animated-selector__orphan-hint">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span>${<?= json_encode(__admin('preview.mayBeTriggeredVia') ?? 'May be triggered via') ?>}: ${relatedList}</span>
                </div>
            `;
        } else if (item.isOrphan) {
            orphanHintHtml = `
                <div class="preview-animated-selector__orphan-hint preview-animated-selector__orphan-hint--warning">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span>${<?= json_encode(__admin('preview.noDirectTrigger') ?? 'No direct trigger found - may be unused') ?>}</span>
                </div>
            `;
        }
        
        if (!hasStates) {
            // Simple selector without states (possibly orphan) - clickable to open State & Animation Editor
            return `
                <div class="preview-animated-selector preview-animated-selector--clickable ${item.isOrphan ? 'preview-animated-selector--orphan' : ''}" data-selector="${escapeHtml(item.selector || item.baseSelector)}" data-type="${type}">
                    <div class="preview-animated-selector__main">
                        <code class="preview-animated-selector__name">${escapeHtml(item.baseSelector)}</code>
                        <span class="preview-animated-selector__details">${detailsHtml}</span>
                        <svg class="preview-animated-selector__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>
                        </svg>
                    </div>
                    ${orphanHintHtml}
                </div>
            `;
        }
        
        // Group with states - render as tree
        const statesHtml = item.states.map(state => {
            // Get changed properties (exclude transition/animation props for cleaner display)
            const changedProps = Object.entries(state.properties || {})
                .filter(([prop]) => !prop.startsWith('transition') && !prop.startsWith('animation'))
                .map(([prop, val]) => `${prop}: ${val}`)
                .slice(0, 3); // Show max 3 properties
            
            const propsDisplay = changedProps.length > 0 
                ? changedProps.join('; ') + (Object.keys(state.properties || {}).length > 3 ? '...' : '')
                : '';
                
            return `
                <div class="preview-animated-selector-state preview-animated-selector-state--clickable" data-selector="${escapeHtml(state.selector)}">
                    <code class="preview-animated-selector-state__pseudo">${escapeHtml(state.pseudo)}</code>
                    <span class="preview-animated-selector-state__props">${escapeHtml(propsDisplay)}</span>
                </div>
            `;
        }).join('');
        
        return `
            <div class="preview-animated-selector-group" data-type="${type}">
                <div class="preview-animated-selector-group__header preview-animated-selector-group__header--clickable" data-selector="${escapeHtml(item.selector || item.baseSelector)}">
                    <button type="button" class="preview-animated-selector-group__toggle" title="${<?= json_encode(__admin('preview.toggleStates') ?? 'Toggle states') ?>}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <code class="preview-animated-selector__name">${escapeHtml(item.baseSelector)}</code>
                    <span class="preview-animated-selector__details">${detailsHtml}</span>
                    <span class="preview-animated-selector__state-count">${item.states.length} ${<?= json_encode(__admin('preview.states') ?? 'states') ?>}</span>
                    <svg class="preview-animated-selector__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>
                    </svg>
                </div>
                <div class="preview-animated-selector-group__states">
                    ${statesHtml}
                </div>
            </div>
        `;
    }
    
    /**
     * Render a trigger-only group (pseudo-states without transition/animation)
     */
    function renderTriggerGroup(item) {
        const statesHtml = item.states.map(state => {
            // Show properties that change
            const changedProps = Object.entries(state.properties || {})
                .map(([prop, val]) => `${prop}: ${val}`)
                .slice(0, 3);
            
            const propsDisplay = changedProps.length > 0 
                ? changedProps.join('; ') + (Object.keys(state.properties || {}).length > 3 ? '...' : '')
                : '';
                
            return `
                <div class="preview-animated-selector-state preview-animated-selector-state--trigger preview-animated-selector-state--clickable" data-selector="${escapeHtml(state.selector)}">
                    <code class="preview-animated-selector-state__pseudo">${escapeHtml(state.pseudo)}</code>
                    <span class="preview-animated-selector-state__props">${escapeHtml(propsDisplay)}</span>
                </div>
            `;
        }).join('');
        
        return `
            <div class="preview-animated-selector-group preview-animated-selector-group--trigger">
                <div class="preview-animated-selector-group__header preview-animated-selector-group__header--clickable" data-selector="${escapeHtml(item.selector || item.baseSelector)}">
                    <button type="button" class="preview-animated-selector-group__toggle" title="${<?= json_encode(__admin('preview.toggleStates') ?? 'Toggle states') ?>}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <code class="preview-animated-selector__name preview-animated-selector__name--muted">${escapeHtml(item.baseSelector)}</code>
                    <span class="preview-animated-selector__state-count preview-animated-selector__state-count--muted">${item.states.length} ${<?= json_encode(__admin('preview.states') ?? 'states') ?>}</span>
                    <svg class="preview-animated-selector__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>
                    </svg>
                </div>
                <div class="preview-animated-selector-group__states">
                    ${statesHtml}
                </div>
            </div>
        `;
    }
    
    /**
     * Format transition details for display (handles array of parsed transitions)
     */
    function formatTransitionDetails(parsed) {
        // parsed is an array of transitions
        if (!Array.isArray(parsed) || parsed.length === 0) return '';
        
        return parsed.map(t => {
            const parts = [];
            if (t.property && t.property !== 'all') parts.push(t.property);
            if (t.duration) parts.push(t.duration);
            if (t.timing && t.timing !== 'ease') parts.push(t.timing);
            return parts.join(' ');
        }).filter(Boolean).join(', ') || 'transition';
    }
    
    /**
     * Format animation details for display (handles array of parsed animations)
     */
    function formatAnimationDetails(parsed) {
        // parsed is an array of animations
        if (!Array.isArray(parsed) || parsed.length === 0) return '';
        
        return parsed.map(a => {
            const parts = [];
            if (a.name) parts.push(a.name);
            if (a.duration) parts.push(a.duration);
            if (a.iterationCount && a.iterationCount !== '1') parts.push(a.iterationCount + 'x');
            return parts.join(' ');
        }).filter(Boolean).join(', ') || 'animation';
    }
    
    /**
     * Preview a keyframe animation - opens Animation Preview Modal
     */
    async function previewKeyframe(keyframeName) {
        if (!animationPreviewModal) return;
        
        currentPreviewKeyframe = keyframeName;
        
        // Update modal header
        if (animationPreviewName) {
            animationPreviewName.textContent = `@keyframes ${keyframeName}`;
        }
        
        // Reset controls to defaults
        if (animationPreviewDuration) animationPreviewDuration.value = 1000;
        if (animationPreviewTiming) animationPreviewTiming.value = 'ease';
        if (animationPreviewDelay) animationPreviewDelay.value = 0;
        if (animationPreviewCount) animationPreviewCount.value = 1;
        if (animationPreviewInfinite) animationPreviewInfinite.checked = false;
        
        // Inject the keyframe CSS into the admin page so the animation can play
        await injectKeyframeForPreview(keyframeName);
        
        // Update CSS preview
        updateAnimationPreviewCss();
        
        // Show modal
        animationPreviewModal.classList.add('preview-keyframe-modal--visible');
        
        // Auto-play the animation
        setTimeout(() => playAnimationPreview(), 100);
    }
    
    /**
     * Inject keyframe CSS into admin page for preview
     * @param {string} keyframeName - Name of the keyframe to inject
     */
    async function injectKeyframeForPreview(keyframeName) {
        // Remove any previous preview keyframe style
        const existingStyle = document.getElementById('animation-preview-keyframe-style');
        if (existingStyle) existingStyle.remove();
        
        // Find the keyframe data
        const keyframeData = keyframesData.find(kf => kf.name === keyframeName);
        if (!keyframeData) return;
        
        // Fetch the full keyframe definition from API
        try {
            const response = await fetch(managementUrl + 'getKeyframes/' + encodeURIComponent(keyframeName), {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.status === 200 && data.data?.frames) {
                    // Reconstruct CSS from frames data
                    let css = `@keyframes ${keyframeName} {\n`;
                    for (const [key, value] of Object.entries(data.data.frames)) {
                        css += `  ${key} { ${value} }\n`;
                    }
                    css += '}';
                    
                    // Create style element with the keyframe
                    const style = document.createElement('style');
                    style.id = 'animation-preview-keyframe-style';
                    style.textContent = css;
                    document.head.appendChild(style);
                }
            }
        } catch (e) {
            console.error('Failed to fetch keyframe for preview:', e);
        }
    }
    
    /**
     * Update the animation CSS preview text
     */
    function updateAnimationPreviewCss() {
        if (!animationPreviewCss || !currentPreviewKeyframe) return;
        
        const duration = animationPreviewDuration?.value || 1000;
        const timing = animationPreviewTiming?.value || 'ease';
        const delay = animationPreviewDelay?.value || 0;
        const isInfinite = animationPreviewInfinite?.checked;
        const count = isInfinite ? 'infinite' : (animationPreviewCount?.value || 1);
        
        animationPreviewCss.textContent = `animation: ${duration}ms ${timing} ${delay}ms ${currentPreviewKeyframe};\nanimation-iteration-count: ${count};`;
    }
    
    /**
     * Play/replay the animation preview
     */
    function playAnimationPreview() {
        if (!animationPreviewStage || !currentPreviewKeyframe) return;
        
        const duration = animationPreviewDuration?.value || 1000;
        const timing = animationPreviewTiming?.value || 'ease';
        const delay = animationPreviewDelay?.value || 0;
        const isInfinite = animationPreviewInfinite?.checked;
        const count = isInfinite ? 'infinite' : (animationPreviewCount?.value || 1);
        
        // Get current logo or find it
        let logo = document.getElementById('animation-preview-logo');
        
        if (logo) {
            // Store src before removing
            const logoSrc = logo.src;
            const logoAlt = logo.alt;
            
            // Remove the logo completely
            logo.remove();
            
            // Small delay to ensure DOM updates
            requestAnimationFrame(() => {
                // Create fresh logo element
                const newLogo = document.createElement('img');
                newLogo.id = 'animation-preview-logo';
                newLogo.className = 'animation-preview__logo';
                newLogo.src = logoSrc;
                newLogo.alt = logoAlt;
                
                // Apply animation
                newLogo.style.animation = `${currentPreviewKeyframe} ${duration}ms ${timing} ${delay}ms`;
                newLogo.style.animationIterationCount = count;
                newLogo.style.animationFillMode = 'forwards';
                
                animationPreviewStage.appendChild(newLogo);
            });
        }
    }
    
    /**
     * Close the Animation Preview Modal
     */
    function closeAnimationPreview() {
        if (animationPreviewModal) {
            animationPreviewModal.classList.remove('preview-keyframe-modal--visible');
        }
        
        // Stop any running animation
        const logo = document.getElementById('animation-preview-logo');
        if (logo) {
            logo.style.animation = '';
        }
        
        currentPreviewKeyframe = null;
    }
    
    /**
     * Initialize Animation Preview Modal event listeners
     */
    function initAnimationPreviewModal() {
        // Close button
        if (animationPreviewCloseBtn) {
            animationPreviewCloseBtn.addEventListener('click', closeAnimationPreview);
        }
        if (animationPreviewDoneBtn) {
            animationPreviewDoneBtn.addEventListener('click', closeAnimationPreview);
        }
        
        // Backdrop click to close
        const backdrop = animationPreviewModal?.querySelector('.preview-keyframe-modal__backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', closeAnimationPreview);
        }
        
        // Play button
        if (animationPreviewPlayBtn) {
            animationPreviewPlayBtn.addEventListener('click', playAnimationPreview);
        }
        
        // Update CSS preview when controls change
        const controls = [animationPreviewDuration, animationPreviewTiming, animationPreviewDelay, animationPreviewCount, animationPreviewInfinite];
        controls.forEach(ctrl => {
            if (ctrl) {
                ctrl.addEventListener('change', updateAnimationPreviewCss);
                ctrl.addEventListener('input', updateAnimationPreviewCss);
            }
        });
        
        // Infinite checkbox disables count input
        if (animationPreviewInfinite) {
            animationPreviewInfinite.addEventListener('change', () => {
                if (animationPreviewCount) {
                    animationPreviewCount.disabled = animationPreviewInfinite.checked;
                }
            });
        }
    }
    
    // Initialize on DOM ready
    initAnimationPreviewModal();
    
    /**
     * Stop keyframe preview animation
     */
    function stopKeyframePreview() {
        const iframe = document.getElementById('preview-frame');
        if (!iframe?.contentWindow) return;
        
        // Remove animation from any element
        const animated = iframe.contentDocument.querySelector('[style*="animation"]');
        if (animated) {
            animated.style.animation = '';
        }
        
        keyframePreviewActive = null;
        updateKeyframePreviewButtons();
    }
    
    /**
     * Update keyframe preview button states
     */
    function updateKeyframePreviewButtons() {
        document.querySelectorAll('.preview-keyframe-item__btn--preview').forEach(btn => {
            const item = btn.closest('.preview-keyframe-item');
            const name = item?.dataset.keyframe;
            btn.classList.toggle('preview-keyframe-item__btn--active', name === keyframePreviewActive);
        });
    }
    
    /**
     * Edit a keyframe (opens keyframe editor modal)
     */
    async function editKeyframe(keyframeName) {
        try {
            // Fetch keyframe data from API
            const response = await fetch(managementUrl + 'getKeyframes/' + encodeURIComponent(keyframeName), {
                method: 'GET',
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            
            const data = await response.json();
            
            // API returns { status, data: { name, frames } } for single keyframe
            if (data.status === 200 && data.data && data.data.frames) {
                const keyframeData = data.data.frames;
                const frames = parseKeyframeData(keyframeData);
                openKeyframeModal(keyframeName, frames, 'edit');
            } else {
                throw new Error(data.message || 'Keyframe not found');
            }
        } catch (error) {
            console.error('Failed to load keyframe:', error);
            showToast(<?= json_encode(__admin('preview.loadKeyframeFailed') ?? 'Failed to load keyframe') ?>, 'error');
        }
    }
    
    /**
     * Create a new keyframe
     */
    function createNewKeyframe() {
        // Default frames for new keyframe
        const defaultFrames = [
            { percent: 0, properties: [{ property: 'opacity', value: '0' }] },
            { percent: 100, properties: [{ property: 'opacity', value: '1' }] }
        ];
        openKeyframeModal('', defaultFrames, 'create');
    }
    
    /**
     * Parse keyframe data into structured format
     * Handles both object format {from: 'opacity: 0', to: 'opacity: 1'} from API
     * and CSS string format "from { opacity: 0; } to { opacity: 1; }"
     */
    function parseKeyframeData(keyframeData) {
        const frames = [];
        
        // Check if data is an object (from API) or string (CSS)
        if (typeof keyframeData === 'object' && keyframeData !== null) {
            // Object format: { "from": "opacity: 0", "to": "opacity: 1", "50%": "opacity: 0.5" }
            for (const [key, value] of Object.entries(keyframeData)) {
                let percent = key.trim();
                // Convert from/to to percentages
                if (percent.toLowerCase() === 'from') percent = 0;
                else if (percent.toLowerCase() === 'to') percent = 100;
                else percent = parseFloat(percent);
                
                const properties = [];
                // Parse CSS properties from the value string
                const propRegex = /([a-z-]+)\s*:\s*([^;]+);?/gi;
                let propMatch;
                while ((propMatch = propRegex.exec(value)) !== null) {
                    properties.push({
                        property: propMatch[1].trim(),
                        value: propMatch[2].trim()
                    });
                }
                
                frames.push({ percent, properties });
            }
        } else if (typeof keyframeData === 'string') {
            // CSS string format: "from { opacity: 0; } to { opacity: 1; }"
            const frameRegex = /([\d.]+%|from|to)\s*\{([^}]*)\}/gi;
            let match;
            
            while ((match = frameRegex.exec(keyframeData)) !== null) {
                let percent = match[1].trim();
                // Convert from/to to percentages
                if (percent.toLowerCase() === 'from') percent = 0;
                else if (percent.toLowerCase() === 'to') percent = 100;
                else percent = parseFloat(percent);
                
                const propertiesStr = match[2].trim();
                const properties = [];
                
                // Parse CSS properties
                const propRegex = /([a-z-]+)\s*:\s*([^;]+);?/gi;
                let propMatch;
                while ((propMatch = propRegex.exec(propertiesStr)) !== null) {
                    properties.push({
                        property: propMatch[1].trim(),
                        value: propMatch[2].trim()
                    });
                }
                
                frames.push({ percent, properties });
            }
        }
        
        // Sort by percentage
        frames.sort((a, b) => a.percent - b.percent);
        
        return frames;
    }
    
    /**
     * Open the keyframe editor modal
     */
    function openKeyframeModal(name, frames, mode) {
        keyframeEditorMode = mode;
        editingKeyframeName = name;
        keyframeFrames = JSON.parse(JSON.stringify(frames)); // Deep copy
        selectedFramePercent = frames.length > 0 ? frames[0].percent : 0;
        
        // Update modal title
        keyframeModalTitle.textContent = mode === 'edit' 
            ? (<?= json_encode(__admin('preview.editKeyframe') ?? 'Edit Keyframe') ?>)
            : (<?= json_encode(__admin('preview.createKeyframe') ?? 'Create New Keyframe') ?>);
        
        // Set name input
        keyframeNameInput.value = name;
        keyframeNameInput.readOnly = (mode === 'edit');
        
        // Render timeline and frames
        renderKeyframeTimeline();
        renderKeyframeFrames();
        
        // Show modal
        keyframeModal.classList.add('preview-keyframe-modal--visible');
        document.body.style.overflow = 'hidden';
    }
    
    /**
     * Close the keyframe editor modal
     */
    function closeKeyframeModal() {
        keyframeModal.classList.remove('preview-keyframe-modal--visible');
        document.body.style.overflow = '';
        keyframeEditorMode = null;
        editingKeyframeName = null;
        keyframeFrames = [];
        selectedFramePercent = null;
        
        // Stop any preview
        stopKeyframePreview();
    }
    
    /**
     * Render the timeline with frame markers
     */
    function renderKeyframeTimeline() {
        keyframeTimeline.innerHTML = '';
        
        // Create timeline bar
        const bar = document.createElement('div');
        bar.className = 'preview-keyframe-modal__timeline-bar';
        keyframeTimeline.appendChild(bar);
        
        // Add frame markers
        keyframeFrames.forEach((frame, index) => {
            const marker = document.createElement('div');
            marker.className = 'preview-keyframe-modal__timeline-marker';
            if (frame.percent === selectedFramePercent) {
                marker.classList.add('preview-keyframe-modal__timeline-marker--selected');
            }
            marker.style.left = frame.percent + '%';
            marker.title = frame.percent + '% - <?= __admin('preview.dragToMoveFrame') ?? 'Drag to move' ?>';
            marker.dataset.percent = frame.percent;
            marker.draggable = false; // We'll handle drag manually
            
            // Click to select
            marker.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!marker.dataset.dragging) {
                    selectFrame(frame.percent);
                }
            });
            
            // Drag to move marker
            let isDragging = false;
            let startX = 0;
            
            marker.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                isDragging = true;
                startX = e.clientX;
                marker.classList.add('preview-keyframe-modal__timeline-marker--dragging');
                marker.dataset.dragging = 'true';
                
                const originalPercent = frame.percent;
                const barRect = bar.getBoundingClientRect();
                
                const onMouseMove = (moveEvent) => {
                    if (!isDragging) return;
                    const newPercent = Math.round(((moveEvent.clientX - barRect.left) / barRect.width) * 100);
                    const clampedPercent = Math.max(0, Math.min(100, newPercent));
                    
                    // Update marker position visually
                    marker.style.left = clampedPercent + '%';
                    marker.title = clampedPercent + '%';
                };
                
                const onMouseUp = (upEvent) => {
                    if (!isDragging) return;
                    isDragging = false;
                    marker.classList.remove('preview-keyframe-modal__timeline-marker--dragging');
                    
                    const newPercent = Math.round(((upEvent.clientX - barRect.left) / barRect.width) * 100);
                    const clampedPercent = Math.max(0, Math.min(100, newPercent));
                    
                    // Check if we actually moved
                    if (Math.abs(upEvent.clientX - startX) < 5) {
                        // It was a click, not drag - select frame
                        delete marker.dataset.dragging;
                        selectFrame(frame.percent);
                    } else if (clampedPercent !== originalPercent) {
                        // Check if another frame exists at this percent
                        const existingFrame = keyframeFrames.find(f => f.percent === clampedPercent && f !== frame);
                        if (existingFrame) {
                            // Revert - can't move to existing position
                            marker.style.left = originalPercent + '%';
                            showNotification(<?= json_encode(__admin('preview.frameExists') ?? 'A frame already exists at this percentage') ?>, 'error');
                        } else {
                            // Update frame percent
                            frame.percent = clampedPercent;
                            if (selectedFramePercent === originalPercent) {
                                selectedFramePercent = clampedPercent;
                            }
                            // Re-sort frames
                            keyframeFrames.sort((a, b) => a.percent - b.percent);
                            renderKeyframeTimeline();
                            renderKeyframeFrames();
                        }
                    }
                    
                    setTimeout(() => delete marker.dataset.dragging, 10);
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                };
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
            
            keyframeTimeline.appendChild(marker);
        });
        
        // Click on timeline to add frame at position
        bar.addEventListener('click', (e) => {
            const rect = bar.getBoundingClientRect();
            const percent = Math.round(((e.clientX - rect.left) / rect.width) * 100);
            // Check if frame already exists at this percent
            if (!keyframeFrames.find(f => f.percent === percent)) {
                addKeyframeFrame(percent);
            } else {
                selectFrame(percent);
            }
        });
    }
    
    /**
     * Select a frame for editing
     */
    function selectFrame(percent) {
        selectedFramePercent = percent;
        renderKeyframeTimeline();
        renderKeyframeFrames();
    }
    
    /**
     * Render the frame editors
     */
    function renderKeyframeFrames() {
        keyframeFramesContainer.innerHTML = '';
        
        // Common CSS animation properties for dropdown
        const commonProperties = [
            '', // Custom option
            'opacity',
            'transform',
            'background-color',
            'color',
            'width',
            'height',
            'top',
            'left',
            'right',
            'bottom',
            'margin',
            'padding',
            'border-radius',
            'box-shadow',
            'filter',
            'clip-path',
            'scale',
            'rotate',
            'translate',
            'skew',
            'visibility',
            'z-index',
            'font-size',
            'letter-spacing',
            'line-height',
            'text-shadow'
        ];
        
        keyframeFrames.forEach((frame, frameIndex) => {
            const frameEl = document.createElement('div');
            frameEl.className = 'preview-keyframe-modal__frame';
            if (frame.percent === selectedFramePercent) {
                frameEl.classList.add('preview-keyframe-modal__frame--selected');
            }
            
            // Frame header
            const header = document.createElement('div');
            header.className = 'preview-keyframe-modal__frame-header';
            header.innerHTML = `
                <div class="preview-keyframe-modal__frame-percent-group">
                    <input type="number" class="preview-keyframe-modal__frame-percent-input" 
                           value="${frame.percent}" min="0" max="100" step="1"
                           title="<?= __admin('preview.enterFramePercent') ?? 'Frame percentage (0-100)' ?>">
                    <span class="preview-keyframe-modal__frame-percent-symbol">%</span>
                </div>
                <div class="preview-keyframe-modal__frame-actions">
                    ${keyframeFrames.length > 1 ? `
                        <button type="button" class="preview-keyframe-modal__delete-frame" title="<?= __admin('preview.deleteFrame') ?? 'Delete frame' ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    ` : ''}
                </div>
            `;
            
            // Percent input change handler
            const percentInput = header.querySelector('.preview-keyframe-modal__frame-percent-input');
            percentInput.addEventListener('change', (e) => {
                const newPercent = parseInt(e.target.value, 10);
                if (isNaN(newPercent) || newPercent < 0 || newPercent > 100) {
                    e.target.value = frame.percent;
                    return;
                }
                // Check if another frame exists at this percent
                const existingFrame = keyframeFrames.find(f => f.percent === newPercent && f !== frame);
                if (existingFrame) {
                    e.target.value = frame.percent;
                    showNotification(<?= json_encode(__admin('preview.frameExists') ?? 'A frame already exists at this percentage') ?>, 'error');
                    return;
                }
                // Update frame percent
                const oldPercent = frame.percent;
                frame.percent = newPercent;
                if (selectedFramePercent === oldPercent) {
                    selectedFramePercent = newPercent;
                }
                // Re-sort frames
                keyframeFrames.sort((a, b) => a.percent - b.percent);
                renderKeyframeTimeline();
                renderKeyframeFrames();
            });
            
            // Click header to select frame
            header.addEventListener('click', (e) => {
                if (!e.target.closest('input') && !e.target.closest('button')) {
                    selectFrame(frame.percent);
                }
            });
            
            // Delete frame button
            const deleteBtn = header.querySelector('.preview-keyframe-modal__delete-frame');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    deleteKeyframeFrame(frame.percent);
                });
            }
            
            frameEl.appendChild(header);
            
            // Properties container
            const propsContainer = document.createElement('div');
            propsContainer.className = 'preview-keyframe-modal__properties';
            
            frame.properties.forEach((prop, propIndex) => {
                const propEl = document.createElement('div');
                propEl.className = 'preview-keyframe-modal__property';
                
                // Check if property is in common list
                const isCommonProp = commonProperties.includes(prop.property);
                
                // Build select options
                let selectOptions = commonProperties.map(p => {
                    if (p === '') {
                        return `<option value="" ${!isCommonProp ? 'selected' : ''}><?= __admin('preview.customProperty') ?? 'Custom...' ?></option>`;
                    }
                    return `<option value="${p}" ${prop.property === p ? 'selected' : ''}>${p}</option>`;
                }).join('');
                
                propEl.innerHTML = `
                    <div class="preview-keyframe-modal__property-name-group">
                        <select class="preview-keyframe-modal__property-select" 
                                data-frame="${frameIndex}" data-prop="${propIndex}">
                            ${selectOptions}
                        </select>
                        <input type="text" class="preview-keyframe-modal__property-name ${isCommonProp ? 'preview-keyframe-modal__property-name--hidden' : ''}" 
                               value="${escapeHTML(prop.property)}" 
                               placeholder="<?= __admin('preview.keyframePropertyName') ?? 'property' ?>"
                               data-frame="${frameIndex}" data-prop="${propIndex}" data-field="property">
                    </div>
                    <span class="preview-keyframe-modal__property-colon">:</span>
                    ${renderPropertyValueInput(prop.property, prop.value, frameIndex, propIndex)}
                    <button type="button" class="preview-keyframe-modal__delete-property" title="<?= __admin('preview.deleteKeyframeProperty') ?? 'Delete property' ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                `;
                
                // Property select handler
                const propSelect = propEl.querySelector('.preview-keyframe-modal__property-select');
                const nameInput = propEl.querySelector('.preview-keyframe-modal__property-name');
                const deletePropertyBtn = propEl.querySelector('.preview-keyframe-modal__delete-property');
                
                propSelect.addEventListener('change', (e) => {
                    if (e.target.value === '') {
                        // Custom - show text input
                        nameInput.classList.remove('preview-keyframe-modal__property-name--hidden');
                        nameInput.focus();
                    } else {
                        // Common property selected
                        nameInput.classList.add('preview-keyframe-modal__property-name--hidden');
                        nameInput.value = e.target.value;
                        keyframeFrames[frameIndex].properties[propIndex].property = e.target.value;
                        // Re-render frames to update input type for new property
                        renderKeyframeFrames();
                    }
                });
                
                nameInput.addEventListener('input', (e) => {
                    keyframeFrames[frameIndex].properties[propIndex].property = e.target.value;
                });
                
                // Attach event handlers based on input type
                attachPropertyValueHandlers(propEl, frameIndex, propIndex, prop.property);
                
                deletePropertyBtn.addEventListener('click', () => {
                    deleteKeyframeProperty(frameIndex, propIndex);
                });
                
                propsContainer.appendChild(propEl);
            });
            
            // Add property button
            const addPropBtn = document.createElement('button');
            addPropBtn.type = 'button';
            addPropBtn.className = 'preview-keyframe-modal__add-property';
            addPropBtn.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <?= __admin('preview.addKeyframeProperty') ?? 'Add Property' ?>
            `;
            addPropBtn.addEventListener('click', () => addKeyframeProperty(frameIndex));
            
            propsContainer.appendChild(addPropBtn);
            frameEl.appendChild(propsContainer);
            
            keyframeFramesContainer.appendChild(frameEl);
        });
        
        // Add frame button
        const addFrameBtn = document.createElement('button');
        addFrameBtn.type = 'button';
        addFrameBtn.className = 'preview-keyframe-modal__add-frame';
        addFrameBtn.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <?= __admin('preview.addFrame') ?? 'Add Frame' ?>
        `;
        addFrameBtn.addEventListener('click', () => promptAddFrame());
        keyframeFramesContainer.appendChild(addFrameBtn);
    }
    
    /**
     * Prompt user for frame percentage and add frame
     */
    function promptAddFrame() {
        const percent = prompt(<?= json_encode(__admin('preview.enterFramePercent') ?? 'Enter frame percentage (0-100):') ?>, '50');
        if (percent !== null) {
            const percentNum = parseInt(percent, 10);
            if (!isNaN(percentNum) && percentNum >= 0 && percentNum <= 100) {
                if (keyframeFrames.find(f => f.percent === percentNum)) {
                    showToast(<?= json_encode(__admin('preview.frameExists') ?? 'A frame already exists at this percentage') ?>, 'warning');
                } else {
                    addKeyframeFrame(percentNum);
                }
            } else {
                showToast(<?= json_encode(__admin('preview.invalidPercent') ?? 'Please enter a valid percentage (0-100)') ?>, 'error');
            }
        }
    }
    
    /**
     * Add a new keyframe frame at specified percentage
     */
    function addKeyframeFrame(percent) {
        // Find surrounding frames to interpolate properties
        let beforeFrame = null;
        let afterFrame = null;
        
        for (const frame of keyframeFrames) {
            if (frame.percent < percent) {
                beforeFrame = frame;
            } else if (frame.percent > percent && !afterFrame) {
                afterFrame = frame;
            }
        }
        
        // Create new frame with inherited properties
        const newFrame = {
            percent: percent,
            properties: []
        };
        
        // Copy properties from nearest frame
        const sourceFrame = beforeFrame || afterFrame || keyframeFrames[0];
        if (sourceFrame) {
            newFrame.properties = sourceFrame.properties.map(p => ({ ...p }));
        } else {
            newFrame.properties = [{ property: 'opacity', value: '1' }];
        }
        
        keyframeFrames.push(newFrame);
        keyframeFrames.sort((a, b) => a.percent - b.percent);
        
        selectedFramePercent = percent;
        renderKeyframeTimeline();
        renderKeyframeFrames();
    }
    
    /**
     * Delete a keyframe frame
     */
    function deleteKeyframeFrame(percent) {
        if (keyframeFrames.length <= 1) {
            showToast(<?= json_encode(__admin('preview.cannotDeleteLastFrame') ?? 'Cannot delete the last frame') ?>, 'warning');
            return;
        }
        
        keyframeFrames = keyframeFrames.filter(f => f.percent !== percent);
        
        // Select another frame if current one was deleted
        if (selectedFramePercent === percent) {
            selectedFramePercent = keyframeFrames[0].percent;
        }
        
        renderKeyframeTimeline();
        renderKeyframeFrames();
    }
    
    /**
     * Add a property to a frame
     */
    function addKeyframeProperty(frameIndex) {
        keyframeFrames[frameIndex].properties.push({
            property: '',
            value: ''
        });
        renderKeyframeFrames();
        
        // Focus the new property name input
        setTimeout(() => {
            const inputs = keyframeFramesContainer.querySelectorAll(`.preview-keyframe-modal__property-name[data-frame="${frameIndex}"]`);
            const lastInput = inputs[inputs.length - 1];
            if (lastInput) lastInput.focus();
        }, 0);
    }
    
    /**
     * Delete a property from a frame
     */
    function deleteKeyframeProperty(frameIndex, propIndex) {
        if (keyframeFrames[frameIndex].properties.length <= 1) {
            showToast(<?= json_encode(__admin('preview.cannotDeleteLastProperty') ?? 'Cannot delete the last property') ?>, 'warning');
            return;
        }
        
        keyframeFrames[frameIndex].properties.splice(propIndex, 1);
        renderKeyframeFrames();
    }
    
    /**
     * Generate keyframe CSS from frames data
     */
    function generateKeyframeCSS(name, frames) {
        let css = `@keyframes ${name} {\n`;
        
        frames.forEach(frame => {
            // Always use percentage format (0%, 100%) for consistency
            const percent = frame.percent + '%';
            css += `    ${percent} {\n`;
            
            frame.properties.forEach(prop => {
                if (prop.property && prop.value) {
                    css += `        ${prop.property}: ${prop.value};\n`;
                }
            });
            
            css += `    }\n`;
        });
        
        css += `}`;
        return css;
    }
    
    /**
     * Preview the keyframe animation
     */
    let keyframePreviewStyleElement = null;
    
    function previewKeyframeAnimation() {
        const name = keyframeNameInput.value.trim() || 'preview-temp-animation';
        
        if (keyframeFrames.length === 0) {
            showToast(<?= json_encode(__admin('preview.noFramesToPreview') ?? 'No frames to preview') ?>, 'warning');
            return;
        }
        
        // Generate keyframe CSS
        const keyframeCSS = generateKeyframeCSS(name, keyframeFrames);
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            
            // Remove existing preview style
            stopKeyframePreview();
            
            // Create and inject style element
            keyframePreviewStyleElement = iframeDoc.createElement('style');
            keyframePreviewStyleElement.id = 'preview-keyframe-animation-style';
            keyframePreviewStyleElement.textContent = keyframeCSS;
            iframeDoc.head.appendChild(keyframePreviewStyleElement);
            
            // Find an element to animate (use selected element or first animated element)
            let targetElement = null;
            if (selectedElement && selectedElement.element) {
                targetElement = selectedElement.element;
            } else {
                // Try to find any element in the iframe
                targetElement = iframeDoc.body;
            }
            
            if (targetElement) {
                // Store original animation
                const originalAnimation = targetElement.style.animation;
                
                // Apply preview animation
                targetElement.style.animation = `${name} 1s ease-in-out infinite`;
                
                // Store for cleanup
                targetElement.dataset.previewOriginalAnimation = originalAnimation;
                targetElement.dataset.previewAnimating = 'true';
                
                showToast(<?= json_encode(__admin('preview.previewingAnimation') ?? 'Previewing animation...') ?>, 'info');
            }
        } catch (error) {
            console.error('Failed to preview animation:', error);
            showToast(<?= json_encode(__admin('preview.previewFailed') ?? 'Failed to preview animation') ?>, 'error');
        }
    }
    
    /**
     * Stop the keyframe preview animation
     */
    function stopKeyframePreview() {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            
            // Remove preview style element
            const existingStyle = iframeDoc.getElementById('preview-keyframe-animation-style');
            if (existingStyle) {
                existingStyle.remove();
            }
            
            // Restore original animation on elements
            const animatingElements = iframeDoc.querySelectorAll('[data-preview-animating="true"]');
            animatingElements.forEach(el => {
                el.style.animation = el.dataset.previewOriginalAnimation || '';
                delete el.dataset.previewOriginalAnimation;
                delete el.dataset.previewAnimating;
            });
            
            keyframePreviewStyleElement = null;
        } catch (error) {
            console.error('Failed to stop preview:', error);
        }
    }
    
    /**
     * Save the keyframe
     */
    async function saveKeyframe() {
        const name = keyframeNameInput.value.trim();
        
        // Validate name
        if (!name) {
            showToast(<?= json_encode(__admin('preview.keyframeNameRequired') ?? 'Keyframe name is required') ?>, 'error');
            keyframeNameInput.focus();
            return;
        }
        
        // Validate name format (alphanumeric, hyphens, underscores)
        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(name)) {
            showToast(<?= json_encode(__admin('preview.invalidKeyframeName') ?? 'Invalid keyframe name. Use letters, numbers, hyphens, and underscores.') ?>, 'error');
            keyframeNameInput.focus();
            return;
        }
        
        // Determine if we're allowed to overwrite
        // - Edit mode: always allowed (editing same keyframe)
        // - Create mode: only if user confirms
        let allowOverwrite = keyframeEditorMode === 'edit';
        
        // Check if keyframe already exists when creating new one
        if (keyframeEditorMode === 'create') {
            const existingKeyframe = keyframesData.find(kf => kf.name === name);
            if (existingKeyframe) {
                const confirmOverwrite = confirm(
                    <?= json_encode(__admin('preview.keyframeExistsConfirm') ?? 'A keyframe with this name already exists. Do you want to replace it?') ?> +
                    `\n\n@keyframes ${name}`
                );
                if (!confirmOverwrite) {
                    keyframeNameInput.focus();
                    keyframeNameInput.select();
                    return;
                }
                allowOverwrite = true; // User confirmed overwrite
            }
        }
        
        // Validate frames have at least one property
        const validFrames = keyframeFrames.filter(f => 
            f.properties.some(p => p.property && p.value)
        );
        
        if (validFrames.length === 0) {
            showToast(<?= json_encode(__admin('preview.atLeastOneFrame') ?? 'At least one frame with properties is required') ?>, 'error');
            return;
        }
        
        // Convert frames to API format { "0%": "opacity: 0;", "100%": "opacity: 1;" }
        const framesObj = {};
        validFrames.forEach(frame => {
            // Always use percentage format for consistency
            const key = frame.percent + '%';
            const propsStr = frame.properties
                .filter(p => p.property && p.value)
                .map(p => `${p.property}: ${p.value};`)
                .join(' ');
            framesObj[key] = propsStr;
        });
        
        try {
            // Stop preview before saving
            stopKeyframePreview();
            
            const response = await fetch(managementUrl + 'setKeyframes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    name: name,
                    frames: framesObj,
                    allowOverwrite: allowOverwrite
                })
            });
            
            const data = await response.json();
            
            // Handle 409 Conflict (keyframe exists but allowOverwrite was false)
            // This can happen if client data was stale (keyframe created in another tab/session)
            if (data.status === 409) {
                const confirmOverwrite = confirm(
                    <?= json_encode(__admin('preview.keyframeExistsConfirm') ?? 'A keyframe with this name already exists. Do you want to replace it?') ?> +
                    `\n\n@keyframes ${name}`
                );
                if (confirmOverwrite) {
                    // Retry with allowOverwrite: true
                    const retryResponse = await fetch(managementUrl + 'setKeyframes', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                        },
                        body: JSON.stringify({
                            name: name,
                            frames: framesObj,
                            allowOverwrite: true
                        })
                    });
                    const retryData = await retryResponse.json();
                    if (retryData.status !== 200) {
                        throw new Error(retryData.message || 'Failed to save keyframe');
                    }
                    // Success on retry - continue to success handler below
                } else {
                    keyframeNameInput.focus();
                    keyframeNameInput.select();
                    return;
                }
            } else if (data.status !== 200) {
                throw new Error(data.message || 'Failed to save keyframe');
            }
            
            // Success (either first try or retry)
            showToast(
                keyframeEditorMode === 'create' 
                    ? (<?= json_encode(__admin('preview.keyframeCreated') ?? 'Keyframe created successfully') ?>)
                    : (<?= json_encode(__admin('preview.keyframeSaved') ?? 'Keyframe saved successfully') ?>),
                'success'
            );
            
            // Close modal
            closeKeyframeModal();
            
            // Reload animations tab
            animationsLoaded = false;
            loadAnimationsTab();
            
            // Reload iframe to reflect changes
            reloadPreview();
            
        } catch (error) {
            console.error('Failed to save keyframe:', error);
            showToast(<?= json_encode(__admin('preview.saveKeyframeFailed') ?? 'Failed to save keyframe') ?>, 'error');
        }
    }
    
    /**
     * Escape HTML for safe rendering
     */
    function escapeHTML(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    /**
     * Delete a keyframe
     */
    async function deleteKeyframe(keyframeName) {
        if (!confirm(<?= json_encode(__admin('preview.confirmDeleteKeyframe') ?? 'Are you sure you want to delete this keyframe?') ?> + `\n\n@keyframes ${keyframeName}`)) {
            return;
        }
        
        try {
            const response = await fetch(managementUrl + 'deleteKeyframes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({ name: keyframeName })
            });
            
            const data = await response.json();
            
            if (data.status === 200) {
                showToast(<?= json_encode(__admin('preview.keyframeDeleted') ?? 'Keyframe deleted successfully') ?>, 'success');
                // Reload animations tab
                animationsLoaded = false;
                loadAnimationsTab();
                // Reload iframe to reflect changes
                reloadPreview();
            } else {
                throw new Error(data.message || 'Failed to delete keyframe');
            }
        } catch (error) {
            console.error('Failed to delete keyframe:', error);
            showToast(<?= json_encode(__admin('preview.deleteKeyframeFailed') ?? 'Failed to delete keyframe') ?>, 'error');
        }
    }
    
    // ==================== Selector Browser (Phase 8.4) ====================
    
    /**
     * Load all CSS selectors from the API
     */
    async function loadStyleSelectors() {
        if (!selectorsLoading || !selectorsGroups) return;
        
        // Show loading state
        selectorsLoading.style.display = '';
        selectorsGroups.style.display = 'none';
        
        try {
            const response = await fetch(managementUrl + 'listStyleRules', {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            const data = await response.json();
            
            if (data.status === 200 && data.data?.selectors) {
                allSelectors = data.data.selectors;
                selectorsLoaded = true;
                
                // Categorize selectors
                categorizeSelectors(data.data);
                
                // Populate the UI
                populateSelectorBrowser();
                
                // Show content, hide loading
                selectorsLoading.style.display = 'none';
                selectorsGroups.style.display = '';
            } else {
                throw new Error(data.message || 'Failed to load selectors');
            }
        } catch (error) {
            console.error('Error loading selectors:', error);
            selectorsLoading.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" style="color: #ef4444;">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span style="color: #ef4444;">${<?= json_encode(__admin('preview.selectorsLoadError') ?? 'Failed to load selectors') ?>}</span>
            `;
        }
    }
    
    /**
     * Categorize selectors by type
     */
    function categorizeSelectors(data) {
        categorizedSelectors = { tags: [], classes: [], ids: [], attributes: [], media: {} };
        
        // Process global selectors
        const globalSelectors = data.grouped?.global || [];
        globalSelectors.forEach(selector => {
            categorizeSelector(selector, null);
        });
        
        // Process media query selectors
        const mediaSelectors = data.grouped?.media || {};
        for (const [mediaQuery, selectors] of Object.entries(mediaSelectors)) {
            categorizedSelectors.media[mediaQuery] = [];
            selectors.forEach(selector => {
                categorizedSelectors.media[mediaQuery].push(selector);
            });
        }
    }
    
    /**
     * Categorize a single selector
     */
    function categorizeSelector(selector, mediaQuery) {
        // Skip :root and complex compound selectors for simplicity
        if (selector === ':root') return;
        
        // Extract the primary selector part (before any combinator or pseudo)
        const primarySelector = selector.split(/[\s>+~:]/)[0];
        
        if (primarySelector.startsWith('.')) {
            categorizedSelectors.classes.push({ selector, mediaQuery });
        } else if (primarySelector.startsWith('#')) {
            categorizedSelectors.ids.push({ selector, mediaQuery });
        } else if (primarySelector.startsWith('[')) {
            categorizedSelectors.attributes.push({ selector, mediaQuery });
        } else if (/^[a-zA-Z]/.test(primarySelector)) {
            // Starts with a letter = tag selector
            categorizedSelectors.tags.push({ selector, mediaQuery });
        } else {
            // Other (like * or complex selectors) - put in tags for now
            categorizedSelectors.tags.push({ selector, mediaQuery });
        }
    }
    
    /**
     * Populate the selector browser UI
     */
    function populateSelectorBrowser() {
        // Clear existing items
        if (selectorTagsList) selectorTagsList.innerHTML = '';
        if (selectorClassesList) selectorClassesList.innerHTML = '';
        if (selectorIdsList) selectorIdsList.innerHTML = '';
        if (selectorAttributesList) selectorAttributesList.innerHTML = '';
        if (selectorMediaList) selectorMediaList.innerHTML = '';
        
        // Populate Tags
        categorizedSelectors.tags.forEach(item => {
            const chip = createSelectorChip(item.selector, 'tag');
            selectorTagsList?.appendChild(chip);
        });
        
        // Populate Classes
        categorizedSelectors.classes.forEach(item => {
            const chip = createSelectorChip(item.selector, 'class');
            selectorClassesList?.appendChild(chip);
        });
        
        // Populate IDs
        categorizedSelectors.ids.forEach(item => {
            const chip = createSelectorChip(item.selector, 'id');
            selectorIdsList?.appendChild(chip);
        });
        
        // Populate Attributes
        categorizedSelectors.attributes.forEach(item => {
            const chip = createSelectorChip(item.selector, 'attribute');
            selectorAttributesList?.appendChild(chip);
        });
        
        // Populate Media Queries
        for (const [mediaQuery, selectors] of Object.entries(categorizedSelectors.media)) {
            const mediaGroup = document.createElement('div');
            mediaGroup.className = 'preview-selectors-media-group';
            
            const mediaTitle = document.createElement('div');
            mediaTitle.className = 'preview-selectors-media-group__title';
            mediaTitle.textContent = `@media ${mediaQuery}`;
            mediaGroup.appendChild(mediaTitle);
            
            const mediaChips = document.createElement('div');
            mediaChips.className = 'preview-selectors-group__list';
            mediaChips.style.paddingLeft = '0';
            
            selectors.forEach(selector => {
                const chip = createSelectorChip(selector, 'media', mediaQuery);
                mediaChips.appendChild(chip);
            });
            
            mediaGroup.appendChild(mediaChips);
            selectorMediaList?.appendChild(mediaGroup);
        }
        
        // Update counts
        updateSelectorCounts();
        
        // Update total count
        const totalCount = categorizedSelectors.tags.length + 
                          categorizedSelectors.classes.length + 
                          categorizedSelectors.ids.length + 
                          categorizedSelectors.attributes.length +
                          Object.values(categorizedSelectors.media).flat().length;
        if (selectorCount) selectorCount.textContent = totalCount;
        
        // Hide empty groups
        hideEmptyGroups();
    }
    
    /**
     * Create a selector chip element
     */
    function createSelectorChip(selector, type, mediaQuery = null) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = `preview-selector-item preview-selector-item--${type}`;
        chip.textContent = selector;
        chip.title = selector + (mediaQuery ? ` (${mediaQuery})` : '');
        chip.dataset.selector = selector;
        if (mediaQuery) chip.dataset.media = mediaQuery;
        
        // Hover → highlight elements in iframe
        chip.addEventListener('mouseenter', () => {
            hoveredSelector = selector;
            sendToIframe('highlightBySelector', { selector });
        });
        
        chip.addEventListener('mouseleave', () => {
            if (hoveredSelector === selector) {
                hoveredSelector = null;
                sendToIframe('clearSelectorHighlight', {});
            }
        });
        
        // Click → select this selector
        chip.addEventListener('click', () => {
            selectSelector(selector, mediaQuery);
        });
        
        return chip;
    }
    
    /**
     * Update selector counts in group headers
     */
    function updateSelectorCounts() {
        if (selectorTagsCount) selectorTagsCount.textContent = categorizedSelectors.tags.length;
        if (selectorClassesCount) selectorClassesCount.textContent = categorizedSelectors.classes.length;
        if (selectorIdsCount) selectorIdsCount.textContent = categorizedSelectors.ids.length;
        if (selectorAttributesCount) selectorAttributesCount.textContent = categorizedSelectors.attributes.length;
        if (selectorMediaCount) {
            const mediaCount = Object.values(categorizedSelectors.media).flat().length;
            selectorMediaCount.textContent = mediaCount;
        }
    }
    
    /**
     * Hide empty selector groups
     */
    function hideEmptyGroups() {
        const groups = selectorsGroups?.querySelectorAll('.preview-selectors-group');
        groups?.forEach(group => {
            const groupName = group.dataset.group;
            let isEmpty = false;
            
            if (groupName === 'tags') isEmpty = categorizedSelectors.tags.length === 0;
            else if (groupName === 'classes') isEmpty = categorizedSelectors.classes.length === 0;
            else if (groupName === 'ids') isEmpty = categorizedSelectors.ids.length === 0;
            else if (groupName === 'attributes') isEmpty = categorizedSelectors.attributes.length === 0;
            else if (groupName === 'media') isEmpty = Object.keys(categorizedSelectors.media).length === 0;
            
            group.style.display = isEmpty ? 'none' : '';
        });
    }
    
    /**
     * Select a selector and show its info
     */
    function selectSelector(selector, mediaQuery = null) {
        currentSelectedSelector = { selector, mediaQuery };
        
        // Update UI
        if (selectorSelectedValue) selectorSelectedValue.textContent = selector;
        if (selectorSelected) selectorSelected.style.display = '';
        
        // Clear previous active states
        selectorsGroups?.querySelectorAll('.preview-selector-item--active').forEach(el => {
            el.classList.remove('preview-selector-item--active');
        });
        
        // Mark the clicked selector as active
        const activeChip = selectorsGroups?.querySelector(`[data-selector="${CSS.escape(selector)}"]`);
        if (activeChip) activeChip.classList.add('preview-selector-item--active');
        
        // Highlight and count matching elements in iframe
        sendToIframe('selectBySelector', { selector });
        
        // Count matches (we'll receive this via message)
        countSelectorMatches(selector);
    }
    
    /**
     * Clear selector selection
     */
    function clearSelectorSelection() {
        currentSelectedSelector = null;
        if (selectorSelected) selectorSelected.style.display = 'none';
        
        // Clear active states
        selectorsGroups?.querySelectorAll('.preview-selector-item--active').forEach(el => {
            el.classList.remove('preview-selector-item--active');
        });
        
        // Clear highlight in iframe
        sendToIframe('clearSelectorHighlight', {});
    }
    
    /**
     * Count how many elements match the selector in the iframe
     */
    function countSelectorMatches(selector) {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (iframeDoc) {
                const matches = iframeDoc.querySelectorAll(selector);
                const count = matches.length;
                if (selectorMatchCount) selectorMatchCount.textContent = count;
                editingSelectorCount = count; // Store for Style Editor
            }
        } catch (e) {
            console.warn('Could not count selector matches:', e);
            if (selectorMatchCount) selectorMatchCount.textContent = '?';
            editingSelectorCount = 0;
        }
    }
    
    /**
     * Filter selectors based on search input
     */
    function filterSelectors(query) {
        const searchLower = query.toLowerCase();
        
        selectorsGroups?.querySelectorAll('.preview-selector-item').forEach(chip => {
            const selector = chip.dataset.selector || '';
            const matches = selector.toLowerCase().includes(searchLower);
            chip.style.display = matches ? '' : 'none';
        });
        
        // Update visible counts (approximate - just show/hide based on children visibility)
        updateVisibleSelectorCounts();
        
        // Show/hide clear button
        if (selectorSearchClear) {
            selectorSearchClear.style.display = query ? '' : 'none';
        }
    }
    
    /**
     * Update counts based on visible selectors after filtering
     */
    function updateVisibleSelectorCounts() {
        const countVisibleIn = (listEl) => {
            return listEl?.querySelectorAll('.preview-selector-item:not([style*="display: none"])').length || 0;
        };
        
        if (selectorTagsCount) selectorTagsCount.textContent = countVisibleIn(selectorTagsList);
        if (selectorClassesCount) selectorClassesCount.textContent = countVisibleIn(selectorClassesList);
        if (selectorIdsCount) selectorIdsCount.textContent = countVisibleIn(selectorIdsList);
        if (selectorAttributesCount) selectorAttributesCount.textContent = countVisibleIn(selectorAttributesList);
        
        // Media is trickier - count all visible in media list
        if (selectorMediaCount) {
            selectorMediaCount.textContent = countVisibleIn(selectorMediaList);
        }
        
        // Update total
        const total = countVisibleIn(selectorTagsList) + 
                     countVisibleIn(selectorClassesList) + 
                     countVisibleIn(selectorIdsList) + 
                     countVisibleIn(selectorAttributesList) +
                     countVisibleIn(selectorMediaList);
        if (selectorCount) selectorCount.textContent = total;
    }
    
    /**
     * Initialize selector browser event handlers
     */
    function initSelectorBrowser() {
        // Search input
        if (selectorSearchInput) {
            selectorSearchInput.addEventListener('input', (e) => {
                filterSelectors(e.target.value);
            });
        }
        
        // Search clear button
        if (selectorSearchClear) {
            selectorSearchClear.addEventListener('click', () => {
                if (selectorSearchInput) selectorSearchInput.value = '';
                filterSelectors('');
            });
        }
        
        // Clear selection button
        if (selectorSelectedClear) {
            selectorSelectedClear.addEventListener('click', clearSelectorSelection);
        }
        
        // Edit styles button - opens Style Editor (Phase 8.5)
        if (selectorEditBtn) {
            selectorEditBtn.addEventListener('click', () => {
                if (currentSelectedSelector) {
                    openStyleEditor(currentSelectedSelector.selector, editingSelectorCount || 0);
                }
            });
        }
        
        // Animate button - opens State & Animation Editor (Phase 11)
        if (selectorAnimateBtn) {
            selectorAnimateBtn.addEventListener('click', () => {
                if (currentSelectedSelector) {
                    openTransitionEditor(currentSelectedSelector.selector);
                }
            });
        }
        
        // Group header collapse/expand
        selectorsGroups?.querySelectorAll('.preview-selectors-group__header').forEach(header => {
            header.addEventListener('click', () => {
                const isExpanded = header.classList.contains('preview-selectors-group__header--expanded');
                header.classList.toggle('preview-selectors-group__header--expanded');
                
                const list = header.nextElementSibling;
                if (list) {
                    list.style.display = isExpanded ? 'none' : '';
                }
            });
        });
    }
    
    // ==================== Style Editor (Phase 8.5) ====================
    
    // Common CSS properties that should use color picker
    const colorProperties = [
        'color', 'background-color', 'background', 'border-color', 'border-top-color',
        'border-right-color', 'border-bottom-color', 'border-left-color', 'outline-color',
        'box-shadow', 'text-shadow', 'fill', 'stroke', 'caret-color', 'accent-color',
        'text-decoration-color', 'column-rule-color'
    ];
    
    /**
     * Open the Style Editor for a selector
     * @param {string} selector - CSS selector to edit
     * @param {number} matchCount - Number of elements matching this selector
     */
    async function openStyleEditor(selector, matchCount = 0) {
        if (!styleEditor) return;
        
        editingSelector = selector;
        editingSelectorCount = matchCount;
        originalStyles = {};
        currentStyles = {};
        newProperties = [];
        deletedProperties = [];  // Reset deleted properties when opening editor
        
        // Update header
        if (styleEditorSelector) styleEditorSelector.textContent = selector;
        if (styleEditorCount) styleEditorCount.textContent = matchCount;
        
        // Show loading state
        showStyleEditorState('loading');
        
        // Hide selector browser content, show style editor
        if (selectorsPanel) {
            selectorsPanel.querySelector('.preview-selectors-search')?.style.setProperty('display', 'none');
            selectorsLoading?.style.setProperty('display', 'none');
            selectorsGroups?.style.setProperty('display', 'none');
            selectorSelected?.style.setProperty('display', 'none');
        }
        styleEditor.style.display = 'flex';
        styleEditorVisible = true;
        
        // Load styles from API
        try {
            const response = await fetch(managementUrl + 'getStyleRule/' + encodeURIComponent(selector), {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            
            const result = await response.json();
            
            if (response.ok && result.data) {
                const stylesString = result.data.styles || '';
                originalStyles = parseStylesString(stylesString);
                currentStyles = { ...originalStyles };
                
                if (Object.keys(originalStyles).length === 0) {
                    showStyleEditorState('empty');
                } else {
                    renderStyleProperties();
                    showStyleEditorState('properties');
                }
            } else {
                // Selector not found in CSS (might be a browser-default or doesn't exist)
                originalStyles = {};
                currentStyles = {};
                showStyleEditorState('empty');
            }
        } catch (error) {
            console.error('Failed to load styles:', error);
            showToast(<?= json_encode(__admin('preview.failedToLoadStyles') ?? 'Failed to load styles') ?>, 'error');
            closeStyleEditor();
        }
    }
    
    /**
     * Close the Style Editor and return to selector browser
     */
    function closeStyleEditor() {
        styleEditorVisible = false;
        editingSelector = null;
        
        // Remove live preview styles
        removeLivePreviewStyles();
        
        // Hide style editor
        styleEditor.style.display = 'none';
        
        // Show selector browser content
        if (selectorsPanel) {
            selectorsPanel.querySelector('.preview-selectors-search')?.style.setProperty('display', '');
            selectorsGroups?.style.setProperty('display', '');
            if (currentSelectedSelector) {
                selectorSelected?.style.setProperty('display', '');
            }
        }
    }
    
    /**
     * Show a specific state of the style editor
     * @param {'loading'|'empty'|'properties'} state
     */
    function showStyleEditorState(state) {
        if (styleEditorLoading) styleEditorLoading.style.display = state === 'loading' ? '' : 'none';
        if (styleEditorEmpty) styleEditorEmpty.style.display = state === 'empty' ? '' : 'none';
        if (styleEditorProperties) styleEditorProperties.style.display = state === 'properties' ? '' : 'none';
        if (styleEditorAdd) styleEditorAdd.style.display = (state === 'properties' || state === 'empty') ? '' : 'none';
        if (styleEditorActions) styleEditorActions.style.display = (state === 'properties' || state === 'empty') ? '' : 'none';
    }
    
    /**
     * Parse CSS styles string into object
     * @param {string} stylesString - CSS declarations like "color: red; font-size: 16px;"
     * @returns {Object} Property/value pairs
     */
    function parseStylesString(stylesString) {
        const result = {};
        if (!stylesString) return result;
        
        // Split by semicolons, handling multi-line
        const declarations = stylesString.split(/;\s*/);
        
        for (const decl of declarations) {
            const trimmed = decl.trim();
            if (!trimmed) continue;
            
            const colonIndex = trimmed.indexOf(':');
            if (colonIndex === -1) continue;
            
            const property = trimmed.substring(0, colonIndex).trim();
            const value = trimmed.substring(colonIndex + 1).trim();
            
            if (property && value) {
                result[property] = value;
            }
        }
        
        return result;
    }
    
    /**
     * Extract color from a compound CSS value (like box-shadow, text-shadow, border)
     * Returns { color, prefix, suffix } where prefix + color + suffix = original value
     * @param {string} value - Full CSS value
     * @returns {object|null} - { color, prefix, suffix, fullMatch } or null if no color found
     */
    function extractColorFromValue(value) {
        if (!value) return null;
        
        const trimmed = value.trim();
        
        // If the value is a simple var() reference, don't treat as compound
        // This prevents matching color names inside var(--color-orange) as "orange"
        if (/^var\(--[\w-]+(?:\s*,\s*[^)]+)?\)$/i.test(trimmed)) {
            return null;
        }
        
        // Patterns to match colors in compound values
        const colorPatterns = [
            // rgba(r, g, b, a)
            /(rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+)?\s*\))/i,
            // hsla(h, s%, l%, a)
            /(hsla?\(\s*\d+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(?:,\s*[\d.]+)?\s*\))/i,
            // #hex colors (3, 4, 6, or 8 digits)
            /(#[0-9a-fA-F]{3,8})\b/
        ];
        
        // Named colors - checked separately to avoid matching inside var() or CSS variable names
        const namedColors = ['red', 'blue', 'green', 'yellow', 'orange', 'purple', 'pink', 
            'white', 'black', 'gray', 'grey', 'cyan', 'magenta', 'lime', 'navy', 
            'teal', 'maroon', 'olive', 'silver', 'aqua', 'fuchsia', 'transparent'];
        
        for (const pattern of colorPatterns) {
            const match = value.match(pattern);
            if (match) {
                const colorMatch = match[1];
                const index = match.index;
                return {
                    color: colorMatch,
                    prefix: value.substring(0, index),
                    suffix: value.substring(index + colorMatch.length),
                    fullMatch: colorMatch
                };
            }
        }
        
        // Check for named colors, but exclude matches inside var() functions
        // First, temporarily replace all var() with placeholders
        const varMatches = [];
        const tempValue = value.replace(/var\([^)]+\)/gi, (match) => {
            varMatches.push(match);
            return `__VAR_${varMatches.length - 1}__`;
        });
        
        // Now search for named colors in the sanitized string
        for (const colorName of namedColors) {
            const regex = new RegExp(`\\b(${colorName})\\b`, 'i');
            const match = tempValue.match(regex);
            if (match) {
                // Found a color, now find its position in the original string
                // We need to account for any var() replacements that came before
                const tempIndex = match.index;
                
                // Count how many placeholder characters came before this position
                let offset = 0;
                for (let i = 0; i < varMatches.length; i++) {
                    const placeholder = `__VAR_${i}__`;
                    const placeholderPos = tempValue.indexOf(placeholder);
                    if (placeholderPos !== -1 && placeholderPos < tempIndex) {
                        offset += varMatches[i].length - placeholder.length;
                    }
                }
                
                const actualIndex = tempIndex + offset;
                return {
                    color: match[1],
                    prefix: value.substring(0, actualIndex),
                    suffix: value.substring(actualIndex + match[1].length),
                    fullMatch: match[1]
                };
            }
        }
        
        return null;
    }
    
    /**
     * Rebuild a compound value with a new color
     * @param {string} prefix - Text before color
     * @param {string} newColor - New color value
     * @param {string} suffix - Text after color
     * @returns {string}
     */
    function rebuildValueWithColor(prefix, newColor, suffix) {
        return prefix + newColor + suffix;
    }
    
    /**
     * CSS Properties organized by category for the property selector dropdown (Phase 8.7)
     */
    const CSS_PROPERTIES_BY_CATEGORY = {
        'Layout': [
            'display', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'box-sizing', 'overflow', 'overflow-x', 'overflow-y'
        ],
        'Flexbox': [
            'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
            'gap', 'row-gap', 'column-gap', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
            'align-self', 'order'
        ],
        'Grid': [
            'grid-template-columns', 'grid-template-rows', 'grid-gap', 'grid-column-gap',
            'grid-row-gap', 'grid-auto-flow', 'grid-column', 'grid-row', 'place-items',
            'place-content', 'place-self'
        ],
        'Position': [
            'position', 'top', 'right', 'bottom', 'left', 'z-index', 'inset'
        ],
        'Typography': [
            'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
            'letter-spacing', 'word-spacing', 'text-align', 'text-decoration', 
            'text-transform', 'white-space', 'word-break', 'text-overflow'
        ],
        'Colors': [
            'color', 'background-color', 'background', 'background-image',
            'border-color', 'outline-color', 'fill', 'stroke'
        ],
        'Borders': [
            'border', 'border-width', 'border-style', 'border-color', 'border-radius',
            'border-top', 'border-right', 'border-bottom', 'border-left',
            'outline', 'outline-width', 'outline-style', 'outline-offset'
        ],
        'Effects': [
            'opacity', 'visibility', 'box-shadow', 'text-shadow', 'filter',
            'backdrop-filter', 'mix-blend-mode', 'clip-path'
        ],
        'Transform': [
            'transform', 'transform-origin', 'perspective', 'translate', 'rotate', 'scale'
        ],
        'Transition': [
            'transition', 'transition-property', 'transition-duration', 
            'transition-timing-function', 'transition-delay'
        ],
        'Animation': [
            'animation', 'animation-name', 'animation-duration', 'animation-timing-function',
            'animation-delay', 'animation-iteration-count', 'animation-direction', 'animation-fill-mode'
        ],
        'Other': [
            'cursor', 'pointer-events', 'user-select', 'content', 'list-style',
            'object-fit', 'object-position', 'aspect-ratio'
        ]
    };
    
    /**
     * Get property type info (reuses KEYFRAME_PROPERTY_TYPES)
     * @param {string} property - CSS property name
     * @returns {object} - Type info { type, values/units/min/max/step }
     */
    function getStylePropertyType(property) {
        return KEYFRAME_PROPERTY_TYPES[property] || { type: 'text' };
    }
    
    /**
     * Render all style properties in the editor
     */
    function renderStyleProperties() {
        if (!styleEditorProperties) return;
        styleEditorProperties.innerHTML = '';
        
        // Render existing properties
        for (const [property, value] of Object.entries(currentStyles)) {
            const isModified = originalStyles[property] !== value;
            renderPropertyRow(property, value, isModified, false);
        }
        
        // Render new properties
        for (const prop of newProperties) {
            renderPropertyRow(prop.property, prop.value, false, true);
        }
    }
    
    /**
     * Render a single property row
     * @param {string} property - CSS property name
     * @param {string} value - CSS property value
     * @param {boolean} isModified - Whether value differs from original
     * @param {boolean} isNew - Whether this is a newly added property
     */
    function renderPropertyRow(property, value, isModified, isNew) {
        const row = document.createElement('div');
        row.className = 'preview-style-property';
        if (isModified) row.classList.add('preview-style-property--modified');
        if (isNew) row.classList.add('preview-style-property--new');
        row.dataset.property = property;
        
        // Track if original value used var()
        const originalValue = isNew ? '' : (originalStyles[property] || '');
        const originalUsedVar = /var\(([^)]+)\)/.test(originalValue);
        
        // Check if current value uses var()
        const varMatch = value.match(/var\(([^)]+)\)/);
        const usesVar = !!varMatch;
        let resolvedValue = value;
        
        if (usesVar && iframe?.contentDocument) {
            // Try to resolve the variable
            const varName = varMatch[1].split(',')[0].trim();
            try {
                resolvedValue = getComputedStyle(iframe.contentDocument.documentElement)
                    .getPropertyValue(varName).trim() || value;
            } catch (e) {
                resolvedValue = value;
            }
        }
        
        // Detect if this is a color property
        const isColorProperty = colorProperties.some(p => property.includes(p)) || 
                                 isColorValue(resolvedValue);
        
        // Property name (editable for new properties, static for existing)
        if (isNew) {
            // Use searchable property selector dropdown (Phase 8.7)
            const selectorContainer = createPropertySelector(property, (newName) => {
                const currentName = row.dataset.property;
                updatePropertyName(currentName, newName, row);
                
                // Re-render value input based on property type
                updateValueInputForProperty(row, newName, value);
            });
            row.appendChild(selectorContainer);
        } else {
            const nameEl = document.createElement('span');
            nameEl.className = 'preview-style-property__name';
            nameEl.textContent = property;
            nameEl.title = property;
            row.appendChild(nameEl);
        }
        
        // Value container
        const valueContainer = document.createElement('div');
        valueContainer.className = 'preview-style-property__value';
        
        // Color swatch (if color property)
        if (isColorProperty) {
            // Extract just the color part for compound values (e.g., box-shadow)
            const extracted = extractColorFromValue(resolvedValue);
            const swatchColor = extracted ? extracted.color : resolvedValue;
            
            const colorSwatch = document.createElement('button');
            colorSwatch.type = 'button';
            colorSwatch.className = 'preview-style-property__color';
            colorSwatch.style.background = swatchColor;
            colorSwatch.title = <?= json_encode(__admin('preview.clickToPickColor') ?? 'Click to pick color') ?>;
            colorSwatch.addEventListener('click', () => {
                const currentProp = row.dataset.property;
                openColorPickerForProperty(currentProp, resolvedValue, colorSwatch);
            });
            valueContainer.appendChild(colorSwatch);
        }
        
        // Value input
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'preview-style-property__input';
        input.value = value;
        input.dataset.originalUsedVar = originalUsedVar ? '1' : '0';
        input.addEventListener('input', (e) => {
            const currentProp = row.dataset.property;
            updatePropertyValue(currentProp, e.target.value, isNew);
        });
        input.addEventListener('blur', (e) => {
            // Check if user removed var() from a value that originally had it
            const hadVar = e.target.dataset.originalUsedVar === '1';
            const nowHasVar = /var\(([^)]+)\)/.test(e.target.value);
            if (hadVar && !nowHasVar && e.target.value.trim() !== '') {
                showVarReplacementWarning(row.dataset.property, e.target.value);
            }
            applyLivePreview();
        });
        valueContainer.appendChild(input);
        
        // CSS Variables dropdown button
        const varDropdownBtn = document.createElement('button');
        varDropdownBtn.type = 'button';
        varDropdownBtn.className = 'preview-style-property__var-btn';
        varDropdownBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
            <polyline points="6 9 12 15 18 9"/>
        </svg>`;
        varDropdownBtn.title = <?= json_encode(__admin('preview.selectVariable') ?? 'Select CSS variable') ?>;
        varDropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showVarDropdown(row.dataset.property, input, varDropdownBtn);
        });
        valueContainer.appendChild(varDropdownBtn);
        
        // Variable indicator
        if (usesVar) {
            const varIndicator = document.createElement('span');
            varIndicator.className = 'preview-style-property__var';
            varIndicator.textContent = 'var';
            varIndicator.title = varMatch[1];
            valueContainer.appendChild(varIndicator);
        }
        
        row.appendChild(valueContainer);
        
        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'preview-style-property__delete';
        deleteBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>`;
        deleteBtn.title = <?= json_encode(__admin('common.delete') ?? 'Delete') ?>;
        deleteBtn.addEventListener('click', () => {
            deleteProperty(row.dataset.property, isNew);
        });
        row.appendChild(deleteBtn);
        
        styleEditorProperties.appendChild(row);
    }
    
    /**
     * Update property name (for new properties)
     * @param {string} oldName - Old property name
     * @param {string} newName - New property name
     * @param {HTMLElement} row - The property row element
     */
    function updatePropertyName(oldName, newName, row) {
        const prop = newProperties.find(p => p.property === oldName);
        if (prop) {
            prop.property = newName;
            row.dataset.property = newName;
        }
    }
    
    /**
     * Create a searchable property selector dropdown (Phase 8.7)
     * @param {string} currentValue - Current property name
     * @param {function} onSelect - Callback when property is selected
     * @returns {HTMLElement} - The selector container
     */
    /**
     * Create a property selector using QSPropertySelector class
     * This is a wrapper for backward compatibility with existing code
     * @param {string} currentValue - Current property name
     * @param {function} onSelect - Callback when property is selected
     * @returns {HTMLElement} - The selector container
     */
    function createPropertySelector(currentValue, onSelect) {
        const container = document.createElement('div');
        container.className = 'preview-property-selector';
        
        // Get list of properties already in use (existing + new)
        const existingPropertyNames = [
            ...Object.keys(currentStyles),
            ...newProperties.map(p => p.property)
        ];
        
        // Create QSPropertySelector instance
        const selector = new QSPropertySelector({
            container: container,
            currentValue: currentValue,
            excludeProperties: existingPropertyNames,
            onSelect: onSelect
        });
        
        // Store reference to selector for potential later use
        container._selector = selector;
        
        return container;
    }
    
    /**
     * Update value input based on selected property type (Phase 8.7)
     * @param {HTMLElement} row - Property row element
     * @param {string} property - CSS property name
     * @param {string} currentValue - Current value
     */
    function updateValueInputForProperty(row, property, currentValue) {
        const valueContainer = row.querySelector('.preview-style-property__value');
        if (!valueContainer) return;
        
        // Get property type
        const propType = getStylePropertyType(property);
        const isNew = row.classList.contains('preview-style-property--new');
        
        // Preserve var dropdown button
        const varBtn = valueContainer.querySelector('.preview-style-property__var-btn');
        
        // Clear value container except var button
        while (valueContainer.firstChild) {
            if (valueContainer.firstChild === varBtn) break;
            valueContainer.removeChild(valueContainer.firstChild);
        }
        
        // Check if this is a color property (for color swatch)
        const isColorProperty = colorProperties.some(p => property.includes(p)) || propType.type === 'color';
        
        // Add color swatch if needed
        if (isColorProperty) {
            const colorSwatch = document.createElement('button');
            colorSwatch.type = 'button';
            colorSwatch.className = 'preview-style-property__color';
            colorSwatch.style.background = currentValue || '#ffffff';
            colorSwatch.title = <?= json_encode(__admin('preview.clickToPickColor') ?? 'Click to pick color') ?>;
            colorSwatch.addEventListener('click', () => {
                openColorPickerForProperty(row.dataset.property, currentValue, colorSwatch);
            });
            valueContainer.insertBefore(colorSwatch, varBtn);
        }
        
        // Create specialized input based on type
        let inputEl = createSpecializedInput(propType, property, currentValue, (newValue) => {
            updatePropertyValue(row.dataset.property, newValue, isNew);
            
            // Update color swatch if present
            if (isColorProperty) {
                const swatch = valueContainer.querySelector('.preview-style-property__color');
                if (swatch) swatch.style.background = newValue;
            }
        });
        
        valueContainer.insertBefore(inputEl, varBtn);
        
        // Apply live preview
        applyLivePreview();
    }
    
    /**
     * Create a specialized input control based on property type (Phase 8.7)
     * This is a wrapper around QSValueInput for backward compatibility
     * @param {object} propType - Property type configuration
     * @param {string} property - CSS property name
     * @param {string} value - Current value
     * @param {function} onChange - Callback when value changes
     * @returns {HTMLElement}
     */
    function createSpecializedInput(propType, property, value, onChange) {
        const container = document.createElement('div');
        
        // Create QSValueInput instance with Selectors panel styling
        const valueInput = new QSValueInput({
            container: container,
            property: property,
            value: value,
            className: 'preview-style-property',  // Use existing Selectors panel class names
            onChange: onChange,
            onBlur: () => applyLivePreview()
        });
        
        // Store reference for potential later use
        container._valueInput = valueInput;
        
        return container;
    }
    
    /**
     * Show warning when user replaces var() with fixed value
     * @param {string} property - Property name
     * @param {string} newValue - New fixed value
     */
    function showVarReplacementWarning(property, newValue) {
        showToast(
            <?= json_encode(__admin('preview.varReplacementWarning') ?? 'You replaced a theme variable with a fixed value. This property will no longer follow theme changes.') ?>,
            'warning',
            5000
        );
    }
    
    /**
     * Show dropdown with available CSS variables
     * @param {string} property - Property name being edited
     * @param {HTMLInputElement} input - The value input element
     * @param {HTMLElement} anchorEl - Element to position dropdown near
     */
    function showVarDropdown(property, input, anchorEl) {
        // Close any existing dropdown
        closeVarDropdown();
        
        // Get available variables from theme
        const allVariables = Object.keys(originalThemeVariables);
        if (allVariables.length === 0) {
            showToast(<?= json_encode(__admin('preview.noVariablesAvailable') ?? 'No CSS variables available. Load the Theme tab first.') ?>, 'info');
            return;
        }
        
        // Filter variables based on property type (Phase 8.6.3)
        const isFontProperty = property.startsWith('font') || property === 'line-height' || property === 'letter-spacing';
        const isColorProperty = colorProperties.some(p => property.includes(p));
        const isSizeProperty = ['width', 'height', 'margin', 'padding', 'gap', 'border-radius', 'top', 'left', 'right', 'bottom'].some(p => property.includes(p));
        
        let variables = allVariables;
        let filterHint = '';
        
        if (isFontProperty) {
            // Show font variables first, then others
            const fontVars = allVariables.filter(v => v.includes('font') || v.includes('text') || v.includes('line') || v.includes('letter'));
            const otherVars = allVariables.filter(v => !fontVars.includes(v));
            variables = [...fontVars, ...otherVars];
            filterHint = fontVars.length > 0 ? ` (${fontVars.length} font vars)` : '';
        } else if (isColorProperty) {
            // Show color variables first
            const colorVars = allVariables.filter(v => v.includes('color') || v.includes('bg') || v.includes('border') || v.includes('text'));
            const otherVars = allVariables.filter(v => !colorVars.includes(v));
            variables = [...colorVars, ...otherVars];
            filterHint = colorVars.length > 0 ? ` (${colorVars.length} color vars)` : '';
        } else if (isSizeProperty) {
            // Show spacing/size variables first
            const sizeVars = allVariables.filter(v => v.includes('spacing') || v.includes('gap') || v.includes('size') || v.includes('radius') || v.includes('width') || v.includes('height'));
            const otherVars = allVariables.filter(v => !sizeVars.includes(v));
            variables = [...sizeVars, ...otherVars];
            filterHint = sizeVars.length > 0 ? ` (${sizeVars.length} size vars)` : '';
        }
        
        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'preview-var-dropdown';
        dropdown.id = 'var-dropdown';
        
        // Search input
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'preview-var-dropdown__search';
        searchInput.placeholder = <?= json_encode(__admin('common.search') ?? 'Search...') ?> + filterHint;
        dropdown.appendChild(searchInput);
        
        // Variables list
        const list = document.createElement('div');
        list.className = 'preview-var-dropdown__list';
        
        // Render variable items
        const renderItems = (filter = '') => {
            list.innerHTML = '';
            const filterLower = filter.toLowerCase();
            
            for (const varName of variables) {
                if (filterLower && !varName.toLowerCase().includes(filterLower)) continue;
                
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'preview-var-dropdown__item';
                
                const nameSpan = document.createElement('span');
                nameSpan.className = 'preview-var-dropdown__item-name';
                nameSpan.textContent = varName;
                item.appendChild(nameSpan);
                
                const valueSpan = document.createElement('span');
                valueSpan.className = 'preview-var-dropdown__item-value';
                const varValue = originalThemeVariables[varName] || '';
                valueSpan.textContent = varValue.length > 20 ? varValue.substring(0, 20) + '...' : varValue;
                // Show color preview if it's a color
                if (isColorValue(varValue)) {
                    valueSpan.style.setProperty('--preview-color', varValue);
                    valueSpan.classList.add('preview-var-dropdown__item-value--color');
                }
                item.appendChild(valueSpan);
                
                item.addEventListener('click', () => {
                    input.value = `var(${varName})`;
                    const currentProp = input.closest('.preview-style-property')?.dataset.property || property;
                    const isNew = input.closest('.preview-style-property')?.classList.contains('preview-style-property--new');
                    updatePropertyValue(currentProp, input.value, isNew);
                    applyLivePreview();
                    closeVarDropdown();
                });
                
                list.appendChild(item);
            }
            
            if (list.children.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'preview-var-dropdown__empty';
                empty.textContent = <?= json_encode(__admin('common.noResults') ?? 'No results') ?>;
                list.appendChild(empty);
            }
        };
        
        renderItems();
        dropdown.appendChild(list);
        
        // Search filter
        searchInput.addEventListener('input', (e) => {
            renderItems(e.target.value);
        });
        
        // Position dropdown
        const rect = anchorEl.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.top = (rect.bottom + 4) + 'px';
        dropdown.style.left = (rect.left - 200 + rect.width) + 'px';
        
        document.body.appendChild(dropdown);
        searchInput.focus();
        
        // Close on outside click
        setTimeout(() => {
            document.addEventListener('click', closeVarDropdownOnOutsideClick);
        }, 10);
    }
    
    /**
     * Close the var dropdown
     */
    function closeVarDropdown() {
        const dropdown = document.getElementById('var-dropdown');
        if (dropdown) dropdown.remove();
        document.removeEventListener('click', closeVarDropdownOnOutsideClick);
    }
    
    /**
     * Close dropdown when clicking outside
     */
    function closeVarDropdownOnOutsideClick(e) {
        const dropdown = document.getElementById('var-dropdown');
        if (dropdown && !dropdown.contains(e.target)) {
            closeVarDropdown();
        }
    }
    
    /**
     * Check if a value looks like a color
     * @param {string} value
     * @returns {boolean}
     */
    function isColorValue(value) {
        if (!value) return false;
        const v = value.toLowerCase().trim();
        return v.startsWith('#') || 
               v.startsWith('rgb') || 
               v.startsWith('hsl') ||
               /^(red|blue|green|yellow|orange|purple|pink|white|black|gray|grey|transparent|inherit|currentcolor)$/i.test(v);
    }
    
    /**
     * Update a property value
     * @param {string} property - CSS property name
     * @param {string} value - New value
     * @param {boolean} isNew - Whether this is a new property
     */
    function updatePropertyValue(property, value, isNew) {
        if (isNew) {
            const prop = newProperties.find(p => p.property === property);
            if (prop) prop.value = value;
        } else {
            currentStyles[property] = value;
        }
        
        // Update modified state
        const row = styleEditorProperties?.querySelector(`[data-property="${property}"]`);
        if (row && !isNew) {
            row.classList.toggle('preview-style-property--modified', originalStyles[property] !== value);
        }
        
        // Apply live preview with debounce
        applyLivePreviewDebounced();
    }
    
    /**
     * Delete a property
     * @param {string} property - CSS property name
     * @param {boolean} isNew - Whether this is a new property
     */
    function deleteProperty(property, isNew) {
        if (isNew) {
            newProperties = newProperties.filter(p => p.property !== property);
        } else {
            delete currentStyles[property];
            // Track deleted original properties so API can remove them
            if (originalStyles.hasOwnProperty(property) && !deletedProperties.includes(property)) {
                deletedProperties.push(property);
            }
        }
        
        // Remove the row
        const row = styleEditorProperties?.querySelector(`[data-property="${property}"]`);
        if (row) row.remove();
        
        // Check if empty
        if (Object.keys(currentStyles).length === 0 && newProperties.length === 0) {
            showStyleEditorState('empty');
        }
        
        applyLivePreview();
    }
    
    /**
     * Add a new property
     */
    function addNewProperty() {
        const property = '';  // Start with empty name so user must enter it
        const uniqueProp = getUniquePropertyName(property);
        newProperties.push({ property: uniqueProp, value: '' });
        
        renderPropertyRow(uniqueProp, '', false, true);
        showStyleEditorState('properties');
        
        // Focus the property name input for new properties
        const row = styleEditorProperties?.querySelector(`[data-property="${uniqueProp}"]`);
        const nameInput = row?.querySelector('.preview-style-property__name-input');
        if (nameInput) {
            nameInput.focus();
            nameInput.select();
        }
        
        // Apply live preview (for consistency, even though empty value won't show)
        applyLivePreview();
    }
    
    /**
     * Get a unique property name
     * @param {string} baseName
     * @returns {string}
     */
    function getUniquePropertyName(baseName) {
        let name = baseName;
        let counter = 1;
        while (currentStyles[name] || newProperties.some(p => p.property === name)) {
            name = `${baseName}-${counter++}`;
        }
        return name;
    }
    
    // Track active color picker instance for cleanup
    let activeColorPicker = null;
    let activeColorPickerInput = null;
    
    /**
     * Open color picker for a property
     * @param {string} property - CSS property name
     * @param {string} currentColor - Current color value (may be compound value like box-shadow)
     * @param {HTMLElement} swatchEl - The swatch element
     */
    function openColorPickerForProperty(property, currentColor, swatchEl) {
        // Use QSColorPicker if available
        if (typeof QSColorPicker === 'undefined') return;
        
        // Find the input for this property
        const row = swatchEl.closest('.preview-style-property');
        const input = row?.querySelector('.preview-style-property__input');
        if (!input) return;
        
        // Clean up previous picker if any
        if (activeColorPicker) {
            activeColorPicker.destroy();
            activeColorPicker = null;
        }
        if (activeColorPickerInput?.parentNode) {
            activeColorPickerInput.remove();
        }
        
        // Extract color from compound value if needed
        const fullValue = input.value;
        const extracted = extractColorFromValue(fullValue);
        let colorToEdit = currentColor;
        let isCompound = false;
        let prefix = '', suffix = '';
        
        if (extracted && extracted.color !== fullValue) {
            // It's a compound value (e.g., box-shadow with rgba inside)
            colorToEdit = extracted.color;
            prefix = extracted.prefix;
            suffix = extracted.suffix;
            isCompound = true;
        }
        
        // Create a temporary input for the color picker (positioned near the swatch)
        const tempInput = document.createElement('input');
        tempInput.type = 'text';
        tempInput.value = colorToEdit;
        tempInput.style.cssText = 'position:absolute;opacity:0;pointer-events:none;width:1px;height:1px;';
        swatchEl.parentNode.appendChild(tempInput);
        activeColorPickerInput = tempInput;
        
        // Get color variables from theme (originalThemeVariables is loaded on Style mode activation)
        const colorVariables = {};
        if (typeof originalThemeVariables === 'object' && originalThemeVariables) {
            for (const [name, value] of Object.entries(originalThemeVariables)) {
                // Include color-related variables
                if (name.includes('color') || name.includes('bg') || name.includes('text')) {
                    colorVariables[name] = value;
                }
            }
        }
        
        // Create picker attached to temp input
        const picker = new QSColorPicker(tempInput, {
            position: 'auto',
            cssVariables: Object.keys(colorVariables).length > 0 ? colorVariables : null,
            onChange: (color) => {
                // Extract just the color part if it's a var() (for swatch display)
                let swatchColor = color;
                if (color.startsWith('var(')) {
                    const varName = color.match(/var\(([^)]+)\)/)?.[1];
                    if (varName && colorVariables[varName]) {
                        swatchColor = colorVariables[varName];
                    }
                }
                swatchEl.style.background = swatchColor;
                
                // Update the actual input value
                let newValue;
                if (isCompound) {
                    newValue = rebuildValueWithColor(prefix, color, suffix);
                } else {
                    newValue = color;
                }
                input.value = newValue;
                updatePropertyValue(property, newValue, row?.classList.contains('preview-style-property--new'));
                applyLivePreview();
            }
        });
        
        activeColorPicker = picker;
        
        // Position and open the picker - trigger via temp input click
        tempInput.click();
    }
    
    // Debounced live preview
    let livePreviewTimeout = null;
    function applyLivePreviewDebounced() {
        clearTimeout(livePreviewTimeout);
        livePreviewTimeout = setTimeout(applyLivePreview, 150);
    }
    
    /**
     * Apply live preview styles to the iframe
     */
    function applyLivePreview() {
        if (!iframe?.contentDocument || !editingSelector) return;
        
        try {
            const doc = iframe.contentDocument;
            
            // Build combined styles
            const allStyles = { ...currentStyles };
            for (const prop of newProperties) {
                if (prop.property && prop.value) {
                    allStyles[prop.property] = prop.value;
                }
            }
            
            // Build CSS string for active properties
            const cssRules = Object.entries(allStyles)
                .map(([prop, val]) => `${prop}: ${val} !important`);
            
            // Add unset for deleted original properties (so they visually disappear in preview)
            for (const deletedProp of deletedProperties) {
                cssRules.push(`${deletedProp}: unset !important`);
            }
            
            const cssText = cssRules.join('; ');
            
            // Get or create style element in iframe
            let styleEl = doc.getElementById('qs-live-preview-style');
            if (!styleEl) {
                styleEl = doc.createElement('style');
                styleEl.id = 'qs-live-preview-style';
                doc.head.appendChild(styleEl);
                stylePreviewInjected = true;
            }
            
            // Update style content
            styleEl.textContent = `${editingSelector} { ${cssText} }`;
            
        } catch (e) {
            console.error('Failed to apply live preview:', e);
        }
    }
    
    /**
     * Remove live preview styles from iframe
     */
    function removeLivePreviewStyles() {
        if (!iframe?.contentDocument) return;
        
        try {
            const styleEl = iframe.contentDocument.getElementById('qs-live-preview-style');
            if (styleEl) {
                styleEl.remove();
                stylePreviewInjected = false;
            }
        } catch (e) {
            console.error('Failed to remove live preview:', e);
        }
    }
    
    /**
     * Reset styles to original values
     */
    function resetStyleEditor() {
        currentStyles = { ...originalStyles };
        newProperties = [];
        deletedProperties = [];  // Clear deleted properties on reset
        
        if (Object.keys(originalStyles).length === 0) {
            showStyleEditorState('empty');
        } else {
            renderStyleProperties();
        }
        
        applyLivePreview();
        showToast(<?= json_encode(__admin('preview.stylesReset') ?? 'Styles reset to original') ?>, 'info');
    }
    
    /**
     * Save styles via API
     */
    async function saveStyleEditor() {
        if (!editingSelector) return;
        
        // Build combined styles
        const allStyles = { ...currentStyles };
        for (const prop of newProperties) {
            if (prop.property && prop.value) {
                allStyles[prop.property] = prop.value;
            }
        }
        
        // Build styles string
        const stylesString = Object.entries(allStyles)
            .map(([prop, val]) => `${prop}: ${val}`)
            .join(';\n    ');
        
        // Build request body with removeProperties if any were deleted
        const requestBody = {
            selector: editingSelector,
            styles: stylesString
        };
        
        if (deletedProperties.length > 0) {
            requestBody.removeProperties = deletedProperties;
        }
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                // Update original styles to current
                originalStyles = { ...allStyles };
                currentStyles = { ...allStyles };
                newProperties = [];
                deletedProperties = [];  // Clear deleted properties after successful save
                
                // Re-render to clear modified states
                renderStyleProperties();
                
                // Remove live preview (styles are now in CSS file)
                removeLivePreviewStyles();
                
                // Refresh iframe to load updated CSS from server
                const iframe = document.getElementById('preview-frame');
                if (iframe?.contentWindow) {
                    iframe.contentWindow.location.reload();
                }
                
                showToast(<?= json_encode(__admin('preview.stylesSaved') ?? 'Styles saved successfully') ?>, 'success');
            } else {
                showToast(result.message || <?= json_encode(__admin('common.saveFailed') ?? 'Failed to save') ?>, 'error');
            }
        } catch (error) {
            console.error('Failed to save styles:', error);
            showToast(<?= json_encode(__admin('common.saveFailed') ?? 'Failed to save') ?>, 'error');
        }
    }
    
    /**
     * Initialize Style Editor event listeners
     */
    function initStyleEditor() {
        // Back button
        if (styleEditorBack) {
            styleEditorBack.addEventListener('click', closeStyleEditor);
        }
        
        // Clickable label to go back (Phase 8.6.3)
        if (styleEditorLabel) {
            styleEditorLabel.style.cursor = 'pointer';
            styleEditorLabel.addEventListener('click', closeStyleEditor);
        }
        
        // Add property buttons
        if (styleEditorAddBtn) {
            styleEditorAddBtn.addEventListener('click', addNewProperty);
        }
        if (styleEditorAddFirst) {
            styleEditorAddFirst.addEventListener('click', addNewProperty);
        }
        
        // Reset button
        if (styleEditorReset) {
            styleEditorReset.addEventListener('click', resetStyleEditor);
        }
        
        // Save button
        if (styleEditorSave) {
            styleEditorSave.addEventListener('click', saveStyleEditor);
        }
        
        // Escape key to close style editor (Phase 8.6.3)
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && styleEditorVisible) {
                // Don't close if focus is in an input (let user cancel their edit first)
                const activeEl = document.activeElement;
                const isInInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA');
                if (!isInInput) {
                    e.preventDefault();
                    closeStyleEditor();
                }
            }
        });
    }
    
    // Initialize style editor
    initStyleEditor();
    
    // ==================== Node Panel ====================
    
    // Current selection state
    let selectedStruct = null;
    let selectedNode = null;
    let selectedComponent = null;
    let selectedElementClasses = null;  // For style mode preselection
    let selectedElementTag = null;      // For style mode preselection

    function showNodePanel(data) {
        // Store selection info for edit/copy actions
        selectedStruct = data.struct || null;
        selectedNode = data.isComponent ? data.componentNode : data.node;
        selectedComponent = data.component || null;
        selectedElementClasses = data.classes || null;  // Store classes for style mode
        selectedElementTag = data.tag || null;          // Store tag for style mode
        
        // Format structure name for display
        let structDisplay = data.struct || '-';
        if (structDisplay.startsWith('page-')) {
            structDisplay = 'Page: ' + structDisplay.substring(5);
        } else if (structDisplay === 'menu') {
            structDisplay = 'Menu';
        } else if (structDisplay === 'footer') {
            structDisplay = 'Footer';
        }
        
        // Update panel fields (deprecated floating panel - kept for compatibility)
        if (nodeStructEl) nodeStructEl.textContent = structDisplay;
        if (nodeIdEl) nodeIdEl.textContent = selectedNode || '-';
        if (nodeTagEl) nodeTagEl.textContent = data.tag || '-';
        if (nodeClassesEl) nodeClassesEl.textContent = data.classes || '-';
        if (nodeChildrenEl) nodeChildrenEl.textContent = data.childCount !== undefined ? data.childCount : '-';
        if (nodeTextEl) nodeTextEl.textContent = data.textContent || '-';
        
        // Show/hide component row
        if (data.isComponent && data.component) {
            if (nodeComponentEl) nodeComponentEl.textContent = data.component;
            if (nodeComponentRow) nodeComponentRow.style.display = '';
        } else {
            if (nodeComponentRow) nodeComponentRow.style.display = 'none';
        }
        
        // Show/hide textKey row
        if (data.textKeys && data.textKeys.length > 0) {
            // Show first textKey (or comma-separated if multiple)
            if (nodeTextKeyEl) nodeTextKeyEl.textContent = data.textKeys.join(', ');
            if (nodeTextKeyRow) nodeTextKeyRow.style.display = '';
        } else {
            if (nodeTextKeyRow) nodeTextKeyRow.style.display = 'none';
        }
        
        // Phase 8: Update contextual area info
        showContextualInfo(data);
        
        // (deprecated) nodePanel.classList.add('preview-node-panel--visible');
    }
    
    function hideNodePanel() {
        // (deprecated) nodePanel.classList.remove('preview-node-panel--visible');
        selectedStruct = null;
        selectedNode = null;
        selectedComponent = null;
        selectedElementClasses = null;
        selectedElementTag = null;
        
        // Phase 8: Hide contextual info
        hideContextualInfo();
        
        sendToIframe('clearSelection', {});
    }
    
    // ==================== Iframe Communication ====================
    
    function sendToIframe(action, data) {
        try {
            const iframeWindow = iframe.contentWindow;
            if (iframeWindow) {
                iframeWindow.postMessage({ source: 'quicksite-admin', action, ...data }, '*');
            }
        } catch (e) {
            console.warn('Could not send message to iframe:', e);
        }
    }
    
    function injectOverlay() {
        if (overlayInjected) {
            console.log('[Preview] Overlay already injected, skipping');
            return;
        }
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) {
                console.warn('[Preview] Could not access iframe document');
                return;
            }
            
            if (!iframeDoc.body) {
                console.warn('[Preview] Iframe body not ready yet');
                return;
            }
            
            // Check if already injected
            if (iframeDoc.getElementById('quicksite-overlay-styles')) {
                console.log('[Preview] Overlay styles found, marking as injected');
                overlayInjected = true;
                return;
            }
            
            console.log('[Preview] Injecting overlay into iframe...');
            
            // Inject CSS - now works with data-qs-* attributes
            const style = iframeDoc.createElement('style');
            style.id = 'quicksite-overlay-styles';
            style.textContent = `
                /* ===== SELECT MODE STYLES ===== */
                .qs-hover {
                    outline: 2px dashed #d97706 !important;
                    outline-offset: 2px !important;
                }
                .qs-selected {
                    outline: 2px solid #d97706 !important;
                    outline-offset: 2px !important;
                    background-color: rgba(217, 119, 6, 0.1) !important;
                }
                /* Highlight component boundary differently */
                [data-qs-component].qs-hover {
                    outline-color: #2563eb !important;
                }
                [data-qs-component].qs-selected {
                    outline-color: #2563eb !important;
                    background-color: rgba(37, 99, 235, 0.1) !important;
                }
                
                /* ===== DRAG MODE STYLES ===== */
                .qs-draggable {
                    cursor: grab !important;
                }
                .qs-draggable:hover {
                    outline: 2px dashed #10b981 !important;
                    outline-offset: 2px !important;
                }
                .qs-dragging {
                    opacity: 0.5 !important;
                    cursor: grabbing !important;
                }
                .qs-drag-over {
                    outline: 2px solid #10b981 !important;
                    outline-offset: 2px !important;
                }
                .qs-drop-indicator {
                    position: absolute;
                    left: 0;
                    right: 0;
                    height: 4px;
                    background: #10b981;
                    pointer-events: none;
                    z-index: 99999;
                    border-radius: 2px;
                    box-shadow: 0 0 8px rgba(16, 185, 129, 0.5);
                }
                .qs-drop-indicator::before {
                    content: '';
                    position: absolute;
                    left: -4px;
                    top: -4px;
                    width: 12px;
                    height: 12px;
                    background: #10b981;
                    border-radius: 50%;
                }
                .qs-drop-indicator::after {
                    content: '';
                    position: absolute;
                    right: -4px;
                    top: -4px;
                    width: 12px;
                    height: 12px;
                    background: #10b981;
                    border-radius: 50%;
                }
                .qs-cannot-drop {
                    cursor: not-allowed !important;
                    outline: 2px dashed #ef4444 !important;
                }
                
                /* ===== TEXT MODE STYLES ===== */
                .qs-text-editable {
                    cursor: text !important;
                }
                .qs-text-editable:hover {
                    outline: 2px dashed #eab308 !important;
                    outline-offset: 1px !important;
                    background-color: rgba(234, 179, 8, 0.1) !important;
                }
                .qs-text-editing {
                    outline: 2px solid #eab308 !important;
                    outline-offset: 1px !important;
                    background-color: rgba(234, 179, 8, 0.15) !important;
                    min-width: 20px;
                    min-height: 1em;
                }
                .qs-text-editing:focus {
                    outline: 2px solid #ca8a04 !important;
                }
                
                /* ===== STYLE MODE STYLES ===== */
                .qs-styleable {
                    cursor: pointer !important;
                }
                .qs-styleable:hover {
                    outline: 2px dashed #8b5cf6 !important;
                    outline-offset: 2px !important;
                    background-color: rgba(139, 92, 246, 0.05) !important;
                }
                .qs-style-selected {
                    outline: 2px solid #8b5cf6 !important;
                    outline-offset: 2px !important;
                    background-color: rgba(139, 92, 246, 0.1) !important;
                }
                
                /* ===== SELECTOR BROWSER HIGHLIGHT STYLES (Phase 8.4) ===== */
                .qs-selector-hover {
                    outline: 2px dashed #06b6d4 !important;
                    outline-offset: 2px !important;
                    background-color: rgba(6, 182, 212, 0.1) !important;
                }
                .qs-selector-selected {
                    outline: 2px solid #06b6d4 !important;
                    outline-offset: 2px !important;
                    background-color: rgba(6, 182, 212, 0.15) !important;
                }
            `;
            iframeDoc.head.appendChild(style);
            
            // Inject script - uses data-qs-* attributes for node info
            const script = iframeDoc.createElement('script');
            script.id = 'quicksite-overlay-script';
            script.textContent = `
                (function() {
                    console.log('[QuickSite] Overlay script loaded');
                    let currentMode = 'select';
                    let hoveredElement = null;
                    let selectedElement = null;
                    
                    // Drag state
                    let isDragging = false;
                    let dragElement = null;
                    let dragInfo = null;
                    let dropIndicator = null;
                    let dropTarget = null;
                    let dropPosition = null; // 'before' or 'after'
                    
                    // Text edit state
                    let editingElement = null;
                    let editingTextKey = null;
                    let originalText = null;
                    
                    // Elements to ignore
                    const ignoreTags = ['SCRIPT', 'STYLE', 'META', 'LINK', 'NOSCRIPT', 'BR', 'HR', 'HTML', 'HEAD'];
                    
                    // Listen for messages from admin
                    window.addEventListener('message', function(e) {
                        if (e.data && e.data.source === 'quicksite-admin') {
                            console.log('[QuickSite] Received message:', e.data.action);
                            if (e.data.action === 'setMode') {
                                setMode(e.data.mode);
                            }
                            if (e.data.action === 'clearSelection') {
                                clearSelection();
                            }
                            if (e.data.action === 'highlightNode') {
                                highlightByNode(e.data.struct, e.data.node);
                            }
                            if (e.data.action === 'insertNode') {
                                insertNodeIntoDom(e.data.struct, e.data.targetNode, e.data.position, e.data.html, e.data.newNodeId);
                            }
                            if (e.data.action === 'updateNode') {
                                updateNodeInDom(e.data.struct, e.data.nodeId, e.data.html);
                            }
                            if (e.data.action === 'removeNode') {
                                removeNodeFromDom(e.data.struct, e.data.nodeId);
                            }
                            if (e.data.action === 'clearStyleSelection') {
                                clearStyleSelection();
                            }
                            if (e.data.action === 'applyLiveStyle') {
                                applyLiveStyle(e.data.struct, e.data.nodeId, e.data.property, e.data.value);
                            }
                            // Phase 8.4: Selector-based highlighting
                            if (e.data.action === 'highlightBySelector') {
                                highlightBySelector(e.data.selector);
                            }
                            if (e.data.action === 'selectBySelector') {
                                selectBySelector(e.data.selector);
                            }
                            if (e.data.action === 'clearSelectorHighlight') {
                                clearSelectorHighlight();
                            }
                        }
                    });
                    
                    // Insert a new node into the DOM without full page reload
                    function insertNodeIntoDom(struct, targetNode, position, html, newNodeId) {
                        console.log('[QuickSite] Inserting node:', { struct, targetNode, position, newNodeId });
                        
                        // Find the target element
                        const targetEl = document.querySelector('[data-qs-struct="' + struct + '"][data-qs-node="' + targetNode + '"]');
                        if (!targetEl) {
                            console.error('[QuickSite] Target element not found:', struct, targetNode);
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'insertNodeFailed', 
                                error: 'Target element not found' 
                            }, '*');
                            return;
                        }
                        
                        // Create a temporary container to parse the HTML
                        const temp = document.createElement('div');
                        temp.innerHTML = html;
                        const newElement = temp.firstElementChild;
                        
                        if (!newElement) {
                            console.error('[QuickSite] Invalid HTML for new node');
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'insertNodeFailed', 
                                error: 'Invalid HTML' 
                            }, '*');
                            return;
                        }
                        
                        // Insert based on position
                        if (position === 'before') {
                            targetEl.parentNode.insertBefore(newElement, targetEl);
                        } else if (position === 'after') {
                            targetEl.parentNode.insertBefore(newElement, targetEl.nextSibling);
                        } else if (position === 'inside') {
                            // Insert as first child
                            targetEl.insertBefore(newElement, targetEl.firstChild);
                        }
                        
                        console.log('[QuickSite] Node inserted successfully:', newNodeId);
                        
                        // Notify parent of success
                        window.parent.postMessage({ 
                            source: 'quicksite-preview', 
                            action: 'insertNodeSuccess', 
                            nodeId: newNodeId 
                        }, '*');
                        
                        // Select the new element
                        clearSelection();
                        selectedElement = newElement;
                        newElement.classList.add('qs-selected');
                        newElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    
                    // Update an existing node in the DOM (replace its content)
                    function updateNodeInDom(struct, nodeId, html) {
                        console.log('[QuickSite] Updating node:', { struct, nodeId });
                        
                        // Find the target element
                        const targetEl = document.querySelector('[data-qs-struct="' + struct + '"][data-qs-node="' + nodeId + '"]');
                        if (!targetEl) {
                            console.error('[QuickSite] Target element not found:', struct, nodeId);
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'updateNodeFailed', 
                                error: 'Target element not found' 
                            }, '*');
                            return;
                        }
                        
                        // Create a temporary container to parse the HTML
                        const temp = document.createElement('div');
                        temp.innerHTML = html;
                        const newElement = temp.firstElementChild;
                        
                        if (!newElement) {
                            console.error('[QuickSite] Invalid HTML for node update');
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'updateNodeFailed', 
                                error: 'Invalid HTML' 
                            }, '*');
                            return;
                        }
                        
                        // Replace the old element with the new one
                        targetEl.parentNode.replaceChild(newElement, targetEl);
                        
                        console.log('[QuickSite] Node updated successfully:', nodeId);
                        
                        // Notify parent of success
                        window.parent.postMessage({ 
                            source: 'quicksite-preview', 
                            action: 'updateNodeSuccess', 
                            nodeId: nodeId 
                        }, '*');
                        
                        // Re-select the updated element
                        clearSelection();
                        selectedElement = newElement;
                        newElement.classList.add('qs-selected');
                    }
                    
                    // Remove a node from the DOM and reindex siblings
                    function removeNodeFromDom(struct, nodeId) {
                        console.log('[QuickSite] Removing node:', { struct, nodeId });
                        
                        // Find the target element
                        const targetEl = document.querySelector('[data-qs-struct="' + struct + '"][data-qs-node="' + nodeId + '"]');
                        if (!targetEl) {
                            console.error('[QuickSite] Target element not found:', struct, nodeId);
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'removeNodeFailed', 
                                error: 'Target element not found' 
                            }, '*');
                            return;
                        }
                        
                        // Get parent element and identify siblings that need reindexing
                        const parent = targetEl.parentNode;
                        const nodeIdParts = nodeId.split('.');
                        const deletedIndex = parseInt(nodeIdParts[nodeIdParts.length - 1], 10);
                        const parentPath = nodeIdParts.slice(0, -1).join('.');
                        
                        // Remove the element
                        targetEl.remove();
                        
                        // Reindex siblings with higher indices
                        // Find all elements in same struct with same parent path but higher index
                        const allInStruct = document.querySelectorAll('[data-qs-struct="' + struct + '"]');
                        allInStruct.forEach(function(el) {
                            const elNodeId = el.getAttribute('data-qs-node');
                            if (!elNodeId) return;
                            
                            const elParts = elNodeId.split('.');
                            const elParentPath = elParts.slice(0, -1).join('.');
                            const elIndex = parseInt(elParts[elParts.length - 1], 10);
                            
                            // Same parent path, and index is greater than deleted
                            if (elParentPath === parentPath && elIndex > deletedIndex) {
                                // Decrement the index
                                const newIndex = elIndex - 1;
                                const newNodeId = parentPath ? parentPath + '.' + newIndex : String(newIndex);
                                el.setAttribute('data-qs-node', newNodeId);
                                console.log('[QuickSite] Reindexed node:', elNodeId, '->', newNodeId);
                                
                                // Also reindex any child elements
                                reindexChildNodes(el, struct, elNodeId, newNodeId);
                            }
                        });
                        
                        console.log('[QuickSite] Node removed successfully:', nodeId);
                        
                        // Notify parent of success
                        window.parent.postMessage({ 
                            source: 'quicksite-preview', 
                            action: 'removeNodeSuccess', 
                            nodeId: nodeId 
                        }, '*');
                        
                        clearSelection();
                    }
                    
                    // Helper: reindex child nodes when parent's nodeId changed
                    function reindexChildNodes(parentEl, struct, oldParentId, newParentId) {
                        const children = parentEl.querySelectorAll('[data-qs-struct="' + struct + '"]');
                        children.forEach(function(child) {
                            const childNodeId = child.getAttribute('data-qs-node');
                            if (childNodeId && childNodeId.startsWith(oldParentId + '.')) {
                                const newChildId = newParentId + childNodeId.substring(oldParentId.length);
                                child.setAttribute('data-qs-node', newChildId);
                                console.log('[QuickSite] Reindexed child:', childNodeId, '->', newChildId);
                            }
                        });
                    }
                    
                    function setMode(mode) {
                        // Clean up previous mode
                        if (currentMode === 'drag') {
                            disableDragMode();
                        }
                        if (currentMode === 'text') {
                            disableTextMode();
                        }
                        if (currentMode === 'style') {
                            disableStyleMode();
                        }
                        
                        currentMode = mode;
                        clearHover();
                        if (mode !== 'select' && mode !== 'style') clearSelection();
                        
                        // Enable new mode
                        if (mode === 'drag') {
                            enableDragMode();
                        }
                        if (mode === 'text') {
                            enableTextMode();
                        }
                        if (mode === 'style') {
                            enableStyleMode();
                        }
                    }
                    
                    function clearHover() {
                        if (hoveredElement) {
                            hoveredElement.classList.remove('qs-hover');
                            hoveredElement = null;
                        }
                    }
                    
                    function clearSelection() {
                        if (selectedElement) {
                            selectedElement.classList.remove('qs-selected');
                            selectedElement = null;
                        }
                    }
                    
                    function highlightByNode(struct, node) {
                        clearSelection();
                        const el = document.querySelector('[data-qs-struct="' + struct + '"][data-qs-node="' + node + '"]');
                        if (el) {
                            selectedElement = el;
                            el.classList.add('qs-selected');
                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                    
                    // ===== Selector Browser Functions (Phase 8.4) =====
                    let selectorHoveredElements = [];
                    let selectorSelectedElements = [];
                    
                    function highlightBySelector(selector) {
                        // Clear previous hover highlights
                        clearSelectorHover();
                        
                        try {
                            const elements = document.querySelectorAll(selector);
                            elements.forEach(el => {
                                if (!ignoreTags.includes(el.tagName)) {
                                    el.classList.add('qs-selector-hover');
                                    selectorHoveredElements.push(el);
                                }
                            });
                        } catch (e) {
                            console.warn('[QuickSite] Invalid selector:', selector, e);
                        }
                    }
                    
                    function selectBySelector(selector) {
                        // Clear previous selection
                        clearSelectorSelection();
                        
                        try {
                            const elements = document.querySelectorAll(selector);
                            elements.forEach(el => {
                                if (!ignoreTags.includes(el.tagName)) {
                                    el.classList.add('qs-selector-selected');
                                    selectorSelectedElements.push(el);
                                }
                            });
                            
                            // Scroll to first matched element
                            if (selectorSelectedElements.length > 0) {
                                selectorSelectedElements[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        } catch (e) {
                            console.warn('[QuickSite] Invalid selector:', selector, e);
                        }
                    }
                    
                    function clearSelectorHover() {
                        selectorHoveredElements.forEach(el => {
                            el.classList.remove('qs-selector-hover');
                        });
                        selectorHoveredElements = [];
                    }
                    
                    function clearSelectorSelection() {
                        selectorSelectedElements.forEach(el => {
                            el.classList.remove('qs-selector-selected');
                        });
                        selectorSelectedElements = [];
                    }
                    
                    function clearSelectorHighlight() {
                        clearSelectorHover();
                        clearSelectorSelection();
                    }
                    
                    // Find the selectable target (bubble up to component if inside one)
                    function getSelectableTarget(el) {
                        if (!el || el === document.body || el === document.documentElement) return null;
                        if (ignoreTags.includes(el.tagName)) return null;
                        
                        // Check if element has editor attributes
                        if (!el.hasAttribute('data-qs-struct')) {
                            // No editor attributes, find closest parent with them
                            el = el.closest('[data-qs-struct]');
                            if (!el) return null;
                        }
                        
                        // If inside a component, bubble up to component root
                        if (el.hasAttribute('data-qs-in-component') && !el.hasAttribute('data-qs-component')) {
                            const componentRoot = el.closest('[data-qs-component]');
                            if (componentRoot) {
                                return componentRoot;
                            }
                        }
                        
                        return el;
                    }
                    
                    // Get draggable elements (only direct children of the body struct or component children)
                    function getDraggableTarget(el) {
                        if (!el || el === document.body || el === document.documentElement) return null;
                        if (ignoreTags.includes(el.tagName)) return null;
                        
                        // Check if element has editor attributes
                        if (!el.hasAttribute('data-qs-struct')) {
                            el = el.closest('[data-qs-struct]');
                            if (!el) return null;
                        }
                        
                        // For components, treat the whole component as one draggable unit
                        if (el.hasAttribute('data-qs-in-component') && !el.hasAttribute('data-qs-component')) {
                            const componentRoot = el.closest('[data-qs-component]');
                            if (componentRoot) return componentRoot;
                        }
                        
                        return el;
                    }
                    
                    function getElementInfo(el) {
                        // Get editor data attributes
                        const struct = el.getAttribute('data-qs-struct');
                        const node = el.getAttribute('data-qs-node');
                        const component = el.getAttribute('data-qs-component');
                        const componentNode = el.getAttribute('data-qs-component-node');
                        
                        const id = el.id || null;
                        const classes = el.className && typeof el.className === 'string' 
                            ? el.className.replace(/qs-(hover|selected|draggable|dragging|drag-over|text-editable|text-editing)/g, '').trim() 
                            : null;
                        
                        // Get direct text content
                        let textContent = null;
                        for (const child of el.childNodes) {
                            if (child.nodeType === 3 && child.textContent.trim()) {
                                textContent = child.textContent.trim().substring(0, 100);
                                break;
                            }
                        }
                        
                        // Get textKeys from element or its children
                        const textKeys = [];
                        // Check if this element itself has a textKey
                        if (el.hasAttribute('data-qs-textkey')) {
                            textKeys.push(el.getAttribute('data-qs-textkey'));
                        }
                        // Also check immediate children with textKeys
                        el.querySelectorAll('[data-qs-textkey]').forEach(function(textEl) {
                            const key = textEl.getAttribute('data-qs-textkey');
                            if (key && !textKeys.includes(key)) {
                                textKeys.push(key);
                            }
                        });
                        
                        return {
                            // Editor info
                            struct: struct,
                            node: node,
                            component: component,
                            componentNode: componentNode,
                            isComponent: !!component,
                            // DOM info
                            tag: el.tagName.toLowerCase(),
                            id: id,
                            classes: classes || null,
                            childCount: el.children.length,
                            textContent: textContent,
                            textKeys: textKeys
                        };
                    }
                    
                    // ===== DRAG MODE FUNCTIONALITY =====
                    
                    function enableDragMode() {
                        // Mark all draggable elements
                        document.querySelectorAll('[data-qs-node]').forEach(el => {
                            // Only mark top-level nodes or component roots as draggable
                            if (!el.hasAttribute('data-qs-in-component') || el.hasAttribute('data-qs-component')) {
                                el.classList.add('qs-draggable');
                            }
                        });
                        
                        // Create drop indicator
                        dropIndicator = document.createElement('div');
                        dropIndicator.className = 'qs-drop-indicator';
                        dropIndicator.style.display = 'none';
                        document.body.appendChild(dropIndicator);
                        
                        console.log('[QuickSite] Drag mode enabled');
                    }
                    
                    function disableDragMode() {
                        // Remove draggable class from all elements
                        document.querySelectorAll('.qs-draggable').forEach(el => {
                            el.classList.remove('qs-draggable', 'qs-dragging', 'qs-drag-over');
                        });
                        
                        // Remove drop indicator
                        if (dropIndicator) {
                            dropIndicator.remove();
                            dropIndicator = null;
                        }
                        
                        isDragging = false;
                        dragElement = null;
                        dragInfo = null;
                        dropTarget = null;
                        dropPosition = null;
                        
                        console.log('[QuickSite] Drag mode disabled');
                    }
                    
                    // ===== TEXT MODE FUNCTIONALITY =====
                    
                    function enableTextMode() {
                        // Mark all text-editable elements
                        document.querySelectorAll('[data-qs-textkey]').forEach(el => {
                            el.classList.add('qs-text-editable');
                        });
                        console.log('[QuickSite] Text mode enabled');
                    }
                    
                    function disableTextMode() {
                        // Cancel any active editing
                        if (editingElement) {
                            cancelTextEdit();
                        }
                        
                        // Remove editable class from all elements
                        document.querySelectorAll('.qs-text-editable').forEach(el => {
                            el.classList.remove('qs-text-editable', 'qs-text-editing');
                        });
                        console.log('[QuickSite] Text mode disabled');
                    }
                    
                    function startTextEdit(el) {
                        if (editingElement) {
                            // Save current edit before starting new one
                            finishTextEdit();
                        }
                        
                        editingElement = el;
                        editingTextKey = el.getAttribute('data-qs-textkey');
                        originalText = el.textContent;
                        
                        el.classList.add('qs-text-editing');
                        el.setAttribute('contenteditable', 'true');
                        el.focus();
                        
                        // Select all text for easy replacement
                        const selection = window.getSelection();
                        const range = document.createRange();
                        range.selectNodeContents(el);
                        selection.removeAllRanges();
                        selection.addRange(range);
                        
                        console.log('[QuickSite] Started editing:', editingTextKey);
                    }
                    
                    function finishTextEdit() {
                        if (!editingElement) return;
                        
                        const newText = editingElement.textContent.trim();
                        const textKey = editingTextKey;
                        const struct = editingElement.closest('[data-qs-struct]')?.getAttribute('data-qs-struct') || '';
                        
                        // Clean up editing state
                        editingElement.classList.remove('qs-text-editing');
                        editingElement.setAttribute('contenteditable', 'false');
                        
                        // Only save if text actually changed
                        if (newText !== originalText) {
                            console.log('[QuickSite] Text changed:', textKey, originalText, '->', newText);
                            
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'textEdited',
                                textKey: textKey,
                                newValue: newText,
                                oldValue: originalText,
                                structure: struct
                            }, '*');
                        } else {
                            console.log('[QuickSite] Text unchanged, not saving');
                        }
                        
                        editingElement = null;
                        editingTextKey = null;
                        originalText = null;
                    }
                    
                    function cancelTextEdit() {
                        if (!editingElement) return;
                        
                        // Restore original text
                        editingElement.textContent = originalText;
                        editingElement.classList.remove('qs-text-editing');
                        editingElement.setAttribute('contenteditable', 'false');
                        
                        console.log('[QuickSite] Edit cancelled, restored:', editingTextKey);
                        
                        editingElement = null;
                        editingTextKey = null;
                        originalText = null;
                    }
                    
                    // ===== STYLE MODE FUNCTIONALITY =====
                    
                    function enableStyleMode() {
                        // Mark all styleable elements
                        document.querySelectorAll('[data-qs-node]').forEach(el => {
                            el.classList.add('qs-styleable');
                        });
                        console.log('[QuickSite] Style mode enabled');
                    }
                    
                    function disableStyleMode() {
                        // Remove styleable class from all elements
                        document.querySelectorAll('.qs-styleable').forEach(el => {
                            el.classList.remove('qs-styleable', 'qs-style-selected');
                        });
                        console.log('[QuickSite] Style mode disabled');
                    }
                    
                    function getStyleInfo(el) {
                        const computed = window.getComputedStyle(el);
                        
                        // Get class list (filter out our overlay classes)
                        const classList = Array.from(el.classList).filter(c => 
                            !c.startsWith('qs-')
                        );
                        
                        // CSS properties we expose in the simplified panel
                        const commonProps = [
                            'padding', 'margin',
                            'color', 'background-color',
                            'font-size', 'font-weight',
                            'width', 'max-width',
                            'border-radius',
                            'display', 'gap'
                        ];
                        
                        const styles = {};
                        commonProps.forEach(prop => {
                            styles[prop] = computed.getPropertyValue(prop);
                        });
                        
                        // Detect CSS variables in use (check inline style)
                        const cssVars = [];
                        const inlineStyle = el.getAttribute('style') || '';
                        const varMatches = inlineStyle.matchAll(/var\((--[\w-]+)\)/g);
                        for (const match of varMatches) {
                            cssVars.push(match[1]);
                        }
                        
                        // Build selector suggestion
                        const tag = el.tagName.toLowerCase();
                        let selector = tag;
                        if (classList.length > 0) {
                            selector = '.' + classList[0]; // Primary class
                        } else if (el.id) {
                            selector = '#' + el.id;
                        }
                        
                        return {
                            selector: selector,
                            tag: tag,
                            id: el.id || null,
                            classList: classList,
                            styles: styles,
                            cssVars: cssVars
                        };
                    }
                    
                    // Clear style selection (called from parent)
                    function clearStyleSelection() {
                        document.querySelectorAll('.qs-style-selected').forEach(el => {
                            el.classList.remove('qs-style-selected');
                        });
                    }
                    
                    // Apply live style preview to an element
                    function applyLiveStyle(struct, nodeId, property, value) {
                        const selector = '[data-qs-struct="' + struct + '"][data-qs-node="' + nodeId + '"]';
                        const el = document.querySelector(selector);
                        if (el) {
                            // Convert property to camelCase for style property
                            const camelProp = property.replace(/-([a-z])/g, (g) => g[1].toUpperCase());
                            el.style[camelProp] = value;
                            console.log('[QuickSite] Live style applied:', property, '=', value);
                        }
                    }
                    
                    function canDropAt(source, target, position) {
                        if (!source || !target) return false;
                        if (source === target) return false;
                        
                        // Can't drop into itself or its children
                        if (target.contains(source)) return false;
                        
                        // Get structures - must be in the same structure
                        const sourceStruct = source.getAttribute('data-qs-struct');
                        const targetStruct = target.getAttribute('data-qs-struct');
                        
                        // For now, only allow reordering within the same structure
                        // (e.g., within the same page body, or within menu)
                        if (sourceStruct !== targetStruct) return false;
                        
                        // Elements must be siblings or can be moved to become siblings
                        // For simplicity, we'll allow moves within the same parent or adjacent
                        return true;
                    }
                    
                    function showDropIndicator(targetEl, position) {
                        if (!dropIndicator || !targetEl) return;
                        
                        const rect = targetEl.getBoundingClientRect();
                        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
                        
                        dropIndicator.style.display = 'block';
                        dropIndicator.style.left = (rect.left + scrollLeft) + 'px';
                        dropIndicator.style.width = rect.width + 'px';
                        
                        if (position === 'before') {
                            dropIndicator.style.top = (rect.top + scrollTop - 2) + 'px';
                        } else {
                            dropIndicator.style.top = (rect.bottom + scrollTop - 2) + 'px';
                        }
                    }
                    
                    function hideDropIndicator() {
                        if (dropIndicator) {
                            dropIndicator.style.display = 'none';
                        }
                    }
                    
                    function handleDragStart(e, el) {
                        isDragging = true;
                        dragElement = el;
                        dragInfo = getElementInfo(el);
                        el.classList.add('qs-dragging');
                        
                        console.log('[QuickSite] Drag started:', dragInfo);
                    }
                    
                    function handleDragMove(e) {
                        if (!isDragging || !dragElement) return;
                        
                        // Find potential drop target
                        const elementsAtPoint = document.elementsFromPoint(e.clientX, e.clientY);
                        let foundTarget = null;
                        
                        for (const el of elementsAtPoint) {
                            if (el === dragElement || el === dropIndicator) continue;
                            if (el.classList.contains('qs-draggable')) {
                                foundTarget = el;
                                break;
                            }
                        }
                        
                        if (foundTarget) {
                            // Determine if dropping before or after
                            const rect = foundTarget.getBoundingClientRect();
                            const midY = rect.top + rect.height / 2;
                            const position = e.clientY < midY ? 'before' : 'after';
                            
                            if (canDropAt(dragElement, foundTarget, position)) {
                                // Clear previous drop target
                                if (dropTarget && dropTarget !== foundTarget) {
                                    dropTarget.classList.remove('qs-drag-over');
                                }
                                
                                dropTarget = foundTarget;
                                dropPosition = position;
                                foundTarget.classList.add('qs-drag-over');
                                showDropIndicator(foundTarget, position);
                            } else {
                                hideDropIndicator();
                                if (dropTarget) {
                                    dropTarget.classList.remove('qs-drag-over');
                                    dropTarget = null;
                                }
                            }
                        } else {
                            hideDropIndicator();
                            if (dropTarget) {
                                dropTarget.classList.remove('qs-drag-over');
                                dropTarget = null;
                            }
                        }
                    }
                    
                    function handleDragEnd(e) {
                        if (!isDragging) return;
                        
                        // Clean up drag state
                        if (dragElement) {
                            dragElement.classList.remove('qs-dragging');
                        }
                        if (dropTarget) {
                            dropTarget.classList.remove('qs-drag-over');
                        }
                        hideDropIndicator();
                        
                        // If we have a valid drop target, notify parent
                        if (dropTarget && dropPosition && dragInfo) {
                            const targetInfo = getElementInfo(dropTarget);
                            
                            console.log('[QuickSite] Drop:', {
                                sourceElement: dragInfo,
                                targetElement: targetInfo,
                                position: dropPosition
                            });
                            
                            window.parent.postMessage({ 
                                source: 'quicksite-preview', 
                                action: 'elementMoved',
                                sourceElement: dragInfo,
                                targetElement: targetInfo,
                                position: dropPosition
                            }, '*');
                        }
                        
                        isDragging = false;
                        dragElement = null;
                        dragInfo = null;
                        dropTarget = null;
                        dropPosition = null;
                    }
                    
                    // ===== EVENT HANDLERS =====
                    
                    // Hover handling
                    document.addEventListener('mouseover', function(e) {
                        if (currentMode !== 'select') return;
                        
                        const target = getSelectableTarget(e.target);
                        if (!target || target === hoveredElement || target === selectedElement) return;
                        
                        clearHover();
                        hoveredElement = target;
                        target.classList.add('qs-hover');
                    }, true);
                    
                    document.addEventListener('mouseout', function(e) {
                        if (currentMode !== 'select') return;
                        const target = getSelectableTarget(e.target);
                        if (target && target === hoveredElement) {
                            clearHover();
                        }
                    }, true);
                    
                    // Click handling
                    document.addEventListener('click', function(e) {
                        // Prevent link navigation in all editor modes
                        if (currentMode === 'select' || currentMode === 'drag' || currentMode === 'text' || currentMode === 'style') {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                        
                        if (currentMode === 'select') {
                            e.stopImmediatePropagation();
                            
                            const target = getSelectableTarget(e.target);
                            if (target) {
                                clearSelection();
                                clearHover();
                                
                                selectedElement = target;
                                target.classList.add('qs-selected');
                                
                                const info = getElementInfo(target);
                                console.log('[QuickSite] Element selected:', info);
                                
                                window.parent.postMessage({ 
                                    source: 'quicksite-preview', 
                                    action: 'elementSelected',
                                    ...info
                                }, '*');
                            }
                            return false;
                        }
                        
                        // Text mode: click on textkey element to edit
                        if (currentMode === 'text') {
                            const textEl = e.target.closest('[data-qs-textkey]');
                            if (textEl && textEl !== editingElement) {
                                startTextEdit(textEl);
                            }
                        }
                        
                        // Style mode: click on element to get style info
                        if (currentMode === 'style') {
                            e.stopImmediatePropagation();
                            
                            const target = getSelectableTarget(e.target);
                            if (target) {
                                // Remove previous selection
                                document.querySelectorAll('.qs-style-selected').forEach(el => {
                                    el.classList.remove('qs-style-selected');
                                });
                                
                                selectedElement = target;
                                target.classList.add('qs-style-selected');
                                
                                const elementInfo = getElementInfo(target);
                                const styleInfo = getStyleInfo(target);
                                
                                console.log('[QuickSite] Style mode - element selected:', styleInfo);
                                
                                window.parent.postMessage({ 
                                    source: 'quicksite-preview', 
                                    action: 'styleSelected',
                                    element: elementInfo,
                                    style: styleInfo
                                }, '*');
                            }
                            return false;
                        }
                    }, true);
                    
                    // Keyboard handling for text editing
                    document.addEventListener('keydown', function(e) {
                        if (currentMode === 'text' && editingElement) {
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                cancelTextEdit();
                            }
                            // Enter always saves (no newlines - would break JSON translations)
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                finishTextEdit();
                            }
                        }
                    }, true);
                    
                    // Blur handling for text editing
                    document.addEventListener('blur', function(e) {
                        if (currentMode === 'text' && editingElement && e.target === editingElement) {
                            // Small delay to allow click on another textkey to work
                            setTimeout(() => {
                                if (editingElement === e.target) {
                                    finishTextEdit();
                                }
                            }, 100);
                        }
                    }, true);
                    
                    // Mouse events for drag mode
                    document.addEventListener('mousedown', function(e) {
                        if (currentMode === 'select') {
                            e.preventDefault();
                        }
                        
                        if (currentMode === 'drag') {
                            const target = getDraggableTarget(e.target);
                            if (target && target.classList.contains('qs-draggable')) {
                                e.preventDefault();
                                handleDragStart(e, target);
                            }
                        }
                    }, true);
                    
                    document.addEventListener('mousemove', function(e) {
                        if (currentMode === 'drag' && isDragging) {
                            e.preventDefault();
                            handleDragMove(e);
                        }
                    }, true);
                    
                    document.addEventListener('mouseup', function(e) {
                        if (currentMode === 'drag' && isDragging) {
                            e.preventDefault();
                            handleDragEnd(e);
                        }
                    }, true);
                    
                    // Notify parent that we're ready
                    window.parent.postMessage({ 
                        source: 'quicksite-preview', 
                        action: 'overlayReady'
                    }, '*');
                })();
            `;
            iframeDoc.body.appendChild(script);
            
            overlayInjected = true;
            console.log('[Preview] Overlay injection successful!');
        } catch (e) {
            console.warn('[Preview] Could not inject overlay (cross-origin?):', e);
        }
    }
    
    // Listen for messages from iframe
    window.addEventListener('message', function(e) {
        if (e.data && e.data.source === 'quicksite-preview') {
            console.log('[Preview] Message from iframe:', e.data);
            if (e.data.action === 'elementSelected') {
                showNodePanel(e.data);
            }
            if (e.data.action === 'overlayReady') {
                console.log('[Preview] Iframe overlay is ready, restoring mode:', currentMode);
                // Re-send current mode to iframe (preserves drag mode after reload)
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ action: 'setMode', mode: currentMode }, '*');
                    if (currentMode !== 'select') {
                        iframe.contentWindow.postMessage({ action: 'clearSelection' }, '*');
                    }
                }
            }
            if (e.data.action === 'elementMoved') {
                handleElementMoved(e.data);
            }
            if (e.data.action === 'textEdited') {
                handleTextEdited(e.data);
            }
            if (e.data.action === 'styleSelected') {
                showStylePanel(e.data);
            }
        }
    });
    
    // ==================== Scroll To Node ====================
    
    /**
     * Scroll the iframe to a specific node
     */
    function scrollToNode(struct, nodeId) {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            const selector = `[data-qs-struct="${struct}"][data-qs-node="${nodeId}"]`;
            const element = iframeDoc.querySelector(selector);
            
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Flash highlight effect
                element.style.outline = '3px solid var(--primary, #3b82f6)';
                element.style.outlineOffset = '2px';
                setTimeout(() => {
                    element.style.outline = '';
                    element.style.outlineOffset = '';
                }, 1500);
                console.log('[Preview] Scrolled to node:', struct, nodeId);
            } else {
                console.log('[Preview] Node not found for scroll:', selector);
            }
        } catch (err) {
            console.error('[Preview] Scroll to node failed:', err);
        }
    }
    
    // ==================== Drag & Drop Handler ====================
    
    /**
     * Parse struct string into type and name
     * Format: "page-home" -> {type:"page", name:"home"}, "menu" -> {type:"menu", name:null}
     */
    function parseStruct(struct) {
        if (!struct) return null;
        
        // menu, footer are simple types
        if (struct === 'menu' || struct === 'footer') {
            return { type: struct, name: null };
        }
        
        // page-{name} format
        if (struct.startsWith('page-')) {
            return { type: 'page', name: struct.substring(5) };
        }
        
        // component-{name} format (if ever used)
        if (struct.startsWith('component-')) {
            return { type: 'component', name: struct.substring(10) };
        }
        
        return null;
    }
    
    /**
     * Get a node from structure by nodeId (e.g., "0.2.1")
     */
    function getNodeByPath(structure, nodeId) {
        const indices = nodeId.split('.').map(Number);
        let current = structure;
        
        for (let i = 0; i < indices.length; i++) {
            if (!Array.isArray(current)) {
                current = current.children;
            }
            if (!current || !Array.isArray(current)) return null;
            current = current[indices[i]];
            if (!current) return null;
        }
        return current;
    }
    
    /**
     * Deep clone an object, removing _nodeId properties
     */
    function cloneWithoutNodeIds(obj) {
        if (obj === null || typeof obj !== 'object') return obj;
        if (Array.isArray(obj)) return obj.map(cloneWithoutNodeIds);
        
        const clone = {};
        for (const key of Object.keys(obj)) {
            if (key === '_nodeId') continue;
            clone[key] = cloneWithoutNodeIds(obj[key]);
        }
        return clone;
    }
    
    async function handleElementMoved(data) {
        console.log('[Preview] Element moved:', data);
        
        const source = data.sourceElement;
        const target = data.targetElement;
        const position = data.position; // 'before' or 'after'
        
        if (!source || !target || !source.struct || !source.node || !target.node) {
            console.error('[Preview] Invalid move data:', { source, target, position });
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid move data', 'error');
            return;
        }
        
        // Parse struct to get type and name
        const structInfo = parseStruct(source.struct);
        if (!structInfo || !structInfo.type) {
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid structure type', 'error');
            return;
        }
        
        // Get the DOM elements from iframe BEFORE API call (for optimistic update)
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        const sourceSelector = `[data-qs-struct="${source.struct}"][data-qs-node="${source.node}"]`;
        const targetSelector = `[data-qs-struct="${target.struct}"][data-qs-node="${target.node}"]`;
        const sourceEl = iframeDoc.querySelector(sourceSelector);
        const targetEl = iframeDoc.querySelector(targetSelector);
        
        if (!sourceEl || !targetEl) {
            console.error('[Preview] DOM elements not found:', { sourceEl, targetEl });
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Elements not found', 'error');
            return;
        }
        
        showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
        
        try {
            // Use the atomic moveNode command
            const params = {
                type: structInfo.type,
                sourceNodeId: source.node,
                targetNodeId: target.node,
                position: position
            };
            if (structInfo.name) {
                params.name = structInfo.name;
            }
            
            console.log('[Preview] Moving node:', params);
            const result = await QuickSiteAdmin.apiRequest('moveNode', 'PATCH', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to move node');
            }
            
            // Success! Move the DOM element directly (no reload needed)
            if (position === 'after') {
                targetEl.parentNode.insertBefore(sourceEl, targetEl.nextSibling);
            } else {
                targetEl.parentNode.insertBefore(sourceEl, targetEl);
            }
            
            // Scroll to the moved element and highlight it
            sourceEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            sourceEl.style.outline = '3px solid var(--primary, #3b82f6)';
            sourceEl.style.outlineOffset = '2px';
            setTimeout(() => {
                sourceEl.style.outline = '';
                sourceEl.style.outlineOffset = '';
            }, 1500);
            
            showToast(<?= json_encode(__admin('preview.elementMoved') ?? 'Element moved successfully') ?>, 'success');
            console.log('[Preview] DOM element moved successfully');
            
        } catch (error) {
            console.error('[Preview] Move error:', error);
            showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
        }
    }
    
    // ==================== Text Edit Handler ====================
    
    async function handleTextEdited(data) {
        console.log('[Preview] Text edited:', data);
        
        const textKey = data.textKey;
        const newValue = data.newValue;
        const oldValue = data.oldValue;
        
        if (!textKey || newValue === undefined) {
            console.error('[Preview] Invalid text edit data:', data);
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid text data', 'error');
            return;
        }
        
        // Get current language from the selector
        const langSelect = document.getElementById('lang-select');
        const lang = langSelect ? langSelect.value : 'en';
        
        try {
            // Call setTranslationKeys API
            // The API expects 'language' and 'translations' (nested object, not JSON string)
            // Format: { language: 'en', translations: { key.path: 'value' } }
            // But the API actually expects nested structure, so we need to build it
            
            // Parse the textKey path into nested structure
            // e.g., "home.hero.title" -> { home: { hero: { title: "value" } } }
            const parts = textKey.split('.');
            let translations = {};
            let current = translations;
            for (let i = 0; i < parts.length - 1; i++) {
                current[parts[i]] = {};
                current = current[parts[i]];
            }
            current[parts[parts.length - 1]] = newValue;
            
            const params = {
                language: lang,
                translations: translations
            };
            
            console.log('[Preview] Saving translation:', params);
            const result = await QuickSiteAdmin.apiRequest('setTranslationKeys', 'POST', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to save text');
            }
            
            showToast(<?= json_encode(__admin('preview.textSaved') ?? 'Text saved') ?>, 'success');
            console.log('[Preview] Translation saved successfully');
            
            // Update the span in iframe to reflect saved state (already has the new text)
            // No action needed - the contenteditable already shows the new text
            
        } catch (error) {
            console.error('[Preview] Text save error:', error);
            showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
            
            // Restore original text in iframe on error
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const textEl = iframeDoc.querySelector(`[data-qs-textkey="${textKey}"]`);
                if (textEl) {
                    textEl.textContent = oldValue;
                }
            } catch (e) {
                console.error('[Preview] Could not restore text:', e);
            }
        }
    }
    
    // Simple toast helper
    function showToast(message, type) {
        if (window.Admin && Admin.toast) {
            Admin.toast(message, type);
        } else if (window.QuickSiteAdmin && QuickSiteAdmin.toast) {
            QuickSiteAdmin.toast(message, type);
        } else {
            console.log('[Toast]', type, message);
        }
    }
    
    // ==================== Style Panel ====================
    
    const stylePanel = document.getElementById('preview-style-panel');
    const stylePanelClose = document.getElementById('preview-style-close');
    const styleSelectorEl = document.getElementById('style-selector');
    const styleApplyBtn = document.getElementById('style-apply');
    const styleResetBtn = document.getElementById('style-reset');
    
    // Make style panel draggable
    const stylePanelHeader = stylePanel?.querySelector('.preview-style-panel__header');
    makePanelDraggable(stylePanel, stylePanelHeader, 'qs_style_panel_pos');
    
    // Store current style context
    let currentStyleContext = null;
    // originalStyles already declared in Phase 8.5 state variables
    
    /**
     * Show the style panel with element info
     */
    function showStylePanel(data) {
        console.log('[Preview] Show style panel:', data);
        
        const element = data.element;
        const style = data.style;
        
        if (!element || !style) {
            console.error('[Preview] Invalid style data');
            return;
        }
        
        // Build selector for display
        let selector = style.tag;
        if (style.id) selector += '#' + style.id;
        if (style.classList && style.classList.length > 0) {
            selector += '.' + style.classList.join('.');
        }
        styleSelectorEl.textContent = selector;
        
        // Store context for later
        currentStyleContext = {
            struct: element.struct,
            nodeId: element.node, // Note: getElementInfo returns 'node' not 'nodeId'
            selector: style.selector,
            tag: style.tag,
            id: style.id,
            classList: style.classList
        };
        
        // Store original values for reset
        originalStyles = { ...style.styles };
        
        // Populate form fields with current values
        populateStyleFields(style.styles);
        
        // Show panel
        stylePanel.classList.add('preview-style-panel--visible');
    }
    
    /**
     * Hide the style panel
     */
    function hideStylePanel() {
        if (!stylePanel) return; // Guard if called before panel exists
        stylePanel.classList.remove('preview-style-panel--visible');
        currentStyleContext = null;
        originalStyles = {};
        
        // Clear selection in iframe
        sendToIframe('clearStyleSelection', {});
    }
    
    /**
     * Populate style input fields with values
     */
    function populateStyleFields(styles) {
        const inputs = stylePanel.querySelectorAll('.preview-style-input');
        
        inputs.forEach(input => {
            const prop = input.dataset.prop;
            if (!prop) return;
            
            const value = styles[prop] || '';
            
            // Handle select elements
            if (input.tagName === 'SELECT') {
                // Try to match option
                const option = input.querySelector(`option[value="${value}"]`);
                if (option) {
                    input.value = value;
                } else {
                    input.value = '';
                }
            } else if (input.classList.contains('preview-style-input--color')) {
                // Color input - format nicely (preserve rgba if needed)
                input.value = formatColorForInput(value);
                // Trigger input event so QSColorPicker updates its UI
                input.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                input.value = value;
            }
            
            // Remove changed indicator
            input.classList.remove('preview-style-input--changed');
        });
    }
    
    /**
     * Convert any color format to hex (for color picker)
     * Returns null if can't convert (e.g., transparent, CSS variables)
     */
    function rgbToHex(color) {
        if (!color) return null;
        
        // Already hex
        if (color.startsWith('#')) {
            // Ensure 6-digit hex for color picker
            if (color.length === 4) {
                // #rgb -> #rrggbb
                return '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
            }
            return color.substring(0, 7); // Strip alpha if #rrggbbaa
        }
        
        // Can't convert these
        if (color === 'transparent' || color.includes('var(')) return null;
        
        // RGB/RGBA format
        const match = color.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
        if (match) {
            const r = parseInt(match[1]).toString(16).padStart(2, '0');
            const g = parseInt(match[2]).toString(16).padStart(2, '0');
            const b = parseInt(match[3]).toString(16).padStart(2, '0');
            return '#' + r + g + b;
        }
        
        // Named colors - use canvas to resolve
        try {
            const ctx = document.createElement('canvas').getContext('2d');
            ctx.fillStyle = color;
            const resolved = ctx.fillStyle;
            if (resolved.startsWith('#')) {
                return resolved;
            }
            // Canvas returns rgb format
            return rgbToHex(resolved);
        } catch (e) {
            return null;
        }
    }
    
    /**
     * Format color for display in text input
     * Preserves rgba if alpha < 1, otherwise returns hex
     */
    function formatColorForInput(color) {
        if (!color) return '';
        
        // Keep rgba format if it has alpha
        const rgbaMatch = color.match(/rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([\d.]+)\s*\)/);
        if (rgbaMatch && parseFloat(rgbaMatch[4]) < 1) {
            return color; // Keep as rgba
        }
        
        // Convert to hex for simpler colors
        const hex = rgbToHex(color);
        return hex || color;
    }
    
    /**
     * Get changed styles from form
     */
    function getChangedStyles() {
        const changed = {};
        const inputs = stylePanel.querySelectorAll('.preview-style-input');
        
        inputs.forEach(input => {
            const prop = input.dataset.prop;
            if (!prop) return;
            
            // Skip color pickers (use their text input sibling)
            if (input.type === 'color') return;
            
            const newValue = input.value.trim();
            const originalValue = originalStyles[prop] || '';
            
            // Check if changed
            if (newValue !== originalValue && newValue !== '') {
                changed[prop] = newValue;
            }
        });
        
        return changed;
    }
    
    /**
     * Apply styles to the server
     */
    async function applyStyles() {
        if (!currentStyleContext) {
            console.error('[Preview] No style context');
            return;
        }
        
        const changedStyles = getChangedStyles();
        
        if (Object.keys(changedStyles).length === 0) {
            showToast(<?= json_encode(__admin('preview.noChanges') ?? 'No changes to apply') ?>, 'info');
            return;
        }
        
        console.log('[Preview] Applying styles:', changedStyles);
        
        // Build a CSS selector for the rule
        // Priority: use classList if available, else use struct/node path
        let selector = '';
        if (currentStyleContext.classList && currentStyleContext.classList.length > 0) {
            // Use first meaningful class
            const mainClass = currentStyleContext.classList.find(c => !c.startsWith('qs-'));
            if (mainClass) {
                selector = '.' + mainClass;
            }
        }
        
        // Fallback to struct-based selector (for unique node targeting)
        if (!selector && currentStyleContext.struct && currentStyleContext.nodeId) {
            selector = `[data-qs-struct="${currentStyleContext.struct}"][data-qs-node="${currentStyleContext.nodeId}"]`;
        }
        
        // Final fallback: use tag name (affects all elements of that type)
        if (!selector && currentStyleContext.tag) {
            selector = currentStyleContext.tag;
            console.log('[Preview] Using tag selector (will affect all ' + selector + ' elements)');
        }
        
        if (!selector) {
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Cannot determine selector', 'error');
            return;
        }
        
        try {
            // Call setStyleRule API
            const result = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                selector: selector,
                styles: changedStyles
            });
            
            if (!result.ok) {
                throw new Error(result.data?.message || 'Failed to save styles');
            }
            
            showToast(<?= json_encode(__admin('preview.stylesSaved') ?? 'Styles saved') ?>, 'success');
            
            // Update original values
            Object.assign(originalStyles, changedStyles);
            
            // Clear changed indicators
            const inputs = stylePanel.querySelectorAll('.preview-style-input--changed');
            inputs.forEach(input => input.classList.remove('preview-style-input--changed'));
            
            // Reload preview to see changes
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Style save error:', error);
            showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
        }
    }
    
    /**
     * Reset styles to original values
     */
    function resetStyles() {
        populateStyleFields(originalStyles);
        showToast(<?= json_encode(__admin('preview.stylesReset') ?? 'Styles reset') ?>, 'info');
    }
    
    // Style panel event listeners
    if (stylePanelClose) {
        stylePanelClose.addEventListener('click', hideStylePanel);
    }
    
    if (styleApplyBtn) {
        styleApplyBtn.addEventListener('click', applyStyles);
    }
    
    if (styleResetBtn) {
        styleResetBtn.addEventListener('click', resetStyles);
    }
    
    // Initialize QSColorPicker for color inputs
    const colorInputs = stylePanel.querySelectorAll('.preview-style-input--color');
    const colorPickers = [];
    colorInputs.forEach(input => {
        const picker = new QSColorPicker(input, {
            showAlpha: true,
            format: 'auto',
            onChange: (value) => {
                input.classList.add('preview-style-input--changed');
                // Trigger live preview
                if (currentStyleContext) {
                    const prop = input.dataset.prop;
                    sendToIframe('applyLiveStyle', {
                        struct: currentStyleContext.struct,
                        nodeId: currentStyleContext.nodeId,
                        property: prop,
                        value: value
                    });
                }
            }
        });
        colorPickers.push(picker);
    });
    
    // Mark changed inputs
    const styleInputs = stylePanel.querySelectorAll('.preview-style-input:not(.preview-style-input--color)');
    styleInputs.forEach(input => {
        input.addEventListener('change', () => {
            input.classList.add('preview-style-input--changed');
        });
    });
    
    // Live preview: apply changes to iframe element in real-time
    stylePanel.querySelectorAll('.preview-style-input:not(.preview-style-input--color)').forEach(input => {
        const applyLivePreview = () => {
            if (!currentStyleContext) return;
            
            const prop = input.dataset.prop;
            if (!prop) return;
            
            const value = input.value.trim();
            
            // Apply to iframe element using sendToIframe
            sendToIframe('applyLiveStyle', {
                struct: currentStyleContext.struct,
                nodeId: currentStyleContext.nodeId,
                property: prop,
                value: value
            });
        };
        
        input.addEventListener('input', applyLivePreview);
    });
    
    // ==================== Event Handlers ====================
    
    iframe.addEventListener('load', function() {
        clearTimeout(loadingTimeout);
        hideLoading();
        // Inject overlay immediately and with a backup timeout
        injectOverlay();
        // Retry injection in case document wasn't fully ready
        setTimeout(injectOverlay, 50);
        setTimeout(injectOverlay, 200);
    });
    
    routeSelect.addEventListener('change', function() {
        navigateTo(this.value, currentComponent);
    });
    
    if (langSelect) {
        langSelect.addEventListener('change', function() {
            navigateTo(routeSelect.value, currentComponent);
        });
    }
    
    if (componentSelect) {
        componentSelect.addEventListener('change', function() {
            currentComponent = this.value;
            navigateTo(routeSelect.value, currentComponent);
        });
    }
    
    reloadBtn.addEventListener('click', reloadPreview);
    
    deviceBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            setDevice(this.dataset.device);
        });
    });
    
    modeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            setMode(this.dataset.mode);
        });
    });
    
    // ==================== Keyboard Shortcuts ====================
    
    document.addEventListener('keydown', function(e) {
        // Ignore if typing in an input/textarea
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
            return;
        }
        
        // Escape - Clear selection and hide info
        if (e.key === 'Escape') {
            // Clear selection if we have one
            if (selectedStruct && selectedNode) {
                hideNodePanel();
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ action: 'clearSelection' }, '*');
                }
            }
            return;
        }
        
        // Delete or Backspace - Delete selected node
        if (e.key === 'Delete' || (e.key === 'Backspace' && e.metaKey)) {
            // Only if we have a selected node
            if (selectedStruct && selectedNode) {
                e.preventDefault();
                deleteSelectedNode();
            }
            return;
        }
    });
    
    if (nodeClose) {
        nodeClose.addEventListener('click', hideNodePanel);
    }
    
    // Delete node function (shared between floating panel and contextual area)
    async function deleteSelectedNode() {
        if (!selectedStruct || !selectedNode) return;
        
        // Confirm deletion
        const confirmMsg = <?= json_encode(__admin('preview.confirmDeleteNode') ?? 'Are you sure you want to delete this element?') ?>;
        if (!confirm(confirmMsg)) return;
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid structure type', 'error');
            return;
        }
        
        // Save struct and node BEFORE hiding panel (which clears them)
        const structToDelete = selectedStruct;
        const nodeToDelete = selectedNode;
        
        showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
        
        try {
            const params = {
                type: structInfo.type,
                nodeId: nodeToDelete
            };
            if (structInfo.name) {
                params.name = structInfo.name;
            }
            
            console.log('[Preview] Deleting node:', params);
            const result = await QuickSiteAdmin.apiRequest('deleteNode', 'DELETE', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to delete node');
            }
            
            // Hide the node panel
            hideNodePanel();
            
            showToast(<?= json_encode(__admin('preview.nodeDeleted') ?? 'Element deleted successfully') ?>, 'success');
            console.log('[Preview] Node deleted successfully');
            
            // Live DOM update - remove node and reindex siblings
            sendToIframe('removeNode', {
                struct: structToDelete,
                nodeId: nodeToDelete
            });
            
        } catch (error) {
            console.error('[Preview] Delete error:', error);
            showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
        }
    }
    
    if (nodeDeleteBtn) {
        nodeDeleteBtn.addEventListener('click', deleteSelectedNode);
    }
    
    // ==================== Contextual Area Event Listeners (Phase 8) ====================
    
    // Toggle collapse/expand
    if (contextualToggle) {
        contextualToggle.addEventListener('click', toggleContextualArea);
    }
    
    // Contextual area action buttons (wire to same handlers as floating panel)
    if (ctxNodeAdd) {
        ctxNodeAdd.addEventListener('click', function() {
            if (!selectedStruct || !selectedNode) {
                showToast(<?= json_encode(__admin('preview.selectNodeFirst') ?? 'Please select an element first') ?>, 'warning');
                return;
            }
            showAddNodeModal();
        });
    }
    
    if (ctxNodeEdit) {
        ctxNodeEdit.addEventListener('click', function() {
            // Directly call the edit modal function
            openEditNodeModal();
        });
    }
    
    if (ctxNodeDelete) {
        ctxNodeDelete.addEventListener('click', deleteSelectedNode);
    }
    
    if (ctxNodeStyle) {
        ctxNodeStyle.addEventListener('click', function() {
            // Store element info for style mode preselection
            const preselect = {
                classes: selectedElementClasses,
                tag: selectedElementTag,
                selector: null
            };
            
            // Determine best selector: first class or tag
            if (selectedElementClasses && selectedElementClasses !== '-') {
                const firstClass = selectedElementClasses.split(' ')[0].trim();
                if (firstClass) {
                    preselect.selector = '.' + firstClass;
                }
            } else if (selectedElementTag && selectedElementTag !== '-') {
                preselect.selector = selectedElementTag.toLowerCase();
            }
            
            // Switch to style mode with preselection data
            setMode('style', preselect);
        });
    }
    
    // ==================== Theme Variables Event Listeners (Phase 8.3) ====================
    
    // Initialize style tabs
    initStyleTabs();
    
    // Initialize selector browser (Phase 8.4)
    initSelectorBrowser();
    
    // Initialize keyframe editor (Phase 9.3)
    initKeyframeEditor();
    
    // Initialize transform editor (Phase 9.3.1 Step 5)
    initTransformEditorHandlers();
    
    // Theme save button
    if (themeSaveBtn) {
        themeSaveBtn.addEventListener('click', saveThemeVariables);
    }
    
    // Theme reset button
    if (themeResetBtn) {
        themeResetBtn.addEventListener('click', resetThemeVariables);
    }
    
    // ==================== Add Node Modal ====================
    
    // Tag classification and mandatory params (mirrors backend addNode.php)
    const TAG_INFO = {
        BLOCK_TAGS: ['div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 
                     'figure', 'figcaption', 'blockquote', 'pre', 'form', 'fieldset',
                     'ul', 'ol', 'table', 'thead', 'tbody', 'tfoot', 'tr'],
        INLINE_TAGS: ['span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 
                      'a', 'button', 'label', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark',
                      'li', 'td', 'th', 'dt', 'dd', 'caption', 'legend',
                      'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address'],
        SELF_CLOSING_TAGS: ['img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col', 
                            'embed', 'source', 'track', 'wbr'],
        // NOTE: alt is NOT in mandatory params - it's auto-generated as translation key
        MANDATORY_PARAMS: {
            'a': ['href'],
            'img': ['src'],  // alt auto-generated
            'input': ['type'],
            'form': ['action'],
            'iframe': ['src'],
            'video': ['src'],
            'audio': ['src'],
            'source': ['src'],
            'label': ['for'],
            'select': ['name'],
            'textarea': ['name'],
            'area': ['href'],  // alt auto-generated
            'embed': ['src'],
            'object': ['data'],
            'track': ['src'],
            'link': ['href', 'rel']
        },
        // Tags that get auto-generated alt key (translation key)
        TAGS_WITH_ALT: ['img', 'area'],
        // Reserved params that are auto-managed and shouldn't be set manually
        RESERVED_PARAMS: ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'],
        // Placeholder hints for mandatory params
        PARAM_PLACEHOLDERS: {
            'href': 'https://example.com or /page',
            'src': '/images/photo.jpg or https://...',
            'type': 'text, email, password, button...',
            'action': '/submit or https://...',
            'for': 'input-id',
            'name': 'field_name',
            'rel': 'stylesheet, icon...',
            'data': 'data source URL'
        }
    };
    
    function getTagCategory(tag) {
        if (TAG_INFO.SELF_CLOSING_TAGS.includes(tag)) return 'self-closing';
        if (TAG_INFO.INLINE_TAGS.includes(tag)) return 'inline';
        if (TAG_INFO.BLOCK_TAGS.includes(tag)) return 'block';
        return 'block';
    }
    
    function tagRequiresTextKey(tag, hasClass) {
        const category = getTagCategory(tag);
        if (category === 'self-closing') return false;
        if (category === 'inline') return true;
        return !hasClass; // Block: required if no class
    }
    
    const nodeAddBtn = document.getElementById('node-add');
    const addNodeModal = document.getElementById('preview-add-node-modal');
    const addNodeModalClose = document.getElementById('add-node-modal-close');
    const addNodeCancel = document.getElementById('add-node-cancel');
    const addNodeConfirm = document.getElementById('add-node-confirm');
    const addNodeBackdrop = addNodeModal?.querySelector('.preview-add-node-modal__backdrop');
    
    // Type radio buttons
    const typeRadios = addNodeModal?.querySelectorAll('input[name="add-node-type"]');
    
    // Fields
    const tagField = document.getElementById('add-node-tag-field');
    const componentField = document.getElementById('add-node-component-field');
    const classField = document.getElementById('add-node-class-field');
    const mandatoryParamsSection = document.getElementById('add-node-mandatory-params');
    const mandatoryParamsContainer = document.getElementById('mandatory-params-container');
    const textKeyInfoSection = document.getElementById('add-node-textkey-info');
    const generatedTextKeyPreview = document.getElementById('generated-textkey-preview');
    const altKeyInfoSection = document.getElementById('add-node-altkey-info');
    const generatedAltKeyPreview = document.getElementById('generated-altkey-preview');
    const customParamsContainer = document.getElementById('custom-params-container');
    const customParamsList = document.getElementById('custom-params-list');
    const expandParamsBtn = document.getElementById('add-node-expand-params');
    const addAnotherParamBtn = document.getElementById('add-another-param');
    
    // Component-related elements
    const componentVarsSection = document.getElementById('add-node-component-vars');
    const componentVarsContainer = document.getElementById('component-vars-container');
    const componentNoVarsInfo = document.getElementById('component-no-vars');
    
    // Input elements
    const tagSelect = document.getElementById('add-node-tag');
    const addNodeComponentSelect = document.getElementById('add-node-component');
    const positionSelect = document.getElementById('add-node-position');
    const classInput = document.getElementById('add-node-class');
    
    let currentNodeType = 'tag'; // 'tag' or 'component'
    let componentsCache = null; // Cache loaded components
    let selectedComponentData = null; // Currently selected component details
    let customParamsCount = 0;
    
    // Generate a preview of what the textKey will be
    function updateTextKeyPreview() {
        if (!textKeyInfoSection || !generatedTextKeyPreview) return;
        
        const tag = tagSelect?.value || 'div';
        const hasClass = (classInput?.value?.trim() || '').length > 0;
        const needsTextKey = tagRequiresTextKey(tag, hasClass);
        
        if (needsTextKey) {
            // Build preview based on current structure
            const structInfo = parseStruct(selectedStruct || '');
            let prefix = structInfo?.name || structInfo?.type || 'page';
            generatedTextKeyPreview.textContent = `${prefix}.item{N}`;
            textKeyInfoSection.style.display = 'flex';
        } else {
            textKeyInfoSection.style.display = 'none';
        }
    }
    
    // Show/hide alt key info based on tag type
    function updateAltKeyPreview() {
        if (!altKeyInfoSection || !generatedAltKeyPreview) return;
        
        const tag = tagSelect?.value || 'div';
        const needsAlt = TAG_INFO.TAGS_WITH_ALT.includes(tag);
        
        if (needsAlt) {
            const structInfo = parseStruct(selectedStruct || '');
            let prefix = structInfo?.name || structInfo?.type || 'page';
            generatedAltKeyPreview.textContent = `${prefix}.item{N}.alt`;
            altKeyInfoSection.style.display = 'flex';
        } else {
            altKeyInfoSection.style.display = 'none';
        }
    }
    
    // Build mandatory params fields for selected tag
    function updateMandatoryParams() {
        if (!mandatoryParamsSection || !mandatoryParamsContainer) return;
        
        const tag = tagSelect?.value || 'div';
        const requiredParams = TAG_INFO.MANDATORY_PARAMS[tag] || [];
        
        // Always clear the container first to remove stale inputs
        mandatoryParamsContainer.innerHTML = '';
        
        if (requiredParams.length === 0) {
            mandatoryParamsSection.style.display = 'none';
            return;
        }
        
        mandatoryParamsSection.style.display = 'block';
        
        requiredParams.forEach(param => {
            const row = document.createElement('div');
            row.className = 'preview-add-node-modal__param-row';
            row.innerHTML = `
                <label class="preview-add-node-modal__param-label">${param}:</label>
                <input type="text" 
                       class="admin-input preview-add-node-modal__param-input" 
                       data-param="${param}" 
                       data-mandatory="true"
                       placeholder="${TAG_INFO.PARAM_PLACEHOLDERS[param] || ''}"
                       required>
            `;
            mandatoryParamsContainer.appendChild(row);
        });
    }
    
    // Add a custom param row
    function addCustomParamRow(key = '', value = '') {
        customParamsCount++;
        const row = document.createElement('div');
        row.className = 'preview-add-node-modal__param-row preview-add-node-modal__param-row--custom';
        row.dataset.customId = customParamsCount;
        row.innerHTML = `
            <input type="text" 
                   class="admin-input preview-add-node-modal__param-key" 
                   placeholder="<?= __admin('preview.paramName') ?? 'param name' ?>"
                   value="${key}">
            <input type="text" 
                   class="admin-input preview-add-node-modal__param-value" 
                   placeholder="<?= __admin('preview.paramValue') ?? 'value' ?>"
                   value="${value}">
            <button type="button" class="preview-add-node-modal__remove-param" data-remove="${customParamsCount}" title="<?= __admin('common.remove') ?? 'Remove' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        `;
        customParamsList.appendChild(row);
        
        // Add remove handler
        row.querySelector('.preview-add-node-modal__remove-param').addEventListener('click', function() {
            row.remove();
        });
    }
    
    // Collect all params from the form
    function collectParams() {
        const params = {};
        
        // CSS class
        const classValue = classInput?.value?.trim();
        if (classValue) {
            params.class = classValue;
        }
        
        // Mandatory params
        mandatoryParamsContainer?.querySelectorAll('input[data-mandatory="true"]').forEach(input => {
            const value = input.value.trim();
            if (value) {
                params[input.dataset.param] = value;
            }
        });
        
        // Custom params (filter out reserved params like alt)
        customParamsList?.querySelectorAll('.preview-add-node-modal__param-row--custom').forEach(row => {
            const key = row.querySelector('.preview-add-node-modal__param-key')?.value?.trim();
            const value = row.querySelector('.preview-add-node-modal__param-value')?.value?.trim();
            if (key && value && !TAG_INFO.RESERVED_PARAMS.includes(key.toLowerCase())) {
                params[key] = value;
            }
        });
        
        return params;
    }
    
    // Show/hide modal
    function showAddNodeModal() {
        if (addNodeModal) {
            addNodeModal.style.display = 'flex';
            // Reset form
            currentNodeType = 'tag';
            typeRadios?.forEach(r => r.checked = (r.value === 'tag'));
            if (tagSelect) tagSelect.value = 'div';
            if (positionSelect) positionSelect.value = 'after';
            if (classInput) classInput.value = '';
            customParamsList.innerHTML = '';
            customParamsContainer.style.display = 'none';
            expandParamsBtn.querySelector('svg').style.transform = '';
            // Reset component state
            if (addNodeComponentSelect) addNodeComponentSelect.value = '';
            hideComponentVariables();
            // Update UI
            updateNodeTypeUI();
            updateMandatoryParams();
            updateTextKeyPreview();
            updateAltKeyPreview();
        }
    }
    
    function hideAddNodeModal() {
        if (addNodeModal) {
            addNodeModal.style.display = 'none';
        }
    }
    
    // Update UI based on selected node type
    function updateNodeTypeUI() {
        const isTag = currentNodeType === 'tag';
        const isComponent = currentNodeType === 'component';
        
        // Show/hide relevant fields
        if (tagField) tagField.style.display = isTag ? 'block' : 'none';
        if (componentField) componentField.style.display = isComponent ? 'block' : 'none';
        if (classField) classField.style.display = isTag ? 'block' : 'none';
        if (mandatoryParamsSection) mandatoryParamsSection.style.display = (isTag && (TAG_INFO.MANDATORY_PARAMS[tagSelect?.value] || []).length > 0) ? 'block' : 'none';
        if (textKeyInfoSection) textKeyInfoSection.style.display = isTag ? textKeyInfoSection.style.display : 'none';
        if (altKeyInfoSection) altKeyInfoSection.style.display = isTag ? altKeyInfoSection.style.display : 'none';
        if (customParamsContainer?.parentElement) customParamsContainer.parentElement.style.display = isTag ? 'block' : 'none';
        
        // Component-specific sections
        if (componentVarsSection) componentVarsSection.style.display = (isComponent && selectedComponentData) ? 'block' : 'none';
        
        // Hide component vars if switching to tag
        if (isTag) {
            hideComponentVariables();
        }
    }
    
    // Type radio change
    typeRadios?.forEach(radio => {
        radio.addEventListener('change', function() {
            currentNodeType = this.value;
            updateNodeTypeUI();
            if (currentNodeType === 'component') {
                loadComponentsList();
            } else {
                // Switching to tag - update the tag-related previews
                updateTextKeyPreview();
                updateAltKeyPreview();
            }
        });
    });
    
    // Tag select change - update mandatory params and textKey preview
    // Also reset custom params to avoid carrying over params from different tags
    tagSelect?.addEventListener('change', function() {
        updateMandatoryParams();
        updateTextKeyPreview();
        updateAltKeyPreview();
        // Clear custom params when tag changes to prevent href on div, etc.
        customParamsList.innerHTML = '';
        customParamsContainer.style.display = 'none';
        expandParamsBtn.querySelector('svg').style.transform = '';
    });
    
    // Class input change - update textKey preview
    classInput?.addEventListener('input', function() {
        updateTextKeyPreview();
    });
    
    // Expand/collapse custom params
    expandParamsBtn?.addEventListener('click', function() {
        const isHidden = customParamsContainer.style.display === 'none';
        customParamsContainer.style.display = isHidden ? 'block' : 'none';
        this.querySelector('svg').style.transform = isHidden ? 'rotate(45deg)' : '';
        if (isHidden && customParamsList.children.length === 0) {
            addCustomParamRow();
        }
    });
    
    // Add another param button
    addAnotherParamBtn?.addEventListener('click', function() {
        addCustomParamRow();
    });
    
    // Load components list from server
    async function loadComponentsList() {
        if (!addNodeComponentSelect) return;
        
        // Use cache if available
        if (componentsCache) {
            populateComponentSelect(componentsCache);
            return;
        }
        
        addNodeComponentSelect.innerHTML = '<option value=""><?= __admin('common.loading') ?>...</option>';
        
        try {
            const result = await QuickSiteAdmin.apiRequest('listComponents', 'GET');
            if (result.ok && result.data?.data?.components) {
                componentsCache = result.data.data.components;
                populateComponentSelect(componentsCache);
            } else {
                addNodeComponentSelect.innerHTML = '<option value=""><?= __admin('common.error') ?></option>';
            }
        } catch (error) {
            console.error('Failed to load components:', error);
            addNodeComponentSelect.innerHTML = '<option value=""><?= __admin('common.error') ?></option>';
        }
    }
    
    // Populate the component select dropdown
    function populateComponentSelect(components) {
        addNodeComponentSelect.innerHTML = '<option value=""><?= __admin('preview.selectComponentPlaceholder') ?? '-- Select a component --' ?></option>';
        
        if (components.length === 0) {
            addNodeComponentSelect.innerHTML = '<option value=""><?= __admin('preview.noComponents') ?? 'No components available' ?></option>';
            return;
        }
        
        components.forEach(comp => {
            if (!comp.valid) return; // Skip invalid components
            const opt = document.createElement('option');
            opt.value = comp.name;
            opt.textContent = comp.name;
            addNodeComponentSelect.appendChild(opt);
        });
    }
    
    // Handle component selection change - show variables
    function onComponentSelect() {
        const componentName = addNodeComponentSelect?.value;
        selectedComponentData = null;
        
        if (!componentName || !componentsCache) {
            hideComponentVariables();
            return;
        }
        
        // Find the component in cache
        const comp = componentsCache.find(c => c.name === componentName);
        if (!comp || !comp.valid) {
            hideComponentVariables();
            return;
        }
        
        selectedComponentData = comp;
        showComponentVariables(comp);
    }
    
    // Show component variables section
    function showComponentVariables(comp) {
        if (!componentVarsSection || !componentVarsContainer) return;
        
        const variables = comp.variables || [];
        componentVarsContainer.innerHTML = '';
        
        if (variables.length === 0) {
            componentVarsSection.style.display = 'block';
            componentNoVarsInfo.style.display = 'flex';
            return;
        }
        
        componentNoVarsInfo.style.display = 'none';
        
        // Get structure info for textKey generation preview
        const structInfo = parseStruct(selectedStruct || '');
        const prefix = structInfo?.name || structInfo?.type || 'page';
        
        variables.forEach(varInfo => {
            const row = document.createElement('div');
            row.className = 'preview-add-node-modal__param-row';
            row.dataset.varName = varInfo.name;
            row.dataset.varType = varInfo.type;
            
            if (varInfo.type === 'textKey') {
                // textKey variables: show auto-generated key (read-only)
                row.innerHTML = `
                    <label class="preview-add-node-modal__param-label">${varInfo.name}:</label>
                    <div class="preview-add-node-modal__param-readonly" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--admin-bg-secondary, #f5f5f5); border-radius: 4px; flex: 1;">
                        <code style="font-family: monospace; color: var(--admin-primary, #3b82f6);">${prefix}.${comp.name}{N}.${varInfo.name}</code>
                        <small style="color: var(--admin-text-muted, #6b7280); font-style: italic;"><?= __admin('preview.autoGenerated') ?? '(auto-generated)' ?></small>
                    </div>
                `;
            } else {
                // param variables: show input field
                row.innerHTML = `
                    <label class="preview-add-node-modal__param-label">${varInfo.name}:</label>
                    <input type="text" 
                           class="admin-input preview-add-node-modal__param-input" 
                           data-var-name="${varInfo.name}"
                           placeholder="<?= __admin('preview.enterValue') ?? 'Enter value' ?>">
                `;
            }
            
            componentVarsContainer.appendChild(row);
        });
        
        componentVarsSection.style.display = 'block';
    }
    
    // Hide component variables section
    function hideComponentVariables() {
        if (componentVarsSection) {
            componentVarsSection.style.display = 'none';
        }
        if (componentVarsContainer) {
            componentVarsContainer.innerHTML = '';
        }
        selectedComponentData = null;
    }
    
    // Collect component variable values for API
    function collectComponentData() {
        const data = {};
        componentVarsContainer?.querySelectorAll('input[data-var-name]').forEach(input => {
            const varName = input.dataset.varName;
            const value = input.value.trim();
            if (value) {
                data[varName] = value;
            }
        });
        return data;
    }
    
    // Component select change handler
    addNodeComponentSelect?.addEventListener('change', onComponentSelect);
    
    // Open modal
    if (nodeAddBtn) {
        nodeAddBtn.addEventListener('click', function() {
            if (!selectedStruct || !selectedNode) {
                showToast(<?= json_encode(__admin('preview.selectNodeFirst') ?? 'Please select an element first') ?>, 'warning');
                return;
            }
            showAddNodeModal();
        });
    }
    
    // Close modal handlers
    addNodeModalClose?.addEventListener('click', hideAddNodeModal);
    addNodeCancel?.addEventListener('click', hideAddNodeModal);
    addNodeBackdrop?.addEventListener('click', hideAddNodeModal);
    
    // Escape key closes modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && addNodeModal && addNodeModal.style.display === 'flex') {
            hideAddNodeModal();
        }
    });
    
    // Confirm add node
    if (addNodeConfirm) {
        addNodeConfirm.addEventListener('click', async function() {
            if (!selectedStruct || !selectedNode) return;
            
            // Parse struct to get type and name
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid structure type', 'error');
                return;
            }
            
            // Different handling for tag vs component
            if (currentNodeType === 'component') {
                // Component addition
                const componentName = addNodeComponentSelect?.value;
                if (!componentName) {
                    showToast(<?= json_encode(__admin('preview.selectComponent') ?? 'Please select a component') ?>, 'warning');
                    return;
                }
                
                const apiParams = {
                    type: structInfo.type,
                    targetNodeId: selectedNode,
                    position: positionSelect?.value || 'after',
                    component: componentName
                };
                
                if (structInfo.name) {
                    apiParams.name = structInfo.name;
                }
                
                // Collect param-type variable values
                const componentData = collectComponentData();
                if (Object.keys(componentData).length > 0) {
                    apiParams.data = componentData;
                }
                
                hideAddNodeModal();
                showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
                
                try {
                    console.log('[Preview] Adding component:', apiParams);
                    const result = await QuickSiteAdmin.apiRequest('addComponentToNode', 'POST', apiParams);
                    
                    if (!result.ok) {
                        throw new Error(result.data?.message || result.data?.data?.message || 'Failed to add component');
                    }
                    
                    const data = result.data?.data || {};
                    let successMsg = <?= json_encode(__admin('preview.componentAdded') ?? 'Component added') ?> + `: ${componentName}`;
                    if (data.nodeId) {
                        successMsg += ` (${data.nodeId})`;
                    }
                    
                    showToast(successMsg, 'success');
                    console.log('[Preview] Component added:', data);
                    
                    // Save values before hiding panel (hideNodePanel clears selectedNode)
                    const targetNodeForInsert = selectedNode;
                    const structNameForInsert = structInfo.type === 'page' ? `page-${structInfo.name}` : structInfo.type;
                    
                    // Hide the node panel since selection changed
                    hideNodePanel();
                    
                    // Try live DOM update if HTML was returned
                    if (data.html && targetNodeForInsert) {
                        sendToIframe('insertNode', {
                            struct: structNameForInsert,
                            targetNode: targetNodeForInsert,
                            position: apiParams.position,
                            html: data.html,
                            newNodeId: data.nodeId
                        });
                    } else {
                        // Fallback to full reload if no HTML returned
                        reloadPreview();
                    }
                    
                } catch (error) {
                    console.error('[Preview] Add component error:', error);
                    showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
                }
                
            } else {
                // HTML Tag addition
                const tag = tagSelect?.value || 'div';
                
                // Validate mandatory params
                const requiredParams = TAG_INFO.MANDATORY_PARAMS[tag] || [];
                const params = collectParams();
                
                for (const param of requiredParams) {
                    if (!params[param]) {
                        showToast(<?= json_encode(__admin('preview.paramRequired') ?? 'Required parameter') ?> + `: ${param}`, 'warning');
                        return;
                    }
                }
                
                // Build the API params
                const apiParams = {
                    type: structInfo.type,
                    targetNodeId: selectedNode,
                    position: positionSelect?.value || 'after',
                    tag: tag
                };
                
                if (structInfo.name) {
                    apiParams.name = structInfo.name;
                }
                
                if (Object.keys(params).length > 0) {
                    apiParams.params = params;
                }
                
                hideAddNodeModal();
                showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
                
                try {
                    console.log('[Preview] Adding node:', apiParams);
                    const result = await QuickSiteAdmin.apiRequest('addNode', 'POST', apiParams);
                    
                    if (!result.ok) {
                        throw new Error(result.data?.message || result.data?.data?.message || 'Failed to add node');
                    }
                    
                    const data = result.data?.data || {};
                    let successMsg = <?= json_encode(__admin('preview.elementAdded') ?? 'Element added successfully') ?>;
                    if (data.newNodeId) {
                        successMsg += ` (${data.newNodeId})`;
                    }
                    if (data.textKeyGenerated && data.textKey) {
                        successMsg += ` | textKey: ${data.textKey}`;
                    }
                    
                    showToast(successMsg, 'success');
                    console.log('[Preview] Node added:', data);
                    
                    // Save target info BEFORE hiding the panel (which clears selectedNode)
                    const targetNodeId = selectedNode;
                    const structName = structInfo.type === 'page' ? `page-${structInfo.name}` : structInfo.type;
                    const position = positionSelect?.value || 'after';
                    
                    // Hide the node panel since selection changed
                    hideNodePanel();
                    
                    // Step 11: Live DOM update - insert the rendered HTML without reloading
                    if (data.html && targetNodeId) {
                        sendToIframe('insertNode', {
                            struct: structName,
                            targetNode: targetNodeId,
                            position: position,
                            html: data.html,
                            newNodeId: data.newNodeId
                        });
                        console.log('[Preview] Live DOM update for addNode:', data.newNodeId);
                    } else {
                        // Fallback: reload if no HTML or target
                        console.log('[Preview] No HTML returned or no target, reloading preview');
                        reloadPreview();
                    }
                    
                } catch (error) {
                    console.error('[Preview] Add node error:', error);
                    showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
                }
            }
        });
    }
    
    // ==================== Edit Node Modal ====================
    
    const nodeEditElementBtn = document.getElementById('node-edit-element');
    const editNodeModal = document.getElementById('preview-edit-node-modal');
    const editNodeModalClose = document.getElementById('edit-node-modal-close');
    const editNodeCancel = document.getElementById('edit-node-cancel');
    const editNodeConfirm = document.getElementById('edit-node-confirm');
    const editNodeBackdrop = editNodeModal?.querySelector('.preview-add-node-modal__backdrop');
    
    // Edit modal elements
    const editNodeIdDisplay = document.getElementById('edit-node-id');
    const editNodeTagSelect = document.getElementById('edit-node-tag');
    const editNodeTagContent = document.getElementById('edit-node-tag-content');
    const editNodeComponentWarning = document.getElementById('edit-node-component-warning');
    const editNodeTextOnlyWarning = document.getElementById('edit-node-textonly-warning');
    const editParamsContainer = document.getElementById('edit-params-container');
    const editAddParamBtn = document.getElementById('edit-add-param');
    const editNodeTextKeyInfo = document.getElementById('edit-node-textkey-info');
    const editNodeTextKeyValue = document.getElementById('edit-node-textkey-value');
    const editNodeAltKeyInfo = document.getElementById('edit-node-altkey-info');
    const editNodeAltKeyValue = document.getElementById('edit-node-altkey-value');
    
    // Store original node data for comparison
    let editNodeOriginalData = null;
    
    // Create a param row for editing
    function createEditParamRow(key, value, isMandatory = false, isClass = false) {
        const row = document.createElement('div');
        row.className = 'preview-add-node-modal__param-row preview-add-node-modal__param-row--edit';
        row.dataset.paramKey = key;
        row.dataset.mandatory = isMandatory ? 'true' : 'false';
        row.dataset.isClass = isClass ? 'true' : 'false';
        
        const label = isClass ? 'class' : key;
        const placeholder = TAG_INFO.PARAM_PLACEHOLDERS[key] || '';
        const canRemove = !isMandatory && !isClass;
        
        row.innerHTML = `
            <label class="preview-add-node-modal__param-label">${label}${isMandatory ? ' *' : ''}:</label>
            <input type="text" 
                   class="admin-input preview-add-node-modal__param-input" 
                   data-param="${key}"
                   value="${value || ''}"
                   placeholder="${placeholder}"
                   ${isMandatory ? 'required' : ''}>
            ${canRemove ? `
            <button type="button" class="preview-add-node-modal__remove-param" title="<?= __admin('common.remove') ?? 'Remove' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>` : '<span style="width: 30px;"></span>'}
        `;
        
        // Add remove handler
        const removeBtn = row.querySelector('.preview-add-node-modal__remove-param');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                row.remove();
            });
        }
        
        return row;
    }
    
    // Create a new param row (for adding new params)
    function createNewEditParamRow() {
        const row = document.createElement('div');
        row.className = 'preview-add-node-modal__param-row preview-add-node-modal__param-row--edit preview-add-node-modal__param-row--new';
        
        row.innerHTML = `
            <input type="text" 
                   class="admin-input preview-add-node-modal__param-key" 
                   placeholder="<?= __admin('preview.paramName') ?? 'param name' ?>">
            <input type="text" 
                   class="admin-input preview-add-node-modal__param-value" 
                   placeholder="<?= __admin('preview.paramValue') ?? 'value' ?>">
            <button type="button" class="preview-add-node-modal__remove-param" title="<?= __admin('common.remove') ?? 'Remove' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        `;
        
        // Add remove handler
        row.querySelector('.preview-add-node-modal__remove-param').addEventListener('click', function() {
            row.remove();
        });
        
        return row;
    }
    
    // Populate edit form with node data
    function populateEditForm(nodeData) {
        editNodeOriginalData = nodeData;
        
        // Reset form
        editParamsContainer.innerHTML = '';
        editNodeTagContent.style.display = 'block';
        editNodeComponentWarning.style.display = 'none';
        editNodeTextOnlyWarning.style.display = 'none';
        editNodeConfirm.disabled = false;
        
        // Update node ID display
        editNodeIdDisplay.textContent = nodeData.nodeId || '-';
        
        // Check if this is a component node
        if (nodeData.isComponent) {
            editNodeTagContent.style.display = 'none';
            editNodeComponentWarning.style.display = 'flex';
            editNodeTextKeyInfo.style.display = 'none';
            editNodeAltKeyInfo.style.display = 'none';
            editNodeConfirm.disabled = true;
            return;
        }
        
        // Check if this is a text-only node
        if (nodeData.isTextOnly) {
            editNodeTagContent.style.display = 'none';
            editNodeTextOnlyWarning.style.display = 'flex';
            editNodeTextKeyInfo.style.display = 'none';
            editNodeAltKeyInfo.style.display = 'none';
            editNodeConfirm.disabled = true;
            return;
        }
        
        // Set current tag
        if (editNodeTagSelect && nodeData.tag) {
            editNodeTagSelect.value = nodeData.tag;
        }
        
        // Build params list
        const tag = nodeData.tag || 'div';
        const mandatoryParams = TAG_INFO.MANDATORY_PARAMS[tag] || [];
        const existingParams = nodeData.params || {};
        
        // Add CSS class first (if exists)
        if (existingParams.class) {
            editParamsContainer.appendChild(createEditParamRow('class', existingParams.class, false, true));
        }
        
        // Add mandatory params (required)
        mandatoryParams.forEach(param => {
            editParamsContainer.appendChild(createEditParamRow(param, existingParams[param] || '', true));
        });
        
        // Add other existing params (except reserved ones like alt)
        Object.keys(existingParams).forEach(param => {
            if (param !== 'class' && !mandatoryParams.includes(param) && !TAG_INFO.RESERVED_PARAMS.includes(param.toLowerCase())) {
                editParamsContainer.appendChild(createEditParamRow(param, existingParams[param], false));
            }
        });
        
        // Show textKey if present
        if (nodeData.textKey) {
            editNodeTextKeyInfo.style.display = 'flex';
            editNodeTextKeyValue.textContent = nodeData.textKey;
        } else {
            editNodeTextKeyInfo.style.display = 'none';
        }
        
        // Show altKey info for img/area tags
        if (TAG_INFO.TAGS_WITH_ALT.includes(tag)) {
            editNodeAltKeyInfo.style.display = 'flex';
            if (nodeData.hasAlt) {
                // Has existing alt - the key is stored in JSON but we don't know it from DOM
                // Show current translated value with note it's managed as translation
                editNodeAltKeyValue.textContent = `"${nodeData.params.alt}" (translation key in JSON)`;
            } else {
                // No alt yet - will be auto-generated
                const structInfo = parseStruct(selectedStruct || '');
                const prefix = structInfo?.name || structInfo?.type || 'page';
                editNodeAltKeyValue.textContent = `${prefix}.item{N}.alt (will be auto-generated)`;
            }
        } else {
            editNodeAltKeyInfo.style.display = 'none';
        }
    }
    
    // Update params when tag changes
    function updateEditParamsForTag() {
        const newTag = editNodeTagSelect?.value || 'div';
        const currentTag = editNodeOriginalData?.tag || 'div';
        
        if (newTag === currentTag) return;
        
        const newMandatoryParams = TAG_INFO.MANDATORY_PARAMS[newTag] || [];
        const oldMandatoryParams = TAG_INFO.MANDATORY_PARAMS[currentTag] || [];
        
        // Collect current values
        const currentValues = {};
        editParamsContainer.querySelectorAll('.preview-add-node-modal__param-row--edit').forEach(row => {
            const input = row.querySelector('input[data-param]');
            if (input && input.dataset.param) {
                currentValues[input.dataset.param] = input.value;
            }
            // Also handle new param rows
            const keyInput = row.querySelector('.preview-add-node-modal__param-key');
            const valueInput = row.querySelector('.preview-add-node-modal__param-value');
            if (keyInput && valueInput && keyInput.value) {
                currentValues[keyInput.value] = valueInput.value;
            }
        });
        
        // Clear and rebuild
        editParamsContainer.innerHTML = '';
        
        // Add class if was present
        if (currentValues.class) {
            editParamsContainer.appendChild(createEditParamRow('class', currentValues.class, false, true));
        }
        
        // Add new mandatory params
        newMandatoryParams.forEach(param => {
            editParamsContainer.appendChild(createEditParamRow(param, currentValues[param] || '', true));
        });
        
        // Add other params that aren't new mandatory params
        Object.keys(currentValues).forEach(param => {
            if (param !== 'class' && !newMandatoryParams.includes(param)) {
                editParamsContainer.appendChild(createEditParamRow(param, currentValues[param], false));
            }
        });
    }
    
    // Collect edit params for API
    function collectEditParams() {
        const addParams = {};
        const removeParams = [];
        
        const originalParams = editNodeOriginalData?.params || {};
        const seenParams = new Set();
        
        // Process existing param rows
        editParamsContainer.querySelectorAll('.preview-add-node-modal__param-row--edit').forEach(row => {
            // Handle named param rows
            const input = row.querySelector('input[data-param]');
            if (input && input.dataset.param) {
                const key = input.dataset.param;
                const value = input.value.trim();
                seenParams.add(key);
                
                if (value !== (originalParams[key] || '')) {
                    addParams[key] = value;
                }
            }
            
            // Handle new param rows (filter out reserved params)
            const keyInput = row.querySelector('.preview-add-node-modal__param-key');
            const valueInput = row.querySelector('.preview-add-node-modal__param-value');
            if (keyInput && valueInput) {
                const key = keyInput.value.trim();
                const value = valueInput.value.trim();
                if (key && value && !TAG_INFO.RESERVED_PARAMS.includes(key.toLowerCase())) {
                    seenParams.add(key);
                    addParams[key] = value;
                }
            }
        });
        
        // Find removed params
        Object.keys(originalParams).forEach(key => {
            if (!seenParams.has(key)) {
                removeParams.push(key);
            }
        });
        
        return { addParams, removeParams };
    }
    
    // Show edit modal
    function showEditNodeModal() {
        if (!editNodeModal) return;
        editNodeModal.style.display = 'flex';
    }
    
    function hideEditNodeModal() {
        if (editNodeModal) {
            editNodeModal.style.display = 'none';
            editNodeOriginalData = null;
        }
    }
    
    // Load node data and open edit modal
    async function openEditNodeModal() {
        if (!selectedStruct || !selectedNode) {
            showToast(<?= json_encode(__admin('preview.selectNodeFirst') ?? 'Please select an element first') ?>, 'warning');
            return;
        }
        
        // Get node data from iframe
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        const selector = `[data-qs-struct="${selectedStruct}"][data-qs-node="${selectedNode}"]`;
        const element = iframeDoc.querySelector(selector);
        
        if (!element) {
            showToast(<?= json_encode(__admin('common.error')) ?> + ': Element not found', 'error');
            return;
        }
        
        // Extract node data from DOM
        const nodeData = {
            nodeId: selectedNode,
            struct: selectedStruct,
            tag: element.tagName.toLowerCase(),
            isComponent: !!element.dataset.qsComponent,
            isTextOnly: false, // Can't select text-only nodes directly
            params: {},
            textKey: null,
            altKey: null
        };
        
        // Get all attributes except data-qs-*
        for (const attr of element.attributes) {
            if (!attr.name.startsWith('data-qs-')) {
                let value = attr.value;
                // Filter out qs-selected from class attribute (added dynamically when selected)
                if (attr.name === 'class') {
                    value = value.split(' ').filter(c => c !== 'qs-selected').join(' ').trim();
                }
                if (value) { // Only add if there's a value after filtering
                    nodeData.params[attr.name] = value;
                }
            }
        }
        
        // Try to find textKey from children
        // Look for text content that might indicate a textKey
        const textContent = element.dataset.qsText;
        if (textContent) {
            nodeData.textKey = textContent;
        }
        
        // For img/area, check if alt looks like a translation key (has dots pattern)
        // This is a heuristic - the alt in DOM is already translated
        // We can't definitively know the original key from the DOM alone
        // So we note that if they change alt, a new key will be generated
        if (TAG_INFO.TAGS_WITH_ALT.includes(nodeData.tag) && nodeData.params.alt) {
            // Mark that this tag has alt, but we don't know the key from DOM
            nodeData.hasAlt = true;
        }
        
        populateEditForm(nodeData);
        showEditNodeModal();
    }
    
    // Edit element button click
    if (nodeEditElementBtn) {
        nodeEditElementBtn.addEventListener('click', openEditNodeModal);
    }
    
    // Tag change in edit modal
    editNodeTagSelect?.addEventListener('change', function() {
        updateEditParamsForTag();
        updateEditAltKeyInfo();
    });
    
    // Update alt key info for edit modal based on selected tag
    function updateEditAltKeyInfo() {
        if (!editNodeAltKeyInfo) return;
        
        const newTag = editNodeTagSelect?.value || 'div';
        const needsAlt = TAG_INFO.TAGS_WITH_ALT.includes(newTag);
        
        if (needsAlt) {
            editNodeAltKeyInfo.style.display = 'flex';
            // Check if original data has altKey
            if (editNodeOriginalData?.altKey) {
                editNodeAltKeyValue.textContent = editNodeOriginalData.altKey;
            } else {
                const structInfo = parseStruct(selectedStruct || '');
                const prefix = structInfo?.name || structInfo?.type || 'page';
                editNodeAltKeyValue.textContent = `${prefix}.item{N}.alt (auto)`;
            }
        } else {
            editNodeAltKeyInfo.style.display = 'none';
        }
    }
    
    // Add new param in edit modal
    editAddParamBtn?.addEventListener('click', function() {
        editParamsContainer.appendChild(createNewEditParamRow());
    });
    
    // Close edit modal handlers
    editNodeModalClose?.addEventListener('click', hideEditNodeModal);
    editNodeCancel?.addEventListener('click', hideEditNodeModal);
    editNodeBackdrop?.addEventListener('click', hideEditNodeModal);
    
    // Escape key closes edit modal too
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && editNodeModal && editNodeModal.style.display === 'flex') {
            hideEditNodeModal();
        }
    });
    
    // Confirm edit node
    if (editNodeConfirm) {
        editNodeConfirm.addEventListener('click', async function() {
            if (!selectedStruct || !selectedNode || !editNodeOriginalData) return;
            
            // Parse struct to get type and name
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid structure type', 'error');
                return;
            }
            
            const newTag = editNodeTagSelect?.value || editNodeOriginalData.tag;
            const { addParams, removeParams } = collectEditParams();
            
            // Check if anything changed
            const tagChanged = newTag !== editNodeOriginalData.tag;
            const hasParamChanges = Object.keys(addParams).length > 0 || removeParams.length > 0;
            
            if (!tagChanged && !hasParamChanges) {
                hideEditNodeModal();
                showToast(<?= json_encode(__admin('preview.noChanges') ?? 'No changes to save') ?>, 'info');
                return;
            }
            
            // Validate mandatory params for new tag
            const requiredParams = TAG_INFO.MANDATORY_PARAMS[newTag] || [];
            for (const param of requiredParams) {
                const paramRow = editParamsContainer.querySelector(`input[data-param="${param}"]`);
                if (!paramRow || !paramRow.value.trim()) {
                    showToast(<?= json_encode(__admin('preview.paramRequired') ?? 'Required parameter') ?> + `: ${param}`, 'warning');
                    return;
                }
            }
            
            // Build API params
            const apiParams = {
                type: structInfo.type,
                nodeId: selectedNode
            };
            
            if (structInfo.name) {
                apiParams.name = structInfo.name;
            }
            
            if (tagChanged) {
                apiParams.tag = newTag;
            }
            
            if (Object.keys(addParams).length > 0) {
                apiParams.addParams = addParams;
            }
            
            if (removeParams.length > 0) {
                apiParams.removeParams = removeParams;
            }
            
            hideEditNodeModal();
            showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
            
            try {
                console.log('[Preview] Editing node:', apiParams);
                const result = await QuickSiteAdmin.apiRequest('editNode', 'POST', apiParams);
                
                if (!result.ok) {
                    throw new Error(result.data?.message || result.data?.data?.message || 'Failed to edit node');
                }
                
                const data = result.data?.data || {};
                let successMsg = <?= json_encode(__admin('preview.elementUpdated') ?? 'Element updated successfully') ?>;
                
                // Show textKey generation info if applicable
                if (data.textKeyGenerated && data.textKey) {
                    successMsg += ` | textKey: ${data.textKey}`;
                }
                
                showToast(successMsg, 'success');
                console.log('[Preview] Node edited:', data);
                
                // Reload preview to show the changes
                reloadPreview();
                
            } catch (error) {
                console.error('[Preview] Edit node error:', error);
                showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
            }
        });
    }
    
    // ==================== Edit Component Modal ====================
    
    const editComponentModal = document.getElementById('preview-edit-component-modal');
    const editComponentModalClose = document.getElementById('edit-component-modal-close');
    const editComponentCancel = document.getElementById('edit-component-cancel');
    const editComponentConfirm = document.getElementById('edit-component-confirm');
    const editComponentBackdrop = editComponentModal?.querySelector('.preview-add-node-modal__backdrop');
    
    // Edit component modal elements
    const editComponentName = document.getElementById('edit-component-name');
    const editComponentNodeId = document.getElementById('edit-component-node-id');
    const editComponentVarsSection = document.getElementById('edit-component-vars-section');
    const editComponentVarsContainer = document.getElementById('edit-component-vars-container');
    const editComponentTextKeyNotice = document.getElementById('edit-component-textkey-notice');
    const editComponentNoVars = document.getElementById('edit-component-no-vars');
    
    // Store component data for editing
    let editComponentData = null;
    let editComponentVariables = null;
    
    // Show edit component modal
    function showEditComponentModal() {
        if (!editComponentModal) return;
        editComponentModal.style.display = 'flex';
    }
    
    function hideEditComponentModal() {
        if (editComponentModal) {
            editComponentModal.style.display = 'none';
            editComponentData = null;
            editComponentVariables = null;
        }
    }
    
    // Create a variable row for component editing
    function createComponentVarRow(varInfo, currentValue) {
        const row = document.createElement('div');
        row.className = 'preview-add-node-modal__component-var-row';
        row.dataset.varName = varInfo.name;
        row.dataset.varType = varInfo.type;
        
        if (varInfo.type === 'param') {
            // Editable param variable
            row.innerHTML = `
                <label class="preview-add-node-modal__var-label">
                    ${varInfo.name}
                    <small class="preview-add-node-modal__var-type preview-add-node-modal__var-type--param">(param)</small>
                </label>
                <input type="text" 
                       class="admin-input preview-add-node-modal__var-input" 
                       data-var="${varInfo.name}"
                       value="${currentValue || ''}"
                       placeholder="${varInfo.name}">
            `;
        } else {
            // Read-only textKey variable
            row.innerHTML = `
                <label class="preview-add-node-modal__var-label">
                    ${varInfo.name}
                    <small class="preview-add-node-modal__var-type preview-add-node-modal__var-type--text">(text)</small>
                </label>
                <div class="preview-add-node-modal__var-readonly">
                    <code>${currentValue || '(not set)'}</code>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px; opacity: 0.5;" title="<?= __admin('preview.editInTranslations') ?? 'Edit in Translations page' ?>">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
            `;
        }
        
        return row;
    }
    
    // Populate edit component form
    function populateEditComponentForm(componentName, nodeId, variables, currentData) {
        editComponentData = { componentName, nodeId, currentData };
        editComponentVariables = variables;
        
        // Update display
        editComponentName.textContent = componentName;
        editComponentNodeId.textContent = nodeId;
        
        // Clear container
        editComponentVarsContainer.innerHTML = '';
        
        // Filter variables: only show param and textKey types
        const paramVars = variables.filter(v => v.type === 'param');
        const textKeyVars = variables.filter(v => v.type === 'textKey');
        
        if (paramVars.length === 0 && textKeyVars.length === 0) {
            // No editable variables
            editComponentVarsSection.style.display = 'none';
            editComponentTextKeyNotice.style.display = 'none';
            editComponentNoVars.style.display = 'flex';
            editComponentConfirm.disabled = true;
            return;
        }
        
        editComponentVarsSection.style.display = 'block';
        editComponentNoVars.style.display = 'none';
        editComponentConfirm.disabled = paramVars.length === 0; // Disable if only textKey vars
        
        // Add param variables first (editable)
        paramVars.forEach(varInfo => {
            const currentValue = currentData[varInfo.name] || '';
            editComponentVarsContainer.appendChild(createComponentVarRow(varInfo, currentValue));
        });
        
        // Add textKey variables (read-only)
        textKeyVars.forEach(varInfo => {
            const currentValue = currentData[varInfo.name] || '';
            editComponentVarsContainer.appendChild(createComponentVarRow(varInfo, currentValue));
        });
        
        // Show textKey notice if there are textKey variables
        editComponentTextKeyNotice.style.display = textKeyVars.length > 0 ? 'flex' : 'none';
    }
    
    // Collect edited param values
    function collectEditComponentParams() {
        const data = {};
        editComponentVarsContainer.querySelectorAll('input[data-var]').forEach(input => {
            const varName = input.dataset.var;
            const value = input.value.trim();
            data[varName] = value;
        });
        return data;
    }
    
    // Load component info and open edit modal
    async function openEditComponentModal() {
        if (!selectedStruct || !selectedNode || !selectedComponent) {
            showToast(<?= json_encode(__admin('preview.selectComponentFirst') ?? 'Please select a component first') ?>, 'warning');
            return;
        }
        
        showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
        
        try {
            // Fetch component variables from listComponents
            const listResult = await QuickSiteAdmin.apiRequest('listComponents', 'GET');
            if (!listResult.ok) {
                throw new Error('Failed to fetch component list');
            }
            
            const components = listResult.data?.data?.components || [];
            const componentInfo = components.find(c => c.name === selectedComponent);
            
            if (!componentInfo) {
                throw new Error(`Component "${selectedComponent}" not found`);
            }
            
            // Get current data bindings from the node
            // We need to fetch the actual structure JSON to get the data values
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo) {
                throw new Error('Invalid structure type');
            }
            
            // Fetch the structure to get current data bindings
            // getStructure uses URL params: /getStructure/{type}/{name}
            const urlParams = [structInfo.type];
            if (structInfo.name) {
                urlParams.push(structInfo.name);
            }
            const structResult = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
            
            if (!structResult.ok) {
                throw new Error('Failed to fetch structure');
            }
            
            const structure = structResult.data?.data?.structure || {};
            
            // Navigate to the node to get its data
            const nodeData = navigateToNode(structure, selectedNode);
            const currentData = nodeData?.data || {};
            
            populateEditComponentForm(selectedComponent, selectedNode, componentInfo.variables || [], currentData);
            showEditComponentModal();
            
        } catch (error) {
            console.error('[Preview] Error loading component:', error);
            showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
        }
    }
    
    // Navigate structure tree to find node by path
    function navigateToNode(structure, nodePath) {
        if (!structure || !nodePath) return null;
        
        const pathParts = nodePath.split('.');
        let current = structure;
        
        for (let i = 0; i < pathParts.length; i++) {
            const index = parseInt(pathParts[i], 10);
            
            // For the root, check if there's a 'children' array
            if (i === 0) {
                if (Array.isArray(current)) {
                    current = current[index];
                } else if (current.children && Array.isArray(current.children)) {
                    current = current.children[index];
                } else if (current.structure && current.structure.children) {
                    current = current.structure.children[index];
                } else {
                    return null;
                }
            } else {
                if (current && current.children && Array.isArray(current.children)) {
                    current = current.children[index];
                } else {
                    return null;
                }
            }
            
            if (!current) return null;
        }
        
        return current;
    }
    
    // Close edit component modal handlers
    editComponentModalClose?.addEventListener('click', hideEditComponentModal);
    editComponentCancel?.addEventListener('click', hideEditComponentModal);
    editComponentBackdrop?.addEventListener('click', hideEditComponentModal);
    
    // Escape key closes edit component modal too
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && editComponentModal && editComponentModal.style.display === 'flex') {
            hideEditComponentModal();
        }
    });
    
    // Confirm edit component
    if (editComponentConfirm) {
        editComponentConfirm.addEventListener('click', async function() {
            if (!editComponentData) return;
            
            const { componentName, nodeId, currentData } = editComponentData;
            const newData = collectEditComponentParams();
            
            // Check if anything changed
            let hasChanges = false;
            for (const key of Object.keys(newData)) {
                if (newData[key] !== (currentData[key] || '')) {
                    hasChanges = true;
                    break;
                }
            }
            
            if (!hasChanges) {
                hideEditComponentModal();
                showToast(<?= json_encode(__admin('preview.noChanges') ?? 'No changes to save') ?>, 'info');
                return;
            }
            
            // Parse struct
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                showToast(<?= json_encode(__admin('common.error')) ?> + ': Invalid structure type', 'error');
                return;
            }
            
            hideEditComponentModal();
            showToast(<?= json_encode(__admin('common.loading')) ?> + '...', 'info');
            
            try {
                console.log('[Preview] Editing component:', { structInfo, nodeId, newData });
                
                const apiParams = {
                    type: structInfo.type,
                    nodeId: nodeId,
                    data: newData
                };
                
                if (structInfo.name) {
                    apiParams.name = structInfo.name;
                }
                
                const result = await QuickSiteAdmin.apiRequest('editComponentToNode', 'POST', apiParams);
                
                if (!result.ok) {
                    throw new Error(result.data?.message || result.data?.data?.message || 'Failed to edit component');
                }
                
                const data = result.data?.data || {};
                showToast(<?= json_encode(__admin('preview.componentUpdated') ?? 'Component updated successfully') ?>, 'success');
                console.log('[Preview] Component edited:', data);
                
                // If we have HTML returned, update the DOM directly
                if (data.html) {
                    sendToIframe('updateNode', {
                        struct: selectedStruct,
                        nodeId: nodeId,
                        html: data.html
                    });
                } else {
                    // Fall back to reload
                    reloadPreview();
                }
                
            } catch (error) {
                console.error('[Preview] Edit component error:', error);
                showToast(<?= json_encode(__admin('common.error')) ?> + ': ' + error.message, 'error');
            }
        });
    }
    
    // Modify the Edit Element button to handle components differently
    // Override the nodeEditElementBtn click handler
    if (nodeEditElementBtn) {
        // Remove the previous click handler by cloning the button
        const newEditBtn = nodeEditElementBtn.cloneNode(true);
        nodeEditElementBtn.parentNode.replaceChild(newEditBtn, nodeEditElementBtn);
        
        newEditBtn.addEventListener('click', function() {
            if (!selectedStruct || !selectedNode) {
                showToast(<?= json_encode(__admin('preview.selectNodeFirst') ?? 'Please select an element first') ?>, 'warning');
                return;
            }
            
            // If a component is selected, open the edit component modal
            if (selectedComponent) {
                openEditComponentModal();
            } else {
                // Otherwise, open the regular edit element modal
                openEditNodeModal();
            }
        });
    }
    
    // ==================== Miniplayer (Global Sync) ====================
    
    const MINIPLAYER_STORAGE_KEY = 'quicksite-miniplayer';
    const previewPage = document.getElementById('preview-page');
    const miniplayerToggle = document.getElementById('preview-miniplayer-toggle');
    const miniplayerControls = document.getElementById('preview-miniplayer-controls');
    const miniplayerReload = document.getElementById('miniplayer-reload');
    const miniplayerExpand = document.getElementById('miniplayer-expand');
    const miniplayerClose = document.getElementById('miniplayer-close');
    
    let isMiniplayer = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    // Load saved miniplayer state (synced with global)
    function loadMiniplayerState() {
        const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
        if (saved) {
            try {
                const state = JSON.parse(saved);
                // Sync toggle button state with global enabled state
                if (state.enabled) {
                    updateToggleButtonState(true);
                }
                // Apply local preview miniplayer mode if it was enabled
                if (state.previewMiniplayer) {
                    enableMiniplayer(false);
                    if (state.x !== null) {
                        container.style.left = state.x + 'px';
                        container.style.top = state.y + 'px';
                        container.style.right = 'auto';
                        container.style.bottom = 'auto';
                    }
                    if (state.width) {
                        container.style.width = state.width + 'px';
                        container.style.height = state.height + 'px';
                    }
                }
            } catch (e) {
                console.warn('Failed to load miniplayer state:', e);
            }
        }
    }
    
    // Save miniplayer state (synced with global)
    function saveMiniplayerState() {
        // Read existing global state
        let state = { enabled: false };
        try {
            const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
            if (saved) state = JSON.parse(saved);
        } catch (e) {}
        
        // Update with preview-specific state
        state.previewMiniplayer = isMiniplayer;
        if (isMiniplayer) {
            const rect = container.getBoundingClientRect();
            state.x = parseInt(container.style.left) || rect.left;
            state.y = parseInt(container.style.top) || rect.top;
            state.width = rect.width;
            state.height = rect.height;
        }
        
        // Sync route and lang from current preview
        state.route = routeSelect ? routeSelect.value : '';
        state.lang = langSelect ? langSelect.value : '';
        
        localStorage.setItem(MINIPLAYER_STORAGE_KEY, JSON.stringify(state));
    }
    
    // Update toggle button appearance
    function updateToggleButtonState(enabled) {
        const minimizeIcon = miniplayerToggle.querySelector('.preview-miniplayer-icon--minimize');
        const expandIcon = miniplayerToggle.querySelector('.preview-miniplayer-icon--expand');
        const minimizeText = miniplayerToggle.querySelector('.preview-miniplayer-text--minimize');
        const expandText = miniplayerToggle.querySelector('.preview-miniplayer-text--expand');
        
        if (enabled) {
            minimizeIcon.style.display = 'none';
            expandIcon.style.display = 'block';
            minimizeText.style.display = 'none';
            expandText.style.display = 'inline';
        } else {
            minimizeIcon.style.display = 'block';
            expandIcon.style.display = 'none';
            minimizeText.style.display = 'inline';
            expandText.style.display = 'none';
        }
    }
    
    function enableMiniplayer(save = true) {
        isMiniplayer = true;
        previewPage.classList.add('preview-page--miniplayer');
        updateToggleButtonState(true);
        if (save) saveMiniplayerState();
    }
    
    function disableMiniplayer(save = true) {
        isMiniplayer = false;
        previewPage.classList.remove('preview-page--miniplayer');
        updateToggleButtonState(false);
        // Reset position and size
        container.style.left = '';
        container.style.top = '';
        container.style.right = '';
        container.style.bottom = '';
        container.style.width = '';
        container.style.height = '';
        if (save) saveMiniplayerState();
    }
    
    // Toggle global miniplayer state (for when user leaves preview page)
    function toggleGlobalMiniplayer() {
        let state = { enabled: false };
        try {
            const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
            if (saved) state = JSON.parse(saved);
        } catch (e) {}
        
        state.enabled = !state.enabled;
        
        // Sync route and lang
        state.route = routeSelect ? routeSelect.value : '';
        state.lang = langSelect ? langSelect.value : '';
        
        localStorage.setItem(MINIPLAYER_STORAGE_KEY, JSON.stringify(state));
        updateToggleButtonState(state.enabled);
        
        // Show a toast message
        if (state.enabled) {
            showToast(<?= json_encode(__admin('preview.miniplayer')) ?> + ': ON - Preview will float on other pages', 'success');
        } else {
            showToast(<?= json_encode(__admin('preview.miniplayer')) ?> + ': OFF', 'info');
        }
    }
    
    function toggleMiniplayer() {
        // For preview page: toggle LOCAL miniplayer (floating within preview page)
        if (isMiniplayer) {
            disableMiniplayer();
        } else {
            enableMiniplayer();
        }
    }
    
    // Drag functionality
    function onDragStart(e) {
        // Only start drag from the header area (top 28px)
        const rect = container.getBoundingClientRect();
        const relativeY = e.clientY - rect.top;
        
        if (relativeY > 28) return; // Not in header area
        if (e.target.closest('.preview-miniplayer-controls__btn')) return; // Clicked a button
        
        isDragging = true;
        container.classList.add('preview-container--dragging');
        
        dragOffset.x = e.clientX - rect.left;
        dragOffset.y = e.clientY - rect.top;
        
        e.preventDefault();
    }
    
    function onDragMove(e) {
        if (!isDragging) return;
        
        const newX = e.clientX - dragOffset.x;
        const newY = e.clientY - dragOffset.y;
        
        // Constrain to viewport
        const maxX = window.innerWidth - container.offsetWidth;
        const maxY = window.innerHeight - container.offsetHeight;
        
        container.style.left = Math.max(0, Math.min(newX, maxX)) + 'px';
        container.style.top = Math.max(0, Math.min(newY, maxY)) + 'px';
        container.style.right = 'auto';
        container.style.bottom = 'auto';
    }
    
    function onDragEnd() {
        if (isDragging) {
            isDragging = false;
            container.classList.remove('preview-container--dragging');
            saveMiniplayerState();
        }
    }
    
    // Event listeners for miniplayer
    if (miniplayerToggle) {
        // Toggle button controls GLOBAL miniplayer (for use on other pages)
        miniplayerToggle.addEventListener('click', toggleGlobalMiniplayer);
    }
    
    if (miniplayerReload) {
        miniplayerReload.addEventListener('click', reloadPreview);
    }
    
    if (miniplayerExpand) {
        // In preview-page local miniplayer, expand disables local miniplayer
        miniplayerExpand.addEventListener('click', disableMiniplayer);
    }
    
    if (miniplayerClose) {
        miniplayerClose.addEventListener('click', disableMiniplayer);
    }
    
    // Drag events
    container.addEventListener('mousedown', onDragStart);
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);
    
    // Save size on resize
    const resizeObserver = new ResizeObserver(() => {
        if (isMiniplayer) {
            saveMiniplayerState();
        }
    });
    resizeObserver.observe(container);
    
    // ==================== Public API ====================
    
    window.PreviewManager = {
        reload: reloadPreview,
        navigateTo: navigateTo,
        setDevice: setDevice,
        setMode: setMode,
        getCurrentDevice: () => currentDevice,
        getCurrentMode: () => currentMode,
        highlightNode: (struct, node) => sendToIframe('highlightNode', { struct, node }),
        getSelectedNode: () => ({ struct: selectedStruct, node: selectedNode, component: selectedComponent }),
        // Local miniplayer API (preview page only)
        toggleMiniplayer: toggleMiniplayer,
        isMiniplayer: () => isMiniplayer,
        // Global miniplayer API
        toggleGlobalMiniplayer: toggleGlobalMiniplayer,
        isGlobalMiniplayerEnabled: () => {
            try {
                const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
                if (saved) return JSON.parse(saved).enabled;
            } catch (e) {}
            return false;
        }
    };
    
    // Initial state
    startLoadingTimeout();
    loadMiniplayerState();
    
    // Ensure mode is reset to 'select' on page load (browser may cache button states)
    setMode('select');
    
    // Try to inject overlay immediately if iframe is already loaded
    // (handles case where script runs after iframe load event)
    if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
        console.log('[Preview] Iframe already loaded, injecting overlay');
        injectOverlay();
    }
    
    // Also try after a short delay (handles race conditions)
    setTimeout(function() {
        if (!overlayInjected) {
            console.log('[Preview] Delayed injection attempt');
            injectOverlay();
        }
    }, 300);
})();
</script>

</div><!-- End preview-page wrapper -->
