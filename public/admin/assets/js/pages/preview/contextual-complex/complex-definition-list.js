/**
 * Definition list wizard — kind 'definition-list'.
 *
 * Emits a <dl> with N (<dt>, <dd>) pairs. Each pair = one row in the
 * editor with two textKey pickers (term + description).
 *
 * Server-side builder: secure/src/classes/complexElements/DefinitionList.php
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
        row.style.gap = '8px';
        row.style.alignItems = 'flex-start';

        var termMount = document.createElement('div');
        termMount.style.flex = '1';
        termMount.style.minWidth = '0';

        var descMount = document.createElement('div');
        descMount.style.flex = '2';
        descMount.style.minWidth = '0';

        row.appendChild(termMount);
        row.appendChild(descMount);

        var termPicker = window.QSComplexWizard.createTextKeyPicker({
            container: termMount,
            placeholder: t('definitionList.termPlaceholder'),
            value: d.termKey || '',
        });
        var descPicker = window.QSComplexWizard.createTextKeyPicker({
            container: descMount,
            placeholder: t('definitionList.descPlaceholder'),
            value: d.descKey || '',
        });
        pickerMap.set(row, { term: termPicker, desc: descPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');

        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel(t('common.items'), true));
        itemsGroup.appendChild(_renderHint(t('definitionList.itemsHint')));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: t('common.addItem'),
            minRows: 1,
            initialRows: [
                { termKey: '', descKey: '' },
                { termKey: '', descKey: '' },
            ],
            renderRow: function (data) { return _renderItemRow(data, pickerMap); },
            readRow: function (contentEl) {
                var rec = pickerMap.get(contentEl) || {};
                return {
                    termKey: rec.term ? (rec.term.getValue() || '').trim() : '',
                    descKey: rec.desc ? (rec.desc.getValue() || '').trim() : '',
                };
            }
        });

        function getConfig() {
            var rows = editor.getRows()
                .filter(function (r) { return r.termKey !== '' || r.descKey !== ''; });
            return { items: rows };
        }

        function validate() {
            var cfg = getConfig();
            if (cfg.items.length === 0) return t('definitionList.errors.noItem');
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                var nth = { number: QSPanelWords.number(i + 1) };
                if (!it.termKey) return t('definitionList.errors.termKey', nth);
                if (!it.descKey) return t('definitionList.errors.descKey', nth);
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
    window.QSComplexWizard.registry['definition-list'] = {
        label: t('definitionList.label'),
        description: t('definitionList.description'),
        renderWizard: renderWizard
    };
})();
