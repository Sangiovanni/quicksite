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

// Check if spec has 'create' tag (requires fresh start)
$hasCreateTag = !empty($meta['tags']) && in_array('create', $meta['tags']);
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

/* AI Response Section */
.ai-response-hint {
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
    margin-bottom: var(--space-sm);
}

.ai-response-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-sm);
    padding: var(--space-sm) var(--space-md);
    color: #dc2626;
    font-size: var(--font-size-sm);
    margin-top: var(--space-sm);
}

/* Command Preview */
.command-count {
    background: var(--admin-primary);
    color: white;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    margin-left: auto;
}

.command-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    max-height: 400px;
    overflow-y: auto;
}

.command-item {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
    border: 1px solid var(--admin-border);
}

.command-item__number {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    background: var(--admin-bg-tertiary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xs);
    font-weight: 600;
    color: var(--admin-text-muted);
}

.command-item__content {
    flex: 1;
    min-width: 0;
}

.command-item__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-xs);
}

.command-item__method {
    font-size: var(--font-size-xs);
    font-weight: 600;
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    text-transform: uppercase;
}

.command-item__method--get {
    background: #dbeafe;
    color: #1d4ed8;
}

.command-item__method--post {
    background: #dcfce7;
    color: #166534;
}

.command-item__method--put,
.command-item__method--patch {
    background: #fef3c7;
    color: #92400e;
}

.command-item__method--delete {
    background: #fee2e2;
    color: #991b1b;
}

.command-item__command {
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--admin-text);
}

.command-item__params {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    word-break: break-all;
}

/* Execution Results */
.execution-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-lg);
    color: var(--admin-text-muted);
}

.execution-results {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.result-item {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    border: 1px solid var(--admin-border);
}

.result-item--success {
    background: #f0fdf4;
    border-color: #bbf7d0;
}

.result-item--error {
    background: #fef2f2;
    border-color: #fecaca;
}

.result-item--pending {
    background: var(--admin-bg-secondary);
    opacity: 0.6;
}

.result-item__icon {
    flex-shrink: 0;
    font-size: 1rem;
}

.result-item__content {
    flex: 1;
    min-width: 0;
}

.result-item__command {
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: #1f2937;
}

.result-item--success .result-item__command {
    color: #166534;
}

.result-item--error .result-item__command {
    color: #991b1b;
}

.result-item__message {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    margin-top: 2px;
}

.result-item__error {
    font-size: var(--font-size-xs);
    color: #dc2626;
    margin-top: 2px;
}

/* Fresh Start Checkbox */
.fresh-start-option {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}

.fresh-start-option__checkbox {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    cursor: pointer;
}

.fresh-start-option__content {
    flex: 1;
}

.fresh-start-option__label {
    font-weight: 500;
    color: var(--admin-text);
    cursor: pointer;
    display: block;
    margin-bottom: 2px;
}

.fresh-start-option__hint {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

/* Warning Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: var(--radius-lg);
    padding: var(--space-xl);
    max-width: 450px;
    width: 90%;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

.modal-icon {
    font-size: 3rem;
    text-align: center;
    margin-bottom: var(--space-md);
}

.modal-title {
    font-size: var(--font-size-lg);
    font-weight: 600;
    text-align: center;
    margin-bottom: var(--space-sm);
}

.modal-message {
    color: var(--admin-text-muted);
    text-align: center;
    margin-bottom: var(--space-lg);
    line-height: 1.5;
}

.modal-actions {
    display: flex;
    gap: var(--space-sm);
    justify-content: center;
}

.modal-actions .admin-btn {
    min-width: 120px;
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
                        <span id="prompt-next-step" class="admin-btn admin-btn--ghost" style="display: none; cursor: default; opacity: 0.8;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14"/>
                                <path d="M19 12l-7 7-7-7"/>
                            </svg>
                            <?= __admin('ai.spec.pasteBelow', 'Paste AI response below') ?>
                        </span>
                        <div class="ai-spec-prompt__stats">
                            <span id="char-count">0 <?= __admin('ai.spec.chars', 'chars') ?></span>
                            <span id="word-count">0 <?= __admin('ai.spec.words', 'words') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- AI Response Section -->
            <div class="ai-spec-card ai-response-section" style="display: none;">
                <div class="ai-spec-card__header">
                    <span style="display: flex; align-items: center; gap: var(--space-sm);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                        </svg>
                        <?= __admin('ai.spec.aiResponse', 'AI Response') ?>
                    </span>
                </div>
                <div class="ai-spec-card__body">
                    <p class="ai-response-hint"><?= __admin('ai.spec.pasteJsonHint', 'Paste the JSON response from the AI below:') ?></p>
                    <textarea id="ai-response-input" class="ai-spec-prompt__textarea" placeholder='{"commands": [...]}'></textarea>
                    <div id="ai-response-error" class="ai-response-error" style="display: none;"></div>
                    <div class="ai-spec-prompt__actions">
                        <button type="button" id="validate-response" class="admin-btn admin-btn--secondary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            <?= __admin('ai.spec.validateJson', 'Validate JSON') ?>
                        </button>
                        <button type="button" id="preview-commands" class="admin-btn admin-btn--primary" style="display: none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <?= __admin('ai.spec.previewCommands', 'Preview Commands') ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Command Preview Section -->
            <div class="ai-spec-card command-preview-section" style="display: none;">
                <div class="ai-spec-card__header">
                    <span style="display: flex; align-items: center; gap: var(--space-sm);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="4 17 10 11 4 5"/>
                            <line x1="12" y1="19" x2="20" y2="19"/>
                        </svg>
                        <?= __admin('ai.spec.commandPreview', 'Command Preview') ?>
                    </span>
                    <span id="command-count" class="command-count"></span>
                </div>
                <div class="ai-spec-card__body">
                    <div id="fresh-start-option" class="fresh-start-option" style="display: none;">
                        <input type="checkbox" id="fresh-start-checkbox" class="fresh-start-option__checkbox" checked>
                        <div class="fresh-start-option__content">
                            <label for="fresh-start-checkbox" class="fresh-start-option__label">
                                <?= __admin('ai.spec.freshStartLabel', 'Fresh Start (reset project before execution)') ?>
                            </label>
                            <span class="fresh-start-option__hint">
                                <?= __admin('ai.spec.freshStartHint', 'Recommended for "create" specs - clears existing routes, assets, and structures') ?>
                            </span>
                        </div>
                    </div>
                    <div id="command-list" class="command-list"></div>
                    <div class="ai-spec-prompt__actions" style="margin-top: var(--space-md);">
                        <button type="button" id="back-to-json" class="admin-btn admin-btn--secondary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 12H5"/>
                                <path d="M12 19l-7-7 7-7"/>
                            </svg>
                            <?= __admin('ai.spec.backToJson', 'Back') ?>
                        </button>
                        <button type="button" id="execute-commands" class="admin-btn admin-btn--primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            <?= __admin('ai.spec.executeCommands', 'Execute All') ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Execution Results Section -->
            <div class="ai-spec-card execution-results-section" style="display: none;">
                <div class="ai-spec-card__header">
                    <span style="display: flex; align-items: center; gap: var(--space-sm);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        <?= __admin('ai.spec.executionResults', 'Execution Results') ?>
                    </span>
                </div>
                <div class="ai-spec-card__body">
                    <div id="execution-progress" class="execution-progress" style="display: none;">
                        <div class="ai-spec-loading__spinner"></div>
                        <span id="progress-text"><?= __admin('ai.spec.executing', 'Executing commands...') ?></span>
                    </div>
                    <div id="execution-results" class="execution-results"></div>
                    <div class="ai-spec-prompt__actions" style="margin-top: var(--space-md);">
                        <button type="button" id="reset-workflow" class="admin-btn admin-btn--secondary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                <path d="M3 3v5h5"/>
                            </svg>
                            <?= __admin('ai.spec.startOver', 'Start Over') ?>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Fresh Start Warning Modal -->
<div id="fresh-start-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="modal-icon">⚠️</div>
        <h3 class="modal-title"><?= __admin('ai.spec.freshStartRecommended', 'Fresh Start Recommended') ?></h3>
        <p class="modal-message">
            <?= __admin('ai.spec.freshStartWarning', 'This spec is designed to create a new project from scratch. Running without Fresh Start may cause conflicts with existing content.') ?>
        </p>
        <div class="modal-actions">
            <button type="button" id="modal-cancel" class="admin-btn admin-btn--secondary">
                <?= __admin('ai.spec.cancel', 'Cancel') ?>
            </button>
            <button type="button" id="modal-proceed" class="admin-btn admin-btn--primary">
                <?= __admin('ai.spec.proceedAnyway', 'Proceed Anyway') ?>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const specId = <?= json_encode($specId) ?>;
    const isCreateSpec = <?= json_encode($hasCreateTag) ?>;
    const form = document.getElementById('spec-params-form');
    const promptOutput = document.getElementById('prompt-output');
    const promptLoading = document.getElementById('prompt-loading');
    const copyBtn = document.getElementById('copy-prompt');
    const promptNextStep = document.getElementById('prompt-next-step');
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
        promptNextStep.style.display = 'none';
        
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
                promptNextStep.style.display = 'inline-flex';
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
    
    // === AI Response & Command Preview ===
    const aiResponseSection = document.querySelector('.ai-response-section');
    const aiResponseInput = document.getElementById('ai-response-input');
    const aiResponseError = document.getElementById('ai-response-error');
    const validateBtn = document.getElementById('validate-response');
    const previewBtn = document.getElementById('preview-commands');
    const commandPreviewSection = document.querySelector('.command-preview-section');
    const commandList = document.getElementById('command-list');
    const commandCount = document.getElementById('command-count');
    const backToJsonBtn = document.getElementById('back-to-json');
    const executeBtn = document.getElementById('execute-commands');
    const executionResultsSection = document.querySelector('.execution-results-section');
    const executionProgress = document.getElementById('execution-progress');
    const progressText = document.getElementById('progress-text');
    const executionResults = document.getElementById('execution-results');
    const resetBtn = document.getElementById('reset-workflow');
    const freshStartOption = document.getElementById('fresh-start-option');
    const freshStartCheckbox = document.getElementById('fresh-start-checkbox');
    const freshStartModal = document.getElementById('fresh-start-modal');
    const modalCancelBtn = document.getElementById('modal-cancel');
    const modalProceedBtn = document.getElementById('modal-proceed');
    
    let parsedCommands = [];
    let pendingExecution = false;
    
    // Show AI response section after prompt is generated
    const originalGeneratePrompt = generatePrompt;
    generatePrompt = async function(params) {
        await originalGeneratePrompt(params);
        if (promptOutput.value && !promptOutput.value.startsWith('Error:')) {
            aiResponseSection.style.display = 'block';
        }
    };
    
    // Validate JSON
    function validateJson() {
        const jsonText = aiResponseInput.value.trim();
        aiResponseError.style.display = 'none';
        previewBtn.style.display = 'none';
        
        if (!jsonText) {
            aiResponseError.textContent = 'Please paste the JSON response from the AI.';
            aiResponseError.style.display = 'block';
            return null;
        }
        
        try {
            const data = JSON.parse(jsonText);
            
            // Check for commands array
            let commands = null;
            if (Array.isArray(data)) {
                commands = data;
            } else if (data.commands && Array.isArray(data.commands)) {
                commands = data.commands;
            } else if (data.command) {
                commands = [data];
            }
            
            if (!commands || commands.length === 0) {
                aiResponseError.textContent = 'No commands found. Expected format: {"commands": [...]} or an array of commands.';
                aiResponseError.style.display = 'block';
                return null;
            }
            
            // Validate each command
            for (let i = 0; i < commands.length; i++) {
                const cmd = commands[i];
                if (!cmd.command) {
                    aiResponseError.textContent = `Command #${i + 1} is missing the "command" field.`;
                    aiResponseError.style.display = 'block';
                    return null;
                }
            }
            
            return commands;
        } catch (e) {
            aiResponseError.textContent = 'Invalid JSON: ' + e.message;
            aiResponseError.style.display = 'block';
            return null;
        }
    }
    
    // Validate button click
    validateBtn.addEventListener('click', function() {
        const commands = validateJson();
        if (commands) {
            parsedCommands = commands;
            previewBtn.style.display = 'inline-flex';
            aiResponseError.style.display = 'none';
        }
    });
    
    // Format parameters for preview
    function formatParams(params) {
        if (!params || Object.keys(params).length === 0) return '';
        const parts = [];
        for (const [key, value] of Object.entries(params)) {
            if (typeof value === 'object') {
                parts.push(`${key}={...}`);
            } else if (typeof value === 'string' && value.length > 50) {
                parts.push(`${key}="${value.substring(0, 50)}..."`);
            } else {
                parts.push(`${key}=${JSON.stringify(value)}`);
            }
        }
        return parts.join(', ');
    }
    
    // Get HTTP method for command
    function getMethod(cmd) {
        if (cmd.method) return cmd.method.toUpperCase();
        if (cmd.params && Object.keys(cmd.params).length > 0) return 'POST';
        return 'GET';
    }
    
    // Preview commands
    previewBtn.addEventListener('click', function() {
        commandList.innerHTML = '';
        
        parsedCommands.forEach((cmd, index) => {
            const method = getMethod(cmd);
            const methodClass = 'command-item__method--' + method.toLowerCase();
            
            const item = document.createElement('div');
            item.className = 'command-item';
            item.innerHTML = `
                <span class="command-item__number">${index + 1}</span>
                <div class="command-item__content">
                    <div class="command-item__header">
                        <span class="command-item__method ${methodClass}">${method}</span>
                        <span class="command-item__command">${cmd.command}</span>
                    </div>
                    ${cmd.params ? `<div class="command-item__params">${formatParams(cmd.params)}</div>` : ''}
                </div>
            `;
            commandList.appendChild(item);
        });
        
        commandCount.textContent = parsedCommands.length + ' command' + (parsedCommands.length > 1 ? 's' : '');
        
        // Show fresh start option for create specs
        if (isCreateSpec) {
            freshStartOption.style.display = 'flex';
            freshStartCheckbox.checked = true; // Default to checked
        } else {
            freshStartOption.style.display = 'none';
        }
        
        aiResponseSection.style.display = 'none';
        commandPreviewSection.style.display = 'block';
    });
    
    // Back to JSON
    backToJsonBtn.addEventListener('click', function() {
        commandPreviewSection.style.display = 'none';
        aiResponseSection.style.display = 'block';
    });
    
    // Modal handlers
    modalCancelBtn.addEventListener('click', function() {
        freshStartModal.style.display = 'none';
        pendingExecution = false;
    });
    
    modalProceedBtn.addEventListener('click', function() {
        freshStartModal.style.display = 'none';
        executeCommands(false); // Execute without fresh start
    });
    
    // Generate fresh start commands (same logic as batch.php)
    async function generateFreshStartCommands() {
        const commands = [];
        const managementUrl = '<?= rtrim(BASE_URL, '/') ?>/management/';
        const token = '<?= $router->getToken() ?>';
        
        async function apiCall(command, params = {}) {
            const hasParams = Object.keys(params).length > 0;
            const url = managementUrl + command;
            const options = {
                method: hasParams ? 'POST' : 'GET',
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json'
                }
            };
            if (hasParams) options.body = JSON.stringify(params);
            const response = await fetch(url, options);
            return await response.json();
        }
        
        try {
            // 1. Delete routes (except 404 and home)
            // API returns: { routes: {nested object}, flat_routes: ["home", "about", ...] }
            const routesResponse = await apiCall('getRoutes');
            if ((routesResponse.status === 200 || routesResponse.success) && routesResponse.data?.flat_routes) {
                const protectedRoutes = ['404', 'home'];
                for (const routeName of routesResponse.data.flat_routes) {
                    if (!protectedRoutes.includes(routeName)) {
                        commands.push({ command: 'deleteRoute', params: { route: routeName } });
                    }
                }
            }
            
            // 2. Delete all assets
            const assetsResponse = await apiCall('listAssets');
            if ((assetsResponse.status === 200 || assetsResponse.success) && assetsResponse.data?.assets) {
                for (const [category, files] of Object.entries(assetsResponse.data.assets)) {
                    for (const file of files) {
                        commands.push({ 
                            command: 'deleteAsset', 
                            params: { category: category, filename: file.filename } 
                        });
                    }
                }
            }
            
            // 3. Delete all components
            const componentsResponse = await apiCall('listComponents');
            if ((componentsResponse.status === 200 || componentsResponse.success) && componentsResponse.data?.components) {
                for (const component of componentsResponse.data.components) {
                    commands.push({ 
                        command: 'editStructure', 
                        params: { type: 'component', name: component.name, structure: [] } 
                    });
                }
            }
            
            // 4. Clear translation keys (except 404.*)
            const translationsResponse = await apiCall('getTranslations');
            if ((translationsResponse.status === 200 || translationsResponse.success) && translationsResponse.data?.translations) {
                for (const [lang, keys] of Object.entries(translationsResponse.data.translations)) {
                    const topLevelKeys = Object.keys(keys).filter(key => key !== '404');
                    if (topLevelKeys.length > 0) {
                        commands.push({ 
                            command: 'deleteTranslationKeys', 
                            params: { language: lang, keys: topLevelKeys } 
                        });
                    }
                }
            }
            
            // 5. Clear structures
            commands.push({ command: 'editStructure', params: { type: 'menu', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'footer', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'page', name: 'home', structure: [] } });
            
            // 6. Minimize 404 page
            commands.push({ 
                command: 'editStructure', 
                params: { 
                    type: 'page',
                    name: '404', 
                    structure: [
                        { tag: 'section', params: { class: 'error-page' }, children: [
                            { tag: 'h1', children: [{ textKey: '404.pageNotFound' }] },
                            { tag: 'p', children: [{ textKey: '404.message' }] }
                        ]}
                    ]
                } 
            });
            
            // 7. Clear CSS
            commands.push({ command: 'editStyles', params: { content: '/* Fresh Start - CSS cleared */\n' } });
            
            return commands;
        } catch (error) {
            console.error('Error generating fresh start commands:', error);
            return [];
        }
    }
    
    // Execute commands (with optional fresh start)
    async function executeCommands(withFreshStart = true) {
        commandPreviewSection.style.display = 'none';
        executionResultsSection.style.display = 'block';
        executionProgress.style.display = 'flex';
        executionResults.innerHTML = '';
        
        let allCommands = [...parsedCommands];
        let freshStartCount = 0;
        
        // If fresh start is enabled, prepend fresh start commands
        if (withFreshStart) {
            progressText.textContent = 'Analyzing project for Fresh Start...';
            const freshStartCommands = await generateFreshStartCommands();
            freshStartCount = freshStartCommands.length;
            allCommands = [...freshStartCommands, ...parsedCommands];
        }
        
        // Create result items for all commands
        allCommands.forEach((cmd, index) => {
            const isFreshStart = index < freshStartCount;
            const item = document.createElement('div');
            item.className = 'result-item result-item--pending';
            item.id = 'result-' + index;
            item.innerHTML = `
                <span class="result-item__icon">⏳</span>
                <div class="result-item__content">
                    <div class="result-item__command">${isFreshStart ? '🧹 ' : ''}${cmd.command}</div>
                    <div class="result-item__message">${isFreshStart ? 'Fresh Start: ' : ''}Pending...</div>
                </div>
            `;
            executionResults.appendChild(item);
        });
        
        // Add separator after fresh start commands
        if (freshStartCount > 0) {
            const separator = document.createElement('div');
            separator.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
            separator.textContent = '— AI Commands —';
            executionResults.insertBefore(separator, executionResults.children[freshStartCount]);
        }
        
        const managementUrl = '<?= rtrim(BASE_URL, '/') ?>/management/';
        const token = '<?= $router->getToken() ?>';
        
        for (let i = 0; i < allCommands.length; i++) {
            const cmd = allCommands[i];
            const isFreshStart = i < freshStartCount;
            progressText.textContent = `${isFreshStart ? '🧹 Fresh Start: ' : ''}Executing command ${i + 1} of ${allCommands.length}: ${cmd.command}`;
            
            const resultItem = document.getElementById('result-' + i);
            
            try {
                const method = getMethod(cmd);
                const url = managementUrl + cmd.command;
                
                const options = {
                    method: method,
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    }
                };
                
                if (method !== 'GET' && cmd.params) {
                    options.body = JSON.stringify(cmd.params);
                }
                
                const response = await fetch(url, options);
                const data = await response.json();
                
                // API returns status codes: 200 (OK), 201 (Created), etc.
                // Check for any 2xx status or success:true
                const isSuccess = (data.status >= 200 && data.status < 300) || data.success === true;
                const errorMsg = data.error || data.message || 'Failed';
                
                if (isSuccess) {
                    resultItem.className = 'result-item result-item--success';
                    resultItem.innerHTML = `
                        <span class="result-item__icon">✅</span>
                        <div class="result-item__content">
                            <div class="result-item__command">${isFreshStart ? '🧹 ' : ''}${cmd.command}</div>
                            <div class="result-item__message">${data.message || 'Success'}</div>
                        </div>
                    `;
                } else {
                    resultItem.className = 'result-item result-item--error';
                    resultItem.innerHTML = `
                        <span class="result-item__icon">❌</span>
                        <div class="result-item__content">
                            <div class="result-item__command">${isFreshStart ? '🧹 ' : ''}${cmd.command}</div>
                            <div class="result-item__error">${errorMsg}</div>
                        </div>
                    `;
                }
            } catch (error) {
                resultItem.className = 'result-item result-item--error';
                resultItem.innerHTML = `
                    <span class="result-item__icon">❌</span>
                    <div class="result-item__content">
                        <div class="result-item__command">${isFreshStart ? '🧹 ' : ''}${cmd.command}</div>
                        <div class="result-item__error">${error.message}</div>
                    </div>
                `;
            }
        }
        
        executionProgress.style.display = 'none';
    }
    
    // Execute commands button
    executeBtn.addEventListener('click', async function() {
        const shouldFreshStart = isCreateSpec && freshStartCheckbox.checked;
        
        // If it's a create spec and fresh start is unchecked, show warning
        if (isCreateSpec && !freshStartCheckbox.checked) {
            pendingExecution = true;
            freshStartModal.style.display = 'flex';
            return;
        }
        
        // Execute with fresh start if applicable
        executeCommands(shouldFreshStart);
    });
    
    // Reset workflow
    resetBtn.addEventListener('click', function() {
        executionResultsSection.style.display = 'none';
        aiResponseSection.style.display = 'block';
        aiResponseInput.value = '';
        previewBtn.style.display = 'none';
        parsedCommands = [];
    });
})();
</script>
