/**
 * Preview Sidebar Resize Module
 * 
 * Handles sidebar width resizing with drag support.
 * - Drag handle to resize sidebar
 * - Min/max width constraints (the panel's CSS min-width to 50% of viewport)
 * - State persistence via localStorage (QuickSiteStorageKeys.sidebarWidth)
 * - Pointer events, so a finger drags the handle as a mouse does: the panel's
 *   one drag routine, QSDom.followPointer(), which the device frame's handles share
 *
 * Auto-initializes when DOM is ready.
 */
(function() {
    'use strict';
    
    const SIDEBAR_STORAGE_KEY = QuickSiteStorageKeys.sidebarWidth;
    
    // DOM references
    const sidebarResize = document.getElementById('sidebar-resize');
    const sidebar = document.getElementById('preview-sidebar');
    const workspace = document.querySelector('.preview-workspace');
    
    // Exit early if elements don't exist
    if (!sidebarResize || !sidebar || !workspace) {
        console.warn('[SidebarResize] Required elements not found');
        return;
    }
    
    /**
     * Get maximum allowed width (50% of viewport)
     */
    function getMaxWidth() {
        return Math.floor(window.innerWidth * 0.5);
    }

    /**
     * Get minimum allowed width: the stylesheet's, its one place
     */
    function getMinWidth() {
        return parseFloat(getComputedStyle(sidebar).minWidth) || 0;
    }
    
    /**
     * Load saved width from localStorage
     */
    function loadSavedWidth() {
        try {
            const savedWidth = localStorage.getItem(SIDEBAR_STORAGE_KEY);
            if (savedWidth) {
                const width = parseInt(savedWidth, 10);
                const maxWidth = getMaxWidth();
                if (width >= getMinWidth() && width <= maxWidth) {
                    sidebar.style.width = width + 'px';
                }
            }
        } catch (e) {
            console.warn('[SidebarResize] Failed to load sidebar width:', e);
        }
    }
    
    /**
     * Save current width to localStorage
     */
    function saveWidth() {
        try {
            localStorage.setItem(SIDEBAR_STORAGE_KEY, sidebar.offsetWidth.toString());
        } catch (e) {
            console.warn('[SidebarResize] Failed to save sidebar width:', e);
        }
    }
    
    /**
     * A press on the handle: the panel follows the pointer, clamped to its minimum and to half the
     * window, and its width is stored once the pointer is let go
     */
    function onResizeStart(e) {
        const startWidth = sidebar.offsetWidth;
        
        sidebarResize.classList.add('preview-sidebar__resize--active');
        workspace.classList.add('preview-workspace--resizing');
        
        QSDom.followPointer(e, function(deltaX) {
            sidebar.style.width = Math.max(getMinWidth(), Math.min(getMaxWidth(), startWidth + deltaX)) + 'px';
        }, function() {
            sidebarResize.classList.remove('preview-sidebar__resize--active');
            workspace.classList.remove('preview-workspace--resizing');
            saveWidth();
        });
    }

    // Setup event listener
    sidebarResize.addEventListener('pointerdown', onResizeStart);
    
    // Load saved width on init
    loadSavedWidth();
    
    console.log('[SidebarResize] Initialized');
    
    // Export for external access if needed
    window.PreviewSidebarResize = {
        getWidth: () => sidebar.offsetWidth,
        setWidth: (width) => {
            const maxWidth = getMaxWidth();
            width = Math.max(getMinWidth(), Math.min(maxWidth, width));
            sidebar.style.width = width + 'px';
            saveWidth();
        },
        reset: () => {
            sidebar.style.width = '';
            localStorage.removeItem(SIDEBAR_STORAGE_KEY);
        }
    };
})();
