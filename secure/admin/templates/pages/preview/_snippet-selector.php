<?php
/**
 * Snippet Selector Component
 * 
 * A searchable dropdown for snippet selection, following the same
 * tag-dropdown pattern used by the HTML Tag selector.
 * Groups snippets by category with source badges (Core/Global/Project).
 * 
 * Usage: include with $selectorId parameter for unique IDs
 *   <?php $selectorId = 'add'; include '_snippet-selector.php'; ?>
 * 
 * Data is loaded dynamically via JS (listSnippets API).
 */

$selectorId = $selectorId ?? 'add';
?>

<div class="snippet-selector" id="<?= $selectorId ?>-snippet-selector">
    <!-- Searchable Dropdown (tag-dropdown pattern) -->
    <div class="tag-dropdown" id="<?= $selectorId ?>-snippet-dropdown">
        <!-- Trigger button (shows current selection) -->
        <button type="button" class="tag-dropdown__trigger" id="<?= $selectorId ?>-snippet-trigger">
            <span class="tag-dropdown__value" id="<?= $selectorId ?>-snippet-display"><?= __admin('preview.selectSnippet', 'Select a snippet') ?></span>
            <span class="tag-dropdown__desc" id="<?= $selectorId ?>-snippet-display-desc"></span>
            <svg class="tag-dropdown__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
        
        <!-- Dropdown panel -->
        <div class="tag-dropdown__panel" id="<?= $selectorId ?>-snippet-panel" style="display: none;">
            <!-- Search input -->
            <div class="tag-dropdown__search">
                <svg class="tag-dropdown__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" 
                       class="tag-dropdown__search-input admin-input" 
                       id="<?= $selectorId ?>-snippet-search"
                       placeholder="<?= __admin('preview.searchSnippets', 'Search snippets...') ?>"
                       autocomplete="off">
            </div>
            
            <!-- Scrollable options list (populated by JS from listSnippets API) -->
            <div class="tag-dropdown__options" id="<?= $selectorId ?>-snippet-options">
                <div class="snippet-selector__loading">
                    <div class="spinner"></div>
                    <?= __admin('common.loading', 'Loading...') ?>
                </div>
            </div>
            
            <!-- No results message -->
            <div class="tag-dropdown__no-results" id="<?= $selectorId ?>-snippet-no-results" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span><?= __admin('preview.noSnippetsFound', 'No snippets found') ?></span>
            </div>
        </div>
    </div>
    
    <!-- Hidden input to store selected snippet ID -->
    <input type="hidden" id="<?= $selectorId ?>-snippet" value="">
    
    <!-- Preview Panel (shown when a snippet is selected) -->
    <div class="snippet-selector__preview" id="<?= $selectorId ?>-snippet-preview" style="display: none;">
        <div class="snippet-selector__preview-header">
            <span class="snippet-selector__preview-title" id="<?= $selectorId ?>-snippet-preview-title"></span>
            <span class="snippet-selector__preview-source" id="<?= $selectorId ?>-snippet-preview-source"></span>
        </div>
        <p class="snippet-selector__preview-desc" id="<?= $selectorId ?>-snippet-preview-desc"></p>
        <div class="snippet-selector__preview-frame-container">
            <iframe class="snippet-selector__preview-frame" id="<?= $selectorId ?>-snippet-preview-frame" sandbox="allow-same-origin"></iframe>
        </div>
        <label class="snippet-selector__style-toggle" id="<?= $selectorId ?>-snippet-style-toggle" style="display: none;">
            <input type="checkbox" id="<?= $selectorId ?>-snippet-style-toggle-input">
            <span><?= __admin('preview.withoutProjectStyle', 'Without current project style') ?></span>
        </label>
        <!-- CSS Selector Status (populated by JS from getSnippet response) -->
        <div class="snippet-selector__css-status" id="<?= $selectorId ?>-snippet-css-status" style="display: none;">
            <div class="snippet-selector__css-status-header">
                <span>CSS Selectors</span>
                <button type="button" class="snippet-selector__css-toggle" id="<?= $selectorId ?>-snippet-css-toggle">
                    <?= __admin('preview.savedCss', 'Saved CSS') ?> ▼
                </button>
            </div>
            <div class="snippet-selector__css-selectors" id="<?= $selectorId ?>-snippet-css-selectors"></div>
            <div class="snippet-selector__css-actions" id="<?= $selectorId ?>-snippet-css-actions" style="display: none;">
            <div class="snippet-selector__css-options" id="<?= $selectorId ?>-snippet-css-options" style="display: none;">
                <label class="snippet-selector__css-option">
                    <input type="radio" name="<?= $selectorId ?>-snippet-css-action" value="skip" checked>
                    <span><?= __admin('preview.cssSkip', "Don't touch CSS") ?></span>
                </label>
                <label class="snippet-selector__css-option" id="<?= $selectorId ?>-snippet-css-option-missing">
                    <input type="radio" name="<?= $selectorId ?>-snippet-css-action" value="missing">
                    <span><?= __admin('preview.cssMissing', 'Add only missing CSS') ?></span>
                </label>
                <label class="snippet-selector__css-option">
                    <input type="radio" name="<?= $selectorId ?>-snippet-css-action" value="replace">
                    <span><?= __admin('preview.cssReplace', 'Replace all snippet CSS') ?></span>
                </label>
                <div class="snippet-selector__css-warning" id="<?= $selectorId ?>-snippet-css-warning" style="display: none;">
                    <span>⚠</span> <?= __admin('preview.cssWarning', 'This will permanently modify the project stylesheet and may affect all pages.') ?>
                </div>
            </div>
            <pre class="snippet-selector__css-code" id="<?= $selectorId ?>-snippet-css-code" style="display: none;"></pre>
        </div>
        <!-- Actions for non-core snippets -->
        <div class="snippet-selector__preview-actions" id="<?= $selectorId ?>-snippet-preview-actions" style="display: none;">
            <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" id="delete-snippet-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
                <?= __admin('preview.deleteSnippet', 'Delete Snippet') ?>
            </button>
        </div>
    </div>
</div>
