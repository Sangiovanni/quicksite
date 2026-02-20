/**
 * AI Index Page JavaScript
 * 
 * Handles spec browsing: search, tag filtering, and import functionality.
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';
    
    // Get config from PHP
    const config = window.QUICKSITE_CONFIG || {};
    const apiBaseUrl = config.apiBaseUrl || '';
    const token = config.token || '';
    const translations = config.translations || {};
    
    // Helper for translations
    function t(key, fallback) {
        return translations[key] || fallback;
    }
    
    // DOM elements
    let searchInput;
    let tagButtons;
    let specCards;
    let categories;
    let noResults;
    let importBtn;
    let fileInput;
    
    // State
    let currentSearch = '';
    let currentTag = 'all';
    
    /**
     * Initialize the AI index page
     */
    function init() {
        // Get DOM elements
        searchInput = document.getElementById('spec-search');
        tagButtons = document.querySelectorAll('.ai-browser__tag-btn');
        specCards = document.querySelectorAll('.ai-spec-card');
        categories = document.querySelectorAll('.ai-category');
        noResults = document.getElementById('no-results');
        importBtn = document.getElementById('import-spec');
        fileInput = document.getElementById('import-file-input');
        
        // Bind event handlers
        if (searchInput) {
            searchInput.addEventListener('input', handleSearch);
        }
        
        tagButtons.forEach(btn => {
            btn.addEventListener('click', handleTagClick);
        });
        
        if (importBtn) {
            importBtn.addEventListener('click', () => fileInput?.click());
        }
        
        if (fileInput) {
            fileInput.addEventListener('change', handleImport);
        }
    }
    
    /**
     * Handle search input
     */
    function handleSearch() {
        currentSearch = searchInput.value;
        filterSpecs();
    }
    
    /**
     * Handle tag button click
     * @param {Event} e - Click event
     */
    function handleTagClick(e) {
        currentTag = e.target.dataset.tag;
        
        // Update active state
        tagButtons.forEach(b => b.classList.remove('ai-browser__tag-btn--active'));
        e.target.classList.add('ai-browser__tag-btn--active');
        
        filterSpecs();
    }
    
    /**
     * Filter specs based on current search and tag
     */
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
        if (noResults) {
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }
    
    /**
     * Handle spec import from file
     */
    async function handleImport() {
        const file = fileInput.files[0];
        if (!file) return;
        
        try {
            const content = await file.text();
            const data = JSON.parse(content);
            
            // Validate import structure
            if (!data.spec || !data.template) {
                throw new Error(t('invalidImport', 'Invalid import file: must contain spec and template'));
            }
            
            // Send to save endpoint
            const response = await fetch(`${apiBaseUrl}/api/ai-spec-save`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
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
                alert(t('importSuccess', 'Spec imported successfully!'));
                window.location.reload();
            } else {
                alert(t('importError', 'Import failed:') + ' ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            alert(t('importError', 'Import failed:') + ' ' + error.message);
        }
        
        // Reset file input
        fileInput.value = '';
    }
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
