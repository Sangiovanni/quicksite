/**
 * Preview Miniplayer Module
 * 
 * Handles miniplayer functionality for the visual editor.
 * - Toggle between normal and floating miniplayer mode
 * - Drag to reposition
 * - Resize support
 * - State persistence via localStorage
 * - Global sync (miniplayer persists across page navigations)
 * 
 * Dependencies: Expects PreviewMiniplayer.init() to be called with config object
 */
(function() {
    'use strict';
    
    // Storage key for localStorage
    const MINIPLAYER_STORAGE_KEY = 'quicksite-miniplayer';
    
    // State
    let isMiniplayer = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    // DOM references (set during init)
    let container = null;
    let previewPage = null;
    let miniplayerToggle = null;
    let miniplayerControls = null;
    let miniplayerReload = null;
    let miniplayerExpand = null;
    let miniplayerClose = null;
    let targetSelect = null;
    let langSelect = null;
    
    // Callbacks (set during init)
    let showToast = null;
    let reloadPreview = null;
    let i18n = {};
    
    /**
     * Load saved miniplayer state from localStorage
     */
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
                console.warn('[Miniplayer] Failed to load state:', e);
            }
        }
    }
    
    /**
     * Save miniplayer state to localStorage
     */
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
        state.editTarget = targetSelect ? targetSelect.value : '';
        state.lang = langSelect ? langSelect.value : '';
        
        localStorage.setItem(MINIPLAYER_STORAGE_KEY, JSON.stringify(state));
    }
    
    /**
     * Update toggle button appearance
     */
    function updateToggleButtonState(enabled) {
        if (!miniplayerToggle) return;
        
        const minimizeIcon = miniplayerToggle.querySelector('.preview-miniplayer-icon--minimize');
        const expandIcon = miniplayerToggle.querySelector('.preview-miniplayer-icon--expand');
        const minimizeText = miniplayerToggle.querySelector('.preview-miniplayer-text--minimize');
        const expandText = miniplayerToggle.querySelector('.preview-miniplayer-text--expand');
        
        if (enabled) {
            if (minimizeIcon) minimizeIcon.style.display = 'none';
            if (expandIcon) expandIcon.style.display = 'block';
            if (minimizeText) minimizeText.style.display = 'none';
            if (expandText) expandText.style.display = 'inline';
        } else {
            if (minimizeIcon) minimizeIcon.style.display = 'block';
            if (expandIcon) expandIcon.style.display = 'none';
            if (minimizeText) minimizeText.style.display = 'inline';
            if (expandText) expandText.style.display = 'none';
        }
    }
    
    /**
     * Enable miniplayer mode (floating preview)
     */
    function enableMiniplayer(save = true) {
        isMiniplayer = true;
        if (previewPage) previewPage.classList.add('preview-page--miniplayer');
        updateToggleButtonState(true);
        if (save) saveMiniplayerState();
    }
    
    /**
     * Disable miniplayer mode (normal preview)
     */
    function disableMiniplayer(save = true) {
        isMiniplayer = false;
        if (previewPage) previewPage.classList.remove('preview-page--miniplayer');
        updateToggleButtonState(false);
        // Reset position and size
        if (container) {
            container.style.left = '';
            container.style.top = '';
            container.style.right = '';
            container.style.bottom = '';
            container.style.width = '';
            container.style.height = '';
        }
        if (save) saveMiniplayerState();
    }
    
    /**
     * Toggle global miniplayer state (for when user leaves preview page)
     */
    function toggleGlobalMiniplayer() {
        let state = { enabled: false };
        try {
            const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
            if (saved) state = JSON.parse(saved);
        } catch (e) {}
        
        state.enabled = !state.enabled;
        
        // Sync route and lang
        state.editTarget = targetSelect ? targetSelect.value : '';
        state.lang = langSelect ? langSelect.value : '';
        
        localStorage.setItem(MINIPLAYER_STORAGE_KEY, JSON.stringify(state));
        updateToggleButtonState(state.enabled);
        
        // Show a toast message
        if (showToast) {
            if (state.enabled) {
                showToast((i18n.miniplayer || 'Miniplayer') + ': ON - Preview will float on other pages', 'success');
            } else {
                showToast((i18n.miniplayer || 'Miniplayer') + ': OFF', 'info');
            }
        }
    }
    
    /**
     * Toggle local miniplayer mode (within preview page)
     */
    function toggleMiniplayer() {
        if (isMiniplayer) {
            disableMiniplayer();
        } else {
            enableMiniplayer();
        }
    }
    
    /**
     * Check if global miniplayer is enabled
     */
    function isGlobalMiniplayerEnabled() {
        try {
            const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
            if (saved) return JSON.parse(saved).enabled;
        } catch (e) {}
        return false;
    }
    
    // ==================== Drag Functionality ====================
    
    function onDragStart(e) {
        if (!container) return;
        
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
        if (!isDragging || !container) return;
        
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
            if (container) container.classList.remove('preview-container--dragging');
            saveMiniplayerState();
        }
    }
    
    // ==================== Event Setup ====================
    
    function setupEventListeners() {
        // Toggle button controls GLOBAL miniplayer (for use on other pages)
        if (miniplayerToggle) {
            miniplayerToggle.addEventListener('click', toggleGlobalMiniplayer);
        }
        
        // Reload button
        if (miniplayerReload && reloadPreview) {
            miniplayerReload.addEventListener('click', reloadPreview);
        }
        
        // Expand button (disables local miniplayer)
        if (miniplayerExpand) {
            miniplayerExpand.addEventListener('click', function() { disableMiniplayer(); });
        }
        
        // Close button
        if (miniplayerClose) {
            miniplayerClose.addEventListener('click', function() { disableMiniplayer(); });
        }
        
        // Drag events
        if (container) {
            container.addEventListener('mousedown', onDragStart);
            document.addEventListener('mousemove', onDragMove);
            document.addEventListener('mouseup', onDragEnd);
            
            // Save size on resize via ResizeObserver
            const resizeObserver = new ResizeObserver(() => {
                if (isMiniplayer) {
                    saveMiniplayerState();
                }
            });
            resizeObserver.observe(container);
        }
    }
    
    // ==================== Public API ====================
    
    /**
     * Initialize the miniplayer module
     * @param {Object} config Configuration object
     * @param {HTMLElement} config.container - Preview container element
     * @param {HTMLElement} config.previewPage - Preview page wrapper element
     * @param {HTMLElement} config.targetSelect - Target/route select dropdown
     * @param {HTMLElement} config.langSelect - Language select dropdown
     * @param {Function} config.showToast - Toast notification function
     * @param {Function} config.reloadPreview - Reload preview function
     * @param {Object} config.i18n - Internationalization strings
     */
    function init(config) {
        // Store DOM references
        container = config.container || document.getElementById('preview-container');
        previewPage = config.previewPage || document.getElementById('preview-page');
        targetSelect = config.targetSelect || document.getElementById('preview-target');
        langSelect = config.langSelect || document.getElementById('preview-lang');
        
        // Get miniplayer-specific elements
        miniplayerToggle = document.getElementById('preview-miniplayer-toggle');
        miniplayerControls = document.getElementById('preview-miniplayer-controls');
        miniplayerReload = document.getElementById('miniplayer-reload');
        miniplayerExpand = document.getElementById('miniplayer-expand');
        miniplayerClose = document.getElementById('miniplayer-close');
        
        // Store callbacks
        showToast = config.showToast || window.showToast;
        reloadPreview = config.reloadPreview;
        i18n = config.i18n || {};
        
        // Setup event listeners
        setupEventListeners();
        
        // Load saved state
        loadMiniplayerState();
        
        console.log('[Miniplayer] Initialized');
    }
    
    // Export to global namespace
    window.PreviewMiniplayer = {
        init: init,
        toggle: toggleMiniplayer,
        enable: enableMiniplayer,
        disable: disableMiniplayer,
        isEnabled: function() { return isMiniplayer; },
        toggleGlobal: toggleGlobalMiniplayer,
        isGlobalEnabled: isGlobalMiniplayerEnabled,
        loadState: loadMiniplayerState,
        saveState: saveMiniplayerState
    };
})();
