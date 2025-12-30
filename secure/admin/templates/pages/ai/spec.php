<?php
/**
 * AI Spec Detail Page
 * 
 * Shows a specific AI spec with:
 * - Parameter form (if spec has parameters)
 * - Generated prompt preview
 * - Copy/export options
 * - Related commands reference
 * 
 * @version 2.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/AiSpecManager.php';

$specManager = new AiSpecManager();
$specId = $router->getSpecId();

// Load the spec
$spec = $specManager->loadSpec($specId);

if (!$spec) {
    http_response_code(404);
    ?>
    <div class="ai-spec-error">
        <div class="ai-spec-error__icon">❓</div>
        <h2><?= __admin('ai.spec.notFound.title', 'Spec Not Found') ?></h2>
        <p><?= __admin('ai.spec.notFound.message', 'The requested AI specification could not be found.') ?></p>
        <a href="<?= $router->url('ai') ?>" class="admin-btn admin-btn--primary">
            <?= __admin('ai.spec.backToList', 'Back to Specs') ?>
        </a>
    </div>
    <?php
    return;
}

// Validate spec
$validation = $specManager->validateSpec($spec);

$meta = $spec['meta'] ?? [];
$parameters = $spec['parameters'] ?? [];
$examples = $spec['examples'] ?? [];
$relatedCommands = $spec['relatedCommands'] ?? [];

// Get initial data for display
$specData = $specManager->fetchDataRequirements($spec);
?>

<style>
.ai-spec {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--space-lg);
}

.ai-spec__breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
    margin-bottom: var(--space-lg);
}

.ai-spec__breadcrumb a {
    color: var(--admin-primary);
    text-decoration: none;
}

.ai-spec__breadcrumb a:hover {
    text-decoration: underline;
}

.ai-spec__header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--admin-border);
}

.ai-spec__icon {
    font-size: 3rem;
    line-height: 1;
}

.ai-spec__title-group {
    flex: 1;
}

.ai-spec__title {
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--admin-text);
    margin-bottom: var(--space-xs);
}

.ai-spec__desc {
    color: var(--admin-text-muted);
    font-size: var(--font-size-md);
    line-height: 1.5;
}

.ai-spec__meta {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-sm);
}

.ai-spec__tag {
    background: var(--admin-bg-tertiary);
    color: var(--admin-text-muted);
    padding: 2px 10px;
    border-radius: var(--radius-full);
    font-size: var(--font-size-xs);
}

.ai-spec__tag--custom {
    background: #ddd6fe;
    color: #5b21b6;
}

.ai-spec__version {
    color: var(--admin-text-muted);
    font-size: var(--font-size-xs);
}

.ai-spec__layout {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: var(--space-xl);
}

@media (max-width: 900px) {
    .ai-spec__layout {
        grid-template-columns: 1fr;
    }
}

.ai-spec__sidebar {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.ai-spec__main {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.ai-spec-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.ai-spec-card__header {
    padding: var(--space-md) var(--space-lg);
    background: var(--admin-bg-secondary);
    border-bottom: 1px solid var(--admin-border);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.ai-spec-card__body {
    padding: var(--space-lg);
}

/* Parameter Form */
.ai-spec-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.ai-spec-form__group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.ai-spec-form__label {
    font-weight: 500;
    color: var(--admin-text);
    font-size: var(--font-size-sm);
}

.ai-spec-form__required {
    color: #ef4444;
}

.ai-spec-form__input {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    background: var(--admin-bg);
    color: var(--admin-text);
}

.ai-spec-form__input:focus {
    outline: none;
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.ai-spec-form__help {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

.ai-spec-form__textarea {
    min-height: 120px;
    resize: vertical;
}

/* Checkbox */
.ai-spec-form__checkbox-group {
    flex-direction: row;
    align-items: center;
    gap: var(--space-sm);
}

.ai-spec-form__checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.ai-spec-form__checkbox-label {
    cursor: pointer;
    font-weight: 500;
    color: var(--admin-text);
    font-size: var(--font-size-sm);
}

/* Conditional fields */
.ai-spec-form__group--hidden {
    display: none;
}

/* User Prompt Section */
.ai-spec-user-prompt {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

@media (max-width: 900px) {
    .ai-spec-user-prompt {
        grid-template-columns: 1fr;
    }
}

.ai-spec-user-prompt--single {
    grid-template-columns: 1fr;
}

.ai-spec-user-prompt__textarea {
    width: 100%;
    min-height: 150px;
    padding: var(--space-md);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    line-height: 1.6;
    background: var(--admin-bg);
    color: var(--admin-text);
    resize: vertical;
}

.ai-spec-user-prompt__textarea:focus {
    outline: none;
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Examples */
.ai-spec-examples {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.ai-spec-example {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: var(--font-size-sm);
}

.ai-spec-example:hover {
    border-color: var(--admin-primary);
    background: var(--admin-bg-secondary);
}

.ai-spec-example--selected {
    border-color: var(--admin-primary);
    background: rgba(59, 130, 246, 0.1);
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}

.ai-spec-example__title {
    font-weight: 500;
    color: var(--admin-text);
}

.ai-spec-example__desc {
    color: var(--admin-text-muted);
    font-size: var(--font-size-xs);
    margin-top: 2px;
}

/* Prompt Output */
.ai-spec-prompt {
    position: relative;
}

.ai-spec-prompt__textarea {
    width: 100%;
    min-height: 400px;
    padding: var(--space-md);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    line-height: 1.6;
    background: var(--admin-bg);
    color: var(--admin-text);
    resize: vertical;
}

.ai-spec-prompt__actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-md);
}

.ai-spec-prompt__stats {
    margin-left: auto;
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    display: flex;
    align-items: center;
    gap: var(--space-md);
}

/* Related Commands */
.ai-spec-commands {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}

.ai-spec-command {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    background: var(--admin-bg-tertiary);
    border-radius: var(--radius-sm);
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
    color: var(--admin-text);
    text-decoration: none;
}

.ai-spec-command:hover {
    background: var(--admin-primary);
    color: white;
}

/* Validation Error */
.ai-spec-validation-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-lg);
}

.ai-spec-validation-error__title {
    color: #991b1b;
    font-weight: 600;
    margin-bottom: var(--space-sm);
}

.ai-spec-validation-error__list {
    color: #dc2626;
    font-size: var(--font-size-sm);
    margin: 0;
    padding-left: var(--space-lg);
}

/* Error state */
.ai-spec-error {
    text-align: center;
    padding: var(--space-2xl);
}

.ai-spec-error__icon {
    font-size: 4rem;
    margin-bottom: var(--space-lg);
}

/* Loading state */
.ai-spec-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-xl);
    color: var(--admin-text-muted);
}

.ai-spec-loading__spinner {
    width: 20px;
    height: 20px;
    border: 2px solid var(--admin-border);
    border-top-color: var(--admin-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div class="ai-spec">
    <!-- Breadcrumb -->
    <nav class="ai-spec__breadcrumb">
        <a href="<?= $router->url('ai') ?>"><?= __admin('ai.browser.title', 'AI Specs') ?></a>
        <span>›</span>
        <span><?= __admin($meta['titleKey'] ?? '', $specId) ?></span>
    </nav>
    
    <?php if (!$validation['valid']): ?>
    <div class="ai-spec-validation-error">
        <div class="ai-spec-validation-error__title">⚠️ <?= __admin('ai.spec.validationError', 'Spec Validation Errors') ?></div>
        <ul class="ai-spec-validation-error__list">
            <?php foreach ($validation['errors'] as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <header class="ai-spec__header">
        <span class="ai-spec__icon"><?= htmlspecialchars($meta['icon'] ?? '📋') ?></span>
        <div class="ai-spec__title-group">
            <h1 class="ai-spec__title"><?= __admin($meta['titleKey'] ?? '', $specId) ?></h1>
            <p class="ai-spec__desc"><?= __admin($meta['descriptionKey'] ?? '', '') ?></p>
            <div class="ai-spec__meta">
                <?php if (!empty($meta['tags'])): ?>
                    <?php foreach ($meta['tags'] as $tag): ?>
                    <span class="ai-spec__tag"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
                <span class="ai-spec__version">v<?= htmlspecialchars($spec['version'] ?? '1.0.0') ?></span>
                <?php if (($spec['_source'] ?? 'core') === 'custom'): ?>
                <span class="ai-spec__tag ai-spec__tag--custom"><?= __admin('ai.spec.custom', 'Custom') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (($spec['_source'] ?? 'core') === 'custom'): ?>
        <a href="<?= $router->url('ai', 'edit/' . $specId) ?>" class="admin-btn admin-btn--secondary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            <?= __admin('ai.spec.edit', 'Edit Spec') ?>
        </a>
        <?php endif; ?>
    </header>
    
    <!-- Examples + User Prompt Section -->
    <div class="ai-spec-user-prompt<?= empty($examples) ? ' ai-spec-user-prompt--single' : '' ?>">
        <?php if (!empty($examples)): ?>
        <!-- Examples -->
        <div class="ai-spec-card">
            <div class="ai-spec-card__header">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <path d="M14 2v6h6"/>
                    <path d="M16 13H8"/>
                    <path d="M16 17H8"/>
                </svg>
                <?= __admin('ai.spec.examples', 'Examples') ?>
            </div>
            <div class="ai-spec-card__body">
                <div class="ai-spec-examples">
                    <?php foreach ($examples as $example): ?>
                    <div class="ai-spec-example" 
                         data-params='<?= htmlspecialchars(json_encode($example['params'] ?? [])) ?>'
                         data-prompt="<?= htmlspecialchars(__admin($example['promptKey'] ?? '', '')) ?>">
                        <div class="ai-spec-example__title"><?= __admin($example['titleKey'] ?? '', $example['id']) ?></div>
                        <?php if (isset($example['promptKey'])): ?>
                        <div class="ai-spec-example__desc"><?= __admin($example['promptKey'], '') ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- User Prompt -->
        <div class="ai-spec-card">
            <div class="ai-spec-card__header">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                <?= __admin('ai.spec.yourPrompt', 'Your Prompt') ?>
            </div>
            <div class="ai-spec-card__body">
                <textarea 
                    id="user-prompt" 
                    class="ai-spec-user-prompt__textarea" 
                    placeholder="<?= __admin('ai.spec.yourPromptPlaceholder', 'Describe what you want to create... Click an example to fill this automatically.') ?>"
                ></textarea>
            </div>
        </div>
    </div>
    
    <div class="ai-spec__layout">
        <!-- Sidebar -->
        <aside class="ai-spec__sidebar">
            <?php if (!empty($parameters)): ?>
            <!-- Parameters Form -->
            <div class="ai-spec-card">
                <div class="ai-spec-card__header">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 3v18"/>
                        <path d="M3 12h18"/>
                    </svg>
                    <?= __admin('ai.spec.parameters', 'Parameters') ?>
                </div>
                <div class="ai-spec-card__body">
                    <form id="spec-params-form" class="ai-spec-form">
                        <?php foreach ($parameters as $param): ?>
                        <?php if (($param['type'] ?? 'text') === 'checkbox'): ?>
                        <div class="ai-spec-form__group ai-spec-form__checkbox-group"<?php if (isset($param['condition'])): ?> data-condition="<?= htmlspecialchars($param['condition']) ?>"<?php endif; ?>>
                            <input 
                                type="checkbox"
                                id="param-<?= htmlspecialchars($param['id']) ?>"
                                name="<?= htmlspecialchars($param['id']) ?>"
                                class="ai-spec-form__checkbox"
                                value="true"
                                <?= ($param['default'] ?? false) ? 'checked' : '' ?>
                            />
                            <label class="ai-spec-form__checkbox-label" for="param-<?= htmlspecialchars($param['id']) ?>">
                                <?= __admin($param['labelKey'] ?? '', $param['id']) ?>
                            </label>
                            <?php if (isset($param['helpKey'])): ?>
                            <span class="ai-spec-form__help" style="margin-left: auto;"><?= __admin($param['helpKey'], '') ?></span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="ai-spec-form__group"<?php if (isset($param['condition'])): ?> data-condition="<?= htmlspecialchars($param['condition']) ?>"<?php endif; ?>>
                            <label class="ai-spec-form__label" for="param-<?= htmlspecialchars($param['id']) ?>">
                                <?= __admin($param['labelKey'] ?? '', $param['id']) ?>
                                <?php if ($param['required'] ?? false): ?>
                                <span class="ai-spec-form__required">*</span>
                                <?php endif; ?>
                            </label>
                            <?php if (($param['type'] ?? 'text') === 'textarea'): ?>
                            <textarea 
                                id="param-<?= htmlspecialchars($param['id']) ?>"
                                name="<?= htmlspecialchars($param['id']) ?>"
                                class="ai-spec-form__input ai-spec-form__textarea"
                                placeholder="<?= __admin($param['placeholderKey'] ?? '', '') ?>"
                                <?= ($param['required'] ?? false) ? 'required' : '' ?>
                            ></textarea>
                            <?php elseif (($param['type'] ?? 'text') === 'select' && isset($param['options'])): ?>
                            <select 
                                id="param-<?= htmlspecialchars($param['id']) ?>"
                                name="<?= htmlspecialchars($param['id']) ?>"
                                class="ai-spec-form__input"
                                <?= ($param['required'] ?? false) ? 'required' : '' ?>
                            >
                                <option value=""><?= __admin($param['placeholderKey'] ?? '', '-- Select --') ?></option>
                                <?php foreach ($param['options'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['value'] ?? $opt) ?>">
                                    <?= htmlspecialchars($opt['label'] ?? $opt) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input 
                                type="text"
                                id="param-<?= htmlspecialchars($param['id']) ?>"
                                name="<?= htmlspecialchars($param['id']) ?>"
                                class="ai-spec-form__input"
                                placeholder="<?= __admin($param['placeholderKey'] ?? '', '') ?>"
                                <?= ($param['required'] ?? false) ? 'required' : '' ?>
                            />
                            <?php endif; ?>
                            <?php if (isset($param['helpKey'])): ?>
                            <span class="ai-spec-form__help"><?= __admin($param['helpKey'], '') ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="admin-btn admin-btn--primary" style="margin-top: var(--space-sm);">
                            <?= __admin('ai.spec.generatePrompt', 'Generate Prompt') ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($relatedCommands)): ?>
            <!-- Related Commands -->
            <div class="ai-spec-card">
                <div class="ai-spec-card__header">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="4 17 10 11 4 5"/>
                        <line x1="12" y1="19" x2="20" y2="19"/>
                    </svg>
                    <?= __admin('ai.spec.relatedCommands', 'Related Commands') ?>
                </div>
                <div class="ai-spec-card__body">
                    <div class="ai-spec-commands">
                        <?php foreach ($relatedCommands as $cmd): ?>
                        <a href="<?= $router->url('command', $cmd) ?>" class="ai-spec-command" target="_blank">
                            <?= htmlspecialchars($cmd) ?>
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        
        <!-- Main Content -->
        <main class="ai-spec__main">
            <!-- Prompt Output -->
            <div class="ai-spec-card">
                <div class="ai-spec-card__header">
                    <span style="display: flex; align-items: center; gap: var(--space-sm);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        <?= __admin('ai.spec.generatedPrompt', 'Generated Prompt') ?>
                    </span>
                    <button type="button" id="export-spec" class="admin-btn admin-btn--ghost" style="margin-left: auto;" title="<?= __admin('ai.spec.export', 'Export Spec') ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <?= __admin('ai.spec.export', 'Export') ?>
                    </button>
                </div>
                <div class="ai-spec-card__body ai-spec-prompt">
                    <div id="prompt-loading" class="ai-spec-loading" style="display: none;">
                        <div class="ai-spec-loading__spinner"></div>
                        <span><?= __admin('ai.spec.generating', 'Generating prompt...') ?></span>
                    </div>
                    <textarea id="prompt-output" class="ai-spec-prompt__textarea" readonly placeholder="<?= __admin('ai.spec.promptPlaceholder', 'Fill in the parameters and click Generate to create a prompt...') ?>"></textarea>
                    <div class="ai-spec-prompt__actions">
                        <button type="button" id="copy-prompt" class="admin-btn admin-btn--secondary" disabled>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                            </svg>
                            <?= __admin('ai.spec.copyPrompt', 'Copy') ?>
                        </button>
                        <a href="<?= $router->url('batch') ?>" id="go-batch" class="admin-btn admin-btn--primary" style="display: none;">
                            <?= __admin('ai.spec.goToBatch', 'Go to Batch Panel') ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14"/>
                                <path d="M12 5l7 7-7 7"/>
                            </svg>
                        </a>
                        <div class="ai-spec-prompt__stats">
                            <span id="char-count">0 <?= __admin('ai.spec.chars', 'chars') ?></span>
                            <span id="word-count">0 <?= __admin('ai.spec.words', 'words') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
(function() {
    const specId = <?= json_encode($specId) ?>;
    const form = document.getElementById('spec-params-form');
    const promptOutput = document.getElementById('prompt-output');
    const promptLoading = document.getElementById('prompt-loading');
    const copyBtn = document.getElementById('copy-prompt');
    const goBatchBtn = document.getElementById('go-batch');
    const charCount = document.getElementById('char-count');
    const wordCount = document.getElementById('word-count');
    const userPromptTextarea = document.getElementById('user-prompt');
    
    // Evaluate condition expression
    function evaluateCondition(condition, formData) {
        // Simple condition parser for expressions like "multilingual === true"
        // Supports: ===, !==, ==, !=, &&, ||
        try {
            // Replace parameter names with their values
            let expr = condition;
            for (const [key, value] of Object.entries(formData)) {
                // Handle boolean-like values
                let actualValue = value;
                if (value === 'true') actualValue = true;
                else if (value === 'false' || value === '') actualValue = false;
                
                // Replace the variable with its JSON value
                const regex = new RegExp(`\\b${key}\\b`, 'g');
                expr = expr.replace(regex, JSON.stringify(actualValue));
            }
            // Also replace any remaining undefined params with false
            expr = expr.replace(/\b[a-zA-Z_][a-zA-Z0-9_]*\b(?!\s*:)/g, (match) => {
                if (match === 'true' || match === 'false' || match === 'null') return match;
                return 'false';
            });
            return eval(expr);
        } catch (e) {
            console.warn('Condition evaluation failed:', condition, e);
            return true; // Default to showing the field
        }
    }
    
    // Update conditional fields visibility
    function updateConditionalFields() {
        if (!form) return;
        
        // Get current form values
        const formData = {};
        form.querySelectorAll('input, select, textarea').forEach(input => {
            if (input.type === 'checkbox') {
                formData[input.name] = input.checked ? 'true' : 'false';
            } else {
                formData[input.name] = input.value;
            }
        });
        
        // Check all conditional fields
        form.querySelectorAll('[data-condition]').forEach(group => {
            const condition = group.dataset.condition;
            const shouldShow = evaluateCondition(condition, formData);
            group.classList.toggle('ai-spec-form__group--hidden', !shouldShow);
        });
    }
    
    // Listen for form changes to update conditionals
    if (form) {
        form.addEventListener('change', updateConditionalFields);
        // Initial evaluation
        updateConditionalFields();
    }
    
    // Update stats
    function updateStats(text) {
        const chars = text.length;
        const words = text.trim() ? text.trim().split(/\s+/).length : 0;
        charCount.textContent = chars.toLocaleString() + ' <?= __admin('ai.spec.chars', 'chars') ?>';
        wordCount.textContent = words.toLocaleString() + ' <?= __admin('ai.spec.words', 'words') ?>';
    }
    
    // Generate prompt
    async function generatePrompt(params = {}) {
        promptLoading.style.display = 'flex';
        promptOutput.style.display = 'none';
        copyBtn.disabled = true;
        goBatchBtn.style.display = 'none';
        
        try {
            const queryString = new URLSearchParams(params).toString();
            const url = '<?= $router->getBaseUrl() ?>/api/ai-spec/' + specId + (queryString ? '?' + queryString : '');
            
            const response = await fetch(url, {
                headers: {
                    'Authorization': 'Bearer <?= $router->getToken() ?>'
                }
            });
            
            const data = await response.json();
            
            if (data.success && data.data?.prompt) {
                // Get user's custom prompt
                const userPrompt = userPromptTextarea ? userPromptTextarea.value.trim() : '';
                
                // Combine spec prompt with user prompt
                let finalPrompt = data.data.prompt;
                if (userPrompt) {
                    finalPrompt += '\n\n---\n\n**User Request:**\n' + userPrompt;
                }
                
                promptOutput.value = finalPrompt;
                copyBtn.disabled = false;
                goBatchBtn.style.display = 'inline-flex';
                updateStats(finalPrompt);
            } else {
                promptOutput.value = 'Error: ' + (data.error || 'Failed to generate prompt');
                updateStats('');
            }
        } catch (error) {
            promptOutput.value = 'Error: ' + error.message;
            updateStats('');
        }
        
        promptLoading.style.display = 'none';
        promptOutput.style.display = 'block';
    }
    
    // Form submit
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(form);
            const params = {};
            formData.forEach((value, key) => {
                if (value) params[key] = value;
            });
            generatePrompt(params);
        });
    }
    
    // Example click - fill user prompt textarea
    document.querySelectorAll('.ai-spec-example').forEach(example => {
        example.addEventListener('click', function() {
            const params = JSON.parse(this.dataset.params || '{}');
            const examplePrompt = this.dataset.prompt || '';
            
            // Fill user prompt textarea with example description
            if (userPromptTextarea && examplePrompt) {
                userPromptTextarea.value = examplePrompt;
                userPromptTextarea.focus();
            }
            
            // Fill form with example params
            if (form) {
                Object.entries(params).forEach(([key, value]) => {
                    const input = form.querySelector(`[name="${key}"]`);
                    if (input) {
                        if (input.type === 'checkbox') {
                            input.checked = value === true || value === 'true';
                        } else {
                            input.value = value;
                        }
                    }
                });
            }
            
            // Highlight the selected example
            document.querySelectorAll('.ai-spec-example').forEach(ex => ex.classList.remove('ai-spec-example--selected'));
            this.classList.add('ai-spec-example--selected');
        });
    });
    
    // Copy button
    copyBtn.addEventListener('click', async function() {
        try {
            await navigator.clipboard.writeText(promptOutput.value);
            const originalText = this.innerHTML;
            this.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> <?= __admin('ai.spec.copied', 'Copied!') ?>';
            setTimeout(() => { this.innerHTML = originalText; }, 2000);
        } catch (err) {
            // Fallback
            promptOutput.select();
            document.execCommand('copy');
        }
    });
    
    // Export button
    const exportBtn = document.getElementById('export-spec');
    exportBtn.addEventListener('click', async function() {
        try {
            const response = await fetch('<?= $router->getBaseUrl() ?>/api/ai-spec-raw/' + specId, {
                headers: {
                    'Authorization': 'Bearer <?= $router->getToken() ?>'
                }
            });
            const data = await response.json();
            
            if (data.success) {
                // Create a combined export object
                const exportData = {
                    spec: data.data.spec,
                    template: data.data.template
                };
                
                // Create and download file
                const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = specId + '.spec.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            } else {
                alert('Export failed: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            alert('Export failed: ' + error.message);
        }
    });
    
    // Generate initial prompt if no parameters required
    <?php if (empty($parameters)): ?>
    generatePrompt({});
    <?php endif; ?>
})();
</script>
