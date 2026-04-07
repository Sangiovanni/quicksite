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

// Ensure $selectorId is set
$selectorId = $selectorId ?? 'add';

// Tag definitions with categories, icons, and descriptions
$tagCategories = [
    'layout' => [
        'label' => __admin('preview.layoutTags') ?? 'Layout',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
        'tags' => [
            'div' => ['desc' => __admin('preview.tagDesc.div') ?? 'Generic container'],
            'section' => ['desc' => __admin('preview.tagDesc.section') ?? 'Thematic grouping'],
            'article' => ['desc' => __admin('preview.tagDesc.article') ?? 'Self-contained content'],
            'header' => ['desc' => __admin('preview.tagDesc.header') ?? 'Introductory content'],
            'footer' => ['desc' => __admin('preview.tagDesc.footer') ?? 'Footer content'],
            'nav' => ['desc' => __admin('preview.tagDesc.nav') ?? 'Navigation links'],
            'main' => ['desc' => __admin('preview.tagDesc.main') ?? 'Main content'],
            'aside' => ['desc' => __admin('preview.tagDesc.aside') ?? 'Side content'],
            'figure' => ['desc' => __admin('preview.tagDesc.figure') ?? 'Figure with caption'],
            'figcaption' => ['desc' => __admin('preview.tagDesc.figcaption') ?? 'Figure caption'],
        ]
    ],
    'text' => [
        'label' => __admin('preview.textTags') ?? 'Text',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
        'tags' => [
            'p' => ['desc' => __admin('preview.tagDesc.p') ?? 'Paragraph'],
            'h1' => ['desc' => __admin('preview.tagDesc.h1') ?? 'Heading level 1'],
            'h2' => ['desc' => __admin('preview.tagDesc.h2') ?? 'Heading level 2'],
            'h3' => ['desc' => __admin('preview.tagDesc.h3') ?? 'Heading level 3'],
            'h4' => ['desc' => __admin('preview.tagDesc.h4') ?? 'Heading level 4'],
            'h5' => ['desc' => __admin('preview.tagDesc.h5') ?? 'Heading level 5'],
            'h6' => ['desc' => __admin('preview.tagDesc.h6') ?? 'Heading level 6'],
            'span' => ['desc' => __admin('preview.tagDesc.span') ?? 'Inline container'],
            'strong' => ['desc' => __admin('preview.tagDesc.strong') ?? 'Strong importance'],
            'em' => ['desc' => __admin('preview.tagDesc.em') ?? 'Emphasis'],
            'small' => ['desc' => __admin('preview.tagDesc.small') ?? 'Side comments'],
            'mark' => ['desc' => __admin('preview.tagDesc.mark') ?? 'Highlighted text'],
            'blockquote' => ['desc' => __admin('preview.tagDesc.blockquote') ?? 'Block quotation'],
            'pre' => ['desc' => __admin('preview.tagDesc.pre') ?? 'Preformatted text'],
            'code' => ['desc' => __admin('preview.tagDesc.code') ?? 'Code snippet'],
            'q' => ['desc' => __admin('preview.tagDesc.q') ?? 'Inline quotation'],
            'cite' => ['desc' => __admin('preview.tagDesc.cite') ?? 'Citation'],
            'abbr' => ['desc' => __admin('preview.tagDesc.abbr') ?? 'Abbreviation'],
            'time' => ['desc' => __admin('preview.tagDesc.time') ?? 'Date/time'],
            'address' => ['desc' => __admin('preview.tagDesc.address') ?? 'Contact info'],
        ]
    ],
    'interactive' => [
        'label' => __admin('preview.interactiveTags') ?? 'Interactive',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'tags' => [
            'a' => ['desc' => __admin('preview.tagDesc.a') ?? 'Hyperlink', 'required' => true],
            'button' => ['desc' => __admin('preview.tagDesc.button') ?? 'Clickable button'],
            'details' => ['desc' => __admin('preview.tagDesc.details') ?? 'Disclosure widget'],
            'summary' => ['desc' => __admin('preview.tagDesc.summary') ?? 'Details summary'],
            'dialog' => ['desc' => __admin('preview.tagDesc.dialog') ?? 'Dialog box'],
        ]
    ],
    'list' => [
        'label' => __admin('preview.listTags') ?? 'Lists',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
        'tags' => [
            'ul' => ['desc' => __admin('preview.tagDesc.ul') ?? 'Unordered list'],
            'ol' => ['desc' => __admin('preview.tagDesc.ol') ?? 'Ordered list'],
            'li' => ['desc' => __admin('preview.tagDesc.li') ?? 'List item'],
            'dl' => ['desc' => __admin('preview.tagDesc.dl') ?? 'Description list'],
            'dt' => ['desc' => __admin('preview.tagDesc.dt') ?? 'Description term'],
            'dd' => ['desc' => __admin('preview.tagDesc.dd') ?? 'Description detail'],
        ]
    ],
    'media' => [
        'label' => __admin('preview.mediaTags') ?? 'Media',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'tags' => [
            'img' => ['desc' => __admin('preview.tagDesc.img') ?? 'Image', 'required' => true],
            'picture' => ['desc' => __admin('preview.tagDesc.picture') ?? 'Responsive images'],
            'video' => ['desc' => __admin('preview.tagDesc.video') ?? 'Video player', 'required' => true],
            'audio' => ['desc' => __admin('preview.tagDesc.audio') ?? 'Audio player', 'required' => true],
            'iframe' => ['desc' => __admin('preview.tagDesc.iframe') ?? 'Embedded frame', 'required' => true],
            'embed' => ['desc' => __admin('preview.tagDesc.embed') ?? 'External content', 'required' => true],
            'object' => ['desc' => __admin('preview.tagDesc.object') ?? 'External resource', 'required' => true],
            'source' => ['desc' => __admin('preview.tagDesc.source') ?? 'Media source', 'required' => true],
            'track' => ['desc' => __admin('preview.tagDesc.track') ?? 'Text tracks', 'required' => true],
            'canvas' => ['desc' => __admin('preview.tagDesc.canvas') ?? 'Drawing canvas'],
            'svg' => ['desc' => __admin('preview.tagDesc.svg') ?? 'SVG graphics'],
        ]
    ],
    'form' => [
        'label' => __admin('preview.formTags') ?? 'Form',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>',
        'tags' => [
            'form' => ['desc' => __admin('preview.tagDesc.form') ?? 'Form container', 'required' => true],
            'input' => ['desc' => __admin('preview.tagDesc.input') ?? 'Input field', 'required' => true],
            'textarea' => ['desc' => __admin('preview.tagDesc.textarea') ?? 'Text area', 'required' => true],
            'label' => ['desc' => __admin('preview.tagDesc.label') ?? 'Form label', 'required' => true],
            'select' => ['desc' => __admin('preview.tagDesc.select') ?? 'Dropdown', 'required' => true],
            'option' => ['desc' => __admin('preview.tagDesc.option') ?? 'Select option'],
            'optgroup' => ['desc' => __admin('preview.tagDesc.optgroup') ?? 'Option group'],
            'fieldset' => ['desc' => __admin('preview.tagDesc.fieldset') ?? 'Field group'],
            'legend' => ['desc' => __admin('preview.tagDesc.legend') ?? 'Fieldset caption'],
            'datalist' => ['desc' => __admin('preview.tagDesc.datalist') ?? 'Autocomplete list'],
            'output' => ['desc' => __admin('preview.tagDesc.output') ?? 'Calculation result'],
            'progress' => ['desc' => __admin('preview.tagDesc.progress') ?? 'Progress bar'],
            'meter' => ['desc' => __admin('preview.tagDesc.meter') ?? 'Scalar measurement'],
        ]
    ],
    'table' => [
        'label' => __admin('preview.tableTags') ?? 'Table',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>',
        'tags' => [
            'table' => ['desc' => __admin('preview.tagDesc.table') ?? 'Table container'],
            'thead' => ['desc' => __admin('preview.tagDesc.thead') ?? 'Table header'],
            'tbody' => ['desc' => __admin('preview.tagDesc.tbody') ?? 'Table body'],
            'tfoot' => ['desc' => __admin('preview.tagDesc.tfoot') ?? 'Table footer'],
            'tr' => ['desc' => __admin('preview.tagDesc.tr') ?? 'Table row'],
            'th' => ['desc' => __admin('preview.tagDesc.th') ?? 'Header cell'],
            'td' => ['desc' => __admin('preview.tagDesc.td') ?? 'Data cell'],
            'caption' => ['desc' => __admin('preview.tagDesc.caption') ?? 'Table caption'],
            'colgroup' => ['desc' => __admin('preview.tagDesc.colgroup') ?? 'Column group'],
            'col' => ['desc' => __admin('preview.tagDesc.col') ?? 'Column'],
        ]
    ],
    'other' => [
        'label' => __admin('preview.otherTags') ?? 'Other',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>',
        'tags' => [
            'br' => ['desc' => __admin('preview.tagDesc.br') ?? 'Line break'],
            'hr' => ['desc' => __admin('preview.tagDesc.hr') ?? 'Horizontal rule'],
            'wbr' => ['desc' => __admin('preview.tagDesc.wbr') ?? 'Word break opportunity'],
        ]
    ],
];

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
            <span class="tag-dropdown__desc" id="<?= $selectorId ?>-tag-display-desc"><?= __admin('preview.tagDesc.div') ?? 'Generic container' ?></span>
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
                       placeholder="<?= __admin('preview.searchTags') ?? 'Search tags... (Enter = quick add)' ?>"
                       autocomplete="off">
            </div>
            
            <!-- Scrollable options list -->
            <div class="tag-dropdown__options" id="<?= $selectorId ?>-tag-options">
                <!-- ★ Favorites optgroup (populated by JS from localStorage) -->
                <div class="tag-dropdown__group tag-dropdown__group--favorites" id="<?= $selectorId ?>-tag-favorites-group" style="display: none;">
                    <button type="button" class="tag-dropdown__group-header" data-group="favorites">
                        <span class="tag-dropdown__group-icon">★</span>
                        <span class="tag-dropdown__group-label"><?= __admin('preview.favorites') ?? 'Favorites' ?></span>
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
                        <span class="tag-dropdown__group-label"><?= __admin('preview.suggested') ?? 'Suggested' ?></span>
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
                            <span class="tag-dropdown__option-required" title="<?= __admin('preview.requiresParams') ?? 'Requires parameters' ?>">*</span>
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
                    <span><?= __admin('preview.noTagsFound') ?? 'No tags found' ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Star button (below dropdown) -->
    <button type="button" class="tag-dropdown__star-btn" id="<?= $selectorId ?>-tag-star" title="<?= __admin('preview.starTag') ?? 'Add to favorites' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        <span id="<?= $selectorId ?>-tag-star-label"><?= __admin('preview.starTag') ?? 'Add to favorites' ?></span>
    </button>
    
    <!-- Hidden input to store selected tag value -->
    <input type="hidden" id="<?= $selectorId ?>-tag" value="div">
    
    <!-- Preview Panel (moved to collapsible body by JS) -->
    <div class="tag-selector__preview" id="<?= $selectorId ?>-tag-preview">
        <div class="tag-selector__preview-header">
            <code class="tag-selector__preview-tag" id="<?= $selectorId ?>-tag-preview-name">&lt;div&gt;</code>
            <button type="button" class="tag-selector__code-toggle" id="<?= $selectorId ?>-tag-code-toggle" title="<?= __admin('preview.showHtml') ?? 'Show HTML' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 18 22 12 16 6"></polyline>
                    <polyline points="8 6 2 12 8 18"></polyline>
                </svg>
            </button>
        </div>
        <p class="tag-selector__preview-desc" id="<?= $selectorId ?>-tag-preview-desc">
            <?= __admin('preview.tagDesc.div') ?? 'Generic container' ?>
        </p>
        <div class="tag-selector__preview-render" id="<?= $selectorId ?>-tag-preview-render">
            <?= getTagExample('div') ?>
        </div>
        <div class="tag-selector__preview-code" id="<?= $selectorId ?>-tag-preview-code" style="display: none;">
            <pre><code id="<?= $selectorId ?>-tag-preview-code-content"></code></pre>
        </div>
        <div class="tag-selector__preview-norender" id="<?= $selectorId ?>-tag-preview-norender" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
            </svg>
            <span><?= __admin('preview.noVisualPreview') ?? 'No visual preview' ?></span>
        </div>
    </div>
    
    <!-- Tag data for JS (categories, examples, suggestions) -->
    <script type="application/json" id="<?= $selectorId ?>-tag-data">
    <?php 
    $examples = getTagExamples();
    $examplesMap = [];
    foreach ($examples as $tag => $html) {
        $examplesMap[$tag] = $html === false ? null : $html;
    }
    echo json_encode([
        'tags' => $tagDataForJs,
        'examples' => $examplesMap,
        'categories' => array_map(fn($c) => ['label' => $c['label']], $tagCategories),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    </script>
</div>
