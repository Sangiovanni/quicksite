<?php
/**
 * AI Spec Editor Page
 * 
 * Create and edit custom AI specifications.
 * 
 * Features:
 * - JSON definition editor with validation
 * - Markdown prompt template editor
 * - Live preview of rendered prompts
 * - Save/Update custom specs
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/AiSpecManager.php';

$specManager = new AiSpecManager();
$specId = $router->getSpecId();
$isNew = ($specId === 'new');
$isEdit = str_starts_with($specId ?? '', 'edit/');

// For edit mode, extract the actual spec ID
if ($isEdit) {
    $editSpecId = substr($specId, 5); // Remove 'edit/' prefix
    $spec = $specManager->loadSpec($editSpecId);
    
    if (!$spec) {
        http_response_code(404);
        ?>
        <div class="ai-editor-error">
            <div class="ai-editor-error__icon">❓</div>
            <h2><?= __admin('ai.editor.notFound.title', 'Spec Not Found') ?></h2>
            <p><?= __admin('ai.editor.notFound.message', 'The requested AI specification could not be found.') ?></p>
            <a href="<?= $router->url('ai') ?>" class="admin-btn admin-btn--primary">
                <?= __admin('ai.spec.backToList', 'Back to Specs') ?>
            </a>
        </div>
        <?php
        return;
    }
    
    // Load the markdown template
    $templatePath = SECURE_FOLDER_PATH . '/admin/ai_specs/' . ($spec['_source'] ?? 'core') . '/' . $editSpecId . '.md';
    $templateContent = file_exists($templatePath) ? file_get_contents($templatePath) : '';
    
    // Remove internal fields for editing
    unset($spec['_source']);
    $specJson = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    // New spec - provide starter template
    $editSpecId = '';
    $specJson = json_encode([
        'id' => 'my-custom-spec',
        'version' => '1.0.0',
        'meta' => [
            'icon' => '🎯',
            'titleKey' => 'ai.specs.myCustomSpec.title',
            'descriptionKey' => 'ai.specs.myCustomSpec.description',
            'category' => 'advanced',
            'difficulty' => 'intermediate',
            'tags' => ['custom']
        ],
        'parameters' => [
            [
                'id' => 'userGoal',
                'type' => 'textarea',
                'required' => true,
                'labelKey' => 'ai.specs.myCustomSpec.params.userGoal.label',
                'placeholderKey' => 'ai.specs.myCustomSpec.params.userGoal.placeholder',
                'helpKey' => 'ai.specs.myCustomSpec.params.userGoal.help'
            ]
        ],
        'examples' => [
            [
                'id' => 'example1',
                'titleKey' => 'ai.specs.myCustomSpec.examples.example1.title',
                'promptKey' => 'ai.specs.myCustomSpec.examples.example1.prompt',
                'params' => [
                    'userGoal' => 'Example goal description'
                ]
            ]
        ],
        'dataRequirements' => [
            [
                'id' => 'structure',
                'extract' => 'components'
            ]
        ],
        'relatedCommands' => [
            'editStructure'
        ],
        'promptTemplate' => 'my-custom-spec.md'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    $templateContent = <<<'TEMPLATE'
# My Custom Spec

You are helping with a custom QuickSite task.

## User's Goal

{{userGoal}}

## Current Structure

```json
{{json structure}}
```

## Output Format

```json
[
  { "command": "commandName", "param": "value" }
]
```

## Instructions

1. Analyze the user's goal
2. Generate appropriate QuickSite commands
3. Return valid JSON array of commands

{{#if notes}}
## Additional Notes
{{notes}}
{{/if}}
TEMPLATE;
}

// Load the JSON schema for reference
$schemaPath = SECURE_FOLDER_PATH . '/admin/ai_specs/schema.json';
$schema = file_exists($schemaPath) ? file_get_contents($schemaPath) : '{}';
?>

<style>
.ai-editor {
    max-width: 1400px;
    margin: 0 auto;
    padding: var(--space-lg);
}

.ai-editor__breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
    margin-bottom: var(--space-lg);
}

.ai-editor__breadcrumb a {
    color: var(--admin-primary);
    text-decoration: none;
}

.ai-editor__breadcrumb a:hover {
    text-decoration: underline;
}

.ai-editor__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-xl);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--admin-border);
}

.ai-editor__title {
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--admin-text);
}

.ai-editor__actions {
    display: flex;
    gap: var(--space-sm);
}

.ai-editor__layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-lg);
}

@media (max-width: 1200px) {
    .ai-editor__layout {
        grid-template-columns: 1fr;
    }
}

.ai-editor-panel {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.ai-editor-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    background: var(--admin-bg-secondary);
    border-bottom: 1px solid var(--admin-border);
}

.ai-editor-panel__title {
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.ai-editor-panel__body {
    padding: 0;
}

.ai-editor-textarea {
    width: 100%;
    min-height: 400px;
    padding: var(--space-md);
    border: none;
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    line-height: 1.6;
    background: var(--admin-bg);
    color: var(--admin-text);
    resize: vertical;
}

.ai-editor-textarea:focus {
    outline: none;
    background: var(--admin-card-bg);
}

.ai-editor-tabs {
    display: flex;
    border-bottom: 1px solid var(--admin-border);
}

.ai-editor-tab {
    padding: var(--space-sm) var(--space-md);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: all 0.2s ease;
}

.ai-editor-tab:hover {
    color: var(--admin-text);
}

.ai-editor-tab--active {
    color: var(--admin-primary);
    border-bottom-color: var(--admin-primary);
}

.ai-editor-tab-content {
    display: none;
}

.ai-editor-tab-content--active {
    display: block;
}

.ai-editor-validation {
    padding: var(--space-md) var(--space-lg);
    border-top: 1px solid var(--admin-border);
    font-size: var(--font-size-sm);
}

.ai-editor-validation--valid {
    background: #ecfdf5;
    color: #065f46;
}

.ai-editor-validation--invalid {
    background: #fef2f2;
    color: #991b1b;
}

.ai-editor-validation__title {
    font-weight: 600;
    margin-bottom: var(--space-xs);
}

.ai-editor-validation__list {
    margin: 0;
    padding-left: var(--space-lg);
}

/* Preview panel */
.ai-editor-preview {
    min-height: 400px;
    padding: var(--space-md);
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    line-height: 1.6;
    background: var(--admin-bg);
    color: var(--admin-text);
    white-space: pre-wrap;
    word-wrap: break-word;
    overflow-y: auto;
}

.ai-editor-preview--empty {
    color: var(--admin-text-muted);
    text-align: center;
    padding: var(--space-2xl);
}

/* Error state */
.ai-editor-error {
    text-align: center;
    padding: var(--space-2xl);
}

.ai-editor-error__icon {
    font-size: 4rem;
    margin-bottom: var(--space-lg);
}

/* Info panel */
.ai-editor-info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-lg);
    font-size: var(--font-size-sm);
}

.ai-editor-info__title {
    font-weight: 600;
    color: #1e40af;
    margin-bottom: var(--space-xs);
}

.ai-editor-info__text {
    color: #1e3a8a;
}

/* Save success/error */
.ai-editor-message {
    position: fixed;
    top: 80px;
    right: 20px;
    padding: var(--space-md) var(--space-lg);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    z-index: 1000;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.ai-editor-message--success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.ai-editor-message--error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
</style>

<div class="ai-editor">
    <!-- Breadcrumb -->
    <nav class="ai-editor__breadcrumb">
        <a href="<?= $router->url('ai') ?>"><?= __admin('ai.browser.title', 'AI Specs') ?></a>
        <span>›</span>
        <span><?= $isNew ? __admin('ai.editor.new', 'New Spec') : __admin('ai.editor.edit', 'Edit Spec') ?></span>
    </nav>
    
    <!-- Info for new specs -->
    <?php if ($isNew): ?>
    <div class="ai-editor-info">
        <div class="ai-editor-info__title">💡 <?= __admin('ai.editor.info.title', 'Creating a Custom Spec') ?></div>
        <div class="ai-editor-info__text">
            <?= __admin('ai.editor.info.text', 'Custom specs are saved to the secure/admin/ai_specs/custom/ folder. They will appear alongside core specs in the browser. Make sure to add translation keys to your locale files for labels and descriptions.') ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <header class="ai-editor__header">
        <h1 class="ai-editor__title">
            <?= $isNew 
                ? __admin('ai.editor.title.new', 'Create Custom Spec') 
                : __admin('ai.editor.title.edit', 'Edit: ' . htmlspecialchars($editSpecId)) 
            ?>
        </h1>
        <div class="ai-editor__actions">
            <button type="button" id="preview-btn" class="admin-btn admin-btn--secondary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <?= __admin('ai.editor.preview', 'Preview') ?>
            </button>
            <button type="button" id="save-btn" class="admin-btn admin-btn--primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <?= __admin('ai.editor.save', 'Save Spec') ?>
            </button>
        </div>
    </header>
    
    <div class="ai-editor__layout">
        <!-- Left Panel: JSON Definition -->
        <div class="ai-editor-panel">
            <div class="ai-editor-panel__header">
                <span class="ai-editor-panel__title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    <?= __admin('ai.editor.definition', 'Spec Definition (JSON)') ?>
                </span>
            </div>
            <div class="ai-editor-panel__body">
                <textarea id="spec-json" class="ai-editor-textarea" spellcheck="false"><?= htmlspecialchars($specJson) ?></textarea>
            </div>
            <div id="json-validation" class="ai-editor-validation ai-editor-validation--valid">
                <div class="ai-editor-validation__title">✓ <?= __admin('ai.editor.valid', 'Valid JSON') ?></div>
            </div>
        </div>
        
        <!-- Right Panel: Markdown Template -->
        <div class="ai-editor-panel">
            <div class="ai-editor-panel__header">
                <span class="ai-editor-panel__title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <path d="M14 2v6h6"/>
                    </svg>
                    <?= __admin('ai.editor.template', 'Prompt Template (Markdown)') ?>
                </span>
            </div>
            <div class="ai-editor-tabs">
                <button class="ai-editor-tab ai-editor-tab--active" data-tab="template">
                    <?= __admin('ai.editor.tab.template', 'Template') ?>
                </button>
                <button class="ai-editor-tab" data-tab="preview">
                    <?= __admin('ai.editor.tab.preview', 'Preview') ?>
                </button>
                <button class="ai-editor-tab" data-tab="schema">
                    <?= __admin('ai.editor.tab.schema', 'Schema Reference') ?>
                </button>
            </div>
            <div class="ai-editor-panel__body">
                <div id="tab-template" class="ai-editor-tab-content ai-editor-tab-content--active">
                    <textarea id="spec-template" class="ai-editor-textarea" spellcheck="false"><?= htmlspecialchars($templateContent) ?></textarea>
                </div>
                <div id="tab-preview" class="ai-editor-tab-content">
                    <div id="template-preview" class="ai-editor-preview ai-editor-preview--empty">
                        <?= __admin('ai.editor.previewEmpty', 'Click "Preview" to see the rendered prompt') ?>
                    </div>
                </div>
                <div id="tab-schema" class="ai-editor-tab-content">
                    <textarea class="ai-editor-textarea" readonly spellcheck="false"><?= htmlspecialchars($schema) ?></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const jsonEditor = document.getElementById('spec-json');
    const templateEditor = document.getElementById('spec-template');
    const jsonValidation = document.getElementById('json-validation');
    const templatePreview = document.getElementById('template-preview');
    const previewBtn = document.getElementById('preview-btn');
    const saveBtn = document.getElementById('save-btn');
    const isNew = <?= json_encode($isNew) ?>;
    const originalSpecId = <?= json_encode($editSpecId) ?>;
    
    // Tab switching
    document.querySelectorAll('.ai-editor-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const tabId = tab.dataset.tab;
            
            document.querySelectorAll('.ai-editor-tab').forEach(t => t.classList.remove('ai-editor-tab--active'));
            document.querySelectorAll('.ai-editor-tab-content').forEach(c => c.classList.remove('ai-editor-tab-content--active'));
            
            tab.classList.add('ai-editor-tab--active');
            document.getElementById('tab-' + tabId).classList.add('ai-editor-tab-content--active');
        });
    });
    
    // JSON validation on input
    let validationTimeout;
    jsonEditor.addEventListener('input', () => {
        clearTimeout(validationTimeout);
        validationTimeout = setTimeout(validateJson, 500);
    });
    
    function validateJson() {
        try {
            const spec = JSON.parse(jsonEditor.value);
            
            // Basic validation
            const errors = [];
            if (!spec.id) errors.push('Missing required field: id');
            if (!spec.version) errors.push('Missing required field: version');
            if (!spec.meta) errors.push('Missing required field: meta');
            if (!spec.dataRequirements) errors.push('Missing required field: dataRequirements');
            if (!spec.relatedCommands) errors.push('Missing required field: relatedCommands');
            
            if (spec.id && !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(spec.id)) {
                errors.push('Invalid ID format: must be kebab-case');
            }
            
            if (spec.version && !/^\d+\.\d+\.\d+$/.test(spec.version)) {
                errors.push('Invalid version format: must be semver (x.y.z)');
            }
            
            if (errors.length > 0) {
                jsonValidation.className = 'ai-editor-validation ai-editor-validation--invalid';
                jsonValidation.innerHTML = `
                    <div class="ai-editor-validation__title">⚠️ <?= __admin('ai.editor.validationErrors', 'Validation Errors') ?></div>
                    <ul class="ai-editor-validation__list">
                        ${errors.map(e => `<li>${e}</li>`).join('')}
                    </ul>
                `;
            } else {
                jsonValidation.className = 'ai-editor-validation ai-editor-validation--valid';
                jsonValidation.innerHTML = '<div class="ai-editor-validation__title">✓ <?= __admin('ai.editor.validJson', 'Valid JSON - All required fields present') ?></div>';
            }
            
            return { valid: errors.length === 0, spec };
        } catch (e) {
            jsonValidation.className = 'ai-editor-validation ai-editor-validation--invalid';
            jsonValidation.innerHTML = `
                <div class="ai-editor-validation__title">❌ <?= __admin('ai.editor.invalidJson', 'Invalid JSON') ?></div>
                <ul class="ai-editor-validation__list">
                    <li>${e.message}</li>
                </ul>
            `;
            return { valid: false };
        }
    }
    
    // Preview button
    previewBtn.addEventListener('click', async () => {
        const validation = validateJson();
        if (!validation.valid) {
            showMessage('error', '<?= __admin('ai.editor.fixErrors', 'Please fix JSON errors before previewing') ?>');
            return;
        }
        
        previewBtn.disabled = true;
        previewBtn.innerHTML = '<span class="admin-spinner"></span> <?= __admin('ai.editor.loading', 'Loading...') ?>';
        
        try {
            const response = await fetch('<?= $router->getBaseUrl() ?>/api/ai-spec-preview', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer <?= $router->getToken() ?>',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    spec: validation.spec,
                    template: templateEditor.value
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                templatePreview.textContent = data.data.prompt;
                templatePreview.classList.remove('ai-editor-preview--empty');
                
                // Switch to preview tab
                document.querySelector('[data-tab="preview"]').click();
            } else {
                showMessage('error', data.error || 'Preview failed');
            }
        } catch (error) {
            showMessage('error', error.message);
        }
        
        previewBtn.disabled = false;
        previewBtn.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
            <?= __admin('ai.editor.preview', 'Preview') ?>
        `;
    });
    
    // Save button
    saveBtn.addEventListener('click', async () => {
        const validation = validateJson();
        if (!validation.valid) {
            showMessage('error', '<?= __admin('ai.editor.fixErrors', 'Please fix JSON errors before saving') ?>');
            return;
        }
        
        if (!templateEditor.value.trim()) {
            showMessage('error', '<?= __admin('ai.editor.templateRequired', 'Prompt template is required') ?>');
            return;
        }
        
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="admin-spinner"></span> <?= __admin('ai.editor.saving', 'Saving...') ?>';
        
        try {
            const response = await fetch('<?= $router->getBaseUrl() ?>/api/ai-spec-save', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer <?= $router->getToken() ?>',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    spec: validation.spec,
                    template: templateEditor.value,
                    isNew: isNew,
                    originalSpecId: originalSpecId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage('success', '<?= __admin('ai.editor.saved', 'Spec saved successfully!') ?>');
                
                // Redirect to the spec page after a short delay
                setTimeout(() => {
                    window.location.href = '<?= $router->url('ai') ?>/' + validation.spec.id;
                }, 1500);
            } else {
                showMessage('error', data.error || 'Save failed');
            }
        } catch (error) {
            showMessage('error', error.message);
        }
        
        saveBtn.disabled = false;
        saveBtn.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/>
                <polyline points="7 3 7 8 15 8"/>
            </svg>
            <?= __admin('ai.editor.save', 'Save Spec') ?>
        `;
    });
    
    function showMessage(type, message) {
        const existing = document.querySelector('.ai-editor-message');
        if (existing) existing.remove();
        
        const div = document.createElement('div');
        div.className = `ai-editor-message ai-editor-message--${type}`;
        div.textContent = message;
        document.body.appendChild(div);
        
        setTimeout(() => div.remove(), 4000);
    }
    
    // Initial validation
    validateJson();
})();
</script>
