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
        <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="node-edit-structure">
            <?= __admin('preview.editStructure') ?>
        </button>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="node-copy-id">
            <?= __admin('preview.copyNodeId') ?>
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
    const nodeEditBtn = document.getElementById('node-edit-structure');
    const nodeCopyBtn = document.getElementById('node-copy-id');
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
                        }
                    });
                    
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
    
    if (nodeCopyBtn) {
        nodeCopyBtn.addEventListener('click', function() {
            if (selectedNode) {
                // Copy format: struct:node (e.g., "menu:0.1" or "page-home:0.2")
                const copyText = selectedStruct + ':' + selectedNode;
                navigator.clipboard.writeText(copyText).then(() => {
                    this.textContent = '<?= __admin('common.copied') ?>';
                    setTimeout(() => {
                        this.textContent = '<?= __admin('preview.copyNodeId') ?>';
                    }, 1500);
                });
            }
        });
    }
    
    if (nodeEditBtn) {
        nodeEditBtn.addEventListener('click', function() {
            if (selectedStruct && selectedNode) {
                // Navigate to structure page with struct and nodeId
                // Convert struct format: page-home -> home, menu -> menu, footer -> footer
                let structPath = selectedStruct;
                if (structPath.startsWith('page-')) {
                    structPath = structPath.substring(5);
                }
                window.location.href = structureUrl + '?struct=' + encodeURIComponent(structPath) + '&nodeId=' + encodeURIComponent(selectedNode);
            }
        });
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
            
            // Get the DOM element before API call
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            const selector = `[data-qs-struct="${selectedStruct}"][data-qs-node="${selectedNode}"]`;
            const element = iframeDoc.querySelector(selector);
            
            showToast('<?= __admin('common.loading') ?>...', 'info');
            
            try {
                const params = {
                    type: structInfo.type,
                    nodeId: selectedNode
                };
                if (structInfo.name) {
                    params.name = structInfo.name;
                }
                
                console.log('[Preview] Deleting node:', params);
                const result = await QuickSiteAdmin.apiRequest('deleteNode', 'DELETE', params);
                
                if (!result.ok) {
                    throw new Error(result.data?.message || result.data?.data?.message || 'Failed to delete node');
                }
                
                // Success! Remove the DOM element directly
                if (element) {
                    element.remove();
                }
                
                // Hide the node panel
                hideNodePanel();
                
                // Clear selection in iframe
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ action: 'clearSelection' }, '*');
                }
                
                showToast('<?= __admin('preview.nodeDeleted') ?? 'Element deleted successfully' ?>', 'success');
                console.log('[Preview] Node deleted successfully');
                
            } catch (error) {
                console.error('[Preview] Delete error:', error);
                showToast('<?= __admin('common.error') ?>: ' + error.message, 'error');
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
