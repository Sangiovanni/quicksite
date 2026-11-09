<?php
/**
 * Visual Preview Page
 * 
 * Live preview of the website with responsive controls.
 * 
 * Refactored: Split into partial files for maintainability.
 * 
 * @version 2.1.0
 */

require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php'; // the edited project's languages
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // flattenRoutes()

// Get auth token for API calls
$token = $router->getToken();

// The editor edits getCurrentProject() (the per-user selected_project). Every server-side
// read on this page (and in the partials + preview-config.php it includes) comes from the
// EDITED project's own path + config, so the sidebar/toolbar always describe the project on
// screen — its routes, components, languages and theme flags.
$editProject     = $router->getCurrentProject() ?: (defined('PROJECT_NAME') ? PROJECT_NAME : '');
$editProjectPath = SECURE_FOLDER_PATH . '/projects/' . $editProject;
$editConfigFile  = $editProjectPath . '/config.php';
$editConfig      = is_file($editConfigFile) ? (require $editConfigFile) : (defined('CONFIG') ? CONFIG : []);
$editConfig      = is_array($editConfig) ? $editConfig : [];

// Get multilingual config (of the EDITED project)
$isMultilingual = $editConfig['MULTILINGUAL_SUPPORT'] ?? false;
$defaultLang = qs_project_default_language($editConfig);
$languages = $isMultilingual ? qs_project_language_codes($editConfig) : [$defaultLang];

// Get site URL for iframe (start with default language) + ?_editor=1 for editor mode.
// The project you EDIT is getCurrentProject() (per-user selected_project); every project
// previews at its own /p/<id>/ from its own folder. A PRIVATE project's iframe is a plain
// browser navigation with no headers of its own; it authenticates on the session cookie,
// which the browser sends because surface B shares this origin.
$previewProject = $router->getCurrentProject();          // what you EDIT (selected_project)
$siteUrl = $router->projectSiteBase($previewProject) . '/';
if ($isMultilingual) {
    $siteUrl .= $defaultLang . '/';
}
// `_t` = a per-page-load cache-buster so the preview iframe always loads FRESH content for
// the current project — never a stale/bfcached page from a previous project (which would
// desync the iframe DOM from the editor's marker and mis-target edits).
$siteUrl .= '?_editor=1&_t=' . time();

// Get available routes for navigation (from the EDITED project)
$routes = [];
$routesFile = $editProjectPath . '/routes.php';
$routesData = file_exists($routesFile) ? require $routesFile : null;
// is_array, not file_exists alone: a routes.php that returns anything else
// (empty file, a stray scalar, a half-written save) reached the flattener as a
// non-array and fatalled the whole editor page. Every other reader in the tree
// already guards this way.
if (is_array($routesData)) {
    // The list getRoutes answers, so the browser's rebuild of the Edit list spells every route the same way.
    $routes = flattenRoutes($routesData);
}

// Add the special page that exists as a structure file but is not in routes: the one the editor can
// show (SPECIAL_PAGES names others, whose address renders this one). The browser's rebuild of the
// Edit list keeps it (PreviewConfig.specialPages): getRoutes does not answer it.
$specialPagesListed = [];
foreach (['404'] as $sp) {
    $spFolder = $editProjectPath . '/templates/model/json/pages/' . $sp . '/' . $sp . '.json';
    $spFlat = $editProjectPath . '/templates/model/json/pages/' . $sp . '.json';
    if (file_exists($spFolder) || file_exists($spFlat)) {
        $specialPagesListed[] = $sp;
    }
}
$routes = array_merge($routes, $specialPagesListed);

// Get available components (from the EDITED project)
$components = [];
$componentsDir = $editProjectPath . '/templates/model/json/components';
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
<?php include __DIR__ . '/preview/tools.php'; ?>
<?php include __DIR__ . '/preview/element-info.php'; ?>

<div class="preview-workspace">
    <?php include __DIR__ . '/preview/element-info-details.php'; ?>

    <aside class="preview-sidebar" id="preview-sidebar">
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

        <!-- TRANSLATION MODE Content -->
        <?php include __DIR__ . '/preview/contextual-translation.php'; ?>

        <!-- AI TOOLS MODE Content -->
        <?php include __DIR__ . '/preview/contextual-ai-tools.php'; ?>
    </div><!-- End preview-contextual-content -->
</div><!-- End preview-contextual-area -->

        </div><!-- End preview-sidebar__options -->
        <!-- Opens and closes the panel where it folds away (the phone block draws it, and turns the arrow); its words are the tool's name -->
        <button type="button" class="preview-sidebar__toggle" id="preview-panel-toggle" aria-expanded="false" aria-controls="preview-sidebar-options">
            <span id="preview-panel-toggle-name"></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
        <div class="preview-sidebar__resize" id="sidebar-resize" title="<?= adminAttr(__admin('preview.resizePanel', 'Drag to widen or narrow this panel')) ?>"></div>
    </aside><!-- End preview-sidebar -->
    
    <!-- Main Preview Area -->
    <?php include __DIR__ . '/preview/main-area.php'; ?>
</div><!-- End preview-workspace -->

</div><!-- End preview-page wrapper -->

<!-- Modals (top-level so position:fixed isn't constrained by any ancestor containing block) -->
<?php include __DIR__ . '/preview/modals/keyframe.php'; ?>
<?php include __DIR__ . '/preview/modals/transition.php'; ?>
<?php include __DIR__ . '/preview/modals/animation-preview.php'; ?>
<?php include __DIR__ . '/preview/modals/apply-keyframe.php'; ?>
<?php include __DIR__ . '/preview/modals/add-transition.php'; ?>
<?php include __DIR__ . '/preview/modals/translate-csv.php'; ?>

<script src="<?= $baseUrl ?>/admin/assets/js/components/colorpicker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/components/colorpicker.js') ?>"></script>
<?php include __DIR__ . '/preview-config.php'; ?>
