/**
 * Preview Sidebar Resize Module
 * 
 * Handles sidebar width resizing with drag support.
 * - Drag handle to resize sidebar
 * - Min/max width constraints (180px to 50% of viewport)
 * - State persistence via localStorage
 * 
 * Auto-initializes when DOM is ready.
 */
(function() {
    'use strict';
    
    const SIDEBAR_MIN_WIDTH = 180;
    const SIDEBAR_STORAGE_KEY = 'quicksite_sidebar_width';
    
    // DOM references
    const sidebarResize = document.getElementById('sidebar-resize');
    const sidebar = document.getElementById('preview-sidebar');
    const workspace = document.querySelector('.preview-workspace');
    
    // Exit early if elements don't exist
    if (!sidebarResize || !sidebar || !workspace) {
        console.warn('[SidebarResize] Required elements not found');
        return;
    }
    
    // State
    let isResizing = false;
    let startX = 0;
    let startWidth = 0;
    
    /**
     * Get maximum allowed width (50% of viewport)
     */
    function getMaxWidth() {
        return Math.floor(window.innerWidth * 0.5);
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
                if (width >= SIDEBAR_MIN_WIDTH && width <= maxWidth) {
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
     * Handle resize start
     */
    function onResizeStart(e) {
        e.preventDefault();
        isResizing = true;
        startX = e.clientX;
        startWidth = sidebar.offsetWidth;
        
        sidebarResize.classList.add('preview-sidebar__resize--active');
        workspace.classList.add('preview-workspace--resizing');
        
        document.addEventListener('mousemove', onResizeMove);
        document.addEventListener('mouseup', onResizeEnd);
    }
    
    /**
     * Handle resize drag
     */
    function onResizeMove(e) {
        if (!isResizing) return;
        
        const deltaX = e.clientX - startX;
        let newWidth = startWidth + deltaX;
        
        // Clamp to min/max (max is 50% of viewport)
        const maxWidth = getMaxWidth();
        newWidth = Math.max(SIDEBAR_MIN_WIDTH, Math.min(maxWidth, newWidth));
        
        sidebar.style.width = newWidth + 'px';
    }
    
    /**
     * Handle resize end
     */
    function onResizeEnd() {
        if (!isResizing) return;
        
        isResizing = false;
        sidebarResize.classList.remove('preview-sidebar__resize--active');
        workspace.classList.remove('preview-workspace--resizing');
        
        document.removeEventListener('mousemove', onResizeMove);
        document.removeEventListener('mouseup', onResizeEnd);
        
        saveWidth();
    }
    
    // Setup event listener
    sidebarResize.addEventListener('mousedown', onResizeStart);
    
    // Load saved width on init
    loadSavedWidth();
    
    console.log('[SidebarResize] Initialized');
    
    // Export for external access if needed
    window.PreviewSidebarResize = {
        getWidth: () => sidebar.offsetWidth,
        setWidth: (width) => {
            const maxWidth = getMaxWidth();
            width = Math.max(SIDEBAR_MIN_WIDTH, Math.min(maxWidth, width));
            sidebar.style.width = width + 'px';
            saveWidth();
        },
        reset: () => {
            sidebar.style.width = '';
            localStorage.removeItem(SIDEBAR_STORAGE_KEY);
        }
    };
})();
