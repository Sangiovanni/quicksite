<?php
/**
 * Preview Page Configuration
 * Generates the PreviewConfig JavaScript object for preview.js
 *
 * This file is included by preview.php to separate config from template
 */

// Route resolvers sidecar exposed to PreviewConfig so the
// editor knows which routes have a resolver (and what variables they
// expose) when building the emulation panel. Small payload — only
// routes WITH a resolver are present.
// Read from the EDITED project ($editProjectPath, computed by preview.php
// which includes this file). Defensive fallbacks in case of a different
// inclusion context.
require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';
$__previewRouteResolvers = loadResolversSidecar($editProjectPath ?? null);

// Tag classification comes from TagRegistry, the single source of
// truth. preview.js used to carry a hand-written copy that had drifted from
// the server (it offered blocked tags and demanded params the server never
// required). Adding a tag or a param is now one edit, in TagRegistry.
require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';
// qs_inline_script_json(), the encoder for a value in an inline script.
require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php'; // qs_project_default_language
if (!isset($editConfig)) {
    $editConfig = defined('CONFIG') ? CONFIG : [];
}

// The Workflows panel's list (mode ai-tools), loaded server-side. A workflow is listed only when
// the role may send every command a run of it may send, its sub-workflows' included
// (WorkflowManager::runCommands()); the others go to a list of their own, which the panel shows
// greyed and inert only when asked to (Options, "Show workflows my role can't run"). Each row carries what a run does, derived from the workflow's
// file and every workflow it may reach (WorkflowManager::rowFacts()), and the groups come in the
// schema's order (WorkflowManager::categories()).
require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
$__previewAiToolsWorkflows = [];
$__previewAiToolsUnrunnable = [];
$__previewWorkflowGroups = [];
try {
    $__aiToolsManager = new WorkflowManager();
    $__aiToolsSpecs = $__aiToolsManager->listWorkflows();
    $__aiToolsAllowed = null;
    // Resolve the caller's session to a user, then its effective commands.
    $__aiToolsResolved = qs_session_auth();
    if ($__aiToolsResolved['valid']) {
        $__aiToolsPerms = getTokenPermissions($__aiToolsResolved['user']);
        $__aiToolsAllowed = $__aiToolsPerms['commands'] ?? null;
    }
    $__aiToolsCategoryOrder = $__aiToolsManager->categories();
    $__aiToolsCategoryIndex = array_flip($__aiToolsCategoryOrder);
    foreach ($__aiToolsSpecs as $__aiToolsSpec) {
        $__aiToolsRunnable = true;
        if ($__aiToolsAllowed !== null) {
            foreach ($__aiToolsManager->runCommands($__aiToolsSpec) as $__aiToolsCmd) {
                if (!in_array($__aiToolsCmd, $__aiToolsAllowed, true)) {
                    $__aiToolsRunnable = false;
                    break;
                }
            }
        }
        $__aiToolsMeta = $__aiToolsSpec['meta'] ?? [];
        $__aiToolsCategory = is_string($__aiToolsMeta['category'] ?? null) ? $__aiToolsMeta['category'] : 'other';
        $__aiToolsFacts = $__aiToolsManager->rowFacts($__aiToolsSpec);
        // What a workflow needs first, in the panel's words (the key its file names).
        $__aiToolsFacts['needs'] = $__aiToolsFacts['needsKey'] !== null ? __admin($__aiToolsFacts['needsKey']) : '';
        unset($__aiToolsFacts['needsKey']);
        $__aiToolsRow = [
            'id'              => $__aiToolsSpec['id'],
            'icon'            => $__aiToolsMeta['icon'] ?? '📋',
            'title'           => $__aiToolsMeta['name']
                                  ?? __workflow($__aiToolsSpec, $__aiToolsMeta['titleKey'] ?? '', $__aiToolsSpec['id']),
            'description'     => $__aiToolsMeta['description']
                                  ?? __workflow($__aiToolsSpec, $__aiToolsMeta['descriptionKey'] ?? '', ''),
            'category'        => $__aiToolsCategory,
            // Hidden search words: a search reads them, nothing shows them.
            'tags'            => array_values(array_filter($__aiToolsMeta['tags'] ?? [], 'is_string')),
            'facts'           => $__aiToolsFacts,
        ];
        // A workflow the role cannot run goes to a list of its own, which nothing that opens a workflow reads: the panel
        // shows it greyed, with nothing to press, only when the person asks to see it.
        if ($__aiToolsRunnable) {
            $__previewAiToolsWorkflows[] = $__aiToolsRow;
        } else {
            $__previewAiToolsUnrunnable[] = $__aiToolsRow;
        }
    }
    $__aiToolsSort = function ($a, $b) use ($__aiToolsCategoryIndex) {
        $aIdx = $__aiToolsCategoryIndex[$a['category']] ?? 999;
        $bIdx = $__aiToolsCategoryIndex[$b['category']] ?? 999;
        if ($aIdx !== $bIdx) return $aIdx - $bIdx;
        return strcasecmp($a['title'], $b['title']);
    };
    usort($__previewAiToolsWorkflows, $__aiToolsSort);
    usort($__previewAiToolsUnrunnable, $__aiToolsSort);
    // The groups in the schema's order, then any group a file names that the schema does not know, last, by its own name.
    $__aiToolsGroupIds = $__aiToolsCategoryOrder;
    foreach (array_merge($__previewAiToolsWorkflows, $__previewAiToolsUnrunnable) as $__aiToolsRow) {
        if (!in_array($__aiToolsRow['category'], $__aiToolsGroupIds, true)) $__aiToolsGroupIds[] = $__aiToolsRow['category'];
    }
    // Each group's title and the line under it (workflows.categories.<group>); a group no word file names shows its path.
    foreach ($__aiToolsGroupIds as $__aiToolsGroupId) {
        $__previewWorkflowGroups[] = [
            'id'    => $__aiToolsGroupId,
            'title' => __admin('workflows.categories.' . $__aiToolsGroupId . '.title'),
            'line'  => __admin('workflows.categories.' . $__aiToolsGroupId . '.desc'),
        ];
    }
} catch (\Throwable $__aiToolsErr) {
    error_log('[PreviewAiTools] Failed to load workflow list: ' . $__aiToolsErr->getMessage());
}

// Per-route schema-driven default values for the
// emulation panel. For each resolver-bound route, walk the endpoint's
// responseSchema (if defined in /admin/apis) and generate sample values
// per `expose` mapping. The editor uses these to pre-fill the panel
// when no per-page emulation has been saved yet — author sees realistic
// placeholders without typing. Empty defaults when the endpoint has no
// responseSchema (panel falls back to empty inputs).
$__previewResolverDefaults = [];
if (!empty($__previewRouteResolvers)) {
    require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';
    $__previewApiManager = new ApiEndpointManager();
    foreach ($__previewRouteResolvers as $__routePath => $__resolverCfg) {
        $__previewResolverDefaults[$__routePath] = getResolverDefaultsForRoute($__resolverCfg, $__previewApiManager);
    }
}

// The project this panel works with (getCurrentProject = the caller's own
// selected_project, same as the editor marker + the preview iframe, so edit and preview
// never diverge). Management commands go through QuickSiteAdmin.apiRequest, which adds
// the project marker itself.
$__previewProject  = $router->getCurrentProject();
?>
<!-- The editor's words (QSPanelWords), before its scripts: its own sections, and the Social previews and Translations
     pages' sections, whose words its description field and Translation tool share; common and nav come from the layout -->
<?= __adminWords(['preview', 'complexWizard', 'socialPreviews', 'translations']) ?>
<!-- Preview Configuration (needed before preview.js) -->
<script>
window.PreviewConfig = {
    // URLs and settings
    baseUrl: <?= qs_inline_script_json(rtrim(BASE_URL, '/')) ?>,
    // The base the preview IFRAME navigates under: the edited project's own
    // '/p/<id>' view. buildUrl() must use THIS (not baseUrl) or picking a page in the
    // toolbar silently navigates the iframe out of the project being edited.
    previewBase: <?= qs_inline_script_json($router->projectSiteBase($__previewProject)) ?>,
    adminUrl: <?= qs_inline_script_json($router->url('')) ?>,
    currentProject: <?= qs_inline_script_json($__previewProject) ?>,

    // Tag classification, mandatory params, per-tag defaults and the
    // per-tag translation-key params, emitted from TagRegistry::editorPayload().
    // preview.js reads this as TAG_INFO and keeps NO list of its own.
    tagInfo: <?= qs_inline_script_json(TagRegistry::editorPayload()) ?>,

    // The special pages the Edit list holds beside the routes (preview.php): getRoutes does
    // not answer them, and refreshEditList() rebuilds the Pages group whole.
    specialPages: <?= qs_inline_script_json($specialPagesListed) ?>,

    // Per-route resolver sidecar (only routes with a resolver).
    // Used by the editor's emulation panel to know which variables exist
    // per page (resolver.expose keys → editable inputs).
    routeResolvers: <?= qs_inline_script_json($__previewRouteResolvers ?: new stdClass()) ?>,

    // Schema-driven sample defaults for resolver
    // variables. Used by the emulation panel to pre-fill first-time
    // inputs with realistic placeholders derived from the endpoint's
    // responseSchema. Empty per-route map when the endpoint has no
    // schema declared in /admin/apis.
    routeResolverDefaults: <?= qs_inline_script_json($__previewResolverDefaults ?: new stdClass()) ?>,

    // Workflows panel — pre-resolved workflow metadata, each with what a run does
    // (its facts). Role-filtered. One entry per visible workflow.
    aiToolsWorkflows: <?= qs_inline_script_json($__previewAiToolsWorkflows) ?>,
    // The workflows the role cannot run, the same shape: shown greyed, with nothing to press, only when the
    // person asks to see them. Nothing that opens a workflow reads this list.
    aiToolsUnrunnable: <?= qs_inline_script_json($__previewAiToolsUnrunnable) ?>,
    // The panel's groups, in order: id, title and the line under it.
    workflowGroups: <?= qs_inline_script_json($__previewWorkflowGroups) ?>,
    // The edited project's own stylesheet, where /p/<id>/ serves it (the snippet
    // preview's "with the project's style"). The install root has no stylesheet.
    projectStyleUrl: <?= qs_inline_script_json($router->projectSiteBase($__previewProject) . '/style/style.css') ?>,
    // The per-session token, same value as QUICKSITE_CONFIG.token — the editor's
    // hand-built fetch sites read it from here. It authorizes nothing on its own;
    // the session cookie is the credential (see AuthManagement).
    authToken: <?= qs_inline_script_json($router->getToken()) ?>,
    structureUrl: <?= qs_inline_script_json($router->url('structure')) ?>,
    multilingual: <?= qs_inline_script_json(qs_project_is_multilingual($editConfig)) ?>,
    defaultLang: <?= qs_inline_script_json(qs_project_default_language($editConfig)) ?>,
    
    // Theme mode
    themeModeEnabled: <?= qs_inline_script_json($editConfig['THEME_MODE_ENABLED'] ?? false) ?>,
    themeDefault: <?= qs_inline_script_json($editConfig['THEME_DEFAULT'] ?? 'light') ?>,
    themeUserToggle: <?= qs_inline_script_json($editConfig['THEME_USER_TOGGLE_ENABLED'] ?? false) ?>,
};
</script>

<!-- Preview JavaScript Modules (load order matters) -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-state.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-state.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-navigation.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-navigation.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-theme.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-style-theme.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-motion.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-style-motion.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-selectors.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-style-selectors.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-editor.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-style-editor.js') ?>"></script>
<!-- lib/code-editor — order matters: core widget first, tokenizers register on top -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/lib/code-editor/code-editor.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/lib/code-editor/code-editor.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/lib/code-editor/css-tokenizer.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/lib/code-editor/css-tokenizer.js') ?>"></script>
<!-- lib/easing-picker — cubic-bezier easing picker -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/lib/easing-picker/easing-picker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/lib/easing-picker/easing-picker.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-source.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-style-source.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-js-interactions.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-js-interactions.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-translation.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-translation.js') ?>"></script>
<!-- AI libs: BYOK connection store + provider catalog + caller. Read-only
     here (full management UI lives on /admin/ai-connections). Load order
     matters: catalog + presets before store; store + catalog before caller. -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/provider-catalog.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/lib/provider-catalog.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/local-presets.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/lib/local-presets.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/connections-store.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/lib/connections-store.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/stream-parsers.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/lib/stream-parsers.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/ai-call.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/lib/ai-call.js') ?>"></script>
<!-- A srcset read and written (QSSrcset): the Workflows tool's outside pictures and the srcset field split it as the engine does -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/core/srcset.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/srcset.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-ai-tools.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-ai-tools.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-transition-editor.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-transition-editor.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-sidebar-resize.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-sidebar-resize.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-drag.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-drag.js') ?>"></script>

<!-- Complex Element wizards (Add Element → Complex tab).
     Loaded BEFORE preview.js so the Complex-tab handler can call into
     window.QSComplexWizard during init.
     Order matters: the wizards' words (wizard-text.js, over the complexWizard
     section handed above), then the shared helpers (row editor + textKey picker),
     then the per-kind `complex-*.js` files (each kind may use them on parse). -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/wizard-text.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/wizard-text.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/wizard-row-editor.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/wizard-row-editor.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/storage-key-picker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/storage-key-picker.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/route-input.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/route-input.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js') ?>"></script>
<?php
    // Auto-include every complex-*.js so adding a new kind is one file drop.
    foreach (glob(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/complex-*.js') as $_ceJs) {
        $_ceBase = basename($_ceJs);
        echo '<script src="' . rtrim(BASE_URL, '/') . '/admin/assets/js/pages/preview/contextual-complex/' . $_ceBase
            . '?v=' . filemtime($_ceJs) . '"></script>' . "\n";
    }
?>

<!-- A srcset's field (PreviewSrcsetField): the Add form's and Edit Params' rows of pictures. Before preview.js, which draws it. -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-srcset-field.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-srcset-field.js') ?>"></script>

<!-- The simple Add (Select → Add in simple mode): its list's sends. Before preview.js, which hands it the target. -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-simple-add.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview-simple-add.js') ?>"></script>

<!-- A translation's markers (QSTextMarkers): the snippet preview and the update of a saved text read them; preview.js injects the same file into the frame, whose inline edit writes a sentence back with them -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/core/text-markers.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/text-markers.js') ?>"></script>

<!-- Preview JavaScript (Main) -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/preview.js') ?>"></script>
