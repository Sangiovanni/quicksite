/**
 * The panel's own hover bubble.
 *
 * Every `title` in the panel is shown here instead of by the browser: on mouse
 * hover (after a short delay) and on keyboard focus, as TEXT (never markup),
 * with its line breaks, inside the window. One set of listeners on the document
 * does it, so no page marks its titles: a new tooltip is simply a `title`.
 *
 * How a title reaches the bubble. When the pointer enters an element with a
 * title, or the keyboard focuses one, the element is HELD: its text is kept
 * here and its `title` set to "" (an empty title is what keeps the browser
 * from drawing its own tooltip beside the bubble). When the pointer has left
 * and the focus has gone, the title is given back. While an element is held:
 *   - a title the page writes is shown at once (the header's allowance changes
 *     while it is open), and one the page clears or removes stays cleared: an
 *     observer on that one element tells the page's writes from the bubble's;
 *   - a screen reader keeps what the title gave it: the element is named by the
 *     text (aria-label) when the title was its only name, otherwise described
 *     by the bubble (aria-describedby). At rest nothing is changed.
 *
 * What stays the browser's: an <iframe>'s title (its accessible name), an
 * <option>'s (the browser draws a native list outside the page), and anything
 * inside [data-qs-native-title] (the tag preview, which shows a visitor's
 * markup as a browser renders it). An SVG element passes the pointer on to the
 * element that holds it.
 *
 * Which title: on hover, the nearest one up the tree, as the browser picks it
 * (an empty title on the way stops the search); on focus, only when the focus
 * is the keyboard's (:focus-visible), and only the focused element's own title
 * or its <label>'s, so a group's title does not show for every control in it.
 *
 * Hidden on leave and blur; on Escape and on a press, until the pointer leaves;
 * on a scroll; and when the element leaves the page. A touch shows nothing, as
 * a title never did. Loaded in the layout's <head>: it listens on the document
 * and builds its element the first time it needs it.
 */
(function () {
    'use strict';

    var SHOW_DELAY = 500;      // ms of hover before a bubble shows
    var WARM_WINDOW = 300;     // ms after one hides in which the next shows at once
    var MARGIN = 8;            // px kept from the window's edges
    var GAP = 6;               // px between the element and the bubble
    var CONNECTED_CHECK = 250; // ms between checks that the held element is still on the page
    var BUBBLE_ID = 'qs-bubble';
    var NATIVE_TITLES = '[data-qs-native-title]';
    var SVG_NS = 'http://www.w3.org/2000/svg';

    var bubbleEl = null;
    // The held element and what the bubble did to it:
    // { el, text, pointer, focus, dismissed, aria: 'label'|'describedby'|null, ariaLabel, describedBy }
    var held = null;
    var observer = null;
    var selfWrites = 0;    // title writes of the bubble's own the observer has not reported yet
    var showTimer = null;
    var checkTimer = null;
    var hiddenAt = 0;

    function bubble() {
        if (!bubbleEl) {
            bubbleEl = document.createElement('div');
            bubbleEl.id = BUBBLE_ID;
            bubbleEl.className = 'qs-bubble';
            bubbleEl.setAttribute('role', 'tooltip');
            bubbleEl.hidden = true;
            document.body.appendChild(bubbleEl);
        }
        return bubbleEl;
    }

    function isShown() {
        return !!bubbleEl && !bubbleEl.hidden;
    }

    /** True for an element whose title is never the bubble's. */
    function keepsNativeTitle(el) {
        var tag = el.tagName;
        return tag === 'IFRAME' || tag === 'OPTION' || tag === 'OPTGROUP' || !!el.closest(NATIVE_TITLES);
    }

    /** The element whose title the browser would show for the pointer over `node`, or null. */
    function titleOwnerForPointer(node) {
        var el = node && node.nodeType === 1 ? node : (node ? node.parentElement : null);
        for (; el; el = el.parentElement) {
            if (held && el === held.el) return el;
            if (el.namespaceURI === SVG_NS || !el.hasAttribute('title')) continue;
            if (el.getAttribute('title') === '' || keepsNativeTitle(el)) return null;
            return el;
        }
        return null;
    }

    function isKeyboardFocus(el) {
        try {
            return el.matches(':focus-visible');
        } catch (e) {
            return true;  // a browser without :focus-visible: every focus counts
        }
    }

    /** The element whose title a keyboard focus on `el` shows: its own, or its <label>'s. */
    function titleOwnerForFocus(el) {
        if (!el || el.nodeType !== 1 || el.namespaceURI === SVG_NS || !isKeyboardFocus(el)) return null;
        var label = el.closest('label');
        var candidates = label && label !== el ? [el, label] : [el];
        for (var i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            if (held && c === held.el) return c;
            if (c.getAttribute('title') && !keepsNativeTitle(c)) return c;
        }
        return null;
    }

    /** True when the element has a name of its own besides its title. */
    function hasOtherName(el) {
        if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby')) return true;
        if ((el.textContent || '').trim() !== '') return true;
        if (el.labels && el.labels.length) return true;
        if (el.getAttribute('alt')) return true;
        return el.tagName === 'INPUT' && /^(button|submit|reset)$/i.test(el.type) && el.value !== '';
    }

    function hold(el) {
        var text = el.getAttribute('title');
        held = {
            el: el, text: text, pointer: false, focus: false, dismissed: false,
            aria: null, ariaLabel: null, describedBy: null
        };
        el.setAttribute('title', '');
        selfWrites = 0;
        if (!hasOtherName(el)) {
            el.setAttribute('aria-label', text);
            held.aria = 'label';
            held.ariaLabel = text;
        } else if (el.getAttribute('aria-label') !== text) {
            held.describedBy = el.getAttribute('aria-describedby');
            el.setAttribute('aria-describedby', (held.describedBy ? held.describedBy + ' ' : '') + BUBBLE_ID);
            held.aria = 'describedby';
        }
        bubble().textContent = text;
        observer = new MutationObserver(onTitleWritten);
        observer.observe(el, { attributes: true, attributeFilter: ['title'] });
        checkTimer = setInterval(function () {
            if (held && !held.el.isConnected) release();
        }, CONNECTED_CHECK);
    }

    /**
     * Take in the title writes the observer reports. Returns false when the page
     * cleared or removed the title (it stays so); a new text is shown at once.
     */
    function absorb(records) {
        var own = selfWrites;
        selfWrites = 0;
        if (records.length <= own) return true;  // only the bubble's own writes
        var now = held.el.getAttribute('title');
        if (!now) {
            held.text = '';
            return false;
        }
        held.text = now;
        if (held.aria === 'label') {
            held.el.setAttribute('aria-label', now);
            held.ariaLabel = now;
        }
        bubble().textContent = now;
        selfWrites++;
        held.el.setAttribute('title', '');
        return true;
    }

    function onTitleWritten(records) {
        if (!held) return;
        if (!absorb(records)) {
            release();
            return;
        }
        if (isShown()) place();
    }

    /** Give the held element back: its title, unless the page cleared it, and its own ARIA. */
    function release() {
        if (!held) return;
        absorb(observer.takeRecords());
        observer.disconnect();
        observer = null;
        selfWrites = 0;
        clearInterval(checkTimer);
        clearTimeout(showTimer);
        var h = held;
        held = null;
        hide();
        if (h.text) h.el.setAttribute('title', h.text);
        if (h.aria === 'label' && h.el.getAttribute('aria-label') === h.ariaLabel) {
            h.el.removeAttribute('aria-label');
        } else if (h.aria === 'describedby') {
            var ids = (h.el.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) {
                return id !== '' && id !== BUBBLE_ID;
            });
            if (ids.length) h.el.setAttribute('aria-describedby', ids.join(' '));
            else h.el.removeAttribute('aria-describedby');
        }
    }

    function hide() {
        clearTimeout(showTimer);
        if (isShown()) {
            bubbleEl.hidden = true;
            hiddenAt = Date.now();
        }
    }

    /** Hide until the pointer leaves the element (Escape, a press, a scroll). */
    function dismiss() {
        if (!held) return;
        held.dismissed = true;
        hide();
    }

    function show() {
        if (!held || held.dismissed || !held.text) return;
        bubble().textContent = held.text;
        bubbleEl.hidden = false;
        place();
    }

    function schedule(ms) {
        clearTimeout(showTimer);
        if (ms <= 0) show();
        else showTimer = setTimeout(show, ms);
    }

    function isWarm() {
        return isShown() || Date.now() - hiddenAt < WARM_WINDOW;
    }

    /** Below the element and centred on it; above when it does not fit below; always inside the window. */
    function place() {
        var b = bubble();
        var r = held.el.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        b.style.left = '0px';
        b.style.top = '0px';
        var bw = b.offsetWidth;
        var bh = b.offsetHeight;
        var left = Math.max(MARGIN, Math.min(r.left + r.width / 2 - bw / 2, vw - bw - MARGIN));
        var top = r.bottom + GAP;
        if (top + bh > vh - MARGIN) {
            var above = r.top - GAP - bh;
            top = above >= MARGIN ? above : Math.max(MARGIN, vh - bh - MARGIN);
        }
        b.style.left = Math.round(left) + 'px';
        b.style.top = Math.round(top) + 'px';
    }

    function onPointerOver(e) {
        if (e.pointerType === 'touch') return;
        var el = titleOwnerForPointer(e.target);
        if (held && el === held.el) {
            held.pointer = true;
            return;
        }
        var warm = isWarm();
        if (held) release();
        if (!el) return;
        hold(el);
        held.pointer = true;
        schedule(warm ? 0 : SHOW_DELAY);
    }

    function onPointerOut(e) {
        if (!held || !held.pointer || e.pointerType === 'touch') return;
        if (e.relatedTarget && held.el.contains(e.relatedTarget)) return;  // still inside it
        held.pointer = false;
        if (!held.focus) release();
    }

    function onFocusIn(e) {
        var el = titleOwnerForFocus(e.target);
        if (!el) return;
        if (held && held.el !== el) release();
        if (!held) hold(el);
        held.focus = true;
        held.dismissed = false;
        schedule(0);
    }

    function onFocusOut(e) {
        if (!held || !held.focus) return;
        if (!held.el.contains(e.target)) return;
        if (e.relatedTarget && held.el.contains(e.relatedTarget)) return;  // focus stays inside it
        held.focus = false;
        if (!held.pointer) release();
    }

    function onKeyDown(e) {
        if (e.key === 'Escape') dismiss();
    }

    document.addEventListener('pointerover', onPointerOver, true);
    document.addEventListener('pointerout', onPointerOut, true);
    document.addEventListener('pointerdown', dismiss, true);
    document.addEventListener('focusin', onFocusIn, true);
    document.addEventListener('focusout', onFocusOut, true);
    document.addEventListener('keydown', onKeyDown, true);
    document.addEventListener('scroll', function () { if (isShown()) dismiss(); }, true);
    window.addEventListener('blur', release);
})();
