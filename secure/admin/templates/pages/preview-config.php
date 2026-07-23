<?php
/**
 * Preview Page Configuration
 * Generates the PreviewConfig JavaScript object for preview.js
 *
 * This file is included by preview.php to separate config from template
 */

// Beta.8 A2 — route resolvers sidecar exposed to PreviewConfig so the
// editor knows which routes have a resolver (and what variables they
// expose) when building the emulation panel. Small payload — only
// routes WITH a resolver are present.
// C9/C5b — read from the EDITED project ($editProjectPath, computed by
// preview.php which includes this file), not the served one PROJECT_PATH
// is bound to. Defensive fallbacks in case of a different inclusion context.
require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';
$__previewRouteResolvers = loadResolversSidecar($editProjectPath ?? null);
if (!isset($editConfig)) {
    $editConfig = defined('CONFIG') ? CONFIG : [];
}

// AI tools mode workflow metadata. Loaded server-side (mirrors
// /admin/workflows pattern) with the same role-based filter applied.
require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
$__previewAiToolsWorkflows = [];
try {
    $__aiToolsManager = new WorkflowManager();
    $__aiToolsSpecs = $__aiToolsManager->listWorkflows();
    $__aiToolsAllowed = null;
    $__aiToolsToken = $router->getToken();
    if ($__aiToolsToken) {
        // Resolve the session access token to a user, then its effective
        // commands (C5/C5b — validateBearerToken checks the session store).
        $__aiToolsResolved = validateBearerToken('Bearer ' . $__aiToolsToken);
        if ($__aiToolsResolved['valid']) {
            $__aiToolsPerms = getTokenPermissions($__aiToolsResolved['user']);
            $__aiToolsAllowed = $__aiToolsPerms['commands'] ?? null;
        }
    }
    $__aiToolsCategoryOrder = ['creation', 'template', 'modification', 'content', 'style', 'advanced', 'wip'];
    $__aiToolsCategoryIndex = array_flip($__aiToolsCategoryOrder);
    foreach ($__aiToolsSpecs as $__aiToolsSpec) {
        if ($__aiToolsAllowed !== null) {
            $__aiToolsRequired = $__aiToolsSpec['relatedCommands'] ?? [];
            if (!empty($__aiToolsSpec['steps'])) {
                foreach ($__aiToolsSpec['steps'] as $__aiToolsStep) {
                    if (!empty($__aiToolsStep['command'])) {
                        $__aiToolsRequired[] = $__aiToolsStep['command'];
                    }
                }
            }
            $__aiToolsSkip = false;
            foreach (array_unique($__aiToolsRequired) as $__aiToolsCmd) {
                if (!in_array($__aiToolsCmd, $__aiToolsAllowed, true)) {
                    $__aiToolsSkip = true;
                    break;
                }
            }
            if ($__aiToolsSkip) continue;
        }
        $__aiToolsMeta = $__aiToolsSpec['meta'] ?? [];
        $__aiToolsHasSteps = !empty($__aiToolsSpec['steps']);
        $__aiToolsHasPrompt = !empty($__aiToolsSpec['promptTemplate']);
        $__aiToolsCategory = $__aiToolsMeta['category'] ?? 'other';
        $__aiToolsDifficulty = $__aiToolsMeta['difficulty'] ?? 'intermediate';
        $__previewAiToolsWorkflows[] = [
            'id'              => $__aiToolsSpec['id'],
            'icon'            => $__aiToolsMeta['icon'] ?? '📋',
            'title'           => $__aiToolsMeta['name']
                                  ?? __workflow($__aiToolsSpec, $__aiToolsMeta['titleKey'] ?? '', $__aiToolsSpec['id']),
            'description'     => $__aiToolsMeta['description']
                                  ?? __workflow($__aiToolsSpec, $__aiToolsMeta['descriptionKey'] ?? '', ''),
            'category'        => $__aiToolsCategory,
            'categoryLabel'   => __admin('ai.categories.' . $__aiToolsCategory . '.title', ucfirst($__aiToolsCategory)),
            'tags'            => array_values($__aiToolsMeta['tags'] ?? []),
            'difficulty'      => $__aiToolsDifficulty,
            'difficultyLabel' => __admin('ai.difficulty.' . $__aiToolsDifficulty, ucfirst($__aiToolsDifficulty)),
            'source'          => $__aiToolsSpec['_source'] ?? 'core',
            'isAI'            => $__aiToolsHasPrompt,
            'isManual'        => $__aiToolsHasSteps && !$__aiToolsHasPrompt,
        ];
    }
    usort($__previewAiToolsWorkflows, function ($a, $b) use ($__aiToolsCategoryIndex) {
        $aIdx = $__aiToolsCategoryIndex[$a['category']] ?? 999;
        $bIdx = $__aiToolsCategoryIndex[$b['category']] ?? 999;
        if ($aIdx !== $bIdx) return $aIdx - $bIdx;
        return strcasecmp($a['title'], $b['title']);
    });
} catch (\Throwable $__aiToolsErr) {
    error_log('[PreviewAiTools] Failed to load workflow list: ' . $__aiToolsErr->getMessage());
}

// Beta.8 A2 Track 2d — per-route schema-driven default values for the
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

// C8 (8.W) — the served project this panel works with (getCurrentProject = target.php,
// same as the editor marker + the preview iframe, so edit and preview never diverge).
// The visual-editor STYLE panels (preview-style-*/transition) hand-build
// fetch(managementUrl + cmd) for project-scoped commands, so the C7 '/management/p/<id>/'
// marker is baked into managementUrl here (every command reached this way is
// project-scoped). Empty marker → falls back to '/management/' when target.php is
// missing. (Cleanup chip tracks routing those calls through QuickSiteAdmin.apiRequest.)
$__previewProject = $router->getCurrentProject();
$__previewMgmtBase = rtrim(BASE_URL, '/') . '/management/'
    . ($__previewProject !== null && $__previewProject !== '' ? 'p/' . rawurlencode($__previewProject) . '/' : '');
?>
<!-- Preview Configuration (needed before preview.js) -->
<script>
window.PreviewConfig = {
    // URLs and settings
    baseUrl: <?= json_encode(rtrim(BASE_URL, '/')) ?>,
    // C9/C5b — the base the preview IFRAME navigates under: the site root when
    // editing the SERVED project, the surface-B '/p/<id>' view otherwise.
    // buildUrl() must use THIS (not baseUrl) or picking a page in the toolbar
    // silently navigates the iframe back to the main project.
    previewBase: <?= json_encode(
        (isset($previewAtRoot) && !$previewAtRoot && isset($previewProject))
            ? rtrim(BASE_URL, '/') . '/p/' . rawurlencode($previewProject)
            : rtrim(BASE_URL, '/')
    ) ?>,
    adminUrl: <?= json_encode($router->url('')) ?>,
    managementUrl: <?= json_encode($__previewMgmtBase) ?>,
    currentProject: <?= json_encode($__previewProject) ?>,

    // Beta.8 A2 — per-route resolver sidecar (only routes with a resolver).
    // Used by the editor's emulation panel to know which variables exist
    // per page (resolver.expose keys → editable inputs).
    routeResolvers: <?= json_encode($__previewRouteResolvers ?: new stdClass()) ?>,

    // Beta.8 A2 Track 2d — schema-driven sample defaults for resolver
    // variables. Used by the emulation panel to pre-fill first-time
    // inputs with realistic placeholders derived from the endpoint's
    // responseSchema. Empty per-route map when the endpoint has no
    // schema declared in /admin/apis.
    routeResolverDefaults: <?= json_encode($__previewResolverDefaults ?: new stdClass()) ?>,

    // AI tools panel — pre-resolved workflow metadata. Role-filtered
    // (matches /admin/workflows). One entry per visible workflow.
    aiToolsWorkflows: <?= json_encode($__previewAiToolsWorkflows) ?>,
    projectStyleUrl: <?= json_encode(rtrim(BASE_URL, '/') . '/style/style.css') ?>,
    authToken: <?= json_encode($router->getToken()) ?>,
    structureUrl: <?= json_encode($router->url('structure')) ?>,
    multilingual: <?= json_encode($editConfig['MULTILINGUAL_SUPPORT'] ?? false) ?>,
    defaultLang: <?= json_encode($editConfig['LANGUAGE_DEFAULT'] ?? 'en') ?>,
    
    // Theme mode
    themeModeEnabled: <?= json_encode($editConfig['THEME_MODE_ENABLED'] ?? false) ?>,
    themeDefault: <?= json_encode($editConfig['THEME_DEFAULT'] ?? 'light') ?>,
    themeUserToggle: <?= json_encode($editConfig['THEME_USER_TOGGLE_ENABLED'] ?? false) ?>,
    
    // Translations — always-needed keys (used by preview.js core and always-active panels).
    // Panel-specific keys are in i18nPanels below and merged on first tab activation.
    i18n: {
        // ── Common ──
        delete: <?= json_encode(__admin('common.delete', 'Delete')) ?>,
        edit: <?= json_encode(__admin('common.edit', 'Edit')) ?>,
        error: <?= json_encode(__admin('common.error', 'Error')) ?>,
        loading: <?= json_encode(__admin('common.loading', 'Loading')) ?>,
        noResults: <?= json_encode(__admin('common.noResults', 'No results')) ?>,
        remove: <?= json_encode(__admin('common.remove', 'Remove')) ?>,
        save: <?= json_encode(__admin('common.save', 'Save')) ?>,
        saveFailed: <?= json_encode(__admin('common.saveFailed', 'Save failed')) ?>,
        saving: <?= json_encode(__admin('common.saving', 'Saving')) ?>,
        search: <?= json_encode(__admin('common.search', 'Search')) ?>,

        // ── Mode toolbar ──
        modeSelect: <?= json_encode(__admin('preview.modeSelect', 'Select')) ?>,
        modeDrag: <?= json_encode(__admin('preview.modeDrag', 'Drag')) ?>,
        modeStyle: <?= json_encode(__admin('preview.modeStyle', 'Style')) ?>,
        modeAdd: <?= json_encode(__admin('preview.modeAdd', 'Add')) ?>,
        modeText: <?= json_encode(__admin('preview.modeText', 'Text')) ?>,
        modeJs: <?= json_encode(__admin('preview.modeJs', 'JS')) ?>,
        miniplayer: <?= json_encode(__admin('preview.miniplayer', 'Miniplayer')) ?>,

        // ── Node / element management ──
        autoGenerated: <?= json_encode(__admin('preview.autoGenerated', 'Auto-generated')) ?>,
        confirmDeleteNode: <?= json_encode(__admin('preview.confirmDeleteNode', 'Delete this element?')) ?>,
        deleteLastTextWarning: <?= json_encode(__admin('preview.deleteLastTextWarning', "This is the last element containing text content. Deleting it will create a text-less component (styling shape only). The resulting element must have dimensions set via CSS to remain visible.\n\nContinue?")) ?>,
        editInTranslations: <?= json_encode(__admin('preview.editInTranslations', 'Edit in Translations')) ?>,
        elementAdded: <?= json_encode(__admin('preview.elementAdded', 'Element added')) ?>,
        elementUpdated: <?= json_encode(__admin('preview.elementUpdated', 'Element updated')) ?>,
        nodeDeleted: <?= json_encode(__admin('preview.nodeDeleted', 'Element deleted')) ?>,
        textNodeDeleted: <?= json_encode(__admin('preview.textNodeDeleted', 'Text node deleted')) ?>,
        confirmDeleteTextNode: <?= json_encode(__admin('preview.confirmDeleteTextNode', 'Delete this text node?')) ?>,

        // ── Text editing ──
        textContent: <?= json_encode(__admin('preview.textContent', 'Text Content')) ?>,
        textHintRaw: <?= json_encode(__admin('preview.textHintRaw', 'Enter raw text. This will be displayed as-is.')) ?>,
        textHintTranslation: <?= json_encode(__admin('preview.textHintTranslation', 'Enter a translation key. Value will be looked up in translations.')) ?>,
        textHintVariable: <?= json_encode(__admin('preview.textHintVariable', 'Enter variable name (without {{ }}). Value will be passed from parent.')) ?>,
        textModeNone: <?= json_encode(__admin('preview.textModeNone', 'None (container only)')) ?>,
        textModeRaw: <?= json_encode(__admin('preview.textModeRaw', 'Raw Text')) ?>,
        textModeTranslation: <?= json_encode(__admin('preview.textModeTranslation', 'Translation Key')) ?>,
        textModeVariable: <?= json_encode(__admin('preview.textModeVariable', 'Variable {{...}}')) ?>,
        textPlaceholderRaw: <?= json_encode(__admin('preview.textPlaceholderRaw', 'e.g. Hello World')) ?>,
        textPlaceholderTranslation: <?= json_encode(__admin('preview.textPlaceholderTranslation', 'e.g. component.myButton.label')) ?>,
        textPlaceholderVariable: <?= json_encode(__admin('preview.textPlaceholderVariable', 'e.g. buttonText')) ?>,
        textSaved: <?= json_encode(__admin('preview.textSaved', 'Text saved')) ?>,
        textKeyAutoGenerated: <?= json_encode(__admin('preview.textKeyAutoGenerated', 'Auto-generated text key')) ?>,

        // ── Component management ──
        componentAdded: <?= json_encode(__admin('preview.componentAdded', 'Component added')) ?>,
        componentUpdated: <?= json_encode(__admin('preview.componentUpdated', 'Component updated')) ?>,
        componentCreated: <?= json_encode(__admin('preview.componentCreated', 'Component created')) ?>,
        componentCreateFailed: <?= json_encode(__admin('preview.componentCreateFailed', 'Failed to create component')) ?>,
        componentNamePrompt: <?= json_encode(__admin('preview.componentNamePrompt', 'Component name (letters, numbers, hyphens):')) ?>,
        componentNameInvalid: <?= json_encode(__admin('preview.componentNameInvalid', 'Invalid name. Use only letters, numbers, hyphens, and underscores.')) ?>,
        componentNameExists: <?= json_encode(__admin('preview.componentNameExists', 'A component with this name already exists.')) ?>,
        componentNameRequired: <?= json_encode(__admin('preview.componentNameRequired', 'Component name is required')) ?>,
        componentSaved: <?= json_encode(__admin('preview.componentSaved', 'Component saved successfully!')) ?>,
        componentWarning: <?= json_encode(__admin('preview.componentWarning', 'Editing component template - changes affect all :count usage(s)')) ?>,
        deleteComponent: <?= json_encode(__admin('preview.deleteComponent', 'Delete')) ?>,
        confirmDeleteComponent: <?= json_encode(__admin('preview.confirmDeleteComponent', 'Delete component "%s"? This cannot be undone.')) ?>,
        componentDeleted: <?= json_encode(__admin('preview.componentDeleted', 'Component deleted')) ?>,
        componentDeleteFailed: <?= json_encode(__admin('preview.componentDeleteFailed', 'Failed to delete component')) ?>,
        searchComponents: <?= json_encode(__admin('preview.searchComponents', 'Search components...')) ?>,
        noComponentsFound: <?= json_encode(__admin('preview.noComponentsFound', 'No components found')) ?>,
        errorLoadingComponents: <?= json_encode(__admin('preview.errorLoadingComponents', 'Error loading components')) ?>,

        // ── Snippets ──
        selectSnippet: <?= json_encode(__admin('preview.selectSnippet', 'Please select a snippet')) ?>,
        snippetAdded: <?= json_encode(__admin('preview.snippetAdded', 'Snippet added')) ?>,
        noSnippetsFound: <?= json_encode(__admin('preview.noSnippetsFound', 'No snippets found')) ?>,
        errorLoadingSnippets: <?= json_encode(__admin('preview.errorLoadingSnippets', 'Error loading snippets')) ?>,
        allSnippets: <?= json_encode(__admin('preview.allSnippets', 'All')) ?>,
        noSnippetsInCategory: <?= json_encode(__admin('preview.noSnippetsInCategory', 'No snippets in this category')) ?>,
        noSnippetSelected: <?= json_encode(__admin('preview.noSnippetSelected', 'No snippet selected')) ?>,
        confirmDeleteSnippet: <?= json_encode(__admin('preview.confirmDeleteSnippet', 'Delete snippet "%s"? This action cannot be undone.')) ?>,
        snippetDeleted: <?= json_encode(__admin('preview.snippetDeleted', 'Snippet deleted successfully')) ?>,
        deleteSnippetFailed: <?= json_encode(__admin('preview.deleteSnippetFailed', 'Failed to delete snippet')) ?>,
        cannotDeleteCore: <?= json_encode(__admin('preview.cannotDeleteCore', 'Cannot delete core snippets')) ?>,
        snippetNameRequired: <?= json_encode(__admin('preview.snippetNameRequired', 'Snippet name is required')) ?>,
        snippetIdRequired: <?= json_encode(__admin('preview.snippetIdRequired', 'Snippet ID is required')) ?>,
        snippetIdInvalid: <?= json_encode(__admin('preview.snippetIdInvalid', 'ID must start with a letter and contain only letters, numbers, dashes')) ?>,
        snippetSaved: <?= json_encode(__admin('preview.snippetSaved', 'Snippet saved successfully!')) ?>,
        searchSnippets: <?= json_encode(__admin('preview.searchSnippets', 'Search snippets...')) ?>,

        // ── Style editor (contextual panel — always visible in style mode) ──
        addStyle: <?= json_encode(__admin('preview.addStyle', 'Add Style')) ?>,
        clickToPickColor: <?= json_encode(__admin('preview.clickToPickColor', 'Click to pick color')) ?>,
        colorValue: <?= json_encode(__admin('preview.colorValue', '#000000 or rgba(...)')) ?>,
        copyStyleFrom: <?= json_encode(__admin('preview.copyStyleFrom', 'Copy From…')) ?>,
        copyStyleFromLabel: <?= json_encode(__admin('preview.copyStyleFromLabel', 'Copy styles from:')) ?>,
        failedToLoadStyles: <?= json_encode(__admin('preview.failedToLoadStyles', 'Failed to load styles')) ?>,
        noBaseStyles: <?= json_encode(__admin('preview.noBaseStyles', 'No base styles')) ?>,
        noVariablesAvailable: <?= json_encode(__admin('preview.noVariablesAvailable', 'No variables available')) ?>,
        openTransformEditor: <?= json_encode(__admin('preview.openTransformEditor', 'Open Transform Editor')) ?>,
        propertyAdded: <?= json_encode(__admin('preview.propertyAdded', 'Property added')) ?>,
        propertyAlreadyExists: <?= json_encode(__admin('preview.propertyAlreadyExists', 'Property already exists')) ?>,
        propertyDeleted: <?= json_encode(__admin('preview.propertyDeleted', 'Property deleted')) ?>,
        searchProperties: <?= json_encode(__admin('preview.searchProperties', 'Search properties...')) ?>,
        selectProperty: <?= json_encode(__admin('preview.selectProperty', 'Select property...')) ?>,
        selectVariable: <?= json_encode(__admin('preview.selectVariable', 'Select variable')) ?>,
        state: <?= json_encode(__admin('preview.state', 'State')) ?>,
        style: <?= json_encode(__admin('preview.style', 'Style')) ?>,
        stylesReset: <?= json_encode(__admin('preview.stylesReset', 'Styles reset')) ?>,
        stylesSaved: <?= json_encode(__admin('preview.stylesSaved', 'Styles saved')) ?>,
        transformEmpty: <?= json_encode(__admin('preview.transformEmpty', 'No transform functions')) ?>,
        transformValue: <?= json_encode(__admin('preview.transformValue', 'translateX(10px) rotate(5deg)')) ?>,
        useCustomProperty: <?= json_encode(__admin('preview.useCustomProperty', 'Use custom:')) ?>,
        varReplacementWarning: <?= json_encode(__admin('preview.varReplacementWarning', 'CSS variables will be replaced with actual values')) ?>,

        // ── Transition editor (floating panel, on-demand) ──
        addPropertyFailed: <?= json_encode(__admin('preview.addPropertyFailed', 'Failed to add property')) ?>,
        animationAdded: <?= json_encode(__admin('preview.animationAdded', 'Animation added')) ?>,
        animationRemoved: <?= json_encode(__admin('preview.animationRemoved', 'Animation removed')) ?>,
        deletePropertyFailed: <?= json_encode(__admin('preview.deletePropertyFailed', 'Failed to delete property')) ?>,
        errorAddingAnimation: <?= json_encode(__admin('preview.errorAddingAnimation', 'Error adding animation')) ?>,
        errorLoadingKeyframes: <?= json_encode(__admin('preview.errorLoadingKeyframes', 'Error loading keyframes')) ?>,
        errorRemovingAnimation: <?= json_encode(__admin('preview.errorRemovingAnimation', 'Error removing animation')) ?>,
        keyframeNotFound: <?= json_encode(__admin('preview.keyframeNotFound', 'Keyframe not found')) ?>,
        keyframePropertyValue: <?= json_encode(__admin('preview.keyframePropertyValue', 'value')) ?>,
        loadTransitionFailed: <?= json_encode(__admin('preview.loadTransitionFailed', 'Failed to load transition')) ?>,
        noAnimationToPreview: <?= json_encode(__admin('preview.noAnimationToPreview', 'No animation to preview')) ?>,
        noElementsFound: <?= json_encode(__admin('preview.noElementsFound', 'No elements found')) ?>,
        noKeyframesFound: <?= json_encode(__admin('preview.noKeyframesFound', 'No keyframes found')) ?>,
        noPropertiesToTransition: <?= json_encode(__admin('preview.noPropertiesToTransition', 'No properties to transition')) ?>,
        noTriggerStyles: <?= json_encode(__admin('preview.noTriggerStyles', 'No trigger styles')) ?>,
        noTriggersWithoutTransition: <?= json_encode(__admin('preview.noTriggersWithoutTransition', 'No triggers without transition')) ?>,
        playsOn: <?= json_encode(__admin('preview.playsOn', 'plays on')) ?>,
        previewingHover: <?= json_encode(__admin('preview.previewingHover', 'Previewing hover')) ?>,
        previewNotAvailable: <?= json_encode(__admin('preview.previewNotAvailable', 'Preview not available')) ?>,
        saveTransitionFailed: <?= json_encode(__admin('preview.saveTransitionFailed', 'Failed to save transition')) ?>,
        transitionSaved: <?= json_encode(__admin('preview.transitionSaved', 'Transition saved')) ?>,

        // ── JS Interactions panel (on-demand) ──
        addInteraction: <?= json_encode(__admin('preview.addInteraction', 'Add Interaction')) ?>,
        advanced: <?= json_encode(__admin('preview.advanced', 'Advanced')) ?>,
        confirmDeleteInteraction: <?= json_encode(__admin('preview.confirmDeleteInteraction', 'Delete this interaction?')) ?>,
        containerSelector: <?= json_encode(__admin('preview.containerSelector', 'Container selector')) ?>,
        containerSelectorHint: <?= json_encode(__admin('preview.containerSelectorHint', 'Container element whose first child is the template. Each array item clones it.')) ?>,
        editInteraction: <?= json_encode(__admin('preview.editInteraction', 'Edit Interaction')) ?>,
        emptyText: <?= json_encode(__admin('preview.emptyText', 'Empty text:')) ?>,
        emptyTextPlaceholder: <?= json_encode(__admin('preview.emptyTextPlaceholder', 'e.g. No items found  (optional)')) ?>,
        emptyTextHint: <?= json_encode(__admin('preview.emptyTextHint', 'Text shown when the array is empty')) ?>,
        functionGroupCore: <?= json_encode(__admin('preview.functionGroupCore', 'Core Functions')) ?>,
        functionGroupCustom: <?= json_encode(__admin('preview.functionGroupCustom', 'Custom Functions')) ?>,
        functionGroupOther: <?= json_encode(__admin('preview.functionGroupOther', 'Other')) ?>,
        interactionAdded: <?= json_encode(__admin('preview.interactionAdded', 'Interaction added')) ?>,
        interactionDeleted: <?= json_encode(__admin('preview.interactionDeleted', 'Interaction deleted')) ?>,
        interactionUpdated: <?= json_encode(__admin('preview.interactionUpdated', 'Interaction updated')) ?>,
        interactionNotFound: <?= json_encode(__admin('preview.interactionNotFound', 'Interaction not found')) ?>,
        listModeHint: <?= json_encode(__admin('preview.listModeHint', "Uses data-bind attributes inside the container's first child as item template.")) ?>,
        newInteraction: <?= json_encode(__admin('preview.newInteraction', 'New Interaction')) ?>,
        noElementSelected: <?= json_encode(__admin('preview.noElementSelected', 'No element selected')) ?>,
        noInteractionData: <?= json_encode(__admin('preview.noInteractionData', 'No interaction data available')) ?>,
        noInteractions: <?= json_encode(__admin('preview.noInteractions', 'No interactions')) ?>,
        noParams: <?= json_encode(__admin('preview.noParams', 'No parameters')) ?>,
        removeFunction: <?= json_encode(__admin('preview.removeFunction', 'Remove function')) ?>,
        responseField: <?= json_encode(__admin('preview.responseField', 'Response field')) ?>,
        responseMapping: <?= json_encode(__admin('preview.responseMapping', 'Response Mapping')) ?>,
        responseMappingHint: <?= json_encode(__admin('preview.responseMappingHint', 'Map API response fields to page elements')) ?>,
        addMapping: <?= json_encode(__admin('preview.addMapping', 'Add Mapping')) ?>,
        noResponseSchema: <?= json_encode(__admin('preview.noResponseSchema', 'No response schema defined for this endpoint')) ?>,
        paramName: <?= json_encode(__admin('preview.paramName', 'Parameter name')) ?>,
        paramRequired: <?= json_encode(__admin('preview.paramRequired', 'Required')) ?>,
        paramValue: <?= json_encode(__admin('preview.paramValue', 'Value')) ?>,
        searchClass: <?= json_encode(__admin('preview.searchClass', 'Search class')) ?>,
        selectApi: <?= json_encode(__admin('preview.selectApi', 'Select API')) ?>,
        selectComponent: <?= json_encode(__admin('preview.selectComponent', 'Select Component')) ?>,
        selectComponentFirst: <?= json_encode(__admin('preview.selectComponentFirst', 'Select a component first')) ?>,
        selectComponentPlaceholder: <?= json_encode(__admin('preview.selectComponentPlaceholder', '-- Select component --')) ?>,
        selectEndpoint: <?= json_encode(__admin('preview.selectEndpoint', 'Select endpoint')) ?>,
        selectEvent: <?= json_encode(__admin('preview.selectEvent', 'Select event')) ?>,
        selectEventAndFunction: <?= json_encode(__admin('preview.selectEventAndFunction', 'Please select an event and function')) ?>,
        selectEventApiEndpoint: <?= json_encode(__admin('preview.selectEventApiEndpoint', 'Please select an event, API, and endpoint')) ?>,
        selectFunction: <?= json_encode(__admin('preview.selectFunction', 'Select function')) ?>,
        // ── Beta.6: bucketed events picker + function details + input wizard ──
        eventsCommonFor: <?= json_encode(__admin('preview.eventsCommonFor', 'Common for')) ?>,
        eventsLessCommon: <?= json_encode(__admin('preview.eventsLessCommon', 'Less common')) ?>,
        eventsAdvanced: <?= json_encode(__admin('preview.eventsAdvanced', 'Advanced')) ?>,
        functionExample: <?= json_encode(__admin('preview.functionExample', 'Example')) ?>,
        nameRequiredForInput: <?= json_encode(__admin('preview.nameRequiredForInput', 'A name is required so the field is submitted with the form.')) ?>,
        invalidId: <?= json_encode(__admin('preview.invalidId', 'Invalid ID — start with a letter or underscore; letters, digits, hyphens and underscores only (no spaces).')) ?>,
        optional: <?= json_encode(__admin('preview.optional', 'Optional')) ?>,
        eventTooltips: <?= json_encode(AdminTranslation::getInstance()->getRaw('preview.eventTooltips') ?: new stdClass()) ?>,
        selectNodeFirst: <?= json_encode(__admin('preview.selectNodeFirst', 'Select an element first')) ?>,
        selectorOrThis: <?= json_encode(__admin('preview.selectorOrThis', 'selector or this')) ?>,
        targetAttribute: <?= json_encode(__admin('preview.targetAttribute', 'Attribute (optional)')) ?>,
        targetSelector: <?= json_encode(__admin('preview.targetSelector', 'Target selector')) ?>,
        thisElement: <?= json_encode(__admin('preview.thisElement', 'this element')) ?>,

        // ── State stores panel (preview-js-interactions.js) ──
        noStateStores: <?= json_encode(__admin('preview.noStateStores', 'No state stores yet.')) ?>,
        stateStoreSaved: <?= json_encode(__admin('preview.stateStoreSaved', 'State store saved')) ?>,
        stateStoreDeleted: <?= json_encode(__admin('preview.stateStoreDeleted', 'State store deleted')) ?>,
        confirmDeleteStateStore: <?= json_encode(__admin('preview.confirmDeleteStateStore', 'Delete state store "%s"?')) ?>,
        stateStoreInvalidId: <?= json_encode(__admin('preview.stateStoreInvalidId', 'Invalid store id (use letters, digits, _ or -)')) ?>,
        selectApiEndpoint: <?= json_encode(__admin('preview.selectApiEndpoint', 'Select an API and endpoint')) ?>,
        stateStoreNoFields: <?= json_encode(__admin('preview.stateStoreNoFields', 'Add at least one field')) ?>,
        stateStoreIdExists: <?= json_encode(__admin('preview.stateStoreIdExists', 'A store named "%s" already exists')) ?>,
        stateStoreFieldName: <?= json_encode(__admin('preview.stateStoreFieldName', 'field name')) ?>,
        stateStoreInitPlaceholder: <?= json_encode(__admin('preview.stateStoreInitPlaceholder', 'literal, query:x, localStorage:x')) ?>,
        stateStoreDefaultPlaceholder: <?= json_encode(__admin('preview.stateStoreDefaultPlaceholder', 'default')) ?>,
        stateStoreFromPlaceholder: <?= json_encode(__admin('preview.stateStoreFromPlaceholder', 'response path, e.g. data.items')) ?>,
        stateStoreAppend: <?= json_encode(__admin('preview.stateStoreAppend', 'append (grow list)')) ?>,
        stateStoreNonePage: <?= json_encode(__admin('preview.stateStoreNonePage', 'No stores on this page')) ?>,
        stateStoreDirRequest: <?= json_encode(__admin('preview.stateStoreDirRequest', 'request (sent)')) ?>,
        stateStoreDirResponse: <?= json_encode(__admin('preview.stateStoreDirResponse', 'response (received)')) ?>,
        stateStoreDirBoth: <?= json_encode(__admin('preview.stateStoreDirBoth', 'both (sent + received)')) ?>,
        selectApiPlaceholder: <?= json_encode(__admin('preview.selectApiPlaceholder', '-- Select API --')) ?>,
        selectEndpointPlaceholder: <?= json_encode(__admin('preview.selectEndpointPlaceholder', '-- Select endpoint --')) ?>,

        // ── Drag tool ──
        dragLock: <?= json_encode(__admin('preview.dragLock', 'Lock')) ?>,
        dragUndo: <?= json_encode(__admin('preview.dragUndo', 'Undo')) ?>,
        dragRedo: <?= json_encode(__admin('preview.dragRedo', 'Redo')) ?>,
        dragSelectHint: <?= json_encode(__admin('preview.dragSelectHint', 'Click to select, arrows to navigate, hold to drag')) ?>,
        dragLockedHint: <?= json_encode(__admin('preview.dragLockedHint', 'Element locked — drag to move, click to re-select')) ?>,
        dragToReorder: <?= json_encode(__admin('preview.dragToReorder', 'Drag to reorder')) ?>,
        elementMoved: <?= json_encode(__admin('preview.elementMoved', 'Element moved')) ?>,
        elementMoveUndone: <?= json_encode(__admin('preview.elementMoveUndone', 'Move undone')) ?>,
        elementMoveRedone: <?= json_encode(__admin('preview.elementMoveRedone', 'Move redone')) ?>,

        // ── Variables panel (component editing) ──
        variables: <?= json_encode(__admin('preview.variables', 'Variables')) ?>,
        variablesSaved: <?= json_encode(__admin('preview.variablesSaved', 'Variable saved')) ?>,
        variablesValueRequired: <?= json_encode(__admin('preview.variablesValueRequired', 'Value cannot be empty')) ?>,
        variablesNodeInfo: <?= json_encode(__admin('preview.variablesNodeInfo', 'Showing: %s')) ?>,
        variablesClickToSelect: <?= json_encode(__admin('preview.variablesClickToSelect', 'Click to select this node')) ?>,
        variablesSectionText: <?= json_encode(__admin('preview.variablesSectionText', 'Text Variables')) ?>,
        variablesSectionParams: <?= json_encode(__admin('preview.variablesSectionParams', 'Parameters')) ?>,
        variablesTypeTranslation: <?= json_encode(__admin('preview.variablesTypeTranslation', 'Translation Key')) ?>,
        variablesTypeVariable: <?= json_encode(__admin('preview.variablesTypeVariable', 'Variable {{...}}')) ?>,
        variablesTypeRaw: <?= json_encode(__admin('preview.variablesTypeRaw', 'Raw Text')) ?>,
        variablesTypeString: <?= json_encode(__admin('preview.variablesTypeString', 'Literal String')) ?>,
        variablesPlaceholderTranslation: <?= json_encode(__admin('preview.variablesPlaceholderTranslation', 'Search or type a translation key')) ?>,
        variablesCreateKey: <?= json_encode(__admin('preview.variablesCreateKey', 'Create')) ?>,
        variablesCreateKeyValue: <?= json_encode(__admin('preview.variablesCreateKeyValue', 'Value')) ?>,
        variablesKeyCreated: <?= json_encode(__admin('preview.variablesKeyCreated', 'Translation key created')) ?>,
        variablesKeyCreateError: <?= json_encode(__admin('preview.variablesKeyCreateError', 'Failed to create translation key')) ?>,
        variablesPlaceholderVariable: <?= json_encode(__admin('preview.variablesPlaceholderVariable', 'e.g. SUBTITLE')) ?>,
        variablesPlaceholderRaw: <?= json_encode(__admin('preview.variablesPlaceholderRaw', 'e.g. Raw Value')) ?>,
        variablesPlaceholderParamString: <?= json_encode(__admin('preview.variablesPlaceholderParamString', 'e.g. /page/link')) ?>,
        variablesHintVariable: <?= json_encode(__admin('preview.variablesHintVariable', '{{}} added automatically — use CAPS by convention')) ?>,
        variablesHintRaw: <?= json_encode(__admin('preview.variablesHintRaw', '__RAW__ added automatically — same in all languages')) ?>,
        variablesTypeEnum: <?= json_encode(__admin('preview.variablesTypeEnum', 'Enum')) ?>,
        variablesHintEnum: <?= json_encode(__admin('preview.variablesHintEnum', 'Resolved from enum definition at render time')) ?>,
        variablesSelectEnum: <?= json_encode(__admin('preview.variablesSelectEnum', 'Select an enum variable')) ?>,

        // ── Enums panel (component editing) ──
        enums: <?= json_encode(__admin('preview.enums', 'Enums')) ?>,
        enumSource: <?= json_encode(__admin('preview.enumSource', 'Source key')) ?>,
        enumDefault: <?= json_encode(__admin('preview.enumDefault', 'Default')) ?>,
        enumKey: <?= json_encode(__admin('preview.enumKey', 'Key')) ?>,
        enumValue: <?= json_encode(__admin('preview.enumValue', 'Value')) ?>,
        enumKeyPlaceholder: <?= json_encode(__admin('preview.enumKeyPlaceholder', 'key')) ?>,
        enumValuePlaceholder: <?= json_encode(__admin('preview.enumValuePlaceholder', 'value')) ?>,
        enumAddOption: <?= json_encode(__admin('preview.enumAddOption', 'Add Option')) ?>,
        enumSourceRequired: <?= json_encode(__admin('preview.enumSourceRequired', 'Source key is required')) ?>,
        enumNeedsOptions: <?= json_encode(__admin('preview.enumNeedsOptions', 'At least one option is required')) ?>,
        enumSaved: <?= json_encode(__admin('preview.enumSaved', 'Enum saved')) ?>,
        enumDeleted: <?= json_encode(__admin('preview.enumDeleted', 'Enum deleted')) ?>,
        enumCssStubsCreated: <?= json_encode(__admin('preview.enumCssStubsCreated', 'CSS stubs created: %s')) ?>,
        emulation: <?= json_encode(__admin('preview.emulation', 'Variable Emulation')) ?>,
        noVariablesToEmulate: <?= json_encode(__admin('preview.noVariablesToEmulate', 'No variables found in this component')) ?>,
        emulationApply: <?= json_encode(__admin('preview.emulationApply', 'Apply Preview')) ?>,
        emulationReset: <?= json_encode(__admin('preview.emulationReset', 'Reset All')) ?>,
        emulationResetField: <?= json_encode(__admin('preview.emulationResetField', 'Reset this field')) ?>,
        emulationApplied: <?= json_encode(__admin('preview.emulationApplied', 'Emulation applied')) ?>,
        enumConfirmDelete: <?= json_encode(__admin('preview.enumConfirmDelete', 'Delete enum "%s"?')) ?>,
        enumRenameTip: <?= json_encode(__admin('preview.enumRenameTip', 'Rename')) ?>,
        enumNamePlaceholder: <?= json_encode(__admin('preview.enumNamePlaceholder', 'enum_name')) ?>,
        enumNameRequired: <?= json_encode(__admin('preview.enumNameRequired', 'Enum name is required')) ?>,
        enumNameDuplicate: <?= json_encode(__admin('preview.enumNameDuplicate', 'Enum "%s" already exists')) ?>,
        enumValueType: <?= json_encode(__admin('preview.enumValueType', 'Value type')) ?>,
        enumType: <?= json_encode(__admin('preview.enumType', 'Type')) ?>,
        enumTypeDefault: <?= json_encode(__admin('preview.enumTypeDefault', '(default)')) ?>,
        enumTypeLiteral: <?= json_encode(__admin('preview.enumTypeLiteral', 'Literal')) ?>,
        enumTypeOverrideTip: <?= json_encode(__admin('preview.enumTypeOverrideTip', 'Click to override type for this row')) ?>,

        // ── Tag dropdown (add element) ──
        starTag: <?= json_encode(__admin('preview.starTag', 'Add to favorites')) ?>,
        unstarTag: <?= json_encode(__admin('preview.unstarTag', 'Remove from favorites')) ?>,
        requiresParams: <?= json_encode(__admin('preview.requiresParams', 'Requires additional parameters')) ?>,
        noPropertiesFound: <?= json_encode(__admin('preview.noPropertiesFound', 'No properties found')) ?>,
        noComponents: <?= json_encode(__admin('preview.noComponents', 'No components')) ?>
    },

    // Panel-specific i18n keys, merged into PreviewConfig.i18n on first tab activation.
    // Keys here are ONLY used by the named panel and not by preview.js core.
    // Add ensureI18nPanel(tabName) call in preview.js initStyleTabs() to activate.
    i18nPanels: {

        // ── Theme tab (preview-style-theme.js) ──
        theme: {
            noChanges: <?= json_encode(__admin('preview.noChanges', 'No changes')) ?>,
            noColorVariables: <?= json_encode(__admin('preview.noColorVariables', 'No color variables')) ?>,
            noFontVariables: <?= json_encode(__admin('preview.noFontVariables', 'No font variables')) ?>,
            noSpacingVariables: <?= json_encode(__admin('preview.noSpacingVariables', 'No spacing variables')) ?>,
            themeLoadError: <?= json_encode(__admin('preview.themeLoadError', 'Failed to load theme')) ?>,
            themeReset: <?= json_encode(__admin('preview.themeReset', 'Theme reset')) ?>,
            themeSaved: <?= json_encode(__admin('preview.themeSaved', 'Theme saved')) ?>,
            themeSaveError: <?= json_encode(__admin('preview.themeSaveError', 'Failed to save theme')) ?>,
            variableCollision: <?= json_encode(__admin('preview.variableCollision', 'Variable name collision!')) ?>,
            variableCollisionChild: <?= json_encode(__admin('preview.variableCollisionChild', 'Variable used by child component!')) ?>,
            variableCollisionChildHint: <?= json_encode(__admin('preview.variableCollisionChildHint', 'is used by:')) ?>,
            variableCollisionHint: <?= json_encode(__admin('preview.variableCollisionHint', 'These variables exist in both parent and child:')) ?>,
            variableDuplicate: <?= json_encode(__admin('preview.variableDuplicate', 'Duplicate variable!')) ?>,
            variableDuplicateHint: <?= json_encode(__admin('preview.variableDuplicateHint', 'already exists in this component.')) ?>,
            // A3 slice 6 — Theme quick-add
            themeAddVariable: <?= json_encode(__admin('preview.themeAddVariable', 'Add variable')) ?>,
            themeAddVariableNameLabel: <?= json_encode(__admin('preview.themeAddVariableNameLabel', 'Name')) ?>,
            themeAddVariableValueLabel: <?= json_encode(__admin('preview.themeAddVariableValueLabel', 'Value')) ?>,
            themeAddVariableSubmit: <?= json_encode(__admin('preview.themeAddVariableSubmit', 'Add')) ?>,
            themeAddVariableTargetLight: <?= json_encode(__admin('preview.themeAddVariableTargetLight', 'Adding to: Light scope')) ?>,
            themeAddVariableTargetDark: <?= json_encode(__admin('preview.themeAddVariableTargetDark', 'Adding to: Dark scope')) ?>,
            themeAddVariableAlsoOtherScope: <?= json_encode(__admin('preview.themeAddVariableAlsoOtherScope', 'Also add to the other scope')) ?>,
            themeAddVariableNameRequired: <?= json_encode(__admin('preview.themeAddVariableNameRequired', 'Variable name is required')) ?>,
            themeAddVariableValueRequired: <?= json_encode(__admin('preview.themeAddVariableValueRequired', 'Value is required')) ?>,
            themeAddVariableNameExists: <?= json_encode(__admin('preview.themeAddVariableNameExists', 'Variable {name} already exists')) ?>,
            themeAddVariableAdded: <?= json_encode(__admin('preview.themeAddVariableAdded', 'Variable {name} added')) ?>,
            themeAddVariableAddedBoth: <?= json_encode(__admin('preview.themeAddVariableAddedBoth', 'Variable {name} added to both scopes')) ?>,
            themeAddVariableAddError: <?= json_encode(__admin('preview.themeAddVariableAddError', 'Failed to add variable: {error}'))  ?>
        },

        // ── Selectors tab (preview-style-selectors.js) ──
        selectors: {
            createSelector: <?= json_encode(__admin('preview.createSelector', 'Create')) ?>,
            noSelectorsWithRules: <?= json_encode(__admin('preview.noSelectorsWithRules', 'No other selectors with styles found')) ?>,
            noStylesToCopy: <?= json_encode(__admin('preview.noStylesToCopy', 'No styles found on source selector')) ?>,
            searchSelectors: <?= json_encode(__admin('preview.searchSelectors', 'Search selectors…')) ?>,
            selectorCreated: <?= json_encode(__admin('preview.selectorCreated', 'Selector created')) ?>,
            selectorCreateError: <?= json_encode(__admin('preview.selectorCreateError', 'Failed to create selector')) ?>,
            selectorsLoadError: <?= json_encode(__admin('preview.selectorsLoadError', 'Failed to load selectors')) ?>
        },

        // ── Animations tab (preview-style-animations.js) ──
        animations: {
            addFrame: <?= json_encode(__admin('preview.addFrame', 'Add Frame')) ?>,
            addKeyframeProperty: <?= json_encode(__admin('preview.addKeyframeProperty', 'Add Property')) ?>,
            atLeastOneFrame: <?= json_encode(__admin('preview.atLeastOneFrame', 'At least one frame required')) ?>,
            cannotDeleteLastFrame: <?= json_encode(__admin('preview.cannotDeleteLastFrame', 'Cannot delete last frame')) ?>,
            cannotDeleteLastProperty: <?= json_encode(__admin('preview.cannotDeleteLastProperty', 'Cannot delete last property')) ?>,
            confirmDeleteKeyframe: <?= json_encode(__admin('preview.confirmDeleteKeyframe', 'Delete this keyframe?')) ?>,
            createKeyframe: <?= json_encode(__admin('preview.createKeyframe', 'Create Keyframe')) ?>,
            customProperty: <?= json_encode(__admin('preview.customProperty', 'Custom Property')) ?>,
            deleteFrame: <?= json_encode(__admin('preview.deleteFrame', 'Delete Frame')) ?>,
            deleteKeyframeFailed: <?= json_encode(__admin('preview.deleteKeyframeFailed', 'Failed to delete keyframe')) ?>,
            deleteKeyframeProperty: <?= json_encode(__admin('preview.deleteKeyframeProperty', 'Delete Property')) ?>,
            dragToMoveFrame: <?= json_encode(__admin('preview.dragToMoveFrame', 'Drag to move frame')) ?>,
            editKeyframe: <?= json_encode(__admin('preview.editKeyframe', 'Edit Keyframe')) ?>,
            enterFramePercent: <?= json_encode(__admin('preview.enterFramePercent', 'Enter frame percent (0-100)')) ?>,
            enterValue: <?= json_encode(__admin('preview.enterValue', 'Enter value')) ?>,
            frame: <?= json_encode(__admin('preview.frame', 'Frame')) ?>,
            frameExists: <?= json_encode(__admin('preview.frameExists', 'Frame already exists')) ?>,
            frames: <?= json_encode(__admin('preview.frames', 'frames')) ?>,
            invalidKeyframeName: <?= json_encode(__admin('preview.invalidKeyframeName', 'Invalid keyframe name')) ?>,
            invalidPercent: <?= json_encode(__admin('preview.invalidPercent', 'Invalid percent value')) ?>,
            keyframeCreated: <?= json_encode(__admin('preview.keyframeCreated', 'Keyframe created')) ?>,
            keyframeDeleted: <?= json_encode(__admin('preview.keyframeDeleted', 'Keyframe deleted')) ?>,
            keyframeExistsConfirm: <?= json_encode(__admin('preview.keyframeExistsConfirm', 'Keyframe exists. Overwrite?')) ?>,
            keyframeNameRequired: <?= json_encode(__admin('preview.keyframeNameRequired', 'Keyframe name required')) ?>,
            keyframePropertyName: <?= json_encode(__admin('preview.keyframePropertyName', 'property')) ?>,
            keyframeSaved: <?= json_encode(__admin('preview.keyframeSaved', 'Keyframe saved')) ?>,
            loadAnimationsFailed: <?= json_encode(__admin('preview.loadAnimationsFailed', 'Failed to load animations')) ?>,
            loadKeyframeFailed: <?= json_encode(__admin('preview.loadKeyframeFailed', 'Failed to load keyframe')) ?>,
            mayBeTriggeredVia: <?= json_encode(__admin('preview.mayBeTriggeredVia', 'May be triggered via')) ?>,
            noAnimationSelectors: <?= json_encode(__admin('preview.noAnimationSelectors', 'No animated selectors')) ?>,
            noDirectTrigger: <?= json_encode(__admin('preview.noDirectTrigger', 'No direct trigger')) ?>,
            noFramesToPreview: <?= json_encode(__admin('preview.noFramesToPreview', 'No frames to preview')) ?>,
            noTransitions: <?= json_encode(__admin('preview.noTransitions', 'No transitions')) ?>,
            previewAnimation: <?= json_encode(__admin('preview.previewAnimation', 'Preview Animation')) ?>,
            previewFailed: <?= json_encode(__admin('preview.previewFailed', 'Preview failed')) ?>,
            previewingAnimation: <?= json_encode(__admin('preview.previewingAnimation', 'Previewing animation')) ?>,
            saveKeyframeFailed: <?= json_encode(__admin('preview.saveKeyframeFailed', 'Failed to save keyframe')) ?>,
            states: <?= json_encode(__admin('preview.states', 'States')) ?>,
            toggleStates: <?= json_encode(__admin('preview.toggleStates', 'Toggle States')) ?>,
            // Motion Slice 2 — apply-keyframe-to-selector
            applyKeyframeToSelector: <?= json_encode(__admin('preview.applyKeyframeToSelector', 'Apply to selector…')) ?>,
            applyKeyframeTitle: <?= json_encode(__admin('preview.applyKeyframeTitle', 'Apply')) ?>,
            applyKeyframeTitleTo: <?= json_encode(__admin('preview.applyKeyframeTitleTo', 'to selector')) ?>,
            applyKeyframeAction: <?= json_encode(__admin('preview.applyKeyframeAction', 'Apply')) ?>,
            applyKeyframeNoSelectors: <?= json_encode(__admin('preview.applyKeyframeNoSelectors', 'No selectors yet — add one via the Selectors tab.')) ?>,
            applyKeyframeNoMatch: <?= json_encode(__admin('preview.applyKeyframeNoMatch', 'No selector matches.')) ?>,
            applyKeyframeAdded: <?= json_encode(__admin('preview.applyKeyframeAdded', '{name} applied to {selector}')) ?>,
            applyKeyframeError: <?= json_encode(__admin('preview.applyKeyframeError', 'Failed to apply: {error}')) ?>,
            // Motion Slice 2b — keyframe used-by + remove
            keyframeUsedByCount: <?= json_encode(__admin('preview.keyframeUsedByCount', 'used by {n}')) ?>,
            keyframeUsedByTitle: <?= json_encode(__admin('preview.keyframeUsedByTitle', 'Show selectors using this keyframe')) ?>,
            keyframeRemoveFromSelector: <?= json_encode(__admin('preview.keyframeRemoveFromSelector', 'Remove animation from this selector')) ?>,
            keyframeRemoveConfirm: <?= json_encode(__admin('preview.keyframeRemoveConfirm', 'Remove animation from {selector}?')) ?>,
            keyframeRemoved: <?= json_encode(__admin('preview.keyframeRemoved', 'Animation removed from {selector}')) ?>,
            keyframeRemoveError: <?= json_encode(__admin('preview.keyframeRemoveError', 'Failed to remove: {error}')) ?>,
            // Motion Slice 3 — easing picker (top-level keys for the lib)
            easingPickerTitle: <?= json_encode(__admin('preview.easingPickerTitle', 'Easing curve')) ?>,
            easingPickerReplay: <?= json_encode(__admin('preview.easingPickerReplay', '▶ Replay')) ?>,
            easingPickerCustom: <?= json_encode(__admin('preview.easingPickerCustom', 'Custom…'))   ?>,
            // Motion Slice 4 — transition wizard
            addTransition: <?= json_encode(__admin('preview.addTransition', 'Add transition')) ?>,
            addTransitionTitle: <?= json_encode(__admin('preview.addTransitionTitle', 'Add transition')) ?>,
            addTransitionSelector: <?= json_encode(__admin('preview.addTransitionSelector', 'Selector')) ?>,
            addTransitionProperty: <?= json_encode(__admin('preview.addTransitionProperty', 'Property')) ?>,
            addTransitionDuration: <?= json_encode(__admin('preview.addTransitionDuration', 'Duration')) ?>,
            addTransitionEasing: <?= json_encode(__admin('preview.addTransitionEasing', 'Easing')) ?>,
            addTransitionDelay: <?= json_encode(__admin('preview.addTransitionDelay', 'Delay')) ?>,
            addTransitionMs: <?= json_encode(__admin('preview.addTransitionMs', 'ms')) ?>,
            addTransitionPropertyPlaceholder: <?= json_encode(__admin('preview.addTransitionPropertyPlaceholder', 'or type a custom property')) ?>,
            addTransitionSelectorSearchPlaceholder: <?= json_encode(__admin('preview.addTransitionSelectorSearchPlaceholder', 'Search selectors…')) ?>,
            addTransitionEasingEditBtn: <?= json_encode(__admin('preview.addTransitionEasingEditBtn', 'Edit curve…')) ?>,
            addTransitionSubmit: <?= json_encode(__admin('preview.addTransitionSubmit', 'Add transition')) ?>,
            addTransitionPreviewLabel: <?= json_encode(__admin('preview.addTransitionPreviewLabel', 'Preview')) ?>,
            addTransitionExistingHint: <?= json_encode(__admin('preview.addTransitionExistingHint', 'This selector already has a transition. Submitting will overwrite it.')) ?>,
            addTransitionAdded: <?= json_encode(__admin('preview.addTransitionAdded', 'Transition added to {selector}')) ?>,
            addTransitionError: <?= json_encode(__admin('preview.addTransitionError', 'Failed to add transition: {error}')) ?>,
            addTransitionPropertyRequired: <?= json_encode(__admin('preview.addTransitionPropertyRequired', 'Property is required')) ?>,
            addTransitionSelectorRequired: <?= json_encode(__admin('preview.addTransitionSelectorRequired', 'Pick a selector first')) ?>
        },

        // ── Source tab (preview-style-source.js — Beta.9 A3) ──
        source: {
            styleSource: <?= json_encode(__admin('preview.styleSource', 'Source')) ?>,
            styleSourceHint: <?= json_encode(__admin('preview.styleSourceHint', 'Edit the full style.css source')) ?>,
            styleSourceComingSoon: <?= json_encode(__admin('preview.styleSourceComingSoon', 'Source editor coming in the next slice')) ?>,
            styleSourceRefine: <?= json_encode(__admin('preview.styleSourceRefine', 'Refine in CSS Refiner')) ?>,
            styleSourceFile: <?= json_encode(__admin('preview.styleSourceFile', 'File')) ?>,
            styleSourceEditInCanvas: <?= json_encode(__admin('preview.styleSourceEditInCanvas', 'Edit style.css in the canvas')) ?>,
            styleSourceLoading: <?= json_encode(__admin('preview.styleSourceLoading', 'Loading style.css…')) ?>,
            styleSourceLoadError: <?= json_encode(__admin('preview.styleSourceLoadError', 'Failed to load style.css')) ?>,
            styleSourceFindPlaceholder: <?= json_encode(__admin('preview.styleSourceFindPlaceholder', 'Find… (type :N to jump to line N)')) ?>,
            styleSourceFindPrev: <?= json_encode(__admin('preview.styleSourceFindPrev', 'Previous match (Shift+Enter)')) ?>,
            styleSourceFindNext: <?= json_encode(__admin('preview.styleSourceFindNext', 'Next match (Enter)')) ?>,
            styleSourceFindNoMatch: <?= json_encode(__admin('preview.styleSourceFindNoMatch', 'No match')) ?>,
            styleSourceFindCount: <?= json_encode(__admin('preview.styleSourceFindCount', '{current}/{total}')) ?>,
            styleSourceSave: <?= json_encode(__admin('preview.styleSourceSave', 'Save')) ?>,
            styleSourceCancel: <?= json_encode(__admin('preview.styleSourceCancel', 'Cancel')) ?>,
            styleSourceDirty: <?= json_encode(__admin('preview.styleSourceDirty', 'Unsaved changes')) ?>,
            styleSourceClean: <?= json_encode(__admin('preview.styleSourceClean', 'All saved')) ?>,
            styleSourceSaving: <?= json_encode(__admin('preview.styleSourceSaving', 'Saving…')) ?>,
            styleSourceSaved: <?= json_encode(__admin('preview.styleSourceSaved', 'style.css saved')) ?>,
            styleSourceSaveError: <?= json_encode(__admin('preview.styleSourceSaveError', 'Save failed: {error}')) ?>,
            styleSourceCancelConfirm: <?= json_encode(__admin('preview.styleSourceCancelConfirm', 'Discard unsaved changes and reload style.css from the server?')) ?>,
            styleSourceSwitchConfirm: <?= json_encode(__admin('preview.styleSourceSwitchConfirm', 'You have unsaved Source edits. Discard them and switch?')) ?>,
            styleSourceRestoreTitle: <?= json_encode(__admin('preview.styleSourceRestoreTitle', 'Unsaved draft available')) ?>,
            styleSourceRestoreDetail: <?= json_encode(__admin('preview.styleSourceRestoreDetail', 'From {time}')) ?>,
            styleSourceRestoreAccept: <?= json_encode(__admin('preview.styleSourceRestoreAccept', 'Restore')) ?>,
            styleSourceRestoreDecline: <?= json_encode(__admin('preview.styleSourceRestoreDecline', 'Discard')) ?>
        },

        // ── AI tools panel (preview-ai-tools.js) ──
        aiTools: {
            aiBadgeTooltip: <?= json_encode(__admin('preview.aiToolsAiBadgeTooltip', 'AI workflow — generates a prompt')) ?>,
            manualBadgeTooltip: <?= json_encode(__admin('preview.aiToolsManualBadgeTooltip', 'Steps-only workflow — runs commands without AI')) ?>,
            showMoreLabel: <?= json_encode(__admin('preview.aiToolsShowMore', 'Show more')) ?>,
            showMoreCount: <?= json_encode(__admin('preview.aiToolsShowMoreCount', 'Show {n} more')) ?>,
            tagShowMore: <?= json_encode(__admin('preview.aiToolsTagShowMore', '+{n} more')) ?>,
            tagShowLess: <?= json_encode(__admin('preview.aiToolsTagShowLess', 'Show less')) ?>,
            backToList: <?= json_encode(__admin('preview.aiToolsBackToList', '← Back')) ?>,
            sectionYourPrompt: <?= json_encode(__admin('preview.aiToolsSectionYourPrompt', 'Your prompt')) ?>,
            sectionParameters: <?= json_encode(__admin('preview.aiToolsSectionParameters', 'Parameters')) ?>,
            sectionGeneralPrompt: <?= json_encode(__admin('preview.aiToolsSectionGeneralPrompt', 'General prompt')) ?>,
            sectionGeneralPromptHint: <?= json_encode(__admin('preview.aiToolsSectionGeneralPromptHint', 'The final prompt will appear here after you click Run.')) ?>,
            sectionModel: <?= json_encode(__admin('preview.aiToolsSectionModel', 'Model')) ?>,
            sectionModelNone: <?= json_encode(__admin('preview.aiToolsSectionModelNone', 'No AI connection configured.')) ?>,
            sectionModelConfigure: <?= json_encode(__admin('preview.aiToolsSectionModelConfigure', 'Configure one in the AI Connections page.')) ?>,
            sectionRelatedCommands: <?= json_encode(__admin('preview.aiToolsSectionRelatedCommands', 'Related commands')) ?>,
            sectionAiResponse: <?= json_encode(__admin('preview.aiToolsSectionAiResponse', 'AI response')) ?>,
            sectionBatch: <?= json_encode(__admin('preview.aiToolsSectionBatch', 'Steps')) ?>,
            stateIdle: <?= json_encode(__admin('preview.aiToolsStateIdle', 'Idle')) ?>,
            stateRunning: <?= json_encode(__admin('preview.aiToolsStateRunning', 'Running…')) ?>,
            stateDone: <?= json_encode(__admin('preview.aiToolsStateDone', 'Done')) ?>,
            autoPreviewLabel: <?= json_encode(__admin('preview.aiToolsAutoPreviewLabel', 'Auto-preview')) ?>,
            autoExecuteLabel: <?= json_encode(__admin('preview.aiToolsAutoExecuteLabel', 'Auto-execute')) ?>,
            runButton: <?= json_encode(__admin('preview.aiToolsRunButton', 'Run')) ?>,
            paramOptionsDeferred: <?= json_encode(__admin('preview.aiToolsParamOptionsDeferred', 'Options loaded when you run the workflow.')) ?>,
            runnerLoading: <?= json_encode(__admin('preview.aiToolsRunnerLoading', 'Loading workflow…')) ?>,
            runnerError: <?= json_encode(__admin('preview.aiToolsRunnerError', 'Failed to load workflow: {error}')) ?>,
            yourPromptPlaceholder: <?= json_encode(__admin('preview.aiToolsYourPromptPlaceholder', 'Describe what you want — type, style, content, anything specific.')) ?>,
            generalPromptPlaceholder: <?= json_encode(__admin('preview.aiToolsGeneralPromptPlaceholder', 'Click Generate to assemble the final prompt.')) ?>,
            generateBtn: <?= json_encode(__admin('preview.aiToolsGenerateBtn', 'Generate')) ?>,
            generatingPrompt: <?= json_encode(__admin('preview.aiToolsGeneratingPrompt', 'Generating…')) ?>,
            generateError: <?= json_encode(__admin('preview.aiToolsGenerateError', 'Generate failed: {error}')) ?>,
            copyBtn: <?= json_encode(__admin('preview.aiToolsCopyBtn', 'Copy')) ?>,
            copied: <?= json_encode(__admin('preview.aiToolsCopied', 'Copied!')) ?>,
            paramOptionsEmpty: <?= json_encode(__admin('preview.aiToolsParamOptionsEmpty', 'No options available.')) ?>,
            zoneInputs: <?= json_encode(__admin('preview.aiToolsZoneInputs', 'Inputs')) ?>,
            zoneExchange: <?= json_encode(__admin('preview.aiToolsZoneExchange', 'AI exchange')) ?>,
            zoneExecution: <?= json_encode(__admin('preview.aiToolsZoneExecution', 'Run')) ?>,
            aiResponseHint: <?= json_encode(__admin('preview.aiToolsAiResponseHint', "Paste the AI's JSON reply here.")) ?>,
            aiResponsePlaceholder: <?= json_encode(__admin('preview.aiToolsAiResponsePlaceholder', '{"commands": [...]}')) ?>,
            aiResponseValid: <?= json_encode(__admin('preview.aiToolsAiResponseValid', '{n} commands ready')) ?>,
            aiResponseInvalid: <?= json_encode(__admin('preview.aiToolsAiResponseInvalid', 'Invalid JSON: {error}')) ?>,
            aiResponseEmpty: <?= json_encode(__admin('preview.aiToolsAiResponseEmpty', 'No commands found in response.')) ?>,
            footerModel: <?= json_encode(__admin('preview.aiToolsFooterModel', 'Model')) ?>,
            footerTouches: <?= json_encode(__admin('preview.aiToolsFooterTouches', 'Touches')) ?>,
            validationRequired: <?= json_encode(__admin('preview.aiToolsValidationRequired', 'Required')) ?>,
            validationMinItems: <?= json_encode(__admin('preview.aiToolsValidationMinItems', 'Pick at least {n}')) ?>,
            validationMaxItems: <?= json_encode(__admin('preview.aiToolsValidationMaxItems', 'Pick at most {n}')) ?>,
            validationMinLength: <?= json_encode(__admin('preview.aiToolsValidationMinLength', 'At least {n} characters')) ?>,
            validationMaxLength: <?= json_encode(__admin('preview.aiToolsValidationMaxLength', 'At most {n} characters')) ?>,
            validationPattern: <?= json_encode(__admin('preview.aiToolsValidationPattern', "Doesn't match the required pattern")) ?>,
            validationMin: <?= json_encode(__admin('preview.aiToolsValidationMin', 'Minimum {n}')) ?>,
            validationMax: <?= json_encode(__admin('preview.aiToolsValidationMax', 'Maximum {n}')) ?>,
            runBlockedTooltip: <?= json_encode(__admin('preview.aiToolsRunBlockedTooltip', 'Fix the highlighted parameters before running.')) ?>,
            runBlockedAiResponse: <?= json_encode(__admin('preview.aiToolsRunBlockedAiResponse', "Paste the AI's JSON reply (or click Generate + Send first).")) ?>,
            sendingToAi: <?= json_encode(__admin('preview.aiToolsSendingToAi', 'Sending to {model}…')) ?>,
            sendFailed: <?= json_encode(__admin('preview.aiToolsSendFailed', 'Send failed: {error}')) ?>,
            executionRunning: <?= json_encode(__admin('preview.aiToolsExecutionRunning', 'Running {current}/{total}')) ?>,
            executionDone: <?= json_encode(__admin('preview.aiToolsExecutionDone', 'Done ({ok} succeeded)')) ?>,
            executionDoneWithErrors: <?= json_encode(__admin('preview.aiToolsExecutionDoneWithErrors', 'Done ({ok}/{total} succeeded, {fail} failed)')) ?>,
            stepPending: <?= json_encode(__admin('preview.aiToolsStepPending', 'Pending')) ?>,
            stepRunning: <?= json_encode(__admin('preview.aiToolsStepRunning', 'Running…')) ?>,
            stepError: <?= json_encode(__admin('preview.aiToolsStepError', 'Failed: {error}')) ?>,
            runActive: <?= json_encode(__admin('preview.aiToolsRunActive', 'Running…')) ?>,
            actionRunWithAi: <?= json_encode(__admin('preview.aiToolsActionRunWithAi', 'Run with AI')) ?>,
            actionSendToAi: <?= json_encode(__admin('preview.aiToolsActionSendToAi', 'Send to AI')) ?>,
            actionGeneratePrompt: <?= json_encode(__admin('preview.aiToolsActionGeneratePrompt', 'Generate prompt')) ?>,
            actionRun: <?= json_encode(__admin('preview.aiToolsActionRun', 'Run')) ?>,
            actionExecute: <?= json_encode(__admin('preview.aiToolsActionExecute', 'Execute commands')) ?>,
            actionGenerateForCopy: <?= json_encode(__admin('preview.aiToolsActionGenerateForCopy', 'Generate for copy')) ?>,
            modelLabel: <?= json_encode(__admin('preview.aiToolsModelLabel', 'Model:')) ?>,
            phaseGenerating: <?= json_encode(__admin('preview.aiToolsPhaseGenerating', 'Generating prompt…')) ?>,
            copyHint: <?= json_encode(__admin('preview.aiToolsCopyHint', 'Copy this to your AI assistant, then paste the reply below.')) ?>,
            pasteHint: <?= json_encode(__admin('preview.aiToolsPasteHint', 'Paste the JSON reply here.')) ?>,
            stepsReady: <?= json_encode(__admin('preview.aiToolsStepsReady', '{n} commands ready')) ?>,
            stepsParseError: <?= json_encode(__admin('preview.aiToolsStepsParseError', 'No commands available from current response.')) ?>,
            batchTitle: <?= json_encode(__admin('preview.aiToolsBatchTitle', 'Batch')) ?>,
            streaming: <?= json_encode(__admin('preview.aiToolsStreaming', 'Receiving from {model}… ({chars} chars)')) ?>,
            sendingWithElapsed: <?= json_encode(__admin('preview.aiToolsSendingWithElapsed', 'Sending to {model}… ({sec}s)')) ?>,
            streamingWithElapsed: <?= json_encode(__admin('preview.aiToolsStreamingWithElapsed', 'Receiving from {model}… ({chars} chars, {sec}s)')) ?>,
            backupBtn: <?= json_encode(__admin('preview.aiToolsBackupBtn', 'Create backup now')) ?>,
            backupCreating: <?= json_encode(__admin('preview.aiToolsBackupCreating', 'Creating backup…')) ?>,
            backupSuccess: <?= json_encode(__admin('preview.aiToolsBackupSuccess', 'Backup created ({path})')) ?>,
            backupFailed: <?= json_encode(__admin('preview.aiToolsBackupFailed', 'Backup failed: {error}')) ?>,
            selectorEmpty: <?= json_encode(__admin('preview.aiToolsSelectorEmpty', 'No element selected — click one in the iframe.')) ?>,
            selectorIn: <?= json_encode(__admin('preview.aiToolsSelectorIn', 'in')) ?>,
            autoExecHint: <?= json_encode(__admin('preview.aiToolsAutoExecHint', 'Auto-executing in 1.5s — edit response to cancel')) ?>,
            copiedToast: <?= json_encode(__admin('preview.aiToolsCopiedToast', 'Prompt copied to clipboard')) ?>,
            copyFailedToast: <?= json_encode(__admin('preview.aiToolsCopyFailedToast', 'Could not auto-copy — use the Copy button or Ctrl+C')) ?>
        },

        // ── Translation manager panel (preview-translation.js — Beta.9 A4) ──
        translation: {
            translationScopeSite: <?= json_encode(__admin('preview.translationScopeSite', 'Whole site')) ?>,
            translationScopePages: <?= json_encode(__admin('preview.translationScopePages', 'Pages')) ?>,
            translationScopeComponents: <?= json_encode(__admin('preview.translationScopeComponents', 'Components')) ?>,
            translationScopeLayout: <?= json_encode(__admin('preview.translationScopeLayout', 'Layout')) ?>,
            translationCoverage: <?= json_encode(__admin('preview.translationCoverage', 'Coverage: {pct}% ({used}/{total})')) ?>,
            translationLoading: <?= json_encode(__admin('preview.translationLoading', 'Loading…')) ?>,
            translationActionEdit: <?= json_encode(__admin('preview.translationActionEdit', 'Edit')) ?>,
            translationActionDelete: <?= json_encode(__admin('preview.translationActionDelete', 'Delete')) ?>,
            translationActionSetValue: <?= json_encode(__admin('preview.translationActionSetValue', 'Set value')) ?>,
            translationUnsetPlaceholder: <?= json_encode(__admin('preview.translationUnsetPlaceholder', '(not set)')) ?>,
            translationNoMatches: <?= json_encode(__admin('preview.translationNoMatches', 'No keys match the current filters.')) ?>,
            translationEditSave: <?= json_encode(__admin('preview.translationEditSave', 'Save')) ?>,
            translationEditCancel: <?= json_encode(__admin('preview.translationEditCancel', 'Cancel')) ?>,
            translationEditSaving: <?= json_encode(__admin('preview.translationEditSaving', 'Saving…')) ?>,
            translationEditPlaceholder: <?= json_encode(__admin('preview.translationEditPlaceholder', 'Translation value…')) ?>,
            translationEditSaveError: <?= json_encode(__admin('preview.translationEditSaveError', 'Failed to save: {error}')) ?>,
            translationDeletePrompt: <?= json_encode(__admin('preview.translationDeletePrompt', 'Delete {key} from {lang}.json?')) ?>,
            translationConfirmDelete: <?= json_encode(__admin('preview.translationConfirmDelete', 'Delete')) ?>,
            translationDeleting: <?= json_encode(__admin('preview.translationDeleting', 'Deleting…')) ?>,
            translationDeleteError: <?= json_encode(__admin('preview.translationDeleteError', 'Failed to delete: {error}')) ?>,
            translationBulkDeleteHeader: <?= json_encode(__admin('preview.translationBulkDeleteHeader', 'These {n} unused keys will be deleted from {lang}.json:')) ?>,
            translationBulkDeleteConfirm: <?= json_encode(__admin('preview.translationBulkDeleteConfirm', 'Delete {n} keys')) ?>,
            translationDeleteAllLangs: <?= json_encode(__admin('preview.translationDeleteAllLangs', 'Delete from all {n} languages ({list})')) ?>,
            translationDeleteMultiError: <?= json_encode(__admin('preview.translationDeleteMultiError', 'Failed for {n}/{total} languages: {details}')) ?>
        }
    }
};
</script>

<!-- Preview JavaScript Modules (load order matters) -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-state.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-state.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-navigation.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-navigation.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-theme.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-style-theme.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-motion.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-style-motion.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-selectors.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-style-selectors.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-editor.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-style-editor.js') ?>"></script>
<!-- A3 lib/code-editor — order matters: core widget first, tokenizers register on top -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/lib/code-editor/code-editor.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/lib/code-editor/code-editor.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/lib/code-editor/css-tokenizer.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/lib/code-editor/css-tokenizer.js') ?>"></script>
<!-- A3-companion Motion Slice 3 lib — cubic-bezier easing picker -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/lib/easing-picker/easing-picker.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/lib/easing-picker/easing-picker.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-style-source.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-style-source.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-js-interactions.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-js-interactions.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-translation.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-translation.js') ?>"></script>
<!-- AI libs: BYOK connection store + provider catalog + caller. Read-only
     here (full management UI lives on /admin/ai-connections). Load order
     matters: catalog + presets before store; store + catalog before caller. -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/provider-catalog.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/lib/provider-catalog.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/local-presets.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/lib/local-presets.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/connections-store.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/lib/connections-store.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/stream-parsers.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/lib/stream-parsers.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai/lib/ai-call.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/lib/ai-call.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-ai-tools.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-ai-tools.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-transition-editor.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-transition-editor.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-miniplayer.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-miniplayer.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-sidebar-resize.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-sidebar-resize.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview-drag.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview-drag.js') ?>"></script>

<!-- Complex Element wizards (Add Element → Complex tab).
     Loaded BEFORE preview.js so the Complex-tab handler can call into
     window.QSComplexWizard during init.
     Order matters: shared helpers (row editor + textKey picker) before
     the per-kind `complex-*.js` files (each kind may use them on parse). -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/wizard-row-editor.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/contextual-complex/wizard-row-editor.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/route-input.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/contextual-complex/route-input.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js') ?>"></script>
<?php
    // Auto-include every complex-*.js so adding a new kind is one file drop.
    foreach (glob(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/contextual-complex/complex-*.js') as $_ceJs) {
        $_ceBase = basename($_ceJs);
        echo '<script src="' . rtrim(BASE_URL, '/') . '/admin/assets/js/pages/preview/contextual-complex/' . $_ceBase
            . '?v=' . filemtime($_ceJs) . '"></script>' . "\n";
    }
?>

<!-- Preview JavaScript (Main) -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/preview/preview.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/preview/preview.js') ?>"></script>
