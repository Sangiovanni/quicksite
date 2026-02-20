/**
 * Preview Transition Editor Module
 * 
 * Handles the State & Animation Editor modal for creating CSS transitions
 * and managing base/trigger state styles with animations.
 * 
 * Dependencies:
 * - PreviewConfig (for i18n and URLs)
 * - QSPropertySelector, QSValueInput (property selection components)
 * - QSColorPicker (color picker)
 * 
 * @version 1.0.0
 */
window.PreviewTransitionEditor = (function() {
    'use strict';

    // Callbacks to parent context
    let showToast = () => {};
    let escapeHtml = (str) => String(str);
    let parseStylesString = (str) => ({});
    let refreshPreviewFrame = () => {};
    let openAnimationPreviewModal = null;
    let getKeyframesData = () => [];
    let getThemeVariables = () => ({});

    // API configuration
    let managementUrl = '';
    let authToken = '';

    // Transition Editor state
    let transitionEditorSelector = null;
    let transitionEditorBaseProperties = {};
    let transitionEditorHoverProperties = {};
    let transitionEditorCurrentPseudo = ':hover';
    let transitionEditorCallback = null;
    let transitionEditorBaseAnimation = null;
    let transitionEditorTriggerAnimation = null;

    // DOM references
    let transitionEditorModal = null;
    let transitionEditorSelectorEl = null;
    let transitionBaseSelectorEl = null;
    let transitionBasePropsEl = null;
    let transitionHoverPropsEl = null;
    let transitionPseudoSelect = null;
    let transitionPropertySelect = null;
    let transitionSpecificProps = null;
    let transitionDuration = null;
    let transitionDurationUnit = null;
    let transitionDelay = null;
    let transitionDelayUnit = null;
    let transitionTimingSelect = null;
    let transitionCubicBezier = null;
    let transitionPreviewCode = null;
    let transitionTriggerLabel = null;
    let transitionAddHoverText = null;

    // Inline property editor DOM references
    let transitionBaseAddToggle = null;
    let transitionBaseAddForm = null;
    let transitionBasePropSelectorContainer = null;
    let transitionBaseValueContainer = null;
    let transitionBaseAddConfirm = null;
    let transitionBaseAddCancel = null;
    let transitionTriggerAddToggle = null;
    let transitionTriggerAddForm = null;
    let transitionTriggerPropSelectorContainer = null;
    let transitionTriggerValueContainer = null;
    let transitionTriggerAddConfirm = null;
    let transitionTriggerAddCancel = null;

    // QSPropertySelector and QSValueInput instances
    let transitionBasePropSelector = null;
    let transitionTriggerPropSelector = null;
    let transitionBaseValueInput = null;
    let transitionTriggerValueInput = null;

    // Animation section DOM references
    let transitionBaseAnimEmpty = null;
    let transitionBaseAnimConfig = null;
    let transitionBaseAnimName = null;
    let transitionBaseAnimDuration = null;
    let transitionBaseAnimIterations = null;
    let transitionBaseAnimInfinite = null;
    let transitionBaseAnimAddBtn = null;
    let transitionBaseAnimPreviewBtn = null;
    let transitionBaseAnimRemoveBtn = null;
    let transitionTriggerAnimEmpty = null;
    let transitionTriggerAnimConfig = null;
    let transitionTriggerAnimName = null;
    let transitionTriggerAnimDuration = null;
    let transitionTriggerAnimIterations = null;
    let transitionTriggerAnimInfinite = null;
    let transitionTriggerAnimAddBtn = null;
    let transitionTriggerAnimPreviewBtn = null;
    let transitionTriggerAnimRemoveBtn = null;
    let transitionTriggerAnimHint = null;

    // Track active transition color picker for cleanup
    let activeTransitionColorPicker = null;
    let activeTransitionColorPickerInput = null;

    /**
     * Initialize DOM references
     */
    function initDOMReferences() {
        transitionEditorModal = document.getElementById('transition-editor-modal');
        transitionEditorSelectorEl = document.getElementById('transition-editor-selector');
        transitionBaseSelectorEl = document.getElementById('transition-base-selector');
        transitionBasePropsEl = document.getElementById('transition-base-properties');
        transitionHoverPropsEl = document.getElementById('transition-hover-properties');
        transitionPseudoSelect = document.getElementById('transition-pseudo-select');
        transitionPropertySelect = document.getElementById('transition-property-select');
        transitionSpecificProps = document.getElementById('transition-specific-props');
        transitionDuration = document.getElementById('transition-duration');
        transitionDurationUnit = document.getElementById('transition-duration-unit');
        transitionDelay = document.getElementById('transition-delay');
        transitionDelayUnit = document.getElementById('transition-delay-unit');
        transitionTimingSelect = document.getElementById('transition-timing-select');
        transitionCubicBezier = document.getElementById('transition-cubic-bezier');
        transitionPreviewCode = document.getElementById('transition-preview-code');
        transitionTriggerLabel = document.getElementById('transition-trigger-label');
        transitionAddHoverText = document.getElementById('transition-add-hover-text');

        // Inline property editor
        transitionBaseAddToggle = document.getElementById('transition-base-add-toggle');
        transitionBaseAddForm = document.getElementById('transition-base-add-form');
        transitionBasePropSelectorContainer = document.getElementById('transition-base-prop-selector');
        transitionBaseValueContainer = document.getElementById('transition-base-value-container');
        transitionBaseAddConfirm = document.getElementById('transition-base-add-confirm');
        transitionBaseAddCancel = document.getElementById('transition-base-add-cancel');
        transitionTriggerAddToggle = document.getElementById('transition-trigger-add-toggle');
        transitionTriggerAddForm = document.getElementById('transition-trigger-add-form');
        transitionTriggerPropSelectorContainer = document.getElementById('transition-trigger-prop-selector');
        transitionTriggerValueContainer = document.getElementById('transition-trigger-value-container');
        transitionTriggerAddConfirm = document.getElementById('transition-trigger-add-confirm');
        transitionTriggerAddCancel = document.getElementById('transition-trigger-add-cancel');

        // Animation section
        transitionBaseAnimEmpty = document.getElementById('transition-base-animation-empty');
        transitionBaseAnimConfig = document.getElementById('transition-base-animation-config');
        transitionBaseAnimName = document.getElementById('transition-base-animation-name');
        transitionBaseAnimDuration = document.getElementById('transition-base-anim-duration');
        transitionBaseAnimIterations = document.getElementById('transition-base-anim-iterations');
        transitionBaseAnimInfinite = document.getElementById('transition-base-anim-infinite');
        transitionBaseAnimAddBtn = document.getElementById('transition-base-animation-add');
        transitionBaseAnimPreviewBtn = document.getElementById('transition-base-animation-preview-btn');
        transitionBaseAnimRemoveBtn = document.getElementById('transition-base-animation-remove');
        transitionTriggerAnimEmpty = document.getElementById('transition-trigger-animation-empty');
        transitionTriggerAnimConfig = document.getElementById('transition-trigger-animation-config');
        transitionTriggerAnimName = document.getElementById('transition-trigger-animation-name');
        transitionTriggerAnimDuration = document.getElementById('transition-trigger-anim-duration');
        transitionTriggerAnimIterations = document.getElementById('transition-trigger-anim-iterations');
        transitionTriggerAnimInfinite = document.getElementById('transition-trigger-anim-infinite');
        transitionTriggerAnimAddBtn = document.getElementById('transition-trigger-animation-add');
        transitionTriggerAnimPreviewBtn = document.getElementById('transition-trigger-animation-preview-btn');
        transitionTriggerAnimRemoveBtn = document.getElementById('transition-trigger-animation-remove');
        transitionTriggerAnimHint = document.getElementById('transition-trigger-animation-hint');
    }

    /**
     * Open the Transition Editor for a selector
     * @param {string} selector - Base CSS selector (without pseudo-class)
     * @param {function} onSave - Callback when transition is saved
     */
    async function open(selector, onSave = null) {
        transitionEditorSelector = selector;
        transitionEditorCallback = onSave;

        // Update header
        if (transitionEditorSelectorEl) transitionEditorSelectorEl.textContent = selector;
        if (transitionBaseSelectorEl) transitionBaseSelectorEl.textContent = selector;

        // Reset state
        transitionEditorBaseProperties = {};
        transitionEditorHoverProperties = {};
        transitionEditorCurrentPseudo = transitionPseudoSelect?.value || ':hover';

        // Reset controls to defaults
        if (transitionDuration) transitionDuration.value = 0.3;
        if (transitionDelay) transitionDelay.value = 0;
        if (transitionTimingSelect) transitionTimingSelect.value = 'ease';
        if (transitionPropertySelect) transitionPropertySelect.value = 'all';
        if (transitionCubicBezier) transitionCubicBezier.style.display = 'none';
        if (transitionSpecificProps) transitionSpecificProps.style.display = 'none';

        // Update labels to match current pseudo-class
        updateTriggerStateLabels();

        // Show modal
        transitionEditorModal?.classList.add('preview-keyframe-modal--visible');

        // Load selector states
        await loadTransitionSelectorStates(selector);

        // Update preview
        updateTransitionPreviewCode();
    }

    /**
     * Close the Transition Editor
     */
    function close() {
        transitionEditorModal?.classList.remove('preview-keyframe-modal--visible');
        transitionEditorSelector = null;
        transitionEditorCallback = null;
    }

    /**
     * Load base and pseudo-state styles for the selector
     */
    async function loadTransitionSelectorStates(selector) {
        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';
        
        const pseudoClass = transitionEditorCurrentPseudo;
        const hoverSelector = selector + pseudoClass;

        try {
            const [baseResponse, hoverResponse] = await Promise.all([
                fetch(managementUrl + 'getStyleRule/' + encodeURIComponent(selector), {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                }),
                fetch(managementUrl + 'getStyleRule/' + encodeURIComponent(hoverSelector), {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                })
            ]);

            const baseData = await baseResponse.json();
            const hoverData = await hoverResponse.json();

            // Extract properties
            transitionEditorBaseProperties = {};
            transitionEditorHoverProperties = {};
            transitionEditorBaseAnimation = null;
            transitionEditorTriggerAnimation = null;

            if (baseData.status === 200 && baseData.data?.styles) {
                transitionEditorBaseProperties = parseStylesString(baseData.data.styles);

                if (transitionEditorBaseProperties.transition) {
                    parseExistingTransition(transitionEditorBaseProperties.transition);
                }

                if (transitionEditorBaseProperties.animation) {
                    transitionEditorBaseAnimation = parseAnimationValue(transitionEditorBaseProperties.animation);
                }
            }

            if (hoverData.status === 200 && hoverData.data?.styles) {
                transitionEditorHoverProperties = parseStylesString(hoverData.data.styles);

                if (transitionEditorHoverProperties.animation) {
                    transitionEditorTriggerAnimation = parseAnimationValue(transitionEditorHoverProperties.animation);
                }
            }

            // Render property panels
            renderTransitionBaseProperties();
            renderTransitionHoverProperties();

            // Render animation sections
            renderAnimationSection('base');
            renderAnimationSection('trigger');

            // Re-initialize property selectors
            initTransitionPropertySelectors();

        } catch (error) {
            console.error('Failed to load selector states:', error);
            showToast(PreviewConfig.i18n.loadTransitionFailed || 'Failed to load transition data', 'error');
        }
    }

    /**
     * Parse animation CSS value into structured object
     */
    function parseAnimationValue(animationValue) {
        if (!animationValue || animationValue === 'none') return null;

        const parts = animationValue.trim().split(/\s+/);

        let name = null;
        let duration = 1000;
        let iterations = 1;
        let infinite = false;

        for (const part of parts) {
            if (part === 'infinite') {
                infinite = true;
            } else if (/^\d+$/.test(part)) {
                iterations = parseInt(part);
            } else if (/^\d+(\.\d+)?(ms|s)$/.test(part)) {
                duration = part.endsWith('ms') ? parseFloat(part) : parseFloat(part) * 1000;
            } else if (!['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'forwards', 'backwards', 'both', 'none', 'normal', 'reverse', 'alternate', 'alternate-reverse', 'running', 'paused'].includes(part) && !/^cubic-bezier/.test(part)) {
                name = part;
            }
        }

        if (!name && transitionEditorBaseProperties['animation-name']) {
            name = transitionEditorBaseProperties['animation-name'];
        }

        return name ? { name, duration, iterations, infinite } : null;
    }

    /**
     * Render animation section (base or trigger)
     */
    function renderAnimationSection(target) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        const emptyEl = target === 'base' ? transitionBaseAnimEmpty : transitionTriggerAnimEmpty;
        const configEl = target === 'base' ? transitionBaseAnimConfig : transitionTriggerAnimConfig;
        const nameEl = target === 'base' ? transitionBaseAnimName : transitionTriggerAnimName;
        const durationEl = target === 'base' ? transitionBaseAnimDuration : transitionTriggerAnimDuration;
        const iterationsEl = target === 'base' ? transitionBaseAnimIterations : transitionTriggerAnimIterations;
        const infiniteEl = target === 'base' ? transitionBaseAnimInfinite : transitionTriggerAnimInfinite;
        const addBtn = target === 'base' ? transitionBaseAnimAddBtn : transitionTriggerAnimAddBtn;

        if (!animation) {
            if (emptyEl) emptyEl.style.display = '';
            if (configEl) configEl.style.display = 'none';
            if (addBtn) addBtn.style.display = '';
        } else {
            if (emptyEl) emptyEl.style.display = 'none';
            if (configEl) configEl.style.display = '';
            if (addBtn) addBtn.style.display = 'none';

            if (nameEl) nameEl.textContent = `@keyframes ${animation.name}`;
            if (durationEl) durationEl.value = animation.duration;
            if (iterationsEl) iterationsEl.value = animation.iterations;
            if (infiniteEl) infiniteEl.checked = animation.infinite;
        }

        if (target === 'trigger' && transitionTriggerAnimHint) {
            const pseudo = transitionEditorCurrentPseudo || ':hover';
            transitionTriggerAnimHint.textContent = `(${PreviewConfig.i18n.playsOn || 'plays on'} ${pseudo})`;
        }
    }

    /**
     * Parse existing transition value and populate controls
     */
    function parseExistingTransition(transitionValue) {
        const parts = transitionValue.trim().split(/\s+/);

        if (parts.length >= 1) {
            const prop = parts[0];
            if (transitionPropertySelect) {
                if (prop === 'all') {
                    transitionPropertySelect.value = 'all';
                } else {
                    transitionPropertySelect.value = 'all';
                }
            }
        }

        if (parts.length >= 2) {
            const dur = parseFloat(parts[1]);
            if (!isNaN(dur) && transitionDuration) {
                transitionDuration.value = dur;
            }
        }

        if (parts.length >= 3) {
            const timing = parts[2];
            if (transitionTimingSelect) {
                const validTimings = ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out'];
                if (validTimings.includes(timing)) {
                    transitionTimingSelect.value = timing;
                } else if (timing.startsWith('cubic-bezier')) {
                    transitionTimingSelect.value = 'cubic-bezier';
                    const match = timing.match(/cubic-bezier\(([^)]+)\)/);
                    if (match) {
                        const vals = match[1].split(',').map(v => parseFloat(v.trim()));
                        if (vals.length === 4) {
                            document.getElementById('transition-bezier-x1').value = vals[0];
                            document.getElementById('transition-bezier-y1').value = vals[1];
                            document.getElementById('transition-bezier-x2').value = vals[2];
                            document.getElementById('transition-bezier-y2').value = vals[3];
                        }
                    }
                    if (transitionCubicBezier) transitionCubicBezier.style.display = '';
                }
            }
        }

        if (parts.length >= 4) {
            const delay = parseFloat(parts[3]);
            if (!isNaN(delay) && transitionDelay) {
                transitionDelay.value = delay;
            }
        }
    }

    /**
     * Render base state properties panel
     */
    function renderTransitionBaseProperties() {
        if (!transitionBasePropsEl) return;

        const props = Object.entries(transitionEditorBaseProperties)
            .filter(([key]) => key !== 'transition' && key !== 'animation');

        if (props.length === 0) {
            transitionBasePropsEl.innerHTML = `
                <div class="transition-editor__empty">${PreviewConfig.i18n.noBaseStyles || 'No base styles defined'}</div>
            `;
            return;
        }

        transitionBasePropsEl.innerHTML = props.map(([prop, value]) => `
            <div class="transition-editor__property" data-property="${escapeHtml(prop)}" data-target="base">
                <div class="transition-editor__property-info">
                    <span class="transition-editor__property-name">${escapeHtml(prop)}</span>
                    <span class="transition-editor__property-value" title="${escapeHtml(value)}">${escapeHtml(value)}</span>
                </div>
                <div class="transition-editor__property-actions">
                    <button type="button" class="transition-editor__property-btn transition-editor__property-btn--delete" 
                            data-action="delete" title="${PreviewConfig.i18n.delete || 'Delete'}">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');

        // Add event listeners for delete buttons
        transitionBasePropsEl.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const propEl = e.target.closest('.transition-editor__property');
                const prop = propEl?.dataset.property;
                if (prop) deleteTransitionProperty('base', prop);
            });
        });
    }

    /**
     * Render trigger state properties panel
     */
    function renderTransitionHoverProperties() {
        if (!transitionHoverPropsEl) return;

        const props = Object.entries(transitionEditorHoverProperties);

        if (props.length === 0) {
            transitionHoverPropsEl.innerHTML = `
                <div class="transition-editor__empty">${PreviewConfig.i18n.noTriggerStyles || 'No trigger state styles'}</div>
            `;
            return;
        }

        transitionHoverPropsEl.innerHTML = props.map(([prop, value]) => `
            <div class="transition-editor__property" data-property="${escapeHtml(prop)}" data-target="trigger">
                <div class="transition-editor__property-info">
                    <span class="transition-editor__property-name">${escapeHtml(prop)}</span>
                    <span class="transition-editor__property-value" title="${escapeHtml(value)}">${escapeHtml(value)}</span>
                </div>
                <div class="transition-editor__property-actions">
                    <button type="button" class="transition-editor__property-btn transition-editor__property-btn--delete" 
                            data-action="delete" title="${PreviewConfig.i18n.delete || 'Delete'}">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');

        // Add event listeners for delete buttons
        transitionHoverPropsEl.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const propEl = e.target.closest('.transition-editor__property');
                const prop = propEl?.dataset.property;
                if (prop) deleteTransitionProperty('trigger', prop);
            });
        });

        updateSpecificPropertiesCheckboxes();
    }

    /**
     * Delete a property from base or trigger state
     */
    async function deleteTransitionProperty(target, property) {
        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';
        
        const selector = target === 'base'
            ? transitionEditorSelector
            : transitionEditorSelector + transitionEditorCurrentPseudo;

        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: '',
                    removeProperties: [property]
                })
            });

            const result = await response.json();

            if (result.status === 200) {
                showToast(PreviewConfig.i18n.propertyDeleted || 'Property deleted', 'success');
                await loadTransitionSelectorStates(transitionEditorSelector);
            } else {
                throw new Error(result.message || 'Failed to delete property');
            }
        } catch (error) {
            console.error('Failed to delete property:', error);
            showToast(PreviewConfig.i18n.deletePropertyFailed || 'Failed to delete property', 'error');
        }
    }

    /**
     * Add a property to base or trigger state
     */
    async function addTransitionProperty(target, property, value) {
        if (!property || !value) return;

        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';

        const selector = target === 'base'
            ? transitionEditorSelector
            : transitionEditorSelector + transitionEditorCurrentPseudo;

        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: `${property}: ${value};`
                })
            });

            const result = await response.json();

            if (result.status === 200) {
                showToast(PreviewConfig.i18n.propertyAdded || 'Property added', 'success');
                await loadTransitionSelectorStates(transitionEditorSelector);
                refreshPreviewFrame();
            } else {
                throw new Error(result.message || 'Failed to add property');
            }
        } catch (error) {
            console.error('Failed to add property:', error);
            showToast(PreviewConfig.i18n.addPropertyFailed || 'Failed to add property', 'error');
        }
    }

    /**
     * Initialize property selector dropdowns using QSPropertySelector class
     */
    function initTransitionPropertySelectors() {
        const baseExclude = Object.keys(transitionEditorBaseProperties);
        const triggerExclude = Object.keys(transitionEditorHoverProperties);

        const handleColorPick = (property, value, swatchEl) => {
            openColorPickerForTransition(property, value, swatchEl);
        };

        // Initialize base property selector
        if (transitionBasePropSelectorContainer && typeof QSPropertySelector !== 'undefined') {
            if (transitionBasePropSelector) {
                transitionBasePropSelector.destroy();
            }
            transitionBasePropSelector = new QSPropertySelector({
                container: transitionBasePropSelectorContainer,
                excludeProperties: baseExclude,
                onSelect: (property) => {
                    if (transitionBaseValueContainer && typeof QSValueInput !== 'undefined') {
                        if (transitionBaseValueInput) {
                            transitionBaseValueInput.destroy();
                        }
                        transitionBaseValueInput = new QSValueInput({
                            container: transitionBaseValueContainer,
                            property: property,
                            value: '',
                            onChange: () => {},
                            onBlur: () => {},
                            onColorPick: handleColorPick
                        });
                        transitionBaseValueInput.focus();
                    }
                }
            });
        }

        // Initialize base value input
        if (transitionBaseValueContainer && typeof QSValueInput !== 'undefined') {
            if (transitionBaseValueInput) {
                transitionBaseValueInput.destroy();
            }
            transitionBaseValueInput = new QSValueInput({
                container: transitionBaseValueContainer,
                property: '',
                value: '',
                onChange: () => {},
                onBlur: () => {},
                onColorPick: handleColorPick
            });
        }

        // Initialize trigger property selector
        if (transitionTriggerPropSelectorContainer && typeof QSPropertySelector !== 'undefined') {
            if (transitionTriggerPropSelector) {
                transitionTriggerPropSelector.destroy();
            }
            transitionTriggerPropSelector = new QSPropertySelector({
                container: transitionTriggerPropSelectorContainer,
                excludeProperties: triggerExclude,
                onSelect: (property) => {
                    if (transitionTriggerValueContainer && typeof QSValueInput !== 'undefined') {
                        if (transitionTriggerValueInput) {
                            transitionTriggerValueInput.destroy();
                        }
                        transitionTriggerValueInput = new QSValueInput({
                            container: transitionTriggerValueContainer,
                            property: property,
                            value: '',
                            onChange: () => {},
                            onBlur: () => {},
                            onColorPick: handleColorPick
                        });
                        transitionTriggerValueInput.focus();
                    }
                }
            });
        }

        // Initialize trigger value input
        if (transitionTriggerValueContainer && typeof QSValueInput !== 'undefined') {
            if (transitionTriggerValueInput) {
                transitionTriggerValueInput.destroy();
            }
            transitionTriggerValueInput = new QSValueInput({
                container: transitionTriggerValueContainer,
                property: '',
                value: '',
                onChange: () => {},
                onBlur: () => {},
                onColorPick: handleColorPick
            });
        }
    }

    /**
     * Open color picker for State & Animation Editor
     */
    function openColorPickerForTransition(property, currentColor, swatchEl) {
        if (typeof QSColorPicker === 'undefined') {
            console.warn('QSColorPicker not available');
            return;
        }

        if (activeTransitionColorPicker) {
            activeTransitionColorPicker.destroy();
            activeTransitionColorPicker = null;
        }
        if (activeTransitionColorPickerInput?.parentNode) {
            activeTransitionColorPickerInput.remove();
        }

        const tempInput = document.createElement('input');
        tempInput.type = 'text';
        tempInput.value = currentColor || '';
        tempInput.style.cssText = 'position:absolute;opacity:0;pointer-events:none;width:1px;height:1px;';
        swatchEl.parentNode.appendChild(tempInput);
        activeTransitionColorPickerInput = tempInput;

        const colorVariables = {};
        const themeVars = getThemeVariables();
        if (typeof themeVars === 'object' && themeVars) {
            for (const [name, value] of Object.entries(themeVars)) {
                if (name.includes('color') || name.includes('bg') || name.includes('text')) {
                    colorVariables[name] = value;
                }
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

                if (swatchEl) swatchEl.style.background = swatchColor;

                if (transitionBaseAddForm?.style.display !== 'none' && transitionBaseValueInput) {
                    transitionBaseValueInput.setValue(color);
                } else if (transitionTriggerAddForm?.style.display !== 'none' && transitionTriggerValueInput) {
                    transitionTriggerValueInput.setValue(color);
                }
            }
        });

        activeTransitionColorPicker = picker;
        tempInput.click();
    }

    /**
     * Toggle inline add property form
     */
    function toggleAddPropertyForm(target, show) {
        const toggle = target === 'base' ? transitionBaseAddToggle : transitionTriggerAddToggle;
        const form = target === 'base' ? transitionBaseAddForm : transitionTriggerAddForm;
        const propSelector = target === 'base' ? transitionBasePropSelector : transitionTriggerPropSelector;
        const valueInput = target === 'base' ? transitionBaseValueInput : transitionTriggerValueInput;
        const valueContainer = target === 'base' ? transitionBaseValueContainer : transitionTriggerValueContainer;

        if (toggle) toggle.style.display = show ? 'none' : '';
        if (form) form.style.display = show ? '' : 'none';

        if (show) {
            if (propSelector) propSelector.setValue('');
            if (valueInput && valueContainer && typeof QSValueInput !== 'undefined') {
                valueInput.destroy();
                const newInput = new QSValueInput({
                    container: valueContainer,
                    property: '',
                    value: '',
                    onChange: () => {},
                    onBlur: () => {},
                    onColorPick: (property, value, swatchEl) => openColorPickerForTransition(property, value, swatchEl)
                });
                if (target === 'base') {
                    transitionBaseValueInput = newInput;
                } else {
                    transitionTriggerValueInput = newInput;
                }
            }
        }
    }

    /**
     * Open animation picker dropdown
     */
    function openAnimationPicker(target) {
        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';
        
        const keyframesData = getKeyframesData();

        if (!keyframesData || keyframesData.length === 0) {
            fetch(managementUrl + 'getKeyframes', {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 200 && data.data) {
                    // API returns { keyframes: { name: frames, ... }, count }
                    // Convert to array of { name } for the picker
                    const kfObj = data.data.keyframes || data.data;
                    let kfList;
                    if (Array.isArray(kfObj)) {
                        kfList = kfObj;
                    } else {
                        kfList = Object.keys(kfObj).map(name => ({ name }));
                    }
                    if (kfList.length === 0) {
                        showToast(PreviewConfig.i18n.noKeyframesFound || 'No keyframes found', 'warning');
                    } else {
                        showAnimationPickerDropdown(target, kfList);
                    }
                } else {
                    showToast(PreviewConfig.i18n.noKeyframesFound || 'No keyframes found', 'warning');
                }
            })
            .catch((err) => {
                console.error('[QuickSite] Error loading keyframes:', err);
                showToast(PreviewConfig.i18n.errorLoadingKeyframes || 'Error loading keyframes', 'error');
            });
        } else {
            showAnimationPickerDropdown(target, keyframesData);
        }
    }

    /**
     * Show animation picker dropdown
     */
    function showAnimationPickerDropdown(target, keyframesData) {
        if (!keyframesData || keyframesData.length === 0) {
            showToast(PreviewConfig.i18n.noKeyframesFound || 'No keyframes found', 'warning');
            return;
        }

        const existingDropdown = document.querySelector('.animation-picker-dropdown');
        if (existingDropdown) existingDropdown.remove();

        const addBtn = target === 'base' ? transitionBaseAnimAddBtn : transitionTriggerAnimAddBtn;
        if (!addBtn) return;

        const rect = addBtn.getBoundingClientRect();

        const dropdown = document.createElement('div');
        dropdown.className = 'animation-picker-dropdown';
        dropdown.style.cssText = `
            position: fixed;
            left: ${rect.left}px;
            top: ${rect.bottom + 4}px;
            background: var(--qsb-bg-secondary, #2d2d2d);
            border: 1px solid var(--qsb-border, #404040);
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 100002;
            max-height: 200px;
            overflow-y: auto;
            min-width: 180px;
        `;

        dropdown.innerHTML = keyframesData.map(kf => `
            <div class="animation-picker-dropdown__item" data-name="${escapeHtml(kf.name)}" style="
                padding: 8px 12px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
                border-bottom: 1px solid var(--qsb-border, #404040);
            ">
                <span style="color: var(--qsb-accent, #4fc3f7);">@keyframes</span>
                <span style="color: var(--qsb-text, #fff);">${escapeHtml(kf.name)}</span>
            </div>
        `).join('');

        dropdown.querySelectorAll('.animation-picker-dropdown__item').forEach(item => {
            item.addEventListener('click', () => {
                const name = item.dataset.name;
                addAnimationToSelector(target, name);
                dropdown.remove();
            });
            item.addEventListener('mouseenter', () => {
                item.style.background = 'var(--qsb-bg-hover, #3a3a3a)';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = '';
            });
        });

        const closeDropdown = (e) => {
            if (!dropdown.contains(e.target) && e.target !== addBtn) {
                dropdown.remove();
                document.removeEventListener('click', closeDropdown);
            }
        };
        setTimeout(() => document.addEventListener('click', closeDropdown), 10);

        document.body.appendChild(dropdown);
    }

    /**
     * Add animation to selector
     */
    async function addAnimationToSelector(target, keyframeName) {
        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';
        
        const selector = target === 'base' ? transitionEditorSelector :
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;

        const animationValue = `${keyframeName} 1000ms ease forwards`;

        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: `animation: ${animationValue};`
                })
            });

            const result = await response.json();
            if (result.status === 200) {
                showToast(PreviewConfig.i18n.animationAdded || 'Animation added', 'success');

                if (target === 'base') {
                    transitionEditorBaseAnimation = { name: keyframeName, duration: 1000, iterations: 1, infinite: false };
                    transitionEditorBaseProperties.animation = animationValue;
                } else {
                    transitionEditorTriggerAnimation = { name: keyframeName, duration: 1000, iterations: 1, infinite: false };
                    transitionEditorHoverProperties.animation = animationValue;
                }

                renderAnimationSection(target);
                refreshPreviewFrame();
            } else {
                showToast(result.message || PreviewConfig.i18n.errorAddingAnimation || 'Error adding animation', 'error');
            }
        } catch (error) {
            console.error('Failed to add animation:', error);
            showToast(PreviewConfig.i18n.errorAddingAnimation || 'Error adding animation', 'error');
        }
    }

    /**
     * Remove animation from selector
     */
    async function removeAnimation(target) {
        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';
        
        const selector = target === 'base' ? transitionEditorSelector :
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;

        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: '',
                    removeProperties: ['animation']
                })
            });

            const result = await response.json();
            if (result.status === 200) {
                showToast(PreviewConfig.i18n.animationRemoved || 'Animation removed', 'success');

                if (target === 'base') {
                    transitionEditorBaseAnimation = null;
                    delete transitionEditorBaseProperties.animation;
                } else {
                    transitionEditorTriggerAnimation = null;
                    delete transitionEditorHoverProperties.animation;
                }

                renderAnimationSection(target);
                refreshPreviewFrame();
            } else {
                showToast(result.message || PreviewConfig.i18n.errorRemovingAnimation || 'Error removing animation', 'error');
            }
        } catch (error) {
            console.error('Failed to remove animation:', error);
            showToast(PreviewConfig.i18n.errorRemovingAnimation || 'Error removing animation', 'error');
        }
    }

    /**
     * Preview animation using the Animation Preview Modal
     */
    function previewAnimation(target) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        if (!animation || !animation.name) {
            showToast(PreviewConfig.i18n.noAnimationToPreview || 'No animation to preview', 'warning');
            return;
        }

        const keyframesData = getKeyframesData();
        const keyframe = keyframesData?.find(kf => kf.name === animation.name);
        if (!keyframe) {
            showToast(PreviewConfig.i18n.keyframeNotFound || 'Keyframe not found', 'error');
            return;
        }

        if (typeof openAnimationPreviewModal === 'function') {
            openAnimationPreviewModal(keyframe);
        } else {
            showToast(PreviewConfig.i18n.previewNotAvailable || 'Preview not available', 'warning');
        }
    }

    /**
     * Update animation setting
     */
    async function updateAnimationSetting(target, setting) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        if (!animation || !animation.name) return;

        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';

        const selector = target === 'base' ? transitionEditorSelector :
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;

        const durationEl = target === 'base' ? transitionBaseAnimDuration : transitionTriggerAnimDuration;
        const iterationsEl = target === 'base' ? transitionBaseAnimIterations : transitionTriggerAnimIterations;
        const infiniteEl = target === 'base' ? transitionBaseAnimInfinite : transitionTriggerAnimInfinite;

        const duration = durationEl?.value || animation.duration;
        const iterations = infiniteEl?.checked ? 'infinite' : (iterationsEl?.value || animation.iterations);
        const infinite = infiniteEl?.checked || false;

        const animationValue = `${animation.name} ${duration}ms ease ${iterations === 'infinite' ? 'infinite' : ''} forwards`.replace(/\s+/g, ' ').trim();

        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: selector,
                    styles: `animation: ${animationValue};`
                })
            });

            const result = await response.json();
            if (result.status === 200) {
                if (target === 'base') {
                    transitionEditorBaseAnimation = { ...animation, duration: parseInt(duration), iterations: infinite ? 'infinite' : parseInt(iterationsEl?.value || 1), infinite };
                    transitionEditorBaseProperties.animation = animationValue;
                } else {
                    transitionEditorTriggerAnimation = { ...animation, duration: parseInt(duration), iterations: infinite ? 'infinite' : parseInt(iterationsEl?.value || 1), infinite };
                    transitionEditorHoverProperties.animation = animationValue;
                }

                refreshPreviewFrame();
            }
        } catch (error) {
            console.error('Failed to update animation:', error);
        }
    }

    /**
     * Update specific properties checkboxes
     */
    function updateSpecificPropertiesCheckboxes() {
        if (!transitionSpecificProps) return;

        const allProps = new Set([
            ...Object.keys(transitionEditorBaseProperties),
            ...Object.keys(transitionEditorHoverProperties)
        ]);

        const propsToShow = [...allProps].filter(p =>
            p !== 'transition' && p !== 'animation'
        );

        if (propsToShow.length === 0) {
            transitionSpecificProps.innerHTML = `
                <div class="transition-editor__empty">${PreviewConfig.i18n.noPropertiesToTransition || 'No properties to transition'}</div>
            `;
            return;
        }

        transitionSpecificProps.innerHTML = propsToShow.map(prop => `
            <label class="transition-editor__checkbox-label">
                <input type="checkbox" class="transition-editor__prop-checkbox" value="${escapeHtml(prop)}" checked>
                ${escapeHtml(prop)}
            </label>
        `).join('');

        transitionSpecificProps.querySelectorAll('.transition-editor__prop-checkbox').forEach(cb => {
            cb.addEventListener('change', updateTransitionPreviewCode);
        });
    }

    /**
     * Build transition value string
     */
    function buildTransitionValue() {
        const property = transitionPropertySelect?.value || 'all';
        const durationVal = transitionDuration?.value || 0.3;
        const durationUnit = transitionDurationUnit?.value || 's';
        const duration = durationVal + durationUnit;
        const delayVal = parseFloat(transitionDelay?.value || 0);
        const delayUnit = transitionDelayUnit?.value || 's';
        const delay = delayVal > 0 ? delayVal + delayUnit : null;
        const timing = transitionTimingSelect?.value || 'ease';

        let timingValue = timing;
        if (timing === 'cubic-bezier') {
            const x1 = document.getElementById('transition-bezier-x1')?.value || 0.4;
            const y1 = document.getElementById('transition-bezier-y1')?.value || 0;
            const x2 = document.getElementById('transition-bezier-x2')?.value || 0.2;
            const y2 = document.getElementById('transition-bezier-y2')?.value || 1;
            timingValue = `cubic-bezier(${x1}, ${y1}, ${x2}, ${y2})`;
        }

        if (property === 'specific') {
            const selectedProps = [];
            transitionSpecificProps?.querySelectorAll('.transition-editor__prop-checkbox:checked').forEach(cb => {
                selectedProps.push(cb.value);
            });

            if (selectedProps.length === 0) {
                return 'none';
            }

            return selectedProps.map(prop =>
                `${prop} ${duration} ${timingValue}${delay ? ' ' + delay : ''}`
            ).join(', ');
        }

        let value = `all ${duration} ${timingValue}`;
        if (delay) {
            value += ` ${delay}`;
        }
        return value;
    }

    /**
     * Update the transition preview code display
     */
    function updateTransitionPreviewCode() {
        if (!transitionPreviewCode) return;
        transitionPreviewCode.textContent = buildTransitionValue();
    }

    /**
     * Update trigger state labels
     */
    function updateTriggerStateLabels() {
        const pseudo = transitionEditorCurrentPseudo || ':hover';
        const pseudoName = pseudo.replace(':', '');
        const pseudoCapitalized = pseudoName.charAt(0).toUpperCase() + pseudoName.slice(1);

        if (transitionTriggerLabel) {
            transitionTriggerLabel.textContent = `${pseudo} ${PreviewConfig.i18n.state || 'State'}`;
        }

        if (transitionAddHoverText) {
            transitionAddHoverText.textContent = `${PreviewConfig.i18n.addStyle || 'Add'} ${pseudo} ${PreviewConfig.i18n.style || 'style'}`;
        }
    }

    /**
     * Save the transition
     */
    async function saveTransition() {
        if (!transitionEditorSelector) return;

        managementUrl = PreviewConfig?.managementUrl || '';
        authToken = PreviewConfig?.authToken || '';

        const transitionValue = buildTransitionValue();

        try {
            const response = await fetch(managementUrl + 'setStyleRule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    selector: transitionEditorSelector,
                    styles: `transition: ${transitionValue};`
                })
            });

            const result = await response.json();

            if (result.status === 200) {
                showToast(PreviewConfig.i18n.transitionSaved || 'Transition saved', 'success');

                refreshPreviewFrame();

                if (typeof transitionEditorCallback === 'function') {
                    transitionEditorCallback(transitionValue);
                }

                close();
            } else {
                throw new Error(result.message || 'Failed to save');
            }

        } catch (error) {
            console.error('Failed to save transition:', error);
            showToast(PreviewConfig.i18n.saveTransitionFailed || 'Failed to save transition', 'error');
        }
    }

    /**
     * Preview hover effect in iframe
     */
    function previewTransitionHover() {
        const iframe = document.getElementById('preview-frame');
        if (!iframe?.contentDocument || !transitionEditorSelector) return;

        try {
            const elements = iframe.contentDocument.querySelectorAll(transitionEditorSelector);
            if (elements.length === 0) {
                showToast(PreviewConfig.i18n.noElementsFound || 'No elements found', 'warning');
                return;
            }

            elements.forEach(el => {
                el.dataset.originalStyle = el.getAttribute('style') || '';

                Object.entries(transitionEditorHoverProperties).forEach(([prop, value]) => {
                    el.style.setProperty(prop, value);
                });
            });

            setTimeout(() => {
                elements.forEach(el => {
                    const original = el.dataset.originalStyle;
                    if (original) {
                        el.setAttribute('style', original);
                    } else {
                        el.removeAttribute('style');
                    }
                    delete el.dataset.originalStyle;
                });
            }, 1500);

            showToast(PreviewConfig.i18n.previewingHover || 'Previewing hover effect', 'info');

        } catch (error) {
            console.error('Failed to preview hover:', error);
        }
    }

    /**
     * Initialize event listeners
     */
    function initEventListeners() {
        if (!transitionEditorModal) return;

        // Close button
        document.getElementById('transition-editor-close')?.addEventListener('click', close);
        document.getElementById('transition-cancel')?.addEventListener('click', close);

        // Save button
        document.getElementById('transition-save')?.addEventListener('click', saveTransition);

        // Preview button
        document.getElementById('transition-preview-btn')?.addEventListener('click', previewTransitionHover);

        // Backdrop click
        transitionEditorModal.querySelector('.preview-keyframe-modal__backdrop')?.addEventListener('click', close);

        // Pseudo-class select change
        transitionPseudoSelect?.addEventListener('change', async (e) => {
            transitionEditorCurrentPseudo = e.target.value;
            updateTriggerStateLabels();
            await loadTransitionSelectorStates(transitionEditorSelector);
        });

        // Property select change
        transitionPropertySelect?.addEventListener('change', (e) => {
            if (e.target.value === 'specific') {
                transitionSpecificProps.style.display = '';
            } else {
                transitionSpecificProps.style.display = 'none';
            }
            updateTransitionPreviewCode();
        });

        // Timing function change
        transitionTimingSelect?.addEventListener('change', (e) => {
            if (e.target.value === 'cubic-bezier') {
                transitionCubicBezier.style.display = '';
            } else {
                transitionCubicBezier.style.display = 'none';
            }
            updateTransitionPreviewCode();
        });

        // Duration/delay changes
        transitionDuration?.addEventListener('input', updateTransitionPreviewCode);
        transitionDelay?.addEventListener('input', updateTransitionPreviewCode);
        transitionDurationUnit?.addEventListener('change', updateTransitionPreviewCode);
        transitionDelayUnit?.addEventListener('change', updateTransitionPreviewCode);

        // Cubic bezier changes
        ['transition-bezier-x1', 'transition-bezier-y1', 'transition-bezier-x2', 'transition-bezier-y2'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', updateTransitionPreviewCode);
        });

        // Initialize property selectors
        initTransitionPropertySelectors();

        // Base state inline add property form
        transitionBaseAddToggle?.addEventListener('click', () => toggleAddPropertyForm('base', true));
        transitionBaseAddCancel?.addEventListener('click', () => toggleAddPropertyForm('base', false));
        transitionBaseAddConfirm?.addEventListener('click', async () => {
            const prop = transitionBasePropSelector?.getValue();
            const value = transitionBaseValueInput?.getValue();
            if (prop && value) {
                await addTransitionProperty('base', prop, value);
                toggleAddPropertyForm('base', false);
            }
        });

        // Keyboard events for base value input
        transitionBaseValueContainer?.addEventListener('keydown', async (e) => {
            if (e.key === 'Enter') {
                const prop = transitionBasePropSelector?.getValue();
                const value = transitionBaseValueInput?.getValue();
                if (prop && value) {
                    await addTransitionProperty('base', prop, value);
                    toggleAddPropertyForm('base', false);
                }
            } else if (e.key === 'Escape') {
                toggleAddPropertyForm('base', false);
            }
        });

        // Trigger state inline add property form
        transitionTriggerAddToggle?.addEventListener('click', () => toggleAddPropertyForm('trigger', true));
        transitionTriggerAddCancel?.addEventListener('click', () => toggleAddPropertyForm('trigger', false));
        transitionTriggerAddConfirm?.addEventListener('click', async () => {
            const prop = transitionTriggerPropSelector?.getValue();
            const value = transitionTriggerValueInput?.getValue();
            if (prop && value) {
                await addTransitionProperty('trigger', prop, value);
                toggleAddPropertyForm('trigger', false);
            }
        });

        // Keyboard events for trigger value input
        transitionTriggerValueContainer?.addEventListener('keydown', async (e) => {
            if (e.key === 'Enter') {
                const prop = transitionTriggerPropSelector?.getValue();
                const value = transitionTriggerValueInput?.getValue();
                if (prop && value) {
                    await addTransitionProperty('trigger', prop, value);
                    toggleAddPropertyForm('trigger', false);
                }
            } else if (e.key === 'Escape') {
                toggleAddPropertyForm('trigger', false);
            }
        });

        // Animation section event listeners
        transitionBaseAnimAddBtn?.addEventListener('click', () => openAnimationPicker('base'));
        transitionTriggerAnimAddBtn?.addEventListener('click', () => openAnimationPicker('trigger'));
        transitionBaseAnimRemoveBtn?.addEventListener('click', () => removeAnimation('base'));
        transitionTriggerAnimRemoveBtn?.addEventListener('click', () => removeAnimation('trigger'));
        transitionBaseAnimPreviewBtn?.addEventListener('click', () => previewAnimation('base'));
        transitionTriggerAnimPreviewBtn?.addEventListener('click', () => previewAnimation('trigger'));

        // Animation setting change listeners
        transitionBaseAnimDuration?.addEventListener('change', () => updateAnimationSetting('base', 'duration'));
        transitionTriggerAnimDuration?.addEventListener('change', () => updateAnimationSetting('trigger', 'duration'));
        transitionBaseAnimIterations?.addEventListener('change', () => updateAnimationSetting('base', 'iterations'));
        transitionTriggerAnimIterations?.addEventListener('change', () => updateAnimationSetting('trigger', 'iterations'));
        transitionBaseAnimInfinite?.addEventListener('change', () => updateAnimationSetting('base', 'infinite'));
        transitionTriggerAnimInfinite?.addEventListener('change', () => updateAnimationSetting('trigger', 'infinite'));
    }

    /**
     * Initialize the module
     */
    function init() {
        initDOMReferences();
        initEventListeners();
    }

    // Public API
    return {
        init: init,
        open: open,
        close: close,

        // Callback setters
        setShowToast: (fn) => { showToast = fn; },
        setEscapeHtml: (fn) => { escapeHtml = fn; },
        setParseStylesString: (fn) => { parseStylesString = fn; },
        setRefreshPreviewFrame: (fn) => { refreshPreviewFrame = fn; },
        setOpenAnimationPreviewModal: (fn) => { openAnimationPreviewModal = fn; },
        setGetKeyframesData: (fn) => { getKeyframesData = fn; },
        setGetThemeVariables: (fn) => { getThemeVariables = fn; },

        // Getters for current state
        getCurrentSelector: () => transitionEditorSelector,
        isOpen: () => transitionEditorModal?.classList.contains('preview-keyframe-modal--visible')
    };
})();
