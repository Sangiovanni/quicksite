/**
 * Preview Selector Browser Module
 * Handles the Selectors tab in Style mode
 * 
 * Dependencies:
 * - PreviewConfig global (from preview-config.php)
 * - PreviewState global (from preview-state.js)
 * 
 * @version 1.0.0
 */
(function() {
    'use strict';

    // ==================== Module State ====================
    
    let selectorsLoaded = false;
    let allSelectors = [];
    let categorizedSelectors = {
        tags: [],
        classes: [],
        ids: [],
        attributes: [],
        media: {}
    };
    let currentSelectedSelector = null;
    let hoveredSelector = null;
    let editingSelectorCount = 0;
    let elementFilterActive = false;
    let elementFilterInfo = null;
    
    // DOM element references
    let selectorsLoading = null;
    let selectorsGroups = null;
    let selectorCount = null;
    let selectorSearchInput = null;
    let selectorSearchClear = null;
    let selectorSelected = null;
    let selectorSelectedValue = null;
    let selectorSelectedClear = null;
    let selectorMatchCount = null;
    let selectorEditBtn = null;
    let selectorAnimateBtn = null;
    let selectorTagsList = null;
    let selectorTagsCount = null;
    let selectorClassesList = null;
    let selectorClassesCount = null;
    let selectorIdsList = null;
    let selectorIdsCount = null;
    let selectorAttributesList = null;
    let selectorAttributesCount = null;
    let selectorMediaList = null;
    let selectorMediaCount = null;
    
    // Shared references
    let managementUrl = '';
    let authToken = '';
    let iframe = null;
    
    // Callback references
    let onOpenStyleEditor = null;
    let onOpenTransitionEditor = null;

    // ==================== Utility Functions ====================
    
    function sendToIframe(action, data) {
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage({ source: 'quicksite-admin', action, ...data }, '*');
        }
    }

    // ==================== Initialization ====================
    
    function init() {
        // Get DOM references
        selectorsLoading = document.getElementById('selectors-loading');
        selectorsGroups = document.getElementById('selectors-groups');
        selectorCount = document.getElementById('selector-count');
        selectorSearchInput = document.getElementById('selector-search-input');
        selectorSearchClear = document.getElementById('selector-search-clear');
        selectorSelected = document.getElementById('selector-selected');
        selectorSelectedValue = document.getElementById('selector-selected-value');
        selectorSelectedClear = document.getElementById('selector-selected-clear');
        selectorMatchCount = document.getElementById('selector-match-count');
        selectorEditBtn = document.getElementById('selector-edit-btn');
        selectorAnimateBtn = document.getElementById('selector-animate-btn');
        selectorTagsList = document.getElementById('selectors-tags-list');
        selectorTagsCount = document.getElementById('selectors-tags-count');
        selectorClassesList = document.getElementById('selectors-classes-list');
        selectorClassesCount = document.getElementById('selectors-classes-count');
        selectorIdsList = document.getElementById('selectors-ids-list');
        selectorIdsCount = document.getElementById('selectors-ids-count');
        selectorAttributesList = document.getElementById('selectors-attributes-list');
        selectorAttributesCount = document.getElementById('selectors-attributes-count');
        selectorMediaList = document.getElementById('selectors-media-list');
        selectorMediaCount = document.getElementById('selectors-media-count');
        
        // Get shared references from PreviewConfig
        managementUrl = PreviewConfig.managementUrl;
        authToken = PreviewConfig.authToken;
        iframe = document.getElementById('preview-iframe');
        
        // Initialize event handlers
        initSelectorBrowser();
    }

    // ==================== Load Selectors ====================
    
    /**
     * Load CSS selectors data from API (data only, no UI)
     * Used by both Style mode and JS mode picker
     */
    async function loadSelectorsData() {
        if (selectorsLoaded) return true;
        
        try {
            const response = await fetch(managementUrl + 'listStyleRules', {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            const data = await response.json();
            
            if (data.status === 200 && data.data?.selectors) {
                allSelectors = data.data.selectors;
                selectorsLoaded = true;
                
                // Categorize CSS selectors from stylesheet
                categorizeSelectors(data.data);
                
                // Merge with DOM-present tags/classes/IDs from the iframe
                mergeWithDomSelectors();
                
                return true;
            }
        } catch (error) {
            console.error('Error loading selectors data:', error);
        }
        return false;
    }
    
    /**
     * Load all CSS selectors from the API (with UI updates for Style panel)
     */
    async function loadStyleSelectors() {
        if (!selectorsLoading || !selectorsGroups) {
            // No UI elements - just load data
            await loadSelectorsData();
            return;
        }
        
        // Show loading state
        selectorsLoading.style.display = '';
        selectorsGroups.style.display = 'none';
        
        const success = await loadSelectorsData();
        
        if (success) {
            // Populate the UI
            populateSelectorBrowser();
            
            // Show content, hide loading
            selectorsLoading.style.display = 'none';
            selectorsGroups.style.display = '';
        } else {
            selectorsLoading.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" style="color: #ef4444;">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span style="color: #ef4444;">${PreviewConfig.i18n?.selectorsLoadError || 'Failed to load selectors'}</span>
            `;
        }
    }

    // ==================== Categorize Selectors ====================
    
    /**
     * Categorize selectors by type
     */
    function categorizeSelectors(data) {
        categorizedSelectors = { tags: [], classes: [], ids: [], attributes: [], media: {} };
        
        // Process global selectors
        const globalSelectors = data.grouped?.global || [];
        globalSelectors.forEach(selector => {
            categorizeSelector(selector, null);
        });
        
        // Process media query selectors
        const mediaSelectors = data.grouped?.media || {};
        for (const [mediaQuery, selectors] of Object.entries(mediaSelectors)) {
            categorizedSelectors.media[mediaQuery] = [];
            selectors.forEach(selector => {
                categorizedSelectors.media[mediaQuery].push(selector);
            });
        }
    }
    
    /**
     * Categorize a single selector
     */
    function categorizeSelector(selector, mediaQuery) {
        // Skip :root and complex compound selectors for simplicity
        if (selector === ':root') return;
        
        // Extract the primary selector part (before any combinator or pseudo)
        const primarySelector = selector.split(/[\s>+~:]/)[0];
        
        if (primarySelector.startsWith('.')) {
            categorizedSelectors.classes.push({ selector, mediaQuery, hasRules: true });
        } else if (primarySelector.startsWith('#')) {
            categorizedSelectors.ids.push({ selector, mediaQuery, hasRules: true });
        } else if (primarySelector.startsWith('[')) {
            categorizedSelectors.attributes.push({ selector, mediaQuery, hasRules: true });
        } else if (/^[a-zA-Z]/.test(primarySelector)) {
            // Starts with a letter = tag selector
            categorizedSelectors.tags.push({ selector, mediaQuery, hasRules: true });
        } else {
            // Other (like * or complex selectors) - put in tags for now
            categorizedSelectors.tags.push({ selector, mediaQuery, hasRules: true });
        }
    }

    // ==================== DOM Selector Merging ====================

    /**
     * Scan the iframe DOM for tag names and class names not already in the
     * CSS selectors list, then merge them in. This gives a complete picture
     * of all targetable selectors on the current page.
     */
    function mergeWithDomSelectors() {
        let iframeDoc;
        try {
            iframeDoc = iframe?.contentDocument || iframe?.contentWindow?.document;
        } catch (e) {
            console.warn('[SelectorBrowser] Cannot access iframe document for DOM scan:', e);
            return;
        }
        if (!iframeDoc) return;

        // Collect existing CSS selector names for fast lookup
        const existingTags = new Set(categorizedSelectors.tags.map(t => t.selector.toLowerCase()));
        const existingClasses = new Set(categorizedSelectors.classes.map(c => c.selector));
        const existingIds = new Set(categorizedSelectors.ids.map(i => i.selector));

        // Scan all elements in the iframe body
        const allElements = iframeDoc.body?.querySelectorAll('*') || [];
        const domTags = new Set();
        const domClasses = new Set();
        const domIds = new Set();

        allElements.forEach(function(el) {
            // Skip QuickSite overlay elements
            if (el.closest('#qs-overlay-root') || el.id === 'qs-overlay-root') return;
            if (el.closest('#qs-live-preview-style')) return;

            // Collect tag name
            const tag = el.tagName.toLowerCase();
            if (tag !== 'script' && tag !== 'link' && tag !== 'meta' && tag !== 'style') {
                domTags.add(tag);
            }

            // Collect classes (skip qs- internal classes)
            el.classList.forEach(function(cls) {
                if (!cls.startsWith('qs-')) {
                    domClasses.add(cls);
                }
            });

            // Collect IDs (skip qs- internal IDs)
            if (el.id && !el.id.startsWith('qs-') && !el.id.startsWith('qs_')) {
                domIds.add(el.id);
            }
        });

        // Merge tags that are in DOM but not yet in CSS selectors
        domTags.forEach(function(tag) {
            if (!existingTags.has(tag)) {
                categorizedSelectors.tags.push({ selector: tag, mediaQuery: null, hasRules: false });
            }
        });

        // Merge classes
        domClasses.forEach(function(cls) {
            const classSelector = '.' + cls;
            if (!existingClasses.has(classSelector)) {
                categorizedSelectors.classes.push({ selector: classSelector, mediaQuery: null, hasRules: false });
            }
        });

        // Merge IDs
        domIds.forEach(function(id) {
            const idSelector = '#' + id;
            if (!existingIds.has(idSelector)) {
                categorizedSelectors.ids.push({ selector: idSelector, mediaQuery: null, hasRules: false });
            }
        });

        // Sort: CSS selectors (hasRules) first, then DOM-only, alphabetically within each group
        const sortFn = function(a, b) {
            if (a.hasRules !== b.hasRules) return a.hasRules ? -1 : 1;
            return a.selector.localeCompare(b.selector);
        };
        categorizedSelectors.tags.sort(sortFn);
        categorizedSelectors.classes.sort(sortFn);
        categorizedSelectors.ids.sort(sortFn);
    }

    // ==================== Populate UI ====================
    
    /**
     * Populate the selector browser UI
     */
    function populateSelectorBrowser() {
        // Clear existing items
        if (selectorTagsList) selectorTagsList.innerHTML = '';
        if (selectorClassesList) selectorClassesList.innerHTML = '';
        if (selectorIdsList) selectorIdsList.innerHTML = '';
        if (selectorAttributesList) selectorAttributesList.innerHTML = '';
        if (selectorMediaList) selectorMediaList.innerHTML = '';
        
        // Populate Tags
        categorizedSelectors.tags.forEach(item => {
            const chip = createSelectorChip(item.selector, 'tag', null, item.hasRules);
            selectorTagsList?.appendChild(chip);
        });
        
        // Populate Classes
        categorizedSelectors.classes.forEach(item => {
            const chip = createSelectorChip(item.selector, 'class', null, item.hasRules);
            selectorClassesList?.appendChild(chip);
        });
        
        // Populate IDs
        categorizedSelectors.ids.forEach(item => {
            const chip = createSelectorChip(item.selector, 'id', null, item.hasRules);
            selectorIdsList?.appendChild(chip);
        });
        
        // Populate Attributes
        categorizedSelectors.attributes.forEach(item => {
            const chip = createSelectorChip(item.selector, 'attribute', null, item.hasRules !== false);
            selectorAttributesList?.appendChild(chip);
        });
        
        // Populate Media Queries
        for (const [mediaQuery, selectors] of Object.entries(categorizedSelectors.media)) {
            const mediaGroup = document.createElement('div');
            mediaGroup.className = 'preview-selectors-media-group';
            
            const mediaTitle = document.createElement('div');
            mediaTitle.className = 'preview-selectors-media-group__title';
            mediaTitle.textContent = `@media ${mediaQuery}`;
            mediaGroup.appendChild(mediaTitle);
            
            const mediaChips = document.createElement('div');
            mediaChips.className = 'preview-selectors-group__list';
            mediaChips.style.paddingLeft = '0';
            
            selectors.forEach(selector => {
                const chip = createSelectorChip(selector, 'media', mediaQuery);
                mediaChips.appendChild(chip);
            });
            
            mediaGroup.appendChild(mediaChips);
            selectorMediaList?.appendChild(mediaGroup);
        }
        
        // Update counts
        updateSelectorCounts();
        
        // Update total count
        const totalCount = categorizedSelectors.tags.length + 
                          categorizedSelectors.classes.length + 
                          categorizedSelectors.ids.length + 
                          categorizedSelectors.attributes.length +
                          Object.values(categorizedSelectors.media).flat().length;
        if (selectorCount) selectorCount.textContent = totalCount;
        
        // Hide empty groups
        hideEmptyGroups();
    }
    
    /**
     * Create a selector chip element
     * @param {string} selector - CSS selector
     * @param {string} type - tag|class|id|attribute|media
     * @param {string|null} mediaQuery - media query string if applicable
     * @param {boolean} hasRules - whether the selector has existing CSS rules
     */
    function createSelectorChip(selector, type, mediaQuery, hasRules) {
        if (hasRules === undefined) hasRules = true;
        
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = `preview-selector-item preview-selector-item--${type}`;
        if (!hasRules) {
            chip.classList.add('preview-selector-item--dom-only');
        }
        chip.textContent = selector;
        chip.title = selector + (mediaQuery ? ` (${mediaQuery})` : '') + (hasRules ? '' : ' — no CSS rules yet');
        chip.dataset.selector = selector;
        chip.dataset.hasRules = hasRules ? '1' : '0';
        if (mediaQuery) chip.dataset.media = mediaQuery;
        
        // Hover: highlight elements in iframe
        chip.addEventListener('mouseenter', () => {
            hoveredSelector = selector;
            sendToIframe('highlightBySelector', { selector });
        });
        
        chip.addEventListener('mouseleave', () => {
            if (hoveredSelector === selector) {
                hoveredSelector = null;
                sendToIframe('clearSelectorHighlight', {});
            }
        });
        
        // Click: select this selector
        chip.addEventListener('click', () => {
            selectSelector(selector, mediaQuery);
        });
        
        return chip;
    }
    
    /**
     * Update selector counts in group headers
     */
    function updateSelectorCounts() {
        if (selectorTagsCount) selectorTagsCount.textContent = categorizedSelectors.tags.length;
        if (selectorClassesCount) selectorClassesCount.textContent = categorizedSelectors.classes.length;
        if (selectorIdsCount) selectorIdsCount.textContent = categorizedSelectors.ids.length;
        if (selectorAttributesCount) selectorAttributesCount.textContent = categorizedSelectors.attributes.length;
        if (selectorMediaCount) {
            const mediaCount = Object.values(categorizedSelectors.media).flat().length;
            selectorMediaCount.textContent = mediaCount;
        }
    }
    
    /**
     * Hide empty selector groups
     */
    function hideEmptyGroups() {
        const groups = selectorsGroups?.querySelectorAll('.preview-selectors-group');
        groups?.forEach(group => {
            const groupName = group.dataset.group;
            let isEmpty = false;
            
            if (groupName === 'tags') isEmpty = categorizedSelectors.tags.length === 0;
            else if (groupName === 'classes') isEmpty = categorizedSelectors.classes.length === 0;
            else if (groupName === 'ids') isEmpty = categorizedSelectors.ids.length === 0;
            else if (groupName === 'attributes') isEmpty = categorizedSelectors.attributes.length === 0;
            else if (groupName === 'media') isEmpty = Object.keys(categorizedSelectors.media).length === 0;
            
            group.style.display = isEmpty ? 'none' : '';
        });
    }

    // ==================== Selection ====================
    
    /**
     * Select a selector and show its info
     */
    function selectSelector(selector, mediaQuery = null) {
        currentSelectedSelector = { selector, mediaQuery };
        
        // Update UI
        if (selectorSelectedValue) selectorSelectedValue.textContent = selector;
        if (selectorSelected) selectorSelected.style.display = '';
        
        // Clear previous active states
        selectorsGroups?.querySelectorAll('.preview-selector-item--active').forEach(el => {
            el.classList.remove('preview-selector-item--active');
        });
        
        // Mark the clicked selector as active
        const activeChip = selectorsGroups?.querySelector(`[data-selector="${CSS.escape(selector)}"]`);
        if (activeChip) activeChip.classList.add('preview-selector-item--active');
        
        // Highlight and count matching elements in iframe
        sendToIframe('selectBySelector', { selector });
        
        // Count matches
        countSelectorMatches(selector);
    }
    
    /**
     * Clear selector selection
     */
    function clearSelectorSelection() {
        currentSelectedSelector = null;
        if (selectorSelected) selectorSelected.style.display = 'none';
        
        // Clear active states
        selectorsGroups?.querySelectorAll('.preview-selector-item--active').forEach(el => {
            el.classList.remove('preview-selector-item--active');
        });
        
        // Clear highlight in iframe
        sendToIframe('clearSelectorHighlight', {});
    }
    
    /**
     * Count how many elements match the selector in the iframe
     */
    function countSelectorMatches(selector) {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (iframeDoc) {
                const matches = iframeDoc.querySelectorAll(selector);
                const count = matches.length;
                if (selectorMatchCount) selectorMatchCount.textContent = count;
                editingSelectorCount = count;
            }
        } catch (e) {
            console.warn('Could not count selector matches:', e);
            if (selectorMatchCount) selectorMatchCount.textContent = '?';
            editingSelectorCount = 0;
        }
    }

    // ==================== Filtering ====================
    
    /**
     * Filter selectors based on search input
     */
    function filterSelectors(query) {
        // If element filter is active, combine with text search
        if (elementFilterActive && elementFilterInfo) {
            filterByElementAndSearch(query);
            return;
        }
        
        const searchLower = query.toLowerCase();
        
        selectorsGroups?.querySelectorAll('.preview-selector-item').forEach(chip => {
            const selector = chip.dataset.selector || '';
            const matches = selector.toLowerCase().includes(searchLower);
            chip.style.display = matches ? '' : 'none';
        });
        
        // Update visible counts
        updateVisibleSelectorCounts();
        
        // Show/hide clear button
        if (selectorSearchClear) {
            selectorSearchClear.style.display = query ? '' : 'none';
        }
    }
    
    /**
     * Filter selectors by element context (tag + classes + id).
     * Shows only selectors that are relevant to the clicked element.
     * 
     * @param {Object} elementInfo - { tag, classList, id }
     */
    function filterByElement(elementInfo) {
        if (!elementInfo) return;
        
        elementFilterActive = true;
        elementFilterInfo = elementInfo;
        
        // Build a set of relevant selectors for this element
        const relevantSelectors = new Set();
        
        // The element's tag
        if (elementInfo.tag) {
            relevantSelectors.add(elementInfo.tag.toLowerCase());
        }
        
        // The element's classes
        if (elementInfo.classList && elementInfo.classList.length > 0) {
            elementInfo.classList.forEach(function(cls) {
                if (!cls.startsWith('qs-')) {
                    relevantSelectors.add('.' + cls);
                }
            });
        }
        
        // The element's ID
        if (elementInfo.id) {
            relevantSelectors.add('#' + elementInfo.id);
        }
        
        // Show filter bar
        showElementFilterBar(elementInfo);
        
        // Apply filter (combined with any existing search text)
        const searchQuery = selectorSearchInput?.value || '';
        filterByElementAndSearch(searchQuery);
    }
    
    /**
     * Apply both element filter and text search
     */
    function filterByElementAndSearch(searchQuery) {
        if (!elementFilterInfo) return;
        
        const searchLower = searchQuery.toLowerCase();
        
        // Build a set of relevant selectors for this element
        const relevantSelectors = new Set();
        if (elementFilterInfo.tag) {
            relevantSelectors.add(elementFilterInfo.tag.toLowerCase());
        }
        if (elementFilterInfo.classList) {
            elementFilterInfo.classList.forEach(function(cls) {
                if (!cls.startsWith('qs-')) {
                    relevantSelectors.add('.' + cls);
                }
            });
        }
        if (elementFilterInfo.id) {
            relevantSelectors.add('#' + elementFilterInfo.id);
        }
        
        selectorsGroups?.querySelectorAll('.preview-selector-item').forEach(chip => {
            const selector = chip.dataset.selector || '';
            
            // Must match element (tag, class, or id)
            const matchesElement = relevantSelectors.has(selector.toLowerCase());
            
            // Also must match text search if provided
            const matchesSearch = !searchQuery || selector.toLowerCase().includes(searchLower);
            
            chip.style.display = (matchesElement && matchesSearch) ? '' : 'none';
        });
        
        // Update visible counts
        updateVisibleSelectorCounts();
        
        // Show/hide search clear button
        if (selectorSearchClear) {
            selectorSearchClear.style.display = searchQuery ? '' : 'none';
        }
    }
    
    /**
     * Show the element filter bar above the selectors list
     */
    function showElementFilterBar(elementInfo) {
        removeElementFilterBar();
        
        if (!selectorsGroups) return;
        
        const filterBar = document.createElement('div');
        filterBar.className = 'preview-selectors-filter-bar';
        filterBar.id = 'selectors-element-filter-bar';
        
        // Build display label (e.g. "div.hero-section")
        let label = elementInfo.tag || '';
        if (elementInfo.classList && elementInfo.classList.length > 0) {
            const visibleClasses = elementInfo.classList.filter(function(c) { return !c.startsWith('qs-'); });
            if (visibleClasses.length > 0) {
                label += '.' + visibleClasses.slice(0, 3).join('.');
                if (visibleClasses.length > 3) label += '...';
            }
        }
        if (elementInfo.id) {
            label = elementInfo.tag + '#' + elementInfo.id;
        }
        
        const labelSpan = document.createElement('span');
        labelSpan.className = 'preview-selectors-filter-bar__label';
        labelSpan.textContent = label;
        labelSpan.title = label;
        
        const prefix = document.createElement('span');
        prefix.textContent = (PreviewConfig.i18n?.filteringBy || 'Filtering:') + ' ';
        
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'preview-selectors-filter-bar__clear';
        clearBtn.textContent = PreviewConfig.i18n?.showAll || 'Show All';
        clearBtn.addEventListener('click', clearElementFilter);
        
        filterBar.appendChild(prefix);
        filterBar.appendChild(labelSpan);
        filterBar.appendChild(clearBtn);
        
        selectorsGroups.parentNode.insertBefore(filterBar, selectorsGroups);
    }
    
    /**
     * Remove the element filter bar
     */
    function removeElementFilterBar() {
        var existingBar = document.getElementById('selectors-element-filter-bar');
        if (existingBar) existingBar.remove();
    }
    
    /**
     * Clear the element filter and show all selectors again
     */
    function clearElementFilter() {
        elementFilterActive = false;
        elementFilterInfo = null;
        removeElementFilterBar();
        
        // Re-apply only the text search filter (or show all)
        const searchQuery = selectorSearchInput?.value || '';
        filterSelectors(searchQuery);
    }
    
    /**
     * Update counts based on visible selectors after filtering
     */
    function updateVisibleSelectorCounts() {
        const countVisibleIn = (listEl) => {
            return listEl?.querySelectorAll('.preview-selector-item:not([style*="display: none"])').length || 0;
        };
        
        if (selectorTagsCount) selectorTagsCount.textContent = countVisibleIn(selectorTagsList);
        if (selectorClassesCount) selectorClassesCount.textContent = countVisibleIn(selectorClassesList);
        if (selectorIdsCount) selectorIdsCount.textContent = countVisibleIn(selectorIdsList);
        if (selectorAttributesCount) selectorAttributesCount.textContent = countVisibleIn(selectorAttributesList);
        
        // Media - count all visible in media list
        if (selectorMediaCount) {
            selectorMediaCount.textContent = countVisibleIn(selectorMediaList);
        }
        
        // Update total
        const total = countVisibleIn(selectorTagsList) + 
                     countVisibleIn(selectorClassesList) + 
                     countVisibleIn(selectorIdsList) + 
                     countVisibleIn(selectorAttributesList) +
                     countVisibleIn(selectorMediaList);
        if (selectorCount) selectorCount.textContent = total;
    }

    // ==================== Event Handlers ====================
    
    /**
     * Initialize selector browser event handlers
     */
    function initSelectorBrowser() {
        // Search input
        if (selectorSearchInput) {
            selectorSearchInput.addEventListener('input', (e) => {
                filterSelectors(e.target.value);
            });
        }
        
        // Search clear button
        if (selectorSearchClear) {
            selectorSearchClear.addEventListener('click', () => {
                if (selectorSearchInput) selectorSearchInput.value = '';
                filterSelectors('');
            });
        }
        
        // Clear selection button
        if (selectorSelectedClear) {
            selectorSelectedClear.addEventListener('click', clearSelectorSelection);
        }
        
        // Edit styles button - calls callback
        if (selectorEditBtn) {
            selectorEditBtn.addEventListener('click', () => {
                if (currentSelectedSelector && onOpenStyleEditor) {
                    onOpenStyleEditor(currentSelectedSelector.selector, editingSelectorCount || 0);
                }
            });
        }
        
        // Animate button - calls callback
        if (selectorAnimateBtn) {
            selectorAnimateBtn.addEventListener('click', () => {
                if (currentSelectedSelector && onOpenTransitionEditor) {
                    onOpenTransitionEditor(currentSelectedSelector.selector);
                }
            });
        }
        
        // Group header collapse/expand
        selectorsGroups?.querySelectorAll('.preview-selectors-group__header').forEach(header => {
            header.addEventListener('click', () => {
                const isExpanded = header.classList.contains('preview-selectors-group__header--expanded');
                header.classList.toggle('preview-selectors-group__header--expanded');
                
                const list = header.nextElementSibling;
                if (list) {
                    list.style.display = isExpanded ? 'none' : '';
                }
            });
        });
    }

    // ==================== Public API ====================
    
    window.PreviewSelectorBrowser = {
        init: init,
        load: loadStyleSelectors,
        loadData: loadSelectorsData,
        isLoaded: function() { return selectorsLoaded; },
        reset: function() {
            selectorsLoaded = false;
            allSelectors = [];
            categorizedSelectors = { tags: [], classes: [], ids: [], attributes: [], media: {} };
            currentSelectedSelector = null;
            elementFilterActive = false;
            elementFilterInfo = null;
            removeElementFilterBar();
        },
        
        // Get data for external use
        getAllSelectors: function() { return allSelectors; },
        getCategorizedSelectors: function() { return categorizedSelectors; },
        getSelectedSelector: function() { return currentSelectedSelector; },
        getSelectedCount: function() { return editingSelectorCount; },
        
        // Selection methods
        selectSelector: selectSelector,
        clearSelection: clearSelectorSelection,
        
        // Element filtering
        filterByElement: filterByElement,
        clearElementFilter: clearElementFilter,
        isElementFilterActive: function() { return elementFilterActive; },
        
        // Register callbacks
        onOpenStyleEditor: function(callback) { onOpenStyleEditor = callback; },
        onOpenTransitionEditor: function(callback) { onOpenTransitionEditor = callback; }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
