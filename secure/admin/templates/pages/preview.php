<?php
/**
 * Visual Preview Page
 * 
 * Live preview of the website with responsive controls.
 * Phase 1 & 2 of the Visual Editor feature.
 * 
 * @version 2.0.0
 */

// Get site URL for iframe
$siteUrl = rtrim(BASE_URL, '/') . '/';
if (CONFIG['MULTILINGUAL_SUPPORT'] ?? false) {
    $defaultLang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
    $siteUrl .= $defaultLang . '/';
}

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
            <span class="preview-node-panel__label"><?= __admin('preview.nodeId') ?>:</span>
            <code class="preview-node-panel__value" id="node-id">-</code>
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
    </div>
</div>

<!-- Preview Frame Container -->
<div class="preview-container" id="preview-container">
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
    const componentSelect = document.getElementById('preview-component');
    const reloadBtn = document.getElementById('preview-reload');
    const deviceBtns = document.querySelectorAll('.preview-device-btn');
    const modeBtns = document.querySelectorAll('.preview-mode-btn');
    
    // Node panel elements
    const nodePanel = document.getElementById('preview-node-panel');
    const nodeClose = document.getElementById('preview-node-close');
    const nodeIdEl = document.getElementById('node-id');
    const nodeTagEl = document.getElementById('node-tag');
    const nodeClassesEl = document.getElementById('node-classes');
    const nodeChildrenEl = document.getElementById('node-children');
    const nodeTextEl = document.getElementById('node-text');
    const nodeEditBtn = document.getElementById('node-edit-structure');
    const nodeCopyBtn = document.getElementById('node-copy-id');
    
    // Configuration
    const baseUrl = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
    const adminUrl = <?= json_encode(rtrim(ADMIN_BASE, '/')) ?>;
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
    let selectedNodeId = null;
    let overlayInjected = false;
    
    // ==================== URL Building ====================
    
    function buildUrl(route, component = '') {
        let url = baseUrl + '/';
        if (multilingual) {
            url += defaultLang + '/';
        }
        if (route) {
            url += route;
        }
        // Add component isolation parameter if set
        if (component) {
            url += (url.includes('?') ? '&' : '?') + '_component=' + encodeURIComponent(component);
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
    
    function showNodePanel(nodeData) {
        selectedNodeId = nodeData.nodeId;
        
        nodeIdEl.textContent = nodeData.nodeId || '-';
        nodeTagEl.textContent = nodeData.tag || '-';
        nodeClassesEl.textContent = nodeData.classes || '-';
        nodeChildrenEl.textContent = nodeData.childCount !== undefined ? nodeData.childCount : '-';
        nodeTextEl.textContent = nodeData.textContent || '-';
        
        nodePanel.classList.add('preview-node-panel--visible');
    }
    
    function hideNodePanel() {
        nodePanel.classList.remove('preview-node-panel--visible');
        selectedNodeId = null;
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
        if (overlayInjected) return;
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;
            
            // Check if already injected
            if (iframeDoc.getElementById('quicksite-overlay-styles')) {
                overlayInjected = true;
                return;
            }
            
            // Inject CSS
            const style = iframeDoc.createElement('style');
            style.id = 'quicksite-overlay-styles';
            style.textContent = `
                [data-_nodeid].qs-hover {
                    outline: 2px dashed #d97706 !important;
                    outline-offset: 2px !important;
                }
                [data-_nodeid].qs-selected {
                    outline: 2px solid #d97706 !important;
                    outline-offset: 2px !important;
                    background-color: rgba(217, 119, 6, 0.1) !important;
                }
                .qs-drag-over {
                    outline: 3px dashed #3b82f6 !important;
                    outline-offset: 4px !important;
                }
                .qs-drag-indicator {
                    position: absolute;
                    height: 3px;
                    background: #3b82f6;
                    pointer-events: none;
                    z-index: 99999;
                }
            `;
            iframeDoc.head.appendChild(style);
            
            // Inject script
            const script = iframeDoc.createElement('script');
            script.id = 'quicksite-overlay-script';
            script.textContent = `
                (function() {
                    let currentMode = 'select';
                    let hoveredElement = null;
                    let selectedElement = null;
                    
                    // Listen for messages from admin
                    window.addEventListener('message', function(e) {
                        if (e.data && e.data.source === 'quicksite-admin') {
                            if (e.data.action === 'setMode') {
                                currentMode = e.data.mode;
                                clearHover();
                                if (currentMode !== 'select') clearSelection();
                            }
                            if (e.data.action === 'clearSelection') {
                                clearSelection();
                            }
                            if (e.data.action === 'highlightNode') {
                                highlightNodeById(e.data.nodeId);
                            }
                        }
                    });
                    
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
                    
                    function highlightNodeById(nodeId) {
                        clearSelection();
                        const el = document.querySelector('[data-_nodeid="' + nodeId + '"]');
                        if (el) {
                            selectedElement = el;
                            el.classList.add('qs-selected');
                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                    
                    function getNodeInfo(el) {
                        const nodeId = el.getAttribute('data-_nodeid');
                        if (!nodeId) return null;
                        
                        return {
                            nodeId: nodeId,
                            tag: el.tagName.toLowerCase(),
                            classes: el.className.replace(/qs-(hover|selected)/g, '').trim() || null,
                            childCount: el.children.length,
                            textContent: el.childNodes.length === 1 && el.childNodes[0].nodeType === 3 
                                ? el.textContent.substring(0, 100) 
                                : null
                        };
                    }
                    
                    // Hover handling
                    document.addEventListener('mouseover', function(e) {
                        if (currentMode !== 'select') return;
                        
                        const target = e.target.closest('[data-_nodeid]');
                        if (target && target !== hoveredElement && target !== selectedElement) {
                            clearHover();
                            hoveredElement = target;
                            target.classList.add('qs-hover');
                        }
                    });
                    
                    document.addEventListener('mouseout', function(e) {
                        if (currentMode !== 'select') return;
                        
                        const target = e.target.closest('[data-_nodeid]');
                        if (target && target === hoveredElement) {
                            clearHover();
                        }
                    });
                    
                    // Click handling
                    document.addEventListener('click', function(e) {
                        if (currentMode !== 'select') return;
                        
                        const target = e.target.closest('[data-_nodeid]');
                        if (target) {
                            e.preventDefault();
                            e.stopPropagation();
                            
                            clearSelection();
                            clearHover();
                            
                            selectedElement = target;
                            target.classList.add('qs-selected');
                            
                            const info = getNodeInfo(target);
                            if (info) {
                                window.parent.postMessage({ 
                                    source: 'quicksite-preview', 
                                    action: 'nodeSelected',
                                    ...info
                                }, '*');
                            }
                        }
                    }, true);
                })();
            `;
            iframeDoc.body.appendChild(script);
            
            overlayInjected = true;
        } catch (e) {
            console.warn('Could not inject overlay (cross-origin?):', e);
        }
    }
    
    // Listen for messages from iframe
    window.addEventListener('message', function(e) {
        if (e.data && e.data.source === 'quicksite-preview') {
            if (e.data.action === 'nodeSelected') {
                showNodePanel(e.data);
            }
        }
    });
    
    // ==================== Event Handlers ====================
    
    iframe.addEventListener('load', function() {
        clearTimeout(loadingTimeout);
        hideLoading();
        // Inject overlay after iframe loads
        setTimeout(injectOverlay, 100);
    });
    
    routeSelect.addEventListener('change', function() {
        navigateTo(this.value, currentComponent);
    });
    
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
    
    nodeClose.addEventListener('click', hideNodePanel);
    
    nodeCopyBtn.addEventListener('click', function() {
        if (selectedNodeId) {
            navigator.clipboard.writeText(selectedNodeId).then(() => {
                this.textContent = '<?= __admin('common.copied') ?>';
                setTimeout(() => {
                    this.textContent = '<?= __admin('preview.copyNodeId') ?>';
                }, 1500);
            });
        }
    });
    
    nodeEditBtn.addEventListener('click', function() {
        if (selectedNodeId) {
            // Navigate to structure page with nodeId pre-filled
            window.location.href = adminUrl + '/structure?nodeId=' + encodeURIComponent(selectedNodeId);
        }
    });
    
    // ==================== Public API ====================
    
    window.PreviewManager = {
        reload: reloadPreview,
        navigateTo: navigateTo,
        setDevice: setDevice,
        setMode: setMode,
        getCurrentDevice: () => currentDevice,
        getCurrentMode: () => currentMode,
        highlightNode: (nodeId) => sendToIframe('highlightNode', { nodeId }),
        getSelectedNodeId: () => selectedNodeId
    };
    
    // Initial state
    startLoadingTimeout();
})();
</script>
