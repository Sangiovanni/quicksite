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
 * Scope:
 *   - Flat list, no nesting.
 *   - Each <li> is a single textKey (no raw text, no <a> child).
 *   - ol-only fields: start (integer), reversed (boolean).
 *
 * Server-side builder: secure/src/classes/complexElements/ListElement.php
 */
(function () {
    'use strict';

    function renderWizard(container) {
        const W = window.QSComplexWizard;
        const t = W.t;
        const radio = (value, checked) => QSDom.el('input', Object.assign({ type: 'radio', name: 'ce-list-tag', value: value }, checked ? { checked: '' } : {}));
        const startEl = QSDom.el('input', { class: 'admin-input', type: 'number', id: 'ce-list-start', placeholder: '1', autocomplete: 'off' });
        const reversedEl = QSDom.el('input', { type: 'checkbox', id: 'ce-list-reversed' });
        const olGroup = QSDom.el('div', { class: 'admin-form-group', id: 'ce-list-ol-group', style: 'display:none' }, [
            QSDom.el('div', { style: 'display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap' }, [
                QSDom.el('div', { style: 'flex:0 0 110px' }, [
                    QSDom.el('label', { class: 'admin-label', for: 'ce-list-start' }, [QSDom.el('small', { text: t('list.startAt') })]),
                    startEl
                ]),
                QSDom.el('div', { style: 'flex:1; min-width:160px' }, [
                    QSDom.el('label', { class: 'admin-checkbox' }, [reversedEl, QSDom.el('span', { text: t('list.reversed') })])
                ])
            ]),
            QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('list.olHint'), { start: 'start', reversed: 'reversed' }))
        ]);
        const itemsHost = QSDom.el('div', { id: 'ce-list-items-rows' });

        const wrap = QSDom.el('div', { class: 'qs-complex-wizard qs-complex-wizard--list' }, [
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('list.type'), ' ', W.requiredMark()]),
                QSDom.el('div', null, [
                    QSDom.el('label', { class: 'admin-checkbox', style: 'margin-right:14px' }, [radio('ul', true), QSDom.el('span', { text: t('list.unordered') })]),
                    ' ',
                    QSDom.el('label', { class: 'admin-checkbox' }, [radio('ol', false), QSDom.el('span', { text: t('list.ordered') })])
                ])
            ]),
            olGroup,
            QSDom.el('hr', { style: 'margin:12px 0' }),
            QSDom.el('div', { class: 'admin-form-group' }, [
                QSDom.el('label', { class: 'admin-label' }, [t('list.items'), ' ', W.requiredMark()]),
                QSDom.el('p', { class: 'admin-hint' }, W.codeText(t('list.itemsHint'), { li: '<li>' })),
                itemsHost
            ])
        ]);
        container.appendChild(wrap);

        const tagRadios   = wrap.querySelectorAll('input[name="ce-list-tag"]');

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
        const itemsEditor = W.createRowEditor({
            container: itemsHost,
            addButtonLabel: t('list.addItem'),
            minRows: 1,
            initialRows: [
                { labelKey: '' },
                { labelKey: '' },
                { labelKey: '' },
            ],
            renderRow: function (data) {
                const d = data || {};
                const mount = QSDom.el('div', { class: 'qs-list-item-row__label-mount' });
                const row = QSDom.el('div', { class: 'qs-list-item-row' }, [mount]);

                const picker = W.createTextKeyPicker({
                    container: mount,
                    placeholder: t('list.itemKeyPlaceholder'),
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
            if (cfg.tag !== 'ul' && cfg.tag !== 'ol') return t('list.errors.type');
            if (cfg.items.length === 0) return t('list.errors.noItem');
            if (cfg.tag === 'ol' && startEl.value.trim() !== '' && Number.isNaN(parseInt(startEl.value, 10))) {
                return t('list.errors.start');
            }
            return null;
        }

        function destroy() {
            try { itemsEditor.destroy(); } catch (e) {}
            container.replaceChildren();
        }

        return { getConfig, validate, destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['list'] = {
        label: window.QSComplexWizard.t('list.label'),
        description: window.QSComplexWizard.t('list.description'),
        renderWizard: renderWizard
    };
})();
