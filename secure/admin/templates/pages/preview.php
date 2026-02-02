<?php
/**
 * Visual Preview Page
 * 
 * Live preview of the website with responsive controls.
 * Phase 1 & 2 of the Visual Editor feature.
 * 
 * @version 2.0.0
 */

// Get auth token for API calls
$token = $router->getToken();

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
            <button type="button" class="preview-mode-btn" data-mode="js" title="<?= __admin('preview.modeJs') ?? 'JS Mode: Manage Interactions' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
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
        
        <!-- JS MODE Content -->
        <div class="preview-contextual-section preview-contextual-section--js" id="contextual-js" data-mode="js" style="display: none;">
            <div class="preview-contextual-default" id="contextual-js-default">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                </svg>
                <span><?= __admin('preview.jsModeHint') ?? 'Click an element to manage its JavaScript interactions' ?></span>
            </div>
            
            <!-- JS Info (shown when element selected) -->
            <div class="preview-contextual-js-info" id="contextual-js-info" style="display: none;">
                <div class="preview-contextual-js-element">
                    <span class="preview-contextual-js-label"><?= __admin('preview.element') ?? 'Element' ?>:</span>
                    <code class="preview-contextual-js-value" id="js-element-info">-</code>
                </div>
            </div>
            
            <!-- Interactions List -->
            <div class="preview-contextual-js-content" id="contextual-js-content" style="display: none;">
                <div class="preview-contextual-js-list" id="js-interactions-list">
                    <p class="preview-contextual-js-empty"><?= __admin('preview.noInteractions') ?? 'No interactions yet.' ?></p>
                </div>
                
                <!-- Add Interaction Form (hidden by default) -->
                <div class="preview-contextual-js-form" id="js-add-form" style="display: none;">
                    <div class="preview-contextual-js-form-header">
                        <strong><?= __admin('preview.newInteraction') ?? 'New Interaction' ?></strong>
                        <button type="button" class="preview-contextual-js-form-close" id="js-form-close" title="<?= __admin('common.cancel') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= __admin('preview.event') ?? 'Event' ?></label>
                        <select class="preview-contextual-js-form-select" id="js-form-event">
                            <option value=""><?= __admin('preview.selectEvent') ?? '-- Select event --' ?></option>
                        </select>
                    </div>
                    
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= __admin('preview.function') ?? 'Function' ?></label>
                        <select class="preview-contextual-js-form-select" id="js-form-function">
                            <option value=""><?= __admin('preview.selectFunction') ?? '-- Select function --' ?></option>
                        </select>
                    </div>
                    
                    <div class="preview-contextual-js-form-params" id="js-form-params">
                        <!-- Dynamic params will be populated based on selected function -->
                    </div>
                    
                    <div class="preview-contextual-js-form-preview">
                        <span class="preview-contextual-js-form-label"><?= __admin('preview.previewCode') ?? 'Preview' ?>:</span>
                        <code class="preview-contextual-js-form-code" id="js-preview-code">-</code>
                    </div>
                    
                    <div class="preview-contextual-js-form-actions">
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="js-form-cancel">
                            <?= __admin('common.cancel') ?? 'Cancel' ?>
                        </button>
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="js-form-save" disabled>
                            <?= __admin('preview.addInteraction') ?? 'Add' ?>
                        </button>
                    </div>
                </div>
                
                <!-- Add button -->
                <div class="preview-contextual-js-actions" id="js-panel-actions">
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="js-add-interaction">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <?= __admin('preview.addInteraction') ?? 'Add Interaction' ?>
                    </button>
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
<script src="<?= $baseUrl ?>/admin/assets/js/components/colorpicker.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/components/colorpicker.js') ?>"></script>

<!-- Preview JavaScript -->

<!-- Preview Configuration and JavaScript (externalized) -->
<?php include __DIR__ . '/preview-config.php'; ?>

</div><!-- End preview-page wrapper -->
