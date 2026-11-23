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

    /**
     * The panel's named icons: one map of names to drawings, each drawing a list of shapes ([element name, attributes]) on a
     * 24 × 24 grid, stroked in the text's colour (currentColor), so both themes draw them. icon() builds one with
     * createElementNS; nothing is parsed. A new icon is one entry here.
     */
    var ICONS = {
        // Uses AI: a spark
        ai: [
            ['path', { d: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z' }],
            ['path', { d: 'M19 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z' }]
        ],
        // No AI: the spark, struck through
        noAi: [
            ['path', { d: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z' }],
            ['line', { x1: '3', y1: '3', x2: '21', y2: '21' }]
        ],
        // Can delete: a bin
        canDelete: [
            ['path', { d: 'M3 6h18' }],
            ['path', { d: 'M8 6V4h8v2' }],
            ['path', { d: 'M19 6l-1 14H6L5 6' }],
            ['path', { d: 'M10 11v5M14 11v5' }]
        ],
        // Needs something first: a plug
        needs: [
            ['path', { d: 'M9 2v5M15 2v5' }],
            ['path', { d: 'M6 7h12v4a6 6 0 0 1-12 0z' }],
            ['path', { d: 'M12 17v5' }]
        ]
    };

    /**
     * A named icon (ICONS) as an element, hidden from assistive technology: the caller gives it a name where it stands alone.
     * @param {string} name
     * @param {number} [size=14]
     * @returns {SVGElement|null} null for a name the map does not hold
     */
    function icon(name, size) {
        var shapes = Object.prototype.hasOwnProperty.call(ICONS, name) ? ICONS[name] : null;
        if (!shapes) return null;
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('width', String(size || 14));
        svg.setAttribute('height', String(size || 14));
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        shapes.forEach(function (shape) {
            var part = document.createElementNS(ns, shape[0]);
            Object.keys(shape[1]).forEach(function (k) { part.setAttribute(k, shape[1][k]); });
            svg.appendChild(part);
        });
        return svg;
    }

    /**
     * The one scroller of a table of the panel: .admin-table-wrapper's edge shadow says there
     * is more, and the keyboard reaches it.
     * @param {HTMLElement} table
     * @returns {HTMLElement}
     */
    function tableWrapper(table) {
        return el('div', { class: 'admin-table-wrapper', tabindex: '0' }, [table]);
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
     * The control of the page carrying this focus key (`data-focus-key`, compared as text: an item's name may hold
     * any character), or null.
     * @param {?string} key
     * @returns {?HTMLElement}
     */
    function byFocusKey(key) {
        if (!key) return null;
        var all = document.querySelectorAll('[data-focus-key]');
        for (var i = 0; i < all.length; i++) {
            if (all[i].getAttribute('data-focus-key') === key) return all[i];
        }
        return null;
    }

    /**
     * Remember where the focus is, to give it back after the page redraws: a dialog closing (openModal), a list
     * drawn again under the focus. restore() focuses the element if it is still in the page, else the element that
     * took its place: the same `id`, else the same `data-focus-key` (the controls of QSControls carry one, `<kind>:<item>`),
     * else, the item being gone (a delete), the control of the same kind after it, then the one before it, as they
     * stood when the focus was remembered.
     *
     * @param {Element} [target=document.activeElement]
     * @returns {{restore: function(): boolean}} restore() answers whether it gave the focus to something
     */
    function rememberFocus(target) {
        var doc = document;
        var el = target || doc.activeElement;
        var id = el && el.id ? el.id : '';
        var key = el && el.getAttribute ? el.getAttribute('data-focus-key') : null;
        var after = null;
        var before = null;
        if (key) {
            var kind = key.split(':')[0] + ':';
            var keyed = Array.prototype.slice.call(doc.querySelectorAll('[data-focus-key]')).filter(function (k) {
                return k.getAttribute('data-focus-key').indexOf(kind) === 0;
            });
            var at = keyed.indexOf(el);
            if (at !== -1) {
                after = at + 1 < keyed.length ? keyed[at + 1].getAttribute('data-focus-key') : null;
                before = at > 0 ? keyed[at - 1].getAttribute('data-focus-key') : null;
            }
        }
        function restore() {
            var back = el && el !== doc.body && el.isConnected ? el : null;
            if (!back && id) back = doc.getElementById(id);
            if (!back) back = byFocusKey(key) || byFocusKey(after) || byFocusKey(before);
            if (!back || typeof back.focus !== 'function') return false;
            back.focus();
            return doc.activeElement === back;
        }
        return { restore: restore };
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

    /**
     * Follow a pointer from a press on a handle until it is let go: the one drag
     * routine of the panel's handles (the editor's options panel, the edges of its
     * device frame). A mouse, a pen and a finger run the same code; the handle
     * carries `touch-action: none` in the stylesheet, so a finger drags it rather
     * than scrolling. The handle captures the pointer, so a pointer that crosses a
     * frame's page mid-drag keeps reporting here; a caller still sets
     * `pointer-events: none` on that frame while it drags, for a browser that
     * would not hold the capture across it.
     *
     * @param {PointerEvent} press   the pointerdown on the handle
     * @param {function(number, number, PointerEvent)} onMove  the distance from
     *        the press, across and down, with the move itself
     * @param {function(PointerEvent)} [onEnd]  once: on release, or when the
     *        browser takes the pointer for itself
     */
    function followPointer(press, onMove, onEnd) {
        var handle = press.currentTarget || press.target;
        var doc = handle.ownerDocument || document;
        var id = press.pointerId;
        var x0 = press.clientX;
        var y0 = press.clientY;
        press.preventDefault();
        try { handle.setPointerCapture(id); } catch (e) { /* the pointer is already gone: the release still ends it */ }
        function move(e) {
            if (e.pointerId === id) onMove(e.clientX - x0, e.clientY - y0, e);
        }
        function end(e) {
            if (e.pointerId !== id) return;
            doc.removeEventListener('pointermove', move);
            doc.removeEventListener('pointerup', end);
            doc.removeEventListener('pointercancel', end);
            doc.removeEventListener('lostpointercapture', end);
            if (onEnd) onEnd(e);
        }
        doc.addEventListener('pointermove', move);
        doc.addEventListener('pointerup', end);
        doc.addEventListener('pointercancel', end);
        doc.addEventListener('lostpointercapture', end);
    }

    return {
        el: el,
        svgIcon: svgIcon,
        icon: icon,
        iconEl: iconEl,
        tableWrapper: tableWrapper,
        clear: clear,
        setSelectPlaceholder: setSelectPlaceholder,
        setButtonBusy: setButtonBusy,
        rememberFocus: rememberFocus,
        byFocusKey: byFocusKey,
        followPointer: followPointer
    };
})();
