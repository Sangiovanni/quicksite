/**
 * QuickSite Admin — shared DOM construction helpers (C8 8.3c).
 *
 * The house idiom for HTML-in-JS hygiene (CLAUDE.md): createElement +
 * textContent via a tiny element factory; no innerHTML string-glueing.
 * Loaded in the layout <head> (like storage-keys.js) so page scripts can
 * reference it at parse time.
 *
 * NOTE: storage.js / privacy.js / oauth-providers.js each still carry a
 * file-local copy of this factory (they predate this module); new code uses
 * QSDom, migrating the older pages is a separate cleanup.
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

    return { el: el, svgIcon: svgIcon, clear: clear };
})();
