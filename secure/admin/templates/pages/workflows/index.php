<?php
/**
 * Workflows Browser
 * 
 * Lists all available workflows organized by category.
 * Users can select a workflow to view details and generate prompts (AI) or execute steps (manual).
 * 
 * @version 2.2.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';

$workflowManager = new WorkflowManager();
$specsByCategory = $workflowManager->getSpecsByCategory();
$baseUrl = rtrim(BASE_URL, '/');

// Category display configuration
$categoryConfig = [
    'creation' => [
        'icon' => '🏗️',
        'titleKey' => 'ai.categories.creation.title',
        'descKey' => 'ai.categories.creation.desc',
        'color' => '#22c55e'
    ],
    'modification' => [
        'icon' => '✏️',
        'titleKey' => 'ai.categories.modification.title',
        'descKey' => 'ai.categories.modification.desc',
        'color' => '#3b82f6'
    ],
    'template' => [
        'icon' => '📦',
        'titleKey' => 'ai.categories.template.title',
        'descKey' => 'ai.categories.template.desc',
        'color' => '#0ea5e9'
    ],
    'content' => [
        'icon' => '📝',
        'titleKey' => 'ai.categories.content.title',
        'descKey' => 'ai.categories.content.desc',
        'color' => '#f59e0b'
    ],
    'style' => [
        'icon' => '🎨',
        'titleKey' => 'ai.categories.style.title',
        'descKey' => 'ai.categories.style.desc',
        'color' => '#ec4899'
    ],
    'advanced' => [
        'icon' => '⚡',
        'titleKey' => 'ai.categories.advanced.title',
        'descKey' => 'ai.categories.advanced.desc',
        'color' => '#8b5cf6'
    ],
    'wip' => [
        'icon' => '🚧',
        'titleKey' => 'ai.categories.wip.title',
        'descKey' => 'ai.categories.wip.desc',
        'color' => '#6b7280'
    ]
];

// Category order
$categoryOrder = ['creation', 'template', 'modification', 'content', 'style', 'advanced', 'wip'];
?>

<script>
// Page config for ai-index.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.apiBaseUrl = '<?= $router->getBaseUrl() ?>';
window.QUICKSITE_CONFIG.token = '<?= $router->getToken() ?>';
window.QUICKSITE_CONFIG.translations = {
    invalidImport: '<?= __admin('ai.browser.invalidImport', 'Invalid import file: must contain spec and template') ?>',
    importSuccess: '<?= __admin('ai.browser.importSuccess', 'Spec imported successfully!') ?>',
    importError: '<?= __admin('ai.browser.importError', 'Import failed:') ?>'
};
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai-index.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/ai-index.js') ?>"></script>

<div class="ai-browser">
    <div class="ai-browser__header">
        <div>
            <h1 class="ai-browser__title"><?= __admin('ai.browser.title', 'AI Specifications') ?></h1>
            <p class="ai-browser__subtitle"><?= __admin('ai.browser.subtitle', 'Generate AI-ready prompts for common QuickSite tasks') ?></p>
        </div>
        <div class="ai-browser__actions">
            <button type="button" id="import-spec" class="admin-btn admin-btn--secondary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <?= __admin('workflows.browser.importSpec', 'Import Workflow') ?>
            </button>
            <a href="<?= $router->url('workflows', 'new') ?>" class="admin-btn admin-btn--primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('workflows.browser.createSpec', 'Create Custom Workflow') ?>
            </a>
        </div>
    </div>
    
    <div class="ai-browser__intro">
        <div class="ai-browser__intro-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 16v-4"/>
                <path d="M12 8h.01"/>
            </svg>
            <?= __admin('ai.browser.howItWorks', 'How it works') ?>
        </div>
        <div class="ai-browser__intro-steps">
            <div class="ai-browser__step">
                <span class="ai-browser__step-number">1</span>
                <span class="ai-browser__step-text"><?= __admin('ai.browser.step1', 'Select a specification below') ?></span>
            </div>
            <div class="ai-browser__step">
                <span class="ai-browser__step-number">2</span>
                <span class="ai-browser__step-text"><?= __admin('ai.browser.step2', 'Describe your goal in detail') ?></span>
            </div>
            <div class="ai-browser__step">
                <span class="ai-browser__step-number">3</span>
                <span class="ai-browser__step-text"><?= __admin('ai.browser.step3', 'Copy the generated prompt to your AI assistant') ?></span>
            </div>
            <div class="ai-browser__step">
                <span class="ai-browser__step-number">4</span>
                <span class="ai-browser__step-text"><?= __admin('ai.browser.step4', 'Execute the AI response in the Batch panel') ?></span>
            </div>
        </div>
    </div>
    
    <!-- Search and Filter Bar -->
    <div class="ai-browser__filters">
        <div class="ai-browser__search">
            <svg class="ai-browser__search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <path d="M21 21l-4.35-4.35"/>
            </svg>
            <input 
                type="text" 
                id="spec-search" 
                class="ai-browser__search-input" 
                placeholder="<?= __admin('ai.browser.searchPlaceholder', 'Search specifications...') ?>"
            />
        </div>
        <div class="ai-browser__tags" id="tag-filters">
            <button type="button" class="ai-browser__tag-btn ai-browser__tag-btn--active" data-tag="all">
                <?= __admin('ai.browser.allTags', 'All') ?>
            </button>
            <?php
            // Collect all unique tags
            $allTags = [];
            foreach ($specsByCategory as $specs) {
                foreach ($specs as $spec) {
                    if (!empty($spec['meta']['tags'])) {
                        foreach ($spec['meta']['tags'] as $tag) {
                            $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
                        }
                    }
                }
            }
            arsort($allTags); // Sort by frequency
            $topTags = array_slice(array_keys($allTags), 0, 8); // Show top 8 tags
            foreach ($topTags as $tag):
            ?>
            <button type="button" class="ai-browser__tag-btn" data-tag="<?= htmlspecialchars($tag) ?>">
                <?= htmlspecialchars($tag) ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="ai-browser__no-results" id="no-results">
        <div class="ai-empty-state__icon">🔍</div>
        <p><?= __admin('ai.browser.noResults', 'No specifications match your search.') ?></p>
    </div>
    
    <?php 
    $hasSpecs = false;
    foreach ($categoryOrder as $category): 
        if (!isset($specsByCategory[$category]) || empty($specsByCategory[$category])) continue;
        $hasSpecs = true;
        $config = $categoryConfig[$category] ?? ['icon' => '📋', 'titleKey' => 'ai.categories.' . $category . '.title', 'color' => '#6b7280'];
        $specs = $specsByCategory[$category];
    ?>
    <div class="ai-category">
        <div class="ai-category__header">
            <span class="ai-category__icon"><?= $config['icon'] ?></span>
            <span class="ai-category__title"><?= __admin($config['titleKey'], ucfirst($category)) ?></span>
            <span class="ai-category__count"><?= count($specs) ?></span>
        </div>
        
        <div class="ai-specs-grid">
            <?php foreach ($specs as $spec): 
                $meta = $spec['meta'] ?? [];
                $difficulty = $meta['difficulty'] ?? 'intermediate';
                $specTags = implode(',', $meta['tags'] ?? []);
                $specTitle = __admin($meta['titleKey'] ?? '', $spec['id']);
                $specDesc = __admin($meta['descriptionKey'] ?? '', '');
                $isManual = isset($spec['steps']) && !isset($spec['promptTemplate']);
            ?>
            <a href="<?= $router->url('workflows', $spec['id']) ?>" 
               class="ai-spec-card<?= $isManual ? ' ai-spec-card--manual' : '' ?>" 
               data-tags="<?= htmlspecialchars($specTags) ?>"
               data-title="<?= htmlspecialchars(strtolower($specTitle)) ?>"
               data-desc="<?= htmlspecialchars(strtolower($specDesc)) ?>"
               data-id="<?= htmlspecialchars($spec['id']) ?>">
                <div class="ai-spec-card__header">
                    <span class="ai-spec-card__icon"><?= htmlspecialchars($meta['icon'] ?? '📋') ?></span>
                    <span class="ai-spec-card__title"><?= __admin($meta['titleKey'] ?? '', $spec['id']) ?></span>
                    <?php if ($isManual): ?>
                    <span class="ai-spec-card__badge ai-spec-card__badge--manual" title="<?= __admin('workflows.spec.manualHint', 'Executes predefined commands without AI') ?>">
                        📦
                    </span>
                    <?php else: ?>
                    <span class="ai-spec-card__badge ai-spec-card__badge--ai" title="<?= __admin('workflows.spec.aiHint', 'Generates AI prompts') ?>">
                        🤖
                    </span>
                    <?php endif; ?>
                </div>
                <p class="ai-spec-card__desc">
                    <?= __admin($meta['descriptionKey'] ?? '', 'No description available') ?>
                </p>
                <div class="ai-spec-card__tags">
                    <span class="ai-spec-card__difficulty ai-spec-card__difficulty--<?= $difficulty ?>">
                        <?= __admin('ai.difficulty.' . $difficulty, ucfirst($difficulty)) ?>
                    </span>
                    <?php if (!empty($meta['tags'])): ?>
                        <?php foreach (array_slice($meta['tags'], 0, 2) as $tag): ?>
                        <span class="ai-spec-card__tag"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (!$hasSpecs): ?>
    <div class="ai-empty-state">
        <div class="ai-empty-state__icon">📭</div>
        <p><?= __admin('ai.browser.noSpecs', 'No AI specifications available yet.') ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Hidden file input for import -->
<input type="file" id="import-file-input" accept=".json" style="display: none;">
