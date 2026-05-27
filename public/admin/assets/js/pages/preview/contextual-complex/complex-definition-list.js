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

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
            star.className = 'admin-text-danger';
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
        row.className = 'qs-dl-row';
        row.style.display = 'flex';
        row.style.gap = '8px';
        row.style.alignItems = 'flex-start';

        var termMount = document.createElement('div');
        termMount.className = 'qs-dl-row__term-mount';
        termMount.style.flex = '1';
        termMount.style.minWidth = '0';

        var descMount = document.createElement('div');
        descMount.className = 'qs-dl-row__desc-mount';
        descMount.style.flex = '2';
        descMount.style.minWidth = '0';

        row.appendChild(termMount);
        row.appendChild(descMount);

        var termPicker = window.QSComplexWizard.createTextKeyPicker({
            container: termMount,
            placeholder: 'term key (e.g. glossary.api.term)',
            value: d.termKey || '',
        });
        var descPicker = window.QSComplexWizard.createTextKeyPicker({
            container: descMount,
            placeholder: 'description key (e.g. glossary.api.desc)',
            value: d.descKey || '',
        });
        pickerMap.set(row, { term: termPicker, desc: descPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--definition-list';

        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel('Items', true));
        itemsGroup.appendChild(_renderHint('Each item is a (term, description) pair rendered as <dt>+<dd>.'));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: '+ Add item',
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
            if (cfg.items.length === 0) return 'Add at least one (term, description) pair.';
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                if (!it.termKey) return 'Item #' + (i + 1) + ' is missing a term key.';
                if (!it.descKey) return 'Item #' + (i + 1) + ' is missing a description key.';
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
        label: 'Definition list (dl)',
        description: 'A &lt;dl&gt; with N (&lt;dt&gt;, &lt;dd&gt;) pairs. One translation key per term and per description.',
        renderWizard: renderWizard
    };
})();
