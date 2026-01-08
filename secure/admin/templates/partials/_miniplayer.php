<?php
/**
 * Global Miniplayer Partial
 * 
 * Floating preview window that persists across admin pages.
 * Include this in layout.php for global miniplayer functionality.
 * 
 * @version 1.0.0
 */

// Get site URL for iframe
$miniplayerSiteUrl = rtrim(BASE_URL, '/') . '/';
if (CONFIG['MULTILINGUAL_SUPPORT'] ?? false) {
    $miniplayerDefaultLang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
    $miniplayerSiteUrl .= $miniplayerDefaultLang . '/';
}
$miniplayerSiteUrl .= '?_editor=1';
?>

<!-- Global Miniplayer Container -->
<div class="global-miniplayer" id="global-miniplayer">
    <!-- Header bar (drag handle) -->
    <div class="global-miniplayer__header">
        <span class="global-miniplayer__title"><?= __admin('preview.title') ?></span>
        <div class="global-miniplayer__controls">
            <button type="button" class="global-miniplayer__btn" id="global-miniplayer-reload" title="<?= __admin('preview.reload') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
            </button>
            <button type="button" class="global-miniplayer__btn" id="global-miniplayer-goto" title="<?= __admin('preview.expand') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 3 21 3 21 9"/>
                    <polyline points="9 21 3 21 3 15"/>
                    <line x1="21" y1="3" x2="14" y2="10"/>
                    <line x1="3" y1="21" x2="10" y2="14"/>
                </svg>
            </button>
            <button type="button" class="global-miniplayer__btn" id="global-miniplayer-close" title="<?= __admin('common.close') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Iframe container -->
    <div class="global-miniplayer__content">
        <iframe 
            id="global-miniplayer-iframe"
            class="global-miniplayer__iframe"
            src="about:blank"
            data-src="<?= $miniplayerSiteUrl ?>"
            title="Website Preview"
        ></iframe>
        
        <!-- Loading overlay -->
        <div class="global-miniplayer__loading" id="global-miniplayer-loading">
            <div class="global-miniplayer__spinner"></div>
        </div>
    </div>
    
    <!-- Resize handle indicator -->
    <div class="global-miniplayer__resize-handle"></div>
</div>

<script>
/**
 * Global Miniplayer Manager
 * Handles miniplayer state across all admin pages
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
    
    // Load state from localStorage
    function loadState() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const state = JSON.parse(saved);
                return state;
            }
        } catch (e) {}
        return { enabled: false, x: null, y: null, width: 400, height: 300 };
    }
    
    // Save state to localStorage
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
    
    // Show miniplayer
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
    
    // Hide miniplayer
    function hide() {
        isActive = false;
        miniplayer.classList.remove('global-miniplayer--active');
        document.body.classList.remove('has-miniplayer');
        saveState();
        
        // Dispatch event
        window.dispatchEvent(new CustomEvent('miniplayer:hide'));
    }
    
    // Toggle miniplayer
    function toggle() {
        if (isActive) {
            hide();
        } else {
            show();
        }
    }
    
    // Reload preview
    function reload() {
        if (!iframe.src || iframe.src === 'about:blank') {
            iframe.src = iframe.dataset.src;
        } else {
            loading.style.display = 'flex';
            iframe.src = iframe.src;
        }
    }
    
    // Navigate to a specific route
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
    
    // Go to preview page (expand)
    function gotoPreviewPage() {
        // Save current state first
        saveState();
        // Navigate to preview page
        window.location.href = QUICKSITE_CONFIG.adminBase + '/preview';
    }
    
    // Drag functionality
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
    
    // Event listeners
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
    
    // Initialize from saved state
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
    
    // Public API
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
</script>
