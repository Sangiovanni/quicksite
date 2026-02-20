/**
 * Preview Style Editor Module
 * 
 * Handles CSS property editing for selected selectors.
 * Part of the Visual Editor refactoring.
 * 
 * Dependencies:
 * - PreviewState global (from preview-state.js)
 * - PreviewConfig global
 * - QSPropertySelector class
 * - QSValueInput class
 * - QSColorPicker class
 */
(function() {
    'use strict';
    
    // ==================== DOM Elements ====================
    
    const styleEditor = document.getElementById('style-editor');
    const styleEditorBack = document.getElementById('style-editor-back');
    const styleEditorLabel = document.getElementById('style-editor-label');
    const styleEditorSelector = document.getElementById('style-editor-selector');
    const styleEditorCount = document.getElementById('style-editor-count');
    const styleEditorLoading = document.getElementById('style-editor-loading');
    const styleEditorEmpty = document.getElementById('style-editor-empty');
    const styleEditorProperties = document.getElementById('style-editor-properties');
    const styleEditorAdd = document.getElementById('style-editor-add');
    const styleEditorAddBtn = document.getElementById('style-editor-add-btn');
    const styleEditorAddFirst = document.getElementById('style-editor-add-first');
    const styleEditorActions = document.getElementById('style-editor-actions');
    const styleEditorReset = document.getElementById('style-editor-reset');
    const styleEditorSave = document.getElementById('style-editor-save');
    const selectorsPanel = document.getElementById('selectors-panel');
    const selectorsLoading = document.getElementById('selectors-loading');
    const selectorsGroups = document.getElementById('selectors-groups');
    const selectorSelected = document.getElementById('selector-selected');
    
    // ==================== Configuration ====================
    
    const managementUrl = PreviewConfig?.managementUrl || '';
    const authToken = PreviewConfig?.authToken || '';
    
    // Common CSS properties that should use color picker
    const colorProperties = [
        'color', 'background-color', 'background', 'border-color', 'border-top-color',
        'border-right-color', 'border-bottom-color', 'border-left-color', 'outline-color',
        'box-shadow', 'text-shadow', 'fill', 'stroke', 'caret-color', 'accent-color',
        'text-decoration-color', 'column-rule-color'
    ];
    
    /**
     * CSS Properties organized by category for the property selector dropdown
     */
    const CSS_PROPERTIES_BY_CATEGORY = {
        'Layout': [
            'display', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'box-sizing', 'overflow', 'overflow-x', 'overflow-y'
        ],
        'Flexbox': [
            'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
            'gap', 'row-gap', 'column-gap', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
            'align-self', 'order'
        ],
        'Grid': [
            'grid-template-columns', 'grid-template-rows', 'grid-gap', 'grid-column-gap',
            'grid-row-gap', 'grid-auto-flow', 'grid-column', 'grid-row', 'place-items',
            'place-content', 'place-self'
        ],
        'Position': [
            'position', 'top', 'right', 'bottom', 'left', 'z-index', 'inset'
        ],
        'Typography': [
            'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
            'letter-spacing', 'word-spacing', 'text-align', 'text-decoration', 
            'text-transform', 'white-space', 'word-break', 'text-overflow'
        ],
        'Colors': [
            'color', 'background-color', 'background', 'background-image',
            'border-color', 'outline-color', 'fill', 'stroke'
        ],
        'Borders': [
            'border', 'border-width', 'border-style', 'border-color', 'border-radius',
            'border-top', 'border-right', 'border-bottom', 'border-left',
            'outline', 'outline-width', 'outline-style', 'outline-offset'
        ],
        'Effects': [
            'opacity', 'visibility', 'box-shadow', 'text-shadow', 'filter',
            'backdrop-filter', 'mix-blend-mode', 'clip-path'
        ],
        'Transform': [
            'transform', 'transform-origin', 'perspective', 'translate', 'rotate', 'scale'
        ],
        'Transition': [
            'transition', 'transition-property', 'transition-duration', 
            'transition-timing-function', 'transition-delay'
        ],
        'Animation': [
            'animation', 'animation-name', 'animation-duration', 'animation-timing-function',
            'animation-delay', 'animation-iteration-count', 'animation-direction', 'animation-fill-mode'
        ],
        'Other': [
            'cursor', 'pointer-events', 'user-select', 'content', 'list-style',
            'object-fit', 'object-position', 'aspect-ratio'
        ]
    };
    
    // ==================== State ====================
    
    let styleEditorVisible = false;
    let editingSelector = null;
    let editingSelectorCount = 0;
    let originalStyles = {};
    let currentStyles = {};
    let newProperties = [];
    let deletedProperties = [];
    let stylePreviewInjected = false;
    let currentSelectedSelector = null;
    
    // Color picker tracking
    let activeColorPicker = null;
    let activeColorPickerInput = null;
    
    // Live preview debounce
    let livePreviewTimeout = null;
    
    // Callbacks
    let showToastFn = null;
    let getIframeFn = null;
    let getThemeVariablesFn = null;
    let getPropertyTypesFn = null;
    
    // ==================== Utility Functions ====================
    
    function showToast(message, type, duration) {
        if (showToastFn) {
            showToastFn(message, type, duration);
        } else {
            console.log(`[StyleEditor Toast] ${type}: ${message}`);
        }
    }
    
    function getIframe() {
        if (getIframeFn) return getIframeFn();
        return document.getElementById('preview-iframe');
    }
    
    function getThemeVariables() {
        if (getThemeVariablesFn) return getThemeVariablesFn();
        if (window.PreviewStyleTheme) return PreviewStyleTheme.getVariables();
        return {};
    }
    
    function getPropertyType(property) {
        if (getPropertyTypesFn) return getPropertyTypesFn(property);
        return { type: 'text' };
    }
    
    // ==================== Style Editor Functions ====================
    
    /**
     * Open the Style Editor for a selector
     * @param {string} selector - CSS selector to edit
     * @param {number} matchCount - Number of elements matching this selector
     */
    async function openStyleEditor(selector, matchCount = 0) {
        if (!styleEditor) return;
        
        editingSelector = selector;
        editingSelectorCount = matchCount;
        originalStyles = {};
        currentStyles = {};
        newProperties = [];
        deletedProperties = [];
        
        // Update header
        if (styleEditorSelector) styleEditorSelector.textContent = selector;
        if (styleEditorCount) styleEditorCount.textContent = matchCount;
        
        // Show loading state
        showStyleEditorState('loading');
        
        // Hide selector browser content, show style editor
        if (selectorsPanel) {
            selectorsPanel.querySelector('.preview-selectors-search')?.style.setProperty('display', 'none');
            selectorsLoading?.style.setProperty('display', 'none');
            selectorsGroups?.style.setProperty('display', 'none');
            selectorSelected?.style.setProperty('display', 'none');
        }
        styleEditor.style.display = 'flex';
        styleEditorVisible = true;
        
        // Load styles from API
        try {
            const response = await fetch(managementUrl + 'getStyleRule/' + encodeURIComponent(selector), {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            
            const result = await response.json();
            
            if (response.ok && result.data) {
                const stylesString = result.data.styles || '';
                originalStyles = parseStylesString(stylesString);
                currentStyles = { ...originalStyles };
                
                if (Object.keys(originalStyles).length === 0) {
                    showStyleEditorState('empty');
                } else {
                    renderStyleProperties();
                    showStyleEditorState('properties');
                }
            } else {
                // Selector not found in CSS (might be a browser-default or doesn't exist)
                originalStyles = {};
                currentStyles = {};
                showStyleEditorState('empty');
            }
        } catch (error) {
            console.error('Failed to load styles:', error);
            showToast(PreviewConfig.i18n.failedToLoadStyles, 'error');
            closeStyleEditor();
        }
    }
    
    /**
     * Close the Style Editor and return to selector browser
     */
    function closeStyleEditor() {
        styleEditorVisible = false;
        editingSelector = null;
        
        // Remove live preview styles
        removeLivePreviewStyles();
        
        // Hide style editor
        if (styleEditor) styleEditor.style.display = 'none';
        
        // Show selector browser content
        if (selectorsPanel) {
            selectorsPanel.querySelector('.preview-selectors-search')?.style.setProperty('display', '');
            selectorsGroups?.style.setProperty('display', '');
            if (currentSelectedSelector) {
                selectorSelected?.style.setProperty('display', '');
            }
        }
    }
    
    /**
     * Show a specific state of the style editor
     * @param {'loading'|'empty'|'properties'} state
     */
    function showStyleEditorState(state) {
        if (styleEditorLoading) styleEditorLoading.style.display = state === 'loading' ? '' : 'none';
        if (styleEditorEmpty) styleEditorEmpty.style.display = state === 'empty' ? '' : 'none';
        if (styleEditorProperties) styleEditorProperties.style.display = state === 'properties' ? '' : 'none';
        if (styleEditorAdd) styleEditorAdd.style.display = (state === 'properties' || state === 'empty') ? '' : 'none';
        if (styleEditorActions) styleEditorActions.style.display = (state === 'properties' || state === 'empty') ? '' : 'none';
    }
    
    /**
     * Parse CSS styles string into object
     * @param {string} stylesString - CSS declarations like "color: red; font-size: 16px;"
     * @returns {Object} Property/value pairs
     */
    function parseStylesString(stylesString) {
        const result = {};
        if (!stylesString) return result;
        
        // Split by semicolons, handling multi-line
        const declarations = stylesString.split(/;\s*/);
        
        for (const decl of declarations) {
            const trimmed = decl.trim();
            if (!trimmed) continue;
            
            const colonIndex = trimmed.indexOf(':');
            if (colonIndex === -1) continue;
            
            const property = trimmed.substring(0, colonIndex).trim();
            const value = trimmed.substring(colonIndex + 1).trim();
            
            if (property && value) {
                result[property] = value;
            }
        }
        
        return result;
    }
    
    /**
     * Extract color from a compound CSS value (like box-shadow, text-shadow, border)
     * @param {string} value - Full CSS value
     * @returns {object|null} - { color, prefix, suffix, fullMatch } or null if no color found
     */
    function extractColorFromValue(value) {
        if (!value) return null;
        
        const trimmed = value.trim();
        
        // If the value is a simple var() reference, don't treat as compound
        if (/^var\(--[\w-]+(?:\s*,\s*[^)]+)?\)$/i.test(trimmed)) {
            return null;
        }
        
        // Patterns to match colors in compound values
        const colorPatterns = [
            // rgba(r, g, b, a)
            /(rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+)?\s*\))/i,
            // hsla(h, s%, l%, a)
            /(hsla?\(\s*\d+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(?:,\s*[\d.]+)?\s*\))/i,
            // #hex colors (3, 4, 6, or 8 digits)
            /(#[0-9a-fA-F]{3,8})\b/
        ];
        
        // Named colors - checked separately to avoid matching inside var()
        const namedColors = ['red', 'blue', 'green', 'yellow', 'orange', 'purple', 'pink', 
            'white', 'black', 'gray', 'grey', 'cyan', 'magenta', 'lime', 'navy', 
            'teal', 'maroon', 'olive', 'silver', 'aqua', 'fuchsia', 'transparent'];
        
        for (const pattern of colorPatterns) {
            const match = value.match(pattern);
            if (match) {
                const colorMatch = match[1];
                const index = match.index;
                return {
                    color: colorMatch,
                    prefix: value.substring(0, index),
                    suffix: value.substring(index + colorMatch.length),
                    fullMatch: colorMatch
                };
            }
        }
        
        // Check for named colors, but exclude matches inside var() functions
        const varMatches = [];
        const tempValue = value.replace(/var\([^)]+\)/gi, (match) => {
            varMatches.push(match);
            return `__VAR_${varMatches.length - 1}__`;
        });
        
        for (const colorName of namedColors) {
            const regex = new RegExp(`\\b(${colorName})\\b`, 'i');
            const match = tempValue.match(regex);
            if (match) {
                const tempIndex = match.index;
                
                let offset = 0;
                for (let i = 0; i < varMatches.length; i++) {
                    const placeholder = `__VAR_${i}__`;
                    const placeholderPos = tempValue.indexOf(placeholder);
                    if (placeholderPos !== -1 && placeholderPos < tempIndex) {
                        offset += varMatches[i].length - placeholder.length;
                    }
                }
                
                const actualIndex = tempIndex + offset;
                return {
                    color: match[1],
                    prefix: value.substring(0, actualIndex),
                    suffix: value.substring(actualIndex + match[1].length),
                    fullMatch: match[1]
                };
            }
        }
        
        return null;
    }
    
    /**
     * Rebuild a compound value with a new color
     */
    function rebuildValueWithColor(prefix, newColor, suffix) {
        return prefix + newColor + suffix;
    }
    
    /**
     * Check if a value looks like a color
     */
    function isColorValue(value) {
        if (!value) return false;
        const v = value.toLowerCase().trim();
        return v.startsWith('#') || 
               v.startsWith('rgb') || 
               v.startsWith('hsl') ||
               /^(red|blue|green|yellow|orange|purple|pink|white|black|gray|grey|transparent|inherit|currentcolor)$/i.test(v);
    }
    
    /**
     * Render all style properties in the editor
     */
    function renderStyleProperties() {
        if (!styleEditorProperties) return;
        styleEditorProperties.innerHTML = '';
        
        // Render existing properties
        for (const [property, value] of Object.entries(currentStyles)) {
            const isModified = originalStyles[property] !== value;
            renderPropertyRow(property, value, isModified, false);
        }
        
        // Render new properties
        for (const prop of newProperties) {
            renderPropertyRow(prop.property, prop.value, false, true);
        }
    }
    
    /**
     * Render a single property row
     */
    function renderPropertyRow(property, value, isModified, isNew) {
        const iframe = getIframe();
        const row = document.createElement('div');
        row.className = 'preview-style-property';
        if (isModified) row.classList.add('preview-style-property--modified');
        if (isNew) row.classList.add('preview-style-property--new');
        row.dataset.property = property;
        
        // Track if original value used var()
        const originalValue = isNew ? '' : (originalStyles[property] || '');
        const originalUsedVar = /var\(([^)]+)\)/.test(originalValue);
        
        // Check if current value uses var()
        const varMatch = value.match(/var\(([^)]+)\)/);
        const usesVar = !!varMatch;
        let resolvedValue = value;
        
        if (usesVar && iframe?.contentDocument) {
            const varName = varMatch[1].split(',')[0].trim();
            try {
                resolvedValue = getComputedStyle(iframe.contentDocument.documentElement)
                    .getPropertyValue(varName).trim() || value;
            } catch (e) {
                resolvedValue = value;
            }
        }
        
        // Detect if this is a color property
        const isColorProperty = colorProperties.some(p => property.includes(p)) || 
                                 isColorValue(resolvedValue);
        
        // Property name (editable for new properties, static for existing)
        if (isNew) {
            const selectorContainer = createPropertySelector(property, (newName) => {
                const currentName = row.dataset.property;
                updatePropertyName(currentName, newName, row);
                updateValueInputForProperty(row, newName, value);
            });
            row.appendChild(selectorContainer);
        } else {
            const nameEl = document.createElement('span');
            nameEl.className = 'preview-style-property__name';
            nameEl.textContent = property;
            nameEl.title = property;
            row.appendChild(nameEl);
        }
        
        // Value container
        const valueContainer = document.createElement('div');
        valueContainer.className = 'preview-style-property__value';
        
        // Color swatch (if color property)
        if (isColorProperty) {
            const extracted = extractColorFromValue(resolvedValue);
            const swatchColor = extracted ? extracted.color : resolvedValue;
            
            const colorSwatch = document.createElement('button');
            colorSwatch.type = 'button';
            colorSwatch.className = 'preview-style-property__color';
            colorSwatch.style.background = swatchColor;
            colorSwatch.title = PreviewConfig.i18n.clickToPickColor;
            colorSwatch.addEventListener('click', () => {
                const currentProp = row.dataset.property;
                openColorPickerForProperty(currentProp, resolvedValue, colorSwatch);
            });
            valueContainer.appendChild(colorSwatch);
        }
        
        // Value input
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'preview-style-property__input';
        input.value = value;
        input.dataset.originalUsedVar = originalUsedVar ? '1' : '0';
        input.addEventListener('input', (e) => {
            const currentProp = row.dataset.property;
            updatePropertyValue(currentProp, e.target.value, isNew);
        });
        input.addEventListener('blur', (e) => {
            const hadVar = e.target.dataset.originalUsedVar === '1';
            const nowHasVar = /var\(([^)]+)\)/.test(e.target.value);
            if (hadVar && !nowHasVar && e.target.value.trim() !== '') {
                showVarReplacementWarning(row.dataset.property, e.target.value);
            }
            applyLivePreview();
        });
        valueContainer.appendChild(input);
        
        // CSS Variables dropdown button
        const varDropdownBtn = document.createElement('button');
        varDropdownBtn.type = 'button';
        varDropdownBtn.className = 'preview-style-property__var-btn';
        varDropdownBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
            <polyline points="6 9 12 15 18 9"/>
        </svg>`;
        varDropdownBtn.title = PreviewConfig.i18n.selectVariable;
        varDropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showVarDropdown(row.dataset.property, input, varDropdownBtn);
        });
        valueContainer.appendChild(varDropdownBtn);
        
        // Variable indicator
        if (usesVar) {
            const varIndicator = document.createElement('span');
            varIndicator.className = 'preview-style-property__var';
            varIndicator.textContent = 'var';
            varIndicator.title = varMatch[1];
            valueContainer.appendChild(varIndicator);
        }
        
        row.appendChild(valueContainer);
        
        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'preview-style-property__delete';
        deleteBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>`;
        deleteBtn.title = PreviewConfig.i18n.delete;
        deleteBtn.addEventListener('click', () => {
            deleteProperty(row.dataset.property, isNew);
        });
        row.appendChild(deleteBtn);
        
        styleEditorProperties.appendChild(row);
    }
    
    /**
     * Update property name (for new properties)
     */
    function updatePropertyName(oldName, newName, row) {
        const prop = newProperties.find(p => p.property === oldName);
        if (prop) {
            prop.property = newName;
            row.dataset.property = newName;
        }
    }
    
    /**
     * Create a property selector using QSPropertySelector class
     */
    function createPropertySelector(currentValue, onSelect) {
        const container = document.createElement('div');
        container.className = 'preview-property-selector';
        
        const existingPropertyNames = [
            ...Object.keys(currentStyles),
            ...newProperties.map(p => p.property)
        ];
        
        if (typeof QSPropertySelector !== 'undefined') {
            const selector = new QSPropertySelector({
                container: container,
                currentValue: currentValue,
                excludeProperties: existingPropertyNames,
                onSelect: onSelect
            });
            container._selector = selector;
        }
        
        return container;
    }
    
    /**
     * Update value input based on selected property type
     */
    function updateValueInputForProperty(row, property, currentValue) {
        const valueContainer = row.querySelector('.preview-style-property__value');
        if (!valueContainer) return;
        
        const propType = getPropertyType(property);
        const isNew = row.classList.contains('preview-style-property--new');
        const varBtn = valueContainer.querySelector('.preview-style-property__var-btn');
        
        while (valueContainer.firstChild) {
            if (valueContainer.firstChild === varBtn) break;
            valueContainer.removeChild(valueContainer.firstChild);
        }
        
        const isColorProperty = colorProperties.some(p => property.includes(p)) || propType.type === 'color';
        
        if (isColorProperty) {
            const colorSwatch = document.createElement('button');
            colorSwatch.type = 'button';
            colorSwatch.className = 'preview-style-property__color';
            colorSwatch.style.background = currentValue || '#ffffff';
            colorSwatch.title = PreviewConfig.i18n.clickToPickColor;
            colorSwatch.addEventListener('click', () => {
                openColorPickerForProperty(row.dataset.property, currentValue, colorSwatch);
            });
            valueContainer.insertBefore(colorSwatch, varBtn);
        }
        
        let inputEl = createSpecializedInput(propType, property, currentValue, (newValue) => {
            updatePropertyValue(row.dataset.property, newValue, isNew);
            
            if (isColorProperty) {
                const swatch = valueContainer.querySelector('.preview-style-property__color');
                if (swatch) swatch.style.background = newValue;
            }
        });
        
        valueContainer.insertBefore(inputEl, varBtn);
        applyLivePreview();
    }
    
    /**
     * Create a specialized input control based on property type
     */
    function createSpecializedInput(propType, property, value, onChange) {
        const container = document.createElement('div');
        
        if (typeof QSValueInput !== 'undefined') {
            const valueInput = new QSValueInput({
                container: container,
                property: property,
                value: value,
                className: 'preview-style-property',
                onChange: onChange,
                onBlur: () => applyLivePreview()
            });
            container._valueInput = valueInput;
        }
        
        return container;
    }
    
    /**
     * Show warning when user replaces var() with fixed value
     */
    function showVarReplacementWarning(property, newValue) {
        showToast(PreviewConfig.i18n.varReplacementWarning, 'warning', 5000);
    }
    
    /**
     * Show dropdown with available CSS variables
     */
    function showVarDropdown(property, input, anchorEl) {
        closeVarDropdown();
        
        const themeVariables = getThemeVariables();
        const allVariables = Object.keys(themeVariables);
        if (allVariables.length === 0) {
            showToast(PreviewConfig.i18n.noVariablesAvailable, 'info');
            return;
        }
        
        // Filter variables based on property type
        const isFontProperty = property.startsWith('font') || property === 'line-height' || property === 'letter-spacing';
        const isColorProperty = colorProperties.some(p => property.includes(p));
        const isSizeProperty = ['width', 'height', 'margin', 'padding', 'gap', 'border-radius', 'top', 'left', 'right', 'bottom'].some(p => property.includes(p));
        
        let variables = allVariables;
        let filterHint = '';
        
        if (isFontProperty) {
            const fontVars = allVariables.filter(v => v.includes('font') || v.includes('text') || v.includes('line') || v.includes('letter'));
            const otherVars = allVariables.filter(v => !fontVars.includes(v));
            variables = [...fontVars, ...otherVars];
            filterHint = fontVars.length > 0 ? ` (${fontVars.length} font vars)` : '';
        } else if (isColorProperty) {
            const colorVars = allVariables.filter(v => v.includes('color') || v.includes('bg') || v.includes('border') || v.includes('text'));
            const otherVars = allVariables.filter(v => !colorVars.includes(v));
            variables = [...colorVars, ...otherVars];
            filterHint = colorVars.length > 0 ? ` (${colorVars.length} color vars)` : '';
        } else if (isSizeProperty) {
            const sizeVars = allVariables.filter(v => v.includes('spacing') || v.includes('gap') || v.includes('size') || v.includes('radius') || v.includes('width') || v.includes('height'));
            const otherVars = allVariables.filter(v => !sizeVars.includes(v));
            variables = [...sizeVars, ...otherVars];
            filterHint = sizeVars.length > 0 ? ` (${sizeVars.length} size vars)` : '';
        }
        
        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'preview-var-dropdown';
        dropdown.id = 'var-dropdown';
        
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'preview-var-dropdown__search';
        searchInput.placeholder = PreviewConfig.i18n.search + filterHint;
        dropdown.appendChild(searchInput);
        
        const list = document.createElement('div');
        list.className = 'preview-var-dropdown__list';
        
        const renderItems = (filter = '') => {
            list.innerHTML = '';
            const filterLower = filter.toLowerCase();
            
            for (const varName of variables) {
                if (filterLower && !varName.toLowerCase().includes(filterLower)) continue;
                
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'preview-var-dropdown__item';
                
                const nameSpan = document.createElement('span');
                nameSpan.className = 'preview-var-dropdown__item-name';
                nameSpan.textContent = varName;
                item.appendChild(nameSpan);
                
                const valueSpan = document.createElement('span');
                valueSpan.className = 'preview-var-dropdown__item-value';
                const varValue = themeVariables[varName] || '';
                valueSpan.textContent = varValue.length > 20 ? varValue.substring(0, 20) + '...' : varValue;
                if (isColorValue(varValue)) {
                    valueSpan.style.setProperty('--preview-color', varValue);
                    valueSpan.classList.add('preview-var-dropdown__item-value--color');
                }
                item.appendChild(valueSpan);
                
                item.addEventListener('click', () => {
                    input.value = `var(${varName})`;
                    const currentProp = input.closest('.preview-style-property')?.dataset.property || property;
                    const isNew = input.closest('.preview-style-property')?.classList.contains('preview-style-property--new');
                    updatePropertyValue(currentProp, input.value, isNew);
                    applyLivePreview();
                    closeVarDropdown();
                });
                
                list.appendChild(item);
            }
            
            if (list.children.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'preview-var-dropdown__empty';
                empty.textContent = PreviewConfig.i18n.noResults;
                list.appendChild(empty);
            }
        };
        
        renderItems();
        dropdown.appendChild(list);
        
        searchInput.addEventListener('input', (e) => {
            renderItems(e.target.value);
        });
        
        const rect = anchorEl.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.top = (rect.bottom + 4) + 'px';
        dropdown.style.left = (rect.left - 200 + rect.width) + 'px';
        
        document.body.appendChild(dropdown);
        searchInput.focus();
        
        setTimeout(() => {
            document.addEventListener('click', closeVarDropdownOnOutsideClick);
        }, 10);
    }
    
    function closeVarDropdown() {
        const dropdown = document.getElementById('var-dropdown');
        if (dropdown) dropdown.remove();
        document.removeEventListener('click', closeVarDropdownOnOutsideClick);
    }
    
    function closeVarDropdownOnOutsideClick(e) {
        const dropdown = document.getElementById('var-dropdown');
        if (dropdown && !dropdown.contains(e.target)) {
            closeVarDropdown();
        }
    }
    
    /**
     * Update a property value
     */
    function updatePropertyValue(property, value, isNew) {
        if (isNew) {
            const prop = newProperties.find(p => p.property === property);
            if (prop) prop.value = value;
        } else {
            currentStyles[property] = value;
        }
        
        const row = styleEditorProperties?.querySelector(`[data-property="${property}"]`);
        if (row && !isNew) {
            row.classList.toggle('preview-style-property--modified', originalStyles[property] !== value);
        }
        
        applyLivePreviewDebounced();
    }
    
    /**
     * Delete a property
     */
    function deleteProperty(property, isNew) {
        if (isNew) {
            newProperties = newProperties.filter(p => p.property !== property);
        } else {
            delete currentStyles[property];
            if (originalStyles.hasOwnProperty(property) && !deletedProperties.includes(property)) {
                deletedProperties.push(property);
            }
        }
        
        const row = styleEditorProperties?.querySelector(`[data-property="${property}"]`);
        if (row) row.remove();
        
        if (Object.keys(currentStyles).length === 0 && newProperties.length === 0) {
            showStyleEditorState('empty');
        }
        
        applyLivePreview();
    }
    
    /**
     * Add a new property
     */
    function addNewProperty() {
        const property = '';
        const uniqueProp = getUniquePropertyName(property);
        newProperties.push({ property: uniqueProp, value: '' });
        
        renderPropertyRow(uniqueProp, '', false, true);
        showStyleEditorState('properties');
        
        const row = styleEditorProperties?.querySelector(`[data-property="${uniqueProp}"]`);
        const nameInput = row?.querySelector('.preview-style-property__name-input');
        if (nameInput) {
            nameInput.focus();
            nameInput.select();
        }
        
        applyLivePreview();
    }
    
    /**
     * Get a unique property name
     */
    function getUniquePropertyName(baseName) {
        let name = baseName;
        let counter = 1;
        while (currentStyles[name] || newProperties.some(p => p.property === name)) {
            name = `${baseName}-${counter++}`;
        }
        return name;
    }
    
    /**
     * Open color picker for a property
     */
    function openColorPickerForProperty(property, currentColor, swatchEl) {
        if (typeof QSColorPicker === 'undefined') return;
        
        const row = swatchEl.closest('.preview-style-property');
        const input = row?.querySelector('.preview-style-property__input');
        if (!input) return;
        
        if (activeColorPicker) {
            activeColorPicker.destroy();
            activeColorPicker = null;
        }
        if (activeColorPickerInput?.parentNode) {
            activeColorPickerInput.remove();
        }
        
        const fullValue = input.value;
        const extracted = extractColorFromValue(fullValue);
        let colorToEdit = currentColor;
        let isCompound = false;
        let prefix = '', suffix = '';
        
        if (extracted && extracted.color !== fullValue) {
            colorToEdit = extracted.color;
            prefix = extracted.prefix;
            suffix = extracted.suffix;
            isCompound = true;
        }
        
        const tempInput = document.createElement('input');
        tempInput.type = 'text';
        tempInput.value = colorToEdit;
        tempInput.style.cssText = 'position:absolute;opacity:0;pointer-events:none;width:1px;height:1px;';
        swatchEl.parentNode.appendChild(tempInput);
        activeColorPickerInput = tempInput;
        
        const themeVariables = getThemeVariables();
        const colorVariables = {};
        for (const [name, value] of Object.entries(themeVariables)) {
            if (name.includes('color') || name.includes('bg') || name.includes('text')) {
                colorVariables[name] = value;
            }
        }
        
        const picker = new QSColorPicker(tempInput, {
            position: 'auto',
            cssVariables: Object.keys(colorVariables).length > 0 ? colorVariables : null,
            onChange: (color) => {
                let swatchColor = color;
                if (color.startsWith('var(')) {
                    const varName = color.match(/var\(([^)]+)\)/)?.[1];
                    if (varName && colorVariables[varName]) {
                        swatchColor = colorVariables[varName];
                    }
                }
                swatchEl.style.background = swatchColor;
                
                let newValue;
                if (isCompound) {
                    newValue = rebuildValueWithColor(prefix, color, suffix);
                } else {
                    newValue = color;
                }
                input.value = newValue;
                updatePropertyValue(property, newValue, row?.classList.contains('preview-style-property--new'));
                applyLivePreview();
            }
        });
        
        activeColorPicker = picker;
        tempInput.click();
    }
    
    function applyLivePreviewDebounced() {
        clearTimeout(livePreviewTimeout);
        livePreviewTimeout = setTimeout(applyLivePreview, 150);
    }
    
    /**
     * Apply live preview styles to the iframe
     */
    function applyLivePreview() {
        const iframe = getIframe();
        if (!iframe?.contentDocument || !editingSelector) return;
        
        try {
            const doc = iframe.contentDocument;
            
            const allStyles = { ...currentStyles };
            for (const prop of newProperties) {
                if (prop.property && prop.value) {
                    allStyles[prop.property] = prop.value;
                }
            }
            
            const cssRules = Object.entries(allStyles)
                .map(([prop, val]) => `${prop}: ${val} !important`);
            
            for (const deletedProp of deletedProperties) {
                cssRules.push(`${deletedProp}: unset !important`);
            }
            
            const cssText = cssRules.join('; ');
            
            let styleEl = doc.getElementById('qs-live-preview-style');
            if (!styleEl) {
                styleEl = doc.createElement('style');
                styleEl.id = 'qs-live-preview-style';
                doc.head.appendChild(styleEl);
                stylePreviewInjected = true;
            }
            
            styleEl.textContent = `${editingSelector} { ${cssText} }`;
            
        } catch (e) {
            console.error('Failed to apply live preview:', e);
        }
    }
    
    /**
     * Remove live preview styles from iframe
     */
    function removeLivePreviewStyles() {
        const iframe = getIframe();
        if (!iframe?.contentDocument) return;
        
        try {
            const styleEl = iframe.contentDocument.getElementById('qs-live-preview-style');
            if (styleEl) {
                styleEl.remove();
                stylePreviewInjected = false;
            }
        } catch (e) {
            console.error('Failed to remove live preview:', e);
        }
    }
    
    /**
     * Reset styles to original values
     */
    function resetStyleEditor() {
        currentStyles = { ...originalStyles };
        newProperties = [];
        deletedProperties = [];
        
        if (Object.keys(originalStyles).length === 0) {
            showStyleEditorState('empty');
        } else {
            renderStyleProperties();
        }
        
        applyLivePreview();
        showToast(PreviewConfig.i18n.stylesReset, 'info');
    }
    
    /**
     * Save styles via API
     */
    async function saveStyleEditor() {
        if (!editingSelector) return;
        
        const allStyles = { ...currentStyles };
        for (const prop of newProperties) {
            if (prop.property && prop.value) {
                allStyles[prop.property] = prop.value;
            }
        }
        
        const stylesString = Object.entries(allStyles)
            .map(([prop, val]) => `${prop}: ${val}`)
            .join(';\n    ');
        
        const requestBody = {
            selector: editingSelector,
            styles: stylesString
        };
        
        if (deletedProperties.length > 0) {
            requestBody.removeProperties = deletedProperties;
        }
        
        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                originalStyles = { ...allStyles };
                currentStyles = { ...allStyles };
                newProperties = [];
                deletedProperties = [];
                
                renderStyleProperties();
                removeLivePreviewStyles();
                
                // Hot-reload CSS without full page reload
                if (window.PreviewState?.hotReloadCss) {
                    PreviewState.hotReloadCss();
                } else {
                    const iframe = getIframe();
                    if (iframe?.contentWindow) {
                        iframe.contentWindow.location.reload();
                    }
                }
                
                showToast(PreviewConfig.i18n.stylesSaved, 'success');
            } else {
                showToast(result.message || PreviewConfig.i18n.saveFailed, 'error');
            }
        } catch (error) {
            console.error('Failed to save styles:', error);
            showToast(PreviewConfig.i18n.saveFailed, 'error');
        }
    }
    
    /**
     * Initialize Style Editor event listeners
     */
    function initStyleEditor() {
        if (styleEditorBack) {
            styleEditorBack.addEventListener('click', closeStyleEditor);
        }
        
        if (styleEditorLabel) {
            styleEditorLabel.style.cursor = 'pointer';
            styleEditorLabel.addEventListener('click', closeStyleEditor);
        }
        
        if (styleEditorAddBtn) {
            styleEditorAddBtn.addEventListener('click', addNewProperty);
        }
        if (styleEditorAddFirst) {
            styleEditorAddFirst.addEventListener('click', addNewProperty);
        }
        
        if (styleEditorReset) {
            styleEditorReset.addEventListener('click', resetStyleEditor);
        }
        
        if (styleEditorSave) {
            styleEditorSave.addEventListener('click', saveStyleEditor);
        }
        
        // Escape key to close style editor
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && styleEditorVisible) {
                const activeEl = document.activeElement;
                const isInInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA');
                if (!isInInput) {
                    e.preventDefault();
                    closeStyleEditor();
                }
            }
        });
    }
    
    // Initialize on load
    initStyleEditor();
    
    // ==================== Public API ====================
    
    window.PreviewStyleEditor = {
        open: openStyleEditor,
        close: closeStyleEditor,
        isVisible: () => styleEditorVisible,
        
        // Callbacks for integration
        setShowToast: (fn) => { showToastFn = fn; },
        setGetIframe: (fn) => { getIframeFn = fn; },
        setGetThemeVariables: (fn) => { getThemeVariablesFn = fn; },
        setGetPropertyTypes: (fn) => { getPropertyTypesFn = fn; },
        setCurrentSelectedSelector: (selector) => { currentSelectedSelector = selector; },
        
        // Constants for external use
        CSS_PROPERTIES_BY_CATEGORY: CSS_PROPERTIES_BY_CATEGORY,
        colorProperties: colorProperties
    };
    
    console.log('[PreviewStyleEditor] Module loaded');
    
})();
