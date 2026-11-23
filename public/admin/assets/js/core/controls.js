/**
 * QuickSite Admin — the panel's edit, delete and close controls: one look for each, built here.
 *
 * - An item's actions in a list or on a card are the pencil and the bin: ghost buttons holding the icon alone, grey,
 *   the bin red on hover and on keyboard focus. Each carries its word as its tooltip and its screen-reader label.
 * - The button that confirms a delete, and a destructive action of a whole page, is the red button that says its
 *   verb (`.admin-btn--danger`); it needs no builder.
 * - A dialog's close is one × at its top right (`.admin-modal__close`); a row removed from a form before Save (nothing
 *   is written until then) is the small × (`.admin-row-remove`), and asks nothing.
 *
 * Every icon is `QuickSiteUtils.ICON_PATHS`' (read when a control is built, so this file may load before utils.js).
 * A button a page template draws carries `data-qs-icon="edit|trash|close"` and no drawing: drawIcons() gives it the
 * same icon when the page has loaded, so the templates and the scripts draw from one catalogue.
 *
 * Each builder returns ONE element, made with createElement (HTML-in-JS hygiene, CLAUDE.md).
 *
 * @requires js/core/dom.js (QSDom.el, QSDom.iconEl), js/core/utils.js (ICON_PATHS) at call time
 */
window.QSControls = (function () {
    'use strict';

    /** The icon each kind of control draws (an ICON_PATHS name) and its size in pixels. */
    var KINDS = {
        edit: { icon: 'edit', size: 14 },
        trash: { icon: 'trash', size: 14 },
        close: { icon: 'close', size: 20 },
        row: { icon: 'close', size: 14 }
    };

    /** A word every page ships (`common.<name>`), read as the panel's dialogs read theirs. */
    function word(name) {
        var utils = window.QuickSiteUtils;
        return utils && typeof utils.tCommon === 'function' ? utils.tCommon(name) : 'common.' + name;
    }

    /** An icon of the catalogue as an element, hidden from assistive technology (the button carries the word). */
    function _renderIcon(kind) {
        var k = KINDS[kind];
        var paths = window.QuickSiteUtils && window.QuickSiteUtils.ICON_PATHS;
        return paths ? window.QSDom.iconEl(paths[k.icon], k.size) : null;
    }

    /**
     * The button every builder makes: its classes, its word as tooltip and label, the role mark of the command it
     * sends (`data-requires-command`, which hides it from a role that cannot run it), and a focus key that a redraw
     * keeps, so the focus finds it again (QSDom.rememberFocus).
     *
     * @param {string} kind     a KINDS name
     * @param {string} cls      its classes
     * @param {string} label    its word: the tooltip and the screen-reader label
     * @param {?function(MouseEvent)} onClick
     * @param {Object} [opts]
     * @param {string} [opts.command]    the command it sends
     * @param {string} [opts.focusKey]   a name stable across redraws (`<kind>:<item>`)
     * @param {string} [opts.className]  the page's own class, for its place in a layout
     * @param {boolean} [opts.disabled]
     * @param {Object} [opts.attrs]      other attributes (`data-*`), as QSDom.el takes them
     * @returns {HTMLButtonElement}
     */
    function _renderButton(kind, cls, label, onClick, opts) {
        var o = opts || {};
        var props = { type: 'button', class: cls + (o.className ? ' ' + o.className : ''), 'aria-label': label, title: label };
        if (o.attrs) {
            Object.keys(o.attrs).forEach(function (k) { props[k] = o.attrs[k]; });
        }
        if (o.command) props['data-requires-command'] = o.command;
        if (o.focusKey) props['data-focus-key'] = o.focusKey;
        var btn = window.QSDom.el('button', props);
        var svg = _renderIcon(kind);
        if (svg) btn.appendChild(svg);
        if (o.disabled) btn.disabled = true;
        if (onClick) btn.addEventListener('click', onClick);
        return btn;
    }

    /** An item's pencil: it opens the item to edit it. */
    function edit(label, onClick, opts) {
        return _renderButton('edit', 'admin-btn admin-btn--ghost admin-action admin-action--edit', label, onClick, opts);
    }

    /** An item's bin: it asks before it deletes (QuickSiteUtils.confirm), red on hover. */
    function bin(label, onClick, opts) {
        return _renderButton('trash', 'admin-btn admin-btn--ghost admin-action admin-action--delete', label, onClick, opts);
    }

    /**
     * A dialog's ×, at its top right: it closes the dialog, as Cancel does where the dialog asks a question. Its word is
     * the panel's "Close" unless the caller gives another.
     */
    function close(label, onClick, opts) {
        return _renderButton('close', 'admin-modal__close', label || word('close'), onClick, opts);
    }

    /** The small × that takes a row out of a form: nothing is written until the form is saved, so it asks nothing. */
    function removeRow(label, onClick, opts) {
        return _renderButton('row', 'admin-row-remove', label, onClick, opts);
    }

    /**
     * Give every button a template drew with `data-qs-icon` its icon, once, before its word when it has one: `edit` and
     * `trash` the item's actions (or an action panel's Edit and Delete, icon and name), `close` a dialog's ×, `row` the
     * small ×. A name the catalogue does not know draws nothing.
     *
     * @param {ParentNode} [root=document]
     */
    function drawIcons(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-qs-icon]').forEach(function (btn) {
            var kind = btn.getAttribute('data-qs-icon');
            if (!Object.prototype.hasOwnProperty.call(KINDS, kind) || btn.querySelector('svg')) return;
            var svg = _renderIcon(kind);
            if (svg) btn.insertBefore(svg, btn.firstChild);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { drawIcons(document); });
    } else {
        drawIcons(document);
    }

    return {
        edit: edit,
        bin: bin,
        close: close,
        removeRow: removeRow,
        drawIcons: drawIcons
    };
})();
