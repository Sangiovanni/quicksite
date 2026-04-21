/* =============================================================================
   CSS REFINER — UI: Diff View
   Renders before/after diffs inside suggestion panels.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var el = CSSRefiner.UI.Components.el;

    /**
     * Render a diff view into a container element.
     *
     * @param {HTMLElement} container
     * @param {Object}      diff       { before: string, after: string }
     */
    function renderDiff(container, diff) {
        var fragment = document.createDocumentFragment();

        var beforeLines = (diff.before || '').split('\n');
        var afterLines  = (diff.after  || '').split('\n');

        /* Simple line-based diff: show removed lines, then added lines */
        /* For a more sophisticated diff we could implement LCS, but line-level
           removed/added is clear enough for CSS refactoring suggestions. */

        var removedMap = {};
        var addedMap   = {};
        var i;

        /* Build sets for quick lookup */
        var beforeSet = {};
        var afterSet  = {};
        for (i = 0; i < beforeLines.length; i++) { beforeSet[beforeLines[i]] = true; }
        for (i = 0; i < afterLines.length; i++)  { afterSet[afterLines[i]]  = true; }

        /* Lines only in before → removed */
        for (i = 0; i < beforeLines.length; i++) {
            if (!afterSet[beforeLines[i]]) {
                removedMap[i] = true;
            }
        }
        /* Lines only in after → added */
        for (i = 0; i < afterLines.length; i++) {
            if (!beforeSet[afterLines[i]]) {
                addedMap[i] = true;
            }
        }

        /* Render removed */
        for (i = 0; i < beforeLines.length; i++) {
            var lineClass = removedMap[i] ? 'cr-diff__line cr-diff__line--remove'
                                          : 'cr-diff__line cr-diff__line--context';
            var sign = removedMap[i] ? '\u2212' : ' ';  /* minus sign */
            fragment.appendChild(createDiffLine(sign, beforeLines[i], lineClass));
        }

        /* Separator */
        fragment.appendChild(el('hr', { className: 'cr-diff__separator' }));

        /* Render added */
        for (i = 0; i < afterLines.length; i++) {
            var lineClass2 = addedMap[i] ? 'cr-diff__line cr-diff__line--add'
                                         : 'cr-diff__line cr-diff__line--context';
            var sign2 = addedMap[i] ? '+' : ' ';
            fragment.appendChild(createDiffLine(sign2, afterLines[i], lineClass2));
        }

        var diffWrap = el('div', { className: 'cr-diff' });
        diffWrap.appendChild(fragment);

        /* Clear and append */
        while (container.firstChild) { container.removeChild(container.firstChild); }
        container.appendChild(diffWrap);
    }

    /* ── Helpers ── */

    function createDiffLine(sign, text, className) {
        return el('div', { className: className }, [
            el('span', { className: 'cr-diff__sign', textContent: sign }),
            el('code', { textContent: text })
        ]);
    }

    /* ── Public API ── */
    CSSRefiner.UI = CSSRefiner.UI || {};
    CSSRefiner.UI.DiffView = {
        renderDiff: renderDiff
    };

})();
