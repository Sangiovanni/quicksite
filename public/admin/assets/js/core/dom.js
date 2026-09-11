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
     * An icon from QuickSiteUtils.ICON_PATHS as an ELEMENT.
     *
     * The catalogue in utils.js stores each icon as the INNER markup of an
     * <svg> ('<polyline points="…"/>', '<path d="…"/><path d="…"/>'), because
     * its own svgIcon() returns an HTML string. That shape cannot be handed to
     * svgIcon() above, which takes a single bare `d` value — so a caller
     * building DOM had no way to use the catalogue without innerHTML, and
     * dashboard.js worked around it by hardcoding one icon's path a second
     * time. A second, element-shaped copy of the catalogue is exactly the
     * "third hand-maintained copy" MAINTAINING.md warns about, so this parses
     * the one catalogue instead of duplicating it.
     *
     * DOMParser rather than innerHTML: it introduces no dependency, it is not
     * an HTML-injection sink, and the ICON_PATHS entries are well-formed XML
     * (every element self-closes). Results are cached, so an icon repeated
     * down a tree is parsed once.
     *
     * @param {string} innerMarkup  an ICON_PATHS value
     * @param {number} [size=14]
     * @param {string} [cls]        class attribute for the <svg>
     * @returns {SVGElement|null}   null if the markup will not parse
     */
    var _iconCache = {};
    function iconEl(innerMarkup, size, cls) {
        if (!innerMarkup) return null;
        var cached = _iconCache[innerMarkup];
        if (cached === undefined) {
            var doc = new DOMParser().parseFromString(
                '<svg xmlns="http://www.w3.org/2000/svg">' + innerMarkup + '</svg>',
                'image/svg+xml'
            );
            // A parse failure yields a <parsererror> document rather than throwing.
            cached = doc.getElementsByTagName('parsererror').length
                ? null
                : doc.documentElement;
            _iconCache[innerMarkup] = cached;
        }
        if (!cached) return null;

        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        if (size !== 0) {
            svg.setAttribute('width', String(size || 14));
            svg.setAttribute('height', String(size || 14));
        }
        if (cls) svg.setAttribute('class', cls);
        svg.setAttribute('aria-hidden', 'true');
        // Clone out of the cache — a node can only live in one tree.
        for (var i = 0; i < cached.childNodes.length; i++) {
            svg.appendChild(cached.childNodes[i].cloneNode(true));
        }
        return svg;
    }

    /**
     * Put a button into its working state: the panel's spinner, then a label.
     *
     * The panel repeats `btn.innerHTML = spinner + ' ' + label` in three files.
     * Two of dashboard.js's six wrote `class="spinner"`, which has no rule in
     * admin.css — those buttons showed no spinner at all. Centralising the
     * idiom is what makes that class of divergence impossible rather than
     * merely fixed once.
     *
     * @param {HTMLElement} button      no-op if falsy
     * @param {string} label            shown beside the spinner, via textContent
     * @param {Object}  [opts]
     * @param {number}  [opts.size]     spinner px (the CSS default when absent)
     * @param {boolean} [opts.disabled=true]
     * @returns {string} the button's previous textContent, for restoring it
     */
    function setButtonBusy(button, label, opts) {
        if (!button) return '';
        var o = opts || {};
        var previous = button.textContent;
        clear(button);
        var spinner = document.createElement('span');
        spinner.className = 'admin-spinner';
        if (o.size) {
            spinner.style.width = o.size + 'px';
            spinner.style.height = o.size + 'px';
        }
        button.appendChild(spinner);
        button.appendChild(document.createTextNode(' ' + (label == null ? '' : String(label))));
        if (o.disabled !== false) button.disabled = true;
        return previous;
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
        iconEl: iconEl,
        clear: clear,
        setSelectPlaceholder: setSelectPlaceholder,
        setButtonBusy: setButtonBusy
    };
})();
