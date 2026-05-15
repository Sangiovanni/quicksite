/**
 * WizardRowEditor — shared row-collection primitive for complex element wizards
 *
 * Every variable-cardinality wizard (Form Scaffold fields, Select options,
 * List items, Radio options, Table cells, …) reuses this primitive to get
 * "+ Add row / move up / move down / delete" mechanics with keyboard
 * navigation. Build once, use seven+ times.
 *
 * Public API (under window.QSComplexWizard):
 *
 *   QSComplexWizard.createRowEditor({
 *     container: HTMLElement,        // where rows live (required)
 *     renderRow: (data, ctx) => HTMLElement,   // build a row's content (required)
 *     readRow:   (contentEl)   => data,        // extract data from a row (required)
 *     initialRows: object[],         // pre-populate. Default: [].
 *     minRows:   number,             // refuse to remove below. Default 0.
 *     maxRows:   number,             // refuse to add above. Default Infinity.
 *     addButtonLabel: string|null,   // null = no add button (caller adds rows). Default '+ Add row'.
 *     keyboardNav: boolean,          // Alt+↑/↓ reorders the focused row. Default true.
 *     onChange:  (rows) => void      // fires after add/remove/reorder. Default no-op.
 *   })
 *
 *   Returned controller:
 *     .getRows()   → data[]   (calls readRow on each row)
 *     .addRow(data?) → rowEl  (appended; respects maxRows)
 *     .clear()
 *     .destroy()  (removes the add button + any container listeners)
 *
 * Each row is wrapped in a <div class="qs-wizard-row"> with a side
 * toolbar (↑ ↓ ×). The user's renderRow returns the row's CONTENT;
 * the toolbar is attached automatically.
 *
 * Loaded by: secure/admin/templates/pages/preview/contextual-add.php
 * (Step 3 of the Complex Element MVP wires it in).
 */
(function () {
    'use strict';

    const ROW_CLASS         = 'qs-wizard-row';
    const ROW_CONTENT_CLASS = 'qs-wizard-row__content';
    const ROW_TOOLS_CLASS   = 'qs-wizard-row__tools';
    const BTN_UP_CLASS      = 'qs-wizard-row__up';
    const BTN_DOWN_CLASS    = 'qs-wizard-row__down';
    const BTN_DELETE_CLASS  = 'qs-wizard-row__delete';
    const ADD_BTN_CLASS     = 'qs-wizard-row__add';

    function noop() {}

    /**
     * Create a row editor.
     * @returns {{ getRows: Function, addRow: Function, clear: Function, destroy: Function }}
     */
    function createRowEditor(opts) {
        if (!opts || !(opts.container instanceof HTMLElement)) {
            throw new Error('[WizardRowEditor] container (HTMLElement) is required');
        }
        if (typeof opts.renderRow !== 'function') {
            throw new Error('[WizardRowEditor] renderRow (function) is required');
        }
        if (typeof opts.readRow !== 'function') {
            throw new Error('[WizardRowEditor] readRow (function) is required');
        }
        const cfg = {
            container:      opts.container,
            renderRow:      opts.renderRow,
            readRow:        opts.readRow,
            minRows:        Number.isFinite(opts.minRows) ? opts.minRows : 0,
            maxRows:        Number.isFinite(opts.maxRows) ? opts.maxRows : Infinity,
            addButtonLabel: opts.addButtonLabel === null ? null : (opts.addButtonLabel || '+ Add row'),
            keyboardNav:    opts.keyboardNav !== false,
            onChange:       typeof opts.onChange === 'function' ? opts.onChange : noop,
        };

        // Apply marker class so styles + delegation can target rows reliably.
        cfg.container.classList.add('qs-wizard-row-editor');

        // ----- helpers ------------------------------------------------------

        function rowCount() {
            return cfg.container.querySelectorAll(':scope > .' + ROW_CLASS).length;
        }

        function rowAt(idx) {
            return cfg.container.querySelectorAll(':scope > .' + ROW_CLASS)[idx] || null;
        }

        function getRows() {
            return Array.from(cfg.container.querySelectorAll(':scope > .' + ROW_CLASS))
                .map(rowEl => {
                    const contentEl = rowEl.querySelector(':scope > .' + ROW_CONTENT_CLASS);
                    return cfg.readRow(contentEl);
                });
        }

        function fireChange() {
            try { cfg.onChange(getRows()); } catch (e) { console.warn('[WizardRowEditor] onChange threw:', e); }
        }

        function buildToolbar() {
            const tools = document.createElement('div');
            tools.className = ROW_TOOLS_CLASS;
            tools.innerHTML =
                '<button type="button" class="' + BTN_UP_CLASS + '" title="Move up" aria-label="Move up">&uarr;</button>' +
                '<button type="button" class="' + BTN_DOWN_CLASS + '" title="Move down" aria-label="Move down">&darr;</button>' +
                '<button type="button" class="' + BTN_DELETE_CLASS + '" title="Remove" aria-label="Remove">&times;</button>';
            return tools;
        }

        /**
         * Append a new row. `data` is optional; renderRow receives undefined
         * for a fresh row so it can use defaults.
         */
        function addRow(data) {
            if (rowCount() >= cfg.maxRows) {
                console.warn('[WizardRowEditor] addRow refused: maxRows (' + cfg.maxRows + ') reached');
                return null;
            }
            const ctx = { isNew: data === undefined };
            const contentEl = cfg.renderRow(data, ctx);
            if (!(contentEl instanceof HTMLElement)) {
                throw new Error('[WizardRowEditor] renderRow must return an HTMLElement');
            }
            contentEl.classList.add(ROW_CONTENT_CLASS);

            const rowEl = document.createElement('div');
            rowEl.className = ROW_CLASS;
            rowEl.appendChild(contentEl);
            rowEl.appendChild(buildToolbar());

            cfg.container.appendChild(rowEl);
            fireChange();
            return rowEl;
        }

        function clear() {
            cfg.container.querySelectorAll(':scope > .' + ROW_CLASS).forEach(r => r.remove());
            fireChange();
        }

        function removeRow(rowEl) {
            if (rowCount() <= cfg.minRows) {
                console.warn('[WizardRowEditor] removeRow refused: minRows (' + cfg.minRows + ') reached');
                return false;
            }
            rowEl.remove();
            fireChange();
            return true;
        }

        function swapRows(aIdx, bIdx) {
            const a = rowAt(aIdx), b = rowAt(bIdx);
            if (!a || !b) return false;
            // Swap by anchor: insert a before b, then b's old spot is filled by what was next.
            // Simpler: clone-replace using a placeholder.
            const placeholder = document.createComment('qs-wizard-swap');
            a.parentNode.replaceChild(placeholder, a);
            b.parentNode.replaceChild(a, b);
            placeholder.parentNode.replaceChild(b, placeholder);
            fireChange();
            return true;
        }

        // ----- delegated row-toolbar clicks --------------------------------

        function handleContainerClick(e) {
            const btn = e.target.closest('button');
            if (!btn) return;
            const rowEl = btn.closest('.' + ROW_CLASS);
            if (!rowEl || rowEl.parentNode !== cfg.container) return;

            const rows = Array.from(cfg.container.querySelectorAll(':scope > .' + ROW_CLASS));
            const idx = rows.indexOf(rowEl);

            if (btn.classList.contains(BTN_UP_CLASS)) {
                e.preventDefault(); e.stopPropagation();
                if (idx > 0) swapRows(idx - 1, idx);
            } else if (btn.classList.contains(BTN_DOWN_CLASS)) {
                e.preventDefault(); e.stopPropagation();
                if (idx >= 0 && idx < rows.length - 1) swapRows(idx, idx + 1);
            } else if (btn.classList.contains(BTN_DELETE_CLASS)) {
                e.preventDefault(); e.stopPropagation();
                removeRow(rowEl);
            }
        }
        cfg.container.addEventListener('click', handleContainerClick);

        // ----- keyboard nav (Alt+↑ / Alt+↓ on the focused row) -------------

        function handleContainerKeydown(e) {
            if (!cfg.keyboardNav) return;
            if (!e.altKey) return;
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            const rowEl = e.target.closest('.' + ROW_CLASS);
            if (!rowEl || rowEl.parentNode !== cfg.container) return;
            const rows = Array.from(cfg.container.querySelectorAll(':scope > .' + ROW_CLASS));
            const idx = rows.indexOf(rowEl);
            if (idx === -1) return;
            if (e.key === 'ArrowUp' && idx > 0) {
                e.preventDefault();
                swapRows(idx - 1, idx);
            } else if (e.key === 'ArrowDown' && idx < rows.length - 1) {
                e.preventDefault();
                swapRows(idx, idx + 1);
            }
        }
        cfg.container.addEventListener('keydown', handleContainerKeydown);

        // ----- "+ Add row" button -----------------------------------------

        let addBtn = null;
        if (cfg.addButtonLabel !== null) {
            addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'admin-btn admin-btn--ghost admin-btn--xs ' + ADD_BTN_CLASS;
            addBtn.textContent = cfg.addButtonLabel;
            addBtn.addEventListener('click', function (e) {
                e.preventDefault();
                addRow();
            });
            cfg.container.parentNode.insertBefore(addBtn, cfg.container.nextSibling);
        }

        // ----- initial rows -----------------------------------------------

        if (Array.isArray(opts.initialRows)) {
            opts.initialRows.forEach(d => addRow(d));
        }

        // ----- destroy ----------------------------------------------------

        function destroy() {
            cfg.container.removeEventListener('click', handleContainerClick);
            cfg.container.removeEventListener('keydown', handleContainerKeydown);
            if (addBtn && addBtn.parentNode) addBtn.parentNode.removeChild(addBtn);
            cfg.container.classList.remove('qs-wizard-row-editor');
        }

        return { getRows, addRow, clear, destroy };
    }

    // Expose under the shared namespace.
    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.createRowEditor = createRowEditor;
})();
