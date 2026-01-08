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
    background: var(--admin-bg);
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
    overflow: hidden;
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

/* Node Selector */
.ai-spec-node-selector {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.ai-spec-node-selector__mode {
    display: flex;
    gap: var(--space-md);
    margin-bottom: var(--space-xs);
}

.ai-spec-node-selector__mode-option {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--font-size-sm);
    cursor: pointer;
}

.ai-spec-node-selector__mode-option input {
    cursor: pointer;
}

.ai-spec-node-selector__select-ui {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.ai-spec-node-selector__select-ui select {
    width: 100%;
}

.ai-spec-node-selector__hint-ui input {
    width: 100%;
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
    max-width: 100%;
    min-height: 150px;
    padding: var(--space-md);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    line-height: 1.6;
    background: var(--admin-bg);
    color: var(--admin-text);
    resize: vertical;
    box-sizing: border-box;
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
    max-width: 100%;
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
    box-sizing: border-box;
}

.ai-spec-prompt__actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-md);
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-sm);
}

.ai-spec-prompt__hint {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    padding: var(--space-xs) var(--space-sm);
    background: var(--admin-bg-tertiary);
    border-radius: var(--radius-sm);
}

.ai-spec-prompt__stats {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    display: flex;
    align-items: center;
    gap: var(--space-md);
}

/* API Integration Section (optional) */
.api-integration-wrapper {
    width: 100%;
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px dashed var(--admin-border);
}

.api-integration-divider {
    display: flex;
    align-items: center;
    margin-bottom: var(--space-sm);
}

.api-integration-divider span {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.api-integration-controls {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.api-integration-controls select {
    min-width: 200px;
    max-width: 280px;
    font-size: var(--font-size-sm);
}

.api-integration-controls select optgroup {
    font-weight: 600;
    color: var(--admin-text);
}

.api-integration-controls select option {
    font-weight: 400;
    padding-left: var(--space-sm);
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

/* Auto options */
.ai-auto-options {
    display: flex;
    gap: var(--space-md);
    margin-left: auto;
    align-items: center;
}

.ai-auto-option {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    cursor: pointer;
    user-select: none;
}

.ai-auto-option input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--admin-primary);
}

.ai-auto-option:hover {
    color: var(--admin-text);
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

.command-item__badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    background: #e0e7ff;
    color: #4338ca;
    text-transform: uppercase;
}

.command-item--post {
    background: var(--admin-bg-alt);
    border-left: 3px solid #6366f1;
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

/* Expandable details */
.result-item__toggle {
    font-size: 11px;
    color: var(--admin-primary);
    cursor: pointer;
    margin-left: 8px;
    opacity: 0.7;
    user-select: none;
}

.result-item__toggle:hover {
    opacity: 1;
    text-decoration: underline;
}

.result-item__details {
    display: none;
    margin-top: 8px;
    padding: 8px;
    background: #f8fafc;
    border-radius: 4px;
    font-family: monospace;
    font-size: 11px;
    max-height: 200px;
    overflow-y: auto;
}

.result-item__details.expanded {
    display: block;
}

.result-item__details-section {
    margin-bottom: 6px;
}

.result-item__details-label {
    font-weight: 600;
    color: #64748b;
    margin-bottom: 2px;
}

.result-item__details-value {
    white-space: pre-wrap;
    word-break: break-all;
    color: #334155;
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
                            <?php elseif (($param['type'] ?? 'text') === 'select' && (isset($param['options']) || isset($param['optionsFrom']))): ?>
                            <?php
                            // Build options from static list or dynamic data
                            $selectOptions = [];
                            
                            // Static options
                            if (isset($param['options'])) {
                                $selectOptions = $param['options'];
                            }
                            
                            // Dynamic options from data requirements
                            if (isset($param['optionsFrom'])) {
                                $dataKey = $param['optionsFrom']['data'] ?? $param['optionsFrom'];
                                $valueField = $param['optionsFrom']['value'] ?? null;
                                $labelField = $param['optionsFrom']['label'] ?? null;
                                $prependOptions = $param['optionsFrom']['prepend'] ?? [];
                                
                                // Get data from specData
                                $dynamicData = $specData[$dataKey] ?? [];
                                
                                // Add prepended options first (e.g., "root" for routes)
                                foreach ($prependOptions as $prependOpt) {
                                    $selectOptions[] = $prependOpt;
                                }
                                
                                // Add dynamic options
                                if (is_array($dynamicData)) {
                                    foreach ($dynamicData as $key => $item) {
                                        if (is_array($item) && $valueField) {
                                            // Data is array of objects
                                            $selectOptions[] = [
                                                'value' => $item[$valueField] ?? $key,
                                                'label' => $labelField ? ($item[$labelField] ?? $item[$valueField] ?? $key) : ($item[$valueField] ?? $key)
                                            ];
                                        } else {
                                            // Data is simple array
                                            $selectOptions[] = is_string($item) ? $item : (string)$key;
                                        }
                                    }
                                }
                            }
                            ?>
                            <select 
                                id="param-<?= htmlspecialchars($param['id']) ?>"
                                name="<?= htmlspecialchars($param['id']) ?>"
                                class="ai-spec-form__input"
                                <?= ($param['required'] ?? false) ? 'required' : '' ?>
                            >
                                <option value=""><?= __admin($param['placeholderKey'] ?? '', '-- Select --') ?></option>
                                <?php foreach ($selectOptions as $opt): ?>
                                <?php 
                                    // Handle both simple strings and objects with value/label or value/labelKey
                                    if (is_array($opt)) {
                                        $optValue = $opt['value'] ?? '';
                                        $optLabel = isset($opt['labelKey']) ? __admin($opt['labelKey'], $opt['value'] ?? '') : ($opt['label'] ?? $optValue);
                                    } else {
                                        $optValue = $opt;
                                        $optLabel = $opt;
                                    }
                                ?>
                                <option value="<?= htmlspecialchars($optValue) ?>">
                                    <?= htmlspecialchars($optLabel) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php elseif (($param['type'] ?? 'text') === 'nodeSelector'): ?>
                            <?php
                            // Node selector: allows selecting from page/menu/footer structures OR using text hint
                            $structureTypes = $param['structures'] ?? ['page', 'menu', 'footer'];
                            $allowTextHint = $param['allowTextHint'] ?? true;
                            $includePages = in_array('page', $structureTypes);
                            ?>
                            <div class="ai-spec-node-selector" id="node-selector-<?= htmlspecialchars($param['id']) ?>">
                                <!-- Mode toggle -->
                                <div class="ai-spec-node-selector__mode">
                                    <label class="ai-spec-node-selector__mode-option">
                                        <input type="radio" name="<?= htmlspecialchars($param['id']) ?>_mode" value="select" checked>
                                        <?= __admin('ai.spec.nodeSelector.selectMode', 'Select node') ?>
                                    </label>
                                    <?php if ($allowTextHint): ?>
                                    <label class="ai-spec-node-selector__mode-option">
                                        <input type="radio" name="<?= htmlspecialchars($param['id']) ?>_mode" value="hint">
                                        <?= __admin('ai.spec.nodeSelector.hintMode', 'Describe position') ?>
                                    </label>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Select mode UI -->
                                <div class="ai-spec-node-selector__select-ui">
                                    <!-- Structure type -->
                                    <select class="ai-spec-form__input ai-spec-node-selector__structure" name="<?= htmlspecialchars($param['id']) ?>_structure">
                                        <option value=""><?= __admin('ai.spec.nodeSelector.selectStructure', '-- Select structure type --') ?></option>
                                        <?php if ($includePages && !empty($specData['routes'])): ?>
                                        <option value="page"><?= __admin('ai.spec.nodeSelector.page', 'Page') ?></option>
                                        <?php endif; ?>
                                        <?php if (in_array('menu', $structureTypes) && !empty($specData['menuStructure'])): ?>
                                        <option value="menu"><?= __admin('ai.spec.nodeSelector.menu', 'Menu') ?></option>
                                        <?php endif; ?>
                                        <?php if (in_array('footer', $structureTypes) && !empty($specData['footerStructure'])): ?>
                                        <option value="footer"><?= __admin('ai.spec.nodeSelector.footer', 'Footer') ?></option>
                                        <?php endif; ?>
                                    </select>
                                    
                                    <!-- Page selection (only shown when structure=page) -->
                                    <select class="ai-spec-form__input ai-spec-node-selector__page" name="<?= htmlspecialchars($param['id']) ?>_page" style="display: none;" disabled>
                                        <option value=""><?= __admin('ai.spec.nodeSelector.selectPage', '-- Select page --') ?></option>
                                        <?php if (!empty($specData['routes'])): ?>
                                        <?php foreach ($specData['routes'] as $route): ?>
                                        <?php if (is_string($route)): ?>
                                        <option value="<?= htmlspecialchars($route) ?>"><?= htmlspecialchars($route) ?></option>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    
                                    <!-- Node selection (populated by JS based on structure) -->
                                    <select class="ai-spec-form__input ai-spec-node-selector__node" name="<?= htmlspecialchars($param['id']) ?>_nodeId" disabled>
                                        <option value=""><?= __admin('ai.spec.nodeSelector.selectNode', '-- Select node --') ?></option>
                                    </select>
                                    
                                    <!-- Action -->
                                    <select class="ai-spec-form__input ai-spec-node-selector__action" name="<?= htmlspecialchars($param['id']) ?>_action" disabled>
                                        <option value=""><?= __admin('ai.spec.nodeSelector.selectAction', '-- Action --') ?></option>
                                        <option value="insertBefore"><?= __admin('ai.spec.nodeSelector.insertBefore', 'Insert before') ?></option>
                                        <option value="insertAfter"><?= __admin('ai.spec.nodeSelector.insertAfter', 'Insert after') ?></option>
                                        <option value="update"><?= __admin('ai.spec.nodeSelector.update', 'Update/Replace') ?></option>
                                        <option value="delete"><?= __admin('ai.spec.nodeSelector.delete', 'Delete') ?></option>
                                    </select>
                                </div>
                                
                                <!-- Text hint mode UI -->
                                <?php if ($allowTextHint): ?>
                                <div class="ai-spec-node-selector__hint-ui" style="display: none;">
                                    <input type="text" 
                                        class="ai-spec-form__input" 
                                        name="<?= htmlspecialchars($param['id']) ?>_hint"
                                        placeholder="<?= __admin('ai.spec.nodeSelector.hintPlaceholder', 'e.g., after the About link in the menu') ?>"
                                    />
                                </div>
                                <?php endif; ?>
                                
                                <!-- Hidden input for combined value -->
                                <input type="hidden" name="<?= htmlspecialchars($param['id']) ?>" id="param-<?= htmlspecialchars($param['id']) ?>" />
                            </div>
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
                        <!-- Primary Action: Copy (works for everyone) -->
                        <button type="button" id="copy-prompt" class="admin-btn admin-btn--primary" disabled>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                            </svg>
                            <span id="copy-prompt-text"><?= __admin('ai.spec.copyPrompt', 'Copy Prompt') ?></span>
                        </button>
                        
                        <!-- Hint for manual workflow -->
                        <span id="prompt-next-step" class="ai-spec-prompt__hint" style="display: none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16v-4"/>
                                <path d="M12 8h.01"/>
                            </svg>
                            <?= __admin('ai.spec.pasteInAi', 'Paste in ChatGPT, Gemini, or Claude') ?>
                        </span>
                        
                        <div class="ai-spec-prompt__stats">
                            <span id="char-count">0 <?= __admin('ai.spec.chars', 'chars') ?></span>
                            <span id="word-count">0 <?= __admin('ai.spec.words', 'words') ?></span>
                        </div>
                        
                        <!-- Optional: Direct AI Integration (for users with API keys) -->
                        <div id="api-integration-wrapper" class="api-integration-wrapper" style="display: none;">
                            <div class="api-integration-divider">
                                <span><?= __admin('ai.spec.orSendDirectly', 'or send directly') ?></span>
                            </div>
                            <div class="api-integration-controls">
                                <select id="provider-selector" class="admin-select admin-select--sm" title="<?= __admin('ai.spec.selectModel', 'Select model') ?>">
                                </select>
                                <button type="button" id="send-to-ai" class="admin-btn admin-btn--secondary" disabled>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 2L11 13"/>
                                        <path d="M22 2l-7 20-4-9-9-4 20-7z"/>
                                    </svg>
                                    <span id="send-to-ai-text"><?= __admin('ai.spec.sendToAi', 'Send') ?></span>
                                </button>
                            </div>
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
                        <div class="ai-auto-options">
                            <label class="ai-auto-option">
                                <input type="checkbox" id="auto-preview-checkbox">
                                <span><?= __admin('ai.spec.autoPreview', 'Auto preview') ?></span>
                            </label>
                            <label class="ai-auto-option">
                                <input type="checkbox" id="auto-execute-checkbox">
                                <span><?= __admin('ai.spec.autoExecute', 'Auto execute') ?></span>
                            </label>
                        </div>
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
    const sendToAiBtn = document.getElementById('send-to-ai');
    const sendToAiText = document.getElementById('send-to-ai-text');
    const promptNextStep = document.getElementById('prompt-next-step');
    const charCount = document.getElementById('char-count');
    const wordCount = document.getElementById('word-count');
    const userPromptTextarea = document.getElementById('user-prompt');
    
    // AI storage keys (v2 - multi-provider support, must match ai-settings.php)
    const AI_STORAGE_KEYS = {
        keysV2: 'quicksite_ai_keys_v2',
        defaultProvider: 'quicksite_ai_default_provider',
        persist: 'quicksite_ai_persist',
        // Legacy v1 keys for migration
        legacyKey: 'quicksite_ai_key',
        legacyProvider: 'quicksite_ai_provider',
        legacyModel: 'quicksite_ai_model'
    };
    
    // Get AI configuration from storage (v2 format with v1 fallback)
    function getAiConfig() {
        const persist = localStorage.getItem(AI_STORAGE_KEYS.persist) === 'true';
        const storage = persist ? localStorage : sessionStorage;
        
        // Try v2 format first
        const storedData = storage.getItem(AI_STORAGE_KEYS.keysV2);
        const defaultProvider = storage.getItem(AI_STORAGE_KEYS.defaultProvider);
        
        if (storedData && defaultProvider) {
            try {
                const providers = JSON.parse(storedData);
                const provider = providers[defaultProvider];
                if (provider && provider.key) {
                    return {
                        key: provider.key,
                        provider: defaultProvider,
                        model: provider.defaultModel,
                        name: provider.name,
                        models: provider.models || [],
                        enabledModels: provider.enabledModels || provider.models || [],
                        configured: true,
                        allProviders: providers
                    };
                }
            } catch (e) {
                console.warn('Failed to parse AI config v2:', e);
            }
        }
        
        // Fallback to v1 format
        const legacyKey = storage.getItem(AI_STORAGE_KEYS.legacyKey);
        if (legacyKey) {
            return {
                key: legacyKey,
                provider: storage.getItem(AI_STORAGE_KEYS.legacyProvider),
                model: storage.getItem(AI_STORAGE_KEYS.legacyModel),
                configured: true,
                allProviders: null
            };
        }
        
        return {
            key: null,
            provider: null,
            model: null,
            configured: false,
            allProviders: null
        };
    }
    
    // Initialize provider selector for multi-provider support
    const apiIntegrationWrapper = document.getElementById('api-integration-wrapper');
    const providerSelector = document.getElementById('provider-selector');
    let selectedProviderId = null;
    let selectedModelId = null;
    
    // Known models for reference (with free tier info where applicable)
    // Note: Free tier availability changes - Google Gemini is currently the best free option
    const knownModels = {
        openai: {
            recommended: ['gpt-4o', 'gpt-4-turbo', 'gpt-4'],
            popular: ['gpt-4o-mini', 'gpt-3.5-turbo', 'o1', 'o1-mini'],
            freeTier: false // OpenAI requires billing
        },
        anthropic: {
            recommended: ['claude-sonnet-4-20250514', 'claude-3-5-sonnet-20241022', 'claude-3-opus-20240229'],
            popular: ['claude-3-5-haiku-20241022', 'claude-3-haiku-20240307'],
            freeTier: false // Anthropic requires billing
        },
        google: {
            recommended: ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
            popular: ['gemini-2.0-flash', 'gemini-1.0-pro'],
            freeTier: true, // Google offers generous free tier
            freeTierModels: ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-1.0-pro']
        },
        mistral: {
            recommended: ['mistral-large-latest', 'mistral-medium-latest'],
            popular: ['mistral-small-latest', 'open-mixtral-8x22b', 'open-mistral-7b'],
            freeTier: false // Mistral requires billing
        }
    };
    
    function initializeProviderSelector() {
        const aiConfig = getAiConfig();
        
        // If no providers configured, hide the API integration section entirely
        if (!aiConfig.configured || !aiConfig.allProviders) {
            apiIntegrationWrapper.style.display = 'none';
            selectedProviderId = aiConfig.provider;
            selectedModelId = aiConfig.model;
            return;
        }
        
        const providers = Object.entries(aiConfig.allProviders);
        
        // Show the API integration section
        apiIntegrationWrapper.style.display = 'block';
        providerSelector.innerHTML = '';
        
        // Build optgroups for each provider with their models
        providers.forEach(([providerId, data]) => {
            const optgroup = document.createElement('optgroup');
            const known = knownModels[providerId] || { recommended: [], popular: [], freeTier: false, freeTierModels: [] };
            
            // Add free tier indicator to provider label
            optgroup.label = data.name + (known.freeTier ? ' 🆓' : '');
            
            // Use enabledModels if available (user-filtered), otherwise all models
            const availableModels = data.enabledModels || data.models || [];
            
            // Sort models: recommended first, then popular, then rest
            const sortedModels = sortModels(availableModels, known.recommended, known.popular, known.freeTierModels || []);
            
            sortedModels.forEach(modelInfo => {
                const option = document.createElement('option');
                option.value = `${providerId}::${modelInfo.model}`;
                
                // Build label with badges
                let label = modelInfo.model;
                if (modelInfo.isRecommended) {
                    label = '⭐ ' + label;
                    if (modelInfo.isFreeTier) {
                        label += ' 🆓';
                    }
                } else if (modelInfo.isFreeTier) {
                    label = '🆓 ' + label;
                } else if (modelInfo.isNew) {
                    label = '🆕 ' + label;
                }
                
                option.textContent = label;
                
                // Select if this is the default model for the default provider
                if (providerId === aiConfig.provider && modelInfo.model === data.defaultModel) {
                    option.selected = true;
                    selectedProviderId = providerId;
                    selectedModelId = modelInfo.model;
                }
                
                optgroup.appendChild(option);
            });
            
            providerSelector.appendChild(optgroup);
        });
        
        // If no selection was made, select the first option
        if (!selectedProviderId && providerSelector.options.length > 0) {
            providerSelector.selectedIndex = 0;
            const firstValue = providerSelector.value;
            if (firstValue) {
                [selectedProviderId, selectedModelId] = firstValue.split('::');
            }
        }
    }
    
    // Sort models: recommended first, then popular, then rest (mark new and free tier ones)
    function sortModels(availableModels, recommended, popular, freeTierModels = []) {
        const allKnown = [...recommended, ...popular];
        
        return availableModels.map(model => {
            const isRecommended = recommended.includes(model);
            const isPopular = popular.includes(model);
            const isNew = !allKnown.some(known => model.includes(known) || known.includes(model));
            const isFreeTier = freeTierModels.some(free => model.includes(free) || free.includes(model));
            
            return {
                model,
                isRecommended,
                isPopular,
                isNew,
                isFreeTier,
                sortOrder: isRecommended ? 0 : (isPopular ? 1 : (isNew ? 3 : 2))
            };
        }).sort((a, b) => a.sortOrder - b.sortOrder);
    }
    
    // Handle provider/model selection change
    providerSelector.addEventListener('change', function() {
        const value = this.value;
        if (value && value.includes('::')) {
            [selectedProviderId, selectedModelId] = value.split('::');
        }
    });
    
    // Get config for a specific provider with optional model override
    function getProviderConfig(providerId, modelOverride = null) {
        const aiConfig = getAiConfig();
        if (!aiConfig.allProviders || !aiConfig.allProviders[providerId]) {
            return aiConfig; // Fallback to default
        }
        const provider = aiConfig.allProviders[providerId];
        return {
            key: provider.key,
            provider: providerId,
            model: modelOverride || provider.defaultModel,
            name: provider.name,
            configured: true
        };
    }
    
    // Initialize on page load
    initializeProviderSelector();
    
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
    
    // === Node Selector Functionality ===
    function initNodeSelectors() {
        document.querySelectorAll('.ai-spec-node-selector').forEach(selector => {
            const paramId = selector.id.replace('node-selector-', '');
            const modeRadios = selector.querySelectorAll(`input[name="${paramId}_mode"]`);
            const selectUI = selector.querySelector('.ai-spec-node-selector__select-ui');
            const hintUI = selector.querySelector('.ai-spec-node-selector__hint-ui');
            const structureSelect = selector.querySelector('.ai-spec-node-selector__structure');
            const pageSelect = selector.querySelector('.ai-spec-node-selector__page');
            const nodeSelect = selector.querySelector('.ai-spec-node-selector__node');
            const actionSelect = selector.querySelector('.ai-spec-node-selector__action');
            const hintInput = selector.querySelector(`input[name="${paramId}_hint"]`);
            const hiddenInput = selector.querySelector(`input[name="${paramId}"]`);
            
            // Mode toggle
            modeRadios.forEach(radio => {
                radio.addEventListener('change', () => {
                    const isSelectMode = radio.value === 'select';
                    if (selectUI) selectUI.style.display = isSelectMode ? 'flex' : 'none';
                    if (hintUI) hintUI.style.display = isSelectMode ? 'none' : 'block';
                    updateHiddenValue();
                });
            });
            
            // Load nodes using the same API as command-form (structure-nodes endpoint)
            async function loadNodeOptions(structureType, pageName = null) {
                nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.loading', 'Loading...') ?></option>';
                nodeSelect.disabled = true;
                actionSelect.disabled = true;
                
                if (!structureType) {
                    nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.selectNode', '-- Select node --') ?></option>';
                    return;
                }
                
                // Build params for structure-nodes API
                const params = (structureType === 'page') ? [structureType, pageName] : [structureType];
                
                if (structureType === 'page' && !pageName) {
                    nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.selectPageFirst', '-- Select page first --') ?></option>';
                    return;
                }
                
                try {
                    const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
                    nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.selectNode', '-- Select node --') ?></option>';
                    
                    if (nodes && nodes.length > 0) {
                        QuickSiteAdmin.appendOptionsToSelect(nodeSelect, nodes);
                        nodeSelect.disabled = false;
                    } else {
                        nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.noNodes', 'No nodes found') ?></option>';
                    }
                } catch (error) {
                    console.error('Failed to load nodes:', error);
                    nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.error', 'Error loading nodes') ?></option>';
                }
                
                updateHiddenValue();
            }
            
            // Structure selection change
            if (structureSelect) {
                structureSelect.addEventListener('change', async () => {
                    const structureType = structureSelect.value;
                    
                    // Show/hide page select based on structure type
                    if (pageSelect) {
                        if (structureType === 'page') {
                            pageSelect.style.display = 'block';
                            pageSelect.disabled = false;
                            nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.selectPageFirst', '-- Select page first --') ?></option>';
                            nodeSelect.disabled = true;
                            actionSelect.disabled = true;
                        } else {
                            pageSelect.style.display = 'none';
                            pageSelect.disabled = true;
                            pageSelect.value = '';
                            // Load nodes directly for menu/footer
                            if (structureType) {
                                await loadNodeOptions(structureType);
                            }
                        }
                    } else if (structureType) {
                        // No page select, load nodes directly
                        await loadNodeOptions(structureType);
                    }
                    
                    if (!structureType) {
                        nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.selectNode', '-- Select node --') ?></option>';
                        nodeSelect.disabled = true;
                        actionSelect.disabled = true;
                    }
                    
                    updateHiddenValue();
                });
            }
            
            // Page selection change (for page structure type)
            if (pageSelect) {
                pageSelect.addEventListener('change', async () => {
                    const pageName = pageSelect.value;
                    if (pageName) {
                        await loadNodeOptions('page', pageName);
                    } else {
                        nodeSelect.innerHTML = '<option value=""><?= __admin('ai.spec.nodeSelector.selectPageFirst', '-- Select page first --') ?></option>';
                        nodeSelect.disabled = true;
                        actionSelect.disabled = true;
                    }
                    updateHiddenValue();
                });
            }
            
            // Node selection change
            if (nodeSelect) {
                nodeSelect.addEventListener('change', () => {
                    actionSelect.disabled = !nodeSelect.value;
                    updateHiddenValue();
                });
            }
            
            // Action selection change
            if (actionSelect) {
                actionSelect.addEventListener('change', updateHiddenValue);
            }
            
            // Hint input change
            if (hintInput) {
                hintInput.addEventListener('input', updateHiddenValue);
            }
            
            // Update hidden value with combined data
            function updateHiddenValue() {
                const mode = selector.querySelector(`input[name="${paramId}_mode"]:checked`)?.value || 'select';
                
                if (mode === 'hint') {
                    hiddenInput.value = JSON.stringify({
                        mode: 'hint',
                        hint: hintInput?.value || ''
                    });
                } else {
                    const structure = structureSelect?.value || '';
                    const page = pageSelect?.value || '';
                    const nodeId = nodeSelect?.value || '';
                    const action = actionSelect?.value || '';
                    
                    if (structure === 'page') {
                        if (page && nodeId && action) {
                            hiddenInput.value = JSON.stringify({
                                mode: 'select',
                                structure: 'page',
                                page: page,
                                nodeId: nodeId,
                                action: action
                            });
                        } else {
                            hiddenInput.value = '';
                        }
                    } else if (structure && nodeId && action) {
                        hiddenInput.value = JSON.stringify({
                            mode: 'select',
                            structure: structure,
                            nodeId: nodeId,
                            action: action
                        });
                    } else {
                        hiddenInput.value = '';
                    }
                }
            }
        });
    }
    
    // Initialize node selectors
    initNodeSelectors();
    
    // Update stats
    function updateStats(text) {
        const chars = text.length;
        const words = text.trim() ? text.trim().split(/\s+/).length : 0;
        charCount.textContent = chars.toLocaleString() + ' <?= __admin('ai.spec.chars', 'chars') ?>';
        wordCount.textContent = words.toLocaleString() + ' <?= __admin('ai.spec.words', 'words') ?>';
    }
    
    // Generate prompt
    let specPostCommands = []; // Store post-commands from spec
    let specPostCommandsRaw = []; // Raw definitions for late resolution
    let specUserParams = {}; // User params for late resolution
    let specPreCommandsExecuted = false; // Track if preCommands have been executed
    
    // Execute preCommands before prompt generation
    async function executePreCommands(preCommands) {
        if (!preCommands || preCommands.length === 0) {
            return { success: true, results: [] };
        }
        
        const managementUrl = '<?= rtrim(BASE_URL, '/') ?>/management/';
        const token = '<?= $router->getToken() ?>';
        const results = [];
        
        for (const cmd of preCommands) {
            try {
                const cmdUrl = managementUrl + cmd.command + (cmd.urlParams?.length ? '/' + cmd.urlParams.join('/') : '');
                
                const response = await fetch(cmdUrl, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(cmd.params || {})
                });
                
                const data = await response.json();
                
                results.push({
                    command: cmd.command,
                    success: response.ok && data.status < 400,
                    data: data,
                    abortOnFail: cmd.abortOnFail !== false // Default to true
                });
                
                // Check if this command should abort on failure
                if (!response.ok || data.status >= 400) {
                    if (cmd.abortOnFail !== false) {
                        return {
                            success: false,
                            error: data.message || `Command ${cmd.command} failed`,
                            errorData: data,
                            failedCommand: cmd,
                            results: results
                        };
                    }
                }
            } catch (error) {
                results.push({
                    command: cmd.command,
                    success: false,
                    error: error.message
                });
                
                if (cmd.abortOnFail !== false) {
                    return {
                        success: false,
                        error: error.message,
                        failedCommand: cmd,
                        results: results
                    };
                }
            }
        }
        
        return { success: true, results: results };
    }
    
    async function generatePrompt(params = {}) {
        promptLoading.style.display = 'flex';
        promptOutput.style.display = 'none';
        copyBtn.disabled = true;
        sendToAiBtn.disabled = true;
        promptNextStep.style.display = 'none';
        specPreCommandsExecuted = false;
        
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
                // Execute preCommands first (if any)
                const preCommands = data.data.preCommands || [];
                if (preCommands.length > 0) {
                    const loadingText = promptLoading.querySelector('span');
                    if (loadingText) {
                        loadingText.textContent = '<?= __admin('ai.spec.executingPreCommands', 'Executing pre-commands...') ?>';
                    }
                    
                    const preResult = await executePreCommands(preCommands);
                    
                    if (!preResult.success) {
                        // PreCommand failed - show error and abort
                        let errorMessage = '❌ <?= __admin('ai.spec.preCommandFailed', 'Pre-command failed') ?>: ' + preResult.error;
                        
                        // Special handling for route already exists
                        if (preResult.failedCommand?.command === 'addRoute' && 
                            preResult.errorData?.message?.includes('already exists')) {
                            errorMessage += '\n\n💡 <?= __admin('ai.spec.routeExistsSuggestion', 'This route already exists. Use the Edit Page spec to modify an existing page.') ?>';
                        }
                        
                        promptOutput.value = errorMessage;
                        promptLoading.style.display = 'none';
                        promptOutput.style.display = 'block';
                        updateStats('');
                        return;
                    }
                    
                    specPreCommandsExecuted = true;
                    
                    // Wait for filesystem to settle after preCommands (route/file creation)
                    await new Promise(resolve => setTimeout(resolve, 1500));
                }
                
                // Store post-commands info for later execution
                // Note: postCommands may not be fully resolved if they depend on config that AI will set
                specPostCommands = data.data.postCommands || [];
                specPostCommandsRaw = data.data.postCommandsRaw || [];
                specUserParams = data.data.userParams || {};
                
                // Get user's custom prompt
                const userPrompt = userPromptTextarea ? userPromptTextarea.value.trim() : '';
                
                // Combine spec prompt with user prompt
                let finalPrompt = data.data.prompt;
                if (userPrompt) {
                    finalPrompt += '\n\n---\n\n**User Request:**\n' + userPrompt;
                }
                
                promptOutput.value = finalPrompt;
                copyBtn.disabled = false;
                sendToAiBtn.disabled = false;
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
    
    // Copy button - primary action for manual workflow
    const copyPromptText = document.getElementById('copy-prompt-text');
    // promptNextStep already declared earlier in the file
    
    copyBtn.addEventListener('click', async function() {
        try {
            await navigator.clipboard.writeText(promptOutput.value);
            const originalText = copyPromptText.textContent;
            copyPromptText.textContent = '<?= __admin('ai.spec.copied', 'Copied!') ?>';
            this.classList.add('admin-btn--success');
            
            // Show the hint for next step
            promptNextStep.style.display = 'flex';
            
            // Also show the AI Response section for pasting
            const aiResponseSection = document.querySelector('.ai-response-section');
            if (aiResponseSection) {
                aiResponseSection.style.display = 'block';
                aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            
            setTimeout(() => { 
                copyPromptText.textContent = originalText;
                this.classList.remove('admin-btn--success');
            }, 3000);
        } catch (err) {
            // Fallback
            promptOutput.select();
            document.execCommand('copy');
        }
    });
    
    // Send to AI button
    sendToAiBtn.addEventListener('click', async function() {
        const baseConfig = getAiConfig();
        
        // Check if AI is configured
        if (!baseConfig.configured) {
            if (confirm('<?= __admin('ai.spec.configureAiFirst', 'No AI API key configured. Would you like to configure it now?') ?>')) {
                window.location.href = '<?= $router->getBaseUrl() ?>/admin/ai-settings';
            }
            return;
        }
        
        // Get the selected provider's config (for multi-provider support)
        // Get the selected provider's config with the selected model
        const aiConfig = selectedProviderId 
            ? getProviderConfig(selectedProviderId, selectedModelId) 
            : baseConfig;
        
        const prompt = promptOutput.value;
        if (!prompt) {
            alert('<?= __admin('ai.spec.noPrompt', 'Please generate a prompt first.') ?>');
            return;
        }
        
        // Reset UI state - show fresh AI Response section, hide previous results
        const aiResponseSection = document.querySelector('.ai-response-section');
        const aiResponseInput = document.getElementById('ai-response-input');
        const executionResultsSection = document.querySelector('.execution-results-section');
        
        if (aiResponseSection) {
            aiResponseSection.style.display = 'block';
            if (aiResponseInput) aiResponseInput.value = '';
        }
        if (executionResultsSection) {
            executionResultsSection.style.display = 'none';
        }
        
        // Show loading state with timer
        const originalText = sendToAiText.textContent;
        sendToAiBtn.disabled = true;
        
        // Start countdown timer
        const maxTimeout = 180; // 3 minutes max
        let elapsedSeconds = 0;
        let abortController = new AbortController();
        
        const formatTime = (seconds) => {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        };
        
        const updateTimer = () => {
            elapsedSeconds++;
            const remaining = maxTimeout - elapsedSeconds;
            if (remaining >= 0) {
                sendToAiText.textContent = `⏳ ${formatTime(elapsedSeconds)} / ${formatTime(maxTimeout)}`;
            }
        };
        
        const timerInterval = setInterval(updateTimer, 1000);
        sendToAiText.textContent = `⏳ 0:00 / ${formatTime(maxTimeout)}`;
        
        // Add cancel functionality - change button temporarily
        const originalOnClick = sendToAiBtn.onclick;
        sendToAiBtn.disabled = false;
        sendToAiBtn.classList.add('admin-btn--danger');
        sendToAiBtn.onclick = () => {
            abortController.abort();
            clearInterval(timerInterval);
            sendToAiText.textContent = originalText;
            sendToAiBtn.disabled = false;
            sendToAiBtn.classList.remove('admin-btn--danger');
            sendToAiBtn.onclick = originalOnClick;
        };
        
        try {
            const response = await fetch('<?= rtrim(BASE_URL, '/') ?>/management/callAi', {
                signal: abortController.signal,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer <?= $router->getToken() ?>'
                },
                body: JSON.stringify({
                    key: aiConfig.key,
                    provider: aiConfig.provider,
                    model: selectedModelId || aiConfig.model,
                    messages: [
                        { role: 'user', content: prompt }
                    ],
                    // Request enough tokens for complex JSON responses
                    max_tokens: 16384,
                    // Allow up to 3 minutes for large responses
                    timeout: 180
                })
            });
            
            const data = await response.json();
            
            // Stop timer and restore button
            clearInterval(timerInterval);
            sendToAiBtn.classList.remove('admin-btn--danger');
            sendToAiBtn.onclick = null;
            
            // Log full response for debugging
            console.log('AI Response:', data);
            console.log(`⏱️ AI request completed in ${formatTime(elapsedSeconds)}`);
            
            // Log rate limit info if available
            if (data.data?.rate_limits) {
                console.log('📊 Rate Limits:', data.data.rate_limits);
            }
            
            // Log usage info
            if (data.data?.usage) {
                console.log('📈 Token Usage:', data.data.usage);
            }
            
            // Check for success using status code (2xx = success)
            const isSuccess = data.status >= 200 && data.status < 300;
            
            if (isSuccess) {
                let content = data.data?.content || '';
                
                // If no content but has debug info, log it
                if (!content && data.data?.debug) {
                    console.warn('AI response parsing issue:', data.data.debug);
                    content = '/* DEBUG: Content could not be parsed. Raw response: */\n' + (data.data.debug.raw_response_preview || 'N/A');
                }
                
                // Strip markdown code fences if present (```json ... ```)
                content = content.trim();
                if (content.startsWith('```')) {
                    // Remove opening fence (```json, ```JSON, ``` etc.)
                    content = content.replace(/^```[a-zA-Z]*\n?/, '');
                    // Remove closing fence
                    content = content.replace(/\n?```\s*$/, '');
                }
                
                // Update AI response textarea (section already visible from reset)
                const validateBtn = document.getElementById('validate-response');
                
                if (aiResponseInput) {
                    aiResponseInput.value = content;
                    
                    // Auto-scroll to response section
                    aiResponseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    
                    // Trigger auto-preview/execute flow if enabled
                    setTimeout(() => {
                        triggerAutoFlow();
                    }, 300);
                    
                    // Show success feedback with time taken
                    sendToAiText.textContent = `✅ ${formatTime(elapsedSeconds)}`;
                    setTimeout(() => {
                        sendToAiText.textContent = originalText;
                        sendToAiBtn.disabled = false;
                    }, 3000);
                }
            } else {
                // Show error with helpful hints
                const errorMsg = data.error || data.message || '<?= __admin('ai.spec.aiError', 'Failed to get AI response') ?>';
                
                // Provide helpful hints for common errors
                let helpHint = '';
                const errLower = errorMsg.toLowerCase();
                if (errLower.includes('quota') || errLower.includes('exceeded') || errLower.includes('limit')) {
                    helpHint = '\n\n💡 This usually means:\n• OpenAI: Add billing/credits to your account\n• Google: Try "gemini-1.5-flash" (better free tier)\n• The model you selected may not be available on free tier';
                } else if (errLower.includes('not found') || errLower.includes('does not exist')) {
                    helpHint = '\n\n💡 This model may not exist or be available. Try a different model from the dropdown.';
                } else if (errLower.includes('invalid') && errLower.includes('key')) {
                    helpHint = '\n\n💡 Your API key appears to be invalid. Check the key in AI Settings.';
                } else if (errLower.includes('rate')) {
                    helpHint = '\n\n💡 Rate limit reached. Wait a moment and try again.';
                }
                
                alert('<?= __admin('ai.spec.aiErrorPrefix', 'AI Error: ') ?>' + errorMsg + helpHint);
                sendToAiText.textContent = originalText;
                sendToAiBtn.disabled = false;
                sendToAiBtn.classList.remove('admin-btn--danger');
                sendToAiBtn.onclick = null;
            }
        } catch (error) {
            // Stop timer on error
            clearInterval(timerInterval);
            
            // Handle abort differently
            if (error.name === 'AbortError') {
                console.log('AI request cancelled by user');
                return; // Button already restored by cancel handler
            }
            
            alert('<?= __admin('ai.spec.networkError', 'Network error: ') ?>' + error.message);
            sendToAiText.textContent = originalText;
            sendToAiBtn.disabled = false;
            sendToAiBtn.classList.remove('admin-btn--danger');
            sendToAiBtn.onclick = null;
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
    const autoPreviewCheckbox = document.getElementById('auto-preview-checkbox');
    const autoExecuteCheckbox = document.getElementById('auto-execute-checkbox');
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
    
    // Auto options storage keys
    const AUTO_OPTIONS_KEYS = {
        autoPreview: 'quicksite_ai_auto_preview',
        autoExecute: 'quicksite_ai_auto_execute'
    };
    
    // Load auto options from localStorage
    function loadAutoOptions() {
        autoPreviewCheckbox.checked = localStorage.getItem(AUTO_OPTIONS_KEYS.autoPreview) === 'true';
        autoExecuteCheckbox.checked = localStorage.getItem(AUTO_OPTIONS_KEYS.autoExecute) === 'true';
    }
    
    // Save auto options to localStorage
    autoPreviewCheckbox.addEventListener('change', function() {
        localStorage.setItem(AUTO_OPTIONS_KEYS.autoPreview, this.checked);
    });
    
    autoExecuteCheckbox.addEventListener('change', function() {
        localStorage.setItem(AUTO_OPTIONS_KEYS.autoExecute, this.checked);
    });
    
    // Load settings on page load
    loadAutoOptions();
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
        let jsonText = aiResponseInput.value.trim();
        aiResponseError.style.display = 'none';
        previewBtn.style.display = 'none';
        
        if (!jsonText) {
            aiResponseError.textContent = 'Please paste the JSON response from the AI.';
            aiResponseError.style.display = 'block';
            return null;
        }
        
        // Strip markdown code fences if present (```json ... ```)
        if (jsonText.startsWith('```')) {
            jsonText = jsonText.replace(/^```[a-zA-Z]*\n?/, '').replace(/\n?```\s*$/, '');
            // Update the textarea with cleaned content
            aiResponseInput.value = jsonText;
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
                
                // Normalize command structure: if AI put params directly on command object, wrap them
                // Expected: { command: "X", params: { ... } }
                // AI might generate: { command: "X", code: "fr", name: "French" }
                if (!cmd.params) {
                    const params = {};
                    for (const [key, value] of Object.entries(cmd)) {
                        if (key !== 'command') {
                            params[key] = value;
                        }
                    }
                    if (Object.keys(params).length > 0) {
                        cmd.params = params;
                        // Remove the direct properties now that they're in params
                        for (const key of Object.keys(params)) {
                            delete cmd[key];
                        }
                        console.log(`Normalized command ${i}: ${cmd.command}`, cmd.params);
                    }
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
            // DON'T add postCommands here - they'll be resolved after AI commands execute
            // postCommands depend on config values that AI commands will set (e.g., LANGUAGES_NAME)
            parsedCommands = [...commands];
            previewBtn.style.display = 'inline-flex';
            aiResponseError.style.display = 'none';
            
            // Show info if postCommands will be added
            if (specPostCommandsRaw.length > 0) {
                console.log(`${specPostCommandsRaw.length} post-commands from spec will be resolved and executed after AI commands`);
            }
        }
    });
    
    // Trigger auto-preview and auto-execute flow
    function triggerAutoFlow() {
        const text = aiResponseInput.value.trim();
        if (!text) return;
        
        const commands = validateJson();
        if (commands) {
            // DON'T add postCommands here - they'll be resolved after AI commands execute
            parsedCommands = [...commands];
            previewBtn.style.display = 'inline-flex';
            aiResponseError.style.display = 'none';
            
            // Auto-preview if enabled
            if (autoPreviewCheckbox.checked) {
                previewBtn.click();
                
                // Auto-execute if enabled (with small delay for UX)
                if (autoExecuteCheckbox.checked) {
                    setTimeout(() => {
                        executeBtn.click();
                    }, 300);
                }
            }
        }
    }
    
    // Auto-validate on input change (debounced)
    let autoValidateTimeout = null;
    aiResponseInput.addEventListener('input', function() {
        // Clear previous timeout
        if (autoValidateTimeout) clearTimeout(autoValidateTimeout);
        
        // Debounce: wait 500ms after user stops typing
        autoValidateTimeout = setTimeout(() => {
            triggerAutoFlow();
        }, 500);
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
        
        // AI commands are all of parsedCommands (postCommands are resolved later)
        const aiCommandCount = parsedCommands.length;
        
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
        
        // Show placeholder for post-commands that will be resolved later
        if (specPostCommandsRaw.length > 0) {
            const separator = document.createElement('div');
            separator.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
            separator.textContent = '— Post-Commands (resolved after execution) —';
            commandList.appendChild(separator);
            
            specPostCommandsRaw.forEach((cmdDef, index) => {
                const item = document.createElement('div');
                item.className = 'command-item command-item--post';
                item.innerHTML = `
                    <span class="command-item__number">+${index + 1}</span>
                    <div class="command-item__content">
                        <div class="command-item__header">
                            <span class="command-item__method command-item__method--post">POST</span>
                            <span class="command-item__command">🔧 ${cmdDef.command || cmdDef.template || 'auto'}</span>
                            <span class="command-item__badge">Auto</span>
                        </div>
                        ${cmdDef.condition ? `<div class="command-item__params" style="font-style: italic;">Condition: ${cmdDef.condition}</div>` : ''}
                    </div>
                `;
                commandList.appendChild(item);
            });
        }
        
        commandCount.textContent = parsedCommands.length + ' command' + (parsedCommands.length > 1 ? 's' : '');
        if (specPostCommandsRaw.length > 0) {
            commandCount.textContent += ` (+${specPostCommandsRaw.length} auto)`;
        }
        
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
            try {
                const response = await fetch(url, options);
                const text = await response.text();
                return text ? JSON.parse(text) : { status: response.status, message: 'Empty response' };
            } catch (error) {
                console.error(`API call to ${command} failed:`, error);
                return { status: 500, message: error.message };
            }
        }
        
        try {
            // 1. Delete extra languages (keep only default)
            // Note: deleteLang removes the entire translation file, so no need for deleteTranslationKeys on those
            const langResponse = await apiCall('getLangList');
            let defaultLang = 'en'; // fallback
            if ((langResponse.status === 200 || langResponse.success) && langResponse.data) {
                defaultLang = langResponse.data.default_language;
                const allLangs = langResponse.data.languages || [];
                
                // Delete all non-default languages (this removes their translation files entirely)
                const langsToDelete = allLangs.filter(lang => lang !== defaultLang);
                for (const lang of langsToDelete) {
                    commands.push({ command: 'deleteLang', params: { code: lang } });
                }
            }
            
            // 1b. Disable multilingual mode (resets LANGUAGES_SUPPORTED to just default, cleans up config)
            commands.push({ command: 'setMultilingual', params: { enabled: false } });
            
            // 2. Delete routes (except 404 and home)
            // API returns: { routes: {nested object}, flat_routes: ["home", "about", "guides/installation", ...] }
            const routesResponse = await apiCall('getRoutes');
            if ((routesResponse.status === 200 || routesResponse.success) && routesResponse.data?.flat_routes) {
                const protectedRoutes = ['404', 'home'];
                // Sort by path length descending - delete children before parents to avoid cascade issues
                const routesToDelete = routesResponse.data.flat_routes
                    .filter(routeName => !protectedRoutes.includes(routeName))
                    .sort((a, b) => b.length - a.length);
                    
                for (const routeName of routesToDelete) {
                    commands.push({ command: 'deleteRoute', params: { route: routeName } });
                }
            }
            
            // 3. Delete all assets
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
            
            // 4. Delete ALL components (list them first to know what exists)
            const componentsResponse = await apiCall('listComponents');
            if ((componentsResponse.status === 200 || componentsResponse.success) && componentsResponse.data?.components) {
                console.log('Fresh Start: Found', componentsResponse.data.components.length, 'components to remove:', 
                    componentsResponse.data.components.map(c => c.name).join(', '));
                for (const component of componentsResponse.data.components) {
                    commands.push({ 
                        command: 'editStructure', 
                        params: { type: 'component', name: component.name, structure: [] } 
                    });
                }
            }
            
            // 5. Clear translation keys for DEFAULT language only (except 404.*)
            // Other languages are deleted entirely by deleteLang above
            const translationsResponse = await apiCall('getTranslations');
            if ((translationsResponse.status === 200 || translationsResponse.success) && translationsResponse.data?.translations) {
                const defaultLangKeys = translationsResponse.data.translations[defaultLang];
                if (defaultLangKeys) {
                    const topLevelKeys = Object.keys(defaultLangKeys).filter(key => key !== '404');
                    if (topLevelKeys.length > 0) {
                        commands.push({ 
                            command: 'deleteTranslationKeys', 
                            params: { language: defaultLang, keys: topLevelKeys } 
                        });
                    }
                }
            }
            
            // 6. Clear structures
            commands.push({ command: 'editStructure', params: { type: 'menu', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'footer', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'page', name: 'home', structure: [] } });
            
            // 7. Minimize 404 page
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
            
            // 8. Clear CSS
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
            
            // Add delay after Fresh Start completes to allow config.php to fully sync
            // This prevents race conditions where AI commands read stale config
            if (freshStartCount > 0 && i === freshStartCount) {
                progressText.textContent = '⏳ Waiting for config sync...';
                await new Promise(resolve => setTimeout(resolve, 1500));
            }
            
            progressText.textContent = `${isFreshStart ? '🧹 Fresh Start: ' : ''}Executing command ${i + 1} of ${allCommands.length}: ${cmd.command}`;
            
            const resultItem = document.getElementById('result-' + i);
            
            try {
                // Normalize command structure: ensure params is properly set
                // AI might generate: { command: "addLang", code: "fr" } instead of { command: "addLang", params: { code: "fr" } }
                if (!cmd.params) {
                    const params = {};
                    for (const [key, value] of Object.entries(cmd)) {
                        if (key !== 'command') {
                            params[key] = value;
                        }
                    }
                    if (Object.keys(params).length > 0) {
                        cmd.params = params;
                    }
                }
                
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
                
                // Debug: log what's being sent
                console.log(`[${i}] ${cmd.command}:`, cmd.params ? JSON.stringify(cmd.params) : '(no params)');
                
                const response = await fetch(url, options);
                
                // Handle empty responses gracefully
                const responseText = await response.text();
                let data;
                try {
                    data = responseText ? JSON.parse(responseText) : { status: response.status, message: 'No response body' };
                } catch (parseError) {
                    data = { status: 500, message: 'Invalid JSON response: ' + responseText.substring(0, 100) };
                }
                
                // API returns status codes: 200 (OK), 201 (Created), 204 (No Content), etc.
                // Check for any 2xx status or success:true
                const isSuccess = (data.status >= 200 && data.status < 300) || data.success === true;
                
                // For fresh start cleanup, 404 (not found) is also acceptable - means nothing to delete
                const isAcceptableFailure = isFreshStart && data.status === 404;
                
                const errorMsg = data.error || data.message || 'Failed';
                
                // Helper to format params for display
                const formatParams = (params) => {
                    if (!params) return '(none)';
                    return JSON.stringify(params, null, 2);
                };
                
                // Helper to format response for display
                const formatResponse = (data) => {
                    const display = { ...data };
                    // Truncate very long data fields
                    if (display.data && JSON.stringify(display.data).length > 500) {
                        display.data = '(truncated - large data)';
                    }
                    return JSON.stringify(display, null, 2);
                };
                
                const detailsId = `details-${i}`;
                const paramsDisplay = formatParams(cmd.params);
                const responseDisplay = formatResponse(data);
                
                if (isSuccess || isAcceptableFailure) {
                    resultItem.className = 'result-item result-item--success';
                    resultItem.innerHTML = `
                        <span class="result-item__icon">${isAcceptableFailure ? '⏭️' : '✅'}</span>
                        <div class="result-item__content">
                            <div class="result-item__command">
                                ${isFreshStart ? '🧹 ' : ''}${cmd.command}
                                <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                            </div>
                            <div class="result-item__message">${isAcceptableFailure ? 'Skipped (not found)' : (data.message || 'Success')}</div>
                            <div class="result-item__details" id="${detailsId}">
                                <div class="result-item__details-section">
                                    <div class="result-item__details-label">Parameters:</div>
                                    <div class="result-item__details-value">${paramsDisplay}</div>
                                </div>
                                <div class="result-item__details-section">
                                    <div class="result-item__details-label">Response:</div>
                                    <div class="result-item__details-value">${responseDisplay}</div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    resultItem.className = 'result-item result-item--error';
                    resultItem.innerHTML = `
                        <span class="result-item__icon">❌</span>
                        <div class="result-item__content">
                            <div class="result-item__command">
                                ${isFreshStart ? '🧹 ' : ''}${cmd.command}
                                <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                            </div>
                            <div class="result-item__error">${errorMsg}</div>
                            <div class="result-item__details" id="${detailsId}">
                                <div class="result-item__details-section">
                                    <div class="result-item__details-label">Parameters:</div>
                                    <div class="result-item__details-value">${paramsDisplay}</div>
                                </div>
                                <div class="result-item__details-section">
                                    <div class="result-item__details-label">Response:</div>
                                    <div class="result-item__details-value">${responseDisplay}</div>
                                </div>
                            </div>
                        </div>
                    `;
                }
            } catch (error) {
                const detailsIdErr = `details-err-${i}`;
                const paramsDisplayErr = cmd.params ? JSON.stringify(cmd.params, null, 2) : '(none)';
                resultItem.className = 'result-item result-item--error';
                resultItem.innerHTML = `
                    <span class="result-item__icon">❌</span>
                    <div class="result-item__content">
                        <div class="result-item__command">
                            ${isFreshStart ? '🧹 ' : ''}${cmd.command}
                            <span class="result-item__toggle" onclick="document.getElementById('${detailsIdErr}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                        </div>
                        <div class="result-item__error">${error.message}</div>
                        <div class="result-item__details" id="${detailsIdErr}">
                            <div class="result-item__details-section">
                                <div class="result-item__details-label">Parameters:</div>
                                <div class="result-item__details-value">${paramsDisplayErr}</div>
                            </div>
                            <div class="result-item__details-section">
                                <div class="result-item__details-label">Error Stack:</div>
                                <div class="result-item__details-value">${error.stack || error.message}</div>
                            </div>
                        </div>
                    </div>
                `;
            }
        }
        
        // Wait for filesystem to settle after AI commands before post-commands
        if (specPostCommandsRaw.length > 0) {
            progressText.textContent = 'Waiting for filesystem to settle...';
            await new Promise(resolve => setTimeout(resolve, 1500));
        }
        
        // After all AI commands executed, resolve and execute post-commands
        if (specPostCommandsRaw.length > 0) {
            progressText.textContent = 'Resolving post-commands with fresh config...';
            
            // Add separator
            const postSeparator = document.createElement('div');
            postSeparator.style.cssText = 'text-align: center; padding: 8px; color: var(--admin-text-muted); font-size: 12px; border-top: 1px dashed var(--admin-border); margin-top: 8px;';
            postSeparator.textContent = '— Post-Commands (Auto-Generated) —';
            executionResults.appendChild(postSeparator);
            
            try {
                // Resolve post-commands with fresh config (after AI set languages etc.)
                const resolveResponse = await fetch('<?= $router->getBaseUrl() ?>/api/ai-spec-resolve-post', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        postCommandsRaw: specPostCommandsRaw,
                        userParams: specUserParams
                    })
                });
                
                const resolveData = await resolveResponse.json();
                const resolvedPostCommands = resolveData.success ? (resolveData.data?.commands || []) : [];
                
                console.log('Resolved post-commands:', resolvedPostCommands);
                
                if (resolvedPostCommands.length === 0) {
                    const noPostItem = document.createElement('div');
                    noPostItem.style.cssText = 'padding: 8px; color: var(--admin-text-muted); font-size: 12px; text-align: center;';
                    noPostItem.textContent = 'No post-commands to execute (conditions not met)';
                    executionResults.appendChild(noPostItem);
                } else {
                    // Create result items for post-commands
                    const postStartIndex = allCommands.length;
                    resolvedPostCommands.forEach((cmd, idx) => {
                        const item = document.createElement('div');
                        item.className = 'result-item result-item--pending';
                        item.id = 'result-post-' + idx;
                        item.innerHTML = `
                            <span class="result-item__icon">⏳</span>
                            <div class="result-item__content">
                                <div class="result-item__command">🔧 ${cmd.command}</div>
                                <div class="result-item__message">Post-Command: Pending...</div>
                            </div>
                        `;
                        executionResults.appendChild(item);
                    });
                    
                    // Execute post-commands
                    for (let j = 0; j < resolvedPostCommands.length; j++) {
                        const cmd = resolvedPostCommands[j];
                        const resultItem = document.getElementById('result-post-' + j);
                        progressText.textContent = `Executing post-command ${j + 1}/${resolvedPostCommands.length}...`;
                        
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
                            
                            console.log(`[post-${j}] ${cmd.command}:`, cmd.params ? JSON.stringify(cmd.params) : '(no params)');
                            
                            const response = await fetch(url, options);
                            const responseText = await response.text();
                            let data;
                            try {
                                data = responseText ? JSON.parse(responseText) : { status: response.status, message: 'No response body' };
                            } catch (parseError) {
                                data = { status: 500, message: 'Invalid JSON response: ' + responseText.substring(0, 100) };
                            }
                            
                            const isSuccess = (data.status >= 200 && data.status < 300) || data.success === true;
                            const errorMsg = data.error || data.message || 'Failed';
                            
                            const detailsId = `details-post-${j}`;
                            const paramsDisplay = cmd.params ? JSON.stringify(cmd.params, null, 2) : '(none)';
                            const responseDisplay = JSON.stringify(data, null, 2);
                            
                            if (isSuccess) {
                                resultItem.className = 'result-item result-item--success';
                                resultItem.innerHTML = `
                                    <span class="result-item__icon">✅</span>
                                    <div class="result-item__content">
                                        <div class="result-item__command">
                                            🔧 ${cmd.command}
                                            <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                                        </div>
                                        <div class="result-item__message">${data.message || 'Success'}</div>
                                        <div class="result-item__details" id="${detailsId}">
                                            <div class="result-item__details-section">
                                                <div class="result-item__details-label">Parameters:</div>
                                                <div class="result-item__details-value">${paramsDisplay}</div>
                                            </div>
                                            <div class="result-item__details-section">
                                                <div class="result-item__details-label">Response:</div>
                                                <div class="result-item__details-value">${responseDisplay}</div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            } else {
                                resultItem.className = 'result-item result-item--error';
                                resultItem.innerHTML = `
                                    <span class="result-item__icon">❌</span>
                                    <div class="result-item__content">
                                        <div class="result-item__command">
                                            🔧 ${cmd.command}
                                            <span class="result-item__toggle" onclick="document.getElementById('${detailsId}').classList.toggle('expanded'); this.textContent = this.textContent === '[+]' ? '[-]' : '[+]'">[+]</span>
                                        </div>
                                        <div class="result-item__error">${errorMsg}</div>
                                        <div class="result-item__details" id="${detailsId}">
                                            <div class="result-item__details-section">
                                                <div class="result-item__details-label">Parameters:</div>
                                                <div class="result-item__details-value">${paramsDisplay}</div>
                                            </div>
                                            <div class="result-item__details-section">
                                                <div class="result-item__details-label">Response:</div>
                                                <div class="result-item__details-value">${responseDisplay}</div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }
                        } catch (error) {
                            resultItem.className = 'result-item result-item--error';
                            resultItem.innerHTML = `
                                <span class="result-item__icon">❌</span>
                                <div class="result-item__content">
                                    <div class="result-item__command">🔧 ${cmd.command}</div>
                                    <div class="result-item__error">${error.message}</div>
                                </div>
                            `;
                        }
                    }
                }
            } catch (error) {
                console.error('Error resolving post-commands:', error);
                const errorItem = document.createElement('div');
                errorItem.className = 'result-item result-item--error';
                errorItem.innerHTML = `
                    <span class="result-item__icon">❌</span>
                    <div class="result-item__content">
                        <div class="result-item__command">Post-Commands Resolution</div>
                        <div class="result-item__error">${error.message}</div>
                    </div>
                `;
                executionResults.appendChild(errorItem);
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
