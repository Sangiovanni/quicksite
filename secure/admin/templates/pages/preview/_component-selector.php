<?php
/**
 * Component Selector Component
 * 
 * A searchable dropdown for component selection, following the same
 * tag-dropdown pattern used by the HTML Tag and Snippet selectors.
 * Flat list (no categories) — project-only components.
 * 
 * Usage: include with $selectorId parameter for unique IDs
 *   <?php $selectorId = 'add'; include '_component-selector.php'; ?>
 * 
 * Data is loaded dynamically via JS (listComponents API).
 */

$selectorId = $selectorId ?? 'add';
?>

<div class="component-selector" id="<?= $selectorId ?>-component-selector">
    <!-- Searchable Dropdown (tag-dropdown pattern) -->
    <div class="tag-dropdown" id="<?= $selectorId ?>-component-dropdown">
        <!-- Trigger button -->
        <button type="button" class="tag-dropdown__trigger" id="<?= $selectorId ?>-component-trigger">
            <span class="tag-dropdown__value" id="<?= $selectorId ?>-component-display"><?= __admin('preview.selectComponent', 'Select a component') ?></span>
            <span class="tag-dropdown__desc" id="<?= $selectorId ?>-component-display-desc"></span>
            <svg class="tag-dropdown__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
        
        <!-- Dropdown panel -->
        <div class="tag-dropdown__panel" id="<?= $selectorId ?>-component-panel" style="display: none;">
            <!-- Search input -->
            <div class="tag-dropdown__search">
                <svg class="tag-dropdown__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" 
                       class="tag-dropdown__search-input admin-input" 
                       id="<?= $selectorId ?>-component-search"
                       placeholder="<?= __admin('preview.searchComponents', 'Search components...') ?>"
                       autocomplete="off">
            </div>
            
            <!-- Options list (populated by JS from listComponents API) -->
            <div class="tag-dropdown__options" id="<?= $selectorId ?>-component-options">
                <div class="snippet-selector__loading">
                    <div class="spinner"></div>
                    <?= __admin('common.loading', 'Loading...') ?>
                </div>
            </div>
            
            <!-- No results message -->
            <div class="tag-dropdown__no-results" id="<?= $selectorId ?>-component-no-results" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span><?= __admin('preview.noComponentsFound', 'No components found') ?></span>
            </div>
        </div>
    </div>
    
    <!-- Hidden input for selected component -->
    <input type="hidden" id="<?= $selectorId ?>-component" value="">
    
    <!-- Preview Panel (shown when a component is selected and loaded) -->
    <div class="snippet-selector__preview" id="<?= $selectorId ?>-component-preview" style="display: none;">
        <div class="snippet-selector__preview-header">
            <span class="snippet-selector__preview-title" id="<?= $selectorId ?>-component-preview-title"></span>
        </div>
        <div class="snippet-selector__preview-frame-container">
            <iframe class="snippet-selector__preview-frame" id="<?= $selectorId ?>-component-preview-frame" sandbox="allow-same-origin"></iframe>
        </div>
    </div>
</div>
