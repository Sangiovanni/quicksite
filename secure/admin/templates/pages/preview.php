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

<!-- Node Info Panel (shown when element selected) -->
<div class="preview-node-panel" id="preview-node-panel">
    <div class="preview-node-panel__header">
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

<!-- Preview JavaScript -->
<script>
(function() {
    'use strict';
    
    // DOM Elements
    const iframe = document.getElementById('preview-iframe');
    const container = document.getElementById('preview-container');
    const wrapper = document.getElementById('preview-frame-wrapper');
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
    const nodeDeleteBtn = document.getElementById('node-delete');
    
    // Configuration
    const baseUrl = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
    const adminUrl = <?= json_encode($router->url('')) ?>;
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
    
    function setMode(mode) {
        currentMode = mode;
        
        modeBtns.forEach(btn => {
            btn.classList.toggle('preview-mode-btn--active', btn.dataset.mode === mode);
        });
        
        // Update container class for mode-specific styling
        container.dataset.mode = mode;
        
        // Send mode change to iframe
        sendToIframe('setMode', { mode });
        
        // Hide node panel when switching away from select mode
        if (mode !== 'select') {
            hideNodePanel();
        }
    }
    
    // ==================== Node Panel ====================
    
    // Current selection state
    let selectedStruct = null;
    let selectedNode = null;
    let selectedComponent = null;
    
    function showNodePanel(data) {
        // Store selection info for edit/copy actions
        selectedStruct = data.struct || null;
        selectedNode = data.isComponent ? data.componentNode : data.node;
        selectedComponent = data.component || null;
        
        // Format structure name for display
        let structDisplay = data.struct || '-';
        if (structDisplay.startsWith('page-')) {
            structDisplay = 'Page: ' + structDisplay.substring(5);
        } else if (structDisplay === 'menu') {
            structDisplay = 'Menu';
        } else if (structDisplay === 'footer') {
            structDisplay = 'Footer';
        }
        
        // Update panel fields
        nodeStructEl.textContent = structDisplay;
        nodeIdEl.textContent = selectedNode || '-';
        nodeTagEl.textContent = data.tag || '-';
        nodeClassesEl.textContent = data.classes || '-';
        nodeChildrenEl.textContent = data.childCount !== undefined ? data.childCount : '-';
        nodeTextEl.textContent = data.textContent || '-';
        
        // Show/hide component row
        if (data.isComponent && data.component) {
            nodeComponentEl.textContent = data.component;
            nodeComponentRow.style.display = '';
        } else {
            nodeComponentRow.style.display = 'none';
        }
        
        nodePanel.classList.add('preview-node-panel--visible');
    }
    
    function hideNodePanel() {
        nodePanel.classList.remove('preview-node-panel--visible');
        selectedStruct = null;
        selectedNode = null;
        selectedComponent = null;
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
                        
                        currentMode = mode;
                        clearHover();
                        if (mode !== 'select') clearSelection();
                        
                        // Enable new mode
                        if (mode === 'drag') {
                            enableDragMode();
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
                            ? el.className.replace(/qs-(hover|selected|draggable|dragging|drag-over)/g, '').trim() 
                            : null;
                        
                        // Get direct text content
                        let textContent = null;
                        for (const child of el.childNodes) {
                            if (child.nodeType === 3 && child.textContent.trim()) {
                                textContent = child.textContent.trim().substring(0, 100);
                                break;
                            }
                        }
                        
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
                            textContent: textContent
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
                        if (currentMode === 'select') {
                            e.preventDefault();
                            e.stopPropagation();
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
            showToast('<?= __admin('common.error') ?>: Invalid move data', 'error');
            return;
        }
        
        // Parse struct to get type and name
        const structInfo = parseStruct(source.struct);
        if (!structInfo || !structInfo.type) {
            showToast('<?= __admin('common.error') ?>: Invalid structure type', 'error');
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
            showToast('<?= __admin('common.error') ?>: Elements not found', 'error');
            return;
        }
        
        showToast('<?= __admin('common.loading') ?>...', 'info');
        
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
            
            showToast('<?= __admin('preview.elementMoved') ?? 'Element moved successfully' ?>', 'success');
            console.log('[Preview] DOM element moved successfully');
            
        } catch (error) {
            console.error('[Preview] Move error:', error);
            showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
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
        
        // Escape - Clear selection and hide panel
        if (e.key === 'Escape') {
            if (nodePanel && nodePanel.classList.contains('show')) {
                hideNodePanel();
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ action: 'clearSelection' }, '*');
                }
            }
            return;
        }
        
        // Delete or Backspace - Delete selected node
        if (e.key === 'Delete' || (e.key === 'Backspace' && e.metaKey)) {
            // Only if we have a selected node and panel is visible
            if (selectedStruct && selectedNode && nodePanel && nodePanel.classList.contains('show')) {
                e.preventDefault();
                // Trigger the delete button click (reuse existing logic)
                if (nodeDeleteBtn) {
                    nodeDeleteBtn.click();
                }
            }
            return;
        }
    });
    
    if (nodeClose) {
        nodeClose.addEventListener('click', hideNodePanel);
    }
    
    if (nodeDeleteBtn) {
        nodeDeleteBtn.addEventListener('click', async function() {
            if (!selectedStruct || !selectedNode) return;
            
            // Confirm deletion
            const confirmMsg = '<?= __admin('preview.confirmDeleteNode') ?? 'Are you sure you want to delete this element?' ?>';
            if (!confirm(confirmMsg)) return;
            
            // Parse struct to get type and name
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                showToast('<?= __admin('common.error') ?>: Invalid structure type', 'error');
                return;
            }
            
            // Save struct and node BEFORE hiding panel (which clears them)
            const structToDelete = selectedStruct;
            const nodeToDelete = selectedNode;
            
            showToast('<?= __admin('common.loading') ?>...', 'info');
            
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
                
                showToast('<?= __admin('preview.nodeDeleted') ?? 'Element deleted successfully' ?>', 'success');
                console.log('[Preview] Node deleted successfully');
                
                // Live DOM update - remove node and reindex siblings
                sendToIframe('removeNode', {
                    struct: structToDelete,
                    nodeId: nodeToDelete
                });
                
            } catch (error) {
                console.error('[Preview] Delete error:', error);
                showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
            }
        });
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
                showToast('<?= __admin('preview.selectNodeFirst') ?? 'Please select an element first' ?>', 'warning');
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
                showToast('<?= __admin('common.error') ?>: Invalid structure type', 'error');
                return;
            }
            
            // Different handling for tag vs component
            if (currentNodeType === 'component') {
                // Component addition
                const componentName = addNodeComponentSelect?.value;
                if (!componentName) {
                    showToast('<?= __admin('preview.selectComponent') ?? 'Please select a component' ?>', 'warning');
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
                showToast('<?= __admin('common.loading') ?>...', 'info');
                
                try {
                    console.log('[Preview] Adding component:', apiParams);
                    const result = await QuickSiteAdmin.apiRequest('addComponentToNode', 'POST', apiParams);
                    
                    if (!result.ok) {
                        throw new Error(result.data?.message || result.data?.data?.message || 'Failed to add component');
                    }
                    
                    const data = result.data?.data || {};
                    let successMsg = `<?= __admin('preview.componentAdded') ?? 'Component added' ?>: ${componentName}`;
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
                    showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
                }
                
            } else {
                // HTML Tag addition
                const tag = tagSelect?.value || 'div';
                
                // Validate mandatory params
                const requiredParams = TAG_INFO.MANDATORY_PARAMS[tag] || [];
                const params = collectParams();
                
                for (const param of requiredParams) {
                    if (!params[param]) {
                        showToast(`<?= __admin('preview.paramRequired') ?? 'Required parameter' ?>: ${param}`, 'warning');
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
                showToast('<?= __admin('common.loading') ?>...', 'info');
                
                try {
                    console.log('[Preview] Adding node:', apiParams);
                    const result = await QuickSiteAdmin.apiRequest('addNode', 'POST', apiParams);
                    
                    if (!result.ok) {
                        throw new Error(result.data?.message || result.data?.data?.message || 'Failed to add node');
                    }
                    
                    const data = result.data?.data || {};
                    let successMsg = '<?= __admin('preview.elementAdded') ?? 'Element added successfully' ?>';
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
                    showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
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
            showToast('<?= __admin('preview.selectNodeFirst') ?? 'Please select an element first' ?>', 'warning');
            return;
        }
        
        // Get node data from iframe
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        const selector = `[data-qs-struct="${selectedStruct}"][data-qs-node="${selectedNode}"]`;
        const element = iframeDoc.querySelector(selector);
        
        if (!element) {
            showToast('<?= __admin('common.error') ?>: Element not found', 'error');
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
                showToast('<?= __admin('common.error') ?>: Invalid structure type', 'error');
                return;
            }
            
            const newTag = editNodeTagSelect?.value || editNodeOriginalData.tag;
            const { addParams, removeParams } = collectEditParams();
            
            // Check if anything changed
            const tagChanged = newTag !== editNodeOriginalData.tag;
            const hasParamChanges = Object.keys(addParams).length > 0 || removeParams.length > 0;
            
            if (!tagChanged && !hasParamChanges) {
                hideEditNodeModal();
                showToast('<?= __admin('preview.noChanges') ?? 'No changes to save' ?>', 'info');
                return;
            }
            
            // Validate mandatory params for new tag
            const requiredParams = TAG_INFO.MANDATORY_PARAMS[newTag] || [];
            for (const param of requiredParams) {
                const paramRow = editParamsContainer.querySelector(`input[data-param="${param}"]`);
                if (!paramRow || !paramRow.value.trim()) {
                    showToast(`<?= __admin('preview.paramRequired') ?? 'Required parameter' ?>: ${param}`, 'warning');
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
            showToast('<?= __admin('common.loading') ?>...', 'info');
            
            try {
                console.log('[Preview] Editing node:', apiParams);
                const result = await QuickSiteAdmin.apiRequest('editNode', 'POST', apiParams);
                
                if (!result.ok) {
                    throw new Error(result.data?.message || result.data?.data?.message || 'Failed to edit node');
                }
                
                const data = result.data?.data || {};
                let successMsg = '<?= __admin('preview.elementUpdated') ?? 'Element updated successfully' ?>';
                
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
                showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
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
            showToast('<?= __admin('preview.selectComponentFirst') ?? 'Please select a component first' ?>', 'warning');
            return;
        }
        
        showToast('<?= __admin('common.loading') ?>...', 'info');
        
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
            showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
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
                showToast('<?= __admin('preview.noChanges') ?? 'No changes to save' ?>', 'info');
                return;
            }
            
            // Parse struct
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                showToast('<?= __admin('common.error') ?>: Invalid structure type', 'error');
                return;
            }
            
            hideEditComponentModal();
            showToast('<?= __admin('common.loading') ?>...', 'info');
            
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
                showToast('<?= __admin('preview.componentUpdated') ?? 'Component updated successfully' ?>', 'success');
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
                showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
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
                showToast('<?= __admin('preview.selectNodeFirst') ?? 'Please select an element first' ?>', 'warning');
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
            showToast('<?= __admin('preview.miniplayer') ?>: ON - Preview will float on other pages', 'success');
        } else {
            showToast('<?= __admin('preview.miniplayer') ?>: OFF', 'info');
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
