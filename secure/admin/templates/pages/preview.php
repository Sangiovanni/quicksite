<?php
/**
 * Visual Preview Page
 * 
 * Live preview of the website with responsive controls.
 * Phase 1 of the Visual Editor feature.
 * 
 * @version 1.0.0
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
            <option value=""><?= __admin('preview.homePage') ?></option>
            <?php foreach ($routes as $route): ?>
                <option value="<?= adminEscape($route) ?>"><?= adminEscape($route) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
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
    
    const iframe = document.getElementById('preview-iframe');
    const container = document.getElementById('preview-container');
    const wrapper = document.getElementById('preview-frame-wrapper');
    const loading = document.getElementById('preview-loading');
    const routeSelect = document.getElementById('preview-route');
    const reloadBtn = document.getElementById('preview-reload');
    const deviceBtns = document.querySelectorAll('.preview-device-btn');
    
    // Site base URL
    const baseUrl = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
    const multilingual = <?= json_encode(CONFIG['MULTILINGUAL_SUPPORT'] ?? false) ?>;
    const defaultLang = <?= json_encode(CONFIG['LANGUAGE_DEFAULT'] ?? 'en') ?>;
    
    // Device sizes
    const devices = {
        desktop: { width: '100%', height: '100%' },
        tablet: { width: '768px', height: '1024px' },
        mobile: { width: '375px', height: '667px' }
    };
    
    let currentDevice = 'desktop';
    
    // Build URL for a route
    function buildUrl(route) {
        let url = baseUrl + '/';
        if (multilingual) {
            url += defaultLang + '/';
        }
        if (route) {
            url += route;
        }
        return url;
    }
    
    // Show loading overlay
    function showLoading() {
        loading.classList.add('preview-loading--visible');
    }
    
    // Hide loading overlay
    function hideLoading() {
        loading.classList.remove('preview-loading--visible');
    }
    
    // Reload iframe
    function reloadPreview() {
        showLoading();
        iframe.src = iframe.src;
    }
    
    // Navigate to route
    function navigateTo(route) {
        showLoading();
        iframe.src = buildUrl(route);
    }
    
    // Set device size
    function setDevice(device) {
        currentDevice = device;
        const size = devices[device];
        
        // Update active button
        deviceBtns.forEach(btn => {
            btn.classList.toggle('preview-device-btn--active', btn.dataset.device === device);
        });
        
        // Update wrapper size
        wrapper.style.width = size.width;
        wrapper.style.height = size.height;
        
        // Toggle device frame mode
        container.classList.toggle('preview-container--device', device !== 'desktop');
    }
    
    // Event: iframe load
    iframe.addEventListener('load', function() {
        hideLoading();
    });
    
    // Event: route change
    routeSelect.addEventListener('change', function() {
        navigateTo(this.value);
    });
    
    // Event: reload button
    reloadBtn.addEventListener('click', function() {
        reloadPreview();
    });
    
    // Event: device buttons
    deviceBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            setDevice(this.dataset.device);
        });
    });
    
    // Expose reload function globally for other pages to use
    window.PreviewManager = {
        reload: reloadPreview,
        navigateTo: navigateTo,
        setDevice: setDevice,
        getCurrentDevice: () => currentDevice
    };
    
    // Initial state
    showLoading();
})();
</script>
