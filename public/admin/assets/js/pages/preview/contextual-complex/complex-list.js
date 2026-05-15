/**
 * List wizard — kind 'list'.
 *
 * Emits a plain <ul> or <ol> with N <li> children, each <li> wrapping
 * one translation key:
 *
 *   <ul>
 *     <li><textKey/></li>
 *     <li><textKey/></li>
 *   </ul>
 *
 *   <ol start="N" reversed>            (start + reversed = <ol>-only)
 *     <li><textKey/></li>
 *   </ol>
 *
 * MVP scope (per COMPLEX_ELEMENTS.txt):
 *   - Flat list, no nesting.
 *   - Each <li> is a single textKey (no raw text, no <a> child).
 *   - ol-only fields: start (integer), reversed (boolean).
 *
 * Server-side builder: secure/src/classes/complexElements/ListElement.php
 */
(function () {
    'use strict';

    function renderWizard(container) {
        const wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--list';
        wrap.innerHTML = [
            '<div class="admin-form-group">',
            '  <label class="admin-label">List type <span class="admin-text-danger">*</span></label>',
            '  <div>',
            '    <label class="admin-checkbox" style="margin-right:14px">',
            '      <input type="radio" name="ce-list-tag" value="ul" checked>',
            '      <span>Unordered (&lt;ul&gt;)</span>',
            '    </label>',
            '    <label class="admin-checkbox">',
            '      <input type="radio" name="ce-list-tag" value="ol">',
            '      <span>Ordered (&lt;ol&gt;)</span>',
            '    </label>',
            '  </div>',
            '</div>',
            '<div class="admin-form-group" id="ce-list-ol-group" style="display:none">',
            '  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">',
            '    <div style="flex:0 0 110px">',
            '      <label class="admin-label" for="ce-list-start"><small>Start at</small></label>',
            '      <input class="admin-input" type="number" id="ce-list-start" placeholder="1" autocomplete="off">',
            '    </div>',
            '    <div style="flex:1; min-width:160px">',
            '      <label class="admin-checkbox">',
            '        <input type="checkbox" id="ce-list-reversed">',
            '        <span>Reversed (counts down)</span>',
            '      </label>',
            '    </div>',
            '  </div>',
            '  <p class="admin-hint"><code>start</code> and <code>reversed</code> only apply to ordered lists.</p>',
            '</div>',

            '<hr style="margin:12px 0">',

            '<div class="admin-form-group">',
            '  <label class="admin-label">Items <span class="admin-text-danger">*</span></label>',
            '  <p class="admin-hint">Each item is one translation key, rendered as a <code>&lt;li&gt;</code>.</p>',
            '  <div id="ce-list-items-rows"></div>',
            '</div>',
        ].join('');
        container.appendChild(wrap);

        const tagRadios   = wrap.querySelectorAll('input[name="ce-list-tag"]');
        const olGroup     = wrap.querySelector('#ce-list-ol-group');
        const startEl     = wrap.querySelector('#ce-list-start');
        const reversedEl  = wrap.querySelector('#ce-list-reversed');
        const itemsHost   = wrap.querySelector('#ce-list-items-rows');

        function currentTag() {
            for (const r of tagRadios) if (r.checked) return r.value;
            return 'ul';
        }
        function syncOlVisibility() {
            olGroup.style.display = currentTag() === 'ol' ? '' : 'none';
        }
        tagRadios.forEach(r => r.addEventListener('change', syncOlVisibility));
        syncOlVisibility();

        // ---- items list (WizardRowEditor + per-row textKey picker) ----
        const itemPickers = new WeakMap();
        const itemsEditor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: '+ Add item',
            minRows: 1,
            initialRows: [
                { labelKey: '' },
                { labelKey: '' },
                { labelKey: '' },
            ],
            renderRow: function (data) {
                const d = data || {};
                const row = document.createElement('div');
                row.className = 'qs-list-item-row';
                row.innerHTML = '<div class="qs-list-item-row__label-mount"></div>';

                const picker = window.QSComplexWizard.createTextKeyPicker({
                    container: row.querySelector('.qs-list-item-row__label-mount'),
                    placeholder: 'e.g. menu.home, footer.copyright',
                    value: d.labelKey || '',
                });
                itemPickers.set(row, picker);
                return row;
            },
            readRow: function (contentEl) {
                const picker = itemPickers.get(contentEl);
                return {
                    labelKey: picker ? (picker.getValue() || '').trim() : ''
                };
            }
        });

        // ---- controller ----
        function getConfig() {
            const cfg = {
                tag: currentTag(),
                items: itemsEditor.getRows().filter(it => it.labelKey)  // drop empty-label rows
            };
            if (cfg.tag === 'ol') {
                const startStr = startEl.value.trim();
                if (startStr !== '') {
                    const n = parseInt(startStr, 10);
                    if (!Number.isNaN(n)) cfg.start = n;
                }
                if (reversedEl.checked) cfg.reversed = true;
            }
            return cfg;
        }

        function validate() {
            const cfg = getConfig();
            if (cfg.tag !== 'ul' && cfg.tag !== 'ol') return 'Pick a list type (ul or ol)';
            if (cfg.items.length === 0) return 'Add at least one item with a translation key';
            if (cfg.tag === 'ol' && startEl.value.trim() !== '' && Number.isNaN(parseInt(startEl.value, 10))) {
                return '"Start at" must be a whole number';
            }
            return null;
        }

        function destroy() {
            try { itemsEditor.destroy(); } catch (e) {}
            container.innerHTML = '';
        }

        return { getConfig, validate, destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['list'] = {
        label: 'List (ul / ol)',
        description: 'A &lt;ul&gt; or &lt;ol&gt; with one translation key per &lt;li&gt;.',
        renderWizard: renderWizard
    };
})();
