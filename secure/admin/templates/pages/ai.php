<?php
/**
 * Admin AI Integration Page
 * 
 * Generates AI-ready specifications for QuickSite API.
 * Split into Setup, Build, and Deploy specs for focused AI assistance.
 * 
 * @version 1.6.0
 */

// ============================================================================
// SERVER-SIDE PRE-COMPUTATION FOR AI SPECS
// ============================================================================
// Compute detection data server-side for robustness (no JS race conditions)

$aiPrecomputedData = [];

// Helper: Check if a structure contains a lang-switch component
function aiDetectLangSwitchInStructure($structure, &$nodeId = null) {
    if (!is_array($structure)) return false;
    
    // Check if this node is a lang-switch component
    if (isset($structure['component']) && $structure['component'] === 'lang-switch') {
        $nodeId = $structure['_nodeId'] ?? null;
        return true;
    }
    
    // Recursively check children
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $child) {
            if (aiDetectLangSwitchInStructure($child, $nodeId)) {
                return true;
            }
        }
    }
    
    return false;
}

// Helper: Find parent container of lang-switch or lang-links
function aiFindLangSwitcherParent($structure, &$nodeId = null) {
    if (!is_array($structure)) return false;
    
    // Check if this node contains lang-switch component or lang-related classes
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $child) {
            // Check if child is lang-switch component
            if (isset($child['component']) && $child['component'] === 'lang-switch') {
                $nodeId = $structure['_nodeId'] ?? null;
                return true;
            }
            // Check if child has lang-related class
            if (isset($child['class']) && (
                strpos($child['class'], 'lang-') !== false ||
                strpos($child['class'], 'language') !== false ||
                strpos($child['class'], 'locale') !== false
            )) {
                $nodeId = $structure['_nodeId'] ?? null;
                return true;
            }
        }
        // Recurse into children
        foreach ($structure['children'] as $child) {
            if (aiFindLangSwitcherParent($child, $nodeId)) {
                return true;
            }
        }
    }
    
    return false;
}

// 1. Load footer structure and detect lang-switch
$footerPath = PROJECT_PATH . '/templates/model/json/footer.json';
$aiPrecomputedData['footer'] = [
    'exists' => false,
    'structure' => null,
    'langSwitchFound' => false,
    'langSwitchNodeId' => null,
    'langSwitcherParentNodeId' => null
];

if (file_exists($footerPath)) {
    $footerJson = file_get_contents($footerPath);
    $footerStructure = json_decode($footerJson, true);
    if ($footerStructure) {
        $aiPrecomputedData['footer']['exists'] = true;
        $aiPrecomputedData['footer']['structure'] = $footerStructure;
        
        // Detect lang-switch component
        $langSwitchNodeId = null;
        if (aiDetectLangSwitchInStructure($footerStructure, $langSwitchNodeId)) {
            $aiPrecomputedData['footer']['langSwitchFound'] = true;
            $aiPrecomputedData['footer']['langSwitchNodeId'] = $langSwitchNodeId;
        }
        
        // Find parent container for lang links
        $parentNodeId = null;
        aiFindLangSwitcherParent($footerStructure, $parentNodeId);
        $aiPrecomputedData['footer']['langSwitcherParentNodeId'] = $parentNodeId;
    }
}

// 2. Check if lang-switch component exists
$langSwitchComponentPath = PROJECT_PATH . '/templates/model/json/components/lang-switch.json';
$aiPrecomputedData['langSwitchComponent'] = [
    'exists' => file_exists($langSwitchComponentPath),
    'path' => $langSwitchComponentPath
];

if ($aiPrecomputedData['langSwitchComponent']['exists']) {
    $componentJson = file_get_contents($langSwitchComponentPath);
    $aiPrecomputedData['langSwitchComponent']['structure'] = json_decode($componentJson, true);
}

// 3. Get available site languages
$translationsDir = PROJECT_PATH . '/translate';
$aiPrecomputedData['languages'] = [];
if (is_dir($translationsDir)) {
    $files = glob($translationsDir . '/*.json');
    foreach ($files as $file) {
        $lang = basename($file, '.json');
        if (strlen($lang) === 2) { // Only 2-letter codes
            $aiPrecomputedData['languages'][] = $lang;
        }
    }
    sort($aiPrecomputedData['languages']);
}

// 4. List all components for reference
$componentsDir = PROJECT_PATH . '/templates/model/json/components';
$aiPrecomputedData['components'] = [];
if (is_dir($componentsDir)) {
    $files = glob($componentsDir . '/*.json');
    foreach ($files as $file) {
        $componentName = basename($file, '.json');
        $componentJson = file_get_contents($file);
        $componentData = json_decode($componentJson, true);
        $aiPrecomputedData['components'][$componentName] = [
            'path' => $file,
            'hasVariables' => isset($componentData['_variables']),
            'hasSlots' => isset($componentData['_slots']),
            'variables' => $componentData['_variables'] ?? [],
            'slots' => $componentData['_slots'] ?? []
        ];
    }
}

// 5. Get all pages (routes) - use flattenRoutes for nested structure
$routesPath = PROJECT_PATH . '/routes.php';
$aiPrecomputedData['routes'] = [];
if (file_exists($routesPath)) {
    $routes = include $routesPath;
    if (is_array($routes)) {
        $aiPrecomputedData['routes'] = flattenRoutes($routes);
    }
}

// Encode for JavaScript usage
$aiPrecomputedJson = json_encode($aiPrecomputedData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>

<div class="admin-ai-page" data-precomputed="<?= adminAttr($aiPrecomputedJson) ?>">

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('ai.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('ai.subtitle') ?></p>
</div>

<!-- Introduction -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 16v-4"/>
                <path d="M12 8h.01"/>
            </svg>
            <?= __admin('ai.howItWorks') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-ai-intro">
            <p><?= __admin('ai.intro.description') ?></p>
            
            <div class="admin-ai-steps">
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">1</span>
                    <div class="admin-ai-step__content">
                        <strong><?= __admin('ai.steps.chooseSpec.title') ?></strong>
                        <p><?= __admin('ai.steps.chooseSpec.hint') ?></p>
                    </div>
                </div>
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">2</span>
                    <div class="admin-ai-step__content">
                        <strong><?= __admin('ai.steps.describeGoal.title') ?></strong>
                        <p><?= __admin('ai.steps.describeGoal.hint') ?></p>
                    </div>
                </div>
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">3</span>
                    <div class="admin-ai-step__content">
                        <strong><?= __admin('ai.steps.copyPrompt.title') ?></strong>
                        <p><?= __admin('ai.steps.copyPrompt.hint') ?></p>
                    </div>
                </div>
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">4</span>
                    <div class="admin-ai-step__content">
                        <strong><?= __admin('ai.steps.importExecute.title') ?></strong>
                        <p><?= __admin('ai.steps.importExecute.hint') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Spec Selector -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <?= __admin('ai.specification') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        
        <!-- Step 1: Choose a Spec -->
        <div class="admin-ai-workflow-step">
            <div class="admin-ai-step-badge">1</div>
            <div class="admin-ai-step-label"><?= __admin('ai.steps.chooseSpec.title') ?></div>
        </div>
        
        <!-- Search & Filter Bar -->
        <div class="admin-ai-filter-bar">
            <div class="admin-ai-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="M21 21l-4.35-4.35"/>
                </svg>
                <input type="text" id="spec-search" class="admin-ai-search__input" placeholder="<?= __admin('ai.searchPlaceholder') ?>" oninput="filterSpecs()">
            </div>
            <div class="admin-ai-tags" id="filter-tags">
                <button type="button" class="admin-ai-tag admin-ai-tag--active" data-tag="all" onclick="filterByTag('all')"><?= __admin('ai.filter.all') ?></button>
                <button type="button" class="admin-ai-tag" data-tag="landing" onclick="filterByTag('landing')"><?= __admin('ai.filter.landingPage') ?></button>
                <button type="button" class="admin-ai-tag" data-tag="website" onclick="filterByTag('website')"><?= __admin('ai.filter.website') ?></button>
                <button type="button" class="admin-ai-tag" data-tag="business" onclick="filterByTag('business')"><?= __admin('ai.filter.business') ?></button>
                <button type="button" class="admin-ai-tag" data-tag="creative" onclick="filterByTag('creative')"><?= __admin('ai.filter.creative') ?></button>
                <button type="button" class="admin-ai-tag" data-tag="multilang" onclick="filterByTag('multilang')"><?= __admin('ai.filter.multilingual') ?></button>
            </div>
        </div>

        <!-- Specs Sections -->
        <div class="admin-ai-specs-container" id="specs-container">
            
            <!-- Fresh Start Section -->
            <div class="admin-ai-section" data-section="fresh">
                <h3 class="admin-ai-section__title">
                    <span class="admin-ai-section__icon">🌱</span>
                    <?= __admin('ai.section.freshStart.title') ?>
                    <span class="admin-ai-section__hint"><?= __admin('ai.section.freshStart.hint') ?></span>
                </h3>
                <div class="admin-ai-specs-grid" id="specs-fresh">
                    <!-- Populated by JS -->
                </div>
            </div>

            <!-- Early Stage Section -->
            <div class="admin-ai-section" data-section="early">
                <h3 class="admin-ai-section__title">
                    <span class="admin-ai-section__icon">🌿</span>
                    <?= __admin('ai.section.earlyStage.title') ?>
                    <span class="admin-ai-section__hint"><?= __admin('ai.section.earlyStage.hint') ?></span>
                </h3>
                <div class="admin-ai-specs-grid" id="specs-early">
                    <!-- Populated by JS -->
                </div>
            </div>

            <!-- Work In Progress Section -->
            <div class="admin-ai-section" data-section="wip">
                <h3 class="admin-ai-section__title">
                    <span class="admin-ai-section__icon">🔧</span>
                    <?= __admin('ai.section.workInProgress.title') ?>
                    <span class="admin-ai-section__hint"><?= __admin('ai.section.workInProgress.hint') ?></span>
                </h3>
                <div class="admin-ai-specs-grid" id="specs-wip">
                    <!-- Populated by JS -->
                </div>
            </div>

        </div>

        <!-- Selected Spec Panel (shown when a spec is selected) -->
        <div class="admin-ai-selected-spec" id="selected-spec-panel" style="display: none;">
            <div class="admin-ai-selected-spec__header">
                <button type="button" class="admin-ai-back-btn" onclick="deselectSpec()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M19 12H5"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Back to specs
                </button>
                <div class="admin-ai-selected-spec__info">
                    <span class="admin-ai-selected-spec__icon" id="selected-spec-icon"></span>
                    <span class="admin-ai-selected-spec__name" id="selected-spec-name"></span>
                </div>
            </div>
            
            <!-- Spec Description -->
            <div class="admin-ai-spec-desc" id="spec-description">
                <p id="selected-spec-description"></p>
            </div>
            
            <!-- Page Selector (only for add-section spec) -->
            <div class="admin-ai-page-selector" id="page-selector-container" style="display: none;">
                <label class="admin-ai-page-selector__label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                    </svg>
                    <span>Select target page:</span>
                    <span class="admin-required">*</span>
                </label>
                <select id="target-page-select" class="admin-select" onchange="onTargetPageChange()">
                    <option value="">-- Select a page --</option>
                </select>
                <div class="admin-ai-page-selector__hint" id="page-selector-hint">
                    ⚠️ Please select a page where you want to add the section
                </div>
            </div>
            
            <!-- Navigation Placement Selector (only for add-page spec) -->
            <div class="admin-ai-page-selector" id="nav-placement-container" style="display: none;">
                <label class="admin-ai-page-selector__label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M3 3h18v18H3zM3 9h18M9 21V9"></path>
                    </svg>
                    <span>Where should the page link appear?</span>
                    <span class="admin-required">*</span>
                </label>
                <select id="nav-placement-select" class="admin-select" onchange="onNavPlacementChange()">
                    <option value="">-- Select navigation placement --</option>
                    <option value="menu">Menu - Add to main navigation</option>
                    <option value="footer">Footer - Add to footer links</option>
                    <option value="both">Both - Add to menu and footer</option>
                    <option value="none">I'll handle it myself</option>
                </select>
                <div class="admin-ai-page-selector__hint" id="nav-placement-hint">
                    ⚠️ Please select where you want the page link to appear
                </div>
            </div>
        
            <!-- Step 2: Describe Your Goal -->
            <div class="admin-ai-workflow-step">
                <div class="admin-ai-step-badge">2</div>
                <div class="admin-ai-step-label">Describe Your Goal</div>
            </div>
            
            <!-- Prompt Builder Section -->
            <div class="admin-ai-prompt-builder">
                <h3 class="admin-ai-prompt-builder__title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    What do you want to build?
                </h3>
            
                <!-- Example Prompt Selector -->
                <div class="admin-ai-example-selector">
                    <label class="admin-ai-example-selector__label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                        </svg>
                        <span>Start from an example:</span>
                    </label>
                    <select id="example-prompt-select" class="admin-select" onchange="loadExamplePrompt()">
                        <option value="">Write your own...</option>
                    </select>
                </div>
            
                <!-- Goal Textarea -->
                <div class="admin-ai-goal-area">
                    <textarea id="user-goal" class="admin-textarea" rows="8" placeholder="Describe what you want to create...

Include details like:
• Languages (English, French, Spanish...)
• Pages and their purpose
• Style preferences (colors, mood)
• Specific content or sections"></textarea>
                    <div class="admin-ai-goal-hints">
                        <span class="admin-hint">💡 Be specific! The more details you provide, the better the AI output.</span>
                    </div>
                </div>
            </div>
        
            <!-- Step 3: Copy Prompt -->
            <div class="admin-ai-workflow-step">
                <div class="admin-ai-step-badge">3</div>
                <div class="admin-ai-step-label">Copy & Send to AI</div>
            </div>
        
            <!-- Action Buttons -->
            <div class="admin-ai-spec-actions">
                <button type="button" class="admin-btn admin-btn--primary admin-btn--large" onclick="copyFullPrompt()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg>
                    Copy Full Prompt
                </button>
                <button type="button" class="admin-btn admin-btn--secondary" onclick="copySpecOnly()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Copy Spec Only
                </button>
                <button type="button" class="admin-btn admin-btn--ghost" onclick="previewFullPrompt()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    Preview Full
                </button>
                <button type="button" class="admin-btn admin-btn--ghost" onclick="toggleSpecPreview()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Preview Spec Only
                </button>
            </div>
        
            <!-- Spec Preview (collapsed by default) -->
            <div id="spec-preview" class="admin-ai-spec-preview" style="display: none;">
                <div class="admin-ai-spec-stats" id="ai-spec-stats"></div>
                <textarea id="ai-spec-content" class="admin-textarea admin-textarea--code" rows="15" readonly></textarea>
            </div>
        </div>
        <!-- End of selected-spec-panel -->
        
        <!-- Step 4: Import & Execute (Always visible) -->
        <div class="admin-ai-workflow-step admin-ai-workflow-step--large">
            <div class="admin-ai-step-badge">4</div>
            <div class="admin-ai-step-label">Import AI Response</div>
        </div>
        
        <div class="admin-ai-import-section">
                <p class="admin-ai-import-hint">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    After sending the prompt to AI, paste its JSON response below
                </p>
                
                <div class="admin-ai-import-area">
                    <textarea id="import-json" class="admin-textarea admin-textarea--code" rows="6" placeholder='[
  {"command": "addRoute", "params": {"lang": "en", "route": "/", "title": "Home"}},
  {"command": "editStructure", "params": {"lang": "en", "route": "/", ...}}
]' oninput="validateImportJson()"></textarea>
                    
                    <div class="admin-ai-import-status" id="import-status">
                        <span class="admin-ai-import-status__text">Paste JSON to begin</span>
                    </div>
                </div>
                
                <div class="admin-ai-import-actions" id="import-actions">
                    <button type="button" class="admin-btn admin-btn--primary admin-btn--large" id="preview-btn" onclick="showPreview()" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        Preview Commands
                    </button>
                    <a href="<?= $router->url('batch') ?>" class="admin-btn admin-btn--ghost">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <line x1="3" y1="9" x2="21" y2="9"/>
                            <line x1="9" y1="21" x2="9" y2="9"/>
                        </svg>
                        Open in Batch →
                    </a>
                </div>
                
                <!-- Command Preview Section -->
                <div id="command-preview" class="admin-ai-preview" style="display: none;">
                    <div class="admin-ai-preview-header">
                        <h4 class="admin-ai-preview-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <line x1="8" y1="6" x2="21" y2="6"/>
                                <line x1="8" y1="12" x2="21" y2="12"/>
                                <line x1="8" y1="18" x2="21" y2="18"/>
                                <line x1="3" y1="6" x2="3.01" y2="6"/>
                                <line x1="3" y1="12" x2="3.01" y2="12"/>
                                <line x1="3" y1="18" x2="3.01" y2="18"/>
                            </svg>
                            <span id="preview-title">Commands to Execute</span>
                        </h4>
                        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="hidePreview()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                            Cancel
                        </button>
                    </div>
                    <div class="admin-ai-preview-list" id="preview-list"></div>
                    <div class="admin-ai-preview-actions">
                        <button type="button" class="admin-btn admin-btn--success admin-btn--large" id="execute-btn" onclick="executeImportedJson()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            Execute All
                        </button>
                        <button type="button" class="admin-btn admin-btn--ghost" onclick="hidePreview()">
                            Cancel
                        </button>
                    </div>
                </div>
                
                <!-- Execution Results -->
                <div id="execution-results" class="admin-ai-execution-results" style="display: none;">
                    <div class="admin-ai-execution-header">
                        <h4 class="admin-ai-execution-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                            <span id="execution-title">Execution Complete</span>
                        </h4>
                        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="clearExecutionResults()">Clear</button>
                    </div>
                    <div class="admin-ai-execution-summary" id="execution-summary"></div>
                    <div class="admin-ai-execution-details" id="execution-details"></div>
                </div>
            </div>
        </div>
    </div>


<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/ai.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/ai.js') ?>"></script>

</div> <!-- .admin-ai-page -->
