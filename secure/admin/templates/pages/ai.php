<?php
/**
 * Admin AI Integration Page
 * 
 * Generates AI-ready specifications for QuickSite API.
 * Split into Setup, Build, and Deploy specs for focused AI assistance.
 * 
 * @version 1.6.0
 */
?>

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
            
            <!-- Step 4: Import & Execute -->
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
                
                <div class="admin-ai-import-actions">
                    <button type="button" class="admin-btn admin-btn--success admin-btn--large" id="execute-btn" onclick="executeImportedJson()" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        Execute Now
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
</div>

<style>
.admin-ai-intro p {
    margin-bottom: var(--space-md);
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
}

.admin-ai-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
}

.admin-ai-step {
    display: flex;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
}

.admin-ai-step__number {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--admin-accent);
    color: #000;
    border-radius: 50%;
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-sm);
    flex-shrink: 0;
}

.admin-ai-step__content {
    flex: 1;
}

.admin-ai-step__content strong {
    display: block;
    margin-bottom: var(--space-xs);
    font-size: var(--font-size-sm);
}

.admin-ai-step__content p {
    margin: 0;
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

/* Filter Bar */
.admin-ai-filter-bar {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-lg);
}

.admin-ai-search {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
}

.admin-ai-search svg {
    color: var(--admin-text-muted);
    flex-shrink: 0;
}

.admin-ai-search__input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: var(--font-size-sm);
    color: var(--admin-text);
    outline: none;
}

.admin-ai-search__input::placeholder {
    color: var(--admin-text-muted);
}

.admin-ai-tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}

.admin-ai-tag {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-full);
    color: var(--admin-text-secondary);
    cursor: pointer;
    transition: all 0.15s;
}

.admin-ai-tag:hover {
    border-color: var(--admin-accent);
    color: var(--admin-accent);
}

.admin-ai-tag--active {
    background: var(--admin-accent);
    border-color: var(--admin-accent);
    color: #000;
}

/* Specs Container */
.admin-ai-specs-container {
    display: flex;
    flex-direction: column;
    gap: var(--space-xl);
}

/* Section */
.admin-ai-section {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.admin-ai-section[data-visible="false"] {
    display: none;
}

.admin-ai-section__title {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
    color: var(--admin-text);
}

.admin-ai-section__icon {
    font-size: var(--font-size-xl);
}

.admin-ai-section__hint {
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-normal);
    color: var(--admin-text-muted);
    margin-left: var(--space-xs);
}

/* Specs Grid */
.admin-ai-specs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
}

.admin-ai-specs-grid:empty::after {
    content: 'No specs match your search';
    grid-column: 1 / -1;
    text-align: center;
    padding: var(--space-lg);
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
}

/* Spec Card */
.admin-ai-spec-card {
    display: flex;
    flex-direction: column;
    padding: var(--space-md);
    background: var(--admin-bg);
    border: 2px solid var(--admin-border);
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.2s;
}

.admin-ai-spec-card:hover {
    border-color: var(--admin-accent);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.admin-ai-spec-card__header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.admin-ai-spec-card__icon {
    font-size: var(--font-size-xl);
    line-height: 1;
}

.admin-ai-spec-card__title {
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-sm);
    color: var(--admin-text);
}

.admin-ai-spec-card__desc {
    flex: 1;
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    line-height: 1.4;
    margin-bottom: var(--space-sm);
}

.admin-ai-spec-card__tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-top: auto;
}

.admin-ai-spec-card__tag {
    padding: 2px 6px;
    font-size: 10px;
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-sm);
    color: var(--admin-text-muted);
}

/* Selected Spec Panel */
.admin-ai-selected-spec {
    margin-top: var(--space-lg);
    padding: var(--space-lg);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-lg);
    border: 2px solid var(--admin-accent);
}

.admin-ai-selected-spec__header {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
    margin-bottom: var(--space-md);
    padding-bottom: var(--space-md);
    border-bottom: 1px solid var(--admin-border);
}

.admin-ai-back-btn {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--font-size-sm);
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.15s;
}

.admin-ai-back-btn:hover {
    border-color: var(--admin-accent);
    color: var(--admin-accent);
}

.admin-ai-selected-spec__info {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.admin-ai-selected-spec__icon {
    font-size: var(--font-size-xl);
}

.admin-ai-selected-spec__name {
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-lg);
}

/* Spec Description */
.admin-ai-spec-desc {
    padding: var(--space-md);
    background: var(--admin-bg);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}

.admin-ai-spec-desc p {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

/* Actions */
.admin-ai-spec-actions {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.admin-btn--large {
    padding: var(--space-md) var(--space-xl);
    font-size: var(--font-size-base);
}

/* Preview */
.admin-ai-spec-preview {
    margin-top: var(--space-lg);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--admin-border);
}

.admin-ai-spec-stats {
    display: flex;
    gap: var(--space-lg);
    margin-bottom: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
}

.admin-ai-spec-stat {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}

.admin-ai-spec-stat__label {
    color: var(--admin-text-muted);
}

.admin-ai-spec-stat__value {
    font-weight: var(--font-weight-medium);
    color: var(--admin-accent);
}

.admin-textarea--code {
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
    line-height: 1.4;
    resize: vertical;
    background: var(--admin-bg);
}

/* Prompt Builder */
.admin-ai-prompt-builder {
    margin-top: var(--space-lg);
    padding: var(--space-lg);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--admin-border);
}

.admin-ai-prompt-builder__title {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0 0 var(--space-md) 0;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-bold);
}

.admin-ai-example-selector {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
    flex-wrap: wrap;
}

.admin-ai-example-selector__label {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    cursor: pointer;
    font-size: var(--font-size-sm);
}

.admin-ai-example-selector__label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--admin-accent);
}

.admin-ai-example-selector .admin-select {
    flex: 1;
    min-width: 200px;
    max-width: 400px;
}

.admin-ai-goal-area {
    margin-top: var(--space-sm);
}

.admin-ai-goal-area .admin-textarea {
    width: 100%;
    min-height: 150px;
    font-size: var(--font-size-sm);
    line-height: 1.6;
}

.admin-ai-goal-hints {
    margin-top: var(--space-sm);
}

.admin-ai-goal-hints .admin-hint {
    font-size: var(--font-size-xs);
}

/* Spec Actions */
.admin-ai-spec-actions {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    margin-top: var(--space-lg);
}

.admin-btn--ghost {
    background: transparent;
    border: 1px solid var(--admin-border);
    color: var(--admin-text-muted);
}

.admin-btn--ghost:hover {
    background: var(--admin-surface);
    color: var(--admin-text);
}

/* Workflow Step Badges */
.admin-ai-workflow-step {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-xl);
    margin-bottom: var(--space-sm);
}

.admin-ai-workflow-step:first-of-type {
    margin-top: 0;
}

.admin-ai-workflow-step--large {
    margin-top: var(--space-xxl);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--admin-border);
}

.admin-ai-step-badge {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--admin-accent);
    color: #000;
    border-radius: 50%;
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-sm);
    flex-shrink: 0;
}

.admin-ai-step-label {
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-base);
    color: var(--admin-text);
}

/* Import Section */
.admin-ai-import-section {
    margin-top: var(--space-md);
    padding: var(--space-lg);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--admin-border);
}

.admin-ai-import-hint {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0 0 var(--space-md) 0;
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

.admin-ai-import-area {
    margin-bottom: var(--space-md);
}

.admin-ai-import-area .admin-textarea {
    width: 100%;
    font-size: var(--font-size-xs);
}

.admin-ai-import-status {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
}

.admin-ai-import-status--valid {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
}

.admin-ai-import-status--invalid {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

.admin-ai-import-actions {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    align-items: center;
}

.admin-btn--success {
    background: #22c55e;
    color: #000;
    border: none;
}

.admin-btn--success:hover:not(:disabled) {
    background: #16a34a;
}

.admin-btn--success:disabled {
    background: var(--admin-border);
    color: var(--admin-text-muted);
    cursor: not-allowed;
    opacity: 0.6;
}

/* Execution Results */
.admin-ai-execution-results {
    margin-top: var(--space-lg);
    padding: var(--space-md);
    background: var(--admin-bg);
    border-radius: var(--radius-lg);
    border: 1px solid var(--admin-border);
}

.admin-ai-execution-results--success {
    border-color: #22c55e;
}

.admin-ai-execution-results--error {
    border-color: #ef4444;
}

.admin-ai-execution-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-md);
}

.admin-ai-execution-title {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0;
    font-size: var(--font-size-base);
    color: #22c55e;
}

.admin-ai-execution-results--error .admin-ai-execution-title {
    color: #ef4444;
}

.admin-ai-execution-summary {
    display: flex;
    gap: var(--space-lg);
    margin-bottom: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
}

.admin-ai-execution-summary__item {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}

.admin-ai-execution-summary__item--success {
    color: #22c55e;
}

.admin-ai-execution-summary__item--error {
    color: #ef4444;
}

.admin-ai-execution-details {
    max-height: 200px;
    overflow-y: auto;
    font-size: var(--font-size-xs);
    font-family: var(--font-mono);
}

.admin-ai-execution-details__item {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-xs) 0;
    border-bottom: 1px solid var(--admin-border);
}

.admin-ai-execution-details__item:last-child {
    border-bottom: none;
}

.admin-ai-execution-details__status {
    flex-shrink: 0;
    font-size: var(--font-size-sm);
}

.admin-ai-execution-details__command {
    flex: 1;
    word-break: break-all;
}
</style>

<script>
// Current state
let currentSpec = null;
let currentTag = 'all';
let searchQuery = '';
let specsCache = {};
let commandsData = null;

// AI Specs Definition - organized by section with tags
const aiSpecs = {
    // Fresh Start specs - for blank projects
    fresh: [
        {
            id: 'create-landing',
            icon: '🚀',
            title: 'Create Landing Page',
            desc: 'Build a single-page landing site from scratch. Perfect for product launches, events, or showcases.',
            tags: ['landing', 'business', 'creative'],
            examples: {
                'landing-saas': {
                    title: '🎯 SaaS Product Launch',
                    text: `Create a modern SaaS landing page in English with: a hero section featuring the product tagline, 3 feature cards highlighting key benefits, a pricing section with 3 tiers (Free, Pro, Enterprise), a testimonials section with 3 customer quotes, and a final call-to-action. Use a professional blue/purple gradient theme with clean typography.`
                },
                'landing-event': {
                    title: '🎪 Event / Conference',
                    text: `Create a bilingual (English and French) conference landing page with: event title and date in the hero, speaker showcase section with 4 speaker cards, event schedule/agenda section, venue information with address, sponsors section, and registration call-to-action button. Professional corporate style with dark blue and gold accents.`
                },
                'landing-app': {
                    title: '📱 Mobile App Launch',
                    text: `Create an app launch landing page in English with: hero section showcasing the app with placeholder for screenshot, feature highlights section with 4 key features and icons, how it works section with 3 steps, app store download buttons (iOS/Android), and FAQ section with 5 common questions. Dark theme with vibrant gradient accents (purple to pink).`
                },
                'landing-coming-soon': {
                    title: '⏳ Coming Soon',
                    text: `Create a simple coming soon landing page in English with: centered hero with product name and "launching soon" tagline, brief description of what's coming, email signup call-to-action placeholder, and social media links in footer. Minimal dark theme with a single accent color.`
                }
            }
        },
        {
            id: 'create-website',
            icon: '🌐',
            title: 'Create Website',
            desc: 'Build a multi-page website from scratch. Ideal for business sites, portfolios, or blogs.',
            tags: ['website', 'business', 'multilang'],
            examples: {
                'website-business': {
                    title: '🏢 Business / Agency',
                    text: `Create a professional business website in English with pages: Home (hero with company tagline, 3 service highlights, client logos section), About (company story, mission, team section with 4 members), Services (detailed service cards), and Contact (contact info, location, simple contact form placeholder). Dark corporate theme with gold accents.`
                },
                'website-portfolio': {
                    title: '📸 Portfolio / Creative',
                    text: `Create a photographer/designer portfolio website in English with pages: Home (full-width hero image placeholder, brief intro, featured works grid), Gallery (masonry-style image grid with categories), About (personal story, skills, awards), and Contact (email, social links). Minimal black and white theme, focus on imagery with lots of whitespace.`
                },
                'website-restaurant': {
                    title: '🍽️ Restaurant / Café',
                    text: `Create a restaurant website in English and French with pages: Home (hero with food image placeholder, welcome message, opening hours, reservation CTA), Menu (organized by categories: starters, mains, desserts, drinks with prices), About (restaurant story, chef bio), and Contact (address, phone, opening hours, map placeholder). Warm elegant theme with burgundy and cream colors.`
                },
                'website-bookstore': {
                    title: '📚 Book Seller',
                    text: `I want a 3-language website (English, French, and Spanish) that will showcase my books. Pages needed:

• Home: summarizes my passion for books and provides a site overview for visitors
• About: explains that I've loved reading since age 5 and never stopped
• Blog: a section for future articles and book reviews
• Books: presents the 3 books I currently sell with titles and summaries:
  - "Ideavidual": exploring the concept of letting ideas be free, not owning them, understanding that ideas don't define the people who share them
  - "Mathematical Thoughts, a 42nd Work": mathematical treasures I want to share with readers
  - "Alice Weils": how a character's name brings them to life until people see the character, not the actor

Warm, literary theme with earthy tones.`
                },
                'website-nonprofit': {
                    title: '💚 Non-Profit / NGO',
                    text: `Create a non-profit organization website in English with pages: Home (hero with mission statement, impact stats section with 4 numbers, featured programs), About (organization history, values, team), Programs (3 main programs with descriptions and impact), Get Involved (volunteer opportunities, donation CTA), and Contact. Warm green and earth tones theme conveying trust and community.`
                }
            }
        }
    ],
    // Early Stage specs - basic structure exists
    early: [
        {
            id: 'add-page',
            icon: '📄',
            title: 'Add New Page',
            desc: 'Add a new page to your existing site with full structure and content.',
            tags: ['website', 'creative'],
            examples: {
                'add-blog': {
                    title: '📝 Blog Page',
                    text: `Add a blog page to my existing website with: a header showing "Blog" title, a grid of article preview cards (title, excerpt, date, read more link), and categories sidebar. Match existing site style.`
                },
                'add-contact': {
                    title: '📬 Contact Page',
                    text: `Add a contact page with: contact form placeholder (name, email, message fields), company address and phone, embedded map placeholder, and social media links. Keep existing site theme.`
                }
            }
        },
        {
            id: 'add-section',
            icon: '🧱',
            title: 'Add Section',
            desc: 'Add a new section to an existing page with proper styling.',
            tags: ['landing', 'website', 'creative'],
            examples: {
                'add-testimonials': {
                    title: '⭐ Testimonials Section',
                    text: `Add a testimonials section to my home page with: 3 customer testimonial cards (quote, name, role, company), a subtle background, and professional styling matching my site theme.`
                },
                'add-pricing': {
                    title: '💰 Pricing Section',
                    text: `Add a pricing section with 3 tiers: Basic, Pro, Enterprise. Each shows price, feature list, and CTA button. Highlight the Pro tier as recommended. Match my existing site colors.`
                }
            }
        }
    ],
    // Work In Progress specs - enhance existing project
    wip: [
        {
            id: 'add-language',
            icon: '🌍',
            title: 'Add Language',
            desc: 'Add a new language to your multilingual site with all translations.',
            tags: ['multilang', 'website'],
            examples: {
                'add-spanish': {
                    title: '🇪🇸 Add Spanish',
                    text: `Add Spanish (es) to my existing English/French website. Translate all existing content maintaining the same tone and style. Update language switcher.`
                },
                'add-german': {
                    title: '🇩🇪 Add German',
                    text: `Add German (de) language support to my website. Provide accurate translations for all pages and update navigation to include language selector.`
                }
            }
        },
        {
            id: 'restyle',
            icon: '🎨',
            title: 'Restyle Site',
            desc: 'Update the visual style of your site while keeping content intact.',
            tags: ['creative', 'landing', 'website'],
            examples: {
                'restyle-modern': {
                    title: '✨ Modern Refresh',
                    text: `Update my site's visual style to a modern look: clean sans-serif fonts, increased whitespace, subtle shadows, rounded corners, and a fresh color palette (suggest colors based on current content).`
                },
                'restyle-dark': {
                    title: '🌙 Dark Theme',
                    text: `Convert my site to a dark theme: dark backgrounds (#1a1a2e), light text, accent color highlights, adjust contrast for readability, update all component colors accordingly.`
                }
            }
        },
        {
            id: 'improve-seo',
            icon: '🔍',
            title: 'Improve SEO',
            desc: 'Enhance meta tags, titles, and content structure for better search visibility.',
            tags: ['business', 'website'],
            examples: {
                'seo-basic': {
                    title: '📈 Basic SEO Setup',
                    text: `Set up SEO for my site: create unique meta titles and descriptions for each page, add proper heading hierarchy, ensure all pages have descriptive URLs.`
                }
            }
        }
    ]
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', async function() {
    // Load commands data
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET');
        if (result.ok && result.data.data) {
            commandsData = result.data.data;
        }
    } catch (e) {
        console.error('Failed to load commands:', e);
    }
    
    // Render spec cards
    renderSpecs();
});

// Render all spec cards
function renderSpecs() {
    for (const [section, specs] of Object.entries(aiSpecs)) {
        const container = document.getElementById(`specs-${section}`);
        if (!container) continue;
        
        container.innerHTML = '';
        
        const filteredSpecs = specs.filter(spec => matchesFilter(spec));
        
        filteredSpecs.forEach(spec => {
            const card = createSpecCard(spec);
            container.appendChild(card);
        });
        
        // Show/hide section based on whether it has visible specs
        const sectionEl = container.closest('.admin-ai-section');
        if (sectionEl) {
            sectionEl.dataset.visible = filteredSpecs.length > 0 ? 'true' : 'false';
        }
    }
}

// Check if spec matches current filters
function matchesFilter(spec) {
    // Tag filter
    if (currentTag !== 'all' && !spec.tags.includes(currentTag)) {
        return false;
    }
    
    // Search filter
    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        const matchesTitle = spec.title.toLowerCase().includes(q);
        const matchesDesc = spec.desc.toLowerCase().includes(q);
        const matchesTags = spec.tags.some(t => t.toLowerCase().includes(q));
        if (!matchesTitle && !matchesDesc && !matchesTags) {
            return false;
        }
    }
    
    return true;
}

// Create a spec card element
function createSpecCard(spec) {
    const card = document.createElement('div');
    card.className = 'admin-ai-spec-card';
    card.dataset.specId = spec.id;
    card.onclick = () => selectSpec(spec);
    
    card.innerHTML = `
        <div class="admin-ai-spec-card__header">
            <span class="admin-ai-spec-card__icon">${spec.icon}</span>
            <span class="admin-ai-spec-card__title">${spec.title}</span>
        </div>
        <p class="admin-ai-spec-card__desc">${spec.desc}</p>
        <div class="admin-ai-spec-card__tags">
            ${spec.tags.map(tag => `<span class="admin-ai-spec-card__tag">${tag}</span>`).join('')}
        </div>
    `;
    
    return card;
}

// Filter by tag
function filterByTag(tag) {
    currentTag = tag;
    
    // Update active tag
    document.querySelectorAll('.admin-ai-tag').forEach(el => {
        el.classList.toggle('admin-ai-tag--active', el.dataset.tag === tag);
    });
    
    renderSpecs();
}

// Filter by search
function filterSpecs() {
    searchQuery = document.getElementById('spec-search').value;
    renderSpecs();
}

// Select a spec and show the panel
function selectSpec(spec) {
    currentSpec = spec;
    
    // Hide specs container
    document.getElementById('specs-container').style.display = 'none';
    document.querySelector('.admin-ai-filter-bar').style.display = 'none';
    
    // Update selected spec panel
    document.getElementById('selected-spec-icon').textContent = spec.icon;
    document.getElementById('selected-spec-name').textContent = spec.title;
    document.getElementById('selected-spec-description').textContent = spec.desc;
    
    // Show panel
    document.getElementById('selected-spec-panel').style.display = 'block';
    
    // Update example selector
    updateExampleSelector();
    
    // Clear previous input
    document.getElementById('user-goal').value = '';
    document.getElementById('example-prompt-select').value = '';
}

// Deselect spec and go back
function deselectSpec() {
    currentSpec = null;
    
    // Hide panel
    document.getElementById('selected-spec-panel').style.display = 'none';
    document.getElementById('spec-preview').style.display = 'none';
    
    // Show specs container
    document.getElementById('specs-container').style.display = 'flex';
    document.querySelector('.admin-ai-filter-bar').style.display = 'flex';
}

// Update example selector based on current spec
function updateExampleSelector() {
    const select = document.getElementById('example-prompt-select');
    select.innerHTML = '<option value="">Write your own...</option>';
    
    if (!currentSpec || !currentSpec.examples) return;
    
    for (const [id, example] of Object.entries(currentSpec.examples)) {
        const option = document.createElement('option');
        option.value = id;
        option.textContent = example.title;
        select.appendChild(option);
    }
}

// Load selected example prompt
function loadExamplePrompt() {
    const select = document.getElementById('example-prompt-select');
    const textarea = document.getElementById('user-goal');
    const exampleId = select.value;
    
    if (exampleId && currentSpec && currentSpec.examples && currentSpec.examples[exampleId]) {
        textarea.value = currentSpec.examples[exampleId].text;
    } else {
        textarea.value = '';
        textarea.focus();
    }
}

function toggleSpecPreview() {
    const preview = document.getElementById('spec-preview');
    const isHidden = preview.style.display === 'none';
    
    if (isHidden) {
        preview.style.display = 'block';
        // Generate and show spec only (no user goal)
        generateSpec(currentSpec);
    } else {
        preview.style.display = 'none';
    }
}

function previewFullPrompt() {
    if (!currentSpec) {
        QuickSiteAdmin.showToast('Select a spec first', 'warning');
        return;
    }
    
    if (!commandsData) {
        QuickSiteAdmin.showToast('Commands data still loading, please wait...', 'warning');
        return;
    }
    
    const preview = document.getElementById('spec-preview');
    preview.style.display = 'block';
    
    // Generate spec if not cached
    if (!specsCache[currentSpec.id]) {
        generateSpec(currentSpec);
    }
    
    const spec = specsCache[currentSpec.id];
    const userGoal = document.getElementById('user-goal')?.value?.trim() || '';
    
    if (!spec) {
        QuickSiteAdmin.showToast('Specification not ready', 'warning');
        return;
    }
    
    let fullContent = spec;
    
    if (userGoal) {
        fullContent += '\n\n---\n\n## My Request\n\n' + userGoal;
    }
    
    document.getElementById('ai-spec-content').value = fullContent;
    
    // Update stats
    const charCount = fullContent.length;
    const wordCount = fullContent.split(/\s+/).length;
    let statsHtml = `
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Characters:</span>
            <span class="admin-ai-spec-stat__value">${charCount.toLocaleString()}</span>
        </div>
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Words:</span>
            <span class="admin-ai-spec-stat__value">${wordCount.toLocaleString()}</span>
        </div>
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Est. tokens:</span>
            <span class="admin-ai-spec-stat__value">~${Math.ceil(wordCount * 1.3).toLocaleString()}</span>
        </div>
    `;
    
    if (userGoal) {
        statsHtml += `
            <div class="admin-ai-spec-stat" style="color: var(--admin-success);">
                <span class="admin-ai-spec-stat__label">✓</span>
                <span class="admin-ai-spec-stat__value">Includes your request</span>
            </div>
        `;
    } else {
        statsHtml += `
            <div class="admin-ai-spec-stat" style="color: var(--admin-warning);">
                <span class="admin-ai-spec-stat__label">⚠</span>
                <span class="admin-ai-spec-stat__value">No user request added</span>
            </div>
        `;
    }
    
    document.getElementById('ai-spec-stats').innerHTML = statsHtml;
}

function generateSpec(spec) {
    if (!commandsData || !spec) return null;
    
    const specId = spec.id;
    
    // Check cache
    if (specsCache[specId]) {
        displaySpec(specsCache[specId]);
        return specsCache[specId];
    }
    
    let specContent = '';
    
    switch (specId) {
        case 'create-landing':
            specContent = generateCreateLandingSpec();
            break;
        case 'create-website':
            specContent = generateCreateWebsiteSpec();
            break;
        default:
            // For other spec types, generate a generic spec
            specContent = generateGenericSpec(spec);
            break;
    }
    
    specsCache[specId] = specContent;
    displaySpec(specContent);
    return specContent;
}

function displaySpec(specContent) {
    const content = document.getElementById('ai-spec-content');
    const stats = document.getElementById('ai-spec-stats');
    
    content.value = specContent;
    
    const charCount = specContent.length;
    const wordCount = specContent.split(/\s+/).length;
    
    stats.innerHTML = `
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Characters:</span>
            <span class="admin-ai-spec-stat__value">${charCount.toLocaleString()}</span>
        </div>
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Words:</span>
            <span class="admin-ai-spec-stat__value">${wordCount.toLocaleString()}</span>
        </div>
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Est. tokens:</span>
            <span class="admin-ai-spec-stat__value">~${Math.ceil(wordCount * 1.3).toLocaleString()}</span>
        </div>
    `;
}

function generateCreateLandingSpec() {
    const commands = commandsData.commands || {};
    const creationCommands = ['setMultilingual', 'addLang', 'editStructure', 'setTranslationKeys', 'editStyles', 'setRootVariables'];
    
    return `# QuickSite Create Landing Page Specification

You are creating a single-page landing from a blank QuickSite project. Generate a JSON command sequence.

## Output Format
\`\`\`json
[
  { "command": "commandName", "params": { "key": "value" } }
]
\`\`\`

---

## CRITICAL: Command Order Rules

**⚠️ MANDATORY ORDER:**

1. **Multilingual FIRST** (if needed):
   - \`setMultilingual\` → enables multi-language mode
   - \`addLang\` → for each additional language (default is "en")

2. **Structures** (in this order):
   - \`editStructure\` type="menu" → navigation (can be empty)
   - \`editStructure\` type="footer" → footer (can be empty)
   - \`editStructure\` type="page", name="home" → landing page content

3. **Translations**:
   - \`setTranslationKeys\` → MUST cover ALL textKeys used in structures
   - Note: \`page.titles.home\` should be set for the page title

4. **Styles LAST** (order matters!):
   - \`editStyles\` → MUST come BEFORE setRootVariables
   - \`setRootVariables\` → AFTER editStyles (optional helper)

**Why order matters:** editStyles can reset CSS variables, so setRootVariables must apply after.

---

## Core Concept: Structure → Translation

ALL text must use \`textKey\` references, never hardcode:

\`\`\`json
{ "tag": "h1", "children": [{ "textKey": "home.title" }] }
\`\`\`

**Naming convention for textKeys:**
- \`menu.*\` → navigation (menu.logo, menu.features, menu.contact)
- \`footer.*\` → footer (footer.copyright, footer.links)
- \`home.*\` → page content (home.title, home.subtitle, home.cta)
- \`page.titles.home\` → page <title>

**⚠️ NEVER hardcode text. Always use textKey.**

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| \`menu\` | Navigation (optional) | type + structure |
| \`footer\` | Footer (optional) | type + structure |  
| \`page\` | Page content | type + **name** + structure |
| \`component\` | Reusable template | type + **name** + structure |

**Important:** For \`page\` and \`component\`, the \`name\` parameter is REQUIRED.
- For \`page\`: name = route name ("home" exists by default)
- For \`component\`: name = component identifier (alphanumeric, hyphens, underscores)

## Components (Reusable Templates)

Variables use \`{{varName}}\` syntax in component definition:

\`\`\`json
{
  "command": "editStructure",
  "params": {
    "type": "component",
    "name": "feature-card",
    "structure": {
      "tag": "div",
      "params": { "class": "card" },
      "children": [
        { "tag": "h3", "children": [{ "textKey": "{{titleKey}}" }] },
        { "tag": "p", "children": [{ "textKey": "{{descKey}}" }] }
      ]
    }
  }
}
\`\`\`

Call with \`component\` and \`data\`:
\`\`\`json
{
  "component": "feature-card",
  "data": { "titleKey": "features.card1.title", "descKey": "features.card1.desc" }
}
\`\`\`

**Note:** Nested components require all variables passed from the outer call (global scope).

---

## Special Syntax

### Raw HTML
Prefix with \`__RAW__\` for HTML entities:
\`\`\`json
{ "textKey": "__RAW__footer.copyright" }
\`\`\`
Translation: \`"copyright": "&copy; 2025 Company"\`

### Language Switcher (Multilingual)
\`\`\`json
{ "tag": "a", "params": { "href": "{{__current_page;lang=fr}}" }, "children": [{ "textKey": "lang.french" }] }
\`\`\`

---

## Available Commands

${creationCommands.map(cmd => commands[cmd] ? formatCommandDetailed(cmd, commands[cmd]) : '').filter(Boolean).join('\n---\n')}

---

## Minimal Landing Page Example

\`\`\`json
[
  {
    "command": "editStructure",
    "params": {
      "type": "menu",
      "structure": [
        {
          "tag": "nav",
          "children": [
            { "tag": "a", "params": { "href": "/" }, "children": [{ "textKey": "menu.logo" }] },
            { "tag": "a", "params": { "href": "#features" }, "children": [{ "textKey": "menu.features" }] },
            { "tag": "a", "params": { "href": "#contact" }, "children": [{ "textKey": "menu.contact" }] }
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "footer",
      "structure": [
        { "tag": "footer", "children": [{ "tag": "p", "children": [{ "textKey": "__RAW__footer.copyright" }] }] }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "home",
      "structure": [
        {
          "tag": "section",
          "params": { "id": "hero" },
          "children": [
            { "tag": "h1", "children": [{ "textKey": "home.title" }] },
            { "tag": "p", "children": [{ "textKey": "home.subtitle" }] },
            { "tag": "a", "params": { "href": "#contact", "class": "btn" }, "children": [{ "textKey": "home.cta" }] }
          ]
        },
        {
          "tag": "section",
          "params": { "id": "features" },
          "children": [
            { "tag": "h2", "children": [{ "textKey": "features.title" }] },
            {
              "tag": "div",
              "params": { "class": "grid" },
              "children": [
                { "tag": "div", "children": [{ "tag": "h3", "children": [{ "textKey": "features.item1.title" }] }, { "tag": "p", "children": [{ "textKey": "features.item1.desc" }] }] },
                { "tag": "div", "children": [{ "tag": "h3", "children": [{ "textKey": "features.item2.title" }] }, { "tag": "p", "children": [{ "textKey": "features.item2.desc" }] }] }
              ]
            }
          ]
        }
      ]
    }
  },
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "en",
      "translations": {
        "page": { "titles": { "home": "My Landing Page" } },
        "menu": { "logo": "Brand", "features": "Features", "contact": "Contact" },
        "home": { "title": "Welcome", "subtitle": "Your tagline here", "cta": "Get Started" },
        "features": {
          "title": "Features",
          "item1": { "title": "Feature 1", "desc": "Description..." },
          "item2": { "title": "Feature 2", "desc": "Description..." }
        },
        "footer": { "copyright": "&copy; 2025 Brand" }
      }
    }
  },
  {
    "command": "editStyles",
    "params": {
      "css": ":root { --color-primary: #6366f1; --color-text: #333; }\\nbody { font-family: sans-serif; color: var(--color-text); }\\nnav { display: flex; gap: 1rem; padding: 1rem; }\\nsection { padding: 3rem 2rem; }\\n.btn { display: inline-block; padding: 0.75rem 1.5rem; background: var(--color-primary); color: white; text-decoration: none; border-radius: 4px; }\\n.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }\\nfooter { text-align: center; padding: 2rem; }"
    }
  }
]
\`\`\`

---

Create a landing page based on the user's requirements. Ensure ALL textKeys have matching translations.`;
}

function generateGenericSpec(spec) {
    const commands = commandsData.commands || {};
    
    // Determine relevant commands based on spec tags/type
    let relevantCommands = [];
    
    if (spec.tags.includes('multilang')) {
        relevantCommands.push('setMultilingual', 'addLang');
    }
    if (spec.id.includes('page') || spec.id.includes('section')) {
        relevantCommands.push('editStructure', 'setTranslationKeys');
    }
    if (spec.id.includes('style') || spec.id.includes('restyle')) {
        relevantCommands.push('editStyles', 'setRootVariables');
    }
    if (spec.id.includes('language')) {
        relevantCommands.push('addLang', 'setTranslationKeys');
    }
    if (spec.id.includes('seo')) {
        relevantCommands.push('editStructure', 'setTranslationKeys');
    }
    
    // Default fallback
    if (relevantCommands.length === 0) {
        relevantCommands = ['editStructure', 'setTranslationKeys', 'editStyles'];
    }
    
    return `# QuickSite ${spec.title} Specification

${spec.desc}

## Output Format
\`\`\`json
[
  { "command": "commandName", "params": { "key": "value" } }
]
\`\`\`

---

## Available Commands

${relevantCommands.map(cmd => commands[cmd] ? formatCommandDetailed(cmd, commands[cmd]) : '').filter(Boolean).join('\n---\n')}

---

Complete the task based on the user's requirements. Use only the commands shown above.`;
}

function generateCreateWebsiteSpec() {
    const commands = commandsData.commands || {};
    const creationCommands = ['setMultilingual', 'addLang', 'addRoute', 'editStructure', 'setTranslationKeys', 'editStyles', 'setRootVariables'];
    
    return `# QuickSite Create Website Specification

You are creating a complete multi-page website from a blank QuickSite project. Generate a JSON command sequence.

## Output Format
\`\`\`json
[
  { "command": "commandName", "params": { "key": "value" } }
]
\`\`\`

---

## CRITICAL: Command Order Rules

**⚠️ MANDATORY ORDER:**

1. **Multilingual FIRST** (if needed):
   - \`setMultilingual\` → enables multi-language mode
   - \`addLang\` → for each additional language (default is "en")

2. **Routes**:
   - \`addRoute\` → creates new pages
   - Note: "home" route exists by default (accessible as "/" or "/home")

3. **Structures** (in this order):
   - \`editStructure\` type="menu" → navigation (shared, can be empty)
   - \`editStructure\` type="footer" → footer (shared, can be empty)
   - \`editStructure\` type="page", name="$routeName" → each page content (route must exist)

4. **Translations**:
   - \`setTranslationKeys\` → MUST cover ALL textKeys used in structures
   - Note: \`addRoute\` auto-creates \`page.titles.$routeName\` - set this too!

5. **Styles LAST** (order matters!):
   - \`editStyles\` → MUST come BEFORE setRootVariables
   - \`setRootVariables\` → AFTER editStyles (optional helper)

**Why order matters:** editStyles can reset CSS variables, so setRootVariables must apply after.

---

## Core Concept: Structure → Translation

This system separates **structure** (HTML) from **content** (text). ALL text must use \`textKey\` references:

\`\`\`json
{ "tag": "h1", "children": [{ "textKey": "home.title" }] }
\`\`\`

**Naming convention for textKeys:**
- \`menu.*\` → navigation items (menu.home, menu.about, menu.contact)
- \`footer.*\` → footer content (footer.copyright, footer.tagline)
- \`$routeName.*\` → page content (home.title, about.intro, services.card1.title)
- \`page.titles.$routeName\` → page <title> tag (auto-created by addRoute)

**⚠️ NEVER hardcode text. Always use textKey.**

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| \`menu\` | Navigation (all pages) | type + structure |
| \`footer\` | Footer (all pages) | type + structure |
| \`page\` | Page content | type + **name** + structure |
| \`component\` | Reusable template | type + **name** + structure |

**Important:** For \`page\` and \`component\`, the \`name\` parameter is REQUIRED.
- For \`page\`: name = route name (must exist via addRoute or be "home")
- For \`component\`: name = component identifier (alphanumeric, hyphens, underscores)

Menu and footer can be empty arrays \`[]\` if not needed.

---

## Components (Reusable Templates)

Components are reusable structure fragments with variable placeholders.

### Creating a Component

Variables use \`{{varName}}\` syntax:

\`\`\`json
{
  "command": "editStructure",
  "params": {
    "type": "component",
    "name": "link-button",
    "structure": {
      "tag": "a",
      "params": { 
        "href": "{{href}}", 
        "class": "btn {{btnClass}}" 
      },
      "children": [{ "textKey": "{{labelKey}}" }]
    }
  }
}
\`\`\`

### Using a Component

Call with \`component\` and pass values via \`data\`:

\`\`\`json
{
  "component": "link-button",
  "data": {
    "href": "/contact",
    "btnClass": "btn-primary",
    "labelKey": "home.cta"
  }
}
\`\`\`

### Nested Components

Components can contain other components. **Important:** Variables are NOT scoped - all variables must be passed from the top-level \`data\`:

\`\`\`json
{
  "component": "card-with-image",
  "data": {
    "title": "services.card1.title",
    "imgSrc": "/assets/images/service1.jpg",
    "imgAlt": "Service 1"
  }
}
\`\`\`

If \`card-with-image\` internally uses \`img-dynamic\` component, you must pass imgSrc and imgAlt in the outer call.

---

## Special Syntax

### Raw HTML (no escaping)
Prefix textKey with \`__RAW__\` for HTML entities:
\`\`\`json
{ "textKey": "__RAW__footer.copyright" }
\`\`\`
Translation: \`"copyright": "&copy; 2025 Company Name"\`

### Language Switcher URLs
Use \`{{__current_page;lang=xx}}\` for dynamic language links:
\`\`\`json
{
  "tag": "a",
  "params": { "href": "{{__current_page;lang=en}}" },
  "children": [{ "textKey": "lang.english" }]
}
\`\`\`

---

## Available Commands

${creationCommands.map(cmd => commands[cmd] ? formatCommandDetailed(cmd, commands[cmd]) : '').filter(Boolean).join('\n---\n')}

---

## Structure Example

\`\`\`json
{
  "command": "editStructure",
  "params": {
    "type": "page",
    "name": "about",
    "structure": [
      {
        "tag": "section",
        "params": { "class": "page-header" },
        "children": [
          { "tag": "h1", "children": [{ "textKey": "about.title" }] },
          { "tag": "p", "children": [{ "textKey": "about.intro" }] }
        ]
      },
      {
        "tag": "section",
        "params": { "class": "content" },
        "children": [
          { "tag": "h2", "children": [{ "textKey": "about.mission.title" }] },
          { "tag": "p", "children": [{ "textKey": "about.mission.text" }] }
        ]
      }
    ]
  }
}
\`\`\`

---

## Translation Example

\`\`\`json
{
  "command": "setTranslationKeys",
  "params": {
    "language": "en",
    "translations": {
      "page": {
        "titles": {
          "home": "Home | Company Name",
          "about": "About Us | Company Name",
          "contact": "Contact | Company Name"
        }
      },
      "menu": {
        "home": "Home",
        "about": "About",
        "contact": "Contact"
      },
      "home": {
        "title": "Welcome",
        "subtitle": "Your tagline here",
        "cta": "Learn More"
      },
      "about": {
        "title": "About Us",
        "intro": "Our story...",
        "mission": {
          "title": "Our Mission",
          "text": "What we do..."
        }
      },
      "footer": {
        "copyright": "&copy; 2025 Company Name"
      }
    }
  }
}
\`\`\`

---

## CSS & Variables

### editStyles
Write CSS that uses variables for theming:
\`\`\`css
.btn {
  background: var(--color-primary);
  color: var(--color-text-inverse);
  border-radius: var(--border-radius);
}
\`\`\`

### setRootVariables (Optional)
Helper to set/override CSS custom properties:
\`\`\`json
{
  "command": "setRootVariables",
  "params": {
    "variables": {
      "--color-primary": "#1e3a5f",
      "--color-text": "#333",
      "--border-radius": "8px"
    }
  }
}
\`\`\`

You can also define variables directly in editStyles - setRootVariables is just a convenience.

---

## Minimal Complete Example

\`\`\`json
[
  { "command": "addRoute", "params": { "name": "about" } },
  { "command": "addRoute", "params": { "name": "contact" } },
  {
    "command": "editStructure",
    "params": {
      "type": "menu",
      "structure": [
        {
          "tag": "nav",
          "children": [
            { "tag": "a", "params": { "href": "/" }, "children": [{ "textKey": "menu.home" }] },
            { "tag": "a", "params": { "href": "/about" }, "children": [{ "textKey": "menu.about" }] },
            { "tag": "a", "params": { "href": "/contact" }, "children": [{ "textKey": "menu.contact" }] }
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "footer",
      "structure": [
        { "tag": "footer", "children": [{ "tag": "p", "children": [{ "textKey": "__RAW__footer.copyright" }] }] }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "home",
      "structure": [
        { "tag": "h1", "children": [{ "textKey": "home.title" }] },
        { "tag": "p", "children": [{ "textKey": "home.subtitle" }] }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "about",
      "structure": [
        { "tag": "h1", "children": [{ "textKey": "about.title" }] },
        { "tag": "p", "children": [{ "textKey": "about.content" }] }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "contact",
      "structure": [
        { "tag": "h1", "children": [{ "textKey": "contact.title" }] },
        { "tag": "p", "children": [{ "textKey": "contact.intro" }] }
      ]
    }
  },
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "en",
      "translations": {
        "page": { "titles": { "home": "Home", "about": "About", "contact": "Contact" } },
        "menu": { "home": "Home", "about": "About", "contact": "Contact" },
        "home": { "title": "Welcome", "subtitle": "Your website tagline" },
        "about": { "title": "About Us", "content": "Our story..." },
        "contact": { "title": "Contact Us", "intro": "Get in touch" },
        "footer": { "copyright": "&copy; 2025 Company" }
      }
    }
  },
  {
    "command": "editStyles",
    "params": {
      "css": ":root { --color-primary: #1e3a5f; --color-text: #333; }\\nbody { font-family: sans-serif; color: var(--color-text); }\\nnav { display: flex; gap: 1rem; padding: 1rem; }\\nfooter { text-align: center; padding: 2rem; background: var(--color-primary); color: white; }"
    }
  }
]
\`\`\`

---

Create a complete website based on the user's requirements. Ensure ALL textKeys in structures have corresponding translations.`;
}

function formatCommandDetailed(name, cmd) {
    let text = `### ${name}\n`;
    text += `**Method:** \`${cmd.method || 'GET'}\`\n\n`;
    text += `**Description:** ${cmd.description || 'No description'}\n\n`;
    
    if (cmd.parameters && Object.keys(cmd.parameters).length > 0) {
        text += `**Parameters:**\n`;
        for (const [pName, pData] of Object.entries(cmd.parameters)) {
            const required = pData.required ? ' *(required)*' : ' *(optional)*';
            const type = pData.type ? ` \`${pData.type}\`` : '';
            text += `- \`${pName}\`${type}${required}: ${pData.description || 'No description'}\n`;
        }
        text += '\n';
    }
    
    return text;
}

function formatCommandBrief(name, cmd) {
    let text = `**${name}** (\`${cmd.method || 'GET'}\`): ${cmd.description || 'No description'}\n`;
    
    if (cmd.parameters && Object.keys(cmd.parameters).length > 0) {
        const params = Object.entries(cmd.parameters)
            .map(([pName, pData]) => `\`${pName}\`${pData.required ? '*' : ''}`)
            .join(', ');
        text += `  Params: ${params}\n`;
    }
    
    return text;
}

async function copyFullPrompt() {
    console.log('copyFullPrompt called, currentSpec:', currentSpec, 'commandsData:', !!commandsData);
    
    if (!currentSpec) {
        QuickSiteAdmin.showToast('Select a spec first', 'warning');
        return;
    }
    
    if (!commandsData) {
        QuickSiteAdmin.showToast('Commands data still loading, please wait...', 'warning');
        return;
    }
    
    // Generate spec if not cached
    if (!specsCache[currentSpec.id]) {
        console.log('Generating spec for:', currentSpec.id);
        generateSpec(currentSpec);
    }
    
    const spec = specsCache[currentSpec.id];
    const userGoal = document.getElementById('user-goal')?.value?.trim() || '';
    
    console.log('spec exists:', !!spec, 'userGoal:', userGoal);
    
    if (!spec) {
        QuickSiteAdmin.showToast('Specification not ready. Try clicking Preview Spec first.', 'warning');
        return;
    }
    
    let fullPrompt = spec;
    
    if (userGoal) {
        fullPrompt += '\n\n---\n\n## My Request\n\n' + userGoal;
    } else {
        QuickSiteAdmin.showToast('Add a description of what you want to create!', 'warning');
        document.getElementById('user-goal')?.focus();
        return;
    }
    
    console.log('Attempting to copy, length:', fullPrompt.length);
    
    try {
        await navigator.clipboard.writeText(fullPrompt);
        QuickSiteAdmin.showToast('Full prompt copied! Paste in your AI chat.', 'success');
    } catch (e) {
        console.error('Clipboard API failed:', e);
        // Fallback
        const textarea = document.getElementById('ai-spec-content');
        if (textarea) {
            textarea.value = fullPrompt;
            textarea.select();
            document.execCommand('copy');
            QuickSiteAdmin.showToast('Full prompt copied!', 'success');
        } else {
            QuickSiteAdmin.showToast('Could not copy to clipboard', 'error');
        }
    }
}

async function copySpecOnly() {
    if (!currentSpec) {
        QuickSiteAdmin.showToast('Select a spec first', 'warning');
        return;
    }
    
    // Generate if not cached
    if (!specsCache[currentSpec.id]) {
        generateSpec(currentSpec);
    }
    
    const spec = specsCache[currentSpec.id];
    
    if (!spec) {
        QuickSiteAdmin.showToast('Specification not ready', 'warning');
        return;
    }
    
    try {
        await navigator.clipboard.writeText(spec);
        QuickSiteAdmin.showToast('Spec copied! Don\'t forget to add your goal.', 'success');
    } catch (e) {
        // Fallback
        const textarea = document.getElementById('ai-spec-content');
        textarea.value = spec;
        textarea.select();
        document.execCommand('copy');
        QuickSiteAdmin.showToast('Spec copied!', 'success');
    }
}

// ============================================================
// Step 4: Import & Execute Functions
// ============================================================

let parsedCommands = null;

function validateImportJson() {
    const textarea = document.getElementById('import-json');
    const statusEl = document.getElementById('import-status');
    const executeBtn = document.getElementById('execute-btn');
    const jsonText = textarea.value.trim();
    
    // Reset state
    parsedCommands = null;
    executeBtn.disabled = true;
    statusEl.className = 'admin-ai-import-status';
    
    if (!jsonText) {
        statusEl.innerHTML = '<span class="admin-ai-import-status__text">Paste JSON to begin</span>';
        return;
    }
    
    try {
        // Try to parse JSON
        let commands = JSON.parse(jsonText);
        
        // Ensure it's an array
        if (!Array.isArray(commands)) {
            // Maybe it's a single command object - wrap it
            if (typeof commands === 'object' && commands.command) {
                commands = [commands];
            } else {
                throw new Error('Expected a JSON array of commands');
            }
        }
        
        // Validate each command has required structure
        const validCommands = [];
        const issues = [];
        
        commands.forEach((cmd, idx) => {
            if (!cmd.command) {
                issues.push(`Item ${idx + 1}: missing "command" field`);
            } else {
                validCommands.push(cmd);
            }
        });
        
        if (validCommands.length === 0) {
            throw new Error('No valid commands found');
        }
        
        // Success - store parsed commands
        parsedCommands = validCommands;
        executeBtn.disabled = false;
        statusEl.className = 'admin-ai-import-status admin-ai-import-status--valid';
        
        // Show summary
        const commandTypes = {};
        validCommands.forEach(cmd => {
            commandTypes[cmd.command] = (commandTypes[cmd.command] || 0) + 1;
        });
        
        const typeSummary = Object.entries(commandTypes)
            .map(([name, count]) => `${count}× ${name}`)
            .join(', ');
        
        let statusHtml = `<span class="admin-ai-import-status__text">✓ ${validCommands.length} command${validCommands.length !== 1 ? 's' : ''} detected</span>`;
        statusHtml += `<span style="color: var(--admin-text-muted); font-size: var(--font-size-xs);">(${typeSummary})</span>`;
        
        if (issues.length > 0) {
            statusHtml += `<span style="color: #f59e0b; font-size: var(--font-size-xs);">⚠ ${issues.length} skipped</span>`;
        }
        
        statusEl.innerHTML = statusHtml;
        
    } catch (e) {
        // Parse error
        statusEl.className = 'admin-ai-import-status admin-ai-import-status--invalid';
        statusEl.innerHTML = `<span class="admin-ai-import-status__text">✗ ${e.message}</span>`;
    }
}

async function executeImportedJson() {
    if (!parsedCommands || parsedCommands.length === 0) {
        QuickSiteAdmin.showToast('No valid commands to execute', 'warning');
        return;
    }
    
    const executeBtn = document.getElementById('execute-btn');
    const resultsEl = document.getElementById('execution-results');
    const summaryEl = document.getElementById('execution-summary');
    const detailsEl = document.getElementById('execution-details');
    const titleEl = document.getElementById('execution-title');
    
    // Show loading state
    executeBtn.disabled = true;
    executeBtn.innerHTML = `
        <svg class="admin-btn__spinner" viewBox="0 0 24 24" width="18" height="18">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="32" stroke-linecap="round">
                <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/>
            </circle>
        </svg>
        Executing...
    `;
    
    // Execute commands
    const results = [];
    let successCount = 0;
    let errorCount = 0;
    
    for (let i = 0; i < parsedCommands.length; i++) {
        const cmd = parsedCommands[i];
        
        try {
            const response = await QuickSiteAdmin.apiRequest(cmd.command, cmd.params || {});
            
            if (response.success) {
                successCount++;
                results.push({
                    command: cmd.command,
                    params: cmd.params,
                    success: true,
                    message: response.message || 'Success'
                });
            } else {
                errorCount++;
                results.push({
                    command: cmd.command,
                    params: cmd.params,
                    success: false,
                    message: response.error || 'Failed'
                });
            }
        } catch (e) {
            errorCount++;
            results.push({
                command: cmd.command,
                params: cmd.params,
                success: false,
                message: e.message || 'Request failed'
            });
        }
    }
    
    // Reset button
    executeBtn.disabled = false;
    executeBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
            <polygon points="5 3 19 12 5 21 5 3"/>
        </svg>
        Execute Now
    `;
    
    // Show results
    resultsEl.style.display = 'block';
    resultsEl.className = errorCount > 0 
        ? 'admin-ai-execution-results admin-ai-execution-results--error'
        : 'admin-ai-execution-results admin-ai-execution-results--success';
    
    titleEl.textContent = errorCount > 0 
        ? `Execution Complete (${errorCount} error${errorCount !== 1 ? 's' : ''})`
        : 'Execution Complete!';
    
    // Summary
    summaryEl.innerHTML = `
        <span class="admin-ai-execution-summary__item admin-ai-execution-summary__item--success">
            ✓ ${successCount} succeeded
        </span>
        ${errorCount > 0 ? `
        <span class="admin-ai-execution-summary__item admin-ai-execution-summary__item--error">
            ✗ ${errorCount} failed
        </span>
        ` : ''}
        <span class="admin-ai-execution-summary__item" style="color: var(--admin-text-muted);">
            Total: ${results.length} commands
        </span>
    `;
    
    // Details
    detailsEl.innerHTML = results.map((r, idx) => `
        <div class="admin-ai-execution-details__item">
            <span class="admin-ai-execution-details__status">${r.success ? '✓' : '✗'}</span>
            <span class="admin-ai-execution-details__command">
                <strong>${r.command}</strong>
                ${r.params ? ` - ${summarizeParams(r.params)}` : ''}
                ${!r.success ? `<br><span style="color: #ef4444;">${r.message}</span>` : ''}
            </span>
        </div>
    `).join('');
    
    // Toast notification
    if (errorCount === 0) {
        QuickSiteAdmin.showToast(`All ${successCount} commands executed successfully!`, 'success');
    } else {
        QuickSiteAdmin.showToast(`${successCount} succeeded, ${errorCount} failed`, errorCount > successCount ? 'error' : 'warning');
    }
    
    // Scroll to results
    resultsEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function summarizeParams(params) {
    if (!params) return '';
    
    // Show key params briefly
    const parts = [];
    if (params.lang) parts.push(`lang: ${params.lang}`);
    if (params.route) parts.push(`route: ${params.route}`);
    if (params.name) parts.push(`name: ${params.name}`);
    if (params.type) parts.push(`type: ${params.type}`);
    if (params.title) parts.push(`title: "${params.title.substring(0, 20)}${params.title.length > 20 ? '...' : ''}"`);
    
    return parts.length > 0 ? parts.join(', ') : JSON.stringify(params).substring(0, 50) + '...';
}

function clearExecutionResults() {
    const resultsEl = document.getElementById('execution-results');
    resultsEl.style.display = 'none';
    resultsEl.className = 'admin-ai-execution-results';
}
</script>
