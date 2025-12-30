<?php
/**
 * AI Specs Browser
 * 
 * Lists all available AI specs organized by category.
 * Users can select a spec to view details and generate prompts.
 * 
 * @version 2.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/AiSpecManager.php';

$specManager = new AiSpecManager();
$specsByCategory = $specManager->getSpecsByCategory();

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
$categoryOrder = ['creation', 'modification', 'content', 'style', 'advanced', 'wip'];
?>

<style>
.ai-browser {
    padding: var(--space-lg);
}

.ai-browser__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

.ai-browser__actions {
    display: flex;
    gap: var(--space-sm);
}

.ai-browser__title {
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--admin-text);
    margin-bottom: var(--space-sm);
}

.ai-browser__subtitle {
    color: var(--admin-text-muted);
    font-size: var(--font-size-lg);
}

.ai-browser__intro {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
}

.ai-browser__intro-title {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-weight: 600;
    margin-bottom: var(--space-md);
    color: var(--admin-text);
}

.ai-browser__intro-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
}

.ai-browser__step {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
}

.ai-browser__step-number {
    width: 24px;
    height: 24px;
    background: var(--admin-primary);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-sm);
    font-weight: 600;
    flex-shrink: 0;
}

.ai-browser__step-text {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

.ai-category {
    margin-bottom: var(--space-xl);
}

.ai-category__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
    padding-bottom: var(--space-sm);
    border-bottom: 2px solid var(--admin-border);
}

.ai-category__icon {
    font-size: var(--font-size-xl);
}

.ai-category__title {
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--admin-text);
}

.ai-category__count {
    background: var(--admin-bg-tertiary);
    color: var(--admin-text-muted);
    padding: 2px 8px;
    border-radius: var(--radius-full);
    font-size: var(--font-size-xs);
    margin-left: auto;
}

.ai-specs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
}

.ai-spec-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    transition: all 0.2s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    display: block;
}

.ai-spec-card:hover {
    border-color: var(--admin-primary);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.ai-spec-card__header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.ai-spec-card__icon {
    font-size: var(--font-size-2xl);
    line-height: 1;
}

.ai-spec-card__title {
    font-weight: 600;
    color: var(--admin-text);
    font-size: var(--font-size-md);
}

.ai-spec-card__desc {
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
    line-height: 1.5;
    margin-bottom: var(--space-md);
}

.ai-spec-card__tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}

.ai-spec-card__tag {
    background: var(--admin-bg-tertiary);
    color: var(--admin-text-muted);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
}

.ai-spec-card__difficulty {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: var(--font-size-xs);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
}

.ai-spec-card__difficulty--beginner {
    background: #dcfce7;
    color: #166534;
}

.ai-spec-card__difficulty--intermediate {
    background: #fef3c7;
    color: #92400e;
}

.ai-spec-card__difficulty--advanced {
    background: #fee2e2;
    color: #991b1b;
}

.ai-empty-state {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--admin-text-muted);
}

.ai-empty-state__icon {
    font-size: 3rem;
    margin-bottom: var(--space-md);
    opacity: 0.5;
}

/* Search and Filter Bar */
.ai-browser__filters {
    display: flex;
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
    flex-wrap: wrap;
}

.ai-browser__search {
    flex: 1;
    min-width: 250px;
    position: relative;
}

.ai-browser__search-input {
    width: 100%;
    padding: var(--space-sm) var(--space-md) var(--space-sm) 40px;
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    background: var(--admin-card-bg);
    color: var(--admin-text);
}

.ai-browser__search-input:focus {
    outline: none;
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.ai-browser__search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--admin-text-muted);
    pointer-events: none;
}

.ai-browser__tags {
    display: flex;
    gap: var(--space-xs);
    flex-wrap: wrap;
    align-items: center;
}

.ai-browser__tag-btn {
    padding: 6px 12px;
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-full);
    background: var(--admin-card-bg);
    color: var(--admin-text-muted);
    font-size: var(--font-size-xs);
    cursor: pointer;
    transition: all 0.2s ease;
}

.ai-browser__tag-btn:hover {
    border-color: var(--admin-primary);
    color: var(--admin-primary);
}

.ai-browser__tag-btn--active {
    background: var(--admin-primary);
    border-color: var(--admin-primary);
    color: white;
}

.ai-browser__tag-btn--active:hover {
    background: var(--admin-primary-dark, #2563eb);
    color: white;
}

.ai-browser__no-results {
    text-align: center;
    padding: var(--space-xl);
    color: var(--admin-text-muted);
    display: none;
}

.ai-spec-card--hidden {
    display: none;
}

.ai-category--hidden {
    display: none;
}
</style>

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
                <?= __admin('ai.browser.importSpec', 'Import Spec') ?>
            </button>
            <a href="<?= $router->url('ai', 'new') ?>" class="admin-btn admin-btn--primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('ai.browser.createSpec', 'Create Custom Spec') ?>
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
            ?>
            <a href="<?= $router->url('ai', $spec['id']) ?>" 
               class="ai-spec-card" 
               data-tags="<?= htmlspecialchars($specTags) ?>"
               data-title="<?= htmlspecialchars(strtolower($specTitle)) ?>"
               data-desc="<?= htmlspecialchars(strtolower($specDesc)) ?>"
               data-id="<?= htmlspecialchars($spec['id']) ?>">
                <div class="ai-spec-card__header">
                    <span class="ai-spec-card__icon"><?= htmlspecialchars($meta['icon'] ?? '📋') ?></span>
                    <span class="ai-spec-card__title"><?= __admin($meta['titleKey'] ?? '', $spec['id']) ?></span>
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

<script>
(function() {
    // Search and Filter functionality
    const searchInput = document.getElementById('spec-search');
    const tagButtons = document.querySelectorAll('.ai-browser__tag-btn');
    const specCards = document.querySelectorAll('.ai-spec-card');
    const categories = document.querySelectorAll('.ai-category');
    const noResults = document.getElementById('no-results');
    
    let currentSearch = '';
    let currentTag = 'all';
    
    function filterSpecs() {
        let visibleCount = 0;
        
        specCards.forEach(card => {
            const tags = card.dataset.tags || '';
            const title = card.dataset.title || '';
            const desc = card.dataset.desc || '';
            const id = card.dataset.id || '';
            
            // Check tag match
            const tagMatch = currentTag === 'all' || tags.split(',').includes(currentTag);
            
            // Check search match
            const searchLower = currentSearch.toLowerCase();
            const searchMatch = !currentSearch || 
                title.includes(searchLower) || 
                desc.includes(searchLower) || 
                id.includes(searchLower) ||
                tags.toLowerCase().includes(searchLower);
            
            const isVisible = tagMatch && searchMatch;
            card.classList.toggle('ai-spec-card--hidden', !isVisible);
            
            if (isVisible) visibleCount++;
        });
        
        // Update category visibility
        categories.forEach(category => {
            const grid = category.querySelector('.ai-specs-grid');
            const visibleCards = grid.querySelectorAll('.ai-spec-card:not(.ai-spec-card--hidden)');
            category.classList.toggle('ai-category--hidden', visibleCards.length === 0);
            
            // Update count
            const countBadge = category.querySelector('.ai-category__count');
            if (countBadge) {
                countBadge.textContent = visibleCards.length;
            }
        });
        
        // Show/hide no results message
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
    
    // Search input handler
    searchInput.addEventListener('input', function() {
        currentSearch = this.value;
        filterSpecs();
    });
    
    // Tag button handler
    tagButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            currentTag = this.dataset.tag;
            
            // Update active state
            tagButtons.forEach(b => b.classList.remove('ai-browser__tag-btn--active'));
            this.classList.add('ai-browser__tag-btn--active');
            
            filterSpecs();
        });
    });
    
    // Import functionality
    const importBtn = document.getElementById('import-spec');
    const fileInput = document.getElementById('import-file-input');
    
    importBtn.addEventListener('click', () => fileInput.click());
    
    fileInput.addEventListener('change', async function() {
        const file = this.files[0];
        if (!file) return;
        
        try {
            const content = await file.text();
            const data = JSON.parse(content);
            
            // Validate import structure
            if (!data.spec || !data.template) {
                throw new Error('<?= __admin('ai.browser.invalidImport', 'Invalid import file: must contain spec and template') ?>');
            }
            
            // Send to save endpoint
            const response = await fetch('<?= $router->getBaseUrl() ?>/api/ai-spec-save', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer <?= $router->getToken() ?>',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    spec: data.spec,
                    template: data.template,
                    isNew: true,
                    originalSpecId: ''
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('<?= __admin('ai.browser.importSuccess', 'Spec imported successfully!') ?>');
                window.location.reload();
            } else {
                alert('<?= __admin('ai.browser.importError', 'Import failed:') ?> ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            alert('<?= __admin('ai.browser.importError', 'Import failed:') ?> ' + error.message);
        }
        
        // Reset file input
        this.value = '';
    });
})();
</script>
