/**
 * QuickSite Core Library (qs.js)
 * 
 * Lightweight utility functions for declarative interactions.
 * Use via {{call:functionName:args}} syntax in structure JSON.
 * 
 * Example: "onclick": "{{call:hide:#modal}}"
 * Renders: onclick="QS.hide('#modal')"
 * 
 * @version 2.0.0
 * @license MIT
 */

(function(window) {
    'use strict';

    // Create QS namespace
    const QS = {};

    // Default hidden class (CSS should define .hidden { display: none; })
    const DEFAULT_HIDDEN_CLASS = 'hidden';

    /**
     * Get all elements matching selector
     * @param {string} selector - CSS selector
     * @returns {NodeList}
     */
    function getElements(selector) {
        try {
            return document.querySelectorAll(selector);
        } catch (e) {
            console.warn('[QS] Invalid selector:', selector);
            return [];
        }
    }

    /**
     * Show element(s) - removes hidden class
     * @param {string} target - CSS selector
     * @param {string} [hideClass] - Class to remove (default: 'hidden')
     */
    QS.show = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.remove(hideClass));
    };

    /**
     * Hide element(s) - adds hidden class
     * @param {string} target - CSS selector
     * @param {string} [hideClass] - Class to add (default: 'hidden')
     */
    QS.hide = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.add(hideClass));
    };

    /**
     * Toggle element(s) visibility (show/hide flip-switch)
     * @param {string} target - CSS selector
     * @param {string} [hideClass] - Class to toggle (default: 'hidden')
     */
    QS.toggleHide = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.toggle(hideClass));
    };

    /**
     * Toggle a CSS class on element(s)
     * @param {string} target - CSS selector
     * @param {string} className - Class to toggle
     */
    QS.toggle = function(target, className) {
        if (!className) {
            console.warn('[QS] toggle: className required');
            return;
        }
        const elements = getElements(target);
        elements.forEach(el => el.classList.toggle(className));
    };

    /**
     * Add a CSS class to element(s)
     * @param {string} target - CSS selector
     * @param {string} className - Class to add
     */
    QS.addClass = function(target, className) {
        if (!className) {
            console.warn('[QS] addClass: className required');
            return;
        }
        const elements = getElements(target);
        elements.forEach(el => el.classList.add(className));
    };

    /**
     * Remove a CSS class from element(s)
     * @param {string} target - CSS selector
     * @param {string} className - Class to remove
     */
    QS.removeClass = function(target, className) {
        if (!className) {
            console.warn('[QS] removeClass: className required');
            return;
        }
        const elements = getElements(target);
        elements.forEach(el => el.classList.remove(className));
    };

    /**
     * Set text content or value of element(s)
     * Handles inputs, textareas, selects, checkboxes, and radios
     * @param {string} target - CSS selector
     * @param {string|boolean} value - Value to set (boolean for checkbox/radio)
     */
    QS.setValue = function(target, value) {
        const elements = getElements(target);
        elements.forEach(el => {
            if (el.tagName === 'INPUT') {
                const type = (el.type || '').toLowerCase();
                if (type === 'checkbox' || type === 'radio') {
                    // For checkbox/radio: set checked state
                    // Accept boolean, 'true'/'false' strings, or any truthy value
                    el.checked = (value === true || value === 'true' || value === '1');
                } else {
                    el.value = value;
                }
            } else if (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                el.value = value;
            } else {
                el.textContent = value;
            }
        });
    };

    /**
     * Navigate to URL
     * @param {string} url - URL to navigate to
     */
    QS.redirect = function(url) {
        if (url) {
            window.location.href = url;
        }
    };

    /**
     * Filter elements based on text input
     * Call from input element's oninput event
     * @param {Event|string} eventOrSelector - Event object OR items selector (for backward compat)
     * @param {string} [itemsSelector] - Selector for items to filter (when event passed)
     * @param {string} [matchAttr] - Attribute to match against (default: textContent)
     * @param {string} [hideClass] - Class to add/remove (default: 'hidden')
     * 
     * Usage in JSON: "oninput": "{{call:filter:event,.card,data-title}}"
     * Pass 'event' as first arg to get input value from the triggering element
     */
    QS.filter = function(eventOrSelector, itemsSelector, matchAttr, hideClass) {
        let searchValue = '';
        let selector = itemsSelector;
        let attr = matchAttr;
        let hideCls = hideClass || DEFAULT_HIDDEN_CLASS;
        
        // Check if first arg is an event or a selector string
        if (eventOrSelector && typeof eventOrSelector === 'object' && eventOrSelector.target) {
            // Called with event: QS.filter(event, '.items', 'data-title')
            searchValue = (eventOrSelector.target.value || '').toLowerCase();
        } else if (typeof eventOrSelector === 'string') {
            // Called without event: QS.filter('.items', 'data-title')
            // Shift arguments and try to get value from activeElement
            selector = eventOrSelector;
            attr = itemsSelector;
            hideCls = matchAttr || DEFAULT_HIDDEN_CLASS;
            const activeEl = document.activeElement;
            searchValue = (activeEl && activeEl.value !== undefined) 
                ? activeEl.value.toLowerCase() 
                : '';
        }
        
        if (!selector) {
            console.warn('[QS] filter: itemsSelector required');
            return;
        }

        const items = getElements(selector);
        
        items.forEach(el => {
            let matchValue = '';
            
            if (!attr || attr === 'textContent') {
                matchValue = el.textContent || '';
            } else if (attr.startsWith('data-') || attr.startsWith('[')) {
                // data-* attribute or [attr] selector syntax
                const attrName = attr.replace(/^\[|\]$/g, ''); // Remove brackets if present
                matchValue = el.getAttribute(attrName) || el.textContent || '';
            } else {
                matchValue = el.getAttribute(attr) || el.textContent || '';
            }

            matchValue = matchValue.toLowerCase();

            if (searchValue === '' || matchValue.includes(searchValue)) {
                el.classList.remove(hideCls);
            } else {
                el.classList.add(hideCls);
            }
        });
    };

    /**
     * Scroll smoothly to an element
     * @param {string} target - CSS selector
     * @param {string} [behavior] - 'smooth' (default) or 'instant'
     */
    QS.scrollTo = function(target, behavior) {
        behavior = behavior || 'smooth';
        const element = document.querySelector(target);
        if (element) {
            element.scrollIntoView({ behavior: behavior, block: 'start' });
        }
    };

    /**
     * Focus an element
     * @param {string} target - CSS selector
     */
    QS.focus = function(target) {
        const element = document.querySelector(target);
        if (element && element.focus) {
            element.focus();
        }
    };

    /**
     * Blur (unfocus) an element
     * @param {string} target - CSS selector
     */
    QS.blur = function(target) {
        const element = document.querySelector(target);
        if (element && element.blur) {
            element.blur();
        }
    };

    // Expose to window
    window.QS = QS;

})(window);
