<?php
/**
 * Unified Tag Selector Component
 * 
 * A searchable dropdown with optgroups for HTML tag selection.
 * Replaces the old 3-tier selector (search → categories → card grid).
 * Includes favorites (★) and contextual suggestions (✦).
 * 
 * Usage: include with $selectorId parameter to set unique IDs
 *   <?php $selectorId = 'add'; include '_tag-selector.php'; ?>
 */

// Include tag examples for preview panel
require_once __DIR__ . '/_tag-examples.php';
require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php'; // qs_inline_script_json

// Ensure $selectorId is set
$selectorId = $selectorId ?? 'add';

// Tag definitions from centralized TagRegistry (single source of truth)
$tagCategories = TagRegistry::getUICategories();

// Category icons (UI-only, kept here not in TagRegistry)
$categoryIcons = [
    'layout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
    'text' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
    'interactive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
    'media' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
    'form' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>',
    'table' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>',
    'other' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>',
];
// Merge icons into categories
foreach ($tagCategories as $catId => &$category) {
    $category['icon'] = $categoryIcons[$catId] ?? '';
}
unset($category);

// Build flat tag data for JS (category, desc, required)
$tagDataForJs = [];
foreach ($tagCategories as $catId => $category) {
    foreach ($category['tags'] as $tagName => $tagInfo) {
        $tagDataForJs[$tagName] = [
            'category' => $catId,
            'desc' => $tagInfo['desc'],
            'required' => !empty($tagInfo['required']),
        ];
    }
}
?>

<div class="tag-selector" id="<?= $selectorId ?>-tag-selector">
    <!-- Unified Searchable Dropdown -->
    <div class="tag-dropdown" id="<?= $selectorId ?>-tag-dropdown">
        <!-- Trigger button (shows current selection) -->
        <button type="button" class="tag-dropdown__trigger" id="<?= $selectorId ?>-tag-trigger">
            <span class="tag-dropdown__value" id="<?= $selectorId ?>-tag-display">&lt;div&gt;</span>
            <span class="tag-dropdown__desc" id="<?= $selectorId ?>-tag-display-desc"><?= adminEscape(__admin('preview.tagDesc.div')) ?></span>
            <svg class="tag-dropdown__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
        
        <!-- Dropdown panel -->
        <div class="tag-dropdown__panel" id="<?= $selectorId ?>-tag-panel" style="display: none;">
            <!-- Search input -->
            <div class="tag-dropdown__search">
                <svg class="tag-dropdown__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" 
                       class="tag-dropdown__search-input admin-input" 
                       id="<?= $selectorId ?>-tag-search"
                       placeholder="<?= adminAttr(__admin('preview.searchTags')) ?>"
                       autocomplete="off">
            </div>
            
            <!-- Scrollable options list -->
            <div class="tag-dropdown__options" id="<?= $selectorId ?>-tag-options">
                <!-- ★ Favorites optgroup (populated by JS from localStorage) -->
                <div class="tag-dropdown__group tag-dropdown__group--favorites" id="<?= $selectorId ?>-tag-favorites-group" style="display: none;">
                    <button type="button" class="tag-dropdown__group-header" data-group="favorites">
                        <span class="tag-dropdown__group-icon">★</span>
                        <span class="tag-dropdown__group-label"><?= adminEscape(__admin('preview.favorites')) ?></span>
                        <svg class="tag-dropdown__group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="tag-dropdown__group-items" id="<?= $selectorId ?>-tag-favorites-items">
                        <!-- Populated by JS -->
                    </div>
                </div>
                
                <!-- ✦ Suggested optgroup (populated by JS based on parent tag) -->
                <div class="tag-dropdown__group tag-dropdown__group--suggested" id="<?= $selectorId ?>-tag-suggested-group" style="display: none;">
                    <button type="button" class="tag-dropdown__group-header" data-group="suggested">
                        <span class="tag-dropdown__group-icon">✦</span>
                        <span class="tag-dropdown__group-label"><?= adminEscape(__admin('preview.suggested')) ?></span>
                        <svg class="tag-dropdown__group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="tag-dropdown__group-items" id="<?= $selectorId ?>-tag-suggested-items">
                        <!-- Populated by JS -->
                    </div>
                </div>
                
                <!-- Standard category optgroups -->
                <?php foreach ($tagCategories as $catId => $category): ?>
                <div class="tag-dropdown__group" data-category="<?= $catId ?>">
                    <button type="button" class="tag-dropdown__group-header" data-group="<?= $catId ?>">
                        <span class="tag-dropdown__group-icon"><?= $category['icon'] ?></span>
                        <span class="tag-dropdown__group-label"><?= htmlspecialchars($category['label']) ?></span>
                        <svg class="tag-dropdown__group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="tag-dropdown__group-items">
                        <?php foreach ($category['tags'] as $tagName => $tagInfo): ?>
                        <button type="button" 
                                class="tag-dropdown__option" 
                                data-tag="<?= $tagName ?>"
                                data-category="<?= $catId ?>"
                                data-desc="<?= htmlspecialchars($tagInfo['desc']) ?>"
                                <?= !empty($tagInfo['required']) ? 'data-required="true"' : '' ?>>
                            <code class="tag-dropdown__option-tag">&lt;<?= $tagName ?>&gt;</code>
                            <span class="tag-dropdown__option-desc"><?= htmlspecialchars($tagInfo['desc']) ?></span>
                            <?php if (!empty($tagInfo['required'])): ?>
                            <span class="tag-dropdown__option-required" title="<?= adminAttr(__admin('preview.requiresParams')) ?>">*</span>
                            <?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- No results message -->
                <div class="tag-dropdown__no-results" id="<?= $selectorId ?>-tag-no-results" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <span><?= adminEscape(__admin('preview.noTagsFound')) ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Star button (below dropdown) -->
    <button type="button" class="tag-dropdown__star-btn" id="<?= $selectorId ?>-tag-star" title="<?= adminAttr(__admin('preview.starTag')) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        <span id="<?= $selectorId ?>-tag-star-label"><?= adminEscape(__admin('preview.starTag')) ?></span>
    </button>
    
    <!-- Hidden input to store selected tag value -->
    <input type="hidden" id="<?= $selectorId ?>-tag" value="div">
    
    <!-- Preview Panel (moved to collapsible body by JS) -->
    <div class="tag-selector__preview" id="<?= $selectorId ?>-tag-preview">
        <div class="tag-selector__preview-header">
            <code class="tag-selector__preview-tag" id="<?= $selectorId ?>-tag-preview-name">&lt;div&gt;</code>
            <button type="button" class="tag-selector__code-toggle" id="<?= $selectorId ?>-tag-code-toggle" title="<?= adminAttr(__admin('preview.showHtml')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 18 22 12 16 6"></polyline>
                    <polyline points="8 6 2 12 8 18"></polyline>
                </svg>
            </button>
        </div>
        <p class="tag-selector__preview-desc" id="<?= $selectorId ?>-tag-preview-desc">
            <?= adminEscape(__admin('preview.tagDesc.div')) ?>
        </p>
        <!-- The example is a visitor's markup: its titles stay the browser's, never the panel's bubble -->
        <div class="tag-selector__preview-render" id="<?= $selectorId ?>-tag-preview-render" data-qs-native-title>
            <?= getTagExample('div') ?>
        </div>
        <div class="tag-selector__preview-code" id="<?= $selectorId ?>-tag-preview-code" style="display: none;">
            <pre><code id="<?= $selectorId ?>-tag-preview-code-content"></code></pre>
        </div>
        <div class="tag-selector__preview-norender" id="<?= $selectorId ?>-tag-preview-norender" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
            </svg>
            <span><?= adminEscape(__admin('preview.noVisualPreview')) ?></span>
        </div>
    </div>
    
    <!-- Each tag's example, parsed once with the page and cloned into the preview by the editor.
         A tag whose example is false (it renders nothing visible) has none: "No visual preview". -->
    <?php foreach (getTagExamples() as $exampleTag => $exampleHtml): if ($exampleHtml === false) { continue; } ?>
    <template id="<?= $selectorId ?>-tag-example-<?= $exampleTag ?>"><?= $exampleHtml ?></template>
    <?php endforeach; ?>

    <!-- Tag data for JS (categories, each tag's description and parameters) -->
    <script type="application/json" id="<?= $selectorId ?>-tag-data">
    <?php 
    echo qs_inline_script_json([
        'tags' => $tagDataForJs,
        'categories' => array_map(fn($c) => ['label' => $c['label']], $tagCategories),
    ]);
    ?>
    </script>
</div>
