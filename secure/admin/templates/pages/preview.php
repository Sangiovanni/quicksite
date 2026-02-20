<?php
/**
 * Visual Preview Page
 * 
 * Live preview of the website with responsive controls.
 * Phase 1 & 2 of the Visual Editor feature.
 * 
 * Refactored: Split into partial files for maintainability.
 * 
 * @version 2.1.0
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

<!-- Preview Controls - Toolbar -->
<?php include __DIR__ . '/preview/toolbar.php'; ?>

<div class="preview-workspace">
    <aside class="preview-sidebar" id="preview-sidebar">
        <!-- Sidebar Tool Icons -->
        <?php include __DIR__ . '/preview/sidebar-tools.php'; ?>
        
        <div class="preview-sidebar__options" id="preview-sidebar-options">

<div class="preview-contextual-area preview-contextual-area--sidebar" id="preview-contextual-area">
    <div class="preview-contextual-content" id="preview-contextual-content">
        <!-- SELECT MODE Content -->
        <?php include __DIR__ . '/preview/contextual-select.php'; ?>
        
        <!-- DRAG MODE Content -->
        <?php include __DIR__ . '/preview/contextual-drag.php'; ?>
        
        <!-- TEXT MODE Content -->
        <?php include __DIR__ . '/preview/contextual-text.php'; ?>
        
        <!-- STYLE MODE Content -->
        <?php include __DIR__ . '/preview/contextual-style.php'; ?>
        
        <!-- JS MODE Content -->
        <?php include __DIR__ . '/preview/contextual-js.php'; ?>
        
        <!-- ADD MODE Content -->
        <?php include __DIR__ . '/preview/contextual-add.php'; ?>
    </div><!-- End preview-contextual-content -->
</div><!-- End preview-contextual-area -->

<!-- Legacy Panels (deprecated, kept for compatibility) -->
<?php include __DIR__ . '/preview/deprecated-panels.php'; ?>
<?php include __DIR__ . '/preview/modals/keyframe.php'; ?>
<?php include __DIR__ . '/preview/modals/transform.php'; ?>
<?php include __DIR__ . '/preview/modals/transition.php'; ?>
<?php include __DIR__ . '/preview/modals/animation-preview.php'; ?>

        </div><!-- End preview-sidebar__options -->
        <div class="preview-sidebar__resize" id="sidebar-resize"></div>
    </aside><!-- End preview-sidebar -->
    
    <!-- Main Preview Area -->
    <?php include __DIR__ . '/preview/main-area.php'; ?>
</div><!-- End preview-workspace -->

<script src="<?= $baseUrl ?>/admin/assets/js/components/colorpicker.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/components/colorpicker.js') ?>"></script>
<?php include __DIR__ . '/preview-config.php'; ?>

</div><!-- End preview-page wrapper -->
