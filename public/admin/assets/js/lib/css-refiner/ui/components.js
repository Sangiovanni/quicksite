/* =============================================================================
   CSS REFINER — UI: Component Builder Functions
   Every piece of dynamic HTML is created through these parameterised functions.
   We use DOM API (no innerHTML) for security and clarity.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t = CSSRefiner.t;

    /* ── Low-level DOM helper ── */

    /**
     * Create a DOM element with attributes and children.
     *
     * @param {string} tag
     * @param {Object} [attrs]     key/value — className, textContent, data-*, on*
     * @param {Array}  [children]  DOM nodes or strings
     * @returns {HTMLElement}
     */
    function el(tag, attrs, children) {
        var element = document.createElement(tag);

        if (attrs) {
            var keys = Object.keys(attrs);
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                var val = attrs[key];

                if (val === null || val === undefined) { continue; }

                if (key === 'className')   { element.className = val; }
                else if (key === 'textContent') { element.textContent = val; }
                else if (key === 'htmlFor') { element.htmlFor = val; }
                else if (key === 'checked') { element.checked = !!val; }
                else if (key === 'disabled') { element.disabled = !!val; }
                else if (key === 'hidden')  { element.hidden = !!val; }
                else if (key === 'readOnly') { element.readOnly = !!val; }
                else if (key === 'value')   { element.value = val; }
                else if (key === 'type')    { element.type = val; }
                else if (key === 'placeholder') { element.placeholder = val; }
                else if (key.indexOf('on') === 0) {
                    element.addEventListener(key.slice(2).toLowerCase(), val);
                }
                else {
                    element.setAttribute(key, val);
                }
            }
        }

        if (children) {
            var list = Array.isArray(children) ? children : [children];
            for (var c = 0; c < list.length; c++) {
                if (!list[c]) { continue; }
                if (typeof list[c] === 'string') {
                    element.appendChild(document.createTextNode(list[c]));
                } else {
                    element.appendChild(list[c]);
                }
            }
        }

        return element;
    }

    /* ── High-level component builders ── */

    /**
     * Build a tool tab button.
     */
    function createTab(toolId, label, count, isActive, onClick) {
        var countBadge = el('span', { className: 'cr-tab__count', textContent: String(count) });
        var btn = el('button', {
            className: 'cr-tab' + (isActive ? ' cr-tab--active' : ''),
            type:      'button',
            role:      'tab',
            'aria-selected': isActive ? 'true' : 'false',
            'data-tool': toolId,
            onClick:   onClick
        }, [
            document.createTextNode(label + ' '),
            countBadge
        ]);
        return btn;
    }

    /**
     * Build the results header (count + select all / deselect all).
     */
    function createResultsHeader(count, onSelectAll, onDeselectAll) {
        var countSpan = el('span', {
            className:   'cr-results-count',
            textContent: t('results.found', { count: count })
        });

        var actions = el('div', { className: 'cr-results-actions' }, [
            el('button', {
                className:   'cr-btn cr-btn--small cr-btn--ghost',
                type:        'button',
                textContent: t('results.selectAll'),
                onClick:     onSelectAll
            }),
            el('button', {
                className:   'cr-btn cr-btn--small cr-btn--ghost',
                type:        'button',
                textContent: t('results.deselectAll'),
                onClick:     onDeselectAll
            })
        ]);

        return el('div', { className: 'cr-results-header' }, [countSpan, actions]);
    }

    /**
     * Build a suggestion list item with checkbox, description, meta, diff toggle.
     */
    function createSuggestionItem(suggestion, onToggle, onDiffToggle, onValueEdit) {
        var checkbox = el('label', { className: 'cr-checkbox' }, [
            el('input', {
                type:    'checkbox',
                checked: suggestion.enabled,
                onChange: function () { onToggle(suggestion.id, this.checked); }
            }),
            el('span', { className: 'cr-checkbox__mark' })
        ]);

        var textParts = [
            el('span', { className: 'cr-suggestion__desc', textContent: suggestion.description })
        ];

        if (suggestion.meta) {
            textParts.push(el('div', { className: 'cr-suggestion__meta', textContent: suggestion.meta }));
        }

        /* Editable suggested value (for fuzzy tool) */
        if (suggestion.suggestedValue !== undefined && onValueEdit) {
            var editLabel = el('span', {
                className: 'cr-suggestion__meta',
                textContent: t('tools.fuzzyValues.suggestedValue') + ' '
            });
            var editInput = el('input', {
                className:   'cr-suggestion__edit-value',
                type:        'text',
                value:       suggestion.suggestedValue,
                onChange:     function () { onValueEdit(suggestion.id, this.value); }
            });
            textParts.push(el('div', { className: 'cr-suggestion__meta' }, [editLabel, editInput]));
        }

        var textWrap = el('div', { className: 'cr-suggestion__text' }, textParts);

        var diffContainer = el('div', { className: 'cr-suggestion__diff', hidden: true });
        var toggleBtn = el('button', {
            className: 'cr-suggestion__toggle',
            type:      'button',
            textContent: t('results.showDiff'),
            onClick: function () {
                var showing = !diffContainer.hidden;
                diffContainer.hidden = showing;
                this.textContent = showing ? t('results.showDiff') : t('results.hideDiff');
                if (!diffContainer.hidden && diffContainer.childNodes.length === 0) {
                    onDiffToggle(suggestion, diffContainer);
                }
            }
        });

        var header = el('div', { className: 'cr-suggestion__header' }, [
            checkbox, textWrap, toggleBtn
        ]);

        var li = el('li', {
            className: 'cr-suggestion' + (suggestion.enabled ? '' : ' cr-suggestion--disabled'),
            'data-id': suggestion.id
        }, [header, diffContainer]);

        return li;
    }

    /**
     * Build settings controls for a tool.
     */
    function createSliderSetting(label, value, min, max, unit, onChange) {
        var valueDisplay = el('span', {
            className:   'cr-setting__value',
            textContent: value + (unit || '')
        });

        var slider = el('input', {
            className: 'cr-slider',
            type:      'range',
            value:     String(value),
            'min':     String(min),
            'max':     String(max),
            onInput:   function () {
                valueDisplay.textContent = this.value + (unit || '');
                onChange(parseInt(this.value, 10));
            }
        });

        return el('div', { className: 'cr-setting' }, [
            el('span', { className: 'cr-setting__label', textContent: label }),
            slider,
            valueDisplay
        ]);
    }

    function createNumberSetting(label, value, min, max, unit, onChange) {
        var input = el('input', {
            className: 'cr-number-input',
            type:      'number',
            value:     String(value),
            'min':     String(min),
            'max':     String(max),
            onChange:   function () {
                var v = parseInt(this.value, 10);
                if (isNaN(v)) { v = min; }
                if (v < min) { v = min; }
                if (v > max) { v = max; }
                this.value = v;
                onChange(v);
            }
        });

        var unitSpan = unit ? el('span', {
            className:   'cr-setting__label',
            textContent: unit
        }) : null;

        return el('div', { className: 'cr-setting' }, [
            el('span', { className: 'cr-setting__label', textContent: label }),
            input,
            unitSpan
        ].filter(Boolean));
    }

    /**
     * Build an empty state message.
     */
    function createEmptyState(icon, message) {
        return el('div', { className: 'cr-empty' }, [
            el('div', { className: 'cr-empty__icon', textContent: icon }),
            el('div', { textContent: message })
        ]);
    }

    /**
     * Slider + number input combo (synced).
     */
    function createSliderWithInput(label, value, min, max, unit, onChange) {
        var valueDisplay = el('span', {
            className:   'cr-setting__value',
            textContent: value + (unit || '')
        });

        var numInput = el('input', {
            className: 'cr-number-input cr-number-input--sync',
            type:      'number',
            value:     String(value),
            'min':     String(min),
            'max':     String(max)
        });

        var slider = el('input', {
            className: 'cr-slider',
            type:      'range',
            value:     String(value),
            'min':     String(min),
            'max':     String(max)
        });

        slider.addEventListener('input', function () {
            var v = parseInt(this.value, 10);
            numInput.value = v;
            valueDisplay.textContent = v + (unit || '');
            onChange(v);
        });

        numInput.addEventListener('change', function () {
            var v = parseInt(this.value, 10);
            if (isNaN(v)) { v = min; }
            if (v < min) { v = min; }
            if (v > max) { v = max; }
            this.value = v;
            slider.value = v;
            valueDisplay.textContent = v + (unit || '');
            onChange(v);
        });

        return el('div', { className: 'cr-setting' }, [
            el('span', { className: 'cr-setting__label', textContent: label }),
            slider,
            numInput,
            valueDisplay
        ]);
    }

    /**
     * Select dropdown setting.
     */
    function createSelectSetting(label, currentValue, options, onChange) {
        var select = el('select', { className: 'cr-select cr-select--small' });

        for (var i = 0; i < options.length; i++) {
            var opt = el('option', {
                value:       options[i].value,
                textContent: options[i].label
            });
            if (options[i].value === currentValue) {
                opt.selected = true;
            }
            select.appendChild(opt);
        }

        select.addEventListener('change', function () {
            onChange(this.value);
        });

        return el('div', { className: 'cr-setting' }, [
            el('span', { className: 'cr-setting__label', textContent: label }),
            select
        ]);
    }

    /**
     * Ignore-properties collapsible checklist.
     */
    function createIgnorePropsControl(label, allProps, currentIgnored, onChange) {
        var wrapper = el('div', { className: 'cr-ignore-props' });

        var toggleBtn = el('button', {
            className:   'cr-btn cr-btn--small cr-btn--ghost',
            type:        'button',
            textContent: label + ' (' + currentIgnored.length + ')'
        });

        var listDiv = el('div', { className: 'cr-ignore-props__list', hidden: true });

        toggleBtn.addEventListener('click', function () {
            listDiv.hidden = !listDiv.hidden;
        });

        var ignored = currentIgnored.slice();

        for (var i = 0; i < allProps.length; i++) {
            (function (prop) {
                var isChecked = ignored.indexOf(prop) !== -1;
                var cb = el('input', {
                    type:    'checkbox',
                    checked: isChecked
                });
                cb.addEventListener('change', function () {
                    var idx = ignored.indexOf(prop);
                    if (this.checked && idx === -1) {
                        ignored.push(prop);
                    } else if (!this.checked && idx !== -1) {
                        ignored.splice(idx, 1);
                    }
                    toggleBtn.textContent = label + ' (' + ignored.length + ')';
                    onChange(ignored);
                });
                var lbl = el('label', { className: 'cr-ignore-props__item' }, [
                    cb,
                    document.createTextNode(' ' + prop)
                ]);
                listDiv.appendChild(lbl);
            })(allProps[i]);
        }

        wrapper.appendChild(toggleBtn);
        wrapper.appendChild(listDiv);
        return wrapper;
    }

    /* ── Public API ── */
    CSSRefiner.UI = CSSRefiner.UI || {};
    CSSRefiner.UI.Components = {
        el:                    el,
        createTab:             createTab,
        createResultsHeader:   createResultsHeader,
        createSuggestionItem:  createSuggestionItem,
        createSliderSetting:   createSliderSetting,
        createSliderWithInput: createSliderWithInput,
        createSelectSetting:   createSelectSetting,
        createIgnorePropsControl: createIgnorePropsControl,
        createNumberSetting:   createNumberSetting,
        createEmptyState:      createEmptyState
    };

})();
