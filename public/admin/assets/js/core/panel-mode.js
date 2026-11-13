/**
 * The panel's mode: simple or advanced, the menu an account chose. Loaded on every panel page of a signed-in
 * account, after admin.js.
 *
 *  - The account menu's Mode row (layout.php) switches it: at once, without a reload, and saved on the account
 *    (/admin/state/panel-mode). A save that fails says so and puts the menu back as it was.
 *  - An account with no mode yet is asked once by the chooser (templates/partials/mode-chooser.php), on the first
 *    panel page it loads, once permissions have loaded: its lists then go through the same role filter as the
 *    menu. The chooser closes only by a pick. After the pick the account menu opens, its Mode row glows, and a
 *    bubble beside it says the mode can be switched there at any time; the bubble goes with its × or with the menu.
 *
 * ⚠ THE MODE IS NOT A PERMISSION. It hides menu entries (the page's admin-body--simple class) and shows a line on a page of the
 * advanced menu opened in simple (the same class), nothing else:
 * every page still opens at its address for a role that may open it, and nothing here is asked by the server.
 *
 * A page that draws a view of its own for each mode (the visual editor) hears a switch two ways:
 *  - before it: each function in window.QuickSitePanelModeGuards is asked, with the mode about to be drawn, and answers
 *    true or false (or a promise of it). One false and nothing switches: the page has asked the person, who chose to stay.
 *    An array a page fills whenever it loads, so a page's script may run before this one.
 *  - after it: 'quicksite:panel-mode' on the document, its detail {mode}, each time the class changes (a switch, a failed
 *    save putting the mode back, the chooser's pick).
 *
 * @requires js/core/api.js (QuickSiteAPI.setPanelMode), js/core/utils.js (openModal, showToast), admin.js
 */
(function () {
    'use strict';

    var CONFIG = window.QUICKSITE_CONFIG || {};
    var WORDS = (CONFIG.translations && CONFIG.translations.panelMode) || {};
    var MODES = ['simple', 'advanced'];

    function word(key, fallback) {
        return WORDS[key] || fallback;
    }

    /** The menu as one mode draws it: the page's class, then the groups (ruling: one entry left is a link); the page told. */
    function drawMenu(mode) {
        document.body.classList.toggle('admin-body--simple', mode !== 'advanced');
        window.QuickSiteAdmin.applyMenuGroups();
        document.dispatchEvent(new CustomEvent('quicksite:panel-mode', { detail: { mode: mode } }));
    }

    /** Whether the page lets a switch to `mode` happen: every guard asked in turn, true unless one says no. */
    function guardsAllow(mode) {
        var guards = Array.isArray(window.QuickSitePanelModeGuards) ? window.QuickSitePanelModeGuards.slice() : [];
        return guards.reduce(function (ok, guard) {
            return ok.then(function (yes) {
                if (!yes || typeof guard !== 'function') return yes;
                return Promise.resolve(guard(mode)).then(function (answer) { return answer !== false; }, function () { return false; });
            });
        }, Promise.resolve(true));
    }

    /** The Mode row: the current mode marked, none before the first pick. */
    function markRow(mode) {
        document.querySelectorAll('#admin-account-mode [data-panel-mode]').forEach(function (btn) {
            var on = btn.getAttribute('data-panel-mode') === mode;
            btn.classList.toggle('admin-account__mode-btn--active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    /** Save a mode; resolves true when the server kept it. */
    function save(mode) {
        return window.QuickSiteAPI.setPanelMode(mode).then(function (res) {
            return !!(res && res.ok);
        }, function () {
            return false;
        });
    }

    // ------------------------------------------------------------------
    // The switch
    // ------------------------------------------------------------------

    var current = MODES.indexOf(CONFIG.panelMode) === -1 ? null : CONFIG.panelMode;
    var saving = false;
    var asking = false;

    /** Switch to `mode` once the page lets it (guardsAllow()): at once where no page asks. */
    function switchTo(mode) {
        if (saving || asking || mode === current || MODES.indexOf(mode) === -1) return;
        if (!Array.isArray(window.QuickSitePanelModeGuards) || window.QuickSitePanelModeGuards.length === 0) {
            apply(mode);
            return;
        }
        asking = true;
        guardsAllow(mode).then(function (yes) {
            asking = false;
            if (yes) apply(mode);
        });
    }

    /** Switch to `mode` now; a failed save puts the menu and the row back and says so. */
    function apply(mode) {
        if (saving || mode === current) return;
        var was = current;
        current = mode;
        saving = true;
        drawMenu(mode);
        markRow(mode);
        save(mode).then(function (ok) {
            saving = false;
            if (ok) {
                CONFIG.panelMode = mode;
                return;
            }
            current = was;
            drawMenu(was);
            markRow(was);
            window.QuickSiteUtils.showToast(word('saveFailed', 'Your choice could not be saved. Try again.'), 'error');
        });
    }

    var row = document.getElementById('admin-account-mode');
    if (row) {
        row.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-panel-mode]');
            if (btn) switchTo(btn.getAttribute('data-panel-mode'));
        });
    }

    // A page of the advanced menu opened in simple says so at its top (layout.php), with the same switch.
    var notice = document.getElementById('admin-mode-notice');
    if (notice) {
        notice.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-panel-mode]');
            if (btn) switchTo(btn.getAttribute('data-panel-mode'));
        });
    }

    // ------------------------------------------------------------------
    // After the first pick: the account menu, the glow, the bubble
    // ------------------------------------------------------------------

    /** The bubble beside the Mode row, with its ×. */
    function renderBubble() {
        var close = QSDom.el('button', {
            type: 'button',
            class: 'admin-mode-bubble__close',
            'aria-label': word('bubbleClose', 'Close')
        }, [QSDom.iconEl('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>', 14)]);
        return QSDom.el('div', { class: 'admin-mode-bubble', id: 'admin-mode-bubble', role: 'status' }, [
            QSDom.el('p', { class: 'admin-mode-bubble__text', text: word('bubble', 'You can switch between Simple and Advanced here at any time.') }),
            close
        ]);
    }

    function showWhereToSwitch() {
        var menu = window.QuickSiteAdmin.openAccountMenu();
        var modeRow = document.getElementById('admin-account-mode');
        if (!menu || !modeRow) return;
        var account = modeRow.closest('.admin-account');
        var bubble = renderBubble();
        modeRow.insertAdjacentElement('afterend', bubble);
        modeRow.classList.add('admin-account__mode--glow');
        var active = modeRow.querySelector('.admin-account__mode-btn--active');
        if (active) {
            active.setAttribute('aria-describedby', 'admin-mode-bubble');
            active.focus();
        }

        var observer = null;
        function done() {
            if (observer) observer.disconnect();
            bubble.remove();
            modeRow.classList.remove('admin-account__mode--glow');
            if (active) active.removeAttribute('aria-describedby');
        }
        bubble.querySelector('.admin-mode-bubble__close').addEventListener('click', function (e) {
            // The × answers its own click: it takes the bubble out of the menu, so the menu's own "a click outside closes it"
            // would read the click as outside and close the menu with it.
            e.stopPropagation();
            done();
            if (active) active.focus();
        });
        // The bubble goes with the menu, whatever closed it.
        if (account && window.MutationObserver) {
            observer = new MutationObserver(function () {
                if (!account.classList.contains('admin-account--open')) done();
            });
            observer.observe(account, { attributes: true, attributeFilter: ['class'] });
        }
        // The glow is played once; with reduce motion it does not play (responsive.css), and the row stays as it is.
        modeRow.addEventListener('animationend', function () {
            modeRow.classList.remove('admin-account__mode--glow');
        }, { once: true });
    }

    // ------------------------------------------------------------------
    // The chooser
    // ------------------------------------------------------------------

    function openChooser(template) {
        var modal = template.content.firstElementChild.cloneNode(true);
        // The same rule that filtered the menu: a page this role cannot open is not promised.
        window.QuickSiteAdmin.filterByPermissions(modal);
        // A role that leaves Advanced nothing more (an account in no project yet) reads one line, not "plus:" over nothing.
        var advanced = modal.querySelector('#admin-mode-chooser-advanced-list');
        if (advanced) {
            var more = Array.prototype.filter.call(advanced.querySelectorAll('.admin-mode-chooser__item'), function (li) {
                return !li.classList.contains('admin-mode-chooser__item--all') && !li.classList.contains('admin-mode-chooser__item--none')
                    && !li.classList.contains('admin-hidden-permission');
            });
            if (!more.length) {
                var all = advanced.querySelector('.admin-mode-chooser__item--all');
                var none = advanced.querySelector('.admin-mode-chooser__item--none');
                if (all) all.hidden = true;
                if (none) none.hidden = false;
            }
        }
        var picks = Array.prototype.slice.call(modal.querySelectorAll('.admin-mode-chooser__pick'));
        var error = modal.querySelector('.admin-mode-chooser__error');
        var shown = window.QuickSiteUtils.openModal(modal, {
            focusable: function () { return picks; },
            first: picks[0],
            dismiss: null
        });
        var busy = false;

        function pick(mode) {
            if (busy) return;
            busy = true;
            picks.forEach(function (b) { b.disabled = true; });
            if (error) error.hidden = true;
            save(mode).then(function (ok) {
                busy = false;
                picks.forEach(function (b) { b.disabled = false; });
                if (!ok) {
                    // Not saved, so not chosen: the chooser stays, and says so.
                    if (error) error.hidden = false;
                    var again = modal.querySelector('.admin-mode-chooser__pick[data-panel-mode="' + mode + '"]');
                    if (again) again.focus();
                    return;
                }
                current = mode;
                CONFIG.panelMode = mode;
                shown.close();
                drawMenu(mode);
                markRow(mode);
                showWhereToSwitch();
            });
        }

        // A card is its button: a click anywhere on it picks it.
        modal.querySelectorAll('.admin-mode-chooser__card').forEach(function (card) {
            card.addEventListener('click', function () {
                pick(card.getAttribute('data-panel-mode'));
            });
        });
    }

    var template = document.getElementById('admin-mode-chooser');
    if (template && current === null) {
        var ready = window.QuickSiteAdmin.permissionsReady || Promise.resolve();
        ready.then(function () { openChooser(template); }, function () { openChooser(template); });
    }
})();
