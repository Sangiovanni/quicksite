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
 * @version 1.1.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/AiSpecManager.php';

$specManager = new AiSpecManager();
$specId = $router->getSpecId();
$isNew = ($specId === 'new');
$isEdit = str_starts_with($specId ?? '', 'edit/');
$baseUrl = rtrim(BASE_URL, '/');

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

<script>
// Page config for ai-editor.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.apiBaseUrl = '<?= $router->getBaseUrl() ?>';
window.QUICKSITE_CONFIG.token = '<?= $router->getToken() ?>';
window.QUICKSITE_CONFIG.isNew = <?= json_encode($isNew) ?>;
window.QUICKSITE_CONFIG.originalSpecId = <?= json_encode($editSpecId) ?>;
window.QUICKSITE_CONFIG.aiUrl = '<?= $router->url('ai') ?>';
window.QUICKSITE_CONFIG.translations = {
    validationErrors: '<?= __admin('ai.editor.validationErrors', 'Validation Errors') ?>',
    validJson: '<?= __admin('ai.editor.validJson', 'Valid JSON - All required fields present') ?>',
    invalidJson: '<?= __admin('ai.editor.invalidJson', 'Invalid JSON') ?>',
    fixErrors: '<?= __admin('ai.editor.fixErrors', 'Please fix JSON errors before previewing') ?>',
    templateRequired: '<?= __admin('ai.editor.templateRequired', 'Prompt template is required') ?>',
    loading: '<?= __admin('ai.editor.loading', 'Loading...') ?>',
    saving: '<?= __admin('ai.editor.saving', 'Saving...') ?>',
    saved: '<?= __admin('ai.editor.saved', 'Spec saved successfully!') ?>'
};
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai-editor.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/ai-editor.js') ?>"></script>

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
