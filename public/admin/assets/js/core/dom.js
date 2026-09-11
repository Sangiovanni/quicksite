/**
 * QuickSite Admin — shared DOM construction helpers.
 *
 * The house idiom for HTML-in-JS hygiene (CLAUDE.md): createElement +
 * textContent via a tiny element factory; no innerHTML string-glueing.
 * Loaded in the layout <head> (like storage-keys.js) so page scripts can
 * reference it at parse time — every admin page renders through
 * AdminRouter::render() -> templates/layout.php, so every page has it.
 *
 * This is the ONE base factory. Page-specific _render* helpers stay in their
 * own page and build on it rather than re-declaring the factory.
 */
window.QSDom = (function () {
    'use strict';

    /**
     * Element factory.
     * @param {string} tag
     * @param {Object} [props] - 'class', 'text' (textContent), 'dataset',
     *   'onclick'/'on*' (addEventListener), anything else = setAttribute
     * @param {Array<Node|string|null>} [children] - strings become text nodes
     * @returns {HTMLElement}
     */
    function el(tag, props, children) {
        var e = document.createElement(tag);
        if (props) {
            for (var k in props) {
                if (k === 'dataset' && typeof props[k] === 'object') {
                    Object.assign(e.dataset, props[k]);
                } else if (k.indexOf('on') === 0 && typeof props[k] === 'function') {
                    e.addEventListener(k.slice(2).toLowerCase(), props[k]);
                } else if (k === 'class') {
                    e.className = props[k];
                } else if (k === 'text') {
                    e.textContent = props[k];
                } else {
                    e.setAttribute(k, props[k]);
                }
            }
        }
        if (children) {
            children.forEach(function (c) {
                if (c == null) return;
                if (typeof c === 'string') e.appendChild(document.createTextNode(c));
                else e.appendChild(c);
            });
        }
        return e;
    }

    /**
     * Single-path stroke icon (the admin panel's SVG style).
     * @param {string} pathD
     * @param {number} [size=14]
     * @returns {SVGElement}
     */
    function svgIcon(pathD, size) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('width', String(size || 14));
        svg.setAttribute('height', String(size || 14));
        svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('d', pathD);
        svg.appendChild(p);
        return svg;
    }

    /** Remove every child of a node (textContent-free list reset). */
    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    /**
     * Reset a <select> to a single placeholder option.
     *
     * The createElement replacement for the panel's most-repeated innerHTML
     * idiom, `select.innerHTML = '<option value="">Select type first…</option>'`.
     * It REPLACES every existing option, because that is what the assignment
     * it stands in for did — a cascading select calls this to empty itself
     * before repopulating, and an append would leave the stale list behind.
     *
     * `text` is set with textContent, so a label carrying <, & or a quote —
     * a filename, an API error message, a translation value — renders as
     * itself instead of being parsed as markup.
     *
     * @param {HTMLSelectElement} select  the select to reset (no-op if falsy)
     * @param {string} text               visible label; '' gives a blank option
     * @param {Object}  [opts]
     * @param {string}  [opts.value='']   the option's value attribute
     * @param {boolean} [opts.disabled]   render it unselectable — a pure label,
     *                                    the shape used by the list-box pickers
     * @param {boolean} [opts.selected]   mark it selected
     * @returns {HTMLOptionElement|null}  the option, so a caller can hold on to it
     */
    function setSelectPlaceholder(select, text, opts) {
        if (!select) return null;
        var o = opts || {};
        clear(select);
        var option = document.createElement('option');
        option.value = o.value == null ? '' : String(o.value);
        option.textContent = text == null ? '' : String(text);
        if (o.disabled) option.disabled = true;
        if (o.selected) option.selected = true;
        select.appendChild(option);
        return option;
    }

    return {
        el: el,
        svgIcon: svgIcon,
        clear: clear,
        setSelectPlaceholder: setSelectPlaceholder
    };
})();
