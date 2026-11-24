/**
 * Tab set wizard — kind 'tab-set'.
 *
 * Emits an ARIA-correct tab set with click-to-switch wired through
 * existing QS.* verbs (no new runtime needed). See TabSet.php for the
 * emitted shape + onclick chain details.
 *
 * Wizard fields:
 *  - setId (HTML id of the wrapper, used to scope per-tab click chains)
 *  - items[] = { labelKey, contentKey } — variable cardinality
 *
 * Server-side builder: secure/src/classes/complexElements/TabSet.php
 */
(function () {
    'use strict';

    var t = window.QSComplexWizard.t;

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
            star.textContent = ' *';
            l.appendChild(star);
        }
        return l;
    }
    function _renderHint(text) {
        var p = document.createElement('p');
        p.className = 'admin-hint';
        p.textContent = text;
        return p;
    }
    function _renderGroup(child) {
        var g = document.createElement('div');
        g.className = 'admin-form-group';
        if (child) g.appendChild(child);
        return g;
    }

    function _renderItemRow(data, pickerMap) {
        var d = data || {};
        var row = document.createElement('div');
        row.style.display = 'flex';
        row.style.flexDirection = 'column';
        row.style.gap = '6px';

        var labelMount = document.createElement('div');

        var contentMount = document.createElement('div');

        var labelHint = document.createElement('div');
        labelHint.className = 'admin-hint';
        labelHint.textContent = t('tabSet.tabLabel');

        var contentHint = document.createElement('div');
        contentHint.className = 'admin-hint';
        contentHint.textContent = t('tabSet.panelContent');

        row.appendChild(labelHint);
        row.appendChild(labelMount);
        row.appendChild(contentHint);
        row.appendChild(contentMount);

        var labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: t('tabSet.labelPlaceholder'),
            value: d.labelKey || '',
        });
        var contentPicker = window.QSComplexWizard.createTextKeyPicker({
            container: contentMount,
            placeholder: t('tabSet.contentPlaceholder'),
            value: d.contentKey || '',
        });
        pickerMap.set(row, { label: labelPicker, content: contentPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');

        // -- setId --
        var setIdGroup = _renderGroup();
        setIdGroup.appendChild(_renderLabel(t('tabSet.setId'), true));
        var setIdInput = document.createElement('input');
        setIdInput.type = 'text';
        setIdInput.className = 'admin-input';
        setIdInput.id = 'ce-ts-set-id';
        setIdInput.placeholder = t('tabSet.setIdPlaceholder');
        setIdInput.autocomplete = 'off';
        setIdGroup.appendChild(setIdInput);
        setIdGroup.appendChild(_renderHint(t('tabSet.setIdHint')));
        wrap.appendChild(setIdGroup);

        // -- items --
        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel(t('tabSet.tabs'), true));
        itemsGroup.appendChild(_renderHint(t('tabSet.tabsHint')));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: t('tabSet.addTab'),
            minRows: 1,
            initialRows: [
                { labelKey: '', contentKey: '' },
                { labelKey: '', contentKey: '' },
            ],
            renderRow: function (data) { return _renderItemRow(data, pickerMap); },
            readRow: function (contentEl) {
                var rec = pickerMap.get(contentEl) || {};
                return {
                    labelKey:   rec.label   ? (rec.label.getValue()   || '').trim() : '',
                    contentKey: rec.content ? (rec.content.getValue() || '').trim() : '',
                };
            }
        });

        function getConfig() {
            var rows = editor.getRows()
                .filter(function (r) { return r.labelKey !== '' || r.contentKey !== ''; });
            return {
                setId: setIdInput.value.trim(),
                items: rows,
            };
        }

        function validate() {
            var cfg = getConfig();
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.setId)) {
                return t('tabSet.errors.setId');
            }
            if (cfg.items.length === 0) return t('tabSet.errors.noTab');
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                var nth = { number: QSPanelWords.number(i + 1) };
                if (!it.labelKey)   return t('tabSet.errors.labelKey', nth);
                if (!it.contentKey) return t('tabSet.errors.contentKey', nth);
            }
            return null;
        }

        function destroy() {
            try { editor.destroy(); } catch (e) {}
            container.textContent = '';
        }

        return { getConfig: getConfig, validate: validate, destroy: destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['tab-set'] = {
        label: t('tabSet.label'),
        description: t('tabSet.description'),
        renderWizard: renderWizard
    };
})();
