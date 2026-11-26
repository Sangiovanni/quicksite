/* =============================================================================
   CSS REFINER — UI: the DOM helper diff-view.js builds with.
   We use DOM API (no innerHTML) for security and clarity.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

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

    /* ── Public API ── */
    CSSRefiner.UI = CSSRefiner.UI || {};
    CSSRefiner.UI.Components = {
        el: el
    };

})();
