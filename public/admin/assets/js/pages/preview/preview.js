/**
 * Visual Preview Page JavaScript
 * Extracted from preview.php for browser caching
 * 
 * Dependencies:
 * - PreviewConfig global (set by inline script in preview.php)
 * - QuickSiteAdmin global (from admin.js)
 * - ColorPicker (from colorpicker.js)
 */
(function() {
    'use strict';
    
    // DOM Elements
    const iframe = document.getElementById('preview-iframe');
    const container = document.getElementById('preview-container');
    const workspace = document.querySelector('.preview-workspace');
    const wrapper = document.getElementById('preview-frame-wrapper');
    const loading = document.getElementById('preview-loading');
    const targetSelect = document.getElementById('preview-target');  // Unified page/component dropdown

    // Wrap the edit-target select with the
    // searchable combobox so authors can filter by typing instead of
    // scrolling the layout/pages/components optgroups. Idempotent —
    // QSSearchableSelect has its own double-wrap guard on the native
    // select's dataset.qsSearchableWrapped marker.
    let targetPicker = null;
    if (targetSelect && window.QSSearchableSelect) {
        try {
            targetPicker = new window.QSSearchableSelect(targetSelect, {
                placeholder: QSPanelWords.t('preview.editListPlaceholder'),
                searchPlaceholder: QSPanelWords.t('preview.editListSearchPlaceholder'),
                emptyText: QSPanelWords.t('preview.editListNoMatches'),
                fitOptions: true,
            });
        } catch (e) {
            console.warn('[Preview] Failed to mount QSSearchableSelect on preview-target:', e);
        }
    }

    const langSelect = document.getElementById('preview-lang');
    // The page title field (the top bar): the page's title in the language shown
    const pageTitleGroup = document.getElementById('preview-page-title-group');
    const pageTitleInput = document.getElementById('preview-page-title');
    const pageDescriptionGroup = document.getElementById('preview-page-description-group');
    const pageDescriptionInput = document.getElementById('preview-page-description');
    const pageDescriptionCut = document.getElementById('preview-page-description-cut');
    const pageDescriptionExpand = document.getElementById('preview-page-description-expand');
    const reloadBtn = document.getElementById('preview-reload');
    const deviceBtns = document.querySelectorAll('.preview-device-btn');
    const modeBtns = document.querySelectorAll('.preview-tool[data-mode]');
    
    // Contextual area elements
    const contextualSections = document.querySelectorAll('.preview-contextual-section');
    const ctxSelectDefault = document.getElementById('contextual-select-default');
    const ctxSelectInfo = document.getElementById('contextual-select-info');
    const ctxNodeStruct = document.getElementById('ctx-node-struct');
    const ctxNodeId = document.getElementById('ctx-node-id');
    const ctxNodeComponentRow = document.getElementById('ctx-node-component-row');
    const ctxNodeComponent = document.getElementById('ctx-node-component');
    const ctxNodeTag = document.getElementById('ctx-node-tag');
    const ctxNodeClasses = document.getElementById('ctx-node-classes');
    const ctxNodeChildren = document.getElementById('ctx-node-children');
    const ctxNodeText = document.getElementById('ctx-node-text');
    const ctxNodeTextKeyRow = document.getElementById('ctx-node-textkey-row');
    const ctxNodeTextKey = document.getElementById('ctx-node-textkey');
    const ctxNodeAdd = document.getElementById('ctx-node-add');
    const ctxNodeEditParams = document.getElementById('ctx-node-edit-params');
    const ctxNodeDelete = document.getElementById('ctx-node-delete');
    const ctxNodeDuplicate = document.getElementById('ctx-node-duplicate');
    const ctxNodeSaveSnippet = document.getElementById('ctx-node-save-snippet');
    const ctxNodeVariables = document.getElementById('ctx-node-variables');
    // Translate-from-CSV: button + modal refs. Shown when the selected
    // node has data-qs-complex='table'.
    const ctxNodeTranslateCsv = document.getElementById('ctx-node-translate-csv');
    const translateCsvModal       = document.getElementById('preview-translate-csv-modal');
    const translateCsvClose       = document.getElementById('translate-csv-modal-close');
    const translateCsvCancel      = document.getElementById('translate-csv-cancel');
    const translateCsvApply       = document.getElementById('translate-csv-apply');
    const translateCsvLang        = document.getElementById('translate-csv-lang');
    const translateCsvHasHead     = document.getElementById('translate-csv-has-head');
    const translateCsvExpectedDims= document.getElementById('translate-csv-expected-dims');
    const translateCsvPasteArea   = document.getElementById('translate-csv-paste');
    const translateCsvStatus      = document.getElementById('translate-csv-status');
    const translateCsvStructIdEl  = document.getElementById('translate-csv-structure-id');
    // Per-open context for the modal: which table is being translated +
    // its expected dimensions (measured from the iframe DOM at button-click
    // time so we don't carry stale data around).
    let translateCsvContext = null;  // { structureId, route, dims: {hasHead, headerCols, bodyRows, cols} }

    // Save as Snippet form elements
    const saveSnippetForm = document.getElementById('contextual-save-snippet-form');
    const saveSnippetClose = document.getElementById('save-snippet-close');
    const saveSnippetName = document.getElementById('save-snippet-name');
    const saveSnippetId = document.getElementById('save-snippet-id');
    const saveSnippetCategory = document.getElementById('save-snippet-category');
    const saveSnippetDesc = document.getElementById('save-snippet-desc');
    const saveSnippetPreview = document.getElementById('save-snippet-structure-preview');
    const saveSnippetCancel = document.getElementById('save-snippet-cancel');
    const saveSnippetSubmit = document.getElementById('save-snippet-submit');
    const saveSnippetPersonal = document.getElementById('save-snippet-personal');
    
    // Save as Component form elements
    const ctxNodeSaveComponent = document.getElementById('ctx-node-save-component');
    const saveComponentForm = document.getElementById('contextual-save-component-form');
    const saveComponentClose = document.getElementById('save-component-close');
    const saveComponentName = document.getElementById('save-component-name');
    const saveComponentPreview = document.getElementById('save-component-structure-preview');
    const saveComponentCancel = document.getElementById('save-component-cancel');
    const saveComponentSubmit = document.getElementById('save-component-submit');
    
    // Variables panel elements (component-only)
    const variablesPanel = document.getElementById('contextual-variables-panel');
    const variablesPanelClose = document.getElementById('variables-panel-close');
    const variablesPanelLoading = document.getElementById('variables-panel-loading');
    const variablesPanelEmpty = document.getElementById('variables-panel-empty');
    const variablesPanelCards = document.getElementById('variables-panel-cards');
    const variablesPanelFooter = document.getElementById('variables-panel-footer');
    const variablesPanelFooterText = document.getElementById('variables-panel-footer-text');
    
    // Enums panel elements (component-only)
    const enumsPanel = document.getElementById('contextual-enums-panel');
    const enumsPanelClose = document.getElementById('enums-panel-close');
    const enumsPanelLoading = document.getElementById('enums-panel-loading');
    const enumsPanelEmpty = document.getElementById('enums-panel-empty');
    const enumsPanelCards = document.getElementById('enums-panel-cards');
    const enumsPanelAdd = document.getElementById('enums-panel-add');
    const enumsPanelAddBtn = document.getElementById('enums-panel-add-btn');
    const ctxNodeEnums = document.getElementById('ctx-node-enums');
    
    // Emulation panel elements (component-only)
    const emulationPanel = document.getElementById('contextual-emulation-panel');
    const emulationPanelClose = document.getElementById('emulation-panel-close');
    const emulationPanelLoading = document.getElementById('emulation-panel-loading');
    const emulationPanelEmpty = document.getElementById('emulation-panel-empty');
    const emulationPanelFields = document.getElementById('emulation-panel-fields');
    const emulationPanelActions = document.getElementById('emulation-panel-actions');
    const emulationApplyBtn = document.getElementById('emulation-apply-btn');
    const emulationResetBtn = document.getElementById('emulation-reset-btn');
    // Live-data toggle button (page emulation only).
    const emulationLiveBtn  = document.getElementById('emulation-live-btn');
    const ctxNodeEmulation = document.getElementById('ctx-node-emulation');
    
    // Text mode contextual elements
    const ctxTextDefault = document.getElementById('contextual-text-default');
    const ctxTextInfo = document.getElementById('contextual-text-info');
    const ctxTextKey = document.getElementById('ctx-text-key');
    const ctxTextDelete = document.getElementById('ctx-text-delete');
    const ctxTextKeepKeys = document.getElementById('ctx-text-keep-keys');

    // Route layout toggle elements
    const layoutTogglesContainer = document.getElementById('preview-layout-toggles');
    const toggleMenuCheckbox = document.getElementById('preview-toggle-menu');
    const toggleFooterCheckbox = document.getElementById('preview-toggle-footer');
    // Consent layer (banner + popup) preview toggles — preview-only (site-wide,
    // not per-route), shown only when the layer has been generated.
    const toggleConsentBannerCheckbox = document.getElementById('preview-toggle-consent-banner');
    const toggleConsentPopupCheckbox = document.getElementById('preview-toggle-consent-popup');
    const toggleConsentBannerLabel = document.getElementById('preview-toggle-consent-banner-label');
    const toggleConsentPopupLabel = document.getElementById('preview-toggle-consent-popup-label');
    const createComponentBtn = document.getElementById('preview-create-component');

    // Back to Select button (in add form)
    const addBackToSelect = document.getElementById('add-back-to-select');
    
    // Tools show names checkbox: it governs the tools' row and the actions of the Select and Text panels
    const toolsRow = document.getElementById('preview-tools');
    const toolsShowNames = document.getElementById('preview-tools-show-names');
    const actionRows = [document.getElementById('ctx-node-actions'), document.getElementById('ctx-text-actions')].filter(Boolean);
    
    // Drag tool options — DOM refs and logic extracted to preview-drag.js (window.PreviewDrag)
    
    // The options panel, and the bar under it that opens and closes it where it folds away
    const optionsPanel = document.getElementById('preview-sidebar');
    const panelToggle = document.getElementById('preview-panel-toggle');
    const panelToggleName = document.getElementById('preview-panel-toggle-name');

    // Global Element Info Bar (its own row, above the workspace)
    const globalElementInfo = document.getElementById('preview-element-info');
    const globalElementInfoToggle = document.getElementById('preview-element-info-toggle');
    const globalElementInfoSummary = document.getElementById('preview-element-info-summary');
    const globalElementInfoDetails = document.getElementById('preview-element-info-details');
    const globalInfoNodeId = document.getElementById('info-node-id');
    const globalInfoNodeTag = document.getElementById('info-node-tag');
    const globalInfoNodeClasses = document.getElementById('info-node-classes');
    const globalInfoNodeChildren = document.getElementById('info-node-children');
    const globalInfoNodeParams = document.getElementById('info-node-params');
    const globalInfoNodeParamsRow = document.getElementById('info-node-params-row');
    const globalInfoNodeTextKeyRow = document.getElementById('info-node-textkey-row');
    const globalInfoNodeTextKey = document.getElementById('info-node-textkey');
    const globalInfoNodeComponentRow = document.getElementById('info-node-component-row');
    const globalInfoNodeComponent = document.getElementById('info-node-component');
    
    // Theme panel elements
    const styleTabs = document.getElementById('contextual-style-tabs');
    const styleContent = document.getElementById('contextual-style-content');
    const themePanel = document.getElementById('theme-panel');
    const themeLoading = document.getElementById('theme-loading');
    const themeContent = document.getElementById('theme-content');
    const themeColorsGrid = document.getElementById('theme-colors-grid');
    const themeFontsGrid = document.getElementById('theme-fonts-grid');
    const themeSpacingGrid = document.getElementById('theme-spacing-grid');
    const themeOtherGrid = document.getElementById('theme-other-grid');
    const themeOtherSection = document.getElementById('theme-other-section');
    const themeResetBtn = document.getElementById('theme-reset-btn');
    const themeSaveBtn = document.getElementById('theme-save-btn');
    const selectorsPanel = document.getElementById('selectors-panel');
    
    // Selector browser elements
    const selectorSearchInput = document.getElementById('selector-search-input');
    const selectorSearchClear = document.getElementById('selector-search-clear');
    const selectorCount = document.getElementById('selector-count');
    const selectorsLoading = document.getElementById('selectors-loading');
    const selectorsGroups = document.getElementById('selectors-groups');
    const selectorTagsList = document.getElementById('selectors-tags-list');
    const selectorClassesList = document.getElementById('selectors-classes-list');
    const selectorIdsList = document.getElementById('selectors-ids-list');
    const selectorAttributesList = document.getElementById('selectors-attributes-list');
    const selectorMediaList = document.getElementById('selectors-media-list');
    const selectorTagsCount = document.getElementById('selectors-tags-count');
    const selectorClassesCount = document.getElementById('selectors-classes-count');
    const selectorIdsCount = document.getElementById('selectors-ids-count');
    const selectorAttributesCount = document.getElementById('selectors-attributes-count');
    const selectorMediaCount = document.getElementById('selectors-media-count');
    const selectorSelected = document.getElementById('selector-selected');
    const selectorSelectedValue = document.getElementById('selector-selected-value');
    const selectorSelectedClear = document.getElementById('selector-selected-clear');
    const selectorEditBtn = document.getElementById('selector-edit-btn');
    const selectorAnimateBtn = document.getElementById('selector-animate-btn');
    
    // Style Editor elements
    const styleEditor = document.getElementById('style-editor');
    const styleEditorBack = document.getElementById('style-editor-back');
    const styleEditorLabel = document.getElementById('style-editor-label');
    const styleEditorSelector = document.getElementById('style-editor-selector');
    const styleEditorCount = document.getElementById('style-editor-count');
    const styleEditorLoading = document.getElementById('style-editor-loading');
    const styleEditorEmpty = document.getElementById('style-editor-empty');
    const styleEditorProperties = document.getElementById('style-editor-properties');
    const styleEditorAdd = document.getElementById('style-editor-add');
    const styleEditorAddBtn = document.getElementById('style-editor-add-btn');
    const styleEditorAddFirst = document.getElementById('style-editor-add-first');
    const styleEditorActions = document.getElementById('style-editor-actions');
    const styleEditorReset = document.getElementById('style-editor-reset');
    const styleEditorSave = document.getElementById('style-editor-save');
    
    // Animations panel elements
    const animationsPanel = document.getElementById('animations-panel');
    const animationsLoading = document.getElementById('animations-loading');
    const animationsContent = document.getElementById('animations-content');
    const keyframesCount = document.getElementById('keyframes-count');
    const keyframesEmpty = document.getElementById('keyframes-empty');
    const keyframesList = document.getElementById('keyframes-list');
    const keyframeAddBtn = document.getElementById('keyframe-add-btn');
    const transitionsCount = document.getElementById('transitions-count');
    const transitionsList = document.getElementById('transitions-list');
    const animationsCount = document.getElementById('animations-count');
    const animationsList = document.getElementById('animations-list');
    const animatedEmpty = document.getElementById('animated-empty');

    // Source panel elements (sidebar scaffold + role gate)
    const sourcePanel       = document.getElementById('source-panel');
    const sourceAdvancedRow = document.getElementById('contextual-style-advanced');
    const sourceBtn         = document.getElementById('contextual-style-source-btn');
    // Source canvas (full-CSS editor host in the main area)
    const sourceCanvas      = document.getElementById('preview-source-canvas');
    const previewContainer  = document.getElementById('preview-container');

    // Keyframe Editor Modal elements
    const keyframeModal = document.getElementById('preview-keyframe-modal');
    const keyframeModalTitle = document.getElementById('keyframe-modal-title');
    const keyframeModalClose = document.getElementById('keyframe-modal-close');
    const keyframeNameInput = document.getElementById('keyframe-name');
    const keyframeTimeline = document.getElementById('keyframe-timeline');
    const keyframeFramesContainer = document.getElementById('keyframe-frames');
    const keyframeAddFrameBtn = document.getElementById('keyframe-add-frame');
    const keyframePreviewBtn = document.getElementById('keyframe-preview-btn');
    const keyframeCancelBtn = document.getElementById('keyframe-cancel');
    const keyframeSaveBtn = document.getElementById('keyframe-save');
    
    // Component Warning Banner
    const componentWarning = document.getElementById('preview-component-warning');
    const componentWarningText = document.getElementById('preview-component-warning-text');
    const deleteComponentBtn = document.getElementById('preview-delete-component');
    
    // Iframe Warning Banner
    const iframeWarning = document.getElementById('preview-iframe-warning');
    const iframeWarningClose = document.getElementById('preview-iframe-warning-close');
    let iframeWarningDismissed = false;

    // Sidebar Add Form Elements (Add Mode)
    const contextualAddDefault = document.getElementById('contextual-add-default');
    const contextualAddForm = document.getElementById('contextual-add-form');
    const addTypeTabs = document.getElementById('add-type-tabs');
    const addTypeInput = document.getElementById('add-type-input');
    const addTagField = document.getElementById('add-tag-field');
    const addTagSelect = document.getElementById('add-tag');
    const addComponentField = document.getElementById('add-component-field');
    const addComponentSelect = document.getElementById('add-component');
    const addSnippetField = document.getElementById('add-snippet-field');
    const addSnippetInput = document.getElementById('add-snippet');
    const addSnippetPreview = document.getElementById('add-snippet-preview');
    const addSnippetPreviewTitle = document.getElementById('add-snippet-preview-title');
    const addSnippetPreviewSource = document.getElementById('add-snippet-preview-source');
    const addSnippetPreviewDesc = document.getElementById('add-snippet-preview-desc');
    const addSnippetPreviewFrame = document.getElementById('add-snippet-preview-frame');
    const addSnippetCssStatus = document.getElementById('add-snippet-css-status');
    const addSnippetCssSelectors = document.getElementById('add-snippet-css-selectors');
    const addSnippetCssCode = document.getElementById('add-snippet-css-code');
    const addSnippetCssToggle = document.getElementById('add-snippet-css-toggle');
    const addSnippetCssActions = document.getElementById('add-snippet-css-actions');
    const addSnippetCssOptions = document.getElementById('add-snippet-css-options');
    const addSnippetCssOptionMissing = document.getElementById('add-snippet-css-option-missing');
    const addSnippetCssWarning = document.getElementById('add-snippet-css-warning');
    const addSnippetStyleToggle = document.getElementById('add-snippet-style-toggle');
    const addSnippetStyleToggleInput = document.getElementById('add-snippet-style-toggle-input');
    const addSnippetKeysToggleInput = document.getElementById('add-snippet-keys-toggle-input');
    const addSnippetPreviewActions = document.getElementById('add-snippet-preview-actions');
    const deleteSnippetBtn = document.getElementById('delete-snippet-btn');
    const addPositionPicker = document.getElementById('add-position-picker');
    const addPositionNone = document.getElementById('add-position-none');
    const addMandatoryParams = document.getElementById('add-mandatory-params');
    const addMandatoryParamsContainer = document.getElementById('add-mandatory-params-container');
    const addClassField = document.getElementById('add-class-field');
    const addClassInput = document.getElementById('add-class');
    const addIdField = document.getElementById('add-id-field');
    const addIdInput = document.getElementById('add-id-input');
    const addCustomParamsContainer = document.getElementById('add-custom-params-container');
    const addAdvancedSection = document.getElementById('add-advanced-section');
    const addPreviewSection = document.getElementById('add-preview-section');
    const addPositionSection = document.getElementById('add-position-section');
    // Text-node UI: value input (RAW) + key picker container (Translation key) + their wrapper fields
    const addTextValueField = document.getElementById('add-text-value-field');
    const addTextKeyField = document.getElementById('add-text-key-field');
    const addTextKeyPickerHost = document.getElementById('add-text-key-picker');
    let addTextKeyPickerInst = null;   // QSComplexWizard.createTextKeyPicker instance (lazy)
    
    // Helper to get position from radio picker
    function getAddPosition() {
        const checked = addPositionPicker?.querySelector('input[name="add-position"]:checked');
        return checked?.value || 'after';
    }
    
    // Helper to set position in radio picker
    function setAddPosition(value) {
        const radio = addPositionPicker?.querySelector(`input[name="add-position"][value="${value}"]`);
        if (radio) radio.checked = true;
    };
    // The simple Add's hints follow the position the person picks (a Button inside a form sends it).
    addPositionPicker?.addEventListener('change', function() {
        if (window.PreviewSimpleAdd) PreviewSimpleAdd.refresh();
    });
    const addCustomParamsList = document.getElementById('add-custom-params-list');
    const addAnotherParamBtn = document.getElementById('add-another-param');
    // Text-node tab refs
    const addTextField = document.getElementById('add-text-field');
    const addTextValueInput = document.getElementById('add-text-value');
    // Translation-key params (alt on img/area, title on iframe — the set is
    // TagRegistry::TRANSLATION_KEY_PARAMS, arriving via TAG_INFO).
    const addTransKeyParams = document.getElementById('add-transkey-params');
    const addTransKeyParamsContainer = document.getElementById('add-transkey-params-container');
    const addComponentVars = document.getElementById('add-component-vars');
    const addComponentVarsContainer = document.getElementById('add-component-vars-container');
    const addComponentNoVars = document.getElementById('add-component-no-vars');
    const addCancelBtn = document.getElementById('add-cancel');
    const addConfirmBtn = document.getElementById('add-confirm');
    const addConfirmAnotherBtn = document.getElementById('add-confirm-another');
    // Edit-Params mode wiring: type-tab wrapper (hidden in edit mode),
    // header text span (swapped to "Edit Params: <tag>"), bottom-button
    // text span (swapped to "Save").
    const addTypeField = document.getElementById('add-type-field');
    const addFormTitleText = document.getElementById('add-form-title-text');
    const addConfirmLabel = document.getElementById('add-confirm-label');
    // Complex Element tab — kind picker + wizard body.
    // The kind dropdown is populated from window.QSComplexWizard.registry,
    // and each kind module owns rendering inside addComplexBody via its
    // own renderWizard(container) factory.
    const addComplexField = document.getElementById('add-complex-field');
    const addComplexKindSelect = document.getElementById('add-complex-kind');
    // The kind list is the panel's own dropdown: a native list is drawn outside
    // the page, where neither a kind's description line nor the bubble reaches.
    const addComplexKindPicker = addComplexKindSelect && window.QSSearchableSelect
        ? new window.QSSearchableSelect(addComplexKindSelect, {
            placeholder: addComplexKindSelect.options[0] ? addComplexKindSelect.options[0].textContent : '',
            searchPlaceholder: QSPanelWords.t('preview.complexKindSearch'),
            emptyText: QSPanelWords.t('preview.complexKindNoMatch')
        })
        : null;
    const addComplexBody = document.getElementById('add-complex-body');
    let addComplexController = null;  // controller returned by the active kind's renderWizard
    
    // Configuration
    const baseUrl = PreviewConfig.baseUrl;
    // The base the preview iframe navigates under: the edited project's own
    // '/p/<id>' view (surface B).
    // (PreviewConfig.authToken is read where it is used, never captured here: it is
    // the per-session token this page embeds, which authorizes nothing on its own —
    // the session cookie is the credential — and api.js replaces it when a
    // deployment plugs in its own token source.)
    const previewBase = PreviewConfig.previewBase || PreviewConfig.baseUrl;
    const adminUrl = PreviewConfig.adminUrl;
    const structureUrl = PreviewConfig.structureUrl;
    // Both follow a run that changes the project's languages (rebuildLanguageList()).
    let multilingual = PreviewConfig.multilingual;
    let defaultLang = PreviewConfig.defaultLang;
    
    // Initialize PreviewState module with DOM references
    if (window.PreviewState) {
        PreviewState.init({
            iframe: iframe,
            container: container,
            wrapper: wrapper,
            loading: loading,
            targetSelect: targetSelect,
            langSelect: langSelect
        });
    }
    
    // The device frame's common sizes, in CSS pixels, each kind's default marked: the bar's list offers the kind chosen,
    // labelled by the numbers. Desktop has no size: it fills the room. A tablet frame and a phone frame of one size
    // render alike, as nothing but the panel's own stylesheet reads which button was pressed.
    const FRAME_SIZES = Object.freeze({
        mobile: Object.freeze({ sizes: [[360, 800], [375, 667], [390, 844], [430, 932]], default: 1 }),
        tablet: Object.freeze({ sizes: [[768, 1024], [810, 1080], [820, 1180], [1024, 1366]], default: 0 }),
    });
    // The limits of a frame, [width, height]: the handles stop at them, and a typed, picked, rotated or stored size is held to them
    const FRAME_MIN = Object.freeze([200, 200]);
    const FRAME_MAX = Object.freeze([3840, 10000]);
    
    // State
    let currentDevice = 'desktop';
    let currentMode = 'select';
    let currentEditType = 'page';     // 'page', 'component', or 'layout'
    let currentEditName = '';         // route name for pages, component name for components, 'menu'/'footer' for layout
    let overlayInjected = false;
    let layoutToReveal = null; // 'menu' or 'footer': scrolled into view once the iframe has loaded
    
    // Which Style-tool tab is showing: 'theme' or 'selectors'.
    let activeStyleTab = 'theme';

    // Page structure classes (for JS mode picker)
    let pageStructureClasses = [];    // Classes from actual DOM (not just CSS)

    // Theme-variable, selector-browser, style-editor, animations and
    // keyframe-editor state lives in those panels' own modules
    // (preview-style-theme.js, -selectors.js, -editor.js, -motion.js).
    // This file keeps no copy of any of it — reach them through the module.

    // ==================== Property Type Registry ====================
    
    /**
     * Property type definitions for keyframe editor
     * Maps CSS property names to input types and configurations
     */
    const KEYFRAME_PROPERTY_TYPES = {
        // Opacity - range slider 0-1
        opacity: { type: 'range', min: 0, max: 1, step: 0.01 },
        
        // Color properties - color picker
        'color': { type: 'color' },
        'background-color': { type: 'color' },
        'border-color': { type: 'color' },
        'border-top-color': { type: 'color' },
        'border-right-color': { type: 'color' },
        'border-bottom-color': { type: 'color' },
        'border-left-color': { type: 'color' },
        'outline-color': { type: 'color' },
        'fill': { type: 'color' },
        'stroke': { type: 'color' },
        'text-decoration-color': { type: 'color' },
        'caret-color': { type: 'color' },
        
        // Length properties - number + unit dropdown
        'width': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'auto'] },
        'height': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'auto'] },
        'min-width': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh'] },
        'min-height': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh'] },
        'max-width': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'none'] },
        'max-height': { type: 'length', units: ['px', '%', 'em', 'rem', 'vw', 'vh', 'none'] },
        'top': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'right': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'bottom': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'left': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-top': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-right': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-bottom': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'margin-left': { type: 'length', units: ['px', '%', 'em', 'rem', 'auto'] },
        'padding': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-top': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-right': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-bottom': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'padding-left': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'gap': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'row-gap': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'column-gap': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'border-width': { type: 'length', units: ['px', 'em'] },
        'border-radius': { type: 'length', units: ['px', '%', 'em', 'rem'] },
        'font-size': { type: 'length', units: ['px', 'em', 'rem', '%', 'vw'] },
        'line-height': { type: 'length', units: ['px', 'em', 'rem', '%', ''] },  // '' = unitless
        'letter-spacing': { type: 'length', units: ['px', 'em', 'normal'] },
        'word-spacing': { type: 'length', units: ['px', 'em', 'normal'] },
        'outline-width': { type: 'length', units: ['px', 'em'] },
        'outline-offset': { type: 'length', units: ['px', 'em'] },
        
        // Enumerated values - dropdown
        'visibility': { type: 'enum', values: ['visible', 'hidden', 'collapse'] },
        'display': { type: 'enum', values: ['block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'none', 'contents'] },
        'overflow': { type: 'enum', values: ['visible', 'hidden', 'scroll', 'auto', 'clip'] },
        'overflow-x': { type: 'enum', values: ['visible', 'hidden', 'scroll', 'auto', 'clip'] },
        'overflow-y': { type: 'enum', values: ['visible', 'hidden', 'scroll', 'auto', 'clip'] },
        'position': { type: 'enum', values: ['static', 'relative', 'absolute', 'fixed', 'sticky'] },
        'pointer-events': { type: 'enum', values: ['auto', 'none'] },
        'cursor': { type: 'enum', values: ['auto', 'default', 'pointer', 'grab', 'grabbing', 'text', 'crosshair', 'move', 'not-allowed', 'wait', 'progress', 'help', 'none'] },
        'text-align': { type: 'enum', values: ['left', 'center', 'right', 'justify', 'start', 'end'] },
        'text-decoration': { type: 'enum', values: ['none', 'underline', 'overline', 'line-through'] },
        'font-weight': { type: 'enum', values: ['normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900'] },
        'font-style': { type: 'enum', values: ['normal', 'italic', 'oblique'] },
        'white-space': { type: 'enum', values: ['normal', 'nowrap', 'pre', 'pre-wrap', 'pre-line', 'break-spaces'] },
        'flex-direction': { type: 'enum', values: ['row', 'row-reverse', 'column', 'column-reverse'] },
        'flex-wrap': { type: 'enum', values: ['nowrap', 'wrap', 'wrap-reverse'] },
        'justify-content': { type: 'enum', values: ['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly', 'start', 'end'] },
        'align-items': { type: 'enum', values: ['flex-start', 'flex-end', 'center', 'baseline', 'stretch', 'start', 'end'] },
        'align-content': { type: 'enum', values: ['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'stretch', 'start', 'end'] },
        'align-self': { type: 'enum', values: ['auto', 'flex-start', 'flex-end', 'center', 'baseline', 'stretch'] },
        
        // Number properties (unitless)
        'z-index': { type: 'number', step: 1 },
        'flex-grow': { type: 'number', min: 0, step: 1 },
        'flex-shrink': { type: 'number', min: 0, step: 1 },
        'order': { type: 'number', step: 1 },
        
        // Angle properties
        'rotate': { type: 'angle', units: ['deg', 'rad', 'turn'] },
        
        // Scale (unitless numbers, can be space-separated for X Y)
        'scale': { type: 'text' },  // e.g., "1.5" or "1.2 0.8"
        
        // Complex properties - text input fallback
        'transform': { type: 'transform' },  // no dedicated widget — QSValueInput falls through to a text field
        'filter': { type: 'text' },
        'box-shadow': { type: 'text' },
        'text-shadow': { type: 'text' },
        'clip-path': { type: 'text' },
        'background': { type: 'text' },
        'background-image': { type: 'text' },
        'transition': { type: 'text' },
        'animation': { type: 'text' },
        'translate': { type: 'text' },
        'skew': { type: 'text' }
    };
    
    /**
     * CSS Properties organized by category for property selector dropdowns
     * Used by QSPropertySelector class. A category's id names its label's word: preview.propertyCategory<id>.
     */
    const CSS_PROPERTY_CATEGORIES = {
        'Layout': [
            'display', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'box-sizing', 'overflow', 'overflow-x', 'overflow-y'
        ],
        'Flexbox': [
            'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
            'gap', 'row-gap', 'column-gap', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
            'align-self', 'order'
        ],
        'Grid': [
            'grid-template-columns', 'grid-template-rows', 'grid-gap', 'grid-column-gap',
            'grid-row-gap', 'grid-auto-flow', 'grid-column', 'grid-row', 'place-items',
            'place-content', 'place-self'
        ],
        'Position': [
            'position', 'top', 'right', 'bottom', 'left', 'z-index', 'inset'
        ],
        'Typography': [
            'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
            'letter-spacing', 'word-spacing', 'text-align', 'text-decoration', 
            'text-transform', 'white-space', 'word-break', 'text-overflow'
        ],
        'Colors': [
            'color', 'background-color', 'background', 'background-image',
            'border-color', 'outline-color', 'fill', 'stroke'
        ],
        'Borders': [
            'border', 'border-width', 'border-style', 'border-color', 'border-radius',
            'border-top', 'border-right', 'border-bottom', 'border-left',
            'outline', 'outline-width', 'outline-style', 'outline-offset'
        ],
        'Effects': [
            'opacity', 'visibility', 'box-shadow', 'text-shadow', 'filter',
            'backdrop-filter', 'mix-blend-mode', 'clip-path'
        ],
        'Transform': [
            'transform', 'transform-origin', 'perspective', 'translate', 'rotate', 'scale'
        ],
        'Transition': [
            'transition', 'transition-property', 'transition-duration', 
            'transition-timing-function', 'transition-delay'
        ],
        'Animation': [
            'animation', 'animation-name', 'animation-duration', 'animation-timing-function',
            'animation-delay', 'animation-iteration-count', 'animation-direction', 'animation-fill-mode'
        ],
        'Other': [
            'cursor', 'pointer-events', 'user-select', 'content', 'list-style',
            'object-fit', 'object-position', 'aspect-ratio'
        ]
    };
    
    /**
     * QSPropertySelector - Reusable searchable CSS property selector dropdown
     * Can be used anywhere a property needs to be selected from a categorized list
     * 
     * @example
     * const selector = new QSPropertySelector({
     *     container: document.getElementById('my-container'),
     *     onSelect: (property) => console.log('Selected:', property),
     *     excludeProperties: ['color', 'background'] // optional
     * });
     */
    class QSPropertySelector {
        constructor(options) {
            this.container = options.container;
            this.onSelect = options.onSelect || (() => {});
            this.excludeProperties = new Set(options.excludeProperties || []);
            this.placeholder = options.placeholder || QSPanelWords.t('preview.selectProperty');
            this.searchPlaceholder = options.searchPlaceholder || QSPanelWords.t('preview.searchProperties');
            this.currentValue = options.currentValue || '';
            this.showCategoryLabels = options.showCategoryLabels !== false;
            
            this.dropdownEl = null;
            this.triggerEl = null;
            this.isOpen = false;
            this.focusedIndex = -1;
            this.allItems = [];
            
            this._closeHandler = null;
            
            this.render();
        }
        
        render() {
            // Clear container
            this.container.replaceChildren();
            this.container.className = 'qs-property-selector';
            
            // Create trigger button
            this.triggerEl = document.createElement('button');
            this.triggerEl.type = 'button';
            this.triggerEl.className = 'qs-property-selector__trigger';
            this.triggerEl.append(
                QSDom.el('span', { class: 'qs-property-selector__text', text: this.currentValue || this.placeholder }),
                ' ',
                QSDom.iconEl(QuickSiteUtils.ICON_PATHS.chevronDown, 12)
            );
            
            this.triggerEl.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.isOpen) {
                    this.close();
                } else {
                    this.open();
                }
            });
            
            this.container.appendChild(this.triggerEl);
        }
        
        open() {
            if (this.isOpen) return;
            this.isOpen = true;
            this.triggerEl.classList.add('qs-property-selector__trigger--open');
            
            // Create dropdown
            this.dropdownEl = document.createElement('div');
            this.dropdownEl.className = 'qs-property-selector__dropdown';
            
            // Position dropdown using fixed positioning (to escape overflow containers)
            const triggerRect = this.triggerEl.getBoundingClientRect();
            this.dropdownEl.style.top = (triggerRect.bottom + 4) + 'px';
            this.dropdownEl.style.left = triggerRect.left + 'px';
            this.dropdownEl.style.minWidth = Math.max(triggerRect.width, 200) + 'px';
            
            // Search input
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'qs-property-selector__search';
            searchInput.placeholder = this.searchPlaceholder;
            searchInput.value = this.currentValue || '';
            this.dropdownEl.appendChild(searchInput);
            
            // List container
            const list = document.createElement('div');
            list.className = 'qs-property-selector__list';
            this.dropdownEl.appendChild(list);
            
            // Render initial list
            this._renderList(list, searchInput.value);
            
            // Search filtering with keyboard nav
            searchInput.addEventListener('input', (e) => {
                this._renderList(list, e.target.value);
            });
            
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.focusedIndex = Math.min(this.focusedIndex + 1, this.allItems.length - 1);
                    this._updateFocusedItem();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.focusedIndex = Math.max(this.focusedIndex - 1, 0);
                    this._updateFocusedItem();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (this.focusedIndex >= 0 && this.allItems[this.focusedIndex]) {
                        this.allItems[this.focusedIndex].click();
                    } else if (e.target.value.trim()) {
                        // Allow custom property entry
                        const customVal = e.target.value.trim();
                        if (!this.excludeProperties.has(customVal)) {
                            this.select(customVal);
                        } else {
                            showToast(QSPanelWords.t('preview.propertyAlreadyExists'), 'warning');
                        }
                    }
                } else if (e.key === 'Escape') {
                    this.close();
                }
            });
            
            // Append to document.body so it escapes overflow containers
            document.body.appendChild(this.dropdownEl);
            
            // Focus search
            setTimeout(() => searchInput.focus(), 0);
            
            // Close on outside click
            this._closeHandler = (e) => {
                if (!this.container.contains(e.target) && !this.dropdownEl?.contains(e.target)) {
                    this.close();
                }
            };
            setTimeout(() => document.addEventListener('click', this._closeHandler), 0);
        }
        
        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            
            if (this.dropdownEl) {
                this.dropdownEl.remove();
                this.dropdownEl = null;
            }
            this.triggerEl.classList.remove('qs-property-selector__trigger--open');
            
            if (this._closeHandler) {
                document.removeEventListener('click', this._closeHandler);
                this._closeHandler = null;
            }
            
            this.focusedIndex = -1;
            this.allItems = [];
        }
        
        select(property) {
            this.currentValue = property;
            this.triggerEl.querySelector('.qs-property-selector__text').textContent = property;
            this.close();
            this.onSelect(property);
        }
        
        setValue(property) {
            this.currentValue = property;
            this.triggerEl.querySelector('.qs-property-selector__text').textContent = property || this.placeholder;
        }
        
        getValue() {
            return this.currentValue;
        }
        
        setExcludeProperties(properties) {
            this.excludeProperties = new Set(properties);
        }
        
        destroy() {
            this.close();
            this.container.replaceChildren();
        }
        
        _renderList(listEl, filter = '') {
            listEl.replaceChildren();
            this.allItems = [];
            const filterLower = filter.toLowerCase().trim();
            
            // Render each category
            for (const [category, properties] of Object.entries(CSS_PROPERTY_CATEGORIES)) {
                // Filter by search AND exclude existing properties
                const filtered = properties.filter(p => 
                    (!filterLower || p.toLowerCase().includes(filterLower)) &&
                    !this.excludeProperties.has(p)
                );
                
                if (filtered.length === 0) continue;
                
                // Category group
                const group = document.createElement('div');
                group.className = 'qs-property-selector__group';
                
                // Category label
                if (this.showCategoryLabels) {
                    const label = document.createElement('div');
                    label.className = 'qs-property-selector__group-label';
                    label.textContent = QSPanelWords.t('preview.propertyCategory' + category);
                    group.appendChild(label);
                }
                
                // Property items
                for (const prop of filtered) {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'qs-property-selector__item';
                    item.dataset.property = prop;
                    
                    const propType = KEYFRAME_PROPERTY_TYPES[prop];
                    const typeLabel = propType?.type && propType.type !== 'text' ? propType.type : '';
                    
                    item.append(QSDom.el('span', { text: prop }));
                    if (typeLabel) item.append(' ', QSDom.el('span', { class: 'qs-property-selector__item-type', text: typeLabel }));
                    
                    item.addEventListener('click', () => this.select(prop));
                    item.addEventListener('mouseenter', () => {
                        this.focusedIndex = this.allItems.indexOf(item);
                        this._updateFocusedItem();
                    });
                    
                    group.appendChild(item);
                    this.allItems.push(item);
                }
                
                listEl.appendChild(group);
            }
            
            // Add "Custom" option for manual entry (but warn if property exists)
            if (filterLower && !this.allItems.some(item => item.dataset.property === filterLower)) {
                if (this.excludeProperties.has(filterLower) || this.excludeProperties.has(filter)) {
                    // Show warning
                    const warningItem = document.createElement('div');
                    warningItem.className = 'qs-property-selector__warning';
                    warningItem.append(QSDom.iconEl(QuickSiteUtils.ICON_PATHS.alertCircle, 14), ' ',
                        QSDom.el('span', { text: QSPanelWords.t('preview.propertyAlreadyExists') }));
                    listEl.appendChild(warningItem);
                } else {
                    const customItem = document.createElement('button');
                    customItem.type = 'button';
                    customItem.className = 'qs-property-selector__item qs-property-selector__item--custom';
                    customItem.appendChild(QSDom.el('span', {}, [QSPanelWords.t('preview.useCustomProperty') + ' ', QSDom.el('strong', { text: filter })]));
                    customItem.addEventListener('click', () => this.select(filter));
                    listEl.appendChild(customItem);
                    this.allItems.push(customItem);
                }
            }
            
            // Show empty state
            if (this.allItems.length === 0 && !filterLower) {
                listEl.replaceChildren(QSDom.el('div', { class: 'qs-property-selector__empty', text: QSPanelWords.t('preview.noPropertiesFound') }));
            }
            
            this.focusedIndex = -1;
        }
        
        _updateFocusedItem() {
            this.allItems.forEach((item, i) => {
                item.classList.toggle('qs-property-selector__item--focused', i === this.focusedIndex);
            });
            if (this.focusedIndex >= 0 && this.allItems[this.focusedIndex]) {
                this.allItems[this.focusedIndex].scrollIntoView({ block: 'nearest' });
            }
        }
    }
    
    /**
     * QSValueInput - Reusable CSS value input component
     * Creates appropriate input controls based on CSS property type:
     * - range: Slider with a number to type beside it, the two kept in step
     * - length: Number input + unit dropdown
     * - enum: Dropdown with predefined values
     * - number: Number input with step
     * - color: Text input with optional color swatch
     * - text: Plain text input (default)
     * 
     * Supports two class naming conventions:
     * - 'qs-value-input' (default): Uses BEM-style classes (qs-value-input--range, qs-value-input__range)
     * - 'preview-style-property': Uses existing Selectors panel classes (preview-style-property__range-container)
     */
    class QSValueInput {
        /**
         * @param {object} options
         * @param {HTMLElement} options.container - Container element
         * @param {string} options.property - CSS property name
         * @param {string} options.value - Initial value
         * @param {function} options.onChange - Callback when value changes
         * @param {function} options.onBlur - Callback on blur (for live preview)
         * @param {string} options.className - CSS class prefix ('qs-value-input' or 'preview-style-property')
         * @param {boolean} options.showColorSwatch - Whether to show color swatch for color properties
         * @param {function} options.onColorPick - Callback when color swatch is clicked (receives property, value, swatchEl)
         */
        constructor(options) {
            this.container = options.container;
            this.property = options.property || '';
            this.currentValue = options.value || '';
            this.onChange = options.onChange || (() => {});
            this.onBlur = options.onBlur || (() => {});
            this.classPrefix = options.className || 'qs-value-input';
            this.showColorSwatch = options.showColorSwatch !== false; // Default true
            this.onColorPick = options.onColorPick || null;
            
            // Use legacy class names for preview-style-property prefix
            this.useLegacyClasses = this.classPrefix === 'preview-style-property';
            
            this.inputEl = null;
            this.unitSelectEl = null;
            this.colorSwatchEl = null;
            this.rangeNumberEl = null;
            
            this._render();
        }
        
        /**
         * Check if property is a color property
         */
        _isColorProperty() {
            const colorProps = ['color', 'background', 'border', 'outline', 'fill', 'stroke', 'shadow', 'caret'];
            const propType = KEYFRAME_PROPERTY_TYPES[this.property];
            return propType?.type === 'color' || colorProps.some(cp => this.property.includes(cp));
        }
        
        /**
         * Get class name based on naming convention
         */
        _getClass(type) {
            if (this.useLegacyClasses) {
                // Legacy class names for Selectors panel
                const legacyMap = {
                    'container-range': 'preview-style-property__range-container',
                    'range': 'preview-style-property__range',
                    'range-number': 'preview-style-property__range-number',
                    'container-length': 'preview-style-property__length-container',
                    'number': 'preview-style-property__length-input',
                    'unit': 'preview-style-property__unit-select',
                    'select': 'preview-style-property__enum-select',
                    'input': 'preview-style-property__input',
                    'color-swatch': 'preview-style-property__color'
                };
                return legacyMap[type] || `${this.classPrefix}__${type}`;
            }
            // New BEM-style class names
            return `${this.classPrefix}__${type}`;
        }
        
        _render() {
            this.container.replaceChildren();
            this.colorSwatchEl = null;
            this.rangeNumberEl = null;
            this.container.className = this.useLegacyClasses ? '' : this.classPrefix;
            
            const propType = KEYFRAME_PROPERTY_TYPES[this.property] || { type: 'text' };
            
            switch (propType.type) {
                case 'range':
                    this._renderRange(propType);
                    break;
                case 'length':
                    this._renderLength(propType);
                    break;
                case 'enum':
                    this._renderEnum(propType);
                    break;
                case 'number':
                    this._renderNumber(propType);
                    break;
                case 'color':
                    this._renderText(); // Color swatch handled externally
                    break;
                case 'angle':
                    this._renderLength(propType); // Same as length but with angle units
                    break;
                default:
                    this._renderText();
            }
        }
        
        _renderRange(propType) {
            if (this.useLegacyClasses) {
                this.container.className = this._getClass('container-range');
            } else {
                this.container.classList.add(`${this.classPrefix}--range`);
            }
            
            const min = propType.min ?? 0;
            const max = propType.max ?? 1;
            const step = propType.step ?? 0.01;

            const range = document.createElement('input');
            range.type = 'range';
            range.className = this._getClass('range');
            range.min = min;
            range.max = max;
            range.step = step;
            range.value = parseFloat(this.currentValue) || propType.min || 0;

            // The same value as a number to type, with the slider's bounds and step
            const number = document.createElement('input');
            number.type = 'number';
            number.className = this._getClass('range-number');
            number.min = min;
            number.max = max;
            number.step = step;
            number.value = range.value;
            number.setAttribute('aria-label', this.property);

            range.addEventListener('input', () => {
                this.currentValue = range.value;
                number.value = range.value;
                this.onChange(range.value);
            });

            range.addEventListener('blur', () => this.onBlur(this.currentValue));

            // While typing, a number within the bounds moves the slider at once
            number.addEventListener('input', () => {
                const typed = Number(number.value);
                if (number.value.trim() === '' || !Number.isFinite(typed) || typed < min || typed > max) return;
                range.value = number.value;
                this.currentValue = number.value;
                this.onChange(number.value);
            });

            // On leaving the field, a number outside the bounds is brought to the nearest
            // one, and anything that is not a number goes back to the last good value
            const settle = () => {
                const typed = Number(number.value);
                if (number.value.trim() === '' || !Number.isFinite(typed)) {
                    number.value = range.value;
                    return;
                }
                const clamped = Math.min(max, Math.max(min, typed));
                if (clamped !== typed) {
                    number.value = String(clamped);
                    range.value = String(clamped);
                    this.currentValue = String(clamped);
                    this.onChange(this.currentValue);
                }
            };
            number.addEventListener('change', settle);
            number.addEventListener('blur', () => {
                settle();
                this.onBlur(this.currentValue);
            });

            this.inputEl = range;
            this.rangeNumberEl = number;
            this.container.appendChild(range);
            this.container.appendChild(number);
        }
        
        _renderLength(propType) {
            if (this.useLegacyClasses) {
                this.container.className = this._getClass('container-length');
            } else {
                this.container.classList.add(`${this.classPrefix}--length`);
            }
            
            // Parse current value
            const parsed = this._parseLength(this.currentValue, propType);
            
            const numInput = document.createElement('input');
            numInput.type = 'text';
            numInput.className = this._getClass('number');
            numInput.value = parsed.num;
            numInput.placeholder = '0';
            
            const unitSelect = document.createElement('select');
            unitSelect.className = this._getClass('unit');
            for (const unit of (propType.units || ['px', '%', 'em', 'rem'])) {
                const opt = document.createElement('option');
                opt.value = unit;
                opt.textContent = unit || '—';
                if (unit === parsed.unit) opt.selected = true;
                unitSelect.appendChild(opt);
            }
            
            const keywords = ['auto', 'none', 'normal', 'inherit', 'initial', 'unset'];
            
            const emitChange = () => {
                const num = numInput.value.trim();
                const unit = unitSelect.value;
                
                if (keywords.includes(num.toLowerCase())) {
                    // User typed a keyword in the text input — use it directly
                    this.currentValue = num.toLowerCase();
                } else if (keywords.includes(unit)) {
                    // Keyword selected as unit — use the unit as the value
                    this.currentValue = unit;
                } else {
                    this.currentValue = num ? num + unit : '';
                }
                this.onChange(this.currentValue);
            };
            
            // Disable number input when a keyword unit is selected
            if (keywords.includes(parsed.unit)) {
                numInput.disabled = true;
            }
            
            numInput.addEventListener('input', emitChange);
            numInput.addEventListener('blur', () => this.onBlur(this.currentValue));
            unitSelect.addEventListener('change', () => {
                const isKeyword = keywords.includes(unitSelect.value);
                numInput.disabled = isKeyword;
                if (isKeyword) numInput.value = '';
                emitChange();
                this.onBlur(this.currentValue);
            });
            
            this.inputEl = numInput;
            this.unitSelectEl = unitSelect;
            this.container.appendChild(numInput);
            this.container.appendChild(unitSelect);
        }
        
        _renderEnum(propType) {
            if (!this.useLegacyClasses) {
                this.container.classList.add(`${this.classPrefix}--enum`);
            }
            
            const select = document.createElement('select');
            select.className = this._getClass('select');
            
            for (const val of (propType.values || [])) {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                if (val === this.currentValue) opt.selected = true;
                select.appendChild(opt);
            }
            
            select.addEventListener('change', () => {
                this.currentValue = select.value;
                this.onChange(this.currentValue);
                this.onBlur(this.currentValue);
            });
            
            this.inputEl = select;
            this.container.appendChild(select);
        }
        
        _renderNumber(propType) {
            if (!this.useLegacyClasses) {
                this.container.classList.add(`${this.classPrefix}--number`);
            }
            
            const input = document.createElement('input');
            input.type = 'number';
            input.className = this._getClass('input');
            input.value = parseFloat(this.currentValue) || 0;
            input.step = propType.step ?? 1;
            if (propType.min !== undefined) input.min = propType.min;
            if (propType.max !== undefined) input.max = propType.max;
            
            input.addEventListener('input', () => {
                this.currentValue = input.value;
                this.onChange(this.currentValue);
            });
            
            input.addEventListener('blur', () => this.onBlur(this.currentValue));
            
            this.inputEl = input;
            this.container.appendChild(input);
        }
        
        _renderText() {
            if (!this.useLegacyClasses) {
                this.container.classList.add(`${this.classPrefix}--text`);
            }
            
            // Add color swatch for color properties
            const isColor = this._isColorProperty();
            if (isColor && this.showColorSwatch) {
                this._renderColorSwatch();
            }
            
            const input = document.createElement('input');
            input.type = 'text';
            input.className = this._getClass('input');
            input.value = this.currentValue;
            input.placeholder = isColor ? '#ffffff' : '';
            
            input.addEventListener('input', () => {
                this.currentValue = input.value;
                this.onChange(this.currentValue);
                // Update color swatch
                if (this.colorSwatchEl) {
                    this.colorSwatchEl.style.background = this.currentValue || '#ffffff';
                }
            });
            
            input.addEventListener('blur', () => this.onBlur(this.currentValue));
            
            this.inputEl = input;
            this.container.appendChild(input);
        }
        
        /**
         * Render color swatch button
         */
        _renderColorSwatch() {
            const swatch = document.createElement('button');
            swatch.type = 'button';
            swatch.className = this._getClass('color-swatch');
            swatch.style.background = this.currentValue || '#ffffff';
            swatch.title = QSPanelWords.t('preview.clickToPickColor');
            
            swatch.addEventListener('click', () => {
                if (this.onColorPick) {
                    this.onColorPick(this.property, this.currentValue, swatch);
                }
            });
            
            this.colorSwatchEl = swatch;
            this.container.appendChild(swatch);
        }
        
        _parseLength(value, propType) {
            if (!value || value === 'auto' || value === 'none' || value === 'normal') {
                return { num: '', unit: value || (propType.units?.[0] || 'px') };
            }
            const match = String(value).match(/^(-?[\d.]+)(.*)$/);
            if (match) {
                const unit = match[2].trim() || propType.units?.[0] || 'px';
                return { num: match[1], unit };
            }
            return { num: '', unit: propType.units?.[0] || 'px' };
        }
        
        /**
         * Set property and re-render input
         * @param {string} property - CSS property name
         */
        setProperty(property) {
            this.property = property;
            this._render();
        }
        
        /**
         * Set value
         * @param {string} value - CSS value
         */
        setValue(value) {
            this.currentValue = value;
            
            // Update input element based on type
            const propType = KEYFRAME_PROPERTY_TYPES[this.property] || { type: 'text' };
            
            if (propType.type === 'range') {
                if (this.inputEl) this.inputEl.value = parseFloat(value) || 0;
                if (this.rangeNumberEl && this.inputEl) this.rangeNumberEl.value = this.inputEl.value;
            } else if (propType.type === 'length' || propType.type === 'angle') {
                const parsed = this._parseLength(value, propType);
                if (this.inputEl) this.inputEl.value = parsed.num;
                if (this.unitSelectEl) this.unitSelectEl.value = parsed.unit;
            } else if (this.inputEl) {
                this.inputEl.value = value;
            }
            
            // Update color swatch if present
            if (this.colorSwatchEl) {
                this.colorSwatchEl.style.background = value || '#ffffff';
            }
        }
        
        /**
         * Get current value
         * @returns {string}
         */
        getValue() {
            return this.currentValue;
        }
        
        /**
         * Focus the input
         */
        focus() {
            if (this.inputEl) this.inputEl.focus();
        }
        
        /**
         * Destroy and cleanup
         */
        destroy() {
            this.container.replaceChildren();
        }
    }
    
    // ==================== Transition Editor ====================
    // The modal the UI calls "State & Animation Editor" — Style tool →
    // Selectors → pick a selector → [Animate]. There is no "Transition editor"
    // label anywhere in the interface; the code name and the UI name differ.
    // Functionality moved to preview-transition-editor.js module

    /**
     * Open the Transition Editor (delegates to module); mediaQuery: the rule's, when it sits
     * inside @media
     */
    function openTransitionEditor(selector, onSave = null, mediaQuery = null) {
        if (window.PreviewTransitionEditor) {
            PreviewTransitionEditor.open(selector, onSave, mediaQuery);
        }
    }
    // Exposed for the other modules (the Motion tab's animated selectors open it)
    window.openTransitionEditor = openTransitionEditor;
    
    /**
     * Close the Transition Editor (delegates to module)
     */
    function closeTransitionEditor() {
        if (window.PreviewTransitionEditor) {
            PreviewTransitionEditor.close();
        }
    }
    
    /**
     * Initialize the Transition Editor module
     */
    function initTransitionEditor() {
        if (window.PreviewTransitionEditor) {
            PreviewTransitionEditor.setShowToast(showToast);
            PreviewTransitionEditor.setParseStylesString(parseStylesString);
            PreviewTransitionEditor.setRefreshPreviewFrame(() => {
                if (iframe?.contentWindow) {
                    iframe.contentWindow.location.reload();
                }
            });
            PreviewTransitionEditor.setOpenAnimationPreviewModal(
                window.PreviewStyleMotion?.openAnimationPreviewModal || function() {}
            );
            // The keyframe list belongs to the Motion tab's module, which fills
            // it from a real fetch. Read it at call time — this file keeps no
            // copy of its own.
            PreviewTransitionEditor.setGetKeyframesData(
                () => window.PreviewStyleMotion?.getKeyframesData?.() || []
            );
            // …and load it on demand, because Selectors → [Animate] can reach
            // the preview without the Motion tab ever having been opened. Same
            // isLoaded/load pattern the style tabs use in initStyleTabs().
            PreviewTransitionEditor.setEnsureKeyframesData(async () => {
                const motion = window.PreviewStyleMotion;
                if (motion && motion.isLoaded && !motion.isLoaded() && motion.load) {
                    await motion.load();
                }
            });
            PreviewTransitionEditor.setGetThemeVariables(() => {
                if (window.PreviewStyleTheme && PreviewStyleTheme.isLoaded()) {
                    return PreviewStyleTheme.getCurrent();
                }
                return {};
            });
            PreviewTransitionEditor.init();
        }
    }

    // ==================== URL Building ====================
    
    function getCurrentLang() {
        return langSelect ? langSelect.value : defaultLang;
    }
    
    function buildUrl(editType, editName) {
        // previewBase, not baseUrl — editing a non-served project must stay
        // inside its /p/<id>/ view (picking a page used to jump the iframe
        // back to the main project).
        let url = previewBase + '/';
        
        if (editType === 'component') {
            // Component preview - standalone component render
            // Include language prefix so component translation follows toolbar language.
            if (multilingual) {
                url += getCurrentLang() + '/';
            }
            url += '?_component=' + encodeURIComponent(editName) + '&_editor=1';
            // Include emulation data if available
            try {
                const raw = localStorage.getItem(QuickSiteStorageKeys.emulateComponent + editName);
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed && typeof parsed === 'object' && Object.keys(parsed).length > 0) {
                        url += '&_emulate=' + encodeURIComponent(btoa(raw));
                    }
                }
            } catch(e) {}
        } else if (editType === 'layout') {
            // Layout preview (menu/footer) - load home page where they're visible
            if (multilingual) {
                url += getCurrentLang() + '/';
            }
            // Load home page (menu/footer are always visible on home by default)
            url += (url.includes('?') ? '&' : '?') + '_editor=1';
        } else {
            // Page preview - normal route
            if (multilingual) {
                url += getCurrentLang() + '/';
            }
            if (editName) {
                // Param routes (route names containing ':')
                // need a concrete URL segment per param OR the public
                // 404 fires before the resolver / template can run.
                // Substitute each ':name' with the emulated value if
                // saved (or a sensible fallback) so the iframe lands on
                // a matchable URL. The emulation override in
                // public/index.php then re-overrides routeParams() so
                // templates see the EDITOR'S values regardless of what
                // the URL segment happened to be.
                let urlPath = editName;
                if (urlPath.includes(':')) {
                    const emulated = _getPageEmulationData(editName);
                    const paramOverrides = emulated && emulated.routeParams || {};
                    urlPath = urlPath.split('/').map(seg => {
                        if (!seg.startsWith(':')) return seg;
                        const name = seg.slice(1);
                        const v = paramOverrides[name];
                        return (v != null && v !== '') ? encodeURIComponent(v) : 'preview-' + name;
                    }).join('/');
                }
                url += urlPath;
            }
            // Always add _editor=1 for editor mode
            url += (url.includes('?') ? '&' : '?') + '_editor=1';
            // Append page-emulation payload from localStorage
            // when present. Encoded as base64(JSON({routeParams, resolved})).
            // Server-side public/index.php decodes + overrides routeParams
            // / resolved before the template renders.
            //
            // When the panel is in "Use Live Data" mode, add
            // _live=1 so the real resolver fires server-side. In that
            // case the _emulate payload carries only routeParams (the
            // resolved overrides would defeat the purpose of going live).
            const __pageEmulation = _getPageEmulationData(editName);
            const __useLive = !!(__pageEmulation && __pageEmulation.useLive);
            if (__useLive) {
                url += '&_live=1';
            }
            if (__pageEmulation) {
                const __payload = {};
                if (Object.keys(__pageEmulation.routeParams || {}).length > 0) {
                    __payload.routeParams = __pageEmulation.routeParams;
                }
                if (!__useLive && Object.keys(__pageEmulation.resolved || {}).length > 0) {
                    __payload.resolved = __pageEmulation.resolved;
                }
                if (Object.keys(__payload).length > 0) {
                    try {
                        url += '&_emulate=' + encodeURIComponent(btoa(JSON.stringify(__payload)));
                    } catch(e) { /* btoa fails on unicode — swallow; emulation simply absent */ }
                }
            }
        }
        return url;
    }

    /**
     * Read the editor's saved page-emulation values for a
     * route. Storage key is namespaced (qs_emulate_page_*) so it can't
     * collide with the component-emulation pattern (qs_emulate_*).
     *
     * Returns {routeParams: {...}, resolved: {...}} or null when nothing
     * is saved / parse failed. Both sub-objects default to empty when
     * absent so callers can read them safely.
     */
    function _getPageEmulationData(routePath) {
        if (!routePath) return null;
        const storageKey = QuickSiteStorageKeys.emulatePage + routePath;
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return null;
            return {
                routeParams: (parsed.routeParams && typeof parsed.routeParams === 'object') ? parsed.routeParams : {},
                resolved:    (parsed.resolved    && typeof parsed.resolved    === 'object') ? parsed.resolved    : {},
                // Live-data toggle. true → editor preview
                // fires the real resolver instead of using the resolved
                // overrides. Default false. Persists per page in
                // localStorage alongside the rest of the emulation state.
                useLive:     !!parsed.useLive,
            };
        } catch(e) {
            return null;
        }
    }

    /**
     * Derive the list of variables that the editor's
     * emulation panel should offer fields for, given a route path:
     *   - Route params: extract ':name' segments from the route pattern.
     *   - Resolved vars: read the resolver's `expose` keys from
     *     PreviewConfig.routeResolvers[routePath].expose.
     *
     * Returns {routeParams: ['slug', ...], resolved: ['product', ...]}.
     * Either array may be empty (static route + no resolver = no fields,
     * empty-state in the panel).
     */
    function _getPageEmulationVarNames(routePath) {
        const out = { routeParams: [], resolved: [] };
        if (!routePath) return out;
        routePath.split('/').forEach(seg => {
            if (seg.startsWith(':') && seg.length > 1) {
                out.routeParams.push(seg.slice(1));
            }
        });
        const resolvers = (PreviewConfig && PreviewConfig.routeResolvers) || {};
        const cfg = resolvers[routePath];
        if (cfg && cfg.expose && typeof cfg.expose === 'object') {
            out.resolved = Object.keys(cfg.expose);
        }
        return out;
    }
    
    // ==================== Loading ====================
    
    function showLoading() {
        loading.classList.add('preview-loading--visible');
    }
    
    function hideLoading() {
        loading.classList.remove('preview-loading--visible');
    }
    
    let loadingTimeout = null;
    function startLoadingTimeout() {
        clearTimeout(loadingTimeout);
        loadingTimeout = setTimeout(hideLoading, 5000);
    }
    
    // ==================== Navigation ====================
    
    function reloadPreview() {
        showLoading();
        startLoadingTimeout();
        overlayInjected = false;
        // Rebuild URL for component AND page previews so emulation
        // changes (qs_emulate_* / qs_emulate_page_*) propagate. Pages
        // previously used `iframe.src = iframe.src` which reused the OLD
        // URL — the updated _emulate query was never picked up, so
        // 'Apply Preview' on the page emulation panel silently did
        // nothing without a hard refresh. Layouts keep the self-assign
        // path since they don't emulate.
        if (currentEditType === 'component'
            || currentEditType === 'page'
            || currentEditType === undefined
            || currentEditType === null) {
            iframe.src = buildUrl(currentEditType, currentEditName);
        } else {
            iframe.src = iframe.src;
        }
    }
    
    /**
     * Hot-reload CSS in the iframe without a full page reload.
     * Finds the project's style.css <link> and cache-busts its href,
     * causing the browser to re-fetch the stylesheet while preserving
     * scroll position, focus, and DOM state.
     */
    function hotReloadCss() {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
            if (!iframeDoc) {
                console.warn('[Preview] hotReloadCss: no iframe document, falling back to full reload');
                reloadPreview();
                return;
            }
            const links = iframeDoc.querySelectorAll('link[rel="stylesheet"]');
            let found = false;
            links.forEach(function(link) {
                if (link.href && link.href.indexOf('style/style.css') !== -1) {
                    const base = link.href.split('?')[0];
                    link.href = base + '?v=' + Date.now();
                    found = true;
                }
            });
            if (!found) {
                console.warn('[Preview] hotReloadCss: style.css link not found, falling back to full reload');
                reloadPreview();
            }
        } catch (e) {
            console.error('[Preview] hotReloadCss error, falling back to full reload:', e);
            reloadPreview();
        }
    }

    // Register reloadPreview and hotReloadCss as callbacks for modules to use
    if (window.PreviewState) {
        PreviewState.setCallback('reloadPreview', reloadPreview);
        PreviewState.setCallback('hotReloadCss', hotReloadCss);
    }

    /** The frame on one structure: the loading veil, and the overlay injected again once it loads. */
    function loadFrame(editType, editName) {
        showLoading();
        startLoadingTimeout();
        overlayInjected = false;
        currentEditType = editType;
        currentEditName = editName;
        iframe.src = buildUrl(editType, editName);
    }

    /**
     * opts.keepMode: the tool stays open. For a move the author did not ask for, in the AI tools,
     * where a run's review and answer box are.
     */
    function navigateTo(editType, editName, opts) {
        loadFrame(editType, editName);
        
        // Reset tool state: switch back to select mode and clear selection.
        if (currentMode !== 'select' && !(opts && opts.keepMode)) {
            setMode('select');
        }
        hideNodePanel();
        
        // Hide Variables panel when navigating away
        if (variablesPanel && variablesPanel.style.display !== 'none') {
            hideVariablesPanel();
        }
        // Hide Enums panel when navigating away
        if (enumsPanel && enumsPanel.style.display !== 'none') {
            hideEnumsPanel();
        }
        
        // Update component warning banner
        updateComponentWarning(editType, editName);
        
        // Update iframe warning banner
        updateIframeWarning(editType, editName);
        
        // Update layout toggles visibility (show only for pages)
        updateLayoutToggles(editType, editName);
        // The page title and description fields follow the page and the language shown
        updatePageTitleField(editType, editName);
        updatePageDescriptionField(editType, editName);
        
        // A menu or a footer is brought into view once the iframe has loaded, as it can be below the
        // fold. Nothing is selected, as on a page: the tool's panel waits for a click.
        layoutToReveal = editType === 'layout' ? editName : null;
    }
    
    // ==================== The Edit list ====================

    /** The list's value, on the closed list too: the searchable select reads its select only when told. */
    function showEditTarget(value) {
        targetSelect.value = value;
        if (targetPicker) targetPicker.refresh();
    }

    /** The first page of the list, else the menu: where the panel opens on a project with no page. */
    function navigateToFirstPage(opts) {
        const firstPage = targetSelect.querySelector('option[value^="page:"]');
        const value = firstPage ? firstPage.value : 'layout:menu';
        showEditTarget(value);
        const colonIdx = value.indexOf(':');
        navigateTo(value.substring(0, colonIdx), value.substring(colonIdx + 1), opts);
    }

    /** The Components group. The toolbar prints it only on a project that has components. */
    function editListComponentsGroup() {
        return targetSelect.querySelector('optgroup[label*="🧩"]');
    }

    function _renderComponentsGroup() {
        return QSDom.el('optgroup', { label: '🧩 ' + QSPanelWords.t('preview.components') });
    }

    /** A new component's option, in its group, which is made for the first one. */
    function addEditListComponent(name) {
        const group = editListComponentsGroup() || targetSelect.appendChild(_renderComponentsGroup());
        return group.appendChild(_renderOption('component:' + name, name));
    }

    /** One of the two lists the Edit list is built from, or null: a read that fails changes nothing. */
    async function readEditListPart(command, field) {
        try {
            const result = await QuickSiteAdmin.apiRequest(command, 'GET');
            const list = result.ok && result.data && result.data.data ? result.data.data[field] : null;
            return Array.isArray(list) ? list : null;
        } catch (error) {
            console.warn('[Preview] ' + command + ' failed, the Edit list stays as it was:', error);
            return null;
        }
    }

    /** The Pages group whole: the routes, spelled as the toolbar prints them, then the special pages. */
    function rebuildEditListPages(routes) {
        const group = targetSelect.querySelector('optgroup[label*="📄"]');
        if (!group) return;
        const names = routes.concat(PreviewConfig.specialPages || []);
        group.replaceChildren(...names.map((name) => _renderOption('page:' + name, name)));
    }

    /** The Components group whole, ordered as the toolbar prints it; none left, no group. */
    function rebuildEditListComponents(components) {
        const names = components.map((component) => component.name).sort();
        let group = editListComponentsGroup();
        if (names.length === 0) {
            if (group) group.remove();
            return;
        }
        if (!group) group = targetSelect.appendChild(_renderComponentsGroup());
        group.replaceChildren(...names.map((name) => _renderOption('component:' + name, name)));
    }

    /**
     * The target that is gone is left. A title typed for it goes with it: leaving the field later
     * would save it on the page shown by then.
     */
    function leaveVanishedTarget() {
        const gone = currentEditName;
        if (pageTitleInput) {
            pageTitleInput.value = pageTitleSaved;
            pageTitleInput.blur();
        }
        if (pageDescriptionInput) {
            pageDescriptionInput.value = pageDescriptionSaved;
            pageDescriptionInput.blur();
        }
        navigateToFirstPage({ keepMode: currentMode === 'ai-tools' });
        const shown = targetSelect.options[targetSelect.selectedIndex];
        showToast(QSPanelWords.t('preview.editTargetGone', { name: gone, now: shown ? shown.textContent : '' }), 'info');
    }

    /** What getLangList answers, or null: the languages then stay as they were. */
    async function readLanguages() {
        try {
            const result = await QuickSiteAdmin.apiRequest('getLangList', 'GET');
            const data = result.ok && result.data ? result.data.data : null;
            return data && Array.isArray(data.languages) ? data : null;
        } catch (error) {
            console.warn('[Preview] getLangList failed, the languages stay as they were:', error);
            return null;
        }
    }

    /**
     * The languages this page holds follow the project's: a workflow can turn them on or off, or add
     * or delete one, while the page stays open, and the frame's address carries the language. The same
     * rule as the toolbar's first draw. Answers whether that address changed, in which case the page
     * in the frame is one the project no longer has.
     */
    function rebuildLanguageList(data) {
        const shownBefore = multilingual ? getCurrentLang() : null;
        multilingual = !!data.multilingual_enabled;
        defaultLang = data.default_language;
        PreviewConfig.multilingual = multilingual;
        PreviewConfig.defaultLang = defaultLang;
        if (translateCsvLang) translateCsvLang._loaded = false;
        const codes = multilingual ? data.languages : [defaultLang];
        const keep = codes.includes(langSelect.value) ? langSelect.value : defaultLang;
        langSelect.replaceChildren(...codes.map((code) => _renderOption(code, code.toUpperCase())));
        langSelect.value = keep;
        langSelect.closest('.preview-toolbar__group').style.display = multilingual && codes.length > 1 ? '' : 'none';
        return (multilingual ? getCurrentLang() : null) !== shownBefore;
    }

    /** One reading of the list. Answers whether the editor moved, off a target that is gone or to the address the languages now give it. */
    async function rebuildEditList() {
        const [routes, components, languages] = await Promise.all([
            readEditListPart('getRoutes', 'flat_routes'),
            readEditListPart('listComponents', 'components'),
            readLanguages(),
        ]);
        const addressMoved = languages ? rebuildLanguageList(languages) : false;
        if (routes) rebuildEditListPages(routes);
        if (components) rebuildEditListComponents(components);
        const target = currentEditType + ':' + currentEditName;
        if (Array.from(targetSelect.options).some((option) => option.value === target)) {
            showEditTarget(target);
            if (!addressMoved) return false;
            navigateTo(currentEditType, currentEditName, { keepMode: currentMode === 'ai-tools' });
            return true;
        }
        leaveVanishedTarget();
        return true;
    }

    let editListReading = null;     // the reading under way: every caller waits for it
    let editListChanged = false;    // something changed while it read, so it reads again

    /**
     * The Edit list follows what changed under it: the one rebuild, called by what changes a route, a
     * component or a language on this page without going through the list's own buttons (a wizard's
     * setup, a workflow's phase), before the preview reloads. It tells the page's other copies of the
     * routes (quicksite:routes-changed), and answers whether the editor moved, off a target that is
     * gone or to a new address: the move has loaded the preview, so the caller does not reload it.
     */
    function refreshEditList() {
        componentsLoaded = false;
        window.dispatchEvent(new CustomEvent('quicksite:routes-changed'));
        if (!targetSelect) return Promise.resolve(false);
        if (editListReading) {
            editListChanged = true;
            return editListReading;
        }
        editListReading = (async () => {
            let moved = false;
            try {
                do {
                    editListChanged = false;
                    moved = (await rebuildEditList()) || moved;
                } while (editListChanged);
            } catch (error) {
                console.warn('[Preview] The Edit list could not be rebuilt:', error);
            } finally {
                editListReading = null;
            }
            return moved;
        })();
        return editListReading;
    }

    // ==================== Component Warning Banner ====================
    
    /**
     * Show/hide component warning banner and fetch usage count
     */
    async function updateComponentWarning(editType, editName) {
        if (!componentWarning || !componentWarningText) return;
        
        // Update Text mode button visibility - hide when editing components
        updateTextModeVisibility(editType);
        
        // Show/hide the toolbar's bin of the component being edited, named for it
        if (deleteComponentBtn) {
            deleteComponentBtn.style.display = editType === 'component' ? 'inline-flex' : 'none';
            if (editType === 'component') {
                const label = QSPanelWords.t('preview.deleteComponentNamed', { name: editName });
                deleteComponentBtn.title = label;
                deleteComponentBtn.setAttribute('aria-label', label);
            }
        }
        
        // Show/hide Variables button (component-only)
        if (ctxNodeVariables) {
            ctxNodeVariables.style.display = editType === 'component' ? '' : 'none';
        }
        // Show/hide Enums button (component-only)
        if (ctxNodeEnums) {
            ctxNodeEnums.style.display = editType === 'component' ? '' : 'none';
        }
        // Show/hide Emulation button. Components: always shown.
        // Pages: shown ONLY when the route has variables to emulate
        // (':name' segments in the path OR a resolver in the sidecar).
        // Static routes with no resolver get no button — there's nothing
        // to emulate, so the button would just be noise.
        if (ctxNodeEmulation) {
            let showBtn = false;
            if (editType === 'component') {
                showBtn = true;
            } else if (editType === 'page' || editType === undefined || editType === null) {
                const vars = _getPageEmulationVarNames(editName);
                showBtn = vars.routeParams.length > 0 || vars.resolved.length > 0;
            }
            ctxNodeEmulation.style.display = showBtn ? '' : 'none';
        }
        // Hide Variables panel if open and switching away from component
        if (editType !== 'component' && variablesPanel && variablesPanel.style.display !== 'none') {
            hideVariablesPanel();
        }
        // Hide Enums panel if open and switching away from component
        if (editType !== 'component' && enumsPanel && enumsPanel.style.display !== 'none') {
            hideEnumsPanel();
        }
        // Hide Emulation panel if open and switching to layout (pages
        // keep it open — the user can switch pages with their emulation
        // panel still visible since each page has its own storage key).
        if (editType === 'layout' && emulationPanel && emulationPanel.style.display !== 'none') {
            hideEmulationPanel();
        }
        
        if (editType !== 'component') {
            componentWarning.style.display = 'none';
            return;
        }
        
        // Show banner immediately with loading state
        componentWarning.style.display = 'flex';
        componentWarningText.textContent = QSPanelWords.t('preview.componentWarningLoading');
        
        try {
            // Fetch usage count via findComponentUsages API (GET with name as URL param)
            const result = await QuickSiteAdmin.apiRequest('findComponentUsages', 'GET', null, [editName]);
            
            if (result.ok && result.data?.data) {
                const totalUsages = result.data.data.totalUsages || 0;
                componentWarningText.textContent = QSPanelWords.count('preview.componentWarningUsages', totalUsages);
            }
        } catch (error) {
            console.warn('[Preview] Failed to fetch component usages:', error);
            // Keep showing generic warning
        }
    }
    
    /**
     * Show/hide Text mode button based on edit type
     * Text mode is hidden when editing components (use Add Tag modal for text content)
     */
    function updateTextModeVisibility(editType) {
        const textModeBtn = document.querySelector('.preview-tool[data-mode="text"]');
        if (!textModeBtn) return;
        
        const isComponent = editType === 'component';
        textModeBtn.style.display = isComponent ? 'none' : '';
        
        // If currently in text mode and switching to component, reset to select mode
        if (isComponent && currentMode === 'text') {
            setMode('select');
        }
    }
    
    // ==================== Iframe Warning Banner ====================
    
    /**
     * Whether an embed takes the embed policy's default sandbox, the strictest the deployer set: its address
     * matches no host the policy lists. That is an address with no host of its own (a relative one, which
     * resolves to this site), an address naming no host the policy lists, and a srcdoc frame, which never
     * loads its src. The host is read and matched as IframeSandbox reads and matches it (extractHostname(),
     * matchesDomain(): the host itself, or any subdomain of it; an address carrying a user name has no host);
     * this site's own host is never one the policy can list, so it falls to the default too.
     *
     * @param {string} src the embed's address as authored
     * @param {boolean} hasSrcdoc whether the embed carries a srcdoc
     * @param {string[]} hosts the policy's host names (getIframeSandbox)
     * @returns {boolean}
     */
    function embedOutsidePolicy(src, hasSrcdoc, hosts) {
        if (hasSrcdoc) return true;
        const host = embedHost(src);
        if (host === null) return true;
        return !(hosts || []).some(name => {
            const d = String(name || '').trim().toLowerCase();
            return d !== '' && (host === d || host.endsWith('.' + d));
        });
    }

    /** The lower-case host an embed's address names, or null (IframeSandbox::extractHostname()). */
    function embedHost(src) {
        const s = String(src == null ? '' : src).trim();
        // A host is named only after "//": with a scheme ("https://") or scheme-relative ("//host"). Anything
        // else is a path, which resolves against this site.
        const m = /^(?:[A-Za-z][A-Za-z0-9+.-]*:)?\/\/([^/?#]*)/.exec(s);
        if (!m) return null;
        const authority = m[1];
        if (authority.indexOf('@') !== -1) return null;
        let host = authority;
        if (host.charAt(0) === '[') {
            const close = host.indexOf(']');
            host = close === -1 ? host : host.slice(0, close + 1);
        } else {
            const colon = host.lastIndexOf(':');
            if (colon !== -1) host = host.slice(0, colon);
        }
        host = host.toLowerCase();
        return host === '' ? null : host;
    }

    // The policy's host names, read once per page load (it changes only on the server's disk, by the deployer).
    let embedPolicyHostsPromise = null;
    function embedPolicyHosts() {
        if (!embedPolicyHostsPromise) {
            embedPolicyHostsPromise = QuickSiteAdmin.apiRequest('getIframeSandbox', 'GET').then(res => {
                const hosts = res && res.ok && res.data && res.data.data && Array.isArray(res.data.data.hosts) ? res.data.data.hosts : null;
                if (!hosts) throw new Error('getIframeSandbox');
                return hosts.map(h => String(h && h.name || ''));
            });
            embedPolicyHostsPromise.catch(() => { embedPolicyHostsPromise = null; });
        }
        return embedPolicyHostsPromise;
    }

    /** Every embed of a structure: [{src, srcdoc}] for each <iframe> node, nested ones included. */
    function embedsIn(structure) {
        const out = [];
        (function walk(node) {
            if (Array.isArray(node)) { node.forEach(walk); return; }
            if (!node || typeof node !== 'object') return;
            if (String(node.tag || '').toLowerCase() === 'iframe') {
                const params = (node.params && typeof node.params === 'object') ? node.params : {};
                out.push({ src: typeof params.src === 'string' ? params.src : '', srcdoc: Object.prototype.hasOwnProperty.call(params, 'srcdoc') });
            }
            if (Array.isArray(node.children)) node.children.forEach(walk);
        })(structure);
        return out;
    }

    /**
     * The embed warning: shown while the structure being edited (a page, the menu, the footer or a component)
     * holds an embed outside the embed policy (embedOutsidePolicy()), hidden otherwise and once dismissed.
     */
    async function updateIframeWarning(editType, editName) {
        if (!iframeWarning || iframeWarningDismissed) return;
        const urlParams = editType === 'page' ? ['page', ...String(editName).split('/')]
            : editType === 'component' ? ['component', editName]
            : editType === 'layout' ? [editName] : null;
        if (!urlParams || !editName) {
            iframeWarning.style.display = 'none';
            return;
        }
        try {
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
            const embeds = result.ok && result.data?.data ? embedsIn(result.data.data.structure) : [];
            if (embeds.length === 0) {
                iframeWarning.style.display = 'none';
                return;
            }
            const hosts = await embedPolicyHosts();
            // The answer for a structure no longer shown is dropped.
            if (editType !== currentEditType || editName !== currentEditName || iframeWarningDismissed) return;
            iframeWarning.style.display = embeds.some(e => embedOutsidePolicy(e.src, e.srcdoc, hosts)) ? 'flex' : 'none';
        } catch (e) {
            iframeWarning.style.display = 'none';
        }
    }

    if (iframeWarningClose) {
        iframeWarningClose.addEventListener('click', function() {
            iframeWarningDismissed = true;
            iframeWarning.style.display = 'none';
        });
    }
    
    // ==================== Delete Component ====================
    
    if (deleteComponentBtn) {
        deleteComponentBtn.addEventListener('click', async function() {
            if (currentEditType !== 'component' || !currentEditName) return;
            
            const name = currentEditName;
            const asked = await QuickSiteUtils.confirm(QSPanelWords.t('preview.deleteCannotBeUndone'), {
                title: QSPanelWords.t('preview.deleteComponentTitle', { name: name }),
                type: 'danger',
                confirmText: QSPanelWords.t('common.delete')
            });
            if (!asked) return;
            
            try {
                // Send empty structure to delete
                const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                    type: 'component',
                    name: name,
                    structure: []
                });
                
                if (result.ok) {
                    // Remove option from dropdown
                    const option = targetSelect.querySelector('option[value="component:' + name + '"]');
                    if (option) {
                        const optgroup = option.parentElement;
                        option.remove();
                        // Remove empty optgroup
                        if (optgroup && optgroup.tagName === 'OPTGROUP' && optgroup.children.length === 0) {
                            optgroup.remove();
                        }
                    }
                    
                    navigateToFirstPage();

                    // Show warnings if any
                    const warnings = result.data?.data?.warnings;
                    if (warnings && warnings.length > 0) {
                        showToast(QSPanelWords.t('preview.componentDeleted') + ' ⚠️ ' + warnings.join(', '), 'warning');
                    } else {
                        showToast(QSPanelWords.t('preview.componentDeleted'), 'success');
                    }
                } else {
                    const msg = result.data?.message || QSPanelWords.t('preview.componentDeleteFailed');
                    showToast(msg, 'error');
                }
            } catch (error) {
                console.error('[Preview] Delete component failed:', error);
                showToast(QSPanelWords.t('preview.componentDeleteFailed'), 'error');
            }
        });
    }
    
    // ==================== Page Title Field ====================

    // The title the field holds as saved (what Escape and an emptied field put back), and a
    // number that drops a read answering after a newer one.
    let pageTitleSaved = '';
    let pageTitleSeq = 0;

    /**
     * The title field shows while a page is the edit target, holding that page's title in the
     * language shown, read where the page reads it: the language's file, or default.json on a
     * one-language site. It hides for the menu, the footer and a component.
     */
    async function updatePageTitleField(editType, editName) {
        if (!pageTitleGroup || !pageTitleInput) return;
        const seq = ++pageTitleSeq;
        if (editType !== 'page' || !editName) {
            pageTitleGroup.style.display = 'none';
            return;
        }
        pageTitleGroup.style.display = '';
        pageTitleSaved = '';
        pageTitleInput.value = '';
        pageTitleInput.placeholder = '';
        const readLang = PreviewConfig.multilingual ? getCurrentLang() : 'default';
        const result = await QuickSiteAdmin.apiRequest('getTranslation', 'GET', null, [readLang]);
        if (seq !== pageTitleSeq) return;
        const titles = (result.ok && result.data?.data?.translations?.page?.titles) || {};
        pageTitleSaved = typeof titles[editName] === 'string' ? titles[editName] : '';
        // A title typed while the read was answering is kept.
        if (document.activeElement !== pageTitleInput) pageTitleInput.value = pageTitleSaved;
        syncPageTitlePlaceholder();
    }

    /**
     * The placeholder is what the preview's tab shows: with no title saved, the readable one the
     * page shell gives (the last part of the address).
     */
    function syncPageTitlePlaceholder() {
        if (!pageTitleInput || currentEditType !== 'page') return;
        let shown = '';
        try {
            shown = (iframe.contentDocument && iframe.contentDocument.title) || '';
        } catch (e) {
            shown = '';
        }
        pageTitleInput.placeholder = shown;
    }

    /**
     * Save the field through editTitle when it differs from the saved title, in the language
     * shown (the default language on a one-language site; editTitle keeps default.json in step).
     * An emptied field puts the saved title back: editTitle refuses an empty title.
     */
    async function savePageTitle() {
        if (!pageTitleInput || currentEditType !== 'page' || !currentEditName) return;
        const value = pageTitleInput.value.trim();
        if (value === '' || value === pageTitleSaved) {
            pageTitleInput.value = pageTitleSaved;
            return;
        }
        const route = currentEditName;
        const result = await QuickSiteAdmin.apiRequest('editTitle', 'POST', {
            route: route,
            lang: getCurrentLang(),
            title: value
        });
        if (result.ok) {
            if (route === currentEditName) pageTitleSaved = value;
            showToast(QSPanelWords.t('preview.pageTitleSaved'), 'success');
        } else {
            const error = (result.data && result.data.message) || String(result.status);
            showToast(QSPanelWords.t('preview.pageTitleSaveFailed', { error: error }), 'error');
        }
    }

    // ==================== Page Description Field ====================

    // As the title's: the description as saved (what Escape puts back), and a number that drops a read answering
    // after a newer one. The platform table and the page's card type come with the read (getSocialPreview), for the
    // length mark.
    let pageDescriptionSaved = '';
    let pageDescriptionSeq = 0;
    let pageDescriptionPlatforms = null;
    let pageDescriptionCardType = null;

    /**
     * The description field shows beside the title while a page of the site is the edit target (a special page
     * carries no link-preview tag), holding its description in the language shown, read through getSocialPreview as
     * the page reads it. Hidden for the menu, the footer and a component; a role that cannot save it never sees it
     * (editorTools.php, toolbar.description).
     */
    async function updatePageDescriptionField(editType, editName) {
        if (!pageDescriptionGroup || !pageDescriptionInput) return;
        const seq = ++pageDescriptionSeq;
        const special = (PreviewConfig.specialPages || []).indexOf(editName) >= 0;
        if (editType !== 'page' || !editName || special) {
            pageDescriptionGroup.style.display = 'none';
            return;
        }
        pageDescriptionGroup.style.display = '';
        pageDescriptionSaved = '';
        pageDescriptionInput.value = '';
        pageDescriptionPlatforms = null;
        syncPageDescriptionCut();
        const lang = getCurrentLang();
        const result = await QuickSiteAdmin.apiRequest('getSocialPreview', 'GET', null, [], { route: editName });
        if (seq !== pageDescriptionSeq) return;
        const report = result.ok && result.data && result.data.data;
        const page = report && report.pages ? report.pages[editName] : null;
        const shown = page && page.languages ? page.languages[lang] : null;
        pageDescriptionPlatforms = report ? report.platforms : null;
        pageDescriptionCardType = page && page.cardType ? page.cardType.value : null;
        pageDescriptionSaved = shown && typeof shown.description === 'string' ? shown.description : '';
        // A description typed while the read was answering is kept.
        if (document.activeElement !== pageDescriptionInput) pageDescriptionInput.value = pageDescriptionSaved;
        syncPageDescriptionCut();
    }

    /**
     * The length mark: the platforms that would cut the description as it stands, judged as it is typed by the
     * table's figures (QSSocialFigures, the judge the Social previews page cuts its cards with): a count, and the
     * platforms with their lengths in its title.
     */
    function syncPageDescriptionCut() {
        if (!pageDescriptionCut || !pageDescriptionInput) return;
        const warnings = descriptionWarnings(pageDescriptionInput.value.trim());
        pageDescriptionCut.hidden = warnings.length === 0;
        pageDescriptionCut.textContent = warnings.length ? '⚠ ' + QSPanelWords.number(warnings.length) : '';
        if (!warnings.length) {
            pageDescriptionCut.removeAttribute('title');
            return;
        }
        pageDescriptionCut.title = descriptionCutSentence(warnings);
    }

    /**
     * The platforms a description would be cut by, by the table's figures (none until the table is read): judged by its
     * words as the page's tags carry them, its formatting markers removed and their words kept (QSTextMarkers, with the
     * engine's list), as the Social previews page judges them.
     */
    function descriptionWarnings(text) {
        const figures = window.QSSocialFigures;
        const markers = (PreviewConfig.tagInfo || {}).TEXT_MARKERS;
        const words = window.QSTextMarkers && markers ? window.QSTextMarkers.remove(text, markers) : text;
        return figures && pageDescriptionPlatforms
            ? figures.textWarnings(words, 'description', pageDescriptionPlatforms, pageDescriptionCardType)
            : [];
    }

    /** Those platforms in one sentence, each with where it cuts (the Social previews page's words). */
    function descriptionCutSentence(warnings) {
        const items = warnings.map((w) => QSPanelWords.t('socialPreviews.cutItem', {
            platform: socialPlatformName(w),
            limit: QSPanelWords.number(w.limit),
            unit: QSPanelWords.t(w.unit === 'bytes' ? 'socialPreviews.unitBytes' : 'socialPreviews.unitCharacters')
        }));
        let list;
        try {
            list = new Intl.ListFormat(QSPanelWords.lang, { style: 'long', type: 'conjunction' }).format(items);
        } catch (e) {
            list = items.join(', ');
        }
        return QSPanelWords.t('socialPreviews.cutBy', { list: list });
    }

    /** A platform's name: the panel's word where the Social previews page has one (X's two cards), else the table's own. */
    function socialPlatformName(w) {
        const key = 'socialPreviews.platformName.' + w.platform;
        return QSPanelWords.has(key) ? QSPanelWords.t(key) : w.name;
    }

    /**
     * The whole description in a dialog (the field's expand button): a text area holding the field's text, its length,
     * and the platforms that would cut it named as it is typed. Save (or Enter) puts it in the field and saves it as the
     * field does; ×, Cancel, Escape and the backdrop leave the field as it was. The panel's one modal
     * (QuickSiteUtils.openModal): the focus stays in it, and no key reaches the editor behind it. A line break typed or
     * pasted becomes a space, as the field holds one line.
     */
    function openPageDescriptionDialog() {
        if (!pageDescriptionInput || currentEditType !== 'page' || !currentEditName || !controlOffered(pageDescriptionGroup)) return;
        const modal = _renderDescriptionDialog(pageDescriptionInput.value);
        const area = modal.querySelector('.preview-description-dialog__text');
        const count = modal.querySelector('.preview-description-dialog__count');
        const cut = modal.querySelector('.preview-description-dialog__cut');
        const closeBtn = modal.querySelector('[data-dialog="close"]');
        const cancelBtn = modal.querySelector('[data-dialog="cancel"]');
        const saveBtn = modal.querySelector('[data-dialog="save"]');
        const sync = () => {
            const warnings = descriptionWarnings(area.value.trim());
            cut.hidden = warnings.length === 0;
            cut.textContent = warnings.length ? '⚠ ' + descriptionCutSentence(warnings) : '';
            count.textContent = QSPanelWords.t('preview.pageDescriptionCount', { count: QSPanelWords.number(area.value.length), max: QSPanelWords.number(area.maxLength) });
        };
        let shown = null;
        const close = () => {
            if (!shown) return;
            const s = shown;
            shown = null;
            s.close();
        };
        const save = () => {
            pageDescriptionInput.value = area.value.replace(/\s*[\r\n]+\s*/g, ' ').trim();
            close();
            syncPageDescriptionCut();
            savePageDescription();
        };
        area.addEventListener('input', sync);
        closeBtn.addEventListener('click', close);
        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', save);
        shown = QuickSiteUtils.openModal(modal, {
            focusable: () => [closeBtn, area, cancelBtn, saveBtn],
            first: area,
            dismiss: close,
            onKey: (e) => {
                if (e.key === 'Enter' && e.target === area) {
                    e.preventDefault();
                    save();
                    return true;
                }
                return false;
            }
        });
        sync();
        area.setSelectionRange(area.value.length, area.value.length);
    }

    /** The description's dialog: its title (the page, and the language when there are several) with ×, its text, its line, its buttons. */
    function _renderDescriptionDialog(text) {
        const id = 'preview-description-dialog';
        const langGroup = langSelect ? langSelect.closest('.preview-toolbar__group') : null;
        const several = !!langGroup && langGroup.style.display !== 'none';
        const title = several
            ? QSPanelWords.t('preview.pageDescriptionDialogLang', { page: currentEditName, lang: getCurrentLang().toUpperCase() })
            : QSPanelWords.t('preview.pageDescriptionDialog', { page: currentEditName });
        const area = QSDom.el('textarea', {
            id: id + '-text', class: 'admin-input preview-description-dialog__text', rows: '6',
            'aria-labelledby': id + '-title', 'aria-describedby': id + '-hint'
        });
        area.maxLength = pageDescriptionInput.maxLength;
        area.value = text;
        return QSDom.el('div', {
            class: 'admin-modal-dialog preview-description-dialog', id: id, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id + '-title'
        }, [
            QSDom.el('div', { class: 'preview-description-dialog__header' }, [
                QSDom.el('h3', { class: 'admin-modal-dialog__title', id: id + '-title', text: title }),
                QSControls.close(null, null, { attrs: { 'data-dialog': 'close' } })
            ]),
            QSDom.el('div', { class: 'preview-description-dialog__body' }, [
                QSDom.el('p', { class: 'admin-hint', id: id + '-hint', text: QSPanelWords.t('preview.pageDescriptionDialogHint') }),
                area,
                QSDom.el('p', { class: 'preview-description-dialog__count' }),
                QSDom.el('p', { class: 'preview-description-dialog__cut', hidden: '' })
            ]),
            QSDom.el('div', { class: 'admin-modal-dialog__actions preview-description-dialog__actions' }, [
                QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--ghost', 'data-dialog': 'cancel', text: QSPanelWords.t('common.cancel') }),
                QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--primary', 'data-dialog': 'save', text: QSPanelWords.t('common.save') })
            ])
        ]);
    }

    /**
     * Save the field through setSocialPreview when it differs from the saved description, in the language shown
     * (the default language on a one-language site: the writer keeps default.json in step, as editTitle does). Unlike
     * the title, an emptied description is sent empty, which removes it.
     */
    async function savePageDescription() {
        if (!pageDescriptionInput || currentEditType !== 'page' || !currentEditName || !controlOffered(pageDescriptionGroup)) return;
        const value = pageDescriptionInput.value.trim();
        if (value === pageDescriptionSaved) {
            pageDescriptionInput.value = pageDescriptionSaved;
            syncPageDescriptionCut();
            return;
        }
        const route = currentEditName;
        const lang = getCurrentLang();
        const result = await QuickSiteAdmin.apiRequest('setSocialPreview', 'POST', {
            route: route,
            lang: lang,
            description: value
        });
        if (result.ok) {
            if (route === currentEditName && lang === getCurrentLang()) pageDescriptionSaved = value;
            showToast(value === '' ? QSPanelWords.t('preview.pageDescriptionRemoved') : QSPanelWords.t('preview.pageDescriptionSaved'), 'success');
        } else {
            const error = (result.data && result.data.message) || String(result.status);
            showToast(QSPanelWords.t('preview.pageDescriptionSaveFailed', { error: error }), 'error');
        }
    }

    // ==================== Route Layout Toggles ====================
    
    /**
     * Show/hide layout toggles and load current layout for the active page
     */
    function updateLayoutToggles(editType, editName) {
        if (!layoutTogglesContainer) return;
        
        if (editType !== 'page') {
            layoutTogglesContainer.style.display = 'none';
            return;
        }
        
        layoutTogglesContainer.style.display = '';
        
        // Layout state will be read from iframe DOM after it loads (in overlayReady handler)
        // For now, default to checked (shown)
        if (toggleMenuCheckbox) toggleMenuCheckbox.checked = true;
        if (toggleFooterCheckbox) toggleFooterCheckbox.checked = true;
    }
    
    /**
     * Read actual menu/footer visibility from the iframe DOM.
     * Called after overlay is ready (iframe fully loaded).
     */
    function syncLayoutTogglesFromIframe() {
        if (!layoutTogglesContainer || currentEditType !== 'page') return;
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;
            
            const menuEl = iframeDoc.querySelector('[data-qs-struct="menu"]');
            const footerEl = iframeDoc.querySelector('[data-qs-struct="footer"]');
            
            if (toggleMenuCheckbox) toggleMenuCheckbox.checked = !!menuEl;
            if (toggleFooterCheckbox) toggleFooterCheckbox.checked = !!footerEl;

            // Consent layer: reveal the toggles only when the layer is present
            // in the iframe. They reflect the element's current hidden state and
            // are preview-only (no persistence).
            const bannerEl = iframeDoc.getElementById('qs-consent-banner');
            const popupEl = iframeDoc.getElementById('qs-consent-popup');
            if (toggleConsentBannerLabel) toggleConsentBannerLabel.style.display = bannerEl ? '' : 'none';
            if (toggleConsentPopupLabel) toggleConsentPopupLabel.style.display = popupEl ? '' : 'none';
            if (toggleConsentBannerCheckbox && bannerEl) toggleConsentBannerCheckbox.checked = !bannerEl.hidden;
            if (toggleConsentPopupCheckbox && popupEl) toggleConsentPopupCheckbox.checked = !popupEl.hidden;
        } catch (e) {
            console.warn('[Preview] Could not read layout state from iframe:', e);
        }
    }

    /** Show/hide a consent-layer element in the preview iframe (preview-only). */
    function handleConsentToggle(which, show) {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;
            const el = iframeDoc.getElementById(which === 'banner' ? 'qs-consent-banner' : 'qs-consent-popup');
            if (el) el.hidden = !show;
        } catch (e) {
            console.warn('[Preview] Consent toggle failed:', e);
        }
    }
    
    /**
     * Handle layout toggle change — update route layout and refresh iframe
     */
    async function handleLayoutToggleChange(section) {
        if (currentEditType !== 'page' || !currentEditName) return;
        
        const params = { route: currentEditName };
        if (section === 'menu') {
            params.menu = toggleMenuCheckbox.checked;
        } else {
            params.footer = toggleFooterCheckbox.checked;
        }
        
        try {
            const result = await QuickSiteAdmin.apiRequest('setRouteLayout', 'POST', params);
            
            if (result.ok) {
                const struct = section; // 'menu' or 'footer'
                const show = section === 'menu' ? toggleMenuCheckbox.checked : toggleFooterCheckbox.checked;
                
                if (show) {
                    // Re-enabling: check if struct elements exist in iframe DOM
                    let structExists = false;
                    try {
                        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                        structExists = !!iframeDoc.querySelector('[data-qs-struct="' + struct + '"]');
                    } catch (e) {
                        // Cross-origin or unavailable
                    }
                    
                    if (structExists) {
                        // Elements exist (were just hidden) — show them live
                        sendToIframe('showStruct', { struct: struct });
                    } else {
                        // Elements not in DOM (page was reloaded without them) — need full reload
                        reloadPreview();
                    }
                } else {
                    // Hiding: elements are in DOM, just hide them
                    sendToIframe('hideStruct', { struct: struct });
                }
                
                const msg = section === 'menu'
                    ? QSPanelWords.t(show ? 'preview.layoutMenuShown' : 'preview.layoutMenuHidden')
                    : QSPanelWords.t(show ? 'preview.layoutFooterShown' : 'preview.layoutFooterHidden');
                showToast(msg, 'success');
            } else {
                throw new Error(result.data?.message || QSPanelWords.t('preview.layoutToggleFailed'));
            }
        } catch (error) {
            console.error('[Preview] Layout toggle error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
            // Revert toggle
            if (section === 'menu' && toggleMenuCheckbox) {
                toggleMenuCheckbox.checked = !toggleMenuCheckbox.checked;
            } else if (toggleFooterCheckbox) {
                toggleFooterCheckbox.checked = !toggleFooterCheckbox.checked;
            }
        }
    }
    
    // Wire up toggle event listeners
    if (toggleMenuCheckbox) {
        toggleMenuCheckbox.addEventListener('change', () => handleLayoutToggleChange('menu'));
    }
    if (toggleFooterCheckbox) {
        toggleFooterCheckbox.addEventListener('change', () => handleLayoutToggleChange('footer'));
    }
    if (toggleConsentBannerCheckbox) {
        toggleConsentBannerCheckbox.addEventListener('change', () => handleConsentToggle('banner', toggleConsentBannerCheckbox.checked));
    }
    if (toggleConsentPopupCheckbox) {
        toggleConsentPopupCheckbox.addEventListener('change', () => handleConsentToggle('popup', toggleConsentPopupCheckbox.checked));
    }
    
    // ==================== Create Component ====================
    
    if (createComponentBtn) {
        createComponentBtn.addEventListener('click', async function() {
            const namePrompt = QSPanelWords.t('preview.componentNamePrompt');
            const name = await QuickSiteUtils.prompt(namePrompt);
            if (!name) return;
            
            // Validate name: alphanumeric, hyphens, underscores only
            const trimmed = name.trim();
            if (!trimmed || !/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(trimmed)) {
                showToast(QSPanelWords.t('preview.componentNameInvalid'), 'error');
                return;
            }
            
            // Check if component already exists in the dropdown
            const existingOption = targetSelect.querySelector('option[value="component:' + trimmed + '"]');
            if (existingOption) {
                showToast(QSPanelWords.t('preview.componentNameExists'), 'error');
                return;
            }
            
            try {
                // Create component via editStructure API with starter template
                const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                    type: 'component',
                    name: trimmed,
                    structure: { tag: 'div', children: [
                        { textKey: '__RAW__' + (PreviewConfig.componentStarter[getCurrentLang()] || PreviewConfig.componentStarter.en).replace(/\{\{name\}\}/g, () => trimmed) }
                    ] }
                });
                
                if (result.ok) {
                    // Auto-select the new component
                    showEditTarget(addEditListComponent(trimmed).value);
                    navigateTo('component', trimmed);
                    
                    showToast(QSPanelWords.t('preview.componentCreated'), 'success');
                } else {
                    const msg = result.data?.message || QSPanelWords.t('preview.componentCreateFailed');
                    showToast(msg, 'error');
                }
            } catch (error) {
                console.error('[Preview] Create component failed:', error);
                showToast(QSPanelWords.t('preview.componentCreateFailed'), 'error');
            }
        });
    }
    
    // ==================== Device ====================
    
    // The frame keeps the size it is given, and the room around it scrolls. Each kind keeps its own last size, its
    // default the first time; the device and both sizes are remembered per browser (QuickSiteStorageKeys.previewFrame).
    // Which of them shows at a width is the stylesheet's alone: a tablet shows a Tablet at its own width, a phone
    // every device, and the bar and the handles go with the frame.
    const frameBar = document.getElementById('preview-frame-bar');
    const frameSizesSelect = document.getElementById('preview-frame-sizes');
    const frameWidthInput = document.getElementById('preview-frame-width');
    const frameHeightInput = document.getElementById('preview-frame-height');
    const frameRotateBtn = document.getElementById('preview-frame-rotate');
    const frameSize = { mobile: frameDefault('mobile'), tablet: frameDefault('tablet') };

    function frameDefault(kind) {
        const k = FRAME_SIZES[kind];
        return k.sizes[k.default].slice();
    }

    /** A length held to the limits of its axis (0 the width, 1 the height), in whole pixels. */
    function clampFrame(value, axis) {
        return Math.min(FRAME_MAX[axis], Math.max(FRAME_MIN[axis], Math.round(value)));
    }

    function isFramePair(pair) {
        return Array.isArray(pair) && pair.length === 2
            && pair.every((v, axis) => Number.isInteger(v) && v >= FRAME_MIN[axis] && v <= FRAME_MAX[axis]);
    }

    /** What this browser remembers: a part that is missing, malformed or out of the limits gives that part's default. */
    function readStoredFrame() {
        let stored = null;
        try {
            stored = JSON.parse(localStorage.getItem(QuickSiteStorageKeys.previewFrame) || 'null');
        } catch (e) {
            stored = null;
        }
        const own = (o, k) => o !== null && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k);
        const sizes = {};
        Object.keys(FRAME_SIZES).forEach(kind => {
            const pair = own(stored, 'sizes') && own(stored.sizes, kind) ? stored.sizes[kind] : null;
            sizes[kind] = isFramePair(pair) ? pair.slice() : frameDefault(kind);
        });
        const device = own(stored, 'device') && (stored.device === 'desktop' || own(FRAME_SIZES, stored.device)) ? stored.device : 'desktop';
        return { device: device, sizes: sizes };
    }

    function storeFrame() {
        try {
            localStorage.setItem(QuickSiteStorageKeys.previewFrame, JSON.stringify({ device: currentDevice, sizes: frameSize }));
        } catch (e) {
            // Storage refused: the frame works, and is not remembered
        }
    }

    function setDevice(device, remember = true) {
        currentDevice = device;
        
        deviceBtns.forEach(btn => {
            btn.classList.toggle('preview-device-btn--active', btn.dataset.device === device);
        });
        
        container.classList.toggle('preview-container--device', device !== 'desktop');
        container.dataset.device = device;
        applyFrameSize();
        if (remember) storeFrame();
    }

    /** The frame's size, inline: the kind's own, or none for Desktop, which fills the room. The bar follows. */
    function applyFrameSize() {
        const size = frameSize[currentDevice];
        wrapper.style.width = size ? size[0] + 'px' : '';
        wrapper.style.height = size ? size[1] + 'px' : '';
        syncFrameBar();
    }

    function frameLabel(pair) {
        return pair[0] + ' × ' + pair[1];
    }

    /**
     * The bar shows the frame's size: the two boxes, and the list's entry that matches it either way round. When none
     * does, the list's first entry is the frame's own size, so the list never shows a size the frame is not.
     */
    function syncFrameBar() {
        const size = frameSize[currentDevice];
        if (!size || !frameBar) return;
        frameWidthInput.value = size[0];
        frameHeightInput.value = size[1];
        const list = FRAME_SIZES[currentDevice].sizes;
        const match = list.find(p => (p[0] === size[0] && p[1] === size[1]) || (p[0] === size[1] && p[1] === size[0]));
        const options = list.map(p => _renderOption(p[0] + 'x' + p[1], frameLabel(p)));
        if (!match) options.unshift(_renderOption('', frameLabel(size)));
        frameSizesSelect.replaceChildren(...options);
        frameSizesSelect.value = match ? match[0] + 'x' + match[1] : '';
    }

    /** A new size for the frame shown, held to the limits; it is remembered unless a drag is still under way. */
    function setFrameSize(width, height, remember = true) {
        if (!frameSize[currentDevice]) return;
        frameSize[currentDevice] = [clampFrame(width, 0), clampFrame(height, 1)];
        applyFrameSize();
        if (remember) storeFrame();
    }

    /** A box's value, on Enter or on leaving it: a number is held to the limits; anything else puts the size back. */
    function applyTypedFrame(input, axis) {
        const size = frameSize[currentDevice];
        if (!size) return;
        const typed = input.value.trim() === '' ? NaN : Number(input.value);
        if (!Number.isFinite(typed)) {
            input.value = size[axis];
            return;
        }
        const next = size.slice();
        next[axis] = typed;
        setFrameSize(next[0], next[1]);
    }

    /**
     * Sizes the frame so that its right edge (axis 0) or its bottom edge (axis 1) lands where the pointer holds it. A
     * frame narrower than the room is centred in it, so its width moves its right edge by half as much; a wider one
     * starts at the room's start, and so does every frame's top. A few passes settle the change from one to the other
     * and the scroll a frame gives back as it shrinks.
     */
    function followFrameEdge(axis, target) {
        const size = frameSize[currentDevice];
        for (let pass = 0; pass < 3; pass++) {
            const rect = wrapper.getBoundingClientRect();
            const miss = target - (axis === 0 ? rect.right : rect.bottom);
            if (Math.abs(miss) < 0.5) return;
            const centred = axis === 0 && wrapper.offsetLeft > parseFloat(getComputedStyle(container).paddingLeft) + 0.5;
            const next = clampFrame(size[axis] + miss * (centred ? 2 : 1), axis);
            if (next === size[axis]) return;
            size[axis] = next;
            wrapper.style[axis === 0 ? 'width' : 'height'] = next + 'px';
            (axis === 0 ? frameWidthInput : frameHeightInput).value = next;
        }
    }

    /** A press on a handle: the edge it holds follows the pointer until it is let go, and the size is then remembered. */
    function startFrameDrag(e) {
        if (!frameSize[currentDevice]) return;
        const edge = this.dataset.edge;
        const rect = wrapper.getBoundingClientRect();
        const holdX = e.clientX - rect.right;
        const holdY = e.clientY - rect.bottom;
        container.dataset.sizing = edge;
        QSDom.followPointer(e, function(dx, dy, move) {
            if (edge !== 'bottom') followFrameEdge(0, move.clientX - holdX);
            if (edge !== 'right') followFrameEdge(1, move.clientY - holdY);
        }, function() {
            delete container.dataset.sizing;
            syncFrameBar();
            storeFrame();
        });
    }

    /** The bar and the handles answer, and the remembered device and sizes come back with no transition. */
    function initFrame() {
        if (frameBar) {
            [frameWidthInput, frameHeightInput].forEach((input, axis) => {
                input.min = FRAME_MIN[axis];
                input.max = FRAME_MAX[axis];
                input.addEventListener('change', () => applyTypedFrame(input, axis));
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyTypedFrame(input, axis);
                    }
                });
            });
            frameSizesSelect.addEventListener('change', function() {
                const pair = this.value.split('x').map(Number);
                if (pair.length === 2) setFrameSize(pair[0], pair[1]);
            });
            frameRotateBtn.addEventListener('click', function() {
                const size = frameSize[currentDevice];
                if (size) setFrameSize(size[1], size[0]);
            });
            wrapper.querySelectorAll('.preview-frame-handle').forEach(handle => {
                handle.addEventListener('pointerdown', startFrameDrag);
            });
        }
        const stored = readStoredFrame();
        Object.assign(frameSize, stored.sizes);
        wrapper.style.transition = 'none';
        setDevice(stored.device, false);
        void wrapper.offsetWidth;
        wrapper.style.transition = '';
    }
    
    // ==================== Editor Mode ====================
    
    // Store preselection data for style mode
    let styleModePreselect = null;
    let isSwitchingMode = false; // Debounce flag to prevent rapid mode switching

    function setMode(mode, preselect = null) {
        // Debounce rapid mode switches to prevent layout thrashing
        if (isSwitchingMode) return;
        // Dirty guard for Source. Leaving style mode while Source is active
        // with unsaved edits asks first (the panel's dialog); the switch
        // runs once the answer is yes, and not at all on a no.
        if (currentMode === 'style' && mode !== 'style'
            && window.PreviewStyleSource && PreviewStyleSource.isActive()
            && !PreviewStyleSource.canLeave()) {
            PreviewStyleSource.confirmLeave().then(ok => {
                if (ok) switchMode(mode, preselect);
            });
            return;
        }
        switchMode(mode, preselect);
    }

    /** Whether the panel is in simple mode (the page's class, which the account's mode sets). */
    function isSimpleView() {
        return document.body.classList.contains('admin-body--simple');
    }

    /**
     * Whether a control of the editor's definition (editorTools.php) is offered here: its role holds what it
     * needs (the role filter has not hidden it, and has run when the control needs a command) and the mode
     * shows it. The server still decides what runs; this decides what the editor offers.
     */
    function controlOffered(el) {
        if (!el) return false;
        if (el.classList.contains('admin-hidden-permission')) return false;
        if (el.hasAttribute('data-requires-command')
            && !(window.QuickSiteAdmin && QuickSiteAdmin.permissions && QuickSiteAdmin.permissions.loaded)) return false;
        if (el.hasAttribute('data-editor-advanced') && isSimpleView()) return false;
        return true;
    }

    /** Whether a tool is offered here: its button is (controlOffered()), and nothing has put it aside. */
    function toolOffered(mode) {
        // The Add form is Select's, reached by its Add or Edit Params, which follow the same definition.
        if (mode === 'add') return true;
        const btn = Array.from(modeBtns).find(b => b.dataset.mode === mode);
        return controlOffered(btn) && btn.style.display !== 'none';
    }

    /** setMode() once nothing is left to ask. */
    function switchMode(mode, preselect) {
        // A tool this role or this mode does not offer is never entered: Select instead.
        if (mode !== 'select' && !toolOffered(mode)) mode = 'select';
        // A tool that selects without asking (AI tools, Interactions) may hold an element outside the
        // target. Select acts on what it holds, so it does not start from one.
        if (mode === 'select' && switchFor(selectedStruct)) hideNodePanel();
        isSwitchingMode = true;
        currentMode = mode;
        
        modeBtns.forEach(btn => {
            btn.classList.toggle('preview-tool--active', btn.dataset.mode === mode);
        });
        
        // Update workspace data-mode for the phone's Add overlay
        if (workspace) workspace.dataset.mode = mode;
        
        // The panel opens with a tool that holds its options, and with Select while an element is selected.
        const tool = Array.from(modeBtns).find(btn => btn.dataset.mode === mode);
        setPanelOpen(!!tool && (tool.hasAttribute('data-panel-opens')
            || (mode === 'select' && selectedStruct != null && selectedNode != null)));
        if (panelToggleName && tool) panelToggleName.textContent = tool.textContent.trim();
        
        // Update container class for mode-specific styling
        container.dataset.mode = mode;
        
        // Send mode change to iframe. AI tools mode reuses select-mode
        // behavior in the iframe (hover highlight + click to select) so
        // the user can pick elements directly while seeing Suggested
        // tools in the panel — without round-tripping through select mode.
        sendToIframe('setMode', frameModeMessage(mode === 'ai-tools' ? 'select' : mode));
        if (mode === 'text') sendStoredWords();
        
        // Store preselection for style mode (for the selector browser)
        if (mode === 'style' && preselect) {
            styleModePreselect = preselect;
            console.log('[Preview] Style mode preselection:', preselect);
            // TODO: Auto-select the selector in the selector browser
        } else if (mode !== 'style') {
            styleModePreselect = null;
        }
        
        // Update contextual area sections
        updateContextualSection(mode);
        
        // Hide node panel when switching away from select mode (but not for add which needs the selection)
        if (mode !== 'select' && mode !== 'add') {
            hideNodePanel();
        }
        
        // Reset drag UI when entering drag mode
        if (mode === 'drag' && window.PreviewDrag) {
            PreviewDrag.onModeEnter();
        }
        
        // Clear style mode state when switching away
        if (mode !== 'style') {
            sendToIframe('clearStyleSelection', {});
            if (window.PreviewSelectorBrowser) {
                PreviewSelectorBrowser.clearElementFilter();
            }
        }
        
        // Hide JS panel when switching away from js mode
        if (mode !== 'js') {
            hideJsPanel();
        }
        
        // Load page events when entering JS mode (pages only)
        if (mode === 'js' && window.PreviewJsInteractions) {
            if (currentEditType === 'page') {
                PreviewJsInteractions.setCurrentPage(currentEditName);
                PreviewJsInteractions.loadPageEvents();
                PreviewJsInteractions.loadStateStores();
            } else {
                PreviewJsInteractions.setCurrentPage(null);
            }
        }

        // Translation Manager. enter() is idempotent + lazy;
        // first entry triggers data load, subsequent entries are no-op
        // unless a refresh is requested. leave() keeps the cache.
        if (mode === 'translation' && window.PreviewTranslation) {
            PreviewTranslation.enter();
        } else if (mode !== 'translation' && window.PreviewTranslation) {
            PreviewTranslation.leave();
        }

        if (mode === 'ai-tools' && window.PreviewAiTools) {
            PreviewAiTools.enter();
        } else if (mode !== 'ai-tools' && window.PreviewAiTools) {
            PreviewAiTools.leave();
        }

        // Reset debounce flag after a short delay
        requestAnimationFrame(() => {
            isSwitchingMode = false;
        });
    }
    
    // ==================== The options panel ====================
    
    // Its state is a class at every width, and only the phone block gives it a meaning: there the panel
    // is one of the rows, and the class is what shows it. No script reads a width.
    function setPanelOpen(open) {
        if (!workspace) return;
        const wasOpen = workspace.classList.contains('preview-workspace--panel-open');
        workspace.classList.toggle('preview-workspace--panel-open', open);
        if (panelToggle) panelToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        // The page just got shorter under whatever is selected in it: the frame has its new height
        // once the box is read, so what is selected comes back into view.
        if (open && !wasOpen && panelFolds() && previewContainer && previewContainer.offsetHeight) {
            sendToIframe('revealSelection', {});
        }
    }

    /** Opens a closed panel and closes an open one; what it holds stays as it is. */
    function togglePanel() {
        setPanelOpen(!workspace.classList.contains('preview-workspace--panel-open'));
    }

    /** Whether the panel folds away, as one of the rows: the phone block's own answer, read from the box. */
    function panelFolds() {
        return !!workspace && getComputedStyle(workspace).display === 'contents';
    }

    /** While a Save form is up, the phone block gives it the whole window. */
    function syncSaveFormFills() {
        if (!workspace) return;
        const isUp = form => !!form && form.style.display !== 'none';
        workspace.classList.toggle('preview-workspace--panel-fills', isUp(saveSnippetForm) || isUp(saveComponentForm));
    }
    
    // ==================== Contextual Area ====================
    
    function updateContextualSection(mode) {
        // Hide all sections
        contextualSections.forEach(section => {
            section.style.display = 'none';
            section.classList.remove('preview-contextual-section--active');
        });
        
        // Show the section matching the current mode
        const activeSection = document.getElementById('contextual-' + mode);
        if (activeSection) {
            activeSection.style.display = '';
            activeSection.classList.add('preview-contextual-section--active');
        }
        
        // When returning to select mode, restore the select info if there's an active selection
        if (mode === 'select') {
            const hasSelection = window.PreviewState && PreviewState.get('selectedStruct') && PreviewState.get('selectedNode');
            if (hasSelection) {
                if (ctxSelectDefault) ctxSelectDefault.style.display = 'none';
                if (ctxSelectInfo) ctxSelectInfo.style.display = '';
            }
        } else if (ctxSelectInfo) {
            // Reset select mode info display when switching away
            ctxSelectInfo.style.display = 'none';
            ctxSelectDefault.style.display = '';
        }
        
        // Reset text mode info when switching away from text
        if (mode !== 'text' && typeof resetTextModeInfo === 'function') {
            resetTextModeInfo();
        }
        
        // Show/hide style tabs and content when in style mode
        if (mode === 'style') {
            if (styleTabs) styleTabs.style.display = '';
            if (styleContent) styleContent.style.display = '';
            // Advanced row (Source button) appears alongside the
            // tabs. admin.js filterByPermissions has already hidden it if
            // the role lacks editStyles.
            if (sourceAdvancedRow) sourceAdvancedRow.style.display = '';
            // Load theme variables via module if not already loaded
            if (window.PreviewStyleTheme && !PreviewStyleTheme.isLoaded()) {
                PreviewStyleTheme.load();
            }
        } else {
            if (styleTabs) styleTabs.style.display = 'none';
            if (styleContent) styleContent.style.display = 'none';
            if (sourceAdvancedRow) sourceAdvancedRow.style.display = 'none';
            // Leaving style mode also resets Source state — next
            // entry lands on the tab view, not Source. The active tab's
            // panel will be restored by the Source deactivation path.
            if (window.PreviewStyleSource && PreviewStyleSource.isActive()) {
                deactivateSource();
            }
        }
    }

    function showContextualInfo(data) {
        // Format structure name for display
        let structDisplay = data.struct || '-';
        if (structDisplay.startsWith('page-')) {
            structDisplay = QSPanelWords.t('preview.selectionPage', { name: structDisplay.substring(5) });
        } else if (structDisplay === 'menu') {
            structDisplay = QSPanelWords.t('preview.selectionMenu');
        } else if (structDisplay === 'footer') {
            structDisplay = QSPanelWords.t('preview.selectionFooter');
        }
        
        // Update contextual info fields (with null checks)
        if (ctxNodeStruct) ctxNodeStruct.textContent = structDisplay;
        if (ctxNodeId) ctxNodeId.textContent = data.isComponent ? data.componentNode : (data.node === '' ? QSPanelWords.t('preview.nodeIdRoot') : data.node || '-');
        if (ctxNodeTag) ctxNodeTag.textContent = data.tag || '-';
        if (ctxNodeClasses) ctxNodeClasses.textContent = data.classes || '-';
        if (ctxNodeChildren) ctxNodeChildren.textContent = data.childCount !== undefined ? QSPanelWords.number(data.childCount) : '-';
        if (ctxNodeText) ctxNodeText.textContent = data.textContent || '-';
        
        // Show/hide component row
        if (data.isComponent && data.component) {
            if (ctxNodeComponent) ctxNodeComponent.textContent = data.component;
            if (ctxNodeComponentRow) ctxNodeComponentRow.style.display = '';
        } else {
            if (ctxNodeComponentRow) ctxNodeComponentRow.style.display = 'none';
        }
        
        // Show/hide textKey row
        if (data.textKeys && data.textKeys.length > 0) {
            if (ctxNodeTextKey) ctxNodeTextKey.textContent = data.textKeys.join(', ');
            if (ctxNodeTextKeyRow) ctxNodeTextKeyRow.style.display = '';
        } else {
            if (ctxNodeTextKeyRow) ctxNodeTextKeyRow.style.display = 'none';
        }
        
        // Show info, hide default message
        if (ctxSelectDefault) ctxSelectDefault.style.display = 'none';
        if (ctxSelectInfo) ctxSelectInfo.style.display = '';
        
        // Update global element info bar
        updateGlobalElementInfo(data);
    }
    
    /**
     * Update global element info bar
     */
    function updateGlobalElementInfo(data) {
        if (!globalElementInfo) return;
        
        // Update summary. The `kind` field disambiguates text-only wrappers
        // (the renderer wraps text in <span data-qs-textonly>) from real
        // elements — used to read as just "span" and led to misdiagnosis.
        if (globalElementInfoSummary) {
            const nodeDisplay = data.isComponent ? data.componentNode : (data.node === '' ? QSPanelWords.t('preview.nodeIdRoot') : data.node || '-');
            let summary;
            if (data.kind === 'text') {
                const preview = (data.textContent || '').replace(/\s+/g, ' ').substring(0, 30);
                summary = QSPanelWords.t('preview.elementSummaryText', { text: preview, node: nodeDisplay });
            } else if (data.kind === 'component') {
                summary = QSPanelWords.t('preview.elementSummaryComponent', { name: data.component || data.tag || '?', node: nodeDisplay });
            } else {
                summary = `${data.tag || '-'}${data.classes ? '.' + data.classes.split(' ')[0] : ''} [${nodeDisplay}]`;
            }
            globalElementInfoSummary.textContent = summary;
        }

        // Update details
        if (globalInfoNodeId) globalInfoNodeId.textContent = data.isComponent ? data.componentNode : (data.node === '' ? QSPanelWords.t('preview.nodeIdRoot') : data.node || '-');
        if (globalInfoNodeTag) globalInfoNodeTag.textContent = data.tag || '-';
        if (globalInfoNodeClasses) globalInfoNodeClasses.textContent = data.classes || '-';
        if (globalInfoNodeChildren) globalInfoNodeChildren.textContent = data.childCount !== undefined ? QSPanelWords.number(data.childCount) : '-';

        // Params row: stacked key="value" lines, hidden when no user-authored
        // attrs survive the editor-chrome filter (data-qs-*, contenteditable).
        if (globalInfoNodeParamsRow && globalInfoNodeParams) {
            const entries = data.params ? Object.entries(data.params) : [];
            if (entries.length === 0) {
                globalInfoNodeParamsRow.style.display = 'none';
                globalInfoNodeParams.textContent = '';
            } else {
                globalInfoNodeParams.textContent = '';
                entries.forEach(function (pair) {
                    const line = document.createElement('div');
                    line.textContent = pair[0] + '="' + pair[1] + '"';
                    globalInfoNodeParams.appendChild(line);
                });
                globalInfoNodeParamsRow.style.display = '';
            }
        }
        
        // Component row
        if (data.isComponent && data.component) {
            if (globalInfoNodeComponent) globalInfoNodeComponent.textContent = data.component;
            if (globalInfoNodeComponentRow) globalInfoNodeComponentRow.style.display = '';
        } else {
            if (globalInfoNodeComponentRow) globalInfoNodeComponentRow.style.display = 'none';
        }
        
        // Text key row
        if (data.textKeys && data.textKeys.length > 0) {
            if (globalInfoNodeTextKey) globalInfoNodeTextKey.textContent = data.textKeys.join(', ');
            if (globalInfoNodeTextKeyRow) globalInfoNodeTextKeyRow.style.display = '';
        } else {
            if (globalInfoNodeTextKeyRow) globalInfoNodeTextKeyRow.style.display = 'none';
        }
    }
    
    /**
     * Reset global element info bar to empty state (bar stays visible)
     */
    function hideGlobalElementInfo() {
        if (!globalElementInfo) return;
        
        // Reset summary to empty
        if (globalElementInfoSummary) globalElementInfoSummary.textContent = '-';
        
        // Reset all values to empty
        if (globalInfoNodeId) globalInfoNodeId.textContent = '-';
        if (globalInfoNodeTag) globalInfoNodeTag.textContent = '-';
        if (globalInfoNodeClasses) globalInfoNodeClasses.textContent = '-';
        if (globalInfoNodeChildren) globalInfoNodeChildren.textContent = '-';
        if (globalInfoNodeParams) globalInfoNodeParams.textContent = '';

        // Hide optional rows
        if (globalInfoNodeComponentRow) globalInfoNodeComponentRow.style.display = 'none';
        if (globalInfoNodeTextKeyRow) globalInfoNodeTextKeyRow.style.display = 'none';
        if (globalInfoNodeParamsRow) globalInfoNodeParamsRow.style.display = 'none';
    }
    
    function hideContextualInfo() {
        // Show default message, hide info
        if (ctxSelectDefault) ctxSelectDefault.style.display = '';
        if (ctxSelectInfo) ctxSelectInfo.style.display = 'none';
        
        // Hide Variables panel if open
        if (variablesPanel && variablesPanel.style.display !== 'none') {
            variablesPanel.style.display = 'none';
            variablesPanelStructure = null;
        }
        // Hide Enums panel if open
        if (enumsPanel && enumsPanel.style.display !== 'none') {
            enumsPanel.style.display = 'none';
            enumsPanelStructure = null;
        }
        // Hide Emulation panel if open
        if (emulationPanel && emulationPanel.style.display !== 'none') {
            emulationPanel.style.display = 'none';
        }
        
        // Restore action buttons (may have been hidden by Variables/Enums panel)
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = '';
        
        // Hide global element info bar
        hideGlobalElementInfo();
        
        // Select with nothing selected has nothing but a hint: the page comes back.
        if (currentMode === 'select') setPanelOpen(false);
    }
    
    // ==================== Theme Variables ====================
    // Theme variable editing is now handled by preview-style-theme.js module
    // See: public/admin/assets/js/pages/preview/preview-style-theme.js

    // ==================== Source Tab ====================
    // Sidebar scaffold + role gate. The Source button sits in
    // the advanced top row above the regular tabs and toggles a Source
    // panel that hides the three regular tabs. The code editor itself
    // mounts in the source canvas (#preview-source-canvas-mount).

    function activateSource() {
        if (!sourcePanel || !window.PreviewStyleSource) return;
        if (PreviewStyleSource.isActive()) return;
        if (sourceBtn) sourceBtn.classList.add('preview-contextual-style-source-btn--active');
        if (styleTabs) styleTabs.style.display = 'none';
        if (themePanel) themePanel.style.display = 'none';
        if (selectorsPanel) selectorsPanel.style.display = 'none';
        if (animationsPanel) animationsPanel.style.display = 'none';
        sourcePanel.style.display = '';
        // Swap the main canvas — hide the iframe container,
        // show the source canvas where the code editor mounts.
        if (previewContainer) previewContainer.style.display = 'none';
        if (sourceCanvas) sourceCanvas.style.display = '';
        PreviewStyleSource.enter();
    }

    function deactivateSource() {
        if (!sourcePanel || !window.PreviewStyleSource) return;
        if (!PreviewStyleSource.isActive()) return;
        PreviewStyleSource.leave();
        if (sourceBtn) sourceBtn.classList.remove('preview-contextual-style-source-btn--active');
        sourcePanel.style.display = 'none';
        // Restore the iframe canvas.
        if (sourceCanvas) sourceCanvas.style.display = 'none';
        if (previewContainer) previewContainer.style.display = '';
        // Restore the tabs row + the previously active tab's panel. Only
        // restore tab visibility when we are still in style mode — when
        // leaving style mode entirely, updateContextualSection has already
        // hidden styleTabs and the panel toggles are no-ops in practice
        // since the contextual section itself is hidden.
        if (styleTabs) styleTabs.style.display = '';
        if (themePanel) themePanel.style.display = activeStyleTab === 'theme' ? '' : 'none';
        if (selectorsPanel) selectorsPanel.style.display = activeStyleTab === 'selectors' ? '' : 'none';
        if (animationsPanel) animationsPanel.style.display = activeStyleTab === 'animations' ? '' : 'none';

        // When the Source button is used to toggle off,
        // we don't go through the tab-click handler — so the reload-on-
        // stale check there is skipped. Source's save / cancel may have
        // invalidated the active tab's cache via invalidateStructuredTabs();
        // re-run the check here so the panel shows fresh data. (Only
        // fires when isLoaded() returns false, so a Source-open-and-exit
        // without save preserves the active tab's unsaved edits.)
        const moduleByTab = {
            theme:      window.PreviewStyleTheme,
            selectors:  window.PreviewSelectorBrowser,
            animations: window.PreviewStyleMotion
        };
        const activeMod = moduleByTab[activeStyleTab];
        if (activeMod && activeMod.isLoaded && !activeMod.isLoaded() && activeMod.load) {
            activeMod.load();
        }
    }

    function initStyleSource() {
        if (window.PreviewStyleSource) PreviewStyleSource.init();
        if (!sourceBtn) return;
        sourceBtn.addEventListener('click', () => {
            if (!window.PreviewStyleSource) return;
            if (PreviewStyleSource.isActive()) {
                // Dirty guard: unsaved edits ask first; Source closes on a yes.
                if (!PreviewStyleSource.canLeave()) {
                    PreviewStyleSource.confirmLeave().then(ok => {
                        if (ok) deactivateSource();
                    });
                    return;
                }
                deactivateSource();
            } else {
                activateSource();
            }
        });
    }

    /**
     * Initialize style tab switching
     */
    function initStyleTabs() {
        if (!styleTabs) return;

        const tabs = styleTabs.querySelectorAll('.preview-contextual-style-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Clicking a tab while Source is active returns to
                // the structured editor surface. Deactivate Source first so
                // the tab panel becomes visible. With unsaved edits Source
                // asks first; the tab opens on a yes, the user stays on a no.
                if (window.PreviewStyleSource && PreviewStyleSource.isActive()) {
                    if (!PreviewStyleSource.canLeave()) {
                        PreviewStyleSource.confirmLeave().then(ok => {
                            if (!ok) return;
                            deactivateSource();
                            openStyleTab(tab);
                        });
                        return;
                    }
                    deactivateSource();
                }
                openStyleTab(tab);
            });
        });

        /** Show a tab's panel (after Source, if it was open, has closed). */
        function openStyleTab(tab) {
            const tabName = tab.dataset.tab;

            // Reload-on-stale check must run
            // BEFORE the `tabName === activeStyleTab` early return.
            // After exiting Source, the user is typically clicking
            // back to the same tab they were on — activeStyleTab is
            // unchanged, but the module's cache may have been
            // invalidated by Source's save / cancel. Each module's
            // isLoaded() is the source of truth.
            const moduleByTab = {
                theme:      window.PreviewStyleTheme,
                selectors:  window.PreviewSelectorBrowser,
                animations: window.PreviewStyleMotion
            };
            const targetMod = moduleByTab[tabName];
            if (targetMod && targetMod.isLoaded && !targetMod.isLoaded() && targetMod.load) {
                targetMod.load();
            }

            if (tabName === activeStyleTab) return;

            // Update active tab button
            tabs.forEach(t => t.classList.remove('preview-contextual-style-tab--active'));
            tab.classList.add('preview-contextual-style-tab--active');

            // Show/hide panels
            activeStyleTab = tabName;
            if (themePanel) themePanel.style.display = tabName === 'theme' ? '' : 'none';
            if (selectorsPanel) selectorsPanel.style.display = tabName === 'selectors' ? '' : 'none';
            if (animationsPanel) animationsPanel.style.display = tabName === 'animations' ? '' : 'none';
        }
    }
    
    // ==================== Selector Browser ====================
    // The Selectors tab is preview-style-selectors.js (PreviewSelectorBrowser: init, load, loadData,
    // isLoaded); the Motion tab is preview-style-motion.js (PreviewStyleMotion). Both are loaded by
    // their tab (moduleByTab above).
    
    // Delegate to selector browser module
    function loadSelectorsData() {
        if (window.PreviewSelectorBrowser) {
            return PreviewSelectorBrowser.loadData();
        }
        return Promise.resolve(false);
    }
    
    function initSelectorBrowser() {
        // Register callbacks for cross-module communication
        if (window.PreviewSelectorBrowser) {
            // Delegate to Style Editor module
            if (window.PreviewStyleEditor) {
                PreviewSelectorBrowser.onOpenStyleEditor(PreviewStyleEditor.open);
                PreviewSelectorBrowser.onCopyStyleFrom(PreviewStyleEditor.open);
            }
            PreviewSelectorBrowser.onOpenTransitionEditor((selector, mediaQuery) => openTransitionEditor(selector, null, mediaQuery));
        }
    }
    
    // ==================== Style Editor ====================
    // Delegated to preview-style-editor.js module
    
    function initStyleEditor() {
        if (window.PreviewStyleEditor) {
            // Set up callbacks for module integration
            PreviewStyleEditor.setShowToast(showToast);
            PreviewStyleEditor.setGetIframe(() => iframe);
            PreviewStyleEditor.setGetThemeVariables(() => {
                if (window.PreviewStyleTheme && PreviewStyleTheme.isLoaded()) {
                    return PreviewStyleTheme.getCurrent();
                }
                return {};
            });
            PreviewStyleEditor.setGetPropertyTypes((prop) => KEYFRAME_PROPERTY_TYPES[prop] || { type: 'text' });
        }
    }
    
    // Initialize style editor module integration
    initStyleEditor();

    // ==================== Node Panel ====================

    // Current selection state
    let selectedStruct = null;
    let selectedNode = null;
    let selectedComponent = null;
    let selectedElementClasses = null;  // For style mode preselection
    let selectedElementTag = null;      // For style mode preselection
    let selectedHoldsChildren = true;   // Whether the Add panel may offer "Inside" (the iframe's answer)
    let selectedIsInline = false;       // Whether text beside it continues its line: the Text tab's Before / After
    let selectedFrameNode = null;       // The selection's own node in the frame: a component's inner node is not one

    /**
     * The switch a struct calls for, when it is outside what the Edit list names: the frame shows a
     * page with its menu and footer around it, whichever of them is the target. Null on the target.
     */
    function switchFor(struct) {
        if (struct === 'menu' || struct === 'footer') {
            if (currentEditType === 'layout' && currentEditName === struct) return null;
            const message = struct === 'menu'
                ? QSPanelWords.t('preview.switchToMenuConfirm')
                : QSPanelWords.t('preview.switchToFooterConfirm');
            return { message: message, type: 'layout', name: struct };
        }
        if (struct && currentEditType === 'layout' && struct.startsWith('page-')) {
            const page = struct.substring(5);
            return { message: QSPanelWords.t('preview.switchToPageConfirm', { page: page }), type: 'page', name: page };
        }
        return null;
    }

    /**
     * A click outside what the Edit list names asks to switch to it. On a no, the frame goes back to
     * what the panel holds, so the outline, the Edit list and the actions never disagree.
     */
    function askToSwitchTarget(target) {
        QuickSiteUtils.confirm(target.message).then(ok => {
            if (ok) {
                if (targetSelect) showEditTarget(target.type + ':' + target.name);
                navigateTo(target.type, target.name);
            } else if (selectedStruct != null && selectedFrameNode != null) {
                sendToIframe('selectNode', { struct: selectedStruct, node: selectedFrameNode });
            } else {
                sendToIframe('clearSelection', {});
            }
        });
    }

    function showNodePanel(data) {
        // AI tools mode forwards click handling as select-mode in the iframe
        // but doesn't care which structure is being edited — selection is
        // purely informational (no edit-context switch). Skip the
        // structure-mismatch prompts entirely when in ai-tools mode.
        const outside = currentMode === 'ai-tools' ? null : switchFor(data.struct || null);
        if (outside) {
            askToSwitchTarget(outside);
            // Either way, don't process this selection (it came from the wrong context)
            return;
        }
        
        setPanelOpen(true);

        // Store selection info for edit/copy actions
        selectedStruct = data.struct || null;
        selectedNode = data.isComponent ? data.componentNode : data.node;
        selectedFrameNode = data.node;
        selectedComponent = data.component || null;
        selectedElementClasses = data.classes || null;  // Store classes for style mode
        selectedElementTag = data.tag || null;          // Store tag for style mode
        selectedHoldsChildren = data.holdsChildren !== false;
        selectedIsInline = data.inline === true;
        // An Add form left open follows the new selection's positions.
        if (contextualAddMode === 'add' && contextualAddForm && contextualAddForm.style.display !== 'none') {
            _syncAddPositionOptions();
            if (window.PreviewSimpleAdd) PreviewSimpleAdd.refresh();
        }
        
        // If Variables panel is open, refresh it for the new node instead of hiding
        const variablesPanelOpen = variablesPanel && variablesPanel.style.display !== 'none';
        // If Enums panel is open, keep it open (it's component-global, not node-specific)
        const enumsPanelOpen = enumsPanel && enumsPanel.style.display !== 'none';
        if (variablesPanelOpen) {
            // Update state first, then refresh
            if (window.PreviewState) {
                PreviewState.set('selectedStruct', selectedStruct);
                PreviewState.set('selectedNode', selectedNode);
                PreviewState.set('navHasParent', data.hasParent || false);
                PreviewState.set('navHasPrevSibling', data.hasPrevSibling || false);
                PreviewState.set('navHasNextSibling', data.hasNextSibling || false);
                PreviewState.set('navHasChildren', data.hasChildren || false);
            }
            if (window.PreviewNavigation) {
                PreviewNavigation.updateButtons();
            }
            showVariablesPanel();
            return;
        }
        
        // Update PreviewState with selection and navigation info
        if (window.PreviewState) {
            PreviewState.set('selectedStruct', selectedStruct);
            PreviewState.set('selectedNode', selectedNode);
            PreviewState.set('navHasParent', data.hasParent || false);
            PreviewState.set('navHasPrevSibling', data.hasPrevSibling || false);
            PreviewState.set('navHasNextSibling', data.hasNextSibling || false);
            PreviewState.set('navHasChildren', data.hasChildren || false);
        }
        
        // Update navigation buttons
        if (window.PreviewNavigation) {
            PreviewNavigation.updateButtons();
        }
        
        // Update contextual area info
        showContextualInfo(data);

        // Toggle the Translate-from-CSV button (visible only for tables
        // stamped with data-qs-complex='table'). Queries the iframe DOM
        // for the selected node to read its data attrs + measure dims.
        _updateTranslateCsvButton(data);
    }

    // ============================================================
    // Translate-from-CSV
    // ============================================================
    // Translate an existing complex-element subtree (currently only
    // Table) to another language by pasting a CSV-shaped grid. UI:
    // button in the select-mode toolbar (visible only for tables
    // stamped with data-qs-complex='table') → modal → POST to
    // /management/importStructureTranslations.
    //
    // The button + modal are wired here rather than spreading across
    // multiple files because the entire flow is small and tightly
    // coupled to selection state.

    function _measureTableDimsFromDom(tableEl) {
        if (!tableEl || tableEl.tagName !== 'TABLE') return null;
        const thead = tableEl.querySelector(':scope > thead');
        const tbody = tableEl.querySelector(':scope > tbody');
        const hasHead = !!thead;
        let headerCols = 0;
        if (thead) {
            const headTr = thead.querySelector(':scope > tr');
            if (headTr) headerCols = headTr.querySelectorAll(':scope > th, :scope > td').length;
        }
        const bodyRowEls = tbody ? tbody.querySelectorAll(':scope > tr') : [];
        const bodyRows = bodyRowEls.length;
        let bodyCols = 0;
        if (bodyRows > 0) {
            bodyCols = bodyRowEls[0].querySelectorAll(':scope > td, :scope > th').length;
        }
        return {
            hasHead: hasHead,
            headerCols: headerCols,
            bodyRows: bodyRows,
            cols: Math.max(headerCols, bodyCols),
        };
    }

    function _updateTranslateCsvButton(data) {
        if (!ctxNodeTranslateCsv) return;
        // Default: hide. Recompute every selection so stale state can't linger.
        ctxNodeTranslateCsv.style.display = 'none';
        translateCsvContext = null;

        if (!data || !data.node) return;
        const iframeDoc = iframe && (iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document));
        if (!iframeDoc) return;

        // selectedNode = data-qs-node id of the clicked element.
        const safeNode = String(data.node).replace(/"/g, '\\"');
        const el = iframeDoc.querySelector('[data-qs-node="' + safeNode + '"]');
        if (!el) return;

        const kind = el.getAttribute('data-qs-complex');
        const sid = el.getAttribute('data-qs-complex-id');
        if (kind !== 'table' || !sid) return;

        const dims = _measureTableDimsFromDom(el);
        if (!dims) return;

        // The route to pass to the backend is the current page name. The
        // selection's struct field shape is `page-<route>` for pages;
        // strip the prefix to get the route as the backend expects.
        let route = data.struct || '';
        if (route.indexOf('page-') === 0) route = route.substring('page-'.length);

        translateCsvContext = {
            structureId: sid,
            kind: kind,
            route: route,
            dims: dims,
        };
        ctxNodeTranslateCsv.style.display = '';
    }

    // Mini TSV/CSV parser — same heuristic as complex-table.js
    // (tabs win when present on the first non-empty line; otherwise
    // comma split). Quoted cells with embedded delimiters are NOT
    // supported in this MVP — user can fix individual cells after
    // pasting if it bites.
    function _parseTabularBlob(text) {
        const lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
        while (lines.length && lines[lines.length - 1] === '') lines.pop();
        if (lines.length === 0) return [];
        const firstNonEmpty = lines.find(l => l.trim() !== '') || '';
        const sep = firstNonEmpty.indexOf('\t') !== -1 ? '\t' : ',';
        return lines.map(l => l.split(sep).map(c => c.trim()));
    }

    /** The language the editor shows, which the dialog's language picker starts on at every opening. */
    function _translateCsvShownLang() {
        const sel = document.getElementById('preview-lang');
        return sel && sel.value ? sel.value : (window.PreviewConfig && window.PreviewConfig.defaultLang) || 'en';
    }

    // Populate the language picker the FIRST time the modal opens. Result
    // is cached on the select element itself so subsequent opens reuse it,
    // each starting on the language shown at that opening.
    function _ensureTranslateCsvLangsLoaded() {
        if (!translateCsvLang) return Promise.resolve();
        if (translateCsvLang._loaded) {
            const shown = _translateCsvShownLang();
            if (Array.from(translateCsvLang.options).some(o => o.value === shown)) translateCsvLang.value = shown;
            return Promise.resolve();
        }
        const api = window.QuickSiteAdmin;
        if (!api) return Promise.resolve();
        return api.apiRequest('getLangList', 'GET').then(function (res) {
            // res shape: {ok, status, data: <envelope>}; envelope = {status, code, data: {languages, ...}}
            const payload = (res && res.data && (res.data.data || res.data)) || {};
            const langs = Array.isArray(payload.languages) ? payload.languages : ['en'];
            const currentEditorLang = _translateCsvShownLang();
            translateCsvLang.replaceChildren();
            langs.forEach(function (lang) {
                const opt = document.createElement('option');
                opt.value = lang;
                opt.textContent = lang;
                if (lang === currentEditorLang) opt.selected = true;
                translateCsvLang.appendChild(opt);
            });
            translateCsvLang._loaded = true;
        }).catch(function () { /* leave (loading…) — user picks at submit time */ });
    }

    /** The table's dimensions in words: its header's cells when it has a header, then its body's rows × columns. */
    function _translateCsvDims(dims) {
        const parts = [];
        if (dims.hasHead) parts.push(QSPanelWords.t('preview.translateCsvDimsHeader', { cells: QSPanelWords.number(dims.headerCols) }));
        parts.push(QSPanelWords.t('preview.translateCsvDimsBody', { rows: QSPanelWords.number(dims.bodyRows), cols: QSPanelWords.number(dims.cols) }));
        return parts.join(' + ');
    }

    /** Row numbers in a list, as a status line names them. */
    function _translateCsvRowList(rows) {
        return rows.map((n) => QSPanelWords.number(n, { useGrouping: false })).join(', ');
    }

    // Live-validate the pasted grid against the expected dimensions.
    //
    // Severity model:
    //   - HARD ERROR (blocks Apply): header presence/count mismatch,
    //     body row count mismatch. These would silently corrupt the
    //     translation namespace or lose data the user thinks is being
    //     written.
    //   - WARNING (allows Apply): per-row column count mismatch. The
    //     backend handles jagged grids safely — short rows get padded
    //     with empty strings, over-long rows get truncated — so the
    //     user should be told but not blocked. They can fix individual
    //     cells via the translation editor after applying.
    //
    // Returns { ok, severity, message, hasContent }:
    //   ok=true  → Apply allowed (green ✓ or yellow ⚠)
    //   ok=false → Apply blocked (red ✕)
    //   hasContent=false → neutral empty state (Apply still disabled)
    function _validateTranslateCsvPaste() {
        if (!translateCsvContext) return { ok: false, severity: 'empty', message: '', hasContent: false };
        const ctx = translateCsvContext;
        const raw = translateCsvPasteArea ? translateCsvPasteArea.value : '';
        if (!raw.trim()) return { ok: false, severity: 'empty', message: '', hasContent: false };

        const grid = _parseTabularBlob(raw);
        if (grid.length === 0) return { ok: false, severity: 'empty', message: '', hasContent: false };

        const hasHead = translateCsvHasHead ? translateCsvHasHead.checked : false;
        let header = [];
        let rows = grid;
        if (hasHead) {
            header = grid[0] || [];
            rows = grid.slice(1);
        }

        const expectedHeader = ctx.dims.hasHead ? ctx.dims.headerCols : 0;
        const expectedBody = ctx.dims.bodyRows;
        const expectedCols = ctx.dims.cols;

        const pastedHeader = hasHead ? header.length : 0;
        const pastedBody = rows.length;

        // ---- HARD errors ------------------------------------------------
        const hardErrs = [];
        if (pastedHeader !== expectedHeader) {
            const headerWord = !ctx.dims.hasHead && hasHead ? 'preview.translateCsvHeaderMismatchUntick'
                : (ctx.dims.hasHead && !hasHead ? 'preview.translateCsvHeaderMismatchTick' : 'preview.translateCsvHeaderMismatch');
            hardErrs.push(QSPanelWords.t(headerWord, { expected: QSPanelWords.number(expectedHeader), got: QSPanelWords.number(pastedHeader) }));
        }
        if (pastedBody !== expectedBody) {
            hardErrs.push(QSPanelWords.t('preview.translateCsvBodyMismatch', { expected: QSPanelWords.number(expectedBody), got: QSPanelWords.number(pastedBody) }));
        }
        if (hardErrs.length > 0) {
            return {
                ok: false,
                severity: 'error',
                hasContent: true,
                message: QSPanelWords.t('preview.translateCsvErrors', { errors: hardErrs.join(QSPanelWords.t('common.join.semicolon')) }),
            };
        }

        // ---- per-row WARNINGS -------------------------------------------
        // Identify rows whose cell count doesn't match expected. Separate
        // "too few" and "too many" so the message tells the user what'll
        // happen at write time (pad with empty / drop extras).
        const tooFew = [];   // row indices (1-based for user)
        const tooMany = [];
        rows.forEach(function (row, i) {
            if (row.length < expectedCols) tooFew.push(i + 1);
            else if (row.length > expectedCols) tooMany.push(i + 1);
        });
        // Also flag a header that has fewer/more cells than expected.
        // Edge case: hasHead match check above caught the count mismatch
        // for the GROUP, but if the user pasted an all-empty cell at the
        // end of the header row, it still parsed as one cell — handled
        // by the header check, no extra logic here.

        if (tooFew.length === 0 && tooMany.length === 0) {
            return {
                ok: true,
                severity: 'ok',
                hasContent: true,
                message: QSPanelWords.t('preview.translateCsvMatches', { dims: _translateCsvDims(ctx.dims) }),
            };
        }

        // Build the warning sentence — keep it short when only one
        // category is in play.
        const warnParts = [];
        if (tooFew.length > 0) {
            warnParts.push(QSPanelWords.count('preview.translateCsvRowsTooShort', tooFew.length, { rows: _translateCsvRowList(tooFew) }));
        }
        if (tooMany.length > 0) {
            warnParts.push(QSPanelWords.count('preview.translateCsvRowsTooLong', tooMany.length,
                { rows: _translateCsvRowList(tooMany), column: QSPanelWords.number(expectedCols) }));
        }
        return {
            ok: true,   // allow Apply — backend handles jagged grids safely
            severity: 'warning',
            hasContent: true,
            message: QSPanelWords.t('preview.translateCsvWarnings', { warnings: warnParts.join(QSPanelWords.t('common.join.semicolon')) }),
        };
    }

    // Re-validate the paste + update the status line + Apply button on
    // every keystroke / hasHead toggle. Cheap (sub-ms for typical grids).
    function _refreshTranslateCsvValidation() {
        const v = _validateTranslateCsvPaste();
        if (translateCsvStatus) {
            translateCsvStatus.textContent = v.message;
            // Color hints — three states: green (ok), amber (warning,
            // Apply still allowed), red (hard error, Apply blocked).
            // Empty/neutral state clears the colour entirely.
            let color = '';
            if (v.severity === 'ok')        color = 'var(--admin-success, #2c7a3f)';
            else if (v.severity === 'warning') color = 'var(--admin-warning, #b3691e)';
            else if (v.severity === 'error')   color = 'var(--admin-error)';
            translateCsvStatus.style.color = color;
        }
        if (translateCsvApply) {
            translateCsvApply.disabled = !v.ok;
        }
    }

    function openTranslateCsvModal() {
        if (!translateCsvModal || !translateCsvContext) return;
        const ctx = translateCsvContext;

        if (translateCsvStructIdEl) translateCsvStructIdEl.textContent = '"' + ctx.structureId + '"';
        if (translateCsvExpectedDims) {
            translateCsvExpectedDims.textContent = QSPanelWords.t('preview.translateCsvExpected', { dims: _translateCsvDims(ctx.dims) });
        }
        if (translateCsvHasHead) translateCsvHasHead.checked = !!ctx.dims.hasHead;
        if (translateCsvPasteArea) translateCsvPasteArea.value = '';
        if (translateCsvStatus) {
            translateCsvStatus.textContent = '';
            translateCsvStatus.style.color = '';
        }
        // Apply button starts disabled — validation enables it once a
        // dimension-matching grid is pasted.
        if (translateCsvApply) translateCsvApply.disabled = true;

        _ensureTranslateCsvLangsLoaded();
        translateCsvModal.style.display = '';
        if (translateCsvPasteArea) translateCsvPasteArea.focus();
    }

    function closeTranslateCsvModal() {
        if (translateCsvModal) translateCsvModal.style.display = 'none';
    }

    async function submitTranslateCsv() {
        if (!translateCsvContext) return;
        const ctx = translateCsvContext;
        if (!translateCsvStatus) return;

        const grid = _parseTabularBlob(translateCsvPasteArea ? translateCsvPasteArea.value : '');
        if (grid.length === 0) {
            translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvNothingPasted');
            return;
        }
        const hasHead = translateCsvHasHead ? translateCsvHasHead.checked : false;
        let header = [];
        let rows = grid;
        if (hasHead) {
            header = grid[0] || [];
            rows = grid.slice(1);
            if (rows.length === 0) {
                translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvHeaderOnly');
                return;
            }
        }
        const lang = translateCsvLang ? translateCsvLang.value : '';
        if (!lang) {
            translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvPickLanguage');
            return;
        }

        const api = window.QuickSiteAdmin;
        if (!api) {
            translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvNoApi');
            return;
        }
        if (translateCsvApply) translateCsvApply.disabled = true;
        translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvWriting', { lang: lang });

        try {
            const res = await api.apiRequest('importStructureTranslations', 'POST', {
                route:       ctx.route,
                kind:        ctx.kind,
                structureId: ctx.structureId,
                language:    lang,
                header:      header,
                rows:        rows,
            });
            const payload = (res && res.data && (res.data.data || res.data)) || {};
            const envelope = res && res.data;
            const ok = res && res.ok;

            if (!ok) {
                // 422 dimension mismatch — show the diff if present.
                const code = envelope && envelope.code;
                const msg = (envelope && envelope.message) || QSPanelWords.t('preview.translateCsvRequestFailed', { status: res && res.status });
                if (code === 'validation.dimension_mismatch' && payload.diffs) {
                    translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvAdjust', { message: msg });
                } else {
                    translateCsvStatus.textContent = msg;
                }
                if (translateCsvApply) translateCsvApply.disabled = false;
                return;
            }

            const written = payload.keysWritten || 0;
            translateCsvStatus.textContent = QSPanelWords.count('preview.translateCsvWrote', written, { id: ctx.structureId, lang: lang });
            // Close after a short pause so the user sees the success.
            setTimeout(function () {
                closeTranslateCsvModal();
                // Reload the iframe so the rendered table shows the new
                // translations immediately (if the editor language matches
                // the just-translated language).
                if (iframe && iframe.contentWindow) {
                    try { iframe.contentWindow.location.reload(); } catch (e) {}
                }
            }, 800);

        } catch (err) {
            console.error('[translate-csv] submit failed:', err);
            translateCsvStatus.textContent = QSPanelWords.t('preview.translateCsvRequestFailedConsole');
            if (translateCsvApply) translateCsvApply.disabled = false;
        }
    }

    // Wire button + modal listeners (once on init).
    if (ctxNodeTranslateCsv) {
        ctxNodeTranslateCsv.addEventListener('click', openTranslateCsvModal);
    }
    if (translateCsvClose)  translateCsvClose.addEventListener('click', closeTranslateCsvModal);
    if (translateCsvCancel) translateCsvCancel.addEventListener('click', closeTranslateCsvModal);
    if (translateCsvApply)  translateCsvApply.addEventListener('click', submitTranslateCsv);
    // Live validation — re-runs on every keystroke (`input` covers both
    // typing and the paste event) and on hasHead toggle (which changes
    // how we split header vs body).
    if (translateCsvPasteArea) {
        translateCsvPasteArea.addEventListener('input', _refreshTranslateCsvValidation);
    }
    if (translateCsvHasHead) {
        translateCsvHasHead.addEventListener('change', _refreshTranslateCsvValidation);
    }
    // Dismiss on backdrop click.
    if (translateCsvModal) {
        translateCsvModal.addEventListener('click', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('preview-translate-csv-modal__backdrop')) {
                closeTranslateCsvModal();
            }
        });
    }

    function hideNodePanel() {
        selectedStruct = null;
        selectedNode = null;
        selectedComponent = null;
        selectedElementClasses = null;
        selectedElementTag = null;
        selectedHoldsChildren = true;
        selectedIsInline = false;
        
        // Clear navigation states
        if (window.PreviewState) {
            PreviewState.set('selectedStruct', null);
            PreviewState.set('selectedNode', null);
            PreviewState.set('navHasParent', false);
            PreviewState.set('navHasPrevSibling', false);
            PreviewState.set('navHasNextSibling', false);
            PreviewState.set('navHasChildren', false);
        }
        
        // Update navigation buttons (disable all)
        if (window.PreviewNavigation) {
            PreviewNavigation.updateButtons();
        }
        
        // Hide contextual info
        hideContextualInfo();
        
        sendToIframe('clearSelection', {});
    }
    
    // ==================== Iframe Communication ====================
    
    /**
     * The frame's mode, with what its inline edit reads a translated text by: the markers a word may carry and
     * the elements that show no formatting (TagRegistry, through PreviewConfig.tagInfo: TAG_INFO is declared
     * further down, and a mode is sent before it is).
     */
    function frameModeMessage(mode) {
        const tagInfo = PreviewConfig.tagInfo || {};
        return { mode: mode, textMarkers: tagInfo.TEXT_MARKERS || null, plainTextTags: tagInfo.PLAIN_TEXT_TAGS || [] };
    }

    /**
     * The words of the language shown, by key, as they are stored (placeholders unfilled, markers written), from the
     * one list of the site's words (QSSiteWords): inline edit starts a text from its stored word, so a sentence holding
     * {{__lang}} or a parameter is edited and saved as written, never as the page filled it. Sent to the frame with
     * text mode, after each reload in it, and when the site's words change; the frame keeps the last it was sent.
     */
    function sendStoredWords() {
        if (!window.QSSiteWords) return;
        QSSiteWords.read().then(function (list) {
            if (currentMode !== 'text' || !list) return;
            const lang = QSSiteWords.shownLanguage(list, getCurrentLang());
            const words = {};
            QSSiteWords.leafKeys(list.words[lang] || {}).forEach(function (key) {
                const word = QSSiteWords.word(list, lang, key);
                if (word !== null) words[key] = word;
            });
            sendToIframe('storedWords', { words: words });
        });
    }

    /**
     * Show a translation's word in a text of the frame, as the renderer draws it there: its markers elements
     * (QSTextMarkers, core/text-markers.js), or, inside an element that shows no formatting, its words alone.
     */
    function showFrameWord(textEl, word) {
        const tagInfo = PreviewConfig.tagInfo || {};
        const plain = (tagInfo.PLAIN_TEXT_TAGS || []).join(',');
        if (!window.QSTextMarkers || !tagInfo.TEXT_MARKERS) {
            textEl.textContent = word;
        } else if (plain && textEl.closest(plain)) {
            textEl.textContent = window.QSTextMarkers.remove(word, tagInfo.TEXT_MARKERS);
        } else {
            textEl.replaceChildren(window.QSTextMarkers.read(textEl.ownerDocument, word, tagInfo.TEXT_MARKERS, { editor: true }));
        }
    }

    function sendToIframe(action, data) {
        try {
            const iframeWindow = iframe.contentWindow;
            if (iframeWindow) {
                // Same-origin: target the iframe's specific origin instead of '*'.
                iframeWindow.postMessage({ source: 'quicksite-admin', action, ...data }, window.location.origin);
            }
        } catch (e) {
            console.warn('Could not send message to iframe:', e);
        }
    }
    
    function injectOverlay() {
        if (overlayInjected) {
            console.log('[Preview] Overlay already injected, skipping');
            return;
        }
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) {
                console.warn('[Preview] Could not access iframe document');
                return;
            }
            
            if (!iframeDoc.body) {
                console.warn('[Preview] Iframe body not ready yet');
                return;
            }
            
            // Check if script is already alive (has event listeners)
            if (iframeDoc.getElementById('quicksite-overlay-script') && iframeDoc.getElementById('quicksite-overlay-styles')) {
                console.log('[Preview] Overlay already present in DOM, marking as injected');
                overlayInjected = true;
                return;
            }
            
            console.log('[Preview] Injecting overlay into iframe...');
            
            // Inject external CSS file instead of inline styles
            const cssLink = iframeDoc.createElement('link');
            cssLink.id = 'quicksite-overlay-styles';
            cssLink.rel = 'stylesheet';
            cssLink.href = baseUrl + '/admin/assets/css/preview-iframe-overlay.css';
            iframeDoc.head.appendChild(cssLink);
            
            // The grammar of a translation's markers, which the inline edit writes a sentence back with
            const markersScript = iframeDoc.createElement('script');
            markersScript.id = 'quicksite-text-markers-script';
            markersScript.async = false;
            markersScript.src = baseUrl + '/admin/assets/js/core/text-markers.js?v=' + Date.now();
            iframeDoc.body.appendChild(markersScript);

            // Inject script - uses data-qs-* attributes for node info
            const script = iframeDoc.createElement('script');
            script.id = 'quicksite-overlay-script';
            script.src = baseUrl + '/admin/assets/js/pages/preview/preview-iframe-inject.js?v=' + Date.now();
            iframeDoc.body.appendChild(script);
            
            overlayInjected = true;
            console.log('[Preview] Overlay injection successful!');
        } catch (e) {
            console.warn('[Preview] Could not inject overlay (cross-origin?):', e);
        }
    }
    
    // Listen for messages from iframe
    window.addEventListener('message', function(e) {
        // Security: reject messages from any other origin (preview iframe is same-origin)
        if (e.origin !== window.location.origin) return;
        if (e.data && e.data.source === 'quicksite-preview') {
            console.log('[Preview] Message from iframe:', e.data);
            if (e.data.action === 'elementSelected') {
                showNodePanel(e.data);
            }
            if (e.data.action === 'overlayReady') {
                console.log('[Preview] Iframe overlay is ready, restoring mode:', currentMode);
                // Re-send current mode to iframe (preserves drag mode after reload)
                // Use setTimeout to ensure iframe's message listener is fully ready
                setTimeout(() => {
                    console.log('[Preview] Sending setMode to iframe:', currentMode);
                    // Use sendToIframe to ensure source is included (required by iframe handler)
                    // AI tools mode reuses select-mode iframe behavior (see setMode comment).
                    sendToIframe('setMode', frameModeMessage(currentMode === 'ai-tools' ? 'select' : currentMode));
                    if (currentMode === 'text') sendStoredWords();
                    if (currentMode !== 'select') {
                        sendToIframe('clearSelection', {});
                    }
                    
                    // If in JS mode, refresh page classes for the new page
                    if (currentMode === 'js') {
                        pageStructureClasses = []; // Clear old page data
                        sendToIframe('getPageClasses', {}); // Request fresh data
                        hideJsPanel(); // Reset panel since element selection was cleared
                        
                        // Refresh page events for the new page
                        if (window.PreviewJsInteractions) {
                            if (currentEditType === 'page') {
                                PreviewJsInteractions.setCurrentPage(currentEditName);
                                PreviewJsInteractions.loadPageEvents();
                                PreviewJsInteractions.loadStateStores();
                            } else {
                                PreviewJsInteractions.setCurrentPage(null);
                            }
                        }
                    }
                    
                    // Bring the layout target (menu/footer) into view after navigation
                    if (layoutToReveal) {
                        const struct = layoutToReveal; // 'menu' or 'footer'
                        layoutToReveal = null;
                        // Small extra delay to ensure DOM is rendered
                        setTimeout(() => {
                            sendToIframe('revealNode', { struct: struct, node: '0' });
                        }, 100);
                    }
                    
                    // Ensure layout toggles match current edit type
                    updateLayoutToggles(currentEditType, currentEditName);
                    // Sync layout toggles from iframe DOM (read actual menu/footer presence)
                    syncLayoutTogglesFromIframe();
                    // The title field's placeholder: what the preview's tab shows
                    syncPageTitlePlaceholder();
                }, 50); // Small delay to ensure iframe is ready to receive messages
            }
            if (e.data.action === 'elementMoved' || e.data.action.startsWith('drag')) {
                if (window.PreviewDrag) PreviewDrag.handleMessage(e.data);
            }
            if (e.data.action === 'textEdited') {
                handleTextEdited(e.data);
            }
            if (e.data.action === 'styleSelected') {
                setPanelOpen(true);
                updateGlobalElementInfo(e.data.element);
                
                // Filter the selector browser to show only this element's selectors
                if (window.PreviewSelectorBrowser && e.data.style) {
                    PreviewSelectorBrowser.filterByElement({
                        tag: e.data.style.tag,
                        classList: e.data.style.classList,
                        id: e.data.style.id
                    });
                }
            }
            if (e.data.action === 'interactionSelected') {
                setPanelOpen(true);
                showJsPanel(e.data);
                updateGlobalElementInfo(e.data.element);
                // Mirror the selection into the shared selectedStruct/Node
                // and PreviewState navigation flags so arrow-key navigation
                // (PreviewNavigation.handleArrowKey) works in JS mode the
                // same way it does in select mode.
                const elInfo = e.data.element || {};
                selectedStruct = elInfo.struct || null;
                selectedNode = elInfo.isComponent ? elInfo.componentNode : elInfo.node;
                if (window.PreviewState) {
                    PreviewState.set('selectedStruct', selectedStruct);
                    PreviewState.set('selectedNode', selectedNode);
                    PreviewState.set('navHasParent', elInfo.hasParent || false);
                    PreviewState.set('navHasPrevSibling', elInfo.hasPrevSibling || false);
                    PreviewState.set('navHasNextSibling', elInfo.hasNextSibling || false);
                    PreviewState.set('navHasChildren', elInfo.hasChildren || false);
                }
                if (window.PreviewNavigation) {
                    PreviewNavigation.updateButtons();
                }
            }
            if (e.data.action === 'textElementInfo') {
                updateGlobalElementInfo(e.data.element);
                updateTextModeInfo(e.data.element);
            }
            if (e.data.action === 'pageClassesResult') {
                pageStructureClasses = e.data.classes || [];
                console.log('[Preview] Received page classes:', pageStructureClasses.length);
            }
            if (e.data.action === 'structureEmptied') {
                // The last element of a structure was deleted: reload, so the server
                // renders the placeholder that lets the author select its root again.
                reloadPreview();
            }
            if (e.data.action === 'insertNodeFailed') {
                // The node is saved, but the page could not show it in place: a reload does.
                reloadPreview();
            }
        }
    });
    
    // ==================== Scroll To Node ====================
    
    /**
     * Scroll the iframe to a specific node
     */
    function scrollToNode(struct, nodeId) {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            const selector = `[data-qs-struct="${struct}"][data-qs-node="${nodeId}"]`;
            const element = iframeDoc.querySelector(selector);
            
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Flash highlight effect
                element.style.outline = '3px solid var(--primary, #3b82f6)';
                element.style.outlineOffset = '2px';
                setTimeout(() => {
                    element.style.outline = '';
                    element.style.outlineOffset = '';
                }, 1500);
                console.log('[Preview] Scrolled to node:', struct, nodeId);
            } else {
                console.log('[Preview] Node not found for scroll:', selector);
            }
        } catch (err) {
            console.error('[Preview] Scroll to node failed:', err);
        }
    }
    
    // ==================== Drag & Drop Handler ====================
    
    /**
     * Parse struct string into type and name.
     * Delegates to PreviewState.utils.parseStruct (canonical).
     */
    function parseStruct(struct) {
        return PreviewState.utils.parseStruct(struct);
    }
    
    /**
     * Get a node from structure by nodeId (e.g., "0.2.1").
     * Delegates to PreviewState.utils.getNodeByPath (canonical).
     */
    function getNodeByPath(structure, nodeId) {
        return PreviewState.utils.getNodeByPath(structure, nodeId);
    }
    
    /**
     * Deep clone an object, removing _nodeId properties.
     * Delegates to PreviewState.utils.cloneWithoutNodeIds (canonical).
     */
    function cloneWithoutNodeIds(obj) {
        return PreviewState.utils.cloneWithoutNodeIds(obj);
    }
    
    // handleElementMoved — extracted to preview-drag.js (PreviewDrag.handleMessage)
    
    // ==================== Text Edit Handler ====================
    
    async function handleTextEdited(data) {
        console.log('[Preview] Text edited:', data);
        
        const textKey = data.textKey;
        const newValue = data.newValue;
        const oldValue = data.oldValue;
        
        if (!textKey || newValue === undefined) {
            console.error('[Preview] Invalid text edit data:', data);
            showToast(QSPanelWords.t('preview.errorDetail', { error: QSPanelWords.t('preview.textEditInvalidData') }), 'error');
            return;
        }
        
        // Reject unresolved component variable placeholders (e.g., {{$icon}})
        if (textKey.includes('{{')) {
            console.warn('[Preview] Ignoring edit on unresolved variable placeholder:', textKey);
            showToast(QSPanelWords.t('preview.textEditUnresolvedVariable'), 'warning');
            return;
        }

        // A RAW text is NOT a translation key — update the node's textKey
        // in the structure directly instead of writing a (bogus) translation.
        if (textKey.startsWith('__RAW__')) {
            return saveRawTextEdit(data);
        }

        // Get current language from the preview selector
        const lang = getCurrentLang();

        try {
            // The word as written, in the language shown, through the one list of the site's words
            // (QSSiteWords.write(): the answer checked, a refusal's words shown and nothing changed).
            const words = {};
            words[textKey] = newValue;
            const done = await QSSiteWords.write(lang, words);
            if (!done.ok) {
                throw new Error(done.message);
            }

            showToast(QSPanelWords.t('preview.textSaved'), 'success');
            // A word holding a placeholder ({{__lang}}, a parameter) shows filled once the page draws it again.
            if (newValue.indexOf('{{') !== -1) {
                reloadPreview();
                return;
            }

            // Update the span the user just edited — the frame already
            // shows the new word there, no action needed.
            // BUT: sweep the iframe for OTHER spans referencing the same
            // textKey + show the new word in each, with its formatting, as
            // the renderer draws it (showFrameWord()). Without this
            // sweep, the editor shows the new text only on the edited
            // span while siblings stay stale (looks like inconsistent
            // state until the iframe reloads). The rendered page is
            // always correct — this is purely an editor-view sync.
            try {
                const iframeDoc = iframe && (iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document));
                if (iframeDoc) {
                    const safeKey = String(textKey).replace(/"/g, '\\"');
                    iframeDoc.querySelectorAll('[data-qs-textkey="' + safeKey + '"]').forEach(function (span) {
                        // Skip the actively-editing element so we don't
                        // clobber an in-progress contenteditable cursor
                        // — the user can see their edits already.
                        if (span.getAttribute('contenteditable') === 'true') return;
                        showFrameWord(span, newValue);
                    });
                }
            } catch (sweepErr) {
                // Iframe access can throw cross-origin in pathological
                // setups; don't fail the save just because we couldn't
                // sync the sibling spans. The next iframe reload will
                // refresh everything.
                console.warn('[Preview] textKey sibling sweep failed:', sweepErr);
            }
            
        } catch (error) {
            console.error('[Preview] Text save error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
            
            // Restore original text in iframe on error
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const textEl = iframeDoc.querySelector(`[data-qs-textkey="${textKey}"]`);
                if (textEl) {
                    showFrameWord(textEl, oldValue);
                }
            } catch (e) {
                console.error('[Preview] Could not restore text:', e);
            }
        }
    }

    /**
     * Save a RAW/LIT literal text edit. Literals have no translation entry, so we
     * update the node's textKey in the structure (editStructure) to
     * "__RAW__" + newValue instead of writing a translation.
     */
    async function saveRawTextEdit(data) {
        const struct = data.structure;
        const node = data.node;
        if (!struct || !node) {
            console.error('[Preview] Raw text edit missing struct/node:', data);
            showToast(QSPanelWords.t('preview.errorDetail', { error: QSPanelWords.t('preview.textEditMissingNode') }), 'error');
            return;
        }
        const info = parseStruct(struct);
        if (!info || !info.type) {
            showToast(QSPanelWords.t('preview.errorDetail', { error: QSPanelWords.t('preview.textEditInvalidStructure') }), 'error');
            return;
        }

        const params = {
            type: info.type,
            nodeId: node,
            action: 'update',
            structure: { textKey: '__RAW__' + data.newValue }
        };
        if (info.name) params.name = info.name;

        try {
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PATCH', params);
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || QSPanelWords.t('preview.textSaveFailed'));
            }
            showToast(QSPanelWords.t('preview.textSaved'), 'success');
            // The inline contenteditable already shows the new text; the stale
            // data-qs-textkey on the span refreshes on the next reload.
        } catch (error) {
            console.error('[Preview] Raw text save error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const textEl = iframeDoc.querySelector(`[data-qs-textkey="${data.textKey}"]`);
                if (textEl) textEl.textContent = data.oldValue;
            } catch (e) {
                console.error('[Preview] Could not restore text:', e);
            }
        }
    }

    // ==================== Utility Functions ====================
    
    // ==================== DOM helpers (each returns ONE Element) ====================

    // The editor's own icons, inner SVG markup for QSDom.iconEl (the others are QuickSiteUtils.ICON_PATHS).
    const ICON_RESET = '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>';
    const ICON_TEXT_VARIABLES = '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>';
    const ICON_PARAM = '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>';
    const ICON_NODE_PATH = '<circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6m-7-7H1m22 0h-4"/>';

    /** One <option>; `selected` sets the attribute, so the select starts on it. */
    function _renderOption(value, text, selected) {
        const option = QSDom.el('option', { value: value, text: text });
        if (selected) option.setAttribute('selected', '');
        return option;
    }

    /** An icon sized by its style (the enums and emulation panels' small buttons). */
    function _renderSizedIcon(paths, px) {
        const icon = QSDom.iconEl(paths, 0);
        icon.setAttribute('style', 'width:' + px + 'px;height:' + px + 'px;');
        return icon;
    }

    /** The icon in front of a variables panel section's title. */
    function _renderSectionIcon(paths) {
        const icon = QSDom.iconEl(paths, 0);
        icon.setAttribute('style', 'width:14px;height:14px;vertical-align:middle;margin-right:4px;');
        return icon;
    }

    /** The line a panel shows when it failed to load: "Error: <message>". */
    function _renderPanelError(error) {
        return QSDom.el('div', {
            style: 'color:var(--admin-error);font-size:var(--font-size-sm);padding:var(--space-sm);',
            text: QSPanelWords.t('preview.errorDetail', { error: error.message })
        });
    }

    /** The note a component or snippet list shows when it is empty or failed to load. */
    function _renderSelectorNote(text) {
        return QSDom.el('div', { class: 'snippet-selector__empty' }, [
            QSDom.iconEl(QuickSiteUtils.ICON_PATHS.ban, 0),
            ' ',
            QSDom.el('span', { text: text })
        ]);
    }

    /** The spinner a component or snippet list shows while it loads. */
    function _renderSelectorLoading() {
        return QSDom.el('div', { class: 'snippet-selector__loading' }, [
            QSDom.el('div', {}),
            QSPanelWords.t('common.loading')
        ]);
    }

    /** The note the asset picker shows when it has nothing to list. */
    function _renderAssetPickerNote(text) {
        return QSDom.el('div', { class: 'preview-asset-picker__empty', text: text });
    }

    /** The Complex tab's line when a kind cannot draw its wizard. */
    function _renderComplexError(text) {
        return QSDom.el('p', { class: 'admin-hint', text: text });
    }

    /** A section title of a component's variables in the Add form. */
    function _renderVariablesSectionTitle(text) {
        return QSDom.el('div', { class: 'preview-variables-section__title', text: text });
    }

    /**
     * One labelled parameter row of the Add form or of a component's variables: the field, an
     * asset browse button when `browseCategory` is not null, and a route datalist when
     * `datalistId` is set.
     */
    function _renderParamRow(labelText, inputProps, browseCategory, datalistId) {
        const input = QSDom.el('input', Object.assign({ type: 'text', class: 'admin-input admin-input--sm preview-contextual-form__param-input' }, inputProps));
        if (datalistId) input.setAttribute('list', datalistId);
        const group = QSDom.el('div', { class: 'preview-contextual-form__param-input-group' }, [input]);
        if (browseCategory !== null) {
            group.append(' ', QSDom.el('button', {
                type: 'button', class: 'admin-btn admin-btn--outline preview-asset-browse-btn',
                'data-category': browseCategory, title: QSPanelWords.t('preview.browseAssets')
            }, ['📁']));
        }
        return QSDom.el('div', { class: 'preview-contextual-form__param-row' }, [
            QSDom.el('label', { class: 'preview-contextual-form__param-label', text: labelText + ':' }),
            group,
            datalistId ? QSDom.el('datalist', { id: datalistId }) : null
        ]);
    }

    /** The count of a component's variables, in the Add form's list. */
    function componentVariablesLabel(count) {
        return QSPanelWords.count('preview.componentVariablesCount', count);
    }
    
    /**
     * Parse a CSS styles string into an object.
     * Delegates to PreviewState.utils.parseStylesString (canonical).
     */
    function parseStylesString(stylesString) {
        return PreviewState.utils.parseStylesString(stylesString);
    }
    
    // Simple toast helper
    function showToast(message, type) {
        if (window.QuickSiteAdmin && QuickSiteAdmin.showToast) {
            QuickSiteAdmin.showToast(message, type);
        } else {
            console.log('[Toast]', type, message);
        }
    }
    
    // ==================== Style Panel (removed — editing via Selectors tab) ====================
    
    // ==================== JS Interactions Panel ====================
    // Functionality has been extracted to preview-js-interactions.js
    // See: /admin/assets/js/pages/preview/preview-js-interactions.js
    // Public API: PreviewJsInteractions.show(), .hide(), .reload()
    
    /**
     * Show JS interactions panel (delegates to module)
     */
    function showJsPanel(data) {
        if (window.PreviewJsInteractions) {
            PreviewJsInteractions.show(data);
        }
    }
    
    /**
     * Hide JS interactions panel (delegates to module)
     */
    function hideJsPanel() {
        if (window.PreviewJsInteractions) {
            PreviewJsInteractions.hide();
        }
    }
    
    /**
     * Initialize Drag module with callbacks from preview.js
     */
    function initPreviewDrag() {
        if (window.PreviewDrag) {
            PreviewDrag.setShowContextualInfo(showContextualInfo);
            PreviewDrag.setUpdateGlobalElementInfo(updateGlobalElementInfo);
        }
    }

    /**
     * Initialize JS Interactions module with callbacks
     */
    function initJsInteractions() {
        if (window.PreviewJsInteractions) {
            PreviewJsInteractions.setShowToast(showToast);
            PreviewJsInteractions.setSendToIframe(sendToIframe);
            PreviewJsInteractions.setReloadPreview(reloadPreview);
            // Always false. The real flag lives in PreviewSelectorBrowser, and
            // the consumer only uses this to decide whether to call
            // loadSelectorsData(), which guards on its own copy — so reporting
            // false costs one no-op call and nothing else. Left reporting false
            // rather than wired to PreviewSelectorBrowser.isLoaded(), because
            // wiring it would change behaviour and this is a deletion.
            PreviewJsInteractions.setGetSelectorsLoaded(() => false);
            PreviewJsInteractions.setLoadSelectorsData(loadSelectorsData);
            PreviewJsInteractions.setGetCategorizedSelectors(() => {
                if (window.PreviewSelectorBrowser) {
                    return PreviewSelectorBrowser.getCategorizedSelectors();
                }
                return { tags: [], classes: [], ids: [], attributes: [], media: {} };
            });
            PreviewJsInteractions.setGetPageStructureClasses(() => pageStructureClasses);
        }
    }
    
    // Initialize Drag module
    initPreviewDrag();

    // Initialize JS Interactions module
    initJsInteractions();
    
    // Initialize Transition Editor module
    initTransitionEditor();
    
    // ==================== Event Handlers ====================
    
    function initIframeAndControls() {
        function handleIframeLoad() {
            clearTimeout(loadingTimeout);
            hideLoading();
            // A new document loaded — previous overlay is gone regardless of flag
            overlayInjected = false;
            injectOverlay();
            // Retry injection in case document wasn't fully ready
            setTimeout(function() { if (!overlayInjected) injectOverlay(); }, 50);
            setTimeout(function() { if (!overlayInjected) injectOverlay(); }, 200);
        }
        iframe.addEventListener('load', handleIframeLoad);
        // First (uncached) page load: this big script parses slower than the iframe's
        // page, so the iframe's 'load' event may have ALREADY fired before the listener
        // above was attached — leaving hideLoading()/injectOverlay() un-run and the
        // loading overlay stuck over a "blank" iframe until a manual refresh. If the
        // iframe document is already complete, run the handler now. (Same-origin; a
        // cross-origin access throws and we fall back to waiting for the event.)
        // ⚠ about:blank reports readyState 'complete' straight away, so
        // readyState alone is not evidence the real page is there. Injecting
        // into about:blank makes the overlay script run in a document whose
        // location.origin is the string "null", and its opening
        // postMessage(..., ALLOWED_ORIGIN) then throws
        // "An invalid or illegal string was specified" — which also skips the
        // rest of that script, including scanEmptyElements(). Same guard as the
        // sibling injection attempt at the end of this file.
        try {
            var _idoc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
            var _iloc = (iframe.contentWindow && iframe.contentWindow.location && iframe.contentWindow.location.href) || '';
            if (_idoc && _idoc.readyState === 'complete' && _iloc && _iloc !== 'about:blank') {
                handleIframeLoad();
            }
        } catch (e) { /* cross-origin or not ready — the load event will handle it */ }
        
        targetSelect.addEventListener('change', function() {
            // Parse unified dropdown value: "type:name" (e.g., "page:home" or "component:feature-item")
            const value = this.value;
            const colonIdx = value.indexOf(':');
            if (colonIdx === -1) {
                // Fallback for legacy format - treat as page
                navigateTo('page', value);
                return;
            }
            const type = value.substring(0, colonIdx);
            const name = value.substring(colonIdx + 1);
            navigateTo(type, name);
        });
        
        if (langSelect) {
            langSelect.addEventListener('change', function() {
                setEditingLanguage(langSelect.value, 'toolbar');
            });
        }

        if (pageTitleInput) {
            // Enter saves by leaving the field; Escape puts the saved title back, then leaves it
            pageTitleInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    pageTitleInput.blur();
                } else if (e.key === 'Escape') {
                    pageTitleInput.value = pageTitleSaved;
                    pageTitleInput.blur();
                }
            });
            pageTitleInput.addEventListener('blur', savePageTitle);
        }

        if (pageDescriptionInput) {
            // As the title: Enter saves by leaving the field, Escape puts the saved description back, then leaves it
            pageDescriptionInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    pageDescriptionInput.blur();
                } else if (e.key === 'Escape') {
                    pageDescriptionInput.value = pageDescriptionSaved;
                    syncPageDescriptionCut();
                    pageDescriptionInput.blur();
                }
            });
            pageDescriptionInput.addEventListener('input', syncPageDescriptionCut);
            pageDescriptionInput.addEventListener('blur', savePageDescription);
            if (pageDescriptionExpand) pageDescriptionExpand.addEventListener('click', openPageDescriptionDialog);
        }
        
        reloadBtn.addEventListener('click', reloadPreview);

        // The button beside Reload: the rows and the panel away, and back as they were. Not stored: a
        // page opened anew has the editor's rows. Where the column scrolls (a tablet, a phone), it comes
        // back where it was: hiding the rows shortens it, and the browser drops its scroll.
        const rowsToggle = document.getElementById('preview-rows-toggle');
        const previewPage = document.getElementById('preview-page');
        if (rowsToggle && previewPage) {
            let rowsScrollTop = 0;
            rowsToggle.addEventListener('click', function() {
                if (!previewPage.classList.contains('preview-page--rows-hidden')) rowsScrollTop = previewPage.scrollTop;
                const hidden = previewPage.classList.toggle('preview-page--rows-hidden');
                if (!hidden) previewPage.scrollTop = rowsScrollTop;
                this.setAttribute('aria-pressed', String(hidden));
                this.title = QSPanelWords.t(hidden ? 'preview.rowsShow' : 'preview.rowsHide');
            });
        }

        deviceBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                setDevice(this.dataset.device);
            });
        });
        initFrame();
        
        modeBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Where the panel folds away, the tool in use opens and closes its panel and keeps what it holds.
                if (this.dataset.mode === currentMode && panelFolds()) {
                    togglePanel();
                    return;
                }
                setMode(this.dataset.mode);
            });
        });
        
        if (panelToggle) {
            panelToggle.addEventListener('click', togglePanel);
        }
        
        // Drag tool button events are handled by PreviewDrag module (preview-drag.js)
    }
    
    initIframeAndControls();
    
    // ==================== Keyboard Shortcuts ====================
    
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Ignore if typing in an input/textarea
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
                return;
            }
            
            // Drag mode: Ctrl+Z / Ctrl+Y for undo/redo — handled by PreviewDrag module
            if (currentMode === 'drag' && window.PreviewDrag) {
                if (PreviewDrag.handleKeydown(e)) return;
            }
            
            // Arrow keys - Navigate selection (select mode and JS mode)
            if ((currentMode === 'select' || currentMode === 'js') && selectedStruct && selectedNode != null) {
                if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    if (window.PreviewNavigation && PreviewNavigation.handleArrowKey(e.key)) {
                        e.preventDefault();
                        return;
                    }
                }
            }
            
            // Escape - Clear selection and hide info
            if (e.key === 'Escape') {
                // Clear selection if we have one
                if (selectedStruct && selectedNode != null) {
                    hideNodePanel();
                    if (iframe.contentWindow) {
                        iframe.contentWindow.postMessage({ action: 'clearSelection' }, window.location.origin);
                    }
                }
                return;
            }
            
            // Delete or Backspace - Delete selected node
            if (e.key === 'Delete' || (e.key === 'Backspace' && e.metaKey)) {
                // Only if we have a selected node, and only where the Delete button is offered (the role)
                if (selectedStruct && selectedNode != null && controlOffered(ctxNodeDelete)) {
                    e.preventDefault();
                    deleteSelectedNode();
                }
                return;
            }
        });
    }
    
    initKeyboardShortcuts();
    
    // ==================== Text Mode: Delete Text-Only Node ====================
    
    // Track current text-only selection
    let textOnlySelection = null;
    
    function updateTextModeInfo(data) {
        if (data && data.textOnly) {
            textOnlySelection = data;
            if (ctxTextKey) ctxTextKey.textContent = (data.textKeys && data.textKeys[0]) || '-';
            if (ctxTextDefault) ctxTextDefault.style.display = 'none';
            if (ctxTextInfo) ctxTextInfo.style.display = '';
        } else {
            resetTextModeInfo();
        }
    }
    
    function resetTextModeInfo() {
        textOnlySelection = null;
        if (ctxTextDefault) ctxTextDefault.style.display = '';
        if (ctxTextInfo) ctxTextInfo.style.display = 'none';
    }

    /**
     * Fire after every successful structure mutation. The selector
     * cache in preview-style-selectors.js listens and marks itself
     * stale so the next picker open re-scans the iframe DOM for
     * newly-added ids and classes (lazy — avoids re-fetching on
     * every rapid mutation).
     */
    function broadcastStructureChanged() {
        window.dispatchEvent(new CustomEvent('qs:editor:structure-changed'));
        // An embed added or removed shows or hides the embed warning.
        updateIframeWarning(currentEditType, currentEditName);
    }

    async function deleteTextOnlyNode() {
        if (!textOnlySelection) return;
        
        const { struct, node, textKeys } = textOnlySelection;
        if (!struct || node == null) return;
        
        const asked = await QuickSiteUtils.confirm('', {
            title: QSPanelWords.t('preview.confirmDeleteTextNode'),
            type: 'danger',
            confirmText: QSPanelWords.t('common.delete')
        });
        if (!asked) return;
        
        const structInfo = parseStruct(struct);
        if (!structInfo || !structInfo.type) {
            showToast(QSPanelWords.t('preview.errorDetail', { error: QSPanelWords.t('preview.invalidStructureType') }), 'error');
            return;
        }
        
        const keepKeys = ctxTextKeepKeys ? ctxTextKeepKeys.checked : false;
        
        showToast(QSPanelWords.t('common.loading'), 'info');
        
        try {
            const params = {
                type: structInfo.type,
                nodeId: node
            };
            if (structInfo.name) params.name = structInfo.name;
            if (keepKeys) params.keepTranslationKeys = true;
            
            console.log('[Preview] Deleting text-only node:', params);
            const result = await QuickSiteAdmin.apiRequest('deleteNode', 'DELETE', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || QSPanelWords.t('preview.textNodeDeleteFailed'));
            }
            
            showToast(QSPanelWords.t('preview.textNodeDeleted'), 'success');

            // Live DOM update — remove the node from iframe
            sendToIframe('removeNode', { struct, nodeId: node });

            resetTextModeInfo();
            broadcastStructureChanged();
        } catch (error) {
            console.error('[Preview] Text delete error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
        }
    }
    
    if (ctxTextDelete) {
        ctxTextDelete.addEventListener('click', deleteTextOnlyNode);
    }
    
    // Delete the selected node: the contextual area's Delete button, and the Delete key
    async function deleteSelectedNode() {
        if (selectedStruct == null || selectedNode == null) return;
        if (selectedNode === '') {
            showToast(QSPanelWords.t('preview.cannotDeleteRoot'), 'warning');
            return;
        }
        
        // Smart textKey check: the last child of a component with a textKey says so, in the delete's one question
        let lastTextWarning = '';
        if (currentEditType === 'component') {
            try {
                const structInfo = parseStruct(selectedStruct);
                if (structInfo) {
                    const urlParams = [structInfo.type];
                    if (structInfo.name) urlParams.push(structInfo.name);
                    const resp = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
                    if (resp.ok && resp.data?.data?.structure) {
                        const structure = resp.data.data.structure;
                        const children = structure.children || [];
                        
                        // Count children that contain textKey (recursively)
                        function hasTextKey(node) {
                            if (!node) return false;
                            if (node.textKey) return true;
                            if (node.children && Array.isArray(node.children)) {
                                return node.children.some(c => hasTextKey(c));
                            }
                            return false;
                        }
                        
                        const childrenWithText = children.filter(c => hasTextKey(c));
                        
                        // Find which child index is being deleted
                        const deletingIndex = parseInt(selectedNode.split('.')[0], 10);
                        const deletingNode = children[deletingIndex];
                        
                        if (deletingNode && hasTextKey(deletingNode) && childrenWithText.length <= 1) {
                            lastTextWarning = QSPanelWords.t('preview.deleteLastTextWarning');
                        }
                    }
                }
            } catch (e) {
                console.warn('[Preview] Could not check textKey before delete:', e);
            }
        }
        
        // Confirm deletion
        const asked = await QuickSiteUtils.confirm(lastTextWarning, {
            title: QSPanelWords.t('preview.confirmDeleteNode'),
            type: 'danger',
            confirmText: QSPanelWords.t('common.delete')
        });
        if (!asked) return;
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            showToast(QSPanelWords.t('preview.errorDetail', { error: QSPanelWords.t('preview.invalidStructureType') }), 'error');
            return;
        }
        
        // Save struct and node BEFORE hiding panel (which clears them)
        const structToDelete = selectedStruct;
        const nodeToDelete = selectedNode;
        
        showToast(QSPanelWords.t('common.loading'), 'info');
        
        try {
            const params = {
                type: structInfo.type,
                nodeId: nodeToDelete
            };
            if (structInfo.name) {
                params.name = structInfo.name;
            }
            
            console.log('[Preview] Deleting node:', params);
            const result = await QuickSiteAdmin.apiRequest('deleteNode', 'DELETE', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || QSPanelWords.t('preview.nodeDeleteFailed'));
            }
            
            // Hide the node panel
            hideNodePanel();
            
            showToast(QSPanelWords.t('preview.nodeDeleted'), 'success');
            console.log('[Preview] Node deleted successfully');

            // Live DOM update - remove node and reindex siblings
            sendToIframe('removeNode', {
                struct: structToDelete,
                nodeId: nodeToDelete
            });
            broadcastStructureChanged();

        } catch (error) {
            console.error('[Preview] Delete error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
        }
    }
    
    // ==================== Duplicate Node ====================
    
    async function duplicateSelectedNode() {
        if (selectedStruct == null || selectedNode == null) {
            showToast(QSPanelWords.t('preview.selectNodeFirst'), 'warning');
            return;
        }
        if (selectedNode === '' && currentEditType !== 'component') {
            showToast(QSPanelWords.t('preview.cannotDuplicateRoot'), 'warning');
            return;
        }
        
        try {
            // Get the structure type and name
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                throw new Error(QSPanelWords.t('preview.invalidStructureType'));
            }
            
            showToast(QSPanelWords.t('common.loading'), 'info');
            
            // Use the duplicateNode API which handles:
            // - Deep clones the node
            // - Generates new translation keys for all textKey/alt/etc
            // - Copies translation values
            // - Inserts after the original
            const params = {
                type: structInfo.type,
                nodeId: selectedNode === '' ? 'root' : selectedNode,
                copyTranslations: true
            };
            if (structInfo.name) params.name = structInfo.name;
            
            const result = await QuickSiteAdmin.apiRequest('duplicateNode', 'POST', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || QSPanelWords.t('preview.nodeDuplicateFailed'));
            }
            
            const keysCreated = result.data?.data?.translationKeysMapped || 0;
            const newNodeId = result.data?.data?.newNodeId;
            const msg = keysCreated > 0
                ? QSPanelWords.count('preview.nodeDuplicatedKeys', keysCreated)
                : QSPanelWords.t('preview.nodeDuplicated');
            
            showToast(msg, 'success');
            
            // Live DOM update - use server-rendered HTML to show correct translations
            const renderedHtml = result.data?.data?.html;
            if (newNodeId && selectedNode !== '' && renderedHtml) {
                sendToIframe('duplicateNode', {
                    struct: selectedStruct,
                    sourceNodeId: selectedNode,
                    newNodeId: newNodeId,
                    html: renderedHtml
                });
            } else {
                // Root duplication or no rendered HTML - full reload needed
                reloadPreview();
            }
            broadcastStructureChanged();

        } catch (error) {
            console.error('[Preview] Duplicate error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
        }
    }
    
    if (ctxNodeDuplicate) {
        ctxNodeDuplicate.addEventListener('click', duplicateSelectedNode);
    }
    
    // ==================== Contextual Area Event Listeners ====================
    
    function initContextualBindings() {
        // Contextual area action buttons (wire to same handlers as floating panel)
        if (ctxNodeAdd) {
            ctxNodeAdd.addEventListener('click', function() {
                if (selectedStruct == null || selectedNode == null) {
                    showToast(QSPanelWords.t('preview.selectNodeFirst'), 'warning');
                    return;
                }
                setMode('add');
                showSidebarAddForm();
            });
        }

        // Edit Params button — opens the Add Element form in 'edit' mode,
        // pre-populated from the selected node's saved params. Reuses the
        // Mandatory / Class / Advanced sections (the data-attr picker
        // auto-attaches to pre-populated custom rows too).
        if (ctxNodeEditParams) {
            ctxNodeEditParams.addEventListener('click', function() {
                showSidebarEditParamsForm();
            });
        }

        // Back to Select button (from Add mode). In Edit mode this is also
        // a "discard" path — prompt if the form has unsaved changes.
        if (addBackToSelect) {
            addBackToSelect.addEventListener('click', async function() {
                if (_isEditModeDirty()) {
                    const msg = QSPanelWords.t('preview.discardUnsavedParams');
                    if (!(await QuickSiteUtils.confirm(msg, { type: 'danger', confirmText: QSPanelWords.t('preview.discard') }))) return;
                }
                setMode('select');
            });
        }
        
        // Tools show names checkbox: the tools and the Select and Text panels' actions show each name beside
        // its icon, or the icon alone (an action's title stays its tooltip, its aria-label its name).
        if (toolsShowNames && toolsRow) {
            const applyShowNames = function(showNames) {
                toolsRow.classList.toggle('preview-tools--icons-only', !showNames);
                actionRows.forEach(function(row) {
                    row.classList.toggle('preview-contextual-info__actions--icons-only', !showNames);
                });
            };
            // Restore state from localStorage (default: show names = true). A browser that refuses the
            // site's storage shows the names and keeps no change of them.
            let showNames = true;
            try { showNames = localStorage.getItem(QuickSiteStorageKeys.toolsShowNames) !== 'false'; } catch (e) { showNames = true; }
            toolsShowNames.checked = showNames;
            applyShowNames(showNames);
            
            toolsShowNames.addEventListener('change', function() {
                applyShowNames(this.checked);
                try { localStorage.setItem(QuickSiteStorageKeys.toolsShowNames, this.checked); } catch (e) { /* not kept */ }
            });
        }
        
        if (ctxNodeDelete) {
            ctxNodeDelete.addEventListener('click', deleteSelectedNode);
        }
        
        // Save as Snippet
        if (ctxNodeSaveSnippet) {
            ctxNodeSaveSnippet.addEventListener('click', showSaveSnippetForm);
        }
        if (saveSnippetClose) {
            saveSnippetClose.addEventListener('click', hideSaveSnippetForm);
        }
        if (saveSnippetCancel) {
            saveSnippetCancel.addEventListener('click', hideSaveSnippetForm);
        }
        
        // Save as Component
        if (ctxNodeSaveComponent) {
            ctxNodeSaveComponent.addEventListener('click', showSaveComponentForm);
        }
        if (saveComponentClose) {
            saveComponentClose.addEventListener('click', hideSaveComponentForm);
        }
        if (saveComponentCancel) {
            saveComponentCancel.addEventListener('click', hideSaveComponentForm);
        }
        if (saveComponentSubmit) {
            saveComponentSubmit.addEventListener('click', submitSaveComponent);
        }
        
        // Variables panel button
        if (ctxNodeVariables) {
            ctxNodeVariables.addEventListener('click', showVariablesPanel);
        }
        if (variablesPanelClose) {
            variablesPanelClose.addEventListener('click', hideVariablesPanel);
        }
        
        // Enums panel button
        if (ctxNodeEnums) {
            ctxNodeEnums.addEventListener('click', showEnumsPanel);
        }
        if (enumsPanelClose) {
            enumsPanelClose.addEventListener('click', hideEnumsPanel);
        }
        if (enumsPanelAddBtn) {
            enumsPanelAddBtn.addEventListener('click', addNewEnum);
        }
        
        // Emulation panel button
        if (ctxNodeEmulation) {
            ctxNodeEmulation.addEventListener('click', showEmulationPanel);
        }
        if (emulationPanelClose) {
            emulationPanelClose.addEventListener('click', hideEmulationPanel);
        }
        if (emulationApplyBtn) {
            emulationApplyBtn.addEventListener('click', applyEmulation);
        }
        if (emulationResetBtn) {
            emulationResetBtn.addEventListener('click', resetEmulation);
        }
        // Live-data toggle. Clicking flips the
        // useLive flag in localStorage and reloads the preview with
        // _live=1 so the real resolver fires server-side instead of the
        // emulated `resolved` overrides being used. Page-edit only —
        // showEmulationPanel shows/hides the button per edit type.
        if (emulationLiveBtn) {
            emulationLiveBtn.addEventListener('click', toggleLiveData);
        }
        
        // Auto-generate ID from name
        if (saveSnippetName) {
            saveSnippetName.addEventListener('input', function() {
                if (saveSnippetId) {
                    // Auto-generate slug from name
                    const slug = this.value
                        .toLowerCase()
                        .trim()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-');
                    saveSnippetId.value = slug;
                }
            });
        }
        
        if (saveSnippetSubmit) {
            saveSnippetSubmit.addEventListener('click', submitSaveSnippet);
        }
        if (deleteSnippetBtn) {
            deleteSnippetBtn.addEventListener('click', deleteSelectedSnippet);
        }
        
        // Global element info bar toggle
        if (globalElementInfoToggle) {
            globalElementInfoToggle.addEventListener('click', function() {
                if (globalElementInfo) {
                    globalElementInfo.classList.toggle('preview-element-info--expanded');
                }
            });
        }
    }
    
    initContextualBindings();
    
    // ==================== Module Initializations ====================
    
    initStyleTabs();
    initStyleSource();
    initSelectorBrowser();


    // ==================== Sidebar Add/Edit Forms ====================
    
    // State for sidebar add form
    let sidebarAddNodeType = 'tag';
    let sidebarAddCustomParamsCount = 0;
    let sidebarAddSelectedComponentData = null;
    // Edit-Params reuses the Add Element form in an "edit" mode. When
    // 'edit', certain sections (type tabs, tag selector, position
    // picker) are hidden, the form is pre-populated from the selected
    // node's saved params, and the Confirm button becomes Save. The
    // snapshot lets Save (editParamsNode) compute the addParams/removeParams diff.
    let contextualAddMode = 'add';            // 'add' | 'edit'
    let contextualAddOriginalParams = null;   // snapshot of params on edit-open
    let contextualAddOriginalTag = null;      // locked tag of the node being edited
    // Selection snapshot — captured at edit-open so saving uses the
    // element the user actually opened, even if the canvas-selection
    // moves while the form is up (race-free Save).
    let contextualAddOriginalStruct = null;
    let contextualAddOriginalNode = null;
    
    // Initialize collapsible sections (read stored state from localStorage)
    function initCollapsibleSections() {
        document.querySelectorAll('.preview-contextual-form__collapsible-header[data-storage-key]').forEach(header => {
            const key = header.dataset.storageKey;
            const section = header.closest('.preview-contextual-form__collapsible');
            if (!section) return;
            
            // Read stored state (default: expanded for position, collapsed for others)
            const defaultCollapsed = key !== 'qs-add-position-expanded';
            let stored = null;
            try { stored = localStorage.getItem(key); } catch (e) { stored = null; }
            const isCollapsed = stored !== null ? stored === '0' : defaultCollapsed;
            section.classList.toggle('collapsed', isCollapsed);
            
            // Toggle on click
            header.addEventListener('click', function() {
                const nowCollapsed = section.classList.toggle('collapsed');
                try { localStorage.setItem(key, nowCollapsed ? '0' : '1'); } catch (e) { /* not kept */ }
            });
        });
    }
    initCollapsibleSections();
    
    // Move tag-selector preview panel into the collapsible preview section
    const tagPreviewEl = document.getElementById('add-tag-preview');
    const previewBody = document.getElementById('add-preview-body');
    if (tagPreviewEl && previewBody) {
        previewBody.appendChild(tagPreviewEl);
    }
    
    // Show sidebar add form with selection context
    /**
     * The positions the Add form offers for the current selection. At the root only "inside"
     * (before / after hidden). Elsewhere "inside" only where the node can hold children — not a
     * text, a component or a void element such as an image (the iframe's answer, the rule the
     * drag follows): the server refuses an insert there. The Text tab offers before / after only
     * beside an inline element (a bold word, a link: the iframe's answer), where the new text
     * continues the line; where neither fits, the form says so and Add is disabled. Run when the
     * form opens, when its tab changes, and again when the selection changes while it is open.
     */
    function _syncAddPositionOptions() {
        const isAtRoot = selectedNode === '' && currentEditType !== 'component';
        const isText = sidebarAddNodeType === 'text';
        const insideOffered = isAtRoot || selectedHoldsChildren;
        const besideOffered = !isAtRoot && (!isText || selectedIsInline);
        if (isAtRoot) {
            setAddPosition('inside');
        } else if (!insideOffered && getAddPosition() === 'inside') {
            setAddPosition('after');
        } else if (!besideOffered && getAddPosition() !== 'inside') {
            setAddPosition('inside');
        }
        const nothingFits = !insideOffered && !besideOffered;
        if (addPositionNone) addPositionNone.style.display = nothingFits ? '' : 'none';
        if (addConfirmBtn) addConfirmBtn.disabled = nothingFits;
        if (!addPositionPicker) return;
        addPositionPicker.querySelectorAll('input[name="add-position"]').forEach(radio => {
            const wrapper = radio.closest('label') || radio.parentElement;
            if (radio.value === 'before' || radio.value === 'after') {
                wrapper.style.display = besideOffered ? '' : 'none';
            } else if (radio.value === 'inside') {
                wrapper.style.display = insideOffered ? '' : 'none';
                // The Text tab's Inside is the element's end: its label and its diagram say so
                const label = wrapper.querySelector('.position-picker__label');
                if (label) label.textContent = isText ? QSPanelWords.t('preview.positionInsideEnd') : QSPanelWords.t('preview.positionInside');
                const box = wrapper.querySelector('.position-picker__new');
                if (box) box.setAttribute('y', isText ? '26' : '10');
            }
        });
    }

    function showSidebarAddForm() {
        if (!contextualAddForm || !contextualAddDefault) return;
        
        // If no selection, show default hint
        if (selectedStruct == null || selectedNode == null) {
            contextualAddDefault.style.display = '';
            contextualAddForm.style.display = 'none';
            return;
        }
        
        // Show form, hide default
        contextualAddDefault.style.display = 'none';
        contextualAddForm.style.display = '';

        // Reset any leftover Edit-Params state (header label, hidden
        // tab-strip + position section). Safe even if we were already
        // in 'add' mode — it's a no-op then.
        _resetContextualAddMode();

        // In simple mode the form is the simple Add (preview-simple-add.js) in place of the five tabs.
        const simple = isSimpleView() && !!window.PreviewSimpleAdd;
        if (addTypeField) addTypeField.style.display = simple ? 'none' : '';
        if (simple) {
            sidebarAddNodeType = 'simple';
        } else {
            // Reset form state — use remembered tab or default to 'tag' (HTML Tag). A browser that refuses the
            // site's storage opens the form on the default tab.
            let rememberedTab = null;
            try { rememberedTab = localStorage.getItem(QuickSiteStorageKeys.addLastTab); } catch (e) { rememberedTab = null; }
            const VALID_TABS = ['tag', 'component', 'snippet', 'complex', 'text'];
            sidebarAddNodeType = VALID_TABS.includes(rememberedTab) ? rememberedTab : 'tag';
        }
        sidebarAddCustomParamsCount = 0;
        sidebarAddSelectedComponentData = null;
        
        // Reset UI elements
        if (addTypeInput) addTypeInput.value = sidebarAddNodeType;
        // Use visual tag selector if available, fallback to direct value set
        if (addTagSelector) {
            addTagSelector.selectTag('div');
        } else if (addTagSelect) {
            addTagSelect.value = 'div';
        }
        // The Text tab starts on Inside, the other tabs on After, as a click on the tab does
        setAddPosition(sidebarAddNodeType === 'text' ? 'inside' : 'after');
        if (classCombobox) classCombobox.reset();
        else if (addClassInput) addClassInput.value = '';
        if (addIdInput) addIdInput.value = '';
        if (addCustomParamsList) addCustomParamsList.replaceChildren();
        if (addComponentSelector) addComponentSelector.reset();
        else if (addComponentSelect) addComponentSelect.value = '';

        _syncAddPositionOptions();

        
        // Update tabs UI
        updateSidebarAddTypeTabs(sidebarAddNodeType);
        updateSidebarAddNodeTypeUI();
        updateSidebarAddMandatoryParams();
        updateSidebarAddTransKeyParams();
        if (window.PreviewSimpleAdd) {
            if (simple) PreviewSimpleAdd.open(); else PreviewSimpleAdd.close();
        }
        
        // Refresh contextual suggestions for new selection context
        if (addTagSelector) addTagSelector.refreshSuggestions();
        
        // Load what the active tab shows, as a click on the tab does
        if (sidebarAddNodeType === 'component') {
            loadSidebarComponentsList();
        } else if (sidebarAddNodeType === 'snippet') {
            loadSidebarSnippetsList();
        } else if (sidebarAddNodeType === 'complex') {
            populateComplexKindPicker();
        } else if (sidebarAddNodeType === 'text') {
            updateAddTextFieldsForType();
        }
    }
    
    // Hide sidebar add form (go back to select)
    function hideSidebarAddForm() {
        if (contextualAddDefault) contextualAddDefault.style.display = '';
        if (contextualAddForm) contextualAddForm.style.display = 'none';
        setMode('select');
    }

    // Save-&-add-another mode flag — set while the "Save & add another"
    // button drives an add, so the success path keeps the form open.
    let addAnotherMode = false;

    /**
     * Finalize a successful add. Normal mode: close the form (return false so
     * the caller runs its own live-insert / reload). Save-&-add-another mode:
     * keep the form open, run the optional per-type field reset, and reload
     * the preview — a plain reload preserves the target selection so repeated
     * adds land at the same anchor — then return true so the caller skips its
     * own refresh.
     */
    function _finalizeAdd(perTypeReset) {
        if (!addAnotherMode) {
            hideSidebarAddForm();
            return false;
        }
        if (typeof perTypeReset === 'function') perTypeReset();
        reloadPreview();
        return true;
    }

    // HTML id validation — CSS-selector-safe subset: start with a letter or
    // underscore, then letters / digits / hyphens / underscores; no spaces.
    function _isValidElementId(v) {
        return /^[A-Za-z_][A-Za-z0-9_-]*$/.test(v);
    }

    // ---------- Edit Params (form-reuse in 'edit' mode) ----------

    /**
     * Restore Add-Element visuals: type-tab strip, position section,
     * default header text + Confirm button label, mode flag. Called at
     * the top of showSidebarAddForm() so a prior Edit-Params open never
     * leaks state into the next Add Element open.
     */
    function _resetContextualAddMode() {
        contextualAddMode = 'add';
        contextualAddOriginalParams = null;
        contextualAddOriginalTag = null;
        contextualAddOriginalStruct = null;
        contextualAddOriginalNode = null;
        if (contextualAddForm) contextualAddForm.classList.remove('preview-contextual-form--edit-mode');
        if (addTypeField) addTypeField.style.display = '';
        if (addPositionSection) addPositionSection.style.display = '';
        // Confirm button reverts to the success-coloured "Add Element" affordance.
        if (addConfirmBtn) {
            addConfirmBtn.classList.remove('admin-btn--primary');
            addConfirmBtn.classList.add('admin-btn--success');
        }
        const defaultTitle = QSPanelWords.t('preview.addElement');
        if (addFormTitleText) addFormTitleText.textContent = defaultTitle;
        if (addConfirmLabel) addConfirmLabel.textContent = defaultTitle;
    }

    /**
     * Open the Add Element form in 'edit' mode, pre-populated from the
     * currently-selected node's saved params. Fetches the structure via
     * getStructure (same path Save as Snippet uses), partitions params
     * into class / mandatory-for-tag / custom, and hands each partition
     * to its existing widget. Custom rows go through _renderCustomParamRow
     * so the data-attr picker auto-attaches.
     *
     * It only pre-populates; Save (editParamsNode) diffs the widgets against
     * the snapshot taken here and calls editNode.
     */
    async function showSidebarEditParamsForm() {
        if (!contextualAddForm || !contextualAddDefault) return;
        if (selectedStruct == null || selectedNode == null) {
            showToast(QSPanelWords.t('preview.selectNodeFirst'), 'warning');
            return;
        }

        // Fetch the node's saved structure. allowRoot=true so editing
        // the page's <main> root works (Save Snippet refuses root, but
        // editing its class/data-* is a legitimate use case).
        let nodeData;
        try {
            const result = await extractSelectedNodeStructure({ allowRoot: true });
            nodeData = result.nodeData;
        } catch (err) {
            showToast(err.message || QSPanelWords.t('preview.elementLoadFailed'), 'error');
            return;
        }

        // List-shape page root (the whole structure is an array) cannot
        // be edited as a single node — the user should pick a child.
        if (Array.isArray(nodeData)) {
            showToast(QSPanelWords.t('preview.editParamsRootList'), 'warning');
            return;
        }
        // Component reference + text nodes: no editable params here.
        if (!nodeData.tag) {
            if (nodeData.component) {
                showToast(QSPanelWords.t('preview.editParamsComponentCall'), 'info');
            } else if (nodeData.textKey !== undefined) {
                showToast(QSPanelWords.t('preview.editParamsTextNode'), 'info');
            } else {
                showToast(QSPanelWords.t('preview.editParamsNone'), 'warning');
            }
            return;
        }

        const tag = nodeData.tag;
        const params = nodeData.params || {};

        // Snapshot for Save's diff calculation + safe Save target.
        // Selection-on-canvas can shift while the form is open; using a
        // live read of selectedStruct/Node at Save-time would write to
        // the wrong node. Snapshots resolve that race.
        contextualAddOriginalParams = JSON.parse(JSON.stringify(params));
        contextualAddOriginalTag = tag;
        contextualAddOriginalStruct = selectedStruct;
        contextualAddOriginalNode = selectedNode;

        // Switch the contextual area to the add-section (where the form
        // lives) and flip our internal mode flag.
        setMode('add');
        contextualAddMode = 'edit';

        // Reveal the form, hide the default hint.
        contextualAddDefault.style.display = 'none';
        contextualAddForm.style.display = '';

        // Form behaves as a Tag form (mandatory + class + advanced),
        // but with type tabs / tag picker / position locked away.
        sidebarAddNodeType = 'tag';
        sidebarAddCustomParamsCount = 0;
        if (addTypeInput) addTypeInput.value = 'tag';

        // Lock the tag selection internally (drives mandatory-params
        // logic) without showing the picker.
        if (addTagSelector) addTagSelector.selectTag(tag);
        else if (addTagSelect) addTagSelect.value = tag;

        // Reset the three target widgets before pre-populating.
        if (classCombobox) classCombobox.reset();
        else if (addClassInput) addClassInput.value = '';
        if (addIdInput) addIdInput.value = '';
        if (addCustomParamsList) addCustomParamsList.replaceChildren();

        // Tear down any prior Complex wizard mount. #add-complex-body is a
        // SIBLING of #add-complex-field in contextual-add.php — hiding only
        // the field below leaves the body's rendered wizard visible inside
        // the form, masking the class chip + custom rows.
        renderComplexWizardBody(null);
        if (addComplexKindSelect) addComplexKindSelect.value = '';

        // Explicit edit-mode visibility — DON'T call
        // updateSidebarAddNodeTypeUI(); it would un-hide things we want
        // hidden (it's wired for Add Element's tab-driven UX).
        if (addTypeField) addTypeField.style.display = 'none';        // tabs
        if (window.PreviewSimpleAdd) PreviewSimpleAdd.close();        // the simple Add's list
        if (addTagField) addTagField.style.display = 'none';          // tag picker
        if (addPositionSection) addPositionSection.style.display = 'none';
        if (addPreviewSection) addPreviewSection.style.display = 'none';
        if (addTextField) addTextField.style.display = 'none';
        if (addSnippetField) addSnippetField.style.display = 'none';
        if (addComponentField) addComponentField.style.display = 'none';
        if (addComplexField) addComplexField.style.display = 'none';
        if (addComponentVars) addComponentVars.style.display = 'none';
        if (addClassField) addClassField.style.display = 'block';
        if (addIdField) addIdField.style.display = 'none';
        if (addConfirmAnotherBtn) addConfirmAnotherBtn.style.display = 'none';
        if (addAdvancedSection) addAdvancedSection.style.display = '';

        // Build mandatory-param widgets for this tag (the helper reads
        // addTagSelect.value, which we just set). Must run BEFORE we
        // fill in the mandatory values, since the inputs don't exist
        // until this builds them. Same for the translation-key pickers,
        // which _prepopulateEditForm fills from the saved params.
        updateSidebarAddMandatoryParams();
        updateSidebarAddTransKeyParams();

        // Swap header + bottom-button text.
        if (addFormTitleText) addFormTitleText.textContent = QSPanelWords.t('preview.editParamsHeaderTag', { tag: tag });
        if (addConfirmLabel) addConfirmLabel.textContent = QSPanelWords.t('common.save');

        // Edit-mode visual cues: form gets a class for the header accent
        // strip (admin.css), and the Save button switches from
        // admin-btn--success (creation green) to admin-btn--primary (a save,
        // not a creation).
        if (contextualAddForm) contextualAddForm.classList.add('preview-contextual-form--edit-mode');
        if (addConfirmBtn) {
            addConfirmBtn.classList.remove('admin-btn--success');
            addConfirmBtn.classList.add('admin-btn--primary');
            // Save works whatever the Add form's Text tab last offered
            addConfirmBtn.disabled = false;
        }

        // Pre-populate from the saved params.
        _prepopulateEditForm(params, tag);
    }

    /**
     * Walk the saved params dict and route each entry to its widget:
     *  - 'class' tokens -> classCombobox.addClass() each
     *  - tag's mandatory params -> the matching #add-mandatory-<name> input
     *  - tag's translation-key params -> the matching key picker
     *  - everything else -> a custom row (with picker auto-attached)
     */
    function _prepopulateEditForm(params, tag) {
        const mandatoryNames = (TAG_INFO && TAG_INFO.MANDATORY_PARAMS && TAG_INFO.MANDATORY_PARAMS[tag]) || [];
        // A group the tag needs one of (a <source>'s src or srcset) has its rows beside the mandatory ones
        const mandatorySet = new Set(mandatoryNames.concat(...((TAG_INFO && TAG_INFO.MANDATORY_ONE_OF && TAG_INFO.MANDATORY_ONE_OF[tag]) || [])));
        // For <input> the wizard uses 'type' + always-rendered 'name' as
        // mandatory, even though MANDATORY_PARAMS['input'] only lists 'type'.
        if (tag === 'input') mandatorySet.add('name');
        // A group's choice follows what the node holds; then a field opens on it and takes its parameters (a srcset, and
        // sizes / type where it shows them)
        _openOneOfChoices(params);
        const takenByFields = new Set(_openParamFields(params, tag));

        Object.keys(params).forEach(key => {
            const value = params[key];
            if (takenByFields.has(key)) return;
            if (transKeyPickers[key]) {
                // alt / title — the key picker owns this param in BOTH flows,
                // so editing an existing value uses the same widget that
                // created it.
                transKeyPickers[key].setValue(String(value));
            } else if (key === 'class') {
                if (classCombobox && typeof value === 'string') {
                    value.split(/\s+/).forEach(cls => {
                        const trimmed = cls.trim();
                        if (trimmed) classCombobox.addClass(trimmed);
                    });
                } else if (addClassInput) {
                    addClassInput.value = String(value || '');
                }
            } else if (mandatorySet.has(key)) {
                const input = document.getElementById('add-mandatory-' + key);
                if (input) {
                    input.value = String(value);
                    // For <input type=...>, the wizard re-renders when type
                    // changes — dispatch change so dependent widgets refresh.
                    if (tag === 'input' && key === 'type') {
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else {
                    // Mandatory widget not present (e.g., unknown tag config).
                    // Fall through to custom row so the user doesn't lose data.
                    sidebarAddCustomParamsCount++;
                    const row = _renderCustomParamRow({
                        key: key,
                        value: String(value),
                        index: sidebarAddCustomParamsCount
                    });
                    if (addCustomParamsList) addCustomParamsList.appendChild(row);
                }
            } else {
                sidebarAddCustomParamsCount++;
                const row = _renderCustomParamRow({
                    key: key,
                    value: String(value),
                    index: sidebarAddCustomParamsCount
                });
                if (addCustomParamsList) addCustomParamsList.appendChild(row);
                // Pre-fill via the picker's dedicated edit-mode entry
                // point: installs the smart widget for known data-*
                // entries (store/field cascader, enum select, storage
                // composer) but does NOT open the dropdown. Unknown
                // keys silently no-op — row stays as plain text inputs.
                const keyInput = row.querySelector('.preview-contextual-form__param-key');
                const valueInput = row.querySelector('.preview-contextual-form__param-value');
                if (keyInput && window.QSComplexWizard && window.QSComplexWizard.prefillDataAttrRow) {
                    window.QSComplexWizard.prefillDataAttrRow(keyInput, valueInput);
                }
            }
        });
    }

    /**
     * Read the edit-form widgets back into a params dict, with the same
     * semantics addTagNode uses to collect Add-Element params:
     *  - class combobox flushed + read into `class` (omitted if empty)
     *  - mandatory widgets read by id pattern (omitted if empty)
     *  - custom rows: empty key OR empty value → row is ignored
     *  - the fields (a srcset's) answer their own parameters, read last
     *
     * Empty-value === "I don't want this attr set" — to actually keep an
     * attr with empty string the user has to type a single space (HTML
     * boolean attrs like `disabled` accept any non-empty value, and JSON
     * trimming makes `value=" "` become real empty anyway, so this is a
     * known limitation matching Add Element's behaviour).
     */
    function _collectEditFormParams() {
        const params = {};

        // Class combobox — commit any uncommitted text first
        if (classCombobox) classCombobox.flush();
        const classes = (addClassInput?.value || '').trim();
        if (classes) params.class = classes;

        // Mandatory params (per-tag widgets keyed by id "add-mandatory-<name>"; a field's own inputs left out)
        _mandatoryParamInputs().forEach(field => {
            const name = field.id.replace('add-mandatory-', '');
            const value = (field.value || '').trim();
            if (value) params[name] = value;
        });

        // Translation-key params — same widgets, same semantics, in both flows.
        Object.assign(params, _collectTransKeyParams());

        // Custom params rows — same empty-row rule as Add Element
        const customRows = addCustomParamsList?.querySelectorAll('.preview-contextual-form__param-row--custom') || [];
        customRows.forEach(row => {
            const key   = row.querySelector('.preview-contextual-form__param-key')?.value?.trim();
            const value = row.querySelector('.preview-contextual-form__param-value')?.value?.trim();
            if (key && value) params[key] = value;
        });

        // The fields: what the node held, byte for byte, while nothing in them changed
        Object.assign(params, _paramFieldParams());

        return params;
    }

    /**
     * Compute the editNode diff between the snapshot taken at
     * Edit-Params-open and the form's current state.
     *  - removeParams: keys in original missing from current
     *  - addParams:    keys new in current OR with a different value
     * Stringified comparison so 1 vs "1" doesn't read as a change.
     */
    function _diffParams(original, current) {
        const addParams = {};
        const removeParams = [];

        Object.keys(current).forEach(key => {
            if (!(key in original) || String(original[key]) !== String(current[key])) {
                addParams[key] = current[key];
            }
        });
        Object.keys(original).forEach(key => {
            if (!(key in current)) removeParams.push(key);
        });

        return { addParams, removeParams };
    }

    /**
     * "Are we in edit mode with unsaved changes?" — used by Cancel + Back
     * handlers to prompt before discarding. Returns false in Add mode (Add
     * has no unsaved-changes prompt, and this one does not extend to
     * it).
     */
    function _isEditModeDirty() {
        if (contextualAddMode !== 'edit') return false;
        if (!contextualAddOriginalParams) return false;
        const current = _collectEditFormParams();
        const { addParams, removeParams } = _diffParams(contextualAddOriginalParams, current);
        return Object.keys(addParams).length > 0 || removeParams.length > 0;
    }

    /**
     * Save the Edit-Params form: diff the current widgets against the
     * snapshot, POST to /editNode, refresh the iframe. Uses snapshotted
     * struct + nodeId (not live selectedStruct/Node) so a canvas
     * reselection mid-edit can't cross-write to a different node.
     *
     * Server validations we propagate as toasts:
     *  - Reserved data-qs-* attributes
     *  - Cannot remove a mandatory param
     *  - Missing mandatory param after the diff applies
     * On any 4xx/5xx the form stays open so the user can correct.
     */
    async function editParamsNode() {
        // Edit-mode state sanity
        if (contextualAddOriginalParams == null
            || contextualAddOriginalStruct == null
            || contextualAddOriginalNode == null) {
            throw new Error(QSPanelWords.t('preview.editParamsStateLost'));
        }
        if (contextualAddOriginalNode === '') {
            throw new Error(QSPanelWords.t('preview.cannotEditRoot'));
        }

        const structInfo = parseStruct(contextualAddOriginalStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }

        // A group with nothing chosen, or a field's problem (a duplicate, a width missing…), is never saved: said there and here
        const fieldProblem = _paramFieldProblem();
        if (fieldProblem) throw new Error(fieldProblem);

        const currentParams = _collectEditFormParams();
        const { addParams, removeParams } = _diffParams(contextualAddOriginalParams, currentParams);

        // No-op short-circuit: just close without hitting the server.
        if (Object.keys(addParams).length === 0 && removeParams.length === 0) {
            showToast(QSPanelWords.t('preview.noParamChanges'), 'info');
            hideSidebarAddForm();
            return;
        }

        const body = {
            type: structInfo.type,
            nodeId: String(contextualAddOriginalNode),
            addParams: addParams,
            removeParams: removeParams
        };
        if (structInfo.name) body.name = structInfo.name;

        const response = await QuickSiteAdmin.apiRequest('editNode', 'POST', body);
        if (!response.ok) {
            const msg = (response.data && response.data.message)
                || QSPanelWords.t('preview.editParamsError');
            throw new Error(msg);
        }

        const data = response.data?.data || {};

        // Cache snapshot targets BEFORE hideSidebarAddForm clears them
        // (hideSidebarAddForm doesn't currently call _reset, but be defensive).
        const targetStruct = contextualAddOriginalStruct;
        const targetNode = contextualAddOriginalNode;

        showToast(QSPanelWords.t('preview.paramsSaved'), 'success');
        hideSidebarAddForm();

        // Live DOM update if the server returned the re-rendered HTML;
        // otherwise full reload as fallback. Mirrors addTagNode's pattern.
        if (data.html) {
            sendToIframe('updateNode', {
                struct: targetStruct,
                nodeId: targetNode,
                html: data.html
            });
        } else {
            if (typeof reloadPreview === 'function') reloadPreview();
        }
        broadcastStructureChanged();
    }

    // Update add form tabs UI
    function updateSidebarAddTypeTabs(type) {
        if (!addTypeTabs) return;
        const tabs = addTypeTabs.querySelectorAll('.preview-contextual-form__tab');
        tabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.type === type);
        });
    }
    
    // Update add form UI based on selected type
    function updateSidebarAddNodeTypeUI() {
        const isTag = sidebarAddNodeType === 'tag';
        const isComponent = sidebarAddNodeType === 'component';
        const isSnippet = sidebarAddNodeType === 'snippet';
        const isComplex = sidebarAddNodeType === 'complex';
        const isText = sidebarAddNodeType === 'text';

        if (addTagField) addTagField.style.display = isTag ? 'block' : 'none';
        if (addComponentField) addComponentField.style.display = isComponent ? 'block' : 'none';
        if (addSnippetField) addSnippetField.style.display = isSnippet ? 'block' : 'none';
        if (addClassField) addClassField.style.display = isTag ? 'block' : 'none';
        if (addIdField) addIdField.style.display = isTag ? 'block' : 'none';
        // Save & add another applies to tag / component / complex only.
        if (addConfirmAnotherBtn) addConfirmAnotherBtn.style.display = (isTag || isComponent || isComplex) ? '' : 'none';
        // Text node tab — value input + Raw/Translation-key toggle.
        if (addTextField) addTextField.style.display = isText ? 'block' : 'none';
        // The "Advanced — custom params" section is shared between the
        // HTML Tag and the Component tabs. For tags, the entries become
        // the tag's attributes. For components, they become call-site
        // `params` on the {component, data, params} node, merged into
        // the rendered template root by JsonToHtmlRenderer (see
        // docs/ADMIN_PANEL.md §"Component list binding" — the hidden
        // template uses this to mark itself with data-list-template /
        // display:none).
        if (addAdvancedSection) addAdvancedSection.style.display = (isTag || isComponent) ? '' : 'none';
        if (addPreviewSection) addPreviewSection.style.display = isTag ? '' : 'none';
        // Complex tab — show kind picker; body only shows once a kind is picked.
        if (addComplexField) addComplexField.style.display = isComplex ? 'block' : 'none';
        if (addComplexBody) {
            const hasController = isComplex && addComplexController !== null;
            addComplexBody.style.display = hasController ? '' : 'none';
        }

        // (Removed) The "top Add Element" quick-add button is gone — the
        // bottom Cancel/Add pair is the single submit affordance.

        // Update mandatory params visibility
        updateSidebarAddMandatoryParams();
        updateSidebarAddTransKeyParams();

        // Component vars
        if (addComponentVars) addComponentVars.style.display = (isComponent && sidebarAddSelectedComponentData) ? 'block' : 'none';
    }
    
    // Tags that support asset browsing for their src param
    const ASSET_BROWSABLE_TAGS = {
        'img': 'images',
        'video': 'videos',
        'audio': 'audio',
        'source': null  // any category
    };
    
    // Full HTML <input type=…> list. Meta-types
    // "select" / "textarea" deliberately omitted here — those are
    // separate tags reachable from the tag picker.
    const INPUT_TYPES_GROUP_A = [
        'text', 'email', 'tel', 'url', 'number', 'password', 'search',
        'date', 'time', 'datetime-local', 'month', 'week', 'color',
        'file', 'hidden', 'checkbox', 'radio', 'range',
        'submit', 'reset', 'button'
    ];
    // Types that DON'T need a name= (no form submission participation
    // beyond the user's explicit opt-in).
    const INPUT_TYPES_NAME_EXEMPT = new Set(['submit', 'reset', 'button', 'hidden']);

    // Update mandatory params based on selected tag
    function updateSidebarAddMandatoryParams() {
        if (!addMandatoryParams || !addMandatoryParamsContainer) return;
        // The fields drawn for the last tag go with its rows.
        _destroyParamFields();
        
        const tag = addTagSelect?.value;
        const mandatoryParams = TAG_INFO?.MANDATORY_PARAMS?.[tag] || [];
        // The groups the tag needs one param of (a <source>: src or srcset), each row saying so
        const oneOfGroups = TAG_INFO?.MANDATORY_ONE_OF?.[tag] || [];

        if (sidebarAddNodeType !== 'tag' || (mandatoryParams.length === 0 && oneOfGroups.length === 0)) {
            addMandatoryParams.style.display = 'none';
            return;
        }
        
        addMandatoryParams.style.display = 'block';
        addMandatoryParamsContainer.replaceChildren();

        // ---- Special case: <input> wizard ----------------------------
        // 'type' becomes a <select>, and we always render a 'name' row
        // (required unless type is in the exempt set).
        if (tag === 'input') {
            renderInputWizardGroupA();
            return;
        }
        // --------------------------------------------------------------

        // A group the tag needs one param of (a <source>: src or srcset) comes first: a choice, then the chosen one's row
        oneOfGroups.forEach(group => _renderOneOfGroup(tag, group));
        mandatoryParams.forEach(param => _appendMandatoryRow(tag, param, false));

        // A tag that may carry a srcset of its own (an <img>) offers its field under its rows
        if (SRCSET_OPTIONAL_TAGS.includes(tag)) addMandatoryParamsContainer.appendChild(_renderSrcsetOffer(tag));
    }

    /**
     * One row of the parameters box, appended: a srcset's field, or a text box (with Media's button for a file, the site's
     * routes for a link). inGroup: one param of a group the tag needs one of. Returns the row.
     */
    function _appendMandatoryRow(tag, param, inGroup) {
        // A srcset is a field of rows (a picture and its descriptor each), not a text box
        if (param === 'srcset') return addMandatoryParamsContainer.appendChild(_renderSrcsetFieldRow(tag, false, null));
        const showBrowse = param === 'src' && tag in ASSET_BROWSABLE_TAGS;
        const showRouteList = param === 'href' && tag === 'a';
        const datalistId = showRouteList ? 'add-mandatory-href-routes' : '';
        const placeholder = (inGroup || !showRouteList) ? QSPanelWords.t('common.required')
            : QSPanelWords.t('preview.hrefPlaceholder');
        const row = _renderParamRow(param, {
            id: 'add-mandatory-' + param,
            placeholder: placeholder
        }, showBrowse ? (ASSET_BROWSABLE_TAGS[tag] || '') : null, datalistId);
        addMandatoryParamsContainer.appendChild(row);
        if (showBrowse) {
            row.querySelector('.preview-asset-browse-btn').addEventListener('click', () => {
                openAssetPicker(row.querySelector(`#add-mandatory-${param}`), ASSET_BROWSABLE_TAGS[tag]);
            });
        }
        // Populate route datalist for href (once the row is in the page, where the list is found by its id)
        if (showRouteList) {
            populateRouteDatalist(datalistId);
        }
        return row;
    }

    // ---- A group the tag needs one param of ------------------------
    // A <source> names its file by src (in a <video> or an <audio>) or by srcset (in a <picture>), and HTML lets it hold one,
    // never both. So the box asks which first: a choice at its top, none made, then the chosen param's row alone. A row not
    // chosen is no parameter: it carries data-one-of-off, which the box's read and the fields' read leave out.

    /** Words under a group's choice, per tag: which param goes where. */
    const ONE_OF_HINTS = {
        source: () => QSPanelWords.t('preview.oneOfHintSource')
    };

    /** The groups' choices drawn in the box: { group, rows, radios, row, both, state } (state: a param, 'all' or null). */
    let oneOfChoices = [];

    /** A group's choice and its params' rows, appended to the box, none chosen. */
    function _renderOneOfGroup(tag, group) {
        const name = 'add-oneof-' + oneOfChoices.length;
        const words = QSPanelWords.t('preview.oneOfParams', { params: group.join(', ') });
        const radios = {};
        const choices = QSDom.el('div', { class: 'preview-oneof__choices', role: 'radiogroup', 'aria-label': words });
        group.forEach(param => {
            const radio = QSDom.el('input', { type: 'radio', name: name, value: param });
            radios[param] = radio;
            choices.appendChild(QSDom.el('label', { class: 'preview-oneof__choice' }, [radio, QSDom.el('span', { text: param })]));
        });
        const hint = ONE_OF_HINTS[tag] ? ONE_OF_HINTS[tag]() : '';
        const both = QSDom.el('small', { class: 'preview-oneof__both', text: QSPanelWords.t('preview.oneOfBoth'), hidden: '' });
        const row = QSDom.el('div', { class: 'preview-contextual-form__param-row preview-oneof', 'data-param-field': 'one-of' }, [
            QSDom.el('span', { class: 'preview-contextual-form__param-label' }, [
                words,
                QSDom.el('span', { class: 'preview-contextual-form__param-required', text: '*' })
            ]),
            choices,
            hint ? QSDom.el('small', { class: 'preview-contextual-form__param-hint', text: hint }) : null,
            both
        ]);
        addMandatoryParamsContainer.appendChild(row);
        const rows = {};
        group.forEach(param => { rows[param] = _appendMandatoryRow(tag, param, true); });
        const choice = { group: group, rows: rows, radios: radios, row: row, both: both, state: null };
        choices.addEventListener('change', (e) => {
            if (e.target && group.includes(e.target.value)) _chooseOneOf(choice, e.target.value);
        });
        oneOfChoices.push(choice);
        _chooseOneOf(choice, null);
    }

    /** The group's state: one param's row shown (its radio checked), none ('' or null), or every row ('all', saying so). */
    function _chooseOneOf(choice, state) {
        choice.state = state || null;
        choice.group.forEach(param => {
            const on = state === 'all' || state === param;
            choice.rows[param].hidden = !on;
            if (on) choice.rows[param].removeAttribute('data-one-of-off');
            else choice.rows[param].setAttribute('data-one-of-off', '');
            choice.radios[param].checked = state === param;
        });
        choice.both.hidden = state !== 'all';
        choice.row.classList.remove('preview-oneof--missing');
    }

    /** Edit Params: each group on what the element holds (one param, or every row when it holds several). */
    function _openOneOfChoices(params) {
        oneOfChoices.forEach(choice => {
            const held = choice.group.filter(param => params[param] != null && String(params[param]) !== '');
            _chooseOneOf(choice, held.length > 1 ? 'all' : (held[0] || null));
        });
    }

    /** A group with nothing chosen, which stops a save (its choice outlined), or null. */
    function _oneOfProblem() {
        const open = oneOfChoices.find(choice => choice.state === null);
        if (!open) return null;
        open.row.classList.add('preview-oneof--missing');
        return QSPanelWords.t('preview.oneOfChoose', { params: open.group.join(', ') });
    }

    // ---- A srcset's field ------------------------------------------
    // A srcset is edited as rows, each a picture and its descriptor (PreviewSrcsetField, preview-srcset-field.js):
    // on a <source>'s one-of row, and on the row an <img> offers, in Add and in Edit Params. A field's inputs sit in
    // the parameters box but are no parameters of their own: the box is read through _mandatoryParamInputs(), which
    // leaves them out, and each field answers its parameters itself (the srcset, and sizes / type where it shows them).

    /** The tags offering an optional srcset of their own; a <source>'s srcset is its one-of row. */
    const SRCSET_OPTIONAL_TAGS = ['img'];

    /** The fields drawn in the parameters box, by the parameter they write. */
    let paramFields = {};
    /** Opens the <img>'s offered field (Edit Params, on an image that has a srcset); null when none is drawn. */
    let srcsetOfferOpen = null;

    function _destroyParamFields() {
        Object.keys(paramFields).forEach(k => {
            try { paramFields[k].destroy(); } catch (e) { /* already gone */ }
        });
        paramFields = {};
        srcsetOfferOpen = null;
        oneOfChoices = [];
    }

    /** Whether a field is read: not when it sits in a group's row that is not chosen. */
    function _paramFieldOn(field) {
        return !field.root.closest('[data-one-of-off]');
    }

    /**
     * The parameters box's own inputs, each a parameter named by its id: a field's inputs (and a group's choice) left out,
     * and a group's row that is not chosen.
     */
    function _mandatoryParamInputs() {
        return Array.from(addMandatoryParamsContainer?.querySelectorAll('input, select') || [])
            .filter(field => !field.closest('[data-param-field], [data-one-of-off]'));
    }

    /** What the fields write, in Add and in Edit Params alike (a field in a row not chosen writes nothing). */
    function _paramFieldParams() {
        const out = {};
        Object.keys(paramFields).forEach(k => { if (_paramFieldOn(paramFields[k])) Object.assign(out, paramFields[k].params()); });
        return out;
    }

    /** What stops a save in the box (said there too): a group with nothing chosen, then a field's first problem; or null. */
    function _paramFieldProblem() {
        const open = _oneOfProblem();
        if (open) return open;
        for (const k of Object.keys(paramFields)) {
            if (!_paramFieldOn(paramFields[k])) continue;
            const problem = paramFields[k].problem();
            if (problem) return problem;
        }
        return null;
    }

    /** Edit Params: each field opened on what the node holds. Answers the names the fields took; the rest go to rows. */
    function _openParamFields(params, tag) {
        if (params.srcset != null && !paramFields.srcset && srcsetOfferOpen) srcsetOfferOpen();
        let taken = [];
        Object.keys(paramFields).forEach(k => { taken = taken.concat(paramFields[k].open(params)); });
        return taken;
    }

    /** Empty and whole again, each field (Save & add another). */
    function _resetParamFields() {
        Object.keys(paramFields).forEach(k => paramFields[k].reset());
    }

    /**
     * A custom row's value for `name`, taken out of the Advanced list (the row removed), or null: a field that comes to
     * show `sizes` or `type` holds it from then on, so one place writes each name.
     */
    function _adoptCustomParam(name) {
        const row = Array.from(addCustomParamsList?.querySelectorAll('.preview-contextual-form__param-row--custom') || [])
            .find(r => (r.querySelector('.preview-contextual-form__param-key')?.value || '').trim() === name);
        if (!row) return null;
        const value = row.querySelector('.preview-contextual-form__param-value')?.value ?? '';
        row.remove();
        return value;
    }

    /**
     * The srcset's row: its label and its field (kept in paramFields). closable: the <img>'s, whose × calls onClose.
     * Returns ONE Element.
     */
    function _renderSrcsetFieldRow(tag, closable, onClose) {
        const field = PreviewSrcsetField.create({
            param: 'srcset',
            withType: tag === 'source',
            closable: closable,
            onClose: onClose,
            pickPicture: (input, onPick) => openAssetPicker(input, 'images', { onPick: onPick }),
            adopt: _adoptCustomParam
        });
        paramFields.srcset = field;
        return QSDom.el('div', { class: 'preview-contextual-form__param-row preview-contextual-form__param-row--field' }, [
            QSDom.el('label', { class: 'preview-contextual-form__param-label', text: 'srcset:' }),
            field.root
        ]);
    }

    /** The <img>'s offer: a button opening its srcset's field, whose × gives the button back. Returns ONE Element. */
    function _renderSrcsetOffer(tag) {
        const holder = QSDom.el('div', { class: 'preview-srcset-offer' });
        const openField = () => {
            holder.replaceChildren(_renderSrcsetFieldRow(tag, true, showOffer));
        };
        const showOffer = () => {
            if (paramFields.srcset) {
                paramFields.srcset.destroy();
                delete paramFields.srcset;
            }
            const btn = QSDom.el('button', { type: 'button', class: 'preview-contextual-form__add-param-btn' },
                ['+ ', QSPanelWords.t('preview.srcsetOffer')]);
            btn.addEventListener('click', () => {
                openField();
                holder.querySelector('.preview-srcset-field__address')?.focus();
            });
            holder.replaceChildren(btn);
        };
        srcsetOfferOpen = openField;
        showOffer();
        return holder;
    }

    // ---- Translation-key params ------------------------------------
    // Optional params whose value is a translation KEY rather than free text:
    // `alt` on img/area, `title` on iframe. The renderer already translates
    // these attributes when the value looks like a key; this is the author's
    // way to choose which key. The set comes from the server
    // (TagRegistry::TRANSLATION_KEY_PARAMS) — never hardcode it here.
    //
    // Live pickers for the current tag, keyed by param name. Rebuilt whenever
    // the tag changes; read back by addTagNode() and _collectEditFormParams().
    let transKeyPickers = {};

    /** One labelled translation-key row. Returns ONE Element. */
    function _renderTransKeyRow(param) {
        const row = document.createElement('div');
        row.className = 'preview-contextual-form__param-row';

        const label = document.createElement('label');
        label.className = 'preview-contextual-form__param-label';
        label.textContent = param + ':';
        row.appendChild(label);

        const host = document.createElement('div');
        host.className = 'preview-contextual-form__param-input-group';
        row.appendChild(host);

        // createTextKeyPicker appends its own root into the host and returns
        // the controller (getValue / setValue / destroy).
        transKeyPickers[param] = window.QSComplexWizard.createTextKeyPicker({
            container: host,
            placeholder: QSPanelWords.t('preview.searchOrTypeKey')
        });

        return row;
    }

    /**
     * Rebuild the translation-key param block for the currently selected tag.
     * Hidden entirely when the tag declares none (most tags).
     */
    function updateSidebarAddTransKeyParams() {
        if (!addTransKeyParams || !addTransKeyParamsContainer) return;

        // Drop the previous tag's pickers before the DOM under them goes.
        Object.keys(transKeyPickers).forEach(k => {
            try { transKeyPickers[k].destroy(); } catch (e) {}
        });
        transKeyPickers = {};
        addTransKeyParamsContainer.replaceChildren();

        const tag = addTagSelect?.value;
        const params = TAG_INFO?.TRANSLATION_KEY_PARAMS?.[tag] || [];

        // The picker is a shared primitive loaded before preview.js. If it is
        // absent the block would render as an empty box promising a field that
        // never appears — hide it and say why instead.
        const pickerAvailable = !!(window.QSComplexWizard
            && typeof window.QSComplexWizard.createTextKeyPicker === 'function');
        if (!pickerAvailable && params.length) {
            console.error('[Preview] QSComplexWizard.createTextKeyPicker unavailable — '
                + 'translation-key params (' + params.join(', ') + ') cannot be offered.');
        }

        if (sidebarAddNodeType !== 'tag' || params.length === 0 || !pickerAvailable) {
            addTransKeyParams.style.display = 'none';
            return;
        }

        addTransKeyParams.style.display = 'block';
        params.forEach(param => {
            addTransKeyParamsContainer.appendChild(_renderTransKeyRow(param));
        });
    }

    /** Read the translation-key pickers back. Empty values are omitted. */
    function _collectTransKeyParams() {
        const out = {};
        Object.keys(transKeyPickers).forEach(param => {
            const value = (transKeyPickers[param].getValue() || '').trim();
            if (value) out[param] = value;
        });
        return out;
    }

    // ---- Input wizard ----------------------------------------------
    // Renders the mandatory-params block for tag=<input>:
    //   1) 'type' as a <select> populated with INPUT_TYPES_GROUP_A
    //   2) 'name' as a text field, required unless type is exempt.
    // Updates the name field's required state on type change.
    function renderInputWizardGroupA() {
        // --- type row ---
        const typeRow = document.createElement('div');
        typeRow.className = 'preview-contextual-form__param-row';
        typeRow.append(
            QSDom.el('label', { class: 'preview-contextual-form__param-label', for: 'add-mandatory-type', text: 'type:' }),
            QSDom.el('div', { class: 'preview-contextual-form__param-input-group' }, [
                QSDom.el('select', { id: 'add-mandatory-type', class: 'admin-input admin-input--sm preview-contextual-form__param-input' },
                    INPUT_TYPES_GROUP_A.map(t => QSDom.el('option', { value: t, text: t })))
            ])
        );
        addMandatoryParamsContainer.appendChild(typeRow);

        // --- name row ---
        const nameRow = document.createElement('div');
        nameRow.className = 'preview-contextual-form__param-row';
        nameRow.id = 'add-mandatory-name-row';
        nameRow.append(
            QSDom.el('label', { class: 'preview-contextual-form__param-label', for: 'add-mandatory-name' }, [
                'name',
                QSDom.el('span', { class: 'preview-contextual-form__param-required', id: 'add-mandatory-name-star', text: '*' }),
                ':'
            ]),
            QSDom.el('div', { class: 'preview-contextual-form__param-input-group' }, [
                QSDom.el('input', { type: 'text', id: 'add-mandatory-name', class: 'admin-input admin-input--sm preview-contextual-form__param-input',
                    placeholder: QSPanelWords.t('common.required') })
            ]),
            QSDom.el('small', { class: 'preview-contextual-form__param-hint', id: 'add-mandatory-name-hint',
                text: QSPanelWords.t('preview.nameRequiredForInput') })
        );
        addMandatoryParamsContainer.appendChild(nameRow);

        // Wire dynamic required-state on type change.
        const typeSelect = typeRow.querySelector('#add-mandatory-type');
        const nameInput = nameRow.querySelector('#add-mandatory-name');
        const nameStar = nameRow.querySelector('#add-mandatory-name-star');
        const nameHint = nameRow.querySelector('#add-mandatory-name-hint');

        const refreshNameRequired = () => {
            const isExempt = INPUT_TYPES_NAME_EXEMPT.has(typeSelect.value);
            if (isExempt) {
                nameStar.style.display = 'none';
                nameHint.style.display = 'none';
                nameInput.placeholder = QSPanelWords.t('preview.optional');
                nameInput.classList.remove('admin-input--error');
            } else {
                nameStar.style.display = '';
                nameHint.style.display = '';
                nameInput.placeholder = QSPanelWords.t('common.required');
            }
        };
        typeSelect.addEventListener('change', refreshNameRequired);
        // Clear error styling once user starts typing
        nameInput.addEventListener('input', () => {
            nameInput.classList.remove('admin-input--error');
        });
        refreshNameRequired();
    }
    // ----------------------------------------------------------------
    
    // Fetch routes and populate a datalist for href suggestions
    async function populateRouteDatalist(datalistId) {
        const datalist = document.getElementById(datalistId);
        if (!datalist) return;
        try {
            const routes = await QuickSiteAdmin.fetchHelperData('routes', []);
            routes.forEach(route => {
                const option = document.createElement('option');
                option.value = '/' + route.value;
                option.label = '/' + route.label;
                datalist.appendChild(option);
            });
        } catch (e) {
            // Silently fail — user can still type manually
        }
    }
    
    /**
     * The picker's upload row (options.upload): a file sent to uploadAsset, then picked at once. Offered to a
     * role that may upload. Returns ONE Element.
     */
    function _renderAssetUpload(targetInput, accept, onPicked) {
        const file = QSDom.el('input', { type: 'file', accept: accept || '', hidden: '' });
        const btn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--outline', text: QSPanelWords.t('preview.simpleAdd.upload') });
        btn.addEventListener('click', () => file.click());
        file.addEventListener('change', async () => {
            const picked = file.files && file.files[0];
            if (!picked) return;
            btn.disabled = true;
            btn.textContent = QSPanelWords.t('preview.simpleAdd.uploading');
            try {
                const form = new FormData();
                form.append('file', picked);
                const res = await QuickSiteAdmin.apiUpload('uploadAsset', form);
                const path = res && res.ok && res.data && res.data.data ? res.data.data.path : '';
                if (!path) throw new Error((res && res.data && res.data.message) || QSPanelWords.t('preview.simpleAdd.uploadFailed'));
                onPicked(path, picked.type || '');
            } catch (err) {
                showToast(err.message || QSPanelWords.t('preview.simpleAdd.uploadFailed'), 'error');
                btn.disabled = false;
                btn.textContent = QSPanelWords.t('preview.simpleAdd.upload');
                file.value = '';
            }
        });
        return QSDom.el('div', { class: 'preview-asset-picker__upload' }, [btn, file]);
    }

    // Asset picker: opens a dropdown/modal to browse and select an asset.
    // options.upload: an upload row at the top (options.accept: what the file dialog offers).
    // options.onPick(asset): the picked asset handed back after the input is filled (its path, and the width, height
    // and mime_type listAssets holds for it), in place of the inline preview, for a caller that shows the picture its
    // own way (a srcset's row, which takes the width and the format).
    async function openAssetPicker(targetInput, category, options) {
        const opts = options || {};
        // Remove any existing picker
        document.querySelector('.preview-asset-picker')?.remove();

        const picker = document.createElement('div');
        picker.className = 'preview-asset-picker';
        // With an upload row, the list is drawn below it; without, in the picker itself, as ever.
        const body = opts.upload && QuickSiteAdmin.canRun('uploadAsset') ? QSDom.el('div', { class: 'preview-asset-picker__body' }) : picker;
        if (body !== picker) {
            picker.appendChild(_renderAssetUpload(targetInput, opts.accept, (path, mime) => {
                targetInput.value = path;
                targetInput.dispatchEvent(new Event('input'));
                picker.remove();
                document.removeEventListener('mousedown', closeHandler);
                if (typeof opts.onPick === 'function') opts.onPick({ path: path, mime_type: mime });
                else showAssetPreview(targetInput, path, mime);
            }));
            picker.appendChild(body);
        }
        body.appendChild(QSDom.el('div', { class: 'preview-asset-picker__loading', text: QSPanelWords.t('common.loading') }));
        
        // Position near the input, clamped to viewport
        const rect = targetInput.getBoundingClientRect();
        const pickerMaxH = 280;
        const spaceBelow = window.innerHeight - rect.bottom - 8;
        const spaceAbove = rect.top - 8;
        
        if (spaceBelow >= pickerMaxH || spaceBelow >= spaceAbove) {
            // Open below
            const availH = Math.min(pickerMaxH, spaceBelow);
            picker.style.top = (rect.bottom + 4) + 'px';
            picker.style.maxHeight = availH + 'px';
        } else {
            // Open above
            const availH = Math.min(pickerMaxH, spaceAbove);
            picker.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            picker.style.maxHeight = availH + 'px';
        }
        // As wide as the field and a little more, 320 at least, and inside the window: on a phone it takes the width there is.
        // The bounds are CSS's own min() and max(), so no script reads the window's width (the panel's three views).
        const pickerWidth = Math.max(rect.width + 50, 320) + 'px';
        picker.style.width = 'min(' + pickerWidth + ', 100vw - 1rem)';
        picker.style.left = 'max(0.5rem, min(' + rect.left + 'px, 100vw - 0.5rem - min(' + pickerWidth + ', 100vw - 1rem)))';
        document.body.appendChild(picker);

        // Close on click outside
        const closeHandler = (e) => {
            if (!picker.contains(e.target) && e.target !== targetInput) {
                picker.remove();
                document.removeEventListener('mousedown', closeHandler);
            }
        };
        setTimeout(() => document.addEventListener('mousedown', closeHandler), 0);
        
        try {
            const urlParams = category ? [category] : [];
            const response = await QuickSiteAdmin.apiRequest('listAssets', 'GET', null, urlParams);
            
            if (!response.ok || !response.data?.data) {
                body.replaceChildren(_renderAssetPickerNote(QSPanelWords.t('preview.assetsLoadFailed')));
                return;
            }
            
            const assetsMap = response.data.data.assets || {};
            // Flatten category map into a single array
            let assets = [];
            for (const [cat, files] of Object.entries(assetsMap)) {
                if (Array.isArray(files)) {
                    files.forEach(f => { f.category = f.category || cat; });
                    assets = assets.concat(files);
                }
            }
            if (assets.length === 0) {
                body.replaceChildren(_renderAssetPickerNote(QSPanelWords.t('preview.assetsNone')));
                return;
            }

            body.replaceChildren();
            const list = document.createElement('div');
            list.className = 'preview-asset-picker__list';
            
            assets.forEach(asset => {
                const item = document.createElement('div');
                item.className = 'preview-asset-picker__item';
                const path = asset.path || `/assets/${asset.category}/${asset.filename}`;
                const isImage = asset.mime_type?.startsWith('image/');
                const displayPath = previewBase + path; // project assets live under /p/<id>/ for a non-served project
                
                item.append(
                    isImage
                        ? QSDom.el('img', { class: 'preview-asset-picker__thumb', src: displayPath, alt: '', loading: 'lazy' })
                        : QSDom.el('span', { class: 'preview-asset-picker__icon', text: '📄' }),
                    ' ',
                    QSDom.el('span', { class: 'preview-asset-picker__name', title: path, text: asset.filename || path.split('/').pop() })
                );
                
                item.addEventListener('click', () => {
                    targetInput.value = path;
                    targetInput.dispatchEvent(new Event('input'));
                    picker.remove();
                    document.removeEventListener('mousedown', closeHandler);
                    
                    // Auto-fill alt from the asset's own metadata (set at
                    // upload) if the author hasn't chosen anything yet. `alt`
                    // now lives in the translation-key picker, which accepts a
                    // typed literal as well as a key — the renderer translates
                    // only values shaped like a key, so a sentence stays a
                    // sentence.
                    if (asset.alt && transKeyPickers.alt && !transKeyPickers.alt.getValue()) {
                        transKeyPickers.alt.setValue(asset.alt);
                    }
                    
                    // Show inline media preview below the input group, or hand the picture to the caller
                    if (typeof opts.onPick === 'function') opts.onPick(Object.assign({}, asset, { path: path }));
                    else showAssetPreview(targetInput, path, asset.mime_type);
                });
                
                list.appendChild(item);
            });
            
            body.appendChild(list);
        } catch (err) {
            body.replaceChildren(_renderAssetPickerNote(QSPanelWords.t('preview.assetsLoadError')));
            console.error('[Preview] Asset picker error:', err);
        }
    }
    
    /**
     * Show an inline media preview below the src input after asset selection
     */
    function showAssetPreview(targetInput, path, mimeType) {
        const row = targetInput.closest('.preview-contextual-form__param-row');
        if (!row) return;
        
        // Remove existing preview
        row.querySelector('.preview-asset-inline')?.remove();
        
        if (!path) return;
        
        const container = document.createElement('div');
        container.className = 'preview-asset-inline';
        
        // Determine media type from mime or file extension
        const ext = path.split('.').pop().toLowerCase();
        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        const videoExts = ['mp4', 'webm', 'ogv'];
        const audioExts = ['mp3', 'wav', 'ogg'];
        const displayPath = previewBase + path; // project assets live under /p/<id>/ for a non-served project
        
        const mime = (mimeType || '').split('/')[0];
        
        if (mime === 'image' || imageExts.includes(ext)) {
            container.appendChild(QSDom.el('img', { src: displayPath, alt: QSPanelWords.t('preview.assetPreviewAlt'), class: 'preview-asset-inline__media' }));
        } else if (mime === 'video' || videoExts.includes(ext)) {
            container.appendChild(QSDom.el('video', { src: displayPath, controls: '', class: 'preview-asset-inline__media' }));
        } else if (mime === 'audio' || audioExts.includes(ext)) {
            container.appendChild(QSDom.el('audio', { src: displayPath, controls: '', class: 'preview-asset-inline__audio' }));
        } else {
            container.appendChild(QSDom.el('span', { class: 'preview-asset-inline__file', text: '📄 ' + path.split('/').pop() }));
        }
        
        row.appendChild(container);
    }
    
    

    
    // Add type tabs click handler
    addTypeTabs?.querySelectorAll('.preview-contextual-form__tab').forEach(tab => {
        tab.addEventListener('click', function() {
            sidebarAddNodeType = this.dataset.type;
            if (addTypeInput) addTypeInput.value = sidebarAddNodeType;
            try { localStorage.setItem(QuickSiteStorageKeys.addLastTab, sidebarAddNodeType); } catch (e) { /* not kept: the next form opens on the default tab */ }
            updateSidebarAddTypeTabs(sidebarAddNodeType);
            updateSidebarAddNodeTypeUI();
            // The Text tab starts on Inside, the other tabs on After (as the form opens)
            setAddPosition(sidebarAddNodeType === 'text' ? 'inside' : 'after');
            _syncAddPositionOptions();
            if (sidebarAddNodeType === 'component') {
                loadSidebarComponentsList();
            } else if (sidebarAddNodeType === 'snippet') {
                loadSidebarSnippetsList();
            } else if (sidebarAddNodeType === 'complex') {
                populateComplexKindPicker();
            } else if (sidebarAddNodeType === 'text') {
                updateAddTextFieldsForType();
            }
        });
    });

    // Text tab: swap value-input vs key-picker visibility on type-radio change,
    // and lazy-mount the QSComplexWizard textKey picker on first use.
    function ensureAddTextKeyPicker() {
        if (addTextKeyPickerInst || !addTextKeyPickerHost) return;
        if (!window.QSComplexWizard || !window.QSComplexWizard.createTextKeyPicker) {
            console.warn('[Preview] textKey picker module (QSComplexWizard.createTextKeyPicker) not available');
            return;
        }
        addTextKeyPickerInst = window.QSComplexWizard.createTextKeyPicker({
            container: addTextKeyPickerHost,
            value: '',
            placeholder: QSPanelWords.t('preview.variablesPlaceholderTranslation')
        });
    }
    function updateAddTextFieldsForType() {
        const isRaw = document.querySelector('input[name="add-text-type"]:checked')?.value === 'raw';
        if (addTextValueField) addTextValueField.style.display = isRaw ? '' : 'none';
        if (addTextKeyField) addTextKeyField.style.display = isRaw ? 'none' : '';
        if (!isRaw) ensureAddTextKeyPicker();
    }
    document.querySelectorAll('input[name="add-text-type"]').forEach(r => {
        r.addEventListener('change', updateAddTextFieldsForType);
    });

    // -------------------------------------------------------------------
    // Complex Element wizard glue
    // -------------------------------------------------------------------

    /**
     * Read window.QSComplexWizard.registry and (re)populate the kind
     * <select> the kind picker wraps. Each registry entry must have at
     * least { label, renderWizard(container) }; its description becomes
     * the option's data-description, which the picker shows under the
     * kind. Called every time the user opens the Complex tab so
     * newly-shipped kinds appear without page reload during development.
     */
    function populateComplexKindPicker() {
        if (!addComplexKindSelect) return;
        const registry = (window.QSComplexWizard && window.QSComplexWizard.registry) || {};
        const previous = addComplexKindSelect.value;
        // Keep the leading "— pick a kind —" placeholder; rebuild the rest.
        const placeholder = addComplexKindSelect.querySelector('option[value=""]');
        addComplexKindSelect.replaceChildren();
        if (placeholder) addComplexKindSelect.appendChild(placeholder);
        Object.keys(registry).sort().forEach(kind => {
            const entry = registry[kind] || {};
            const opt = document.createElement('option');
            opt.value = kind;
            opt.textContent = entry.label || kind;
            if (entry.description) opt.dataset.description = entry.description;
            addComplexKindSelect.appendChild(opt);
        });
        // Restore previous selection if it still exists, else clear.
        if (previous && registry[previous]) {
            addComplexKindSelect.value = previous;
        } else {
            addComplexKindSelect.value = '';
            renderComplexWizardBody(null);
        }
        if (addComplexKindPicker) addComplexKindPicker.refresh();
    }

    /**
     * Render the wizard body for the picked kind. Tears down any prior
     * controller first so each kind starts clean.
     */
    function renderComplexWizardBody(kind) {
        if (!addComplexBody) return;
        // Tear down prior controller
        if (addComplexController && typeof addComplexController.destroy === 'function') {
            try { addComplexController.destroy(); } catch (e) { console.warn('[CE] destroy threw:', e); }
        }
        addComplexController = null;
        addComplexBody.replaceChildren();

        if (!kind) {
            addComplexBody.style.display = 'none';
            return;
        }
        const registry = (window.QSComplexWizard && window.QSComplexWizard.registry) || {};
        const entry = registry[kind];
        if (!entry || typeof entry.renderWizard !== 'function') {
            addComplexBody.replaceChildren(_renderComplexError(QSPanelWords.t('preview.complexKindNoWizard', { kind: kind })));
            addComplexBody.style.display = '';
            return;
        }
        try {
            addComplexController = entry.renderWizard(addComplexBody) || null;
        } catch (e) {
            console.error('[CE] renderWizard for "' + kind + '" threw:', e);
            addComplexBody.replaceChildren(_renderComplexError(QSPanelWords.t('preview.complexWizardFailed')));
        }
        addComplexBody.style.display = '';
    }

    // React to kind changes.
    addComplexKindSelect?.addEventListener('change', function () {
        renderComplexWizardBody(this.value);
    });
    
    // ==================== Component Selector ====================
    let componentsLoaded = false;
    let componentsData = [];
    
    function initComponentSelector(selectorId) {
        const selector = document.getElementById(`${selectorId}-component-selector`);
        if (!selector) return null;
        
        const trigger = document.getElementById(`${selectorId}-component-trigger`);
        const displayValue = document.getElementById(`${selectorId}-component-display`);
        const displayDesc = document.getElementById(`${selectorId}-component-display-desc`);
        const panel = document.getElementById(`${selectorId}-component-panel`);
        const searchInput = document.getElementById(`${selectorId}-component-search`);
        const optionsContainer = document.getElementById(`${selectorId}-component-options`);
        const noResults = document.getElementById(`${selectorId}-component-no-results`);
        const hiddenInput = document.getElementById(`${selectorId}-component`);
        const previewPanel = document.getElementById(`${selectorId}-component-preview`);
        const previewTitle = document.getElementById(`${selectorId}-component-preview-title`);
        const previewFrame = document.getElementById(`${selectorId}-component-preview-frame`);
        
        let isOpen = false;
        
        function openDropdown() {
            if (!panel) return;
            isOpen = true;
            panel.style.display = '';
            trigger?.classList.add('open');
            setTimeout(() => searchInput?.focus(), 50);
        }
        
        function closeDropdown() {
            if (!panel) return;
            isOpen = false;
            panel.style.display = 'none';
            trigger?.classList.remove('open');
            if (searchInput) searchInput.value = '';
            resetSearch();
        }
        
        function toggleDropdown() {
            isOpen ? closeDropdown() : openDropdown();
        }
        
        trigger?.addEventListener('click', toggleDropdown);
        
        document.addEventListener('click', function(e) {
            if (isOpen && !selector.contains(e.target)) closeDropdown();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                closeDropdown();
                e.stopPropagation();
            }
        });
        
        function createOptionButton(comp) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tag-dropdown__option';
            btn.dataset.name = comp.name.toLowerCase();
            btn.dataset.componentName = comp.name;
            const varCount = (comp.variables || []).length;
            if (hiddenInput?.value === comp.name) btn.classList.add('selected');
            btn.appendChild(QSDom.el('span', { class: 'tag-dropdown__option-tag', text: comp.name }));
            if (varCount > 0) btn.append(' ', QSDom.el('span', { class: 'tag-dropdown__option-desc', text: componentVariablesLabel(varCount) }));
            btn.addEventListener('click', () => {
                selectComponent(comp);
                closeDropdown();
            });
            return btn;
        }
        
        function renderOptions() {
            if (!optionsContainer) return;
            optionsContainer.replaceChildren();
            
            const validComponents = componentsData.filter(c => c.valid);
            if (validComponents.length === 0) {
                optionsContainer.replaceChildren(_renderSelectorNote(QSPanelWords.t('preview.noComponentsFound')));
                return;
            }
            
            validComponents.forEach(comp => {
                optionsContainer.appendChild(createOptionButton(comp));
            });
        }
        
        function selectComponent(comp) {
            sidebarAddSelectedComponentData = comp;
            
            if (hiddenInput) hiddenInput.value = comp.name;
            if (displayValue) displayValue.textContent = comp.name;
            
            const varCount = (comp.variables || []).length;
            if (displayDesc) displayDesc.textContent = varCount > 0 ? componentVariablesLabel(varCount) : '';
            
            optionsContainer?.querySelectorAll('.tag-dropdown__option').forEach(o => {
                o.classList.toggle('selected', o.dataset.componentName === comp.name);
            });
            
            // Populate component variables
            renderComponentVars(comp);
            
            // Load full component data for preview
            loadComponentPreview(comp.name);
        }
        
        function loadComponentPreview(componentName) {
            if (!previewFrame) return;
            if (previewPanel) previewPanel.style.display = 'block';
            if (previewTitle) previewTitle.textContent = componentName;
            
            // Reuse the server-side component renderer (same as main editor preview)
            previewFrame.src = (PreviewConfig.previewBase || PreviewConfig.baseUrl) + '/?_component=' + encodeURIComponent(componentName) + '&_editor=1';
        }
        
        function renderComponentVars(comp) {
            const vars = comp.variables || [];
            
            if (addComponentVars) addComponentVars.style.display = 'block';
            
            if (vars.length === 0) {
                if (addComponentVarsContainer) addComponentVarsContainer.replaceChildren();
                if (addComponentNoVars) addComponentNoVars.style.display = 'flex';
                return;
            }
            
            if (addComponentNoVars) addComponentNoVars.style.display = 'none';
            if (!addComponentVarsContainer) return;
            
            addComponentVarsContainer.replaceChildren();
            
            const enumVars = vars.filter(v => v.type === 'enum');
            const paramVars = vars.filter(v => v.type === 'param');
            const textKeyVars = vars.filter(v => v.type === 'textKey');
            
            // Enums section — select dropdowns
            if (enumVars.length > 0) {
                const enumSection = document.createElement('div');
                enumSection.className = 'preview-variables-section';
                enumSection.appendChild(_renderVariablesSectionTitle(QSPanelWords.t('preview.variablesSectionEnums')));
                
                enumVars.forEach(v => {
                    const options = v.options || [];
                    const defaultVal = v.default || options[0] || '';
                    const row = document.createElement('div');
                    row.className = 'preview-contextual-form__param-row';
                    row.append(
                        QSDom.el('label', { class: 'preview-contextual-form__param-label', text: v.name + ':' }),
                        QSDom.el('select', { class: 'admin-input admin-input--sm preview-contextual-form__param-input', 'data-var-name': v.name },
                            options.map(opt => _renderOption(opt, opt, opt === defaultVal)))
                    );
                    enumSection.appendChild(row);
                });
                addComponentVarsContainer.appendChild(enumSection);
            }
            
            // Parameters section — editable inputs
            if (paramVars.length > 0) {
                const paramSection = document.createElement('div');
                paramSection.className = 'preview-variables-section';
                paramSection.appendChild(_renderVariablesSectionTitle(QSPanelWords.t('preview.variablesSectionParams')));
                
                paramVars.forEach(v => {
                    // Check if this param variable should show an asset browse button
                    const showBrowse = v.paramName === 'src' && v.parentTag in ASSET_BROWSABLE_TAGS;
                    const showRouteList = v.paramName === 'href' && v.parentTag === 'a';
                    const datalistId = showRouteList ? `add-comp-${v.name}-routes` : '';
                    const row = _renderParamRow(v.name, {
                        'data-var-name': v.name,
                        placeholder: showRouteList ? QSPanelWords.t('preview.hrefPlaceholder') : '{{' + v.name + '}}'
                    }, showBrowse ? (ASSET_BROWSABLE_TAGS[v.parentTag] || '') : null, datalistId);
                    paramSection.appendChild(row);
                    
                    if (showBrowse) {
                        row.querySelector('.preview-asset-browse-btn').addEventListener('click', () => {
                            openAssetPicker(row.querySelector(`input[data-var-name="${v.name}"]`), ASSET_BROWSABLE_TAGS[v.parentTag]);
                        });
                    }
                    
                    if (showRouteList) {
                        populateRouteDatalist(datalistId);
                    }
                });
                addComponentVarsContainer.appendChild(paramSection);
            }
            
            // Text keys section — info only
            if (textKeyVars.length > 0) {
                const textSection = document.createElement('div');
                textSection.className = 'preview-variables-section';
                textSection.appendChild(_renderVariablesSectionTitle(QSPanelWords.t('preview.variablesSectionText')));
                
                const infoRow = document.createElement('div');
                infoRow.className = 'preview-contextual-form__info';
                infoRow.style.margin = '0';
                infoRow.append(
                    QSDom.iconEl(QuickSiteUtils.ICON_PATHS.info, 14),
                    ' ',
                    QSDom.el('span', { style: 'font-size:0.75rem;', text: QSPanelWords.t('preview.componentTextKeysNote',
                        { names: textKeyVars.map(v => v.name).join(', ') }) })
                );
                textSection.appendChild(infoRow);
                
                // Hidden inputs for data collection
                textKeyVars.forEach(v => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.dataset.varName = v.name;
                    hidden.value = '';
                    textSection.appendChild(hidden);
                });
                
                addComponentVarsContainer.appendChild(textSection);
            }
        }
        
        // Search filtering
        function resetSearch() {
            optionsContainer?.querySelectorAll('.tag-dropdown__option').forEach(o => o.style.display = '');
            if (noResults) noResults.style.display = 'none';
        }
        
        searchInput?.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            if (!query) { resetSearch(); return; }
            
            let totalMatches = 0;
            optionsContainer?.querySelectorAll('.tag-dropdown__option').forEach(opt => {
                const name = opt.dataset.name || '';
                const matches = name.includes(query);
                opt.style.display = matches ? '' : 'none';
                if (matches) totalMatches++;
            });
            
            if (noResults) noResults.style.display = totalMatches === 0 ? 'flex' : 'none';
        });
        
        searchInput?.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const visibleOpt = optionsContainer?.querySelector('.tag-dropdown__option:not([style*="display: none"])');
            if (visibleOpt) visibleOpt.click();
        });
        
        return {
            renderOptions,
            showLoading: function() {
                if (optionsContainer) optionsContainer.replaceChildren(_renderSelectorLoading());
            },
            showError: function() {
                if (optionsContainer) optionsContainer.replaceChildren(_renderSelectorNote(QSPanelWords.t('preview.errorLoadingComponents')));
            },
            reset: function() {
                sidebarAddSelectedComponentData = null;
                if (hiddenInput) hiddenInput.value = '';
                if (displayValue) displayValue.textContent = QSPanelWords.t('preview.selectComponent');
                if (displayDesc) displayDesc.textContent = '';
                if (previewPanel) previewPanel.style.display = 'none';
                if (addComponentVars) addComponentVars.style.display = 'none';
                if (addComponentVarsContainer) addComponentVarsContainer.replaceChildren();
            },
            getValue: () => hiddenInput?.value || ''
        };
    }
    
    const addComponentSelector = initComponentSelector('add');
    
    // Load components for sidebar add form
    async function loadSidebarComponentsList(forceReload = false) {
        if (!addComponentSelector) return;
        
        if (componentsLoaded && !forceReload) return;
        
        addComponentSelector.showLoading();
        
        try {
            const response = await QuickSiteAdmin.apiRequest('listComponents', 'GET');
            if (response.ok && response.data?.data?.components) {
                componentsData = response.data.data.components;
                componentsLoaded = true;
                addComponentSelector.renderOptions();
            } else {
                addComponentSelector.showError();
            }
        } catch (error) {
            console.error('[Preview] Failed to load components:', error);
            addComponentSelector.showError();
        }
    }
    
    // ==================== Snippet Selector ====================
    // State for snippets (module-level, shared with addSnippetNode/submitSaveSnippet)
    let snippetsLoaded = false;
    let snippetsData = { byCategory: {}, snippets: [] };
    let selectedSnippetId = null;
    let selectedSnippetData = null;
    let snippetPreviewWithProjectStyle = true;
    let snippetPreviewShowKeys = false;

    /** The word for the tier a listed snippet comes from: core, personal or the project. */
    function snippetSourceLabel(source) {
        if (source === 'core') return QSPanelWords.t('preview.snippetSourceCore');
        if (source === 'personal') return QSPanelWords.t('preview.snippetSourcePersonal');
        return QSPanelWords.t('preview.snippetSourceProject');
    }

    /** A snippet's name: a core snippet's in the panel's language, any other as its author wrote it (its own words). */
    function snippetName(snippet) {
        const key = 'preview.coreSnippets.' + snippet.id + '.name';
        return snippet.source === 'core' && QSPanelWords.has(key) ? QSPanelWords.t(key) : (snippet.name || snippet.id);
    }

    /** A snippet's description, read as its name is. */
    function snippetDescription(snippet) {
        const key = 'preview.coreSnippets.' + snippet.id + '.description';
        return snippet.source === 'core' && QSPanelWords.has(key) ? QSPanelWords.t(key) : (snippet.description || '');
    }

    /** A category's group header: its word, or a category outside the list as its own id, capitalised. */
    function snippetCategoryLabel(category) {
        const id = category.charAt(0).toUpperCase() + category.slice(1);
        const key = 'preview.snippetCategory' + id;
        return QSPanelWords.has(key) ? QSPanelWords.t(key) : id;
    }
    
    // Searchable snippet dropdown (tag-dropdown pattern)
    function initSnippetSelector(selectorId) {
        const selector = document.getElementById(`${selectorId}-snippet-selector`);
        if (!selector) return null;
        
        const trigger = document.getElementById(`${selectorId}-snippet-trigger`);
        const displayValue = document.getElementById(`${selectorId}-snippet-display`);
        const displayDesc = document.getElementById(`${selectorId}-snippet-display-desc`);
        const panel = document.getElementById(`${selectorId}-snippet-panel`);
        const searchInput = document.getElementById(`${selectorId}-snippet-search`);
        const optionsContainer = document.getElementById(`${selectorId}-snippet-options`);
        const noResults = document.getElementById(`${selectorId}-snippet-no-results`);
        const hiddenInput = document.getElementById(`${selectorId}-snippet`);
        const previewPanel = document.getElementById(`${selectorId}-snippet-preview`);
        const previewTitle = document.getElementById(`${selectorId}-snippet-preview-title`);
        const previewSource = document.getElementById(`${selectorId}-snippet-preview-source`);
        const previewDesc = document.getElementById(`${selectorId}-snippet-preview-desc`);
        const previewActions = document.getElementById(`${selectorId}-snippet-preview-actions`);
        
        let isOpen = false;
        
        function sourceFromSnippet(snippet) {
            return snippet.source;
        }
        
        // Dropdown open/close
        function openDropdown() {
            if (!panel) return;
            isOpen = true;
            panel.style.display = '';
            trigger?.classList.add('open');
            setTimeout(() => searchInput?.focus(), 50);
        }
        
        function closeDropdown() {
            if (!panel) return;
            isOpen = false;
            panel.style.display = 'none';
            trigger?.classList.remove('open');
            if (searchInput) searchInput.value = '';
            resetSearch();
        }
        
        function toggleDropdown() {
            isOpen ? closeDropdown() : openDropdown();
        }
        
        trigger?.addEventListener('click', toggleDropdown);
        
        document.addEventListener('click', function(e) {
            if (isOpen && !selector.contains(e.target)) closeDropdown();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                closeDropdown();
                e.stopPropagation();
            }
        });
        
        // Create option button for a snippet
        function createOptionButton(snippet) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tag-dropdown__option';
            btn.dataset.snippetId = snippet.id;
            btn.dataset.name = snippetName(snippet).toLowerCase();
            btn.dataset.desc = snippetDescription(snippet).toLowerCase();
            if (snippet.id === selectedSnippetId) btn.classList.add('selected');
            
            const src = sourceFromSnippet(snippet);
            const srcText = snippetSourceLabel(src);
            btn.append(
                QSDom.el('span', { class: 'tag-dropdown__option-tag', text: snippetName(snippet) }),
                ' ',
                QSDom.el('span', { class: 'tag-dropdown__option-desc', text: snippetDescription(snippet) }),
                ' ',
                QSDom.el('span', { class: 'snippet-selector__option-source snippet-selector__option-source--' + src, text: srcText })
            );
            btn.addEventListener('click', () => {
                selectSnippet(snippet);
                closeDropdown();
            });
            return btn;
        }
        
        // Render options grouped by category
        function renderOptions() {
            if (!optionsContainer) return;
            optionsContainer.replaceChildren();
            
            const categories = Object.keys(snippetsData.byCategory || {});
            if (categories.length === 0) {
                optionsContainer.replaceChildren(_renderSelectorNote(QSPanelWords.t('preview.noSnippetsFound')));
                return;
            }
            
            categories.forEach(cat => {
                const group = document.createElement('div');
                group.className = 'tag-dropdown__group';
                
                const header = document.createElement('button');
                header.type = 'button';
                header.className = 'tag-dropdown__group-header';
                header.append(QSDom.el('span', { text: snippetCategoryLabel(cat) }), ' ',
                    QSDom.iconEl(QuickSiteUtils.ICON_PATHS.chevronDown, 0, 'tag-dropdown__group-chevron'));
                header.addEventListener('click', () => group.classList.toggle('collapsed'));
                group.appendChild(header);
                
                const items = document.createElement('div');
                items.className = 'tag-dropdown__group-items';
                (snippetsData.byCategory[cat] || []).forEach(snippet => {
                    items.appendChild(createOptionButton(snippet));
                });
                group.appendChild(items);
                optionsContainer.appendChild(group);
            });
        }
        
        // Search filtering
        function resetSearch() {
            optionsContainer?.querySelectorAll('.tag-dropdown__group').forEach(g => {
                g.style.display = '';
                g.querySelectorAll('.tag-dropdown__option').forEach(o => o.style.display = '');
            });
            if (noResults) noResults.style.display = 'none';
        }
        
        searchInput?.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            if (!query) { resetSearch(); return; }
            
            let totalMatches = 0;
            optionsContainer?.querySelectorAll('.tag-dropdown__group').forEach(group => {
                let groupMatches = 0;
                group.querySelectorAll('.tag-dropdown__option').forEach(opt => {
                    const name = opt.dataset.name || '';
                    const desc = opt.dataset.desc || '';
                    const matches = name.includes(query) || desc.includes(query);
                    opt.style.display = matches ? '' : 'none';
                    if (matches) groupMatches++;
                });
                group.style.display = groupMatches > 0 ? '' : 'none';
                if (groupMatches > 0) group.classList.remove('collapsed');
                totalMatches += groupMatches;
            });
            
            if (noResults) noResults.style.display = totalMatches === 0 ? 'flex' : 'none';
        });
        
        // Enter key: select first visible match
        searchInput?.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const visibleOpt = optionsContainer?.querySelector('.tag-dropdown__option:not([style*="display: none"])');
            if (visibleOpt) visibleOpt.click();
        });
        
        // Update trigger display to reflect current selection
        function updateTriggerDisplay(snippet) {
            if (displayValue) displayValue.textContent = snippet ? snippetName(snippet) : QSPanelWords.t('preview.selectSnippet');
            if (displayDesc) displayDesc.textContent = snippet ? snippetDescription(snippet) : '';
        }
        
        // Update option highlight
        function updateOptionHighlight() {
            optionsContainer?.querySelectorAll('.tag-dropdown__option').forEach(o => {
                o.classList.toggle('selected', o.dataset.snippetId === selectedSnippetId);
            });
        }
        
        return {
            renderOptions,
            updateTriggerDisplay,
            updateOptionHighlight,
            showLoading: function() {
                if (optionsContainer) optionsContainer.replaceChildren(_renderSelectorLoading());
            },
            showError: function() {
                if (optionsContainer) optionsContainer.replaceChildren(_renderSelectorNote(QSPanelWords.t('preview.errorLoadingSnippets')));
            },
            reset: function() {
                selectedSnippetId = null;
                selectedSnippetData = null;
                if (hiddenInput) hiddenInput.value = '';
                updateTriggerDisplay(null);
                if (previewPanel) previewPanel.style.display = 'none';
                if (addSnippetCssStatus) addSnippetCssStatus.style.display = 'none';
            }
        };
    }
    
    // Initialize snippet selector
    const addSnippetSelector = initSnippetSelector('add');
    
    // Load snippets for sidebar add form (skips API call if already loaded)
    async function loadSidebarSnippetsList(forceReload = false) {
        if (!addSnippetSelector) return;
        
        // Skip reload if data is already cached and not invalidated
        if (snippetsLoaded && !forceReload) return;
        
        // Show loading in dropdown
        addSnippetSelector.showLoading();
        if (addSnippetPreview) addSnippetPreview.style.display = 'none';
        
        try {
            const response = await QuickSiteAdmin.apiRequest('listSnippets', 'GET');
            if (response.ok && response.data?.data) {
                snippetsData = {
                    byCategory: response.data.data.byCategory || {},
                    snippets: response.data.data.snippets || []
                };
                snippetsLoaded = true;
                addSnippetSelector.renderOptions();
            } else {
                addSnippetSelector.showError();
            }
        } catch (error) {
            console.error('[Preview] Failed to load snippets:', error);
            addSnippetSelector.showError();
        }
    }
    
    // Select a snippet
    async function selectSnippet(snippet) {
        selectedSnippetId = snippet.id;
        selectedSnippetData = snippet;
        
        // Update dropdown trigger display
        if (addSnippetSelector) {
            addSnippetSelector.updateTriggerDisplay(snippet);
            addSnippetSelector.updateOptionHighlight();
        }
        
        // Update hidden input
        if (addSnippetInput) addSnippetInput.value = snippet.id;
        
        const src = snippet.source;
        
        // Show preview panel
        if (addSnippetPreview) {
            addSnippetPreview.style.display = 'block';
            if (addSnippetPreviewTitle) addSnippetPreviewTitle.textContent = snippetName(snippet);
            if (addSnippetPreviewSource) {
                addSnippetPreviewSource.textContent = snippetSourceLabel(src);
                addSnippetPreviewSource.className = `snippet-selector__preview-source snippet-selector__preview-source--${src}`;
            }
            if (addSnippetPreviewDesc) addSnippetPreviewDesc.textContent = snippetDescription(snippet);
            
            // Show delete button only for non-core snippets
            if (addSnippetPreviewActions) {
                addSnippetPreviewActions.style.display = src === 'core' ? 'none' : 'flex';
            }
        }
        
        // Load full snippet data for preview
        try {
            const response = await QuickSiteAdmin.apiRequest('getSnippet', 'GET', null, [], { id: snippet.id });
            if (response.ok && response.data?.data) {
                selectedSnippetData = { ...snippet, ...response.data.data };
                renderSnippetPreview(selectedSnippetData);
                renderSnippetCssStatus(selectedSnippetData);
            }
        } catch (error) {
            console.error('[Preview] Failed to load snippet details:', error);
        }
    }
    
    // Delete selected snippet (only for non-core snippets)
    async function deleteSelectedSnippet() {
        if (!selectedSnippetData || !selectedSnippetId) {
            showToast(QSPanelWords.t('preview.noSnippetSelected'), 'warning');
            return;
        }
        
        const src = selectedSnippetData.source;
        if (src === 'core') {
            showToast(QSPanelWords.t('preview.cannotDeleteCore'), 'error');
            return;
        }
        
        const shownName = snippetName(selectedSnippetData);
        const asked = await QuickSiteUtils.confirm(QSPanelWords.t('preview.deleteCannotBeUndone'), {
            title: QSPanelWords.t('preview.deleteSnippetTitle', { name: shownName }),
            type: 'danger',
            confirmText: QSPanelWords.t('common.delete')
        });
        if (!asked) return;
        
        try {
            const response = await QuickSiteAdmin.apiRequest('deleteSnippet', 'DELETE', null, [], { id: selectedSnippetId });
            
            if (response.ok) {
                showToast(QSPanelWords.t('preview.snippetDeleted'), 'success');
                
                // Reset selection and trigger
                if (addSnippetSelector) addSnippetSelector.reset();
                
                // Refresh snippets list
                snippetsLoaded = false;
                await loadSidebarSnippetsList();
            } else {
                throw new Error(response.data?.message || QSPanelWords.t('preview.deleteSnippetFailed'));
            }
        } catch (error) {
            console.error('[Preview] Delete snippet error:', error);
            showToast(error.message || QSPanelWords.t('preview.deleteSnippetFailed'), 'error');
        }
    }
    
    // Render CSS selector status panel
    function renderSnippetCssStatus(snippetData) {
        if (!addSnippetCssStatus) return;
        
        const selectors = snippetData.selectorStatus;
        const css = snippetData.css || '';
        
        // Hide if no selectors tracked
        if (!selectors || selectors.length === 0) {
            addSnippetCssStatus.style.display = 'none';
            if (addSnippetCssActions) addSnippetCssActions.style.display = 'none';
            return;
        }
        
        addSnippetCssStatus.style.display = 'block';
        
        const hasMissing = selectors.some(s => !s.exists);
        
        // Render selector badges
        if (addSnippetCssSelectors) {
            addSnippetCssSelectors.replaceChildren(...selectors.map(s => QSDom.el('span', {
                class: 'snippet-selector__css-badge ' + (s.exists ? 'snippet-selector__css-badge--found' : 'snippet-selector__css-badge--missing'),
                text: (s.exists ? '✓' : '✗') + ' ' + s.selector
            })));
        }
        
        // Show/hide CSS action radio group
        if (addSnippetCssOptions) {
            addSnippetCssOptions.style.display = (selectors.length > 0 && css) ? 'block' : 'none';
            // Show "Add only missing" option only when there are missing selectors
            if (addSnippetCssOptionMissing) {
                addSnippetCssOptionMissing.style.display = hasMissing ? '' : 'none';
                // If "missing" was selected but no longer applicable, reset to "skip"
                if (!hasMissing) {
                    const missingRadio = addSnippetCssOptionMissing.querySelector('input[type="radio"]');
                    if (missingRadio && missingRadio.checked) {
                        const skipRadio = addSnippetCssOptions.querySelector('input[value="skip"]');
                        if (skipRadio) skipRadio.checked = true;
                    }
                }
            }
        }
        if (addSnippetCssActions) {
            addSnippetCssActions.style.display = (selectors.length > 0 && css) ? 'block' : 'none';
        }
        
        // Populate saved CSS code block
        if (addSnippetCssCode) {
            if (css) {
                addSnippetCssCode.textContent = css;
                if (addSnippetCssToggle) addSnippetCssToggle.style.display = '';
            } else {
                addSnippetCssCode.textContent = '';
                addSnippetCssCode.style.display = 'none';
                if (addSnippetCssToggle) addSnippetCssToggle.style.display = 'none';
            }
        }
        
        // Toggle handler
        if (addSnippetCssToggle && !addSnippetCssToggle._bound) {
            addSnippetCssToggle.addEventListener('click', function() {
                const isHidden = addSnippetCssCode.style.display === 'none';
                addSnippetCssCode.style.display = isHidden ? 'block' : 'none';
            });
            addSnippetCssToggle._bound = true;
        }
    }
    
    // Style toggle handler — re-render preview with/without project CSS
    if (addSnippetStyleToggleInput && !addSnippetStyleToggleInput._bound) {
        addSnippetStyleToggleInput.addEventListener('change', function() {
            snippetPreviewWithProjectStyle = !this.checked;
            if (selectedSnippetData) {
                renderSnippetPreview(selectedSnippetData);
            }
        });
        addSnippetStyleToggleInput._bound = true;
    }

    // "Show keys" handler — re-render preview with every key and raw text as stored
    if (addSnippetKeysToggleInput && !addSnippetKeysToggleInput._bound) {
        addSnippetKeysToggleInput.addEventListener('change', function() {
            snippetPreviewShowKeys = this.checked;
            if (selectedSnippetData) {
                renderSnippetPreview(selectedSnippetData);
            }
        });
        addSnippetKeysToggleInput._bound = true;
    }
    
    // Inject snippet CSS into target project stylesheet
    // Returns true on success, false on failure
    async function injectSnippetCss(mode) {
        if (!selectedSnippetId) return false;
        
        const label = QSPanelWords.t(mode === 'missing' ? 'preview.snippetCssAdding' : 'preview.snippetCssReplacing');
        showToast(label, 'info');
        
        try {
            const response = await QuickSiteAdmin.apiRequest('injectSnippetCss', 'POST', {
                id: selectedSnippetId,
                mode: mode
            });
            
            if (response.ok) {
                const count = response.data?.data?.injected?.length || 0;
                showToast(
                    mode === 'missing'
                        ? (count > 0 ? QSPanelWords.count('preview.snippetCssAdded', count) : QSPanelWords.t('preview.snippetCssAllPresent'))
                        : QSPanelWords.t('preview.snippetCssReplaced'),
                    'success'
                );
                
                // Re-fetch snippet to refresh selectorStatus
                if (selectedSnippetData) {
                    const refreshed = await QuickSiteAdmin.apiRequest('getSnippet', 'GET', null, [], { id: selectedSnippetId });
                    if (refreshed.ok && refreshed.data?.data) {
                        selectedSnippetData = { ...selectedSnippetData, ...refreshed.data.data };
                        renderSnippetCssStatus(selectedSnippetData);
                    }
                }
                return true;
            } else {
                throw new Error(response.data?.message || QSPanelWords.t('preview.snippetCssInjectionFailed'));
            }
        } catch (error) {
            console.error('[Preview] CSS inject error:', error);
            showToast(error.message || QSPanelWords.t('preview.snippetCssInjectFailed'), 'error');
            return false;
        }
    }
    
    // Helper: get selected CSS action from radio group
    function getSelectedCssAction() {
        if (!addSnippetCssOptions) return 'skip';
        const checked = addSnippetCssOptions.querySelector('input[name="add-snippet-css-action"]:checked');
        return checked ? checked.value : 'skip';
    }

    // Show/hide CSS warning based on radio selection
    if (addSnippetCssOptions) {
        addSnippetCssOptions.addEventListener('change', function() {
            const action = getSelectedCssAction();
            if (addSnippetCssWarning) {
                addSnippetCssWarning.style.display = (action === 'missing' || action === 'replace') ? 'flex' : 'none';
            }
        });
    }

    // Render the snippet preview. The frame (`_snippet-selector.php`) holds a fixed empty
    // document; each render rebuilds what the snippet brings with the frame's OWN document, so
    // nothing the snippet carries is ever parsed as HTML: its CSS is a <style> element's text,
    // its texts are text nodes, an attribute name the browser refuses is left out. The frame is
    // sandboxed without scripts, and its elements belong to it, never to the panel's document.
    function renderSnippetPreview(snippetData) {
        if (!addSnippetPreviewFrame) return;

        // Show/hide the style toggle when snippet has its own CSS
        if (addSnippetStyleToggle) {
            addSnippetStyleToggle.style.display = snippetData.css ? '' : 'none';
        }

        whenSnippetFrameReady(function(doc) {
            fillSnippetFrame(doc, snippetData);
        });
    }

    /** Run `fn` with the preview frame's document once its fixed empty document has loaded. */
    function whenSnippetFrameReady(fn) {
        const doc = addSnippetPreviewFrame.contentDocument;
        if (doc && doc.URL === 'about:srcdoc' && doc.readyState === 'complete' && doc.body) {
            fn(doc);
            return;
        }
        addSnippetPreviewFrame.addEventListener('load', function() {
            fn(addSnippetPreviewFrame.contentDocument);
        }, { once: true });
    }

    /**
     * Rebuild the frame: the project's stylesheet (unless "Without current project style"),
     * the snippet's own CSS after the frame's base rules, and the snippet's nodes.
     */
    function fillSnippetFrame(doc, snippetData) {
        doc.head.querySelectorAll('[data-snippet-preview]').forEach(function(el) { el.remove(); });

        const projectStyleUrl = PreviewConfig.projectStyleUrl || '';
        if (snippetPreviewWithProjectStyle && projectStyleUrl) {
            const link = doc.createElement('link');
            link.rel = 'stylesheet';
            link.href = projectStyleUrl;
            link.setAttribute('data-snippet-preview', '');
            // Before the frame's base rules: the snippet's own CSS comes after both
            doc.head.insertBefore(link, doc.head.querySelector('style'));
        }
        if (snippetData.css) {
            const style = doc.createElement('style');
            style.setAttribute('data-snippet-preview', '');
            style.textContent = snippetData.css;
            doc.head.appendChild(style);
        }

        // Use expanded preview structure if available (for component-based snippets)
        const structure = snippetData.previewStructure || snippetData.structure;
        doc.body.replaceChildren(...snippetPreviewNodes(doc, structure, snippetData));
    }

    // What the engine's renderer does with a node's text and attributes (JsonToHtmlRenderer
    // renderTextNode() / renderAttribute()), so the preview shows what the page will. Keep in step;
    // the attributes translated and the ones written even when empty are TagRegistry's lists
    // (TAG_INFO, declared further down: read when a preview is built, not at load).
    const SNIPPET_TRANSLATION_KEY = /^[a-z0-9_]+(\.[a-z0-9_]+)+$/i;

    /** The text of a raw (`__RAW__`) value, or null for any other value. */
    function snippetLiteral(value) {
        return value.startsWith('__RAW__') ? value.slice(7) : null;
    }

    /** A key's word in the project's default language, then in English; null when neither has it. */
    function snippetWord(snippetData, key) {
        const lang = PreviewConfig.defaultLang || 'en';
        return snippetData.translations?.[lang]?.[key] ||
               snippetData.translations?.['en']?.[key] ||
               null;
    }

    /** A key's text in a translatable attribute: its word with its markers removed (no formatting there); the key itself when no language has it. */
    function snippetTranslation(snippetData, key) {
        const word = snippetWord(snippetData, key);
        return word === null ? key : QSTextMarkers.remove(word, TAG_INFO.TEXT_MARKERS || {});
    }

    /**
     * A text node's nodes in the frame's document: as stored with "Show keys", a raw text as written and a key
     * no language has as its name, each one text; a word read as the renderer reads it (QSTextMarkers): its
     * markers elements, or, inside an element that shows no formatting ($plain), removed.
     */
    function snippetTextNodes(doc, snippetData, textKey, plain) {
        const literal = snippetLiteral(textKey);
        const word = snippetPreviewShowKeys || literal !== null ? null : snippetWord(snippetData, textKey);
        if (word === null) {
            const text = snippetPreviewShowKeys ? textKey : (literal !== null ? literal : textKey);
            return text === '' ? [] : [doc.createTextNode(text)];
        }
        const markers = TAG_INFO.TEXT_MARKERS || {};
        if (plain) {
            const text = QSTextMarkers.remove(word, markers);
            return text === '' ? [] : [doc.createTextNode(text)];
        }
        return Array.from(QSTextMarkers.read(doc, word, markers).childNodes);
    }

    /**
     * An attribute's value as the page renders it; null when the page renders no such attribute.
     * A raw text (`__RAW__`) is read in a translatable attribute only, as the renderer reads it:
     * anywhere else the value is shown as written.
     */
    function snippetAttributeValue(snippetData, name, value) {
        // A handler never runs in the preview (no scripts), so it is not written at all
        if (/^on/i.test(name)) return null;
        if (value === true) return '';
        if (value === false || value === null || value === undefined || typeof value === 'object') return null;
        value = String(value);
        if (value === '' && !(TAG_INFO.EMPTY_MEANINGFUL_ATTRIBUTES || []).includes(name)) return null;
        if (snippetPreviewShowKeys) return value;
        if ((TAG_INFO.TRANSLATABLE_ATTRIBUTES || []).includes(name)) {
            const literal = snippetLiteral(value);
            if (literal !== null) return literal;
            if (SNIPPET_TRANSLATION_KEY.test(value)) return snippetTranslation(snippetData, value);
        }
        return value;
    }

    /**
     * The snippet's structure (a node or a list of nodes) as nodes of the frame's document. $plain: inside an
     * element that shows no formatting (TagRegistry::PLAIN_TEXT_TAGS), where a word's markers are removed.
     */
    function snippetPreviewNodes(doc, structure, snippetData, plain) {
        if (!structure || typeof structure !== 'object') return [];
        if (Array.isArray(structure)) {
            return structure.flatMap(node => snippetPreviewNodes(doc, node, snippetData, plain));
        }
        // A node carrying a text key is a text, whatever else it carries, as the renderer reads it
        if (structure.textKey !== undefined && structure.textKey !== null) {
            return snippetTextNodes(doc, snippetData, String(structure.textKey), !!plain);
        }
        const el = _renderSnippetElement(doc, structure, snippetData, plain);
        return el ? [el] : [];
    }

    /** One tag node as an element of the frame's document; null for a tag the engine does not render. */
    function _renderSnippetElement(doc, node, snippetData, plain) {
        const tag = String(node.tag || 'div').toLowerCase();
        if ((TAG_INFO.BLOCKED_TAGS || []).includes(tag)) return null;
        if (TAG_INFO.ALLOWED_TAGS && !TAG_INFO.ALLOWED_TAGS.includes(tag)) return null;
        let el;
        try {
            el = doc.createElement(tag);
        } catch (e) {
            return null;
        }
        Object.entries(node.params || {}).forEach(([name, value]) => {
            const shown = snippetAttributeValue(snippetData, name, value);
            if (shown === null) return;
            try {
                el.setAttribute(name, shown);
            } catch (e) {
                // a name the browser refuses: left out
            }
        });
        // A void element (img, br, input...) takes no children
        if ((TAG_INFO.SELF_CLOSING_TAGS || []).includes(tag)) return el;
        const children = snippetPreviewNodes(doc, node.children, snippetData,
            plain || (TAG_INFO.PLAIN_TEXT_TAGS || []).includes(tag));
        el.append(...(tag === 'table' ? snippetTableRows(doc, children) : children));
        return el;
    }

    /** Rows straight inside a table, in the <tbody> the browser's parser gives them on the page. */
    function snippetTableRows(doc, children) {
        const out = [];
        let tbody = null;
        children.forEach(function(child) {
            if (child.nodeType === 1 && child.localName === 'tr') {
                if (!tbody) {
                    tbody = doc.createElement('tbody');
                    out.push(tbody);
                }
                tbody.appendChild(child);
            } else {
                tbody = null;
                out.push(child);
            }
        });
        return out;
    }
    
    // ==================== Unified Tag Dropdown ====================
    // Replaces the old 3-tier tag selector with a searchable dropdown
    // Includes: favorites (★), contextual suggestions (✦), quick-add (Enter)
    
    // Contextual suggestion rules: parent tag → recommended children
    const TAG_SUGGESTIONS = {
        'ul': ['li'],
        'ol': ['li'],
        'nav': ['a', 'ul'],
        'table': ['thead', 'tbody', 'tfoot', 'tr'],
        'thead': ['tr'],
        'tbody': ['tr'],
        'tfoot': ['tr'],
        'tr': ['td', 'th'],
        'dl': ['dt', 'dd'],
        'select': ['option', 'optgroup'],
        'figure': ['img', 'figcaption'],
        'details': ['summary'],
        'fieldset': ['legend', 'input', 'label'],
        'picture': ['source', 'img'],
    };
    
    function initTagSelector(selectorId) {
        const selector = document.getElementById(`${selectorId}-tag-selector`);
        if (!selector) return;
        
        const trigger = document.getElementById(`${selectorId}-tag-trigger`);
        const displayValue = document.getElementById(`${selectorId}-tag-display`);
        const displayDesc = document.getElementById(`${selectorId}-tag-display-desc`);
        const panel = document.getElementById(`${selectorId}-tag-panel`);
        const searchInput = document.getElementById(`${selectorId}-tag-search`);
        const optionsContainer = document.getElementById(`${selectorId}-tag-options`);
        const noResults = document.getElementById(`${selectorId}-tag-no-results`);
        const hiddenInput = document.getElementById(`${selectorId}-tag`);
        const starBtn = document.getElementById(`${selectorId}-tag-star`);
        const starLabel = document.getElementById(`${selectorId}-tag-star-label`);
        const favoritesGroup = document.getElementById(`${selectorId}-tag-favorites-group`);
        const favoritesItems = document.getElementById(`${selectorId}-tag-favorites-items`);
        const suggestedGroup = document.getElementById(`${selectorId}-tag-suggested-group`);
        const suggestedItems = document.getElementById(`${selectorId}-tag-suggested-items`);
        
        // Preview panel elements (use document.getElementById because the preview
        // panel is relocated to a collapsible section before initTagSelector runs)
        const previewPanel = document.getElementById(`${selectorId}-tag-preview`);
        const previewName = document.getElementById(`${selectorId}-tag-preview-name`);
        const previewDesc = document.getElementById(`${selectorId}-tag-preview-desc`);
        const previewRender = document.getElementById(`${selectorId}-tag-preview-render`);
        const previewNoRender = document.getElementById(`${selectorId}-tag-preview-norender`);
        const codeToggle = document.getElementById(`${selectorId}-tag-code-toggle`);
        const codeView = document.getElementById(`${selectorId}-tag-preview-code`);
        const codeContent = document.getElementById(`${selectorId}-tag-preview-code-content`);
        
        // Load tag data from embedded JSON
        let tagData = {};
        const dataEl = document.getElementById(`${selectorId}-tag-data`);
        if (dataEl) {
            try {
                const parsed = JSON.parse(dataEl.textContent);
                tagData = parsed.tags || {};
            } catch (e) {
                console.error('Failed to parse tag data:', e);
            }
        }
        
        // Code view toggle (persisted in localStorage, when the browser keeps it)
        const CODE_VIEW_KEY = QuickSiteStorageKeys.tagShowHtmlCode;
        let showHtmlCode = false;
        try { showHtmlCode = localStorage.getItem(CODE_VIEW_KEY) === 'true'; } catch (e) { /* drawn */ }
        if (codeToggle && codeView) {
            if (showHtmlCode) {
                codeToggle.classList.add('active');
                codeView.style.display = '';
            }
            codeToggle.addEventListener('click', function() {
                showHtmlCode = !showHtmlCode;
                try { localStorage.setItem(CODE_VIEW_KEY, showHtmlCode); } catch (e) { /* not kept */ }
                this.classList.toggle('active', showHtmlCode);
                codeView.style.display = showHtmlCode ? '' : 'none';
            });
        }
        
        // Favorites from localStorage
        const FAVORITES_KEY = QuickSiteStorageKeys.addFavoriteTags;
        const MAX_FAVORITES = 10;
        
        function getFavorites() {
            try { return JSON.parse(localStorage.getItem(FAVORITES_KEY)) || []; }
            catch { return []; }
        }
        
        function saveFavorites(favs) {
            try { localStorage.setItem(FAVORITES_KEY, JSON.stringify(favs.slice(0, MAX_FAVORITES))); } catch (e) { /* not kept */ }
        }
        
        function isFavorite(tag) {
            return getFavorites().includes(tag);
        }
        
        function toggleFavorite(tag) {
            const favs = getFavorites();
            const idx = favs.indexOf(tag);
            if (idx >= 0) {
                favs.splice(idx, 1);
            } else {
                favs.unshift(tag);
            }
            saveFavorites(favs);
            updateStarButton(tag);
            renderFavorites();
        }
        
        function updateStarButton(tag) {
            if (!starBtn) return;
            const isFav = isFavorite(tag);
            starBtn.classList.toggle('active', isFav);
            const svg = starBtn.querySelector('svg');
            if (svg) svg.setAttribute('fill', isFav ? 'currentColor' : 'none');
            if (starLabel) starLabel.textContent = isFav ? 
                QSPanelWords.t('preview.unstarTag') : 
                QSPanelWords.t('preview.starTag');
        }
        
        function renderFavorites() {
            if (!favoritesGroup || !favoritesItems) return;
            const favs = getFavorites();
            if (favs.length === 0) {
                favoritesGroup.style.display = 'none';
                return;
            }
            favoritesGroup.style.display = '';
            favoritesItems.replaceChildren();
            favs.forEach(tag => {
                const info = tagData[tag];
                if (!info) return;
                const btn = createOptionButton(tag, info);
                favoritesItems.appendChild(btn);
            });
        }
        
        // Contextual suggestions based on parent tag
        function renderSuggestions() {
            if (!suggestedGroup || !suggestedItems) return;
            
            // Get the parent tag of current selection from iframe data
            let parentTag = selectedElementTag || null;
            
            const suggestions = parentTag ? (TAG_SUGGESTIONS[parentTag] || []) : [];
            if (suggestions.length === 0) {
                suggestedGroup.style.display = 'none';
                return;
            }
            
            suggestedGroup.style.display = '';
            suggestedItems.replaceChildren();
            suggestions.forEach(tag => {
                const info = tagData[tag];
                if (!info) return;
                const btn = createOptionButton(tag, info);
                suggestedItems.appendChild(btn);
            });
        }
        
        // Create an option button for the dropdown
        function createOptionButton(tag, info) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tag-dropdown__option';
            btn.dataset.tag = tag;
            btn.dataset.category = info.category;
            btn.dataset.desc = info.desc;
            if (info.required) btn.dataset.required = 'true';
            if (hiddenInput?.value === tag) btn.classList.add('selected');
            btn.append(
                QSDom.el('code', { class: 'tag-dropdown__option-tag', text: '<' + tag + '>' }),
                ' ',
                QSDom.el('span', { class: 'tag-dropdown__option-desc', text: info.desc })
            );
            if (info.required) btn.append(' ', QSDom.el('span', {
                class: 'tag-dropdown__option-required', title: QSPanelWords.t('preview.requiresParams'), text: '*'
            }));
            btn.addEventListener('click', () => selectTag(tag));
            return btn;
        }
        
        // Dropdown open/close
        let isOpen = false;
        
        function openDropdown() {
            if (!panel) return;
            isOpen = true;
            panel.style.display = '';
            trigger?.classList.add('open');
            renderFavorites();
            renderSuggestions();
            // Focus search
            setTimeout(() => searchInput?.focus(), 50);
        }
        
        function closeDropdown() {
            if (!panel) return;
            isOpen = false;
            panel.style.display = 'none';
            trigger?.classList.remove('open');
            // Clear search
            if (searchInput) searchInput.value = '';
            resetSearch();
        }
        
        function toggleDropdown() {
            isOpen ? closeDropdown() : openDropdown();
        }
        
        // Trigger click
        trigger?.addEventListener('click', toggleDropdown);
        
        // Close on click outside
        document.addEventListener('click', function(e) {
            if (isOpen && !selector.contains(e.target)) {
                closeDropdown();
            }
        });
        
        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                closeDropdown();
                e.stopPropagation();
            }
        });
        
        // Optgroup expand/collapse
        optionsContainer?.querySelectorAll('.tag-dropdown__group-header').forEach(header => {
            header.addEventListener('click', function() {
                const group = this.closest('.tag-dropdown__group');
                if (group) group.classList.toggle('collapsed');
            });
        });
        
        // Tag option clicks (for static PHP-rendered options)
        optionsContainer?.querySelectorAll('.tag-dropdown__option').forEach(opt => {
            opt.addEventListener('click', function() {
                selectTag(this.dataset.tag);
            });
        });
        
        // Star button
        starBtn?.addEventListener('click', function() {
            const currentTag = hiddenInput?.value || 'div';
            toggleFavorite(currentTag);
        });
        
        // Select a tag
        function selectTag(tagName) {
            if (hiddenInput) {
                hiddenInput.value = tagName;
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            
            // Update trigger display
            if (displayValue) displayValue.textContent = `<${tagName}>`;
            const info = tagData[tagName];
            if (displayDesc && info) displayDesc.textContent = info.desc;
            
            // Update visual selection
            optionsContainer?.querySelectorAll('.tag-dropdown__option').forEach(o => {
                o.classList.toggle('selected', o.dataset.tag === tagName);
            });
            
            // Update preview
            updatePreview(tagName);
            
            // Update star button
            updateStarButton(tagName);
            
            // Close dropdown
            closeDropdown();
        }
        
        // Update preview panel
        function updatePreview(tagName) {
            if (!previewPanel) return;
            
            if (previewName) previewName.textContent = `<${tagName}>`;
            
            const info = tagData[tagName];
            if (previewDesc && info) previewDesc.textContent = info.desc;
            
            // The example is the <template> _tag-selector.php wrote for this tag; none means no visual preview.
            const example = document.getElementById(`${selectorId}-tag-example-${tagName}`);
            if (!example) {
                if (previewRender) previewRender.style.display = 'none';
                if (previewNoRender) previewNoRender.style.display = 'flex';
                if (codeView) codeView.style.display = 'none';
            } else {
                if (previewRender) {
                    previewRender.style.display = '';
                    previewRender.replaceChildren(example.content.cloneNode(true));
                }
                if (previewNoRender) previewNoRender.style.display = 'none';
                if (codeContent) {
                    codeContent.textContent = formatHtmlForDisplay(example.innerHTML);
                }
                if (codeView && showHtmlCode) codeView.style.display = '';
            }
        }
        
        function formatHtmlForDisplay(html) {
            let formatted = html;
            formatted = formatted.replace(/\s*class="[^"]*tag-ex[^"]*"/g, '');
            formatted = formatted.replace(/><(?!\/)/g, '>\n<');
            formatted = formatted.replace(/<\/(div|section|article|header|footer|main|aside|nav|figure|form|fieldset|ul|ol|dl|table|thead|tbody|tfoot|tr|menu|details|blockquote|pre|video|audio)>/g, '</$1>\n');
            return formatted.trim();
        }
        
        // Search functionality
        function resetSearch() {
            // Show all groups, hide no-results
            optionsContainer?.querySelectorAll('.tag-dropdown__group').forEach(g => {
                g.style.display = '';
                g.querySelectorAll('.tag-dropdown__option').forEach(o => o.style.display = '');
            });
            if (noResults) noResults.style.display = 'none';
        }
        
        searchInput?.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            if (!query) {
                resetSearch();
                renderFavorites();
                renderSuggestions();
                return;
            }
            
            // Hide favorites and suggested during search
            if (favoritesGroup) favoritesGroup.style.display = 'none';
            if (suggestedGroup) suggestedGroup.style.display = 'none';
            
            let totalMatches = 0;
            
            // Filter options in each group
            optionsContainer?.querySelectorAll('.tag-dropdown__group:not(.tag-dropdown__group--favorites):not(.tag-dropdown__group--suggested)').forEach(group => {
                let groupMatches = 0;
                group.querySelectorAll('.tag-dropdown__option').forEach(opt => {
                    const tag = opt.dataset.tag || '';
                    const desc = opt.dataset.desc || '';
                    const matches = tag.includes(query) || desc.toLowerCase().includes(query);
                    opt.style.display = matches ? '' : 'none';
                    if (matches) groupMatches++;
                });
                group.style.display = groupMatches > 0 ? '' : 'none';
                // Auto-expand groups with matches
                if (groupMatches > 0) group.classList.remove('collapsed');
                totalMatches += groupMatches;
            });
            
            if (noResults) noResults.style.display = totalMatches === 0 ? 'flex' : 'none';
        });
        
        // Quick-add: Enter in search field
        searchInput?.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            
            const query = this.value.trim().toLowerCase();
            if (!query) return;
            
            // Find visible matches
            const visibleOptions = [];
            optionsContainer?.querySelectorAll('.tag-dropdown__group:not(.tag-dropdown__group--favorites):not(.tag-dropdown__group--suggested)').forEach(group => {
                if (group.style.display === 'none') return;
                group.querySelectorAll('.tag-dropdown__option').forEach(opt => {
                    if (opt.style.display !== 'none') visibleOptions.push(opt);
                });
            });
            
            // Exact match takes priority
            const exactMatch = visibleOptions.find(o => o.dataset.tag === query);
            if (exactMatch) {
                selectTag(exactMatch.dataset.tag);
                // Trigger quick-add: click the confirm button
                addConfirmBtn?.click();
                return;
            }
            
            // Single match → quick add
            if (visibleOptions.length === 1) {
                selectTag(visibleOptions[0].dataset.tag);
                addConfirmBtn?.click();
                return;
            }
            
            // Multiple matches → select first but don't auto-add
            if (visibleOptions.length > 0) {
                selectTag(visibleOptions[0].dataset.tag);
            }
        });
        
        // Initialize
        const initialTag = hiddenInput?.value || 'div';
        updatePreview(initialTag);
        updateStarButton(initialTag);
        
        return {
            selectTag,
            getValue: () => hiddenInput?.value,
            reset: () => selectTag('div'),
            refreshSuggestions: renderSuggestions,
        };
    }
    
    // Initialize tag selector for add mode
    const addTagSelector = initTagSelector('add');
    
    // ==================== CSS Class Combobox ====================
    const classCombobox = (function initClassCombobox() {
        const container = document.getElementById('add-class-combobox');
        const chipsEl = document.getElementById('add-class-chips');
        const input = document.getElementById('add-class-input');
        const dropdown = document.getElementById('add-class-dropdown');
        const suggestionsEl = document.getElementById('add-class-suggestions');
        const hiddenInput = document.getElementById('add-class');
        
        if (!container || !input || !hiddenInput) return null;
        
        let selectedClasses = [];
        let allClasses = [];
        let classesLoaded = false;
        
        function loadClasses() {
            if (classesLoaded) return;
            const classes = new Set();
            
            // Source 1: page DOM classes
            if (typeof pageStructureClasses !== 'undefined' && Array.isArray(pageStructureClasses)) {
                pageStructureClasses.forEach(c => classes.add(c));
            }
            
            // Source 2: CSS-defined class selectors
            try {
                let categorized = null;
                if (window.PreviewSelectorBrowser) {
                    categorized = PreviewSelectorBrowser.getCategorizedSelectors();
                }
                if (categorized?.classes) {
                    categorized.classes.forEach(item => {
                        const name = (typeof item === 'string' ? item : item.selector || '').replace(/^\./, '');
                        if (name && !name.startsWith('qs-')) classes.add(name);
                    });
                }
            } catch (e) { /* selectors not loaded yet */ }
            
            allClasses = [...classes].sort((a, b) => a.localeCompare(b));
            classesLoaded = true;
        }
        
        function syncHiddenInput() {
            hiddenInput.value = selectedClasses.join(' ');
        }
        
        function renderChips() {
            chipsEl.replaceChildren();
            selectedClasses.forEach(cls => {
                const chip = document.createElement('span');
                chip.className = 'class-combobox__chip';
                chip.append(
                    QSDom.el('span', { class: 'class-combobox__chip-text', text: cls }),
                    QSControls.removeRow(QSPanelWords.t('common.remove'), null, { className: 'class-combobox__chip-remove', attrs: { 'data-class': cls } })
                );
                chipsEl.appendChild(chip);
            });
        }
        
        function addClass(name) {
            name = name.trim().replace(/[^a-zA-Z0-9_-]/g, '');
            if (!name || selectedClasses.includes(name)) return;
            selectedClasses.push(name);
            syncHiddenInput();
            renderChips();
            input.value = '';
            closeDropdown();
        }
        
        function removeClass(name) {
            selectedClasses = selectedClasses.filter(c => c !== name);
            syncHiddenInput();
            renderChips();
        }
        
        function showDropdown(filter) {
            if (!classesLoaded) loadClasses();
            const q = (filter || '').toLowerCase();
            const matches = q
                ? allClasses.filter(c => c.toLowerCase().includes(q) && !selectedClasses.includes(c))
                : allClasses.filter(c => !selectedClasses.includes(c));
            
            if (matches.length === 0 && !q) {
                closeDropdown();
                return;
            }
            
            suggestionsEl.replaceChildren();
            const maxShow = 30;
            const displayed = matches.slice(0, maxShow);
            
            if (displayed.length === 0 && q) {
                const hint = document.createElement('div');
                hint.className = 'class-combobox__hint';
                hint.textContent = QSPanelWords.t('preview.classComboboxAdd', { name: q });
                suggestionsEl.appendChild(hint);
            }
            
            displayed.forEach(cls => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'class-combobox__suggestion';
                btn.textContent = cls;
                btn.addEventListener('click', () => addClass(cls));
                suggestionsEl.appendChild(btn);
            });
            
            if (matches.length > maxShow) {
                const more = document.createElement('div');
                more.className = 'class-combobox__hint';
                more.textContent = QSPanelWords.count('preview.classComboboxMore', matches.length - maxShow);
                suggestionsEl.appendChild(more);
            }
            
            dropdown.style.display = '';
        }
        
        function closeDropdown() {
            dropdown.style.display = 'none';
        }
        
        // Events
        input.addEventListener('focus', function() {
            if (!classesLoaded) loadClasses();
            showDropdown(this.value);
        });
        
        input.addEventListener('input', function() {
            showDropdown(this.value);
        });
        
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const val = this.value.trim();
                if (val) addClass(val);
            }
            if (e.key === 'Backspace' && !this.value && selectedClasses.length > 0) {
                removeClass(selectedClasses[selectedClasses.length - 1]);
            }
            if (e.key === 'Escape') {
                closeDropdown();
                this.blur();
            }
        });
        
        // Chip remove buttons
        chipsEl.addEventListener('click', function(e) {
            const btn = e.target.closest('.class-combobox__chip-remove');
            if (btn) removeClass(btn.dataset.class);
        });
        
        // Click outside to close
        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) closeDropdown();
        });
        
        return {
            addClass,
            reset() {
                selectedClasses = [];
                syncHiddenInput();
                renderChips();
                input.value = '';
                closeDropdown();
                classesLoaded = false;
            },
            flush() {
                // Commit any text still in the visible input (user typed but didn't press Enter)
                const val = input.value.trim();
                if (val) addClass(val);
            },
            getValue() {
                return hiddenInput.value;
            },
            refreshClasses() {
                classesLoaded = false;
            },
        };
    })();

    // Tag select change handler
    addTagSelect?.addEventListener('change', function() {
        updateSidebarAddMandatoryParams();
        updateSidebarAddTransKeyParams();
        
        // Show info message for iframe tag
        let iframeInfo = addMandatoryParamsContainer?.parentElement?.querySelector('.preview-iframe-info');
        if (this.value === 'iframe') {
            if (!iframeInfo) {
                iframeInfo = document.createElement('div');
                iframeInfo.className = 'preview-iframe-info';
                iframeInfo.appendChild(QSDom.el('small', { text: QSPanelWords.t('preview.iframeInfo') }));
                addMandatoryParamsContainer?.parentElement?.appendChild(iframeInfo);
            }
            iframeInfo.style.display = 'block';
        } else if (iframeInfo) {
            iframeInfo.style.display = 'none';
        }
    });
    
    // Build one custom-param row (key + value + remove button) with the
    // data-attr picker wired on the key input. Returns the Element.
    // Used by both the "+ Add another" button (empty row) and the
    // Edit-Params pre-populate path (filled row).
    function _renderCustomParamRow(opts) {
        const key   = opts && opts.key   != null ? String(opts.key)   : '';
        const value = opts && opts.value != null ? String(opts.value) : '';
        const index = opts && opts.index != null ? String(opts.index) : '';

        const row = document.createElement('div');
        row.className = 'preview-contextual-form__param-row preview-contextual-form__param-row--custom';

        const keyInput = document.createElement('input');
        keyInput.type = 'text';
        keyInput.className = 'admin-input admin-input--sm preview-contextual-form__param-key';
        keyInput.placeholder = QSPanelWords.t('preview.paramName');
        if (index) keyInput.dataset.paramIndex = index;
        if (key) keyInput.value = key;

        const valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'admin-input admin-input--sm preview-contextual-form__param-value';
        valueInput.placeholder = QSPanelWords.t('preview.paramValue');
        if (index) valueInput.dataset.paramIndex = index;
        if (value) valueInput.value = value;

        // Its class is data-attr-picker.js's hook: the row's description box goes with the row
        const removeBtn = QSControls.removeRow(QSPanelWords.t('common.remove'), () => row.remove(),
            { className: 'preview-contextual-form__remove-param' });

        row.appendChild(keyInput);
        row.appendChild(valueInput);
        row.appendChild(removeBtn);

        // Wire the data-* autocomplete on the row's KEY input. Shared
        // helper from contextual-complex/data-attr-picker.js — opens
        // a dropdown of catalog entries when the user types "data-",
        // and renders a smart widget under the value when the key
        // resolves. No-op if the helper failed to load.
        if (window.QSComplexWizard && window.QSComplexWizard.attachDataAttrPicker) {
            window.QSComplexWizard.attachDataAttrPicker(keyInput, valueInput);
        }

        return row;
    }

    // Add another param
    addAnotherParamBtn?.addEventListener('click', function() {
        if (!addCustomParamsList) return;
        sidebarAddCustomParamsCount++;
        const row = _renderCustomParamRow({ index: sidebarAddCustomParamsCount });
        addCustomParamsList.appendChild(row);
    });
    
    // Cancel button — in edit mode, prompt if the form has unsaved changes.
    addCancelBtn?.addEventListener('click', async function() {
        if (_isEditModeDirty()) {
            const msg = QSPanelWords.t('preview.discardUnsavedParams');
            if (!(await QuickSiteUtils.confirm(msg, { type: 'danger', confirmText: QSPanelWords.t('preview.discard') }))) return;
        }
        hideSidebarAddForm();
    });
    
    // Confirm button - add the element (or save params, in edit mode)
    addConfirmBtn?.addEventListener('click', async function() {
        if (selectedStruct == null || selectedNode == null) {
            showToast(QSPanelWords.t('preview.selectNodeFirst'), 'warning');
            return;
        }

        // Edit Params: collect form → diff against snapshot → editNode.
        if (contextualAddMode === 'edit') {
            try {
                await editParamsNode();
            } catch (error) {
                console.error('[Preview] Edit params error:', error);
                showToast(error.message || QSPanelWords.t('preview.editParamsError'), 'error');
            }
            return;
        }

        try {
            if (sidebarAddNodeType === 'tag') {
                await addTagNode();
            } else if (sidebarAddNodeType === 'component') {
                await addComponentNode();
            } else if (sidebarAddNodeType === 'snippet') {
                await addSnippetNode();
            } else if (sidebarAddNodeType === 'complex') {
                await addComplexNode();
            } else if (sidebarAddNodeType === 'text') {
                await addTextNode();
            } else if (sidebarAddNodeType === 'simple') {
                await PreviewSimpleAdd.submit();
            }
        } catch (error) {
            console.error('[Preview] Add node error:', error);
            showToast(error.message || QSPanelWords.t('preview.addElementFailed'), 'error');
        }
    });

    // Save & add another — same add, kept open for the next one. Hidden for
    // Text + Snippet types (updateSidebarAddNodeTypeUI manages visibility).
    addConfirmAnotherBtn?.addEventListener('click', async function() {
        if (selectedStruct == null || selectedNode == null) {
            showToast(QSPanelWords.t('preview.selectNodeFirst'), 'warning');
            return;
        }
        if (contextualAddMode === 'edit') return;
        addAnotherMode = true;
        try {
            if (sidebarAddNodeType === 'tag') {
                await addTagNode();
            } else if (sidebarAddNodeType === 'component') {
                await addComponentNode();
            } else if (sidebarAddNodeType === 'complex') {
                await addComplexNode();
            }
        } catch (error) {
            console.error('[Preview] Add another error:', error);
            showToast(error.message || QSPanelWords.t('preview.addElementFailed'), 'error');
        } finally {
            addAnotherMode = false;
        }
    });

    // Live ID validation — flag an invalid id on blur, clear it as the user
    // corrects it. The hard block still fires at add time in addTagNode.
    addIdInput?.addEventListener('blur', function() {
        const v = (addIdInput.value || '').trim();
        addIdInput.classList.toggle('admin-input--error', !!v && !_isValidElementId(v));
    });
    addIdInput?.addEventListener('input', function() {
        if (!addIdInput.classList.contains('admin-input--error')) return;
        const v = (addIdInput.value || '').trim();
        if (!v || _isValidElementId(v)) addIdInput.classList.remove('admin-input--error');
    });

    /**
     * Save the currently-active Complex Element wizard.
     * The controller (returned by the kind's renderWizard) drives
     * validation + config extraction; we just dispatch to the
     * addComplexElement command and refresh the preview iframe.
     */
    async function addComplexNode() {
        if (!addComplexKindSelect || !addComplexController) {
            showToast(QSPanelWords.t('preview.complexPickKindFirst'), 'warning');
            return;
        }
        const kind = addComplexKindSelect.value;
        if (!kind) {
            showToast(QSPanelWords.t('preview.complexPickKindFirst'), 'warning');
            return;
        }

        // Validate via controller (returns a string error or null/undef on OK).
        if (typeof addComplexController.validate === 'function') {
            const err = addComplexController.validate();
            if (err) { showToast(err, 'error'); return; }
        }

        // Optional pre-submit hook: lets a wizard
        // perform multi-step setup (route + resolver creation, etc.)
        // BEFORE the final addComplexElement call. Used by oauth-button
        // to call addRoute x2 + setRouteResolver x2 before the button
        // is spliced in. Wizards that don't need it omit the hook.
        if (typeof addComplexController.preSubmit === 'function') {
            let setupFailure = null;
            try {
                await addComplexController.preSubmit();
            } catch (e) {
                setupFailure = e;
            }
            // What the setup made before a failure stays, so the list is read either way. A target
            // that turns out to be gone has been left, and the element has nowhere to go.
            const moved = await refreshEditList();
            if (setupFailure) {
                showToast(setupFailure.message ? setupFailure.message : QSPanelWords.t('preview.complexSetupFailed'), 'error');
                return;
            }
            if (moved) return;
        }

        const config = (typeof addComplexController.getConfig === 'function')
            ? addComplexController.getConfig() : {};

        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }

        // The targetNodeId / structType plumbing mirrors addNode/addComponentNode: the
        // structure's own root is selected as '' (its data-qs-node) and sent as 'root'.
        const isRoot = !selectedNode && selectedNode !== 0;
        const targetNodeId = isRoot ? 'root' : String(selectedNode);
        const position = isRoot ? 'inside' : getAddPosition();
        const body = {
            kind: kind,
            config: config,
            structType: structInfo.type,
            pageName: structInfo.name || undefined,
            targetNodeId: targetNodeId,
            position: position
        };

        // The shared helper joins the URL and sends the auth header.
        const result = await QuickSiteAdmin.apiRequest('addComplexElement', 'POST', body);
        if (!result || !result.ok) {
            const msg = (result && result.data && result.data.message)
                || QSPanelWords.t('preview.complexAddFailed');
            throw new Error(msg);
        }
        const data = result.data || {};
        showToast(data.message || QSPanelWords.t('preview.complexAdded'), 'success');
        // Save & add another (complex): keep the wizard as-is (prefilled with
        // the same config) so the next instance starts from these values.
        const keptOpen = _finalizeAdd(null);
        if (!keptOpen && typeof reloadPreview === 'function') reloadPreview();
        broadcastStructureChanged();
    }
    
    // Add tag node
    async function addTagNode() {
        const tag = addTagSelect?.value || 'div';
        const position = getAddPosition();
        // Flush any uncommitted text from the class combobox before reading
        if (classCombobox) classCombobox.flush();
        const classes = addClassInput?.value?.trim() || '';

        // A group with nothing chosen, or a field's problem (a duplicate, a width missing…), is never saved: said there and here
        const fieldProblem = _paramFieldProblem();
        if (fieldProblem) throw new Error(fieldProblem);
        
        // Collect mandatory params (a field's own inputs, and a group's row not chosen, left out)
        const params = {};
        _mandatoryParamInputs().forEach(field => {
            const paramName = field.id.replace('add-mandatory-', '');
            if (field.value) params[paramName] = field.value;
        });

        // Input wizard: client-side validate that 'name' is
        // present unless the chosen type is in the exempt set.
        if (tag === 'input') {
            const typeVal = params.type || '';
            const nameVal = (params.name || '').trim();
            if (!INPUT_TYPES_NAME_EXEMPT.has(typeVal) && nameVal === '') {
                const nameInput = addMandatoryParamsContainer?.querySelector('#add-mandatory-name');
                if (nameInput) {
                    nameInput.classList.add('admin-input--error');
                    nameInput.focus();
                }
                throw new Error(QSPanelWords.t('preview.nameRequiredForInput'));
            }
        }
        
        // Translation-key params (alt / title). Optional — an empty picker
        // writes nothing, so an image with no alt gets no alt attribute at all
        // rather than an empty one nobody chose.
        Object.assign(params, _collectTransKeyParams());

        // Collect custom params
        const customRows = addCustomParamsList?.querySelectorAll('.preview-contextual-form__param-row--custom') || [];
        customRows.forEach(row => {
            const key = row.querySelector('.preview-contextual-form__param-key')?.value?.trim();
            const value = row.querySelector('.preview-contextual-form__param-value')?.value?.trim();
            if (key && value) params[key] = value;
        });

        // The fields (a srcset's), read last
        Object.assign(params, _paramFieldParams());

        // Optional ID — dedicated convenience field (wins over any Advanced
        // custom `id` row). Validated against CSS-safe HTML id rules.
        const idVal = (addIdInput?.value || '').trim();
        if (addIdInput) addIdInput.classList.remove('admin-input--error');
        if (idVal) {
            if (!_isValidElementId(idVal)) {
                if (addIdInput) {
                    addIdInput.classList.add('admin-input--error');
                    addIdInput.focus();
                }
                throw new Error(QSPanelWords.t('preview.invalidId'));
            }
            params.id = idVal;
        }

        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }
        
        const isRoot = !selectedNode && selectedNode !== 0;
        const requestData = {
            type: structInfo.type,
            targetNodeId: isRoot ? 'root' : String(selectedNode),
            tag: tag,
            position: isRoot ? 'inside' : position
        };
        if (structInfo.name) requestData.name = structInfo.name;
        if (classes) requestData.params = { class: classes, ...params };
        else if (Object.keys(params).length > 0) requestData.params = params;
        
        const response = await QuickSiteAdmin.apiRequest('addNode', 'POST', requestData);
        
        if (response.ok) {
            const data = response.data?.data || {};
            
            // Save selection values BEFORE hiding form (in case hiding clears them)
            const targetStruct = selectedStruct;
            const targetNode = selectedNode;
            
            showToast(QSPanelWords.t('preview.nodeAdded'), 'success');

            // Save & add another (tag): keep the form open, clear instance
            // fields (id + mandatory + custom params), keep tag + class. A
            // plain reload preserves the target so the next add lands here too.
            const keptOpen = _finalizeAdd(function() {
                if (addIdInput) { addIdInput.value = ''; addIdInput.classList.remove('admin-input--error'); }
                _mandatoryParamInputs().forEach(f => { f.value = ''; });
                // A field empty and whole again, never blanked input by input
                _resetParamFields();
                // alt / title are per-instance like the mandatory fields — the
                // next image is not the same image.
                Object.keys(transKeyPickers).forEach(k => transKeyPickers[k].setValue(''));
                if (addCustomParamsList) addCustomParamsList.replaceChildren();
                if (addIdInput) addIdInput.focus();
            });
            if (!keptOpen) {
                // Live DOM update if HTML returned (auto-selects the new node). An add
                // inside an element holding text moves that text into the new element
                // (movedTextKeyChildren): the iframe takes it out of the target as it inserts.
                if (data.html && targetNode) {
                    sendToIframe('insertNode', {
                        struct: targetStruct,
                        targetNode: targetNode,
                        position: position,
                        html: data.html,
                        newNodeId: data.newNodeId,
                        movedText: data.movedTextKeyChildren || 0
                    });
                } else {
                    reloadPreview();
                }
            }
            broadcastStructureChanged();
        } else {
            throw new Error(response.data?.message || QSPanelWords.t('preview.addElementFailed'));
        }
    }

    /**
     * Add a bare text node ({textKey:...}) to the selected element.
     * Posts to addNode with nodeKind='text'. RAW writes "__RAW__"+value into
     * the textKey; Translation key generates a fresh key and writes the value
     * as its translation in the current language. The resulting text node is
     * editable inline in Text mode.
     */
    async function addTextNode() {
        const isRaw = document.querySelector('input[name="add-text-type"]:checked')?.value === 'raw';

        // Per-type input + request shape:
        //   raw → text input → backend builds {textKey: '__RAW__'+textValue}.
        //   key → textKey picker → picker handles key creation + translation
        //         write itself (setTranslationKeys), we just send the chosen
        //         textKey to addNode which inserts {textKey: ...}.
        let textPayload;
        if (isRaw) {
            const value = addTextValueInput?.value || '';
            if (value.trim() === '') {
                showToast(QSPanelWords.t('preview.textValueRequired'), 'warning');
                if (addTextValueInput) addTextValueInput.focus();
                return;
            }
            textPayload = { textRaw: true, textValue: value };
        } else {
            const pickedKey = (addTextKeyPickerInst && addTextKeyPickerInst.getValue()) || '';
            if (!pickedKey) {
                showToast(QSPanelWords.t('preview.textKeyRequired'), 'warning');
                return;
            }
            textPayload = { textRaw: false, textKey: pickedKey };
        }

        // The Text tab's Inside adds at the end of the element: a sentence grows in the order
        // it is written (the root keeps its own rule, first).
        const picked = getAddPosition();
        const position = picked === 'inside' ? 'inside-end' : picked;
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }
        const isRoot = !selectedNode && selectedNode !== 0;

        const requestData = Object.assign({
            type: structInfo.type,
            targetNodeId: isRoot ? 'root' : String(selectedNode),
            nodeKind: 'text',
            position: isRoot ? 'inside' : position
        }, textPayload);
        if (structInfo.name) requestData.name = structInfo.name;

        const response = await QuickSiteAdmin.apiRequest('addNode', 'POST', requestData);

        if (response.ok) {
            const data = response.data?.data || {};
            const targetStruct = selectedStruct;
            const targetNode = selectedNode;

            showToast(QSPanelWords.t('preview.textAdded'), 'success');
            hideSidebarAddForm();

            if (data.html && targetNode) {
                sendToIframe('insertNode', {
                    struct: targetStruct,
                    targetNode: targetNode,
                    position: position,
                    html: data.html,
                    newNodeId: data.newNodeId
                });
            } else {
                reloadPreview();
            }
            broadcastStructureChanged();
        } else {
            throw new Error(response.data?.message || QSPanelWords.t('preview.textAddFailed'));
        }
    }

    // Add component node
    async function addComponentNode() {
        const componentName = addComponentSelect?.value;
        if (!componentName) {
            showToast(QSPanelWords.t('preview.selectComponent'), 'warning');
            return;
        }
        
        const position = getAddPosition();
        
        // Collect component variables if any
        const vars = {};
        const varInputs = addComponentVarsContainer?.querySelectorAll('input[data-var-name], select[data-var-name]') || [];
        varInputs.forEach(input => {
            const varName = input.dataset.varName;
            if (input.value) vars[varName] = input.value;
        });
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }
        
        const isRoot = !selectedNode && selectedNode !== 0;
        const requestData = {
            type: structInfo.type,
            targetNodeId: isRoot ? 'root' : String(selectedNode),
            component: componentName,
            position: isRoot ? 'inside' : position
        };
        if (structInfo.name) requestData.name = structInfo.name;
        if (Object.keys(vars).length > 0) requestData.data = vars;

        // Collect any "Advanced — custom params" rows the user added.
        // These become call-site params on the {component, data, params}
        // node and get merged into the rendered template root by the
        // renderer (class/style concatenate, everything else overrides).
        // Typical use: data-list-template="true" + style="display:none"
        // to mark a hidden template instance for componentList binding.
        const callSiteParams = {};
        const customRows = addCustomParamsList?.querySelectorAll('.preview-contextual-form__param-row--custom') || [];
        customRows.forEach(row => {
            const key   = row.querySelector('.preview-contextual-form__param-key')?.value?.trim();
            const value = row.querySelector('.preview-contextual-form__param-value')?.value?.trim();
            if (key && value !== undefined && value !== '') callSiteParams[key] = value;
        });
        if (Object.keys(callSiteParams).length > 0) requestData.params = callSiteParams;

        const response = await QuickSiteAdmin.apiRequest('addComponentToNode', 'POST', requestData);
        
        if (response.ok) {
            const data = response.data?.data || {};
            
            // Save selection values BEFORE hiding form (in case hiding clears them)
            const targetStruct = selectedStruct;
            const targetNode = selectedNode;
            
            showToast(QSPanelWords.t('preview.componentAdded'), 'success');

            // Save & add another (component): keep the variable inputs as-is
            // (prefilled with the same values) for the next instance.
            const keptOpen = _finalizeAdd(null);
            if (!keptOpen) {
                // Live DOM update if HTML returned.
                if (data.html && targetNode) {
                    sendToIframe('insertNode', {
                        struct: targetStruct,
                        targetNode: targetNode,
                        position: position,
                        html: data.html,
                        newNodeId: data.nodeId
                    });
                } else {
                    reloadPreview();
                }
            }
            broadcastStructureChanged();
        } else {
            throw new Error(response.data?.message || QSPanelWords.t('preview.componentAddFailed'));
        }
    }
    
    // Add snippet node (inserts snippet structure as nodes)
    async function addSnippetNode() {
        if (!selectedSnippetId || !selectedSnippetData) {
            showToast(QSPanelWords.t('preview.selectSnippet'), 'warning');
            return;
        }

        // Check CSS action radio selection — inject CSS before inserting if needed
        const cssAction = getSelectedCssAction();
        let cssWasInjected = false;
        if (cssAction === 'missing' || cssAction === 'replace') {
            const confirmed = await QuickSiteUtils.confirm(QSPanelWords.t('preview.snippetCssConfirm'), {
                type: 'danger',
                confirmText: QSPanelWords.t('preview.snippetCssConfirmAction')
            });
            if (!confirmed) return;

            // Perform CSS injection first
            const injected = await injectSnippetCss(cssAction);
            if (!injected) return; // injection failed, abort
            cssWasInjected = true;
        }
        
        const position = getAddPosition();
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }
        
        // Use the new insertSnippet command which handles full structure + translations
        const isRoot = !selectedNode && selectedNode !== 0;
        const requestData = {
            type: structInfo.type,
            targetNodeId: isRoot ? 'root' : String(selectedNode),
            position: isRoot ? 'inside' : position,
            snippetId: selectedSnippetId
        };
        if (structInfo.name) requestData.name = structInfo.name;
        
        const response = await QuickSiteAdmin.apiRequest('insertSnippet', 'POST', requestData);
        
        if (response.ok) {
            const data = response.data?.data || {};
            
            // Save selection values BEFORE hiding form
            const targetStruct = selectedStruct;
            const targetNode = selectedNode;
            
            showToast(data.translationsAdded > 0
                ? QSPanelWords.count('preview.snippetAddedTranslations', data.translationsAdded)
                : QSPanelWords.t('preview.snippetAdded'), 'success');
            hideSidebarAddForm();
            
            // The live insert places one element; a snippet with several roots reloads.
            const severalRoots = Array.isArray(data.newNodeIds) && data.newNodeIds.length > 1;

            // If CSS was injected, force full reload so the iframe picks up the new stylesheet
            if (cssWasInjected) {
                console.log('[Preview] CSS was injected, forcing full reload.');
                reloadPreview();
            } else if (data.html && targetNode && !severalRoots) {
                console.log('[Preview] Live DOM insert snippet:', { struct: targetStruct, targetNode, position, newNodeId: data.newNodeId });
                sendToIframe('insertNode', {
                    struct: targetStruct,
                    targetNode: targetNode,
                    position: position,
                    html: data.html,
                    newNodeId: data.newNodeId
                });
            } else {
                console.log('[Preview] No snippet HTML returned, reloading preview. Data:', data);
                reloadPreview();
            }
            broadcastStructureChanged();
        } else {
            throw new Error(response.data?.message || QSPanelWords.t('preview.snippetInsertFailed'));
        }
    }
    
    // ========== Save as Snippet / Save as Component (shared) ==========

    // State for save snippet form
    let saveSnippetStructureData = null;
    let saveSnippetTranslationsData = null;

    // State for save component form
    let saveComponentStructureData = null;

    /**
     * Extract the selected node's structure from the current selection.
     * Shared helper for Save as Snippet, Save as Component, and Edit Params.
     * Returns { nodeData, structInfo } or throws on error.
     *
     * opts.allowRoot: Save-Snippet / Save-Component refuse to operate on
     * the page root (a snippet of the whole page is meaningless), but
     * Edit Params legitimately edits the root node's class + custom
     * attrs — pass { allowRoot: true } to relax the guard.
     */
    async function extractSelectedNodeStructure(opts) {
        const allowRoot = !!(opts && opts.allowRoot);
        // Must have selection
        if (selectedStruct == null || selectedNode == null) {
            throw new Error(QSPanelWords.t('preview.selectNodeFirst'));
        }
        if (!allowRoot && selectedNode === '' && currentEditType !== 'component') {
            throw new Error(QSPanelWords.t('preview.cannotSaveRoot'));
        }

        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error(QSPanelWords.t('preview.invalidStructureType'));
        }

        // Fetch full structure
        const urlParams = [structInfo.type];
        if (structInfo.name) {
            urlParams.push(structInfo.name);
        }
        const response = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
        if (!response.ok || !response.data?.data?.structure) {
            throw new Error(QSPanelWords.t('preview.structureReadFailed'));
        }

        const structure = response.data.data.structure;
        const nodeData = selectedNode === '' ? structure : navigateToNode(structure, selectedNode);
        if (!nodeData) {
            throw new Error(QSPanelWords.t('preview.nodeNotFoundInStructure'));
        }

        return { nodeData: JSON.parse(JSON.stringify(nodeData)), structInfo };
    }

    /** Show a node's structure line (`<tag class="…">` and its child count) in `container`, as text. */
    function showStructurePreview(container, nodeData) {
        const tag = nodeData.tag || nodeData.component || '?';
        const classes = nodeData.params?.class || '';
        const childCount = (nodeData.children || []).length;
        container.replaceChildren(QSDom.el('code', { text: '<' + tag + (classes ? ' class="' + classes + '"' : '') + '>' }));
        if (childCount > 0) {
            container.append(' ', QSDom.el('small', { text: QSPanelWords.count('preview.structureChildCount', childCount) }));
        }
    }

    // Show save snippet form
    async function showSaveSnippetForm() {
        if (!saveSnippetForm) return;

        try {
            const { nodeData } = await extractSelectedNodeStructure();

            saveSnippetStructureData = nodeData;

            // Hide other form if open
            if (saveComponentForm) saveComponentForm.style.display = 'none';

            // Show the form
            const selectInfo = document.getElementById('contextual-select-info');
            if (selectInfo) selectInfo.style.display = 'none';
            saveSnippetForm.style.display = '';
            syncSaveFormFills();

            // Reset form fields
            if (saveSnippetName) saveSnippetName.value = '';
            if (saveSnippetId) saveSnippetId.value = '';
            if (saveSnippetCategory) saveSnippetCategory.value = 'other';
            if (saveSnippetDesc) saveSnippetDesc.value = '';

            // Show structure preview
            if (saveSnippetPreview) {
                showStructurePreview(saveSnippetPreview, nodeData);
            }

            if (saveSnippetName) saveSnippetName.focus();

        } catch (error) {
            console.error('[Preview] Save snippet form error:', error);
            showToast(error.message || QSPanelWords.t('preview.elementDataLoadFailed'), 'error');
        }
    }
    
    // Hide save snippet form
    function hideSaveSnippetForm() {
        if (!saveSnippetForm) return;
        
        saveSnippetForm.style.display = 'none';
        syncSaveFormFills();
        const selectInfo = document.getElementById('contextual-select-info');
        if (selectInfo) selectInfo.style.display = '';

        // Clear state
        saveSnippetStructureData = null;
        saveSnippetTranslationsData = null;
    }

    // ========== Save as Component ==========

    // Show save component form
    async function showSaveComponentForm() {
        if (!saveComponentForm) return;

        try {
            const { nodeData } = await extractSelectedNodeStructure();

            saveComponentStructureData = nodeData;

            // Hide other form if open
            if (saveSnippetForm) saveSnippetForm.style.display = 'none';

            // Show the form, hide the info panel
            const selectInfo = document.getElementById('contextual-select-info');
            if (selectInfo) selectInfo.style.display = 'none';
            saveComponentForm.style.display = '';
            syncSaveFormFills();

            // Reset form fields
            if (saveComponentName) saveComponentName.value = '';

            // Show structure preview
            if (saveComponentPreview) {
                showStructurePreview(saveComponentPreview, nodeData);
            }

            if (saveComponentName) saveComponentName.focus();

        } catch (error) {
            console.error('[Preview] Save component form error:', error);
            showToast(error.message || QSPanelWords.t('preview.elementDataLoadFailed'), 'error');
        }
    }

    // Hide save component form
    function hideSaveComponentForm() {
        if (!saveComponentForm) return;

        saveComponentForm.style.display = 'none';
        syncSaveFormFills();
        const selectInfo = document.getElementById('contextual-select-info');
        if (selectInfo) selectInfo.style.display = '';

        saveComponentStructureData = null;
    }

    /**
     * Deep clone a node structure for component use.
     * Converts real textKeys to {{placeholder}} variables.
     * Keeps __RAW__ textKeys as-is, keeps component references as-is.
     * Returns the cloned structure ready for editStructure API.
     */
    function deepCloneForComponent(node, usedNames = {}) {
        if (!node || typeof node !== 'object') return node;

        // Preserve component references as-is (nested components)
        if (node.component) {
            return JSON.parse(JSON.stringify(node));
        }

        const clone = {};

        for (const key of Object.keys(node)) {
            if (key.startsWith('data-qs-')) continue;

            if (key === 'textKey') {
                if (node.textKey && !node.textKey.startsWith('__RAW__')) {
                    // Convert real textKey to {{placeholder}}
                    const varName = deriveVarName(node.textKey, usedNames);
                    clone.textKey = '{{' + varName + '}}';
                } else {
                    clone.textKey = node.textKey;
                }
            } else if (key === 'altKey') {
                if (node.altKey && !node.altKey.startsWith('__RAW__')) {
                    const varName = deriveVarName(node.altKey, usedNames, 'alt');
                    clone.altKey = '{{' + varName + '}}';
                } else {
                    clone.altKey = node.altKey;
                }
            } else if (key === 'children' && Array.isArray(node.children)) {
                clone.children = node.children.map(child => deepCloneForComponent(child, usedNames));
            } else if (key === 'params' && typeof node.params === 'object') {
                clone.params = {};
                for (const [pKey, pVal] of Object.entries(node.params)) {
                    if (pKey.startsWith('data-qs-')) continue;
                    // Convert {{textKey:xxx}} param placeholders to {{varName}}
                    if (typeof pVal === 'string' && pVal.includes('{{textKey:')) {
                        const match = pVal.match(/\{\{textKey:([^}]+)\}\}/);
                        if (match && match[1]) {
                            const varName = deriveVarName(match[1], usedNames, pKey);
                            clone.params[pKey] = pVal.replace(match[0], '{{' + varName + '}}');
                        } else {
                            clone.params[pKey] = pVal;
                        }
                    } else {
                        clone.params[pKey] = pVal;
                    }
                }
            } else if (typeof node[key] === 'object' && node[key] !== null) {
                clone[key] = JSON.parse(JSON.stringify(node[key]));
            } else {
                clone[key] = node[key];
            }
        }

        return clone;
    }

    /**
     * Derive a variable name from a translation key path.
     * e.g. "home.hero.title" → "title", "home.hero.title" (conflict) → "title2"
     * hint param provides context when the key itself is not descriptive.
     */
    function deriveVarName(keyPath, usedNames, hint) {
        // Take the last segment of the dot-path
        const segments = keyPath.split('.');
        let base = segments[segments.length - 1] || hint || 'var';

        // Clean: keep only alphanumeric, hyphens, underscores
        base = base.replace(/[^a-zA-Z0-9_-]/g, '').replace(/^[^a-zA-Z]/, 'v');
        if (!base) base = hint || 'var';

        let candidate = base;
        let counter = 2;
        while (usedNames[candidate]) {
            candidate = base + counter++;
        }
        usedNames[candidate] = true;
        return candidate;
    }

    // Submit save component form
    async function submitSaveComponent() {
        if (!saveComponentStructureData) {
            showToast(QSPanelWords.t('preview.noStructureData'), 'error');
            return;
        }

        const name = saveComponentName?.value?.trim();
        if (!name) {
            showToast(QSPanelWords.t('preview.componentNameRequired'), 'warning');
            saveComponentName?.focus();
            return;
        }

        if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(name)) {
            showToast(QSPanelWords.t('preview.componentNameInvalid'), 'warning');
            saveComponentName?.focus();
            return;
        }

        showToast(QSPanelWords.t('common.saving'), 'info');

        try {
            // Clone structure and convert textKeys to {{placeholder}} variables
            const componentStructure = deepCloneForComponent(saveComponentStructureData);

            console.log('[Preview] Creating component:', { name, structure: componentStructure });

            // Use editStructure API (same as "Create Component" button)
            const response = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: name,
                structure: componentStructure
            });

            if (response.ok) {
                showToast(QSPanelWords.t('preview.componentSaved'), 'success');

                // Add to component dropdown in navigation if not already there
                if (targetSelect && !targetSelect.querySelector('option[value="component:' + name + '"]')) {
                    addEditListComponent(name);
                }

                // Also add to the component select in add-node modal if present
                const addComponentSelect = document.getElementById('add-node-component');
                if (addComponentSelect && !addComponentSelect.querySelector('option[value="' + name + '"]')) {
                    const opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name;
                    addComponentSelect.appendChild(opt);
                }

                // Invalidate cached component list so add-form picks up the new component
                componentsLoaded = false;

                hideSaveComponentForm();
            } else {
                throw new Error(response.data?.message || QSPanelWords.t('preview.componentSaveFailed'));
            }
        } catch (error) {
            console.error('[Preview] Save component error:', error);
            showToast(error.message || QSPanelWords.t('common.saveFailed'), 'error');
        }
    }
    
    // ==================== Variables Panel (Component-only) ====================
    
    // Stored full component structure when Variables panel is open
    let variablesPanelStructure = null;
    // The struct it was read from, which the frame names a card's node by
    let variablesPanelStruct = null;
    // Cached list of enum variable names (keys from __enums__)
    let variablesPanelEnumVarNames = [];
    
    
    /**
     * Detect textKey type from its value
     * @returns {'translation'|'variable'|'raw'}
     */
    function detectTextKeyType(textKey) {
        if (!textKey) return 'translation';
        if (textKey.startsWith('{{') && textKey.endsWith('}}')) {
            // Check if it's an enum-derived variable
            const varName = textKey.slice(2, -2);
            if (variablesPanelEnumVarNames.includes(varName)) return 'enum';
            return 'variable';
        }
        if (textKey.startsWith('__RAW__')) return 'raw';
        return 'translation';
    }
    
    /**
     * Get the editable value from a textKey based on its type
     */
    function textKeyToEditValue(textKey, type) {
        if (!textKey) return '';
        if (type === 'variable' || type === 'enum') return textKey.slice(2, -2); // strip {{ }}
        if (type === 'raw') return textKey.slice(7); // strip __RAW__
        return textKey; // translation key as-is
    }
    
    /**
     * Convert edited value back to textKey based on type
     */
    function editValueToTextKey(value, type) {
        if (!value) return '';
        if (type === 'variable' || type === 'enum') return '{{' + value + '}}';
        if (type === 'raw') return '__RAW__' + value;
        return value; // translation key as-is
    }
    
    /**
     * Navigate a structure tree to find a node at a given dot-separated path
     * @param {object} structure - root structure object
     * @param {string} nodeId - dot-separated path like '0.1.2' or '' for root
     * @returns {object|null} the node at that path
     */
    function getNodeByPath(structure, nodeId) {
        if (nodeId === '' || nodeId === null || nodeId === undefined) return structure;
        
        const parts = nodeId.split('.').map(Number);
        let current = structure;
        
        for (let i = 0; i < parts.length; i++) {
            if (i === 0) {
                if (Array.isArray(current)) {
                    current = current[parts[i]];
                } else if (current.children && Array.isArray(current.children)) {
                    current = current.children[parts[i]];
                } else {
                    return null;
                }
            } else {
                if (current && current.children && Array.isArray(current.children)) {
                    current = current.children[parts[i]];
                } else {
                    return null;
                }
            }
            if (!current) return null;
        }
        
        return current;
    }
    
    /**
     * Collect direct text key items from a node: its own textKey + immediate children's textKeys
     * @returns Array of { nodeId, tag, textKey, path, isSelf }
     */
    function collectDirectTextKeys(node, baseNodeId) {
        const results = [];
        if (!node || typeof node !== 'object') return results;
        
        const tag = node.tag || node.component || '?';
        
        // Node's own textKey
        if (node.textKey) {
            results.push({ nodeId: baseNodeId, tag, textKey: node.textKey, path: tag, isSelf: true });
        }
        
        // Immediate children's textKeys (not recursive)
        if (node.children && Array.isArray(node.children)) {
            node.children.forEach((child, i) => {
                if (child && child.textKey) {
                    const childId = baseNodeId ? baseNodeId + '.' + i : String(i);
                    const childTag = child.tag || child.component || '?';
                    results.push({ nodeId: childId, tag: childTag, textKey: child.textKey, path: tag + ' > ' + childTag, isSelf: false });
                }
            });
        }
        
        return results;
    }
    
    /**
     * Collect params from a node, filtering out data-qs-* editor attributes
     * @returns Array of { paramName, value, editValue, isVariable }
     */
    function collectNodeParams(node) {
        const results = [];
        if (!node || !node.params || typeof node.params !== 'object') return results;
        
        for (const [paramName, value] of Object.entries(node.params)) {
            if (paramName.startsWith('data-qs-')) continue;
            
            const strValue = String(value);
            // Check if the entire value is a single {{variable}}
            const varMatch = strValue.match(/^\{\{([^}]+)\}\}$/);
            const isVariable = !!varMatch;
            const editValue = isVariable ? varMatch[1] : strValue;
            
            results.push({ paramName, value: strValue, editValue, isVariable });
        }
        
        return results;
    }
    
    /**
     * Update a textKey at a specific nodeId path within the structure (in-place)
     * @returns boolean indicating success
     */
    function updateTextKeyInStructure(structure, nodeId, newTextKey) {
        // Special case: root node itself has a textKey
        if (nodeId === '') {
            structure.textKey = newTextKey;
            return true;
        }
        
        const parts = nodeId.split('.').map(Number);
        let current = structure;
        
        for (let i = 0; i < parts.length; i++) {
            if (i === 0) {
                // First index: for components (object with children), go into children
                if (Array.isArray(current)) {
                    current = current[parts[i]];
                } else if (current.children && Array.isArray(current.children)) {
                    current = current.children[parts[i]];
                } else {
                    return false;
                }
            } else {
                if (current && current.children && Array.isArray(current.children)) {
                    current = current.children[parts[i]];
                } else {
                    return false;
                }
            }
            if (!current) return false;
        }
        
        current.textKey = newTextKey;
        return true;
    }
    
    /**
     * Show the Variables panel (context-sensitive to selected node)
     */
    async function showVariablesPanel() {
        if (!variablesPanel || currentEditType !== 'component') return;
        
        // Hide action buttons but keep nav arrows and select-info visible
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = 'none';
        // Also hide snippet/component forms if open
        if (saveSnippetForm) saveSnippetForm.style.display = 'none';
        if (saveComponentForm) saveComponentForm.style.display = 'none';
        syncSaveFormFills();

        variablesPanel.style.display = '';
        
        // Show loading
        if (variablesPanelLoading) variablesPanelLoading.style.display = '';
        if (variablesPanelEmpty) variablesPanelEmpty.style.display = 'none';
        if (variablesPanelCards) variablesPanelCards.replaceChildren();
        if (variablesPanelFooter) variablesPanelFooter.style.display = 'none';
        
        try {
            // The component's structure; the translation-key fields read the site's words themselves when they
            // open (the shared text-key picker, through QSSiteWords).
            variablesPanelStruct = selectedStruct || ('component-' + currentEditName);
            const structInfo = parseStruct(variablesPanelStruct);
            if (!structInfo) throw new Error(QSPanelWords.t('preview.invalidStruct'));
            const urlParams = [structInfo.type];
            if (structInfo.name) urlParams.push(structInfo.name);
            const resp = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
            if (!resp.ok || !resp.data?.data?.structure) throw new Error(QSPanelWords.t('preview.structureReadFailed'));
            variablesPanelStructure = JSON.parse(JSON.stringify(resp.data.data.structure));
            
            // Extract available enum variable names from __enums__
            variablesPanelEnumVarNames = [];
            const enums = variablesPanelStructure.__enums__;
            if (enums && typeof enums === 'object') {
                variablesPanelEnumVarNames = Object.keys(enums);
            }
            

            
            // Navigate to the selected node (fallback to root)
            const nodeId = (selectedNode != null) ? selectedNode : '';
            const targetNode = getNodeByPath(variablesPanelStructure, nodeId);
            if (!targetNode) throw new Error(QSPanelWords.t('preview.nodeNotFoundNamed', { node: nodeId }));
            
            // Collect direct textKey items and params for this node
            const textKeyNodes = collectDirectTextKeys(targetNode, nodeId);
            const paramNodes = collectNodeParams(targetNode);
            
            // Hide loading
            if (variablesPanelLoading) variablesPanelLoading.style.display = 'none';
            
            if (textKeyNodes.length === 0 && paramNodes.length === 0) {
                // Show empty state
                if (variablesPanelEmpty) variablesPanelEmpty.style.display = '';
            } else {
                // Render sections
                renderVariableSections(textKeyNodes, paramNodes);
            }
            
            // Show footer with selected node info
            if (variablesPanelFooter && variablesPanelFooterText) {
                const tag = targetNode.tag || targetNode.component || '?';
                const display = nodeId === '' ? QSPanelWords.t('preview.variablesNodeRoot', { tag: tag }) : tag + ' [' + nodeId + ']';
                variablesPanelFooterText.textContent = QSPanelWords.t('preview.variablesNodeInfo', { name: display });
                variablesPanelFooter.style.display = '';
            }
            
        } catch (error) {
            console.error('[Preview] Variables panel error:', error);
            if (variablesPanelLoading) variablesPanelLoading.style.display = 'none';
            if (variablesPanelCards) {
                variablesPanelCards.replaceChildren(_renderPanelError(error));
            }
        }
    }
    
    /**
     * Build the value editor element (select for translation, input for variable/raw)
     * Returns { container, getValue }
     */
    function buildValueEditor(type, currentValue) {
        const wrapper = document.createElement('div');
        wrapper.className = 'preview-variable-card__editor';

        if (type === 'translation') {
            // The shared text-key picker: the site's keys from the one list (QSSiteWords), each language's words
            // under its language, and the panel's one Create (the word in the language being edited only).
            const picker = QSComplexWizard.createTextKeyPicker({
                container: wrapper,
                value: currentValue || '',
                placeholder: QSPanelWords.t('preview.variablesPlaceholderTranslation'),
            });
            return { container: wrapper, getValue: () => picker.getValue().trim(), el: picker.input };
        }

        if (type === 'enum') {
            // Select dropdown populated from __enums__ variable names
            const sel = document.createElement('select');
            sel.className = 'preview-variable-card__value-select';
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '— ' + QSPanelWords.t('preview.variablesSelectEnum') + ' —';
            sel.appendChild(emptyOpt);
            variablesPanelEnumVarNames.forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (name === currentValue) opt.selected = true;
                sel.appendChild(opt);
            });
            wrapper.appendChild(sel);
            // Hint
            const hint = document.createElement('div');
            hint.className = 'preview-variable-card__hint';
            hint.textContent = QSPanelWords.t('preview.variablesHintEnum');
            wrapper.appendChild(hint);
            return { container: wrapper, getValue: () => sel.value, el: sel };
        }

        // Input for variable / raw
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.value = currentValue;
        inp.placeholder = getPlaceholderForType(type);
        wrapper.appendChild(inp);

        // Hint text
        const hint = document.createElement('div');
        hint.className = 'preview-variable-card__hint';
        if (type === 'variable') {
            hint.textContent = QSPanelWords.t('preview.variablesHintVariable');
        } else if (type === 'raw') {
            hint.textContent = QSPanelWords.t('preview.variablesHintRaw');
        }
        wrapper.appendChild(hint);

        return { container: wrapper, getValue: () => inp.value.trim(), el: inp };
    }

    /**
     * Render both text and param variable sections
     */
    function renderVariableSections(textKeyNodes, paramNodes) {
        if (!variablesPanelCards) return;
        variablesPanelCards.replaceChildren();
        
        // Text Variables section
        if (textKeyNodes.length > 0) {
            const section = document.createElement('div');
            section.className = 'preview-variables-section';
            
            const title = document.createElement('h5');
            title.className = 'preview-variables-section__title';
            title.append(_renderSectionIcon(ICON_TEXT_VARIABLES), QSPanelWords.t('preview.variablesSectionText'));
            section.appendChild(title);
            
            renderVariableCards(textKeyNodes, section);
            variablesPanelCards.appendChild(section);
        }
        
        // Param Variables section
        if (paramNodes.length > 0) {
            const section = document.createElement('div');
            section.className = 'preview-variables-section';
            
            const title = document.createElement('h5');
            title.className = 'preview-variables-section__title';
            title.append(_renderSectionIcon(ICON_PARAM), QSPanelWords.t('preview.variablesSectionParams'));
            section.appendChild(title);
            
            renderParamCards(paramNodes, section);
            variablesPanelCards.appendChild(section);
        }
    }

    /**
     * Render variable cards for textKey nodes into a container
     */
    function renderVariableCards(textKeyNodes, container) {
        textKeyNodes.forEach(item => {
            const type = detectTextKeyType(item.textKey);
            const editValue = textKeyToEditValue(item.textKey, type);
            
            const card = document.createElement('div');
            card.className = 'preview-variable-card';
            card.dataset.nodeId = item.nodeId;
            
            // Path (clickable to select node)
            const pathEl = document.createElement('div');
            pathEl.className = 'preview-variable-card__path';
            pathEl.append(QSDom.iconEl(ICON_NODE_PATH, 0), QSDom.el('span', { text: item.path + ' [' + item.nodeId + ']' }));
            pathEl.title = QSPanelWords.t('preview.variablesClickToSelect');
            pathEl.addEventListener('click', function() {
                hideVariablesPanel();
                // Select this node in the frame, by the struct the panel read
                if (item.nodeId) {
                    sendToIframe('selectNode', { struct: variablesPanelStruct, node: item.nodeId });
                }
            });
            card.appendChild(pathEl);
            
            // Type selector
            const typeDiv = document.createElement('div');
            typeDiv.className = 'preview-variable-card__type';
            const typeSelect = document.createElement('select');
            typeSelect.append(
                _renderOption('translation', QSPanelWords.t('preview.variablesTypeTranslation'), type === 'translation'),
                _renderOption('variable', QSPanelWords.t('preview.variablesTypeVariable'), type === 'variable'),
                _renderOption('enum', QSPanelWords.t('preview.variablesTypeEnum'), type === 'enum'),
                _renderOption('raw', QSPanelWords.t('preview.variablesTypeRaw'), type === 'raw')
            );
            typeDiv.appendChild(typeSelect);
            card.appendChild(typeDiv);
            
            // Value editor (select or input depending on type)
            const valueDiv = document.createElement('div');
            valueDiv.className = 'preview-variable-card__value';
            let editor = buildValueEditor(type, editValue);
            valueDiv.appendChild(editor.container);
            
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'preview-variable-card__save-btn';
            saveBtn.textContent = QSPanelWords.t('common.save');
            valueDiv.appendChild(saveBtn);
            card.appendChild(valueDiv);
            
            // Preview line
            const previewEl = document.createElement('div');
            previewEl.className = 'preview-variable-card__preview';
            previewEl.textContent = '\u2192 ' + item.textKey;
            card.appendChild(previewEl);
            
            // --- Event handlers ---
            
            // Type change: rebuild value editor with empty value (don't carry over)
            typeSelect.addEventListener('change', async function() {
                const newType = this.value;
                // Rebuild editor for new type with empty value
                const newEditor = buildValueEditor(newType, '');
                valueDiv.replaceChild(newEditor.container, editor.container);
                editor = newEditor;
                
                previewEl.textContent = '\u2192 ' + QSPanelWords.t('preview.variablesPreviewEmpty');
                
                // Attach enter key handler if input
                if (editor.el && editor.el.tagName === 'INPUT') {
                    editor.el.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            saveBtn.click();
                        }
                    });
                }
            });
            
            // Save button click
            saveBtn.addEventListener('click', async function() {
                const currentType = typeSelect.value;
                const currentVal = editor.getValue();
                if (!currentVal) {
                    showToast(QSPanelWords.t('preview.variablesValueRequired'), 'warning');
                    return;
                }
                const newTextKey = editValueToTextKey(currentVal, currentType);
                previewEl.textContent = '\u2192 ' + newTextKey;
                await saveVariableCard(card, item.nodeId, newTextKey, previewEl);
            });
            
            // Enter key in input triggers save (only applies to input elements)
            if (editor.el && editor.el.tagName === 'INPUT') {
                editor.el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveBtn.click();
                    }
                });
            }
            
            container.appendChild(card);
        });
    }
    
    /**
     * Render param variable cards into a container
     */
    function renderParamCards(paramNodes, container) {
        paramNodes.forEach(item => {
            const card = document.createElement('div');
            card.className = 'preview-variable-card';
            card.dataset.paramName = item.paramName;
            
            // Detect initial type: enum, variable, or string
            const isEnum = item.isVariable && variablesPanelEnumVarNames.includes(item.editValue);
            const initialType = isEnum ? 'enum' : (item.isVariable ? 'variable' : 'string');
            
            // Param name header
            const pathEl = document.createElement('div');
            pathEl.className = 'preview-variable-card__path';
            pathEl.append(QSDom.iconEl(ICON_PARAM, 0), QSDom.el('span', { text: item.paramName }));
            card.appendChild(pathEl);
            
            // Type selector (string / variable / enum)
            const typeDiv = document.createElement('div');
            typeDiv.className = 'preview-variable-card__type';
            const typeSelect = document.createElement('select');
            typeSelect.append(
                _renderOption('string', QSPanelWords.t('preview.variablesTypeString'), initialType === 'string'),
                _renderOption('variable', QSPanelWords.t('preview.variablesTypeVariable'), initialType === 'variable'),
                _renderOption('enum', QSPanelWords.t('preview.variablesTypeEnum'), initialType === 'enum')
            );
            typeDiv.appendChild(typeSelect);
            card.appendChild(typeDiv);
            
            // Value editor
            const valueDiv = document.createElement('div');
            valueDiv.className = 'preview-variable-card__value';
            const editorWrapper = document.createElement('div');
            editorWrapper.className = 'preview-variable-card__editor';
            
            // Text input (for string / variable types)
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.value = isEnum ? '' : item.editValue;
            inp.placeholder = initialType === 'variable' 
                ? QSPanelWords.t('preview.variablesPlaceholderVariable')
                : QSPanelWords.t('preview.variablesPlaceholderParamString');
            
            // Enum select (for enum type)
            const enumSel = document.createElement('select');
            enumSel.className = 'preview-variable-card__value-select';
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '— ' + QSPanelWords.t('preview.variablesSelectEnum') + ' —';
            enumSel.appendChild(emptyOpt);
            variablesPanelEnumVarNames.forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (isEnum && name === item.editValue) opt.selected = true;
                enumSel.appendChild(opt);
            });
            
            // Show the right editor based on initial type
            if (initialType === 'enum') {
                inp.style.display = 'none';
                editorWrapper.appendChild(enumSel);
                editorWrapper.appendChild(inp);
            } else {
                enumSel.style.display = 'none';
                editorWrapper.appendChild(inp);
                editorWrapper.appendChild(enumSel);
            }
            
            // Hint
            const hint = document.createElement('div');
            hint.className = 'preview-variable-card__hint';
            if (initialType === 'variable') {
                hint.textContent = QSPanelWords.t('preview.variablesHintVariable');
            } else if (initialType === 'enum') {
                hint.textContent = QSPanelWords.t('preview.variablesHintEnum');
            } else {
                hint.textContent = '';
                hint.style.display = 'none';
            }
            editorWrapper.appendChild(hint);
            
            valueDiv.appendChild(editorWrapper);
            
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'preview-variable-card__save-btn';
            saveBtn.textContent = QSPanelWords.t('common.save');
            valueDiv.appendChild(saveBtn);
            card.appendChild(valueDiv);
            
            // Preview line
            const previewEl = document.createElement('div');
            previewEl.className = 'preview-variable-card__preview';
            previewEl.textContent = '\u2192 ' + item.value;
            card.appendChild(previewEl);
            
            // --- Event handlers ---
            
            // Type change: toggle input vs enum select, update hints/placeholder
            typeSelect.addEventListener('change', function() {
                const newType = this.value;
                if (newType === 'enum') {
                    inp.style.display = 'none';
                    enumSel.style.display = '';
                    hint.textContent = QSPanelWords.t('preview.variablesHintEnum');
                    hint.style.display = '';
                    const selVal = enumSel.value;
                    previewEl.textContent = '\u2192 ' + (selVal ? '{{' + selVal + '}}' : QSPanelWords.t('preview.variablesPreviewEmpty'));
                } else {
                    inp.style.display = '';
                    enumSel.style.display = 'none';
                    const isVar = newType === 'variable';
                    inp.placeholder = isVar 
                        ? QSPanelWords.t('preview.variablesPlaceholderVariable')
                        : QSPanelWords.t('preview.variablesPlaceholderParamString');
                    if (isVar) {
                        hint.textContent = QSPanelWords.t('preview.variablesHintVariable');
                        hint.style.display = '';
                    } else {
                        hint.textContent = '';
                        hint.style.display = 'none';
                    }
                    const currentVal = inp.value.trim();
                    const previewVal = isVar ? '{{' + currentVal + '}}' : currentVal;
                    previewEl.textContent = '\u2192 ' + (previewVal || QSPanelWords.t('preview.variablesPreviewEmpty'));
                }
            });
            
            // Input change: update preview
            inp.addEventListener('input', function() {
                const isVar = typeSelect.value === 'variable';
                const val = this.value.trim();
                const previewVal = isVar ? '{{' + val + '}}' : val;
                previewEl.textContent = '\u2192 ' + (previewVal || QSPanelWords.t('preview.variablesPreviewEmpty'));
            });
            
            // Enum select change: update preview
            enumSel.addEventListener('change', function() {
                const val = this.value;
                previewEl.textContent = '\u2192 ' + (val ? '{{' + val + '}}' : QSPanelWords.t('preview.variablesPreviewEmpty'));
            });
            
            // Save button
            saveBtn.addEventListener('click', async function() {
                const currentType = typeSelect.value;
                let newParamValue;
                if (currentType === 'enum') {
                    const val = enumSel.value;
                    if (!val) {
                        showToast(QSPanelWords.t('preview.variablesValueRequired'), 'warning');
                        return;
                    }
                    newParamValue = '{{' + val + '}}';
                } else {
                    const isVar = currentType === 'variable';
                    const val = inp.value.trim();
                    if (!val) {
                        showToast(QSPanelWords.t('preview.variablesValueRequired'), 'warning');
                        return;
                    }
                    newParamValue = isVar ? '{{' + val + '}}' : val;
                }
                previewEl.textContent = '\u2192 ' + newParamValue;
                await saveParamCard(card, item.paramName, newParamValue, previewEl);
            });
            
            // Enter key (only on text input)
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveBtn.click();
                }
            });
            
            container.appendChild(card);
        });
    }
    
    /**
     * Get placeholder text for input based on type
     */
    function getPlaceholderForType(type) {
        if (type === 'variable') return QSPanelWords.t('preview.variablesPlaceholderVariable');
        if (type === 'raw') return QSPanelWords.t('preview.variablesPlaceholderRaw');
        return '';
    }
    
    /**
     * Save a single variable card's textKey to the backend
     */
    async function saveVariableCard(cardEl, nodeId, newTextKey, previewEl) {
        if (!variablesPanelStructure) return;
        
        cardEl.classList.add('preview-variable-card--saving');
        
        try {
            // Update in our local copy
            const updated = updateTextKeyInStructure(variablesPanelStructure, nodeId, newTextKey);
            if (!updated) throw new Error(QSPanelWords.t('preview.nodeNotFoundAt', { node: nodeId }));
            
            // Send full structure to backend
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: currentEditName,
                structure: variablesPanelStructure
            });
            
            if (!result.ok) throw new Error(result.data?.message || QSPanelWords.t('common.saveFailed'));
            
            showToast(QSPanelWords.t('preview.variablesSaved'), 'success');
            componentsLoaded = false; // Invalidate component cache
            
            // Reload preview to reflect change
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Variable save error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
            if (previewEl) previewEl.textContent = '\u2192 ' + QSPanelWords.t('preview.variablesPreviewSaveFailed');
        } finally {
            cardEl.classList.remove('preview-variable-card--saving');
        }
    }
    
    /**
     * Save a single param card's value to the backend
     */
    async function saveParamCard(cardEl, paramName, newValue, previewEl) {
        if (!variablesPanelStructure) return;
        
        cardEl.classList.add('preview-variable-card--saving');
        
        try {
            const nodeId = (selectedNode != null) ? selectedNode : '';
            const targetNode = getNodeByPath(variablesPanelStructure, nodeId);
            if (!targetNode) throw new Error(QSPanelWords.t('preview.nodeNotFound'));
            
            if (!targetNode.params) targetNode.params = {};
            targetNode.params[paramName] = newValue;
            
            // Send full structure to backend
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: currentEditName,
                structure: variablesPanelStructure
            });
            
            if (!result.ok) throw new Error(result.data?.message || QSPanelWords.t('common.saveFailed'));
            
            showToast(QSPanelWords.t('preview.variablesSaved'), 'success');
            componentsLoaded = false; // Invalidate component cache
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Param save error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
            if (previewEl) previewEl.textContent = '\u2192 ' + QSPanelWords.t('preview.variablesPreviewSaveFailed');
        } finally {
            cardEl.classList.remove('preview-variable-card--saving');
        }
    }
    
    /**
     * Hide the Variables panel
     */
    function hideVariablesPanel() {
        if (!variablesPanel) return;
        
        variablesPanel.style.display = 'none';
        variablesPanelStructure = null;
        
        // Restore action buttons
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = '';
    }
    
    // ==================== Enums Panel (Component-only) ====================
    
    // Stored component structure for enums editing
    let enumsPanelStructure = null;
    // Cached flat list of translation key names for enum value selection
    let enumsPanelTranslationKeys = [];
    
    /**
     * Show the Enums panel — loads component structure, extracts __enums__
     */
    async function showEnumsPanel() {
        if (!enumsPanel || currentEditType !== 'component') return;
        
        // Hide other panels
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = 'none';
        if (variablesPanel) variablesPanel.style.display = 'none';
        if (emulationPanel) emulationPanel.style.display = 'none';
        if (saveSnippetForm) saveSnippetForm.style.display = 'none';
        if (saveComponentForm) saveComponentForm.style.display = 'none';
        syncSaveFormFills();

        enumsPanel.style.display = '';
        
        // Show loading
        if (enumsPanelLoading) enumsPanelLoading.style.display = '';
        if (enumsPanelEmpty) enumsPanelEmpty.style.display = 'none';
        if (enumsPanelCards) enumsPanelCards.replaceChildren();
        if (enumsPanelAdd) enumsPanelAdd.style.display = 'none';
        
        try {
            const structInfo = parseStruct(selectedStruct || ('component-' + currentEditName));
            if (!structInfo) throw new Error(QSPanelWords.t('preview.invalidStruct'));
            
            const urlParams = [structInfo.type];
            if (structInfo.name) urlParams.push(structInfo.name);
            
            const [resp, words] = await Promise.all([
                QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams),
                window.QSSiteWords ? QSSiteWords.read() : Promise.resolve(null)
            ]);

            if (!resp.ok || !resp.data?.data?.structure) throw new Error(QSPanelWords.t('preview.structureReadFailed'));

            enumsPanelStructure = JSON.parse(JSON.stringify(resp.data.data.structure));

            // The site's keys, every language's, from the one list of the site's words
            enumsPanelTranslationKeys = words ? QSSiteWords.keys(words) : [];
            
            const enums = enumsPanelStructure.__enums__ || {};
            
            // Hide loading
            if (enumsPanelLoading) enumsPanelLoading.style.display = 'none';
            
            if (Object.keys(enums).length === 0) {
                if (enumsPanelEmpty) enumsPanelEmpty.style.display = '';
            } else {
                renderEnumCards(enums);
            }
            
            // Show Add button
            if (enumsPanelAdd) enumsPanelAdd.style.display = '';
            
        } catch (error) {
            console.error('[Preview] Enums panel error:', error);
            if (enumsPanelLoading) enumsPanelLoading.style.display = 'none';
            if (enumsPanelCards) {
                enumsPanelCards.replaceChildren(_renderPanelError(error));
            }
        }
    }
    
    /**
     * Hide the Enums panel
     */
    function hideEnumsPanel() {
        if (!enumsPanel) return;
        
        enumsPanel.style.display = 'none';
        enumsPanelStructure = null;
        enumsPanelTranslationKeys = [];
        
        // Restore action buttons
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = '';
    }
    
    /**
     * Render all enum cards into the panel
     */
    function renderEnumCards(enums) {
        if (!enumsPanelCards) return;
        enumsPanelCards.replaceChildren();
        
        for (const [varName, enumDef] of Object.entries(enums)) {
            if (!enumDef || typeof enumDef !== 'object' || !enumDef.source || !enumDef.map) continue;
            enumsPanelCards.appendChild(createEnumCard(varName, enumDef));
        }
    }
    
    /**
     * Create a single enum card element
     * 
     * Card layout:
     * ┌─ varName ─────────────────────────────┐
     * │ Source: [sourceKey]                     │
     * │ Default: [select ▾]                    │
     * │ ┌──────────────────────────────────┐   │
     * │ │ Key     │ Value                  │   │
     * │ │ get     │ get-style              │ × │
     * │ │ post    │ post-style             │ × │
     * │ └──────────────────────────────────┘   │
     * │ [+ Add Option]          [Save] [bin]   │
     * └────────────────────────────────────────┘
     */
    /**
     * An enum map value's type: 'raw' for a raw text (`__RAW__…`), 'translation' for a key the project
     * has, else 'value': an attribute's value, written as it is (a class, an address). The render paths
     * read `__RAW__` in a text and a translatable attribute only, so a value for any other attribute
     * carries no prefix.
     */
    function enumValueType(value) {
        const v = String(value);
        if (v.startsWith('__RAW__')) return 'raw';
        return enumsPanelTranslationKeys.includes(v) ? 'translation' : 'value';
    }

    /**
     * The type a value of an enum's variable needs, from where the component first uses `{{name}}`:
     * 'value' in an attribute that is not translatable (a class, an address), 'raw' in a text or a
     * translatable attribute, and 'raw' when the component does not use it yet.
     */
    function enumVariableValueType(varName) {
        const slot = '{{' + varName + '}}';
        const translatable = TAG_INFO.TRANSLATABLE_ATTRIBUTES || [];
        let found = null;
        (function walk(node) {
            if (found || !node || typeof node !== 'object') return;
            if (Array.isArray(node)) { node.forEach(walk); return; }
            if (node.params && typeof node.params === 'object') {
                for (const [name, v] of Object.entries(node.params)) {
                    if (typeof v === 'string' && v.includes(slot)) {
                        found = translatable.includes(name) ? 'raw' : 'value';
                        return;
                    }
                }
            }
            if (typeof node.textKey === 'string' && node.textKey.includes(slot)) { found = 'raw'; return; }
            walk(node.children);
        })(enumsPanelStructure);
        return found || 'raw';
    }

    function createEnumCard(varName, enumDef) {
        const card = document.createElement('div');
        card.className = 'preview-enum-card';
        card.dataset.varName = varName;
        
        const source = enumDef.source || '';
        const map = enumDef.map || {};
        const mapKeys = Object.keys(map);
        const defaultKey = enumDef.default || mapKeys[0] || '';
        
        // Header with variable name (clickable to rename)
        const header = document.createElement('div');
        header.className = 'preview-enum-card__header';

        const nameSpan = document.createElement('span');
        nameSpan.className = 'preview-enum-card__name';
        nameSpan.textContent = varName;

        // The name's pencil; its class is addNewEnum()'s hook, which opens a new card's rename
        const editBtn = QSControls.edit(QSPanelWords.t('preview.enumRenameTip'), null,
            { className: 'preview-enum-card__edit-name-btn' });

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'admin-input admin-input--sm preview-enum-card__name-input';
        nameInput.value = varName;
        nameInput.placeholder = QSPanelWords.t('preview.enumNamePlaceholder');
        nameInput.style.display = 'none';

        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'preview-enum-card__name-confirm';
        confirmBtn.appendChild(_renderSizedIcon(QuickSiteUtils.ICON_PATHS.check, 12));
        confirmBtn.style.display = 'none';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'preview-enum-card__name-cancel';
        cancelBtn.appendChild(_renderSizedIcon(QuickSiteUtils.ICON_PATHS.close, 12));
        cancelBtn.style.display = 'none';

        function startRename() {
            nameSpan.style.display = 'none';
            editBtn.style.display = 'none';
            nameInput.style.display = '';
            confirmBtn.style.display = '';
            cancelBtn.style.display = '';
            nameInput.value = card.dataset.varName;
            nameInput.focus();
            nameInput.select();
        }

        function finishRename(accept) {
            if (accept) {
                const newName = nameInput.value.trim().replace(/[^a-zA-Z0-9_]/g, '_');
                const oldName = card.dataset.varName;
                if (!newName) {
                    showToast(QSPanelWords.t('preview.enumNameRequired'), 'warning');
                    nameInput.focus();
                    return;
                }
                // Check for duplicates (skip self)
                const existing = enumsPanelStructure?.__enums__ || {};
                if (newName !== oldName && existing[newName]) {
                    showToast(QSPanelWords.t('preview.enumNameDuplicate', { name: newName }), 'warning');
                    nameInput.focus();
                    return;
                }
                nameSpan.textContent = newName;
                card.dataset.varName = newName;
            }
            nameSpan.style.display = '';
            editBtn.style.display = '';
            nameInput.style.display = 'none';
            confirmBtn.style.display = 'none';
            cancelBtn.style.display = 'none';
        }

        editBtn.addEventListener('click', startRename);
        nameSpan.addEventListener('dblclick', startRename);
        confirmBtn.addEventListener('click', () => finishRename(true));
        cancelBtn.addEventListener('click', () => finishRename(false));
        nameInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); finishRename(true); }
            if (e.key === 'Escape') { finishRename(false); }
        });

        header.appendChild(nameSpan);
        header.appendChild(editBtn);
        header.appendChild(nameInput);
        header.appendChild(confirmBtn);
        header.appendChild(cancelBtn);
        card.appendChild(header);
        
        // Source key field
        const sourceRow = document.createElement('div');
        sourceRow.className = 'preview-enum-card__field';
        sourceRow.append(
            QSDom.el('label', { class: 'preview-enum-card__label', text: QSPanelWords.t('preview.enumSourceLabel') }),
            QSDom.el('input', { type: 'text', class: 'admin-input admin-input--sm preview-enum-card__source-input', value: source,
                placeholder: QSPanelWords.t('preview.enumSourcePlaceholder') })
        );
        card.appendChild(sourceRow);
        
        // Detect default type from existing values; with none, from where the component uses the variable
        const valueTypes = Object.values(map).map(enumValueType);
        const rawCount = valueTypes.filter(type => type === 'raw').length;
        const plainCount = valueTypes.filter(type => type === 'value').length;
        const totalCount = mapKeys.length;
        const detectedDefault = totalCount === 0 ? enumVariableValueType(varName)
            : (plainCount > rawCount && plainCount >= totalCount / 2) ? 'value'
            : (rawCount > plainCount && rawCount >= totalCount / 2) ? 'raw'
            : enumVariableValueType(varName);
        
        // Default value type dropdown (card-level)
        const typeRow = document.createElement('div');
        typeRow.className = 'preview-enum-card__field';
        const valueLabel = QSPanelWords.t('preview.enumTypeAttributeValue');
        const rawLabel = QSPanelWords.t('preview.variablesTypeRaw');
        const transLabel = QSPanelWords.t('preview.variablesTypeTranslation');
        typeRow.append(
            QSDom.el('label', { class: 'preview-enum-card__label', text: QSPanelWords.t('preview.enumValueTypeLabel') }),
            QSDom.el('select', { class: 'admin-input admin-input--sm preview-enum-card__type-select' }, [
                _renderOption('value', valueLabel, detectedDefault === 'value'),
                _renderOption('raw', rawLabel, detectedDefault === 'raw'),
                _renderOption('translation', transLabel, detectedDefault === 'translation')
            ])
        );
        card.appendChild(typeRow);
        
        // Default dropdown
        const defaultRow = document.createElement('div');
        defaultRow.className = 'preview-enum-card__field';
        defaultRow.append(
            QSDom.el('label', { class: 'preview-enum-card__label', text: QSPanelWords.t('preview.enumDefaultLabel') }),
            QSDom.el('select', { class: 'admin-input admin-input--sm preview-enum-card__default-select' },
                mapKeys.map(k => _renderOption(k, k, k === defaultKey)))
        );
        card.appendChild(defaultRow);
        
        // Map table
        const mapContainer = document.createElement('div');
        mapContainer.className = 'preview-enum-card__map';
        
        const table = document.createElement('table');
        table.className = 'preview-enum-card__table';
        table.appendChild(QSDom.el('thead', {}, [QSDom.el('tr', {}, [
            QSDom.el('th', { text: QSPanelWords.t('preview.enumKey') }),
            QSDom.el('th', { text: QSPanelWords.t('preview.enumValue') }),
            QSDom.el('th', { text: QSPanelWords.t('preview.enumType') }),
            QSDom.el('th')
        ])]));
        const tbody = document.createElement('tbody');
        tbody.className = 'preview-enum-card__tbody';
        
        mapKeys.forEach(key => {
            const rawValue = map[key];
            // Per-row type: if it differs from card default, it's an override
            const rowType = enumValueType(rawValue);
            const displayValue = rowType === 'raw' ? String(rawValue).slice(7) : rawValue;
            const hasOverride = rowType !== detectedDefault;
            tbody.appendChild(createEnumMapRow(key, displayValue, hasOverride ? rowType : null, detectedDefault));
        });
        
        table.appendChild(tbody);
        mapContainer.appendChild(table);
        card.appendChild(mapContainer);
        
        // When card-level type changes, update all rows without an override
        const cardTypeSelect = typeRow.querySelector('.preview-enum-card__type-select');
        cardTypeSelect?.addEventListener('change', () => {
            const newCardType = cardTypeSelect.value;
            tbody.querySelectorAll('.preview-enum-card__row').forEach(row => {
                const rowTypeSelect = row.querySelector('.preview-enum-card__row-type-select');
                if (!rowTypeSelect?.value) {
                    // No override — follows card default
                    const valInput = row.querySelector('.preview-enum-card__value-input');
                    const valSelect = row.querySelector('.preview-enum-card__value-select');
                    if (valInput && valSelect) updateEnumRowValueWidget(valInput, valSelect, newCardType);
                }
            });
        });
        
        // Add option button
        const addOptionBtn = document.createElement('button');
        addOptionBtn.type = 'button';
        addOptionBtn.className = 'admin-btn admin-btn--outline preview-enum-card__add-option';
        addOptionBtn.append(_renderSizedIcon(QuickSiteUtils.ICON_PATHS.plus, 12), ' ' + QSPanelWords.t('preview.enumAddOption'));
        addOptionBtn.addEventListener('click', () => {
            const currentCardType = card.querySelector('.preview-enum-card__type-select')?.value || 'raw';
            tbody.appendChild(createEnumMapRow('', '', null, currentCardType));
            refreshDefaultSelect(card, tbody);
        });
        card.appendChild(addOptionBtn);
        
        // Action buttons — Save and Delete
        const actions = document.createElement('div');
        actions.className = 'preview-enum-card__actions';
        
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'admin-btn admin-btn--primary';
        saveBtn.textContent = QSPanelWords.t('common.save');
        saveBtn.addEventListener('click', () => saveEnumCard(card, varName));
        
        actions.appendChild(saveBtn);
        actions.appendChild(QSControls.bin(QSPanelWords.t('common.delete'), () => deleteEnumCard(varName)));
        card.appendChild(actions);
        
        return card;
    }
    
    /**
     * Create a single row in the enum map table
     * @param {string} key - Map key
     * @param {string} value - Display value (already stripped of __RAW__ if applicable)
     * @param {string|null} typeOverride - 'raw'|'translation' if row overrides card default, null for default
     * @param {string} cardDefaultType - The card-level default type ('raw' or 'translation')
     */
    function createEnumMapRow(key, value, typeOverride, cardDefaultType) {
        const tr = document.createElement('tr');
        tr.className = 'preview-enum-card__row';
        
        const valueLabel = QSPanelWords.t('preview.enumTypeAttributeValue');
        const rawLabel = QSPanelWords.t('preview.variablesTypeRaw');
        const transLabel = QSPanelWords.t('preview.variablesTypeTranslation');
        const defaultLabel = QSPanelWords.t('preview.enumTypeDefault');
        
        // Key cell
        const keyTd = document.createElement('td');
        keyTd.appendChild(QSDom.el('input', { type: 'text', class: 'admin-input admin-input--sm preview-enum-card__key-input', value: String(key),
            placeholder: QSPanelWords.t('preview.enumKeyPlaceholder') }));
        
        // Value cell — contains both a text input and a translation select, one visible at a time
        const valueTd = document.createElement('td');
        
        const valInput = document.createElement('input');
        valInput.type = 'text';
        valInput.className = 'admin-input admin-input--sm preview-enum-card__value-input';
        valInput.value = String(value);
        valInput.placeholder = QSPanelWords.t('preview.enumValuePlaceholder');
        
        const valSelect = document.createElement('select');
        valSelect.className = 'admin-input admin-input--sm preview-enum-card__value-select';
        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '— ' + QSPanelWords.t('preview.enumSelectTranslationKey') + ' —';
        valSelect.appendChild(emptyOpt);
        enumsPanelTranslationKeys.forEach(k => {
            const opt = document.createElement('option');
            opt.value = k;
            opt.textContent = k;
            if (k === value) opt.selected = true;
            valSelect.appendChild(opt);
        });
        // If current value exists but not in the list, add as custom option
        if (value && !enumsPanelTranslationKeys.includes(value)) {
            const customOpt = document.createElement('option');
            customOpt.value = value;
            customOpt.textContent = value + ' (?)';
            customOpt.selected = true;
            valSelect.appendChild(customOpt);
        }
        
        valueTd.appendChild(valInput);
        valueTd.appendChild(valSelect);
        
        // Determine effective type and show correct widget
        const effectiveType = typeOverride || cardDefaultType || 'translation';
        updateEnumRowValueWidget(valInput, valSelect, effectiveType);
        
        // Type cell — shows "(default)" label that can be clicked to override
        const typeTd = document.createElement('td');
        typeTd.className = 'preview-enum-card__type-cell';
        
        const typeLabel = document.createElement('span');
        typeLabel.className = 'preview-enum-card__type-label';
        typeLabel.textContent = defaultLabel;
        typeLabel.title = QSPanelWords.t('preview.enumTypeOverrideTip');
        
        const typeSelect = document.createElement('select');
        typeSelect.className = 'admin-input admin-input--sm preview-enum-card__row-type-select';
        typeSelect.append(
            _renderOption('', defaultLabel, false),
            _renderOption('value', valueLabel, false),
            _renderOption('raw', rawLabel, false),
            _renderOption('translation', transLabel, false)
        );
        
        if (typeOverride) {
            typeSelect.value = typeOverride;
            typeLabel.style.display = 'none';
            typeSelect.style.display = '';
        } else {
            typeLabel.style.display = '';
            typeSelect.style.display = 'none';
        }
        
        typeLabel.addEventListener('click', () => {
            typeLabel.style.display = 'none';
            typeSelect.style.display = '';
            typeSelect.focus();
        });
        
        typeSelect.addEventListener('change', () => {
            if (typeSelect.value === '') {
                // Reverted to default — use card default type
                typeSelect.style.display = 'none';
                typeLabel.style.display = '';
                const card = tr.closest('.preview-enum-card');
                const cardType = card?.querySelector('.preview-enum-card__type-select')?.value || 'raw';
                updateEnumRowValueWidget(valInput, valSelect, cardType);
            } else {
                updateEnumRowValueWidget(valInput, valSelect, typeSelect.value);
            }
        });
        
        typeTd.appendChild(typeLabel);
        typeTd.appendChild(typeSelect);
        
        // Remove cell: the row's small × (nothing is written until the card's Save)
        const removeTd = document.createElement('td');
        removeTd.appendChild(QSControls.removeRow(QSPanelWords.t('common.remove'), () => {
            const card = tr.closest('.preview-enum-card');
            const tbody = tr.closest('tbody');
            tr.remove();
            if (card && tbody) refreshDefaultSelect(card, tbody);
        }));

        tr.appendChild(keyTd);
        tr.appendChild(valueTd);
        tr.appendChild(typeTd);
        tr.appendChild(removeTd);
        
        // Update default select when key changes
        keyTd.querySelector('.preview-enum-card__key-input').addEventListener('change', () => {
            const card = tr.closest('.preview-enum-card');
            const tbody = tr.closest('tbody');
            if (card && tbody) refreshDefaultSelect(card, tbody);
        });
        
        return tr;
    }
    
    /**
     * Toggle between text input and translation select for an enum map row value cell
     */
    function updateEnumRowValueWidget(valInput, valSelect, effectiveType) {
        if (effectiveType === 'translation') {
            valInput.style.display = 'none';
            valSelect.style.display = '';
        } else {
            // Both 'value' and 'raw' use the free text input
            valInput.style.display = '';
            valSelect.style.display = 'none';
        }
    }
    
    /**
     * Refresh the default dropdown options from current map rows
     */
    function refreshDefaultSelect(card, tbody) {
        const select = card.querySelector('.preview-enum-card__default-select');
        if (!select) return;
        
        const currentDefault = select.value;
        const keys = [];
        tbody.querySelectorAll('.preview-enum-card__key-input').forEach(inp => {
            const k = inp.value.trim();
            if (k) keys.push(k);
        });
        
        select.replaceChildren(...keys.map(k => _renderOption(k, k, k === currentDefault)));
        
        // If previous default no longer exists, select first
        if (!keys.includes(currentDefault) && keys.length > 0) {
            select.value = keys[0];
        }
    }
    
    /**
     * Read card form data and save the enum to the component structure
     */
    async function saveEnumCard(card, originalVarName) {
        if (!enumsPanelStructure) return;
        
        // Read the current name from the card (may have been renamed)
        const currentVarName = card.dataset.varName || originalVarName;
        
        const sourceInput = card.querySelector('.preview-enum-card__source-input');
        const defaultSelect = card.querySelector('.preview-enum-card__default-select');
        const tbody = card.querySelector('.preview-enum-card__tbody');
        
        const source = sourceInput?.value?.trim();
        const defaultVal = defaultSelect?.value || '';
        
        if (!source) {
            showToast(QSPanelWords.t('preview.enumSourceRequired'), 'warning');
            sourceInput?.focus();
            return;
        }
        
        // Get card-level default type
        const cardTypeSelect = card.querySelector('.preview-enum-card__type-select');
        const cardDefaultType = cardTypeSelect?.value || 'raw';
        
        // Collect map entries (applying prefix based on effective type)
        const map = {};
        let hasEmpty = false;
        tbody?.querySelectorAll('.preview-enum-card__row').forEach(row => {
            const key = row.querySelector('.preview-enum-card__key-input')?.value?.trim();
            // Determine effective type: row override or card default
            const rowTypeSelect = row.querySelector('.preview-enum-card__row-type-select');
            const effectiveType = (rowTypeSelect?.value) || cardDefaultType;
            // Read value from the correct widget
            let value;
            if (effectiveType === 'translation') {
                value = row.querySelector('.preview-enum-card__value-select')?.value?.trim() || '';
            } else {
                value = row.querySelector('.preview-enum-card__value-input')?.value?.trim() || '';
            }
            if (key) {
                // A raw text gets the __RAW__ prefix; a translation key and an attribute value are written as they are
                if (effectiveType === 'raw' && value) {
                    map[key] = '__RAW__' + value;
                } else {
                    map[key] = value || '';
                }
            } else {
                hasEmpty = true;
            }
        });
        
        if (Object.keys(map).length === 0) {
            showToast(QSPanelWords.t('preview.enumNeedsOptions'), 'warning');
            return;
        }
        
        card.classList.add('preview-enum-card--saving');
        
        try {
            // Update __enums__ in structure
            if (!enumsPanelStructure.__enums__) {
                enumsPanelStructure.__enums__ = {};
            }
            
            // If varName changed, remove old entry
            if (originalVarName && currentVarName !== originalVarName) {
                delete enumsPanelStructure.__enums__[originalVarName];
            }
            
            // Build the enum definition
            const enumDef = { source, map };
            const mapKeys = Object.keys(map);
            // Only include default if it differs from first key
            if (defaultVal && defaultVal !== mapKeys[0]) {
                enumDef.default = defaultVal;
            }
            
            enumsPanelStructure.__enums__[currentVarName] = enumDef;
            
            // Save via editStructure API
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: currentEditName,
                structure: enumsPanelStructure
            });
            
            if (!result.ok) throw new Error(result.data?.message || QSPanelWords.t('common.saveFailed'));
            
            showToast(QSPanelWords.t('preview.enumSaved'), 'success');
            componentsLoaded = false; // Invalidate component cache
            
            // Auto-create CSS stubs for class-bound enum values
            await createCssStubsForEnumIfNeeded(enumsPanelStructure, currentVarName, map);
            
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Enum save error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
        } finally {
            card.classList.remove('preview-enum-card--saving');
        }
    }
    
    /**
     * Delete an enum from the component structure
     */
    async function deleteEnumCard(varName) {
        if (!enumsPanelStructure) return;
        
        const asked = await QuickSiteUtils.confirm('', {
            title: QSPanelWords.t('preview.enumConfirmDelete', { name: varName }),
            type: 'danger',
            confirmText: QSPanelWords.t('common.delete')
        });
        if (!asked) return;
        
        try {
            if (enumsPanelStructure.__enums__) {
                delete enumsPanelStructure.__enums__[varName];
                
                // Clean up empty __enums__ object
                if (Object.keys(enumsPanelStructure.__enums__).length === 0) {
                    delete enumsPanelStructure.__enums__;
                }
            }
            
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: currentEditName,
                structure: enumsPanelStructure
            });
            
            if (!result.ok) throw new Error(result.data?.message || QSPanelWords.t('preview.enumDeleteFailed'));
            
            showToast(QSPanelWords.t('preview.enumDeleted'), 'success');
            
            // Refresh the panel
            showEnumsPanel();
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Enum delete error:', error);
            showToast(QSPanelWords.t('preview.errorDetail', { error: error.message }), 'error');
        }
    }
    
    /**
     * Add a new blank enum and render a card for it
     */
    function addNewEnum() {
        if (!enumsPanelStructure) return;
        
        // Generate a unique name
        const existing = enumsPanelStructure.__enums__ || {};
        let idx = 1;
        let name = 'new_enum';
        while (existing[name]) {
            name = 'new_enum_' + (++idx);
        }
        
        // Create a blank enum definition
        const enumDef = {
            source: '',
            map: {}
        };
        
        // Add to structure in-memory
        if (!enumsPanelStructure.__enums__) {
            enumsPanelStructure.__enums__ = {};
        }
        enumsPanelStructure.__enums__[name] = enumDef;
        
        // Hide empty state if visible
        if (enumsPanelEmpty) enumsPanelEmpty.style.display = 'none';
        
        // Render card (unsaved — user needs to fill it and click Save)
        const card = createEnumCard(name, enumDef);
        if (enumsPanelCards) enumsPanelCards.appendChild(card);
        
        // Auto-activate the rename input so user types a real name immediately
        const editNameBtn = card.querySelector('.preview-enum-card__edit-name-btn');
        if (editNameBtn) editNameBtn.click();
    }
    
    // ==================== Variable Emulation Panel ====================
    
    /**
     * Show the Variable Emulation panel
     */
    async function showEmulationPanel() {
        // Component-edit path uses the structure-parsing flow below.
        // Page-edit path takes the dedicated page branch.
        if (!emulationPanel) return;
        if (currentEditType !== 'component' && currentEditType !== 'page'
            && currentEditType !== undefined && currentEditType !== null) {
            return;
        }

        // Hide other panels
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = 'none';
        if (variablesPanel) variablesPanel.style.display = 'none';
        if (enumsPanel) enumsPanel.style.display = 'none';
        if (saveSnippetForm) saveSnippetForm.style.display = 'none';
        if (saveComponentForm) saveComponentForm.style.display = 'none';
        syncSaveFormFills();

        emulationPanel.style.display = '';

        // Show loading
        if (emulationPanelLoading) emulationPanelLoading.style.display = '';
        if (emulationPanelEmpty) emulationPanelEmpty.style.display = 'none';
        if (emulationPanelFields) emulationPanelFields.replaceChildren();
        if (emulationPanelActions) emulationPanelActions.style.display = 'none';

        // ── Page emulation branch ──
        // Page emulation fields come from two sources: ':name' segments
        // in the route pattern (route params) and the resolver's `expose`
        // keys (resolved vars from PreviewConfig). No server fetch
        // required — both are available client-side.
        if (currentEditType === 'page' || currentEditType === undefined || currentEditType === null) {
            try {
                const vars = _getPageEmulationVarNames(currentEditName);
                if (emulationPanelLoading) emulationPanelLoading.style.display = 'none';
                if (vars.routeParams.length === 0 && vars.resolved.length === 0) {
                    if (emulationPanelEmpty) {
                        emulationPanelEmpty.querySelector('span').textContent =
                            QSPanelWords.t('preview.noVariablesToEmulatePage');
                        emulationPanelEmpty.style.display = '';
                    }
                    return;
                }
                const saved = _getPageEmulationData(currentEditName) || { routeParams: {}, resolved: {}, useLive: false };
                const hasAnySaved = Object.keys(saved.routeParams).length > 0
                                 || Object.keys(saved.resolved).length > 0;
                // Fetch schema-driven defaults from
                // PreviewConfig (computed server-side from each
                // endpoint's responseSchema). Used to pre-fill resolved
                // inputs on FIRST OPEN (no saved emulation yet). After
                // the user has saved anything, their values win — we
                // never overwrite a deliberate save with a default.
                const resolverDefaults = (PreviewConfig.routeResolverDefaults || {})[currentEditName] || {};
                if (emulationPanelFields) {
                    vars.routeParams.forEach(name => {
                        // Route params don't have schemas; default to
                        // 'preview-{name}' so the URL has a matchable
                        // segment without the author having to type.
                        const fallback = hasAnySaved ? '' : ('preview-' + name);
                        emulationPanelFields.appendChild(
                            _createPageEmulationField('routeParams', name, saved.routeParams[name] || fallback)
                        );
                    });
                    vars.resolved.forEach(name => {
                        const schemaDefault = resolverDefaults[name];
                        // Coerce non-string defaults (numbers, booleans)
                        // to display strings for the text input. Arrays
                        // / objects → leave the input blank (empty
                        // collections aren't useful in a text field).
                        let fallback = '';
                        if (!hasAnySaved && schemaDefault !== undefined && schemaDefault !== null) {
                            if (typeof schemaDefault === 'string'
                                || typeof schemaDefault === 'number'
                                || typeof schemaDefault === 'boolean') {
                                fallback = String(schemaDefault);
                            }
                        }
                        emulationPanelFields.appendChild(
                            _createPageEmulationField('resolved', name, saved.resolved[name] || fallback)
                        );
                    });
                }
                // Show the live-data toggle (page-only) and
                // sync its visual state to the saved useLive flag. The
                // button toggles class --active when live mode is on so
                // the user sees the current state at a glance.
                if (emulationLiveBtn) {
                    emulationLiveBtn.style.display = '';
                    _syncLiveBtnState(saved.useLive, vars.resolved.length === 0);
                }
                if (emulationPanelActions) emulationPanelActions.style.display = '';
            } catch (error) {
                console.error('[Preview] Page emulation panel error:', error);
                if (emulationPanelLoading) emulationPanelLoading.style.display = 'none';
                if (emulationPanelFields) {
                    emulationPanelFields.replaceChildren(_renderPanelError(error));
                }
            }
            return;
        }

        // Hide live button on the component branch (no resolver concept).
        if (emulationLiveBtn) emulationLiveBtn.style.display = 'none';

        // ── Component branch (existing flow) ──
        try {
            const structInfo = parseStruct(selectedStruct || ('component-' + currentEditName));
            if (!structInfo) throw new Error(QSPanelWords.t('preview.invalidStruct'));
            
            const urlParams = [structInfo.type];
            if (structInfo.name) urlParams.push(structInfo.name);
            
            const resp = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
            if (!resp.ok || !resp.data?.data?.structure) throw new Error(QSPanelWords.t('preview.structureReadFailed'));
            
            const structure = resp.data.data.structure;
            const enums = structure.__enums__ || {};
            
            // Extract all placeholders from the structure (excluding __enums__)
            const structCopy = JSON.parse(JSON.stringify(structure));
            delete structCopy.__enums__;
            const placeholders = extractPlaceholdersFromStructure(structCopy);
            
            // Build the enum variable names set
            const enumVarNames = Object.keys(enums);
            
            if (emulationPanelLoading) emulationPanelLoading.style.display = 'none';
            
            if (placeholders.length === 0 && enumVarNames.length === 0) {
                if (emulationPanelEmpty) emulationPanelEmpty.style.display = '';
                return;
            }
            
            // Load existing emulation data from localStorage
            const storageKey = QuickSiteStorageKeys.emulateComponent + currentEditName;
            let savedData = {};
            try { savedData = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch(e) {}
            
            // Build form fields
            if (emulationPanelFields) {
                // Regular variables (non-enum)
                const regularVars = placeholders.filter(p => !enumVarNames.includes(p));
                regularVars.forEach(varName => {
                    emulationPanelFields.appendChild(
                        createEmulationField(varName, 'text', savedData[varName] || '', null)
                    );
                });
                
                // Enum variables
                enumVarNames.forEach(varName => {
                    const enumDef = enums[varName];
                    const mapKeys = Object.keys(enumDef.map || {});
                    const savedKey = savedData[varName];
                    emulationPanelFields.appendChild(
                        createEmulationField(varName, 'enum', savedKey || '', mapKeys)
                    );
                });
            }
            
            if (emulationPanelActions) emulationPanelActions.style.display = '';
            
        } catch (error) {
            console.error('[Preview] Emulation panel error:', error);
            if (emulationPanelLoading) emulationPanelLoading.style.display = 'none';
            if (emulationPanelFields) {
                emulationPanelFields.replaceChildren(_renderPanelError(error));
            }
        }
    }
    
    /**
     * Hide the Emulation panel
     */
    function hideEmulationPanel() {
        if (!emulationPanel) return;
        emulationPanel.style.display = 'none';
        
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = '';
    }
    
    /**
     * Extract all {{placeholder}} names from a component structure (client-side)
     */
    function extractPlaceholdersFromStructure(node) {
        const found = new Set();
        const regex = /\{\{(\w+)\}\}/g;
        
        function walk(obj) {
            if (!obj || typeof obj !== 'object') return;
            if (typeof obj === 'string') {
                let m;
                while ((m = regex.exec(obj)) !== null) found.add(m[1]);
                return;
            }
            for (const key of Object.keys(obj)) {
                const val = obj[key];
                if (typeof val === 'string') {
                    let m;
                    regex.lastIndex = 0;
                    while ((m = regex.exec(val)) !== null) found.add(m[1]);
                } else if (typeof val === 'object' && val !== null) {
                    walk(val);
                }
            }
        }
        walk(node);
        return Array.from(found);
    }
    
    /**
     * Create a single emulation field row
     */
    function createEmulationField(varName, type, savedValue, enumKeys) {
        const row = document.createElement('div');
        row.className = 'preview-emulation-field';
        row.dataset.varName = varName;
        
        const label = document.createElement('label');
        label.className = 'preview-emulation-field__label';
        label.textContent = varName;
        if (type === 'enum') {
            const badge = document.createElement('span');
            badge.className = 'preview-emulation-field__badge';
            badge.textContent = 'enum';
            label.appendChild(badge);
        }
        
        let input;
        if (type === 'enum' && enumKeys && enumKeys.length > 0) {
            input = document.createElement('select');
            input.className = 'admin-input admin-input--sm preview-emulation-field__input';
            input.append(_renderOption('', '— {{' + varName + '}} —', false), ...enumKeys.map(k => _renderOption(k, k, k === savedValue)));
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'admin-input admin-input--sm preview-emulation-field__input';
            input.value = savedValue;
            input.placeholder = '{{' + varName + '}}';
        }
        
        const resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'preview-emulation-field__reset';
        resetBtn.title = QSPanelWords.t('preview.emulationResetField');
        resetBtn.appendChild(_renderSizedIcon(ICON_RESET, 12));
        resetBtn.addEventListener('click', () => {
            if (input.tagName === 'SELECT') {
                input.value = '';
            } else {
                input.value = '';
            }
        });
        
        row.appendChild(label);
        row.appendChild(input);
        row.appendChild(resetBtn);
        return row;
    }

    /**
     * Create a single emulation field for page-edit mode.
     * Mirrors createEmulationField's structural output (same CSS classes
     * for the row + label + input + reset button) so the panel chrome is
     * indistinguishable from the component case, but carries a small
     * data-section marker so applyEmulation can rebuild the nested
     * {routeParams: {...}, resolved: {...}} shape on save.
     *
     * @param {'routeParams' | 'resolved'} section
     * @param {string} varName
     * @param {string} savedValue
     */
    function _createPageEmulationField(section, varName, savedValue) {
        const row = document.createElement('div');
        row.className = 'preview-emulation-field';
        row.dataset.varName = varName;
        row.dataset.pageSection = section;

        const label = document.createElement('label');
        label.className = 'preview-emulation-field__label';
        label.textContent = varName;
        // Small badge indicating which kind of variable this is. Reuses
        // the existing badge styling from the enum case.
        const badge = document.createElement('span');
        badge.className = 'preview-emulation-field__badge';
        badge.textContent = section === 'routeParams' ? 'param' : 'resolved';
        label.appendChild(badge);

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'admin-input admin-input--sm preview-emulation-field__input';
        input.value = (typeof savedValue === 'string') ? savedValue : (savedValue == null ? '' : String(savedValue));
        input.placeholder = section === 'routeParams'
            ? '{{param:' + varName + '}}'
            : '{{resolved:' + varName + '}}';

        const resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'preview-emulation-field__reset';
        resetBtn.title = QSPanelWords.t('preview.emulationResetField');
        resetBtn.appendChild(_renderSizedIcon(ICON_RESET, 12));
        resetBtn.addEventListener('click', () => { input.value = ''; });

        row.appendChild(label);
        row.appendChild(input);
        row.appendChild(resetBtn);
        return row;
    }

    /**
     * Apply emulation: store values in localStorage and reload preview.
     * Branches by edit type — pages use a nested {routeParams, resolved}
     * shape under qs_emulate_page_*, components use the existing flat
     * shape under qs_emulate_*.
     */
    function applyEmulation() {
        if (!emulationPanelFields) return;

        // Page-edit branch. Detect by either the explicit
        // edit type OR the data-page-section marker on the first field.
        const isPageEmulation = (currentEditType === 'page'
            || currentEditType === undefined
            || currentEditType === null);
        if (isPageEmulation) {
            const data = { routeParams: {}, resolved: {} };
            let hasValues = false;
            emulationPanelFields.querySelectorAll('.preview-emulation-field').forEach(row => {
                const section = row.dataset.pageSection;
                const varName = row.dataset.varName;
                const input = row.querySelector('.preview-emulation-field__input');
                const value = input?.value?.trim();
                if (!section || !varName || !value) return;
                data[section][varName] = value;
                hasValues = true;
            });
            // Preserve the live-data flag if it was set. We
            // don't toggle it here; Apply just persists the current
            // emulation values + whatever live-mode state is already
            // active. The user clicks "Use Live Data" to change the
            // mode separately.
            const previousSaved = _getPageEmulationData(currentEditName);
            if (previousSaved && previousSaved.useLive) {
                data.useLive = true;
            }
            const storageKey = QuickSiteStorageKeys.emulatePage + currentEditName;
            if (hasValues || data.useLive) {
                localStorage.setItem(storageKey, JSON.stringify(data));
            } else {
                localStorage.removeItem(storageKey);
            }
            reloadPreview();
            showToast(QSPanelWords.t('preview.emulationApplied'), 'success');
            return;
        }

        // Component-edit branch (existing flow).
        const data = {};
        let hasValues = false;

        emulationPanelFields.querySelectorAll('.preview-emulation-field').forEach(row => {
            const varName = row.dataset.varName;
            const input = row.querySelector('.preview-emulation-field__input');
            const value = input?.value?.trim();
            if (value) {
                data[varName] = value;
                hasValues = true;
            }
        });

        const storageKey = QuickSiteStorageKeys.emulateComponent + currentEditName;

        if (hasValues) {
            localStorage.setItem(storageKey, JSON.stringify(data));
        } else {
            localStorage.removeItem(storageKey);
        }

        reloadPreview();
        showToast(QSPanelWords.t('preview.emulationApplied'), 'success');
    }

    /**
     * Reset emulation: clear localStorage and reload preview. Branches
     * on edit type so the right storage key is cleared (mirrors
     * applyEmulation).
     */
    function resetEmulation() {
        const isPageEmulation = (currentEditType === 'page'
            || currentEditType === undefined
            || currentEditType === null);
        const storageKey = isPageEmulation
            ? QuickSiteStorageKeys.emulatePage + currentEditName
            : QuickSiteStorageKeys.emulateComponent + currentEditName;
        localStorage.removeItem(storageKey);

        // Reset all fields
        if (emulationPanelFields) {
            emulationPanelFields.querySelectorAll('.preview-emulation-field__input').forEach(input => {
                if (input.tagName === 'SELECT') {
                    input.value = '';
                } else {
                    input.value = '';
                }
            });
        }

        // Reset also clears the live-data flag. The full
        // "everything wiped" semantics matches the button label "Reset All".
        if (emulationLiveBtn) {
            _syncLiveBtnState(false, false);
        }

        reloadPreview();
        showToast(QSPanelWords.t('preview.emulationResetDone'), 'success');
    }

    /**
     * Toggle live-data mode for the current page.
     * Persists the flag in localStorage alongside the emulation values
     * and rebuilds the preview URL. When live mode is ON the iframe URL
     * gains _live=1; public/index.php sees that and fires the REAL
     * resolver (instead of skipping it). The emulated routeParams are
     * still applied so the URL has matchable segments AND so the live
     * resolver receives the author's chosen "preview slug".
     *
     * The resolved-section inputs stay editable when live mode is on
     * (they're just ignored at render time) — that way the author can
     * switch back to fully-emulated mode and keep their work.
     */
    function toggleLiveData() {
        if (currentEditType !== 'page'
            && currentEditType !== undefined
            && currentEditType !== null) {
            return;
        }
        const current = _getPageEmulationData(currentEditName) || { routeParams: {}, resolved: {}, useLive: false };
        const next = !current.useLive;

        // Build the persisted payload from the current panel state +
        // the new useLive value. We re-read the panel inputs so live
        // mode flips don't lose in-flight unsaved edits to routeParams.
        const data = { routeParams: {}, resolved: {}, useLive: next };
        if (emulationPanelFields) {
            emulationPanelFields.querySelectorAll('.preview-emulation-field').forEach(row => {
                const section = row.dataset.pageSection;
                const varName = row.dataset.varName;
                const input = row.querySelector('.preview-emulation-field__input');
                const value = input?.value?.trim();
                if (!section || !varName || !value) return;
                data[section][varName] = value;
            });
        }
        const storageKey = QuickSiteStorageKeys.emulatePage + currentEditName;
        const hasValues = Object.keys(data.routeParams).length > 0
                       || Object.keys(data.resolved).length > 0;
        if (hasValues || data.useLive) {
            localStorage.setItem(storageKey, JSON.stringify(data));
        } else {
            localStorage.removeItem(storageKey);
        }
        _syncLiveBtnState(next, false);
        reloadPreview();
        showToast(next
            ? QSPanelWords.t('preview.emulationLiveOn')
            : QSPanelWords.t('preview.emulationLiveOff'),
            'success');
    }

    /**
     * Sync the live-data button's visual state to
     * the persisted useLive flag. Adds the --active class when ON so
     * the user sees the current mode at a glance + flips the label.
     * Optional disabled flag for the empty-resolved case (no resolved
     * vars on this page — live mode is meaningless).
     */
    function _syncLiveBtnState(useLive, noResolvedVars) {
        if (!emulationLiveBtn) return;
        emulationLiveBtn.dataset.active = useLive ? 'true' : 'false';
        emulationLiveBtn.classList.toggle('admin-btn--primary', useLive);
        emulationLiveBtn.classList.toggle('admin-btn--outline', !useLive);
        emulationLiveBtn.disabled = !!noResolvedVars;
        const label = emulationLiveBtn.querySelector('[data-emulation-live-label]');
        if (label) {
            label.textContent = useLive
                ? QSPanelWords.t('preview.emulationUsingLive')
                : QSPanelWords.t('preview.emulationUseLive');
        }
    }

    /**
     * Detect if an enum variable is used in a class param and create CSS stubs
     * for all map values that don't already have a CSS rule.
     */
    async function createCssStubsForEnumIfNeeded(structure, varName, map) {
        try {
            // Check if {{varName}} is used in any class param
            const placeholder = '{{' + varName + '}}';
            if (!detectEnumInClassParams(structure, placeholder)) return;
            
            // Collect map values as potential class names (strip __RAW__ prefix)
            const classNames = Object.values(map)
                .map(v => String(v).replace(/^__RAW__/, '').trim())
                .filter(v => v && /^[a-zA-Z_-][\w-]*$/.test(v));
            
            if (classNames.length === 0) return;
            
            // Create empty CSS stubs for each class
            const created = [];
            for (const cls of classNames) {
                const selector = '.' + cls;
                try {
                    const result = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                        selector: selector,
                        styles: ''
                    });
                    if (result.ok && result.data?.action === 'created') {
                        created.push(selector);
                    }
                } catch (e) {
                    // Ignore individual failures — selector may already exist
                    console.warn('[Preview] CSS stub failed for', selector, e);
                }
            }
            
            if (created.length > 0) {
                const msg = QSPanelWords.t('preview.enumCssStubsCreated', { list: created.join(', ') });
                showToast(msg, 'success');
            }
        } catch (e) {
            console.warn('[Preview] CSS stub creation skipped:', e);
        }
    }
    
    /**
     * Recursively check if a placeholder string appears in any class param
     */
    function detectEnumInClassParams(node, placeholder) {
        if (!node || typeof node !== 'object') return false;
        // Skip __enums__ metadata
        if (node.__enums__) {
            const copy = Object.assign({}, node);
            delete copy.__enums__;
            return detectEnumInClassParams(copy, placeholder);
        }
        // Check params.class
        if (node.params?.class) {
            const classVal = Array.isArray(node.params.class)
                ? node.params.class.join(' ')
                : String(node.params.class);
            if (classVal.includes(placeholder)) return true;
        }
        // Recurse into children
        if (Array.isArray(node.children)) {
            for (const child of node.children) {
                if (detectEnumInClassParams(child, placeholder)) return true;
            }
        }
        // Recurse into object values (for root-level structures)
        for (const key of Object.keys(node)) {
            if (key === 'params' || key === 'children' || key === '__enums__') continue;
            if (typeof node[key] === 'object' && node[key] !== null) {
                if (detectEnumInClassParams(node[key], placeholder)) return true;
            }
        }
        return false;
    }
    
    // Deep clone a node (removes internal QS attributes) and remap textKeys
    // Returns { structure, keyMapping } where keyMapping is {oldKey: newKey}
    function deepCloneAndRemapNode(node, snippetId, counter = { value: 1 }, keyMapping = {}) {
        if (!node || typeof node !== 'object') return { structure: node, keyMapping };
        
        const clone = {};
        
        for (const key of Object.keys(node)) {
            // Skip internal QS attributes
            if (key.startsWith('data-qs-')) continue;
            
            if (key === 'textKey' && node.textKey && !node.textKey.startsWith('__RAW__')) {
                // Remap textKey to snippet namespace
                const newKey = `snippet.${snippetId}.item${counter.value++}`;
                keyMapping[node.textKey] = newKey;
                clone.textKey = newKey;
            } else if (key === 'altKey' && node.altKey && !node.altKey.startsWith('__RAW__')) {
                // Remap altKey to snippet namespace
                const newKey = `snippet.${snippetId}.item${counter.value++}`;
                keyMapping[node.altKey] = newKey;
                clone.altKey = newKey;
            } else if (key === 'children' && Array.isArray(node.children)) {
                clone.children = node.children.map(child => {
                    const result = deepCloneAndRemapNode(child, snippetId, counter, keyMapping);
                    return result.structure;
                });
            } else if (key === 'params' && typeof node.params === 'object') {
                // Clone params but remove QS internal attributes and remap translatable params
                clone.params = {};
                for (const [pKey, pVal] of Object.entries(node.params)) {
                    if (pKey.startsWith('data-qs-')) continue;
                    
                    // Check for translatable params like {{textKey:...}}
                    if (typeof pVal === 'string' && pVal.includes('{{textKey:')) {
                        const match = pVal.match(/\{\{textKey:([^}]+)\}\}/);
                        if (match && match[1]) {
                            const oldParamKey = match[1];
                            const newKey = `snippet.${snippetId}.item${counter.value++}`;
                            keyMapping[oldParamKey] = newKey;
                            clone.params[pKey] = pVal.replace(`{{textKey:${oldParamKey}}}`, `{{textKey:${newKey}}}`);
                        } else {
                            clone.params[pKey] = pVal;
                        }
                    } else {
                        clone.params[pKey] = pVal;
                    }
                }
            } else if (typeof node[key] === 'object' && node[key] !== null) {
                clone[key] = JSON.parse(JSON.stringify(node[key]));
            } else {
                clone[key] = node[key];
            }
        }
        
        return { structure: clone, keyMapping };
    }
    
    // Extract translations for keys and remap them
    // keyMapping: {oldKey: newKey}
    async function extractAndRemapTranslations(keyMapping) {
        if (!keyMapping || Object.keys(keyMapping).length === 0) return {};
        
        const oldKeys = Object.keys(keyMapping);
        
        try {
            // Get all translations (returns all languages at once)
            const transResponse = await QuickSiteAdmin.apiRequest('getTranslations', 'GET');
            if (!transResponse.ok || !transResponse.data?.data?.translations) {
                console.warn('[Preview] Could not get translations');
                return {};
            }
            
            const allTranslations = transResponse.data.data.translations;
            const result = {};
            
            // For each language in the project translations
            for (const lang of Object.keys(allTranslations)) {
                const langTranslations = allTranslations[lang];
                
                // Extract values for old keys and store with new keys
                for (const oldKey of oldKeys) {
                    // Support nested keys (e.g., "home.features.title")
                    const value = getNestedValue(langTranslations, oldKey);
                    if (value !== undefined) {
                        if (!result[lang]) result[lang] = {};
                        const newKey = keyMapping[oldKey];
                        result[lang][newKey] = value;
                    }
                }
            }
            
            console.log('[Preview] Extracted translations:', result);
            return result;
        } catch (error) {
            console.error('[Preview] Failed to extract translations:', error);
            return {};
        }
    }
    
    // Helper to get nested value from object using dot notation
    function getNestedValue(obj, path) {
        if (!obj || !path) return undefined;
        const keys = path.split('.');
        let current = obj;
        for (const key of keys) {
            if (current === undefined || current === null) return undefined;
            current = current[key];
        }
        return current;
    }
    
    // Submit save snippet form
    async function submitSaveSnippet() {
        if (!saveSnippetStructureData) {
            showToast(QSPanelWords.t('preview.noStructureData'), 'error');
            return;
        }
        
        // Validate form
        const name = saveSnippetName?.value?.trim();
        const id = saveSnippetId?.value?.trim();
        const category = saveSnippetCategory?.value || 'other';
        const description = saveSnippetDesc?.value?.trim() || '';
        
        if (!name) {
            showToast(QSPanelWords.t('preview.snippetNameRequired'), 'warning');
            saveSnippetName?.focus();
            return;
        }
        
        if (!id) {
            showToast(QSPanelWords.t('preview.snippetIdRequired'), 'warning');
            saveSnippetId?.focus();
            return;
        }
        
        // Validate ID format
        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(id)) {
            showToast(QSPanelWords.t('preview.snippetIdInvalid'), 'warning');
            saveSnippetId?.focus();
            return;
        }
        
        showToast(QSPanelWords.t('common.saving'), 'info');
        
        try {
            // Clone structure and remap textKeys to snippet namespace
            const { structure: remappedStructure, keyMapping } = deepCloneAndRemapNode(saveSnippetStructureData, id);
            
            // Extract translations using the old->new key mapping
            const translations = await extractAndRemapTranslations(keyMapping);
            
            // Call createSnippet API. 'personal' = the author's own library,
            // reusable across THEIR projects.
            const scope = saveSnippetPersonal?.checked ? 'personal' : 'project';
            
            const requestData = {
                id: id,
                name: name,
                category: category,
                description: description,
                structure: remappedStructure,
                translations: translations,
                scope: scope
            };
            
            console.log('[Preview] Creating snippet:', requestData);
            
            const response = await QuickSiteAdmin.apiRequest('createSnippet', 'POST', requestData);
            
            if (response.ok) {
                showToast(QSPanelWords.t('preview.snippetSaved'), 'success');
                
                // Close form
                hideSaveSnippetForm();
                
                // Invalidate snippet cache so next tab switch reloads
                snippetsLoaded = false;
            } else {
                throw new Error(response.data?.message || QSPanelWords.t('preview.snippetSaveFailed'));
            }
        } catch (error) {
            console.error('[Preview] Save snippet error:', error);
            showToast(error.message || QSPanelWords.t('common.saveFailed'), 'error');
        }
    }
    
    // ==================== Tag Classification (Add/Edit Shared) ====================
    
    // Tag classification, emitted by PHP from TagRegistry::editorPayload().
    //
    // ⚠ DO NOT WRITE A TAG LIST HERE. This used to be a hand-maintained mirror
    // of the server's lists and it had drifted: it offered `embed` and `object`
    // (both blocked server-side, so the editor advertised tags the server would
    // refuse), it demanded `alt` on img/area as a MANDATORY param the server
    // never required, and it had lost `dl`. TagRegistry is the single source of
    // truth — add tags and params there and they arrive here for free.
    const TAG_INFO = PreviewConfig.tagInfo || {};
    if (!PreviewConfig.tagInfo) {
        // Loud, because the silent version is an editor that quietly stops
        // asking for mandatory params.
        console.error('[Preview] PreviewConfig.tagInfo missing — tag classification '
            + 'is unavailable. preview-config.php did not emit TagRegistry::editorPayload().');
    }

    // Navigate structure tree to find node by path
    function navigateToNode(structure, nodePath) {
        if (!structure || !nodePath) return null;
        
        const pathParts = nodePath.split('.');
        let current = structure;
        
        for (let i = 0; i < pathParts.length; i++) {
            const index = parseInt(pathParts[i], 10);
            
            // For the root, check if there's a 'children' array
            if (i === 0) {
                if (Array.isArray(current)) {
                    current = current[index];
                } else if (current.children && Array.isArray(current.children)) {
                    current = current.children[index];
                } else if (current.structure && current.structure.children) {
                    current = current.structure.children[index];
                } else {
                    return null;
                }
            } else {
                if (current && current.children && Array.isArray(current.children)) {
                    current = current.children[index];
                } else {
                    return null;
                }
            }
            
            if (!current) return null;
        }
        
        return current;
    }
    
    
    // ==================== The simple Add ====================

    /** The simple Add added its element: as the HTML Tag tab's add ends (the toast, the form closed, the element shown). */
    function _finishSimpleAdd(r) {
        showToast(QSPanelWords.t('preview.nodeAdded'), 'success');
        hideSidebarAddForm();
        const t = r.target || {};
        if (r.command === 'addNode' && r.data && r.data.html && t.node) {
            sendToIframe('insertNode', {
                struct: t.struct,
                targetNode: t.node,
                position: r.position,
                html: r.data.html,
                newNodeId: r.data.newNodeId,
                movedText: r.data.movedTextKeyChildren || 0
            });
        } else {
            reloadPreview();
        }
        broadcastStructureChanged();
    }

    /**
     * One editing language: the toolbar's, the Translation tool's and the Translations page's, remembered for the
     * project in this browser (QSSiteWords). A change reloads the frame in the language; the Translation tool stays
     * open and shows it, every other tool returns to Select as a move to another page does.
     */
    function setEditingLanguage(code, from) {
        if (!langSelect || !code) return;
        if (langSelect.value !== code) langSelect.value = code;
        if (window.QSSiteWords) QSSiteWords.rememberLanguage(code);
        if (from !== 'tool' && currentMode === 'translation' && window.PreviewTranslation) {
            PreviewTranslation.setLanguage(code);
        }
        navigateTo(currentEditType, currentEditName, { keepMode: currentMode === 'translation' });
    }

    if (window.PreviewTranslation && PreviewTranslation.connect) {
        PreviewTranslation.connect({
            language: () => (multilingual ? getCurrentLang() : null),
            setLanguage: (code) => setEditingLanguage(code, 'tool')
        });
    }

    // The site's words changed (here or in another tab of this project): a text being edited starts from the word as
    // it is now, and the languages another tab added, removed or made the default reach the toolbar.
    window.addEventListener('quicksite:translations-changed', function (e) {
        const detail = (e && e.detail) || {};
        if (detail.remote && detail.languagesChanged) refreshEditList();
        if (currentMode === 'text') sendStoredWords();
    });

    if (window.PreviewSimpleAdd) {
        PreviewSimpleAdd.init({
            target: () => ({ struct: selectedStruct, node: selectedNode, frameNode: selectedFrameNode }),
            position: getAddPosition,
            structInfo: () => parseStruct(selectedStruct),
            frameDocument: () => {
                try { return iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document) || null; } catch (e) { return null; }
            },
            editedLanguage: () => (langSelect && langSelect.value) || defaultLang,
            openAssetPicker: openAssetPicker,
            holdsChildren: () => selectedHoldsChildren,
            setPosition: (p) => { setAddPosition(p); _syncAddPositionOptions(); },
            finish: _finishSimpleAdd
        });
    }

    // ==================== The panel's mode ====================
    // A switch of mode (the account menu's Mode row, js/core/panel-mode.js) applies at once. Before it, the editor
    // says what the switch would close or start again, and the person may stay; after it, the editor leaves what
    // the new mode does not show.

    /** What a switch to `toMode` would take away here: {tool} (a tool or part the mode hides) or {addForm}, or null. */
    function _modeSwitchLoss(toMode) {
        const addFormOpen = currentMode === 'add' && contextualAddMode === 'add'
            && contextualAddForm && contextualAddForm.style.display !== 'none';
        if (addFormOpen) return { addForm: true };
        if (toMode !== 'simple') return null;
        const words = (el) => (el ? el.textContent.trim() : '');
        if (currentMode === 'js') {
            return { tool: words(Array.from(modeBtns).find(b => b.dataset.mode === 'js')) };
        }
        if (currentMode === 'style' && window.PreviewStyleSource && PreviewStyleSource.isActive()) {
            return { tool: words(sourceBtn) };
        }
        if (currentMode === 'style' && activeStyleTab === 'animations') {
            return { tool: words(styleTabs && styleTabs.querySelector('[data-tab="animations"]')) };
        }
        return null;
    }

    (window.QuickSitePanelModeGuards = window.QuickSitePanelModeGuards || []).push(function (toMode) {
        const loss = _modeSwitchLoss(toMode);
        if (!loss) return true;
        const modeName = QSPanelWords.t(toMode === 'simple' ? 'nav.modeSimple' : 'nav.modeAdvanced');
        const message = loss.addForm
            ? QSPanelWords.t('preview.modeSwitch.redraws', { mode: modeName })
            : QSPanelWords.t('preview.modeSwitch.closes', { mode: modeName, tool: loss.tool });
        return QuickSiteUtils.confirm(message, {
            title: QSPanelWords.t('preview.modeSwitch.title'),
            type: 'warning',
            confirmText: QSPanelWords.t('preview.modeSwitch.switch'),
            cancelText: QSPanelWords.t('preview.modeSwitch.stay')
        });
    });

    document.addEventListener('quicksite:panel-mode', function () {
        // A tool the mode no longer shows: left for Select. The person answered the switch's question already, so
        // nothing asks again (switchMode(), not setMode()), and Source's unsaved edits go with it.
        if (currentMode !== 'add' && !toolOffered(currentMode)) {
            switchMode('select', null);
        }
        if (currentMode === 'style') {
            if (window.PreviewStyleSource && PreviewStyleSource.isActive() && !controlOffered(sourceAdvancedRow)) deactivateSource();
            const animationsTab = styleTabs && styleTabs.querySelector('[data-tab="animations"]');
            if (activeStyleTab === 'animations' && !controlOffered(animationsTab)) {
                styleTabs.querySelector('[data-tab="theme"]')?.click();
            }
        }
        // An Add form open at the switch starts again in the new mode, on the same element and position.
        if (currentMode === 'add' && contextualAddMode === 'add' && contextualAddForm && contextualAddForm.style.display !== 'none') {
            const position = getAddPosition();
            showSidebarAddForm();
            setAddPosition(position);
            _syncAddPositionOptions();
            if (window.PreviewSimpleAdd) PreviewSimpleAdd.refresh();
        }
    });

    // ==================== Public API ====================
    
    window.PreviewManager = {
        reload: reloadPreview,
        navigateTo: navigateTo,
        refreshEditList: refreshEditList,
        setDevice: setDevice,
        setMode: setMode,
        embedOutsidePolicy: embedOutsidePolicy,
        getCurrentDevice: () => currentDevice,
        getCurrentMode: () => currentMode,
        highlightNode: (struct, node) => sendToIframe('highlightNode', { struct, node }),
        getSelectedNode: () => ({
            struct: selectedStruct,
            node: selectedNode,
            component: selectedComponent,
            tag: selectedElementTag || null,
            classes: selectedElementClasses
                ? String(selectedElementClasses).split(/\s+/).filter(Boolean)
                : []
        }),
    };
    
    // Initial state
    startLoadingTimeout();
    
    // Check for deep-link target from URL parameter (e.g., ?target=page-home)
    const urlTarget = new URLSearchParams(window.location.search).get('target');
    // A page or a component the link set the list to: the frame, which opened on the site's root,
    // moves to it below. A menu or a footer is left to the first page, as the list is.
    let deepLinked = false;
    if (urlTarget && targetSelect) {
        // Parse target format: "page-home", "component-card", "menu", "footer"
        const parsed = parseStruct(urlTarget);
        if (parsed) {
            const selectValue = parsed.name ? `${parsed.type}:${parsed.name}` : `layout:${parsed.type}`;
            const option = targetSelect.querySelector(`option[value="${CSS.escape(selectValue)}"]`);
            if (option) {
                showEditTarget(selectValue);
                deepLinked = !selectValue.startsWith('layout:');
            }
        }
        // Clean URL without reloading
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, '', cleanUrl);
    }

    // The language remembered for this project in this browser (QSSiteWords): the frame, which opened on the default
    // language, moves to it below. A language the project no longer has leaves the default.
    let languageRemembered = false;
    if (langSelect && multilingual && window.QSSiteWords) {
        const codes = Array.from(langSelect.options).map((option) => option.value);
        const remembered = QSSiteWords.editingLanguage(codes, langSelect.value, PreviewConfig.currentProject);
        if (remembered !== langSelect.value) {
            langSelect.value = remembered;
            languageRemembered = true;
        }
    }

    // Initialize edit target state from dropdown
    // Default to first page option (skip layout options which are just for switching to)
    if (targetSelect && targetSelect.value) {
        let value = targetSelect.value;
        
        // On initial load, select the first page instead of layout options
        if (value.startsWith('layout:')) {
            const firstPageOption = targetSelect.querySelector('option[value^="page:"]');
            if (firstPageOption) {
                showEditTarget(firstPageOption.value);
                value = firstPageOption.value;
            }
        }
        
        const colonIdx = value.indexOf(':');
        if (colonIdx !== -1) {
            currentEditType = value.substring(0, colonIdx);
            currentEditName = value.substring(colonIdx + 1);
        } else {
            // Legacy format fallback
            currentEditType = 'page';
            currentEditName = value;
        }
        // A deep link (the Sitemap's edit link) set the list, or the remembered language is not the default: the
        // frame moves to it, as the list's own change moves it. Without either, the frame's first address already
        // shows the list's first page in the default language.
        if (deepLinked || languageRemembered) {
            loadFrame(currentEditType, currentEditName);
        }
        
        // Update component warning on initial load
        updateComponentWarning(currentEditType, currentEditName);
        // And the embed warning, as navigateTo() does for every later structure
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => updateIframeWarning(currentEditType, currentEditName));
        } else {
            updateIframeWarning(currentEditType, currentEditName);
        }
        // Update layout toggles visibility on initial load
        updateLayoutToggles(currentEditType, currentEditName);
        // The title field reads through the panel's API client, which a later script of the
        // page defines (admin.js): the first read waits for the page's scripts.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                updatePageTitleField(currentEditType, currentEditName);
                updatePageDescriptionField(currentEditType, currentEditName);
            });
        } else {
            updatePageTitleField(currentEditType, currentEditName);
            updatePageDescriptionField(currentEditType, currentEditName);
        }
    }
    
    // Ensure mode is reset to 'select' on page load (browser may cache button states)
    setMode('select');
    
    // Try to inject overlay immediately if iframe is already loaded
    // (handles case where script runs after iframe load event)
    // Guard: skip about:blank / empty documents — the load event will handle the real page
    if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
        const loc = iframe.contentWindow?.location?.href || '';
        if (loc && loc !== 'about:blank' && loc !== '') {
            console.log('[Preview] Iframe already loaded, injecting overlay');
            injectOverlay();
        }
    }

    // Export shared classes for cross-module use (preview-style-editor.js)
    window.QSPropertySelector = QSPropertySelector;
    window.QSValueInput = QSValueInput;
    window.KEYFRAME_PROPERTY_TYPES = KEYFRAME_PROPERTY_TYPES;
})();
