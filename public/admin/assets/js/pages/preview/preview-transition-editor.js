/**
 * Preview Transition Editor Module
 *
 * UI PATH: Style tool → Selectors → pick a selector → [Animate].
 * The modal is titled "State & Animation Editor". Nothing in the interface
 * says "transition editor" — this file's name and its UI label differ, and
 * that mismatch has already produced two wrong conclusions and one round of
 * verification steps for controls that do not exist. Its play triangle beside
 * an animation name is previewAnimation(); its "Preview Hover" button is
 * previewTransitionHover(), which hides this modal for the 1.5s the preview
 * runs, because it otherwise covers the very thing it is previewing.
 *
 * Handles the State & Animation Editor modal for creating CSS transitions
 * and managing base/trigger state styles with animations.
 *
 * Dependencies:
 * - PreviewConfig (for i18n and URLs)
 * - QSPropertySelector, QSValueInput (property selection components)
 * - QSColorPicker (color picker)
 */
window.PreviewTransitionEditor = (function() {
    'use strict';

    // Callbacks to parent context
    let showToast = () => {};
    let parseStylesString = (str) => ({});
    let refreshPreviewFrame = () => {};
    let openAnimationPreviewModal = null;   // takes a keyframe NAME, not a keyframe object
    let getKeyframesData = () => [];
    let ensureKeyframesData = async () => {};   // ask the list's owner to load it if it has not
    let getThemeVariables = () => ({});

    // Transition Editor state
    let transitionEditorSelector = null;
    // The media query of the rule being edited, when it sits inside @media (null: outside any)
    let transitionEditorMediaQuery = null;
    let transitionEditorBaseProperties = {};
    let transitionEditorHoverProperties = {};
    let transitionEditorCurrentPseudo = ':hover';
    let transitionEditorCallback = null;
    // The transition's settings as the editor opened: Save writes the transition only when they changed
    let transitionEditorOpenedValue = null;
    // The properties the rule's transition names, read on opening ([] when it names all)
    let transitionEditorTransitionProps = [];
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
    let transitionRowsEl = null;
    let transitionAddRowBtn = null;
    let transitionRowTemplate = null;
    let transitionAllSettings = [];
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

    // Each Specific Properties row's property picker
    const transitionRowPickers = new Map();

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
        transitionRowsEl = document.getElementById('transition-rows');
        transitionAddRowBtn = document.getElementById('transition-add-row');
        transitionRowTemplate = document.getElementById('transition-row-template');
        transitionAllSettings = Array.from(document.querySelectorAll('#transition-editor-modal .transition-editor__all-setting'));
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
     * @param {string|null} mediaQuery - The rule's media query when it sits inside @media:
     *                                   its states are read and written there
     */
    async function open(selector, onSave = null, mediaQuery = null) {
        transitionEditorSelector = selector;
        transitionEditorMediaQuery = mediaQuery || null;
        transitionEditorCallback = onSave;

        // Update header
        if (transitionEditorSelectorEl) transitionEditorSelectorEl.textContent = selector;
        const mediaEl = document.getElementById('transition-editor-media');
        if (mediaEl) {
            mediaEl.textContent = transitionEditorMediaQuery ? '@media ' + transitionEditorMediaQuery : '';
            mediaEl.hidden = !transitionEditorMediaQuery;
        }
        if (transitionBaseSelectorEl) transitionBaseSelectorEl.textContent = selector;

        // Reset state
        transitionEditorBaseProperties = {};
        transitionEditorHoverProperties = {};
        transitionEditorCurrentPseudo = transitionPseudoSelect?.value || ':hover';

        // Reset controls to defaults: All Properties at 0.3s ease, no row (the previous rule's go)
        if (transitionPropertySelect) transitionPropertySelect.value = 'all';
        _setSettings(_allControls(), { duration: '0.3s', delay: '0s', timing: 'ease' });
        _showSpecific(false);
        _setTransitionRows([]);
        transitionEditorTransitionProps = [];

        // Update labels to match current pseudo-class
        updateTriggerStateLabels();

        // Show modal
        transitionEditorModal?.classList.add('preview-keyframe-modal--visible');

        // Load selector states, and the rule's own transition into the controls
        await loadTransitionSelectorStates(selector, true);
        transitionEditorOpenedValue = buildTransitionValue();

        // Update preview
        updateTransitionPreviewCode();
    }

    /**
     * Close the Transition Editor
     */
    function close() {
        transitionEditorModal?.classList.remove('preview-keyframe-modal--visible');
        transitionEditorSelector = null;
        transitionEditorMediaQuery = null;
        transitionEditorCallback = null;
    }

    /**
     * What a setStyleRule body adds for the rule being edited: its media query when it sits
     * inside @media, so its base and its trigger state are written there.
     */
    function ruleMediaQuery() {
        return transitionEditorMediaQuery ? { mediaQuery: transitionEditorMediaQuery } : {};
    }

    /**
     * Load base and pseudo-state styles for the selector. readTransition: also set the transition
     * controls from the rule's own transition, on opening only: a later reload (an add, a delete, the
     * trigger state changed) keeps what the author set.
     */
    async function loadTransitionSelectorStates(selector, readTransition = false) {
        const pseudoClass = transitionEditorCurrentPseudo;
        const hoverSelector = selector + pseudoClass;

        try {
            const [baseResponse, hoverResponse] = await Promise.all([
                QuickSiteAdmin.apiRequest('getStyleRule', 'GET', null,
                    QuickSiteAdmin.styleRuleSegments(selector, transitionEditorMediaQuery)),
                QuickSiteAdmin.apiRequest('getStyleRule', 'GET', null,
                    QuickSiteAdmin.styleRuleSegments(hoverSelector, transitionEditorMediaQuery))
            ]);

            const baseData = baseResponse.data || {};
            const hoverData = hoverResponse.data || {};

            // Extract properties
            transitionEditorBaseProperties = {};
            transitionEditorHoverProperties = {};
            transitionEditorBaseAnimation = null;
            transitionEditorTriggerAnimation = null;

            if (baseData.status === 200 && baseData.data?.styles) {
                transitionEditorBaseProperties = parseStylesString(baseData.data.styles);

                if (readTransition && transitionEditorBaseProperties.transition) {
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

            // Render property panels (the transition's rows are the author's: a reload leaves them)
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

    // The timing functions the timing picker offers by name (a cubic-bezier() it shows in its fields)
    const PICKER_TIMINGS = ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out'];

    /**
     * Set the transition controls from the rule's own transition, as written: a comma list, each
     * transition its property, duration, timing function and delay in any order (a first time is the
     * duration, a second the delay; a property left out is all). A single transition of all shows as
     * All Properties with its settings; anything else as Specific Properties, a row for each
     * transition with its own settings (all included, as one row's property), so every transition
     * the controls build again is the one the rule holds.
     */
    function parseExistingTransition(transitionValue) {
        const items = _splitTopLevel(transitionValue, ',').map(_parseTransitionItem).filter(Boolean);
        if (!items.length || (items.length === 1 && items[0].property === 'none')) return;
        if (items.length === 1 && items[0].property === 'all') {
            _setSettings(_allControls(), items[0]);
            return;
        }
        transitionEditorTransitionProps = items.map(item => item.property);
        if (transitionPropertySelect) transitionPropertySelect.value = 'specific';
        _showSpecific(true);
        _setTransitionRows(items);
    }

    /** `text` split on `sep` (',' or ' ') outside parentheses: a cubic-bezier() stays whole. */
    function _splitTopLevel(text, sep) {
        const out = [];
        let depth = 0;
        let current = '';
        for (const ch of String(text)) {
            if (ch === '(') depth++;
            if (ch === ')') depth = Math.max(0, depth - 1);
            const splits = depth === 0 && (sep === ' ' ? /\s/.test(ch) : ch === sep);
            if (!splits) {
                current += ch;
                continue;
            }
            if (current.trim()) out.push(current.trim());
            current = '';
        }
        if (current.trim()) out.push(current.trim());
        return out;
    }

    /** One transition of the list: its property, duration, delay and timing, CSS's defaults where left out. */
    function _parseTransitionItem(item) {
        const tokens = _splitTopLevel(item, ' ');
        if (!tokens.length) return null;
        const times = [];
        let property = 'all';
        let timing = 'ease';
        tokens.forEach(token => {
            if (/^[+-]?(?:\d+\.?\d*|\.\d+)m?s$/i.test(token)) times.push(token.toLowerCase());
            else if (/^(?:ease|linear|ease-in|ease-out|ease-in-out|step-start|step-end)$/i.test(token)
                || /^(?:cubic-bezier|steps|linear)\(/i.test(token)) timing = token.toLowerCase();
            else if (token !== 'normal' && token !== 'allow-discrete') property = token;
        });
        return { property, duration: times[0] || '0s', delay: times[1] || '0s', timing };
    }

    /** A duration or a delay ("300ms", ".5s") into its number field and its unit picker. */
    function _setTimeControl(input, unitSelect, time) {
        const m = /^([+-]?(?:\d+\.?\d*|\.\d+))(m?s)$/i.exec(time);
        if (!m || !input) return;
        input.value = parseFloat(m[1]);
        if (unitSelect) unitSelect.value = m[2].toLowerCase();
    }

    /**
     * A timing function into a timing picker, All's or a row's (a cubic-bezier() into its four
     * fields). One the picker lacks (steps(), step-start…) becomes one more choice of that picker,
     * as written: kept, never replaced by a timing nobody chose.
     */
    function _setTimingControl(select, bezierBox, timing) {
        if (!select) return;
        select.querySelectorAll('option[data-as-written]').forEach(option => option.remove());
        const bezier = /^cubic-bezier\(([^)]*)\)$/.exec(timing);
        const values = bezier ? bezier[1].split(',').map(v => parseFloat(v.trim())) : [];
        if (PICKER_TIMINGS.includes(timing)) {
            select.value = timing;
        } else if (values.length === 4 && !values.some(v => isNaN(v))) {
            select.value = 'cubic-bezier';
            ['x1', 'y1', 'x2', 'y2'].forEach((k, i) => {
                const field = bezierBox?.querySelector('[data-bezier="' + k + '"]');
                if (field) field.value = values[i];
            });
        } else {
            select.append(QSDom.el('option', { value: timing, 'data-as-written': '', text: timing }));
            select.value = timing;
        }
        if (bezierBox) bezierBox.style.display = select.value === 'cubic-bezier' ? '' : 'none';
    }

    /** What a timing picker holds, as CSS writes it (a cubic-bezier() from its four fields). */
    function _timingOf(select, bezierBox) {
        const timing = select?.value || 'ease';
        if (timing !== 'cubic-bezier') return timing;
        const field = (k, fallback) => bezierBox?.querySelector('[data-bezier="' + k + '"]')?.value || fallback;
        return `cubic-bezier(${field('x1', 0.4)}, ${field('y1', 0)}, ${field('x2', 0.2)}, ${field('y2', 1)})`;
    }

    /** All Properties' one set of controls. */
    function _allControls() {
        return {
            duration: transitionDuration, durationUnit: transitionDurationUnit,
            delay: transitionDelay, delayUnit: transitionDelayUnit,
            timing: transitionTimingSelect, bezier: transitionCubicBezier
        };
    }

    /** A Specific Properties row's own controls. */
    function _rowControls(row) {
        const field = (name) => row.querySelector('.transition-editor__row-' + name);
        return {
            duration: field('duration'), durationUnit: field('duration-unit'),
            delay: field('delay'), delayUnit: field('delay-unit'),
            timing: field('timing'), bezier: field('bezier')
        };
    }

    /** What a set of controls holds: {duration, delay, timing}, each as CSS writes it ("0.3s", "100ms", "ease"). */
    function _settingsOf(controls) {
        return {
            duration: (controls.duration?.value || 0.3) + (controls.durationUnit?.value || 's'),
            delay: (parseFloat(controls.delay?.value || 0) || 0) + (controls.delayUnit?.value || 's'),
            timing: _timingOf(controls.timing, controls.bezier)
        };
    }

    /** A set of settings ({duration, delay, timing}, as CSS writes them) into a set of controls. */
    function _setSettings(controls, settings) {
        _setTimeControl(controls.duration, controls.durationUnit, settings.duration);
        _setTimeControl(controls.delay, controls.delayUnit, settings.delay);
        _setTimingControl(controls.timing, controls.bezier, settings.timing);
    }

    /** One transition as CSS writes it: its property, duration and timing, and its delay when it has one. */
    function _transitionText(property, settings) {
        return `${property} ${settings.duration} ${settings.timing}` + (parseFloat(settings.delay) > 0 ? ' ' + settings.delay : '');
    }

    /** Specific Properties shows its rows, All Properties its one set: never both. */
    function _showSpecific(specific) {
        if (transitionSpecificProps) transitionSpecificProps.style.display = specific ? '' : 'none';
        transitionAllSettings.forEach(el => { el.style.display = specific ? 'none' : ''; });
    }

    /** The Specific Properties rows, in order. */
    function _rows() {
        return transitionRowsEl ? Array.from(transitionRowsEl.querySelectorAll('.transition-editor__row')) : [];
    }

    /** A row's property ('' while none is chosen). */
    function _rowProperty(row) {
        return transitionRowPickers.get(row)?.getValue() || '';
    }

    /** A row as one transition: its property and its own settings. */
    function _rowItem(row) {
        return { property: _rowProperty(row), ..._settingsOf(_rowControls(row)) };
    }

    /**
     * One Specific Properties row, cloned from the page's template: the property picker the add rows
     * use (every CSS property, or a custom name), its own duration, delay and timing, and ✕.
     */
    function _renderTransitionRow(item) {
        const row = transitionRowTemplate.content.querySelector('.transition-editor__row').cloneNode(true);
        const controls = _rowControls(row);
        _setSettings(controls, item);
        if (typeof QSPropertySelector !== 'undefined') {
            transitionRowPickers.set(row, new QSPropertySelector({
                container: row.querySelector('.transition-editor__row-property'),
                currentValue: item.property || '',
                onSelect: () => {
                    _refreshRowExclusions();
                    updateTransitionPreviewCode();
                }
            }));
        }
        row.querySelectorAll('input').forEach(field => field.addEventListener('input', updateTransitionPreviewCode));
        [controls.durationUnit, controls.delayUnit].forEach(field => field?.addEventListener('change', updateTransitionPreviewCode));
        controls.timing?.addEventListener('change', () => {
            if (controls.bezier) controls.bezier.style.display = controls.timing.value === 'cubic-bezier' ? '' : 'none';
            updateTransitionPreviewCode();
        });
        row.querySelector('.transition-editor__row-remove')?.addEventListener('click', () => _removeTransitionRow(row));
        return row;
    }

    /** The rows, replaced by one for each transition given. */
    function _setTransitionRows(items) {
        transitionRowPickers.forEach(picker => picker.destroy());
        transitionRowPickers.clear();
        transitionRowsEl?.replaceChildren(...items.map(_renderTransitionRow));
        _refreshRowExclusions();
    }

    /** Each row's picker leaves out the properties the other rows hold: a property is in one row at most. */
    function _refreshRowExclusions() {
        const rows = _rows();
        rows.forEach(row => transitionRowPickers.get(row)?.setExcludeProperties(
            rows.filter(other => other !== row).map(_rowProperty).filter(Boolean)));
    }

    /** + Add Property: an empty row with the settings of the row above it (All's when there is none). */
    function _addTransitionRow() {
        const rows = _rows();
        const above = rows.length ? _rowItem(rows[rows.length - 1]) : _settingsOf(_allControls());
        transitionRowsEl?.append(_renderTransitionRow({ ...above, property: '' }));
        _refreshRowExclusions();
        updateTransitionPreviewCode();
    }

    /** ✕: the row goes; the last one leaves an empty row with its settings, so the list never shows nothing. */
    function _removeTransitionRow(row) {
        const settings = _rowItem(row);
        transitionRowPickers.get(row)?.destroy();
        transitionRowPickers.delete(row);
        row.remove();
        if (!_rows().length) transitionRowsEl?.append(_renderTransitionRow({ ...settings, property: '' }));
        _refreshRowExclusions();
        updateTransitionPreviewCode();
    }

    /** The properties the rule's states set (base and trigger) and its transition names, once each. */
    function _stateProperties() {
        return [...new Set([
            ...Object.keys(transitionEditorBaseProperties),
            ...Object.keys(transitionEditorHoverProperties),
            ...transitionEditorTransitionProps
        ])].filter(prop => prop !== 'transition' && prop !== 'animation' && prop !== 'all');
    }

    /** The note a list of the editor shows when it has nothing to list. */
    function _renderEmptyNote(text) {
        return QSDom.el('div', { class: 'transition-editor__empty', text: text });
    }

    /**
     * One property of a state (`target`: base or trigger): its name, its value (whole in the
     * tooltip too) and its delete button.
     */
    function _renderPropertyRow(prop, value, target) {
        return QSDom.el('div', { class: 'transition-editor__property', 'data-property': prop, 'data-target': target }, [
            QSDom.el('div', { class: 'transition-editor__property-info' }, [
                QSDom.el('span', { class: 'transition-editor__property-name', text: prop }),
                ' ',
                QSDom.el('span', { class: 'transition-editor__property-value', title: value, text: value })
            ]),
            QSDom.el('div', { class: 'transition-editor__property-actions' }, [
                QSDom.el('button', {
                    type: 'button',
                    class: 'transition-editor__property-btn transition-editor__property-btn--delete',
                    'data-action': 'delete',
                    title: PreviewConfig.i18n.delete || 'Delete'
                }, [QSDom.iconEl(QuickSiteUtils.ICON_PATHS.close, 12)])
            ])
        ]);
    }

    /** One keyframe the animation picker offers. */
    function _renderAnimationPickerItem(name) {
        return QSDom.el('div', {
            class: 'animation-picker-dropdown__item',
            'data-name': name,
            style: 'padding: 8px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--admin-border);'
        }, [
            QSDom.el('span', { style: 'color: var(--admin-accent);', text: '@keyframes' }),
            QSDom.el('span', { style: 'color: var(--admin-text);', text: name })
        ]);
    }

    /**
     * Render base state properties panel
     */
    function renderTransitionBaseProperties() {
        if (!transitionBasePropsEl) return;

        const props = Object.entries(transitionEditorBaseProperties)
            .filter(([key]) => key !== 'transition' && key !== 'animation');

        if (props.length === 0) {
            transitionBasePropsEl.replaceChildren(_renderEmptyNote(PreviewConfig.i18n.noBaseStyles || 'No base styles defined'));
            return;
        }

        transitionBasePropsEl.replaceChildren(...props.map(([prop, value]) => _renderPropertyRow(prop, value, 'base')));

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
            transitionHoverPropsEl.replaceChildren(_renderEmptyNote(PreviewConfig.i18n.noTriggerStyles || 'No trigger state styles'));
            return;
        }

        transitionHoverPropsEl.replaceChildren(...props.map(([prop, value]) => _renderPropertyRow(prop, value, 'trigger')));

        // Add event listeners for delete buttons
        transitionHoverPropsEl.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const propEl = e.target.closest('.transition-editor__property');
                const prop = propEl?.dataset.property;
                if (prop) deleteTransitionProperty('trigger', prop);
            });
        });
    }

    /**
     * Delete a property from base or trigger state
     */
    async function deleteTransitionProperty(target, property) {
        const selector = target === 'base'
            ? transitionEditorSelector
            : transitionEditorSelector + transitionEditorCurrentPseudo;

        try {
            const response = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                ...ruleMediaQuery(),
                selector: selector,
                styles: '',
                removeProperties: [property]
            });

            const result = response.data || {};

            if (result.status === 200) {
                showToast(PreviewConfig.i18n.propertyDeleted || 'Property deleted', 'success');
                await loadTransitionSelectorStates(transitionEditorSelector);
                refreshPreviewFrame();
            } else {
                throw new Error(result.message || 'Failed to delete property');
            }
        } catch (error) {
            console.error('Failed to delete property:', error);
            showToast(PreviewConfig.i18n.deletePropertyFailed || 'Failed to delete property', 'error');
        }
    }

    /**
     * Add a property to base or trigger state. Answers whether it was added.
     */
    async function addTransitionProperty(target, property, value) {
        if (!property || !value) return false;


        const selector = target === 'base'
            ? transitionEditorSelector
            : transitionEditorSelector + transitionEditorCurrentPseudo;

        try {
            const response = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                ...ruleMediaQuery(),
                selector: selector,
                styles: `${property}: ${value};`
            });

            const result = response.data || {};

            if (result.status === 200) {
                showToast(PreviewConfig.i18n.propertyAdded || 'Property added', 'success');
                await loadTransitionSelectorStates(transitionEditorSelector);
                refreshPreviewFrame();
                return true;
            } else {
                throw new Error(result.message || 'Failed to add property');
            }
        } catch (error) {
            console.error('Failed to add property:', error);
            showToast(PreviewConfig.i18n.addPropertyFailed || 'Failed to add property', 'error');
            return false;
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
        const keyframesData = getKeyframesData();

        if (!keyframesData || keyframesData.length === 0) {
            QuickSiteAdmin.apiRequest('getKeyframes')
            .then(res => res.data || {})
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
            background: var(--admin-bg-secondary);
            border: 1px solid var(--admin-border);
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 100002;
            max-height: 200px;
            overflow-y: auto;
            min-width: 180px;
        `;

        dropdown.replaceChildren(...keyframesData.map(kf => _renderAnimationPickerItem(kf.name)));

        dropdown.querySelectorAll('.animation-picker-dropdown__item').forEach(item => {
            item.addEventListener('click', () => {
                const name = item.dataset.name;
                addAnimationToSelector(target, name);
                dropdown.remove();
            });
            item.addEventListener('mouseenter', () => {
                item.style.background = 'var(--admin-surface-hover)';
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
        const selector = target === 'base' ? transitionEditorSelector :
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;

        const animationValue = `${keyframeName} 1000ms ease forwards`;

        try {
            const response = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                ...ruleMediaQuery(),
                selector: selector,
                styles: `animation: ${animationValue};`
            });

            const result = response.data || {};
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
        const selector = target === 'base' ? transitionEditorSelector :
            `${transitionEditorSelector}${transitionEditorCurrentPseudo || ':hover'}`;

        try {
            const response = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                ...ruleMediaQuery(),
                selector: selector,
                styles: '',
                removeProperties: ['animation']
            });

            const result = response.data || {};
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
    async function previewAnimation(target) {
        const animation = target === 'base' ? transitionEditorBaseAnimation : transitionEditorTriggerAnimation;
        if (!animation || !animation.name) {
            showToast(PreviewConfig.i18n.noAnimationToPreview || 'No animation to preview', 'warning');
            return;
        }

        // The keyframe list belongs to the Motion tab's module and is empty
        // until that tab has been opened at least once. Both the lookup below
        // and the preview modal's own CSS injection read it, so reaching this
        // modal from Selectors → Animate without ever visiting Motion has to
        // load it first — otherwise the preview reports "Keyframe not found"
        // for a keyframe that plainly exists.
        await ensureKeyframesData();

        const keyframesData = getKeyframesData();
        const keyframe = keyframesData?.find(kf => kf.name === animation.name);
        if (!keyframe) {
            showToast(PreviewConfig.i18n.keyframeNotFound || 'Keyframe not found', 'error');
            return;
        }

        if (typeof openAnimationPreviewModal === 'function') {
            openAnimationPreviewModal(keyframe.name);
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
            const response = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                ...ruleMediaQuery(),
                selector: selector,
                styles: `animation: ${animationValue};`
            });

            const result = response.data || {};
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
     * The transition the controls hold, as CSS writes it: All's one set for all, or one transition
     * for each row that has a property, with that row's own settings ("none" when no row has one).
     */
    function buildTransitionValue() {
        if (transitionPropertySelect?.value !== 'specific') {
            return _transitionText('all', _settingsOf(_allControls()));
        }
        const parts = _rows().map(_rowItem).filter(item => item.property)
            .map(item => _transitionText(item.property, item));
        return parts.length ? parts.join(', ') : 'none';
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
     * What an open add row holds (a property and a value), added as its Add button would: Save never
     * drops what the author typed. Both rows are read before either is added, as an add re-reads the
     * rule and rebuilds the rows. Answers false when an add failed: Save then stops, the editor open.
     */
    async function addPendingProperties() {
        const rows = [
            ['base', transitionBaseAddForm, transitionBasePropSelector, transitionBaseValueInput],
            ['trigger', transitionTriggerAddForm, transitionTriggerPropSelector, transitionTriggerValueInput]
        ];
        const pending = [];
        rows.forEach(([target, form, propSelector, valueInput]) => {
            if (!form || form.style.display === 'none') return;
            const property = propSelector?.getValue();
            const value = valueInput?.getValue();
            if (property && value) pending.push([target, property, value]);
        });
        for (const [target, property, value] of pending) {
            if (!(await addTransitionProperty(target, property, value))) return false;
            toggleAddPropertyForm(target, false);
        }
        return true;
    }

    /**
     * Save: first what an open add row holds, then the transition, only when its settings changed
     * since the editor opened. A Save with nothing else to do closes, and writes no declaration the
     * author did not ask for: an untouched rule keeps its own transition as written.
     */
    async function saveTransition() {
        if (!transitionEditorSelector) return;

        // The settings as they stand, read before an add re-reads the rule (which sets them from the
        // rule's own transition)
        const transitionValue = buildTransitionValue();
        if (!(await addPendingProperties())) return;
        if (transitionValue === transitionEditorOpenedValue) {
            close();
            return;
        }

        try {
            const response = await QuickSiteAdmin.apiRequest('setStyleRule', 'POST', {
                ...ruleMediaQuery(),
                selector: transitionEditorSelector,
                styles: `transition: ${transitionValue};`
            });

            const result = response.data || {};

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
        const iframe = document.getElementById('preview-iframe');
        if (!iframe?.contentDocument || !transitionEditorSelector) return;

        try {
            const elements = iframe.contentDocument.querySelectorAll(transitionEditorSelector);
            if (elements.length === 0) {
                showToast(PreviewConfig.i18n.noElementsFound || 'No elements found', 'warning');
                return;
            }

            // Step out of the way. This modal sits over the middle of the page
            // behind a dimming backdrop, so previewing a hover state underneath
            // it showed the author nothing. Hide it for the duration; the toast
            // below says what is happening while it is gone.
            transitionEditorModal?.classList.remove('preview-keyframe-modal--visible');

            // Two separate waits, and they are not the same thing. The first is
            // how long the trigger styles stay applied; the second is a beat of
            // unobstructed page AFTER they come off, so the author can see what
            // changed back. Restoring the modal the instant the effect ends
            // reads as a flicker and gives no time to take it in.
            const HOVER_HOLD_MS = 1500;
            const HOVER_SETTLE_MS = 1500;

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

                // Only come back if the editor is still open. close() nulls the
                // selector, so this will not resurrect a modal the author shut
                // while the preview was running — and it is re-checked after
                // the settle wait, not before it, because they can close during
                // the gap too.
                setTimeout(() => {
                    if (transitionEditorSelector) {
                        transitionEditorModal?.classList.add('preview-keyframe-modal--visible');
                    }
                }, HOVER_SETTLE_MS);
            }, HOVER_HOLD_MS);

            showToast(PreviewConfig.i18n.previewingHover || 'Previewing hover effect', 'info');

        } catch (error) {
            console.error('Failed to preview hover:', error);
            // Never leave the author staring at a modal that vanished.
            if (transitionEditorSelector) {
                transitionEditorModal?.classList.add('preview-keyframe-modal--visible');
            }
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

        // Properties: to Specific, a row for each property the rule's states set, each with All's
        // settings (none set: one empty row); back to All, one set, the first row's
        transitionPropertySelect?.addEventListener('change', (e) => {
            const specific = e.target.value === 'specific';
            if (specific) {
                const settings = _settingsOf(_allControls());
                const props = _stateProperties();
                _setTransitionRows((props.length ? props : ['']).map(property => ({ ...settings, property })));
            } else {
                const first = _rows()[0];
                if (first) _setSettings(_allControls(), _rowItem(first));
                _setTransitionRows([]);
            }
            _showSpecific(specific);
            updateTransitionPreviewCode();
        });

        // + Add Property, under the rows
        transitionAddRowBtn?.addEventListener('click', _addTransitionRow);

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
        setParseStylesString: (fn) => { parseStylesString = fn; },
        setRefreshPreviewFrame: (fn) => { refreshPreviewFrame = fn; },
        setOpenAnimationPreviewModal: (fn) => { openAnimationPreviewModal = fn; },
        setGetKeyframesData: (fn) => { getKeyframesData = fn; },
        setEnsureKeyframesData: (fn) => { ensureKeyframesData = fn; },
        setGetThemeVariables: (fn) => { getThemeVariables = fn; },

        // Getters for current state
        getCurrentSelector: () => transitionEditorSelector,
        isOpen: () => transitionEditorModal?.classList.contains('preview-keyframe-modal--visible')
    };
})();
