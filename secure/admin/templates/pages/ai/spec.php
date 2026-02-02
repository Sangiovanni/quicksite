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
 * JavaScript: /admin/assets/js/pages/ai-spec.js
 * CSS: Moved to admin.css (AI SPEC PAGE section)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/AiSpecManager.php';

$baseUrl = rtrim(BASE_URL, '/');
$specManager = new AiSpecManager();
$specId = $router->getSpecId();

// Load the spec
$spec = $specManager->loadSpec($specId);

if (!$spec) {
    http_response_code(404);
    ?>
    <div class="ai-spec-error">
        <div class="ai-spec-error__icon">â“</div>
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


<div class="ai-spec" data-spec-id="<?= htmlspecialchars($specId) ?>" data-is-create-spec="<?= $hasCreateTag ? 'true' : 'false' ?>" data-has-no-params="<?= empty($parameters) ? 'true' : 'false' ?>">
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
            <div class="ai-spec-card ai-response-section">
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


<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai-spec.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/ai-spec.js') ?>"></script>
