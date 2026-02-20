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
    const modeBtns = document.querySelectorAll('.preview-sidebar-tool');
    
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
    
    // Variables panel elements (component-only)
    const variablesPanel = document.getElementById('contextual-variables-panel');
    const variablesPanelClose = document.getElementById('variables-panel-close');
    const variablesPanelLoading = document.getElementById('variables-panel-loading');
    const variablesPanelEmpty = document.getElementById('variables-panel-empty');
    const variablesPanelCards = document.getElementById('variables-panel-cards');
    const variablesPanelFooter = document.getElementById('variables-panel-footer');
    const variablesPanelFooterText = document.getElementById('variables-panel-footer-text');
    
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
    const addSnippetCategories = document.getElementById('add-snippet-categories');
    const addSnippetCards = document.getElementById('add-snippet-cards');
    const addSnippetInput = document.getElementById('add-snippet');
    const addSnippetPreview = document.getElementById('add-snippet-preview');
    const addSnippetPreviewTitle = document.getElementById('add-snippet-preview-title');
    const addSnippetPreviewSource = document.getElementById('add-snippet-preview-source');
    const addSnippetPreviewDesc = document.getElementById('add-snippet-preview-desc');
    const addSnippetPreviewFrame = document.getElementById('add-snippet-preview-frame');
    const addSnippetPreviewActions = document.getElementById('add-snippet-preview-actions');
    const deleteSnippetBtn = document.getElementById('delete-snippet-btn');
    const addPositionPicker = document.getElementById('add-position-picker');
    const addMandatoryParams = document.getElementById('add-mandatory-params');
    const addMandatoryParamsContainer = document.getElementById('add-mandatory-params-container');
    const addClassField = document.getElementById('add-class-field');
    const addClassInput = document.getElementById('add-class');
    const addExpandParamsBtn = document.getElementById('add-expand-params');
    const addCustomParamsContainer = document.getElementById('add-custom-params-container');
    const addCustomParamsSection = document.getElementById('add-custom-params-section');
    
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
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
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
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
            
            const emitChange = () => {
                const num = numInput.value.trim();
                const unit = unitSelect.value;
                // Handle special values like 'auto', 'none', 'normal'
                if (unit === 'auto' || unit === 'none' || unit === 'normal') {
                    this.currentValue = unit;
                } else {
                    this.currentValue = num + unit;
                }
                this.onChange(this.currentValue);
            };
            
            numInput.addEventListener('input', emitChange);
            numInput.addEventListener('blur', () => this.onBlur(this.currentValue));
            unitSelect.addEventListener('change', emitChange);
            
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
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
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
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
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
            PreviewTransitionEditor.setGetThemeVariables(() => originalThemeVariables);
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
            url += '?_component=' + encodeURIComponent(editName) + '&_editor=1';
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
        iframe.src = iframe.src;
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
        
        // Hide Variables panel when navigating away
        if (variablesPanel && variablesPanel.style.display !== 'none') {
            hideVariablesPanel();
        }
        
        // Update component warning banner
        updateComponentWarning(editType, editName);
        
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
        // Hide Variables panel if open and switching away from component
        if (editType !== 'component' && variablesPanel && variablesPanel.style.display !== 'none') {
            hideVariablesPanel();
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
        
        currentMode = mode;
        
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
        
        // Phase 8.3: Show/hide style tabs and content when in style mode
        if (mode === 'style') {
            if (styleTabs) styleTabs.style.display = '';
            if (styleContent) styleContent.style.display = '';
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
            PreviewStyleEditor.setGetThemeVariables(() => originalThemeVariables);
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
        
        // Store selection info for edit/copy actions
        selectedStruct = data.struct || null;
        selectedNode = data.isComponent ? data.componentNode : data.node;
        selectedComponent = data.component || null;
        selectedElementClasses = data.classes || null;  // Store classes for style mode
        selectedElementTag = data.tag || null;          // Store tag for style mode
        
        // Hide Variables panel when selecting a different node
        if (variablesPanel && variablesPanel.style.display !== 'none') {
            hideVariablesPanel();
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
                iframeWindow.postMessage({ source: 'quicksite-admin', action, ...data }, '*');
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
            
            // Check if already injected
            if (iframeDoc.getElementById('quicksite-overlay-styles')) {
                console.log('[Preview] Overlay styles found, marking as injected');
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
            if (e.data.action === 'elementMoved') {
                handleElementMoved(e.data);
            }
            if (e.data.action === 'dragStarted') {
                // Show dragged element info in the contextual info panel
                showContextualInfo(e.data);
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
     * Parse struct string into type and name
     * Format: "page-home" -> {type:"page", name:"home"}, "menu" -> {type:"menu", name:null}
     */
    function parseStruct(struct) {
        if (!struct) return null;
        
        // menu, footer are simple types
        if (struct === 'menu' || struct === 'footer') {
            return { type: struct, name: null };
        }
        
        // page-{name} format
        if (struct.startsWith('page-')) {
            return { type: 'page', name: struct.substring(5) };
        }
        
        // component-{name} format (if ever used)
        if (struct.startsWith('component-')) {
            return { type: 'component', name: struct.substring(10) };
        }
        
        return null;
    }
    
    /**
     * Get a node from structure by nodeId (e.g., "0.2.1")
     */
    function getNodeByPath(structure, nodeId) {
        const indices = nodeId.split('.').map(Number);
        let current = structure;
        
        for (let i = 0; i < indices.length; i++) {
            if (!Array.isArray(current)) {
                current = current.children;
            }
            if (!current || !Array.isArray(current)) return null;
            current = current[indices[i]];
            if (!current) return null;
        }
        return current;
    }
    
    /**
     * Deep clone an object, removing _nodeId properties
     */
    function cloneWithoutNodeIds(obj) {
        if (obj === null || typeof obj !== 'object') return obj;
        if (Array.isArray(obj)) return obj.map(cloneWithoutNodeIds);
        
        const clone = {};
        for (const key of Object.keys(obj)) {
            if (key === '_nodeId') continue;
            clone[key] = cloneWithoutNodeIds(obj[key]);
        }
        return clone;
    }
    
    async function handleElementMoved(data) {
        console.log('[Preview] Element moved:', data);
        
        const source = data.sourceElement;
        const target = data.targetElement;
        const position = data.position; // 'before', 'after', or 'inside'
        
        if (!source || !target || !source.struct || !source.node || !target.node) {
            console.error('[Preview] Invalid move data:', { source, target, position });
            showToast(PreviewConfig.i18n.error + ': Invalid move data', 'error');
            // Rollback — DOM was already moved live in iframe
            sendToIframe('rollbackDrag', {});
            return;
        }
        
        // Parse struct to get type and name
        const structInfo = parseStruct(source.struct);
        if (!structInfo || !structInfo.type) {
            showToast(PreviewConfig.i18n.error + ': Invalid structure type', 'error');
            sendToIframe('rollbackDrag', {});
            return;
        }
        
        try {
            // Use the atomic moveNode command
            const params = {
                type: structInfo.type,
                sourceNodeId: source.node,
                targetNodeId: target.node,
                position: position
            };
            if (structInfo.name) {
                params.name = structInfo.name;
            }
            
            console.log('[Preview] Moving node:', params);
            const result = await QuickSiteAdmin.apiRequest('moveNode', 'PATCH', params);
            
            if (!result.ok) {
                throw new Error(result.data?.message || result.data?.data?.message || 'Failed to move node');
            }
            
            // Success! DOM is already in the correct position (live drag did this)
            // Reindex ALL node IDs in this struct so they match the new JSON structure
            sendToIframe('reindexNodes', { struct: source.struct });
            
            // Highlight the moved element after reindex (use a short delay for reindex to finish)
            setTimeout(() => {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                // After reindex, the moved element has a new node ID from the server
                const newNodeId = result.data?.data?.newNodeId;
                const highlightSelector = newNodeId
                    ? `[data-qs-struct="${source.struct}"][data-qs-node="${newNodeId}"], [data-qs-struct="${source.struct}"] [data-qs-node="${newNodeId}"]`
                    : null;
                
                let sourceEl = highlightSelector ? iframeDoc.querySelector(highlightSelector) : null;
                
                if (sourceEl) {
                    sourceEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    sourceEl.style.outline = '3px solid var(--primary, #3b82f6)';
                    sourceEl.style.outlineOffset = '2px';
                    setTimeout(() => {
                        sourceEl.style.outline = '';
                        sourceEl.style.outlineOffset = '';
                    }, 1500);
                }
            }, 50);
            
            showToast(PreviewConfig.i18n.elementMoved, 'success');
            console.log('[Preview] Move saved successfully');
            
        } catch (error) {
            console.error('[Preview] Move error:', error);
            showToast(PreviewConfig.i18n.error + ': ' + error.message, 'error');
            // Rollback DOM in iframe since API failed
            sendToIframe('rollbackDrag', {});
        }
    }
    
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
        
        // Get current language from the selector
        const langSelect = document.getElementById('lang-select');
        const lang = langSelect ? langSelect.value : 'en';
        
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
     * Escape HTML for safe rendering
     */
    function escapeHTML(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // Alias for modules that use lowercase
    const escapeHtml = escapeHTML;
    
    /**
     * Parse a CSS styles string into an object
     * @param {string} stylesString - CSS declarations like "color: red; font-size: 16px;"
     * @returns {Object} Property/value pairs
     */
    function parseStylesString(stylesString) {
        const result = {};
        if (!stylesString) return result;
        
        // Split by semicolons, handling multi-line
        const declarations = stylesString.split(/;\s*/);
        
        for (const decl of declarations) {
            const trimmed = decl.trim();
            if (!trimmed) continue;
            
            const colonIndex = trimmed.indexOf(':');
            if (colonIndex === -1) continue;
            
            const property = trimmed.substring(0, colonIndex).trim();
            const value = trimmed.substring(colonIndex + 1).trim();
            
            if (property && value) {
                result[property] = value;
            }
        }
        
        return result;
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
    
    // Initialize JS Interactions module
    initJsInteractions();
    
    // Initialize Transition Editor module
    initTransitionEditor();
    
    // ==================== Event Handlers ====================
    
    iframe.addEventListener('load', function() {
        clearTimeout(loadingTimeout);
        hideLoading();
        // Inject overlay immediately and with a backup timeout
        injectOverlay();
        // Retry injection in case document wasn't fully ready
        setTimeout(injectOverlay, 50);
        setTimeout(injectOverlay, 200);
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
    
    // ==================== Keyboard Shortcuts ====================
    
    document.addEventListener('keydown', function(e) {
        // Ignore if typing in an input/textarea
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
            return;
        }
        
        // Arrow keys - Navigate selection (only in select mode with a selection)
        if (currentMode === 'select' && selectedStruct && selectedNode) {
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
            if (selectedStruct && selectedNode) {
                hideNodePanel();
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ action: 'clearSelection' }, '*');
                }
            }
            return;
        }
        
        // Delete or Backspace - Delete selected node
        if (e.key === 'Delete' || (e.key === 'Backspace' && e.metaKey)) {
            // Only if we have a selected node
            if (selectedStruct && selectedNode) {
                e.preventDefault();
                deleteSelectedNode();
            }
            return;
        }
    });
    
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
            
            // Live DOM update - clone source node in iframe (no reload needed)
            if (newNodeId && selectedNode !== '') {
                sendToIframe('duplicateNode', {
                    struct: selectedStruct,
                    sourceNodeId: selectedNode,
                    newNodeId: newNodeId
                });
            } else {
                // Root duplication - full reload needed
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
            if (selectedNode === '' && currentEditType !== 'component') {
                showToast(PreviewConfig.i18n?.selectChildElement || 'This is the component root. Select a child element to use actions.', 'info');
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
    
    // ==================== Save as Snippet Event Listeners ====================
    
    // Show save snippet form
    if (ctxNodeSaveSnippet) {
        ctxNodeSaveSnippet.addEventListener('click', showSaveSnippetForm);
    }
    
    // Close/Cancel save snippet form
    if (saveSnippetClose) {
        saveSnippetClose.addEventListener('click', hideSaveSnippetForm);
    }
    if (saveSnippetCancel) {
        saveSnippetCancel.addEventListener('click', hideSaveSnippetForm);
    }
    
    // Variables panel button
    if (ctxNodeVariables) {
        ctxNodeVariables.addEventListener('click', showVariablesPanel);
    }
    if (variablesPanelClose) {
        variablesPanelClose.addEventListener('click', hideVariablesPanel);
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
    
    // Submit save snippet form
    if (saveSnippetSubmit) {
        saveSnippetSubmit.addEventListener('click', submitSaveSnippet);
    }
    
    // Delete snippet button
    if (deleteSnippetBtn) {
        deleteSnippetBtn.addEventListener('click', deleteSelectedSnippet);
    }
    
    // ==================== Mobile Sections Event Listeners ====================

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
            if (selectedNode === '' && currentEditType !== 'component') {
                showToast(PreviewConfig.i18n?.selectChildElement || 'This is the component root. Select a child element to use actions.', 'info');
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
    
    // ==================== Theme Variables Event Listeners (Phase 8.3) ====================
    
    // Initialize style tabs
    initStyleTabs();
    
    // Initialize selector browser (Phase 8.4)
    initSelectorBrowser();
    
    // Keyframe editor now handled by preview-style-animations.js module (auto-initializes)
    
    // Initialize transform editor (Phase 9.3.1 Step 5)
    initTransformEditorHandlers();
    
    // Theme buttons now handled by preview-style-theme.js module


    // ==================== Sidebar Add/Edit Forms (Phase 8 - Mode Refactoring) ====================
    
    // State for sidebar add form
    let sidebarAddNodeType = 'tag';
    let sidebarAddCustomParamsCount = 0;
    let sidebarAddSelectedComponentData = null;
    
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
        
        // Reset form state
        sidebarAddNodeType = 'snippet';
        sidebarAddCustomParamsCount = 0;
        sidebarAddSelectedComponentData = null;
        
        // Reset UI elements
        if (addTypeInput) addTypeInput.value = 'snippet';
        // Use visual tag selector if available, fallback to direct value set
        if (addTagSelector) {
            addTagSelector.selectTag('div');
        } else if (addTagSelect) {
            addTagSelect.value = 'div';
        }
        setAddPosition('after');
        if (addClassInput) addClassInput.value = '';
        if (addCustomParamsList) addCustomParamsList.innerHTML = '';
        if (addCustomParamsContainer) addCustomParamsContainer.style.display = 'none';
        if (addExpandParamsBtn) addExpandParamsBtn.querySelector('svg').style.transform = '';
        if (addComponentSelect) addComponentSelect.value = '';

        
        // Update tabs UI
        updateSidebarAddTypeTabs('snippet');
        updateSidebarAddNodeTypeUI();
        updateSidebarAddMandatoryParams();
        updateSidebarAddTextKeyPreview();
        
        // Load snippets since it's the default tab
        loadSidebarSnippetsList();
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
        if (addCustomParamsSection) addCustomParamsSection.style.display = isTag ? 'block' : 'none';
        
        // Update mandatory params visibility
        updateSidebarAddMandatoryParams();
        
        // Component vars
        if (addComponentVars) addComponentVars.style.display = (isComponent && sidebarAddSelectedComponentData) ? 'block' : 'none';
    }
    
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
        
        mandatoryParams.forEach(param => {
            const row = document.createElement('div');
            row.className = 'preview-contextual-form__param-row';
            row.innerHTML = `
                <label class="preview-contextual-form__param-label">${param}:</label>
                <input type="text" 
                       id="add-mandatory-${param}"
                       class="admin-input admin-input--sm preview-contextual-form__param-input" 
                       placeholder="${PreviewConfig.i18n.required || 'Required'}">
            `;
            addMandatoryParamsContainer.appendChild(row);
        });
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
    
    // Load components for sidebar add form
    async function loadSidebarComponentsList() {
        if (!addComponentSelect) return;
        
        try {
            const response = await QuickSiteAdmin.apiRequest('listComponents', 'GET');
            if (response.ok && response.data?.data?.components) {
                addComponentSelect.innerHTML = `<option value="">${PreviewConfig.i18n.selectComponentPlaceholder || '-- Select a component --'}</option>`;
                response.data.data.components.forEach(comp => {
                    if (!comp.valid) return; // Skip invalid components
                    const option = document.createElement('option');
                    option.value = comp.name;
                    option.textContent = comp.name;
                    addComponentSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('[Preview] Failed to load components:', error);
        }
    }
    
    // ==================== Snippet Selector ====================
    // State for snippets
    let snippetsLoaded = false;
    let snippetsData = { core: {}, project: {} };
    let selectedSnippetId = null;
    let selectedSnippetData = null;
    let currentSnippetCategory = 'all';
    
    // Category icons for snippets
    const snippetCategoryIcons = {
        all: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        nav: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        forms: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>',
        cards: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>',
        layouts: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
        content: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        lists: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>'
    };
    
    // Load snippets for sidebar add form
    async function loadSidebarSnippetsList() {
        if (!addSnippetCards || !addSnippetCategories) return;
        
        // Show loading
        addSnippetCards.innerHTML = `<div class="snippet-selector__loading"><div class="spinner"></div>${PreviewConfig.i18n.loading || 'Loading...'}</div>`;
        if (addSnippetPreview) addSnippetPreview.style.display = 'none';
        
        try {
            const response = await QuickSiteAdmin.apiRequest('listSnippets', 'GET');
            if (response.ok && response.data?.data) {
                // API returns { snippets: [], byCategory: {}, counts: {} }
                // Store the byCategory data for rendering
                snippetsData = {
                    byCategory: response.data.data.byCategory || {},
                    snippets: response.data.data.snippets || []
                };
                snippetsLoaded = true;
                
                // Build category tabs
                renderSnippetCategories();
                
                // Render snippet cards
                renderSnippetCards();
            } else {
                addSnippetCards.innerHTML = `<div class="snippet-selector__empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                    <span>${PreviewConfig.i18n.noSnippetsFound || 'No snippets found'}</span>
                </div>`;
            }
        } catch (error) {
            console.error('[Preview] Failed to load snippets:', error);
            addSnippetCards.innerHTML = `<div class="snippet-selector__empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                <span>${PreviewConfig.i18n.errorLoadingSnippets || 'Error loading snippets'}</span>
            </div>`;
        }
    }
    
    // Render snippet category tabs
    function renderSnippetCategories() {
        if (!addSnippetCategories) return;
        
        // Collect all categories from byCategory
        const categories = new Set(['all']);
        Object.keys(snippetsData.byCategory || {}).forEach(cat => categories.add(cat));
        
        // Render category buttons
        addSnippetCategories.innerHTML = '';
        categories.forEach(cat => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `snippet-selector__category${cat === currentSnippetCategory ? ' active' : ''}`;
            btn.dataset.category = cat;
            btn.innerHTML = `${snippetCategoryIcons[cat] || snippetCategoryIcons.content}
                <span>${cat === 'all' ? (PreviewConfig.i18n.allSnippets || 'All') : cat.charAt(0).toUpperCase() + cat.slice(1)}</span>`;
            btn.addEventListener('click', () => {
                currentSnippetCategory = cat;
                addSnippetCategories.querySelectorAll('.snippet-selector__category').forEach(b => {
                    b.classList.toggle('active', b.dataset.category === cat);
                });
                renderSnippetCards();
            });
            addSnippetCategories.appendChild(btn);
        });
    }
    
    // Render snippet cards based on selected category
    function renderSnippetCards() {
        if (!addSnippetCards) return;
        
        // Collect snippets to display
        let snippetsToShow = [];
        
        if (currentSnippetCategory === 'all') {
            // Show all snippets from the flat list
            snippetsToShow = snippetsData.snippets || [];
        } else {
            // Show snippets from specific category
            snippetsToShow = (snippetsData.byCategory || {})[currentSnippetCategory] || [];
        }
        
        if (snippetsToShow.length === 0) {
            addSnippetCards.innerHTML = `<div class="snippet-selector__empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
                <span>${PreviewConfig.i18n.noSnippetsInCategory || 'No snippets in this category'}</span>
            </div>`;
            return;
        }
        
        // Render cards
        addSnippetCards.innerHTML = '';
        snippetsToShow.forEach(snippet => {
            const card = document.createElement('div');
            card.className = `snippet-selector__card${snippet.id === selectedSnippetId ? ' selected' : ''}`;
            card.dataset.snippetId = snippet.id;
            card.innerHTML = `
                <span class="snippet-selector__card-name">${snippet.name || snippet.id}</span>
                <span class="snippet-selector__card-desc">${snippet.description || ''}</span>
                <span class="snippet-selector__card-source ${snippet.isCore ? 'snippet-selector__card-source--core' : ''}">${snippet.isCore ? 'Core' : 'Project'}</span>
            `;
            card.addEventListener('click', () => selectSnippet(snippet));
            addSnippetCards.appendChild(card);
        });
    }
    
    // Select a snippet
    async function selectSnippet(snippet) {
        selectedSnippetId = snippet.id;
        selectedSnippetData = snippet;
        
        // Update UI
        addSnippetCards.querySelectorAll('.snippet-selector__card').forEach(card => {
            card.classList.toggle('selected', card.dataset.snippetId === snippet.id);
        });
        
        // Update hidden input
        if (addSnippetInput) addSnippetInput.value = snippet.id;
        
        // Show preview panel
        if (addSnippetPreview) {
            addSnippetPreview.style.display = 'block';
            if (addSnippetPreviewTitle) addSnippetPreviewTitle.textContent = snippet.name || snippet.id;
            if (addSnippetPreviewSource) {
                addSnippetPreviewSource.textContent = snippet.isCore ? 'Core' : 'Project';
                addSnippetPreviewSource.className = `snippet-selector__preview-source ${snippet.isCore ? 'snippet-selector__preview-source--core' : ''}`;
            }
            if (addSnippetPreviewDesc) addSnippetPreviewDesc.textContent = snippet.description || '';
            
            // Show delete button only for project snippets (not core)
            if (addSnippetPreviewActions) {
                addSnippetPreviewActions.style.display = snippet.isCore ? 'none' : 'flex';
            }
        }
        
        // Load full snippet data for preview
        try {
            const response = await QuickSiteAdmin.apiRequest('getSnippet', 'GET', null, [], { id: snippet.id });
            if (response.ok && response.data?.data) {
                selectedSnippetData = { ...snippet, ...response.data.data };
                renderSnippetPreview(selectedSnippetData);
            }
        } catch (error) {
            console.error('[Preview] Failed to load snippet details:', error);
        }
    }
    
    // Delete selected snippet (only for project snippets)
    async function deleteSelectedSnippet() {
        if (!selectedSnippetData || !selectedSnippetId) {
            showToast(PreviewConfig.i18n.noSnippetSelected || 'No snippet selected', 'warning');
            return;
        }
        
        if (selectedSnippetData.isCore) {
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
                
                // Hide preview panel
                if (addSnippetPreview) addSnippetPreview.style.display = 'none';
                
                // Reset selection
                selectedSnippetId = null;
                selectedSnippetData = null;
                if (addSnippetInput) addSnippetInput.value = '';
                
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
    
    // Render snippet preview in iframe
    function renderSnippetPreview(snippetData) {
        if (!addSnippetPreviewFrame) return;
        
        // Build HTML from structure
        const html = buildSnippetHtml(snippetData.structure, snippetData);
        
        // Get project CSS URL if available
        const projectStyleUrl = PreviewConfig.projectStyleUrl || '';
        
        // Create preview document
        const previewDoc = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ${projectStyleUrl ? `<link rel="stylesheet" href="${projectStyleUrl}">` : ''}
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
    
    // ==================== Visual Tag Selector ====================
    // Initialize visual tag selector for both add and edit modes
    
    function initTagSelector(selectorId) {
        const selector = document.getElementById(`${selectorId}-tag-selector`);
        if (!selector) return;
        
        const categories = selector.querySelector(`#${selectorId}-tag-categories`);
        const panels = selector.querySelector(`#${selectorId}-tag-panels`);
        const searchInput = selector.querySelector(`#${selectorId}-tag-search`);
        const searchClear = selector.querySelector(`#${selectorId}-tag-search-clear`);
        const searchResults = selector.querySelector(`#${selectorId}-tag-search-results`);
        const searchPanel = selector.querySelector('[data-category-panel="search"]');
        const noResults = selector.querySelector(`#${selectorId}-tag-no-results`);
        const hiddenInput = document.getElementById(`${selectorId}-tag`);
        const selectedValueEl = selector.querySelector(`#${selectorId}-tag-selected-value`);
        
        // Preview panel elements
        const previewPanel = selector.querySelector(`#${selectorId}-tag-preview`);
        const previewName = selector.querySelector(`#${selectorId}-tag-preview-name`);
        const previewDesc = selector.querySelector(`#${selectorId}-tag-preview-desc`);
        const previewRender = selector.querySelector(`#${selectorId}-tag-preview-render`);
        const previewNoRender = selector.querySelector(`#${selectorId}-tag-preview-norender`);
        const codeToggle = selector.querySelector(`#${selectorId}-tag-code-toggle`);
        const codeView = selector.querySelector(`#${selectorId}-tag-preview-code`);
        const codeContent = selector.querySelector(`#${selectorId}-tag-preview-code-content`);
        
        // Code view toggle state (persisted in localStorage)
        const CODE_VIEW_STORAGE_KEY = 'tagSelector_showHtmlCode';
        let showHtmlCode = localStorage.getItem(CODE_VIEW_STORAGE_KEY) === 'true';
        
        // Initialize code view state
        if (codeToggle && codeView) {
            if (showHtmlCode) {
                codeToggle.classList.add('active');
                codeView.style.display = '';
            }
            
            codeToggle.addEventListener('click', function() {
                showHtmlCode = !showHtmlCode;
                localStorage.setItem(CODE_VIEW_STORAGE_KEY, showHtmlCode);
                
                this.classList.toggle('active', showHtmlCode);
                codeView.style.display = showHtmlCode ? '' : 'none';
            });
        }
        
        // Load tag examples from embedded JSON
        let tagExamples = {};
        const examplesDataEl = document.getElementById(`${selectorId}-tag-examples-data`);
        if (examplesDataEl) {
            try {
                tagExamples = JSON.parse(examplesDataEl.textContent);
            } catch (e) {
                console.error('Failed to parse tag examples data:', e);
            }
        }
        
        // Get all tags data from all panels
        const allTags = [];
        selector.querySelectorAll('.tag-selector__card').forEach(card => {
            allTags.push({
                tag: card.dataset.tag,
                category: card.dataset.category,
                desc: card.dataset.desc || '',
                required: card.dataset.required === 'true',
                element: card
            });
        });
        
        // Category switching
        if (categories) {
            categories.querySelectorAll('.tag-selector__category').forEach(btn => {
                btn.addEventListener('click', function() {
                    // Clear search first
                    if (searchInput) {
                        searchInput.value = '';
                        updateSearchVisibility(false);
                    }
                    
                    // Update active category
                    categories.querySelectorAll('.tag-selector__category').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show corresponding panel
                    const catId = this.dataset.category;
                    panels.querySelectorAll('.tag-selector__panel').forEach(p => p.classList.remove('active'));
                    panels.querySelector(`[data-category-panel="${catId}"]`)?.classList.add('active');
                });
            });
        }
        
        // Tag card clicks
        selector.querySelectorAll('.tag-selector__card').forEach(card => {
            card.addEventListener('click', function() {
                selectTag(this.dataset.tag);
            });
        });
        
        // Select a tag
        function selectTag(tagName) {
            // Update hidden input
            if (hiddenInput) {
                hiddenInput.value = tagName;
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            
            // Update visual selection
            selector.querySelectorAll('.tag-selector__card').forEach(c => c.classList.remove('selected'));
            selector.querySelectorAll(`.tag-selector__card[data-tag="${tagName}"]`).forEach(c => c.classList.add('selected'));
            
            // Update selected indicator
            if (selectedValueEl) {
                selectedValueEl.textContent = `<${tagName}>`;
            }
            
            // Update preview panel
            updatePreview(tagName);
        }
        
        // Update the preview panel for a tag
        function updatePreview(tagName) {
            if (!previewPanel) return;
            
            // Update tag name
            if (previewName) {
                previewName.textContent = `<${tagName}>`;
            }
            
            // Get tag description from allTags data
            const tagData = allTags.find(t => t.tag === tagName);
            if (previewDesc && tagData) {
                previewDesc.textContent = tagData.desc;
            }
            
            // Get example HTML from tagExamples
            const exampleHtml = tagExamples[tagName];
            
            if (exampleHtml === null || exampleHtml === undefined) {
                // Non-renderable tag
                if (previewRender) previewRender.style.display = 'none';
                if (previewNoRender) previewNoRender.style.display = 'flex';
                if (codeView) codeView.style.display = 'none';
            } else {
                // Has visual example
                if (previewRender) {
                    previewRender.style.display = '';
                    previewRender.innerHTML = exampleHtml;
                }
                if (previewNoRender) previewNoRender.style.display = 'none';
                
                // Update code view with formatted HTML
                if (codeContent) {
                    // Format the HTML for display (escape and prettify)
                    const formattedHtml = formatHtmlForDisplay(exampleHtml);
                    codeContent.textContent = formattedHtml;
                }
                if (codeView && showHtmlCode) {
                    codeView.style.display = '';
                }
            }
        }
        
        // Format HTML for display in code view
        function formatHtmlForDisplay(html) {
            // Simple HTML prettifier
            let formatted = html;
            
            // Remove tag-ex-* classes for cleaner display
            formatted = formatted.replace(/\s*class="[^"]*tag-ex[^"]*"/g, '');
            
            // Add newlines after closing tags for readability  
            formatted = formatted.replace(/><(?!\/)/g, '>\n<');
            formatted = formatted.replace(/<\/(div|section|article|header|footer|main|aside|nav|figure|form|fieldset|ul|ol|dl|table|thead|tbody|tfoot|tr|menu|details|blockquote|pre|video|audio)>/g, '</$1>\n');
            
            // Trim extra whitespace
            formatted = formatted.trim();
            
            return formatted;
        }
        
        // Search functionality
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                updateSearchVisibility(query.length > 0);
                
                if (query.length === 0) {
                    return;
                }
                
                // Filter tags
                const matches = allTags.filter(t => 
                    t.tag.toLowerCase().includes(query) || 
                    t.desc.toLowerCase().includes(query)
                );
                
                // Render search results
                if (searchResults) {
                    searchResults.innerHTML = '';
                    
                    if (matches.length === 0) {
                        if (noResults) noResults.style.display = 'flex';
                    } else {
                        if (noResults) noResults.style.display = 'none';
                        
                        // Get category labels
                        const categoryLabels = {};
                        categories.querySelectorAll('.tag-selector__category').forEach(btn => {
                            categoryLabels[btn.dataset.category] = btn.querySelector('.tag-selector__category-label')?.textContent || btn.dataset.category;
                        });
                        
                        matches.forEach(match => {
                            const card = document.createElement('button');
                            card.type = 'button';
                            card.className = 'tag-selector__card tag-selector__card--search';
                            card.dataset.tag = match.tag;
                            if (hiddenInput?.value === match.tag) card.classList.add('selected');
                            card.innerHTML = `
                                <span class="tag-selector__card-tag">&lt;${match.tag}&gt;</span>
                                <span class="tag-selector__card-category">${categoryLabels[match.category] || match.category}</span>
                                ${match.required ? '<span class="tag-selector__card-required" title="Requires parameters">*</span>' : ''}
                            `;
                            card.addEventListener('click', function() {
                                selectTag(match.tag);
                            });
                            searchResults.appendChild(card);
                        });
                    }
                }
            });
        }
        
        // Clear search
        if (searchClear) {
            searchClear.addEventListener('click', function() {
                if (searchInput) {
                    searchInput.value = '';
                    updateSearchVisibility(false);
                    searchInput.focus();
                }
            });
        }
        
        // Toggle search mode visibility
        function updateSearchVisibility(isSearching) {
            if (searchClear) searchClear.style.display = isSearching ? 'block' : 'none';
            if (searchPanel) searchPanel.style.display = isSearching ? 'block' : 'none';
            
            // Hide/show category panels
            panels.querySelectorAll('.tag-selector__panel:not([data-category-panel="search"])').forEach(p => {
                p.classList.toggle('active', !isSearching && p.dataset.categoryPanel === getActiveCategory());
            });
            
            // Dim category buttons during search
            if (categories) {
                categories.style.opacity = isSearching ? '0.5' : '1';
                categories.style.pointerEvents = isSearching ? 'none' : 'auto';
            }
        }
        
        // Get currently active category
        function getActiveCategory() {
            return categories?.querySelector('.tag-selector__category.active')?.dataset.category || 'layout';
        }
        
        // Initialize code view for default tag
        const initialTag = hiddenInput?.value || 'div';
        updatePreview(initialTag);
        
        // Public API for programmatic tag selection
        return {
            selectTag,
            getValue: () => hiddenInput?.value,
            reset: () => selectTag('div')
        };
    }
    
    // Initialize tag selector for add mode
    const addTagSelector = initTagSelector('add');
    
    // Tag select change handler
    addTagSelect?.addEventListener('change', function() {
        updateSidebarAddMandatoryParams();
        updateSidebarAddTextKeyPreview();
    });
    
    // Expand params handler
    addExpandParamsBtn?.addEventListener('click', function() {
        const isExpanded = addCustomParamsContainer?.style.display !== 'none';
        if (addCustomParamsContainer) {
            addCustomParamsContainer.style.display = isExpanded ? 'none' : 'block';
        }
        const svg = this.querySelector('svg');
        if (svg) svg.style.transform = isExpanded ? '' : 'rotate(45deg)';
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
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
        const classes = addClassInput?.value?.trim() || '';
        
        // Collect mandatory params
        const params = {};
        const mandatoryParamInputs = addMandatoryParamsContainer?.querySelectorAll('input') || [];
        mandatoryParamInputs.forEach(input => {
            const paramName = input.id.replace('add-mandatory-', '');
            if (input.value) params[paramName] = input.value;
        });
        
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
        
        const requestData = {
            type: structInfo.type,
            targetNodeId: selectedNode === '' ? 'root' : selectedNode,
            tag: tag,
            position: selectedNode === '' ? 'inside' : position
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
        const varInputs = addComponentVarsContainer?.querySelectorAll('input[data-var-name]') || [];
        varInputs.forEach(input => {
            const varName = input.dataset.varName;
            if (input.value) vars[varName] = input.value;
        });
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error('Invalid structure type');
        }
        
        const requestData = {
            type: structInfo.type,
            targetNodeId: selectedNode === '' ? 'root' : selectedNode,
            component: componentName,
            position: selectedNode === '' ? 'inside' : position
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
        
        const position = getAddPosition();
        
        // Parse struct to get type and name
        const structInfo = parseStruct(selectedStruct);
        if (!structInfo || !structInfo.type) {
            throw new Error('Invalid structure type');
        }
        
        // Use the new insertSnippet command which handles full structure + translations
        const requestData = {
            type: structInfo.type,
            targetNodeId: selectedNode === '' ? 'root' : selectedNode,
            position: selectedNode === '' ? 'inside' : position,
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
            
            // Live DOM update if HTML returned
            if (data.html && targetNode) {
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
    
    // ========== Save as Snippet ==========
    // Phase 5k: Save selected element as a reusable snippet
    
    // State for save snippet form
    let saveSnippetStructureData = null;
    let saveSnippetTranslationsData = null;
    
    // Show save snippet form
    async function showSaveSnippetForm() {
        if (!saveSnippetForm) return;
        
        // Must have selection
        if (selectedStruct == null || selectedNode == null) {
            showToast(PreviewConfig.i18n.selectNodeFirst || 'Select an element first', 'warning');
            return;
        }
        if (selectedNode === '' && currentEditType !== 'component') {
            showToast(PreviewConfig.i18n?.cannotModifyRoot || 'Cannot save the root as snippet. Select a child element.', 'warning');
            return;
        }
        
        try {
            // Get structure data for the selected element
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
            // For root selection, use the entire structure; otherwise navigate to node
            const nodeData = selectedNode === '' ? structure : navigateToNode(structure, selectedNode);
            if (!nodeData) {
                throw new Error('Node not found in structure');
            }
            
            // Store original node data - will be cloned and remapped on submit
            saveSnippetStructureData = JSON.parse(JSON.stringify(nodeData));
            
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
                const tag = nodeData.tag || nodeData.component || '?';
                const classes = nodeData.params?.class || '';
                const childCount = (nodeData.children || []).length;
                saveSnippetPreview.innerHTML = `<code>&lt;${tag}${classes ? ' class="' + classes + '"' : ''}&gt;</code>` +
                    (childCount > 0 ? ` <small>(${childCount} children)</small>` : '');
            }
            
            // Focus name input
            if (saveSnippetName) saveSnippetName.focus();
            
        } catch (error) {
            console.error('[Preview] Save snippet form error:', error);
            showToast(PreviewConfig.i18n.loadError || 'Failed to load element data', 'error');
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
    
    // ==================== Variables Panel (Component-only) ====================
    
    // Stored full component structure when Variables panel is open
    let variablesPanelStructure = null;
    // Cached flat list of translation key names
    let variablesPanelTranslationKeys = [];
    
    /**
     * Flatten nested translation object into dot-notation keys
     * e.g. { home: { title: "x" } } → ["home.title"]
     */
    function flattenTranslationKeys(obj, prefix) {
        const keys = [];
        if (!obj || typeof obj !== 'object') return keys;
        for (const key of Object.keys(obj)) {
            const fullKey = prefix ? prefix + '.' + key : key;
            if (typeof obj[key] === 'object' && obj[key] !== null && !Array.isArray(obj[key])) {
                keys.push(...flattenTranslationKeys(obj[key], fullKey));
            } else {
                keys.push(fullKey);
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
        if (textKey.startsWith('{{') && textKey.endsWith('}}')) return 'variable';
        if (textKey.startsWith('__RAW__')) return 'raw';
        return 'translation';
    }
    
    /**
     * Get the editable value from a textKey based on its type
     */
    function textKeyToEditValue(textKey, type) {
        if (!textKey) return '';
        if (type === 'variable') return textKey.slice(2, -2); // strip {{ }}
        if (type === 'raw') return textKey.slice(7); // strip __RAW__
        return textKey; // translation key as-is
    }
    
    /**
     * Convert edited value back to textKey based on type
     */
    function editValueToTextKey(value, type) {
        if (!value) return '';
        if (type === 'variable') return '{{' + value + '}}';
        if (type === 'raw') return '__RAW__' + value;
        return value; // translation key as-is
    }
    
    /**
     * Recursively collect all nodes with textKey from a structure
     * @returns Array of { nodeId, tag, textKey, path }
     */
    function collectTextKeyNodes(node, nodeId, parentPath) {
        const results = [];
        if (!node || typeof node !== 'object') return results;
        
        const tag = node.tag || node.component || '?';
        const path = parentPath ? parentPath + ' > ' + tag : tag;
        
        if (node.textKey) {
            results.push({ nodeId, tag, textKey: node.textKey, path });
        }
        
        if (node.children && Array.isArray(node.children)) {
            node.children.forEach((child, i) => {
                const childId = nodeId ? nodeId + '.' + i : String(i);
                results.push(...collectTextKeyNodes(child, childId, path));
            });
        }
        
        return results;
    }
    
    /**
     * Count nodes without textKey (containers)
     */
    function countContainerNodes(node) {
        if (!node || typeof node !== 'object') return 0;
        let count = 0;
        if (!node.textKey && node.tag) count = 1;
        if (node.children && Array.isArray(node.children)) {
            node.children.forEach(child => {
                count += countContainerNodes(child);
            });
        }
        return count;
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
     * Show the Variables panel
     */
    async function showVariablesPanel() {
        if (!variablesPanel || currentEditType !== 'component') return;
        
        // Hide select info, show variables panel
        const selectInfo = document.getElementById('contextual-select-info');
        if (selectInfo) selectInfo.style.display = 'none';
        // Also hide snippet form if open
        if (saveSnippetForm) saveSnippetForm.style.display = 'none';
        
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
            
            // Extract flat translation key list from first available language
            variablesPanelTranslationKeys = [];
            if (transResp.ok && transResp.data?.data?.translations) {
                const allTranslations = transResp.data.data.translations;
                const firstLang = Object.keys(allTranslations)[0];
                if (firstLang) {
                    variablesPanelTranslationKeys = flattenTranslationKeys(allTranslations[firstLang], '').sort();
                }
            }
            
            // Collect textKey nodes
            const textKeyNodes = collectTextKeyNodes(variablesPanelStructure, '', '');
            const containerCount = countContainerNodes(variablesPanelStructure);
            
            // Hide loading
            if (variablesPanelLoading) variablesPanelLoading.style.display = 'none';
            
            if (textKeyNodes.length === 0) {
                // Show empty state
                if (variablesPanelEmpty) variablesPanelEmpty.style.display = '';
            } else {
                // Render cards
                renderVariableCards(textKeyNodes);
            }
            
            // Show footer info
            if (containerCount > 0 && variablesPanelFooter && variablesPanelFooterText) {
                variablesPanelFooterText.textContent = (PreviewConfig.i18n?.variablesContainerCount || '%d container node(s) without text binding').replace('%d', containerCount);
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
            // Select dropdown populated from existing translation keys
            const sel = document.createElement('select');
            sel.className = 'preview-variable-card__value-select';
            // Empty option
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '— ' + (PreviewConfig.i18n?.variablesPlaceholderTranslation || 'Select a translation key') + ' —';
            sel.appendChild(emptyOpt);
            variablesPanelTranslationKeys.forEach(key => {
                const opt = document.createElement('option');
                opt.value = key;
                opt.textContent = key;
                if (key === currentValue) opt.selected = true;
                sel.appendChild(opt);
            });
            // If currentValue exists but is not in the list, add it as a custom option
            if (currentValue && !variablesPanelTranslationKeys.includes(currentValue)) {
                const customOpt = document.createElement('option');
                customOpt.value = currentValue;
                customOpt.textContent = currentValue + ' (?)';
                customOpt.selected = true;
                sel.appendChild(customOpt);
            }
            wrapper.appendChild(sel);
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
     * Render variable cards for textKey nodes
     */
    function renderVariableCards(textKeyNodes) {
        if (!variablesPanelCards) return;
        variablesPanelCards.innerHTML = '';
        
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
            
            variablesPanelCards.appendChild(card);
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
     * Hide the Variables panel
     */
    function hideVariablesPanel() {
        if (!variablesPanel) return;
        
        variablesPanel.style.display = 'none';
        variablesPanelStructure = null;
        
        const selectInfo = document.getElementById('contextual-select-info');
        if (selectInfo) selectInfo.style.display = '';
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
            const requestData = {
                id: id,
                name: name,
                category: category,
                description: description,
                structure: remappedStructure,
                translations: translations
            };
            
            console.log('[Preview] Creating snippet:', requestData);
            
            const response = await QuickSiteAdmin.apiRequest('createSnippet', 'POST', requestData);
            
            if (response.ok) {
                showToast(PreviewConfig.i18n.snippetSaved || 'Snippet saved successfully!', 'success');
                
                // Close form
                hideSaveSnippetForm();
                
                // Refresh snippets list if visible
                snippetsLoaded = false;
                if (addSnippetCards && addSnippetCards.offsetParent !== null) {
                    await loadSidebarSnippetsList();
                }
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
            'img': ['src'],
            'input': ['type'],
            'form': ['action'],
            'iframe': ['src'],
            'video': ['src'],
            'audio': ['src'],
            'source': ['src'],
            'label': ['for'],
            'select': ['name'],
            'textarea': ['name'],
            'area': ['href'],
            'embed': ['src'],
            'object': ['data'],
            'track': ['src'],
            'link': ['href', 'rel']
        },
        TAGS_WITH_ALT: ['img', 'area'],
        RESERVED_PARAMS: ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description']
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
    
    
    // ==================== Miniplayer (Global Sync) ====================
    
    const MINIPLAYER_STORAGE_KEY = 'quicksite-miniplayer';
    const previewPage = document.getElementById('preview-page');
    const miniplayerToggle = document.getElementById('preview-miniplayer-toggle');
    const miniplayerControls = document.getElementById('preview-miniplayer-controls');
    const miniplayerReload = document.getElementById('miniplayer-reload');
    const miniplayerExpand = document.getElementById('miniplayer-expand');
    const miniplayerClose = document.getElementById('miniplayer-close');
    
    let isMiniplayer = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    // Load saved miniplayer state (synced with global)
    function loadMiniplayerState() {
        const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
        if (saved) {
            try {
                const state = JSON.parse(saved);
                // Sync toggle button state with global enabled state
                if (state.enabled) {
                    updateToggleButtonState(true);
                }
                // Apply local preview miniplayer mode if it was enabled
                if (state.previewMiniplayer) {
                    enableMiniplayer(false);
                    if (state.x !== null) {
                        container.style.left = state.x + 'px';
                        container.style.top = state.y + 'px';
                        container.style.right = 'auto';
                        container.style.bottom = 'auto';
                    }
                    if (state.width) {
                        container.style.width = state.width + 'px';
                        container.style.height = state.height + 'px';
                    }
                }
            } catch (e) {
                console.warn('Failed to load miniplayer state:', e);
            }
        }
    }
    
    // Save miniplayer state (synced with global)
    function saveMiniplayerState() {
        // Read existing global state
        let state = { enabled: false };
        try {
            const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
            if (saved) state = JSON.parse(saved);
        } catch (e) {}
        
        // Update with preview-specific state
        state.previewMiniplayer = isMiniplayer;
        if (isMiniplayer) {
            const rect = container.getBoundingClientRect();
            state.x = parseInt(container.style.left) || rect.left;
            state.y = parseInt(container.style.top) || rect.top;
            state.width = rect.width;
            state.height = rect.height;
        }
        
        // Sync route and lang from current preview
        state.editTarget = targetSelect ? targetSelect.value : '';
        state.lang = langSelect ? langSelect.value : '';
        
        localStorage.setItem(MINIPLAYER_STORAGE_KEY, JSON.stringify(state));
    }
    
    // Update toggle button appearance
    function updateToggleButtonState(enabled) {
        if (!miniplayerToggle) return;
        
        const minimizeIcon = miniplayerToggle.querySelector('.preview-miniplayer-icon--minimize');
        const expandIcon = miniplayerToggle.querySelector('.preview-miniplayer-icon--expand');
        const minimizeText = miniplayerToggle.querySelector('.preview-miniplayer-text--minimize');
        const expandText = miniplayerToggle.querySelector('.preview-miniplayer-text--expand');
        
        if (enabled) {
            if (minimizeIcon) minimizeIcon.style.display = 'none';
            if (expandIcon) expandIcon.style.display = 'block';
            if (minimizeText) minimizeText.style.display = 'none';
            if (expandText) expandText.style.display = 'inline';
        } else {
            if (minimizeIcon) minimizeIcon.style.display = 'block';
            if (expandIcon) expandIcon.style.display = 'none';
            if (minimizeText) minimizeText.style.display = 'inline';
            if (expandText) expandText.style.display = 'none';
        }
    }
    
    function enableMiniplayer(save = true) {
        isMiniplayer = true;
        previewPage.classList.add('preview-page--miniplayer');
        updateToggleButtonState(true);
        if (save) saveMiniplayerState();
    }
    
    function disableMiniplayer(save = true) {
        isMiniplayer = false;
        previewPage.classList.remove('preview-page--miniplayer');
        updateToggleButtonState(false);
        // Reset position and size
        container.style.left = '';
        container.style.top = '';
        container.style.right = '';
        container.style.bottom = '';
        container.style.width = '';
        container.style.height = '';
        if (save) saveMiniplayerState();
    }
    
    // Toggle global miniplayer state (for when user leaves preview page)
    function toggleGlobalMiniplayer() {
        let state = { enabled: false };
        try {
            const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
            if (saved) state = JSON.parse(saved);
        } catch (e) {}
        
        state.enabled = !state.enabled;
        
        // Sync route and lang
        state.editTarget = targetSelect ? targetSelect.value : '';
        state.lang = langSelect ? langSelect.value : '';
        
        localStorage.setItem(MINIPLAYER_STORAGE_KEY, JSON.stringify(state));
        updateToggleButtonState(state.enabled);
        
        // Show a toast message
        if (state.enabled) {
            showToast(PreviewConfig.i18n.miniplayer + ': ON - Preview will float on other pages', 'success');
        } else {
            showToast(PreviewConfig.i18n.miniplayer + ': OFF', 'info');
        }
    }
    
    function toggleMiniplayer() {
        // For preview page: toggle LOCAL miniplayer (floating within preview page)
        if (isMiniplayer) {
            disableMiniplayer();
        } else {
            enableMiniplayer();
        }
    }
    
    // Drag functionality
    function onDragStart(e) {
        // Only start drag from the header area (top 28px)
        const rect = container.getBoundingClientRect();
        const relativeY = e.clientY - rect.top;
        
        if (relativeY > 28) return; // Not in header area
        if (e.target.closest('.preview-miniplayer-controls__btn')) return; // Clicked a button
        
        isDragging = true;
        container.classList.add('preview-container--dragging');
        
        dragOffset.x = e.clientX - rect.left;
        dragOffset.y = e.clientY - rect.top;
        
        e.preventDefault();
    }
    
    function onDragMove(e) {
        if (!isDragging) return;
        
        const newX = e.clientX - dragOffset.x;
        const newY = e.clientY - dragOffset.y;
        
        // Constrain to viewport
        const maxX = window.innerWidth - container.offsetWidth;
        const maxY = window.innerHeight - container.offsetHeight;
        
        container.style.left = Math.max(0, Math.min(newX, maxX)) + 'px';
        container.style.top = Math.max(0, Math.min(newY, maxY)) + 'px';
        container.style.right = 'auto';
        container.style.bottom = 'auto';
    }
    
    function onDragEnd() {
        if (isDragging) {
            isDragging = false;
            container.classList.remove('preview-container--dragging');
            saveMiniplayerState();
        }
    }
    
    // Event listeners for miniplayer
    if (miniplayerToggle) {
        // Toggle button controls GLOBAL miniplayer (for use on other pages)
        miniplayerToggle.addEventListener('click', toggleGlobalMiniplayer);
    }
    
    if (miniplayerReload) {
        miniplayerReload.addEventListener('click', reloadPreview);
    }
    
    if (miniplayerExpand) {
        // In preview-page local miniplayer, expand disables local miniplayer
        miniplayerExpand.addEventListener('click', disableMiniplayer);
    }
    
    if (miniplayerClose) {
        miniplayerClose.addEventListener('click', disableMiniplayer);
    }
    
    // Drag events
    container.addEventListener('mousedown', onDragStart);
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);
    
    // Save size on resize
    const resizeObserver = new ResizeObserver(() => {
        if (isMiniplayer) {
            saveMiniplayerState();
        }
    });
    resizeObserver.observe(container);
    
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
        // Local miniplayer API (preview page only)
        toggleMiniplayer: toggleMiniplayer,
        isMiniplayer: () => isMiniplayer,
        // Global miniplayer API
        toggleGlobalMiniplayer: toggleGlobalMiniplayer,
        isGlobalMiniplayerEnabled: () => {
            try {
                const saved = localStorage.getItem(MINIPLAYER_STORAGE_KEY);
                if (saved) return JSON.parse(saved).enabled;
            } catch (e) {}
            return false;
        }
    };
    
    // Initial state
    startLoadingTimeout();
    loadMiniplayerState();
    
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
    if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
        console.log('[Preview] Iframe already loaded, injecting overlay');
        injectOverlay();
    }
    
    // Also try after a short delay (handles race conditions)
    setTimeout(function() {
        if (!overlayInjected) {
            console.log('[Preview] Delayed injection attempt');
            injectOverlay();
        }
    }, 300);
})();
