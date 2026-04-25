/**
 * Visual Preview Page JavaScript
 * Extracted from preview.php for browser caching
 * 
 * Dependencies:
 * - PreviewConfig global (set by inline script in preview.php)
 * - QuickSiteAdmin global (from admin.js)
 * - ColorPicker (from colorpicker.js)
 * 
 * @version 1.0.0
 * @filesize ~5,400 lines
 */
(function() {
    'use strict';
    
    // DOM Elements
    const iframe = document.getElementById('preview-iframe');
    const container = document.getElementById('preview-container');
    const workspace = document.querySelector('.preview-workspace');
    const wrapper = document.getElementById('preview-frame-wrapper');
    const previewResizeHandle = document.getElementById('preview-resize-handle');
    const loading = document.getElementById('preview-loading');
    const targetSelect = document.getElementById('preview-target');  // Unified page/component dropdown
    const langSelect = document.getElementById('preview-lang');
    const reloadBtn = document.getElementById('preview-reload');
    const deviceBtns = document.querySelectorAll('.preview-device-btn');
    const modeBtns = document.querySelectorAll('.preview-sidebar-tool[data-mode]');
    
    // Node panel elements
    const nodePanel = document.getElementById('preview-node-panel');
    const nodeClose = document.getElementById('preview-node-close');
    const nodeStructEl = document.getElementById('node-struct');
    const nodeIdEl = document.getElementById('node-id');
    const nodeComponentRow = document.getElementById('node-component-row');
    const nodeComponentEl = document.getElementById('node-component');
    const nodeTagEl = document.getElementById('node-tag');
    const nodeClassesEl = document.getElementById('node-classes');
    const nodeChildrenEl = document.getElementById('node-children');
    const nodeTextEl = document.getElementById('node-text');
    const nodeTextKeyRow = document.getElementById('node-textkey-row');
    const nodeTextKeyEl = document.getElementById('node-textkey');
    const nodeDeleteBtn = document.getElementById('node-delete');
    
    // Contextual area elements (Phase 8)
    const contextualArea = document.getElementById('preview-contextual-area');
    const contextualToggle = document.getElementById('preview-contextual-toggle');
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
    const ctxNodeDelete = document.getElementById('ctx-node-delete');
    const ctxNodeDuplicate = document.getElementById('ctx-node-duplicate');
    const ctxNodeStyle = document.getElementById('ctx-node-style');
    const ctxNodeSaveSnippet = document.getElementById('ctx-node-save-snippet');
    const ctxNodeVariables = document.getElementById('ctx-node-variables');
    
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
    const saveSnippetGlobal = document.getElementById('save-snippet-global');
    
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
    const createComponentBtn = document.getElementById('preview-create-component');

    // Back to Select button (in add form)
    const addBackToSelect = document.getElementById('add-back-to-select');
    
    // Tools show names checkbox
    const sidebarTools = document.getElementById('preview-sidebar-tools');
    const toolsShowNames = document.getElementById('preview-tools-show-names');
    
    // Drag tool options — DOM refs and logic extracted to preview-drag.js (window.PreviewDrag)
    
    // Mobile sections elements (low-width mode)
    const mobileSections = document.getElementById('preview-mobile-sections');
    const mobileSectionInfo = document.getElementById('mobile-section-info');
    const mobileSectionActions = document.getElementById('mobile-section-actions');
    const mobileInfoSummary = document.getElementById('mobile-info-summary');
    const mobileActionsMode = document.getElementById('mobile-actions-mode');
    const mobileCtxId = document.getElementById('mobile-ctx-id');
    const mobileCtxTag = document.getElementById('mobile-ctx-tag');
    const mobileCtxClasses = document.getElementById('mobile-ctx-classes');
    const mobileCtxChildren = document.getElementById('mobile-ctx-children');
    const mobileCtxTextKeyRow = document.getElementById('mobile-ctx-textkey-row');
    const mobileCtxTextKey = document.getElementById('mobile-ctx-textkey');
    const mobileCtxComponentRow = document.getElementById('mobile-ctx-component-row');
    const mobileCtxComponent = document.getElementById('mobile-ctx-component');
    const mobileCtxAdd = document.getElementById('mobile-ctx-add');
    const mobileCtxDuplicate = document.getElementById('mobile-ctx-duplicate');
    const mobileCtxDelete = document.getElementById('mobile-ctx-delete');
    
    // Global Element Info Bar (at bottom of preview area)
    const globalElementInfo = document.getElementById('preview-element-info');
    const globalElementInfoToggle = document.getElementById('preview-element-info-toggle');
    const globalElementInfoSummary = document.getElementById('preview-element-info-summary');
    const globalElementInfoDetails = document.getElementById('preview-element-info-details');
    const globalInfoNodeId = document.getElementById('info-node-id');
    const globalInfoNodeTag = document.getElementById('info-node-tag');
    const globalInfoNodeClasses = document.getElementById('info-node-classes');
    const globalInfoNodeChildren = document.getElementById('info-node-children');
    const globalInfoNodeTextKeyRow = document.getElementById('info-node-textkey-row');
    const globalInfoNodeTextKey = document.getElementById('info-node-textkey');
    const globalInfoNodeComponentRow = document.getElementById('info-node-component-row');
    const globalInfoNodeComponent = document.getElementById('info-node-component');
    
    // Theme panel elements (Phase 8.3)
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
    
    // Selector browser elements (Phase 8.4)
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
    const selectorMatchCount = document.getElementById('selector-match-count');
    const selectorEditBtn = document.getElementById('selector-edit-btn');
    const selectorAnimateBtn = document.getElementById('selector-animate-btn');
    
    // Style Editor elements (Phase 8.5)
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
    
    // Animations panel elements (Phase 9.2)
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
    
    // Keyframe Editor Modal elements (Phase 9.3)
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
    
    // Component Warning Banner (Phase 2E)
    const componentWarning = document.getElementById('preview-component-warning');
    const componentWarningText = document.getElementById('preview-component-warning-text');
    const deleteComponentBtn = document.getElementById('preview-delete-component');
    
    // Iframe Warning Banner
    const iframeWarning = document.getElementById('preview-iframe-warning');
    const iframeWarningClose = document.getElementById('preview-iframe-warning-close');
    let iframeWarningDismissed = false;

    // Sidebar Add Form Elements (Phase 8 - Add Mode)
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
    const addSnippetPreviewActions = document.getElementById('add-snippet-preview-actions');
    const deleteSnippetBtn = document.getElementById('delete-snippet-btn');
    const addPositionPicker = document.getElementById('add-position-picker');
    const addMandatoryParams = document.getElementById('add-mandatory-params');
    const addMandatoryParamsContainer = document.getElementById('add-mandatory-params-container');
    const addClassField = document.getElementById('add-class-field');
    const addClassInput = document.getElementById('add-class');
    const addCustomParamsContainer = document.getElementById('add-custom-params-container');
    const addAdvancedSection = document.getElementById('add-advanced-section');
    const addPreviewSection = document.getElementById('add-preview-section');
    const addPositionSection = document.getElementById('add-position-section');
    const addConfirmTopBtn = document.getElementById('add-confirm-top');
    
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
    const addCustomParamsList = document.getElementById('add-custom-params-list');
    const addAnotherParamBtn = document.getElementById('add-another-param');
    const addTextKeyInfo = document.getElementById('add-textkey-info');
    const addGeneratedTextKeyPreview = document.getElementById('add-generated-textkey-preview');
    const addAltKeyInfo = document.getElementById('add-altkey-info');
    const addGeneratedAltKeyPreview = document.getElementById('add-generated-altkey-preview');
    const addComponentVars = document.getElementById('add-component-vars');
    const addComponentVarsContainer = document.getElementById('add-component-vars-container');
    const addComponentNoVars = document.getElementById('add-component-no-vars');
    const addCancelBtn = document.getElementById('add-cancel');
    const addConfirmBtn = document.getElementById('add-confirm');
    
    // Configuration
    const baseUrl = PreviewConfig.baseUrl;
    const adminUrl = PreviewConfig.adminUrl;
    const managementUrl = PreviewConfig.managementUrl;
    const authToken = PreviewConfig.authToken;
    const structureUrl = PreviewConfig.structureUrl;
    const multilingual = PreviewConfig.multilingual;
    const defaultLang = PreviewConfig.defaultLang;
    
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
    
    // Device sizes
    const devices = {
        desktop: { width: '100%', height: '100%' },
        tablet: { width: '768px', height: '1024px' },
        mobile: { width: '375px', height: '667px' }
    };
    
    // State
    let currentDevice = 'desktop';
    let currentMode = 'select';
    let currentEditType = 'page';     // 'page', 'component', or 'layout'
    let currentEditName = '';         // route name for pages, component name for components, 'menu'/'footer' for layout
    let overlayInjected = false;
    let layoutAutoSelectTarget = null; // 'menu' or 'footer' - auto-select after iframe loads
    
    // Theme variables state (Phase 8.3)
    let themeVariablesLoaded = false;
    let originalThemeVariables = {};  // Original values from CSS file
    let currentThemeVariables = {};   // Current working values (modified)
    let activeStyleTab = 'theme';     // 'theme' or 'selectors'
    
    // Selector browser state (Phase 8.4)
    let selectorsLoaded = false;
    let allSelectors = [];            // All selectors from CSS
    let categorizedSelectors = { tags: [], classes: [], ids: [], attributes: [], media: {} };
    let currentSelectedSelector = null;  // Currently selected selector
    let hoveredSelector = null;       // Currently hovered selector (for highlight)
    
    // Page structure classes (for JS mode picker)
    let pageStructureClasses = [];    // Classes from actual DOM (not just CSS)
    
    // Style Editor state (Phase 8.5)
    let styleEditorVisible = false;
    let editingSelector = null;       // Selector being edited
    let editingSelectorCount = 0;     // Number of matching elements
    let originalStyles = {};          // Original property values from CSS
    let currentStyles = {};           // Current working values (modified)
    let newProperties = [];           // Newly added properties
    let deletedProperties = [];       // Original properties that have been deleted
    let stylePreviewInjected = false; // Whether live preview style is injected
    
    // Animations tab state (Phase 9.2)
    let animationsLoaded = false;     // Whether animations data has been loaded
    let keyframesData = [];           // All @keyframes from CSS
    let animatedSelectorsData = {     // Selectors with transition/animation properties
        transitions: [],
        animations: [],
        triggersWithoutTransition: []
    };
    let keyframePreviewActive = null; // Name of keyframe being previewed
    
    // Keyframe Editor state (Phase 9.3)
    let keyframeEditorMode = 'edit';  // 'edit' or 'create'
    let editingKeyframeName = null;   // Original name (for rename detection)
    let keyframeFrames = {};          // Current frame data: { '0%': { opacity: '0' }, '100%': { opacity: '1' } }
    let selectedFramePercent = null;  // Currently selected frame in timeline
    
    // ==================== Property Type Registry (Phase 9.3.1) ====================
    
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
        
        // Complex properties - text input fallback (Phase 2 for specialized editors)
        'transform': { type: 'transform' },  // Phase 9.3.1 Step 5: Transform Sub-Editor
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
     * Used by QSPropertySelector class
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
            this.placeholder = options.placeholder || PreviewConfig.i18n.selectProperty;
            this.searchPlaceholder = options.searchPlaceholder || PreviewConfig.i18n.searchProperties;
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
            this.container.innerHTML = '';
            this.container.className = 'qs-property-selector';
            
            // Create trigger button
            this.triggerEl = document.createElement('button');
            this.triggerEl.type = 'button';
            this.triggerEl.className = 'qs-property-selector__trigger';
            this.triggerEl.innerHTML = `
                <span class="qs-property-selector__text">${this.currentValue || this.placeholder}</span>
                ${QuickSiteUtils.iconChevronDown(12)}
            `;
            
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
                            showToast(PreviewConfig.i18n.propertyAlreadyExists, 'warning');
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
            this.container.innerHTML = '';
        }
        
        _renderList(listEl, filter = '') {
            listEl.innerHTML = '';
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
                    label.textContent = category;
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
                    
                    item.innerHTML = `
                        <span>${prop}</span>
                        ${typeLabel ? `<span class="qs-property-selector__item-type">${typeLabel}</span>` : ''}
                    `;
                    
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
                    warningItem.innerHTML = `
                        ${QuickSiteUtils.iconAlertCircle(14)}
                        <span>${PreviewConfig.i18n.propertyAlreadyExists}</span>
                    `;
                    listEl.appendChild(warningItem);
                } else {
                    const customItem = document.createElement('button');
                    customItem.type = 'button';
                    customItem.className = 'qs-property-selector__item qs-property-selector__item--custom';
                    customItem.innerHTML = `
                        <span>${PreviewConfig.i18n.useCustomProperty} <strong>${filter}</strong></span>
                    `;
                    customItem.addEventListener('click', () => this.select(filter));
                    listEl.appendChild(customItem);
                    this.allItems.push(customItem);
                }
            }
            
            // Show empty state
            if (this.allItems.length === 0 && !filterLower) {
                listEl.innerHTML = `<div class="qs-property-selector__empty">${PreviewConfig.i18n.noPropertiesFound}</div>`;
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
     * QSValueInput - Reusable CSS value input component (Phase 10.3)
     * Creates appropriate input controls based on CSS property type:
     * - range: Slider with value display
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
                    'range-value': 'preview-style-property__range-value',
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
            this.container.innerHTML = '';
            this.colorSwatchEl = null;
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
            
            const range = document.createElement('input');
            range.type = 'range';
            range.className = this._getClass('range');
            range.min = propType.min ?? 0;
            range.max = propType.max ?? 1;
            range.step = propType.step ?? 0.01;
            range.value = parseFloat(this.currentValue) || propType.min || 0;
            
            const valueDisplay = document.createElement('span');
            valueDisplay.className = this._getClass('range-value');
            valueDisplay.textContent = range.value;
            
            range.addEventListener('input', () => {
                this.currentValue = range.value;
                valueDisplay.textContent = range.value;
                this.onChange(range.value);
            });
            
            range.addEventListener('blur', () => this.onBlur(this.currentValue));
            
            this.inputEl = range;
            this.container.appendChild(range);
            this.container.appendChild(valueDisplay);
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
            swatch.title = PreviewConfig.i18n.clickToPickColor;
            
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
                const displayClass = this._getClass('range-value');
                const display = this.container.querySelector(`.${displayClass}`);
                if (display) display.textContent = value;
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
            this.container.innerHTML = '';
        }
    }
    
    /**
     * Get the input type configuration for a CSS property
     * @param {string} propertyName - CSS property name
     * @returns {object} Configuration object with type and settings
     */
    function getPropertyInputType(propertyName) {
        const prop = propertyName.toLowerCase().trim();
        return KEYFRAME_PROPERTY_TYPES[prop] || { type: 'text' };
    }
    
    /**
     * Parse a CSS length value into number and unit parts
     * @param {string} value - CSS value like "100px", "50%", "1.5em"
     * @returns {object} { num: number, unit: string }
     */
    function parseLength(value) {
        if (!value || value === 'auto' || value === 'none' || value === 'normal') {
            return { num: '', unit: value || '' };
        }
        const match = String(value).match(/^(-?[\d.]+)(.*)$/);
        if (match) {
            return { num: parseFloat(match[1]), unit: match[2].trim() || 'px' };
        }
        return { num: '', unit: value };
    }
    
    /**
     * Parse a CSS angle value into number and unit parts
     * @param {string} value - CSS value like "45deg", "1.5rad"
     * @returns {object} { num: number, unit: string }
     */
    function parseAngle(value) {
        if (!value) return { num: 0, unit: 'deg' };
        const match = String(value).match(/^(-?[\d.]+)(.*)$/);
        if (match) {
            return { num: parseFloat(match[1]), unit: match[2].trim() || 'deg' };
        }
        return { num: 0, unit: 'deg' };
    }
    
    /**
     * Render a property-specific input based on the property type
     * @param {string} property - CSS property name
     * @param {string} value - Current value
     * @param {number} frameIndex - Frame index
     * @param {number} propIndex - Property index
     * @returns {string} HTML string for the input
     */
    function renderPropertyValueInput(property, value, frameIndex, propIndex) {
        const config = getPropertyInputType(property);
        const dataAttrs = `data-frame="${frameIndex}" data-prop="${propIndex}" data-field="value"`;
        
        switch (config.type) {
            case 'range':
                const rangeVal = parseFloat(value) || config.min || 0;
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--range">
                        <input type="range" class="preview-keyframe-modal__property-range" 
                               min="${config.min}" max="${config.max}" step="${config.step}"
                               value="${rangeVal}" ${dataAttrs}>
                        <span class="preview-keyframe-modal__property-range-value">${rangeVal}</span>
                    </div>`;
            
            case 'color':
                const colorVal = escapeHTML(value || '#000000');
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--color">
                        <button type="button" class="preview-keyframe-modal__color-picker-btn" 
                                style="background: ${colorVal};"
                                title="${PreviewConfig.i18n.clickToPickColor}"></button>
                        <input type="text" class="preview-keyframe-modal__property-value preview-keyframe-modal__property-value--color" 
                               value="${colorVal}" ${dataAttrs}
                               placeholder="${PreviewConfig.i18n.colorValue}">
                    </div>`;
            
            case 'length':
                const lengthParsed = parseLength(value);
                const unitOptions = config.units.map(u => 
                    `<option value="${u}" ${lengthParsed.unit === u ? 'selected' : ''}>${u || '(none)'}</option>`
                ).join('');
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--length">
                        <input type="number" class="preview-keyframe-modal__property-number" 
                               value="${lengthParsed.num}" step="any" ${dataAttrs}>
                        <select class="preview-keyframe-modal__property-unit" 
                                data-frame="${frameIndex}" data-prop="${propIndex}" data-field="unit">
                            ${unitOptions}
                        </select>
                    </div>`;
            
            case 'angle':
                const angleParsed = parseAngle(value);
                const angleUnitOptions = config.units.map(u => 
                    `<option value="${u}" ${angleParsed.unit === u ? 'selected' : ''}>${u}</option>`
                ).join('');
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--angle">
                        <input type="number" class="preview-keyframe-modal__property-number" 
                               value="${angleParsed.num}" step="any" ${dataAttrs}>
                        <select class="preview-keyframe-modal__property-unit" 
                                data-frame="${frameIndex}" data-prop="${propIndex}" data-field="unit">
                            ${angleUnitOptions}
                        </select>
                    </div>`;
            
            case 'enum':
                const enumOptions = config.values.map(v => 
                    `<option value="${v}" ${value === v ? 'selected' : ''}>${v}</option>`
                ).join('');
                return `
                    <select class="preview-keyframe-modal__property-enum" ${dataAttrs}>
                        ${enumOptions}
                    </select>`;
            
            case 'number':
                const numVal = value !== '' ? parseFloat(value) : '';
                return `
                    <input type="number" class="preview-keyframe-modal__property-value preview-keyframe-modal__property-value--number" 
                           value="${numVal}" step="${config.step || 1}" 
                           ${config.min !== undefined ? `min="${config.min}"` : ''} ${dataAttrs}>`;
            
            case 'transform':
                // Transform editor - text input + Edit button
                return `
                    <div class="preview-keyframe-modal__property-input-group preview-keyframe-modal__property-input-group--transform">
                        <input type="text" class="preview-keyframe-modal__property-value preview-keyframe-modal__property-value--transform" 
                               value="${escapeHTML(value || 'none')}" 
                               placeholder="${PreviewConfig.i18n.transformValue}" ${dataAttrs}>
                        <button type="button" class="preview-keyframe-modal__transform-edit-btn admin-btn admin-btn--xs admin-btn--secondary"
                                data-frame="${frameIndex}" data-prop="${propIndex}"
                                title="${PreviewConfig.i18n.openTransformEditor}">
                            ${QuickSiteUtils.iconEdit(12)}
                            ${PreviewConfig.i18n.edit}
                        </button>
                    </div>`;
            
            default: // text fallback
                return `
                    <input type="text" class="preview-keyframe-modal__property-value" 
                           value="${escapeHTML(value)}" 
                           placeholder="${PreviewConfig.i18n.keyframePropertyValue}" ${dataAttrs}>`;
        }
    }
    
    /**
     * Attach event handlers for property value inputs based on their type
     * @param {HTMLElement} propEl - The property row element
     * @param {number} frameIndex - Frame index
     * @param {number} propIndex - Property index
     * @param {string} property - CSS property name
     */
    function attachPropertyValueHandlers(propEl, frameIndex, propIndex, property) {
        const config = getPropertyInputType(property);
        
        switch (config.type) {
            case 'range':
                // Range slider with live value display
                const rangeInput = propEl.querySelector('.preview-keyframe-modal__property-range');
                const rangeValue = propEl.querySelector('.preview-keyframe-modal__property-range-value');
                if (rangeInput) {
                    rangeInput.addEventListener('input', (e) => {
                        const val = e.target.value;
                        rangeValue.textContent = val;
                        keyframeFrames[frameIndex].properties[propIndex].value = val;
                    });
                }
                break;
            
            case 'color':
                // Color picker button + text input
                const colorInput = propEl.querySelector('.preview-keyframe-modal__property-value--color');
                const colorBtn = propEl.querySelector('.preview-keyframe-modal__color-picker-btn');
                if (colorInput) {
                    // Manual input change
                    colorInput.addEventListener('input', (e) => {
                        const val = e.target.value;
                        keyframeFrames[frameIndex].properties[propIndex].value = val;
                        if (colorBtn) {
                            colorBtn.style.background = val;
                        }
                    });
                    
                    // Initialize QSColorPicker attached to the input
                    if (typeof QSColorPicker !== 'undefined') {
                        const picker = new QSColorPicker(colorInput, {
                            showAlpha: true,
                            onChange: (color) => {
                                keyframeFrames[frameIndex].properties[propIndex].value = color;
                                if (colorBtn) {
                                    colorBtn.style.background = color;
                                }
                            }
                        });
                        
                        // Also open picker when button is clicked
                        if (colorBtn) {
                            colorBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                picker.open();
                            });
                        }
                    } else if (colorBtn) {
                        // Fallback: focus the text input when button clicked
                        colorBtn.addEventListener('click', () => {
                            colorInput.focus();
                            colorInput.select();
                        });
                    }
                }
                break;
            
            case 'length':
            case 'angle':
                // Number input + unit dropdown
                const numInput = propEl.querySelector('.preview-keyframe-modal__property-number');
                const unitSelect = propEl.querySelector('.preview-keyframe-modal__property-unit');
                if (numInput && unitSelect) {
                    const updateValue = () => {
                        const num = numInput.value;
                        const unit = unitSelect.value;
                        // Handle special units like 'auto', 'none', 'normal'
                        let combinedValue;
                        if (unit === 'auto' || unit === 'none' || unit === 'normal') {
                            combinedValue = unit;
                        } else if (num === '' || num === null) {
                            combinedValue = '';
                        } else {
                            combinedValue = num + unit;
                        }
                        keyframeFrames[frameIndex].properties[propIndex].value = combinedValue;
                    };
                    
                    numInput.addEventListener('input', updateValue);
                    unitSelect.addEventListener('change', () => {
                        // If selecting 'auto', 'none', or 'normal', clear the number
                        const unit = unitSelect.value;
                        if (unit === 'auto' || unit === 'none' || unit === 'normal') {
                            numInput.value = '';
                            numInput.disabled = true;
                        } else {
                            numInput.disabled = false;
                        }
                        updateValue();
                    });
                    
                    // Initial state check for special units
                    if (['auto', 'none', 'normal'].includes(unitSelect.value)) {
                        numInput.disabled = true;
                    }
                }
                break;
            
            case 'enum':
                // Enum dropdown
                const enumSelect = propEl.querySelector('.preview-keyframe-modal__property-enum');
                if (enumSelect) {
                    enumSelect.addEventListener('change', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                break;
            
            case 'number':
                // Unitless number input
                const plainNumInput = propEl.querySelector('.preview-keyframe-modal__property-value--number');
                if (plainNumInput) {
                    plainNumInput.addEventListener('input', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                break;
            
            case 'transform':
                // Transform editor - text input + Edit button
                const transformTextInput = propEl.querySelector('.preview-keyframe-modal__property-value--transform');
                const transformEditBtn = propEl.querySelector('.preview-keyframe-modal__transform-edit-btn');
                
                // Text input for manual editing
                if (transformTextInput) {
                    transformTextInput.addEventListener('input', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                
                // Edit button to open Transform Editor modal
                if (transformEditBtn) {
                    transformEditBtn.addEventListener('click', () => {
                        const currentValue = keyframeFrames[frameIndex].properties[propIndex].value || '';
                        
                        // Live preview target is optional - we don't have direct access
                        // to the selected element from the keyframe modal context
                        openTransformEditor(currentValue, (newValue) => {
                            // Update the stored value
                            keyframeFrames[frameIndex].properties[propIndex].value = newValue;
                            // Update the text input
                            if (transformTextInput) {
                                transformTextInput.value = newValue;
                            }
                        }, null);
                    });
                }
                break;
            
            default:
                // Text input (fallback)
                const textInput = propEl.querySelector('.preview-keyframe-modal__property-value');
                if (textInput) {
                    textInput.addEventListener('input', (e) => {
                        keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                    });
                }
                break;
        }
    }
    
    // ==================== Transform Sub-Editor (Phase 9.3.1 Step 5) ====================
    
    /**
     * Transform function definitions
     * Maps function names to their parameter configs
     */
    const TRANSFORM_FUNCTIONS = {
        // Translation functions
        translateX: { params: ['x'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        translateY: { params: ['y'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        translateZ: { params: ['z'], units: ['px', 'em', 'rem'], category: 'translate' },
        translate: { params: ['x', 'y'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        translate3d: { params: ['x', 'y', 'z'], units: ['px', '%', 'em', 'rem', 'vw', 'vh'], category: 'translate' },
        
        // Rotation functions
        rotate: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotateX: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotateY: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotateZ: { params: ['angle'], units: ['deg', 'rad', 'turn'], category: 'rotate' },
        rotate3d: { params: ['x', 'y', 'z', 'angle'], units: ['deg'], category: 'rotate', special: true },
        
        // Scale functions
        scale: { params: ['x', 'y'], units: [], category: 'scale', unitless: true },
        scaleX: { params: ['x'], units: [], category: 'scale', unitless: true },
        scaleY: { params: ['y'], units: [], category: 'scale', unitless: true },
        scaleZ: { params: ['z'], units: [], category: 'scale', unitless: true },
        scale3d: { params: ['x', 'y', 'z'], units: [], category: 'scale', unitless: true },
        
        // Skew functions
        skew: { params: ['x', 'y'], units: ['deg', 'rad', 'turn'], category: 'skew' },
        skewX: { params: ['x'], units: ['deg', 'rad', 'turn'], category: 'skew' },
        skewY: { params: ['y'], units: ['deg', 'rad', 'turn'], category: 'skew' },
        
        // Other
        perspective: { params: ['d'], units: ['px'], category: 'other' }
    };
    
    /**
     * Parse a CSS transform string into an array of function objects
     * @param {string} transformStr - e.g., "translateY(-10px) rotate(5deg) scale(1.1)"
     * @returns {Array} Array of { fn: 'translateY', args: [{ num: -10, unit: 'px' }] }
     */
    function parseTransformString(transformStr) {
        if (!transformStr || transformStr === 'none') return [];
        
        const functions = [];
        // Match function calls: name(args)
        const regex = /(\w+)\(([^)]+)\)/g;
        let match;
        
        while ((match = regex.exec(transformStr)) !== null) {
            const fnName = match[1];
            const argsStr = match[2];
            const config = TRANSFORM_FUNCTIONS[fnName];
            
            if (!config) continue; // Unknown function, skip
            
            // Parse arguments (comma or space separated)
            const argParts = argsStr.split(/[,\s]+/).filter(a => a.trim());
            const args = [];
            
            for (let i = 0; i < argParts.length; i++) {
                const argStr = argParts[i].trim();
                
                if (config.unitless) {
                    // Unitless number (scale)
                    args.push({ num: parseFloat(argStr) || 0, unit: '' });
                } else if (config.special && fnName === 'rotate3d' && i < 3) {
                    // rotate3d first 3 params are unitless vector
                    args.push({ num: parseFloat(argStr) || 0, unit: '' });
                } else {
                    // Parse number + unit
                    const numMatch = argStr.match(/^(-?[\d.]+)(.*)$/);
                    if (numMatch) {
                        args.push({ 
                            num: parseFloat(numMatch[1]) || 0, 
                            unit: numMatch[2].trim() || config.units[0] || ''
                        });
                    } else {
                        args.push({ num: 0, unit: config.units[0] || '' });
                    }
                }
            }
            
            // Fill missing args with defaults
            while (args.length < config.params.length) {
                const defaultUnit = config.unitless ? '' : (config.units[0] || '');
                args.push({ num: 0, unit: defaultUnit });
            }
            
            functions.push({ fn: fnName, args });
        }
        
        return functions;
    }
    
    /**
     * Serialize transform functions array back to CSS string
     * @param {Array} functions - Array of { fn, args }
     * @returns {string} CSS transform string
     */
    function serializeTransform(functions) {
        if (!functions || functions.length === 0) return 'none';
        
        return functions.map(({ fn, args }) => {
            const config = TRANSFORM_FUNCTIONS[fn];
            const argStrs = args.map((arg, i) => {
                if (config.unitless || (config.special && fn === 'rotate3d' && i < 3)) {
                    return String(arg.num);
                }
                return `${arg.num}${arg.unit}`;
            });
            return `${fn}(${argStrs.join(', ')})`;
        }).join(' ');
    }
    
    // Transform Editor state
    let transformEditorOpen = false;
    let transformEditorCallback = null;  // Called with final value when Apply clicked
    let transformFunctions = [];          // Current transform functions being edited
    let transformEditorTarget = null;     // Element to preview on
    
    /**
     * Open the Transform Editor modal
     * @param {string} initialValue - Current transform CSS value
     * @param {function} onApply - Callback with new transform value
     * @param {HTMLElement} previewTarget - Element in iframe to preview on
     */
    function openTransformEditor(initialValue, onApply, previewTarget) {
        transformEditorCallback = onApply;
        transformEditorTarget = previewTarget;
        transformFunctions = parseTransformString(initialValue);
        transformEditorOpen = true;
        
        renderTransformEditor();
        document.getElementById('transform-editor-modal').classList.add('preview-keyframe-modal--visible');
    }
    
    /**
     * Close the Transform Editor modal
     * @param {boolean} apply - If true, call callback with current value
     */
    function closeTransformEditor(apply = false) {
        if (apply && transformEditorCallback) {
            const value = serializeTransform(transformFunctions);
            transformEditorCallback(value);
        }
        
        // Remove preview
        if (transformEditorTarget) {
            transformEditorTarget.style.transform = '';
        }
        
        transformEditorOpen = false;
        transformEditorCallback = null;
        transformEditorTarget = null;
        transformFunctions = [];
        
        document.getElementById('transform-editor-modal').classList.remove('preview-keyframe-modal--visible');
    }
    
    /**
     * Render the Transform Editor UI
     */
    function renderTransformEditor() {
        const currentValue = serializeTransform(transformFunctions);
        const currentDisplay = document.getElementById('transform-current-value');
        const functionsContainer = document.getElementById('transform-functions-list');
        
        if (currentDisplay) {
            currentDisplay.textContent = currentValue || 'none';
        }
        
        if (!functionsContainer) return;
        functionsContainer.innerHTML = '';
        
        if (transformFunctions.length === 0) {
            functionsContainer.innerHTML = `
                <div class="transform-editor__empty">
                    ${PreviewConfig.i18n.transformEmpty}
                </div>`;
            return;
        }
        
        transformFunctions.forEach((func, index) => {
            const config = TRANSFORM_FUNCTIONS[func.fn];
            if (!config) return;
            
            const row = document.createElement('div');
            row.className = 'transform-editor__function-row';
            row.dataset.index = index;
            
            // Build input fields based on params
            let inputsHTML = '';
            func.args.forEach((arg, argIndex) => {
                const paramName = config.params[argIndex] || '';
                
                if (config.unitless || (config.special && func.fn === 'rotate3d' && argIndex < 3)) {
                    // Unitless number input
                    inputsHTML += `
                        <div class="transform-editor__param">
                            <label>${paramName}</label>
                            <input type="number" step="any" value="${arg.num}" 
                                   data-func="${index}" data-arg="${argIndex}" class="transform-editor__input">
                        </div>`;
                } else {
                    // Number + unit dropdown
                    const unitOptions = config.units.map(u => 
                        `<option value="${u}" ${arg.unit === u ? 'selected' : ''}>${u}</option>`
                    ).join('');
                    
                    inputsHTML += `
                        <div class="transform-editor__param">
                            <label>${paramName}</label>
                            <div class="transform-editor__input-group">
                                <input type="number" step="any" value="${arg.num}" 
                                       data-func="${index}" data-arg="${argIndex}" class="transform-editor__input">
                                <select data-func="${index}" data-arg="${argIndex}" class="transform-editor__unit">
                                    ${unitOptions}
                                </select>
                            </div>
                        </div>`;
                }
            });
            
            row.innerHTML = `
                <div class="transform-editor__drag-handle" title="${PreviewConfig.i18n.dragToReorder}">⋮⋮</div>
                <div class="transform-editor__function-name">${func.fn}</div>
                <div class="transform-editor__params">${inputsHTML}</div>
                <button type="button" class="transform-editor__delete" title="${PreviewConfig.i18n.removeFunction}">
                    ${QuickSiteUtils.iconClose(14)}
                </button>
            `;
            
            // Event: Input change
            row.querySelectorAll('.transform-editor__input').forEach(input => {
                input.addEventListener('input', (e) => {
                    const funcIdx = parseInt(e.target.dataset.func);
                    const argIdx = parseInt(e.target.dataset.arg);
                    transformFunctions[funcIdx].args[argIdx].num = parseFloat(e.target.value) || 0;
                    updateTransformPreview();
                    updateTransformCurrentDisplay();
                });
            });
            
            // Event: Unit change
            row.querySelectorAll('.transform-editor__unit').forEach(select => {
                select.addEventListener('change', (e) => {
                    const funcIdx = parseInt(e.target.dataset.func);
                    const argIdx = parseInt(e.target.dataset.arg);
                    transformFunctions[funcIdx].args[argIdx].unit = e.target.value;
                    updateTransformPreview();
                    updateTransformCurrentDisplay();
                });
            });
            
            // Event: Delete function
            row.querySelector('.transform-editor__delete').addEventListener('click', () => {
                transformFunctions.splice(index, 1);
                renderTransformEditor();
                updateTransformPreview();
            });
            
            functionsContainer.appendChild(row);
        });
        
        // Make rows draggable for reordering
        setupTransformDragReorder(functionsContainer);
    }
    
    /**
     * Update the current value display
     */
    function updateTransformCurrentDisplay() {
        const display = document.getElementById('transform-current-value');
        if (display) {
            display.textContent = serializeTransform(transformFunctions) || 'none';
        }
    }
    
    /**
     * Apply live preview to target element
     */
    function updateTransformPreview() {
        if (transformEditorTarget) {
            transformEditorTarget.style.transform = serializeTransform(transformFunctions);
        }
    }
    
    /**
     * Setup drag-and-drop reordering for function rows
     */
    function setupTransformDragReorder(container) {
        let draggedEl = null;
        let draggedIndex = -1;
        
        container.querySelectorAll('.transform-editor__function-row').forEach(row => {
            const handle = row.querySelector('.transform-editor__drag-handle');
            
            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                draggedEl = row;
                draggedIndex = parseInt(row.dataset.index);
                row.classList.add('transform-editor__function-row--dragging');
                
                const onMouseMove = (e) => {
                    const rows = Array.from(container.querySelectorAll('.transform-editor__function-row'));
                    const y = e.clientY;
                    
                    rows.forEach((r, idx) => {
                        if (r === draggedEl) return;
                        const rect = r.getBoundingClientRect();
                        const midY = rect.top + rect.height / 2;
                        
                        if (y < midY && idx < draggedIndex) {
                            container.insertBefore(draggedEl, r);
                            draggedIndex = idx;
                        } else if (y > midY && idx > draggedIndex) {
                            container.insertBefore(draggedEl, r.nextSibling);
                            draggedIndex = idx;
                        }
                    });
                };
                
                const onMouseUp = () => {
                    row.classList.remove('transform-editor__function-row--dragging');
                    
                    // Reorder transformFunctions array based on DOM order
                    const newOrder = [];
                    container.querySelectorAll('.transform-editor__function-row').forEach(r => {
                        const oldIdx = parseInt(r.dataset.index);
                        newOrder.push(transformFunctions[oldIdx]);
                    });
                    transformFunctions = newOrder;
                    
                    // Re-render to update indices
                    renderTransformEditor();
                    updateTransformPreview();
                    updateTransformCurrentDisplay();
                    
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                };
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        });
    }
    
    /**
     * Add a new transform function
     * @param {string} fnName - Function name (e.g., 'translateX', 'rotate')
     */
    function addTransformFunction(fnName) {
        const config = TRANSFORM_FUNCTIONS[fnName];
        if (!config) return;
        
        // Create default args
        const args = config.params.map((param, i) => {
            if (config.unitless || (config.special && fnName === 'rotate3d' && i < 3)) {
                return { num: fnName.startsWith('scale') ? 1 : 0, unit: '' };
            }
            return { num: 0, unit: config.units[0] || '' };
        });
        
        transformFunctions.push({ fn: fnName, args });
        renderTransformEditor();
        updateTransformPreview();
        
        // Close dropdown
        document.getElementById('transform-add-dropdown').classList.remove('transform-editor__dropdown--open');
    }
    
    /**
     * Toggle add function dropdown
     */
    function toggleTransformDropdown() {
        const dropdown = document.getElementById('transform-add-dropdown');
        dropdown.classList.toggle('transform-editor__dropdown--open');
    }
    
    /**
     * Initialize Transform Editor event handlers
     */
    function initTransformEditorHandlers() {
        const transformModal = document.getElementById('transform-editor-modal');
        const transformClose = document.getElementById('transform-editor-close');
        const transformCancel = document.getElementById('transform-cancel');
        const transformApply = document.getElementById('transform-apply');
        const transformClear = document.getElementById('transform-clear');
        const transformAddBtn = document.getElementById('transform-add-btn');
        const transformDropdown = document.getElementById('transform-add-dropdown');
        
        // Close button
        if (transformClose) {
            transformClose.addEventListener('click', () => closeTransformEditor(false));
        }
        
        // Cancel button
        if (transformCancel) {
            transformCancel.addEventListener('click', () => closeTransformEditor(false));
        }
        
        // Apply button
        if (transformApply) {
            transformApply.addEventListener('click', () => closeTransformEditor(true));
        }
        
        // Clear All button
        if (transformClear) {
            transformClear.addEventListener('click', () => {
                transformFunctions = [];
                renderTransformEditor();
                updateTransformPreview();
            });
        }
        
        // Add Function button
        if (transformAddBtn) {
            transformAddBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleTransformDropdown();
            });
        }
        
        // Dropdown function buttons
        if (transformDropdown) {
            transformDropdown.querySelectorAll('[data-fn]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    addTransformFunction(e.target.dataset.fn);
                });
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!transformDropdown.contains(e.target) && e.target !== transformAddBtn) {
                    transformDropdown.classList.remove('transform-editor__dropdown--open');
                }
            });
        }
        
        // Close modal on backdrop click
        if (transformModal) {
            transformModal.addEventListener('click', (e) => {
                if (e.target === transformModal || e.target.classList.contains('preview-keyframe-modal__backdrop')) {
                    closeTransformEditor(false);
                }
            });
        }
    }


    // ==================== Transition Editor ====================
    // Functionality moved to preview-transition-editor.js module
    
    /**
     * Open the Transition Editor (delegates to module)
     */
    function openTransitionEditor(selector, onSave = null) {
        if (window.PreviewTransitionEditor) {
            PreviewTransitionEditor.open(selector, onSave);
        }
    }
    
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
            PreviewTransitionEditor.setEscapeHtml(escapeHTML);
            PreviewTransitionEditor.setParseStylesString(parseStylesString);
            PreviewTransitionEditor.setRefreshPreviewFrame(() => {
                const iframe = document.getElementById('preview-frame');
                if (iframe?.contentWindow) {
                    iframe.contentWindow.location.reload();
                }
            });
            PreviewTransitionEditor.setOpenAnimationPreviewModal(
                window.PreviewStyleAnimations?.openAnimationPreviewModal || function() {}
            );
            PreviewTransitionEditor.setGetKeyframesData(() => keyframesData);
            PreviewTransitionEditor.setGetThemeVariables(() => {
                if (window.PreviewStyleTheme && PreviewStyleTheme.isLoaded()) {
                    return PreviewStyleTheme.getCurrent();
                }
                return originalThemeVariables;
            });
            PreviewTransitionEditor.init();
        }
    }

    // ==================== Draggable Panels ====================

    /**
     * Make a panel draggable by its header
     * @param {HTMLElement} panel - The panel element
     * @param {HTMLElement} header - The header element to drag from
     * @param {string} storageKey - localStorage key for position persistence
     */
    function makePanelDraggable(panel, header, storageKey) {
        if (!panel || !header) return;
        
        let isDragging = false;
        let startX, startY, startLeft, startTop;
        
        // Restore saved position
        const savedPos = localStorage.getItem(storageKey);
        if (savedPos) {
            try {
                const pos = JSON.parse(savedPos);
                panel.style.left = pos.left + 'px';
                panel.style.top = pos.top + 'px';
                panel.classList.add(panel.classList[0] + '--dragged');
            } catch (e) {}
        }
        
        header.addEventListener('mousedown', (e) => {
            // Don't drag if clicking on close button
            if (e.target.closest('button')) return;
            
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            
            const rect = panel.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;
            
            // Add dragged class to disable default positioning
            panel.classList.add(panel.classList[0] + '--dragged');
            
            // Disable text selection while dragging
            document.body.style.userSelect = 'none';
            
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;
            
            let newLeft = startLeft + deltaX;
            let newTop = startTop + deltaY;
            
            // Keep within viewport
            const panelRect = panel.getBoundingClientRect();
            const maxLeft = window.innerWidth - panelRect.width;
            const maxTop = window.innerHeight - panelRect.height;
            
            newLeft = Math.max(0, Math.min(newLeft, maxLeft));
            newTop = Math.max(0, Math.min(newTop, maxTop));
            
            panel.style.left = newLeft + 'px';
            panel.style.top = newTop + 'px';
        });
        
        document.addEventListener('mouseup', () => {
            if (!isDragging) return;
            isDragging = false;
            
            // Re-enable text selection
            document.body.style.userSelect = '';
            
            // Save position
            const rect = panel.getBoundingClientRect();
            localStorage.setItem(storageKey, JSON.stringify({
                left: rect.left,
                top: rect.top
            }));
        });
    }
    
    // Initialize draggable panels
    const nodePanelHeader = nodePanel?.querySelector('.preview-node-panel__header');
    makePanelDraggable(nodePanel, nodePanelHeader, 'qs_node_panel_pos');
    
    // ==================== URL Building ====================
    
    function getCurrentLang() {
        return langSelect ? langSelect.value : defaultLang;
    }
    
    function buildUrl(editType, editName) {
        let url = baseUrl + '/';
        
        if (editType === 'component') {
            // Component preview - standalone component render
            // Include language prefix so component translation follows toolbar language.
            if (multilingual) {
                url += getCurrentLang() + '/';
            }
            url += '?_component=' + encodeURIComponent(editName) + '&_editor=1';
            // Include emulation data if available
            try {
                const raw = localStorage.getItem('qs_emulate_' + editName);
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
                url += editName;
            }
            // Always add _editor=1 for editor mode
            url += (url.includes('?') ? '&' : '?') + '_editor=1';
        }
        return url;
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
        // For component preview, rebuild URL to include current emulation data
        if (currentEditType === 'component') {
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

    function navigateTo(editType, editName) {
        showLoading();
        startLoadingTimeout();
        overlayInjected = false;
        currentEditType = editType;
        currentEditName = editName;
        iframe.src = buildUrl(editType, editName);
        
        // Reset tool state: switch back to select mode and clear selection.
        // Preview mode is sticky across navigation (it's the "just look at
        // the site" mode — no reason to drop the user back into editor).
        if (currentMode !== 'select' && currentMode !== 'preview') {
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
        
        // For layout (menu/footer), auto-select the element after iframe loads
        if (editType === 'layout') {
            layoutAutoSelectTarget = editName; // 'menu' or 'footer'
        } else {
            layoutAutoSelectTarget = null;
        }
    }
    
    // ==================== Component Warning Banner ====================
    
    /**
     * Show/hide component warning banner and fetch usage count
     */
    async function updateComponentWarning(editType, editName) {
        if (!componentWarning || !componentWarningText) return;
        
        // Update Text mode button visibility - hide when editing components
        updateTextModeVisibility(editType);
        
        // Show/hide delete component button in toolbar
        if (deleteComponentBtn) {
            deleteComponentBtn.style.display = editType === 'component' ? 'inline-flex' : 'none';
        }
        
        // Show/hide Variables button (component-only)
        if (ctxNodeVariables) {
            ctxNodeVariables.style.display = editType === 'component' ? '' : 'none';
        }
        // Show/hide Enums button (component-only)
        if (ctxNodeEnums) {
            ctxNodeEnums.style.display = editType === 'component' ? '' : 'none';
        }
        // Show/hide Emulation button (component-only)
        if (ctxNodeEmulation) {
            ctxNodeEmulation.style.display = editType === 'component' ? '' : 'none';
        }
        // Hide Variables panel if open and switching away from component
        if (editType !== 'component' && variablesPanel && variablesPanel.style.display !== 'none') {
            hideVariablesPanel();
        }
        // Hide Enums panel if open and switching away from component
        if (editType !== 'component' && enumsPanel && enumsPanel.style.display !== 'none') {
            hideEnumsPanel();
        }
        // Hide Emulation panel if open and switching away from component
        if (editType !== 'component' && emulationPanel && emulationPanel.style.display !== 'none') {
            hideEmulationPanel();
        }
        
        if (editType !== 'component') {
            componentWarning.style.display = 'none';
            return;
        }
        
        // Show banner immediately with loading state
        componentWarning.style.display = 'flex';
        componentWarningText.textContent = PreviewConfig.i18n.componentWarning?.replace(':count', '...') || 'Editing component template...';
        
        try {
            // Fetch usage count via findComponentUsages API (GET with name as URL param)
            const result = await QuickSiteAdmin.apiRequest('findComponentUsages', 'GET', null, [editName]);
            
            if (result.ok && result.data?.data) {
                const totalUsages = result.data.data.totalUsages || 0;
                const warningText = PreviewConfig.i18n.componentWarning?.replace(':count', totalUsages.toString()) 
                    || `Editing component template - changes affect all ${totalUsages} usage(s)`;
                componentWarningText.textContent = warningText;
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
        const textModeBtn = document.querySelector('.preview-sidebar-tool[data-mode="text"]');
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
     * Check if the page structure contains iframe nodes and show/hide banner
     */
    async function updateIframeWarning(editType, editName) {
        if (!iframeWarning || iframeWarningDismissed) return;
        
        // Only show for pages, not components/layouts
        if (editType !== 'page') {
            iframeWarning.style.display = 'none';
            return;
        }
        
        try {
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, ['page', ...editName.split('/')]);
            if (result.ok && result.data?.data?.structure) {
                const hasIframe = (function check(node) {
                    if (!node) return false;
                    if (node.tag === 'iframe') return true;
                    if (Array.isArray(node.children)) return node.children.some(check);
                    return false;
                })(result.data.data.structure);
                
                iframeWarning.style.display = hasIframe ? 'flex' : 'none';
            } else {
                iframeWarning.style.display = 'none';
            }
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
            const msg = (PreviewConfig.i18n.confirmDeleteComponent || 'Delete component "%s"? This cannot be undone.').replace('%s', name);
            if (!confirm(msg)) return;
            
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
                    
                    // Navigate to first page
                    const firstPage = targetSelect.querySelector('option[value^="page:"]');
                    if (firstPage) {
                        targetSelect.value = firstPage.value;
                        const colonIdx = firstPage.value.indexOf(':');
                        navigateTo('page', firstPage.value.substring(colonIdx + 1));
                    }
                    
                    // Show warnings if any
                    const warnings = result.data?.data?.warnings;
                    if (warnings && warnings.length > 0) {
                        showToast((PreviewConfig.i18n.componentDeleted || 'Component deleted') + ' ⚠️ ' + warnings.join(', '), 'warning');
                    } else {
                        showToast(PreviewConfig.i18n.componentDeleted || 'Component deleted', 'success');
                    }
                } else {
                    const msg = result.data?.message || PreviewConfig.i18n.componentDeleteFailed || 'Failed to delete component';
                    showToast(msg, 'error');
                }
            } catch (error) {
                console.error('[Preview] Delete component failed:', error);
                showToast(PreviewConfig.i18n.componentDeleteFailed || 'Failed to delete component', 'error');
            }
        });
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
        } catch (e) {
            console.warn('[Preview] Could not read layout state from iframe:', e);
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
                
                const msg = section.charAt(0).toUpperCase() + section.slice(1) + (show ? ' shown' : ' hidden');
                showToast(msg, 'success');
            } else {
                throw new Error(result.data?.message || 'Failed to update layout');
            }
        } catch (error) {
            console.error('[Preview] Layout toggle error:', error);
            showToast((PreviewConfig.i18n?.error || 'Error') + ': ' + error.message, 'error');
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
    
    // ==================== Create Component ====================
    
    if (createComponentBtn) {
        createComponentBtn.addEventListener('click', async function() {
            const namePrompt = PreviewConfig.i18n.componentNamePrompt || 'Component name (letters, numbers, hyphens):';
            const name = prompt(namePrompt);
            if (!name) return;
            
            // Validate name: alphanumeric, hyphens, underscores only
            const trimmed = name.trim();
            if (!trimmed || !/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(trimmed)) {
                showToast(PreviewConfig.i18n.componentNameInvalid || 'Invalid name. Use only letters, numbers, hyphens, and underscores.', 'error');
                return;
            }
            
            // Check if component already exists in the dropdown
            const existingOption = targetSelect.querySelector('option[value="component:' + trimmed + '"]');
            if (existingOption) {
                showToast(PreviewConfig.i18n.componentNameExists || 'A component with this name already exists.', 'error');
                return;
            }
            
            try {
                // Create component via editStructure API with starter template
                const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                    type: 'component',
                    name: trimmed,
                    structure: { tag: 'div', children: [
                        { textKey: '__RAW__' + trimmed + ' component' }
                    ] }
                });
                
                if (result.ok) {
                    // Add option to Components optgroup (create optgroup if needed)
                    let componentsGroup = targetSelect.querySelector('optgroup[label*="Components"], optgroup[label*="🧩"]');
                    if (!componentsGroup) {
                        componentsGroup = document.createElement('optgroup');
                        componentsGroup.label = '🧩 ' + (PreviewConfig.i18n.components || 'Components');
                        targetSelect.appendChild(componentsGroup);
                    }
                    
                    const newOption = document.createElement('option');
                    newOption.value = 'component:' + trimmed;
                    newOption.textContent = trimmed;
                    componentsGroup.appendChild(newOption);
                    
                    // Auto-select the new component
                    targetSelect.value = newOption.value;
                    navigateTo('component', trimmed);
                    
                    showToast(PreviewConfig.i18n.componentCreated || 'Component created', 'success');
                } else {
                    const msg = result.data?.message || PreviewConfig.i18n.componentCreateFailed || 'Failed to create component';
                    showToast(msg, 'error');
                }
            } catch (error) {
                console.error('[Preview] Create component failed:', error);
                showToast(PreviewConfig.i18n.componentCreateFailed || 'Failed to create component', 'error');
            }
        });
    }
    
    // ==================== Device ====================
    
    function setDevice(device) {
        currentDevice = device;
        const size = devices[device];
        
        deviceBtns.forEach(btn => {
            btn.classList.toggle('preview-device-btn--active', btn.dataset.device === device);
        });
        
        wrapper.style.width = size.width;
        wrapper.style.height = size.height;
        container.classList.toggle('preview-container--device', device !== 'desktop');
    }
    
    // ==================== Editor Mode ====================
    
    // Store preselection data for style mode (Phase 8.2)
    let styleModePreselect = null;
    let isSwitchingMode = false; // Debounce flag to prevent rapid mode switching

    function setMode(mode, preselect = null) {
        // Debounce rapid mode switches to prevent layout thrashing
        if (isSwitchingMode) return;
        isSwitchingMode = true;
        
        // "Preview" mode: just reload the iframe to show the page as a real
        // visitor would see it (no selection / hover overlays, navigation
        // still blocked by the iframe-inject capture-phase guard). After
        // the reload, the overlay re-injection + overlayReady handler will
        // re-send setMode('preview') so the iframe stays in preview mode.
        currentMode = mode;
        
        if (mode === 'preview') {
            // Clear any current selection/panels before reloading.
            hideNodePanel();
            // Trigger a fresh fetch of the page.
            reloadPreview();
        }
        
        modeBtns.forEach(btn => {
            btn.classList.toggle('preview-sidebar-tool--active', btn.dataset.mode === mode);
        });
        
        // Update workspace data-mode for mobile CSS (contextual area visibility)
        if (workspace) workspace.dataset.mode = mode;
        
        // Sync mobile tool buttons
        const mobileBtns = document.querySelectorAll('.preview-mobile-tool');
        mobileBtns.forEach(btn => {
            // For add mode, keep select highlighted since it's a sub-mode
            const effectiveMode = (mode === 'add') ? 'select' : mode;
            btn.classList.toggle('preview-mobile-tool--active', btn.dataset.mode === effectiveMode);
        });
        
        // Update container class for mode-specific styling
        container.dataset.mode = mode;
        
        // Send mode change to iframe
        sendToIframe('setMode', { mode });
        
        // Store preselection for style mode (used in Phase 8.4+)
        if (mode === 'style' && preselect) {
            styleModePreselect = preselect;
            console.log('[Preview] Style mode preselection:', preselect);
            // TODO Phase 8.4: Auto-select the selector in the selector browser
        } else if (mode !== 'style') {
            styleModePreselect = null;
        }
        
        // Update contextual area sections
        updateContextualSection(mode);
        
        // Update mobile actions label
        updateMobileActionsMode(mode);
        
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
            } else {
                PreviewJsInteractions.setCurrentPage(null);
            }
        }
        
        // Reset debounce flag after a short delay
        requestAnimationFrame(() => {
            isSwitchingMode = false;
        });
    }
    
    // ==================== Contextual Area (Phase 8) ====================
    
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
        
        // Phase 8.3: Show/hide style tabs and content when in style mode
        if (mode === 'style') {
            if (styleTabs) styleTabs.style.display = '';
            if (styleContent) styleContent.style.display = '';
            // Merge theme panel i18n (default tab) before the module renders
            ensureI18nPanel('theme');
            // Load theme variables via module if not already loaded
            if (window.PreviewStyleTheme && !PreviewStyleTheme.isLoaded()) {
                PreviewStyleTheme.load();
            }
        } else {
            if (styleTabs) styleTabs.style.display = 'none';
            if (styleContent) styleContent.style.display = 'none';
        }
    }
    
    function toggleContextualArea() {
        contextualArea.classList.toggle('preview-contextual-area--collapsed');
    }

    // ==================== i18n Panel Merging ====================

    /**
     * Merge panel-specific i18n keys into PreviewConfig.i18n on first activation.
     * Keys for theme, selectors, and animations tabs live in PreviewConfig.i18nPanels
     * and are merged here so the initial page-load script block stays lean.
     *
     * @param {string} panelName - Panel key matching a PreviewConfig.i18nPanels entry
     */
    const _i18nPanelsMerged = new Set();
    function ensureI18nPanel(panelName) {
        if (_i18nPanelsMerged.has(panelName)) return;
        const partial = window.PreviewConfig?.i18nPanels?.[panelName];
        if (partial) {
            Object.assign(PreviewConfig.i18n, partial);
        }
        _i18nPanelsMerged.add(panelName);
    }

    // ==================== Preview Resize ====================
    
    const PREVIEW_HEIGHT_KEY = 'quicksite-preview-height';
    const MIN_PREVIEW_HEIGHT = 200;
    const MAX_PREVIEW_HEIGHT = window.innerHeight - 150; // Leave space for toolbar
    
    /**
     * Initialize preview area resize functionality
     */
    function initPreviewResize() {
        if (!previewResizeHandle || !container) return;
        
        // Restore saved height
        const savedHeight = localStorage.getItem(PREVIEW_HEIGHT_KEY);
        if (savedHeight) {
            const height = parseInt(savedHeight, 10);
            if (height >= MIN_PREVIEW_HEIGHT && height <= MAX_PREVIEW_HEIGHT) {
                container.style.setProperty('--preview-height', height + 'px');
            }
        }
        
        let isResizing = false;
        let startY = 0;
        let startHeight = 0;
        
        previewResizeHandle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            isResizing = true;
            startY = e.clientY;
            startHeight = container.offsetHeight;
            
            container.classList.add('preview-container--resizing');
            previewResizeHandle.classList.add('preview-resize-handle--active');
            
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });
        
        function onMouseMove(e) {
            if (!isResizing) return;
            
            // Handle at bottom: dragging DOWN = larger, dragging UP = smaller
            const deltaY = e.clientY - startY;
            let newHeight = startHeight + deltaY;
            
            // Clamp to min/max
            const currentMax = window.innerHeight - 150;
            newHeight = Math.max(MIN_PREVIEW_HEIGHT, Math.min(currentMax, newHeight));
            
            container.style.setProperty('--preview-height', newHeight + 'px');
        }
        
        function onMouseUp(e) {
            if (!isResizing) return;
            
            isResizing = false;
            container.classList.remove('preview-container--resizing');
            previewResizeHandle.classList.remove('preview-resize-handle--active');
            
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            
            // Save the final height
            const finalHeight = container.offsetHeight;
            localStorage.setItem(PREVIEW_HEIGHT_KEY, finalHeight.toString());
        }
    }
    
    // Initialize preview resize
    initPreviewResize();
    
    function showContextualInfo(data) {
        // Format structure name for display
        let structDisplay = data.struct || '-';
        if (structDisplay.startsWith('page-')) {
            structDisplay = 'Page: ' + structDisplay.substring(5);
        } else if (structDisplay === 'menu') {
            structDisplay = 'Menu';
        } else if (structDisplay === 'footer') {
            structDisplay = 'Footer';
        }
        
        // Update contextual info fields (with null checks)
        if (ctxNodeStruct) ctxNodeStruct.textContent = structDisplay;
        if (ctxNodeId) ctxNodeId.textContent = data.isComponent ? data.componentNode : (data.node === '' ? '(root)' : data.node || '-');
        if (ctxNodeTag) ctxNodeTag.textContent = data.tag || '-';
        if (ctxNodeClasses) ctxNodeClasses.textContent = data.classes || '-';
        if (ctxNodeChildren) ctxNodeChildren.textContent = data.childCount !== undefined ? data.childCount : '-';
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
        
        // Expand contextual area if collapsed
        if (contextualArea) contextualArea.classList.remove('preview-contextual-area--collapsed');
        
        // Update mobile sections (low-width mode)
        updateMobileSections(data);
        
        // Update global element info bar (at bottom of preview)
        updateGlobalElementInfo(data);
    }
    
    /**
     * Update global element info bar at bottom of preview area
     */
    function updateGlobalElementInfo(data) {
        if (!globalElementInfo) return;
        
        // Update summary
        if (globalElementInfoSummary) {
            const nodeDisplay = data.isComponent ? data.componentNode : (data.node === '' ? '(root)' : data.node || '-');
            globalElementInfoSummary.textContent = `${data.tag || '-'}${data.classes ? '.' + data.classes.split(' ')[0] : ''} [${nodeDisplay}]`;
        }
        
        // Update details
        if (globalInfoNodeId) globalInfoNodeId.textContent = data.isComponent ? data.componentNode : (data.node === '' ? '(root)' : data.node || '-');
        if (globalInfoNodeTag) globalInfoNodeTag.textContent = data.tag || '-';
        if (globalInfoNodeClasses) globalInfoNodeClasses.textContent = data.classes || '-';
        if (globalInfoNodeChildren) globalInfoNodeChildren.textContent = data.childCount !== undefined ? data.childCount : '-';
        
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
        
        // Hide optional rows
        if (globalInfoNodeComponentRow) globalInfoNodeComponentRow.style.display = 'none';
        if (globalInfoNodeTextKeyRow) globalInfoNodeTextKeyRow.style.display = 'none';
    }
    
    /**
     * Update mobile sections with selection data
     */
    function updateMobileSections(data) {
        if (!mobileSections) return;
        
        // Update info section
        if (mobileCtxId) mobileCtxId.textContent = data.isComponent ? data.componentNode : (data.node === '' ? '(root)' : data.node || '-');
        if (mobileCtxTag) mobileCtxTag.textContent = data.tag || '-';
        if (mobileCtxClasses) mobileCtxClasses.textContent = data.classes || '-';
        if (mobileCtxChildren) mobileCtxChildren.textContent = data.childCount !== undefined ? data.childCount : '-';
        
        // Summary for collapsed state
        if (mobileInfoSummary) {
            mobileInfoSummary.textContent = `${data.tag || '-'}${data.classes ? '.' + data.classes.split(' ')[0] : ''}`;
        }
        
        // Component row
        if (data.isComponent && data.component) {
            if (mobileCtxComponent) mobileCtxComponent.textContent = data.component;
            if (mobileCtxComponentRow) mobileCtxComponentRow.style.display = '';
        } else {
            if (mobileCtxComponentRow) mobileCtxComponentRow.style.display = 'none';
        }
        
        // Text key row
        if (data.textKeys && data.textKeys.length > 0) {
            if (mobileCtxTextKey) mobileCtxTextKey.textContent = data.textKeys.join(', ');
            if (mobileCtxTextKeyRow) mobileCtxTextKeyRow.style.display = '';
        } else {
            if (mobileCtxTextKeyRow) mobileCtxTextKeyRow.style.display = 'none';
        }
        
        // Show mobile sections
        mobileSections.classList.add('preview-mobile-sections--visible');
    }
    
    /**
     * Hide mobile sections
     */
    function hideMobileSections() {
        if (mobileSections) {
            mobileSections.classList.remove('preview-mobile-sections--visible');
        }
    }
    
    /**
     * Update mobile actions mode label
     */
    function updateMobileActionsMode(mode) {
        if (!mobileActionsMode) return;
        
        const modeLabels = {
            'select': PreviewConfig.i18n?.modeSelect || 'Select',
            'add': PreviewConfig.i18n?.modeAdd || 'Add',
            'drag': PreviewConfig.i18n?.modeDrag || 'Drag',
            'text': PreviewConfig.i18n?.modeText || 'Text',
            'style': PreviewConfig.i18n?.modeStyle || 'Style',
            'js': PreviewConfig.i18n?.modeJs || 'JS'
        };
        mobileActionsMode.textContent = modeLabels[mode] || mode;
        
        // Show/hide action groups based on mode
        // Note: 'add' is a sub-mode of 'select' - keep select actions visible
        const effectiveMode = (mode === 'add') ? 'select' : mode;
        const actionGroups = document.querySelectorAll('.preview-mobile-section__action-group[data-mode]');
        actionGroups.forEach(group => {
            group.style.display = group.dataset.mode === effectiveMode ? '' : 'none';
        });
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
        
        // Hide mobile sections
        hideMobileSections();
        
        // Hide global element info bar
        hideGlobalElementInfo();
    }
    
    // ==================== Theme Variables (Phase 8.3) ====================
    // Theme variable editing is now handled by preview-style-theme.js module
    // See: public/admin/assets/js/pages/preview/preview-style-theme.js

    /**
     * Initialize style tab switching
     */
    function initStyleTabs() {
        if (!styleTabs) return;
        
        const tabs = styleTabs.querySelectorAll('.preview-contextual-style-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.dataset.tab;
                if (tabName === activeStyleTab) return;
                
                // Merge panel-specific i18n keys on first activation
                ensureI18nPanel(tabName);
                
                // Update active tab button
                tabs.forEach(t => t.classList.remove('preview-contextual-style-tab--active'));
                tab.classList.add('preview-contextual-style-tab--active');
                
                // Show/hide panels
                activeStyleTab = tabName;
                if (themePanel) themePanel.style.display = tabName === 'theme' ? '' : 'none';
                if (selectorsPanel) selectorsPanel.style.display = tabName === 'selectors' ? '' : 'none';
                if (animationsPanel) animationsPanel.style.display = tabName === 'animations' ? '' : 'none';
                
                // Load selectors when switching to selectors tab (Phase 8.4)
                if (tabName === 'selectors' && !selectorsLoaded) {
                    loadStyleSelectors();
                }
                
                // Load animations when switching to animations tab (Phase 9.2)
                if (tabName === 'animations' && !animationsLoaded) {
                    loadAnimationsTab();
                }
            });
        });
        
        // Initialize animations group collapsing
        initAnimationsGroups();
    }
    
    // ==================== Animations Tab (Phase 9.2) ====================
    // Animation functionality has been extracted to preview-style-animations.js
    // See: /admin/assets/js/pages/preview/preview-style-animations.js
    // Public API: PreviewStyleAnimations.init(), .load(), .isLoaded(), .reset()
    
    // Delegate to animations module
    function loadAnimationsTab() {
        if (window.PreviewStyleAnimations) {
            PreviewStyleAnimations.load();
        }
    }
    
    function initAnimationsGroups() {
        // Handled by PreviewStyleAnimations module during init
    }
    
    // ==================== Selector Browser (Phase 8.4) ====================
    // Selector browser functionality has been extracted to preview-style-selectors.js
    // See: /admin/assets/js/pages/preview/preview-style-selectors.js
    // Public API: PreviewSelectorBrowser.init(), .load(), .loadData(), .isLoaded()
    
    // Delegate to selector browser module
    function loadSelectorsData() {
        if (window.PreviewSelectorBrowser) {
            return PreviewSelectorBrowser.loadData();
        }
        return Promise.resolve(false);
    }
    
    function loadStyleSelectors() {
        if (window.PreviewSelectorBrowser) {
            PreviewSelectorBrowser.load();
        }
    }
    
    function initSelectorBrowser() {
        // Register callbacks for cross-module communication
        if (window.PreviewSelectorBrowser) {
            // Delegate to Style Editor module
            if (window.PreviewStyleEditor) {
                PreviewSelectorBrowser.onOpenStyleEditor(PreviewStyleEditor.open);
                PreviewSelectorBrowser.onCopyStyleFrom(PreviewStyleEditor.open);
            }
            PreviewSelectorBrowser.onOpenTransitionEditor(openTransitionEditor);
        }
    }
    
    // ==================== Style Editor (Phase 8.5) ====================
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
                return originalThemeVariables;
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

    function showNodePanel(data) {
        // Check if user clicked a menu/footer element while editing a different target
        const clickedStruct = data.struct || null;
        if (clickedStruct && currentEditType !== 'layout') {
            // User is editing a page/component but clicked on menu or footer
            if (clickedStruct === 'menu' || clickedStruct === 'footer') {
                const structLabel = clickedStruct === 'menu' ? 'Menu' : 'Footer';
                const msg = `This element belongs to the ${structLabel} (shared across all pages). Switch to ${structLabel} editing?`;
                if (confirm(msg)) {
                    // Switch dropdown and navigate to layout target
                    const layoutValue = 'layout:' + clickedStruct;
                    if (targetSelect) targetSelect.value = layoutValue;
                    navigateTo('layout', clickedStruct);
                }
                // Either way, don't process this selection (it came from the wrong context)
                return;
            }
        }
        
        // Reverse: user is editing layout (menu/footer) but clicked a page element
        if (clickedStruct && currentEditType === 'layout' && clickedStruct.startsWith('page-')) {
            const routeName = clickedStruct.substring(5);
            const msg = `This element belongs to the page "${routeName}". Switch to page editing?`;
            if (confirm(msg)) {
                const pageValue = 'page:' + routeName;
                if (targetSelect) targetSelect.value = pageValue;
                navigateTo('page', routeName);
            }
            return;
        }
        
        // Store selection info for edit/copy actions
        selectedStruct = data.struct || null;
        selectedNode = data.isComponent ? data.componentNode : data.node;
        selectedComponent = data.component || null;
        selectedElementClasses = data.classes || null;  // Store classes for style mode
        selectedElementTag = data.tag || null;          // Store tag for style mode
        
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
        
        // Format structure name for display
        let structDisplay = data.struct || '-';
        if (structDisplay.startsWith('page-')) {
            structDisplay = 'Page: ' + structDisplay.substring(5);
        } else if (structDisplay === 'menu') {
            structDisplay = 'Menu';
        } else if (structDisplay === 'footer') {
            structDisplay = 'Footer';
        }
        
        // Update panel fields (deprecated floating panel - kept for compatibility)
        if (nodeStructEl) nodeStructEl.textContent = structDisplay;
        if (nodeIdEl) nodeIdEl.textContent = selectedNode === '' ? '(root)' : selectedNode || '-';
        if (nodeTagEl) nodeTagEl.textContent = data.tag || '-';
        if (nodeClassesEl) nodeClassesEl.textContent = data.classes || '-';
        if (nodeChildrenEl) nodeChildrenEl.textContent = data.childCount !== undefined ? data.childCount : '-';
        if (nodeTextEl) nodeTextEl.textContent = data.textContent || '-';
        
        // Show/hide component row
        if (data.isComponent && data.component) {
            if (nodeComponentEl) nodeComponentEl.textContent = data.component;
            if (nodeComponentRow) nodeComponentRow.style.display = '';
        } else {
            if (nodeComponentRow) nodeComponentRow.style.display = 'none';
        }
        
        // Show/hide textKey row
        if (data.textKeys && data.textKeys.length > 0) {
            // Show first textKey (or comma-separated if multiple)
            if (nodeTextKeyEl) nodeTextKeyEl.textContent = data.textKeys.join(', ');
            if (nodeTextKeyRow) nodeTextKeyRow.style.display = '';
        } else {
            if (nodeTextKeyRow) nodeTextKeyRow.style.display = 'none';
        }
        
        // Phase 8: Update contextual area info
        showContextualInfo(data);
        
        // (deprecated) nodePanel.classList.add('preview-node-panel--visible');
    }
    
    function hideNodePanel() {
        // (deprecated) nodePanel.classList.remove('preview-node-panel--visible');
        selectedStruct = null;
        selectedNode = null;
        selectedComponent = null;
        selectedElementClasses = null;
        selectedElementTag = null;
        
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
        
        // Phase 8: Hide contextual info
        hideContextualInfo();
        
        sendToIframe('clearSelection', {});
    }
    
    // ==================== Iframe Communication ====================
    
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
                    sendToIframe('setMode', { mode: currentMode });
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
                            } else {
                                PreviewJsInteractions.setCurrentPage(null);
                            }
                        }
                    }
                    
                    // Auto-select layout target (menu/footer) after navigation
                    if (layoutAutoSelectTarget) {
                        const struct = layoutAutoSelectTarget; // 'menu' or 'footer'
                        layoutAutoSelectTarget = null;
                        // Small extra delay to ensure DOM is rendered
                        setTimeout(() => {
                            sendToIframe('selectNode', { struct: struct, node: '0' });
                        }, 100);
                    }
                    
                    // Ensure layout toggles match current edit type
                    updateLayoutToggles(currentEditType, currentEditName);
                    // Sync layout toggles from iframe DOM (read actual menu/footer presence)
                    syncLayoutTogglesFromIframe();
                }, 50); // Small delay to ensure iframe is ready to receive messages
            }
            if (e.data.action === 'elementMoved' || e.data.action.startsWith('drag')) {
                if (window.PreviewDrag) PreviewDrag.handleMessage(e.data);
            }
            if (e.data.action === 'textEdited') {
                handleTextEdited(e.data);
            }
            if (e.data.action === 'styleSelected') {
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
                showJsPanel(e.data);
                updateGlobalElementInfo(e.data.element);
            }
            if (e.data.action === 'textElementInfo') {
                updateGlobalElementInfo(e.data.element);
                updateTextModeInfo(e.data.element);
            }
            if (e.data.action === 'pageClassesResult') {
                pageStructureClasses = e.data.classes || [];
                console.log('[Preview] Received page classes:', pageStructureClasses.length);
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
            showToast(PreviewConfig.i18n.error + ': Invalid text data', 'error');
            return;
        }
        
        // Reject unresolved component variable placeholders (e.g., {{$icon}})
        if (textKey.includes('{{')) {
            console.warn('[Preview] Ignoring edit on unresolved variable placeholder:', textKey);
            showToast('Cannot edit unresolved component variable', 'warning');
            return;
        }
        
        // Get current language from the preview selector
        const lang = getCurrentLang();
        
        try {
            // Call setTranslationKeys API
            // The API expects 'language' and 'translations' (nested object, not JSON string)
            // Format: { language: 'en', translations: { key.path: 'value' } }
            // But the API actually expects nested structure, so we need to build it
            
            // Parse the textKey path into nested structure
            // e.g., "home.hero.title" -> { home: { hero: { title: "value" } } }
            const parts = textKey.split('.');
            let translations = {};
            let current = translations;
            for (let i = 0; i < parts.length - 1; i++) {
                current[parts[i]] = {};
                current = current[parts[i]];
            }
            current[parts[parts.length - 1]] = newValue;
            
            const params = {
                language: lang,
                translations: translations
            };
            
            console.log('[Preview] Saving translation:', params);
            const result = await QuickSiteAdmin.apiRequest('setTranslationKeys', 'POST', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to save text');
            }
            
            showToast(PreviewConfig.i18n.textSaved, 'success');
            console.log('[Preview] Translation saved successfully');
            
            // Update the span in iframe to reflect saved state (already has the new text)
            // No action needed - the contenteditable already shows the new text
            
        } catch (error) {
            console.error('[Preview] Text save error:', error);
            showToast(PreviewConfig.i18n.error + ': ' + error.message, 'error');
            
            // Restore original text in iframe on error
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const textEl = iframeDoc.querySelector(`[data-qs-textkey="${textKey}"]`);
                if (textEl) {
                    textEl.textContent = oldValue;
                }
            } catch (e) {
                console.error('[Preview] Could not restore text:', e);
            }
        }
    }
    
    // ==================== Utility Functions ====================
    
    /**
     * Escape HTML for safe rendering. Delegates to PreviewState.utils.escapeHtml.
     */
    function escapeHTML(str) {
        return PreviewState.utils.escapeHtml(str);
    }
    
    // Alias for modules that use lowercase
    const escapeHtml = escapeHTML;
    
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
            PreviewJsInteractions.setGetSelectorsLoaded(() => selectorsLoaded);
            PreviewJsInteractions.setLoadSelectorsData(loadSelectorsData);
            PreviewJsInteractions.setGetCategorizedSelectors(() => {
                if (window.PreviewSelectorBrowser) {
                    return PreviewSelectorBrowser.getCategorizedSelectors();
                }
                return categorizedSelectors;
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
        iframe.addEventListener('load', function() {
            clearTimeout(loadingTimeout);
            hideLoading();
            // A new document loaded — previous overlay is gone regardless of flag
            overlayInjected = false;
            injectOverlay();
            // Retry injection in case document wasn't fully ready
            setTimeout(function() { if (!overlayInjected) injectOverlay(); }, 50);
            setTimeout(function() { if (!overlayInjected) injectOverlay(); }, 200);
        });
        
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
                // Reload with current edit target
                navigateTo(currentEditType, currentEditName);
            });
        }
        
        reloadBtn.addEventListener('click', reloadPreview);
        
        deviceBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                setDevice(this.dataset.device);
            });
        });
        
        modeBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                setMode(this.dataset.mode);
            });
        });
        
        // (The former dedicated #preview-tool-refresh listener is gone:
        // the refresh button is now a real mode tool [data-mode="preview"]
        // and is wired by the modeBtns loop above. Entering preview mode
        // triggers reloadPreview() inside setMode().)
        
        // Mobile tool buttons (bottom toolbar on small screens)
        const mobileBtns = document.querySelectorAll('.preview-mobile-tool');
        mobileBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active state for mobile buttons
                mobileBtns.forEach(b => b.classList.remove('preview-mobile-tool--active'));
                this.classList.add('preview-mobile-tool--active');
                // Also sync desktop buttons
                setMode(this.dataset.mode);
            });
        });
        
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
            
            // Arrow keys - Navigate selection (only in select mode with a selection)
            if (currentMode === 'select' && selectedStruct && selectedNode != null) {
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
                // Only if we have a selected node
                if (selectedStruct && selectedNode != null) {
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
            if (contextualArea) contextualArea.classList.remove('preview-contextual-area--collapsed');
        } else {
            resetTextModeInfo();
        }
    }
    
    function resetTextModeInfo() {
        textOnlySelection = null;
        if (ctxTextDefault) ctxTextDefault.style.display = '';
        if (ctxTextInfo) ctxTextInfo.style.display = 'none';
    }
    
    async function deleteTextOnlyNode() {
        if (!textOnlySelection) return;
        
        const { struct, node, textKeys } = textOnlySelection;
        if (!struct || node == null) return;
        
        const confirmMsg = PreviewConfig.i18n.confirmDeleteTextNode || 'Delete this text node?';
        if (!confirm(confirmMsg)) return;
        
        const structInfo = parseStruct(struct);
        if (!structInfo || !structInfo.type) {
            showToast(PreviewConfig.i18n.error + ': Invalid structure type', 'error');
            return;
        }
        
        const keepKeys = ctxTextKeepKeys ? ctxTextKeepKeys.checked : false;
        
        showToast(PreviewConfig.i18n.loading + '...', 'info');
        
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
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to delete text node');
            }
            
            showToast(PreviewConfig.i18n.textNodeDeleted || 'Text node deleted', 'success');
            
            // Live DOM update — remove the node from iframe
            sendToIframe('removeNode', { struct, nodeId: node });
            
            resetTextModeInfo();
        } catch (error) {
            console.error('[Preview] Text delete error:', error);
            showToast(PreviewConfig.i18n.error + ': ' + error.message, 'error');
        }
    }
    
    if (ctxTextDelete) {
        ctxTextDelete.addEventListener('click', deleteTextOnlyNode);
    }
    
    if (nodeClose) {
        nodeClose.addEventListener('click', hideNodePanel);
    }
    
    // Delete node function (shared between floating panel and contextual area)
    async function deleteSelectedNode() {
        if (selectedStruct == null || selectedNode == null) return;
        if (selectedNode === '') {
            showToast(PreviewConfig.i18n?.cannotModifyRoot || 'Cannot delete the root element. Select a child element.', 'warning');
            return;
        }
        
        // Smart textKey check: warn before deleting the last child with a textKey
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
                            const forceMsg = PreviewConfig.i18n?.deleteLastTextWarning 
                                || 'This is the last element containing text content. Deleting it will create a text-less component (styling shape only). The resulting element must have dimensions set via CSS to remain visible.\n\nContinue?';
                            if (!confirm(forceMsg)) return;
                        }
                    }
                }
            } catch (e) {
                console.warn('[Preview] Could not check textKey before delete:', e);
            }
        }
        
        // Confirm deletion
        const confirmMsg = PreviewConfig.i18n.confirmDeleteNode;
        if (!confirm(confirmMsg)) return;
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            showToast(PreviewConfig.i18n.error + ': Invalid structure type', 'error');
            return;
        }
        
        // Save struct and node BEFORE hiding panel (which clears them)
        const structToDelete = selectedStruct;
        const nodeToDelete = selectedNode;
        
        showToast(PreviewConfig.i18n.loading + '...', 'info');
        
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
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to delete node');
            }
            
            // Hide the node panel
            hideNodePanel();
            
            showToast(PreviewConfig.i18n.nodeDeleted, 'success');
            console.log('[Preview] Node deleted successfully');
            
            // Live DOM update - remove node and reindex siblings
            sendToIframe('removeNode', {
                struct: structToDelete,
                nodeId: nodeToDelete
            });
            
        } catch (error) {
            console.error('[Preview] Delete error:', error);
            showToast(PreviewConfig.i18n.error + ': ' + error.message, 'error');
        }
    }
    
    if (nodeDeleteBtn) {
        nodeDeleteBtn.addEventListener('click', deleteSelectedNode);
    }
    
    // ==================== Duplicate Node ====================
    
    async function duplicateSelectedNode() {
        if (selectedStruct == null || selectedNode == null) {
            showToast(PreviewConfig.i18n?.selectElementFirst || 'Select an element first', 'warning');
            return;
        }
        if (selectedNode === '' && currentEditType !== 'component') {
            showToast(PreviewConfig.i18n?.cannotModifyRoot || 'Cannot duplicate the root element. Select a child element.', 'warning');
            return;
        }
        
        try {
            // Get the structure type and name
            const structInfo = parseStruct(selectedStruct);
            if (!structInfo || !structInfo.type) {
                throw new Error('Invalid structure type');
            }
            
            showToast(PreviewConfig.i18n.loading + '...', 'info');
            
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
                throw new Error(result.data?.message || 'Failed to duplicate node');
            }
            
            const keysCreated = result.data?.data?.translationKeysMapped || 0;
            const newNodeId = result.data?.data?.newNodeId;
            const msg = keysCreated > 0 
                ? (PreviewConfig.i18n?.nodeDuplicated || 'Element duplicated') + ` (${keysCreated} translation keys)`
                : (PreviewConfig.i18n?.nodeDuplicated || 'Element duplicated');
            
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
            
        } catch (error) {
            console.error('[Preview] Duplicate error:', error);
            showToast((PreviewConfig.i18n?.error || 'Error') + ': ' + error.message, 'error');
        }
    }
    
    if (ctxNodeDuplicate) {
        ctxNodeDuplicate.addEventListener('click', duplicateSelectedNode);
    }
    
    // ==================== Contextual Area Event Listeners (Phase 8) ====================
    
    function initContextualBindings() {
        // Toggle collapse/expand
        if (contextualToggle) {
            contextualToggle.addEventListener('click', toggleContextualArea);
        }
        
        // Contextual area action buttons (wire to same handlers as floating panel)
        if (ctxNodeAdd) {
            ctxNodeAdd.addEventListener('click', function() {
                if (selectedStruct == null || selectedNode == null) {
                    showToast(PreviewConfig.i18n.selectNodeFirst, 'warning');
                    return;
                }
                setMode('add');
                showSidebarAddForm();
            });
        }
        
        // Back to Select button (from Add mode)
        if (addBackToSelect) {
            addBackToSelect.addEventListener('click', function() {
                setMode('select');
            });
        }
        
        // Tools show names checkbox
        if (toolsShowNames && sidebarTools) {
            // Restore state from localStorage (default: show names = true)
            const showNames = localStorage.getItem('quicksite-tools-show-names') !== 'false';
            toolsShowNames.checked = showNames;
            if (!showNames) {
                sidebarTools.classList.add('preview-sidebar__tools--icons-only');
            }
            
            toolsShowNames.addEventListener('change', function() {
                if (this.checked) {
                    sidebarTools.classList.remove('preview-sidebar__tools--icons-only');
                } else {
                    sidebarTools.classList.add('preview-sidebar__tools--icons-only');
                }
                localStorage.setItem('quicksite-tools-show-names', this.checked);
            });
        }
        
        if (ctxNodeDelete) {
            ctxNodeDelete.addEventListener('click', deleteSelectedNode);
        }
        
        if (ctxNodeStyle) {
            ctxNodeStyle.addEventListener('click', function() {
                // Store element info for style mode preselection
                const preselect = {
                    classes: selectedElementClasses,
                    tag: selectedElementTag,
                    selector: null
                };
                
                // Determine best selector: first class or tag
                if (selectedElementClasses && selectedElementClasses !== '-') {
                    const firstClass = selectedElementClasses.split(' ')[0].trim();
                    if (firstClass) {
                        preselect.selector = '.' + firstClass;
                    }
                } else if (selectedElementTag && selectedElementTag !== '-') {
                    preselect.selector = selectedElementTag.toLowerCase();
                }
                
                // Switch to style mode with preselection data
                setMode('style', preselect);
            });
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
        
        // Mobile section toggle handlers
        document.querySelectorAll('.preview-mobile-section__toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const section = this.closest('.preview-mobile-section');
                if (section) {
                    section.classList.toggle('preview-mobile-section--expanded');
                }
            });
        });
        
        // Global element info bar toggle (at bottom of preview)
        if (globalElementInfoToggle) {
            globalElementInfoToggle.addEventListener('click', function() {
                if (globalElementInfo) {
                    globalElementInfo.classList.toggle('preview-element-info--expanded');
                }
            });
        }
        
        // Mobile action buttons (wire to same handlers)
        if (mobileCtxAdd) {
            mobileCtxAdd.addEventListener('click', function() {
                if (selectedStruct == null || selectedNode == null) {
                    showToast(PreviewConfig.i18n.selectNodeFirst, 'warning');
                    return;
                }
                setMode('add');
                showSidebarAddForm();
            });
        }
        
        if (mobileCtxDuplicate) {
            mobileCtxDuplicate.addEventListener('click', duplicateSelectedNode);
        }
        
        if (mobileCtxDelete) {
            mobileCtxDelete.addEventListener('click', deleteSelectedNode);
        }
    }
    
    initContextualBindings();
    
    // ==================== Module Initializations ====================
    
    initStyleTabs();
    initSelectorBrowser();
    initTransformEditorHandlers();


    // ==================== Sidebar Add/Edit Forms (Phase 8 - Mode Refactoring) ====================
    
    // State for sidebar add form
    let sidebarAddNodeType = 'tag';
    let sidebarAddCustomParamsCount = 0;
    let sidebarAddSelectedComponentData = null;
    
    // Initialize collapsible sections (read stored state from localStorage)
    function initCollapsibleSections() {
        document.querySelectorAll('.preview-contextual-form__collapsible-header[data-storage-key]').forEach(header => {
            const key = header.dataset.storageKey;
            const section = header.closest('.preview-contextual-form__collapsible');
            if (!section) return;
            
            // Read stored state (default: expanded for position, collapsed for others)
            const defaultCollapsed = key !== 'qs-add-position-expanded';
            const stored = localStorage.getItem(key);
            const isCollapsed = stored !== null ? stored === '0' : defaultCollapsed;
            section.classList.toggle('collapsed', isCollapsed);
            
            // Toggle on click
            header.addEventListener('click', function() {
                const nowCollapsed = section.classList.toggle('collapsed');
                localStorage.setItem(key, nowCollapsed ? '0' : '1');
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
        
        // Reset form state — use remembered tab or default to 'snippet'
        const rememberedTab = localStorage.getItem('qs-add-last-tab');
        sidebarAddNodeType = (rememberedTab === 'tag' || rememberedTab === 'component' || rememberedTab === 'snippet') ? rememberedTab : 'snippet';
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
        setAddPosition('after');
        if (classCombobox) classCombobox.reset();
        else if (addClassInput) addClassInput.value = '';
        if (addCustomParamsList) addCustomParamsList.innerHTML = '';
        if (addComponentSelector) addComponentSelector.reset();
        else if (addComponentSelect) addComponentSelect.value = '';

        // When at root, force "inside" and hide before/after options
        const isAtRoot = selectedNode === '' && currentEditType !== 'component';
        if (isAtRoot) {
            setAddPosition('inside');
        }
        if (addPositionPicker) {
            addPositionPicker.querySelectorAll('input[name="add-position"]').forEach(radio => {
                const wrapper = radio.closest('label') || radio.parentElement;
                if (radio.value === 'before' || radio.value === 'after') {
                    wrapper.style.display = isAtRoot ? 'none' : '';
                }
            });
        }

        
        // Update tabs UI
        updateSidebarAddTypeTabs(sidebarAddNodeType);
        updateSidebarAddNodeTypeUI();
        updateSidebarAddMandatoryParams();
        updateSidebarAddTextKeyPreview();
        
        // Refresh contextual suggestions for new selection context
        if (addTagSelector) addTagSelector.refreshSuggestions();
        
        // Load list for the active tab
        if (sidebarAddNodeType === 'component') {
            loadSidebarComponentsList();
        } else if (sidebarAddNodeType === 'snippet') {
            loadSidebarSnippetsList();
        }
    }
    
    // Hide sidebar add form (go back to select)
    function hideSidebarAddForm() {
        if (contextualAddDefault) contextualAddDefault.style.display = '';
        if (contextualAddForm) contextualAddForm.style.display = 'none';
        setMode('select');
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
        
        if (addTagField) addTagField.style.display = isTag ? 'block' : 'none';
        if (addComponentField) addComponentField.style.display = isComponent ? 'block' : 'none';
        if (addSnippetField) addSnippetField.style.display = isSnippet ? 'block' : 'none';
        if (addClassField) addClassField.style.display = isTag ? 'block' : 'none';
        if (addAdvancedSection) addAdvancedSection.style.display = isTag ? '' : 'none';
        if (addPreviewSection) addPreviewSection.style.display = isTag ? '' : 'none';
        
        // Update mandatory params visibility
        updateSidebarAddMandatoryParams();
        
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
    
    // Group A (beta.6): full HTML <input type=…> list. Meta-types
    // "select" / "textarea" deliberately omitted here — those are
    // separate tags reachable from the tag picker (Group B work).
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
        
        const tag = addTagSelect?.value;
        const mandatoryParams = TAG_INFO?.MANDATORY_PARAMS?.[tag] || [];
        
        if (sidebarAddNodeType !== 'tag' || mandatoryParams.length === 0) {
            addMandatoryParams.style.display = 'none';
            return;
        }
        
        addMandatoryParams.style.display = 'block';
        addMandatoryParamsContainer.innerHTML = '';

        // ---- Special case: <input> wizard (Group A) ------------------
        // 'type' becomes a <select>, and we always render a 'name' row
        // (required unless type is in the exempt set).
        if (tag === 'input') {
            renderInputWizardGroupA();
            return;
        }
        // --------------------------------------------------------------

        mandatoryParams.forEach(param => {
            const row = document.createElement('div');
            row.className = 'preview-contextual-form__param-row';
            
            const showBrowse = param === 'src' && tag in ASSET_BROWSABLE_TAGS;
            const showRouteList = param === 'href' && tag === 'a';
            const datalistId = showRouteList ? 'add-mandatory-href-routes' : '';
            
            row.innerHTML = `
                <label class="preview-contextual-form__param-label">${param}:</label>
                <div class="preview-contextual-form__param-input-group">
                    <input type="text" 
                           id="add-mandatory-${param}"
                           class="admin-input admin-input--sm preview-contextual-form__param-input" 
                           placeholder="${showRouteList ? (PreviewConfig.i18n.hrefPlaceholder || 'Select route or type URL') : (PreviewConfig.i18n.required || 'Required')}"
                           ${showRouteList ? `list="${datalistId}"` : ''}>
                    ${showBrowse ? `<button type="button" class="admin-btn admin-btn--sm admin-btn--outline preview-asset-browse-btn" data-category="${ASSET_BROWSABLE_TAGS[tag] || ''}" title="Browse assets">📁</button>` : ''}
                </div>
                ${showRouteList ? `<datalist id="${datalistId}"></datalist>` : ''}
            `;
            addMandatoryParamsContainer.appendChild(row);
            
            if (showBrowse) {
                row.querySelector('.preview-asset-browse-btn').addEventListener('click', () => {
                    openAssetPicker(row.querySelector(`#add-mandatory-${param}`), ASSET_BROWSABLE_TAGS[tag]);
                });
            }
            
            // Populate route datalist for href
            if (showRouteList) {
                populateRouteDatalist(datalistId);
            }
        });
    }

    // ---- Input wizard (Group A, beta.6) ----------------------------
    // Renders the mandatory-params block for tag=<input>:
    //   1) 'type' as a <select> populated with INPUT_TYPES_GROUP_A
    //   2) 'name' as a text field, required unless type is exempt.
    // Updates the name field's required state on type change.
    function renderInputWizardGroupA() {
        // --- type row ---
        const typeRow = document.createElement('div');
        typeRow.className = 'preview-contextual-form__param-row';
        const typeOpts = INPUT_TYPES_GROUP_A
            .map(t => `<option value="${t}">${t}</option>`)
            .join('');
        typeRow.innerHTML = `
            <label class="preview-contextual-form__param-label" for="add-mandatory-type">type:</label>
            <div class="preview-contextual-form__param-input-group">
                <select id="add-mandatory-type"
                        class="admin-input admin-input--sm preview-contextual-form__param-input">
                    ${typeOpts}
                </select>
            </div>
        `;
        addMandatoryParamsContainer.appendChild(typeRow);

        // --- name row ---
        const nameRow = document.createElement('div');
        nameRow.className = 'preview-contextual-form__param-row';
        nameRow.id = 'add-mandatory-name-row';
        nameRow.innerHTML = `
            <label class="preview-contextual-form__param-label" for="add-mandatory-name">
                name<span class="preview-contextual-form__param-required" id="add-mandatory-name-star">*</span>:
            </label>
            <div class="preview-contextual-form__param-input-group">
                <input type="text"
                       id="add-mandatory-name"
                       class="admin-input admin-input--sm preview-contextual-form__param-input"
                       placeholder="${PreviewConfig.i18n.required || 'Required'}">
            </div>
            <small class="preview-contextual-form__param-hint" id="add-mandatory-name-hint">
                ${PreviewConfig.i18n.nameRequiredForInput || 'Required so the field is submitted with the form.'}
            </small>
        `;
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
                nameInput.placeholder = PreviewConfig.i18n.optional || 'Optional';
                nameInput.classList.remove('admin-input--error');
            } else {
                nameStar.style.display = '';
                nameHint.style.display = '';
                nameInput.placeholder = PreviewConfig.i18n.required || 'Required';
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
    
    // Asset picker: opens a dropdown/modal to browse and select an asset
    async function openAssetPicker(targetInput, category) {
        // Remove any existing picker
        document.querySelector('.preview-asset-picker')?.remove();
        
        const picker = document.createElement('div');
        picker.className = 'preview-asset-picker';
        picker.innerHTML = `<div class="preview-asset-picker__loading">${PreviewConfig.i18n.loading || 'Loading...'}</div>`;
        
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
        picker.style.left = rect.left + 'px';
        picker.style.width = Math.max(rect.width + 50, 320) + 'px';
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
                picker.innerHTML = '<div class="preview-asset-picker__empty">Could not load assets</div>';
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
                picker.innerHTML = '<div class="preview-asset-picker__empty">No assets found</div>';
                return;
            }
            
            picker.innerHTML = '';
            const list = document.createElement('div');
            list.className = 'preview-asset-picker__list';
            
            assets.forEach(asset => {
                const item = document.createElement('div');
                item.className = 'preview-asset-picker__item';
                const path = asset.path || `/assets/${asset.category}/${asset.filename}`;
                const isImage = asset.mime_type?.startsWith('image/');
                const displayPath = baseUrl + path;
                
                item.innerHTML = `
                    ${isImage ? `<img class="preview-asset-picker__thumb" src="${displayPath}" alt="" loading="lazy">` : `<span class="preview-asset-picker__icon">📄</span>`}
                    <span class="preview-asset-picker__name" title="${path}">${asset.filename || path.split('/').pop()}</span>
                `;
                
                item.addEventListener('click', () => {
                    targetInput.value = path;
                    targetInput.dispatchEvent(new Event('input'));
                    picker.remove();
                    document.removeEventListener('mousedown', closeHandler);
                    
                    // Auto-fill alt from asset metadata if available
                    if (asset.alt) {
                        const altInput = document.getElementById('add-mandatory-alt');
                        if (altInput && !altInput.value) {
                            altInput.value = asset.alt;
                        }
                    }
                    
                    // Show inline media preview below the input group
                    showAssetPreview(targetInput, path, asset.mime_type);
                });
                
                list.appendChild(item);
            });
            
            picker.appendChild(list);
        } catch (err) {
            picker.innerHTML = '<div class="preview-asset-picker__empty">Error loading assets</div>';
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
        const displayPath = baseUrl + path;
        
        const mime = (mimeType || '').split('/')[0];
        
        if (mime === 'image' || imageExts.includes(ext)) {
            container.innerHTML = `<img src="${displayPath}" alt="Preview" class="preview-asset-inline__media">`;
        } else if (mime === 'video' || videoExts.includes(ext)) {
            container.innerHTML = `<video src="${displayPath}" controls class="preview-asset-inline__media"></video>`;
        } else if (mime === 'audio' || audioExts.includes(ext)) {
            container.innerHTML = `<audio src="${displayPath}" controls class="preview-asset-inline__audio"></audio>`;
        } else {
            container.innerHTML = `<span class="preview-asset-inline__file">📄 ${path.split('/').pop()}</span>`;
        }
        
        row.appendChild(container);
    }
    
    // Update text key preview
    function updateSidebarAddTextKeyPreview() {
        if (!addTextKeyInfo || !addGeneratedTextKeyPreview) return;
        
        const tag = addTagSelect?.value;
        // Only show for text-bearing tags
        const textBearingTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'a', 'button', 'li', 'label', 'strong', 'em', 'small', 'blockquote'];
        
        if (sidebarAddNodeType === 'tag' && textBearingTags.includes(tag)) {
            addTextKeyInfo.style.display = 'flex';
            addGeneratedTextKeyPreview.textContent = `${selectedNode}_${tag}_text`;
        } else {
            addTextKeyInfo.style.display = 'none';
        }
    }
    

    
    // Add type tabs click handler
    addTypeTabs?.querySelectorAll('.preview-contextual-form__tab').forEach(tab => {
        tab.addEventListener('click', function() {
            sidebarAddNodeType = this.dataset.type;
            if (addTypeInput) addTypeInput.value = sidebarAddNodeType;
            localStorage.setItem('qs-add-last-tab', sidebarAddNodeType);
            updateSidebarAddTypeTabs(sidebarAddNodeType);
            updateSidebarAddNodeTypeUI();
            if (sidebarAddNodeType === 'component') {
                loadSidebarComponentsList();
            } else if (sidebarAddNodeType === 'snippet') {
                loadSidebarSnippetsList();
            } else {
                updateSidebarAddTextKeyPreview();
            }
        });
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
            btn.innerHTML = `
                <span class="tag-dropdown__option-tag">${escapeHTML(comp.name)}</span>
                ${varCount > 0 ? `<span class="tag-dropdown__option-desc">${varCount} variable${varCount > 1 ? 's' : ''}</span>` : ''}
            `;
            btn.addEventListener('click', () => {
                selectComponent(comp);
                closeDropdown();
            });
            return btn;
        }
        
        function renderOptions() {
            if (!optionsContainer) return;
            optionsContainer.innerHTML = '';
            
            const validComponents = componentsData.filter(c => c.valid);
            if (validComponents.length === 0) {
                optionsContainer.innerHTML = `<div class="snippet-selector__empty">
                    ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.ban, null)}
                    <span>${PreviewConfig.i18n.noComponentsFound || 'No components found'}</span>
                </div>`;
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
            if (displayDesc) displayDesc.textContent = varCount > 0 ? `${varCount} variable${varCount > 1 ? 's' : ''}` : '';
            
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
            previewFrame.src = PreviewConfig.baseUrl + '/?_component=' + encodeURIComponent(componentName) + '&_editor=1';
        }
        
        function renderComponentVars(comp) {
            const vars = comp.variables || [];
            
            if (addComponentVars) addComponentVars.style.display = 'block';
            
            if (vars.length === 0) {
                if (addComponentVarsContainer) addComponentVarsContainer.innerHTML = '';
                if (addComponentNoVars) addComponentNoVars.style.display = 'flex';
                return;
            }
            
            if (addComponentNoVars) addComponentNoVars.style.display = 'none';
            if (!addComponentVarsContainer) return;
            
            addComponentVarsContainer.innerHTML = '';
            
            const enumVars = vars.filter(v => v.type === 'enum');
            const paramVars = vars.filter(v => v.type === 'param');
            const textKeyVars = vars.filter(v => v.type === 'textKey');
            
            // Enums section — select dropdowns
            if (enumVars.length > 0) {
                const enumSection = document.createElement('div');
                enumSection.className = 'preview-variables-section';
                enumSection.innerHTML = `<div class="preview-variables-section__title">${PreviewConfig.i18n.variablesSectionEnums || 'Enums'}</div>`;
                
                enumVars.forEach(v => {
                    const options = v.options || [];
                    const defaultVal = v.default || options[0] || '';
                    const row = document.createElement('div');
                    row.className = 'preview-contextual-form__param-row';
                    row.innerHTML = `
                        <label class="preview-contextual-form__param-label">${escapeHTML(v.name)}:</label>
                        <select class="admin-input admin-input--sm preview-contextual-form__param-input"
                                data-var-name="${escapeHTML(v.name)}">
                            ${options.map(opt => `<option value="${escapeHTML(opt)}"${opt === defaultVal ? ' selected' : ''}>${escapeHTML(opt)}</option>`).join('')}
                        </select>
                    `;
                    enumSection.appendChild(row);
                });
                addComponentVarsContainer.appendChild(enumSection);
            }
            
            // Parameters section — editable inputs
            if (paramVars.length > 0) {
                const paramSection = document.createElement('div');
                paramSection.className = 'preview-variables-section';
                paramSection.innerHTML = `<div class="preview-variables-section__title">${PreviewConfig.i18n.variablesSectionParams || 'Parameters'}</div>`;
                
                paramVars.forEach(v => {
                    const row = document.createElement('div');
                    row.className = 'preview-contextual-form__param-row';
                    
                    // Check if this param variable should show an asset browse button
                    const showBrowse = v.paramName === 'src' && v.parentTag in ASSET_BROWSABLE_TAGS;
                    const showRouteList = v.paramName === 'href' && v.parentTag === 'a';
                    const datalistId = showRouteList ? `add-comp-${v.name}-routes` : '';
                    
                    row.innerHTML = `
                        <label class="preview-contextual-form__param-label">${escapeHTML(v.name)}:</label>
                        <div class="preview-contextual-form__param-input-group">
                            <input type="text" 
                                   class="admin-input admin-input--sm preview-contextual-form__param-input" 
                                   data-var-name="${escapeHTML(v.name)}"
                                   placeholder="${showRouteList ? (PreviewConfig.i18n.hrefPlaceholder || 'Select route or type URL') : `{{${escapeHTML(v.name)}}}`}"
                                   ${showRouteList ? `list="${datalistId}"` : ''}>
                            ${showBrowse ? `<button type="button" class="admin-btn admin-btn--sm admin-btn--outline preview-asset-browse-btn" data-category="${ASSET_BROWSABLE_TAGS[v.parentTag] || ''}" title="Browse assets">📁</button>` : ''}
                        </div>
                        ${showRouteList ? `<datalist id="${datalistId}"></datalist>` : ''}
                    `;
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
                textSection.innerHTML = `<div class="preview-variables-section__title">${PreviewConfig.i18n.variablesSectionText || 'Text Variables'}</div>`;
                
                const infoRow = document.createElement('div');
                infoRow.className = 'preview-contextual-form__info';
                infoRow.style.margin = '0';
                infoRow.innerHTML = `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span style="font-size:0.75rem;">${textKeyVars.map(v => escapeHTML(v.name)).join(', ')} — ${PreviewConfig.i18n.textKeyAutoGenerated || 'auto-generated text keys, editable in Text mode'}</span>
                `;
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
                if (optionsContainer) optionsContainer.innerHTML = `<div class="snippet-selector__loading"><div class="spinner"></div>${PreviewConfig.i18n.loading || 'Loading...'}</div>`;
            },
            showError: function() {
                if (optionsContainer) optionsContainer.innerHTML = `<div class="snippet-selector__empty">
                    ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.ban, null)}
                    <span>${PreviewConfig.i18n.errorLoadingComponents || 'Error loading components'}</span>
                </div>`;
            },
            reset: function() {
                sidebarAddSelectedComponentData = null;
                if (hiddenInput) hiddenInput.value = '';
                if (displayValue) displayValue.textContent = PreviewConfig.i18n.selectComponent || 'Select a component';
                if (displayDesc) displayDesc.textContent = '';
                if (previewPanel) previewPanel.style.display = 'none';
                if (addComponentVars) addComponentVars.style.display = 'none';
                if (addComponentVarsContainer) addComponentVarsContainer.innerHTML = '';
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
        
        // Source label helper
        function sourceLabel(source) {
            if (source === 'core') return 'Core';
            if (source === 'global') return 'Global';
            return 'Project';
        }
        
        function sourceFromSnippet(snippet) {
            return snippet.source || (snippet.isCore ? 'core' : 'project');
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
            btn.dataset.name = (snippet.name || snippet.id).toLowerCase();
            btn.dataset.desc = (snippet.description || '').toLowerCase();
            if (snippet.id === selectedSnippetId) btn.classList.add('selected');
            
            const src = sourceFromSnippet(snippet);
            const srcText = sourceLabel(src);
            btn.innerHTML = `
                <span class="tag-dropdown__option-tag">${escapeHTML(snippet.name || snippet.id)}</span>
                <span class="tag-dropdown__option-desc">${escapeHTML(snippet.description || '')}</span>
                <span class="snippet-selector__option-source snippet-selector__option-source--${src}">${srcText}</span>
            `;
            btn.addEventListener('click', () => {
                selectSnippet(snippet);
                closeDropdown();
            });
            return btn;
        }
        
        // Render options grouped by category
        function renderOptions() {
            if (!optionsContainer) return;
            optionsContainer.innerHTML = '';
            
            const categories = Object.keys(snippetsData.byCategory || {});
            if (categories.length === 0) {
                optionsContainer.innerHTML = `<div class="snippet-selector__empty">
                    ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.ban, null)}
                    <span>${PreviewConfig.i18n.noSnippetsFound || 'No snippets found'}</span>
                </div>`;
                return;
            }
            
            categories.forEach(cat => {
                const group = document.createElement('div');
                group.className = 'tag-dropdown__group';
                
                const header = document.createElement('button');
                header.type = 'button';
                header.className = 'tag-dropdown__group-header';
                header.innerHTML = `<span>${cat.charAt(0).toUpperCase() + cat.slice(1)}</span>
                    <svg class="tag-dropdown__group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>`;
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
            if (displayValue) displayValue.textContent = snippet ? (snippet.name || snippet.id) : (PreviewConfig.i18n.selectSnippet || 'Select a snippet');
            if (displayDesc) displayDesc.textContent = snippet ? (snippet.description || '') : '';
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
                if (optionsContainer) optionsContainer.innerHTML = `<div class="snippet-selector__loading"><div class="spinner"></div>${PreviewConfig.i18n.loading || 'Loading...'}</div>`;
            },
            showError: function() {
                if (optionsContainer) optionsContainer.innerHTML = `<div class="snippet-selector__empty">
                    ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.ban, null)}
                    <span>${PreviewConfig.i18n.errorLoadingSnippets || 'Error loading snippets'}</span>
                </div>`;
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
        
        const src = snippet.source || (snippet.isCore ? 'core' : 'project');
        const srcLabel = src === 'core' ? 'Core' : (src === 'global' ? 'Global' : 'Project');
        
        // Show preview panel
        if (addSnippetPreview) {
            addSnippetPreview.style.display = 'block';
            if (addSnippetPreviewTitle) addSnippetPreviewTitle.textContent = snippet.name || snippet.id;
            if (addSnippetPreviewSource) {
                addSnippetPreviewSource.textContent = srcLabel;
                addSnippetPreviewSource.className = `snippet-selector__preview-source snippet-selector__preview-source--${src}`;
            }
            if (addSnippetPreviewDesc) addSnippetPreviewDesc.textContent = snippet.description || '';
            
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
            showToast(PreviewConfig.i18n.noSnippetSelected || 'No snippet selected', 'warning');
            return;
        }
        
        const src = selectedSnippetData.source || (selectedSnippetData.isCore ? 'core' : 'project');
        if (src === 'core') {
            showToast(PreviewConfig.i18n.cannotDeleteCore || 'Cannot delete core snippets', 'error');
            return;
        }
        
        const snippetName = selectedSnippetData.name || selectedSnippetId;
        const confirmMsg = (PreviewConfig.i18n.confirmDeleteSnippet || 'Delete snippet "%s"?').replace('%s', snippetName);
        
        if (!confirm(confirmMsg)) return;
        
        try {
            const response = await QuickSiteAdmin.apiRequest('deleteSnippet', 'DELETE', null, [], { id: selectedSnippetId });
            
            if (response.ok) {
                showToast(PreviewConfig.i18n.snippetDeleted || 'Snippet deleted successfully', 'success');
                
                // Reset selection and trigger
                if (addSnippetSelector) addSnippetSelector.reset();
                
                // Refresh snippets list
                snippetsLoaded = false;
                await loadSidebarSnippetsList();
            } else {
                throw new Error(response.data?.message || 'Failed to delete snippet');
            }
        } catch (error) {
            console.error('[Preview] Delete snippet error:', error);
            showToast(error.message || PreviewConfig.i18n.deleteSnippetFailed || 'Failed to delete snippet', 'error');
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
            addSnippetCssSelectors.innerHTML = selectors.map(s => {
                const icon = s.exists ? '✓' : '✗';
                const cls = s.exists ? 'snippet-selector__css-badge--found' : 'snippet-selector__css-badge--missing';
                return `<span class="snippet-selector__css-badge ${cls}">${icon} ${escapeHTML(s.selector)}</span>`;
            }).join('');
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
    
    // Inject snippet CSS into target project stylesheet
    // Returns true on success, false on failure
    async function injectSnippetCss(mode) {
        if (!selectedSnippetId) return false;
        
        const label = mode === 'missing' ? 'Adding missing CSS...' : 'Replacing CSS...';
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
                        ? (count > 0 ? `Added ${count} CSS rule(s)` : 'All selectors already present')
                        : 'CSS replaced successfully',
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
                throw new Error(response.data?.message || 'CSS injection failed');
            }
        } catch (error) {
            console.error('[Preview] CSS inject error:', error);
            showToast(error.message || 'Failed to inject CSS', 'error');
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

    // Render snippet preview in iframe
    function renderSnippetPreview(snippetData) {
        if (!addSnippetPreviewFrame) return;
        
        // Use expanded preview structure if available (for component-based snippets)
        const structureToRender = snippetData.previewStructure || snippetData.structure;
        
        // Build HTML from structure
        const html = buildSnippetHtml(structureToRender, snippetData);
        
        // Get project CSS URL — include only when toggle is not active
        const projectStyleUrl = PreviewConfig.projectStyleUrl || '';
        const includeProjectStyle = snippetPreviewWithProjectStyle && projectStyleUrl;
        
        // Show/hide the style toggle when snippet has its own CSS
        if (addSnippetStyleToggle) {
            addSnippetStyleToggle.style.display = snippetData.css ? '' : 'none';
        }
        
        // Create preview document
        const previewDoc = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ${includeProjectStyle ? `<link rel="stylesheet" href="${projectStyleUrl}">` : ''}
    <style>
        body { margin: 8px; font-family: system-ui, -apple-system, sans-serif; }
        ${snippetData.css || ''}
    </style>
</head>
<body>${html}</body>
</html>`;
        
        // Write to iframe
        addSnippetPreviewFrame.srcdoc = previewDoc;
    }
    
    // Build HTML from snippet structure (recursive)
    function buildSnippetHtml(structure, snippetData, depth = 0) {
        if (!structure) return '';
        
        // Handle array of nodes
        if (Array.isArray(structure)) {
            return structure.map(node => buildSnippetHtml(node, snippetData, depth)).join('');
        }
        
        // Handle text-only node (no tag, just textKey)
        if (!structure.tag && structure.textKey) {
            const lang = PreviewConfig.defaultLang || 'en';
            return snippetData.translations?.[lang]?.[structure.textKey] || 
                   snippetData.translations?.['en']?.[structure.textKey] || 
                   structure.textKey;
        }
        
        // Handle single node with tag
        const tag = structure.tag || 'div';
        const attrs = [];
        
        // Build attributes from params (our structure format)
        if (structure.params) {
            Object.entries(structure.params).forEach(([key, value]) => {
                if (typeof value === 'string') {
                    // Handle translatable params like {{textKey:...}}
                    if (value.startsWith('{{textKey:') && value.endsWith('}}')) {
                        const transKey = value.slice(10, -2);
                        const lang = PreviewConfig.defaultLang || 'en';
                        const translated = snippetData.translations?.[lang]?.[transKey] || 
                                          snippetData.translations?.['en']?.[transKey] || 
                                          transKey;
                        attrs.push(`${key}="${escapeHTML(translated)}"`);
                    } else {
                        attrs.push(`${key}="${escapeHTML(value)}"`);
                    }
                }
            });
        }
        
        // Also check for legacy attrs format
        if (structure.attrs) {
            Object.entries(structure.attrs).forEach(([key, value]) => {
                if (typeof value === 'string') {
                    attrs.push(`${key}="${escapeHTML(value)}"`);
                }
            });
        }
        
        // Get direct text content (if textKey is on the node itself)
        let textContent = '';
        if (structure.textKey && snippetData.translations) {
            const lang = PreviewConfig.defaultLang || 'en';
            textContent = snippetData.translations[lang]?.[structure.textKey] || 
                         snippetData.translations['en']?.[structure.textKey] || 
                         structure.textKey;
        }
        
        // Self-closing tags
        const selfClosing = ['img', 'br', 'hr', 'input', 'meta', 'link', 'source', 'track', 'wbr', 'area', 'base', 'col', 'embed', 'param'];
        if (selfClosing.includes(tag)) {
            return `<${tag}${attrs.length ? ' ' + attrs.join(' ') : ''}>`;
        }
        
        // Build children
        const children = structure.children ? buildSnippetHtml(structure.children, snippetData, depth + 1) : '';
        
        return `<${tag}${attrs.length ? ' ' + attrs.join(' ') : ''}>${textContent}${children}</${tag}>`;
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
        let tagExamples = {};
        const dataEl = document.getElementById(`${selectorId}-tag-data`);
        if (dataEl) {
            try {
                const parsed = JSON.parse(dataEl.textContent);
                tagData = parsed.tags || {};
                tagExamples = parsed.examples || {};
            } catch (e) {
                console.error('Failed to parse tag data:', e);
            }
        }
        
        // Code view toggle (persisted in localStorage)
        const CODE_VIEW_KEY = 'tagSelector_showHtmlCode';
        let showHtmlCode = localStorage.getItem(CODE_VIEW_KEY) === 'true';
        if (codeToggle && codeView) {
            if (showHtmlCode) {
                codeToggle.classList.add('active');
                codeView.style.display = '';
            }
            codeToggle.addEventListener('click', function() {
                showHtmlCode = !showHtmlCode;
                localStorage.setItem(CODE_VIEW_KEY, showHtmlCode);
                this.classList.toggle('active', showHtmlCode);
                codeView.style.display = showHtmlCode ? '' : 'none';
            });
        }
        
        // Favorites from localStorage
        const FAVORITES_KEY = 'qs-add-favorite-tags';
        const MAX_FAVORITES = 10;
        
        function getFavorites() {
            try { return JSON.parse(localStorage.getItem(FAVORITES_KEY)) || []; }
            catch { return []; }
        }
        
        function saveFavorites(favs) {
            localStorage.setItem(FAVORITES_KEY, JSON.stringify(favs.slice(0, MAX_FAVORITES)));
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
                (PreviewConfig?.i18n?.unstarTag || 'Remove from favorites') : 
                (PreviewConfig?.i18n?.starTag || 'Add to favorites');
        }
        
        function renderFavorites() {
            if (!favoritesGroup || !favoritesItems) return;
            const favs = getFavorites();
            if (favs.length === 0) {
                favoritesGroup.style.display = 'none';
                return;
            }
            favoritesGroup.style.display = '';
            favoritesItems.innerHTML = '';
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
            suggestedItems.innerHTML = '';
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
            btn.innerHTML = `
                <code class="tag-dropdown__option-tag">&lt;${tag}&gt;</code>
                <span class="tag-dropdown__option-desc">${info.desc}</span>
                ${info.required ? `<span class="tag-dropdown__option-required" title="${PreviewConfig?.i18n?.requiresParams || 'Requires additional parameters'}">*</span>` : ''}
            `;
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
            
            const exampleHtml = tagExamples[tagName];
            if (exampleHtml === null || exampleHtml === undefined) {
                if (previewRender) previewRender.style.display = 'none';
                if (previewNoRender) previewNoRender.style.display = 'flex';
                if (codeView) codeView.style.display = 'none';
            } else {
                if (previewRender) {
                    previewRender.style.display = '';
                    previewRender.innerHTML = exampleHtml;
                }
                if (previewNoRender) previewNoRender.style.display = 'none';
                if (codeContent) {
                    codeContent.textContent = formatHtmlForDisplay(exampleHtml);
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
            chipsEl.innerHTML = '';
            selectedClasses.forEach(cls => {
                const chip = document.createElement('span');
                chip.className = 'class-combobox__chip';
                chip.innerHTML = `<span class="class-combobox__chip-text">${cls}</span><button type="button" class="class-combobox__chip-remove" data-class="${cls}" title="Remove">&times;</button>`;
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
            
            suggestionsEl.innerHTML = '';
            const maxShow = 30;
            const displayed = matches.slice(0, maxShow);
            
            if (displayed.length === 0 && q) {
                const hint = document.createElement('div');
                hint.className = 'class-combobox__hint';
                hint.textContent = `Press Enter to add "${q}"`;
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
                more.textContent = `+${matches.length - maxShow} more…`;
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
        updateSidebarAddTextKeyPreview();
        
        // Show info message for iframe tag
        let iframeInfo = addMandatoryParamsContainer?.parentElement?.querySelector('.preview-iframe-info');
        if (this.value === 'iframe') {
            if (!iframeInfo) {
                iframeInfo = document.createElement('div');
                iframeInfo.className = 'preview-iframe-info';
                iframeInfo.innerHTML = `<small>ℹ️ Iframes load external content. Only use trusted HTTPS sources. The iframe is sandboxed within your page's same-origin policy.</small>`;
                addMandatoryParamsContainer?.parentElement?.appendChild(iframeInfo);
            }
            iframeInfo.style.display = 'block';
        } else if (iframeInfo) {
            iframeInfo.style.display = 'none';
        }
    });
    
    // Top confirm button — same as bottom confirm
    addConfirmTopBtn?.addEventListener('click', function() {
        addConfirmBtn?.click();
    });
    
    // Add another param
    addAnotherParamBtn?.addEventListener('click', function() {
        if (!addCustomParamsList) return;
        sidebarAddCustomParamsCount++;
        const row = document.createElement('div');
        row.className = 'preview-contextual-form__param-row preview-contextual-form__param-row--custom';
        row.innerHTML = `
            <input type="text" 
                   class="admin-input admin-input--sm preview-contextual-form__param-key" 
                   placeholder="${PreviewConfig.i18n.paramName || 'name'}" 
                   data-param-index="${sidebarAddCustomParamsCount}">
            <input type="text" 
                   class="admin-input admin-input--sm preview-contextual-form__param-value" 
                   placeholder="${PreviewConfig.i18n.paramValue || 'value'}"
                   data-param-index="${sidebarAddCustomParamsCount}">
            <button type="button" class="preview-contextual-form__remove-param" title="${PreviewConfig.i18n.remove || 'Remove'}">
                ${QuickSiteUtils.iconClose(14)}
            </button>
        `;
        row.querySelector('.preview-contextual-form__remove-param').addEventListener('click', () => row.remove());
        addCustomParamsList.appendChild(row);
    });
    
    // Cancel button
    addCancelBtn?.addEventListener('click', hideSidebarAddForm);
    
    // Confirm button - add the element
    addConfirmBtn?.addEventListener('click', async function() {
        if (selectedStruct == null || selectedNode == null) {
            showToast(PreviewConfig.i18n.selectNodeFirst, 'warning');
            return;
        }
        
        try {
            if (sidebarAddNodeType === 'tag') {
                await addTagNode();
            } else if (sidebarAddNodeType === 'component') {
                await addComponentNode();
            } else if (sidebarAddNodeType === 'snippet') {
                await addSnippetNode();
            }
        } catch (error) {
            console.error('[Preview] Add node error:', error);
            showToast(error.message || PreviewConfig.i18n.addNodeError, 'error');
        }
    });
    
    // Add tag node
    async function addTagNode() {
        const tag = addTagSelect?.value || 'div';
        const position = getAddPosition();
        // Flush any uncommitted text from the class combobox before reading
        if (classCombobox) classCombobox.flush();
        const classes = addClassInput?.value?.trim() || '';
        
        // Collect mandatory params
        const params = {};
        const mandatoryParamFields = addMandatoryParamsContainer?.querySelectorAll('input, select') || [];
        mandatoryParamFields.forEach(field => {
            const paramName = field.id.replace('add-mandatory-', '');
            if (field.value) params[paramName] = field.value;
        });

        // Input wizard (Group A): client-side validate that 'name' is
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
                throw new Error(
                    PreviewConfig.i18n.nameRequiredForInput
                    || "A name is required for input type '" + typeVal + "'."
                );
            }
        }
        
        // Collect custom params
        const customRows = addCustomParamsList?.querySelectorAll('.preview-contextual-form__param-row--custom') || [];
        customRows.forEach(row => {
            const key = row.querySelector('.preview-contextual-form__param-key')?.value?.trim();
            const value = row.querySelector('.preview-contextual-form__param-value')?.value?.trim();
            if (key && value) params[key] = value;
        });
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error('Invalid structure type');
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
            
            showToast(PreviewConfig.i18n.nodeAdded || 'Element added', 'success');
            hideSidebarAddForm();
            
            // Live DOM update if HTML returned
            if (data.html && targetNode) {
                console.log('[Preview] Live DOM insert:', { struct: targetStruct, targetNode, position, newNodeId: data.newNodeId });
                sendToIframe('insertNode', {
                    struct: targetStruct,
                    targetNode: targetNode,
                    position: position,
                    html: data.html,
                    newNodeId: data.newNodeId
                });
            } else {
                console.log('[Preview] No HTML returned, reloading preview. Data:', data);
                reloadPreview();
            }
        } else {
            throw new Error(response.data?.message || 'Failed to add element');
        }
    }
    
    // Add component node
    async function addComponentNode() {
        const componentName = addComponentSelect?.value;
        if (!componentName) {
            showToast(PreviewConfig.i18n.selectComponent || 'Please select a component', 'warning');
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
            throw new Error('Invalid structure type');
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
        
        const response = await QuickSiteAdmin.apiRequest('addComponentToNode', 'POST', requestData);
        
        if (response.ok) {
            const data = response.data?.data || {};
            
            // Save selection values BEFORE hiding form (in case hiding clears them)
            const targetStruct = selectedStruct;
            const targetNode = selectedNode;
            
            showToast(PreviewConfig.i18n.componentAdded || 'Component added', 'success');
            hideSidebarAddForm();
            
            // Live DOM update if HTML returned
            if (data.html && targetNode) {
                console.log('[Preview] Live DOM insert component:', { struct: targetStruct, targetNode, position, newNodeId: data.nodeId });
                sendToIframe('insertNode', {
                    struct: targetStruct,
                    targetNode: targetNode,
                    position: position,
                    html: data.html,
                    newNodeId: data.nodeId
                });
            } else {
                console.log('[Preview] No component HTML returned, reloading preview. Data:', data);
                reloadPreview();
            }
        } else {
            throw new Error(response.data?.message || 'Failed to add component');
        }
    }
    
    // Add snippet node (inserts snippet structure as nodes)
    async function addSnippetNode() {
        if (!selectedSnippetId || !selectedSnippetData) {
            showToast(PreviewConfig.i18n.selectSnippet || 'Please select a snippet', 'warning');
            return;
        }

        // Check CSS action radio selection — inject CSS before inserting if needed
        const cssAction = getSelectedCssAction();
        let cssWasInjected = false;
        if (cssAction === 'missing' || cssAction === 'replace') {
            const confirmed = confirm(
                'This will permanently modify the project\'s CSS stylesheet and may affect the style of all pages.\n\nThe page will reload after insertion to apply the new styles.\n\nContinue?'
            );
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
            throw new Error('Invalid structure type');
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
            
            const translationsMsg = data.translationsAdded > 0 
                ? ` (${data.translationsAdded} translations added)` 
                : '';
            showToast((PreviewConfig.i18n.snippetAdded || 'Snippet added') + translationsMsg, 'success');
            hideSidebarAddForm();
            
            // If CSS was injected, force full reload so the iframe picks up the new stylesheet
            if (cssWasInjected) {
                console.log('[Preview] CSS was injected, forcing full reload.');
                reloadPreview();
            } else if (data.html && targetNode) {
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
        } else {
            throw new Error(response.data?.message || 'Failed to insert snippet');
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
     * Shared helper for Save as Snippet and Save as Component.
     * Returns { nodeData, structInfo } or throws on error.
     */
    async function extractSelectedNodeStructure() {
        // Must have selection
        if (selectedStruct == null || selectedNode == null) {
            throw new Error(PreviewConfig.i18n.selectNodeFirst || 'Select an element first');
        }
        if (selectedNode === '' && currentEditType !== 'component') {
            throw new Error(PreviewConfig.i18n?.cannotModifyRoot || 'Cannot save the root. Select a child element.');
        }

        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error('Invalid structure type');
        }

        // Fetch full structure
        const urlParams = [structInfo.type];
        if (structInfo.name) {
            urlParams.push(structInfo.name);
        }
        const response = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
        if (!response.ok || !response.data?.data?.structure) {
            throw new Error('Failed to get structure');
        }

        const structure = response.data.data.structure;
        const nodeData = selectedNode === '' ? structure : navigateToNode(structure, selectedNode);
        if (!nodeData) {
            throw new Error('Node not found in structure');
        }

        return { nodeData: JSON.parse(JSON.stringify(nodeData)), structInfo };
    }

    /**
     * Build a structure preview HTML string for a node.
     */
    function buildStructurePreviewHtml(nodeData) {
        const tag = nodeData.tag || nodeData.component || '?';
        const classes = nodeData.params?.class || '';
        const childCount = (nodeData.children || []).length;
        return `<code>&lt;${tag}${classes ? ' class="' + classes + '"' : ''}&gt;</code>` +
            (childCount > 0 ? ` <small>(${childCount} children)</small>` : '');
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

            // Reset form fields
            if (saveSnippetName) saveSnippetName.value = '';
            if (saveSnippetId) saveSnippetId.value = '';
            if (saveSnippetCategory) saveSnippetCategory.value = 'other';
            if (saveSnippetDesc) saveSnippetDesc.value = '';

            // Show structure preview
            if (saveSnippetPreview) {
                saveSnippetPreview.innerHTML = buildStructurePreviewHtml(nodeData);
            }

            if (saveSnippetName) saveSnippetName.focus();

        } catch (error) {
            console.error('[Preview] Save snippet form error:', error);
            showToast(error.message || PreviewConfig.i18n.loadError || 'Failed to load element data', 'error');
        }
    }
    
    // Hide save snippet form
    function hideSaveSnippetForm() {
        if (!saveSnippetForm) return;
        
        saveSnippetForm.style.display = 'none';
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

            // Reset form fields
            if (saveComponentName) saveComponentName.value = '';

            // Show structure preview
            if (saveComponentPreview) {
                saveComponentPreview.innerHTML = buildStructurePreviewHtml(nodeData);
            }

            if (saveComponentName) saveComponentName.focus();

        } catch (error) {
            console.error('[Preview] Save component form error:', error);
            showToast(error.message || PreviewConfig.i18n.loadError || 'Failed to load element data', 'error');
        }
    }

    // Hide save component form
    function hideSaveComponentForm() {
        if (!saveComponentForm) return;

        saveComponentForm.style.display = 'none';
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
            showToast(PreviewConfig.i18n.noStructureData || 'No structure data available', 'error');
            return;
        }

        const name = saveComponentName?.value?.trim();
        if (!name) {
            showToast(PreviewConfig.i18n.componentNameRequired || 'Component name is required', 'warning');
            saveComponentName?.focus();
            return;
        }

        if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(name)) {
            showToast(PreviewConfig.i18n.componentNameInvalid || 'Invalid name. Use only letters, numbers, hyphens, and underscores.', 'warning');
            saveComponentName?.focus();
            return;
        }

        showToast(PreviewConfig.i18n.saving || 'Saving...', 'info');

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
                showToast(PreviewConfig.i18n.componentSaved || 'Component saved successfully!', 'success');

                // Add to component dropdown in navigation if not already there
                const tgtSelect = document.getElementById('preview-target');
                if (tgtSelect) {
                    const existingOption = tgtSelect.querySelector('option[value="component:' + name + '"]');
                    if (!existingOption) {
                        let componentsGroup = tgtSelect.querySelector('optgroup[label*="Components"], optgroup[label*="🧩"]');
                        if (!componentsGroup) {
                            componentsGroup = document.createElement('optgroup');
                            componentsGroup.label = '🧩 ' + (PreviewConfig.i18n.components || 'Components');
                            tgtSelect.appendChild(componentsGroup);
                        }
                        const newOption = document.createElement('option');
                        newOption.value = 'component:' + name;
                        newOption.textContent = name;
                        componentsGroup.appendChild(newOption);
                    }
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
                throw new Error(response.data?.message || 'Failed to save component');
            }
        } catch (error) {
            console.error('[Preview] Save component error:', error);
            showToast(error.message || PreviewConfig.i18n.saveFailed || 'Failed to save component', 'error');
        }
    }
    
    // ==================== Variables Panel (Component-only) ====================
    
    // Stored full component structure when Variables panel is open
    let variablesPanelStructure = null;
    // Cached flat list of translation key names
    let variablesPanelTranslationKeys = [];
    // Cached map of translation key → first-language value (for preview)
    let variablesPanelTranslationValues = {};
    // Cached list of available language codes
    let variablesPanelLanguages = [];
    // Cached list of enum variable names (keys from __enums__)
    let variablesPanelEnumVarNames = [];
    
    /**
     * Flatten nested translation object into dot-notation keys
     * e.g. { home: { title: "x" } } → ["home.title"]
     * Also populates valueMap with key → value mappings
     */
    function flattenTranslationKeys(obj, prefix, valueMap) {
        const keys = [];
        if (!obj || typeof obj !== 'object') return keys;
        for (const key of Object.keys(obj)) {
            const fullKey = prefix ? prefix + '.' + key : key;
            if (typeof obj[key] === 'object' && obj[key] !== null && !Array.isArray(obj[key])) {
                keys.push(...flattenTranslationKeys(obj[key], fullKey, valueMap));
            } else {
                keys.push(fullKey);
                if (valueMap) {
                    valueMap[fullKey] = (obj[key] != null) ? String(obj[key]) : '';
                }
            }
        }
        return keys;
    }
    
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
        
        variablesPanel.style.display = '';
        
        // Show loading
        if (variablesPanelLoading) variablesPanelLoading.style.display = '';
        if (variablesPanelEmpty) variablesPanelEmpty.style.display = 'none';
        if (variablesPanelCards) variablesPanelCards.innerHTML = '';
        if (variablesPanelFooter) variablesPanelFooter.style.display = 'none';
        
        try {
            // Fetch component structure AND translation keys in parallel
            const structInfo = parseStruct(selectedStruct || ('component-' + currentEditName));
            if (!structInfo) throw new Error('Invalid struct');
            
            const urlParams = [structInfo.type];
            if (structInfo.name) urlParams.push(structInfo.name);
            
            const [resp, transResp] = await Promise.all([
                QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams),
                QuickSiteAdmin.apiRequest('getTranslations', 'GET')
            ]);
            
            if (!resp.ok || !resp.data?.data?.structure) throw new Error('Failed to get structure');
            
            variablesPanelStructure = JSON.parse(JSON.stringify(resp.data.data.structure));
            
            // Extract available enum variable names from __enums__
            variablesPanelEnumVarNames = [];
            const enums = variablesPanelStructure.__enums__;
            if (enums && typeof enums === 'object') {
                variablesPanelEnumVarNames = Object.keys(enums);
            }
            
            // Extract flat translation key list from first available language
            variablesPanelTranslationKeys = [];
            variablesPanelTranslationValues = {};
            variablesPanelLanguages = [];
            if (transResp.ok && transResp.data?.data?.translations) {
                const allTranslations = transResp.data.data.translations;
                variablesPanelLanguages = transResp.data.data.languages || Object.keys(allTranslations);
                const firstLang = Object.keys(allTranslations)[0];
                if (firstLang) {
                    variablesPanelTranslationValues = {};
                    variablesPanelTranslationKeys = flattenTranslationKeys(allTranslations[firstLang], '', variablesPanelTranslationValues).sort();
                }
            }
            
            // Navigate to the selected node (fallback to root)
            const nodeId = (selectedNode != null) ? selectedNode : '';
            const targetNode = getNodeByPath(variablesPanelStructure, nodeId);
            if (!targetNode) throw new Error('Node not found: ' + nodeId);
            
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
                const display = nodeId === '' ? tag + ' (root)' : tag + ' [' + nodeId + ']';
                variablesPanelFooterText.textContent = (PreviewConfig.i18n?.variablesNodeInfo || 'Showing: %s').replace('%s', display);
                variablesPanelFooter.style.display = '';
            }
            
        } catch (error) {
            console.error('[Preview] Variables panel error:', error);
            if (variablesPanelLoading) variablesPanelLoading.style.display = 'none';
            if (variablesPanelCards) {
                variablesPanelCards.innerHTML = '<div style="color:var(--admin-danger);font-size:var(--font-size-sm);padding:var(--space-sm);">' + 
                    (PreviewConfig.i18n?.error || 'Error') + ': ' + error.message + '</div>';
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
            // Searchable combo: text input + dropdown list + create form
            let selectedKey = currentValue || '';
            
            const combo = document.createElement('div');
            combo.className = 'preview-variable-combo';
            
            // Search input
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'preview-variable-combo__input';
            input.placeholder = PreviewConfig.i18n?.variablesPlaceholderTranslation || 'Search or type a translation key';
            input.value = selectedKey;
            input.autocomplete = 'off';
            combo.appendChild(input);
            
            // Dropdown list
            const dropdown = document.createElement('div');
            dropdown.className = 'preview-variable-combo__dropdown';
            dropdown.style.display = 'none';
            combo.appendChild(dropdown);
            
            // Truncate helper
            function truncateValue(val, max) {
                if (!val) return '';
                return val.length > max ? val.substring(0, max) + '…' : val;
            }
            
            // Build filtered option list
            function buildDropdown(query) {
                dropdown.innerHTML = '';
                const q = (query || '').toLowerCase();
                let hasExact = false;
                let count = 0;
                
                variablesPanelTranslationKeys.forEach(key => {
                    if (q && !key.toLowerCase().includes(q)) return;
                    if (key.toLowerCase() === q) hasExact = true;
                    if (count >= 80) return; // Limit visible options for performance
                    count++;
                    
                    const item = document.createElement('div');
                    item.className = 'preview-variable-combo__item';
                    if (key === selectedKey) item.classList.add('preview-variable-combo__item--selected');
                    item.dataset.key = key;
                    
                    const keySpan = document.createElement('span');
                    keySpan.className = 'preview-variable-combo__item-key';
                    keySpan.textContent = key;
                    item.appendChild(keySpan);
                    
                    const val = variablesPanelTranslationValues[key];
                    if (val) {
                        const valSpan = document.createElement('span');
                        valSpan.className = 'preview-variable-combo__item-value';
                        valSpan.textContent = truncateValue(val, 30);
                        valSpan.title = val;
                        item.appendChild(valSpan);
                    }
                    
                    item.addEventListener('mousedown', (e) => {
                        e.preventDefault(); // Prevent blur
                        selectedKey = key;
                        input.value = key;
                        dropdown.style.display = 'none';
                        createForm.style.display = 'none';
                    });
                    
                    dropdown.appendChild(item);
                });
                
                // Show "create" form when query doesn't match any exact key
                if (q && !hasExact) {
                    createForm.style.display = '';
                    const resolvedKey = q.startsWith('.') || q.startsWith('#') ? q : q;
                    createKeyLabel.textContent = (PreviewConfig.i18n?.variablesCreateKey || 'Create') + ' "' + query.trim() + '"';
                } else {
                    createForm.style.display = 'none';
                }
                
                dropdown.style.display = (count > 0 || (q && !hasExact)) ? '' : 'none';
            }
            
            // Create new key form
            const createForm = document.createElement('div');
            createForm.className = 'preview-variable-combo__create';
            createForm.style.display = 'none';
            
            const createKeyLabel = document.createElement('div');
            createKeyLabel.className = 'preview-variable-combo__create-label';
            createForm.appendChild(createKeyLabel);
            
            const createValueRow = document.createElement('div');
            createValueRow.className = 'preview-variable-combo__create-row';
            
            const createValueLabel = document.createElement('label');
            createValueLabel.className = 'preview-variable-combo__create-value-label';
            createValueLabel.textContent = PreviewConfig.i18n?.variablesCreateKeyValue || 'Value';
            createValueRow.appendChild(createValueLabel);
            
            const createValueInput = document.createElement('input');
            createValueInput.type = 'text';
            createValueInput.className = 'preview-variable-combo__create-value';
            createValueInput.placeholder = 'e.g. My text content';
            createValueRow.appendChild(createValueInput);
            
            createForm.appendChild(createValueRow);
            
            const createBtn = document.createElement('button');
            createBtn.type = 'button';
            createBtn.className = 'admin-btn admin-btn--success admin-btn--sm';
            createBtn.textContent = PreviewConfig.i18n?.variablesCreateKey || 'Create';
            
            createBtn.addEventListener('click', async () => {
                const newKey = input.value.trim();
                const newValue = createValueInput.value.trim() || newKey;
                if (!newKey) return;
                
                createBtn.disabled = true;
                try {
                    // Create for all languages; only current language gets the provided value.
                    const currentLang = getCurrentLang();
                    const targetLanguages = variablesPanelLanguages.length > 0
                        ? variablesPanelLanguages
                        : [currentLang];

                    const promises = targetLanguages.map(lang => {
                        const valueForLang = (lang === currentLang) ? newValue : '';
                        // Build nested object from dot-notation key
                        const translations = {};
                        const parts = newKey.split('.');
                        let obj = translations;
                        for (let i = 0; i < parts.length - 1; i++) {
                            obj[parts[i]] = {};
                            obj = obj[parts[i]];
                        }
                        obj[parts[parts.length - 1]] = valueForLang;
                        
                        return QuickSiteAdmin.apiRequest('setTranslationKeys', 'POST', {
                            language: lang,
                            translations: translations
                        });
                    });
                    
                    await Promise.all(promises);
                    
                    // Add to local cache
                    variablesPanelTranslationKeys.push(newKey);
                    variablesPanelTranslationKeys.sort();
                    variablesPanelTranslationValues[newKey] = newValue;
                    
                    // Select it
                    selectedKey = newKey;
                    input.value = newKey;
                    dropdown.style.display = 'none';
                    createForm.style.display = 'none';
                    
                    if (typeof showToast === 'function') {
                        showToast(PreviewConfig.i18n?.variablesKeyCreated || 'Translation key created', 'success');
                    }
                } catch (err) {
                    console.error('[Variables] Create translation key error:', err);
                    if (typeof showToast === 'function') {
                        showToast(PreviewConfig.i18n?.variablesKeyCreateError || 'Failed to create translation key', 'error');
                    }
                } finally {
                    createBtn.disabled = false;
                }
            });
            
            createForm.appendChild(createBtn);
            combo.appendChild(createForm);
            
            // Events
            input.addEventListener('focus', () => {
                buildDropdown(input.value);
            });
            
            input.addEventListener('input', () => {
                buildDropdown(input.value);
                // Update selectedKey to raw input for freeform typing
                selectedKey = input.value.trim();
            });
            
            input.addEventListener('blur', () => {
                // Delay to allow mousedown on dropdown items
                setTimeout(() => {
                    dropdown.style.display = 'none';
                    // Don't hide create form on blur — user may be typing in value input
                }, 200);
            });
            
            // Allow Enter in create value input to trigger create
            createValueInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    createBtn.click();
                }
            });
            
            wrapper.appendChild(combo);
            return { container: wrapper, getValue: () => selectedKey, el: input };
        }

        if (type === 'enum') {
            // Select dropdown populated from __enums__ variable names
            const sel = document.createElement('select');
            sel.className = 'preview-variable-card__value-select';
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '— ' + (PreviewConfig.i18n?.variablesSelectEnum || 'Select an enum variable') + ' —';
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
            hint.textContent = PreviewConfig.i18n?.variablesHintEnum || 'Resolved from enum definition at render time';
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
            hint.textContent = PreviewConfig.i18n?.variablesHintVariable || '{{}} added automatically — use CAPS by convention';
        } else if (type === 'raw') {
            hint.textContent = PreviewConfig.i18n?.variablesHintRaw || '__RAW__ added automatically — same in all languages';
        }
        wrapper.appendChild(hint);

        return { container: wrapper, getValue: () => inp.value.trim(), el: inp };
    }

    /**
     * Render both text and param variable sections
     */
    function renderVariableSections(textKeyNodes, paramNodes) {
        if (!variablesPanelCards) return;
        variablesPanelCards.innerHTML = '';
        
        // Text Variables section
        if (textKeyNodes.length > 0) {
            const section = document.createElement('div');
            section.className = 'preview-variables-section';
            
            const title = document.createElement('h5');
            title.className = 'preview-variables-section__title';
            title.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>' +
                (PreviewConfig.i18n?.variablesSectionText || 'Text Variables');
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
            title.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>' +
                (PreviewConfig.i18n?.variablesSectionParams || 'Parameters');
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
            pathEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6m-7-7H1m22 0h-4"/></svg>' +
                '<span>' + escapeHtml(item.path + ' [' + item.nodeId + ']') + '</span>';
            pathEl.title = PreviewConfig.i18n?.variablesClickToSelect || 'Click to select this node';
            pathEl.addEventListener('click', function() {
                hideVariablesPanel();
                // Try to select this node in the overlay
                if (item.nodeId) {
                    sendToIframe('selectNode', { nodeId: item.nodeId });
                }
            });
            card.appendChild(pathEl);
            
            // Type selector
            const typeDiv = document.createElement('div');
            typeDiv.className = 'preview-variable-card__type';
            const typeSelect = document.createElement('select');
            typeSelect.innerHTML = 
                '<option value="translation"' + (type === 'translation' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeTranslation || 'Translation Key') + '</option>' +
                '<option value="variable"' + (type === 'variable' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeVariable || 'Variable {{...}}') + '</option>' +
                '<option value="enum"' + (type === 'enum' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeEnum || 'Enum') + '</option>' +
                '<option value="raw"' + (type === 'raw' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeRaw || 'Raw Text') + '</option>';
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
            saveBtn.textContent = PreviewConfig.i18n?.save || 'Save';
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
                
                previewEl.textContent = '\u2192 (empty)';
                
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
                    showToast(PreviewConfig.i18n?.variablesValueRequired || 'Value cannot be empty', 'warning');
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
            card.className = 'preview-variable-card preview-variable-card--param';
            card.dataset.paramName = item.paramName;
            
            // Detect initial type: enum, variable, or string
            const isEnum = item.isVariable && variablesPanelEnumVarNames.includes(item.editValue);
            const initialType = isEnum ? 'enum' : (item.isVariable ? 'variable' : 'string');
            
            // Param name header
            const pathEl = document.createElement('div');
            pathEl.className = 'preview-variable-card__path';
            pathEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>' +
                '<span>' + escapeHtml(item.paramName) + '</span>';
            card.appendChild(pathEl);
            
            // Type selector (string / variable / enum)
            const typeDiv = document.createElement('div');
            typeDiv.className = 'preview-variable-card__type';
            const typeSelect = document.createElement('select');
            typeSelect.innerHTML = 
                '<option value="string"' + (initialType === 'string' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeString || 'Literal String') + '</option>' +
                '<option value="variable"' + (initialType === 'variable' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeVariable || 'Variable {{...}}') + '</option>' +
                '<option value="enum"' + (initialType === 'enum' ? ' selected' : '') + '>' + (PreviewConfig.i18n?.variablesTypeEnum || 'Enum') + '</option>';
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
                ? (PreviewConfig.i18n?.variablesPlaceholderVariable || 'e.g. SUBTITLE')
                : (PreviewConfig.i18n?.variablesPlaceholderParamString || 'e.g. /page/link');
            
            // Enum select (for enum type)
            const enumSel = document.createElement('select');
            enumSel.className = 'preview-variable-card__value-select';
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '— ' + (PreviewConfig.i18n?.variablesSelectEnum || 'Select an enum variable') + ' —';
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
                hint.textContent = PreviewConfig.i18n?.variablesHintVariable || '{{}} added automatically — use CAPS by convention';
            } else if (initialType === 'enum') {
                hint.textContent = PreviewConfig.i18n?.variablesHintEnum || 'Resolved from enum definition at render time';
            } else {
                hint.textContent = '';
                hint.style.display = 'none';
            }
            editorWrapper.appendChild(hint);
            
            valueDiv.appendChild(editorWrapper);
            
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'preview-variable-card__save-btn';
            saveBtn.textContent = PreviewConfig.i18n?.save || 'Save';
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
                    hint.textContent = PreviewConfig.i18n?.variablesHintEnum || 'Resolved from enum definition at render time';
                    hint.style.display = '';
                    const selVal = enumSel.value;
                    previewEl.textContent = '\u2192 ' + (selVal ? '{{' + selVal + '}}' : '(empty)');
                } else {
                    inp.style.display = '';
                    enumSel.style.display = 'none';
                    const isVar = newType === 'variable';
                    inp.placeholder = isVar 
                        ? (PreviewConfig.i18n?.variablesPlaceholderVariable || 'e.g. SUBTITLE')
                        : (PreviewConfig.i18n?.variablesPlaceholderParamString || 'e.g. /page/link');
                    if (isVar) {
                        hint.textContent = PreviewConfig.i18n?.variablesHintVariable || '{{}} added automatically — use CAPS by convention';
                        hint.style.display = '';
                    } else {
                        hint.textContent = '';
                        hint.style.display = 'none';
                    }
                    const currentVal = inp.value.trim();
                    const previewVal = isVar ? '{{' + currentVal + '}}' : currentVal;
                    previewEl.textContent = '\u2192 ' + (previewVal || '(empty)');
                }
            });
            
            // Input change: update preview
            inp.addEventListener('input', function() {
                const isVar = typeSelect.value === 'variable';
                const val = this.value.trim();
                const previewVal = isVar ? '{{' + val + '}}' : val;
                previewEl.textContent = '\u2192 ' + (previewVal || '(empty)');
            });
            
            // Enum select change: update preview
            enumSel.addEventListener('change', function() {
                const val = this.value;
                previewEl.textContent = '\u2192 ' + (val ? '{{' + val + '}}' : '(empty)');
            });
            
            // Save button
            saveBtn.addEventListener('click', async function() {
                const currentType = typeSelect.value;
                let newParamValue;
                if (currentType === 'enum') {
                    const val = enumSel.value;
                    if (!val) {
                        showToast(PreviewConfig.i18n?.variablesValueRequired || 'Value cannot be empty', 'warning');
                        return;
                    }
                    newParamValue = '{{' + val + '}}';
                } else {
                    const isVar = currentType === 'variable';
                    const val = inp.value.trim();
                    if (!val) {
                        showToast(PreviewConfig.i18n?.variablesValueRequired || 'Value cannot be empty', 'warning');
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
        if (type === 'variable') return PreviewConfig.i18n?.variablesPlaceholderVariable || 'e.g. SUBTITLE';
        if (type === 'raw') return PreviewConfig.i18n?.variablesPlaceholderRaw || 'e.g. Raw Value';
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
            if (!updated) throw new Error('Node not found at ' + nodeId);
            
            // Send full structure to backend
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: currentEditName,
                structure: variablesPanelStructure
            });
            
            if (!result.ok) throw new Error(result.data?.message || 'Save failed');
            
            showToast(PreviewConfig.i18n?.variablesSaved || 'Variable saved', 'success');
            componentsLoaded = false; // Invalidate component cache
            
            // Reload preview to reflect change
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Variable save error:', error);
            showToast((PreviewConfig.i18n?.error || 'Error') + ': ' + error.message, 'error');
            if (previewEl) previewEl.textContent = '\u2192 (save failed)';
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
            if (!targetNode) throw new Error('Node not found');
            
            if (!targetNode.params) targetNode.params = {};
            targetNode.params[paramName] = newValue;
            
            // Send full structure to backend
            const result = await QuickSiteAdmin.apiRequest('editStructure', 'PUT', {
                type: 'component',
                name: currentEditName,
                structure: variablesPanelStructure
            });
            
            if (!result.ok) throw new Error(result.data?.message || 'Save failed');
            
            showToast(PreviewConfig.i18n?.variablesSaved || 'Variable saved', 'success');
            componentsLoaded = false; // Invalidate component cache
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Param save error:', error);
            showToast((PreviewConfig.i18n?.error || 'Error') + ': ' + error.message, 'error');
            if (previewEl) previewEl.textContent = '\u2192 (save failed)';
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
        
        enumsPanel.style.display = '';
        
        // Show loading
        if (enumsPanelLoading) enumsPanelLoading.style.display = '';
        if (enumsPanelEmpty) enumsPanelEmpty.style.display = 'none';
        if (enumsPanelCards) enumsPanelCards.innerHTML = '';
        if (enumsPanelAdd) enumsPanelAdd.style.display = 'none';
        
        try {
            const structInfo = parseStruct(selectedStruct || ('component-' + currentEditName));
            if (!structInfo) throw new Error('Invalid struct');
            
            const urlParams = [structInfo.type];
            if (structInfo.name) urlParams.push(structInfo.name);
            
            const [resp, transResp] = await Promise.all([
                QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams),
                QuickSiteAdmin.apiRequest('getTranslations', 'GET')
            ]);
            
            if (!resp.ok || !resp.data?.data?.structure) throw new Error('Failed to get structure');
            
            enumsPanelStructure = JSON.parse(JSON.stringify(resp.data.data.structure));
            
            // Extract flat translation key list from first available language
            enumsPanelTranslationKeys = [];
            if (transResp.ok && transResp.data?.data?.translations) {
                const allTranslations = transResp.data.data.translations;
                const firstLang = Object.keys(allTranslations)[0];
                if (firstLang) {
                    enumsPanelTranslationKeys = flattenTranslationKeys(allTranslations[firstLang], '').sort();
                }
            }
            
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
                enumsPanelCards.innerHTML = '<div style="color:var(--admin-danger);font-size:var(--font-size-sm);padding:var(--space-sm);">' +
                    (PreviewConfig.i18n?.error || 'Error') + ': ' + error.message + '</div>';
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
        enumsPanelCards.innerHTML = '';
        
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
     * │ │ get     │ get-style              │ 🗑│
     * │ │ post    │ post-style             │ 🗑│
     * │ └──────────────────────────────────┘   │
     * │ [+ Add Option]            [Save] [🗑]  │
     * └────────────────────────────────────────┘
     */
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

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'preview-enum-card__edit-name-btn';
        editBtn.title = PreviewConfig.i18n?.enumRenameTip || 'Rename';
        editBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'admin-input admin-input--sm preview-enum-card__name-input';
        nameInput.value = varName;
        nameInput.placeholder = PreviewConfig.i18n?.enumNamePlaceholder || 'enum_name';
        nameInput.style.display = 'none';

        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'preview-enum-card__name-confirm';
        confirmBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><polyline points="20 6 9 17 4 12"/></svg>';
        confirmBtn.style.display = 'none';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'preview-enum-card__name-cancel';
        cancelBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
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
                    showToast(PreviewConfig.i18n?.enumNameRequired || 'Enum name is required', 'warning');
                    nameInput.focus();
                    return;
                }
                // Check for duplicates (skip self)
                const existing = enumsPanelStructure?.__enums__ || {};
                if (newName !== oldName && existing[newName]) {
                    showToast((PreviewConfig.i18n?.enumNameDuplicate || 'Enum "%s" already exists').replace('%s', newName), 'warning');
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
        sourceRow.innerHTML = `
            <label class="preview-enum-card__label">${PreviewConfig.i18n?.enumSource || 'Source key'}:</label>
            <input type="text" class="admin-input admin-input--sm preview-enum-card__source-input" value="${escapeHTML(source)}" placeholder="e.g. method">
        `;
        card.appendChild(sourceRow);
        
        // Detect default type from existing values
        const rawCount = Object.values(map).filter(v => String(v).startsWith('__RAW__')).length;
        const litCount = Object.values(map).filter(v => String(v).startsWith('__LIT__')).length;
        const totalCount = mapKeys.length;
        const detectedDefault = totalCount === 0 ? 'literal'
            : (litCount > rawCount && litCount >= totalCount / 2) ? 'literal'
            : (rawCount > litCount && rawCount >= totalCount / 2) ? 'raw'
            : 'literal';
        
        // Default value type dropdown (card-level)
        const typeRow = document.createElement('div');
        typeRow.className = 'preview-enum-card__field';
        const litLabel = PreviewConfig.i18n?.enumTypeLiteral || 'Literal';
        const rawLabel = PreviewConfig.i18n?.variablesTypeRaw || 'Raw Text';
        const transLabel = PreviewConfig.i18n?.variablesTypeTranslation || 'Translation Key';
        typeRow.innerHTML = `
            <label class="preview-enum-card__label">${PreviewConfig.i18n?.enumValueType || 'Value type'}:</label>
            <select class="admin-input admin-input--sm preview-enum-card__type-select">
                <option value="literal" ${detectedDefault === 'literal' ? 'selected' : ''}>${escapeHTML(litLabel)}</option>
                <option value="raw" ${detectedDefault === 'raw' ? 'selected' : ''}>${escapeHTML(rawLabel)}</option>
                <option value="translation" ${detectedDefault === 'translation' ? 'selected' : ''}>${escapeHTML(transLabel)}</option>
            </select>
        `;
        card.appendChild(typeRow);
        
        // Default dropdown
        const defaultRow = document.createElement('div');
        defaultRow.className = 'preview-enum-card__field';
        defaultRow.innerHTML = `
            <label class="preview-enum-card__label">${PreviewConfig.i18n?.enumDefault || 'Default'}:</label>
            <select class="admin-input admin-input--sm preview-enum-card__default-select">
                ${mapKeys.map(k => `<option value="${escapeHTML(k)}" ${k === defaultKey ? 'selected' : ''}>${escapeHTML(k)}</option>`).join('')}
            </select>
        `;
        card.appendChild(defaultRow);
        
        // Map table
        const mapContainer = document.createElement('div');
        mapContainer.className = 'preview-enum-card__map';
        
        const table = document.createElement('table');
        table.className = 'preview-enum-card__table';
        table.innerHTML = `
            <thead>
                <tr>
                    <th>${PreviewConfig.i18n?.enumKey || 'Key'}</th>
                    <th>${PreviewConfig.i18n?.enumValue || 'Value'}</th>
                    <th>${PreviewConfig.i18n?.enumType || 'Type'}</th>
                    <th></th>
                </tr>
            </thead>
        `;
        const tbody = document.createElement('tbody');
        tbody.className = 'preview-enum-card__tbody';
        
        mapKeys.forEach(key => {
            const rawValue = map[key];
            const isRaw = String(rawValue).startsWith('__RAW__');
            const isLit = String(rawValue).startsWith('__LIT__');
            // Per-row type: if it differs from card default, it's an override
            const rowType = isLit ? 'literal' : isRaw ? 'raw' : 'translation';
            const displayValue = isRaw ? String(rawValue).slice(7) : isLit ? String(rawValue).slice(7) : rawValue;
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
        addOptionBtn.className = 'admin-btn admin-btn--sm admin-btn--outline preview-enum-card__add-option';
        addOptionBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ${PreviewConfig.i18n?.enumAddOption || 'Add Option'}`;
        addOptionBtn.addEventListener('click', () => {
            const currentCardType = card.querySelector('.preview-enum-card__type-select')?.value || 'literal';
            tbody.appendChild(createEnumMapRow('', '', null, currentCardType));
            refreshDefaultSelect(card, tbody);
        });
        card.appendChild(addOptionBtn);
        
        // Action buttons — Save and Delete
        const actions = document.createElement('div');
        actions.className = 'preview-enum-card__actions';
        
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'admin-btn admin-btn--sm admin-btn--primary';
        saveBtn.textContent = PreviewConfig.i18n?.save || 'Save';
        saveBtn.addEventListener('click', () => saveEnumCard(card, varName));
        
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'admin-btn admin-btn--sm admin-btn--danger';
        deleteBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>`;
        deleteBtn.title = PreviewConfig.i18n?.delete || 'Delete';
        deleteBtn.addEventListener('click', () => deleteEnumCard(varName));
        
        actions.appendChild(saveBtn);
        actions.appendChild(deleteBtn);
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
        
        const litLabel = PreviewConfig.i18n?.enumTypeLiteral || 'Literal';
        const rawLabel = PreviewConfig.i18n?.variablesTypeRaw || 'Raw Text';
        const transLabel = PreviewConfig.i18n?.variablesTypeTranslation || 'Translation Key';
        const defaultLabel = PreviewConfig.i18n?.enumTypeDefault || '(default)';
        
        // Key cell
        const keyTd = document.createElement('td');
        keyTd.innerHTML = `<input type="text" class="admin-input admin-input--sm preview-enum-card__key-input" value="${escapeHTML(String(key))}" placeholder="${PreviewConfig.i18n?.enumKeyPlaceholder || 'key'}">`;
        
        // Value cell — contains both a text input and a translation select, one visible at a time
        const valueTd = document.createElement('td');
        
        const valInput = document.createElement('input');
        valInput.type = 'text';
        valInput.className = 'admin-input admin-input--sm preview-enum-card__value-input';
        valInput.value = String(value);
        valInput.placeholder = PreviewConfig.i18n?.enumValuePlaceholder || 'value';
        
        const valSelect = document.createElement('select');
        valSelect.className = 'admin-input admin-input--sm preview-enum-card__value-select';
        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '— ' + (PreviewConfig.i18n?.variablesPlaceholderTranslation || 'Select a translation key') + ' —';
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
        typeLabel.title = PreviewConfig.i18n?.enumTypeOverrideTip || 'Click to override type';
        
        const typeSelect = document.createElement('select');
        typeSelect.className = 'admin-input admin-input--sm preview-enum-card__row-type-select';
        typeSelect.innerHTML = `
            <option value="">${escapeHTML(defaultLabel)}</option>
            <option value="literal">${escapeHTML(litLabel)}</option>
            <option value="raw">${escapeHTML(rawLabel)}</option>
            <option value="translation">${escapeHTML(transLabel)}</option>
        `;
        
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
                const cardType = card?.querySelector('.preview-enum-card__type-select')?.value || 'literal';
                updateEnumRowValueWidget(valInput, valSelect, cardType);
            } else {
                updateEnumRowValueWidget(valInput, valSelect, typeSelect.value);
            }
        });
        
        typeTd.appendChild(typeLabel);
        typeTd.appendChild(typeSelect);
        
        // Remove cell
        const removeTd = document.createElement('td');
        removeTd.innerHTML = `<button type="button" class="preview-enum-card__remove-row" title="${PreviewConfig.i18n?.remove || 'Remove'}">&times;</button>`;
        
        tr.appendChild(keyTd);
        tr.appendChild(valueTd);
        tr.appendChild(typeTd);
        tr.appendChild(removeTd);
        
        // Remove row button
        removeTd.querySelector('.preview-enum-card__remove-row').addEventListener('click', () => {
            const card = tr.closest('.preview-enum-card');
            const tbody = tr.closest('tbody');
            tr.remove();
            if (card && tbody) refreshDefaultSelect(card, tbody);
        });
        
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
            // Both 'literal' and 'raw' use the free text input
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
        
        select.innerHTML = keys.map(k =>
            `<option value="${escapeHTML(k)}" ${k === currentDefault ? 'selected' : ''}>${escapeHTML(k)}</option>`
        ).join('');
        
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
            showToast(PreviewConfig.i18n?.enumSourceRequired || 'Source key is required', 'warning');
            sourceInput?.focus();
            return;
        }
        
        // Get card-level default type
        const cardTypeSelect = card.querySelector('.preview-enum-card__type-select');
        const cardDefaultType = cardTypeSelect?.value || 'literal';
        
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
                // Apply prefix based on type: __RAW__ for raw text, __LIT__ for literal, nothing for translation
                if (effectiveType === 'raw' && value) {
                    map[key] = '__RAW__' + value;
                } else if (effectiveType === 'literal' && value) {
                    map[key] = '__LIT__' + value;
                } else {
                    map[key] = value || '';
                }
            } else {
                hasEmpty = true;
            }
        });
        
        if (Object.keys(map).length === 0) {
            showToast(PreviewConfig.i18n?.enumNeedsOptions || 'At least one option is required', 'warning');
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
            
            if (!result.ok) throw new Error(result.data?.message || 'Save failed');
            
            showToast(PreviewConfig.i18n?.enumSaved || 'Enum saved', 'success');
            componentsLoaded = false; // Invalidate component cache
            
            // Phase 9: Auto-create CSS stubs for class-bound enum values
            await createCssStubsForEnumIfNeeded(enumsPanelStructure, currentVarName, map);
            
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Enum save error:', error);
            showToast((PreviewConfig.i18n?.error || 'Error') + ': ' + error.message, 'error');
        } finally {
            card.classList.remove('preview-enum-card--saving');
        }
    }
    
    /**
     * Delete an enum from the component structure
     */
    async function deleteEnumCard(varName) {
        if (!enumsPanelStructure) return;
        
        const confirmMsg = (PreviewConfig.i18n?.enumConfirmDelete || 'Delete enum "%s"?').replace('%s', varName);
        if (!confirm(confirmMsg)) return;
        
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
            
            if (!result.ok) throw new Error(result.data?.message || 'Delete failed');
            
            showToast(PreviewConfig.i18n?.enumDeleted || 'Enum deleted', 'success');
            
            // Refresh the panel
            showEnumsPanel();
            reloadPreview();
            
        } catch (error) {
            console.error('[Preview] Enum delete error:', error);
            showToast((PreviewConfig.i18n?.error || 'Error') + ': ' + error.message, 'error');
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
        if (!emulationPanel || currentEditType !== 'component') return;
        
        // Hide other panels
        const nodeActions = document.getElementById('ctx-node-actions');
        if (nodeActions) nodeActions.style.display = 'none';
        if (variablesPanel) variablesPanel.style.display = 'none';
        if (enumsPanel) enumsPanel.style.display = 'none';
        if (saveSnippetForm) saveSnippetForm.style.display = 'none';
        if (saveComponentForm) saveComponentForm.style.display = 'none';
        
        emulationPanel.style.display = '';
        
        // Show loading
        if (emulationPanelLoading) emulationPanelLoading.style.display = '';
        if (emulationPanelEmpty) emulationPanelEmpty.style.display = 'none';
        if (emulationPanelFields) emulationPanelFields.innerHTML = '';
        if (emulationPanelActions) emulationPanelActions.style.display = 'none';
        
        try {
            const structInfo = parseStruct(selectedStruct || ('component-' + currentEditName));
            if (!structInfo) throw new Error('Invalid struct');
            
            const urlParams = [structInfo.type];
            if (structInfo.name) urlParams.push(structInfo.name);
            
            const resp = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
            if (!resp.ok || !resp.data?.data?.structure) throw new Error('Failed to get structure');
            
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
            const storageKey = 'qs_emulate_' + currentEditName;
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
                emulationPanelFields.innerHTML = '<div style="color:var(--admin-danger);font-size:var(--font-size-sm);padding:var(--space-sm);">' +
                    (PreviewConfig.i18n?.error || 'Error') + ': ' + error.message + '</div>';
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
            input.innerHTML = '<option value="">— {{' + escapeHTML(varName) + '}} —</option>' +
                enumKeys.map(k => '<option value="' + escapeHTML(k) + '"' +
                    (k === savedValue ? ' selected' : '') + '>' + escapeHTML(k) + '</option>').join('');
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
        resetBtn.title = PreviewConfig.i18n?.emulationResetField || 'Reset';
        resetBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';
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
     * Apply emulation: store values in localStorage and reload preview
     */
    function applyEmulation() {
        if (!emulationPanelFields) return;
        
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
        
        const storageKey = 'qs_emulate_' + currentEditName;
        
        if (hasValues) {
            localStorage.setItem(storageKey, JSON.stringify(data));
        } else {
            localStorage.removeItem(storageKey);
        }
        
        reloadPreview();
        showToast(PreviewConfig.i18n?.emulationApplied || 'Emulation applied', 'success');
    }
    
    /**
     * Reset emulation: clear localStorage and reload preview
     */
    function resetEmulation() {
        const storageKey = 'qs_emulate_' + currentEditName;
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
        
        reloadPreview();
        showToast(PreviewConfig.i18n?.emulationReset || 'Emulation reset', 'success');
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
                const msg = (PreviewConfig.i18n?.enumCssStubsCreated || 'CSS stubs created: %s')
                    .replace('%s', created.join(', '));
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
    
    // Collect all textKeys from a structure (recursive) - kept for reference
    function collectTextKeys(node, keys = []) {
        if (!node || typeof node !== 'object') return keys;
        
        // Check for textKey
        if (node.textKey && !node.textKey.startsWith('__RAW__')) {
            keys.push(node.textKey);
        }
        
        // Check for altKey (used in img tags)
        if (node.altKey && !node.altKey.startsWith('__RAW__')) {
            keys.push(node.altKey);
        }
        
        // Check params for translatable attributes
        if (node.params) {
            const translatableParams = ['alt', 'title', 'placeholder', 'aria-label'];
            for (const param of translatableParams) {
                const paramKey = node.params[param];
                if (paramKey && typeof paramKey === 'string' && 
                    paramKey.startsWith('{{') && paramKey.includes('textKey:')) {
                    // Extract key from {{textKey:...}}
                    const match = paramKey.match(/\{\{textKey:([^}]+)\}\}/);
                    if (match && match[1]) {
                        keys.push(match[1]);
                    }
                }
            }
        }
        
        // Recurse into children
        if (node.children && Array.isArray(node.children)) {
            for (const child of node.children) {
                collectTextKeys(child, keys);
            }
        }
        
        return keys;
    }
    
    // Extract translations for a list of keys
    async function extractTranslationsForKeys(textKeys) {
        if (!textKeys || textKeys.length === 0) return {};
        
        try {
            // Get all available languages
            const langResponse = await QuickSiteAdmin.apiRequest('listLangTranslations', 'GET');
            if (!langResponse.ok || !langResponse.data?.data) {
                console.warn('[Preview] Could not get languages list');
                return {};
            }
            
            const languages = langResponse.data.data.languages || ['en'];
            const translations = {};
            
            // For each language, get translations
            for (const lang of languages) {
                const transResponse = await QuickSiteAdmin.apiRequest('getProjectTranslations', 'GET', null, [lang]);
                if (transResponse.ok && transResponse.data?.data?.translations) {
                    const langTranslations = transResponse.data.data.translations;
                    
                    // Extract only the keys we need
                    for (const key of textKeys) {
                        if (langTranslations[key] !== undefined) {
                            if (!translations[lang]) translations[lang] = {};
                            translations[lang][key] = langTranslations[key];
                        }
                    }
                }
            }
            
            return translations;
        } catch (error) {
            console.error('[Preview] Failed to extract translations:', error);
            return {};
        }
    }
    
    // Submit save snippet form
    async function submitSaveSnippet() {
        if (!saveSnippetStructureData) {
            showToast(PreviewConfig.i18n.noStructureData || 'No structure data available', 'error');
            return;
        }
        
        // Validate form
        const name = saveSnippetName?.value?.trim();
        const id = saveSnippetId?.value?.trim();
        const category = saveSnippetCategory?.value || 'other';
        const description = saveSnippetDesc?.value?.trim() || '';
        
        if (!name) {
            showToast(PreviewConfig.i18n.snippetNameRequired || 'Snippet name is required', 'warning');
            saveSnippetName?.focus();
            return;
        }
        
        if (!id) {
            showToast(PreviewConfig.i18n.snippetIdRequired || 'Snippet ID is required', 'warning');
            saveSnippetId?.focus();
            return;
        }
        
        // Validate ID format
        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(id)) {
            showToast(PreviewConfig.i18n.snippetIdInvalid || 'ID must start with a letter and contain only letters, numbers, dashes', 'warning');
            saveSnippetId?.focus();
            return;
        }
        
        showToast(PreviewConfig.i18n.saving || 'Saving...', 'info');
        
        try {
            // Clone structure and remap textKeys to snippet namespace
            const { structure: remappedStructure, keyMapping } = deepCloneAndRemapNode(saveSnippetStructureData, id);
            
            // Extract translations using the old->new key mapping
            const translations = await extractAndRemapTranslations(keyMapping);
            
            // Call createSnippet API
            const scope = saveSnippetGlobal?.checked ? 'global' : 'project';
            
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
                showToast(PreviewConfig.i18n.snippetSaved || 'Snippet saved successfully!', 'success');
                
                // Close form
                hideSaveSnippetForm();
                
                // Invalidate snippet cache so next tab switch reloads
                snippetsLoaded = false;
            } else {
                throw new Error(response.data?.message || 'Failed to save snippet');
            }
        } catch (error) {
            console.error('[Preview] Save snippet error:', error);
            showToast(error.message || PreviewConfig.i18n.saveFailed || 'Failed to save snippet', 'error');
        }
    }
    
    // ==================== Tag Classification (Add/Edit Shared) ====================
    
    // Tag classification and mandatory params (mirrors backend addNode.php)
    const TAG_INFO = {
        BLOCK_TAGS: ['div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 
                     'figure', 'figcaption', 'blockquote', 'pre', 'form', 'fieldset',
                     'ul', 'ol', 'table', 'thead', 'tbody', 'tfoot', 'tr'],
        INLINE_TAGS: ['span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 
                      'a', 'button', 'label', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark',
                      'li', 'td', 'th', 'dt', 'dd', 'caption', 'legend',
                      'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address'],
        SELF_CLOSING_TAGS: ['img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col', 
                            'embed', 'source', 'track', 'wbr'],
        MANDATORY_PARAMS: {
            'a': ['href'],
            'img': ['src', 'alt'],
            'input': ['type'],
            'form': ['action'],
            'iframe': ['src'],
            'video': ['src'],
            'audio': ['src'],
            'source': ['src'],
            'label': ['for'],
            'select': ['name'],
            'textarea': ['name'],
            'area': ['href', 'alt'],
            'embed': ['src'],
            'object': ['data'],
            'track': ['src'],
            'link': ['href', 'rel']
        },
        TAGS_WITH_ALT: ['img', 'area'],
        RESERVED_PARAMS: ['placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description']
    };

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
    
    
    // ==================== Public API ====================
    
    window.PreviewManager = {
        reload: reloadPreview,
        navigateTo: navigateTo,
        setDevice: setDevice,
        setMode: setMode,
        getCurrentDevice: () => currentDevice,
        getCurrentMode: () => currentMode,
        highlightNode: (struct, node) => sendToIframe('highlightNode', { struct, node }),
        getSelectedNode: () => ({ struct: selectedStruct, node: selectedNode, component: selectedComponent }),
        // Miniplayer API — delegates to preview-miniplayer.js module
        toggleMiniplayer: () => window.PreviewMiniplayer && window.PreviewMiniplayer.toggle(),
        isMiniplayer: () => window.PreviewMiniplayer ? window.PreviewMiniplayer.isEnabled() : false,
        toggleGlobalMiniplayer: () => window.PreviewMiniplayer && window.PreviewMiniplayer.toggleGlobal(),
        isGlobalMiniplayerEnabled: () => window.PreviewMiniplayer ? window.PreviewMiniplayer.isGlobalEnabled() : false
    };
    
    // Initial state
    startLoadingTimeout();
    
    // Initialize miniplayer module (preview-miniplayer.js, loaded before preview.js)
    if (window.PreviewMiniplayer) {
        window.PreviewMiniplayer.init({
            container: container,
            previewPage: document.getElementById('preview-page'),
            targetSelect: targetSelect,
            langSelect: langSelect,
            showToast: showToast,
            reloadPreview: reloadPreview,
            i18n: PreviewConfig.i18n
        });
    }
    
    // Check for deep-link target from URL parameter (e.g., ?target=page-home)
    const urlTarget = new URLSearchParams(window.location.search).get('target');
    if (urlTarget && targetSelect) {
        // Parse target format: "page-home", "component-card", "menu", "footer"
        const parsed = parseStruct(urlTarget);
        if (parsed) {
            const selectValue = parsed.name ? `${parsed.type}:${parsed.name}` : `layout:${parsed.type}`;
            const option = targetSelect.querySelector(`option[value="${CSS.escape(selectValue)}"]`);
            if (option) {
                targetSelect.value = selectValue;
            }
        }
        // Clean URL without reloading
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, '', cleanUrl);
    }

    // Initialize edit target state from dropdown
    // Default to first page option (skip layout options which are just for switching to)
    if (targetSelect && targetSelect.value) {
        let value = targetSelect.value;
        
        // On initial load, select the first page instead of layout options
        if (value.startsWith('layout:')) {
            const firstPageOption = targetSelect.querySelector('option[value^="page:"]');
            if (firstPageOption) {
                targetSelect.value = firstPageOption.value;
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
        
        // Update component warning on initial load
        updateComponentWarning(currentEditType, currentEditName);
        // Update layout toggles visibility on initial load
        updateLayoutToggles(currentEditType, currentEditName);
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
