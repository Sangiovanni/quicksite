/**
 * QuickSite Admin - Global Miniplayer Component
 * 
 * Floating preview window that persists across admin pages.
 * Shows live site preview, auto-reloads on command execution.
 * 
 * @module components/miniplayer
 * @requires QUICKSITE_CONFIG
 */

(function() {
    'use strict';
    
    const STORAGE_KEY = 'quicksite-miniplayer';
    const miniplayer = document.getElementById('global-miniplayer');
    const iframe = document.getElementById('global-miniplayer-iframe');
    const loading = document.getElementById('global-miniplayer-loading');
    const reloadBtn = document.getElementById('global-miniplayer-reload');
    const gotoBtn = document.getElementById('global-miniplayer-goto');
    const closeBtn = document.getElementById('global-miniplayer-close');
    const header = miniplayer?.querySelector('.global-miniplayer__header');
    
    if (!miniplayer || !iframe) return;
    
    let isActive = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    let iframeLoaded = false;
    
    // ============================================
    // State Management
    // ============================================
    
    /**
     * Load state from localStorage
     */
    function loadState() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                return JSON.parse(saved);
            }
        } catch (e) {}
        return { enabled: false, x: null, y: null, width: 400, height: 300 };
    }
    
    /**
     * Save state to localStorage
     */
    function saveState() {
        const rect = miniplayer.getBoundingClientRect();
        const state = {
            enabled: isActive,
            x: parseInt(miniplayer.style.left) || null,
            y: parseInt(miniplayer.style.top) || null,
            width: rect.width,
            height: rect.height,
            route: iframe.dataset.route || '',
            lang: iframe.dataset.lang || ''
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }
    
    // ============================================
    // Show/Hide Controls
    // ============================================
    
    /**
     * Show miniplayer
     */
    function show() {
        isActive = true;
        miniplayer.classList.add('global-miniplayer--active');
        document.body.classList.add('has-miniplayer');
        
        // Load iframe if not loaded yet
        if (!iframeLoaded && iframe.dataset.src) {
            loading.style.display = 'flex';
            iframe.src = iframe.dataset.src;
        }
        
        saveState();
        
        // Dispatch event for other scripts to listen
        window.dispatchEvent(new CustomEvent('miniplayer:show'));
    }
    
    /**
     * Hide miniplayer
     */
    function hide() {
        isActive = false;
        miniplayer.classList.remove('global-miniplayer--active');
        document.body.classList.remove('has-miniplayer');
        saveState();
        
        // Dispatch event
        window.dispatchEvent(new CustomEvent('miniplayer:hide'));
    }
    
    /**
     * Toggle miniplayer visibility
     */
    function toggle() {
        if (isActive) {
            hide();
        } else {
            show();
        }
    }
    
    // ============================================
    // Navigation & Actions
    // ============================================
    
    /**
     * Reload preview iframe
     */
    function reload() {
        if (!iframe.src || iframe.src === 'about:blank') {
            iframe.src = iframe.dataset.src;
        } else {
            loading.style.display = 'flex';
            iframe.src = iframe.src;
        }
    }
    
    /**
     * Navigate to a specific route in preview
     */
    function navigateTo(route, lang) {
        let url = QUICKSITE_CONFIG.baseUrl + '/';
        if (QUICKSITE_CONFIG.multilingual && lang) {
            url += lang + '/';
        } else if (QUICKSITE_CONFIG.multilingual) {
            url += QUICKSITE_CONFIG.defaultLang + '/';
        }
        url += route + '?_editor=1';
        
        iframe.dataset.route = route;
        iframe.dataset.lang = lang || '';
        
        loading.style.display = 'flex';
        iframe.src = url;
        saveState();
    }
    
    /**
     * Go to full preview page (expand)
     */
    function gotoPreviewPage() {
        saveState();
        window.location.href = QUICKSITE_CONFIG.adminBase + '/preview';
    }
    
    // ============================================
    // Drag Functionality
    // ============================================
    
    function onDragStart(e) {
        if (!isActive) return;
        if (e.target.closest('.global-miniplayer__btn')) return;
        if (!e.target.closest('.global-miniplayer__header')) return;
        
        isDragging = true;
        miniplayer.classList.add('global-miniplayer--dragging');
        
        const rect = miniplayer.getBoundingClientRect();
        dragOffset.x = e.clientX - rect.left;
        dragOffset.y = e.clientY - rect.top;
        
        e.preventDefault();
    }
    
    function onDragMove(e) {
        if (!isDragging) return;
        
        const newX = e.clientX - dragOffset.x;
        const newY = e.clientY - dragOffset.y;
        
        // Constrain to viewport
        const maxX = window.innerWidth - miniplayer.offsetWidth;
        const maxY = window.innerHeight - miniplayer.offsetHeight;
        
        miniplayer.style.left = Math.max(0, Math.min(newX, maxX)) + 'px';
        miniplayer.style.top = Math.max(0, Math.min(newY, maxY)) + 'px';
        miniplayer.style.right = 'auto';
        miniplayer.style.bottom = 'auto';
    }
    
    function onDragEnd() {
        if (isDragging) {
            isDragging = false;
            miniplayer.classList.remove('global-miniplayer--dragging');
            saveState();
        }
    }
    
    // ============================================
    // Event Listeners
    // ============================================
    
    // Button controls
    if (reloadBtn) reloadBtn.addEventListener('click', reload);
    if (gotoBtn) gotoBtn.addEventListener('click', gotoPreviewPage);
    if (closeBtn) closeBtn.addEventListener('click', hide);
    
    // Drag events
    if (header) header.addEventListener('mousedown', onDragStart);
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);
    
    // Iframe load handling
    iframe.addEventListener('load', function() {
        iframeLoaded = true;
        loading.style.display = 'none';
    });
    
    // Save size on resize
    const resizeObserver = new ResizeObserver(() => {
        if (isActive) {
            saveState();
        }
    });
    resizeObserver.observe(miniplayer);
    
    // Listen for command execution events to auto-reload
    window.addEventListener('quicksite:command-executed', function(e) {
        if (isActive && iframeLoaded) {
            // Small delay to let the server process the command
            setTimeout(reload, 500);
        }
    });
    
    // ============================================
    // Initialization
    // ============================================
    
    function init() {
        const state = loadState();
        
        // Apply saved position/size
        if (state.width) miniplayer.style.width = state.width + 'px';
        if (state.height) miniplayer.style.height = state.height + 'px';
        if (state.x !== null) {
            miniplayer.style.left = state.x + 'px';
            miniplayer.style.right = 'auto';
        }
        if (state.y !== null) {
            miniplayer.style.top = state.y + 'px';
            miniplayer.style.bottom = 'auto';
        }
        
        // Restore route/lang
        if (state.route) iframe.dataset.route = state.route;
        if (state.lang) iframe.dataset.lang = state.lang;
        
        // Show if was enabled
        if (state.enabled) {
            show();
        }
    }
    
    // ============================================
    // Public API
    // ============================================
    
    window.GlobalMiniplayer = {
        show: show,
        hide: hide,
        toggle: toggle,
        reload: reload,
        navigateTo: navigateTo,
        isActive: () => isActive,
        getIframe: () => iframe
    };
    
    // Initialize
    init();
})();
